<?php
session_start();
require_once 'db_config.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['reg_number']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super_admin')) {
    header("Location: login.php");
    exit();
}

// Check if user has authenticated into a role
if (!isset($_SESSION['role_authenticated']) || !isset($_SESSION['current_admin_role'])) {
    header("Location: admin_dashboard.php");
    exit();
}

$reg_number = $_SESSION['reg_number'];
$user_role = $_SESSION['role'];
$current_role = $_SESSION['current_admin_role'];
$selected_dept = $_SESSION['selected_department'] ?? null;
$full_name = $_SESSION['full_name'] ?? 'Admin';

// Date range filter
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Get department name
$dept_name = 'All Departments';
if ($selected_dept) {
    $deptStmt = $conn->prepare("SELECT dept_name FROM departments WHERE id = ?");
    $deptStmt->bind_param("i", $selected_dept);
    $deptStmt->execute();
    $dept_result = $deptStmt->get_result()->fetch_assoc();
    $dept_name = $dept_result['dept_name'] ?? 'Unknown';
}

// Build base query based on role
$dept_condition = "";
if ($current_role !== 'registrar' && $selected_dept) {
    $dept_condition = "AND u.department_id = $selected_dept";
}

// Overall statistics
$stats_sql = "SELECT 
    COUNT(*) as total_documents,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN d.status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN d.status IN ('Pending_COD', 'Pending_Dean', 'Pending_Registrar') THEN 1 ELSE 0 END) as pending,
    COUNT(DISTINCT d.reg_number) as unique_students
    FROM documents d 
    JOIN users u ON d.reg_number = u.reg_number 
    WHERE DATE(d.upload_date) BETWEEN '$start_date' AND '$end_date' $dept_condition";

$stats = $conn->query($stats_sql)->fetch_assoc();

// Documents by module
$module_sql = "SELECT 
    d.module_type,
    COUNT(*) as count,
    SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN d.status = 'Rejected' THEN 1 ELSE 0 END) as rejected
    FROM documents d 
    JOIN users u ON d.reg_number = u.reg_number 
    WHERE DATE(d.upload_date) BETWEEN '$start_date' AND '$end_date' $dept_condition
    GROUP BY d.module_type";

$module_stats = $conn->query($module_sql);

// Documents by status (for chart)
$status_sql = "SELECT 
    d.status,
    COUNT(*) as count
    FROM documents d 
    JOIN users u ON d.reg_number = u.reg_number 
    WHERE DATE(d.upload_date) BETWEEN '$start_date' AND '$end_date' $dept_condition
    GROUP BY d.status";

$status_stats = $conn->query($status_sql);
$status_data = [];
while ($row = $status_stats->fetch_assoc()) {
    $status_data[$row['status']] = $row['count'];
}

// Daily submission trend (last 30 days)
$trend_sql = "SELECT 
    DATE(d.upload_date) as date,
    COUNT(*) as count
    FROM documents d 
    JOIN users u ON d.reg_number = u.reg_number 
    WHERE DATE(d.upload_date) BETWEEN '$start_date' AND '$end_date' $dept_condition
    GROUP BY DATE(d.upload_date)
    ORDER BY date";

$trend_result = $conn->query($trend_sql);
$trend_data = [];
while ($row = $trend_result->fetch_assoc()) {
    $trend_data[] = $row;
}

// Top departments (for registrar view)
$top_depts = [];
if ($current_role === 'registrar') {
    $top_dept_sql = "SELECT 
        dept.dept_name,
        COUNT(*) as count
        FROM documents d 
        JOIN users u ON d.reg_number = u.reg_number 
        LEFT JOIN departments dept ON u.department_id = dept.id 
        WHERE DATE(d.upload_date) BETWEEN '$start_date' AND '$end_date'
        GROUP BY u.department_id
        ORDER BY count DESC
        LIMIT 5";
    $top_depts = $conn->query($top_dept_sql);
}

// Average processing time - DISABLED (approval_date column doesn't exist)
$avg_processing_days = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | Admin Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #22c55e;
            --primary-dark: #16a34a;
            --secondary: #0f172a;
            --accent: #3b82f6;
            --warning: #f59e0b;
            --danger: #ef4444;
            --success: #10b981;
            --bg: #f1f5f9;
            --card: #ffffff;
            --text: #1e293b;
            --text-light: #64748b;
            --border: #e2e8f0;
            --shadow: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 300px;
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

        .logo img {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: white;
            padding: 4px;
        }

        .logo-text {
            color: white;
        }

        .logo-text h3 {
            font-size: 1rem;
            font-weight: 700;
        }

        .logo-text span {
            font-size: 0.75rem;
            color: rgba(255,255,255,0.6);
        }

        .current-role-box {
            padding: 16px;
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 12px;
            margin: 16px;
            color: white;
        }

        .current-role-box .label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: rgba(255,255,255,0.6);
            margin-bottom: 4px;
        }

        .current-role-box .value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary);
        }

        .btn-switch-role {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: calc(100% - 32px);
            margin: 0 16px 16px;
            padding: 10px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            color: rgba(255,255,255,0.8);
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-switch-role:hover {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .nav-section {
            padding: 16px 0;
        }

        .nav-title {
            padding: 8px 24px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
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

        .nav-item i {
            width: 20px;
            text-align: center;
        }

        .sidebar-footer {
            padding: 16px 24px;
            border-top: 1px solid rgba(255,255,255,0.1);
            position: absolute;
            bottom: 0;
            width: 100%;
        }

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

        .btn-logout:hover {
            background: rgba(239, 68, 68, 0.3);
        }

        .main-content {
            flex: 1;
            margin-left: 300px;
            min-height: 100vh;
        }

        .header {
            background: var(--card);
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .page-title p {
            font-size: 0.875rem;
            color: var(--text-light);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 16px;
            background: var(--bg);
            border-radius: 12px;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .user-info {
            text-align: left;
        }

        .user-info .name {
            font-size: 0.875rem;
            font-weight: 600;
        }

        .user-info .role {
            font-size: 0.75rem;
            color: var(--text-light);
            text-transform: uppercase;
        }

        .content {
            padding: 32px;
        }

        .date-filter {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }

        .date-filter form {
            display: flex;
            gap: 16px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            font-size: 0.875rem;
            font-weight: 600;
        }

        .filter-group input {
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: 0.875rem;
        }

        .btn-filter {
            background: var(--primary);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 16px;
        }

        .stat-icon.blue { background: #dbeafe; color: #2563eb; }
        .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-icon.orange { background: #fef3c7; color: #d97706; }
        .stat-icon.purple { background: #f3e8ff; color: #7c3aed; }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--text-light);
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 24px;
        }

        .chart-card {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .chart-card h3 {
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .module-table {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .module-table h3 {
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--text-light);
            font-weight: 600;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--bg);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .main-content {
                margin-left: 0;
            }
            .stats-grid, .charts-grid {
                grid-template-columns: 1fr;
            }
            .date-filter form {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <img src="assets/images/mut_logo.png" alt="MUT Logo">
                    <div class="logo-text">
                        <h3>Admin Portal</h3>
                        <span>Document Management</span>
                    </div>
                </div>
            </div>

            <div class="current-role-box">
                <div class="label">Current Role</div>
                <div class="value"><?php echo ucfirst(htmlspecialchars($current_role)); ?></div>
                <?php if ($dept_name !== 'All Departments'): ?>
                    <div style="font-size: 0.875rem; color: rgba(255,255,255,0.8); margin-top: 4px;">
                        <i class="fa-solid fa-building"></i> <?php echo htmlspecialchars($dept_name); ?>
                    </div>
                <?php endif; ?>
            </div>

            <a href="?clear_auth=1" class="btn-switch-role">
                <i class="fa-solid fa-arrow-right-arrow-left"></i> Switch Role
            </a>

            <nav class="nav-section">
                <div class="nav-title">Menu</div>
                <a href="admin_dashboard.php" class="nav-item">
                    <i class="fa-solid fa-grid-2"></i> Dashboard
                </a>
                <a href="admin_documents.php" class="nav-item">
                    <i class="fa-solid fa-folder-open"></i> All Documents
                </a>
                <a href="admin_reports.php" class="nav-item active">
                    <i class="fa-solid fa-chart-bar"></i> Reports
                </a>
                <?php if ($user_role === 'super_admin' && !$current_role): ?>
                    <a href="admin_users.php" class="nav-item">
                        <i class="fa-solid fa-users"></i> Manage Users
                    </a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <a href="logout.php" class="btn-logout">
                    <i class="fa-solid fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="header">
                <div class="page-title">
                    <h1>Reports & Analytics</h1>
                    <p>Document processing insights and statistics</p>
                </div>
                <div class="header-actions">
                    <div class="user-menu">
                        <div class="user-avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
                        <div class="user-info">
                            <div class="name"><?php echo htmlspecialchars($full_name); ?></div>
                            <div class="role"><?php echo ucfirst($current_role); ?></div>
                        </div>
                    </div>
                </div>
            </header>

            <div class="content">
                <!-- Date Filter -->
                <div class="date-filter">
                    <form method="GET">
                        <div class="filter-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                        </div>
                        <div class="filter-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" value="<?php echo $end_date; ?>">
                        </div>
                        <button type="submit" class="btn-filter">
                            <i class="fa-solid fa-filter"></i> Generate Report
                        </button>
                    </form>
                </div>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fa-solid fa-file-lines"></i></div>
                        <div class="stat-value"><?php echo $stats['total_documents'] ?? 0; ?></div>
                        <div class="stat-label">Total Documents</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fa-solid fa-check-circle"></i></div>
                        <div class="stat-value"><?php echo $stats['approved'] ?? 0; ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fa-solid fa-clock"></i></div>
                        <div class="stat-value"><?php echo $stats['pending'] ?? 0; ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fa-solid fa-users"></i></div>
                        <div class="stat-value"><?php echo $stats['unique_students'] ?? 0; ?></div>
                        <div class="stat-label">Unique Students</div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <h3><i class="fa-solid fa-chart-pie" style="color: var(--primary);"></i> Document Status Distribution</h3>
                        <div class="chart-container">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h3><i class="fa-solid fa-chart-line" style="color: var(--primary);"></i> Daily Submission Trend</h3>
                        <div class="chart-container">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Module Statistics -->
                <div class="module-table">
                    <h3><i class="fa-solid fa-table" style="color: var(--primary);"></i> Documents by Module</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Module</th>
                                <th>Total</th>
                                <th>Approved</th>
                                <th>Rejected</th>
                                <th>Success Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_all = 0;
                            while ($mod = $module_stats->fetch_assoc()): 
                                $total_all += $mod['count'];
                                $success_rate = $mod['count'] > 0 ? round(($mod['approved'] / $mod['count']) * 100) : 0;
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($mod['module_type']); ?></strong></td>
                                    <td><?php echo $mod['count']; ?></td>
                                    <td style="color: var(--success);"><?php echo $mod['approved']; ?></td>
                                    <td style="color: var(--danger);"><?php echo $mod['rejected']; ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div class="progress-bar" style="flex: 1;">
                                                <div class="progress-fill" style="width: <?php echo $success_rate; ?>%; background: <?php echo $success_rate > 70 ? 'var(--success)' : ($success_rate > 40 ? 'var(--warning)' : 'var(--danger)'); ?>;"></div>
                                            </div>
                                            <span style="font-size: 0.875rem; font-weight: 600;"><?php echo $success_rate; ?>%</span>
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
        // Status Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Approved', 'Pending', 'Rejected'],
                datasets: [{
                    data: [
                        <?php echo $status_data['Approved'] ?? 0; ?>,
                        <?php echo ($status_data['Pending_COD'] ?? 0) + ($status_data['Pending_Dean'] ?? 0) + ($status_data['Pending_Registrar'] ?? 0); ?>,
                        <?php echo $status_data['Rejected'] ?? 0; ?>
                    ],
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(fn($d) => date('M d', strtotime($d['date'])), $trend_data)); ?>,
                datasets: [{
                    label: 'Documents Submitted',
                    data: <?php echo json_encode(array_map(fn($d) => $d['count'], $trend_data)); ?>,
                    borderColor: '#22c55e',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>