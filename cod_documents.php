<?php
session_start();
require_once 'db_config.php';
require_once 'audit_functions.php';

// Check access
if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php");
    exit();
}

$is_cod_view = isset($_SESSION['current_admin_view']) && $_SESSION['current_admin_view'] === 'cod';
$is_actual_cod = ($_SESSION['role'] === 'admin' && $_SESSION['admin_role'] === 'cod');

if (!$is_cod_view && !$is_actual_cod && $_SESSION['role'] !== 'super_admin') {
    logAccessDenied($conn, 'cod_documents.php', 'Not COD');
    header("Location: login.php");
    exit();
}

// Get department ID
$dept_id = $_GET['dept'] ?? $_SESSION['selected_department'] ?? null;
if (!$dept_id && $is_actual_cod) {
    $dept_query = "SELECT department_id FROM users WHERE reg_number = ?";
    $stmt = $conn->prepare($dept_query);
    $stmt->bind_param("s", $_SESSION['reg_number']);
    $stmt->execute();
    $dept_id = $stmt->get_result()->fetch_assoc()['department_id'];
}

// Get department info
$dept_info = $conn->query("SELECT * FROM departments WHERE id = $dept_id")->fetch_assoc();

// Get filter parameters
$filter_module = $_GET['module'] ?? 'all';
$search = $_GET['search'] ?? '';
$filter_month = $_GET['month'] ?? 'all';

// Build query - COD sees only their department's documents at Pending_COD status
$where_clauses = ["d.status = 'Pending_COD'", "u.department_id = $dept_id"];
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

$query = "SELECT d.*, 
          u.full_name as student_name, 
          u.reg_number as student_reg,
          u.course
          FROM documents d
          JOIN users u ON d.reg_number = u.reg_number
          $where_sql
          ORDER BY d.upload_date DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$documents = $stmt->get_result();

// Get modules for filter
$modules_query = "SELECT DISTINCT module_type FROM documents ORDER BY module_type";
$modules = $conn->query($modules_query);

// Stats
$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN DATE(upload_date) = CURDATE() THEN 1 ELSE 0 END) as today
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    WHERE d.status = 'Pending_COD' AND u.department_id = $dept_id";
$stats = $conn->query($stats_query)->fetch_assoc();

// Build approved docs filter (reuses same filter inputs as pending)
$appr_where = ["d.status IN ('Approved_COD','Pending_Dean','Pending_Registrar','Approved','Finalised','Recommended','Not_Recommended','Forwarded_DVC','Special_Exam_Approved')", "u.department_id = $dept_id"];
if ($filter_module !== 'all') $appr_where[] = "d.module_type = '" . $conn->real_escape_string($filter_module) . "'";
if (!empty($search)) { $s = $conn->real_escape_string("%$search%"); $appr_where[] = "(d.title LIKE '$s' OR u.full_name LIKE '$s' OR u.reg_number LIKE '$s')"; }
if ($filter_month !== 'all') $appr_where[] = "EXISTS (SELECT 1 FROM resit_retake_forms rrf WHERE rrf.document_id = d.id AND rrf.exam_month = '" . $conn->real_escape_string($filter_month) . "')";
$appr_where_sql = 'WHERE ' . implode(' AND ', $appr_where);

$approved_count_query = "SELECT COUNT(*) as approved FROM documents d JOIN users u ON d.reg_number = u.reg_number $appr_where_sql";
$approved_count = $conn->query($approved_count_query)->fetch_assoc();
$stats['approved'] = $approved_count['approved'] ?? 0;

$approved_docs_query = "SELECT d.id, d.title, d.module_type, d.status, d.upload_date, u.full_name as student_name, u.reg_number as student_reg, u.course FROM documents d JOIN users u ON d.reg_number = u.reg_number $appr_where_sql ORDER BY d.upload_date DESC";
$approved_docs = $conn->query($approved_docs_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Documents | COD Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
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

        /* Sidebar - Same structure as cod_dashboard.php */
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
            background: linear-gradient(135deg, var(--primary) 0%, #2563eb 100%);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 1.5rem;
        }
        .logo-text { color: white; }
        .logo-text h3 { font-size: 1.1rem; font-weight: 700; }
        .logo-text span { font-size: 0.75rem; color: rgba(255,255,255,0.6); }
        
        .current-view-box {
            margin: 16px; padding: 16px;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.2) 0%, rgba(59, 130, 246, 0.1) 100%);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 12px; color: white;
        }
        .current-view-box .label { font-size: 0.75rem; color: rgba(255,255,255,0.6); margin-bottom: 4px; text-transform: uppercase; }
        .current-view-box .value { font-size: 1.1rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 8px; }
        .current-view-box .dept { font-size: 0.85rem; color: rgba(255,255,255,0.8); margin-top: 6px; padding-top: 6px; border-top: 1px solid rgba(255,255,255,0.1); }
        
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
        .admin-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary) 0%, #2563eb 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; }
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

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: var(--card); border-radius: 16px; padding: 24px;
            box-shadow: var(--shadow); border: 1px solid var(--border);
            text-align: center;
        }
        .stat-icon { width: 56px; height: 56px; border-radius: 16px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.25rem; }
        .stat-icon.blue { background: #dbeafe; color: #2563eb; }
        .stat-value { font-size: 2.5rem; font-weight: 800; color: var(--text); margin-bottom: 4px; }
        .stat-label { font-size: 0.875rem; color: var(--text-light); }

        /* Filters */
        .filters-section {
            background: var(--card); border-radius: 16px; padding: 24px;
            margin-bottom: 24px; box-shadow: var(--shadow); border: 1px solid var(--border);
        }
        .filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .filter-group label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-light); margin-bottom: 8px; text-transform: uppercase; }
        .filter-group select, .filter-group input {
            width: 100%; padding: 10px 14px; border: 2px solid var(--border);
            border-radius: 10px; font-size: 0.9rem; font-family: inherit; background: white;
        }
        .filter-group select:focus, .filter-group input:focus { border-color: var(--primary); outline: none; }
        .filter-actions { display: flex; gap: 12px; justify-content: flex-end; }
        .btn {
            padding: 10px 20px; border-radius: 10px; font-size: 0.9rem; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease; text-decoration: none;
            display: inline-flex; align-items: center; gap: 8px; border: none;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: #2563eb; }
        .btn-secondary { background: var(--bg); color: var(--text); border: 2px solid var(--border); }
        .btn-secondary:hover { background: var(--border); }

        /* Table */
        .documents-section {
            background: var(--card); border-radius: 16px;
            box-shadow: var(--shadow); border: 1px solid var(--border); overflow: hidden;
        }
        .table-header { padding: 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .table-title { font-size: 1.25rem; font-weight: 700; display: flex; align-items: center; gap: 12px; }
        .table-title i { color: var(--primary); }
        .documents-table-wrapper { overflow-x: auto; }
        .documents-table { width: 100%; border-collapse: collapse; min-width: 900px; }
        .documents-table th, .documents-table td { padding: 16px; text-align: left; border-bottom: 1px solid var(--border); }
        .documents-table th { font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-light); background: var(--bg); }
        .documents-table tbody tr:hover { background: rgba(241, 245, 249, 0.5); }

        .doc-info { display: flex; align-items: center; gap: 12px; }
        .doc-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
        .doc-icon.pdf { background: #fee2e2; color: #dc2626; }
        .doc-icon.doc { background: #dbeafe; color: #2563eb; }
        .doc-details h4 { font-weight: 600; margin-bottom: 4px; color: var(--text); font-size: 0.95rem; }
        .doc-details .module-type { font-size: 0.75rem; color: var(--text-light); background: var(--bg); padding: 2px 8px; border-radius: 4px; }

        .student-info { display: flex; flex-direction: column; }
        .student-name { font-weight: 600; color: var(--text); font-size: 0.95rem; }
        .student-reg { font-size: 0.8rem; color: var(--text-light); font-family: monospace; }

        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .status-cod { background: #dbeafe; color: #1e40af; }

        .action-btns { display: flex; gap: 8px; }
        .btn-action {
            padding: 8px 14px; border: none; border-radius: 8px; font-size: 0.8rem; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
        }
        .btn-view { background: var(--bg); color: var(--text); border: 1px solid var(--border); }
        .btn-view:hover { background: var(--border); }
        .btn-forward { background: var(--primary); color: white; }
        .btn-forward:hover { background: #2563eb; }

        .empty-state { text-align: center; padding: 80px 32px; }
        .empty-state-icon { width: 100px; height: 100px; background: var(--bg); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; color: var(--text-light); font-size: 2.5rem; }
    </style>
</head>
<body>
    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                    <div class="logo-text">
                        <h3>COD Portal</h3>
                        <span>Department Head</span>
                    </div>
                </div>
            </div>

            <div class="current-view-box">
                <div class="label">Currently Viewing</div>
                <div class="value">
                    <i class="fa-solid fa-eye"></i>
                    COD View
                </div>
                <?php if ($dept_info): ?>
                    <div class="dept">
                        <i class="fa-solid fa-building"></i>
                        <?php echo htmlspecialchars($dept_info['dept_name']); ?>
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
                
                <a href="cod_dashboard.php?dept=<?php echo $dept_id; ?>" class="nav-item">
                    <i class="fa-solid fa-grid-2"></i>
                    <span>Dashboard</span>
                </a>
                
                <a href="cod_documents.php?dept=<?php echo $dept_id; ?>" class="nav-item active">
                    <i class="fa-solid fa-folder-open"></i>
                    <span>Students Applications</span>
                </a>
                
                <a href="cod_reports.php?dept=<?php echo $dept_id; ?>" class="nav-item">
                    <i class="fa-solid fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <div class="admin-info">
                    <div class="admin-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'C', 0, 1)); ?>
                    </div>
                    <div class="admin-details">
                        <div class="name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'COD'); ?></div>
                        <div class="role">Head of Department</div>
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
                    <h1>Students Applications</h1>
                    <p>Documents from <?php echo htmlspecialchars($dept_info['dept_name'] ?? 'your department'); ?> awaiting review</p>
                </div>
                <?php
                $cod_display_name = $_SESSION['cod_logged_in_name'] ?? ($is_actual_cod ? ($_SESSION['full_name'] ?? '') : '');
                $cod_display_reg  = $_SESSION['cod_logged_in_reg']  ?? ($is_actual_cod ? ($_SESSION['reg_number'] ?? '') : '');
                if ($cod_display_name): ?>
                <div style="display:flex;align-items:center;gap:14px;background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:14px;padding:10px 20px;">
                    <div style="width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6 0%,#2563eb 100%);display:flex;align-items:center;justify-content:center;color:white;font-size:1.25rem;flex-shrink:0;">
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
                        <div class="stat-icon blue">
                            <i class="fa-solid fa-inbox"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['approved']; ?></div>
                        <div class="stat-label">Approved Documents</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-section">
                    <form method="GET" action="">
                        <input type="hidden" name="dept" value="<?php echo $dept_id; ?>">
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label>Module Type</label>
                                <select name="module">
                                    <option value="all" <?php echo $filter_module === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="Resit" <?php echo $filter_module === 'Resit' ? 'selected' : ''; ?>>Resit</option>
                                    <option value="Retake" <?php echo $filter_module === 'Retake' ? 'selected' : ''; ?>>Retake</option>
                                    <option value="Special_Exam" <?php echo $filter_module === 'Special_Exam' ? 'selected' : ''; ?>>Special</option>
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
                            <a href="cod_documents.php?dept=<?php echo $dept_id; ?>" class="btn btn-secondary">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-filter"></i> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Approved Documents Section -->
                <div class="documents-section" id="approvedSection" style="margin-bottom:28px;">
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
                        <div class="documents-table-wrapper" id="approvedTableWrap">
                            <table class="documents-table" id="approvedTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Document</th>
                                        <th>Student</th>
                                        <th>Course</th>
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
                                            <td><?php echo htmlspecialchars($adoc['course'] ?? 'N/A'); ?></td>
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
                            <p>Documents you approve will appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Documents Table -->
                <div class="documents-section">
                    <div class="table-header">
                        <h2 class="table-title">
                            <i class="fa-solid fa-file-lines"></i>
                            Pending Documents
                            <span style="font-size: 0.8rem; font-weight: 500; color: var(--text-light); margin-left: 8px; background: var(--bg); padding: 4px 12px; border-radius: 20px;">
                                <?php echo $documents->num_rows; ?> records
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
                                        <th>Course</th>
                                        <th>Submitted</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($doc = $documents->fetch_assoc()): 
                                        $file_ext = pathinfo($doc['file_name'] ?? '', PATHINFO_EXTENSION);
                                        $icon_class = in_array($file_ext, ['pdf']) ? 'pdf' : 'doc';
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="doc-info">
                                                    <div class="doc-icon <?php echo $icon_class; ?>">
                                                        <i class="fa-solid fa-file<?php echo $icon_class === 'pdf' ? '-pdf' : ''; ?>"></i>
                                                    </div>
                                                    <div class="doc-details">
                                                        <h4><?php echo htmlspecialchars($doc['title']); ?></h4>
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
                                            <td><?php echo htmlspecialchars($doc['course'] ?? 'N/A'); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($doc['upload_date'])); ?></td>
                                            <td>
                                                <span class="status-badge status-cod">
                                                    <i class="fa-solid fa-clock"></i> Pending COD
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-btns">
                                                    <a href="view_form.php?id=<?php echo $doc['id']; ?>" class="btn-action btn-view">
                                                        <i class="fa-solid fa-eye"></i> View & Act
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
                            <h3>No Pending Documents</h3>
                            <p>No documents from your department are awaiting Your review.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
<script>
function printApprovedList() {
    const table = document.getElementById('approvedTable');
    if (!table) { alert('No approved documents to print.'); return; }
    const dept  = document.querySelector('.current-dept')?.innerText || '';
    const win   = window.open('', '_blank', 'width=900,height=700');
    win.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>Approved Documents — COD</title>
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