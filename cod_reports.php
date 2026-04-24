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

$dept_info = $conn->query("SELECT * FROM departments WHERE id = $dept_id")->fetch_assoc();

// Date range filter
$date_range     = $_GET['range'] ?? '365';
$date_condition = "DATE(d.upload_date) >= DATE_SUB(CURDATE(), INTERVAL $date_range DAY)";

// Semester start (current year)
$sem_start = date('Y') . '-01-01';

// ── Overview stats ──
$overview = $conn->query("SELECT 
    COUNT(*) as total_documents,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN d.status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN d.status IN ('Pending_COD','Pending_Dean','Pending_Registrar') THEN 1 ELSE 0 END) as pending,
    AVG(CASE WHEN d.status = 'Approved' THEN DATEDIFF(d.updated_at, d.upload_date) END) as avg_approval_days
    FROM documents d JOIN users u ON d.reg_number = u.reg_number
    WHERE u.department_id = $dept_id AND $date_condition")->fetch_assoc();

// ── Semester exam applications with per-unit rows ──
// One row per unit so the table shows Unit Code + Unit Name individually
$exam_apps = $conn->query("SELECT 
    u.full_name, u.reg_number as student_reg,
    d.id as doc_id, d.module_type, d.status, d.upload_date,
    rrf.exam_month, rrf.exam_year,
    fu.unit_code, fu.unit_title
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    LEFT JOIN resit_retake_forms rrf ON rrf.document_id = d.id
    LEFT JOIN form_units fu ON fu.form_id = rrf.id
    WHERE u.department_id = $dept_id
      AND d.module_type IN ('Resit','Retake','Special_Exam')
      AND d.upload_date >= '$sem_start'
    ORDER BY u.full_name, d.module_type, fu.unit_code");

// ── Module type stats ──
$module_stats = $conn->query("SELECT 
    d.module_type,
    COUNT(*) as count,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN d.status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN d.status IN ('Pending_COD','Pending_Dean','Pending_Registrar') THEN 1 ELSE 0 END) as pending
    FROM documents d JOIN users u ON d.reg_number = u.reg_number
    WHERE u.department_id = $dept_id AND $date_condition
    GROUP BY d.module_type ORDER BY count DESC");

// ── Monthly trend ──
$trend_result = $conn->query("SELECT 
    DATE_FORMAT(d.upload_date, '%b %Y') as month_label,
    COUNT(*) as total,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved
    FROM documents d JOIN users u ON d.reg_number = u.reg_number
    WHERE u.department_id = $dept_id AND $date_condition
    GROUP BY DATE_FORMAT(d.upload_date, '%Y-%m') ORDER BY MIN(d.upload_date)");
$trend_labels = []; $trend_total = []; $trend_approved = [];
while ($row = $trend_result->fetch_assoc()) {
    $trend_labels[]  = $row['month_label'];
    $trend_total[]   = $row['total'];
    $trend_approved[] = $row['approved'];
}

// ── Top students ──
$top_students = $conn->query("SELECT 
    u.full_name, u.reg_number,
    COUNT(*) as doc_count,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved_count
    FROM documents d JOIN users u ON d.reg_number = u.reg_number
    WHERE u.department_id = $dept_id AND $date_condition
    GROUP BY u.reg_number ORDER BY doc_count DESC LIMIT 10");

$status_data = [
    'Approved' => $overview['approved'] ?? 0,
    'Pending'  => $overview['pending']  ?? 0,
    'Rejected' => $overview['rejected'] ?? 0
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports | COD Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--primary:#3b82f6;--bg:#f8fafc;--card:#fff;--text:#1e293b;--text-light:#64748b;--border:#e2e8f0;--shadow:0 1px 3px rgba(0,0,0,.1);--shadow-lg:0 10px 15px -3px rgba(0,0,0,.1);--success:#10b981;--warning:#f59e0b;--danger:#ef4444}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.layout{display:flex;min-height:100vh}
/* Sidebar */
.sidebar{width:280px;background:#0f172a;position:fixed;height:100vh;overflow-y:auto;z-index:100;display:flex;flex-direction:column}
.sidebar-header{padding:24px;border-bottom:1px solid rgba(255,255,255,.1)}
.logo{display:flex;align-items:center;gap:12px}
.logo-icon{width:48px;height:48px;background:linear-gradient(135deg,var(--primary) 0%,#2563eb 100%);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.5rem}
.logo-text{color:white}.logo-text h3{font-size:1.1rem;font-weight:700}.logo-text span{font-size:.75rem;color:rgba(255,255,255,.6)}
.current-view-box{margin:16px;padding:16px;background:linear-gradient(135deg,rgba(59,130,246,.2),rgba(59,130,246,.1));border:1px solid rgba(59,130,246,.3);border-radius:12px;color:white}
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
.admin-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#2563eb);display:flex;align-items:center;justify-content:center;color:white;font-weight:700}
.admin-details .name{color:white;font-weight:600;font-size:.9rem}
.admin-details .role{color:rgba(255,255,255,.5);font-size:.75rem;text-transform:uppercase}
.btn-logout{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;background:rgba(239,68,68,.2);color:#ef4444;border:none;border-radius:8px;font-size:.875rem;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-logout:hover{background:rgba(239,68,68,.3)}
/* Main */
.main-content{flex:1;margin-left:280px;min-height:100vh}
.header{background:var(--card);padding:20px 32px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.page-title h1{font-size:1.75rem;font-weight:800}
.page-title p{font-size:.875rem;color:var(--text-light);margin-top:4px}
.content{padding:32px}
/* Filter bar */
.filter-bar{background:var(--card);border-radius:12px;padding:16px 24px;margin-bottom:24px;box-shadow:var(--shadow);border:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.filter-group{display:flex;align-items:center;gap:12px}
.filter-group label{font-weight:600;color:var(--text-light)}
.filter-group select{padding:8px 16px;border:2px solid var(--border);border-radius:8px;font-family:inherit;font-size:.9rem;cursor:pointer}
.filter-group select:focus{border-color:var(--primary);outline:none}
.btn-export{padding:10px 20px;background:var(--primary);color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:8px;text-decoration:none;font-size:.875rem}
.btn-export:hover{background:#2563eb}
/* Stats */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:32px}
.stat-card{background:var(--card);border-radius:16px;padding:24px;box-shadow:var(--shadow);border:1px solid var(--border)}
.stat-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px}
.stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.25rem}
.stat-icon.blue{background:#dbeafe;color:#2563eb}.stat-icon.green{background:#d1fae5;color:#059669}
.stat-icon.orange{background:#fef3c7;color:#d97706}.stat-icon.red{background:#fee2e2;color:#dc2626}
.stat-value{font-size:2.5rem;font-weight:800;color:var(--text);margin-bottom:4px}
.stat-label{font-size:.875rem;color:var(--text-light)}
.stat-change{font-size:.8rem;margin-top:8px;display:flex;align-items:center;gap:4px}
.stat-change.positive{color:var(--success)}
/* Charts */
.charts-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:24px;margin-bottom:32px}
.chart-card{background:var(--card);border-radius:16px;padding:24px;box-shadow:var(--shadow);border:1px solid var(--border)}
.chart-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.chart-title{font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:8px}
.chart-title i{color:var(--primary)}
.chart-container{position:relative;height:280px}
.chart-container.pie{height:240px}
/* Section card */
.section-card{background:var(--card);border-radius:16px;padding:24px;box-shadow:var(--shadow);border:1px solid var(--border);margin-bottom:28px;overflow:hidden}
.section-hdr{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px}
.section-title-inner{font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:8px}
.section-title-inner i{color:var(--primary)}
/* Exam applications table */
.exam-table{width:100%;border-collapse:collapse}
.exam-table th,.exam-table td{padding:11px 14px;text-align:left;border-bottom:1px solid var(--border);font-size:.875rem}
.exam-table th{background:var(--bg);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--text-light)}
.exam-table tr:last-child td{border-bottom:none}
.exam-table tbody tr:hover{background:rgba(241,245,249,.5)}
/* badge */
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:.75rem;font-weight:600}
.badge-resit{background:#dbeafe;color:#1d4ed8}.badge-retake{background:#ede9fe;color:#6d28d9}
.badge-special{background:#ccfbf1;color:#0f766e}.badge-pending{background:#fef3c7;color:#92400e}
.badge-approved{background:#d1fae5;color:#065f46}.badge-rejected{background:#fee2e2;color:#991b1b}
.badge-success{background:#d1fae5;color:#065f46}.badge-warning{background:#fef3c7;color:#92400e}
.badge-danger{background:#fee2e2;color:#991b1b}
/* Tables 2-col */
.tables-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:24px;margin-bottom:28px}
.table-card{background:var(--card);border-radius:16px;padding:24px;box-shadow:var(--shadow);border:1px solid var(--border)}
.table-header-custom{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.table-title-custom{font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:8px}
.table-title-custom i{color:var(--primary)}
.data-table{width:100%;border-collapse:collapse}
.data-table th,.data-table td{padding:11px 14px;text-align:left;border-bottom:1px solid var(--border);font-size:.875rem}
.data-table th{font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-light);background:var(--bg)}
.data-table tbody tr:hover{background:rgba(241,245,249,.5)}
.progress-bar{width:100%;height:8px;background:var(--border);border-radius:4px;overflow:hidden;margin-top:4px}
.progress-fill{height:100%;background:var(--primary);border-radius:4px}
.progress-fill.success{background:var(--success)}.progress-fill.warning{background:var(--warning)}.progress-fill.danger{background:var(--danger)}
.btn-print-sm{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:var(--bg);border:1px solid var(--border);border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer;color:var(--text);text-decoration:none}
.btn-print-sm:hover{background:var(--border)}
@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}.charts-grid{grid-template-columns:1fr}.tables-grid{grid-template-columns:1fr}}
@media print{
    .sidebar,.filter-bar,.btn-print-sm,
    .header,.btn-back,.btn-export,.btn-logout{display:none!important}
    .main-content{margin-left:0!important}
    .content{padding:8px!important}
    .section-card{box-shadow:none!important;border:1px solid #e2e8f0!important}
    .section-hdr .btn-print-sm{display:none!important}
    .charts-grid,.tables-grid,.stats-grid{display:block!important}
    .stat-card,.chart-card,.table-card{page-break-inside:avoid;margin-bottom:16px}
    body{background:white!important}
}
</style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon"><i class="fa-solid fa-user-tie"></i></div>
                <div class="logo-text"><h3>COD Portal</h3><span>Department Head</span></div>
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
            <a href="cod_dashboard.php?dept=<?php echo $dept_id; ?>" class="nav-item">
                <i class="fa-solid fa-grid-2"></i><span>Dashboard</span>
            </a>
            <a href="cod_documents.php?dept=<?php echo $dept_id; ?>" class="nav-item">
                <i class="fa-solid fa-folder-open"></i><span>Students Applications</span>
            </a>
            <a href="cod_reports.php?dept=<?php echo $dept_id; ?>" class="nav-item active">
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
                <h1>Department Reports</h1>
                <p>Analytics and exam application summary for <?php echo htmlspecialchars($dept_info['dept_name'] ?? 'your department'); ?></p>
            </div>
        </header>

        <div class="content">
            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="filter-group">
                    <label><i class="fa-solid fa-calendar"></i> Period:</label>
                    <select onchange="window.location.href='?dept=<?php echo $dept_id; ?>&range='+this.value">
                        <option value="30"  <?php echo $date_range=='30'?'selected':''; ?>>Last 30 Days</option>
                        <option value="90"  <?php echo $date_range=='90'?'selected':''; ?>>Last 3 Months</option>
                        <option value="365" <?php echo $date_range=='365'?'selected':''; ?>>This Year</option>
                    </select>
                </div>
                <button class="btn-export" onclick="window.print()">
                    <i class="fa-solid fa-print"></i> Print Report
                </button>
            </div>

            <!-- Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header"><div class="stat-icon blue"><i class="fa-solid fa-file-lines"></i></div></div>
                    <div class="stat-value"><?php echo number_format($overview['total_documents']); ?></div>
                    <div class="stat-label">Total Documents</div>
                </div>
                <div class="stat-card">
                    <div class="stat-header"><div class="stat-icon green"><i class="fa-solid fa-check-circle"></i></div></div>
                    <div class="stat-value"><?php echo number_format($overview['approved']); ?></div>
                    <div class="stat-label">Approved</div>
                    <div class="stat-change positive">
                        <i class="fa-solid fa-arrow-up"></i>
                        <?php echo $overview['total_documents'] > 0 ? round(($overview['approved']/$overview['total_documents'])*100,1) : 0; ?>% approval rate
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header"><div class="stat-icon orange"><i class="fa-solid fa-clock"></i></div></div>
                    <div class="stat-value"><?php echo number_format($overview['pending']); ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat-card">
                    <div class="stat-header"><div class="stat-icon red"><i class="fa-solid fa-times-circle"></i></div></div>
                    <div class="stat-value"><?php echo number_format($overview['rejected']); ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fa-solid fa-chart-line"></i> Document Trends</h3>
                    </div>
                    <div class="chart-container"><canvas id="trendChart"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fa-solid fa-chart-pie"></i> Status Distribution</h3>
                    </div>
                    <div class="chart-container pie"><canvas id="statusChart"></canvas></div>
                </div>
            </div>

            <!-- ── Exam Applications Summary (per unit row) ── -->
            <div class="section-card">
                <div class="section-hdr">
                    <h3 class="section-title-inner">
                        <i class="fa-solid fa-table-list"></i>
                        Students Exam Applications – This Semester
                        <span style="font-size:.75rem;font-weight:500;color:var(--text-light);margin-left:8px;">(Resit / Retake / Special)</span>
                    </h3>
                    <a href="#" class="btn-print-sm" onclick="printExamList();return false;">
                        <i class="fa-solid fa-print"></i> Print
                    </a>
                </div>

                <?php if ($exam_apps && $exam_apps->num_rows > 0):
                    // Group rows by document id for display
                    $rows_by_doc = [];
                    while ($r = $exam_apps->fetch_assoc()) {
                        $rows_by_doc[$r['doc_id']][] = $r;
                    }
                    $row_num = 1;
                ?>
                <div style="overflow-x:auto;">
                <table class="exam-table" id="examAppTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Name</th>
                            <th>Reg. Number</th>
                            <th>Exam Type</th>
                            <th>Period</th>
                            <th>Unit Code</th>
                            <th>Unit Name</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows_by_doc as $doc_id => $unit_rows):
                            $first = $unit_rows[0];
                            $type_badge = match($first['module_type']){
                                'Resit'        => '<span class="badge badge-resit">Resit</span>',
                                'Retake'       => '<span class="badge badge-retake">Retake</span>',
                                'Special_Exam' => '<span class="badge badge-special">Special</span>',
                                default        => '<span class="badge">'.$first['module_type'].'</span>'
                            };
                            $status_badge = match(true){
                                str_contains($first['status'],'Pending') => '<span class="badge badge-pending">'.str_replace('_',' ',$first['status']).'</span>',
                                $first['status']==='Approved'            => '<span class="badge badge-approved">Approved</span>',
                                $first['status']==='Rejected'            => '<span class="badge badge-rejected">Rejected</span>',
                                default                                  => '<span class="badge">'.$first['status'].'</span>'
                            };
                            $unit_count = count($unit_rows);
                            $period     = htmlspecialchars($first['exam_month'].' '.($first['exam_year']??''));
                        ?>
                        <?php foreach ($unit_rows as $ui => $ur): ?>
                        <tr>
                            <?php if ($ui === 0): // Only show these cells on first unit row ?>
                            <td rowspan="<?php echo $unit_count; ?>"><?php echo $row_num++; ?></td>
                            <td rowspan="<?php echo $unit_count; ?>" style="font-weight:600;"><?php echo htmlspecialchars($first['full_name']); ?></td>
                            <td rowspan="<?php echo $unit_count; ?>" style="font-family:monospace;font-size:.8rem;"><?php echo htmlspecialchars($first['student_reg']); ?></td>
                            <td rowspan="<?php echo $unit_count; ?>"><?php echo $type_badge; ?></td>
                            <td rowspan="<?php echo $unit_count; ?>"><?php echo $period; ?></td>
                            <?php endif; ?>
                            <td style="font-family:monospace;font-size:.85rem;"><?php echo htmlspecialchars($ur['unit_code'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($ur['unit_title'] ?? '—'); ?></td>
                            <?php if ($ui === 0): ?>
                            <td rowspan="<?php echo $unit_count; ?>"><?php echo $status_badge; ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <div style="text-align:center;padding:40px;color:var(--text-light);">
                    <i class="fa-solid fa-folder-open" style="font-size:2.5rem;opacity:.4;display:block;margin-bottom:12px;"></i>
                    <p>No exam applications recorded this semester.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Top Module Type of Documents -->
            <div class="tables-grid" style="grid-template-columns:1fr;">
                <div class="table-card">
                    <div class="table-header-custom">
                        <h3 class="table-title-custom"><i class="fa-solid fa-layer-group"></i> Top Module Type of Documents</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr><th>Module Type</th><th>Total Applied</th><th>Approved</th><th>Rejected</th><th>Pending</th><th>Success Rate</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            // Re-run module_stats since pointer may be exhausted
                            $mod_stats2 = $conn->query("SELECT
                                d.module_type,
                                COUNT(*) as count,
                                SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved,
                                SUM(CASE WHEN d.status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
                                SUM(CASE WHEN d.status IN ('Pending_COD','Pending_Dean','Pending_Registrar') THEN 1 ELSE 0 END) as pending
                                FROM documents d JOIN users u ON d.reg_number = u.reg_number
                                WHERE u.department_id = $dept_id AND d.module_type IN ('Resit','Retake','Special_Exam')
                                ORDER BY count DESC");
                            while ($mod = $mod_stats2->fetch_assoc()):
                                $sr = $mod['count'] > 0 ? round(($mod['approved']/$mod['count'])*100) : 0;
                                $label = match($mod['module_type']) {
                                    'Resit' => 'Resit', 'Retake' => 'Retake',
                                    'Special_Exam' => 'Special Exam', default => $mod['module_type']
                                };
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($label); ?></strong></td>
                                <td><?php echo number_format($mod['count']); ?></td>
                                <td><?php echo number_format($mod['approved']); ?></td>
                                <td><?php echo number_format($mod['rejected']); ?></td>
                                <td><?php echo number_format($mod['pending']); ?></td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <span><?php echo $sr; ?>%</span>
                                        <div class="progress-bar" style="width:60px;">
                                            <div class="progress-fill <?php echo $sr>=80?'success':($sr>=50?'warning':'danger'); ?>" style="width:<?php echo $sr; ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
const trendCtx = document.getElementById('trendChart').getContext('2d');
new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($trend_labels); ?>,
        datasets: [{
            label: 'Total Documents',
            data: <?php echo json_encode($trend_total); ?>,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59,130,246,.1)',
            tension: 0.4, fill: true
        },{
            label: 'Approved',
            data: <?php echo json_encode($trend_approved); ?>,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16,185,129,.1)',
            tension: 0.4, fill: true
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});

const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Approved','Pending','Rejected'],
        datasets: [{ data: [<?php echo $status_data['Approved']; ?>,<?php echo $status_data['Pending']; ?>,<?php echo $status_data['Rejected']; ?>], backgroundColor: ['#10b981','#f59e0b','#ef4444'], borderWidth: 0 }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
});

// Print only the exam applications list
function printExamList() {
    const dept    = <?php echo json_encode($dept_info['dept_name'] ?? ''); ?>;
    const school  = <?php echo json_encode($dept_info['school'] ?? ''); ?>;
    const table   = document.getElementById('examAppTable');
    if (!table) { window.print(); return; }
    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(`<!DOCTYPE html><html><head>
        <title>Exam Applications – ${dept}</title>
        <style>
            body{font-family:'Times New Roman',Times,serif;padding:20px;font-size:11pt;}
            h2{text-align:center;font-size:13pt;text-transform:uppercase;margin-bottom:4px;}
            p.sub{text-align:center;font-size:10pt;color:#444;margin-bottom:16px;}
            table{width:100%;border-collapse:collapse;}
            th,td{border:1px solid #000;padding:6px 10px;font-size:10pt;}
            th{background:#d9d9d9;font-weight:bold;text-align:center;}
            .badge{padding:2px 6px;border-radius:4px;font-size:9pt;font-weight:bold;}
        </style></head><body>
        <h2>Students Exam Applications – This Semester (Resit / Retake / Special)</h2>
        <p class="sub">Department: ${dept} &nbsp;|&nbsp; ${school} &nbsp;|&nbsp; Printed: ${new Date().toLocaleDateString('en-KE',{day:'numeric',month:'long',year:'numeric'})}</p>
        ${table.outerHTML}
    </body></html>`);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); win.close(); }, 400);
}
</script>
</body>
</html>