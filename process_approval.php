<?php


session_start();
require_once 'db_config.php';
require_once 'mailer.php';

if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php");
    exit();
}

$user_role       = $_SESSION['role'];
$user_admin_role = $_SESSION['admin_role'] ?? 'none';
$admin_reg       = $_SESSION['reg_number'];
$admin_name      = $_SESSION['full_name'] ?? 'Admin';

$allowed = ($user_role === 'super_admin' || $user_role === 'admin');
if (!$allowed) {
    header("Location: login.php");
    exit();
}


$current_view = $_SESSION['current_admin_view'] ?? $user_admin_role;


if ($current_view === 'cod'  && !empty($_SESSION['cod_logged_in_name']))  $admin_name = $_SESSION['cod_logged_in_name'];
if ($current_view === 'dean' && !empty($_SESSION['dean_logged_in_name'])) $admin_name = $_SESSION['dean_logged_in_name'];


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $doc_id = intval($_POST['doc_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($doc_id <= 0) { header("Location: admin_dashboard.php?error=invalid_id"); exit(); }

    
    $docStmt = $conn->prepare(
        "SELECT d.*, u.reg_number AS student_reg, u.email AS student_email,
                u.full_name AS student_fullname, u.course
         FROM documents d
         JOIN users u ON d.reg_number = u.reg_number
         WHERE d.id = ?"
    );
    $docStmt->bind_param("i", $doc_id);
    $docStmt->execute();
    $doc = $docStmt->get_result()->fetch_assoc();

    if (!$doc) { header("Location: admin_dashboard.php?error=doc_not_found"); exit(); }

    $student_reg = $doc['student_reg'];
    $module_type = $doc['module_type'];
    $now         = date('Y-m-d H:i:s');

    switch ($action) {

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

            _syncFormStatus($conn, $doc_id, 'Pending_Dean');

            createNotification($conn, $student_reg,
                '✅ Document Recommended – Forwarded to Dean',
                "Your {$module_type} application has been recommended by the COD and forwarded to the Dean's office for review.",
                'status_update', $doc_id);

            _notifyRole($conn, 'dean', '📄 New Application – Action Required',
                "A {$module_type} application from {$doc['student_fullname']} has been recommended by the COD. Please review.",
                $doc_id);

            logActivity($conn, $admin_reg, 'COD_RECOMMEND', "Recommended doc #{$doc_id}");
            header("Location: cod_dashboard.php?dept=" . ($_SESSION['selected_department'] ?? '') . "&success=recommended");
            exit();

        case 'cod_not_recommend':
            if (empty($reason)) { header("Location: view_form.php?id={$doc_id}&error=reason_required"); exit(); }

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

        case 'dean_approve':
            $stmt = $conn->prepare("UPDATE documents SET
                status = 'Pending_Finance',
                current_approver = 'finance',
                dean_approved = 1,
                dean_approved_at = ?,
                dean_signer_name = ?,
                dean_signed_at = ?,
                dean_decision = 'approved',
                student_visible_status = 'Under Review – At Finance Office',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("ssssi", $now, $admin_name, $now, $now, $doc_id);
            $stmt->execute();

            _syncFormStatus($conn, $doc_id, 'Pending_Finance');

            createNotification($conn, $student_reg,
                '✅ Document Approved by Dean – Forwarded to Finance',
                "Your {$module_type} application has been approved by the Dean and forwarded to the Finance Office.",
                'status_update', $doc_id);

            _notifyRole($conn, 'finance', '📄 Application Ready – Finance Action Required',
                "A {$module_type} application from {$doc['student_fullname']} has been approved by the Dean. Please review and finalise.",
                $doc_id);

            logActivity($conn, $admin_reg, 'DEAN_APPROVE', "Dean approved doc #{$doc_id}");

            $success_param = 'approved';
            if (in_array($module_type, ['Resit', 'Retake'])) {
                $payStmt = $conn->prepare(
                    "SELECT rrf.exam_month, rrf.exam_year,
                            GROUP_CONCAT(fu.unit_code SEPARATOR ', ') AS unit_codes
                     FROM resit_retake_forms rrf
                     LEFT JOIN form_units fu ON fu.form_id = rrf.id
                     WHERE rrf.document_id = ?
                     GROUP BY rrf.id LIMIT 1"
                );
                $payStmt->bind_param("i", $doc_id);
                $payStmt->execute();
                $payRow = $payStmt->get_result()->fetch_assoc();

                $student_pay  = [
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
                $success_param = 'approved_payment';
            }

            $school = urlencode($_SESSION['selected_school'] ?? '');
            header("Location: dean_dashboard.php?school={$school}&success={$success_param}");
            exit();
        case 'dean_recommend_special':
            $stmt = $conn->prepare("UPDATE documents SET
                status = 'Pending_Registrar',
                current_approver = 'registrar',
                dean_approved = 1,
                dean_approved_at = ?,
                dean_signer_name = ?,
                dean_signed_at = ?,
                dean_decision = 'recommended',
                student_visible_status = 'Under Review – At Registrar\\'s Office',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("ssssi", $now, $admin_name, $now, $now, $doc_id);
            $stmt->execute();

            _syncFormStatus($conn, $doc_id, 'Pending_Registrar');

            createNotification($conn, $student_reg,
                '✅ Application Recommended by Dean',
                "Your Special Exam application has been recommended by the Dean and forwarded to the Registrar's office.",
                'status_update', $doc_id);

            _notifyRole($conn, 'registrar', '📄 Special Exam Application – Action Required',
                "A Special Exam application from {$doc['student_fullname']} has been recommended by the Dean. Please review.",
                $doc_id);

            logActivity($conn, $admin_reg, 'DEAN_RECOMMEND_SPECIAL', "Dean recommended special exam doc #{$doc_id}");
            $school = urlencode($_SESSION['selected_school'] ?? '');
            header("Location: dean_dashboard.php?school={$school}&success=recommended");
            exit();

        
        case 'dean_reject':
            if (empty($reason)) { header("Location: view_form.php?id={$doc_id}&error=reason_required"); exit(); }

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


        case 'registrar_recommend_special':
            $stmt = $conn->prepare("UPDATE documents SET
                status = 'Pending_DVC',
                current_approver = 'none',
                registrar_approved = 1,
                registrar_approved_at = ?,
                registrar_signer_name = ?,
                registrar_signed_at = ?,
                student_visible_status = 'Under Review – At DVC ARSA Office',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("ssssi", $now, $admin_name, $now, $now, $doc_id);
            $stmt->execute();

            _syncFormStatus($conn, $doc_id, 'Pending_DVC');

            createNotification($conn, $student_reg,
                '✅ Application Recommended by Registrar',
                "Your Special Exam application has been recommended by the Registrar and forwarded to the DVC ARSA for final approval.",
                'status_update', $doc_id);

            logActivity($conn, $admin_reg, 'REGISTRAR_RECOMMEND_SPECIAL', "Registrar recommended special exam doc #{$doc_id} to DVC");
            header("Location: registrar_dashboard.php?success=recommended_to_dvc");
            exit();

        
        case 'registrar_not_recommend_special':
            if (empty($reason)) { header("Location: view_form.php?id={$doc_id}&error=reason_required"); exit(); }

            $stmt = $conn->prepare("UPDATE documents SET
                status = 'Rejected',
                rejection_reason = ?,
                rejected_at = ?,
                student_visible_status = 'Not Recommended by Registrar',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("sssi", $reason, $now, $now, $doc_id);
            $stmt->execute();

            _syncFormStatus($conn, $doc_id, 'Rejected');

            createNotification($conn, $student_reg,
                'Application Not Recommended by Registrar',
                "Your Special Exam application was not recommended by the Registrar. Reason: {$reason}",
                'rejection', $doc_id);

            logActivity($conn, $admin_reg, 'REGISTRAR_NOT_RECOMMEND_SPECIAL', "Registrar not recommended doc #{$doc_id}. Reason: {$reason}");
            header("Location: registrar_dashboard.php?success=not_recommended");
            exit();

        case 'registrar_approve_fees':
            $stmt = $conn->prepare("UPDATE documents SET
                status = 'Pending_Finance',
                current_approver = 'finance',
                registrar_approved = 1,
                registrar_approved_at = ?,
                registrar_signer_name = ?,
                student_visible_status = 'Approved by Registrar – At Finance Office',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("sssi", $now, $admin_name, $now, $doc_id);
            $stmt->execute();

            _syncFormStatus($conn, $doc_id, 'Pending_Finance');

            createNotification($conn, $student_reg,
                '✅ Request Approved by Registrar – Forwarded to Finance',
                "Your {$module_type} request has been approved by the Registrar and forwarded to the Finance Office for final processing.",
                'status_update', $doc_id);

            _notifyRole($conn, 'finance', '📄 Fee Adjustment – Finance Action Required',
                "A {$module_type} request from {$doc['student_fullname']} has been approved by the Registrar. Please review and finalise.",
                $doc_id);

            logActivity($conn, $admin_reg, 'REGISTRAR_APPROVE_FEES', "Registrar approved {$module_type} doc #{$doc_id} → Finance");
            header("Location: registrar_dashboard.php?success=fees_approved");
            exit();

        
        case 'registrar_reject_fees':
            if (empty($reason)) { header("Location: view_form.php?id={$doc_id}&error=reason_required"); exit(); }

            $stmt = $conn->prepare("UPDATE documents SET
                status = 'Rejected',
                rejection_reason = ?,
                rejected_at = ?,
                student_visible_status = 'Rejected by Registrar',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("sssi", $reason, $now, $now, $doc_id);
            $stmt->execute();

            _syncFormStatus($conn, $doc_id, 'Rejected');

            createNotification($conn, $student_reg,
                'Request Rejected by Registrar',
                "Your {$module_type} request was rejected by the Registrar. Reason: {$reason}",
                'rejection', $doc_id);

            logActivity($conn, $admin_reg, 'REGISTRAR_REJECT_FEES', "Registrar rejected {$module_type} doc #{$doc_id}. Reason: {$reason}");
            header("Location: registrar_dashboard.php?success=fees_rejected");
            exit();


        case 'finance_finalise':
            $amount_paid = trim($_POST['amount_paid'] ?? '');
            if (empty($amount_paid)) {
                if ($module_type === 'Resit')         $amount_paid = 'KSh 800';
                elseif ($module_type === 'Retake')    $amount_paid = 'KSh 10,023';
                elseif ($module_type === 'Special_Exam') $amount_paid = 'KSh 800';
            }

            $stmt = $conn->prepare("UPDATE documents SET
                status = 'Approved',
                current_approver = 'none',
                finance_approved = 1,
                finance_approved_at = ?,
                finance_signer_name = ?,
                finance_signed_at = ?,
                amount_paid = ?,
                student_visible_status = 'Approved – Document Sent to Your Email',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("sssssi", $now, $admin_name, $now, $amount_paid, $now, $doc_id);
            $stmt->execute();

            _syncFormStatus($conn, $doc_id, 'Approved');

            $friendly_type = ($module_type === 'Special_Exam') ? 'Special Exam' : $module_type;

            createNotification($conn, $student_reg,
                '🎉 Document Finalised – Check Your Email!',
                "Your {$friendly_type} registration form has been finalised by the Finance Office. Your completed form has been sent to your email – please download, print and present it at the Examinations Office.",
                'approval', $doc_id);

            logActivity($conn, $admin_reg, 'FINANCE_FINALISE', "Finance finalised doc #{$doc_id}, amount: {$amount_paid}");

            $finStmt = $conn->prepare(
                "SELECT d.*, u.full_name, u.email, u.reg_number AS student_reg,
                        u.course, u.phone AS student_phone,
                        dept.dept_name,
                        rrf.id AS rrf_id, rrf.exam_month, rrf.exam_year,
                        rrf.student_signature_date
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
                $unitsRes  = $unitsStmt->get_result();
                $units_arr = [];
                while ($ur = $unitsRes->fetch_assoc()) $units_arr[] = $ur;
                $finDoc['units']       = $units_arr;
                $finDoc['amount_paid'] = $amount_paid;

                $student_fin = [
                    'full_name'  => $finDoc['full_name'],
                    'email'      => $finDoc['email'],
                    'reg_number' => $finDoc['student_reg'],
                    'course'     => $finDoc['course'] ?? 'N/A',
                    'phone'      => $finDoc['student_phone'] ?? '',
                ];
                sendFinalisedDocumentEmail($student_fin, $finDoc, $admin_name);
            }

            header("Location: view_form.php?id={$doc_id}&success=finalised");
            exit();

        case 'finance_approve_bursary':
    $stmt = $conn->prepare("UPDATE documents SET
        status = 'Approved',
        current_approver = 'none',
        finance_approved = 1,
        finance_approved_at = ?,
        finance_signer_name = ?,
        finance_signed_at = ?,
        student_visible_status = 'Approved by Finance Office',
        updated_at = ?
        WHERE id = ?");

    $stmt->bind_param("ssssi", $now, $admin_name, $now, $now, $doc_id);
    $stmt->execute();

    _syncFormStatus($conn, $doc_id, 'Approved');
   

            createNotification($conn, $student_reg,
                '✅ Bursary Application Approved',
                "Your Bursary application has been approved by the Finance Office. Please check your email for details.",
                'approval', $doc_id);

            logActivity($conn, $admin_reg, 'FINANCE_APPROVE_BURSARY', "Finance approved Bursary doc #{$doc_id}");

            $bursaryEmail = sendFinanceApprovalEmail(
                ['full_name' => $doc['student_fullname'], 'email' => $doc['student_email'],
                 'reg_number' => $doc['student_reg'], 'course' => $doc['course'] ?? 'N/A'],
                $module_type, $doc['title'], 'approved', '', $admin_name
            );

           // all your updates, notifications, email, logging...

header("Location: finance_dashboard.php?success=bursary_approved");
exit();
        case 'finance_reject_bursary':
            if (empty($reason)) { header("Location: view_form.php?id={$doc_id}&error=reason_required"); exit(); }

            $stmt = $conn->prepare("UPDATE documents SET
                status = 'Rejected',
                finance_rejection_reason = ?,
                rejection_reason = ?,
                rejected_at = ?,
                student_visible_status = 'Rejected by Finance Office',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("ssssi", $reason, $reason, $now, $now, $doc_id);
            $stmt->execute();

            _syncFormStatus($conn, $doc_id, 'Rejected');

            createNotification($conn, $student_reg,
                'Bursary Application Rejected',
                "Your Bursary application was rejected by the Finance Office. Reason: {$reason}",
                'rejection', $doc_id);

            logActivity($conn, $admin_reg, 'FINANCE_REJECT_BURSARY', "Finance rejected Bursary doc #{$doc_id}. Reason: {$reason}");

            sendFinanceApprovalEmail(
                ['full_name' => $doc['student_fullname'], 'email' => $doc['student_email'],
                 'reg_number' => $doc['student_reg'], 'course' => $doc['course'] ?? 'N/A'],
                $module_type, $doc['title'], 'rejected', $reason, $admin_name
            );

            header("Location: finance_dashboard.php?success=bursary_rejected");
            exit();

       
        case 'finance_approve_fees':
            $stmt = $conn->prepare("UPDATE documents SET
                status = 'Approved',
                current_approver = 'none',
                finance_approved = 1,
                finance_approved_at = ?,
                finance_signer_name = ?,
                finance_signed_at = ?,
                student_visible_status = 'Approved by Finance Office – Check Your Email',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("ssssi", $now, $admin_name, $now, $now, $doc_id);
            $stmt->execute();

            _syncFormStatus($conn, $doc_id, 'Approved');

            createNotification($conn, $student_reg,
                '✅ Fee Adjustment Approved – Check Your Email',
                "Your Fee Adjustment request has been approved by the Finance Office. Please check your email for the approval letter.",
                'approval', $doc_id);

            logActivity($conn, $admin_reg, 'FINANCE_APPROVE_FEES', "Finance approved Fee Adjustment doc #{$doc_id}");

            sendFinanceApprovalEmail(
                ['full_name' => $doc['student_fullname'], 'email' => $doc['student_email'],
                 'reg_number' => $doc['student_reg'], 'course' => $doc['course'] ?? 'N/A'],
                $module_type, $doc['title'], 'approved', '', $admin_name
            );

            header("Location: finance_dashboard.php?success=fees_approved");
            exit();
        case 'finance_reject_fees':
            if (empty($reason)) { header("Location: view_form.php?id={$doc_id}&error=reason_required"); exit(); }

            $stmt = $conn->prepare("UPDATE documents SET
                status = 'Rejected',
                finance_rejection_reason = ?,
                rejection_reason = ?,
                rejected_at = ?,
                student_visible_status = 'Rejected by Finance Office',
                updated_at = ?
                WHERE id = ?");
            $stmt->bind_param("ssssi", $reason, $reason, $now, $now, $doc_id);
            $stmt->execute();

            _syncFormStatus($conn, $doc_id, 'Rejected');

            createNotification($conn, $student_reg,
                'Fee Adjustment Request Rejected',
                "Your Fee Adjustment request was rejected by the Finance Office. Reason: {$reason}",
                'rejection', $doc_id);

            logActivity($conn, $admin_reg, 'FINANCE_REJECT_FEES', "Finance rejected Fee Adjustment doc #{$doc_id}. Reason: {$reason}");

            sendFinanceApprovalEmail(
                ['full_name' => $doc['student_fullname'], 'email' => $doc['student_email'],
                 'reg_number' => $doc['student_reg'], 'course' => $doc['course'] ?? 'N/A'],
                $module_type, $doc['title'], 'rejected', $reason, $admin_name
            );

            header("Location: finance_dashboard.php?success=fees_rejected");
            exit();

        case 'finance_send_email':
            $email_subject = trim($_POST['email_subject'] ?? '');
            $email_body    = trim($_POST['email_body'] ?? '');
            if (empty($email_subject) || empty($email_body)) {
                header("Location: view_form.php?id={$doc_id}&error=email_fields_required");
                exit();
            }

            $sendResult = sendFinanceCustomEmail(
                $doc['student_email'],
                $doc['student_fullname'],
                $email_subject,
                $email_body,
                $admin_name
            );

            if ($sendResult['success']) {
                logActivity($conn, $admin_reg, 'FINANCE_EMAIL', "Finance sent custom email for doc #{$doc_id}. Subject: {$email_subject}");
                header("Location: view_form.php?id={$doc_id}&success=email_sent");
            } else {
                header("Location: view_form.php?id={$doc_id}&error=" . urlencode('Email failed: ' . $sendResult['error']));
            }
            exit();

        case 'dvc_approve':
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

            $conn->query("UPDATE special_exam_applications SET
                status = 'Approved_Letter_Sent',
                dvc_decision = 'approved',
                dvc_decision_date = NOW(),
                dvc_name = '" . $conn->real_escape_string($dvc_signer) . "'
                WHERE document_id = {$doc_id}");

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
                $emailResult = sendSpecialExamApprovalLetter($student_data, $seaRow, $dvc_signer);
            }

            createNotification($conn, $student_reg,
                'Special Exam Approved! Check Your Email',
                "Your special exam application has been approved by the DVC. An approval letter has been sent to {$doc['student_email']}. Upload it in the portal to complete your registration.",
                'approval', $doc_id);

            logActivity($conn, $admin_reg, 'DVC_APPROVE_SPECIAL',
                "DVC approved special exam doc #{$doc_id}. Email: " . ($emailResult['success'] ? 'sent' : 'failed – ' . $emailResult['error']));

            $emailNote = $emailResult['success'] ? '&email=sent' : '&email=failed';
            header("Location: admin_dashboard.php?success=dvc_approved{$emailNote}");
            exit();

        
        case 'dvc_reject':
            if (empty($reason)) { header("Location: view_form.php?id={$doc_id}&error=reason_required"); exit(); }

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

// ─────────────────────────────────────────────────────────────────────────
// GET handler (legacy quick-forward links)
// ─────────────────────────────────────────────────────────────────────────
if (isset($_GET['action'], $_GET['id'])) {
    $action  = $_GET['action'];
    $doc_id  = intval($_GET['id']);
    $now     = date('Y-m-d H:i:s');

    $docStmt = $conn->prepare(
        "SELECT d.*, u.reg_number AS student_reg, u.full_name AS student_fullname
         FROM documents d JOIN users u ON d.reg_number = u.reg_number WHERE d.id = ?"
    );
    $docStmt->bind_param("i", $doc_id);
    $docStmt->execute();
    $doc = $docStmt->get_result()->fetch_assoc();

    if (!$doc) { header("Location: admin_dashboard.php?error=doc_not_found"); exit(); }

    $student_reg = $doc['student_reg'];
    $module_type = $doc['module_type'];

    switch ($action) {
        case 'forward_dean':
            $conn->prepare("UPDATE documents SET status='Pending_Dean', current_approver='dean', cod_approved=1, cod_approved_at=?, cod_signer_name=?, student_visible_status='Under Review – At Dean\\'s Office', updated_at=? WHERE id=?")->execute() ?: null;
            $s = $conn->prepare("UPDATE documents SET status='Pending_Dean', current_approver='dean', cod_approved=1, cod_approved_at=?, cod_signer_name=?, student_visible_status='Under Review – At Dean\\'s Office', updated_at=? WHERE id=?");
            $s->bind_param("sssi", $now, $admin_name, $now, $doc_id);
            $s->execute();
            _syncFormStatus($conn, $doc_id, 'Pending_Dean');
            createNotification($conn, $student_reg, 'Forwarded to Dean', "Your {$module_type} has been forwarded to the Dean.", 'status_update', $doc_id);
            header("Location: admin_dashboard.php?success=1");
            exit();

        case 'forward_finance':
            $s = $conn->prepare("UPDATE documents SET status='Pending_Finance', current_approver='finance', student_visible_status='Under Review – At Finance Office', updated_at=? WHERE id=?");
            $s->bind_param("si", $now, $doc_id);
            $s->execute();
            _syncFormStatus($conn, $doc_id, 'Pending_Finance');
            createNotification($conn, $student_reg, 'Forwarded to Finance', "Your {$module_type} has been forwarded to Finance.", 'status_update', $doc_id);
            header("Location: admin_dashboard.php?success=1");
            exit();

        case 'approve':
            $s = $conn->prepare("UPDATE documents SET status='Approved', current_approver='none', finance_approved=1, finance_approved_at=?, finance_signer_name=?, student_visible_status='Approved – Ready to Print', updated_at=? WHERE id=?");
            $s->bind_param("sssi", $now, $admin_name, $now, $doc_id);
            $s->execute();
            _syncFormStatus($conn, $doc_id, 'Approved');
            createNotification($conn, $student_reg, 'Application Approved', "Your {$module_type} has been approved.", 'approval', $doc_id);
            header("Location: admin_dashboard.php?success=1");
            exit();

        case 'reject':
            $reason_get = $_GET['reason'] ?? 'No reason provided';
            $s = $conn->prepare("UPDATE documents SET status='Rejected', rejection_reason=?, rejected_at=?, student_visible_status='Rejected', updated_at=? WHERE id=?");
            $s->bind_param("sssi", $reason_get, $now, $now, $doc_id);
            $s->execute();
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

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────
function _syncFormStatus($conn, $doc_id, $new_status) {
    $s = $conn->real_escape_string($new_status);
    $conn->query("UPDATE resit_retake_forms SET status='{$s}' WHERE document_id={$doc_id}");
    $conn->query("UPDATE special_exam_applications SET status='{$s}' WHERE document_id={$doc_id}");
}

function _notifyRole($conn, $admin_role, $title, $message, $doc_id) {
    $ar = $conn->real_escape_string($admin_role);
    $result = $conn->query("SELECT reg_number FROM users WHERE admin_role='{$ar}' AND is_active=1");
    while ($row = $result->fetch_assoc()) {
        createNotification($conn, $row['reg_number'], $title, $message, 'general', $doc_id);
    }
}