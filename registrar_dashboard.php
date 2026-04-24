<?php
session_start();
require_once 'db_config.php';
require_once 'mailer.php';

if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php"); exit();
}

$is_registrar_view   = isset($_SESSION['current_admin_view']) && $_SESSION['current_admin_view'] === 'registrar';
$is_actual_registrar = ($_SESSION['role'] === 'admin' && $_SESSION['admin_role'] === 'registrar');

if (!$is_registrar_view && !($is_actual_registrar || $_SESSION['role'] === 'super_admin')) {
    header("Location: login.php"); exit();
}

// ── Handle Compose Email POST ──
$email_success = '';
$email_error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['compose_email'])) {
    $to_email   = trim($_POST['to_email'] ?? '');
    $to_name    = trim($_POST['to_name'] ?? '');
    $subject    = trim($_POST['subject'] ?? '');
    $body       = trim($_POST['body'] ?? '');
    $attachment = $_FILES['attachment'] ?? null;

    if (empty($to_email) || empty($subject) || empty($body)) {
        $email_error = 'Please fill in all required fields.';
    } elseif (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        $email_error = 'Invalid email address.';
    } else {
        // Build attachments array for sendEmail()
        $attachments = [];
        if ($attachment && $attachment['error'] === UPLOAD_ERR_OK) {
            $attachments[] = [
                'path' => $attachment['tmp_name'],
                'name' => basename($attachment['name']),
            ];
        }

        // Use the project's shared mailer helper
        $html_body   = nl2br(htmlspecialchars($body));
        $mail_result = sendEmail($to_email, $to_name, $subject, $html_body, $attachments);

        if ($mail_result['success']) {
            $email_success = "Email sent successfully to $to_name ($to_email).";
        } else {
            $email_error = 'Email could not be sent: ' . $mail_result['error'];
        }
    }
}

// Quick stats
$stats = $conn->query("SELECT 
    SUM(CASE WHEN status='Pending_Registrar' THEN 1 ELSE 0 END) as total_pending,
    SUM(CASE WHEN DATE(upload_date)=CURDATE() AND status='Pending_Registrar' THEN 1 ELSE 0 END) as today,
    SUM(CASE WHEN status='Pending_Registrar' AND module_type='Resit' THEN 1 ELSE 0 END) as resit_pend,
    SUM(CASE WHEN status='Pending_Registrar' AND module_type='Retake' THEN 1 ELSE 0 END) as retake_pend,
    SUM(CASE WHEN status='Pending_Registrar' AND module_type='Special_Exam' THEN 1 ELSE 0 END) as special_pend
    FROM documents")->fetch_assoc();

$sem_start = date('Y') . '-01-01';

// All exam applications this semester across ALL schools
$exam_summary = $conn->query("SELECT 
    u.full_name, u.reg_number as student_reg,
    dept.dept_name, dept.school,
    d.id as doc_id, d.module_type, d.status, d.upload_date,
    rrf.exam_month, rrf.exam_year,
    GROUP_CONCAT(fu.unit_code SEPARATOR ', ') as unit_codes
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    LEFT JOIN departments dept ON u.department_id = dept.id
    LEFT JOIN resit_retake_forms rrf ON rrf.document_id = d.id
    LEFT JOIN form_units fu ON fu.form_id = rrf.id
    WHERE d.module_type IN ('Resit','Retake','Special_Exam')
      AND d.upload_date >= '$sem_start'
    GROUP BY d.id
    ORDER BY dept.school, dept.dept_name, d.module_type, u.full_name");

// Recent pending docs
$recent = $conn->query("SELECT d.*, u.full_name, u.reg_number, dept.dept_name, dept.school
    FROM documents d JOIN users u ON d.reg_number = u.reg_number 
    LEFT JOIN departments dept ON u.department_id = dept.id
    WHERE d.status = 'Pending_Registrar' 
    ORDER BY d.upload_date DESC LIMIT 6");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Registrar Dashboard | MUT DMS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--primary:#f59e0b;--bg:#f8fafc;--card:#fff;--text:#1e293b;--text-light:#64748b;--border:#e2e8f0;--shadow:0 1px 3px rgba(0,0,0,.1);--shadow-lg:0 10px 15px -3px rgba(0,0,0,.1);--success:#22c55e;--danger:#ef4444}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text)}
.layout{display:flex;min-height:100vh}
.sidebar{width:280px;background:#0f172a;position:fixed;height:100vh;overflow-y:auto;z-index:100;display:flex;flex-direction:column}
.sidebar-header{padding:24px;border-bottom:1px solid rgba(255,255,255,.1)}
.logo{display:flex;align-items:center;gap:12px}
.logo-icon{width:48px;height:48px;background:linear-gradient(135deg,var(--primary) 0%,#d97706 100%);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.5rem}
.logo-text{color:white}.logo-text h3{font-size:1.1rem;font-weight:700}.logo-text span{font-size:.75rem;color:rgba(255,255,255,.6)}
.current-view-box{margin:16px;padding:16px;background:linear-gradient(135deg,rgba(245,158,11,.2) 0%,rgba(245,158,11,.1) 100%);border:1px solid rgba(245,158,11,.3);border-radius:12px;color:white}
.current-view-box .label{font-size:.75rem;color:rgba(255,255,255,.6);margin-bottom:4px;text-transform:uppercase}
.current-view-box .value{font-size:1.1rem;font-weight:700;color:var(--primary);display:flex;align-items:center;gap:8px}
.nav-section{padding:20px 0;flex:1}
.nav-title{padding:8px 24px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4)}
.nav-item{display:flex;align-items:center;gap:12px;padding:12px 24px;color:rgba(255,255,255,.7);text-decoration:none;transition:all .2s;border-left:3px solid transparent}
.nav-item:hover,.nav-item.active{background:rgba(255,255,255,.05);color:white;border-left-color:var(--primary)}
.nav-item i{width:20px;text-align:center}
.btn-back{display:flex;align-items:center;justify-content:center;gap:8px;margin:0 16px 16px;padding:10px;background:rgba(255,255,255,.1);border:1px dashed rgba(255,255,255,.3);border-radius:8px;color:rgba(255,255,255,.7);font-size:.85rem;text-decoration:none;transition:all .2s}
.btn-back:hover{background:rgba(255,255,255,.2);color:white}
.sidebar-footer{padding:16px 24px;border-top:1px solid rgba(255,255,255,.1);margin-top:auto}
.admin-info{display:flex;align-items:center;gap:12px;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid rgba(255,255,255,.1)}
.admin-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--primary) 0%,#d97706 100%);display:flex;align-items:center;justify-content:center;color:white;font-weight:700}
.admin-details .name{color:white;font-weight:600;font-size:.9rem}
.admin-details .role{color:rgba(255,255,255,.5);font-size:.75rem;text-transform:uppercase}
.btn-logout{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;background:rgba(239,68,68,.2);color:#ef4444;border:none;border-radius:8px;font-size:.875rem;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-logout:hover{background:rgba(239,68,68,.3)}
.main-content{flex:1;margin-left:280px;min-height:100vh}
.header{background:var(--card);padding:20px 32px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.page-title h1{font-size:1.75rem;font-weight:800}
.page-title p{font-size:.875rem;color:var(--text-light);margin-top:4px}
.content{padding:32px}
.stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:20px;margin-bottom:28px}
.stat-card{background:var(--card);border-radius:16px;padding:20px;box-shadow:var(--shadow);border:1px solid var(--border);text-align:center}
.stat-icon{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:1.25rem}
.stat-icon.orange{background:#fef3c7;color:#d97706}.stat-icon.green{background:#d1fae5;color:#059669}
.stat-icon.blue{background:#dbeafe;color:#2563eb}.stat-icon.purple{background:#ede9fe;color:#7c3aed}
.stat-icon.teal{background:#ccfbf1;color:#0d9488}
.stat-value{font-size:2.2rem;font-weight:800;color:var(--text);margin-bottom:4px;line-height:1}
.stat-label{font-size:.8rem;color:var(--text-light);font-weight:500}
.section-card{background:var(--card);border-radius:16px;box-shadow:var(--shadow);border:1px solid var(--border);margin-bottom:28px;overflow:hidden}
.section-hdr{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:10px}
.section-title{font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.section-title i{color:var(--primary)}
.view-all{font-size:.875rem;color:var(--primary);text-decoration:none;font-weight:600}
.exam-table{width:100%;border-collapse:collapse}
.exam-table th,.exam-table td{padding:11px 14px;text-align:left;border-bottom:1px solid var(--border);font-size:.85rem}
.exam-table th{background:var(--bg);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--text-light)}
.exam-table tr:last-child td{border-bottom:none}
.exam-table tbody tr:hover{background:rgba(241,245,249,.5)}
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:.75rem;font-weight:600}
.badge-resit{background:#dbeafe;color:#1d4ed8}.badge-retake{background:#ede9fe;color:#6d28d9}
.badge-special{background:#ccfbf1;color:#0f766e}.badge-pending{background:#fef3c7;color:#92400e}
.badge-approved{background:#d1fae5;color:#065f46}.badge-rejected{background:#fee2e2;color:#991b1b}
.action-btns{display:flex;gap:6px}
.btn-action{padding:6px 12px;border:none;border-radius:7px;font-size:.8rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;white-space:nowrap;transition:all .2s}
.btn-view{background:var(--bg);color:var(--text);border:1px solid var(--border)}.btn-view:hover{background:var(--border)}
.btn-finalise{background:var(--primary);color:white}.btn-finalise:hover{background:#d97706}
.btn-print{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:var(--bg);border:1px solid var(--border);border-radius:8px;font-size:.875rem;font-weight:600;cursor:pointer;color:var(--text);text-decoration:none}
.btn-print:hover{background:var(--border)}
.quick-actions{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;margin-bottom:28px}
.action-card{background:var(--card);border-radius:16px;padding:22px;box-shadow:var(--shadow);border:1px solid var(--border);display:flex;align-items:center;gap:16px;text-decoration:none;color:inherit;transition:all .2s}
.action-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg);border-color:var(--primary)}
.action-icon{width:56px;height:56px;border-radius:12px;background:rgba(245,158,11,.1);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:1.4rem}
.action-text h3{font-size:1rem;font-weight:700;margin-bottom:4px}.action-text p{font-size:.8rem;color:var(--text-light)}
.empty-state{text-align:center;padding:48px 24px;color:var(--text-light)}
.empty-state i{font-size:3rem;margin-bottom:12px;display:block;opacity:.4}
.urgent-badge{background:#fee2e2;color:var(--danger);padding:3px 8px;border-radius:8px;font-size:.75rem;font-weight:700}
@media print{.sidebar,.btn-print,.action-btns,.quick-actions,.btn-back{display:none!important}.main-content{margin-left:0!important}.header{position:static!important}}
.compose-card{background:var(--card);border-radius:16px;box-shadow:var(--shadow);border:1px solid var(--border);margin-bottom:28px;overflow:hidden}
.compose-form{padding:24px}
.compose-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.compose-group{margin-bottom:16px}
.compose-group label{display:block;font-size:.78rem;font-weight:600;color:var(--text-light);margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em}
.compose-input{width:100%;padding:10px 14px;border:1px solid var(--border);border-radius:8px;font-size:.9rem;font-family:inherit;color:var(--text);background:#fff;transition:border-color .2s}
.compose-input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(245,158,11,.1)}
.compose-textarea{width:100%;padding:10px 14px;border:1px solid var(--border);border-radius:8px;font-size:.9rem;font-family:inherit;color:var(--text);background:#fff;resize:vertical;min-height:130px;transition:border-color .2s}
.compose-textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(245,158,11,.1)}
.student-suggestions{position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--border);border-top:none;border-radius:0 0 8px 8px;max-height:180px;overflow-y:auto;z-index:200;display:none}
.student-suggestion-item{padding:10px 14px;cursor:pointer;font-size:.875rem;border-bottom:1px solid var(--border)}
.student-suggestion-item:last-child{border-bottom:none}
.student-suggestion-item:hover{background:var(--bg)}
.student-suggestion-name{font-weight:600}
.student-suggestion-meta{font-size:.78rem;color:var(--text-light)}
.compose-search-wrap{position:relative}
.btn-send{background:var(--primary);color:white;border:none;padding:11px 24px;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:all .2s}
.btn-send:hover{background:#d97706;transform:translateY(-1px)}
.compose-alert{padding:11px 16px;border-radius:8px;font-size:.875rem;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.compose-alert.success{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.compose-alert.error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
</style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon"><i class="fa-solid fa-stamp"></i></div>
                <div class="logo-text"><h3>Registrar Portal</h3><span>Academic &amp; Student Affairs</span></div>
            </div>
        </div>

        <div class="current-view-box">
            <div class="label">Currently Viewing</div>
            <div class="value"><i class="fa-solid fa-eye"></i> Registrar View</div>
        </div>

        <?php if (isset($_SESSION['current_admin_view']) && $_SESSION['role'] === 'super_admin'): ?>
        <a href="admin_dashboard.php?clear_view=1" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back to System Admin
        </a>
        <?php endif; ?>

        <nav class="nav-section">
            <div class="nav-title">Menu</div>
            <a href="registrar_dashboard.php" class="nav-item active">
                <i class="fa-solid fa-grid-2"></i><span>Dashboard</span>
            </a>
            <a href="registrar_documents.php" class="nav-item">
                <i class="fa-solid fa-folder-open"></i><span>Students Applications</span>
            </a>
            <a href="registrar_reports.php" class="nav-item">
                <i class="fa-solid fa-chart-bar"></i><span>Reports</span>
            </a>
            <a href="#composeSection" class="nav-item" onclick="document.getElementById('composeSection').scrollIntoView({behavior:'smooth'});return false;">
                <i class="fa-solid fa-envelope-open-text"></i><span>Compose Email</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="admin-info">
                <div class="admin-avatar"><?php echo strtoupper(substr($_SESSION['full_name']??'R',0,1)); ?></div>
                <div class="admin-details">
                    <div class="name"><?php echo htmlspecialchars($_SESSION['full_name']??'Registrar'); ?></div>
                    <div class="role">Registrar</div>
                </div>
            </div>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </aside>

    <main class="main-content">
        <header class="header">
            <div class="page-title">
                <h1>Registrar Dashboard</h1>
                <p>Finance, Planning &amp; Development — Final approval authority for all documents</p>
            </div>
        </header>

        <div class="content">
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="fa-solid fa-clock"></i></div>
                    <div class="stat-value"><?php echo $stats['total_pending']??0; ?></div>
                    <div class="stat-label">Pending Finalisation</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fa-solid fa-calendar-day"></i></div>
                    <div class="stat-value"><?php echo $stats['today']??0; ?></div>
                    <div class="stat-label">Today's Arrivals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fa-solid fa-file-pen"></i></div>
                    <div class="stat-value"><?php echo $stats['resit_pend']??0; ?></div>
                    <div class="stat-label">Resit (Pending)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="fa-solid fa-rotate"></i></div>
                    <div class="stat-value"><?php echo $stats['retake_pend']??0; ?></div>
                    <div class="stat-label">Retake (Pending)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon teal"><i class="fa-solid fa-star"></i></div>
                    <div class="stat-value"><?php echo $stats['special_pend']??0; ?></div>
                    <div class="stat-label">Special (Pending)</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="registrar_documents.php" class="action-card">
                    <div class="action-icon"><i class="fa-solid fa-folder-open"></i></div>
                    <div class="action-text"><h3>Review &amp; Finalise</h3><p>Act on documents awaiting your approval</p></div>
                </a>
                <a href="registrar_reports.php" class="action-card">
                    <div class="action-icon"><i class="fa-solid fa-chart-pie"></i></div>
                    <div class="action-text"><h3>System Reports</h3><p>Analytics across all schools</p></div>
                </a>
            </div>

            <!-- ── Semester Exam Applications – All Schools ── -->
            <div class="section-card">
                <div class="section-hdr">
                    <h2 class="section-title">
                        <i class="fa-solid fa-table-list"></i>
                        This Semester's Exam Applications (All Schools)
                    </h2>
                    <a href="#" class="btn-print" onclick="window.print();return false;">
                        <i class="fa-solid fa-print"></i> Print List
                    </a>
                </div>

                <?php if ($exam_summary && $exam_summary->num_rows > 0): ?>
                <div style="overflow-x:auto;">
                <table class="exam-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Name</th>
                            <th>Reg. No.</th>
                            <th>School</th>
                            <th>Department</th>
                            <th>Type</th>
                            <th>Period</th>
                            <th>Units</th>
                            <th>Status</th>
                            <th class="no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $n=1; while ($row = $exam_summary->fetch_assoc()):
                            $type_badge = match($row['module_type']){
                                'Resit'        => '<span class="badge badge-resit">Resit</span>',
                                'Retake'       => '<span class="badge badge-retake">Retake</span>',
                                'Special_Exam' => '<span class="badge badge-special">Special</span>',
                                default        => '<span class="badge">'.$row['module_type'].'</span>'
                            };
                            $status_badge = match(true){
                                str_contains($row['status'],'Pending') => '<span class="badge badge-pending">'.str_replace('_',' ',$row['status']).'</span>',
                                $row['status']==='Approved'            => '<span class="badge badge-approved">Approved</span>',
                                $row['status']==='Rejected'            => '<span class="badge badge-rejected">Rejected</span>',
                                default                                => '<span class="badge">'.$row['status'].'</span>'
                            };
                            $days_waiting = floor((time()-strtotime($row['upload_date']))/(60*60*24));
                        ?>
                        <tr>
                            <td><?php echo $n++; ?></td>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($row['full_name']); ?></td>
                            <td style="font-family:monospace;font-size:.8rem;"><?php echo htmlspecialchars($row['student_reg']); ?></td>
                            <td style="font-size:.8rem;"><?php echo htmlspecialchars($row['school']??'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['dept_name']??'N/A'); ?></td>
                            <td><?php echo $type_badge; ?></td>
                            <td><?php echo htmlspecialchars($row['exam_month'].' '.($row['exam_year']??'')); ?></td>
                            <td style="font-size:.8rem;"><?php echo htmlspecialchars($row['unit_codes']??'—'); ?></td>
                            <td>
                                <?php echo $status_badge; ?>
                                <?php if ($days_waiting > 7 && str_contains($row['status'],'Pending')): ?>
                                <span class="urgent-badge" style="margin-left:4px;"><?php echo $days_waiting; ?>d</span>
                                <?php endif; ?>
                            </td>
                            <td class="no-print">
                                <?php if ($row['status'] === 'Pending_Registrar'): ?>
                                <a href="view_form.php?id=<?php echo $row['doc_id']; ?>" class="btn-action btn-view">
                                    <i class="fa-solid fa-eye"></i> View
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fa-solid fa-folder-open"></i>
                    <p>No exam applications recorded this semester.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Compose Email to Student ── -->
            <div class="compose-card" id="composeSection">
                <div class="section-hdr">
                    <h2 class="section-title"><i class="fa-solid fa-envelope-open-text"></i> Compose Email to Student</h2>
                </div>
                <div class="compose-form">
                    <?php if ($email_error): ?>
                    <div class="compose-alert error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($email_error); ?></div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="compose_email" value="1">
                        <input type="hidden" name="to_name" id="hiddenToName">

                        <div class="compose-row">
                            <div class="compose-group">
                                <label>Search Student <span style="color:#ef4444">*</span></label>
                                <div class="compose-search-wrap">
                                    <input type="text" id="studentSearch" class="compose-input"
                                        placeholder="Type name or reg number…"
                                        autocomplete="off" oninput="searchStudents(this.value)">
                                    <div class="student-suggestions" id="studentSuggestions"></div>
                                </div>
                            </div>
                            <div class="compose-group">
                                <label>Recipient Email <span style="color:#ef4444">*</span></label>
                                <input type="email" name="to_email" id="toEmail" class="compose-input"
                                    placeholder="auto-filled or type manually"
                                    value="<?php echo htmlspecialchars($_POST['to_email'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="compose-group">
                            <label>Subject <span style="color:#ef4444">*</span></label>
                            <input type="text" name="subject" class="compose-input"
                                placeholder="e.g. Bursary Cheque – April 2026"
                                value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>" required>
                        </div>

                        <div class="compose-group">
                            <label>Message <span style="color:#ef4444">*</span></label>
                            <textarea name="body" class="compose-textarea"
                                placeholder="Write your message here…" required><?php echo htmlspecialchars($_POST['body'] ?? ''); ?></textarea>
                        </div>

                        <div class="compose-group">
                            <label>Attach Document <span style="font-weight:400;text-transform:none;font-size:.75rem;">(optional — PDF, image, Word)</span></label>
                            <input type="file" name="attachment" class="compose-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                        </div>

                        <button type="submit" class="btn-send">
                            <i class="fa-solid fa-paper-plane"></i> Send Email
                        </button>
                    </form>
                </div>
            </div>

            <!-- Recent Pending -->
            <div class="section-card">
                <div class="section-hdr">
                    <h2 class="section-title"><i class="fa-solid fa-clock-rotate-left"></i> Recently Submitted (Pending)</h2>
                    <a href="registrar_documents.php" class="view-all">View All →</a>
                </div>
                <div style="padding:0 24px 16px;">
                    <?php if ($recent && $recent->num_rows > 0): while ($doc = $recent->fetch_assoc()):
                        $days = floor((time()-strtotime($doc['upload_date']))/(60*60*24));
                    ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-bottom:1px solid var(--border);">
                        <div>
                            <div style="font-weight:600;margin-bottom:2px;"><?php echo htmlspecialchars($doc['title']); ?></div>
                            <div style="font-size:.8rem;color:var(--text-light);">
                                <?php echo htmlspecialchars($doc['full_name']); ?> &bull;
                                <?php echo htmlspecialchars($doc['school']??''); ?> – <?php echo htmlspecialchars($doc['dept_name']??''); ?> &bull;
                                <?php echo date('M j, Y', strtotime($doc['upload_date'])); ?>
                                <?php if ($days > 7): ?>
                                <span class="urgent-badge" style="margin-left:6px;"><?php echo $days; ?> days</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="action-btns">
                            <a href="view_form.php?id=<?php echo $doc['id']; ?>" class="btn-action btn-view">
                                <i class="fa-solid fa-eye"></i> View
                            </a>
                        </div>
                    </div>
                    <?php endwhile; else: ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-check-circle"></i>
                        <p>No pending documents. You're all caught up!</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- ── Email Sent Toast (fires after compose form submit) ── -->
<?php if (!empty($email_success)): ?>
<style>
.mut-toast{position:fixed;top:30px;left:50%;transform:translateX(-50%) translateY(-20px);
  background:#fff;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.18);
  padding:22px 36px 22px 24px;display:flex;align-items:flex-start;gap:16px;
  min-width:320px;max-width:480px;z-index:9999;
  animation:slideDown .4s cubic-bezier(.22,1,.36,1) forwards;}
@keyframes slideDown{from{opacity:0;transform:translateX(-50%) translateY(-30px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
.mut-toast-icon{font-size:2rem;line-height:1;flex-shrink:0;}
.mut-toast-body{}
.mut-toast-title{font-family:'Inter',sans-serif;font-weight:700;font-size:1rem;color:#1e293b;margin-bottom:4px;}
.mut-toast-msg{font-family:'Inter',sans-serif;font-size:.875rem;color:#475569;line-height:1.5;}
.mut-toast-close{position:absolute;top:10px;right:14px;background:none;border:none;font-size:1.1rem;color:#94a3b8;cursor:pointer;line-height:1;}
.mut-toast-bar{position:absolute;bottom:0;left:0;height:4px;background:#22c55e;border-radius:0 0 14px 14px;animation:shrink 4s linear forwards;}
@keyframes shrink{from{width:100%}to{width:0%}}
</style>
<div class="mut-toast" id="emailSentToast" style="position:fixed;">
  <div class="mut-toast-icon">📧</div>
  <div class="mut-toast-body">
    <div class="mut-toast-title">Email Sent Successfully</div>
    <div class="mut-toast-msg">Document sent to student's email — <?php echo htmlspecialchars($email_success); ?></div>
  </div>
  <button class="mut-toast-close" onclick="document.getElementById('emailSentToast').remove()">✕</button>
  <div class="mut-toast-bar"></div>
</div>
<script>setTimeout(()=>{const t=document.getElementById('emailSentToast');if(t)t.remove();},5000);</script>
<?php endif; ?>

<!-- ── Success Popup Toast ── -->
<?php if (!empty($_GET['success'])): ?>
<?php
$popup_msg = '';
$popup_icon = '✅';
if ($_GET['success'] === 'finalised') {
    $popup_msg = 'Document finalised and sent to the student\'s email successfully.';
} elseif ($_GET['success'] === 'forwarded_dvc') {
    $popup_msg = 'Special Exam application forwarded to the DVC ARSA office for final approval.';
} elseif ($_GET['success'] === 'recommended_to_dvc') {
    $popup_msg = 'Document recommended and sent to the DVC ARSA office for final approval.';
} elseif ($_GET['success'] === 'not_recommended') {
    $popup_icon = '❌';
    $popup_msg = 'Application not recommended and student has been notified with the reason provided.';
} elseif ($_GET['success'] === 'fees_approved') {
    $popup_msg = 'Request approved. Forwarded to Finance office.';
} elseif ($_GET['success'] === 'fees_rejected') {
    $popup_icon = '❌';
    $popup_msg = 'Request rejected. Student has been notified with the reason.';
}
?>
<?php if ($popup_msg): ?>
<style>
.mut-toast{position:fixed;top:30px;left:50%;transform:translateX(-50%) translateY(-20px);
  background:#fff;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.18);
  padding:22px 36px 22px 24px;display:flex;align-items:flex-start;gap:16px;
  min-width:320px;max-width:480px;z-index:9999;
  animation:slideDown .4s cubic-bezier(.22,1,.36,1) forwards;}
@keyframes slideDown{from{opacity:0;transform:translateX(-50%) translateY(-30px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
.mut-toast-icon{font-size:2rem;line-height:1;flex-shrink:0;}
.mut-toast-body{}
.mut-toast-title{font-family:'Inter',sans-serif;font-weight:700;font-size:1rem;color:#1e293b;margin-bottom:4px;}
.mut-toast-msg{font-family:'Inter',sans-serif;font-size:.875rem;color:#475569;line-height:1.5;}
.mut-toast-close{position:absolute;top:10px;right:14px;background:none;border:none;font-size:1.1rem;color:#94a3b8;cursor:pointer;line-height:1;}
.mut-toast-bar{position:absolute;bottom:0;left:0;height:4px;background:#22c55e;border-radius:0 0 14px 14px;animation:shrink 4s linear forwards;}
@keyframes shrink{from{width:100%}to{width:0%}}
</style>
<div class="mut-toast" id="mutToast">
  <div class="mut-toast-icon"><?php echo $popup_icon; ?></div>
  <div class="mut-toast-body">
    <div class="mut-toast-title">Action Confirmed</div>
    <div class="mut-toast-msg"><?php echo htmlspecialchars($popup_msg); ?></div>
  </div>
  <button class="mut-toast-close" onclick="document.getElementById('mutToast').remove()">✕</button>
  <div class="mut-toast-bar"></div>
</div>
<script>setTimeout(()=>{const t=document.getElementById('mutToast');if(t)t.remove();},4500);</script>
<?php endif; ?>
<?php endif; ?>

<script>
// Student live search for compose email
const allStudents = <?php
    $st = $conn->query("SELECT full_name, reg_number, email FROM users WHERE role='student' AND email != '' ORDER BY full_name");
    $arr = [];
    while ($r = $st->fetch_assoc()) $arr[] = $r;
    echo json_encode($arr);
?>;

function searchStudents(val) {
    const box = document.getElementById('studentSuggestions');
    if (val.length < 2) { box.style.display = 'none'; return; }
    const v = val.toLowerCase();
    const matches = allStudents.filter(s =>
        s.full_name.toLowerCase().includes(v) || s.reg_number.toLowerCase().includes(v)
    ).slice(0, 8);
    if (!matches.length) { box.style.display = 'none'; return; }
    box.innerHTML = matches.map(s => `
        <div class="student-suggestion-item" onclick="selectStudent('${s.full_name.replace(/'/g,"\\'")}','${s.reg_number}','${s.email}')">
            <div class="student-suggestion-name">${s.full_name}</div>
            <div class="student-suggestion-meta">${s.reg_number} &bull; ${s.email}</div>
        </div>`).join('');
    box.style.display = 'block';
}

function selectStudent(name, reg, email) {
    document.getElementById('studentSearch').value = name + ' — ' + reg;
    document.getElementById('toEmail').value = email;
    document.getElementById('hiddenToName').value = name;
    document.getElementById('studentSuggestions').style.display = 'none';
}

document.addEventListener('click', e => {
    if (!e.target.closest('.compose-search-wrap'))
        document.getElementById('studentSuggestions').style.display = 'none';
});
</script>
</body>
</html>