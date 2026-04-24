<?php
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php"); exit();
}

$is_cod_view   = isset($_SESSION['current_admin_view']) && $_SESSION['current_admin_view'] === 'cod';
$is_actual_cod = ($_SESSION['role'] === 'admin' && $_SESSION['admin_role'] === 'cod');

if (!$is_cod_view && !$is_actual_cod && $_SESSION['role'] !== 'super_admin') {
    header("Location: login.php"); exit();
}

$dept_id = $_GET['dept'] ?? $_SESSION['selected_department'] ?? null;
if (!$dept_id && $is_actual_cod) {
    $s = $conn->prepare("SELECT department_id FROM users WHERE reg_number = ?");
    $s->bind_param("s", $_SESSION['reg_number']);
    $s->execute();
    $dept_id = $s->get_result()->fetch_assoc()['department_id'];
    $_SESSION['selected_department'] = $dept_id;
}

// Department info
$dept_info = $conn->query("SELECT * FROM departments WHERE id = $dept_id")->fetch_assoc();

// Current semester bounds (simple: current year, current semester)
$sem_start = date('Y') . '-01-01';

// Stats
$stats = $conn->query("SELECT 
    COUNT(*) as total_pending,
    SUM(CASE WHEN DATE(upload_date) = CURDATE() THEN 1 ELSE 0 END) as today,
    SUM(CASE WHEN module_type = 'Resit' THEN 1 ELSE 0 END) as resit_docs,
    SUM(CASE WHEN module_type = 'Retake' THEN 1 ELSE 0 END) as retake_docs,
    SUM(CASE WHEN module_type = 'Special_Exam' THEN 1 ELSE 0 END) as special_docs
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    WHERE d.status = 'Pending_COD' AND u.department_id = $dept_id")->fetch_assoc();

// Semester exam applications summary (all statuses, this semester)
$exam_summary = $conn->query("SELECT 
    u.full_name, u.reg_number as student_reg,
    d.module_type, d.status, d.upload_date,
    rrf.exam_month, rrf.exam_year,
    GROUP_CONCAT(fu.unit_code SEPARATOR ', ') as unit_codes,
    GROUP_CONCAT(fu.unit_title SEPARATOR ' | ') as unit_titles
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    LEFT JOIN resit_retake_forms rrf ON rrf.document_id = d.id
    LEFT JOIN form_units fu ON fu.form_id = rrf.id
    WHERE u.department_id = $dept_id
      AND d.module_type IN ('Resit','Retake','Special_Exam')
      AND d.upload_date >= '$sem_start'
    GROUP BY d.id
    ORDER BY d.module_type, u.full_name");

// Recent pending docs
$recent = $conn->query("SELECT d.*, u.full_name, u.reg_number 
    FROM documents d JOIN users u ON d.reg_number = u.reg_number 
    WHERE d.status = 'Pending_COD' AND u.department_id = $dept_id
    ORDER BY d.upload_date DESC LIMIT 5");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>COD Dashboard | MUT DMS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--primary:#3b82f6;--bg:#f8fafc;--card:#fff;--text:#1e293b;--text-light:#64748b;--border:#e2e8f0;--shadow:0 1px 3px rgba(0,0,0,.1);--shadow-lg:0 10px 15px -3px rgba(0,0,0,.1);--success:#22c55e;--danger:#ef4444;--warning:#f59e0b;--purple:#7c3aed;--orange:#f97316}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text)}
.layout{display:flex;min-height:100vh}
/* Sidebar */
.sidebar{width:280px;background:#0f172a;position:fixed;height:100vh;overflow-y:auto;z-index:100;display:flex;flex-direction:column}
.sidebar-header{padding:24px;border-bottom:1px solid rgba(255,255,255,.1)}
.logo{display:flex;align-items:center;gap:12px}
.logo-icon{width:48px;height:48px;background:linear-gradient(135deg,var(--primary) 0%,#2563eb 100%);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.5rem}
.logo-text{color:white}.logo-text h3{font-size:1.1rem;font-weight:700}.logo-text span{font-size:.75rem;color:rgba(255,255,255,.6)}
.current-view-box{margin:16px;padding:16px;background:linear-gradient(135deg,rgba(59,130,246,.2) 0%,rgba(59,130,246,.1) 100%);border:1px solid rgba(59,130,246,.3);border-radius:12px;color:white}
.current-view-box .label{font-size:.75rem;color:rgba(255,255,255,.6);margin-bottom:4px;text-transform:uppercase}
.current-view-box .value{font-size:1.1rem;font-weight:700;color:var(--primary);display:flex;align-items:center;gap:8px}
.current-view-box .dept{font-size:.85rem;color:rgba(255,255,255,.8);margin-top:6px;padding-top:6px;border-top:1px solid rgba(255,255,255,.1)}
.nav-section{padding:20px 0;flex:1}
.nav-title{padding:8px 24px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4)}
.nav-item{display:flex;align-items:center;gap:12px;padding:12px 24px;color:rgba(255,255,255,.7);text-decoration:none;transition:all .2s;border-left:3px solid transparent}
.nav-item:hover,.nav-item.active{background:rgba(255,255,255,.05);color:white;border-left-color:var(--primary)}
.nav-item i{width:20px;text-align:center}
.btn-back{display:flex;align-items:center;justify-content:center;gap:8px;margin:0 16px 16px;padding:10px;background:rgba(255,255,255,.1);border:1px dashed rgba(255,255,255,.3);border-radius:8px;color:rgba(255,255,255,.7);font-size:.85rem;text-decoration:none;transition:all .2s}
.btn-back:hover{background:rgba(255,255,255,.2);color:white}
.sidebar-footer{padding:16px 24px;border-top:1px solid rgba(255,255,255,.1);margin-top:auto}
.admin-info{display:flex;align-items:center;gap:12px;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid rgba(255,255,255,.1)}
.admin-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--primary) 0%,#2563eb 100%);display:flex;align-items:center;justify-content:center;color:white;font-weight:700}
.admin-details .name{color:white;font-weight:600;font-size:.9rem}
.admin-details .role{color:rgba(255,255,255,.5);font-size:.75rem;text-transform:uppercase}
.btn-logout{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;background:rgba(239,68,68,.2);color:#ef4444;border:none;border-radius:8px;font-size:.875rem;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-logout:hover{background:rgba(239,68,68,.3)}
/* Main */
.main-content{flex:1;margin-left:280px;min-height:100vh}
.header{background:var(--card);padding:20px 32px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.page-title h1{font-size:1.75rem;font-weight:800}
.dept-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#dbeafe;color:#1e40af;border-radius:20px;font-size:.875rem;font-weight:600;margin-top:8px}
.content{padding:32px}
/* Stats */
.stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:20px;margin-bottom:28px}
.stat-card{background:var(--card);border-radius:16px;padding:20px;box-shadow:var(--shadow);border:1px solid var(--border);text-align:center}
.stat-icon{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:1.25rem}
.stat-icon.blue{background:#dbeafe;color:#2563eb}.stat-icon.green{background:#d1fae5;color:#059669}
.stat-icon.purple{background:#ede9fe;color:#7c3aed}.stat-icon.orange{background:#fef3c7;color:#d97706}
.stat-icon.teal{background:#ccfbf1;color:#0d9488}
.stat-value{font-size:2.2rem;font-weight:800;color:var(--text);margin-bottom:4px;line-height:1}
.stat-label{font-size:.8rem;color:var(--text-light);font-weight:500}
/* Section */
.section-card{background:var(--card);border-radius:16px;box-shadow:var(--shadow);border:1px solid var(--border);margin-bottom:28px;overflow:hidden}
.section-hdr{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid var(--border)}
.section-title{font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.section-title i{color:var(--primary)}
.view-all{font-size:.875rem;color:var(--primary);text-decoration:none;font-weight:600}
/* Exam summary table */
.exam-table{width:100%;border-collapse:collapse}
.exam-table th,.exam-table td{padding:12px 16px;text-align:left;border-bottom:1px solid var(--border);font-size:.875rem}
.exam-table th{background:var(--bg);font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:var(--text-light)}
.exam-table tr:last-child td{border-bottom:none}
.exam-table tbody tr:hover{background:rgba(241,245,249,.5)}
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:.75rem;font-weight:600}
.badge-resit{background:#dbeafe;color:#1d4ed8}.badge-retake{background:#ede9fe;color:#6d28d9}
.badge-special{background:#ccfbf1;color:#0f766e}.badge-pending{background:#fef3c7;color:#92400e}
.badge-approved{background:#d1fae5;color:#065f46}.badge-rejected{background:#fee2e2;color:#991b1b}
/* Action buttons */
.action-btns{display:flex;gap:8px;flex-wrap:nowrap}
.btn-action{padding:7px 13px;border:none;border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;text-decoration:none;white-space:nowrap}
.btn-view{background:var(--bg);color:var(--text);border:1px solid var(--border)}.btn-view:hover{background:var(--border)}
.btn-forward{background:var(--success);color:white}.btn-forward:hover{background:#16a34a}
/* Print button */
.btn-print{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:var(--bg);border:1px solid var(--border);border-radius:8px;font-size:.875rem;font-weight:600;cursor:pointer;color:var(--text);text-decoration:none;transition:all .2s}
.btn-print:hover{background:var(--border)}
/* Empty */
.empty-state{text-align:center;padding:48px 24px;color:var(--text-light)}
.empty-state i{font-size:3rem;margin-bottom:12px;display:block;opacity:.4}
/* Quick actions */
.quick-actions{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;margin-bottom:28px}
.action-card{background:var(--card);border-radius:16px;padding:22px;box-shadow:var(--shadow);border:1px solid var(--border);display:flex;align-items:center;gap:16px;text-decoration:none;color:inherit;transition:all .2s}
.action-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg);border-color:var(--primary)}
.action-icon{width:56px;height:56px;border-radius:12px;background:rgba(59,130,246,.1);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:1.4rem}
.action-text h3{font-size:1rem;font-weight:700;margin-bottom:4px}.action-text p{font-size:.8rem;color:var(--text-light)}
@media print{.sidebar,.btn-print,.action-btns,.quick-actions,.btn-back{display:none!important}.main-content{margin-left:0!important}.header{position:static!important}}
</style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon"><i class="fa-solid fa-user-tie"></i></div>
                <div class="logo-text"><h3>COD Portal</h3><span>MUT Documents</span></div>
            </div>
        </div>

        <div class="current-view-box">
            <div class="label">Currently Viewing</div>
            <div class="value"><i class="fa-solid fa-eye"></i> COD View</div>
            <?php if ($dept_info): ?>
            <div class="dept"><i class="fa-solid fa-building"></i> <?php echo htmlspecialchars($dept_info['dept_name']); ?></div>
            <?php endif; ?>
        </div>

        <?php if (isset($_SESSION['current_admin_view'])): ?>
        <a href="admin_dashboard.php?clear_view=1" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back to System Admin
        </a>
        <?php endif; ?>

        <nav class="nav-section">
            <div class="nav-title">Menu</div>
            <a href="cod_dashboard.php?dept=<?php echo $dept_id; ?>" class="nav-item active">
                <i class="fa-solid fa-grid-2"></i><span>Dashboard</span>
            </a>
            <a href="cod_documents.php?dept=<?php echo $dept_id; ?>" class="nav-item">
                <i class="fa-solid fa-folder-open"></i><span>Students Applications</span>
            </a>
            <a href="cod_reports.php?dept=<?php echo $dept_id; ?>" class="nav-item">
                <i class="fa-solid fa-chart-bar"></i><span>Reports</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="admin-info">
                <div class="admin-avatar"><?php echo strtoupper(substr($_SESSION['full_name']??'C',0,1)); ?></div>
                <div class="admin-details">
                    <div class="name"><?php echo htmlspecialchars($_SESSION['full_name']??'COD'); ?></div>
                    <div class="role">Head of Department</div>
                </div>
            </div>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </aside>

    <main class="main-content">
        <header class="header">
            <div class="page-title">
                <h1>COD Dashboard</h1>
                <?php if ($dept_info): ?>
                <div class="dept-badge">
                    <i class="fa-solid fa-building"></i>
                    <?php echo htmlspecialchars($dept_info['dept_name']); ?>
                    <span style="color:#64748b;margin-left:4px;">| <?php echo htmlspecialchars($dept_info['school']); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <?php
            // Resolve COD identity: from password login session, or actual COD user, or session name
            $cod_display_name = '';
            $cod_display_reg  = '';
            if (!empty($_SESSION['cod_logged_in_name'])) {
                $cod_display_name = $_SESSION['cod_logged_in_name'];
                $cod_display_reg  = $_SESSION['cod_logged_in_reg'];
            } elseif ($is_actual_cod) {
                $cod_display_name = $_SESSION['full_name'] ?? '';
                $cod_display_reg  = $_SESSION['reg_number'] ?? '';
            }
            if ($cod_display_name): ?>
            <div style="display:flex;align-items:center;gap:14px;background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:14px;padding:10px 20px;">
                <div style="width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,var(--primary) 0%,#2563eb 100%);display:flex;align-items:center;justify-content:center;color:white;font-size:1.25rem;flex-shrink:0;">
                    <i class="fa-solid fa-user-tie"></i>
                </div>
                <div>
                    <div style="font-weight:700;font-size:.95rem;color:#1e293b;"><?php echo htmlspecialchars($cod_display_name); ?></div>
                    <div style="font-size:.78rem;color:#64748b;font-family:monospace;margin-top:1px;"><?php echo htmlspecialchars($cod_display_reg); ?> &nbsp;·&nbsp; Head of Department</div>
                </div>
            </div>
            <?php endif; ?>
        </header>

        <div class="content">
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fa-solid fa-inbox"></i></div>
                    <div class="stat-value"><?php echo $stats['total_pending']; ?></div>
                    <div class="stat-label">Pending Review</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fa-solid fa-calendar-day"></i></div>
                    <div class="stat-value"><?php echo $stats['today']; ?></div>
                    <div class="stat-label">Today's Submissions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="fa-solid fa-file-pen"></i></div>
                    <div class="stat-value"><?php echo $stats['resit_docs']; ?></div>
                    <div class="stat-label">Resit (Pending)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="fa-solid fa-rotate"></i></div>
                    <div class="stat-value"><?php echo $stats['retake_docs']; ?></div>
                    <div class="stat-label">Retake (Pending)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon teal"><i class="fa-solid fa-star"></i></div>
                    <div class="stat-value"><?php echo $stats['special_docs']; ?></div>
                    <div class="stat-label">Special (Pending)</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="cod_documents.php?dept=<?php echo $dept_id; ?>" class="action-card">
                    <div class="action-icon"><i class="fa-solid fa-folder-open"></i></div>
                    <div class="action-text"><h3>Review Documents</h3><p>View and act on pending applications</p></div>
                </a>
                <a href="cod_reports.php?dept=<?php echo $dept_id; ?>" class="action-card">
                    <div class="action-icon"><i class="fa-solid fa-chart-pie"></i></div>
                    <div class="action-text"><h3>Department Reports</h3><p>Analytics for your department</p></div>
                </a>
            </div>

            <!-- ── Semester Exam Applications Summary ── -->
            <div class="section-card">
                <div class="section-hdr">
                    <h2 class="section-title"><i class="fa-solid fa-table-list"></i> This Semester's Exam Applications</h2>
            
                </div>

                <?php if ($exam_summary && $exam_summary->num_rows > 0): ?>
                <div style="overflow-x:auto;">
                <table class="exam-table" id="semesterTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Name</th>
                            <th>Reg. Number</th>
                            <th>Type</th>
                            <th>Period</th>
                            <th>Units</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $n = 1; while ($row = $exam_summary->fetch_assoc()):
                            $type_badge = match($row['module_type']) {
                                'Resit'       => '<span class="badge badge-resit">Resit</span>',
                                'Retake'      => '<span class="badge badge-retake">Retake</span>',
                                'Special_Exam'=> '<span class="badge badge-special">Special</span>',
                                default       => '<span class="badge">'.$row['module_type'].'</span>'
                            };
                            $status_badge = match(true) {
                                str_contains($row['status'],'Pending') => '<span class="badge badge-pending">'.str_replace('_',' ',$row['status']).'</span>',
                                $row['status'] === 'Approved'          => '<span class="badge badge-approved">Approved</span>',
                                $row['status'] === 'Rejected'          => '<span class="badge badge-rejected">Rejected</span>',
                                default                                => '<span class="badge">'.$row['status'].'</span>'
                            };
                        ?>
                        <tr>
                            <td><?php echo $n++; ?></td>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($row['full_name']); ?></td>
                            <td style="font-family:monospace;font-size:.8rem;"><?php echo htmlspecialchars($row['student_reg']); ?></td>
                            <td><?php echo $type_badge; ?></td>
                            <td><?php echo htmlspecialchars($row['exam_month'].' '.($row['exam_year']??'')); ?></td>
                            <td style="font-size:.8rem;max-width:200px;">
                                <?php if ($row['unit_codes']): ?>
                                    <span title="<?php echo htmlspecialchars($row['unit_titles']??''); ?>">
                                        <?php echo htmlspecialchars($row['unit_codes']); ?>
                                    </span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><?php echo $status_badge; ?></td>
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


        </div><!-- /content -->
    </main>
</div>

<!-- ── Success Popup Toast ── -->
<?php if (!empty($_GET['success'])): ?>
<?php
$popup_msg = '';
$popup_icon = '✅';
if ($_GET['success'] === 'recommended') {
    $popup_msg = 'Document recommended and sent to the Dean\'s office successfully.';
} elseif ($_GET['success'] === 'not_recommended') {
    $popup_icon = '❌';
    $popup_msg = 'Application marked as not recommended and student has been notified.';
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

</body>
</html>