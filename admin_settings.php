<?php
session_start();
require_once 'db_config.php';
if (!isset($_SESSION['reg_number']) || $_SESSION['role'] !== 'super_admin') { header("Location: login.php"); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings | MUT DMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #22c55e; --bg: #0f172a; --card: #1e293b; --text: #f8fafc; --border: #334155; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; }
        .layout { display: grid; grid-template-columns: 280px 1fr; gap: 25px; max-width: 1400px; margin: 0 auto; }
        .sidebar-nav { display: flex; flex-direction: column; gap: 10px; }
        .nav-item { background: var(--card); padding: 15px; border-radius: 12px; text-decoration: none; color: white; border: 1px solid var(--border); display: flex; align-items: center; gap: 12px; transition: 0.3s; }
        .nav-item.active { border-left: 4px solid var(--primary); background: #334155; border-color: var(--primary); }
        .content-box { background: var(--card); padding: 30px; border-radius: 16px; border: 1px solid var(--border); min-height: 85vh; }
        
        .setting-card { display: flex; justify-content: space-between; align-items: center; padding: 20px; background: #161e2d; border: 1px solid var(--border); border-radius: 12px; margin-bottom: 15px; }
        .btn-save { background: var(--primary); color: white; border: none; padding: 12px 30px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1rem; transition: 0.3s; }
        .btn-save:hover { background: #16a34a; transform: translateY(-2px); }

        /* Toast Styles */
        #toast {
            visibility: hidden; min-width: 300px; background-color: var(--primary); color: #fff; text-align: center; border-radius: 10px;
            padding: 16px; position: fixed; z-index: 1000; left: 50%; top: 30px; transform: translateX(-50%); 
            font-weight: 600; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
        }
        #toast.show { visibility: visible; animation: fadein 0.5s, fadeout 0.5s 2.5s; }
        @keyframes fadein { from {top: 0; opacity: 0;} to {top: 30px; opacity: 1;} }
        @keyframes fadeout { from {top: 30px; opacity: 1;} to {top: 0; opacity: 0;} }
    </style>
</head>
<body>
    <div id="toast"><i class="fa-solid fa-circle-check"></i> System changes have been saved!</div>

    <div class="layout">
        <aside class="sidebar-nav">
            <h3 style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 15px;">MANAGEMENT</h3>
            <a href="admin_users.php" class="nav-item"><i class="fa-solid fa-users"></i> Users</a>
            <a href="admin_departments.php" class="nav-item"><i class="fa-solid fa-building"></i> Departments</a>
            <a href="admin_settings.php" class="nav-item active"><i class="fa-solid fa-gear"></i> Settings</a>
            <a href="admin_audit.php" class="nav-item"><i class="fa-solid fa-list-check"></i> Audit Logs</a>
            <hr style="border: 0; border-top: 1px solid var(--border); margin: 10px 0;">
            <a href="admin_dashboard.php" style="color: var(--primary); text-decoration: none; font-weight: 600;"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
        </aside>
        
        <main class="content-box">
            <h2>System Preferences</h2>
            <p style="color: #94a3b8; margin-bottom: 30px;">Manage global application behaviors.</p>

            <div class="setting-card">
                <div><strong>Maintenance Mode</strong><br><small style="color: #94a3b8;">Prevent document uploads during maintenance</small></div>
                <input type="checkbox" style="width: 20px; height: 20px; accent-color: var(--primary);">
            </div>
            
            <div class="setting-card">
                <div><strong>Email Notifications</strong><br><small style="color: #94a3b8;">Send automated status updates to students</small></div>
                <input type="checkbox" checked style="width: 20px; height: 20px; accent-color: var(--primary);">
            </div>

            <button class="btn-save" onclick="triggerSave()">Save Changes</button>
        </main>
    </div>

    <script>
        function triggerSave() {
            var x = document.getElementById("toast");
            x.className = "show";
            setTimeout(function(){ x.className = x.className.replace("show", ""); }, 3000);
        }
    </script>
</body>
</html>