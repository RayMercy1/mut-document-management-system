<?php
session_start();
require_once 'db_config.php';

// Check if user is logged in
if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php");
    exit();
}

// Redirect admins to admin dashboard
if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin') {
    header("Location: admin_dashboard.php");
    exit();
}

$reg_number = $_SESSION['reg_number'];
$filter = $_GET['filter'] ?? 'Overview';

// Fetch user data
$stmt = $conn->prepare("SELECT u.*, d.dept_name, d.school FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.reg_number = ?");
$stmt->bind_param("s", $reg_number);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$user_name = $user['full_name'] ?? 'Student';
$display_pix = !empty($user['profile_pix']) ? $user['profile_pix'] : 'assets/images/default_avatar.png';

// Fetch statistics
$stats = [];

// Top of file logic to fetch notification count
// Make sure session_start() and db_config.php are already included above this
$reg_number = $_SESSION['reg_number'];
$unread_stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_reg_number = ? AND is_read = 0");
$unread_stmt->bind_param("s", $reg_number);
$unread_stmt->execute();
$unread_count = $unread_stmt->get_result()->fetch_row()[0];

// Total documents
$totalStmt = $conn->prepare("SELECT COUNT(*) as total FROM documents WHERE reg_number = ?");
$totalStmt->bind_param("s", $reg_number);
$totalStmt->execute();
$stats['total'] = $totalStmt->get_result()->fetch_assoc()['total'];

// Pending documents
$pendingStmt = $conn->prepare("SELECT COUNT(*) as total FROM documents WHERE reg_number = ? AND status IN ('Pending_COD', 'Pending_Dean', 'Pending_Registrar')");
$pendingStmt->bind_param("s", $reg_number);
$pendingStmt->execute();
$stats['pending'] = $pendingStmt->get_result()->fetch_assoc()['total'];

// Approved documents
$approvedStmt = $conn->prepare("SELECT COUNT(*) as total FROM documents WHERE reg_number = ? AND status = 'Approved'");
$approvedStmt->bind_param("s", $reg_number);
$approvedStmt->execute();
$stats['approved'] = $approvedStmt->get_result()->fetch_assoc()['total'];

// Rejected documents
$rejectedStmt = $conn->prepare("SELECT COUNT(*) as total FROM documents WHERE reg_number = ? AND status = 'Rejected'");
$rejectedStmt->bind_param("s", $reg_number);
$rejectedStmt->execute();
$stats['rejected'] = $rejectedStmt->get_result()->fetch_assoc()['total'];

// Success rate
$stats['success_rate'] = ($stats['total'] > 0) ? round(($stats['approved'] / $stats['total']) * 100) : 0;

// Unread notifications count
$unreadStmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_reg_number = ? AND is_read = 0");
$unreadStmt->bind_param("s", $reg_number);
$unreadStmt->execute();
$unread_count = $unreadStmt->get_result()->fetch_assoc()['count'];

// Get document counts by module
function getModuleCount($conn, $reg, $module) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM documents WHERE reg_number = ? AND module_type = ?");
    $stmt->bind_param("ss", $reg, $module);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

// Fetch recent documents
$recentStmt = $conn->prepare("SELECT d.*, dept.dept_name 
    FROM documents d 
    LEFT JOIN users u ON d.reg_number = u.reg_number 
    LEFT JOIN departments dept ON u.department_id = dept.id 
    WHERE d.reg_number = ? 
    ORDER BY d.upload_date DESC 
    LIMIT 6");
$recentStmt->bind_param("s", $reg_number);
$recentStmt->execute();
$recent_docs = $recentStmt->get_result();
;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal | MUT Document Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary: #22c55e;
            --primary-dark: #16a34a;
            --primary-light: #86efac;
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

        /* Layout */
        .layout {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--secondary);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
            transition: transform 0.3s ease;
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

        .nav-item:hover {
            background: rgba(255,255,255,0.05);
            color: white;
        }

        .nav-item.active {
            background: rgba(34, 197, 94, 0.15);
            color: var(--primary);
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

        .nav-actions {
        display: flex;
        align-items: center;
        gap: 20px;
        position: relative;
    }

    .notification-btn {
        position: relative;
        cursor: pointer;
        color: #64748b;
        font-size: 20px;
        transition: color 0.2s;
    }

    .notification-btn:hover { color: #22c55e; }

    .badge {
        position: absolute;
        top: -8px;
        right: -8px;
        background: #ef4444;
        color: white;
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 50%;
        border: 2px solid white;
        font-weight: bold;
    }

    /* The Pop-up Dropdown */
    .notif-popup {
        position: absolute;
        top: 45px;
        right: 0;
        width: 320px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        border: 1px solid #e2e8f0;
        display: none; /* Hidden by default */
        z-index: 1000;
        overflow: hidden;
    }

    .notif-popup.active { display: block; }

    .notif-header {
        padding: 12px 16px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .notif-header h4 { font-size: 14px; margin: 0; color: #1e293b; }
    .notif-header a { font-size: 12px; color: #22c55e; text-decoration: none; font-weight: 600; }

    .notif-content { max-height: 300px; overflow-y: auto; }

    .notif-item {
        padding: 12px 16px;
        border-bottom: 1px solid #f1f5f9;
        display: block;
        text-decoration: none;
        color: inherit;
        transition: background 0.2s;
    }

    .notif-item:hover { background: #f8fafc; }
    .notif-item.unread { background: #f0fdf4; border-left: 3px solid #22c55e; }

    .notif-item p { font-size: 13px; margin: 0 0 4px 0; color: #334155; }
    .notif-item span { font-size: 11px; color: #94a3b8; }

    .notif-empty {
        padding: 20px;
        text-align: center;
        color: #94a3b8;
        font-size: 13px;
    }

    .notif-footer {
        padding: 10px;
        text-align: center;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
    }

    .notif-footer a { font-size: 13px; color: #64748b; text-decoration: none; }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
        }

        /* Header - ENHANCED */
        .header {
            background: var(--card);
            padding: 24px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .page-title {
            flex: 1;
        }

        .page-title h1 {
            font-size: 2rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 8px;
            letter-spacing: -0.02em;
        }

        .page-title .welcome-text {
            font-size: 1.125rem;
            color: var(--text-light);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-title .welcome-text::before {
            content: '';
            display: inline-block;
            width: 8px;
            height: 8px;
            background: var(--primary);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* Notification Styles */
        .notification-wrapper {
            position: relative;
        }

        .btn-notification {
            position: relative;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-size: 1.125rem;
        }

        .btn-notification:hover {
            background: var(--bg);
            transform: translateY(-2px);
        }

        .btn-notification .badge {
            position: absolute;
            top: -4px;
            right: -4px;
            width: 20px;
            height: 20px;
            background: var(--danger);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            min-width: 360px;
            max-width: 400px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.2s ease;
            z-index: 100;
        }

        .notification-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notification-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-header h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .notification-header a {
            font-size: 0.875rem;
            color: var(--primary);
            text-decoration: none;
        }

        .notification-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .notification-item {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            gap: 12px;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .notification-item:hover {
            background: var(--bg);
        }

        .notification-item.unread {
            background: rgba(34, 197, 94, 0.05);
            border-left: 3px solid var(--primary);
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .notification-icon.status {
            background: #dbeafe;
            color: #2563eb;
        }

        .notification-icon.document {
            background: #d1fae5;
            color: #059669;
        }

        .notification-icon.system {
            background: #fef3c7;
            color: #d97706;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 4px;
        }

        .notification-text {
            font-size: 0.8125rem;
            color: var(--text-light);
            line-height: 1.4;
        }

        .notification-time {
            font-size: 0.75rem;
            color: var(--text-light);
            margin-top: 4px;
        }

        .notification-empty {
            padding: 40px;
            text-align: center;
            color: var(--text-light);
        }

        .notification-empty i {
            font-size: 3rem;
            margin-bottom: 12px;
            opacity: 0.5;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 16px;
            background: var(--bg);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            border: 2px solid transparent;
        }

        .user-menu:hover {
            background: var(--border);
            border-color: var(--primary-light);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary);
        }

        .user-info {
            text-align: left;
        }

        .user-info .name {
            font-size: 0.9375rem;
            font-weight: 600;
        }

        .user-info .role {
            font-size: 0.8125rem;
            color: var(--text-light);
        }

        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            min-width: 200px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.2s ease;
            z-index: 100;
        }

        .dropdown-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--text);
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .dropdown-item:hover {
            background: var(--bg);
        }

        .dropdown-item:first-child {
            border-radius: 12px 12px 0 0;
        }

        .dropdown-item:last-child {
            border-radius: 0 0 12px 12px;
            color: var(--danger);
        }

        /* Content Area */
        .content {
            padding: 32px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow);
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
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

        .stat-icon.blue { background: #dbeafe; color: #2563eb; }
        .stat-icon.orange { background: #fef3c7; color: #d97706; }
        .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-icon.red { background: #fee2e2; color: #dc2626; }

        .stat-trend {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 6px;
        }

        .stat-trend.up { background: #d1fae5; color: #059669; }
        .stat-trend.down { background: #fee2e2; color: #dc2626; }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--text-light);
        }

        /* Module Cards */
        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modules-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }

        .module-card {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
            position: relative;
            overflow: hidden;
        }

        .module-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }

        .module-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .module-card:hover::before {
            transform: scaleY(1);
        }

        .module-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .module-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .module-count {
            background: var(--bg);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-light);
        }

        .module-title {
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .module-desc {
            font-size: 0.875rem;
            color: var(--text-light);
            margin-bottom: 16px;
            line-height: 1.5;
        }

        .module-action {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--primary);
            font-size: 0.875rem;
            font-weight: 600;
        }

        /* Recent Documents */
        .documents-section {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .view-all {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .documents-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .document-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            background: var(--bg);
            border-radius: 12px;
            transition: all 0.2s ease;
        }

        .document-item:hover {
            background: #e2e8f0;
        }

        .doc-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .doc-icon.pdf { background: #fee2e2; color: #dc2626; }
        .doc-icon.doc { background: #dbeafe; color: #2563eb; }
        .doc-icon.img { background: #d1fae5; color: #059669; }

        .doc-info {
            flex: 1;
        }

        .doc-title {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .doc-meta {
            font-size: 0.75rem;
            color: var(--text-light);
            display: flex;
            gap: 12px;
        }

        .doc-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-processing { background: #dbeafe; color: #1e40af; }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 48px;
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--border);
            margin-bottom: 16px;
        }

        .empty-state h3 {
            font-size: 1.125rem;
            color: var(--text);
            margin-bottom: 8px;
        }

        .empty-state p {
            color: var(--text-light);
        }

        /* --- Optimized Student Carousel --- */
        .carousel-card {
            width: 100%;
            height: 220px;
            background: var(--card);
            border-radius: 16px;
            border: 1px solid var(--border);
            margin: 10px 0 40px 0;
            overflow: hidden;
            position: relative;
        }

        .carousel-inner {
            display: flex;
            width: 300%;
            height: 100%;
            animation: slideAction 9s infinite ease-in-out;
        }

        .carousel-inner img {
            width: 33.333%;
            height: 100%;
            object-fit: cover;
            filter: brightness(0.7);
        }

        @keyframes slideAction {
            0%, 28% { transform: translateX(0); }
            33%, 61% { transform: translateX(-33.333%); }
            66%, 94% { transform: translateX(-66.666%); }
            100% { transform: translateX(0); }
        }

        .carousel-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 15px 20px;
            background: linear-gradient(transparent, rgba(15, 23, 42, 0.9));
            pointer-events: none;
        }

        .carousel-overlay h4 {
            margin: 0;
            color: var(--primary);
            font-size: 1rem;
        }

        .carousel-overlay p {
            margin: 2px 0 0 0;
            color: #cbd5e1;
            font-size: 0.8rem;
        }

        .carousel-dots {
            position: absolute;
            bottom: 15px;
            right: 20px;
            display: flex;
            gap: 5px;
        }

        .dot {
            width: 6px;
            height: 6px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .modules-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .main-content {
                margin-left: 0;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .modules-grid {
                grid-template-columns: 1fr;
            }
            .page-title h1 {
                font-size: 1.5rem;
            }
            .page-title .welcome-text {
                font-size: 0.875rem;
            }
            .notification-dropdown {
                min-width: 300px;
                right: -50px;
            }
        }
    </style>
</head>
<body>
    <div class="layout">
        <!-- Sidebar - ACCOUNT SECTION REMOVED -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <img src="assets/images/mut_logo.png" alt="MUT Logo">
                    <div class="logo-text">
                        <h3>MUT Portal</h3>
                        <span>Document Management</span>
                    </div>
                </div>
            </div>

            <nav class="nav-section">
                <div class="nav-title">Main Menu</div>
                <a href="index.php" class="nav-item <?php echo $filter === 'Overview' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-grid-2"></i>
                    <span>Dashboard</span>
                </a>
                <a href="?filter=Bursary" class="nav-item <?php echo $filter === 'Bursary' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-money-bill-wave"></i>
                    <span>Bursary</span>
                </a>
                <a href="?filter=Resit" class="nav-item <?php echo $filter === 'Resit' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-file-pen"></i>
                    <span>Resit</span>
                </a>
                <a href="?filter=Retake" class="nav-item <?php echo $filter === 'Retake' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-rotate"></i>
                    <span>Retake</span>
                </a>
                <a href="?filter=Fees" class="nav-item <?php echo $filter === 'Fees' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-credit-card"></i>
                    <span>Fee Overpayment</span>
                </a>
                <a href="special_exam_form.php" class="nav-item <?php echo $filter === 'Special_Exam' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-star"></i>
                    <span>Special Exam</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <a href="logout.php" class="btn-logout">
                    <i class="fa-solid fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header - ENHANCED WELCOME SECTION -->
            <header class="header">
                <div class="page-title">
                    <h1><?php echo $filter === 'Overview' ? 'Dashboard' : htmlspecialchars($filter); ?></h1>
                    <div class="welcome-text">Welcome back, <?php echo htmlspecialchars($user_name); ?></div>
                </div>

                <div class="header-actions">
                    <!-- Notification Bell -->
                    <div class="notification-wrapper">
                        <button class="btn-notification" id="notificationBtn" onclick="toggleNotifications()">
                            <i class="fa-solid fa-bell"></i>
                            <span class="badge" id="notificationBadge" style="display: <?php echo $unread_count > 0 ? 'flex' : 'none'; ?>"><?php echo $unread_count; ?></span>
                        </button>

                        <div class="notification-dropdown" id="notificationDropdown">
                            <div class="notification-header">
                                <h3>Notifications</h3>
                                <a href="all_notifications.php">View All</a>
                            </div>
                            <div class="notification-list" id="notificationList">
                                <!-- Notifications loaded via AJAX -->
                            </div>
                        </div>
                    </div>

                    <div class="user-menu" onclick="toggleUserMenu()">
                        <img src="<?php echo htmlspecialchars($display_pix); ?>" alt="Profile" class="user-avatar">
                        <div class="user-info">
                            <div class="name"><?php echo htmlspecialchars($user_name); ?></div>
                            <div class="role"><?php echo htmlspecialchars($user['course'] ?? 'Student'); ?></div>
                        </div>
                        <i class="fa-solid fa-chevron-down" style="color: var(--text-light); font-size: 0.75rem;"></i>

                        <div class="dropdown-menu" id="userDropdown">
                            <a href="profile.php" class="dropdown-item">
                                <i class="fa-solid fa-user"></i>
                                <span>My Profile</span>
                            </a>
                            <a href="all_documents.php" class="dropdown-item">
                                <i class="fa-solid fa-folder-open"></i>
                                <span>My Documents</span>
                            </a>
                            <a href="logout.php" class="dropdown-item">
                                <i class="fa-solid fa-sign-out-alt"></i>
                                <span>Logout</span>
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Content -->
            <div class="content">
                <?php if ($filter === 'Overview'): ?>
                    <!-- Carousel -->
                    <div class="carousel-card">
                        <div class="carousel-inner">
                            <img src="slide1.jpg" alt="Campus News">
                            <img src="slide2.jpg" alt="Events">
                            <img src="slide3.jpg" alt="Notices">
                            <img src="slide4.jpg" alt="Notices">
                            <img src="slide5.jpg" alt="Notices">
                            <img src="slide6.jpg" alt="Notices">
                            <img src="slide7.jpg" alt="Notices">
                        </div>
                        <div class="carousel-overlay">
                            <h4>MUT Student News & Updates</h4>
                            <p>Stay updated with campus events, deadlines, and notices</p>
                        </div>
                        <div class="carousel-dots">
                            <div class="dot"></div>
                            <div class="dot"></div>
                            <div class="dot"></div>
                        </div>
                    </div>

                    <!-- Stats Grid -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon blue">
                                    <i class="fa-solid fa-file-lines"></i>
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['total']; ?></div>
                            <div class="stat-label">Total Documents</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon orange">
                                    <i class="fa-solid fa-clock"></i>
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['pending']; ?></div>
                            <div class="stat-label">Pending Review</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon green">
                                    <i class="fa-solid fa-check-circle"></i>
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['approved']; ?></div>
                            <div class="stat-label">Approved</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon red">
                                    <i class="fa-solid fa-chart-line"></i>
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $stats['success_rate']; ?>%</div>
                            <div class="stat-label">Success Rate</div>
                        </div>
                    </div>

                    <!-- Module Cards -->
                    <h2 class="section-title">
                        <i class="fa-solid fa-layer-group" style="color: var(--primary);"></i>
                        Quick Access
                    </h2>

                    <div class="modules-grid">
                        <a href="?filter=Bursary" class="module-card">
                            <div class="module-header">
                                <div class="module-icon" style="background: #d1fae5; color: #059669;">
                                    <i class="fa-solid fa-money-bill-wave"></i>
                                </div>
                                <span class="module-count"><?php echo getModuleCount($conn, $reg_number, 'Bursary'); ?> docs</span>
                            </div>
                            <h3 class="module-title">Bursary</h3>
                            <p class="module-desc">Upload bursary documents and track receipt status</p>
                            <div class="module-action">
                                <span>Manage Documents</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </div>
                        </a>

                        <a href="?filter=Resit" class="module-card">
                            <div class="module-header">
                                <div class="module-icon" style="background: #dbeafe; color: #2563eb;">
                                    <i class="fa-solid fa-file-pen"></i>
                                </div>
                                <span class="module-count"><?php echo getModuleCount($conn, $reg_number, 'Resit'); ?> docs</span>
                            </div>
                            <h3 class="module-title">Resit</h3>
                            <p class="module-desc">Submit resit exam registration forms</p>
                            <div class="module-action">
                                <span>Submit Form</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </div>
                        </a>

                        <a href="?filter=Retake" class="module-card">
                            <div class="module-header">
                                <div class="module-icon" style="background: #fef3c7; color: #d97706;">
                                    <i class="fa-solid fa-rotate"></i>
                                </div>
                                <span class="module-count"><?php echo getModuleCount($conn, $reg_number, 'Retake'); ?> docs</span>
                            </div>
                            <h3 class="module-title">Retake</h3>
                            <p class="module-desc">Submit retake exam registration forms</p>
                            <div class="module-action">
                                <span>Submit Form</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </div>
                        </a>

                        <a href="?filter=Fees" class="module-card">
                            <div class="module-header">
                                <div class="module-icon" style="background: #f3e8ff; color: #7c3aed;">
                                    <i class="fa-solid fa-credit-card"></i>
                                </div>
                                <span class="module-count"><?php echo getModuleCount($conn, $reg_number, 'Fees'); ?> docs</span>
                            </div>
                            <h3 class="module-title">Fee Overpayment</h3>
                            <p class="module-desc">Request adjustments for overpaid fees</p>
                            <div class="module-action">
                                <span>Submit Request</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </div>
                        </a>

                        <a href="special_exam_form.php" class="module-card">
                            <div class="module-header">
                                <div class="module-icon" style="background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white;">
                                    <i class="fa-solid fa-star"></i>
                                </div>
                                <span class="module-count"><?php echo getModuleCount($conn, $reg_number, 'Special_Exam'); ?> docs</span>
                            </div>
                            <h3 class="module-title">Special Exam</h3>
                            <p class="module-desc">Apply for special exams: Financial, Medical, or Compassionate grounds</p>
                            <div class="module-action">
                                <span>Apply Now</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </div>
                        </a>
                    </div>

                    <!-- Recent Documents -->
                    <div class="documents-section">
                        <div class="section-header">
                            <h2 class="section-title" style="margin: 0;">
                                <i class="fa-solid fa-clock-rotate-left" style="color: var(--primary);"></i>
                                Recent Documents
                            </h2>
                            <a href="all_documents.php" class="view-all">
                                View All <i class="fa-solid fa-arrow-right"></i>
                            </a>
                        </div>

                        <?php if ($recent_docs->num_rows > 0): ?>
                            <style>
                            .doc-progress-row{display:flex;align-items:center;gap:18px;background:#fff;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.06);border-radius:14px;padding:14px 18px;margin-bottom:10px;}
                            .doc-progress-info{flex:1;min-width:0;}
                            .doc-progress-title{font-weight:700;font-size:.9rem;color:#1e293b;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
                            .doc-progress-meta{font-size:.75rem;color:#64748b;display:flex;gap:10px;flex-wrap:wrap;}
                            .wf-track{display:flex;align-items:center;gap:0;flex-shrink:0;}
                            .wf-step{display:flex;flex-direction:column;align-items:center;position:relative;}
                            .wf-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;border:2px solid #cbd5e1;background:#f1f5f9;color:#94a3b8;transition:.2s;}
                            .wf-dot.done{background:#22c55e;border-color:#22c55e;color:#fff;}
                            .wf-dot.current{background:#6366f1;border-color:#6366f1;color:#fff;}
                            .wf-dot.rejected{background:#ef4444;border-color:#ef4444;color:#fff;}
                            .wf-label{font-size:.58rem;color:#94a3b8;margin-top:3px;text-align:center;white-space:nowrap;}
                            .wf-label.done{color:#16a34a;}
                            .wf-label.current{color:#6366f1;}
                            .wf-line{width:22px;height:2px;background:#e2e8f0;margin-bottom:14px;flex-shrink:0;}
                            .wf-line.done{background:#22c55e;}
                            .doc-cur-status{flex-shrink:0;font-size:.72rem;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;}
                            .cs-approved{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;}
                            .cs-rejected{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
                            .cs-pending{background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;}
                            .wf-col-hdr{display:flex;align-items:center;gap:18px;padding:4px 18px 8px;margin-bottom:4px;}
                            .wf-col-hdr-info{flex:1;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;}
                            .wf-col-hdr-track{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;flex-shrink:0;}
                            .wf-col-hdr-status{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;flex-shrink:0;min-width:90px;text-align:right;}
                            </style>
                            <!-- Column headers matching screenshot -->
                            <div class="wf-col-hdr">
                                <div class="wf-col-hdr-info">Document</div>
                                <div class="wf-col-hdr-track">Workflow Progress</div>
                                <div class="wf-col-hdr-status">Current Status</div>
                            </div>
                            <?php 
                            $recent_docs->data_seek(0);
                            while ($doc = $recent_docs->fetch_assoc()):
                                $st = $doc['status'];
                                $mod = $doc['module_type'];
                                // Determine workflow steps based on module type
                                // Resit/Retake/Special_Exam Ph2: COD→Dean→Finance
                                // Bursary: Finance only
                                // Fees: Registrar→Finance
                                // Special_Exam Ph1: Dean→Registrar→DVC
                                if (in_array($mod, ['Resit','Retake'])) {
                                    $steps = [
                                        ['icon'=>'fa-user-tie','label'=>'COD','done'=>in_array($st,['Pending_Dean','Pending_Finance','Approved','Rejected'])&&$doc['cod_approved'],'current'=>$st==='Pending_COD','rejected'=>false],
                                        ['icon'=>'fa-user-graduate','label'=>'Dean','done'=>in_array($st,['Pending_Finance','Approved'])&&$doc['dean_approved'],'current'=>$st==='Pending_Dean','rejected'=>false],
                                        ['icon'=>'fa-building-columns','label'=>'Finance','done'=>$st==='Approved'&&$doc['finance_approved'],'current'=>$st==='Pending_Finance','rejected'=>false],
                                        ['icon'=>'fa-flag','label'=>'Done','done'=>$st==='Approved','current'=>false,'rejected'=>$st==='Rejected'],
                                    ];
                                } elseif ($mod === 'Bursary') {
                                    $steps = [
                                        ['icon'=>'fa-building-columns','label'=>'Finance','done'=>$st==='Approved','current'=>$st==='Pending_Finance','rejected'=>false],
                                        ['icon'=>'fa-flag','label'=>'Done','done'=>$st==='Approved','current'=>false,'rejected'=>$st==='Rejected'],
                                    ];
                                } elseif ($mod === 'Fees') {
                                    $steps = [
                                        ['icon'=>'fa-user-shield','label'=>'Registrar','done'=>in_array($st,['Pending_Finance','Approved']),'current'=>$st==='Pending_Registrar','rejected'=>false],
                                        ['icon'=>'fa-building-columns','label'=>'Finance','done'=>$st==='Approved','current'=>$st==='Pending_Finance','rejected'=>false],
                                        ['icon'=>'fa-flag','label'=>'Done','done'=>$st==='Approved','current'=>false,'rejected'=>$st==='Rejected'],
                                    ];
                                } else { // Special_Exam
                                    $steps = [
                                        ['icon'=>'fa-user-tie','label'=>'COD','done'=>in_array($st,['Pending_Dean','Pending_Registrar','Pending_Finance','Pending_DVC','Approved'])&&$doc['cod_approved'],'current'=>$st==='Pending_COD','rejected'=>false],
                                        ['icon'=>'fa-user-graduate','label'=>'Dean','done'=>in_array($st,['Pending_Registrar','Pending_Finance','Pending_DVC','Approved'])&&$doc['dean_approved'],'current'=>$st==='Pending_Dean','rejected'=>false],
                                        ['icon'=>'fa-user-shield','label'=>'Registrar','done'=>in_array($st,['Pending_DVC','Approved'])&&$doc['registrar_approved'],'current'=>$st==='Pending_Registrar','rejected'=>false],
                                        ['icon'=>'fa-flag','label'=>'Done','done'=>$st==='Approved','current'=>false,'rejected'=>$st==='Rejected'],
                                    ];
                                }
                                // Current status label
                                $statusMap = ['Pending_COD'=>'At COD','Pending_Dean'=>'Pending Dean','Pending_Registrar'=>'At Registrar','Pending_Finance'=>'Pending Finance','Pending_DVC'=>'Pending DVC','Approved'=>'Approved','Rejected'=>'Rejected'];
                                $statusLabel = $statusMap[$st] ?? str_replace('_',' ',$st);
                                $statusCls = $st==='Approved' ? 'cs-approved' : ($st==='Rejected' ? 'cs-rejected' : 'cs-pending');
                            ?>
                            <div class="doc-progress-row">
                                <div class="doc-progress-info">
                                    <div class="doc-progress-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                                    <div class="doc-progress-meta">
                                        <span><?php echo htmlspecialchars($mod); ?></span>
                                        <span><?php echo date('M d, Y', strtotime($doc['upload_date'])); ?></span>
                                    </div>
                                </div>
                                <div class="wf-track">
                                    <?php foreach ($steps as $si => $step):
                                        $dc = $step['rejected'] ? 'rejected' : ($step['done'] ? 'done' : ($step['current'] ? 'current' : ''));
                                        $lc = $step['rejected'] ? 'rejected' : ($step['done'] ? 'done' : ($step['current'] ? 'current' : ''));
                                    ?>
                                    <?php if ($si > 0): ?>
                                    <div class="wf-line <?php echo ($steps[$si-1]['done']||$steps[$si-1]['rejected']) ? 'done' : ''; ?>"></div>
                                    <?php endif; ?>
                                    <div class="wf-step">
                                        <div class="wf-dot <?php echo $dc; ?>">
                                            <?php if ($step['done']): ?><i class="fa-solid fa-check"></i>
                                            <?php elseif ($step['rejected']): ?><i class="fa-solid fa-times"></i>
                                            <?php else: ?><i class="fa-solid <?php echo $step['icon']; ?>"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="wf-label <?php echo $lc; ?>"><?php echo $step['label']; ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <span class="doc-cur-status <?php echo $statusCls; ?>"><?php echo $statusLabel; ?></span>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fa-solid fa-folder-open"></i>
                                <h3>No documents yet</h3>
                                <p>Start by submitting your first document using the modules above.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- Module Specific View -->
                    <?php include 'module_view.php'; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Toggle user dropdown
        function toggleUserMenu() {
            document.getElementById('userDropdown').classList.toggle('show');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.user-menu')) {
                document.getElementById('userDropdown').classList.remove('show');
            }
            if (!e.target.closest('.notification-wrapper')) {
                document.getElementById('notificationDropdown').classList.remove('show');
            }
        });

        // Notification System
        let notificationDropdown = document.getElementById('notificationDropdown');
        let notificationList = document.getElementById('notificationList');
        let notificationBadge = document.getElementById('notificationBadge');
        let isNotificationOpen = false;

        function toggleNotifications() {
            isNotificationOpen = !isNotificationOpen;
            notificationDropdown.classList.toggle('show');

            if (isNotificationOpen) {
                // Mark notifications as read when opening
                markNotificationsAsRead();
            }
        }

        // Load notifications via AJAX
        function loadNotifications() {
            $.ajax({
                url: 'fetch_notifications.php',
                method: 'GET',
                dataType: 'json',
                success: function(data) {
                    updateNotificationUI(data);
                },
                error: function(xhr, status, error) {
                    console.error('Error loading notifications:', error);
                }
            });
        }

        // Update notification UI
        function updateNotificationUI(data) {
            // Update badge
            if (data.unread_count > 0) {
                notificationBadge.textContent = data.unread_count;
                notificationBadge.style.display = 'flex';
            } else {
                notificationBadge.style.display = 'none';
            }

            // Update list
            if (data.notifications.length > 0) {
                let html = '';
                data.notifications.forEach(function(notif) {
                    let iconClass = 'status';
                    let icon = 'fa-bell';

                    if (notif.type === 'document_upload') {
                        iconClass = 'document';
                        icon = 'fa-file-upload';
                    } else if (notif.type === 'status_update') {
                        iconClass = 'status';
                        icon = 'fa-check-circle';
                    } else if (notif.type === 'system') {
                        iconClass = 'system';
                        icon = 'fa-info-circle';
                    }

                    let unreadClass = notif.is_read == 0 ? 'unread' : '';

                    html += `
                        <div class="notification-item ${unreadClass}" data-id="${notif.id}">
                            <div class="notification-icon ${iconClass}">
                                <i class="fa-solid ${icon}"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-title">${notif.title}</div>
                                <div class="notification-text">${notif.message}</div>
                                <div class="notification-time">${notif.time_ago}</div>
                            </div>
                        </div>
                    `;
                });
                notificationList.innerHTML = html;
            } else {
                notificationList.innerHTML = `
                    <div class="notification-empty">
                        <i class="fa-solid fa-bell-slash"></i>
                        <p>No notifications yet</p>
                    </div>
                `;
            }
        }

        // Mark notifications as read
        function markNotificationsAsRead() {
            $.ajax({
                url: 'mark_notifications_read.php',
                method: 'POST',
                success: function() {
                    notificationBadge.style.display = 'none';
                    // Update UI to remove unread styling
                    document.querySelectorAll('.notification-item.unread').forEach(function(item) {
                        item.classList.remove('unread');
                    });
                }
            });
        }

        // Initial load
        loadNotifications();

        // Poll every 30 seconds for new notifications
        setInterval(loadNotifications, 30000);
    </script>
</body>
</html>