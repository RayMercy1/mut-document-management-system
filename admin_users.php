<?php
session_start();
require_once 'db_config.php';
require_once 'audit_functions.php';

if (!isset($_SESSION['reg_number']) || $_SESSION['role'] !== 'super_admin') {
    logAccessDenied($conn, 'admin_users.php', 'Not super_admin');
    header("Location: login.php");
    exit();
}

// Handle delete action
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    
    // Get user info before deleting for audit log
    $user_info = mysqli_query($conn, "SELECT reg_number, full_name FROM users WHERE id = $delete_id");
    if ($user_data = mysqli_fetch_assoc($user_info)) {
        $deleted_reg = $user_data['reg_number'];
        $deleted_name = $user_data['full_name'];
        
        // Prevent self-deletion
        if ($deleted_reg === $_SESSION['reg_number']) {
            $error = "You cannot delete your own account!";
        } else {
            // Delete user
            $delete_sql = "DELETE FROM users WHERE id = $delete_id";
            if (mysqli_query($conn, $delete_sql)) {
                // Log the deletion
                logUserDeletion($conn, $deleted_reg, $deleted_name);
                $success = "User deleted successfully";
            } else {
                $error = "Error deleting user: " . mysqli_error($conn);
            }
        }
    }
}

$users_query = "SELECT u.*, d.dept_name, d.school as dept_school FROM users u LEFT JOIN departments d ON u.department_id = d.id ORDER BY u.role ASC";
$users_res = mysqli_query($conn, $users_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Users | MUT DMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #22c55e; --bg: #0f172a; --card: #1e293b; --text: #f8fafc; --border: #334155; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); margin: 0; display: flex; }
        .sidebar { width: 280px; background: #0b1120; border-right: 1px solid var(--border); height: 100vh; padding: 20px; position: fixed; }
        .main-content { margin-left: 280px; padding: 40px; width: calc(100% - 280px); }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 15px; color: #94a3b8; text-decoration: none; border-radius: 10px; margin-bottom: 5px; }
        .nav-item.active { background: var(--card); color: white; border-left: 4px solid var(--primary); }
        
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); }
        .alert-error { background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
        
        .user-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .user-table th, .user-table td { padding: 15px; border-bottom: 1px solid var(--border); text-align: left; }
        .role-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; text-transform: uppercase; }
        .badge-student { background: #3b82f6; color: white; }
        .badge-staff { background: #8b5cf6; color: white; }
        .badge-admin { background: #22c55e; color: white; }
        
        .action-btns { display: flex; gap: 10px; }
        .action-btns a { color: #94a3b8; transition: color 0.2s; }
        .action-btns a:hover { color: var(--primary); }
        .action-btns a.delete:hover { color: #ef4444; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <h2 style="color: var(--primary); margin-bottom: 30px;">MUT DMS</h2>
        <nav>
            <a href="admin_dashboard.php" class="nav-item"><i class="fa-solid fa-gauge"></i> Dashboard</a>
            <a href="admin_users.php" class="nav-item active"><i class="fa-solid fa-users"></i> Manage Users</a>
            <a href="admin_departments.php" class="nav-item"><i class="fa-solid fa-building"></i> Departments</a>
            <a href="admin_audit.php" class="nav-item"><i class="fa-solid fa-list-check"></i> Audit Logs</a>
        </nav>
    </aside>

    <main class="main-content">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h1>User Management</h1>
            <a href="add_user.php" style="background: var(--primary); color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none;">+ Add User</a>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <table class="user-table">
            <thead>
                <tr><th>Full Name</th><th>Reg No.</th><th>School</th><th>Role</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php while($u = mysqli_fetch_assoc($users_res)): 
                    // Determine badge class and display role
                    if ($u['role'] == 'student') {
                        $badge = 'badge-student';
                        $display_role = 'STUDENT';
                    } elseif ($u['role'] == 'super_admin') {
                        $badge = 'badge-admin';
                        $display_role = 'SUPER_ADMIN';
                    } else {
                        $badge = 'badge-staff';
                        $display_role = !empty($u['admin_role']) && $u['admin_role'] !== 'none' 
                            ? strtoupper($u['admin_role']) 
                            : 'ADMIN';
                    }
                    
                    // Determine school display
                    // Registrar and Super Admin show N/A, everyone else shows their school
                    $is_registrar = ($u['role'] == 'admin' && $u['admin_role'] == 'registrar');
                    $is_super_admin = ($u['role'] == 'super_admin');
                    
                    if ($is_registrar || $is_super_admin) {
                        $school_display = 'N/A';
                    } elseif (!empty($u['school'])) {
                        // Dean has school stored directly
                        $school_display = htmlspecialchars($u['school']);
                    } elseif (!empty($u['dept_school'])) {
                        // Student/COD - get school from their department
                        $school_display = htmlspecialchars($u['dept_school']);
                    } else {
                        $school_display = 'N/A';
                    }
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($u['reg_number']); ?></td>
                    <td><?php echo $school_display; ?></td>
                    <td><span class="role-badge <?php echo $badge; ?>"><?php echo $display_role; ?></span></td>
                    <td>
                        <div class="action-btns">
                            <a href="edit_user.php?id=<?php echo $u['id']; ?>" title="Edit"><i class="fa-solid fa-edit"></i></a>
                            <?php if ($u['reg_number'] !== $_SESSION['reg_number']): ?>
                                <a href="?delete=<?php echo $u['id']; ?>" class="delete" title="Delete" onclick="return confirm('Are you sure you want to delete this user?')"><i class="fa-solid fa-trash"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </main>
</body>
</html>