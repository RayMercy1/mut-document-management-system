<?php
session_start();
require_once 'db_config.php';
require_once 'audit_functions.php';

if (!isset($_SESSION['reg_number']) || $_SESSION['role'] !== 'super_admin') {
    logAccessDenied($conn, 'delete_item.php', 'Not super_admin');
    exit("Unauthorized");
}

if (isset($_GET['id']) && isset($_GET['type'])) {
    $id = intval($_GET['id']);
    $type = $_GET['type'];
    $admin_reg = $_SESSION['reg_number'];

    if ($type === 'user') {
        // Get user info before deleting for audit log
        $user_query = "SELECT reg_number, full_name, role FROM users WHERE id = $id";
        $user_result = mysqli_query($conn, $user_query);
        
        if ($user_data = mysqli_fetch_assoc($user_result)) {
            // Prevent Super Admin from deleting themselves
            if ($user_data['reg_number'] === $admin_reg) {
                logAudit($conn, 'DELETE_DENIED', "Attempted to delete own account", $admin_reg);
                header("Location: admin_users.php?error=You cannot delete your own account");
                exit();
            }
            
            // Delete user
            $query = "DELETE FROM users WHERE id = $id";
            $target = "admin_users.php";
            
            if (mysqli_query($conn, $query)) {
                // Log the deletion
                logUserDeletion($conn, $user_data['reg_number'], $user_data['full_name']);
                header("Location: $target?success=User deleted successfully");
            } else {
                logAudit($conn, 'DELETE_FAILED', "Failed to delete user " . $user_data['reg_number'] . ": " . mysqli_error($conn));
                header("Location: $target?error=Failed to delete user");
            }
        } else {
            header("Location: admin_users.php?error=User not found");
        }
        exit();
        
    } elseif ($type === 'dept') {
        // Get department info before deleting
        $dept_query = "SELECT dept_name FROM departments WHERE id = $id";
        $dept_result = mysqli_query($conn, $dept_query);
        
        if ($dept_data = mysqli_fetch_assoc($dept_result)) {
            $dept_name = $dept_data['dept_name'];
            
            // Check if department has users assigned
            $check_users = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE department_id = $id");
            $user_count = mysqli_fetch_assoc($check_users)['count'];
            
            if ($user_count > 0) {
                logAudit($conn, 'DELETE_DENIED', "Attempted to delete department '$dept_name' with $user_count users assigned");
                header("Location: admin_departments.php?error=Cannot delete department with assigned users");
                exit();
            }
            
            $query = "DELETE FROM departments WHERE id = $id";
            $target = "admin_departments.php";
            
            if (mysqli_query($conn, $query)) {
                logAudit($conn, 'DELETE_DEPARTMENT', "Deleted department: $dept_name", $id);
                header("Location: $target?success=Department deleted successfully");
            } else {
                logAudit($conn, 'DELETE_FAILED', "Failed to delete department '$dept_name': " . mysqli_error($conn));
                header("Location: $target?error=Failed to delete department");
            }
        } else {
            header("Location: admin_departments.php?error=Department not found");
        }
        exit();
        
    } else {
        logAudit($conn, 'INVALID_DELETE', "Invalid delete type attempted: $type");
        header("Location: admin_dashboard.php?error=Invalid delete type");
        exit();
    }
} else {
    header("Location: admin_dashboard.php?error=Missing parameters");
    exit();
}
?>