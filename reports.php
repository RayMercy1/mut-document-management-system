<?php
session_start();
require_once 'db_config.php';

// Access Control - Super Admin Only
if (!isset($_SESSION['reg_number'])) { 
    header("Location: login.php"); 
    exit(); 
}

// 1. Fetch Overall Stats for Top Cards
$total_users = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM users"))['count'];
$total_docs = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM documents"))['count'];
$total_depts = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM departments"))['count'];
$total_staff = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE role != 'Student'"))['count'];

// 2. Fetch Role Distribution (COD, DEAN, REGISTRAR, etc.) for Doughnut Chart
$role_query = "SELECT role, COUNT(*) as count FROM users WHERE role IN ('COD', 'DEAN', 'REGISTRAR', 'ADMIN') GROUP BY role";
$role_res = mysqli_query($conn, $role_query);
$role_labels = [];
$role_data = [];
while($r = mysqli_fetch_assoc($role_res)) {
    $role_labels[] = $r['role'];
    $role_data[] = $r['count'];
}

// 3. Fetch Department Activity for the Table
$dept_activity = mysqli_query($conn, "
    SELECT d.dept_name, COUNT(u.id) as user_count 
    FROM departments d 
    LEFT JOIN users u ON d.id = u.department_id 
    GROUP BY d.id
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Reports | MUT DMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { 
            --primary: #22c55e; 
            --bg: #0f172a; 
            --card: #1e293b; 
            --text: #f8fafc; 
            --border: #334155;
            --blue: #3b82f6;
            --yellow: #eab308;
            --red: #ef4444;
        }
        
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; }
        .layout { display: grid; grid-template-columns: 260px 1fr; gap: 25px; max-width: 1600px; margin: 0 auto; }
        
        /* Sidebar Styling */
        .sidebar-nav { display: flex; flex-direction: column; gap: 8px; }
        .nav-item { 
            background: transparent; padding: 14px 18px; border-radius: 12px; 
            text-decoration: none; color: #94a3b8; display: flex; align-items: center; 
            gap: 12px; transition: 0.3s; font-weight: 500;
        }
        .nav-item:hover { background: #1e293b; color: var(--primary); }
        .nav-item.active { background: rgba(34, 197, 94, 0.1); color: var(--primary); font-weight: 600; }

        .content-box { background: var(--card); padding: 30px; border-radius: 20px; border: 1px solid var(--border); }

        /* Top Stats Cards - Matching your 1st Screenshot */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { padding: 25px; border-radius: 15px; position: relative; overflow: hidden; color: white; transition: 0.3s; }
        .stat-card i { position: absolute; right: 15px; bottom: 15px; font-size: 3rem; opacity: 0.2; }
        .stat-card h4 { margin: 0; font-size: 0.9rem; text-transform: uppercase; opacity: 0.9; }
        .stat-card .number { font-size: 2.2rem; font-weight: 800; margin: 10px 0; display: block; }
        .stat-card .footer { font-size: 0.8rem; background: rgba(0,0,0,0.1); padding: 5px 10px; border-radius: 5px; text-decoration: none; color: white; }

        .bg-blue { background: #0891b2; }
        .bg-green { background: #16a34a; }
        .bg-yellow { background: #ca8a04; }
        .bg-red { background: #dc2626; }

        /* Analytics Grid - Matching your 2nd Screenshot */
        .analytics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-top: 30px; }
        .chart-container { background: #0f172a; padding: 25px; border-radius: 15px; border: 1px solid var(--border); text-align: center; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; background: #0f172a; border-radius: 12px; overflow: hidden; }
        th { text-align: left; color: #94a3b8; font-size: 0.75rem; text-transform: uppercase; padding: 15px; border-bottom: 1px solid var(--border); }
        td { padding: 15px; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        
        .progress-bar { width: 100%; background: #334155; height: 10px; border-radius: 5px; overflow: hidden; margin-top: 5px; }
        .progress-fill { background: var(--primary); height: 100%; border-radius: 5px; }

        @media (max-width: 1024px) { .stats-grid { grid-template-columns: 1fr 1fr; } .layout { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<div class="layout">
    <aside class="sidebar-nav">
        <div style="padding: 10px 18px; margin-bottom: 20px;">
            <h2 style="color: var(--primary); margin: 0;">MUT DMS</h2>
            <small style="color: #64748b;">Super Admin Portal</small>
        </div>
        <a href="admin_dashboard.php" class="nav-item"><i class="fa-solid fa-gauge"></i> Dashboard</a>
        <a href="all_documents.php" class="nav-item"><i class="fa-solid fa-file-shield"></i> Repository</a>
        <a href="reports.php" class="nav-item active"><i class="fa-solid fa-chart-line"></i> Analytics</a>
        <a href="admin_users.php" class="nav-item"><i class="fa-solid fa-user-gear"></i> Manage Users</a>
        <hr style="border: 0; border-top: 1px solid var(--border); margin: 15px 0;">
        <a href="logout.php" class="nav-item" style="color: #ef4444;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <main class="content-box">
        <header style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <div>
                <h1 style="margin: 0; font-size: 1.8rem;">System-Wide Reports</h1>
                <p style="color: #94a3b8; margin: 5px 0 0 0;">Real-time analytics for academic administration.</p>
            </div>
            <button onclick="window.print()" style="background: var(--primary); color: white; border: none; padding: 12px 20px; border-radius: 10px; font-weight: 600; cursor: pointer;">
                <i class="fa-solid fa-file-pdf"></i> Generate PDF
            </button>
        </header>

        <div class="stats-grid">
            <div class="stat-card bg-blue">
                <h4>Total Files</h4>
                <span class="number"><?php echo $total_docs; ?></span>
                <a href="#" class="footer">View Files <i class="fa-solid fa-circle-arrow-right"></i></a>
                <i class="fa-solid fa-file-invoice"></i>
            </div>
            <div class="stat-card bg-green">
                <h4>Active Users</h4>
                <span class="number"><?php echo $total_users; ?></span>
                <a href="#" class="footer">More info <i class="fa-solid fa-circle-arrow-right"></i></a>
                <i class="fa-solid fa-users"></i>
            </div>
            <div class="stat-card bg-yellow">
                <h4>Total Staff</h4>
                <span class="number"><?php echo $total_staff; ?></span>
                <a href="#" class="footer">Staff List <i class="fa-solid fa-circle-arrow-right"></i></a>
                <i class="fa-solid fa-user-tie"></i>
            </div>
            <div class="stat-card bg-red">
                <h4>Academic Depts</h4>
                <span class="number"><?php echo $total_depts; ?></span>
                <a href="#" class="footer">Manage Depts <i class="fa-solid fa-circle-arrow-right"></i></a>
                <i class="fa-solid fa-building-columns"></i>
            </div>
        </div>

        <div class="analytics-grid">
            <div class="chart-container">
                <h3 style="margin-bottom: 20px;">Management Distribution</h3>
                <canvas id="roleChart" style="max-height: 250px;"></canvas>
                <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 15px;">Breakdown of COD, Dean, and Registrar roles.</p>
            </div>

            <div class="chart-container" style="text-align: left;">
                <h3 style="margin-bottom: 10px;">Department Utilization</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Staff Count</th>
                            <th>Active Load</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = mysqli_fetch_assoc($dept_activity)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['dept_name']); ?></td>
                            <td><strong><?php echo $row['user_count']; ?></strong></td>
                            <td>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo min(($row['user_count'] * 15), 100); ?>%"></div>
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
    // Role Distribution Chart (Doughnut)
    const ctx = document.getElementById('roleChart').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($role_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($role_data); ?>,
                backgroundColor: ['#22c55e', '#3b82f6', '#eab308', '#ef4444'],
                borderWidth: 0,
                hoverOffset: 10
            }]
        },
        options: {
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: '#94a3b8', usePointStyle: true, padding: 20 }
                }
            },
            cutout: '70%'
        }
    });
</script>

</body>
</html>