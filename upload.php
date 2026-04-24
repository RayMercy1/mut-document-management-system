<?php
session_start();
require_once 'db_config.php';

// Check if user is logged in
if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php");
    exit();
}

$reg_number = $_SESSION['reg_number'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $module = sanitize($conn, $_POST['module'] ?? '');
    $title = sanitize($conn, $_POST['title'] ?? '');
    $description = sanitize($conn, $_POST['desc'] ?? '');
    
    // Auto-generate title from module type if not provided
    if (empty($title)) {
        $title = $module . ' Document – ' . date('d M Y');
    }
    
    // Validate inputs
    if (empty($module)) {
        $error = "Please fill in all required fields.";
    } elseif (!isset($_FILES['myfile']) || $_FILES['myfile']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = "Please select a file to upload.";
    } else {
        $file = $_FILES['myfile'];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = "Upload error: " . getUploadErrorMessage($file['error']);
        } else {
            // Validate file size (10MB max)
            $max_size = 10 * 1024 * 1024; // 10MB
            if ($file['size'] > $max_size) {
                $error = "File size exceeds 10MB limit.";
            } else {
                // Validate file type
                $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/jpg', 'image/png'];
                $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
                
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (!in_array($file_ext, $allowed_extensions)) {
                    $error = "Invalid file type. Allowed: PDF, DOC, DOCX, JPG, JPEG, PNG.";
                } else {
                    // Sanitize reg_number for use in file paths (replace / with _)
                    $safe_reg_number = str_replace('/', '_', $reg_number);
                    
                    // Create upload directory structure
                    $upload_dir = 'uploads/' . date('Y') . '/' . date('m') . '/' . $safe_reg_number . '/';
                    
                    // Create directory recursively if it doesn't exist
                    if (!is_dir($upload_dir)) {
                        if (!mkdir($upload_dir, 0755, true)) {
                            $error = "Failed to create upload directory. Please check folder permissions.";
                        }
                    }
                    
                    // Only proceed if no error creating directory
                    if (empty($error)) {
                        // Generate unique filename
                        $new_filename = $safe_reg_number . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9.-]/', '_', $file['name']);
                        $file_path = $upload_dir . $new_filename;
                        
                        // Move uploaded file
                        if (move_uploaded_file($file['tmp_name'], $file_path)) {
                            // Determine approval workflow based on module
                            $status = 'Pending_COD';
                            $current_approver = 'cod';
                            $student_visible = 'Under Review - At COD Office';
                            
                            // Bursary → Finance Office directly
                            if ($module === 'Bursary') {
                                $status = 'Pending_Finance';
                                $current_approver = 'finance';
                                $student_visible = 'Under Review – At Finance Office';
                            }
                            // Fee Adjustment → Registrar first, then Finance
                            elseif ($module === 'Fees') {
                                $status = 'Pending_Registrar';
                                $current_approver = 'registrar';
                                $student_visible = 'Under Review – At Registrar\'s Office';
                            }
                            
                            // Insert into database
                            $stmt = $conn->prepare("INSERT INTO documents 
                                (reg_number, module_type, title, description, file_name, file_path, file_size, file_type, status, current_approver, student_visible_status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("ssssssissss", 
                                $reg_number, 
                                $module, 
                                $title, 
                                $description, 
                                $file['name'], 
                                $file_path, 
                                $file['size'], 
                                $file['type'],
                                $status,
                                $current_approver,
                                $student_visible
                            );
                            
                            if ($stmt->execute()) {
                                $document_id = $conn->insert_id;
                                
                                // Create notification — message matches actual workflow
                                if ($module === 'Bursary') {
                                    $notif_message = "Your Bursary document has been submitted and is awaiting Finance Office review.";
                                } elseif ($module === 'Fees') {
                                    $notif_message = "Your Fee Adjustment document has been submitted and is awaiting Registrar review.";
                                } else {
                                    $notif_message = "Your {$module} document has been submitted and is awaiting COD review.";
                                }
                                
                                createNotification($conn, $reg_number, 'Document Submitted', $notif_message, 'status_update', $document_id);
                                
                                // Notify Finance Office when Bursary submitted
                                if ($module === 'Bursary') {
                                    $finStaff = $conn->query("SELECT reg_number FROM users WHERE admin_role='finance' AND is_active=1");
                                    if ($finStaff) {
                                        $sname = $_SESSION['full_name'] ?? $reg_number;
                                        while ($fs = $finStaff->fetch_assoc()) {
                                            createNotification($conn, $fs['reg_number'], '📄 New Bursary Application', "A Bursary application from {$sname} is awaiting your review.", 'general', $document_id);
                                        }
                                    }
                                }
                                // Notify Registrar when Fee Adjustment submitted
                                elseif ($module === 'Fees') {
                                    $regStaff = $conn->query("SELECT reg_number FROM users WHERE admin_role='registrar' AND is_active=1");
                                    if ($regStaff) {
                                        $sname = $_SESSION['full_name'] ?? $reg_number;
                                        while ($rs = $regStaff->fetch_assoc()) {
                                            createNotification($conn, $rs['reg_number'], '📄 New Fee Adjustment Request', "A Fee Adjustment from {$sname} is awaiting your review.", 'general', $document_id);
                                        }
                                    }
                                }
                                
                                // Log activity
                                logActivity($conn, $reg_number, 'Document Upload', "Uploaded {$module} document: {$title}");
                                
                                $success = "Document uploaded successfully! " . $notif_message;
                            } else {
                                $error = "Error saving document information. Please try again.";
                                // Remove uploaded file
                                if (file_exists($file_path)) {
                                    unlink($file_path);
                                }
                            }
                            $stmt->close();
                        } else {
                            $error = "Error moving uploaded file. Please try again.";
                        }
                    }
                }
            }
        }
    }
}

function getUploadErrorMessage($error_code) {
    return match($error_code) {
        UPLOAD_ERR_INI_SIZE => 'File exceeds server maximum size.',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form maximum size.',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension.',
        default => 'Unknown upload error.'
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Document | MUT Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #22c55e;
            --primary-dark: #16a34a;
            --secondary: #0f172a;
            --bg: #f1f5f9;
            --card: #ffffff;
            --text: #1e293b;
            --text-light: #64748b;
            --border: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
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
            padding: 40px 20px;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            margin-bottom: 32px;
        }

        .header img {
            width: 80px;
            height: 80px;
            margin-bottom: 16px;
        }

        .header h1 {
            font-size: 1.5rem;
            color: var(--secondary);
        }

        .card {
            background: var(--card);
            border-radius: 20px;
            padding: 32px;
            box-shadow: var(--shadow);
        }

        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        .alert-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.2s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.1);
        }

        .file-upload-area {
            border: 2px dashed var(--border);
            border-radius: 16px;
            padding: 48px 32px;
            text-align: center;
            transition: all 0.2s ease;
            cursor: pointer;
            position: relative;
        }

        .file-upload-area:hover {
            border-color: var(--primary);
            background: rgba(34, 197, 94, 0.02);
        }

        .file-upload-area.dragover {
            border-color: var(--primary);
            background: rgba(34, 197, 94, 0.05);
        }

        .file-upload-area i {
            font-size: 4rem;
            color: var(--primary);
            margin-bottom: 16px;
        }

        .file-upload-area h3 {
            font-size: 1.125rem;
            margin-bottom: 8px;
        }

        .file-upload-area p {
            color: var(--text-light);
            margin-bottom: 16px;
        }

        .file-upload-area .file-types {
            font-size: 0.75rem;
            color: var(--text-light);
            background: var(--bg);
            padding: 8px 16px;
            border-radius: 20px;
            display: inline-block;
        }

        .file-upload-area input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .file-preview {
            display: none;
            margin-top: 16px;
            padding: 16px;
            background: var(--bg);
            border-radius: 10px;
        }

        .file-preview.show {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .file-preview i {
            font-size: 2rem;
            color: var(--primary);
        }

        .file-preview-info {
            flex: 1;
        }

        .file-preview-name {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .file-preview-size {
            font-size: 0.75rem;
            color: var(--text-light);
        }

        .btn-remove-file {
            width: 32px;
            height: 32px;
            border: none;
            background: #fee2e2;
            color: #dc2626;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .btn-remove-file:hover {
            background: #dc2626;
            color: white;
        }

        .btn-submit {
            width: 100%;
            background: var(--primary);
            color: white;
            padding: 16px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-submit:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-light);
            text-decoration: none;
            margin-bottom: 24px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .btn-back:hover {
            color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="assets/images/mut_logo.png" alt="MUT Logo">
            <h1>Upload Document</h1>
        </div>

        <a href="index.php" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="card">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fa-solid fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
                <div style="text-align: center; margin-top: 24px;">
                    <a href="index.php" class="btn-submit" style="text-decoration: none;">
                        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            <?php else: ?>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="module" value="<?php echo htmlspecialchars($_GET['module'] ?? $_POST['module'] ?? 'Bursary'); ?>">


                    <div class="form-group">
                        <label for="desc">Description (Optional)</label>
                        <textarea id="desc" name="desc" rows="3" placeholder="Add any additional information..."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Upload File</label>
                        <div class="file-upload-area" id="dropZone">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <h3>Drag & drop your file here</h3>
                            <p>or click to browse from your computer</p>
                            <span class="file-types">PDF, DOC, DOCX, JPG, PNG (Max 10MB)</span>
                            <input type="file" id="file" name="myfile" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                        </div>
                        <div class="file-preview" id="filePreview">
                            <i class="fa-solid fa-file"></i>
                            <div class="file-preview-info">
                                <div class="file-preview-name" id="fileName"></div>
                                <div class="file-preview-size" id="fileSize"></div>
                            </div>
                            <button type="button" class="btn-remove-file" onclick="removeFile()">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fa-solid fa-upload"></i> Upload Document
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('file');
        const filePreview = document.getElementById('filePreview');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');

        // Drag and drop events
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                showFilePreview(files[0]);
            }
        });

        // File input change
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                showFilePreview(fileInput.files[0]);
            }
        });

        function showFilePreview(file) {
            fileName.textContent = file.name;
            fileSize.textContent = formatFileSize(file.size);
            filePreview.classList.add('show');
            dropZone.style.display = 'none';
        }

        function removeFile() {
            fileInput.value = '';
            filePreview.classList.remove('show');
            dropZone.style.display = 'block';
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    </script>
</body>
</html>