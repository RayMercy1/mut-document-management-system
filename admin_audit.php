<?php
session_start();
require_once 'db_config.php';

// Access Control
if (!isset($_SESSION['reg_number']) || $_SESSION['role'] !== 'super_admin') { 
    header("Location: login.php"); 
    exit(); 
}

// Check if audit_logs table exists
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'audit_logs'");
if (mysqli_num_rows($table_check) == 0) {
    $error_msg = "Audit logs table does not exist. Please run the SQL setup script.";
    $audit_res = null;
} else {
    // Fetch the 50 most recent logs
    $audit_res = mysqli_query($conn, "SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 50");
    if (!$audit_res) {
        $error_msg = "Error fetching audit logs: " . mysqli_error($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Audit Logs | MUT DMS</title>
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
        
        .error-box { background: rgba(239, 68, 68, 0.1); border: 1px solid #ef4444; color: #ef4444; padding: 20px; border-radius: 12px; margin-bottom: 20px; }
        .error-box h3 { margin-top: 0; }
        .error-box code { background: rgba(0,0,0,0.3); padding: 15px; display: block; border-radius: 8px; margin-top: 10px; font-family: monospace; font-size: 0.85rem; line-height: 1.5; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { text-align: left; color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; padding: 12px; border-bottom: 1px solid var(--border); }
        td { padding: 15px 12px; border-bottom: 1px solid var(--border); font-size: 0.9rem; vertical-align: middle; }
        
        .badge-action { font-family: monospace; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 0.75rem; text-transform: uppercase; }
        .action-delete, .action-login_failed, .action-access_denied { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
        .action-update, .action-status_change, .action-password_reset { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .action-create, .action-create_user, .action-document_upload, .action-login_success { background: rgba(34, 197, 94, 0.1); color: #22c55e; }
        .action-logout { background: rgba(148, 163, 184, 0.1); color: #94a3b8; }
        .action-default { background: rgba(148, 163, 184, 0.1); color: #94a3b8; }
        
        .ip-address { font-family: monospace; font-size: 0.75rem; color: #64748b; }
        .timestamp { font-family: monospace; color: #64748b; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="sidebar-nav">
            <h3 style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 15px;">MANAGEMENT</h3>
            <a href="admin_users.php" class="nav-item"><i class="fa-solid fa-users"></i> Users</a>
            <a href="admin_departments.php" class="nav-item"><i class="fa-solid fa-building"></i> Departments</a>
            <a href="admin_settings.php" class="nav-item"><i class="fa-solid fa-gear"></i> Settings</a>
            <a href="admin_audit.php" class="nav-item active"><i class="fa-solid fa-list-check"></i> Audit Logs</a>
            <hr style="border: 0; border-top: 1px solid var(--border); margin: 10px 0;">
            <a href="admin_dashboard.php" style="color: var(--primary); text-decoration: none; font-weight: 600;"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
        </aside>

        <main class="content-box">
            <div style="margin-bottom: 20px;">
                <h2 style="margin:0;">System Audit Logs</h2>
                <p style="color: #94a3b8; font-size: 0.9rem;">Tracking administrative changes for security and transparency.</p>
            </div>

            <?php if (isset($error_msg)): ?>
                <div class="error-box">
                    <h3><i class="fa-solid fa-triangle-exclamation"></i> Database Error</h3>
                    <p><?php echo htmlspecialchars($error_msg); ?></p>
                    <?php if (strpos($error_msg, 'table does not exist') !== false): ?>
                        <p style="margin-top: 15px;">Run this SQL query to create the table:</p>
                        <code>
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_reg VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    affected_record VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_reg (user_reg),
    INDEX idx_created_at (created_at)
);</code>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(mysqli_num_rows($audit_res) > 0): ?>
                            <?php while($a = mysqli_fetch_assoc($audit_res)): 
                                // Determine badge color based on action
                                $action = strtolower($a['action']);
                                $class = 'action-default';
                                if(strpos($action, 'delete') !== false || strpos($action, 'failed') !== false || strpos($action, 'denied') !== false) {
                                    $class = 'action-delete';
                                } elseif(strpos($action, 'update') !== false || strpos($action, 'change') !== false || strpos($action, 'reset') !== false) {
                                    $class = 'action-update';
                                } elseif(strpos($action, 'create') !== false || strpos($action, 'upload') !== false || strpos($action, 'success') !== false) {
                                    $class = 'action-create';
                                } elseif(strpos($action, 'logout') !== false) {
                                    $class = 'action-logout';
                                }
                            ?>
                            <tr>
                                <td class="timestamp"><?php echo date('Y-m-d H:i:s', strtotime($a['created_at'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($a['user_reg']); ?></strong></td>
                                <td><span class="badge-action <?php echo $class; ?>"><?php echo htmlspecialchars($a['action']); ?></span></td>
                                <td style="color: #cbd5e1;"><?php echo htmlspecialchars($a['details']); ?></td>
                                <td class="ip-address"><?php echo htmlspecialchars($a['ip_address'] ?? 'N/A'); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center; padding: 50px; color: #64748b;">
                                <i class="fa-solid fa-clipboard-list" style="font-size: 3rem; margin-bottom: 15px; display: block;"></i>
                                No logs recorded yet.
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>