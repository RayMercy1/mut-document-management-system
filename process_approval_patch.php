<?php
session_start();
require_once 'db_config.php';
require_once 'mailer.php';   // ← Email helper

if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php");
    exit();
}

$user_role = $_SESSION['role'];
$user_admin_role = $_SESSION['admin_role'] ?? 'none';
$admin_reg = $_SESSION['reg_number'];
$admin_name = $_SESSION['full_name'] ?? 'Admin';

// Allow super_admin, or actual role admins
$allowed = ($user_role === 'super_admin' || $user_role === 'admin');
if (!$allowed) {
    header("Location: login.php");
    exit();
}

// Determine view context
$current_view = $_SESSION['current_admin_view'] ?? $user_admin_role;

// Resolve correct signer name — modal identity takes priority over generic full_name
if ($current_view === 'cod' && !empty($_SESSION['cod_logged_in_name'])) {
    $admin_name = $_SESSION['cod_logged_in_name'];   // super admin used COD modal
} elseif ($current_view === 'dean' && !empty($_SESSION['dean_logged_in_name'])) {
    $admin_name = $_SESSION['dean_logged_in_name'];  // super admin used Dean modal
}
// For actual COD/Dean/Registrar logged in directly, full_name is already correct

// ---- Handle POST actions (COD recommend / not-recommend, Dean approve / reject) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $doc_id   = intval($_POST['doc_id'] ?? 0);
    $reason   = trim($_POST['reason'] ?? '');

    if ($doc_id <= 0) {
        header("Location: admin_dashboard.php?error=invalid_id");
        exit();
    }

    // Fetch document + student reg
    $docStmt = $conn->prepare("SELECT d.*, u.reg_number as student_reg, u.email as student_email, u.full_name as student_fullname, u.course
        FROM documents d JOIN users u ON d.reg_number = u.reg_number WHERE d.id = ?");
    $docStmt->bind_param("i", $doc_id);
    $docStmt->execute();
    $doc = $docStmt->get_result()->fetch_assoc();

    if (!$doc) {
        header("Location: admin_dashboard.php?error=doc_not_found");
        exit();
    }

    $student_reg   = $doc['student_reg'];
    $module_type   = $doc['module_type'];
    $now           = date('Y-m-d H:i:s');

    switch ($action) {

        // ── COD: Recommend → forward to Dean ──
        case 'cod_recommend':
            $stmt = $conn->prepare("UPDATE documents SET 
                status = 'Pending_Dean',
                current_approver = 'dean',
                cod_approved = 1,
                cod_approved_at = ?,
                cod_signer_name = ?,
                cod_signed_at = ?,
                cod_recommendation = 'recommended',
                student_visible_status = 'Under Review – At Dean\\'s Office',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("ssssi", $now, $admin_name, $now, $now, $doc_id);
            $stmt->execute();

            // Sync resit_retake_forms / special_exam_applications
            _syncFormStatus($conn, $doc_id, 'Pending_Dean');

            // Notify student
            createNotification($conn, $student_reg,
                'Application Forwarded to Dean',
                "Your {$module_type} application has been recommended by the COD and sent to the Dean's office.",
                'status_update', $doc_id);

            // Notify Dean users
            _notifyRole($conn, 'dean', 'dean',
                'New Application to Review',
                "A {$module_type} application from {$doc['student_fullname']} has been forwarded for your review.",
                $doc_id);

            logActivity($conn, $admin_reg, 'COD_RECOMMEND', "Recommended doc #{$doc_id}");
            header("Location: cod_dashboard.php?dept=" . ($_SESSION['selected_department'] ?? '') . "&success=recommended");
            exit();

        // ── COD: Not Recommended → send reason to student ──
        case 'cod_not_recommend':
            if (empty($reason)) {
                header("Location: view_form.php?id={$doc_id}&error=reason_required");
                exit();
            }
            $stmt = $conn->prepare("UPDATE documents SET 
                status = 'Rejected',
                cod_recommendation = 'not_recommended',
                cod_signer_name = ?,
                cod_signed_at = ?,
                cod_rejection_reason = ?,
                rejection_reason = ?,
                rejected_at = ?,
                student_visible_status = 'Not Recommended by COD',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("ssssssi", $admin_name, $now, $reason, $reason, $now, $now, $doc_id);
            $stmt->execute();

            _syncFormStatus($conn, $doc_id, 'Rejected');

            createNotification($conn, $student_reg,
                'Application Not Recommended by COD',
                "Your {$module_type} application was not recommended by the COD. Reason: {$reason}",
                'rejection', $doc_id);

            logActivity($conn, $admin_reg, 'COD_NOT_RECOMMEND', "Not recommended doc #{$doc_id}. Reason: {$reason}");
            header("Location: cod_dashboard.php?dept=" . ($_SESSION['selected_department'] ?? '') . "&success=not_recommended");
            exit();

        // ── Dean: Approve → forward to Registrar ──
        case 'dean_approve':
            $stmt = $conn->prepare("UPDATE documents SET
                status = 'Pending_Registrar',
                current_approver = 'registrar',
                dean_approved = 1,
                dean_approved_at = ?,
                dean_signer_name = ?,
                dean_signed_at = ?,
                dean_decision = 'approved',
                student_visible_status = 'Under Review – At Registrar\\'s Office',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("ssssi", $now, $admin_name, $now, $now, $doc_id);
            $stmt->execute();

            _syncFormStatus($conn, $doc_id, 'Pending_Registrar');

            createNotification($conn, $student_reg,
                'Application Approved by Dean',
                "Your {$module_type} application has been approved by the Dean and sent to the Registrar's office.",
                'status_update', $doc_id);

            _notifyRole($conn, 'registrar', 'registrar',
                'New Application to Finalise',
                "A {$module_type} application from {$doc['student_fullname']} is ready for your final action.",
                $doc_id);

            logActivity($conn, $admin_reg, 'DEAN_APPROVE', "Dean approved doc #{$doc_id}");

            // ── Payment notification email for Resit / Retake ──
            if (in_array($module_type, ['Resit', 'Retake'])) {
                // Fetch exam period and units for the email
                $payStmt = $conn->prepare(
                    "SELECT rrf.exam_month, rrf.exam_year,
                            GROUP_CONCAT(fu.unit_code SEPARATOR ', ') as unit_codes
                     FROM resit_retake_forms rrf
                     LEFT JOIN form_units fu ON fu.form_id = rrf.id
                     WHERE rrf.document_id = ?
                     GROUP BY rrf.id LIMIT 1"
                );
                $payStmt->bind_param("i", $doc_id);
                $payStmt->execute();
                $payRow = $payStmt->get_result()->fetch_assoc();

                $student_pay = [
                    'full_name'  => $doc['student_fullname'],
                    'email'      => $doc['student_email'],
                    'reg_number' => $doc['student_reg'],
                    'course'     => $doc['course'] ?? 'N/A',
                ];
                $exam_period = trim(($payRow['exam_month'] ?? '') . ' ' . ($payRow['exam_year'] ?? ''));
                sendPaymentNotificationEmail(
                    $student_pay,
                    $module_type,
                    $doc['title'],
                    $exam_period,
                    $payRow['unit_codes'] ?? ''
                );
            }
            // Special_Exam → no payment email (no charges apply)

            $school = urlencode($_SESSION['selected_school'] ?? '');
            header("Location: dean_dashboard.php?school={$school}&success=approved");
            exit();

        // ── Dean: Reject → send reason to student ──
        case 'dean_reject':
            if (empty($reason)) {
                header("Location: view_form.php?id={$doc_id}&error=reason_required");
                exit();
            }
            $stmt = $conn->prepare("UPDATE documents SET
                status = 'Rejected',
                dean_decision = 'rejected',
                dean_signer_name = ?,
                dean_signed_at = ?,
                dean_rejection_reason = ?,
                rejection_reason = ?,
                rejected_at = ?,
                student_visible_status = 'Rejected by Dean',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("ssssssi", $admin_name, $now, $reason, $reason, $now, $now, $doc_id);
            $stmt->execute();

            _syncFormStatus($conn, $doc_id, 'Rejected');

            createNotification($conn, $student_reg,
                'Application Rejected by Dean',
                "Your {$module_type} application was rejected by the Dean. Reason: {$reason}",
                'rejection', $doc_id);

            logActivity($conn, $admin_reg, 'DEAN_REJECT', "Dean rejected doc #{$doc_id}. Reason: {$reason}");
            $school = urlencode($_SESSION['selected_school'] ?? '');
            header("Location: dean_dashboard.php?school={$school}&success=rejected");
            exit();

        // ── Registrar: Finalise ──
        // For Resit/Retake → Approve directly (ready to print)
        // For Special_Exam → Forward to DVC ARSA for final approval
        case 'registrar_finalise':
            if ($module_type === 'Special_Exam') {
                // Route special exam to DVC
                $stmt = $conn->prepare("UPDATE documents SET
                    status = 'Pending_DVC',
                    current_approver = 'none',
                    student_visible_status = 'Under Review – At DVC ARSA Office',
                    updated_at = ?
                    WHERE id = ?");
                $stmt->bind_param("si", $now, $doc_id);
                $stmt->execute();

                _syncFormStatus($conn, $doc_id, 'Pending_DVC');

                createNotification($conn, $student_reg,
                    'Application Forwarded to DVC ARSA',
                    "Your Special Exam application has been reviewed by the Registrar and forwarded to the DVC for final approval.",
                    'status_update', $doc_id);

                logActivity($conn, $admin_reg, 'REGISTRAR_FORWARD_DVC', "Registrar forwarded Special Exam doc #{$doc_id} to DVC");
                header("Location: registrar_dashboard.php?success=forwarded_dvc");
                exit();

            } else {
                // Resit / Retake → finalise directly
                // Determine amount paid from POST (sent by view_form.php) or auto-calculate
                $amount_paid = trim($_POST['amount_paid'] ?? '');
                if (empty($amount_paid)) {
                    if ($module_type === 'Resit')   $amount_paid = 'KSh 800';
                    elseif ($module_type === 'Retake') $amount_paid = 'KSh 10,023';
                }

                $stmt = $conn->prepare("UPDATE documents SET
                    status = 'Approved',
                    current_approver = 'none',
                    registrar_approved = 1,
                    registrar_approved_at = ?,
                    registrar_signer_name = ?,
                    registrar_signed_at = ?,
                    amount_paid = ?,
                    student_visible_status = 'Approved – Ready to Print',
                    updated_at = ?
                    WHERE id = ?");
                $stmt->bind_param("sssssi", $now, $admin_name, $now, $amount_paid, $now, $doc_id);
                $stmt->execute();

                _syncFormStatus($conn, $doc_id, 'Approved');

                createNotification($conn, $student_reg,
                    'Application Approved – Ready to Print',
                    "Your {$module_type} application has been finalised. You can now view and print your form.",
                    'approval', $doc_id);

                logActivity($conn, $admin_reg, 'REGISTRAR_FINALISE', "Registrar finalised doc #{$doc_id}, amount: {$amount_paid}");

                // ── Send finalised document email to student ──
                $finStmt = $conn->prepare(
                    "SELECT d.*, u.full_name, u.email, u.reg_number as student_reg, u.course, u.phone,
                            dept.dept_name,
                            rrf.id as rrf_id, rrf.exam_month, rrf.exam_year,
                            rrf.student_signature_date, rrf.upload_date
                     FROM documents d
                     JOIN users u ON d.reg_number = u.reg_number
                     LEFT JOIN departments dept ON u.department_id = dept.id
                     LEFT JOIN resit_retake_forms rrf ON rrf.document_id = d.id
                     WHERE d.id = ? LIMIT 1"
                );
                $finStmt->bind_param("i", $doc_id);
                $finStmt->execute();
                $finDoc = $finStmt->get_result()->fetch_assoc();

                if ($finDoc) {
                    // Fetch units
                    $unitsStmt = $conn->prepare(
                        "SELECT fu.unit_code, fu.unit_title FROM form_units fu
                         JOIN resit_retake_forms rrf ON fu.form_id = rrf.id
                         WHERE rrf.document_id = ? ORDER BY fu.id"
                    );
                    $unitsStmt->bind_param("i", $doc_id);
                    $unitsStmt->execute();
                    $unitsRes = $unitsStmt->get_result();
                    $units_arr = [];
                    while ($ur = $unitsRes->fetch_assoc()) $units_arr[] = $ur;
                    $finDoc['units'] = $units_arr;

                    $student_fin = [
                        'full_name'  => $finDoc['full_name'],
                        'email'      => $finDoc['email'],
                        'reg_number' => $finDoc['student_reg'],
                        'course'     => $finDoc['course'] ?? 'N/A',
                        'phone'      => $finDoc['phone'] ?? '',
                    ];
                    sendFinalisedDocumentEmail($student_fin, $finDoc, $admin_name);
                }

                header("Location: registrar_dashboard.php?success=finalised");
                exit();
            }

        // ── DVC: Approve Special Exam → generate & email approval letter ──
        case 'dvc_approve':
            // Resolve DVC signer name
            $dvc_signer = $_SESSION['full_name'] ?? $admin_name;

            $stmt = $conn->prepare("UPDATE documents SET
                status = 'Approved',
                current_approver = 'none',
                registrar_approved = 1,
                registrar_approved_at = ?,
                registrar_signer_name = ?,
                registrar_signed_at = ?,
                student_visible_status = 'Special Exam Approved – Check Your Email',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("ssssi", $now, $dvc_signer, $now, $now, $doc_id);
            $stmt->execute();

            // Update special_exam_applications with DVC decision
            $conn->query("UPDATE special_exam_applications SET
                status = 'Approved_Letter_Sent',
                dvc_decision = 'approved',
                dvc_decision_date = NOW(),
                dvc_name = '" . $conn->real_escape_string($dvc_signer) . "'
                WHERE document_id = {$doc_id}");

            // ── Fetch student + application data for the email ──
            $seaStmt = $conn->prepare(
                "SELECT sea.*, u.full_name, u.email, u.reg_number, u.course
                 FROM special_exam_applications sea
                 JOIN users u ON sea.reg_number = u.reg_number
                 WHERE sea.document_id = ?
                 ORDER BY sea.id DESC LIMIT 1"
            );
            $seaStmt->bind_param("i", $doc_id);
            $seaStmt->execute();
            $seaRow = $seaStmt->get_result()->fetch_assoc();

            $emailResult = ['success' => false, 'error' => 'No application data found'];
            if ($seaRow) {
                $student_data = [
                    'full_name'  => $seaRow['full_name'],
                    'email'      => $seaRow['email'],
                    'reg_number' => $seaRow['reg_number'],
                    'course'     => $seaRow['course'],
                ];
                // Send the beautifully formatted approval letter email
                $emailResult = sendSpecialExamApprovalLetter($student_data, $seaRow, $dvc_signer);
            }

            // In-portal notification
            createNotification($conn, $student_reg,
                'Special Exam Approved! Check Your Email',
                "Your special exam application has been approved by the DVC. An approval letter has been sent to {$doc['student_email']}. Upload it in the portal to complete your registration.",
                'approval', $doc_id);

            // Notify Dean
            _notifyRole($conn, 'dean', 'dean',
                'Special Exam Application Approved',
                "Special exam application for {$doc['student_fullname']} has been approved by the DVC.",
                $doc_id);

            logActivity($conn, $admin_reg, 'DVC_APPROVE_SPECIAL',
                "DVC approved special exam doc #{$doc_id}. Email sent: " . ($emailResult['success'] ? 'yes' : 'no – ' . $emailResult['error']));

            $emailNote = $emailResult['success'] ? '&email=sent' : '&email=failed';
            header("Location: admin_dashboard.php?success=dvc_approved{$emailNote}");
            exit();

        // ── DVC: Reject Special Exam ──
        case 'dvc_reject':
            if (empty($reason)) {
                header("Location: view_form.php?id={$doc_id}&error=reason_required");
                exit();
            }
            $dvc_signer = $_SESSION['full_name'] ?? $admin_name;

            $stmt = $conn->prepare("UPDATE documents SET
                status = 'Rejected',
                rejection_reason = ?,
                rejected_at = ?,
                student_visible_status = 'Special Exam Rejected by DVC',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("sssi", $reason, $now, $now, $doc_id);
            $stmt->execute();

            $conn->query("UPDATE special_exam_applications SET
                status = 'Rejected',
                dvc_decision = 'rejected',
                dvc_decision_date = NOW(),
                dvc_name = '" . $conn->real_escape_string($dvc_signer) . "',
                rejection_reason = '" . $conn->real_escape_string($reason) . "'
                WHERE document_id = {$doc_id}");

            // ── Fetch data and send rejection email ──
            $seaStmt2 = $conn->prepare(
                "SELECT sea.*, u.full_name, u.email, u.reg_number, u.course
                 FROM special_exam_applications sea
                 JOIN users u ON sea.reg_number = u.reg_number
                 WHERE sea.document_id = ?
                 ORDER BY sea.id DESC LIMIT 1"
            );
            $seaStmt2->bind_param("i", $doc_id);
            $seaStmt2->execute();
            $seaRow2 = $seaStmt2->get_result()->fetch_assoc();

            if ($seaRow2) {
                $student_data2 = [
                    'full_name'  => $seaRow2['full_name'],
                    'email'      => $seaRow2['email'],
                    'reg_number' => $seaRow2['reg_number'],
                    'course'     => $seaRow2['course'],
                ];
                sendSpecialExamRejectionEmail($student_data2, $seaRow2, $reason, $dvc_signer);
            }

            createNotification($conn, $student_reg,
                'Special Exam Application Rejected',
                "Your special exam application was rejected by the DVC. Reason: {$reason}",
                'rejection', $doc_id);

            logActivity($conn, $admin_reg, 'DVC_REJECT_SPECIAL', "DVC rejected doc #{$doc_id}");
            header("Location: admin_dashboard.php?success=dvc_rejected");
            exit();

        default:
            header("Location: admin_dashboard.php?error=invalid_action");
            exit();
    }
}

// ---- Handle GET actions (legacy / quick forward links) ----
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action  = $_GET['action'];
    $doc_id  = intval($_GET['id']);
    $now     = date('Y-m-d H:i:s');

    $docStmt = $conn->prepare("SELECT d.*, u.reg_number as student_reg, u.full_name as student_fullname
        FROM documents d JOIN users u ON d.reg_number = u.reg_number WHERE d.id = ?");
    $docStmt->bind_param("i", $doc_id);
    $docStmt->execute();
    $doc = $docStmt->get_result()->fetch_assoc();

    if (!$doc) {
        header("Location: admin_dashboard.php?error=doc_not_found");
        exit();
    }

    $student_reg = $doc['student_reg'];
    $module_type = $doc['module_type'];

    switch ($action) {
        case 'forward_dean':
            $stmt = $conn->prepare("UPDATE documents SET status='Pending_Dean', current_approver='dean', cod_approved=1, cod_approved_at=?, cod_signer_name=?, student_visible_status='Under Review – At Dean\\'s Office', updated_at=? WHERE id=?");
            $stmt->bind_param("sssi", $now, $admin_name, $now, $doc_id);
            $stmt->execute();
            _syncFormStatus($conn, $doc_id, 'Pending_Dean');
            createNotification($conn, $student_reg, 'Forwarded to Dean', "Your {$module_type} has been forwarded to the Dean.", 'status_update', $doc_id);
            header("Location: admin_dashboard.php?success=1");
            exit();

        case 'forward_registrar':
            $stmt = $conn->prepare("UPDATE documents SET status='Pending_Registrar', current_approver='registrar', dean_approved=1, dean_approved_at=?, dean_signer_name=?, student_visible_status='Under Review – At Registrar\\'s Office', updated_at=? WHERE id=?");
            $stmt->bind_param("sssi", $now, $admin_name, $now, $doc_id);
            $stmt->execute();
            _syncFormStatus($conn, $doc_id, 'Pending_Registrar');
            createNotification($conn, $student_reg, 'Forwarded to Registrar', "Your {$module_type} has been forwarded to the Registrar.", 'status_update', $doc_id);
            header("Location: admin_dashboard.php?success=1");
            exit();

        case 'approve':
            $stmt = $conn->prepare("UPDATE documents SET status='Approved', current_approver='none', registrar_approved=1, registrar_approved_at=?, registrar_signer_name=?, student_visible_status='Approved – Ready to Print', updated_at=? WHERE id=?");
            $stmt->bind_param("sssi", $now, $admin_name, $now, $doc_id);
            $stmt->execute();
            _syncFormStatus($conn, $doc_id, 'Approved');
            createNotification($conn, $student_reg, 'Application Approved', "Your {$module_type} has been approved.", 'approval', $doc_id);
            header("Location: admin_dashboard.php?success=1");
            exit();

        case 'reject':
            $reason_get = $_GET['reason'] ?? 'No reason provided';
            $stmt = $conn->prepare("UPDATE documents SET status='Rejected', rejection_reason=?, rejected_at=?, student_visible_status='Rejected', updated_at=? WHERE id=?");
            $stmt->bind_param("sssi", $reason_get, $now, $now, $doc_id);
            $stmt->execute();
            _syncFormStatus($conn, $doc_id, 'Rejected');
            createNotification($conn, $student_reg, 'Application Rejected', "Your {$module_type} was rejected. Reason: {$reason_get}", 'rejection', $doc_id);
            header("Location: admin_dashboard.php?success=1");
            exit();

        default:
            header("Location: admin_dashboard.php?error=invalid_action");
            exit();
    }
}

header("Location: admin_dashboard.php");
exit();

// ─────────────────────────────────────────────
// Helper: sync child form status
// ─────────────────────────────────────────────
function _syncFormStatus($conn, $doc_id, $new_status) {
    $s = $conn->real_escape_string($new_status);
    $conn->query("UPDATE resit_retake_forms SET status='{$s}' WHERE document_id={$doc_id}");
    $conn->query("UPDATE special_exam_applications SET status='{$s}' WHERE document_id={$doc_id}");
}

// Helper: notify all users of a given admin_role
function _notifyRole($conn, $admin_role, $type, $title, $message, $doc_id) {
    $ar = $conn->real_escape_string($admin_role);
    $result = $conn->query("SELECT reg_number FROM users WHERE admin_role='{$ar}' AND is_active=1");
    while ($row = $result->fetch_assoc()) {
        createNotification($conn, $row['reg_number'], $title, $message, 'general', $doc_id);
    }
}
?>