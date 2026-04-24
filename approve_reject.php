<?php
session_start();
require_once 'db_config.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['reg_number']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super_admin')) {
    header("Location: login.php");
    exit();
}

$reg_number = $_SESSION['reg_number'];
$user_id = $_SESSION['user_id'];
$current_role = $_SESSION['current_admin_role'] ?? $_SESSION['admin_role'] ?? 'none';

// Get action and document ID
$action = $_GET['action'] ?? '';
$document_id = intval($_GET['id'] ?? 0);

if (!$document_id || !in_array($action, ['approve', 'reject'])) {
    header("Location: admin_dashboard.php?error=Invalid request");
    exit();
}

// Fetch document details
$docStmt = $conn->prepare("SELECT d.*, u.department_id, u.full_name as student_name, u.email as student_email 
    FROM documents d 
    JOIN users u ON d.reg_number = u.reg_number 
    WHERE d.id = ?");
$docStmt->bind_param("i", $document_id);
$docStmt->execute();
$document = $docStmt->get_result()->fetch_assoc();

if (!$document) {
    header("Location: admin_dashboard.php?error=Document not found");
    exit();
}

// Verify the current user can approve this document
$can_approve = false;

if ($current_role === 'registrar' && $document['status'] === 'Pending_Registrar') {
    $can_approve = true;
} elseif ($current_role === 'dean' && $document['status'] === 'Pending_Dean') {
    // Check if dean's department matches student's department
    $can_approve = true; // Additional check can be added here
} elseif ($current_role === 'cod' && $document['status'] === 'Pending_COD') {
    // Check if COD's department matches student's department
    $can_approve = true; // Additional check can be added here
}

if (!$can_approve && $_SESSION['role'] !== 'super_admin') {
    header("Location: admin_dashboard.php?error=You are not authorized to process this document");
    exit();
}

// Handle approval/rejection
if ($action === 'approve') {
    // Determine next status based on current role
    $new_status = '';
    $new_approver = '';
    $student_visible = '';
    $notification_message = '';
    
    switch ($current_role) {
        case 'cod':
            $new_status = 'Pending_Dean';
            $new_approver = 'dean';
            $student_visible = 'Under Review - At Dean\'s Office';
            $notification_message = "Your {$document['module_type']} request has been reviewed by the COD and forwarded to the Dean.";
            
            // Update COD approval
            $updateStmt = $conn->prepare("UPDATE documents SET 
                cod_approved = TRUE,
                cod_approved_by = ?,
                cod_approved_at = NOW(),
                status = ?,
                current_approver = ?,
                student_visible_status = ?,
                updated_at = NOW()
                WHERE id = ?");
            $updateStmt->bind_param("isssi", $user_id, $new_status, $new_approver, $student_visible, $document_id);
            break;
            
        case 'dean':
            $new_status = 'Pending_Registrar';
            $new_approver = 'registrar';
            $student_visible = 'Under Review - At Registrar\'s Office';
            $notification_message = "Your {$document['module_type']} request has been approved by the Dean and forwarded to the Registrar.";
            
            // Update Dean approval
            $updateStmt = $conn->prepare("UPDATE documents SET 
                dean_approved = TRUE,
                dean_approved_by = ?,
                dean_approved_at = NOW(),
                status = ?,
                current_approver = ?,
                student_visible_status = ?,
                updated_at = NOW()
                WHERE id = ?");
            $updateStmt->bind_param("isssi", $user_id, $new_status, $new_approver, $student_visible, $document_id);
            break;
            
        case 'registrar':
            $new_status = 'Approved';
            $new_approver = 'none';
            $student_visible = 'Approved - Ready for Collection';
            $notification_message = "Your {$document['module_type']} request has been approved by the Registrar. You can now download your document.";
            
            // Update Registrar approval
            $updateStmt = $conn->prepare("UPDATE documents SET 
                registrar_approved = TRUE,
                registrar_approved_by = ?,
                registrar_approved_at = NOW(),
                status = ?,
                current_approver = ?,
                student_visible_status = ?,
                updated_at = NOW()
                WHERE id = ?");
            $updateStmt->bind_param("isssi", $user_id, $new_status, $new_approver, $student_visible, $document_id);
            break;
            
        default:
            header("Location: admin_dashboard.php?error=Invalid approver role");
            exit();
    }
    
    if ($updateStmt->execute()) {
        // Create notification for student
        createNotification($conn, $document['reg_number'], 'Document Approved', $notification_message, 'approval', $document_id);
        
        // Log activity
        logActivity($conn, $reg_number, 'Document Approved', "Approved document ID: {$document_id}");
        
        header("Location: admin_dashboard.php?success=Document approved successfully");
    } else {
        header("Location: admin_dashboard.php?error=Error approving document");
    }
    $updateStmt->close();
    
} elseif ($action === 'reject') {
    // Store rejection info (in a real system, you might want a rejection reason form)
    $rejection_reason = "Document does not meet requirements. Please review and resubmit.";
    
    $updateStmt = $conn->prepare("UPDATE documents SET 
        status = 'Rejected',
        rejection_reason = ?,
        rejected_by = ?,
        rejected_at = NOW(),
        student_visible_status = 'Rejected - Please Resubmit',
        updated_at = NOW()
        WHERE id = ?");
    $updateStmt->bind_param("sii", $rejection_reason, $user_id, $document_id);
    
    if ($updateStmt->execute()) {
        // Create notification for student
        $notification_message = "Your {$document['module_type']} request has been rejected. Reason: {$rejection_reason}";
        createNotification($conn, $document['reg_number'], 'Document Rejected', $notification_message, 'rejection', $document_id);
        
        // Log activity
        logActivity($conn, $reg_number, 'Document Rejected', "Rejected document ID: {$document_id}");
        
        header("Location: admin_dashboard.php?success=Document rejected");
    } else {
        header("Location: admin_dashboard.php?error=Error rejecting document");
    }
    $updateStmt->close();
}

exit();
?>
