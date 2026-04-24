<?php
session_start();
require_once 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        header("Location: login.php?error=Please fill in all fields");
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM users WHERE reg_number = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        header("Location: login.php?error=Account not found");
        exit();
    }

    $user = $result->fetch_assoc();

    if (!password_verify($password, $user['password'] ?? $user['password_hash'] ?? '')) {
        header("Location: login.php?error=Invalid credentials");
        exit();
    }

    if ($user['is_active'] != 1) {
        header("Location: login.php?error=Account is deactivated");
        exit();
    }

    
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['reg_number'] = $user['reg_number'];
    $_SESSION['full_name']  = $user['full_name'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['admin_role'] = $user['admin_role'];
    $_SESSION['email']      = $user['email'];

    unset($_SESSION['current_admin_view']);
    unset($_SESSION['selected_department']);
    unset($_SESSION['selected_school']);
    unset($_SESSION['pending_view']);

    logActivity($conn, $user['reg_number'], 'Login', 'User logged in successfully');

    if ($user['role'] === 'super_admin') {
        
        header("Location: admin_dashboard.php");
        exit();
    }

    if ($user['role'] === 'admin') {
        $admin_role = $user['admin_role'];

        $_SESSION['current_admin_view'] = $admin_role;

        switch ($admin_role) {
            case 'cod':
                $dept_stmt = $conn->prepare("SELECT department_id FROM users WHERE reg_number = ?");
                $dept_stmt->bind_param("s", $user['reg_number']);
                $dept_stmt->execute();
                $dept_row = $dept_stmt->get_result()->fetch_assoc();
                $_SESSION['selected_department'] = $dept_row['department_id'] ?? null;
                header("Location: cod_dashboard.php");
                exit();

            case 'dean':
                $_SESSION['selected_school'] = $user['school'] ?? null;
                // Fallback: derive from department if school column empty
                if (empty($_SESSION['selected_school']) && !empty($user['department_id'])) {
                    $sq = $conn->prepare("SELECT school FROM departments WHERE id = ?");
                    $sq->bind_param("i", $user['department_id']);
                    $sq->execute();
                    $_SESSION['selected_school'] = $sq->get_result()->fetch_assoc()['school'] ?? null;
                }
                header("Location: dean_dashboard.php");
                exit();

            case 'registrar':
                header("Location: registrar_dashboard.php");
                exit();

            case 'dvc_arsa':
                
                $_SESSION['current_admin_view'] = 'dvc_arsa';
                header("Location: admin_dashboard.php");
                exit();

            default:
                header("Location: admin_dashboard.php");
                exit();
        }
    }

    // Students
    header("Location: index.php");
    exit();

} else {
    header("Location: login.php");
    exit();
}
?>