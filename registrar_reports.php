<?php
session_start();
require_once 'db_config.php';

// Check access
if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php");
    exit();
}

$is_registrar_view = isset($_SESSION['current_admin_view']) && $_SESSION['current_admin_view'] === 'registrar';
$is_actual_registrar = ($_SESSION['role'] === 'admin' && $_SESSION['admin_role'] === 'registrar');

if (!$is_registrar_view && !($is_actual_registrar || $_SESSION['role'] === 'super_admin')) {
    header("Location: login.php");
    exit();
}

// Date range filter
$date_range = $_GET['range'] ?? '30';
$date_condition = "DATE(d.upload_date) >= DATE_SUB(CURDATE(), INTERVAL $date_range DAY)";

// Overview Statistics
$overview_query = "SELECT 
    COUNT(*) as total_documents,
    COUNT(DISTINCT d.reg_number) as total_students,
    COUNT(DISTINCT u.department_id) as active_departments,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN d.status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN d.status = 'Pending_Registrar' THEN 1 ELSE 0 END) as pending_registrar
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    WHERE $date_condition";
$overview = $conn->query($overview_query)->fetch_assoc();

// School stats
$school_query = "SELECT 
    COALESCE(dept.school, 'Unassigned') as school_name,
    COUNT(*) as doc_count,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved,
    COUNT(DISTINCT d.reg_number) as student_count
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    LEFT JOIN departments dept ON u.department_id = dept.id
    WHERE $date_condition
    GROUP BY dept.school
    ORDER BY doc_count DESC";
$school_stats = $conn->query($school_query);

// Module stats
$module_query = "SELECT 
    d.module_type,
    COUNT(*) as count,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    WHERE $date_condition
    GROUP BY d.module_type
    ORDER BY count DESC";
$module_stats = $conn->query($module_query);

// Monthly trend
$trend_query = "SELECT 
    DATE_FORMAT(d.upload_date, '%b %Y') as month_label,
    COUNT(*) as total,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN d.status = 'Rejected' THEN 1 ELSE 0 END) as rejected
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    WHERE $date_condition
    GROUP BY DATE_FORMAT(d.upload_date, '%Y-%m')
    ORDER BY MIN(d.upload_date)";
$trend_result = $conn->query($trend_query);
$trend_labels = [];
$trend_total = [];
$trend_approved = [];
$trend_rejected = [];
while ($row = $trend_result->fetch_assoc()) {
    $trend_labels[] = $row['month_label'];
    $trend_total[] = $row['total'];
    $trend_approved[] = $row['approved'];
    $trend_rejected[] = $row['rejected'];
}

// Pipeline stats
$pipeline_query = "SELECT 
    SUM(CASE WHEN status = 'Pending_COD' THEN 1 ELSE 0 END) as cod_stage,
    SUM(CASE WHEN status = 'Pending_Dean' THEN 1 ELSE 0 END) as dean_stage,
    SUM(CASE WHEN status = 'Pending_Registrar' THEN 1 ELSE 0 END) as registrar_stage
    FROM documents";
$pipeline = $conn->query($pipeline_query)->fetch_assoc();

// Top departments
$top_depts_query = "SELECT 
    dept.dept_name,
    dept.school,
    COUNT(*) as doc_count,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    JOIN departments dept ON u.department_id = dept.id
    WHERE $date_condition
    GROUP BY u.department_id
    ORDER BY doc_count DESC
    LIMIT 10";
$top_depts = $conn->query($top_depts_query);

$status_data = [
    'Approved' => $overview['approved'] ?? 0,
    'Pending' => $overview['pending_registrar'] ?? 0,
    'Rejected' => $overview['rejected'] ?? 0
];

// ── Exam Applications Summary (per unit row, ALL schools) ──
$sem_start_reg = date('Y') . '-01-01';
$exam_apps_reg = $conn->query("SELECT 
    u.full_name, u.reg_number as student_reg,
    dept.dept_name, dept.school,
    d.id as doc_id, d.module_type, d.status, d.upload_date,
    rrf.exam_month, rrf.exam_year,
    fu.unit_code, fu.unit_title
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    LEFT JOIN departments dept ON u.department_id = dept.id
    LEFT JOIN resit_retake_forms rrf ON rrf.document_id = d.id
    LEFT JOIN form_units fu ON fu.form_id = rrf.id
    WHERE d.module_type IN ('Resit','Retake','Special_Exam')
      AND d.upload_date >= '$sem_start_reg'
    ORDER BY dept.school, dept.dept_name, u.full_name, d.module_type, fu.unit_code");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Reports | Registrar Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #f59e0b;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #1e293b;
            --text-light: #64748b;
            --border: #e2e8f0;
            --shadow: 0 1px 3px rgba(0,0,0,0.1);
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --purple: #7c3aed;
            --blue: #3b82f6;
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
            background: linear-gradient(135deg, var(--primary) 0%, #d97706 100%);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 1.5rem;
        }
        .logo-text { color: white; }
        .logo-text h3 { font-size: 1.1rem; font-weight: 700; }
        .logo-text span { font-size: 0.75rem; color: rgba(255,255,255,0.6); }
        
        .current-view-box {
            margin: 16px; padding: 16px;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.2) 0%, rgba(245, 158, 11, 0.1) 100%);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 12px; color: white;
        }
        .current-view-box .label { font-size: 0.75rem; color: rgba(255,255,255,0.6); margin-bottom: 4px; text-transform: uppercase; }
        .current-view-box .value { font-size: 1.1rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 8px; }
        
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
        .admin-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary) 0%, #d97706 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; }
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
        .btn-export:hover { background: #d97706; }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
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
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            margin: 0 auto 10px;
        }
        .stat-icon.orange { background: #fef3c7; color: #d97706; }
        .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-icon.blue { background: #dbeafe; color: #2563eb; }
        .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        .stat-icon.red { background: #fee2e2; color: #dc2626; }
        .stat-icon.gray { background: #f1f5f9; color: #64748b; }
        .stat-value {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 4px;
        }
        .stat-label { font-size: 0.75rem; color: var(--text-light); }

        /* Pipeline */
        .pipeline-section {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        .pipeline-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        .pipeline-header h3 {
            font-size: 1.1rem;
            font-weight: 700;
        }
        .pipeline-header i { color: var(--primary); }
        .pipeline-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        .pipeline-card {
            background: linear-gradient(135deg, var(--bg) 0%, white 100%);
            border-radius: 12px;
            padding: 20px;
            border: 2px solid var(--border);
            text-align: center;
            position: relative;
        }
        .pipeline-card::after {
            content: '→';
            position: absolute;
            right: -20px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.5rem;
            color: var(--text-light);
            font-weight: 700;
        }
        .pipeline-card:last-child::after { display: none; }
        .pipeline-card.cod { border-color: #3b82f6; }
        .pipeline-card.dean { border-color: #7c3aed; }
        .pipeline-card.registrar { border-color: #f59e0b; }
        .pipeline-stage {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 8px;
        }
        .pipeline-card.cod .pipeline-stage { color: #3b82f6; }
        .pipeline-card.dean .pipeline-stage { color: #7c3aed; }
        .pipeline-card.registrar .pipeline-stage { color: #f59e0b; }
        .pipeline-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--text);
        }
        .pipeline-label {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-top: 4px;
        }

        /* Charts */
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

        /* Tables */
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
            background: var(--success);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

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

        @media (max-width: 1400px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .pipeline-grid { grid-template-columns: 1fr; }
            .pipeline-card::after { display: none; }
            .charts-grid { grid-template-columns: 1fr; }
            .tables-grid { grid-template-columns: 1fr; }
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
                        <i class="fa-solid fa-stamp"></i>
                    </div>
                    <div class="logo-text">
                        <h3>Registrar Portal</h3>
                        <span>Final Approval</span>
                    </div>
                </div>
            </div>

            <div class="current-view-box">
                <div class="label">Currently Viewing</div>
                <div class="value">
                    <i class="fa-solid fa-eye"></i>
                    Registrar View
                </div>
            </div>

            <?php if (isset($_SESSION['current_admin_view'])): ?>
                <a href="admin_dashboard.php?clear_view=1" class="btn-back">
                    <i class="fa-solid fa-arrow-left"></i>
                    Back to System Admin
                </a>
            <?php endif; ?>

            <nav class="nav-section">
                <div class="nav-title">Menu</div>
                
                <a href="registrar_dashboard.php" class="nav-item">
                    <i class="fa-solid fa-grid-2"></i>
                    <span>Dashboard</span>
                </a>
                
                <a href="registrar_documents.php" class="nav-item">
                    <i class="fa-solid fa-folder-open"></i>
                    <span>Students Applications</span>
                </a>
                
                <a href="registrar_reports.php" class="nav-item active">
                    <i class="fa-solid fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <div class="admin-info">
                    <div class="admin-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'R', 0, 1)); ?>
                    </div>
                    <div class="admin-details">
                        <div class="name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Registrar'); ?></div>
                        <div class="role">Academic Registrar</div>
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
                    <h1>University Reports</h1>
                    <p>Comprehensive analytics across all schools and departments</p>
                </div>
            </header>

            <div class="content">
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <div class="filter-group">
                        <label><i class="fa-solid fa-calendar"></i> Time Period:</label>
                        <select onchange="window.location.href='?range='+this.value">
                            <option value="7" <?php echo $date_range == '7' ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="30" <?php echo $date_range == '30' ? 'selected' : ''; ?>>Last 30 Days</option>
                            <option value="90" <?php echo $date_range == '90' ? 'selected' : ''; ?>>Last 3 Months</option>
                            <option value="365" <?php echo $date_range == '365' ? 'selected' : ''; ?>>Last Year</option>
                        </select>
                    </div>
                    <button class="btn-export" onclick="window.print()">
                        <i class="fa-solid fa-print"></i> Print Report
                    </button>
                </div>

                <!-- Overview Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon orange">
                            <i class="fa-solid fa-file-lines"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($overview['total_documents']); ?></div>
                        <div class="stat-label">Total Documents</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <i class="fa-solid fa-users"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($overview['total_students']); ?></div>
                        <div class="stat-label">Active Students</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple">
                            <i class="fa-solid fa-building"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($overview['active_departments']); ?></div>
                        <div class="stat-label">Departments</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <i class="fa-solid fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($overview['approved']); ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon gray">
                            <i class="fa-solid fa-clock"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($overview['pending_registrar']); ?></div>
                        <div class="stat-label">Pending You</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red">
                            <i class="fa-solid fa-times-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($overview['rejected']); ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                </div>

                <!-- Approval Pipeline -->
                <div class="pipeline-section">
                    <div class="pipeline-header">
                        <i class="fa-solid fa-route"></i>
                        <h3>Current Approval Pipeline</h3>
                    </div>
                    <div class="pipeline-grid">
                        <div class="pipeline-card cod">
                            <div class="pipeline-stage">Stage 1</div>
                            <div class="pipeline-value"><?php echo number_format($pipeline['cod_stage'] ?? 0); ?></div>
                            <div class="pipeline-label">At COD Review</div>
                        </div>
                        <div class="pipeline-card dean">
                            <div class="pipeline-stage">Stage 2</div>
                            <div class="pipeline-value"><?php echo number_format($pipeline['dean_stage'] ?? 0); ?></div>
                            <div class="pipeline-label">At Dean Review</div>
                        </div>
                        <div class="pipeline-card registrar">
                            <div class="pipeline-stage">Stage 3</div>
                            <div class="pipeline-value"><?php echo number_format($pipeline['registrar_stage'] ?? 0); ?></div>
                            <div class="pipeline-label">Pending Final Approval</div>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3 class="chart-title">
                                <i class="fa-solid fa-chart-line"></i>
                                Monthly Document Flow
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
                                <i class="fa-solid fa-university"></i>
                                School Performance
                            </h3>
                        </div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>School</th>
                                    <th>Students</th>
                                    <th>Docs</th>
                                    <th>Approved</th>
                                    <th>Success</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($school_data = $school_stats->fetch_assoc()): 
                                    $success_rate = $school_data['doc_count'] > 0 ? round(($school_data['approved'] / $school_data['doc_count']) * 100) : 0;
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($school_data['school_name']); ?></strong></td>
                                        <td><?php echo number_format($school_data['student_count']); ?></td>
                                        <td><?php echo number_format($school_data['doc_count']); ?></td>
                                        <td><?php echo number_format($school_data['approved']); ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <span><?php echo $success_rate; ?>%</span>
                                                <div class="progress-bar" style="width: 50px;">
                                                    <div class="progress-fill" style="width: <?php echo $success_rate; ?>%"></div>
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
                                    <th>Success</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($mod = $module_stats->fetch_assoc()): 
                                    $mod_success = $mod['count'] > 0 ? round(($mod['approved'] / $mod['count']) * 100) : 0;
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($mod['module_type']); ?></strong></td>
                                        <td><?php echo number_format($mod['count']); ?></td>
                                        <td><?php echo number_format($mod['approved']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $mod_success >= 80 ? 'badge-success' : 'badge-warning'; ?>">
                                                <?php echo $mod_success; ?>%
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                 <!-- ── Students Exam Applications Summary (All Schools) ── -->
                <div class="chart-card" style="margin-bottom:28px;">
                    <div class="chart-header" style="flex-wrap:wrap;gap:10px;">
                        <h3 class="chart-title">
                            <i class="fa-solid fa-table-list"></i>
                            Students Exam Applications – This Semester (All Schools)
                        </h3>
                        <button class="btn-export" onclick="window.print()" style="background:#f59e0b;color:#1e293b;">
                            <i class="fa-solid fa-print"></i> Print
                        </button>
                    </div>

                    <?php if ($exam_apps_reg && $exam_apps_reg->num_rows > 0):
                        $rows_by_doc_r = [];
                        while ($r = $exam_apps_reg->fetch_assoc()) $rows_by_doc_r[$r['doc_id']][] = $r;
                        $rn = 1;
                    ?>
                    <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student Name</th>
                                <th>Reg. Number</th>
                                <th>School</th>
                                <th>Department</th>
                                <th>Exam Type</th>
                                <th>Period</th>
                                <th>Unit Code</th>
                                <th>Unit Name</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows_by_doc_r as $did => $urows):
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
                                <td rowspan="<?php echo $uc; ?>" style="font-size:.8rem;"><?php echo htmlspecialchars($f['school']??'N/A'); ?></td>
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

                <!-- Top Departments -->
                <div class="table-card" style="margin-top: 24px;">
                    <div class="table-header-custom">
                        <h3 class="table-title-custom">
                            <i class="fa-solid fa-trophy"></i>
                            Top 10 Most Active Departments
                        </h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Department</th>
                                <th>School</th>
                                <th>Documents</th>
                                <th>Approved</th>
                                <th>Success Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rank = 1;
                            while ($dept = $top_depts->fetch_assoc()): 
                                $success_rate = $dept['doc_count'] > 0 ? round(($dept['approved'] / $dept['doc_count']) * 100) : 0;
                            ?>
                                <tr>
                                    <td><span style="font-weight: 800; color: var(--primary);">#<?php echo $rank++; ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($dept['dept_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($dept['school']); ?></td>
                                    <td><?php echo number_format($dept['doc_count']); ?></td>
                                    <td><?php echo number_format($dept['approved']); ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span><?php echo $success_rate; ?>%</span>
                                            <div class="progress-bar" style="width: 80px;">
                                                <div class="progress-fill" style="width: <?php echo $success_rate; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
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
                    label: 'Submitted',
                    data: <?php echo json_encode($trend_total); ?>,
                    backgroundColor: 'rgba(245, 158, 11, 0.8)',
                    borderRadius: 6
                }, {
                    label: 'Approved',
                    data: <?php echo json_encode($trend_approved); ?>,
                    backgroundColor: 'rgba(16, 185, 129, 0.8)',
                    borderRadius: 6
                }, {
                    label: 'Rejected',
                    data: <?php echo json_encode($trend_rejected); ?>,
                    backgroundColor: 'rgba(239, 68, 68, 0.8)',
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
                labels: ['Approved', 'Pending You', 'Rejected'],
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
    </script>
</body>
</html>