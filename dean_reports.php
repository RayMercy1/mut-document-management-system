<?php
session_start();
require_once 'db_config.php';

// Check access
if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php");
    exit();
}

$is_dean_view = isset($_SESSION['current_admin_view']) && $_SESSION['current_admin_view'] === 'dean';
$is_actual_dean = ($_SESSION['role'] === 'admin' && $_SESSION['admin_role'] === 'dean');

if (!$is_dean_view && !$is_actual_dean && $_SESSION['role'] !== 'super_admin') {
    header("Location: login.php");
    exit();
}

// Get school — only use GET value if it's actually non-empty
// ── Resolve school — full waterfall, same as dean_dashboard ──
$dean_display_reg = $_SESSION['dean_logged_in_reg'] ?? ($_SESSION['role'] === 'admin' && $_SESSION['admin_role'] === 'dean' ? $_SESSION['reg_number'] : null);

$school = null;
if (!empty($_GET['school'])) {
    $school = $_GET['school'];
    $_SESSION['selected_school'] = $school;
} elseif (!empty($_SESSION['selected_school'])) {
    $school = $_SESSION['selected_school'];
} elseif (!empty($dean_display_reg)) {
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

// Final fallback: direct DB lookup from dean_logged_in_reg
if (!$school && !empty($_SESSION['dean_logged_in_reg'])) {
    $s = $conn->prepare("SELECT school FROM users WHERE reg_number = ?");
    $s->bind_param("s", $_SESSION['dean_logged_in_reg']);
    $s->execute();
    $school = $s->get_result()->fetch_assoc()['school'] ?? null;
    if ($school) $_SESSION['selected_school'] = $school;
}

if (!$school) {
    header("Location: admin_dashboard.php?select_school=1");
    exit();
}
$school_url = urlencode($school);

// Date range filter
$date_range = $_GET['range'] ?? '30';
$date_condition = "DATE(d.upload_date) >= DATE_SUB(CURDATE(), INTERVAL $date_range DAY)";

// Get departments in school
$depts_query = "SELECT id, dept_name FROM departments WHERE school = ? ORDER BY dept_name";
$stmt = $conn->prepare($depts_query);
$stmt->bind_param("s", $school);
$stmt->execute();
$departments = $stmt->get_result();
$dept_ids = [];
$dept_names = [];
while ($d = $departments->fetch_assoc()) {
    $dept_ids[] = $d['id'];
    $dept_names[$d['id']] = $d['dept_name'];
}
$dept_list = !empty($dept_ids) ? implode(',', $dept_ids) : '0';

// Overview Statistics
$overview_query = "SELECT 
    COUNT(*) as total_documents,
    COUNT(DISTINCT d.reg_number) as total_students,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN d.status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN d.status IN ('Pending_COD', 'Pending_Dean', 'Pending_Registrar') THEN 1 ELSE 0 END) as pending,
    AVG(CASE WHEN d.status = 'Approved' THEN DATEDIFF(d.updated_at, d.upload_date) END) as avg_approval_days
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    WHERE u.department_id IN ($dept_list) AND $date_condition";
$overview = $conn->query($overview_query)->fetch_assoc();

// Department Comparison
$dept_query = "SELECT 
    u.department_id,
    dpt.dept_name,
    COUNT(*) as doc_count,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN d.status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
    COUNT(DISTINCT d.reg_number) as student_count
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    JOIN departments dpt ON u.department_id = dpt.id
    WHERE u.department_id IN ($dept_list) AND $date_condition
    GROUP BY u.department_id
    ORDER BY doc_count DESC";
$dept_stats = $conn->query($dept_query);

// Module Type Distribution
$module_query = "SELECT 
    d.module_type,
    COUNT(*) as count,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    WHERE u.department_id IN ($dept_list) AND $date_condition
    GROUP BY d.module_type
    ORDER BY count DESC";
$module_stats = $conn->query($module_query);

// Monthly Trend
$trend_query = "SELECT 
    DATE_FORMAT(d.upload_date, '%Y-%m') as month,
    DATE_FORMAT(d.upload_date, '%b %Y') as month_label,
    COUNT(*) as total,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    WHERE u.department_id IN ($dept_list) AND $date_condition
    GROUP BY DATE_FORMAT(d.upload_date, '%Y-%m')
    ORDER BY month";
$trend_result = $conn->query($trend_query);
$trend_labels = [];
$trend_total = [];
$trend_approved = [];
while ($row = $trend_result->fetch_assoc()) {
    $trend_labels[] = $row['month_label'];
    $trend_total[] = $row['total'];
    $trend_approved[] = $row['approved'];
}

// Processing Timeline
$timeline_query = "SELECT 
    AVG(CASE WHEN status = 'Approved' THEN DATEDIFF(updated_at, upload_date) END) as avg_days,
    SUM(CASE WHEN status = 'Approved' AND DATEDIFF(updated_at, upload_date) <= 3 THEN 1 ELSE 0 END) as fast_approved,
    SUM(CASE WHEN status = 'Approved' AND DATEDIFF(updated_at, upload_date) > 7 THEN 1 ELSE 0 END) as slow_approved
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    WHERE u.department_id IN ($dept_list) AND d.status = 'Approved' AND $date_condition";
$timeline = $conn->query($timeline_query)->fetch_assoc();

// Status for pie chart
$status_data = [
    'Approved' => $overview['approved'] ?? 0,
    'Pending' => $overview['pending'] ?? 0,
    'Rejected' => $overview['rejected'] ?? 0
];

// ── Exam Applications Summary (per unit row) ──
$sem_start_dean = date('Y') . '-01-01';
$exam_apps_dean = $conn->query("SELECT 
    u.full_name, u.reg_number as student_reg, dept.dept_name,
    d.id as doc_id, d.module_type, d.status, d.upload_date,
    rrf.exam_month, rrf.exam_year,
    fu.unit_code, fu.unit_title
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    LEFT JOIN departments dept ON u.department_id = dept.id
    LEFT JOIN resit_retake_forms rrf ON rrf.document_id = d.id
    LEFT JOIN form_units fu ON fu.form_id = rrf.id
    WHERE u.department_id IN ($dept_list)
      AND d.module_type IN ('Resit','Retake','Special_Exam')
      AND d.upload_date >= '$sem_start_dean'
    ORDER BY dept.dept_name, u.full_name, d.module_type, fu.unit_code");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Reports | Dean Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #7c3aed;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #1e293b;
            --text-light: #64748b;
            --border: #e2e8f0;
            --shadow: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

        .layout { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: #0f172a;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
        }
        .sidebar-header { padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .logo { display: flex; align-items: center; gap: 12px; }
        .logo-icon {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, var(--primary) 0%, #5b21b6 100%);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 1.5rem;
        }
        .logo-text { color: white; }
        .logo-text h3 { font-size: 1.1rem; font-weight: 700; }
        .logo-text span { font-size: 0.75rem; color: rgba(255,255,255,0.6); }
        
        .current-view-box {
            margin: 16px; padding: 16px;
            background: linear-gradient(135deg, rgba(124, 58, 237, 0.2) 0%, rgba(124, 58, 237, 0.1) 100%);
            border: 1px solid rgba(124, 58, 237, 0.3);
            border-radius: 12px; color: white;
        }
        .current-view-box .label { font-size: 0.75rem; color: rgba(255,255,255,0.6); margin-bottom: 4px; text-transform: uppercase; }
        .current-view-box .value { font-size: 1.1rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 8px; }
        .current-view-box .school { font-size: 0.85rem; color: rgba(255,255,255,0.8); margin-top: 6px; padding-top: 6px; border-top: 1px solid rgba(255,255,255,0.1); }
        
        .nav-section { padding: 20px 0; }
        .nav-title { padding: 8px 24px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(255,255,255,0.4); }
        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 24px; color: rgba(255,255,255,0.7);
            text-decoration: none; transition: all 0.2s ease;
            border-left: 3px solid transparent;
        }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.05); color: white; border-left-color: var(--primary); }
        .nav-item i { width: 20px; text-align: center; }
        
        .btn-back {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            margin: 0 16px 16px; padding: 10px;
            background: rgba(255,255,255,0.1);
            border: 1px dashed rgba(255,255,255,0.3);
            border-radius: 8px; color: rgba(255,255,255,0.7);
            font-size: 0.85rem; text-decoration: none; transition: all 0.2s ease;
        }
        .btn-back:hover { background: rgba(255,255,255,0.2); color: white; }
        
        .sidebar-footer {
            padding: 16px 24px;
            border-top: 1px solid rgba(255,255,255,0.1);
            position: absolute; bottom: 0; width: 100%;
        }
        .admin-info { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .admin-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary) 0%, #5b21b6 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; }
        .admin-details .name { color: white; font-weight: 600; font-size: 0.9rem; }
        .admin-details .role { color: rgba(255,255,255,0.5); font-size: 0.75rem; text-transform: uppercase; }
        .btn-logout {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; padding: 12px;
            background: rgba(239, 68, 68, 0.2); color: #ef4444;
            border: none; border-radius: 8px;
            font-size: 0.875rem; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease; text-decoration: none;
        }
        .btn-logout:hover { background: rgba(239, 68, 68, 0.3); }

        /* Main Content */
        .main-content { flex: 1; margin-left: 280px; min-height: 100vh; }
        .header {
            background: var(--card); padding: 20px 32px;
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid var(--border);
            position: sticky; top: 0; z-index: 50;
        }
        .page-title h1 { font-size: 1.75rem; font-weight: 800; color: var(--text); }
        .page-title p { font-size: 0.875rem; color: var(--text-light); margin-top: 4px; }
        .content { padding: 32px; }

        /* Filter Bar */
        .filter-bar {
            background: var(--card);
            border-radius: 12px;
            padding: 16px 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .filter-group { display: flex; align-items: center; gap: 12px; }
        .filter-group label { font-weight: 600; color: var(--text-light); }
        .filter-group select {
            padding: 8px 16px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .filter-group select:focus { border-color: var(--primary); outline: none; }
        .btn-export {
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-export:hover { background: #5b21b6; }

        /* Stats Overview */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 16px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: var(--card);
            border-radius: 16px;
            padding: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            text-align: center;
        }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem;
            margin: 0 auto 12px;
        }
        .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-icon.orange { background: #fef3c7; color: #d97706; }
        .stat-icon.red { background: #fee2e2; color: #dc2626; }
        .stat-icon.blue { background: #dbeafe; color: #2563eb; }
        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 4px;
        }
        .stat-label { font-size: 0.8rem; color: var(--text-light); }

        /* Charts Section */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }
        .chart-card {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .chart-title {
            font-size: 1.1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .chart-title i { color: var(--primary); }
        .chart-container { position: relative; height: 300px; }
        .chart-container.pie { height: 250px; }

        /* Tables Section */
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }
        .table-card {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        .table-header-custom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .table-title-custom {
            font-size: 1.1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .table-title-custom i { color: var(--primary); }

        /* Data Table */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        .data-table th {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-light);
            background: var(--bg);
        }
        .data-table tbody tr:hover { background: rgba(241, 245, 249, 0.5); }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--border);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 4px;
        }
        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        .progress-fill.success { background: var(--success); }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }

        .timeline-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .timeline-card {
            background: linear-gradient(135deg, var(--bg) 0%, white 100%);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border);
            text-align: center;
        }
        .timeline-value {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 4px;
        }
        .timeline-label {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .charts-grid { grid-template-columns: 1fr; }
            .tables-grid { grid-template-columns: 1fr; }
            .timeline-stats { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fa-solid fa-user-graduate"></i>
                    </div>
                    <div class="logo-text">
                        <h3>Dean Portal</h3>
                        <span>School Dean</span>
                    </div>
                </div>
            </div>

            <div class="current-view-box">
                <div class="label">Currently Viewing</div>
                <div class="value">
                    <i class="fa-solid fa-eye"></i>
                    Dean View
                </div>
                <?php if ($school): ?>
                    <div class="school">
                        <i class="fa-solid fa-university"></i>
                        <?php echo htmlspecialchars($school); ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (isset($_SESSION['current_admin_view'])): ?>
                <a href="admin_dashboard.php?clear_view=1" class="btn-back">
                    <i class="fa-solid fa-arrow-left"></i>
                    Back to System Admin
                </a>
            <?php endif; ?>

            <nav class="nav-section">
                <div class="nav-title">Menu</div>
                
                <a href="dean_dashboard.php?school=<?php echo $school_url; ?>" class="nav-item">
                    <i class="fa-solid fa-grid-2"></i>
                    <span>Dashboard</span>
                </a>
                
                <a href="dean_documents.php?school=<?php echo $school_url; ?>" class="nav-item">
                    <i class="fa-solid fa-folder-open"></i>
                    <span>Students Applications</span>
                </a>
                
                <a href="dean_reports.php?school=<?php echo $school_url; ?>" class="nav-item active">
                    <i class="fa-solid fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <div class="admin-info">
                    <div class="admin-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'D', 0, 1)); ?>
                    </div>
                    <div class="admin-details">
                        <div class="name"><?php echo htmlspecialchars($_SESSION['dean_logged_in_name'] ?? $_SESSION['full_name'] ?? 'Dean'); ?></div>
                        <div class="role">School Dean</div>
                    </div>
                </div>
                
                <a href="logout.php" class="btn-logout">
                    <i class="fa-solid fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="header">
                <div class="page-title">
                    <h1>School Reports</h1>
                    <p>Analytics and insights for <?php echo htmlspecialchars($school); ?></p>
                </div>
            </header>

            <div class="content">
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <div class="filter-group">
                        <label><i class="fa-solid fa-calendar"></i> Time Period:</label>
                        <select onchange="window.location.href='?school=<?php echo $school_url; ?>&range='+this.value">
                            <option value="7" <?php echo $date_range == '7' ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="30" <?php echo $date_range == '30' ? 'selected' : ''; ?>>Last 30 Days</option>
                            <option value="90" <?php echo $date_range == '90' ? 'selected' : ''; ?>>Last 3 Months</option>
                            <option value="365" <?php echo $date_range == '365' ? 'selected' : ''; ?>>Last Year</option>
                        </select>
                    </div>
                    <button class="btn-export" onclick="printExamList()">
                        <i class="fa-solid fa-print"></i> Print Exam List
                    </button>
                </div>

                <!-- Overview Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon purple">
                            <i class="fa-solid fa-file-lines"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format(intval($overview['total_documents'] ?? 0)); ?></div>
                        <div class="stat-label">Total Documents</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <i class="fa-solid fa-users"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format(intval($overview['total_students'] ?? 0)); ?></div>
                        <div class="stat-label">Active Students</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <i class="fa-solid fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format(intval($overview['approved'] ?? 0)); ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange">
                            <i class="fa-solid fa-clock"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format(intval($overview['pending'] ?? 0)); ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red">
                            <i class="fa-solid fa-times-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format(intval($overview['rejected'] ?? 0)); ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                </div>

                <!-- Processing Timeline -->
                <div class="timeline-stats">
                    <div class="timeline-card">
                        <div class="timeline-value"><?php echo round($timeline['avg_days'] ?? 0, 1); ?> days</div>
                        <div class="timeline-label">Average Processing Time</div>
                    </div>
                    <div class="timeline-card">
                        <div class="timeline-value" style="color: var(--success);"><?php echo number_format($timeline['fast_approved'] ?? 0); ?></div>
                        <div class="timeline-label">Approved ≤ 3 Days</div>
                    </div>
                    <div class="timeline-card">
                        <div class="timeline-value" style="color: var(--danger);"><?php echo number_format($timeline['slow_approved'] ?? 0); ?></div>
                        <div class="timeline-label">Approved > 7 Days</div>
                    </div>
                </div>


                <!-- ── Students Exam Applications Summary ── -->
                <div class="chart-card" style="margin-bottom:28px;">
                    <div class="chart-header" style="flex-wrap:wrap;gap:10px;">
                        <h3 class="chart-title">
                            <i class="fa-solid fa-table-list"></i>
                            Students Exam Applications – This Semester (All Departments)
                        </h3>
                        <button class="btn-export" onclick="printExamList()" style="background:#7c3aed;">
                            <i class="fa-solid fa-print"></i> Print
                        </button>
                    </div>

                    <?php if ($exam_apps_dean && $exam_apps_dean->num_rows > 0):
                        $rows_by_doc_d = [];
                        while ($r = $exam_apps_dean->fetch_assoc()) $rows_by_doc_d[$r['doc_id']][] = $r;
                        $rn = 1;
                    ?>
                    <div style="overflow-x:auto;">
                    <table class="data-table" id="examAppTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student Name</th>
                                <th>Reg. Number</th>
                                <th>Department</th>
                                <th>Exam Type</th>
                                <th>Period</th>
                                <th>Unit Code</th>
                                <th>Unit Name</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows_by_doc_d as $did => $urows):
                                $f = $urows[0];
                                $tb = match($f['module_type']){'Resit'=>'<span style="background:#dbeafe;color:#1d4ed8;padding:3px 9px;border-radius:10px;font-size:.75rem;font-weight:700;">Resit</span>','Retake'=>'<span style="background:#ede9fe;color:#6d28d9;padding:3px 9px;border-radius:10px;font-size:.75rem;font-weight:700;">Retake</span>','Special_Exam'=>'<span style="background:#ccfbf1;color:#0f766e;padding:3px 9px;border-radius:10px;font-size:.75rem;font-weight:700;">Special</span>',default=>$f['module_type']};
                                $sb = match(true){str_contains($f['status'],'Pending')=>'<span style="background:#fef3c7;color:#92400e;padding:3px 9px;border-radius:10px;font-size:.75rem;font-weight:700;">'.str_replace('_',' ',$f['status']).'</span>',$f['status']==='Approved'=>'<span style="background:#d1fae5;color:#065f46;padding:3px 9px;border-radius:10px;font-size:.75rem;font-weight:700;">Approved</span>',$f['status']==='Rejected'=>'<span style="background:#fee2e2;color:#991b1b;padding:3px 9px;border-radius:10px;font-size:.75rem;font-weight:700;">Rejected</span>',default=>$f['status']};
                                $uc = count($urows);
                                $per = htmlspecialchars($f['exam_month'].' '.($f['exam_year']??''));
                            ?>
                            <?php foreach ($urows as $ui => $ur): ?>
                            <tr>
                                <?php if ($ui===0): ?>
                                <td rowspan="<?php echo $uc; ?>"><?php echo $rn++; ?></td>
                                <td rowspan="<?php echo $uc; ?>" style="font-weight:600;"><?php echo htmlspecialchars($f['full_name']); ?></td>
                                <td rowspan="<?php echo $uc; ?>" style="font-family:monospace;font-size:.8rem;"><?php echo htmlspecialchars($f['student_reg']); ?></td>
                                <td rowspan="<?php echo $uc; ?>"><?php echo htmlspecialchars($f['dept_name']??'N/A'); ?></td>
                                <td rowspan="<?php echo $uc; ?>"><?php echo $tb; ?></td>
                                <td rowspan="<?php echo $uc; ?>"><?php echo $per; ?></td>
                                <?php endif; ?>
                                <td style="font-family:monospace;font-size:.85rem;"><?php echo htmlspecialchars($ur['unit_code']??'—'); ?></td>
                                <td><?php echo htmlspecialchars($ur['unit_title']??'—'); ?></td>
                                <?php if ($ui===0): ?>
                                <td rowspan="<?php echo $uc; ?>"><?php echo $sb; ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php else: ?>
                    <p style="color:var(--text-light);padding:24px;text-align:center;">No exam applications this semester.</p>
                    <?php endif; ?>
                </div>

                <!-- Charts -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3 class="chart-title">
                                <i class="fa-solid fa-chart-line"></i>
                                Document Trends
                            </h3>
                        </div>
                        <div class="chart-container">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3 class="chart-title">
                                <i class="fa-solid fa-chart-pie"></i>
                                Status Distribution
                            </h3>
                        </div>
                        <div class="chart-container pie">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Tables -->
                <div class="tables-grid">
                    <div class="table-card">
                        <div class="table-header-custom">
                            <h3 class="table-title-custom">
                                <i class="fa-solid fa-building"></i>
                                Department Performance
                            </h3>
                        </div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Students</th>
                                    <th>Documents</th>
                                    <th>Approved</th>
                                    <th>Success Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($dept = $dept_stats->fetch_assoc()): 
                                    $success_rate = $dept['doc_count'] > 0 ? round(($dept['approved'] / $dept['doc_count']) * 100) : 0;
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($dept['dept_name']); ?></strong></td>
                                        <td><?php echo number_format(intval($dept['student_count'] ?? 0)); ?></td>
                                        <td><?php echo number_format(intval($dept['doc_count'] ?? 0)); ?></td>
                                        <td><?php echo number_format(intval($dept['approved'] ?? 0)); ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <span><?php echo $success_rate; ?>%</span>
                                                <div class="progress-bar" style="width: 60px;">
                                                    <div class="progress-fill success" style="width: <?php echo $success_rate; ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-card">
                        <div class="table-header-custom">
                            <h3 class="table-title-custom">
                                <i class="fa-solid fa-layer-group"></i>
                                Documents by Module Type
                            </h3>
                        </div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Module</th>
                                    <th>Count</th>
                                    <th>Approved</th>
                                    <th>Performance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($mod = $module_stats->fetch_assoc()): 
                                    $mod_success = $mod['count'] > 0 ? round(($mod['approved'] / $mod['count']) * 100) : 0;
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($mod['module_type']); ?></strong></td>
                                        <td><?php echo number_format(intval($mod['count'] ?? 0)); ?></td>
                                        <td><?php echo number_format(intval($mod['approved'] ?? 0)); ?></td>
                                        <td>
                                            <span class="badge <?php echo $mod_success >= 80 ? 'badge-success' : 'badge-warning'; ?>">
                                                <?php echo $mod_success; ?>% approval
                                            </span>
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
        // Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        new Chart(trendCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($trend_labels); ?>,
                datasets: [{
                    label: 'Total Documents',
                    data: <?php echo json_encode($trend_total); ?>,
                    backgroundColor: 'rgba(124, 58, 237, 0.8)',
                    borderRadius: 6
                }, {
                    label: 'Approved',
                    data: <?php echo json_encode($trend_approved); ?>,
                    backgroundColor: 'rgba(16, 185, 129, 0.8)',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });

        // Status Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Approved', 'Pending', 'Rejected'],
                datasets: [{
                    data: [
                        <?php echo $status_data['Approved']; ?>,
                        <?php echo $status_data['Pending']; ?>,
                        <?php echo $status_data['Rejected']; ?>
                    ],
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });

        // Print only the exam applications list
        function printExamList() {
            const school = <?php echo json_encode($school ?? ''); ?>;
            const table  = document.getElementById('examAppTable');
            if (!table) { alert('No exam applications to print.'); return; }
            const win = window.open('', '_blank', 'width=1000,height=750');
            win.document.write(`<!DOCTYPE html><html><head>
                <title>Students Exam Applications – ${school}</title>
                <style>
                    body{font-family:'Times New Roman',Times,serif;padding:20px;font-size:11pt;}
                    h2{text-align:center;font-size:13pt;text-transform:uppercase;margin-bottom:4px;}
                    p.sub{text-align:center;font-size:10pt;color:#444;margin-bottom:16px;}
                    table{width:100%;border-collapse:collapse;}
                    th,td{border:1px solid #000;padding:6px 9px;font-size:10pt;text-align:left;}
                    th{background:#d9d9d9;font-weight:bold;text-align:center;}
                    span[style]{border-radius:4px;padding:2px 6px;font-size:9pt;}
                </style></head><body>
                <h2>Students Exam Applications – This Semester (Resit / Retake / Special)</h2>
                <p class="sub">School: ${school} &nbsp;|&nbsp; Printed: ${new Date().toLocaleDateString('en-KE',{day:'numeric',month:'long',year:'numeric'})}</p>
                ${table.outerHTML}
            </body></html>`);
            win.document.close();
            win.focus();
            setTimeout(() => { win.print(); win.close(); }, 400);
        }
    </script>
</body>
</html>