<?php
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php"); exit();
}

$is_dean_view   = isset($_SESSION['current_admin_view']) && $_SESSION['current_admin_view'] === 'dean';
$is_actual_dean = ($_SESSION['role'] === 'admin' && $_SESSION['admin_role'] === 'dean');

if (!$is_dean_view && !$is_actual_dean && $_SESSION['role'] !== 'super_admin') {
    header("Location: login.php"); exit();
}

// ── Resolve Dean's display identity (password-login identity takes priority) ──
$dean_display_name = $_SESSION['dean_logged_in_name'] ?? $_SESSION['full_name'] ?? 'Dean';
$dean_display_reg  = $_SESSION['dean_logged_in_reg']  ?? $_SESSION['reg_number'] ?? '';

// ── Resolve school — NEVER trust empty GET, always prefer session ──
// Priority: 1) non-empty GET  2) session  3) look up from dean's own user record
$school = null;
if (!empty($_GET['school'])) {
    $school = $_GET['school'];
    $_SESSION['selected_school'] = $school; // update session whenever we get a valid URL value
} elseif (!empty($_SESSION['selected_school'])) {
    $school = $_SESSION['selected_school'];
} elseif (!empty($dean_display_reg)) {
    // Look up school from the logged-in dean's department
    $s = $conn->prepare("SELECT school FROM users WHERE reg_number = ?");
    $s->bind_param("s", $dean_display_reg);
    $s->execute();
    $school = $s->get_result()->fetch_assoc()['school'] ?? null;
    if ($school) $_SESSION['selected_school'] = $school;
} elseif ($is_actual_dean) {
    $s = $conn->prepare("SELECT school FROM users WHERE reg_number = ?");
    $s->bind_param("s", $_SESSION['reg_number']);
    $s->execute();
    $school = $s->get_result()->fetch_assoc()['school'] ?? null;
    if ($school) $_SESSION['selected_school'] = $school;
}

// Always use the safe encoded version for all links
$school_url = urlencode($school ?? '');

// If school is still empty at this point, try one final direct DB lookup using dean_logged_in_reg
if (empty($school) && !empty($_SESSION['dean_logged_in_reg'])) {
    $s = $conn->prepare("SELECT school FROM users WHERE reg_number = ?");
    $s->bind_param("s", $_SESSION['dean_logged_in_reg']);
    $s->execute();
    $found = $s->get_result()->fetch_assoc()['school'] ?? null;
    if ($found) {
        $school = $found;
        $school_url = urlencode($school);
        $_SESSION['selected_school'] = $school;
    }
}

// Departments in school
$depts_stmt = $conn->prepare("SELECT id, dept_name FROM departments WHERE school = ? ORDER BY dept_name");
$depts_stmt->bind_param("s", $school);
$depts_stmt->execute();
$depts_result = $depts_stmt->get_result();
$dept_ids = []; $dept_names = [];
while ($d = $depts_result->fetch_assoc()) {
    $dept_ids[]         = $d['id'];
    $dept_names[$d['id']] = $d['dept_name'];
}
$depts_result->data_seek(0);
$dept_list = implode(',', $dept_ids ?: [0]);

// Stats
$stats = $conn->query("SELECT 
    COUNT(*) as total_pending,
    SUM(CASE WHEN DATE(upload_date) = CURDATE() THEN 1 ELSE 0 END) as today,
    SUM(CASE WHEN module_type = 'Resit' THEN 1 ELSE 0 END) as resit_docs,
    SUM(CASE WHEN module_type = 'Retake' THEN 1 ELSE 0 END) as retake_docs,
    SUM(CASE WHEN module_type = 'Special_Exam' THEN 1 ELSE 0 END) as special_docs
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    WHERE d.status = 'Pending_Dean' AND u.department_id IN ($dept_list)")->fetch_assoc();

$sem_start = date('Y') . '-01-01';

// Semester exam applications summary (all departments under this school)
$exam_summary = $conn->query("SELECT 
    u.full_name, u.reg_number as student_reg, dept.dept_name,
    d.id as doc_id, d.module_type, d.status, d.upload_date,
    rrf.exam_month, rrf.exam_year,
    GROUP_CONCAT(fu.unit_code SEPARATOR ', ') as unit_codes
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    LEFT JOIN departments dept ON u.department_id = dept.id
    LEFT JOIN resit_retake_forms rrf ON rrf.document_id = d.id
    LEFT JOIN form_units fu ON fu.form_id = rrf.id
    WHERE u.department_id IN ($dept_list)
      AND d.module_type IN ('Resit','Retake','Special_Exam')
      AND d.upload_date >= '$sem_start'
    GROUP BY d.id
    ORDER BY dept.dept_name, d.module_type, u.full_name");

// Recent pending docs
$recent = $conn->query("SELECT d.*, u.full_name, u.reg_number, dept.dept_name 
    FROM documents d JOIN users u ON d.reg_number = u.reg_number 
    LEFT JOIN departments dept ON u.department_id = dept.id
    WHERE d.status = 'Pending_Dean' AND u.department_id IN ($dept_list)
    ORDER BY d.upload_date DESC LIMIT 5");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dean Dashboard | MUT DMS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--primary:#7c3aed;--bg:#f8fafc;--card:#fff;--text:#1e293b;--text-light:#64748b;--border:#e2e8f0;--shadow:0 1px 3px rgba(0,0,0,.1);--shadow-lg:0 10px 15px -3px rgba(0,0,0,.1);--success:#22c55e;--danger:#ef4444;--warning:#f59e0b}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text)}
.layout{display:flex;min-height:100vh}
.sidebar{width:280px;background:#0f172a;position:fixed;height:100vh;overflow-y:auto;z-index:100;display:flex;flex-direction:column}
.sidebar-header{padding:24px;border-bottom:1px solid rgba(255,255,255,.1)}
.logo{display:flex;align-items:center;gap:12px}
.logo-icon{width:48px;height:48px;background:linear-gradient(135deg,var(--primary) 0%,#5b21b6 100%);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.5rem}
.logo-text{color:white}.logo-text h3{font-size:1.1rem;font-weight:700}.logo-text span{font-size:.75rem;color:rgba(255,255,255,.6)}
.current-view-box{margin:16px;padding:16px;background:linear-gradient(135deg,rgba(124,58,237,.2) 0%,rgba(124,58,237,.1) 100%);border:1px solid rgba(124,58,237,.3);border-radius:12px;color:white}
.current-view-box .label{font-size:.75rem;color:rgba(255,255,255,.6);margin-bottom:4px;text-transform:uppercase}
.current-view-box .value{font-size:1.1rem;font-weight:700;color:var(--primary);display:flex;align-items:center;gap:8px}
.current-view-box .school{font-size:.85rem;color:rgba(255,255,255,.8);margin-top:6px;padding-top:6px;border-top:1px solid rgba(255,255,255,.1)}
.nav-section{padding:20px 0;flex:1}
.nav-title{padding:8px 24px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4)}
.nav-item{display:flex;align-items:center;gap:12px;padding:12px 24px;color:rgba(255,255,255,.7);text-decoration:none;transition:all .2s;border-left:3px solid transparent}
.nav-item:hover,.nav-item.active{background:rgba(255,255,255,.05);color:white;border-left-color:var(--primary)}
.nav-item i{width:20px;text-align:center}
.btn-back{display:flex;align-items:center;justify-content:center;gap:8px;margin:0 16px 16px;padding:10px;background:rgba(255,255,255,.1);border:1px dashed rgba(255,255,255,.3);border-radius:8px;color:rgba(255,255,255,.7);font-size:.85rem;text-decoration:none;transition:all .2s}
.btn-back:hover{background:rgba(255,255,255,.2);color:white}
.sidebar-footer{padding:16px 24px;border-top:1px solid rgba(255,255,255,.1);margin-top:auto}
.admin-info{display:flex;align-items:center;gap:12px;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid rgba(255,255,255,.1)}
.admin-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--primary) 0%,#5b21b6 100%);display:flex;align-items:center;justify-content:center;color:white;font-weight:700}
.admin-details .name{color:white;font-weight:600;font-size:.9rem}
.admin-details .role{color:rgba(255,255,255,.5);font-size:.75rem;text-transform:uppercase}
.btn-logout{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;background:rgba(239,68,68,.2);color:#ef4444;border:none;border-radius:8px;font-size:.875rem;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-logout:hover{background:rgba(239,68,68,.3)}
.main-content{flex:1;margin-left:280px;min-height:100vh}
.header{background:var(--card);padding:20px 32px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.page-title h1{font-size:1.75rem;font-weight:800}
.school-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#ede9fe;color:#5b21b6;border-radius:20px;font-size:.875rem;font-weight:600;margin-top:8px}
.content{padding:32px}
.stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:20px;margin-bottom:28px}
.stat-card{background:var(--card);border-radius:16px;padding:20px;box-shadow:var(--shadow);border:1px solid var(--border);text-align:center}
.stat-icon{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:1.25rem}
.stat-icon.purple{background:#ede9fe;color:#7c3aed}.stat-icon.green{background:#d1fae5;color:#059669}
.stat-icon.blue{background:#dbeafe;color:#2563eb}.stat-icon.orange{background:#fef3c7;color:#d97706}
.stat-icon.teal{background:#ccfbf1;color:#0d9488}
.stat-value{font-size:2.2rem;font-weight:800;color:var(--text);margin-bottom:4px;line-height:1}
.stat-label{font-size:.8rem;color:var(--text-light);font-weight:500}
.section-card{background:var(--card);border-radius:16px;box-shadow:var(--shadow);border:1px solid var(--border);margin-bottom:28px;overflow:hidden}
.section-hdr{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid var(--border)}
.section-title{font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.section-title i{color:var(--primary)}
.view-all{font-size:.875rem;color:var(--primary);text-decoration:none;font-weight:600}
.exam-table{width:100%;border-collapse:collapse}
.exam-table th,.exam-table td{padding:12px 16px;text-align:left;border-bottom:1px solid var(--border);font-size:.875rem}
.exam-table th{background:var(--bg);font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:var(--text-light)}
.exam-table tr:last-child td{border-bottom:none}
.exam-table tbody tr:hover{background:rgba(241,245,249,.5)}
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:.75rem;font-weight:600}
.badge-resit{background:#dbeafe;color:#1d4ed8}.badge-retake{background:#ede9fe;color:#6d28d9}
.badge-special{background:#ccfbf1;color:#0f766e}.badge-pending{background:#fef3c7;color:#92400e}
.badge-approved{background:#d1fae5;color:#065f46}.badge-rejected{background:#fee2e2;color:#991b1b}
.action-btns{display:flex;gap:6px}
.btn-action{padding:6px 12px;border:none;border-radius:7px;font-size:.8rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;white-space:nowrap;transition:all .2s}
.btn-view{background:var(--bg);color:var(--text);border:1px solid var(--border)}.btn-view:hover{background:var(--border)}
.btn-print{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:var(--bg);border:1px solid var(--border);border-radius:8px;font-size:.875rem;font-weight:600;cursor:pointer;color:var(--text);text-decoration:none;transition:all .2s}
.btn-print:hover{background:var(--border)}
.dept-tags{display:flex;flex-wrap:wrap;gap:8px;padding:16px 24px}
.dept-tag{padding:6px 14px;background:#ede9fe;color:#5b21b6;border-radius:20px;font-size:.8rem;font-weight:600}
.quick-actions{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;margin-bottom:28px}
.action-card{background:var(--card);border-radius:16px;padding:22px;box-shadow:var(--shadow);border:1px solid var(--border);display:flex;align-items:center;gap:16px;text-decoration:none;color:inherit;transition:all .2s}
.action-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg);border-color:var(--primary)}
.action-icon{width:56px;height:56px;border-radius:12px;background:rgba(124,58,237,.1);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:1.4rem}
.action-text h3{font-size:1rem;font-weight:700;margin-bottom:4px}.action-text p{font-size:.8rem;color:var(--text-light)}
.empty-state{text-align:center;padding:48px 24px;color:var(--text-light)}
.empty-state i{font-size:3rem;margin-bottom:12px;display:block;opacity:.4}
@media print{.sidebar,.btn-print,.action-btns,.quick-actions,.btn-back{display:none!important}.main-content{margin-left:0!important}.header{position:static!important}}
</style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon"><i class="fa-solid fa-user-graduate"></i></div>
                <div class="logo-text"><h3>Dean Portal</h3><span>MUT Documents</span></div>
            </div>
        </div>

        <div class="current-view-box">
            <div class="label">Currently Viewing</div>
            <div class="value"><i class="fa-solid fa-eye"></i> Dean View</div>
            <?php if ($school): ?>
            <div class="school"><i class="fa-solid fa-university"></i> <?php echo htmlspecialchars($school); ?></div>
            <?php endif; ?>
        </div>

        <?php if (isset($_SESSION['current_admin_view'])): ?>
        <a href="admin_dashboard.php?clear_view=1" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back to System Admin
        </a>
        <?php endif; ?>

        <nav class="nav-section">
            <div class="nav-title">Menu</div>
            <a href="dean_dashboard.php?school=<?php echo $school_url; ?>" class="nav-item active">
                <i class="fa-solid fa-grid-2"></i><span>Dashboard</span>
            </a>
            <a href="dean_documents.php?school=<?php echo $school_url; ?>" class="nav-item">
                <i class="fa-solid fa-folder-open"></i><span>Students Applications</span>
            </a>
            <a href="dean_reports.php?school=<?php echo $school_url; ?>" class="nav-item">
                <i class="fa-solid fa-chart-bar"></i><span>Reports</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="admin-info">
                <div class="admin-avatar"><?php echo strtoupper(substr($dean_display_name, 0, 1)); ?></div>
                <div class="admin-details">
                    <div class="name"><?php echo htmlspecialchars($dean_display_name); ?></div>
                    <div class="role"><?php echo htmlspecialchars($dean_display_reg); ?> &bull; School Dean</div>
                </div>
            </div>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </aside>

    <main class="main-content">
        <header class="header">
            <div class="page-title">
                <h1>Dean Dashboard</h1>
                <?php if ($school): ?>
                <div class="school-badge">
                    <i class="fa-solid fa-university"></i>
                    <?php echo htmlspecialchars($school); ?>
                </div>
                <?php endif; ?>
            </div>
            <!-- Dean identity card — mirrors COD header card -->
            <div style="display:flex;align-items:center;gap:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:10px 18px;">
                <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#5b21b6);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:1rem;">
                    <?php echo strtoupper(substr($dean_display_name, 0, 1)); ?>
                </div>
                <div>
                    <div style="font-weight:700;font-size:.95rem;color:#1e293b;"><?php echo htmlspecialchars($dean_display_name); ?></div>
                    <div style="font-size:.75rem;color:#64748b;"><?php echo htmlspecialchars($dean_display_reg); ?> &nbsp;&bull;&nbsp; School Dean</div>
                </div>
            </div>
        </header>

        <div class="content">
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="fa-solid fa-inbox"></i></div>
                    <div class="stat-value"><?php echo $stats['total_pending']??0; ?></div>
                    <div class="stat-label">Pending Review</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fa-solid fa-calendar-day"></i></div>
                    <div class="stat-value"><?php echo $stats['today']??0; ?></div>
                    <div class="stat-label">Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fa-solid fa-file-pen"></i></div>
                    <div class="stat-value"><?php echo $stats['resit_docs']??0; ?></div>
                    <div class="stat-label">Resit (Pending)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="fa-solid fa-rotate"></i></div>
                    <div class="stat-value"><?php echo $stats['retake_docs']??0; ?></div>
                    <div class="stat-label">Retake (Pending)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon teal"><i class="fa-solid fa-star"></i></div>
                    <div class="stat-value"><?php echo $stats['special_docs']??0; ?></div>
                    <div class="stat-label">Special (Pending)</div>
                </div>
            </div>

            <!-- Departments under this school -->
            <div class="section-card">
                <div class="section-hdr">
                    <h2 class="section-title"><i class="fa-solid fa-building"></i> Departments Under Your School</h2>
                </div>
                <div class="dept-tags">
                    <?php while ($dept = $depts_result->fetch_assoc()): ?>
                    <span class="dept-tag">
                        <i class="fa-solid fa-building" style="margin-right:4px;"></i>
                        <?php echo htmlspecialchars($dept['dept_name']); ?>
                    </span>
                    <?php endwhile; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="dean_documents.php?school=<?php echo $school_url; ?>" class="action-card">
                    <div class="action-icon"><i class="fa-solid fa-folder-open"></i></div>
                    <div class="action-text"><h3>Review Documents</h3><p>View and act on pending applications</p></div>
                </a>
                <a href="dean_reports.php?school=<?php echo $school_url; ?>" class="action-card">
                    <div class="action-icon"><i class="fa-solid fa-chart-pie"></i></div>
                    <div class="action-text"><h3>School Reports</h3><p>Analytics for all departments</p></div>
                </a>
            </div>

            <!-- ── Semester Exam Applications Summary ── -->
            <div class="section-card">
                <div class="section-hdr">
                    <h2 class="section-title"><i class="fa-solid fa-table-list"></i> This Semester's Exam Applications (All Departments)</h2>
                    
                </div>

                <?php if ($exam_summary && $exam_summary->num_rows > 0): ?>
                <div style="overflow-x:auto;">
                <table class="exam-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Name</th>
                            <th>Reg. No.</th>
                            <th>Department</th>
                            <th>Type</th>
                            <th>Period</th>
                            <th>Units</th>
                            <th>Status</th>
                            <th class="no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $n = 1; while ($row = $exam_summary->fetch_assoc()):
                            $type_badge = match($row['module_type']) {
                                'Resit'        => '<span class="badge badge-resit">Resit</span>',
                                'Retake'       => '<span class="badge badge-retake">Retake</span>',
                                'Special_Exam' => '<span class="badge badge-special">Special</span>',
                                default        => '<span class="badge">'.$row['module_type'].'</span>'
                            };
                            $status_badge = match(true) {
                                str_contains($row['status'],'Pending') => '<span class="badge badge-pending">'.str_replace('_',' ',$row['status']).'</span>',
                                $row['status']==='Approved'            => '<span class="badge badge-approved">Approved</span>',
                                $row['status']==='Rejected'            => '<span class="badge badge-rejected">Rejected</span>',
                                default                                => '<span class="badge">'.$row['status'].'</span>'
                            };
                        ?>
                        <tr>
                            <td><?php echo $n++; ?></td>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($row['full_name']); ?></td>
                            <td style="font-family:monospace;font-size:.8rem;"><?php echo htmlspecialchars($row['student_reg']); ?></td>
                            <td><?php echo htmlspecialchars($row['dept_name']??'N/A'); ?></td>
                            <td><?php echo $type_badge; ?></td>
                            <td><?php echo htmlspecialchars($row['exam_month'].' '.($row['exam_year']??'')); ?></td>
                            <td style="font-size:.8rem;"><?php echo htmlspecialchars($row['unit_codes']??'—'); ?></td>
                            <td><?php echo $status_badge; ?></td>
                            <td class="no-print">
                                <?php if ($row['status'] === 'Pending_Dean'): ?>
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

            <!-- Recent Pending -->
            <div class="section-card">
                <div class="section-hdr">
                    <h2 class="section-title"><i class="fa-solid fa-clock-rotate-left"></i> Pending Documents</h2>
                    <a href="dean_documents.php?school=<?php echo $school_url; ?>" class="view-all">View All →</a>
                </div>
                <div style="padding:0 24px 16px;">
                    <?php if ($recent && $recent->num_rows > 0): while ($doc = $recent->fetch_assoc()): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-bottom:1px solid var(--border);">
                        <div>
                            <div style="font-weight:600;margin-bottom:2px;"><?php echo htmlspecialchars($doc['title']); ?></div>
                            <div style="font-size:.8rem;color:var(--text-light);">
                                <?php echo htmlspecialchars($doc['full_name']); ?> &bull;
                                <?php echo htmlspecialchars($doc['dept_name']??''); ?> &bull;
                                <?php echo htmlspecialchars($doc['module_type']); ?> &bull;
                                <?php echo date('M j, Y', strtotime($doc['upload_date'])); ?>
                            </div>
                        </div>
                        <a href="view_form.php?id=<?php echo $doc['id']; ?>" class="btn-action btn-view">
                            <i class="fa-solid fa-eye"></i> View
                        </a>
                    </div>
                    <?php endwhile; else: ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-check-circle"></i>
                        <p>No pending documents. All caught up!</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- ── Success Popup Toast ── -->
<?php if (!empty($_GET['success'])): ?>
<?php
$popup_msg = '';
$popup_icon = '✅';
if ($_GET['success'] === 'approved') {
    $popup_msg = 'Document approved and forwarded to the Finance Office.';
} elseif ($_GET['success'] === 'approved_payment') {
    $popup_msg = 'Document approved and forwarded to the Finance Office. Payment notification email has been sent to the student.';
} elseif ($_GET['success'] === 'recommended') {
    $popup_msg = 'Document recommended and sent to the Registrar\'s office.';
} elseif ($_GET['success'] === 'rejected') {
    $popup_icon = '❌';
    $popup_msg = 'Application rejected and student has been notified with the reason provided.';
} elseif ($_GET['success'] === 'not_recommended') {
    $popup_icon = '❌';
    $popup_msg = 'Application not recommended and student has been notified with the reason provided.';
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