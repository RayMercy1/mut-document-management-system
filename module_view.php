<?php
/**
 * Module View - Shows specific module content based on filter
 * Included in index.php when a specific module is selected
 */

// Get module-specific documents
$moduleDocsStmt = $conn->prepare("SELECT d.*, sea.application_type, sea.evidence_file_path 
    FROM documents d 
    LEFT JOIN special_exam_applications sea ON d.id = sea.document_id 
    WHERE d.reg_number = ? AND d.module_type = ? 
    ORDER BY d.upload_date DESC");
$moduleDocsStmt->bind_param("ss", $reg_number, $filter);
$moduleDocsStmt->execute();
$module_docs = $moduleDocsStmt->get_result();

// Get reference information based on module
$references = [
    'Bursary' => [
        'Provide Full Name, Registration Number, Course and Year of Study',
        'Attach clear copy/scan of the official bursary award letter',
        'Ensure student details on bursary documents match school records exactly',
        'Submit within required timelines for fee processing',
        'Provide accurate contact details for communication'
    ],
    'Resit' => [
        'Clearly indicate the Unit Code and Unit Title for each resit',
        'Include your Full Name, Registration Number and Course',
        'Confirm the exam period (December, April, or August)',
        'Submit at least two weeks before examinations',
        'Ensure all information is accurate before submission'
    ],
    'Retake' => [
        'Clearly indicate the Unit Code and Unit Title for each retake',
        'Include your Full Name, Registration Number and Course',
        'Confirm the exam period (December, April, or August)',
        'Submit at least two weeks before examinations',
        'Payment confirmation may be required'
    ],
    'Fees' => [
        'Include your Full Name, Registration Number and Course',
        'Clearly state the amount overpaid and the semester',
        'Attach proof of payment (bank slip, fee statement)',
        'Indicate preferred adjustment method (credit/refund)',
        'Include accurate contact details for follow-up'
    ],
    'Special_Exam' => [
        'Select your application type: Financial, Medical, or Compassionate',
        'Financial: No evidence needed - verified through fee status',
        'Medical: Attach medical certificate or hospital documentation',
        'Compassionate: Attach death certificate or relevant documentation',
        'Submit as early as possible before exam period'
    ]
];

$module_refs = $references[$filter] ?? [];

// Determine if this module uses file upload or form
$uses_form = in_array($filter, ['Resit', 'Retake', 'Special_Exam']);
$uses_upload = in_array($filter, ['Bursary', 'Fees']);
$is_special_exam = ($filter === 'Special_Exam');
?>

<style>
    .module-view {
        display: flex;
        flex-direction: column;
        gap: 24px;
    }

    /* GUIDELINES BANNER - NOW AT TOP */
    .guidelines-banner {
        background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
        border: 1px solid #bbf7d0;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 8px;
    }

    .guidelines-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }

    .guidelines-header i {
        font-size: 1.5rem;
        color: var(--primary);
    }

    .guidelines-header h3 {
        font-size: 1.125rem;
        font-weight: 700;
        color: #166534;
    }

    .guidelines-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 12px;
    }

    .guideline-item {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        font-size: 0.9rem;
        color: #166534;
        line-height: 1.5;
        background: rgba(255, 255, 255, 0.6);
        padding: 12px 16px;
        border-radius: 10px;
        border-left: 3px solid var(--primary);
    }

    .guideline-item i {
        color: var(--primary);
        margin-top: 2px;
        flex-shrink: 0;
    }

    .submission-card {
        background: var(--card);
        border-radius: 16px;
        padding: 24px;
        box-shadow: var(--shadow);
    }

    .card-title {
        font-size: 1.125rem;
        font-weight: 700;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
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
    .form-group textarea,
    .form-group select {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid var(--border);
        border-radius: 10px;
        font-size: 0.95rem;
        font-family: inherit;
        transition: all 0.2s ease;
    }

    .form-group input:focus,
    .form-group textarea:focus,
    .form-group select:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.1);
    }

    .form-group textarea {
        min-height: 120px;
        resize: vertical;
    }

    /* Application Type Selection */
    .app-type-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        margin-bottom: 20px;
    }

    .app-type-card {
        padding: 16px;
        border: 2px solid var(--border);
        border-radius: 12px;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .app-type-card:hover {
        border-color: var(--primary);
        background: rgba(34, 197, 94, 0.02);
    }

    .app-type-card.selected {
        border-color: var(--primary);
        background: rgba(34, 197, 94, 0.1);
    }

    .app-type-card i {
        font-size: 1.5rem;
        margin-bottom: 8px;
        display: block;
    }

    .app-type-card.financial i { color: #059669; }
    .app-type-card.medical i { color: #2563eb; }
    .app-type-card.compassionate i { color: #7c3aed; }

    .app-type-card span {
        font-size: 0.875rem;
        font-weight: 600;
    }

    /* Evidence Upload Section */
    .evidence-section {
        background: #f0f9ff;
        border: 1px solid #bae6fd;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .evidence-section h4 {
        font-size: 0.875rem;
        color: #0369a1;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .evidence-note {
        font-size: 0.8rem;
        color: #0369a1;
        margin-bottom: 12px;
        padding: 8px 12px;
        background: #e0f2fe;
        border-radius: 8px;
    }

    .file-upload {
        border: 2px dashed var(--border);
        border-radius: 12px;
        padding: 40px;
        text-align: center;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .file-upload:hover {
        border-color: var(--primary);
        background: rgba(34, 197, 94, 0.02);
    }

    .file-upload i {
        font-size: 3rem;
        color: var(--primary);
        margin-bottom: 16px;
    }

    .file-upload p {
        color: var(--text-light);
        margin-bottom: 8px;
    }

    .file-upload .hint {
        font-size: 0.75rem;
        color: var(--text-light);
    }

    .file-upload input {
        display: none;
    }

    .btn-submit {
        background: var(--primary);
        color: white;
        padding: 14px 28px;
        border: none;
        border-radius: 10px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-submit:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
    }

    .submissions-list {
        margin-top: 32px;
    }

    .submission-item {
        background: var(--bg);
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .submission-status {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }

    .status-pending { background: #fef3c7; color: #d97706; }
    .status-approved { background: #d1fae5; color: #059669; }
    .status-rejected { background: #fee2e2; color: #dc2626; }

    .submission-info {
        flex: 1;
    }

    .submission-title {
        font-weight: 600;
        margin-bottom: 4px;
    }

    .submission-type {
        font-size: 0.75rem;
        color: var(--text-light);
        display: inline-block;
        padding: 2px 8px;
        background: white;
        border-radius: 4px;
        margin-bottom: 4px;
    }

    .submission-date {
        font-size: 0.75rem;
        color: var(--text-light);
    }

    .submission-actions {
        display: flex;
        gap: 8px;
    }

    .btn-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        border: none;
        background: white;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
    }

    .btn-icon:hover {
        background: var(--primary);
        color: white;
    }

    @media (max-width: 768px) {
        .guidelines-grid {
            grid-template-columns: 1fr;
        }
        .app-type-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="module-view">
    <!-- GUIDELINES BANNER - NOW AT TOP -->
    <div class="guidelines-banner">
        <div class="guidelines-header">
            <i class="fa-solid fa-circle-info"></i>
            <h3>Submission Guidelines</h3>
        </div>
        <div class="guidelines-grid">
            <?php foreach ($module_refs as $ref): ?>
                <div class="guideline-item">
                    <i class="fa-solid fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($ref); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Submission Form - NOW FULL WIDTH -->
    <div class="submission-card">
        <h2 class="card-title">
            <i class="fa-solid fa-cloud-arrow-up" style="color: var(--primary);"></i>
            Submit New <?php echo htmlspecialchars($filter === 'Special_Exam' ? 'Special Exam' : $filter); ?> Request
        </h2>

        <?php if ($is_special_exam): ?>
            <!-- Special Exam Form with Application Types -->
            <form action="special_exam_form.php" method="POST" enctype="multipart/form-data" id="specialExamForm">
                
                <div class="form-group">
                    <label>Select Application Type <span style="color: #dc2626;">*</span></label>
                    <div class="app-type-grid">
                        <div class="app-type-card financial" onclick="selectAppType('Financial')">
                            <i class="fa-solid fa-money-bill-wave"></i>
                            <span>Financial</span>
                        </div>
                        <div class="app-type-card medical" onclick="selectAppType('Medical')">
                            <i class="fa-solid fa-notes-medical"></i>
                            <span>Medical</span>
                        </div>
                        <div class="app-type-card compassionate" onclick="selectAppType('Compassionate')">
                            <i class="fa-solid fa-heart"></i>
                            <span>Compassionate</span>
                        </div>
                    </div>
                    <input type="hidden" name="application_type" id="application_type" required>
                </div>

                <div class="form-group">
                    <label for="title">Request Title</label>
                    <input type="text" id="title" name="title" placeholder="e.g., Special Exam Application - Medical Grounds" required>
                </div>

                <div class="form-group">
                    <label for="exam_month">Examination Period <span style="color: #dc2626;">*</span></label>
                    <select id="exam_month" name="exam_month" required>
                        <option value="">Select Month</option>
                        <option value="December">December</option>
                        <option value="April">April</option>
                        <option value="August">August</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="exam_year">Year <span style="color: #dc2626;">*</span></label>
                    <select id="exam_year" name="exam_year" required>
                        <?php for ($y = date('Y'); $y <= date('Y') + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="units">Units to be Written <span style="color: #dc2626;">*</span></label>
                    <textarea id="units" name="units" placeholder="Enter unit codes and titles (e.g., BIT 2101 - Database Systems, BIT 2102 - Programming...)" required></textarea>
                </div>

                <div class="form-group">
                    <label for="reason_description">Reason/Description <span style="color: #dc2626;">*</span></label>
                    <textarea id="reason_description" name="reason_description" placeholder="Provide detailed explanation for your special exam request..." required></textarea>
                </div>

                <!-- Evidence Upload Section (for Medical and Compassionate) -->
                <div class="evidence-section" id="evidenceSection" style="display: none;">
                    <h4><i class="fa-solid fa-paperclip"></i> Supporting Evidence</h4>
                    <div class="evidence-note" id="evidenceNote"></div>
                    <div class="file-upload" onclick="document.getElementById('evidence_file').click()">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <p>Click to upload evidence document</p>
                        <span class="hint">PDF, JPG, PNG (Max 10MB)</span>
                        <input type="file" id="evidence_file" name="evidence_file" accept=".pdf,.jpg,.jpeg,.png" onchange="showEvidenceFileName(this)">
                    </div>
                    <div id="evidence-file-name" style="margin-top: 8px; font-size: 0.875rem; color: var(--primary);"></div>
                </div>

                <!-- Financial Note -->
                <div class="evidence-section" id="financialNote" style="display: none; background: #f0fdf4; border-color: #bbf7d0;">
                    <h4 style="color: #166534;"><i class="fa-solid fa-circle-info"></i> Financial Application</h4>
                    <div class="evidence-note" style="background: #dcfce7; color: #166534;">
                        No evidence attachment required. Your fee status will be verified by the Finance Office.
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-paper-plane"></i>
                    Submit Application
                </button>
            </form>

        <?php elseif ($uses_form): ?>
            <!-- Form-based submission (Resit/Retake) -->
            <form action="resit_retake_form.php" method="POST">
                <input type="hidden" name="exam_type" value="<?php echo $filter; ?>">
                
                <div class="form-group">
                    <label for="description">Additional Information <span style="font-weight:400; color: var(--text-light);">(Optional)</span></label>
                    <textarea id="description" name="description" placeholder="Provide any additional details..."></textarea>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-file-pen"></i>
                    Fill Digital Form
                </button>
            </form>
        <?php else: ?>
            <!-- File upload submission (Bursary/Fees) -->
            <form action="upload.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="module" value="<?php echo htmlspecialchars($filter); ?>">
                
                <div class="form-group">
                    <label for="desc">Description <span style="font-weight:400; color: var(--text-light);">(Optional)</span></label>
                    <textarea id="desc" name="desc" placeholder="Provide details about this document..."></textarea>
                </div>

                <div class="form-group">
                    <label>Upload Document</label>
                    <div class="file-upload" onclick="document.getElementById('file').click()">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <p>Click to upload or drag and drop</p>
                        <span class="hint">PDF, DOC, DOCX, JPG, PNG (Max 10MB)</span>
                        <input type="file" id="file" name="myfile" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required onchange="showFileName(this)">
                    </div>
                    <div id="file-name" style="margin-top: 8px; font-size: 0.875rem; color: var(--primary);"></div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-upload"></i>
                    Submit Document
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Previous Submissions -->
    <?php if ($module_docs->num_rows > 0): ?>
        <div class="submissions-list">
            <h2 class="section-title">
                <i class="fa-solid fa-history" style="color: var(--primary);"></i>
                Your Submissions
            </h2>

            <?php while ($doc = $module_docs->fetch_assoc()): 
                $status_icon = match($doc['status']) {
                    'Approved' => 'fa-check',
                    'Rejected' => 'fa-xmark',
                    default => 'fa-clock'
                };
                $status_class = match($doc['status']) {
                    'Approved' => 'status-approved',
                    'Rejected' => 'status-rejected',
                    default => 'status-pending'
                };
            ?>
                <div class="submission-item">
                    <div class="submission-status <?php echo $status_class; ?>">
                        <i class="fa-solid <?php echo $status_icon; ?>"></i>
                    </div>
                    <div class="submission-info">
                        <div class="submission-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                        <?php if ($doc['application_type']): ?>
                            <span class="submission-type"><?php echo htmlspecialchars($doc['application_type']); ?></span>
                        <?php endif; ?>
                        <div class="submission-date">
                            <?php echo date('M d, Y \a\t h:i A', strtotime($doc['upload_date'])); ?> • 
                            <?php echo getStudentStatus($doc['status']); ?>
                        </div>
                    </div>
                    <div class="submission-actions">
                        <?php if ($doc['file_path']): ?>
                            <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn-icon" title="View">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                        <?php endif; ?>
                        <?php if ($doc['evidence_file_path']): ?>
                            <a href="<?php echo htmlspecialchars($doc['evidence_file_path']); ?>" target="_blank" class="btn-icon" title="View Evidence">
                                <i class="fa-solid fa-paperclip"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    function showFileName(input) {
        if (input.files && input.files[0]) {
            document.getElementById('file-name').textContent = 'Selected: ' + input.files[0].name;
        }
    }

    function showEvidenceFileName(input) {
        if (input.files && input.files[0]) {
            document.getElementById('evidence-file-name').textContent = 'Evidence: ' + input.files[0].name;
        }
    }

    function selectAppType(type) {
        // Update hidden input
        document.getElementById('application_type').value = type;
        
        // Update visual selection
        document.querySelectorAll('.app-type-card').forEach(card => {
            card.classList.remove('selected');
        });
        event.currentTarget.classList.add('selected');
        
        // Show/hide evidence section based on type
        const evidenceSection = document.getElementById('evidenceSection');
        const evidenceNote = document.getElementById('evidenceNote');
        const financialNote = document.getElementById('financialNote');
        const evidenceFile = document.getElementById('evidence_file');
        
        if (type === 'Financial') {
            evidenceSection.style.display = 'none';
            financialNote.style.display = 'block';
            evidenceFile.removeAttribute('required');
        } else if (type === 'Medical') {
            evidenceSection.style.display = 'block';
            financialNote.style.display = 'none';
            evidenceNote.textContent = 'Please attach your medical certificate or hospital documentation.';
            evidenceFile.setAttribute('required', 'required');
        } else if (type === 'Compassionate') {
            evidenceSection.style.display = 'block';
            financialNote.style.display = 'none';
            evidenceNote.textContent = 'Please attach death certificate or other relevant documentation.';
            evidenceFile.setAttribute('required', 'required');
        }
    }

    // Form validation
    document.getElementById('specialExamForm')?.addEventListener('submit', function(e) {
        const appType = document.getElementById('application_type').value;
        if (!appType) {
            e.preventDefault();
            alert('Please select an application type (Financial, Medical, or Compassionate).');
        }
    });

    // Drag and drop
    const fileUpload = document.querySelector('.file-upload');
    
    if (fileUpload) {
        fileUpload.addEventListener('dragover', (e) => {
            e.preventDefault();
            fileUpload.style.borderColor = 'var(--primary)';
            fileUpload.style.background = 'rgba(34, 197, 94, 0.05)';
        });

        fileUpload.addEventListener('dragleave', () => {
            fileUpload.style.borderColor = 'var(--border)';
            fileUpload.style.background = 'transparent';
        });

        fileUpload.addEventListener('drop', (e) => {
            e.preventDefault();
            fileUpload.style.borderColor = 'var(--border)';
            fileUpload.style.background = 'transparent';
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                document.getElementById('file').files = files;
                showFileName(document.getElementById('file'));
            }
        });
    }
</script>