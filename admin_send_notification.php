<?php
session_start();
require_once 'db_config.php';

// Check if admin is logged in
if (!isset($_SESSION['reg_number']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super_admin')) {
    header("Location: login.php");
    exit();
}

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_reg = sanitize($conn, $_POST['student_reg'] ?? '');
    $document_id = intval($_POST['document_id'] ?? 0);
    $notification_type = sanitize($conn, $_POST['notification_type'] ?? '');
    $custom_message = sanitize($conn, $_POST['custom_message'] ?? '');
    
    if (empty($student_reg) || $document_id === 0) {
        $error = "Please select a student and document.";
    } else {
        // Predefined messages
        $messages = [
            'ready_for_collection' => 'Your document has been approved and is ready for collection.',
            'approved' => 'Your document has been approved.',
            'rejected' => 'Your document has been rejected. Please contact the office for more information.',
            'additional_info' => 'Please provide additional information for your application.',
            'custom' => $custom_message
        ];
        
        $title = match($notification_type) {
            'ready_for_collection' => 'Document Ready',
            'approved' => 'Application Approved',
            'rejected' => 'Application Rejected',
            'additional_info' => 'Additional Information Required',
            'custom' => 'Notification',
            default => 'Status Update'
        };
        
        $message = $messages[$notification_type] ?? $custom_message;
        
        if (createNotification($conn, $student_reg, $title, $message, 'status_update', $document_id)) {
            $success = "Notification sent successfully to {$student_reg}!";
        } else {
            $error = "Failed to send notification.";
        }
    }
}

// Get list of approved documents that might need "ready for collection" notification
$approved_docs_query = "SELECT d.id, d.title, d.status, d.reg_number, u.full_name, d.module_type
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    WHERE d.status = 'Approved'
    ORDER BY d.updated_at DESC
    LIMIT 50";
$approved_docs = $conn->query($approved_docs_query);

// Get all students for dropdown
$students_query = "SELECT reg_number, full_name, email FROM users WHERE role = 'student' ORDER BY full_name";
$students = $conn->query($students_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Send Notification | Admin Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #1e293b;
            --text-light: #64748b;
            --border: #e2e8f0;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); }
        
        .container { max-width: 1000px; margin: 40px auto; padding: 0 20px; }
        
        .header {
            background: var(--card);
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }
        
        .header h1 { font-size: 1.5rem; margin-bottom: 8px; }
        .header p { color: var(--text-light); }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary);
            text-decoration: none;
            margin-bottom: 16px;
        }
        
        .card {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }
        
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.875rem;
        }
        .form-group label .required { color: var(--danger); }
        
        .form-group select,
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: inherit;
        }
        
        .form-group select:focus,
        .form-group input:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .notification-types {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .type-option {
            cursor: pointer;
        }
        .type-option input { display: none; }
        
        .type-box {
            padding: 16px;
            border: 2px solid var(--border);
            border-radius: 12px;
            text-align: center;
            transition: all 0.2s ease;
        }
        
        .type-box i {
            font-size: 1.5rem;
            margin-bottom: 8px;
            display: block;
        }
        
        .type-box .title {
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .type-box .desc {
            font-size: 0.75rem;
            color: var(--text-light);
            margin-top: 4px;
        }
        
        .type-option input:checked + .type-box {
            border-color: var(--primary);
            background: #eff6ff;
        }
        
        .type-option:hover .type-box {
            border-color: var(--primary);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover { background: #2563eb; }
        
        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
        }
        
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }
        
        .documents-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .documents-table th,
        .documents-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        
        .documents-table th {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--text-light);
            background: var(--bg);
        }
        
        .documents-table tr:hover { background: var(--bg); }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 0.75rem;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-approved { background: #d1fae5; color: #065f46; }
        
        h3 {
            font-size: 1.125rem;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="admin_dashboard.php" class="back-link">
            <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <div class="header">
            <h1><i class="fa-solid fa-bell" style="color: var(--primary);"></i> Send Student Notification</h1>
            <p>Send status updates and notifications to students about their documents</p>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <!-- Notification Form -->
        <div class="card">
            <h3><i class="fa-solid fa-paper-plane"></i> Compose Notification</h3>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label>Select Student <span class="required">*</span></label>
                    <select name="student_reg" required>
                        <option value="">-- Select Student --</option>
                        <?php while ($student = $students->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($student['reg_number']); ?>">
                                <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['reg_number'] . ')'); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Select Document <span class="required">*</span></label>
                    <select name="document_id" required>
                        <option value="">-- Select Document --</option>
                        <?php 
                        $students->data_seek(0);
                        $approved_docs->data_seek(0);
                        while ($doc = $approved_docs->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $doc['id']; ?>">
                                <?php echo htmlspecialchars($doc['title'] . ' - ' . $doc['full_name'] . ' (' . $doc['module_type'] . ')'); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Notification Type <span class="required">*</span></label>
                    <div class="notification-types">
                        <label class="type-option">
                            <input type="radio" name="notification_type" value="ready_for_collection" required>
                            <div class="type-box">
                                <i class="fa-solid fa-box-open" style="color: var(--success);"></i>
                                <div class="title">Ready for Collection</div>
                                <div class="desc">Document approved and ready</div>
                            </div>
                        </label>
                        
                        <label class="type-option">
                            <input type="radio" name="notification_type" value="approved">
                            <div class="type-box">
                                <i class="fa-solid fa-check-circle" style="color: var(--success);"></i>
                                <div class="title">Approved</div>
                                <div class="desc">Simple approval notice</div>
                            </div>
                        </label>
                        
                        <label class="type-option">
                            <input type="radio" name="notification_type" value="rejected">
                            <div class="type-box">
                                <i class="fa-solid fa-times-circle" style="color: var(--danger);"></i>
                                <div class="title">Rejected</div>
                                <div class="desc">Application rejected</div>
                            </div>
                        </label>
                        
                        <label class="type-option">
                            <input type="radio" name="notification_type" value="additional_info">
                            <div class="type-box">
                                <i class="fa-solid fa-info-circle" style="color: var(--warning);"></i>
                                <div class="title">Additional Info</div>
                                <div class="desc">Request more information</div>
                            </div>
                        </label>
                        
                        <label class="type-option">
                            <input type="radio" name="notification_type" value="custom">
                            <div class="type-box">
                                <i class="fa-solid fa-pen" style="color: var(--primary);"></i>
                                <div class="title">Custom Message</div>
                                <div class="desc">Write your own message</div>
                            </div>
                        </label>
                    </div>
                </div>
                
                <div class="form-group" id="customMessageGroup" style="display: none;">
                    <label>Custom Message</label>
                    <textarea name="custom_message" rows="4" placeholder="Enter your custom notification message..."></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-paper-plane"></i> Send Notification
                </button>
            </form>
        </div>
        
        <!-- Quick Actions -->
        <div class="card">
            <h3><i class="fa-solid fa-bolt"></i> Quick Actions</h3>
            <p style="margin-bottom: 16px; color: var(--text-light);">
                Recently approved documents that might need "ready for collection" notification:
            </p>
            
            <table class="documents-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Document</th>
                        <th>Type</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $approved_docs->data_seek(0);
                    $count = 0;
                    while ($doc = $approved_docs->fetch_assoc() && $count < 5): 
                        $count++;
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($doc['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($doc['title']); ?></td>
                            <td><span class="status-badge status-approved">Approved</span></td>
                            <td>
                                <button class="btn btn-primary btn-small" onclick="quickNotify('<?php echo $doc['reg_number']; ?>', <?php echo $doc['id']; ?>)">
                                    <i class="fa-solid fa-bell"></i> Notify
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
        // Show/hide custom message field
        document.querySelectorAll('input[name="notification_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const customGroup = document.getElementById('customMessageGroup');
                if (this.value === 'custom') {
                    customGroup.style.display = 'block';
                } else {
                    customGroup.style.display = 'none';
                }
            });
        });
        
        // Quick notify function
        function quickNotify(regNumber, docId) {
            document.querySelector('select[name="student_reg"]').value = regNumber;
            document.querySelector('select[name="document_id"]').value = docId;
            document.querySelector('input[value="ready_for_collection"]').checked = true;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    </script>
</body>
</html>