<?php
/**
 * Audit Logging Functions for MUT DMS
 * Include this file in pages where you want to log actions
 */

/**
 * Log an action to the audit_logs table
 * 
 * @param mysqli $conn Database connection
 * @param string $action Action type (e.g., 'CREATE_USER', 'DELETE_DOCUMENT', 'UPDATE_STATUS')
 * @param string $details Description of what happened
 * @param string|null $affected_record ID or identifier of the affected record (optional)
 * @return bool True if logged successfully, false otherwise
 */
function logAudit($conn, $action, $details, $affected_record = null) {
    
    $user_reg = isset($_SESSION['reg_number']) ? $_SESSION['reg_number'] : 'SYSTEM';
    
    // Get IP address
    $ip_address = getClientIP();
    
    // Sanitize inputs
    $action = mysqli_real_escape_string($conn, $action);
    $details = mysqli_real_escape_string($conn, $details);
    $affected_record = $affected_record ? mysqli_real_escape_string($conn, $affected_record) : '';
    
    $sql = "INSERT INTO audit_logs (user_reg, action, details, ip_address, affected_record) 
            VALUES ('$user_reg', '$action', '$details', '$ip_address', '$affected_record')";
    
    return mysqli_query($conn, $sql);
}

/**
 * Get client IP address
 * 
 * @return string IP address
 */
function getClientIP() {
    $ip = '';
    
    if (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED'];
    } elseif (isset($_SERVER['HTTP_X_CLUSTER_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'];
    } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
        $ip = $_SERVER['HTTP_FORWARDED'];
    } elseif (isset($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    // Handle multiple IPs (take first one)
    if (strpos($ip, ',') !== false) {
        $ips = explode(',', $ip);
        $ip = trim($ips[0]);
    }
    
    return $ip ?: 'UNKNOWN';
}

/**
 * Log user login
 * 
 * @param mysqli $conn Database connection
 * @param string $reg_number User who logged in
 * @param bool $success Whether login was successful
 */
function logLogin($conn, $reg_number, $success = true) {
    $action = $success ? 'LOGIN_SUCCESS' : 'LOGIN_FAILED';
    $details = $success ? "User $reg_number logged in successfully" : "Failed login attempt for user $reg_number";
    
    // Temporarily set session reg_number for logging
    $original_reg = isset($_SESSION['reg_number']) ? $_SESSION['reg_number'] : null;
    $_SESSION['reg_number'] = $reg_number;
    
    logAudit($conn, $action, $details, $reg_number);
    
    // Restore original session
    if ($original_reg !== null) {
        $_SESSION['reg_number'] = $original_reg;
    } elseif (!isset($_SESSION['reg_number'])) {
        unset($_SESSION['reg_number']);
    }
}

/**
 * Log user logout
 * 
 * @param mysqli $conn Database connection
 */
function logLogout($conn) {
    if (isset($_SESSION['reg_number'])) {
        logAudit($conn, 'LOGOUT', "User " . $_SESSION['reg_number'] . " logged out");
    }
}

/**
 * Log document upload
 * 
 * @param mysqli $conn Database connection
 * @param string $doc_id Document ID
 * @param string $doc_title Document title
 * @param string $module Module type
 */
function logDocumentUpload($conn, $doc_id, $doc_title, $module) {
    $details = "Uploaded $module document: $doc_title";
    logAudit($conn, 'DOCUMENT_UPLOAD', $details, $doc_id);
}

/**
 * Log document status change
 * 
 * @param mysqli $conn Database connection
 * @param string $doc_id Document ID
 * @param string $old_status Previous status
 * @param string $new_status New status
 * @param string $changed_by Who changed the status
 */
function logStatusChange($conn, $doc_id, $old_status, $new_status, $changed_by) {
    $details = "Status changed from '$old_status' to '$new_status' by $changed_by";
    logAudit($conn, 'STATUS_CHANGE', $details, $doc_id);
}

/**
 * Log user creation
 * 
 * @param mysqli $conn Database connection
 * @param string $new_user_reg Registration number of new user
 * @param string $new_user_name Full name of new user
 * @param string $role Role assigned
 */
function logUserCreation($conn, $new_user_reg, $new_user_name, $role) {
    $details = "Created new user: $new_user_name ($new_user_reg) with role: $role";
    logAudit($conn, 'CREATE_USER', $details, $new_user_reg);
}

/**
 * Log user deletion
 * 
 * @param mysqli $conn Database connection
 * @param string $deleted_user_reg Registration number of deleted user
 * @param string $deleted_user_name Full name of deleted user
 */
function logUserDeletion($conn, $deleted_user_reg, $deleted_user_name) {
    $details = "Deleted user: $deleted_user_name ($deleted_user_reg)";
    logAudit($conn, 'DELETE_USER', $details, $deleted_user_reg);
}

/**
 * Log user update
 * 
 * @param mysqli $conn Database connection
 * @param string $user_reg Registration number of updated user
 * @param string $changes Description of what was changed
 */
function logUserUpdate($conn, $user_reg, $changes) {
    $details = "Updated user $user_reg: $changes";
    logAudit($conn, 'UPDATE_USER', $details, $user_reg);
}

/**
 * Log password reset
 * 
 * @param mysqli $conn Database connection
 * @param string $user_reg User whose password was reset
 * @param bool $by_admin Whether reset was done by admin or self
 */
function logPasswordReset($conn, $user_reg, $by_admin = false) {
    $initiator = $by_admin ? "admin " . $_SESSION['reg_number'] : "self";
    $details = "Password reset for $user_reg by $initiator";
    logAudit($conn, 'PASSWORD_RESET', $details, $user_reg);
}

/**
 * Log failed access attempt
 * 
 * @param mysqli $conn Database connection
 * @param string $attempted_page Page that was accessed
 * @param string $reason Why access was denied
 */
function logAccessDenied($conn, $attempted_page, $reason) {
    $user = isset($_SESSION['reg_number']) ? $_SESSION['reg_number'] : 'GUEST';
    $details = "Access denied to $attempted_page. Reason: $reason. User: $user";
    logAudit($conn, 'ACCESS_DENIED', $details, $attempted_page);
}
?>