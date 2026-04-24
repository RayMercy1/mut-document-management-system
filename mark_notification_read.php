<?php
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['reg_number'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$reg_number = $_SESSION['reg_number'];
// Use 'notification_id' to match common naming or check your JS payload
$notification_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

header('Content-Type: application/json');

if ($notification_id > 0) {
    // Mark a single specific notification as read
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_reg_number = ?");
    $stmt->bind_param("is", $notification_id, $reg_number);
} else {
    // Mark ALL notifications for this user as read
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_reg_number = ? AND is_read = 0");
    $stmt->bind_param("s", $reg_number);
}

$success = $stmt->execute();
echo json_encode(['success' => $success]);
exit();
?>