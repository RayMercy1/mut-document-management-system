<?php
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['reg_number']) || $_SESSION['role'] !== 'super_admin') { 
    header("Location: login.php"); 
    exit(); 
}

// Fetch departments with dynamic staff count per department
$dept_query = "SELECT d.*, (SELECT COUNT(*) FROM users WHERE department_id = d.id) as staff_count 
               FROM departments d 
               ORDER BY school ASC";
$dept_res = mysqli_query($conn, $dept_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Departments | MUT DMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #22c55e; --bg: #0f172a; --card: #1e293b; --text: #f8fafc; --border: #334155; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; }
        .layout { display: grid; grid-template-columns: 280px 1fr; gap: 25px; max-width: 1400px; margin: 0 auto; }
        .sidebar-nav { display: flex; flex-direction: column; gap: 10px; }
        .nav-item { background: var(--card); padding: 15px; border-radius: 12px; text-decoration: none; color: white; border: 1px solid var(--border); display: flex; align-items: center; gap: 12px; transition: 0.3s; }
        .nav-item:hover, .nav-item.active { border-color: var(--primary); background: #334155; }
        .nav-item.active { border-left: 4px solid var(--primary); }
        .content-box { background: var(--card); padding: 30px; border-radius: 16px; border: 1px solid var(--border); min-height: 85vh; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { text-align: left; color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; padding: 12px; border-bottom: 1px solid var(--border); }
        td { padding: 15px 12px; border-bottom: 1px solid var(--border); }
        
        .btn-add { background: var(--primary); color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; transition: 0.3s; }
        .btn-add:hover { background: #16a34a; transform: translateY(-1px); }
        
        .action-icon { color: #64748b; margin-right: 15px; text-decoration: none; font-size: 1.1rem; transition: 0.2s; }
        .action-icon:hover { color: var(--primary); }
        .delete-icon:hover { color: #ef4444; }
        
        .alert { background: rgba(34, 197, 94, 0.2); color: var(--primary); border: 1px solid var(--primary); padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="sidebar-nav">
            <h3 style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 15px;">MANAGEMENT</h3>
            <a href="admin_users.php" class="nav-item"><i class="fa-solid fa-users"></i> Users</a>
            <a href="admin_departments.php" class="nav-item active"><i class="fa-solid fa-building"></i> Departments</a>
            <a href="admin_settings.php" class="nav-item"><i class="fa-solid fa-gear"></i> Settings</a>
            <a href="admin_audit.php" class="nav-item"><i class="fa-solid fa-list-check"></i> Audit Logs</a>
            <hr style="border: 0; border-top: 1px solid var(--border); margin: 10px 0;">
            <a href="admin_dashboard.php" style="color: var(--primary); text-decoration: none; font-weight: 600;"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
        </aside>

        <main class="content-box">
            <?php if(isset($_GET['success'])): ?>
                <div class="alert"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($_GET['success']); ?></div>
            <?php endif; ?>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <div>
                    <h2 style="margin:0;">University Departments</h2>
                    <p style="color: #94a3b8; font-size: 0.9rem;">Manage organizational structure and faculty assignments.</p>
                </div>
                <a href="add_department.php" class="btn-add"><i class="fa-solid fa-plus"></i> New Dept</a>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Department Name</th>
                        <th>School / Faculty</th>
                        <th>Active Staff</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($d = mysqli_fetch_assoc($dept_res)): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($d['dept_name']); ?></strong></td>
                        <td><span style="color: #94a3b8;"><?php echo htmlspecialchars($d['school']); ?></span></td>
                        <td><span style="color: var(--primary); font-weight: 600;"><?php echo $d['staff_count']; ?> Registered</span></td>
                        <td>
                            <a href="edit_dept.php?id=<?php echo $d['id']; ?>" class="action-icon" title="Edit Department">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </a>
                            <a href="delete_item.php?type=dept&id=<?php echo $d['id']; ?>" 
                               class="action-icon delete-icon" 
                               onclick="return confirm('Deleting this department will leave associated staff without a department. Continue?')" 
                               title="Delete Department">
                                <i class="fa-solid fa-trash-can"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </main>
    </div>
</body>
</html>