<?php
/**
 * MUT Document Management System
 * Database Configuration File
 */

// Database credentials - Update these 
define('DB_HOST', 'localhost');
define('DB_USERNAME', 'your_db_username');     
define('DB_PASSWORD', 'your_db_password');         
define('DB_NAME', 'mut_dms');

// Create database connection
$conn = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to handle special characters
$conn->set_charset("utf8mb4");

// Set timezone
date_default_timezone_set('Africa/Nairobi');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * Helper function to sanitize input
 */
function sanitize($conn, $data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $conn->real_escape_string($data);
}

/**
 * Helper function to display errors
 */
function showError($message) {
    return '<div class="error-msg"><i class="fa-solid fa-circle-exclamation"></i> ' . htmlspecialchars($message) . '</div>';
}

/**
 * Helper function to display success messages
 */
function showSuccess($message) {
    return '<div class="success-msg"><i class="fa-solid fa-check-circle"></i> ' . htmlspecialchars($message) . '</div>';
}

/**
 * Log activity
 */
function logActivity($conn, $reg_number, $action, $details = '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $stmt = $conn->prepare("INSERT INTO activity_log (user_reg_number, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $reg_number, $action, $details, $ip);
    $stmt->execute();
    $stmt->close();
}

/**
 * Create notification
 */
function createNotification($conn, $reg_number, $title, $message, $type = 'general', $document_id = null) {
    $stmt = $conn->prepare("INSERT INTO notifications (user_reg_number, title, message, type, related_document_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssi", $reg_number, $title, $message, $type, $document_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Get unread notification count
 */
function getUnreadCount($conn, $reg_number) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_reg_number = ? AND is_read = 0");
    $stmt->bind_param("s", $reg_number);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $stmt->close();
    return $count;
}

/**
 * Format file size
 */
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

/**
 * Get status badge HTML
 */
function getStatusBadge($status) {
    $badges = [
        'Draft' => '<span class="badge badge-draft">Draft</span>',
        'Pending_COD' => '<span class="badge badge-pending">Pending COD Review</span>',
        'Pending_Dean' => '<span class="badge badge-pending">At Dean\'s Office</span>',
        'Pending_Registrar' => '<span class="badge badge-pending">At Registrar\'s Office</span>',
        'Pending_Finance' => '<span class="badge badge-pending">At Finance Office</span>',
        'Approved' => '<span class="badge badge-approved">Approved</span>',
        'Rejected' => '<span class="badge badge-rejected">Rejected</span>',
        'Completed' => '<span class="badge badge-approved">Completed</span>'
    ];
    return $badges[$status] ?? '<span class="badge badge-draft">' . $status . '</span>';
}

/**
 * Get student visible status
 */
function getStudentStatus($status) {
    $statuses = [
        'Draft' => 'Draft - Not Submitted',
        'Pending_COD' => 'Under Review - At COD Office',
        'Pending_Dean' => 'Under Review - At Dean\'s Office',
        'Pending_Registrar' => 'Under Review - At Registrar\'s Office',
        'Pending_Finance'   => 'Under Review - At Finance Office',
        'Approved' => 'Approved - Ready for Collection',
        'Rejected' => 'Rejected - Please Resubmit',
        'Completed' => 'Completed'
    ];
    return $statuses[$status] ?? $status;
}
?>