<?php
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php");
    exit();
}

$viewer_reg   = $_SESSION['reg_number'];
$viewer_role  = $_SESSION['role'];
$viewer_admin = $_SESSION['admin_role'] ?? 'none';
$current_view = $_SESSION['current_admin_view'] ?? $viewer_admin;

// Resolve the correct display name for COD / Dean from password-login session
// Priority: password-login identity → actual logged-in user's name
$cod_identity_name = $_SESSION['cod_logged_in_name'] ?? null;
$cod_identity_reg  = $_SESSION['cod_logged_in_reg']  ?? null;
$dean_identity_name = $_SESSION['dean_logged_in_name'] ?? null;
$dean_identity_reg  = $_SESSION['dean_logged_in_reg']  ?? null;

if ($current_view === 'cod' && $cod_identity_name) {
    $viewer_name = $cod_identity_name;
} elseif ($current_view === 'dean' && $dean_identity_name) {
    $viewer_name = $dean_identity_name;
} else {
    $viewer_name = $_SESSION['full_name'] ?? '';
}

// Determine if this is an admin viewing
$is_admin = ($viewer_role === 'admin' || $viewer_role === 'super_admin');

if (!isset($_GET['id'])) {
    die("<div style='padding:20px;font-family:sans-serif;'>No document ID provided.</div>");
}

$id = intval($_GET['id']);

// Fetch document with student info
$query = "SELECT d.*, 
    u.full_name as student_fullname, u.email as student_email, u.phone as student_phone,
    u.course, u.year_of_study, dept.dept_name,
    rrf.exam_type, rrf.exam_month, rrf.exam_year, rrf.student_signature,
    (SELECT GROUP_CONCAT(fu.unit_code, ' – ', fu.unit_title SEPARATOR '\n') FROM form_units fu WHERE fu.form_id = rrf.id) as units_list
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    LEFT JOIN departments dept ON u.department_id = dept.id
    LEFT JOIN resit_retake_forms rrf ON rrf.document_id = d.id
    WHERE d.id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    die("<div style='padding:20px;font-family:sans-serif;'>Document not found.</div>");
}

// Security: students can only view their own docs
if (!$is_admin && $row['reg_number'] !== $viewer_reg) {
    header("Location: index.php");
    exit();
}

// Detect Special Exam — only show application review card for Phase 1 (no rrf record yet)
// Phase 2 digital form has a resit_retake_forms record (exam_type = 'Special') — treat like Resit/Retake
$has_rrf = !empty($row['exam_type']); // resit_retake_forms was joined; if exam_type filled, it's phase 2
$is_special_exam = ($row['module_type'] === 'Special_Exam') && !$has_rrf;
$sea = null;
$sea_units = [];
if ($is_special_exam) {
    $seaStmt = $conn->prepare(
        "SELECT sea.*, dept.dept_name as sea_dept_name
         FROM special_exam_applications sea
         LEFT JOIN departments dept ON sea.department_id = dept.id
         WHERE sea.document_id = ? ORDER BY sea.id DESC LIMIT 1"
    );
    $seaStmt->bind_param("i", $id);
    $seaStmt->execute();
    $sea = $seaStmt->get_result()->fetch_assoc();
    if ($sea && !empty($sea['units'])) {
        // Units stored as: "BCP 316 – POM, BCP 317 – Analysis"
        // en-dash U+2013 separates code from title
        $parts = array_filter(array_map('trim', explode(',', $sea['units'])));
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;
            // Split on en-dash, em-dash, or plain hyphen between spaces
            $split = preg_split('/\s[\x{2013}\x{2014}\-]\s/u', $part, 2);
            $sea_units[] = [
                'unit_code'  => trim($split[0] ?? $part),
                'unit_title' => trim($split[1] ?? ''),
            ];
        }
    }
}

// Determine what action panel to show
$show_cod_panel       = $is_admin && ($current_view === 'cod' || $viewer_admin === 'cod') && $row['status'] === 'Pending_COD';
$show_dean_panel      = $is_admin && ($current_view === 'dean' || $viewer_admin === 'dean') && $row['status'] === 'Pending_Dean';
$show_registrar_panel = $is_admin && ($current_view === 'registrar' || $viewer_admin === 'registrar') && $row['status'] === 'Pending_Registrar';
$show_finance_panel   = $is_admin && ($current_view === 'finance' || $viewer_admin === 'finance') && in_array($row['status'], ['Pending_Finance', 'Approved']);
$finance_is_pending   = $row['status'] === 'Pending_Finance';
$show_dvc_panel       = $is_admin && ($current_view === 'dvc_arsa' || $viewer_admin === 'dvc_arsa') && $row['status'] === 'Pending_DVC';

$today = date('F j, Y');
$today_val = date('Y-m-d');

// Back link
$back_href = 'index.php';
if ($is_admin) {
    if ($current_view === 'cod' || $viewer_admin === 'cod') {
        $back_href = 'cod_dashboard.php?dept=' . ($_SESSION['selected_department'] ?? '');
    } elseif ($current_view === 'dean' || $viewer_admin === 'dean') {
        $back_href = 'dean_dashboard.php?school=' . urlencode($_SESSION['selected_school'] ?? '');
    } elseif ($current_view === 'registrar' || $viewer_admin === 'registrar') {
        $back_href = 'registrar_dashboard.php';
    } elseif ($current_view === 'finance' || $viewer_admin === 'finance') {
        $back_href = 'finance_dashboard.php';
    } elseif ($current_view === 'dvc_arsa' || $viewer_admin === 'dvc_arsa') {
        $back_href = 'admin_dashboard.php';
    }
}

// Finance: success toast for custom email sent or finalised
$finance_email_sent = isset($_GET['success']) && in_array($_GET['success'], ['email_sent', 'finalised']);

$error_msg = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>View Form – <?php echo htmlspecialchars($row['title']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ── Screen chrome ── */
:root{--primary:#22c55e;--danger:#ef4444;--warning:#f59e0b;--blue:#3b82f6;--purple:#7c3aed;--bg:#f1f5f9;--card:#fff;--text:#1e293b;--text-light:#64748b;--border:#e2e8f0;--shadow:0 4px 6px -1px rgba(0,0,0,.1)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);padding:30px 20px}
.screen-container{max-width:900px;margin:0 auto}
.back-link{display:inline-flex;align-items:center;gap:8px;color:var(--text-light);text-decoration:none;margin-bottom:20px;font-size:.875rem;font-weight:500}
.back-link:hover{color:var(--primary)}
.error-alert{background:#fee2e2;border:1px solid #fecaca;color:var(--danger);padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:.875rem}
/* ── MUT Form styles (matches resit_retake_form.php exactly) ── */
.form-container{max-width:800px;margin:0 auto;background:white;padding:30px;box-shadow:0 0 10px rgba(0,0,0,.1);font-family:'Times New Roman',Times,serif;font-size:12pt}
.form-header{text-align:center;margin-bottom:20px;position:relative}
.form-code{position:absolute;top:0;right:0;font-size:10pt;font-weight:bold}
.form-logo{width:80px;height:80px;margin-bottom:10px}
.university-name{font-size:14pt;font-weight:bold;text-transform:uppercase;margin-bottom:5px}
.office-name{font-size:11pt;font-weight:bold;text-transform:uppercase;margin-bottom:5px}
.form-title{font-size:12pt;font-weight:bold;text-decoration:underline;margin-bottom:20px}
.form-section{margin-bottom:20px}
.section-header{display:flex;align-items:flex-start;margin-bottom:10px}
.section-number{font-weight:bold;margin-right:10px;min-width:20px}
.section-title-label{font-weight:bold}
table{width:100%;border-collapse:collapse;margin-bottom:15px}
table,th,td{border:1px solid #000}
th,td{padding:8px;text-align:left;vertical-align:top}
th{background-color:#d9d9d9;font-weight:bold;text-align:center}
.checkbox-cell{width:30px;text-align:center}
.form-field{width:100%;border:none;background:transparent;font-family:'Times New Roman',Times,serif;font-size:11pt;padding:2px}
.personal-details-table td{padding:10px}
.personal-details-table td:first-child{width:40%}
.units-table th,.units-table td{text-align:center;padding:8px}
.units-table th:first-child,.units-table td:first-child{width:60px}
/* Signature */
.student-signature-display{font-style:italic;font-family:Georgia,'Times New Roman',serif;font-size:16pt;color:#1a1a2e;border-bottom:2px solid #000;display:inline-block;min-width:250px;padding:4px 0;margin:4px 0}
.declaration-text{font-size:10pt;text-align:justify;margin-bottom:15px;line-height:1.5}
.signature-section{margin-top:20px}
.signature-row{display:flex;justify-content:space-between;margin-bottom:15px;align-items:flex-end}
.signature-field{flex:1;margin-right:20px}
.signature-field:last-child{margin-right:0}
/* Approval section */
.approval-section{margin-top:30px}
.approval-title{font-weight:bold;margin-bottom:15px}
.approval-row-form{display:flex;margin-bottom:15px;align-items:center}
.approval-label{width:150px;font-weight:bold}
.approval-field{flex:1;border-bottom:1px solid #000;margin:0 10px;min-height:25px;padding:2px 4px}
.approval-field.auto-filled{font-style:italic;font-family:Georgia,'Times New Roman',serif;font-size:13pt;color:#1a1a2e}
.approval-date{width:120px;border-bottom:1px solid #000;margin-left:10px;min-height:25px;padding:2px 4px}
.approval-date.auto-filled{font-size:10pt;color:#1a1a2e}
/* Payment */
.payment-section{margin-top:20px;border-top:2px solid #000;padding-top:15px}
.payment-title{font-weight:bold;margin-bottom:10px;font-style:italic}
/* ── Action panel (below the form) ── */
.action-panel{background:var(--card);border-radius:16px;box-shadow:var(--shadow);padding:28px;border-top:4px solid var(--blue);margin-top:24px;font-family:'Inter',sans-serif}
.action-panel.cod-panel{border-top-color:var(--blue)}
.action-panel.dean-panel{border-top-color:var(--purple)}
.action-panel.registrar-panel{border-top-color:var(--warning)}
.action-panel.dvc-panel{border-top-color:var(--danger)}
.action-panel.finance-panel{border-top-color:#0ea5e9}
.finance-email-form{background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;padding:20px;margin-top:20px;}
.finance-email-form label{font-size:.875rem;font-weight:600;display:block;margin-bottom:6px;}
.finance-email-form input,.finance-email-form textarea{width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:8px;font-family:inherit;font-size:.875rem;margin-bottom:14px;}
.finance-email-form textarea{min-height:90px;resize:vertical;}
.finance-email-form input:focus,.finance-email-form textarea:focus{border-color:#0ea5e9;outline:none;}
.btn-finance-send{background:#0ea5e9;color:#fff;}
.btn-finance-send:hover{background:#0284c7;}
.panel-header{margin-bottom:20px}
.panel-header h3{font-size:1.1rem;font-weight:700;margin-bottom:4px}
.panel-header p{font-size:.875rem;color:var(--text-light)}
.reason-box{margin-bottom:20px;display:none}
.reason-box label{font-size:.875rem;font-weight:600;display:block;margin-bottom:8px}
.reason-box textarea{width:100%;padding:12px;border:2px solid var(--border);border-radius:10px;font-size:.875rem;font-family:inherit;resize:vertical;min-height:80px}
.reason-box textarea:focus{border-color:var(--danger);outline:none}
.action-btns{display:flex;gap:12px;flex-wrap:wrap}
.btn{padding:12px 24px;border:none;border-radius:10px;font-size:.9rem;font-weight:600;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:8px;font-family:'Inter',sans-serif}
.btn-recommend{background:var(--primary);color:#fff}.btn-recommend:hover{background:#16a34a;transform:translateY(-2px)}
.btn-approve{background:var(--primary);color:#fff}.btn-approve:hover{background:#16a34a;transform:translateY(-2px)}
.btn-not-recommend{background:var(--bg);border:2px solid var(--danger);color:var(--danger)}.btn-not-recommend:hover{background:#fee2e2}
.btn-reject{background:var(--bg);border:2px solid var(--danger);color:var(--danger)}.btn-reject:hover{background:#fee2e2}
.btn-finalise{background:var(--warning);color:#fff}.btn-finalise:hover{background:#d97706}
@media print{.back-link,.action-panel,.no-print{display:none!important}body{padding:0;background:white}.form-container{box-shadow:none;padding:20px}}
/* ── Special Exam Application Review card ── */
.sea-card{background:#fff;border-radius:16px;box-shadow:0 4px 6px -1px rgba(0,0,0,.1);padding:30px;max-width:800px;margin:0 auto;font-family:'Inter',sans-serif}
.sea-header{display:flex;align-items:center;gap:14px;margin-bottom:24px;padding-bottom:16px;border-bottom:2px solid #e2e8f0}
.sea-logo{width:60px;height:60px}
.sea-header-text h2{font-size:1.1rem;font-weight:800;color:#1e293b;margin-bottom:2px}
.sea-header-text p{font-size:.8rem;color:#64748b}
.sea-type-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 16px;border-radius:20px;font-size:.82rem;font-weight:700;margin-bottom:20px}
.sea-type-financial{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.sea-type-medical{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}
.sea-type-compassionate{background:#fce7f3;color:#9d174d;border:1px solid #fbcfe8}
.sea-section{margin-bottom:22px}
.sea-section-title{font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:#7c3aed;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.sea-section-title::after{content:"";flex:1;height:1px;background:#e2e8f0}
.sea-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.sea-field label{display:block;font-size:.72rem;font-weight:600;color:#64748b;margin-bottom:3px}
.sea-field .val{font-size:.9rem;color:#1e293b;font-weight:500}
.sea-reason-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;font-size:.875rem;color:#1e293b;line-height:1.6;white-space:pre-wrap}
.sea-units-table{width:100%;border-collapse:collapse;font-size:.875rem}
.sea-units-table th{background:#f1f5f9;padding:8px 12px;text-align:left;font-weight:600;color:#475569;border:1px solid #e2e8f0}
.sea-units-table td{padding:8px 12px;border:1px solid #e2e8f0;color:#1e293b}
.sea-units-table tr:nth-child(even) td{background:#f8fafc}
.sea-evidence-link{display:inline-flex;align-items:center;gap:8px;background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:600}
.sea-evidence-link:hover{background:#dcfce7}
.sea-approval-trail{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;font-size:.85rem}
.sea-trail-row{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #f1f5f9}
.sea-trail-row:last-child{border-bottom:none}
.sea-trail-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.sea-trail-dot.done{background:#22c55e}
.sea-trail-dot.pending{background:#f59e0b}
.sea-trail-dot.waiting{background:#e2e8f0}
</style>
</head>
<body>
<div class="screen-container">
    <a href="<?php echo htmlspecialchars($back_href); ?>" class="back-link no-print">
        <i class="fa-solid fa-arrow-left"></i> Back
    </a>

    <?php if ($error_msg === 'reason_required'): ?>
    <div class="error-alert no-print"><i class="fa-solid fa-exclamation-circle"></i> Please provide a reason before submitting.</div>
    <?php elseif ($error_msg === 'email_fields_required'): ?>
    <div class="error-alert no-print"><i class="fa-solid fa-exclamation-circle"></i> Please fill in both subject and message fields.</div>
    <?php elseif (!empty($error_msg) && strpos($error_msg, 'Email failed') !== false): ?>
    <div class="error-alert no-print"><i class="fa-solid fa-exclamation-circle"></i> <?php echo htmlspecialchars(urldecode($error_msg)); ?></div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════
         CONDITIONAL: Special Exam shows application review card
         Resit/Retake shows the standard MUT digital form
    ══════════════════════════════════════════════════ -->

    <?php if ($is_special_exam): ?>
    <!-- ── SPECIAL EXAM APPLICATION REVIEW ── -->
    <div class="sea-card">
        <div class="sea-header">
            <img src="assets/images/mut_logo.png" alt="MUT" class="sea-logo">
            <div class="sea-header-text">
                <h2>Special Exam Application – Review</h2>
                <p>Murang'a University of Technology &nbsp;·&nbsp; Office of Registrar (Academic &amp; Student Affairs)</p>
            </div>
        </div>

        <?php
        $type_icons = ['Financial'=>'💰','Medical'=>'🏥','Compassionate'=>'💜'];
        $type_classes = ['Financial'=>'sea-type-financial','Medical'=>'sea-type-medical','Compassionate'=>'sea-type-compassionate'];
        $app_type = $sea['application_type'] ?? 'N/A';
        $type_icon = $type_icons[$app_type] ?? '📋';
        $type_class = $type_classes[$app_type] ?? 'sea-type-financial';
        ?>
        <span class="sea-type-badge <?php echo $type_class; ?>">
            <?php echo $type_icon; ?> <?php echo htmlspecialchars($app_type); ?> Application
        </span>

        <!-- Student Details -->
        <div class="sea-section">
            <div class="sea-section-title"><i class="fa-solid fa-user"></i> Applicant Information</div>
            <div class="sea-grid">
                <div class="sea-field"><label>Full Name</label><div class="val"><?php echo htmlspecialchars($sea['student_name'] ?? $row['student_fullname']); ?></div></div>
                <div class="sea-field"><label>Registration Number</label><div class="val"><?php echo htmlspecialchars($row['reg_number']); ?></div></div>
                <div class="sea-field"><label>Course</label><div class="val"><?php echo htmlspecialchars($sea['course'] ?? $row['course'] ?? 'N/A'); ?></div></div>
                <div class="sea-field"><label>Department</label><div class="val"><?php echo htmlspecialchars($sea['sea_dept_name'] ?? $row['dept_name'] ?? 'N/A'); ?></div></div>
                <div class="sea-field"><label>Phone</label><div class="val"><?php echo htmlspecialchars($sea['student_phone'] ?? $row['student_phone'] ?? 'N/A'); ?></div></div>
                <div class="sea-field"><label>Email</label><div class="val"><?php echo htmlspecialchars($sea['student_email'] ?? $row['student_email'] ?? 'N/A'); ?></div></div>
            </div>
        </div>

        <!-- Exam Period -->
        <div class="sea-section">
            <div class="sea-section-title"><i class="fa-solid fa-calendar"></i> Examination Period</div>
            <div class="sea-grid">
                <div class="sea-field"><label>Month</label><div class="val"><?php echo htmlspecialchars($sea['exam_month'] ?? 'N/A'); ?></div></div>
                <div class="sea-field"><label>Year</label><div class="val"><?php echo htmlspecialchars($sea['exam_year'] ?? 'N/A'); ?></div></div>
            </div>
        </div>

        <!-- Units -->
        <div class="sea-section">
            <div class="sea-section-title"><i class="fa-solid fa-book"></i> Units to be Written</div>
            <?php if (!empty($sea_units)): ?>
            <table class="sea-units-table">
                <thead><tr><th>#</th><th>Unit Code</th><th>Unit Title / Name</th></tr></thead>
                <tbody>
                <?php foreach ($sea_units as $idx => $u): ?>
                    <tr>
                        <td><?php echo $idx + 1; ?></td>
                        <td><?php echo htmlspecialchars($u['unit_code'] ?? $u['code'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($u['unit_title'] ?? $u['title'] ?? $u['name'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p style="color:#64748b;font-size:.875rem;font-style:italic;">No units recorded.</p>
            <?php endif; ?>
        </div>

        <!-- Detailed Reason -->
        <div class="sea-section">
            <div class="sea-section-title"><i class="fa-solid fa-file-lines"></i> Detailed Reason</div>
            <div class="sea-reason-box"><?php echo htmlspecialchars($sea['reason_description'] ?? 'N/A'); ?></div>
        </div>

        <!-- Evidence (Medical / Compassionate) -->
        <?php if (!empty($sea['evidence_file_path'])): ?>
        <div class="sea-section">
            <div class="sea-section-title"><i class="fa-solid fa-paperclip"></i> Supporting Evidence</div>
            <a href="<?php echo htmlspecialchars($sea['evidence_file_path']); ?>" target="_blank" class="sea-evidence-link">
                <i class="fa-solid fa-file-arrow-down"></i>
                <?php echo htmlspecialchars($sea['evidence_file_name'] ?? 'View Evidence'); ?>
            </a>
            <?php if (!empty($sea['evidence_description'])): ?>
            <p style="margin-top:8px;font-size:.82rem;color:#64748b;"><?php echo htmlspecialchars($sea['evidence_description']); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Approval Trail -->
        <div class="sea-section">
            <div class="sea-section-title"><i class="fa-solid fa-route"></i> Approval Progress</div>
            <div class="sea-approval-trail">
                <?php
                // Special Exam Phase-1 trail: Dean → Registrar → DVC ARSA (UNCHANGED)
                $cur = $row['status'];
                $steps = [
                    ['label' => 'Student Submitted',    'dot' => 'done'],
                    ['label' => 'Dean – Recommend',     'dot' => in_array($cur, ['Pending_Registrar','Pending_DVC','Approved']) ? 'done' : ($cur === 'Pending_Dean' ? 'pending' : 'waiting')],
                    ['label' => 'Registrar – Recommend','dot' => in_array($cur, ['Pending_DVC','Approved']) ? 'done' : ($cur === 'Pending_Registrar' ? 'pending' : 'waiting')],
                    ['label' => 'DVC ARSA – Approve',   'dot' => $cur === 'Approved' ? 'done' : ($cur === 'Pending_DVC' ? 'pending' : 'waiting')],
                    ['label' => 'Approval Letter Sent', 'dot' => $cur === 'Approved' ? 'done' : 'waiting'],
                ];
                foreach ($steps as $step):
                ?>
                <div class="sea-trail-row">
                    <div class="sea-trail-dot <?php echo $step['dot']; ?>"></div>
                    <span style="font-weight:<?php echo $step['dot']==='pending'?'700':'500'; ?>;color:<?php echo $step['dot']==='done'?'#16a34a':($step['dot']==='pending'?'#92400e':'#94a3b8'); ?>">
                        <?php echo $step['label']; ?>
                        <?php if ($step['dot']==='pending') echo ' ← Current'; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Submission date -->
        <div style="margin-top:16px;font-size:.78rem;color:#94a3b8;text-align:right;">
            Submitted: <?php echo date('F j, Y', strtotime($row['upload_date'])); ?>
        </div>
    </div><!-- /sea-card -->

    <?php elseif (in_array($row['module_type'], ['Resit','Retake','Special_Exam'])): ?>
    <!-- ── RESIT / RETAKE / SPECIAL PHASE-2 STANDARD FORM ── -->
    <div class="form-container" <?php if ($show_finance_panel) echo 'style="display:none;"'; ?>>
        <!-- Header -->
        <div class="form-header">
            <div class="form-code">MUT/F/ASAA/015</div>
            <img src="assets/images/mut_logo.png" alt="MUT Logo" class="form-logo">
            <div class="university-name">MURANG'A UNIVERSITY OF TECHNOLOGY</div>
            <div class="office-name">OFFICE OF REGISTRAR (Academic and Student Affairs)</div>
            <div class="form-title">SPECIAL/RESIT/RETAKE EXAM REGISTRATION FORM</div>
        </div>

        <!-- Section 1: Examination Type -->
        <div class="form-section">
            <div class="section-header">
                <span class="section-number">1.</span>
                <span class="section-title-label">For which Examination do you wish to register for?</span>
            </div>
            <table class="exam-type-table">
                <tr>
                    <td class="checkbox-cell">
                        <input type="checkbox" <?php echo ($row['exam_type'] === 'Special' || $row['module_type'] === 'Special_Exam') ? 'checked' : ''; ?> disabled>
                    </td>
                    <td>Special Exam</td>
                    <td class="checkbox-cell">
                        <input type="checkbox" <?php echo $row['exam_type'] === 'Resit' ? 'checked' : ''; ?> disabled>
                    </td>
                    <td>Resit Exam</td>
                    <td class="checkbox-cell">
                        <input type="checkbox" <?php echo $row['exam_type'] === 'Retake' ? 'checked' : ''; ?> disabled>
                    </td>
                    <td>Retake Exam</td>
                </tr>
            </table>
        </div>

        <!-- Section 2: Examination Period -->
        <div class="form-section">
            <div class="section-header">
                <span class="section-number">2.</span>
                <span class="section-title-label">Examination Period</span>
            </div>
            <table>
                <tr><th>Month</th><th>Year</th></tr>
                <tr>
                    <td style="text-align:center;"><?php echo htmlspecialchars($row['exam_month'] ?? ''); ?></td>
                    <td style="text-align:center;"><?php echo htmlspecialchars($row['exam_year'] ?? ''); ?></td>
                </tr>
            </table>
        </div>

        <!-- Section 3: Personal Details -->
        <div class="form-section">
            <div class="section-header">
                <span class="section-number">3.</span>
                <span class="section-title-label">Personal Details</span>
            </div>
            <table class="personal-details-table">
                <tr><td>Student Name</td><td><?php echo htmlspecialchars($row['student_fullname']); ?></td></tr>
                <tr><td>Student Registration Number</td><td><?php echo htmlspecialchars($row['reg_number']); ?></td></tr>
                <tr><td>Cell phone</td><td><?php echo htmlspecialchars($row['student_phone'] ?? ''); ?></td></tr>
                <tr><td>Email</td><td><?php echo htmlspecialchars($row['student_email']); ?></td></tr>
                <tr><td>Course</td><td><?php echo htmlspecialchars($row['course'] ?? ''); ?></td></tr>
                <tr><td>Department</td><td><?php echo htmlspecialchars($row['dept_name'] ?? ''); ?></td></tr>
            </table>
        </div>

        <!-- Section 4: Units -->
        <div class="form-section">
            <div class="section-header">
                <span class="section-number">4.</span>
                <span class="section-title-label">Units to be written</span>
            </div>
            <?php
            // Fetch units separately
            $units_stmt = $conn->prepare("SELECT fu.unit_code, fu.unit_title FROM form_units fu JOIN resit_retake_forms rrf ON fu.form_id = rrf.id WHERE rrf.document_id = ? ORDER BY fu.id");
            $units_stmt->bind_param("i", $id);
            $units_stmt->execute();
            $units_result = $units_stmt->get_result();
            $units_arr = [];
            while ($u = $units_result->fetch_assoc()) $units_arr[] = $u;
            ?>
            <table class="units-table">
                <thead><tr><th>S/No</th><th>Unit Code</th><th>Unit Title</th></tr></thead>
                <tbody>
                    <?php if (!empty($units_arr)): ?>
                        <?php foreach ($units_arr as $i => $u): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($u['unit_code']); ?></td>
                            <td><?php echo htmlspecialchars($u['unit_title']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php
                        // Fallback: parse from units_list string
                        $units_raw = $row['units_list'] ?? '';
                        $lines = array_filter(explode("\n", $units_raw));
                        $n = 1;
                        foreach ($lines as $line):
                            $parts = explode(' – ', $line, 2);
                        ?>
                        <tr>
                            <td><?php echo $n++; ?></td>
                            <td><?php echo htmlspecialchars(trim($parts[0] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars(trim($parts[1] ?? '')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php
                    // Fill remaining rows up to 5
                    $filled = max(count($units_arr), count($lines ?? []));
                    for ($e = $filled; $e < 5; $e++):
                    ?>
                    <tr><td><?php echo $e + 1; ?></td><td>&nbsp;</td><td>&nbsp;</td></tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <!-- Section 5: Declaration & Student Signature -->
        <div class="form-section">
            <p class="declaration-text">
                <strong>DECLARATION BY STUDENT:</strong> I agree to abide by the rules and procedures governing
                Murang'a University of Technology examinations. I understand that I must take my identity document
                with me to write my examination and that I have 14 consecutive days from the Examination
                Registration Closing Date to follow up on my examination registration status. I also declare
                that I have successfully completed the compulsory assignments for the above subject(s).
            </p>
            <div class="signature-section">
                <div class="signature-row" style="align-items:flex-end;margin-top:16px;">
                    <div class="signature-field">
                        <label style="display:block;margin-bottom:6px;font-weight:bold;">Student Signature</label>
                        <div class="student-signature-display"><?php echo htmlspecialchars($row['student_fullname']); ?></div>
                        <p style="font-size:9pt;color:#666;font-style:italic;margin-top:4px;">Signature auto-generated from login credentials</p>
                    </div>
                    <div class="signature-field" style="text-align:right;max-width:200px;">
                        <label style="display:block;margin-bottom:6px;font-weight:bold;">Date</label>
                        <div style="border-bottom:1px solid #000;text-align:center;padding:4px 0;font-family:'Times New Roman',Times,serif;">
                            <?php
                            $sig_date_val = !empty($row['student_signature_date']) ? $row['student_signature_date'] : ($row['upload_date'] ?? null);
                            echo $sig_date_val ? date('F j, Y', strtotime($sig_date_val)) : date('F j, Y');
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approval Section — COD box -->
        <div class="approval-section">
            <div class="approval-title">Recommended By:</div>
            <div class="approval-row-form">
                <span class="approval-label">CoD (Name)</span>
                <span class="approval-field <?php echo ($show_cod_panel || !empty($row['cod_signer_name'])) ? 'auto-filled' : ''; ?>">
                    <?php
                    if (!empty($row['cod_signer_name'])) {
                        echo htmlspecialchars($row['cod_signer_name']);
                    } elseif ($show_cod_panel) {
                        echo htmlspecialchars($viewer_name);
                    }
                    ?>
                </span>
                <span style="margin:0 10px;">Date:</span>
                <span class="approval-date <?php echo ($show_cod_panel || !empty($row['cod_signed_at'])) ? 'auto-filled' : ''; ?>">
                    <?php
                    if (!empty($row['cod_signed_at'])) {
                        echo date('F j, Y', strtotime($row['cod_signed_at']));
                    } elseif ($show_cod_panel) {
                        echo $today;
                    }
                    ?>
                </span>
            </div>

            <div class="approval-title">Approved By:</div>
            <div class="approval-row-form">
                <span class="approval-label">Dean (Name)</span>
                <span class="approval-field <?php echo ($show_dean_panel || !empty($row['dean_signer_name'])) ? 'auto-filled' : ''; ?>">
                    <?php
                    if (!empty($row['dean_signer_name'])) {
                        echo htmlspecialchars($row['dean_signer_name']);
                    } elseif ($show_dean_panel) {
                        echo htmlspecialchars($viewer_name);
                    }
                    ?>
                </span>
                <span style="margin:0 10px;">Date:</span>
                <span class="approval-date <?php echo ($show_dean_panel || !empty($row['dean_signed_at'])) ? 'auto-filled' : ''; ?>">
                    <?php
                    if (!empty($row['dean_signed_at'])) {
                        echo date('F j, Y', strtotime($row['dean_signed_at']));
                    } elseif ($show_dean_panel) {
                        echo $today;
                    }
                    ?>
                </span>
            </div>
        </div>

        <!-- Payment Section — shown to registrar and after finalise; amount auto-filled by module type -->
        <?php
        $mod_type = $row['module_type'] ?? ($row['exam_type'] ?? '');
        $is_finalised = ($row['status'] === 'Approved' || !empty($row['registrar_approved']));
        if ($mod_type === 'Special_Exam' || $mod_type === 'Special') {
            $display_amount = 'N/A';
        } elseif ($mod_type === 'Resit') {
            $display_amount = 'KSh 800';
        } elseif ($mod_type === 'Retake') {
            $display_amount = 'KSh 10,023';
        } else {
            $display_amount = !empty($row['amount_paid']) ? htmlspecialchars($row['amount_paid']) : '';
        }
        $show_payment = $is_finalised || $show_registrar_panel;
        ?>
        <?php if ($show_payment): ?>
        <div class="payment-section">
            <div class="payment-title">Confirmation of Payment:</div>
            <div class="approval-row-form">
                <span class="approval-label">Amount Paid:</span>
                <span class="approval-field auto-filled" style="flex:0.5;"><?php echo $display_amount; ?></span>
                <span style="margin:0 20px;"></span>
                <span class="approval-label">Signature &amp; Stamp:</span>
                <span class="approval-field" style="flex:0.5;vertical-align:middle;">
                    <span style="display:inline-flex;flex-direction:column;align-items:center;justify-content:center;
                        border:2.5px solid #1a3a6b;border-radius:3px;padding:8px 12px;min-width:130px;
                        background:#fff;position:relative;font-family:'Times New Roman',Times,serif;">
                        <span style="position:absolute;inset:2px;border:1px solid #1a3a6b;border-radius:1px;pointer-events:none;"></span>
                        <span style="font-weight:900;text-transform:uppercase;letter-spacing:1.5px;color:#1a3a6b;font-size:.62rem;">FINANCE OFFICE</span>
                        <span style="font-weight:700;text-transform:uppercase;color:#1a3a6b;font-size:.52rem;margin-top:2px;text-align:center;line-height:1.3;">MURANG'A UNIVERSITY<br>OF TECHNOLOGY</span>
                        <span style="font-weight:900;color:#c0392b;margin:4px 0 3px;font-size:.78rem;letter-spacing:1.5px;"><?php echo strtoupper(date('d M Y')); ?></span>
                        <span style="color:#1a3a6b;font-size:.44rem;text-align:center;line-height:1.4;">P.O. Box 75-10200, Murang'a<br>Tel: 0711463 515</span>
                        <span style="margin-top:4px;width:28px;height:28px;border:1px solid #1a3a6b;border-radius:50%;
                            display:inline-flex;align-items:center;justify-content:center;text-align:center;
                            font-size:.38rem;font-weight:700;color:#1a3a6b;line-height:1.2;">MUT IS<br>ISO 9001</span>
                    </span>
                </span>
            </div>
        </div>
        <?php endif; ?>
    </div><!-- /form-container -->

    <?php else: ?>
    <!-- ── OTHER DOCUMENT TYPES (Fees, Bursary etc.) ── -->
    <div class="form-container" style="font-family:'Inter',sans-serif;padding:30px;">
        <div style="text-align:center;margin-bottom:24px;">
            <img src="assets/images/mut_logo.png" alt="MUT Logo" style="width:70px;height:70px;margin-bottom:10px;">
            <div style="font-size:13pt;font-weight:bold;text-transform:uppercase;">MURANG'A UNIVERSITY OF TECHNOLOGY</div>
            <div style="font-size:11pt;font-weight:bold;text-transform:uppercase;">OFFICE OF REGISTRAR (Academic and Student Affairs)</div>
        </div>
        <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:11pt;">
            <tr><td style="padding:10px;border:1px solid #000;width:40%;font-weight:bold;">Document Title</td><td style="padding:10px;border:1px solid #000;"><?php echo htmlspecialchars($row['title']); ?></td></tr>
            <tr><td style="padding:10px;border:1px solid #000;font-weight:bold;">Document Type</td><td style="padding:10px;border:1px solid #000;"><?php echo htmlspecialchars($row['module_type']); ?></td></tr>
            <tr><td style="padding:10px;border:1px solid #000;font-weight:bold;">Student Name</td><td style="padding:10px;border:1px solid #000;"><?php echo htmlspecialchars($row['student_fullname']); ?></td></tr>
            <tr><td style="padding:10px;border:1px solid #000;font-weight:bold;">Registration Number</td><td style="padding:10px;border:1px solid #000;"><?php echo htmlspecialchars($row['reg_number']); ?></td></tr>
            <tr><td style="padding:10px;border:1px solid #000;font-weight:bold;">Department</td><td style="padding:10px;border:1px solid #000;"><?php echo htmlspecialchars($row['dept_name'] ?? ''); ?></td></tr>
            <tr><td style="padding:10px;border:1px solid #000;font-weight:bold;">Status</td><td style="padding:10px;border:1px solid #000;"><?php echo htmlspecialchars($row['status']); ?></td></tr>
            <tr><td style="padding:10px;border:1px solid #000;font-weight:bold;">Submitted</td><td style="padding:10px;border:1px solid #000;"><?php echo date('F j, Y', strtotime($row['upload_date'])); ?></td></tr>
            <?php if (!empty($row['description'])): ?>
            <tr><td style="padding:10px;border:1px solid #000;font-weight:bold;">Description / Notes</td><td style="padding:10px;border:1px solid #000;"><?php echo nl2br(htmlspecialchars($row['description'])); ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($row['file_path'])): ?>
            <tr><td style="padding:10px;border:1px solid #000;font-weight:bold;">Attached File</td><td style="padding:10px;border:1px solid #000;"><a href="<?php echo htmlspecialchars($row['file_path']); ?>" target="_blank">View / Download</a></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <?php endif; // end module_type check ?>

    <!-- ══════ ACTION PANELS (below the form) ══════ -->

    <?php if ($show_cod_panel): ?>
    <div class="action-panel cod-panel no-print">
        <div class="panel-header">
            <h3><i class="fa-solid fa-user-tie" style="color:var(--blue);"></i> COD Action
                <span style="font-size:.85rem;font-weight:400;color:var(--text-light);margin-left:8px;">
                    — Logged in as: <strong><?php echo htmlspecialchars($viewer_name); ?></strong>
                    <?php if ($cod_identity_reg): ?>
                        (<?php echo htmlspecialchars($cod_identity_reg); ?>)
                    <?php endif; ?>
                </span>
            </h3>
            <p>Review the application above, then recommend or not recommend to the Dean's office.</p>
        </div>

        <form method="POST" action="process_approval.php" id="codForm">
            <input type="hidden" name="doc_id" value="<?php echo $id; ?>">
            <input type="hidden" name="action" id="codAction">
            <input type="hidden" name="cod_signer_name" value="<?php echo htmlspecialchars($viewer_name); ?>">

            <div class="reason-box" id="codReasonBox">
                <label for="cod_reason">Reason for Not Recommending <span style="color:var(--danger)">*</span></label>
                <textarea name="reason" id="cod_reason" placeholder="Provide a clear reason that will be sent to the student…"></textarea>
            </div>

            <div class="action-btns">
                <button type="button" class="btn btn-recommend" onclick="submitCOD('cod_recommend')">
                    <i class="fa-solid fa-thumbs-up"></i> Recommend
                </button>
                <button type="button" class="btn btn-not-recommend" onclick="showCODReason()">
                    <i class="fa-solid fa-thumbs-down"></i> Not Recommended
                </button>
                <button type="button" class="btn" id="codSubmitReason" style="display:none;background:var(--danger);color:#fff;" onclick="submitCOD('cod_not_recommend')">
                    <i class="fa-solid fa-paper-plane"></i> Submit
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($show_dean_panel): ?>
    <div class="action-panel dean-panel no-print">
        <div class="panel-header">
            <h3><i class="fa-solid fa-user-graduate" style="color:var(--purple);"></i> Dean Action
                <span style="font-size:.85rem;font-weight:400;color:var(--text-light);margin-left:8px;">
                    — Logged in as: <strong><?php echo htmlspecialchars($viewer_name); ?></strong>
                    <?php if ($dean_identity_reg): ?>
                        (<?php echo htmlspecialchars($dean_identity_reg); ?>)
                    <?php endif; ?>
                </span>
            </h3>
            <?php if ($is_special_exam): ?>
            <p>Review the special exam application above, then recommend or not recommend to the Registrar's office.</p>
            <?php else: ?>
            <p>Review the COD recommendation above, then approve or reject the application.</p>
            <?php endif; ?>
        </div>

        <form method="POST" action="process_approval.php" id="deanForm">
            <input type="hidden" name="doc_id" value="<?php echo $id; ?>">
            <input type="hidden" name="action" id="deanAction">
            <input type="hidden" name="dean_signer_name" value="<?php echo htmlspecialchars($viewer_name); ?>">

            <div class="reason-box" id="deanReasonBox">
                <label for="dean_reason">Reason for Not Recommending <span style="color:var(--danger)">*</span></label>
                <textarea name="reason" id="dean_reason" placeholder="Provide a clear reason that will be sent to the student…"></textarea>
            </div>

            <div class="action-btns">
                <?php if ($is_special_exam): ?>
                <button type="button" class="btn btn-recommend" onclick="submitDean('dean_recommend_special')">
                    <i class="fa-solid fa-thumbs-up"></i> Recommend
                </button>
                <button type="button" class="btn btn-not-recommend" onclick="showDeanReason()">
                    <i class="fa-solid fa-thumbs-down"></i> Not Recommend
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-approve" onclick="submitDean('dean_approve')">
                    <i class="fa-solid fa-check-circle"></i> Approve
                </button>
                <button type="button" class="btn btn-reject" onclick="showDeanReason()">
                    <i class="fa-solid fa-xmark-circle"></i> Reject
                </button>
                <?php endif; ?>
                <button type="button" class="btn" id="deanSubmitReason" style="display:none;background:var(--danger);color:#fff;" onclick="submitDean('dean_reject')">
                    <i class="fa-solid fa-paper-plane"></i> Submit
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($show_registrar_panel): ?>
    <?php if ($is_special_exam): ?>
    <!-- Special Exam: Registrar recommends → forwards to DVC ARSA -->
    <div class="action-panel no-print" style="border-top:4px solid var(--warning);margin-top:24px;">
        <div class="panel-header">
            <h3><i class="fa-solid fa-user-shield" style="color:var(--warning);"></i> Registrar Action</h3>
            <p>Review the special exam application, then recommend or not recommend to the DVC ARSA for final approval.</p>
        </div>
        <form method="POST" action="process_approval.php" id="registrarForm">
            <input type="hidden" name="doc_id" value="<?php echo $id; ?>">
            <input type="hidden" name="action" id="registrarAction">
            <div class="reason-box" id="registrarReasonBox">
                <label for="registrar_reason">Reason for Not Recommending <span style="color:var(--danger)">*</span></label>
                <textarea name="reason" id="registrar_reason" placeholder="Provide a clear reason that will be sent to the student…"></textarea>
            </div>
            <div class="action-btns">
                <button type="button" class="btn btn-recommend" onclick="submitRegistrar('registrar_recommend_special')">
                    <i class="fa-solid fa-thumbs-up"></i> Recommend
                </button>
                <button type="button" class="btn btn-not-recommend" onclick="showRegistrarReason()">
                    <i class="fa-solid fa-thumbs-down"></i> Not Recommend
                </button>
                <button type="button" class="btn" id="registrarSubmitReason" style="display:none;background:var(--danger);color:#fff;" onclick="submitRegistrar('registrar_not_recommend_special')">
                    <i class="fa-solid fa-paper-plane"></i> Submit
                </button>
            </div>
        </form>
    </div>
    <?php elseif (in_array($row['module_type'], ['Resit','Retake','Special_Exam'])): ?>
    <!-- Resit/Retake/Special Phase-2: Registrar finalises — UNCHANGED -->
    <?php
        $mod = $row['module_type'] ?? ($row['exam_type'] ?? '');
        $auto_amount = $mod === 'Resit' ? 'KSh 800' : ($mod === 'Retake' ? 'KSh 10,023' : '');
    ?>
    <div class="no-print" style="margin-top:20px;text-align:center;">
        <form method="POST" action="process_approval.php" id="registrarForm">
            <input type="hidden" name="doc_id" value="<?php echo $id; ?>">
            <input type="hidden" name="action" value="registrar_finalise">
            <input type="hidden" name="amount_paid" value="<?php echo htmlspecialchars($auto_amount); ?>">
            <button type="submit" class="btn btn-finalise"
                onclick="return confirm('Finalise and send the completed document to the student\'s email?')">
                <i class="fa-solid fa-check-double"></i> Finalise &amp; Send to Student
            </button>
        </form>
    </div>

    <?php else: ?>
    <!-- Fees/Bursary/Other: Registrar approves or rejects -->
    <div class="action-panel no-print" style="border-top:4px solid var(--warning);margin-top:24px;">
        <div class="panel-header">
            <h3><i class="fa-solid fa-user-shield" style="color:var(--warning);"></i> Registrar Action</h3>
            <p>Review the <?php echo htmlspecialchars($row['module_type']); ?> request, then approve or reject.</p>
        </div>
        <form method="POST" action="process_approval.php" id="registrarFeesForm">
            <input type="hidden" name="doc_id" value="<?php echo $id; ?>">
            <input type="hidden" name="action" id="registrarFeesAction">
            <div class="reason-box" id="registrarFeesReasonBox">
                <label for="registrar_fees_reason">Reason for Rejection <span style="color:var(--danger)">*</span></label>
                <textarea name="reason" id="registrar_fees_reason" placeholder="Provide a clear reason that will be sent to the student…"></textarea>
            </div>
            <div class="action-btns">
                <button type="button" class="btn btn-approve" onclick="submitRegistrarFees('registrar_approve_fees')">
                    <i class="fa-solid fa-check-circle"></i> Approve
                </button>
                <button type="button" class="btn btn-reject" onclick="showRegistrarFeesReason()">
                    <i class="fa-solid fa-xmark-circle"></i> Reject
                </button>
                <button type="button" class="btn" id="registrarFeesSubmitReason"
                    style="display:none;background:var(--danger);color:#fff;"
                    onclick="submitRegistrarFees('registrar_reject_fees')">
                    <i class="fa-solid fa-paper-plane"></i> Submit Rejection
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($show_dvc_panel): ?>
    <div class="action-panel dvc-panel no-print">
        <div class="panel-header">
            <h3><i class="fa-solid fa-crown" style="color:var(--danger);"></i> DVC ARSA – Final Decision</h3>
            <p>Approve or reject the special exam application. An approval letter will be generated and emailed to the student.</p>
        </div>
        <form method="POST" action="process_approval.php" id="dvcForm">
            <input type="hidden" name="doc_id" value="<?php echo $id; ?>">
            <input type="hidden" name="action" id="dvcAction">
            <div class="reason-box" id="dvcReasonBox">
                <label for="dvc_reason">Reason for Rejection <span style="color:var(--danger)">*</span></label>
                <textarea name="reason" id="dvc_reason" placeholder="Provide a reason for the student…"></textarea>
            </div>
            <div class="action-btns">
                <button type="button" class="btn btn-approve" onclick="submitDVC('dvc_approve')">
                    <i class="fa-solid fa-check-circle"></i> Approve &amp; Send Letter
                </button>
                <button type="button" class="btn btn-reject" onclick="showDVCReason()">
                    <i class="fa-solid fa-xmark-circle"></i> Reject
                </button>
                <button type="button" class="btn" id="dvcSubmitReason" style="display:none;background:var(--danger);color:#fff;" onclick="submitDVC('dvc_reject')">
                    <i class="fa-solid fa-paper-plane"></i> Submit Rejection
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- ══════ FINANCE PANEL ══════ -->
    <?php if ($show_finance_panel): ?>

    <?php if ($finance_email_sent): ?>
    <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:12px 18px;border-radius:10px;margin-top:16px;font-weight:600;font-size:.9rem;display:flex;align-items:center;gap:10px;" class="no-print">
        <i class="fa-solid fa-circle-check"></i>
        <?php echo ($_GET['success'] === 'finalised') ? '✅ Form finalised and emailed to student successfully! They can now download and print it.' : '📧 Email sent successfully to the student.'; ?>
    </div>
    <?php endif; ?>

    <?php
    // ── Build payment approval letter (shown to Finance when viewing Resit/Retake) ──
    $mod_fin  = $row['module_type'] ?? '';
    $auto_amt = $mod_fin === 'Resit' ? 'KSh 800' : ($mod_fin === 'Retake' ? 'KSh 10,023' : 'KSh 800');

    // Fetch unit codes for display
    $fin_units_stmt = $conn->prepare("SELECT fu.unit_code FROM form_units fu JOIN resit_retake_forms rrf ON fu.form_id = rrf.id WHERE rrf.document_id = ? ORDER BY fu.id");
    $fin_units_stmt->bind_param("i", $id);
    $fin_units_stmt->execute();
    $fin_units_res = $fin_units_stmt->get_result();
    $fin_unit_codes = [];
    while ($fu = $fin_units_res->fetch_assoc()) $fin_unit_codes[] = $fu['unit_code'];
    $fin_units_str = implode(', ', $fin_unit_codes);

    $type_label_fin = $mod_fin === 'Resit' ? 'RESIT EXAMINATION' : ($mod_fin === 'Retake' ? 'RETAKE EXAMINATION' : strtoupper($mod_fin));
    $amount_words   = $mod_fin === 'Resit' ? 'eight hundred shillings only' : 'ten thousand and twenty-three shillings only';
    $amount_num     = $mod_fin === 'Resit' ? '800' : '10,023';
    $exam_period_fin = trim(($row['exam_month'] ?? '') . ' ' . ($row['exam_year'] ?? ''));

    if ($mod_fin === 'Resit') {
        $fee_rows_html = "<tr><td>1.</td><td>Examination fee</td><td><strong>Ksh 800</strong></td></tr>
                          <tr style='font-weight:700;background:#f5f5f5;'><td colspan='2'>TOTAL</td><td>Ksh 800</td></tr>";
    } else {
        $fee_rows_html = "<tr><td>1.</td><td>Tuition fee</td><td>Ksh 1,333</td></tr>
                          <tr><td>2.</td><td>Statutory fee</td><td>Ksh 8,690</td></tr>
                          <tr style='font-weight:700;background:#f5f5f5;'><td colspan='2'>TOTAL</td><td>Ksh 10,023</td></tr>";
    }
    ?>

    <?php if (in_array($mod_fin, ['Resit','Retake','Special_Exam'])): ?>
    <!-- ── Full MUT Exam Registration Form shown to Finance (matches what student receives) ── -->
    <?php
    // Fetch full units for form display
    $fin_full_units_stmt = $conn->prepare("SELECT fu.unit_code, fu.unit_title FROM form_units fu JOIN resit_retake_forms rrf ON fu.form_id = rrf.id WHERE rrf.document_id = ? ORDER BY fu.id");
    $fin_full_units_stmt->bind_param("i", $id);
    $fin_full_units_stmt->execute();
    $fin_full_units_res = $fin_full_units_stmt->get_result();
    $fin_full_units = [];
    while ($ffu = $fin_full_units_res->fetch_assoc()) $fin_full_units[] = $ffu;

    $chk_s = ($mod_fin === 'Special_Exam') ? 'checked' : '';
    $chk_r = ($mod_fin === 'Resit')        ? 'checked' : '';
    $chk_t = ($mod_fin === 'Retake')       ? 'checked' : '';
    $sig_date_disp = !empty($row['student_signature_date'])
        ? date('F j, Y', strtotime($row['student_signature_date']))
        : (!empty($row['upload_date']) ? date('F j, Y', strtotime($row['upload_date'])) : date('F j, Y'));
    $cod_date_disp  = !empty($row['cod_signed_at'])  ? date('F j, Y', strtotime($row['cod_signed_at']))  : '';
    $dean_date_disp = !empty($row['dean_signed_at']) ? date('F j, Y', strtotime($row['dean_signed_at'])) : '';
    $stamp_date_disp = strtoupper(date('d M Y'));
    ?>

    <!-- Form body styled exactly like the official MUT form -->
    <div style="margin-top:28px;background:#fff;padding:30px 36px;font-family:'Times New Roman',Times,serif;font-size:11pt;color:#000;border:1px solid #ddd;border-radius:8px;">

        <!-- Header -->
        <div style="text-align:center;margin-bottom:16px;position:relative;">
            <div style="position:absolute;top:0;right:0;font-size:9pt;font-weight:bold;">MUT/F/ASAA/015</div>
            <div style="font-size:13pt;font-weight:bold;text-transform:uppercase;margin-bottom:3px;">MURANG'A UNIVERSITY OF TECHNOLOGY</div>
            <div style="font-size:10pt;font-weight:bold;text-transform:uppercase;margin-bottom:3px;">OFFICE OF REGISTRAR (Academic and Student Affairs)</div>
            <div style="font-size:11pt;font-weight:bold;text-decoration:underline;margin-bottom:14px;">SPECIAL/RESIT/RETAKE EXAM REGISTRATION FORM</div>
        </div>

        <!-- Section 1: Exam type -->
        <div style="margin-bottom:12px;">
            <strong>1. For which Examination do you wish to register for?</strong>
            <table style="width:100%;border-collapse:collapse;margin-top:7px;">
                <tr>
                    <td style="width:28px;text-align:center;padding:7px;border:1px solid #000;"><input type="checkbox" <?php echo $chk_s; ?> disabled></td>
                    <td style="padding:7px;border:1px solid #000;">Special Exam</td>
                    <td style="width:28px;text-align:center;padding:7px;border:1px solid #000;"><input type="checkbox" <?php echo $chk_r; ?> disabled></td>
                    <td style="padding:7px;border:1px solid #000;">Resit Exam</td>
                    <td style="width:28px;text-align:center;padding:7px;border:1px solid #000;"><input type="checkbox" <?php echo $chk_t; ?> disabled></td>
                    <td style="padding:7px;border:1px solid #000;">Retake Exam</td>
                </tr>
            </table>
        </div>

        <!-- Section 2: Exam period -->
        <div style="margin-bottom:12px;">
            <strong>2. Examination Period</strong>
            <table style="width:100%;border-collapse:collapse;margin-top:7px;">
                <tr>
                    <th style="background:#d9d9d9;font-weight:bold;text-align:center;padding:7px;border:1px solid #000;">Month</th>
                    <th style="background:#d9d9d9;font-weight:bold;text-align:center;padding:7px;border:1px solid #000;">Year</th>
                </tr>
                <tr>
                    <td style="text-align:center;padding:7px;border:1px solid #000;"><?php echo htmlspecialchars($row['exam_month'] ?? ''); ?></td>
                    <td style="text-align:center;padding:7px;border:1px solid #000;"><?php echo htmlspecialchars($row['exam_year'] ?? ''); ?></td>
                </tr>
            </table>
        </div>

        <!-- Section 3: Personal details -->
        <div style="margin-bottom:12px;">
            <strong>3. Personal Details</strong>
            <table style="width:100%;border-collapse:collapse;margin-top:7px;">
                <tr><td style="width:40%;padding:7px;border:1px solid #000;">Student Name</td><td style="padding:7px;border:1px solid #000;"><?php echo htmlspecialchars($row['student_fullname']); ?></td></tr>
                <tr><td style="padding:7px;border:1px solid #000;">Student Registration Number</td><td style="padding:7px;border:1px solid #000;"><?php echo htmlspecialchars($row['reg_number']); ?></td></tr>
                <tr><td style="padding:7px;border:1px solid #000;">Cell phone</td><td style="padding:7px;border:1px solid #000;"><?php echo htmlspecialchars($row['student_phone'] ?? ''); ?></td></tr>
                <tr><td style="padding:7px;border:1px solid #000;">Email</td><td style="padding:7px;border:1px solid #000;"><?php echo htmlspecialchars($row['student_email']); ?></td></tr>
                <tr><td style="padding:7px;border:1px solid #000;">Course</td><td style="padding:7px;border:1px solid #000;"><?php echo htmlspecialchars($row['course'] ?? ''); ?></td></tr>
                <tr><td style="padding:7px;border:1px solid #000;">Department</td><td style="padding:7px;border:1px solid #000;"><?php echo htmlspecialchars($row['dept_name'] ?? ''); ?></td></tr>
            </table>
        </div>

        <!-- Section 4: Units -->
        <div style="margin-bottom:12px;">
            <strong>4. Units to be written</strong>
            <table style="width:100%;border-collapse:collapse;margin-top:7px;">
                <thead>
                    <tr>
                        <th style="background:#d9d9d9;width:46px;padding:7px;border:1px solid #000;text-align:center;">S/No</th>
                        <th style="background:#d9d9d9;padding:7px;border:1px solid #000;text-align:center;">Unit Code</th>
                        <th style="background:#d9d9d9;padding:7px;border:1px solid #000;">Unit Title</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fin_full_units as $i => $fu): ?>
                    <tr>
                        <td style="text-align:center;padding:6px 8px;border:1px solid #000;"><?php echo $i+1; ?></td>
                        <td style="text-align:center;padding:6px 8px;border:1px solid #000;"><?php echo htmlspecialchars($fu['unit_code']); ?></td>
                        <td style="padding:6px 8px;border:1px solid #000;"><?php echo htmlspecialchars($fu['unit_title']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php for ($e = count($fin_full_units); $e < 5; $e++): ?>
                    <tr>
                        <td style="text-align:center;padding:6px 8px;border:1px solid #000;"><?php echo $e+1; ?></td>
                        <td style="padding:6px 8px;border:1px solid #000;">&nbsp;</td>
                        <td style="padding:6px 8px;border:1px solid #000;">&nbsp;</td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <!-- Declaration & Student Signature -->
        <div style="margin-bottom:14px;">
            <p style="font-size:9pt;text-align:justify;margin-bottom:10px;line-height:1.5;"><strong>DECLARATION BY STUDENT:</strong> I agree to abide by the rules and procedures governing Murang'a University of Technology examinations. I understand that I must take my identity document with me to write my examination and that I have 14 consecutive days from the Examination Registration Closing Date to follow up on my examination registration status. I also declare that I have successfully completed the compulsory assignments for the above subject(s).</p>
            <table style="border:none;width:100%;">
                <tr>
                    <td style="border:none;width:60%;vertical-align:bottom;">
                        <div style="font-weight:bold;margin-bottom:5px;">Student Signature</div>
                        <span style="font-style:italic;font-family:Georgia,'Times New Roman',serif;font-size:15pt;color:#1a1a2e;border-bottom:2px solid #000;display:inline-block;min-width:200px;padding:2px 0;"><?php echo htmlspecialchars($row['student_fullname']); ?></span>
                        <div style="font-size:8pt;color:#666;font-style:italic;margin-top:3px;">Signature auto-generated from login credentials</div>
                    </td>
                    <td style="border:none;vertical-align:bottom;text-align:right;">
                        <div style="font-weight:bold;margin-bottom:5px;">Date</div>
                        <div style="border-bottom:1px solid #000;padding:3px 10px;"><?php echo $sig_date_disp; ?></div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Recommended / Approved by -->
        <div style="margin-top:16px;">
            <div style="font-weight:bold;margin-bottom:7px;">Recommended By:</div>
            <div style="display:flex;margin-bottom:10px;align-items:center;">
                <span style="width:130px;font-weight:bold;font-size:10pt;">CoD (Name)</span>
                <span style="flex:1;border-bottom:1px solid #000;margin:0 8px;min-height:20px;padding:2px 4px;font-style:italic;font-family:Georgia,'Times New Roman',serif;font-size:12pt;color:#1a1a2e;"><?php echo htmlspecialchars($row['cod_signer_name'] ?? ''); ?></span>
                <span style="margin:0 6px;font-size:10pt;">Date:</span>
                <span style="width:110px;border-bottom:1px solid #000;font-size:9.5pt;color:#1a1a2e;padding:2px 4px;"><?php echo $cod_date_disp; ?></span>
            </div>
            <div style="font-weight:bold;margin-bottom:7px;margin-top:10px;">Approved By:</div>
            <div style="display:flex;margin-bottom:10px;align-items:center;">
                <span style="width:130px;font-weight:bold;font-size:10pt;">Dean (Name)</span>
                <span style="flex:1;border-bottom:1px solid #000;margin:0 8px;min-height:20px;padding:2px 4px;font-style:italic;font-family:Georgia,'Times New Roman',serif;font-size:12pt;color:#1a1a2e;"><?php echo htmlspecialchars($row['dean_signer_name'] ?? ''); ?></span>
                <span style="margin:0 6px;font-size:10pt;">Date:</span>
                <span style="width:110px;border-bottom:1px solid #000;font-size:9.5pt;color:#1a1a2e;padding:2px 4px;"><?php echo $dean_date_disp; ?></span>
            </div>
        </div>

        <!-- Confirmation of Payment with Finance Stamp -->
        <div style="margin-top:14px;border-top:2px solid #000;padding-top:10px;">
            <div style="font-weight:bold;font-style:italic;margin-bottom:8px;">Confirmation of Payment:</div>
            <div style="display:flex;align-items:center;">
                <span style="width:130px;font-weight:bold;font-size:10pt;">Amount Paid:</span>
                <span style="flex:0.5;border-bottom:1px solid #000;margin:0 8px;min-height:20px;padding:2px 4px;font-size:11pt;"><?php echo htmlspecialchars($auto_amt); ?></span>
                <span style="margin:0 16px;"></span>
                <span style="width:130px;font-weight:bold;font-size:10pt;">Signature &amp; Stamp:</span>
                <span style="flex:0.5;">
                    <span style="display:inline-flex;flex-direction:column;align-items:center;justify-content:center;border:2.5px solid #1a3a6b;border-radius:3px;padding:8px 12px;min-width:130px;background:#fff;position:relative;">
                        <span style="position:absolute;inset:2px;border:1px solid #1a3a6b;border-radius:1px;"></span>
                        <span style="font-weight:900;text-transform:uppercase;letter-spacing:1.5px;color:#1a3a6b;font-size:.62rem;">FINANCE OFFICE</span>
                        <span style="font-weight:700;text-transform:uppercase;color:#1a3a6b;font-size:.52rem;margin-top:2px;text-align:center;line-height:1.3;">MURANG'A UNIVERSITY<br>OF TECHNOLOGY</span>
                        <span style="font-weight:900;color:#c0392b;margin:4px 0 3px;font-size:.78rem;letter-spacing:1.5px;"><?php echo $stamp_date_disp; ?></span>
                        <span style="color:#1a3a6b;font-size:.44rem;text-align:center;line-height:1.4;">P.O. Box 75-10200, Murang'a<br>Tel: 0711463 515</span>
                        <span style="margin-top:4px;width:28px;height:28px;border:1px solid #1a3a6b;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;text-align:center;font-size:.38rem;font-weight:700;color:#1a3a6b;line-height:1.2;">MUT IS<br>ISO 9001:<br>2015 CERT</span>
                    </span>
                </span>
            </div>
        </div>

    </div><!-- end form body -->

    <!-- Finalise button — only shown when pending -->
    <?php if ($finance_is_pending): ?>
    <div class="no-print" style="margin-top:20px;">
        <form method="POST" action="process_approval.php" id="financeForm">
            <input type="hidden" name="doc_id" value="<?php echo $id; ?>">
            <input type="hidden" name="action" value="finance_finalise">
            <input type="hidden" name="amount_paid" value="<?php echo htmlspecialchars($auto_amt); ?>">
            <button type="submit" class="btn btn-finalise"
                onclick="return confirm('Finalise and email this form to the student?')">
                <i class="fa-solid fa-check-double"></i> Finalise &amp; Send to Student
            </button>
        </form>
    </div>
    <?php else: ?>
    <div class="no-print" style="margin-top:16px;background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:12px 18px;border-radius:10px;font-weight:600;font-size:.9rem;display:flex;align-items:center;gap:10px;">
        <i class="fa-solid fa-circle-check"></i> This application has been finalised and the form was emailed to the student.
    </div>
    <?php endif; ?>

    <?php elseif ($mod_fin === 'Bursary'): ?>
    <!-- Finance approves / rejects Bursary directly -->
    <div class="action-panel finance-panel no-print" style="margin-top:24px;">
        <div class="panel-header">
            <h3><i class="fa-solid fa-building-columns" style="color:#0ea5e9;"></i> Finance Office – Bursary Decision</h3>
            <?php if ($finance_is_pending): ?>
            <p>Approve or reject the bursary application. An email notification will be sent to the student.</p>
            <?php else: ?>
            <p style="color:#22c55e;font-weight:600;"><i class="fa-solid fa-circle-check"></i> This bursary application has already been processed.</p>
            <?php endif; ?>
        </div>

        <?php if ($finance_is_pending): ?>
        <form method="POST" action="process_approval.php" id="financeBursaryForm">
            <input type="hidden" name="doc_id" value="<?php echo $id; ?>">
            <input type="hidden" name="action" id="financeBursaryAction">
            <div class="reason-box" id="financeBursaryReasonBox">
                <label>Reason for Rejection <span style="color:var(--danger)">*</span></label>
                <textarea name="reason" id="finance_bursary_reason" placeholder="Provide a clear reason that will be emailed to the student…"></textarea>
            </div>
            <div class="action-btns">
                <button type="button" class="btn btn-approve" onclick="submitFinanceBursary('finance_approve_bursary')">
                    <i class="fa-solid fa-check-circle"></i> Approve &amp; Notify Student
                </button>
                <button type="button" class="btn btn-reject" onclick="showFinanceBursaryReason()">
                    <i class="fa-solid fa-xmark-circle"></i> Reject
                </button>
                <button type="button" class="btn" id="financeBursarySubmitReason"
                    style="display:none;background:var(--danger);color:#fff;"
                    onclick="submitFinanceBursary('finance_reject_bursary')">
                    <i class="fa-solid fa-paper-plane"></i> Submit Rejection
                </button>
            </div>
        </form>
        <?php endif; ?>

        <!-- Finance: Optional Comment (always visible) -->
        <div class="finance-email-form no-print" style="margin-top:24px;">
            <div style="font-weight:700;font-size:.95rem;margin-bottom:4px;display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-comment-dots" style="color:#0ea5e9;"></i> Write to Student <span style="font-weight:400;font-size:.82rem;color:#64748b;">(Optional)</span>
            </div>
            <div style="font-size:.8rem;color:#64748b;margin-bottom:12px;">Send a direct message to the student about this bursary.</div>
            <form method="POST" action="process_approval.php">
                <input type="hidden" name="doc_id" value="<?php echo $id; ?>">
                <input type="hidden" name="action" value="finance_send_email">
                <label>Email Subject <span style="color:var(--danger)">*</span></label>
                <input type="text" name="email_subject" placeholder="e.g. Bursary Query" required>
                <label>Message to Student <span style="color:var(--danger)">*</span></label>
                <textarea name="email_body" required placeholder="Type your message here…"></textarea>
                <button type="submit" class="btn btn-finance-send">
                    <i class="fa-solid fa-paper-plane"></i> Send Email
                </button>
            </form>
        </div>
    </div>

    <?php elseif ($mod_fin === 'Fees'): ?>
    <!-- Finance approves / rejects Fee Adjustment -->
    <div class="action-panel finance-panel no-print" style="margin-top:24px;">
        <div class="panel-header">
            <h3><i class="fa-solid fa-building-columns" style="color:#0ea5e9;"></i> Finance Office – Fee Adjustment Decision</h3>
            <?php if ($finance_is_pending): ?>
            <p>Approve or reject the fee adjustment request. A formal letter will be emailed to the student.</p>
            <?php else: ?>
            <p style="color:#22c55e;font-weight:600;"><i class="fa-solid fa-circle-check"></i> This fee adjustment has already been processed.</p>
            <?php endif; ?>
        </div>

        <?php if ($finance_is_pending): ?>
        <form method="POST" action="process_approval.php" id="financeFeesForm">
            <input type="hidden" name="doc_id" value="<?php echo $id; ?>">
            <input type="hidden" name="action" id="financeFeesAction">
            <div class="reason-box" id="financeFeesReasonBox">
                <label>Reason for Rejection <span style="color:var(--danger)">*</span></label>
                <textarea name="reason" id="finance_fees_reason" placeholder="Provide a clear reason that will be emailed to the student…"></textarea>
            </div>
            <div class="action-btns">
                <button type="button" class="btn btn-approve" onclick="submitFinanceFees('finance_approve_fees')">
                    <i class="fa-solid fa-check-circle"></i> Approve &amp; Send Letter
                </button>
                <button type="button" class="btn btn-reject" onclick="showFinanceFeesReason()">
                    <i class="fa-solid fa-xmark-circle"></i> Reject
                </button>
                <button type="button" class="btn" id="financeFeesSubmitReason"
                    style="display:none;background:var(--danger);color:#fff;"
                    onclick="submitFinanceFees('finance_reject_fees')">
                    <i class="fa-solid fa-paper-plane"></i> Submit Rejection
                </button>
            </div>
        </form>
        <?php endif; ?>

        <!-- Finance: Optional Comment (always visible) -->
        <div class="finance-email-form no-print" style="margin-top:24px;">
            <div style="font-weight:700;font-size:.95rem;margin-bottom:4px;display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-comment-dots" style="color:#0ea5e9;"></i> Write to Student <span style="font-weight:400;font-size:.82rem;color:#64748b;">(Optional)</span>
            </div>
            <div style="font-size:.8rem;color:#64748b;margin-bottom:12px;">Send a direct message to the student about this fee adjustment.</div>
            <form method="POST" action="process_approval.php">
                <input type="hidden" name="doc_id" value="<?php echo $id; ?>">
                <input type="hidden" name="action" value="finance_send_email">
                <label>Email Subject <span style="color:var(--danger)">*</span></label>
                <input type="text" name="email_subject" placeholder="e.g. Fee Adjustment – Additional Information" required>
                <label>Message to Student <span style="color:var(--danger)">*</span></label>
                <textarea name="email_body" required placeholder="Type your message here…"></textarea>
                <button type="submit" class="btn btn-finance-send">
                    <i class="fa-solid fa-paper-plane"></i> Send Email
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // end show_finance_panel ?>

</div><!-- /screen-container -->

<script>
function submitCOD(action) { document.getElementById('codAction').value = action; document.getElementById('codForm').submit(); }
function showCODReason() { document.getElementById('codReasonBox').style.display='block'; document.getElementById('codSubmitReason').style.display='inline-flex'; document.getElementById('cod_reason').focus(); }
function submitDean(action) { document.getElementById('deanAction').value = action; document.getElementById('deanForm').submit(); }
function showDeanReason() { document.getElementById('deanReasonBox').style.display='block'; document.getElementById('deanSubmitReason').style.display='inline-flex'; document.getElementById('dean_reason').focus(); }
function submitRegistrar(action) { document.getElementById('registrarAction').value = action; document.getElementById('registrarForm').submit(); }
function showRegistrarReason() { document.getElementById('registrarReasonBox').style.display='block'; document.getElementById('registrarSubmitReason').style.display='inline-flex'; document.getElementById('registrar_reason').focus(); }
function submitRegistrarFees(action) { document.getElementById('registrarFeesAction').value = action; document.getElementById('registrarFeesForm').submit(); }
function showRegistrarFeesReason() { document.getElementById('registrarFeesReasonBox').style.display='block'; document.getElementById('registrarFeesSubmitReason').style.display='inline-flex'; document.getElementById('registrar_fees_reason').focus(); }
function submitDVC(action) { document.getElementById('dvcAction').value = action; document.getElementById('dvcForm').submit(); }
function showDVCReason() { document.getElementById('dvcReasonBox').style.display='block'; document.getElementById('dvcSubmitReason').style.display='inline-flex'; document.getElementById('dvc_reason').focus(); }
// Finance functions
function submitFinanceBursary(action) { document.getElementById('financeBursaryAction').value = action; document.getElementById('financeBursaryForm').submit(); }
function showFinanceBursaryReason() { document.getElementById('financeBursaryReasonBox').style.display='block'; document.getElementById('financeBursarySubmitReason').style.display='inline-flex'; document.getElementById('finance_bursary_reason').focus(); }
function submitFinanceFees(action) { document.getElementById('financeFeesAction').value = action; document.getElementById('financeFeesForm').submit(); }
function showFinanceFeesReason() { document.getElementById('financeFeesReasonBox').style.display='block'; document.getElementById('financeFeesSubmitReason').style.display='inline-flex'; document.getElementById('finance_fees_reason').focus(); }
</script>
</body>
</html>