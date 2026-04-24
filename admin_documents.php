<?php
session_start();
require_once 'db_config.php';
require_once 'audit_functions.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['reg_number']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super_admin')) {
    header("Location: login.php");
    exit();
}

$reg_number = $_SESSION['reg_number'];
$user_role = $_SESSION['role'];

// Get filter parameters
$filter_status = $_GET['status'] ?? 'all';
$filter_school = $_GET['school'] ?? 'all';
$filter_module = $_GET['module'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query
$where_clauses = [];
$params = [];
$types = '';

if ($filter_status !== 'all') {
    $where_clauses[] = "d.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

if ($filter_module !== 'all') {
    $where_clauses[] = "d.module_type = ?";
    $params[] = $filter_module;
    $types .= 's';
}

if ($filter_school !== 'all') {
    $where_clauses[] = "dept.school = ?";
    $params[] = $filter_school;
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

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Main query to fetch all documents with student and department info
$query = "SELECT d.*, 
          u.full_name as student_name, 
          u.reg_number as student_reg,
          u.course,
          dept.dept_name,
          dept.school,
          dept.id as dept_id
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

// Get unique schools for filter
$schools_query = "SELECT DISTINCT school FROM departments WHERE school != '' ORDER BY school";
$schools_result = $conn->query($schools_query);
$schools = [];
while ($row = $schools_result->fetch_assoc()) {
    $schools[] = $row['school'];
}

// Get unique modules for filter
$modules_query = "SELECT DISTINCT module_type FROM documents ORDER BY module_type";
$modules_result = $conn->query($modules_query);
$modules = [];
while ($row = $modules_result->fetch_assoc()) {
    $modules[] = $row['module_type'];
}

// Get statistics
$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'Pending_COD' THEN 1 ELSE 0 END) as at_cod,
    SUM(CASE WHEN status = 'Pending_Dean' THEN 1 ELSE 0 END) as at_dean,
    SUM(CASE WHEN status = 'Pending_Registrar' THEN 1 ELSE 0 END) as at_registrar,
    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected
    FROM documents";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Helper function to get workflow stage
function getWorkflowStage($status) {
    $stages = [
        'Pending_COD' => ['stage' => 1, 'label' => 'At COD', 'color' => '#3b82f6'],
        'Pending_Dean' => ['stage' => 2, 'label' => 'At Dean', 'color' => '#7c3aed'],
        'Pending_Registrar' => ['stage' => 3, 'label' => 'At Registrar', 'color' => '#f59e0b'],
        'Approved' => ['stage' => 4, 'label' => 'Approved', 'color' => '#10b981'],
        'Rejected' => ['stage' => 0, 'label' => 'Rejected', 'color' => '#ef4444']
    ];
    return $stages[$status] ?? ['stage' => 0, 'label' => 'Unknown', 'color' => '#64748b'];
}

// Helper function to get status badge class
function getStatusBadgeClass($status) {
    return match($status) {
        'Pending_COD' => 'status-cod',
        'Pending_Dean' => 'status-dean',
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
    <title>All Documents | Admin Portal</title>
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
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
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
            border-left-color: var(--primary);
        }

        .nav-item i { width: 20px; text-align: center; }

        .sidebar-footer {
            padding: 16px 24px;
            border-top: 1px solid rgba(255,255,255,0.1);
            position: absolute;
            bottom: 0;
            width: 100%;
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
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
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
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--card);
            border-radius: 16px;
            padding: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            text-align: center;
            transition: all 0.2s ease;
        }

        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .stat-value.total { color: var(--text); }
        .stat-value.cod { color: #3b82f6; }
        .stat-value.dean { color: #7c3aed; }
        .stat-value.registrar { color: #f59e0b; }
        .stat-value.approved { color: #10b981; }
        .stat-value.rejected { color: #ef4444; }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-light);
            font-weight: 600;
            text-transform: uppercase;
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            transition: all 0.2s ease;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            border-color: var(--primary);
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
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--bg);
            color: var(--text);
            border: 2px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--border);
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

        .table-title i { color: var(--primary); }

        .documents-table-wrapper { overflow-x: auto; }

        .documents-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
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
            white-space: nowrap;
        }

        .documents-table tbody tr:hover { background: rgba(241, 245, 249, 0.5); }

        /* Document Info */
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
            flex-shrink: 0;
        }

        .doc-icon.pdf { background: #fee2e2; color: #dc2626; }
        .doc-icon.doc { background: #dbeafe; color: #2563eb; }
        .doc-icon.img { background: #d1fae5; color: #059669; }

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
            display: inline-block;
        }

        /* Student Info */
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

        /* School & Department */
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

        .na-text {
            color: #94a3b8;
            font-style: italic;
        }

        /* Workflow Progress */
        .workflow-progress {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .workflow-step {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            position: relative;
        }

        .workflow-step.completed {
            background: var(--success);
            color: white;
        }

        .workflow-step.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
            animation: pulse 2s infinite;
        }

        .workflow-step.pending {
            background: var(--border);
            color: var(--text-light);
        }

        .workflow-step.rejected {
            background: var(--danger);
            color: white;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .workflow-connector {
            width: 20px;
            height: 2px;
            background: var(--border);
        }

        .workflow-connector.completed {
            background: var(--success);
        }

        .workflow-label {
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 6px;
            text-align: center;
            white-space: nowrap;
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-cod { background: #dbeafe; color: #1e40af; }
        .status-dean { background: #ede9fe; color: #5b21b6; }
        .status-registrar { background: #fef3c7; color: #92400e; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-default { background: var(--bg); color: var(--text-light); }

        /* Action Buttons */
        .action-btns {
            display: flex;
            gap: 8px;
        }

        .btn-action {
            padding: 8px 12px;
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

        /* Empty State */
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

        .empty-state h3 {
            font-size: 1.5rem;
            color: var(--text);
            margin-bottom: 8px;
        }

        .empty-state p {
            color: var(--text-light);
            font-size: 1rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
        }

        /* Tooltip */
        .tooltip {
            position: relative;
        }

        .tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 6px 10px;
            background: var(--secondary);
            color: white;
            font-size: 0.75rem;
            border-radius: 6px;
            white-space: nowrap;
            z-index: 10;
            margin-bottom: 6px;
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
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div class="logo-text">
                        <h3>Admin Portal</h3>
                        <span>MUT Documents</span>
                    </div>
                </div>
            </div>

            <nav class="nav-section">
                <div class="nav-title">Menu</div>
                
                <a href="admin_dashboard.php" class="nav-item">
                    <i class="fa-solid fa-grid-2"></i>
                    <span>Dashboard</span>
                </a>
                
                <a href="admin_documents.php" class="nav-item active">
                    <i class="fa-solid fa-folder-open"></i>
                    <span>All Documents</span>
                </a>
                
                <a href="reports.php" class="nav-item">
                    <i class="fa-solid fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
                
                <?php if ($user_role === 'super_admin'): ?>
                    <a href="admin_users.php" class="nav-item">
                        <i class="fa-solid fa-users"></i>
                        <span>Manage Users</span>
                    </a>
                    
                    <a href="admin_departments.php" class="nav-item">
                        <i class="fa-solid fa-building"></i>
                        <span>Departments</span>
                    </a>
                    
                    <a href="admin_audit.php" class="nav-item">
                        <i class="fa-solid fa-list-check"></i>
                        <span>Audit Logs</span>
                    </a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <div class="admin-info">
                    <div class="admin-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)); ?>
                    </div>
                    <div class="admin-details">
                        <div class="name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></div>
                        <div class="role"><?php echo ucfirst(str_replace('_', ' ', $user_role)); ?></div>
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
            <!-- Header -->
            <header class="header">
                <div class="page-title">
                    <h1>All Documents</h1>
                    <p>View and track all documents in the system</p>
                </div>
            </header>

            <!-- Content -->
            <div class="content">
                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value total"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total Documents</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value cod"><?php echo $stats['at_cod']; ?></div>
                        <div class="stat-label">At COD</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value dean"><?php echo $stats['at_dean']; ?></div>
                        <div class="stat-label">At Dean</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value registrar"><?php echo $stats['at_registrar']; ?></div>
                        <div class="stat-label">At Registrar</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value approved"><?php echo $stats['approved']; ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value rejected"><?php echo $stats['rejected']; ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-section">
                    <form method="GET" action="">
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label>Status</label>
                                <select name="status">
                                    <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    <option value="Pending_COD" <?php echo $filter_status === 'Pending_COD' ? 'selected' : ''; ?>>Pending COD</option>
                                    <option value="Pending_Dean" <?php echo $filter_status === 'Pending_Dean' ? 'selected' : ''; ?>>Pending Dean</option>
                                    <option value="Pending_Registrar" <?php echo $filter_status === 'Pending_Registrar' ? 'selected' : ''; ?>>Pending Registrar</option>
                                    <option value="Approved" <?php echo $filter_status === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="Rejected" <?php echo $filter_status === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>

                            <div class="filter-group">
                                <label>School</label>
                                <select name="school">
                                    <option value="all" <?php echo $filter_school === 'all' ? 'selected' : ''; ?>>All Schools</option>
                                    <?php foreach ($schools as $school): ?>
                                        <option value="<?php echo htmlspecialchars($school); ?>" <?php echo $filter_school === $school ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($school); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

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
                        </div>

                        <div class="filter-actions">
                            <a href="admin_documents.php" class="btn btn-secondary">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-filter"></i> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Documents Table -->
                <div class="documents-section">
                    <div class="table-header">
                        <h2 class="table-title">
                            <i class="fa-solid fa-file-lines"></i>
                            Documents List
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
                                        <th>School & Department</th>
                                        <th>Workflow Progress</th>
                                        <th>Current Status</th>
                                        <th>Submitted</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($doc = $documents->fetch_assoc()): 
                                        $workflow = getWorkflowStage($doc['status']);
                                        $file_ext = pathinfo($doc['file_name'] ?? '', PATHINFO_EXTENSION);
                                        
                                        // Determine icon based on file type
                                        if (in_array($file_ext, ['pdf'])) {
                                            $icon_class = 'pdf';
                                            $icon = 'fa-file-pdf';
                                        } elseif (in_array($file_ext, ['doc', 'docx'])) {
                                            $icon_class = 'doc';
                                            $icon = 'fa-file-word';
                                        } elseif (in_array($file_ext, ['jpg', 'jpeg', 'png'])) {
                                            $icon_class = 'img';
                                            $icon = 'fa-file-image';
                                        } else {
                                            $icon_class = 'doc';
                                            $icon = 'fa-file';
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="doc-info">
                                                    <div class="doc-icon <?php echo $icon_class; ?>">
                                                        <i class="fa-solid <?php echo $icon; ?>"></i>
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
                                            <td>
                                                <div class="school-dept">
                                                    <?php if (!empty($doc['school'])): ?>
                                                        <span class="school-name">
                                                            <i class="fa-solid fa-university" style="color: var(--primary); margin-right: 4px;"></i>
                                                            <?php echo htmlspecialchars($doc['school']); ?>
                                                        </span>
                                                        <span class="dept-name">
                                                            <i class="fa-solid fa-building" style="margin-right: 4px;"></i>
                                                            <?php echo htmlspecialchars($doc['dept_name'] ?? 'N/A'); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="na-text">Not assigned</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="workflow-progress" style="flex-direction: column; align-items: flex-start;">
                                                    <div style="display: flex; align-items: center;">
                                                        <!-- COD Step -->
                                                        <div class="workflow-step <?php 
                                                            echo $workflow['stage'] >= 1 ? 'completed' : ($doc['status'] === 'Rejected' ? 'rejected' : 'pending'); 
                                                            if ($doc['status'] === 'Pending_COD') echo ' active';
                                                        ?>" title="COD Review">
                                                            <i class="fa-solid <?php echo $workflow['stage'] > 1 || $doc['status'] === 'Approved' ? 'fa-check' : 'fa-user-tie'; ?>"></i>
                                                        </div>
                                                        
                                                        <div class="workflow-connector <?php echo $workflow['stage'] >= 2 ? 'completed' : ''; ?>"></div>
                                                        
                                                        <!-- Dean Step -->
                                                        <div class="workflow-step <?php 
                                                            echo $workflow['stage'] >= 2 ? 'completed' : ($doc['status'] === 'Rejected' && $workflow['stage'] < 2 ? 'rejected' : 'pending'); 
                                                            if ($doc['status'] === 'Pending_Dean') echo ' active';
                                                        ?>" title="Dean Review">
                                                            <i class="fa-solid <?php echo $workflow['stage'] > 2 || $doc['status'] === 'Approved' ? 'fa-check' : 'fa-user-graduate'; ?>"></i>
                                                        </div>
                                                        
                                                        <div class="workflow-connector <?php echo $workflow['stage'] >= 3 ? 'completed' : ''; ?>"></div>
                                                        
                                                        <!-- Registrar Step -->
                                                        <div class="workflow-step <?php 
                                                            echo $workflow['stage'] >= 3 ? 'completed' : ($doc['status'] === 'Rejected' && $workflow['stage'] < 3 ? 'rejected' : 'pending'); 
                                                            if ($doc['status'] === 'Pending_Registrar') echo ' active';
                                                        ?>" title="Registrar Review">
                                                            <i class="fa-solid <?php echo $doc['status'] === 'Approved' ? 'fa-check' : 'fa-stamp'; ?>"></i>
                                                        </div>
                                                        
                                                        <div class="workflow-connector <?php echo $doc['status'] === 'Approved' ? 'completed' : ''; ?>"></div>
                                                        
                                                        <!-- Final Step -->
                                                        <div class="workflow-step <?php 
                                                            echo $doc['status'] === 'Approved' ? 'completed' : ($doc['status'] === 'Rejected' ? 'rejected' : 'pending'); 
                                                        ?>" title="Final Status">
                                                            <i class="fa-solid <?php echo $doc['status'] === 'Approved' ? 'fa-check-double' : ($doc['status'] === 'Rejected' ? 'fa-xmark' : 'fa-flag-checkered'); ?>"></i>
                                                        </div>
                                                    </div>
                                                    <div class="workflow-label" style="color: <?php echo $workflow['color']; ?>">
                                                        <?php echo $workflow['label']; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo getStatusBadgeClass($doc['status']); ?>">
                                                    <i class="fa-solid <?php 
                                                        echo match($doc['status']) {
                                                            'Pending_COD' => 'fa-user-tie',
                                                            'Pending_Dean' => 'fa-user-graduate',
                                                            'Pending_Registrar' => 'fa-stamp',
                                                            'Approved' => 'fa-check-circle',
                                                            'Rejected' => 'fa-times-circle',
                                                            default => 'fa-clock'
                                                        };
                                                    ?>"></i>
                                                    <?php echo str_replace('_', ' ', $doc['status']); ?>
                                                </span>
                                            </td>
                                            <td style="white-space: nowrap;">
                                                <div style="display: flex; flex-direction: column;">
                                                    <span><?php echo date('M d, Y', strtotime($doc['upload_date'])); ?></span>
                                                    <span style="font-size: 0.75rem; color: var(--text-light);"><?php echo date('H:i', strtotime($doc['upload_date'])); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="action-btns">
                                                    <?php if ($doc['file_path']): ?>
                                                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn-action btn-view tooltip" data-tooltip="View Document">
                                                            <i class="fa-solid fa-eye"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="view_document_details.php?id=<?php echo $doc['id']; ?>" class="btn-action btn-view tooltip" data-tooltip="View Details">
                                                        <i class="fa-solid fa-circle-info"></i>
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
                                <i class="fa-solid fa-folder-open"></i>
                            </div>
                            <h3>No Documents Found</h3>
                            <p>No documents match your current filters. Try adjusting your search criteria.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>