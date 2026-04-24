<?php
session_start();
require_once 'db_config.php';

// Check if user is logged in
if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php");
    exit();
}

$reg_number = $_SESSION['reg_number'];

// Pagination & Filter Logic (Preserved)
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

$where_clause = "WHERE user_reg_number = ?";
$params = [$reg_number];
$types = "s";

if ($filter === 'unread') { $where_clause .= " AND is_read = 0"; } 
elseif ($filter === 'read') { $where_clause .= " AND is_read = 1"; }

// Get user data for sidebar
$userStmt = $conn->prepare("SELECT full_name, profile_pix FROM users WHERE reg_number = ?");
$userStmt->bind_param("s", $reg_number);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();

// Get count for pagination
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM notifications $where_clause");
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_notifications = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_notifications / $per_page);

// Fetch notifications
$query = "SELECT * FROM notifications $where_clause ORDER BY created_at DESC LIMIT ?, ?";
$stmt = $conn->prepare($query);
$stmt->bind_param($types . "ii", ...array_merge($params, [$offset, $per_page]));
$stmt->execute();
$notifications = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | MUT Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #22c55e;
            --glass: rgba(255, 255, 255, 0.07);
            --glass-border: rgba(255, 255, 255, 0.12);
            --sidebar-width: 280px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: #ffffff;
            background: radial-gradient(at 0% 0%, rgba(34, 197, 94, 0.1) 0px, transparent 50%), #0f172a;
            background-attachment: fixed;
            display: flex;
            min-height: 100vh;
        }

        /* --- SIDEBAR --- */
        .sidebar {
            width: var(--sidebar-width);
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(20px);
            border-right: 1px solid var(--glass-border);
            padding: 30px 20px;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
        }

        .logo { font-weight: 800; font-size: 1.5rem; color: var(--primary); margin-bottom: 40px; text-align: center; }

        .nav-menu { flex: 1; list-style: none; }
        .nav-item { margin-bottom: 8px; }
        .nav-link {
            display: flex; align-items: center; gap: 12px; padding: 14px 18px;
            text-decoration: none; color: rgba(255,255,255,0.7); border-radius: 12px;
            transition: 0.3s; font-weight: 600; font-size: 0.95rem;
        }
        .nav-link:hover, .nav-link.active { background: var(--glass); color: white; border: 1px solid var(--glass-border); }
        .nav-link.active i { color: var(--primary); }

        /* --- MAIN CONTENT --- */
        .main-content {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 40px;
        }

        .header-box {
            background: var(--glass); backdrop-filter: blur(10px); border-radius: 24px;
            padding: 25px 35px; border: 1px solid var(--glass-border);
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;
        }

        .action-group { display: flex; gap: 12px; }
        .btn-outline {
            background: transparent; border: 1px solid var(--glass-border); color: white;
            padding: 10px 20px; border-radius: 12px; cursor: pointer; font-weight: 600; font-size: 0.85rem; transition: 0.3s;
        }
        .btn-outline:hover { background: rgba(255,255,255,0.1); }
        .btn-danger { border-color: rgba(239, 68, 68, 0.5); color: #f87171; }
        .btn-danger:hover { background: rgba(239, 68, 68, 0.2); }

        /* Notification Styling */
        .filter-tabs { display: flex; gap: 10px; margin-bottom: 25px; }
        .tab { 
            padding: 8px 20px; border-radius: 30px; text-decoration: none; color: rgba(255,255,255,0.6); 
            font-size: 0.85rem; font-weight: 700; border: 1px solid transparent; transition: 0.3s;
        }
        .tab.active { background: var(--primary); color: white; }
        .tab:not(.active):hover { border-color: var(--glass-border); color: white; }

        .notif-card {
            background: var(--glass); backdrop-filter: blur(10px); border-radius: 20px; border: 1px solid var(--glass-border);
            padding: 20px; margin-bottom: 12px; display: flex; gap: 20px; transition: 0.3s;
        }
        .notif-card.unread { background: rgba(34, 197, 94, 0.05); border-left: 4px solid var(--primary); }
        .notif-card:hover { transform: scale(1.01); background: rgba(255,255,255,0.1); }

        .icon-circle {
            width: 48px; height: 48px; border-radius: 15px; background: rgba(255,255,255,0.05);
            display: flex; align-items: center; justify-content: center; color: var(--primary);
        }

        .notif-body { flex: 1; }
        .notif-title { font-weight: 700; font-size: 1rem; margin-bottom: 4px; }
        .notif-text { color: rgba(255,255,255,0.6); font-size: 0.9rem; line-height: 1.5; }
        .notif-meta { font-size: 0.75rem; color: rgba(255,255,255,0.4); margin-top: 10px; display: block; }

        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 30px; }
        .p-btn { 
            width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
            text-decoration: none; color: white; background: var(--glass); border-radius: 10px; border: 1px solid var(--glass-border);
        }
        .p-btn.active { background: var(--primary); border-color: var(--primary); }

        @media (max-width: 992px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 20px; }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="logo">MUT DMS</div>
    <ul class="nav-menu">
        <li class="nav-item"><a href="index.php" class="nav-link"><i class="fa-solid fa-house"></i> Dashboard</a></li>
        <li class="nav-item"><a href="all_documents.php" class="nav-link"><i class="fa-solid fa-file-lines"></i> Documents</a></li>
        <li class="nav-item"><a href="all_notifications.php" class="nav-link active"><i class="fa-solid fa-bell"></i> Notifications</a></li>
        <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fa-solid fa-user-gear"></i> Profile</a></li>
    </ul>
    <div style="margin-top: auto; padding: 20px; background: var(--glass); border-radius: 15px; text-align: center;">
        <img src="<?php echo $user['profile_pix'] ?: 'assets/images/default.png'; ?>" style="width: 50px; height: 50px; border-radius: 50%; border: 2px solid var(--primary); margin-bottom: 10px;">
        <p style="font-size: 0.8rem; font-weight: 700;"><?php echo htmlspecialchars($user['full_name']); ?></p>
        <a href="logout.php" style="color: #f87171; text-decoration: none; font-size: 0.75rem; display: block; margin-top: 10px;">Sign Out</a>
    </div>
</aside>

<main class="main-content">
    <div class="header-box">
        <div>
            <h2 style="font-weight: 800; letter-spacing: -0.5px;">Activity Inbox</h2>
            <p style="font-size: 0.85rem; color: rgba(255,255,255,0.5);">Stay updated with your document status</p>
        </div>
        <div class="action-group">
            <button onclick="markAllRead()" class="btn-outline">Mark All Read</button>
            <button onclick="deleteAllNotif()" class="btn-outline btn-danger">Clear All</button>
        </div>
    </div>

    <div class="filter-tabs">
        <a href="?filter=all" class="tab <?php echo $filter === 'all' ? 'active' : ''; ?>">All Activity</a>
        <a href="?filter=unread" class="tab <?php echo $filter === 'unread' ? 'active' : ''; ?>">Unread</a>
        <a href="?filter=read" class="tab <?php echo $filter === 'read' ? 'active' : ''; ?>">Archived</a>
    </div>

    <div class="notif-container">
        <?php if ($notifications->num_rows > 0): ?>
            <?php while($row = $notifications->fetch_assoc()): ?>
                <div class="notif-card <?php echo $row['is_read'] == 0 ? 'unread' : ''; ?>">
                    <div class="icon-circle">
                        <i class="fa-solid <?php echo $row['is_read'] == 0 ? 'fa-envelope' : 'fa-envelope-open'; ?>"></i>
                    </div>
                    <div class="notif-body">
                        <div class="notif-title"><?php echo htmlspecialchars($row['title']); ?></div>
                        <div class="notif-text"><?php echo htmlspecialchars($row['message']); ?></div>
                        <span class="notif-meta"><i class="fa-regular fa-clock"></i> <?php echo date('d M, h:i A', strtotime($row['created_at'])); ?></span>
                    </div>
                </div>
            <?php endwhile; ?>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>" class="p-btn <?php echo $page == $i ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 100px 0; opacity: 0.4;">
                <i class="fa-solid fa-wind" style="font-size: 3rem; margin-bottom: 20px;"></i>
                <p>Nothing to see here right now.</p>
            </div>
        <?php endif; ?>
    </div>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    function markAllRead() {
        $.post('mark_notification_read.php', { action: 'all' }, function() { location.reload(); });
    }

    function deleteAllNotif() {
        if(confirm('Are you sure you want to permanently clear all notifications?')) {
            $.post('delete_all_notifications.php', function() { location.reload(); });
        }
    }
</script>
</body>
</html>