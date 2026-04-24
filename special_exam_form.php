<?php
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php");
    exit();
}

$reg_number = $_SESSION['reg_number'];
$error      = '';
$success    = '';

// Fetch user data
$stmt = $conn->prepare(
    "SELECT u.*, d.dept_name FROM users u 
     LEFT JOIN departments d ON u.department_id = d.id 
     WHERE u.reg_number = ?"
);
$stmt->bind_param("s", $reg_number);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// ──────────────────────────────────────────────────────────────
// CHECK: Has this student already submitted a Phase-1 application?
// ──────────────────────────────────────────────────────────────
$phase1Check = $conn->prepare(
    "SELECT sea.id, sea.status, sea.phase, sea.approval_letter_path,
            sea.exam_month, sea.exam_year, sea.units,
            d.id as doc_id
     FROM special_exam_applications sea
     JOIN documents d ON sea.document_id = d.id
     WHERE sea.reg_number = ? AND sea.phase = 'approval_request'
     ORDER BY sea.created_at DESC LIMIT 1"
);
$phase1Check->bind_param("s", $reg_number);
$phase1Check->execute();
$phase1App = $phase1Check->get_result()->fetch_assoc();

// Student has an approved letter (DVC approved)
$has_approved_letter = $phase1App && $phase1App['status'] === 'Approved_Letter_Sent';

// Phase 1 submitted but still being reviewed
$phase1_pending = $phase1App && !in_array($phase1App['status'], ['Approved_Letter_Sent', 'Rejected']);

// Has the student previously submitted ANY phase-1 application at all?
$has_prior_submission = ($phase1App !== null);

// mode=digital → show digital registration form
$mode = $_GET['mode'] ?? 'request';

// Guard: can only reach digital mode if they have an approved letter
if ($mode === 'digital' && !$has_approved_letter) {
    header("Location: special_exam_form.php");
    exit();
}

// ──────────────────────────────────────────────────────────────
// HANDLE FORM SUBMISSIONS
// ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submit_phase = $_POST['submit_phase'] ?? 'request';

    // ════════════════════════════════════════════════════════════
    // PHASE 1 — Submit initial application (Dean → Registrar → DVC)
    // ════════════════════════════════════════════════════════════
    if ($submit_phase === 'request') {
        $application_type = sanitize($conn, $_POST['application_type'] ?? '');
        $title            = sanitize($conn, $_POST['title'] ?? '');
        $exam_month       = sanitize($conn, $_POST['exam_month'] ?? '');
        $exam_year        = intval($_POST['exam_year'] ?? date('Y'));
        $units            = sanitize($conn, $_POST['units'] ?? '');
        $reason           = sanitize($conn, $_POST['reason_description'] ?? '');

        if (empty($application_type) || empty($title) || empty($exam_month) || empty($units) || empty($reason)) {
            $error = "Please fill in all required fields.";
        } elseif ($phase1_pending) {
            $error = "You already have a pending Special Exam application. Please wait for it to be processed before submitting another.";
        } else {
            $conn->begin_transaction();
            try {
                $evidence_file_name = null; $evidence_file_path = null;
                $evidence_file_size = null; $evidence_file_type = null;

                if ($application_type !== 'Financial' &&
                    isset($_FILES['evidence_file']) && $_FILES['evidence_file']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['evidence_file'];
                    if ($file['size'] > 10 * 1024 * 1024) throw new Exception("Evidence file exceeds 10MB.");
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['pdf','jpg','jpeg','png'])) throw new Exception("Invalid file type. Use PDF, JPG, or PNG.");
                    $dir = 'uploads/evidence/' . date('Y/m') . '/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $reg_number);
                    $evidence_file_path = $dir . $safe . '_evidence_' . time() . '.' . $ext;
                    if (!move_uploaded_file($file['tmp_name'], $evidence_file_path))
                        throw new Exception("Failed to upload evidence file.");
                    $evidence_file_name = $file['name'];
                    $evidence_file_size = $file['size'];
                    $evidence_file_type = $file['type'];
                }

                // Phase-1 goes to Dean first
                $status          = 'Pending_Dean';
                $student_visible = 'Under Review – At Dean\'s Office';
                $docStmt = $conn->prepare(
                    "INSERT INTO documents (reg_number, module_type, title, description, status, current_approver, student_visible_status)
                     VALUES (?, 'Special_Exam', ?, ?, ?, 'dean', ?)"
                );
                $docStmt->bind_param("sssss", $reg_number, $title, $reason, $status, $student_visible);
                $docStmt->execute();
                $document_id = $conn->insert_id;

                $formStmt = $conn->prepare(
                    "INSERT INTO special_exam_applications
                     (document_id, reg_number, application_type, exam_month, exam_year, units,
                      reason_description, evidence_file_name, evidence_file_path, evidence_file_size,
                      evidence_file_type, student_name, student_phone, student_email, course,
                      department_id, year_of_study, student_declaration, student_signature_date,
                      status, phase)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, NOW(), 'Pending_Dean', 'approval_request')"
                );
                $formStmt->bind_param(
                    "isssissssisssssii",
                    $document_id, $reg_number, $application_type, $exam_month, $exam_year,
                    $units, $reason, $evidence_file_name, $evidence_file_path,
                    $evidence_file_size, $evidence_file_type,
                    $user['full_name'], $user['phone'], $user['email'],
                    $user['course'], $user['department_id'], $user['year_of_study']
                );
                $formStmt->execute();

                createNotification($conn, $reg_number,
                    'Special Exam Application Submitted',
                    "Your special exam application ({$application_type}) has been submitted for review.",
                    'status_update', $document_id
                );
                logActivity($conn, $reg_number, 'Special Exam Phase-1', "Submitted {$application_type} special exam application");
                $conn->commit();
                $success = "request_submitted";

            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }

    // ════════════════════════════════════════════════════════════
    // PHASE 2 — Digital registration form (after approved letter)
    // ════════════════════════════════════════════════════════════
    } elseif ($submit_phase === 'digital') {
        if (!$has_approved_letter) {
            $error = "You need an approved letter before filling the digital form.";
        } else {
            $exam_month  = sanitize($conn, $_POST['exam_month'] ?? '');
            $exam_year   = intval($_POST['exam_year'] ?? date('Y'));
            $title       = sanitize($conn, $_POST['title'] ?? '');
            $declaration = isset($_POST['declaration']) ? 1 : 0;
            $unit_codes  = $_POST['unit_codes'] ?? [];
            $unit_titles = $_POST['unit_titles'] ?? [];

            $letter_path = null;
            if (isset($_FILES['approval_letter']) && $_FILES['approval_letter']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['approval_letter'];
                $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf','jpg','jpeg','png'])) {
                    $error = "Approval letter must be PDF, JPG, or PNG.";
                } else {
                    $dir = 'uploads/approval_letters/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $reg_number);
                    $letter_path = $dir . $safe . '_letter_' . time() . '.' . $ext;
                    if (!move_uploaded_file($file['tmp_name'], $letter_path)) {
                        $error = "Failed to upload approval letter.";
                        $letter_path = null;
                    }
                }
            } else {
                $error = "Please attach your approved letter.";
            }

            if (empty($error)) {
                if (empty($exam_month) || empty($title)) {
                    $error = "Please fill in all required fields.";
                } elseif (!$declaration) {
                    $error = "You must agree to the declaration.";
                } elseif (count($unit_codes) === 0 || empty($unit_codes[0])) {
                    $error = "Please add at least one unit.";
                } else {
                    $conn->begin_transaction();
                    try {
                        $status          = 'Pending_COD';
                        $student_visible = 'Under Review – At COD Office';
                        $docStmt = $conn->prepare(
                            "INSERT INTO documents (reg_number, module_type, title, description, status, current_approver, student_visible_status)
                             VALUES (?, 'Special_Exam', ?, ?, ?, 'cod', ?)"
                        );
                        $desc = "Special Exam Digital Registration";
                        $docStmt->bind_param("sssss", $reg_number, $title, $desc, $status, $student_visible);
                        $docStmt->execute();
                        $document_id = $conn->insert_id;

                        $sig      = $user['full_name'];
                        $sig_date = date('Y-m-d');
                        $formStmt = $conn->prepare(
                            "INSERT INTO resit_retake_forms
                             (document_id, reg_number, exam_type, exam_month, exam_year,
                              student_name, student_phone, student_email, course, department_id,
                              year_of_study, student_declaration, student_signature, student_signature_date, status)
                             VALUES (?, ?, 'Special', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending_COD')"
                        );
                        $formStmt->bind_param(
                            "isssissssiiiss",
                            $document_id, $reg_number, $exam_month, $exam_year,
                            $user['full_name'], $user['phone'], $user['email'], $user['course'],
                            $user['department_id'], $user['year_of_study'], $declaration, $sig, $sig_date
                        );
                        $formStmt->execute();
                        $form_id = $conn->insert_id;

                        $unitStmt = $conn->prepare("INSERT INTO form_units (form_id, unit_code, unit_title) VALUES (?, ?, ?)");
                        for ($i = 0; $i < count($unit_codes); $i++) {
                            if (!empty($unit_codes[$i])) {
                                $unitStmt->bind_param("iss", $form_id, $unit_codes[$i], $unit_titles[$i]);
                                $unitStmt->execute();
                            }
                        }

                        if ($letter_path && $phase1App) {
                            $lp = $conn->real_escape_string($letter_path);
                            $conn->query("UPDATE special_exam_applications SET approval_letter_path='{$lp}' WHERE id={$phase1App['id']}");
                        }

                        createNotification($conn, $reg_number,
                            'Special Exam Registration Submitted',
                            "Your special exam registration form has been submitted and is awaiting COD review.",
                            'status_update', $document_id
                        );
                        logActivity($conn, $reg_number, 'Special Exam Phase-2', "Submitted special exam digital form");
                        $conn->commit();
                        $success = "digital_submitted";

                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = $e->getMessage();
                    }
                }
            }
        }
    }
}

// After a request submit, refresh phase state for the view
if ($success === 'request_submitted') {
    $phase1_pending      = true;
    $has_prior_submission = true;
    $has_approved_letter = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Special Exam Application | MUT Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--primary:#0d9488;--primary-dark:#0f766e;--bg:#f1f5f9;--card:#fff;--text:#1e293b;--text-light:#64748b;--border:#e2e8f0;--shadow:0 4px 6px -1px rgba(0,0,0,.1);--shadow-lg:0 10px 15px -3px rgba(0,0,0,.1);--danger:#ef4444;--success:#22c55e;--purple:#7c3aed}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding:32px 20px}
.container{max-width:900px;margin:0 auto}

.back-link{display:inline-flex;align-items:center;gap:8px;color:var(--text-light);text-decoration:none;margin-bottom:20px;font-size:.875rem;font-weight:500;transition:color .2s}
.back-link:hover{color:var(--primary)}

.page-header{text-align:center;margin-bottom:28px}
.page-header img{width:72px;height:72px;margin-bottom:12px}
.page-header h1{font-size:1.4rem;font-weight:700;margin-bottom:4px}
.page-header p{color:var(--text-light);font-size:.875rem}

/* GUIDELINES */
.guidelines{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:16px;padding:22px 24px;margin-bottom:24px}
.guidelines-header{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.guidelines-header i{color:#22c55e;font-size:1.1rem}
.guidelines-header h3{font-size:1rem;font-weight:700;color:#166534}
.guidelines-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:10px}
.guideline-item{background:#fff;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;display:flex;align-items:flex-start;gap:10px}
.guideline-item i{color:#22c55e;margin-top:2px;flex-shrink:0;font-size:.9rem}
.guideline-item p{font-size:.825rem;color:#166534;line-height:1.5}
.guideline-highlight{background:#dcfce7;border:1px solid #86efac;border-radius:10px;padding:12px 16px;display:flex;align-items:flex-start;gap:10px}
.guideline-highlight i{color:#16a34a;flex-shrink:0;margin-top:2px}
.guideline-highlight p{font-size:.825rem;color:#166534;font-weight:500;line-height:1.5}

/* STATUS BANNERS */
.status-banner{padding:14px 18px;border-radius:12px;margin-bottom:18px;display:flex;align-items:flex-start;gap:12px;font-size:.875rem}
.status-banner i{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.status-banner > div strong{display:block;margin-bottom:2px}
.status-pending{background:#fef3c7;border:1px solid #fde68a;color:#92400e}
.status-approved-banner{background:#d1fae5;border:1px solid #a7f3d0;color:#065f46}
.status-rejected-banner{background:#fee2e2;border:1px solid #fecaca;color:#991b1b}

/* UPLOAD APPROVED DOCUMENT – flat sketch layout */
.upload-section{margin-bottom:16px}
.upload-section-title{font-size:1.15rem;font-weight:700;color:var(--text);margin-bottom:14px;padding-bottom:10px;border-bottom:2px solid var(--border)}
/* Active drop zone */
.drop-zone{border:2px dashed #a78bfa;border-radius:12px;padding:40px 20px;text-align:center;cursor:pointer;transition:all .2s;position:relative;background:#faf5ff}
.drop-zone:hover,.drop-zone.dragging{border-color:var(--purple);background:#f5f3ff}
.drop-zone input[type="file"]{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
.drop-zone .dz-icon{font-size:2.2rem;color:var(--purple);margin-bottom:12px;display:block}
.drop-zone p{font-size:.95rem;font-weight:600;color:var(--text);margin-bottom:4px}
.drop-zone span{font-size:.8rem;color:var(--text-light)}
/* Locked drop zone */
.drop-zone-locked{border:2px dashed var(--border);border-radius:12px;padding:40px 20px;text-align:center;background:var(--bg);opacity:.6}
.drop-zone-locked p{font-size:.9rem;font-weight:600;color:var(--text-light);margin-bottom:4px}
.drop-zone-locked span{font-size:.8rem;color:var(--text-light)}
/* File chosen indicator */
.file-chosen{margin-top:12px;display:none;align-items:center;gap:10px;padding:10px 16px;background:#ede9fe;border-radius:8px;font-size:.875rem;font-weight:600;color:var(--purple)}
.file-chosen.visible{display:flex}

/* FILL DIGITAL FORM BUTTON */
.fill-digital-wrap{margin-bottom:32px}
.btn-fill-digital{width:100%;padding:16px 24px;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;transition:all .2s;border:2px solid transparent;margin-top:12px}
.btn-fill-digital.locked{background:var(--bg);color:var(--text-light);border-color:var(--border);cursor:not-allowed}
.btn-fill-digital.enabled{background:linear-gradient(135deg,var(--purple) 0%,#8b5cf6 100%);color:white;border-color:transparent;box-shadow:0 4px 16px rgba(124,58,237,.3)}
.btn-fill-digital.enabled:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(124,58,237,.4)}
.fill-digital-note{text-align:center;font-size:.8rem;color:var(--text-light);margin-top:6px}

/* SECTION DIVIDER */
.section-divider{display:flex;align-items:center;gap:16px;margin:28px 0 20px}
.section-divider::before,.section-divider::after{content:'';flex:1;height:1px;background:var(--border)}
.section-divider span{font-size:.875rem;font-weight:700;color:var(--text-light);white-space:nowrap;padding:0 4px}

/* FORM CARD */
.form-card{background:var(--card);border-radius:16px;box-shadow:var(--shadow);overflow:hidden;margin-bottom:28px}
.form-hdr{background:linear-gradient(135deg,#0d9488 0%,#14b8a6 100%);color:white;padding:20px 26px}
.form-hdr h2{font-size:1.1rem;font-weight:700;margin-bottom:3px;display:flex;align-items:center;gap:8px}
.form-hdr p{opacity:.9;font-size:.825rem}
.form-body{padding:28px 26px}
.alert{padding:14px 16px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:.875rem}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#16a34a}
.f-section{margin-bottom:24px}
.f-section-title{font-size:.95rem;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid var(--border);display:flex;align-items:center;gap:8px;color:var(--text)}
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.form-group{margin-bottom:4px}
.form-group.full-width{grid-column:1/-1}
.form-group label{display:block;font-weight:600;font-size:.8rem;margin-bottom:7px}
.form-group label .required{color:#dc2626}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:11px 14px;border:2px solid var(--border);border-radius:9px;font-size:.9rem;font-family:inherit;transition:all .2s;color:var(--text)}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--primary);outline:none;box-shadow:0 0 0 3px rgba(13,148,136,.1)}
.form-group input[readonly]{background:var(--bg);cursor:not-allowed;color:var(--text-light)}

/* App type cards */
.app-type-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}
.app-type-card{padding:18px 12px;border:2px solid var(--border);border-radius:12px;text-align:center;cursor:pointer;transition:all .2s}
.app-type-card:hover{border-color:var(--primary);transform:translateY(-2px);box-shadow:var(--shadow)}
.app-type-card.selected{border-color:var(--primary);background:rgba(13,148,136,.07)}
.app-type-card i{font-size:1.7rem;margin-bottom:9px;display:block}
.app-type-card h4{font-size:.9rem;font-weight:700;margin-bottom:3px}
.app-type-card p{font-size:.72rem;color:var(--text-light)}
.app-type-card.financial i{color:#059669}
.app-type-card.medical i{color:#2563eb}
.app-type-card.compassionate i{color:#7c3aed}

/* Evidence */
.evidence-section{background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;padding:18px;margin-bottom:18px}
.evidence-section h4{font-size:.9rem;color:#0369a1;margin-bottom:8px;display:flex;align-items:center;gap:8px}
.evidence-note{font-size:.8rem;color:#0369a1;margin-bottom:10px}
.file-upload-area{border:2px dashed var(--border);border-radius:10px;padding:22px;text-align:center;cursor:pointer;transition:all .2s;position:relative}
.file-upload-area:hover{border-color:var(--primary)}
.file-upload-area i{font-size:1.8rem;color:var(--primary);margin-bottom:8px;display:block}
.file-upload-area p{color:var(--text-light);font-size:.875rem;margin-bottom:4px}
.file-upload-area span{font-size:.75rem;color:var(--text-light)}
.file-upload-area input[type="file"]{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
.file-name-display{margin-top:8px;font-size:.875rem;color:var(--primary);font-weight:600;display:none}
.file-name-display.visible{display:flex;align-items:center;gap:8px}

/* Declaration */
.declaration-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:16px;margin-bottom:18px}
.declaration-text{font-size:.825rem;color:#166534;line-height:1.6;margin-bottom:12px}
.checkbox-group{display:flex;align-items:flex-start;gap:10px}
.checkbox-group input[type="checkbox"]{width:18px;height:18px;margin-top:2px;accent-color:var(--primary);flex-shrink:0}
.checkbox-group label{font-size:.875rem;color:#166534;font-weight:500}

/* Buttons */
.form-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:8px}
.btn{padding:12px 24px;border-radius:10px;font-size:.9rem;font-weight:600;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:8px;text-decoration:none;border:none}
.btn-primary{background:var(--primary);color:white}
.btn-primary:hover{background:var(--primary-dark);transform:translateY(-2px)}
.btn-secondary{background:var(--bg);color:var(--text);border:2px solid var(--border)}
.btn-secondary:hover{background:var(--border)}

/* Units table */
.units-table{width:100%;border-collapse:collapse;margin-bottom:8px}
.units-table th,.units-table td{padding:8px 12px;border:1px solid var(--border);font-size:.875rem;text-align:center}
.units-table th{background:var(--bg);font-weight:600}
.units-table input{width:100%;border:none;background:transparent;font-size:.875rem;text-align:center;padding:2px;font-family:inherit}
.unit-btns{display:flex;gap:8px;margin-top:6px}
.btn-unit{padding:5px 14px;font-size:.8rem;border:1px solid var(--border);background:white;border-radius:6px;cursor:pointer;font-family:inherit;transition:background .2s}
.btn-unit:hover{background:var(--bg)}
.btn-unit-remove{color:var(--danger);border-color:var(--danger)}
.btn-unit-remove:hover{background:#fee2e2}

/* Signature */
.student-sig-display{font-style:italic;font-family:Georgia,'Times New Roman',serif;font-size:1.3rem;color:#1e293b;border-bottom:2px solid #1e293b;display:inline-block;min-width:200px;padding:4px 0;margin-top:6px}

/* Success panel */
.success-panel{background:var(--card);border-radius:16px;box-shadow:var(--shadow);padding:48px 32px;text-align:center}
.success-panel .s-icon{width:84px;height:84px;border-radius:50%;background:#d1fae5;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:2.2rem;color:#22c55e}
.success-panel h2{font-size:1.5rem;font-weight:700;margin-bottom:12px}
.success-panel p{color:var(--text-light);font-size:.9rem;margin-bottom:28px;line-height:1.7;max-width:500px;margin-left:auto;margin-right:auto}

@media(max-width:640px){
    .form-grid,.guidelines-grid,.app-type-grid{grid-template-columns:1fr}
    .form-body{padding:20px}
}
</style>
</head>
<body>
<div class="container">
    <a href="index.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>

    <!-- Page Header -->
    <div class="page-header">
        <img src="assets/images/mut_logo.png" alt="MUT Logo">
        <h1>Murang'a University of Technology</h1>
        <p>Office of Registrar (Academic and Student Affairs) — Special Examination</p>
    </div>

    <?php if ($success === 'request_submitted'): ?>
    <!-- ════ REQUEST SUBMITTED SUCCESS ════ -->
    <div class="success-panel">
        <div class="s-icon"><i class="fa-solid fa-paper-plane"></i></div>
        <h2>Application Submitted!</h2>
        <p>
            Your special exam application has been submitted successfully.<br>
            It will be reviewed by: <strong>Dean → Registrar → DVC ARSA</strong>.<br><br>
            If approved, an <strong>approval letter</strong> will be sent to
            <strong><?php echo htmlspecialchars($user['email']); ?></strong>.
            A copy will also be forwarded to the Dean.<br><br>
            Return here once you receive your approval letter to complete the digital registration form.
        </p>
        <a href="index.php" class="btn btn-primary"><i class="fa-solid fa-house"></i> Back to Dashboard</a>
    </div>

    <?php elseif ($success === 'digital_submitted'): ?>
    <!-- ════ DIGITAL FORM SUBMITTED SUCCESS ════ -->
    <div class="success-panel">
        <div class="s-icon"><i class="fa-solid fa-check-double"></i></div>
        <h2>Registration Form Submitted!</h2>
        <p>
            Your special exam registration form has been submitted successfully.<br>
            It is now awaiting review by your <strong>COD</strong> and will proceed through the full approval chain.
        </p>
        <a href="index.php" class="btn btn-primary"><i class="fa-solid fa-house"></i> Back to Dashboard</a>
    </div>

    <?php else: ?>

    <!-- ════════════════════════════════════════
         SUBMISSION GUIDELINES
    ════════════════════════════════════════ -->
    <div class="guidelines">
        <div class="guidelines-header">
            <i class="fa-solid fa-circle-info"></i>
            <h3>Submission Guidelines</h3>
        </div>
        <div class="guidelines-grid">
            <div class="guideline-item">
                <i class="fa-solid fa-check-circle"></i>
                <p>Select your application type: <strong>Financial</strong>, <strong>Medical</strong>, or <strong>Compassionate</strong></p>
            </div>
            <div class="guideline-item">
                <i class="fa-solid fa-check-circle"></i>
                <p><strong>Financial:</strong> No evidence needed — verified through fee status</p>
            </div>
            <div class="guideline-item">
                <i class="fa-solid fa-check-circle"></i>
                <p><strong>Medical:</strong> Attach medical certificate or hospital documentation</p>
            </div>
            <div class="guideline-item">
                <i class="fa-solid fa-check-circle"></i>
                <p><strong>Compassionate:</strong> Attach death certificate or relevant documentation</p>
            </div>
            <div class="guideline-item">
                <i class="fa-solid fa-check-circle"></i>
                <p>Submit as early as possible before the exam period</p>
            </div>
        </div>
        <div class="guideline-highlight">
            <i class="fa-solid fa-lightbulb"></i>
            <p>If already submitted a request and obtained an <strong>approved letter</strong>, attach it to fill in the digital form for application.</p>
        </div>
    </div>

    <!-- Status banners -->
    <?php if ($phase1_pending): ?>
    <div class="status-banner status-pending">
        <i class="fa-solid fa-clock"></i>
        <div>
            <strong>Application Under Review</strong>
            Your special exam request is being reviewed (Dean → Registrar → DVC ARSA). You will receive an email notification when a decision is made.
        </div>
    </div>
    <?php endif; ?>

    <?php if ($has_approved_letter): ?>
    <div class="status-banner status-approved-banner">
        <i class="fa-solid fa-check-circle"></i>
        <div>
            <strong>Approval Letter Issued!</strong>
            Your application has been approved by the DVC ARSA. An approval letter was sent to <strong><?php echo htmlspecialchars($user['email']); ?></strong>. Upload it below, then click "Fill Digital Form".
        </div>
    </div>
    <?php endif; ?>

    <?php if ($phase1App && $phase1App['status'] === 'Rejected'): ?>
    <div class="status-banner status-rejected-banner">
        <i class="fa-solid fa-xmark-circle"></i>
        <div>
            <strong>Application Rejected</strong>
            Your previous special exam application was not approved. You may submit a new application below.
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════
         UPLOAD APPROVED DOCUMENT  +  FILL DIGITAL FORM
    ════════════════════════════════════════ -->
    <div class="upload-section">
        <h2 class="upload-section-title">Upload Approved Document</h2>

        <?php if ($has_approved_letter): ?>
        <!-- ── UNLOCKED: DVC approved, student can upload their letter ── -->
        <div class="drop-zone" id="dropZone"
             ondragover="handleDrag(event,true)"
             ondragleave="handleDrag(event,false)"
             ondrop="handleDrop(event)">
            <input type="file" id="letterFileInput" accept=".pdf,.jpg,.jpeg,.png"
                   onchange="onLetterChosen(this)"
                   onclick="event.stopPropagation()">
            <i class="fa-solid fa-cloud-arrow-up dz-icon"></i>
            <p>Click to upload or drag and drop</p>
            <span>PDF, JPG, PNG (Max 10MB)</span>
        </div>
        <div class="file-chosen" id="fileChosen">
            <i class="fa-solid fa-file-circle-check"></i>
            <span id="chosenFileName"></span>
        </div>

        <?php else: ?>
        <!-- ── LOCKED (pending / rejected / no submission) ── -->
        <div class="drop-zone-locked">
            <?php if ($phase1_pending): ?>
                <i class="fa-solid fa-hourglass-half" style="color:#d97706;font-size:2rem;display:block;margin-bottom:10px;"></i>
                <p>Awaiting approval letter</p>
                <span>You will receive an email once the DVC ARSA approves your application</span>
            <?php elseif ($phase1App && $phase1App['status'] === 'Rejected'): ?>
                <i class="fa-solid fa-lock" style="color:var(--text-light);font-size:2rem;display:block;margin-bottom:10px;"></i>
                <p>No approved letter available</p>
                <span>Submit a new application below to start the process again</span>
            <?php else: ?>
                <i class="fa-solid fa-lock" style="color:var(--text-light);font-size:2rem;display:block;margin-bottom:10px;"></i>
                <p>Submit an application first</p>
                <span>Use the "Submit New Special Exam Request" form below</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Fill Digital Form Button -->
    <div class="fill-digital-wrap">
        <?php if ($has_approved_letter): ?>
        <button type="button" id="fillDigitalBtn"
                class="btn-fill-digital locked"
                onclick="proceedToDigital()"
                disabled>
            <i class="fa-solid fa-file-pen"></i> Fill Digital Form
        </button>
        <p class="fill-digital-note" id="digitalBtnNote">Upload your approved letter above to unlock</p>
        <?php else: ?>
        <button type="button" class="btn-fill-digital locked" disabled>
            <i class="fa-solid fa-lock"></i> Fill Digital Form
        </button>
        <p class="fill-digital-note">
            <?php if ($phase1_pending): ?>
                Available once DVC ARSA approves your application
            <?php else: ?>
                Submit an application and obtain an approved letter first
            <?php endif; ?>
        </p>
        <?php endif; ?>
    </div>

    <!-- ════════════════════════════════════════
         DIGITAL REGISTRATION FORM (Phase 2)
         Shown when mode=digital AND has_approved_letter
    ════════════════════════════════════════ -->
    <?php if ($mode === 'digital' && $has_approved_letter): ?>
    <div class="section-divider"><span>Digital Registration Form</span></div>
    <div class="form-card">
        <div class="form-hdr" style="background:linear-gradient(135deg,#7c3aed 0%,#8b5cf6 100%);">
            <h2><i class="fa-solid fa-file-pen"></i> Special Exam Digital Registration Form</h2>
            <p>Complete your special exam registration. Attach your approved letter below.</p>
        </div>
        <div class="form-body">
            <?php if ($error): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data" id="phase2Form">
                <input type="hidden" name="submit_phase" value="digital">
                <input type="hidden" name="exam_type" value="Special">
                <input type="hidden" name="title" id="digitalTitle" value="">

                <!-- Attach Approval Letter -->
                <div class="f-section">
                    <h3 class="f-section-title"><i class="fa-solid fa-file-circle-check" style="color:var(--purple);"></i> Attach Your Approved Letter <span style="color:#dc2626;">*</span></h3>
                    <div class="file-upload-area" onclick="document.getElementById('approval_letter').click()">
                        <i class="fa-solid fa-file-arrow-up" style="color:var(--purple);"></i>
                        <p>Click to upload your approved letter</p>
                        <span>PDF, JPG, PNG (Max 10MB)</span>
                        <input type="file" id="approval_letter" name="approval_letter"
                               accept=".pdf,.jpg,.jpeg,.png"
                               onchange="showFileNameDisplay(this,'letterFileName2')" required>
                    </div>
                    <div class="file-name-display" id="letterFileName2">
                        <i class="fa-solid fa-check-circle"></i><span></span>
                    </div>
                </div>

                <!-- Exam Period -->
                <div class="f-section">
                    <h3 class="f-section-title"><i class="fa-solid fa-calendar"></i> Examination Period</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Examination Month <span class="required">*</span></label>
                            <select name="exam_month" required>
                                <option value="">Select Month</option>
                                <option value="December">December</option>
                                <option value="April">April</option>
                                <option value="August">August</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Year <span class="required">*</span></label>
                            <select name="exam_year" required>
                                <?php for ($y=date('Y'); $y<=date('Y')+1; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y==date('Y')?'selected':''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Units -->
                <div class="f-section">
                    <h3 class="f-section-title"><i class="fa-solid fa-list-check"></i> Units to be Written</h3>
                    <table class="units-table">
                        <thead><tr><th>#</th><th>Unit Code</th><th>Unit Title</th></tr></thead>
                        <tbody id="unitsBody2">
                            <?php for ($i=1; $i<=5; $i++): ?>
                            <tr>
                                <td><?php echo $i; ?></td>
                                <td><input type="text" name="unit_codes[]" <?php echo $i===1?'placeholder="e.g. BIT 2101" required':''; ?>></td>
                                <td><input type="text" name="unit_titles[]" <?php echo $i===1?'placeholder="e.g. Database Systems"':''; ?>></td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                    <div class="unit-btns">
                        <button type="button" class="btn-unit" onclick="addRow()">+ Add Row</button>
                        <button type="button" class="btn-unit btn-unit-remove" onclick="removeRow()">− Remove</button>
                    </div>
                </div>

                <!-- Applicant Info -->
                <div class="f-section">
                    <h3 class="f-section-title"><i class="fa-solid fa-user"></i> Applicant Information</h3>
                    <div class="form-grid">
                        <div class="form-group full-width"><label>Student Name</label><input type="text" value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly></div>
                        <div class="form-group"><label>Registration Number</label><input type="text" value="<?php echo htmlspecialchars($user['reg_number']); ?>" readonly></div>
                        <div class="form-group"><label>Course</label><input type="text" value="<?php echo htmlspecialchars($user['course'] ?? ''); ?>" readonly></div>
                        <div class="form-group"><label>Department</label><input type="text" value="<?php echo htmlspecialchars($user['dept_name'] ?? ''); ?>" readonly></div>
                        <div class="form-group"><label>Email</label><input type="text" value="<?php echo htmlspecialchars($user['email']); ?>" readonly></div>
                        <div class="form-group"><label>Phone</label><input type="text" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" readonly></div>
                    </div>
                </div>

                <!-- Declaration & Signature -->
                <div class="f-section">
                    <h3 class="f-section-title"><i class="fa-solid fa-pen-nib"></i> Declaration &amp; Signature</h3>
                    <div class="declaration-box">
                        <p class="declaration-text">I declare that the information provided is accurate and that I hold an approved letter authorising me to sit this special examination.</p>
                        <div class="checkbox-group">
                            <input type="checkbox" name="declaration" id="decl2" required>
                            <label for="decl2">I agree to the above declaration <span class="required">*</span></label>
                        </div>
                    </div>
                    <div>
                        <label style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:var(--text-light);display:block;margin-bottom:8px;">Signature (auto-generated from your login)</label>
                        <div class="student-sig-display"><?php echo htmlspecialchars($user['full_name']); ?></div>
                        <div style="font-size:.8rem;color:var(--text-light);margin-top:6px;">Date: <?php echo date('F j, Y'); ?></div>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="special_exam_form.php" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Cancel</a>
                    <button type="submit" class="btn btn-primary" onclick="return validateDigital()">
                        <i class="fa-solid fa-paper-plane"></i> Submit Registration Form
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif (!$phase1_pending): ?>
    <!-- ════════════════════════════════════════
         SUBMIT NEW SPECIAL EXAM REQUEST (Phase 1)
         Only shown when no pending application
    ════════════════════════════════════════ -->
    <div class="section-divider"><span>Submit New Special Exam Request</span></div>
    <div class="form-card">
        <div class="form-hdr">
            <h2><i class="fa-solid fa-file-circle-plus"></i> Submit New Special Exam Request</h2>
            <p>Apply on Financial, Medical, or Compassionate grounds. Reviewed by Dean → Registrar → DVC ARSA.</p>
        </div>
        <div class="form-body">
            <?php if ($error): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data" id="phase1Form">
                <input type="hidden" name="submit_phase" value="request">

                <!-- Application Type -->
                <div class="f-section">
                    <h3 class="f-section-title"><i class="fa-solid fa-layer-group"></i> Select Application Type <span style="color:#dc2626">*</span></h3>
                    <div class="app-type-grid">
                        <div class="app-type-card financial" onclick="selectType('Financial',event)">
                            <i class="fa-solid fa-money-bill-wave"></i>
                            <h4>Financial</h4><p>Fee payment constraints</p>
                        </div>
                        <div class="app-type-card medical" onclick="selectType('Medical',event)">
                            <i class="fa-solid fa-notes-medical"></i>
                            <h4>Medical</h4><p>Health-related issues</p>
                        </div>
                        <div class="app-type-card compassionate" onclick="selectType('Compassionate',event)">
                            <i class="fa-solid fa-heart"></i>
                            <h4>Compassionate</h4><p>Family bereavement</p>
                        </div>
                    </div>
                    <input type="hidden" name="application_type" id="application_type" required>
                </div>

                <!-- Application Details -->
                <div class="f-section">
                    <h3 class="f-section-title"><i class="fa-solid fa-file-lines"></i> Application Details</h3>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="req_title">Request Title <span class="required">*</span></label>
                            <input type="text" id="req_title" name="title"
                                   placeholder="e.g., Special Exam Application – Medical Grounds" required>
                        </div>
                        <div class="form-group">
                            <label for="exam_month">Examination Period <span class="required">*</span></label>
                            <select id="exam_month" name="exam_month" required>
                                <option value="">Select Month</option>
                                <option value="December">December</option>
                                <option value="April">April</option>
                                <option value="August">August</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="exam_year">Year <span class="required">*</span></label>
                            <select id="exam_year" name="exam_year" required>
                                <?php for ($y=date('Y'); $y<=date('Y')+1; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y==date('Y')?'selected':''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group full-width">
                            <label>Units to be Written <span class="required">*</span></label>
                            <table class="units-table" id="unitsTable1">
                                <thead>
                                    <tr><th>#</th><th>Unit Code</th><th>Unit Title / Name</th></tr>
                                </thead>
                                <tbody id="unitsBody1">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <tr>
                                        <td><?php echo $i; ?></td>
                                        <td><input type="text" name="unit_codes_p1[]" placeholder="e.g. BCP 316" <?php echo $i===1?'required':''; ?>></td>
                                        <td><input type="text" name="unit_titles_p1[]" placeholder="e.g. POM"></td>
                                    </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                            <div class="unit-btns">
                                <button type="button" class="btn-unit" onclick="addRowP1()">+ Add Row</button>
                                <button type="button" class="btn-unit btn-unit-remove" onclick="removeRowP1()">− Remove</button>
                            </div>
                            <!-- Hidden field to combine units for backend -->
                            <input type="hidden" name="units" id="unitsHiddenP1">
                        </div>
                        <div class="form-group full-width">
                            <label for="reason_description">Detailed Reason <span class="required">*</span></label>
                            <textarea id="reason_description" name="reason_description" rows="4"
                                      placeholder="Describe in detail why you are applying for a special exam…" required></textarea>
                        </div>
                    </div>
                </div>

                <!-- Evidence Upload -->
                <div class="evidence-section" id="evidenceSection" style="display:none;">
                    <h4><i class="fa-solid fa-paperclip"></i> Supporting Evidence</h4>
                    <p class="evidence-note" id="evidenceNote"></p>
                    <div class="file-upload-area">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <p>Click to upload evidence</p>
                        <span>PDF, JPG, PNG (Max 10MB)</span>
                        <input type="file" id="evidence_file" name="evidence_file"
                               accept=".pdf,.jpg,.jpeg,.png"
                               onchange="showFileNameDisplay(this,'evidenceFileName')">
                    </div>
                    <div class="file-name-display" id="evidenceFileName">
                        <i class="fa-solid fa-check-circle"></i><span></span>
                    </div>
                </div>

                <div id="financialNote" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px;margin-bottom:16px;">
                    <p style="color:#166534;font-size:.85rem;display:flex;align-items:center;gap:8px;">
                        <i class="fa-solid fa-check-circle"></i>
                        No evidence required for Financial applications. Fee status will be verified by the Registrar.
                    </p>
                </div>

                <!-- Applicant Info -->
                <div class="f-section">
                    <h3 class="f-section-title"><i class="fa-solid fa-user"></i> Applicant Information</h3>
                    <div class="form-grid">
                        <div class="form-group full-width"><label>Student Name</label><input type="text" value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly></div>
                        <div class="form-group"><label>Registration Number</label><input type="text" value="<?php echo htmlspecialchars($user['reg_number']); ?>" readonly></div>
                        <div class="form-group"><label>Course</label><input type="text" value="<?php echo htmlspecialchars($user['course'] ?? 'N/A'); ?>" readonly></div>
                        <div class="form-group"><label>Department</label><input type="text" value="<?php echo htmlspecialchars($user['dept_name'] ?? 'N/A'); ?>" readonly></div>
                        <div class="form-group"><label>Phone</label><input type="text" value="<?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?>" readonly></div>
                        <div class="form-group"><label>Email</label><input type="text" value="<?php echo htmlspecialchars($user['email']); ?>" readonly></div>
                    </div>
                </div>

                <!-- Declaration -->
                <div class="declaration-box">
                    <p class="declaration-text">I declare that all information provided is true and accurate. I understand that providing false information may result in rejection and disciplinary action.</p>
                    <div class="checkbox-group">
                        <input type="checkbox" id="declaration" name="declaration" required>
                        <label for="declaration">I agree to the above declaration <span class="required">*</span></label>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="index.php" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-paper-plane"></i> Submit Application
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; /* not success */ ?>
</div>

<script>
// ── Phase 1 units table ──
let rowCount1 = 5;
function addRowP1() {
    rowCount1++;
    const tbody = document.getElementById('unitsBody1');
    if (!tbody) return;
    const tr = document.createElement('tr');
    tr.innerHTML = '<td>' + rowCount1 + '</td>' +
        '<td><input type="text" name="unit_codes_p1[]" placeholder="e.g. BCP 316"></td>' +
        '<td><input type="text" name="unit_titles_p1[]" placeholder="e.g. POM"></td>';
    tbody.appendChild(tr);
}
function removeRowP1() {
    const tbody = document.getElementById('unitsBody1');
    if (tbody && tbody.children.length > 1) {
        tbody.removeChild(tbody.lastElementChild);
        rowCount1--;
    }
}

// Populate hidden units field before Phase 1 submit
const p1form = document.getElementById('phase1Form');
if (p1form) {
    p1form.addEventListener('submit', function() {
        const codes  = Array.from(document.querySelectorAll('input[name="unit_codes_p1[]"]')).map(i => i.value.trim()).filter(Boolean);
        const titles = Array.from(document.querySelectorAll('input[name="unit_titles_p1[]"]')).map(i => i.value.trim());
        const combined = codes.map((c, i) => c + (titles[i] ? ' – ' + titles[i] : '')).join(', ');
        const hid = document.getElementById('unitsHiddenP1');
        if (hid) hid.value = combined;
    });
}

// ── Application type selection ──
function selectType(type, e) {
    document.getElementById('application_type').value = type;
    document.querySelectorAll('.app-type-card').forEach(c => c.classList.remove('selected'));
    if (e && e.currentTarget) e.currentTarget.classList.add('selected');

    const ev   = document.getElementById('evidenceSection');
    const fn   = document.getElementById('financialNote');
    const note = document.getElementById('evidenceNote');
    const inp  = document.getElementById('evidence_file');

    if (type === 'Financial') {
        ev.style.display = 'none'; fn.style.display = 'block';
        if (inp) inp.removeAttribute('required');
    } else {
        ev.style.display = 'block'; fn.style.display = 'none';
        if (inp) inp.setAttribute('required', 'required');
        if (note) {
            note.innerHTML = type === 'Medical'
                ? '<i class="fa-solid fa-notes-medical"></i> Attach medical certificate, hospital discharge, or doctor\'s note.'
                : '<i class="fa-solid fa-heart"></i> Attach death certificate, burial permit, or relevant documentation.';
        }
    }
}

// ── Show file name on the display element ──
function showFileNameDisplay(input, targetId) {
    const el = document.getElementById(targetId);
    if (!el) return;
    if (input.files && input.files[0]) {
        el.querySelector('span').textContent = input.files[0].name;
        el.classList.add('visible');
    }
}

// ── Upload Approved Document drag-drop ──
let chosenLetterFile = null;

function onLetterChosen(input) {
    if (!input.files || !input.files[0]) return;
    chosenLetterFile = input.files[0];
    document.getElementById('chosenFileName').textContent = chosenLetterFile.name;
    document.getElementById('fileChosen').classList.add('visible');
    const dz2 = document.getElementById('dropZone'); if (dz2) dz2.classList.add('has-letter');
    // Unlock the Fill Digital Form button
    const btn  = document.getElementById('fillDigitalBtn');
    const note = document.getElementById('digitalBtnNote');
    if (btn) {
        btn.removeAttribute('disabled');
        btn.disabled = false;
        btn.classList.remove('locked');
        btn.classList.add('enabled');
        btn.style.cursor = 'pointer';
        btn.style.pointerEvents = 'auto';
    }
    if (note) note.textContent = 'Click to open the digital registration form';
}

function handleDrag(e, entering) {
    e.preventDefault();
    const dz = document.getElementById('dropZone');
    if (dz) dz.classList.toggle('dragging', entering);
}

function handleDrop(e) {
    e.preventDefault();
    const dz = document.getElementById('dropZone');
    if (dz) dz.classList.remove('dragging');
    const file = e.dataTransfer.files[0];
    if (file) {
        const dt = new DataTransfer();
        dt.items.add(file);
        const inp = document.getElementById('letterFileInput');
        inp.files = dt.files;
        onLetterChosen(inp);
    }
}

function proceedToDigital() {
    if (!chosenLetterFile) {
        alert('Please upload your approved letter first.');
        return;
    }
    // Pass all approved application data so resit_retake_form pre-fills and locks everything
    const month = <?php echo json_encode($phase1App['exam_month'] ?? ''); ?>;
    const year  = <?php echo json_encode((string)($phase1App['exam_year'] ?? date('Y'))); ?>;
    const units = <?php echo json_encode($phase1App['units'] ?? ''); ?>;
    let url = 'resit_retake_form.php?type=Special';
    if (month) url += '&month=' + encodeURIComponent(month);
    if (year)  url += '&year='  + encodeURIComponent(year);
    if (units) url += '&units=' + encodeURIComponent(units);
    window.location.href = url;
}

// ── Digital form: units table ──
let rowCount2 = 5;
function addRow() {
    rowCount2++;
    const tbody = document.getElementById('unitsBody2');
    if (!tbody) return;
    const tr = document.createElement('tr');
    tr.innerHTML = '<td>' + rowCount2 + '</td>' +
        '<td><input type="text" name="unit_codes[]"></td>' +
        '<td><input type="text" name="unit_titles[]"></td>';
    tbody.appendChild(tr);
}
function removeRow() {
    const tbody = document.getElementById('unitsBody2');
    if (tbody && tbody.children.length > 1) {
        tbody.removeChild(tbody.lastElementChild);
        rowCount2--;
    }
}

// ── Digital form validation ──
function validateDigital() {
    const monthEl = document.querySelector('[name="exam_month"]');
    const yearEl  = document.querySelector('[name="exam_year"]');
    const letter  = document.getElementById('approval_letter');
    const titleEl = document.getElementById('digitalTitle');

    if (monthEl && !monthEl.value) { alert('Please select an examination month.'); return false; }
    if (letter && !letter.files.length) { alert('Please attach your approved letter.'); return false; }

    if (titleEl && monthEl && yearEl) {
        titleEl.value = 'Special Exam – ' + monthEl.value + ' ' + yearEl.value;
    }

    const unitCodes = document.querySelectorAll('input[name="unit_codes[]"]');
    let hasUnit = false;
    unitCodes.forEach(u => { if (u.value.trim()) hasUnit = true; });
    if (!hasUnit) { alert('Please add at least one unit.'); return false; }

    return true;
}
</script>
</body>
</html>