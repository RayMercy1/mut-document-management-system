<?php
session_start();
require_once 'db_config.php';
require_once 'audit_functions.php';

// Check if user is logged in and is registrar or super_admin in registrar view
if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php");
    exit();
}

// Allow if: super_admin in registrar view, OR actual registrar admin_role
$is_registrar_view = isset($_SESSION['current_admin_view']) && $_SESSION['current_admin_view'] === 'registrar';
$is_actual_registrar = ($_SESSION['role'] === 'admin' && $_SESSION['admin_role'] === 'registrar');

if (!$is_registrar_view && !($is_actual_registrar || $_SESSION['role'] === 'super_admin')) {
    logAccessDenied($conn, 'registrar_documents.php', 'Not registrar');
    header("Location: login.php");
    exit();
}

$reg_number = $_SESSION['reg_number'];

// Get filter parameters
$filter_status = $_GET['status'] ?? 'all';
$filter_module = $_GET['module'] ?? 'all';
$search = $_GET['search'] ?? '';
$filter_month = $_GET['month'] ?? 'all';

// Build query - Registrar sees ALL documents at Pending_Registrar status (all departments)
$where_clauses = ["d.status = 'Pending_Registrar'"];
$params = [];
$types = '';

if ($filter_module !== 'all') {
    $where_clauses[] = "d.module_type = ?";
    $params[] = $filter_module;
    $types .= 's';
}

if (!empty($search)) {
    $where_clauses[] = "(d.title LIKE ? OR u.full_name LIKE ? OR u.reg_number LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

if ($filter_month !== 'all') {
    $where_clauses[] = "EXISTS (SELECT 1 FROM resit_retake_forms rrf WHERE rrf.document_id = d.id AND rrf.exam_month = ?)";
    $params[] = $filter_month;
    $types .= 's';
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

// Main query
$query = "SELECT d.*, 
          u.full_name as student_name, 
          u.reg_number as student_reg,
          u.course,
          dept.dept_name,
          dept.school
          FROM documents d
          JOIN users u ON d.reg_number = u.reg_number
          LEFT JOIN departments dept ON u.department_id = dept.id
          $where_sql
          ORDER BY d.upload_date DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$documents = $stmt->get_result();

// Get unique modules for filter
$modules_query = "SELECT DISTINCT module_type FROM documents ORDER BY module_type";
$modules_result = $conn->query($modules_query);
$modules = [];
while ($row = $modules_result->fetch_assoc()) {
    $modules[] = $row['module_type'];
}

// Get statistics for registrar
$stats_query = "SELECT 
    COUNT(*) as total_pending,
    SUM(CASE WHEN module_type IN ('Bursary', 'Fees') THEN 1 ELSE 0 END) as finance_docs,
    SUM(CASE WHEN DATE(upload_date) = CURDATE() THEN 1 ELSE 0 END) as today_docs,
    SUM(CASE WHEN module_type = 'Bursary' THEN 1 ELSE 0 END) as bursary_docs,
    SUM(CASE WHEN module_type = 'Fees' THEN 1 ELSE 0 END) as fees_docs
    FROM documents WHERE status = 'Pending_Registrar'";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Build approved docs filter (reuses same filter inputs as pending)
$appr_where = ["d.status IN ('Finalised','Approved','Recommended','Not_Recommended','Forwarded_DVC','Special_Exam_Approved')"];
if ($filter_module !== 'all') $appr_where[] = "d.module_type = '" . $conn->real_escape_string($filter_module) . "'";
if (!empty($search)) { $s = $conn->real_escape_string("%$search%"); $appr_where[] = "(d.title LIKE '$s' OR u.full_name LIKE '$s' OR u.reg_number LIKE '$s')"; }
if ($filter_month !== 'all') $appr_where[] = "EXISTS (SELECT 1 FROM resit_retake_forms rrf WHERE rrf.document_id = d.id AND rrf.exam_month = '" . $conn->real_escape_string($filter_month) . "')";
$appr_where_sql = 'WHERE ' . implode(' AND ', $appr_where);

$approved_docs_q = "SELECT d.id, d.title, d.module_type, d.status, d.upload_date, u.full_name as student_name, u.reg_number as student_reg, dept.dept_name, dept.school FROM documents d JOIN users u ON d.reg_number = u.reg_number LEFT JOIN departments dept ON u.department_id = dept.id $appr_where_sql ORDER BY d.upload_date DESC";
$approved_docs = $conn->query($approved_docs_q);

// Helper function
function getStatusBadgeClass($status) {
    return match($status) {
        'Pending_Registrar' => 'status-registrar',
        'Approved' => 'status-approved',
        'Rejected' => 'status-rejected',
        default => 'status-default'
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Applications | Registrar Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #22c55e;
            --primary-dark: #16a34a;
            --secondary: #0f172a;
            --accent: #3b82f6;
            --purple: #7c3aed;
            --orange: #f59e0b;
            --danger: #ef4444;
            --success: #10b981;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #1e293b;
            --text-light: #64748b;
            --border: #e2e8f0;
            --shadow: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

        .layout { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--secondary);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
        }

        .sidebar-header {
            padding: 24px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--orange) 0%, #d97706 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .logo-text { color: white; }
        .logo-text h3 { font-size: 1.1rem; font-weight: 700; }
        .logo-text span { font-size: 0.75rem; color: rgba(255,255,255,0.6); }

        .current-view-box {
            margin: 16px;
            padding: 16px;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.2) 0%, rgba(245, 158, 11, 0.1) 100%);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 12px;
            color: white;
        }

        .current-view-box .label {
            font-size: 0.75rem;
            color: rgba(255,255,255,0.6);
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .current-view-box .value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--orange);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-section { padding: 20px 0; }
        
        .nav-title {
            padding: 8px 24px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(255,255,255,0.4);
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 24px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
        }

        .nav-item:hover, .nav-item.active {
            background: rgba(255,255,255,0.05);
            color: white;
            border-left-color: var(--orange);
        }

        .nav-item i { width: 20px; text-align: center; }

        .sidebar-footer {
            padding: 16px 24px;
            border-top: 1px solid rgba(255,255,255,0.1);
            position: absolute;
            bottom: 0;
            width: 100%;
        }

        .btn-back {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 0 16px 16px;
            padding: 10px;
            background: rgba(255,255,255,0.1);
            border: 1px dashed rgba(255,255,255,0.3);
            border-radius: 8px;
            color: rgba(255,255,255,0.7);
            font-size: 0.85rem;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-back:hover {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .admin-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--orange) 0%, #d97706 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
        }

        .admin-details .name { color: white; font-weight: 600; font-size: 0.9rem; }
        .admin-details .role { color: rgba(255,255,255,0.5); font-size: 0.75rem; text-transform: uppercase; }

        .btn-logout {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px;
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-logout:hover { background: rgba(239, 68, 68, 0.3); }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
        }

        /* Header */
        .header {
            background: var(--card);
            padding: 20px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .page-title h1 { font-size: 1.75rem; font-weight: 800; color: var(--text); }
        .page-title p { font-size: 0.875rem; color: var(--text-light); margin-top: 4px; }

        /* Content */
        .content { padding: 32px; }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .stat-icon.orange { background: #fef3c7; color: #d97706; }
        .stat-icon.blue { background: #dbeafe; color: #2563eb; }
        .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-icon.purple { background: #ede9fe; color: #7c3aed; }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--text-light);
        }

        /* Filters */
        .filters-section {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }

        .filter-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-light);
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: 0.9rem;
            font-family: inherit;
            background: white;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            border-color: var(--orange);
            outline: none;
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
        }

        .btn-primary {
            background: var(--orange);
            color: white;
        }

        .btn-primary:hover {
            background: #d97706;
        }

        .btn-secondary {
            background: var(--bg);
            color: var(--text);
            border: 2px solid var(--border);
        }

        /* Documents Table */
        .documents-section {
            background: var(--card);
            border-radius: 16px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .table-header {
            padding: 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-size: 1.25rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .table-title i { color: var(--orange); }

        .documents-table-wrapper { overflow-x: auto; }

        .documents-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .documents-table th,
        .documents-table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .documents-table th {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-light);
            background: var(--bg);
        }

        .documents-table tbody tr:hover { background: rgba(241, 245, 249, 0.5); }

        .doc-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .doc-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .doc-icon.pdf { background: #fee2e2; color: #dc2626; }

        .doc-details h4 {
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--text);
            font-size: 0.95rem;
        }

        .doc-details .module-type {
            font-size: 0.75rem;
            color: var(--text-light);
            background: var(--bg);
            padding: 2px 8px;
            border-radius: 4px;
        }

        .student-info {
            display: flex;
            flex-direction: column;
        }

        .student-name {
            font-weight: 600;
            color: var(--text);
            font-size: 0.95rem;
        }

        .student-reg {
            font-size: 0.8rem;
            color: var(--text-light);
            font-family: monospace;
        }

        .school-dept {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .school-name {
            font-weight: 600;
            color: var(--text);
            font-size: 0.9rem;
        }

        .dept-name {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-registrar { background: #fef3c7; color: #92400e; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }

        .action-btns {
            display: flex;
            gap: 8px;
        }

        .btn-action {
            padding: 8px 14px;
            border: none;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-view {
            background: var(--bg);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-view:hover {
            background: var(--border);
        }

        .btn-approve {
            background: var(--success);
            color: white;
        }

        .btn-approve:hover {
            background: #059669;
        }

        .btn-reject {
            background: var(--danger);
            color: white;
        }

        .btn-reject:hover {
            background: #dc2626;
        }

        .empty-state {
            text-align: center;
            padding: 80px 32px;
        }

        .empty-state-icon {
            width: 100px;
            height: 100px;
            background: var(--bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            color: var(--text-light);
            font-size: 2.5rem;
        }

        .urgent-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            background: #fee2e2;
            color: #dc2626;
            font-size: 0.7rem;
            font-weight: 600;
            border-radius: 4px;
            margin-left: 8px;
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
                
                <a href="registrar_documents.php" class="nav-item active">
                    <i class="fa-solid fa-folder-open"></i>
                    <span>Student Applications</span>
                </a>
                
                <a href="registrar_reports.php" class="nav-item">
                    <i class="fa-solid fa-chart-bar"></i>
                    <span>My Reports</span>
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
                    <h1>Student Applications</h1>
                    <p>Documents awaiting final approval from all departments</p>
                </div>
            </header>

            <div class="content">
                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon orange">
                                <i class="fa-solid fa-clock"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_pending']; ?></div>
                        <div class="stat-label">Pending Approval</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon blue">
                                <i class="fa-solid fa-money-bill-wave"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo $stats['finance_docs']; ?></div>
                        <div class="stat-label">Finance Documents</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon purple">
                                <i class="fa-solid fa-graduation-cap"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo $stats['bursary_docs']; ?></div>
                        <div class="stat-label">Bursary Documents</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon green">
                                <i class="fa-solid fa-calendar-day"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?php echo $stats['today_docs']; ?></div>
                        <div class="stat-label">Today's Submissions</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-section">
                    <form method="GET" action="">
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label>Module Type</label>
                                <select name="module">
                                    <option value="all" <?php echo $filter_module === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <?php foreach ($modules as $module): ?>
                                        <option value="<?php echo htmlspecialchars($module); ?>" <?php echo $filter_module === $module ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($module); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="filter-group">
                                <label>Search</label>
                                <input type="text" name="search" placeholder="Search by title, student name, or reg..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="filter-group">
                                <label>Month</label>
                                <select name="month">
                                    <option value="all" <?php echo $filter_month === 'all' ? 'selected' : ''; ?>>All Months</option>
                                    <option value="April" <?php echo $filter_month === 'April' ? 'selected' : ''; ?>>April</option>
                                    <option value="August" <?php echo $filter_month === 'August' ? 'selected' : ''; ?>>August</option>
                                    <option value="December" <?php echo $filter_month === 'December' ? 'selected' : ''; ?>>December</option>
                                </select>
                            </div>
                        </div>

                        <div class="filter-actions">
                            <a href="registrar_documents.php" class="btn btn-secondary">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-filter"></i> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Approved Documents Section -->
                <div class="documents-section" style="margin-bottom:28px;">
                    <div class="table-header" style="display:flex;justify-content:space-between;align-items:center;">
                        <h2 class="table-title">
                            <i class="fa-solid fa-circle-check" style="color:#22c55e;"></i>
                            Approved Documents
                            <span style="font-size:0.8rem;font-weight:500;color:var(--text-light);margin-left:8px;background:var(--bg);padding:4px 12px;border-radius:20px;">
                                <?php echo $approved_docs ? $approved_docs->num_rows : 0; ?> records
                            </span>
                        </h2>
                        <button onclick="printApprovedList()" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:#22c55e;color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">
                            <i class="fa-solid fa-print"></i> Print List
                        </button>
                    </div>
                    <?php if ($approved_docs && $approved_docs->num_rows > 0): ?>
                        <div class="documents-table-wrapper">
                            <table class="documents-table" id="registrarApprovedTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Document</th>
                                        <th>Student</th>
                                        <th>School &amp; Department</th>
                                        <th>Type</th>
                                        <th>Date Approved</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $appr_num = 1; while ($adoc = $approved_docs->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo $appr_num++; ?></td>
                                            <td>
                                                <div class="doc-details">
                                                    <h4><?php echo htmlspecialchars($adoc['title']); ?></h4>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="student-info">
                                                    <span class="student-name"><?php echo htmlspecialchars($adoc['student_name']); ?></span>
                                                    <span class="student-reg"><?php echo htmlspecialchars($adoc['student_reg']); ?></span>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars(($adoc['school'] ?? '') . ' – ' . ($adoc['dept_name'] ?? 'N/A')); ?></td>
                                            <td><span class="module-type"><?php echo htmlspecialchars($adoc['module_type']); ?></span></td>
                                            <td><?php echo date('M d, Y', strtotime($adoc['upload_date'])); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fa-solid fa-inbox"></i></div>
                            <h3>No Approved Documents Yet</h3>
                            <p>Documents you finalise will appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Documents Table -->
                <div class="documents-section">
                    <div class="table-header">
                        <h2 class="table-title">
                            <i class="fa-solid fa-file-lines"></i>
                            Documents Awaiting Final Approval
                            <span style="font-size: 0.8rem; font-weight: 500; color: var(--text-light); margin-left: 8px; background: var(--bg); padding: 4px 12px; border-radius: 20px;">
                                <?php echo $documents->num_rows; ?> pending
                            </span>
                        </h2>
                    </div>

                    <?php if ($documents->num_rows > 0): ?>
                        <div class="documents-table-wrapper">
                            <table class="documents-table">
                                <thead>
                                    <tr>
                                        <th>Document</th>
                                        <th>Student</th>
                                        <th>School & Department</th>
                                        <th>Submitted</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($doc = $documents->fetch_assoc()): 
                                        $days_pending = floor((time() - strtotime($doc['upload_date'])) / (60 * 60 * 24));
                                        $is_urgent = $days_pending > 7;
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="doc-info">
                                                    <div class="doc-icon pdf">
                                                        <i class="fa-solid fa-file-pdf"></i>
                                                    </div>
                                                    <div class="doc-details">
                                                        <h4>
                                                            <?php echo htmlspecialchars($doc['title']); ?>
                                                            <?php if ($is_urgent): ?>
                                                                <span class="urgent-badge">
                                                                    <i class="fa-solid fa-exclamation"></i> <?php echo $days_pending; ?> days
                                                                </span>
                                                            <?php endif; ?>
                                                        </h4>
                                                        <span class="module-type"><?php echo htmlspecialchars($doc['module_type']); ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="student-info">
                                                    <span class="student-name"><?php echo htmlspecialchars($doc['student_name']); ?></span>
                                                    <span class="student-reg"><?php echo htmlspecialchars($doc['student_reg']); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="school-dept">
                                                    <?php if (!empty($doc['school'])): ?>
                                                        <span class="school-name">
                                                            <i class="fa-solid fa-university" style="color: var(--orange); margin-right: 4px;"></i>
                                                            <?php echo htmlspecialchars($doc['school']); ?>
                                                        </span>
                                                        <span class="dept-name">
                                                            <?php echo htmlspecialchars($doc['dept_name'] ?? 'N/A'); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="color: #94a3b8;">Not assigned</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div style="display: flex; flex-direction: column;">
                                                    <span><?php echo date('M d, Y', strtotime($doc['upload_date'])); ?></span>
                                                    <span style="font-size: 0.75rem; color: var(--text-light);">
                                                        <?php echo $days_pending; ?> days ago
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="action-btns">
                                                    <a href="view_form.php?id=<?php echo $doc['id']; ?>" class="btn-action btn-view" style="background:#f59e0b;color:white;border:none;font-weight:700;">
                                                        <i class="fa-solid fa-eye"></i> View &amp; Finalise
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fa-solid fa-check-circle"></i>
                            </div>
                            <h3>All Caught Up!</h3>
                            <p>No documents are currently awaiting your final approval.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
<script>
function printApprovedList() {
    const table = document.getElementById('registrarApprovedTable');
    if (!table) { alert('No approved documents to print.'); return; }
    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>Approved Documents — Registrar</title>
    <style>
        body{font-family:Inter,sans-serif;padding:32px;color:#1e293b}
        h1{font-size:1.3rem;font-weight:800;margin-bottom:4px}
        p.sub{font-size:.85rem;color:#64748b;margin-bottom:20px}
        table{width:100%;border-collapse:collapse;font-size:.875rem}
        th{background:#f1f5f9;padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0;font-weight:700}
        td{padding:9px 12px;border-bottom:1px solid #e2e8f0}
        tr:last-child td{border-bottom:none}
        @media print{body{padding:16px}}
    </style></head><body>
    <h1>Approved Documents List</h1>
    <p class="sub">Printed: ${new Date().toLocaleDateString('en-KE',{day:'numeric',month:'long',year:'numeric'})}</p>
    ${table.outerHTML}
    </body></html>`);
    win.document.close();
    setTimeout(() => { win.print(); win.close(); }, 400);
}
</script>
</body>
</html>