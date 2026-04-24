<?php
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php");
    exit();
}

$reg_number = $_SESSION['reg_number'];
$error      = '';
$success    = '';

// Fetch user data
$stmt = $conn->prepare(
    "SELECT u.*, d.dept_name FROM users u 
     LEFT JOIN departments d ON u.department_id = d.id 
     WHERE u.reg_number = ?"
);
$stmt->bind_param("s", $reg_number);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exam_type      = sanitize($conn, $_POST['exam_type'] ?? '');
    $exam_month     = sanitize($conn, $_POST['exam_month'] ?? '');
    $exam_year      = intval($_POST['exam_year'] ?? date('Y'));
    $title          = sanitize($conn, $_POST['title'] ?? '');
    $description    = sanitize($conn, $_POST['description'] ?? '');
    $declaration    = isset($_POST['declaration']) ? 1 : 0;
    $signature_date = date('Y-m-d');
    $student_signature = $user['full_name'];
    $unit_codes  = $_POST['unit_codes'] ?? [];
    $unit_titles = $_POST['unit_titles'] ?? [];

    if (empty($exam_type) || empty($exam_month) || empty($title)) {
        $error = "Please fill in all required fields.";
    } elseif (count($unit_codes) === 0 || empty($unit_codes[0])) {
        $error = "Please add at least one unit.";
    } elseif (!$declaration) {
        $error = "You must agree to the declaration.";
    } else {
        $conn->begin_transaction();
        try {
            $module_type = match($exam_type) {
                'Special' => 'Special_Exam',
                'Resit'   => 'Resit',
                'Retake'  => 'Retake',
                default   => $exam_type
            };
            $status          = 'Pending_COD';
            $student_visible = 'Under Review – At COD Office';

            $docStmt = $conn->prepare(
                "INSERT INTO documents 
                 (reg_number, module_type, title, description, status, current_approver, student_visible_status) 
                 VALUES (?, ?, ?, ?, ?, 'cod', ?)"
            );
            $docStmt->bind_param("ssssss", $reg_number, $module_type, $title, $description, $status, $student_visible);
            $docStmt->execute();
            $document_id = $conn->insert_id;

            $formStmt = $conn->prepare(
                "INSERT INTO resit_retake_forms 
                 (document_id, reg_number, exam_type, exam_month, exam_year,
                  student_name, student_phone, student_email, course, department_id,
                  year_of_study, student_declaration, student_signature, student_signature_date, status) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending_COD')"
            );
            $formStmt->bind_param(
                "isssissssiiiss",
                $document_id, $reg_number, $exam_type, $exam_month, $exam_year,
                $user['full_name'], $user['phone'], $user['email'], $user['course'],
                $user['department_id'], $user['year_of_study'],
                $declaration, $student_signature, $signature_date
            );
            $formStmt->execute();
            $form_id = $conn->insert_id;

            $unitStmt = $conn->prepare("INSERT INTO form_units (form_id, unit_code, unit_title) VALUES (?, ?, ?)");
            for ($i = 0; $i < count($unit_codes); $i++) {
                if (!empty($unit_codes[$i])) {
                    $unitStmt->bind_param("iss", $form_id, $unit_codes[$i], $unit_titles[$i]);
                    $unitStmt->execute();
                }
            }

            createNotification($conn, $reg_number,
                'Form Submitted',
                "Your {$exam_type} form has been submitted and is awaiting COD review.",
                'status_update', $document_id
            );
            logActivity($conn, $reg_number, 'Form Submission', "Submitted {$exam_type} form");

            $conn->commit();
            $success = "Your {$exam_type} form has been submitted successfully! It is now awaiting review by your COD.";

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Submission error: " . $e->getMessage();
            error_log("Form submission error: " . $e->getMessage());
        }
    }
}

// URL params for Special Exam pre-fill
$exam_type_url   = $_GET['type']  ?? $_POST['exam_type']  ?? 'Resit';
$exam_month_url  = trim($_GET['month'] ?? '');
$exam_year_url   = intval($_GET['year'] ?? 0);
$is_special_flow = ($exam_type_url === 'Special');

// Parse units from URL (format: "BCP 316 – POM, BCP 317 – Analysis")
$locked_units = [];
if ($is_special_flow && !empty($_GET['units'])) {
    $raw_units = trim($_GET['units']);
    $parts = array_filter(array_map('trim', explode(',', $raw_units)));
    foreach ($parts as $part) {
        $part = trim($part);
        if (empty($part)) continue;
        // Split on en-dash (–) or hyphen
        if (mb_strpos($part, ' – ') !== false) {
            $split = explode(' – ', $part, 2);
        } elseif (mb_strpos($part, ' - ') !== false) {
            $split = explode(' - ', $part, 2);
        } else {
            $split = [$part, ''];
        }
        $locked_units[] = [
            'code'  => trim($split[0] ?? ''),
            'title' => trim($split[1] ?? ''),
        ];
    }
}

$current_date_display = date('F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Registration Form | MUT Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Times+New+Roman:wght@400;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Times New Roman', Times, serif; background: #f5f5f5; padding: 20px; font-size: 12pt; }
        .form-container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; box-shadow: 0 0 10px rgba(0,0,0,.1); }
        .header { text-align: center; margin-bottom: 20px; position: relative; }
        .form-code { position: absolute; top: 0; right: 0; font-size: 10pt; font-weight: bold; }
        .logo { width: 80px; height: 80px; margin-bottom: 10px; }
        .university-name { font-size: 14pt; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; }
        .office-name { font-size: 11pt; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; }
        .form-title { font-size: 12pt; font-weight: bold; text-decoration: underline; margin-bottom: 20px; }
        .form-section { margin-bottom: 20px; }
        .section-header { display: flex; align-items: flex-start; margin-bottom: 10px; }
        .section-number { font-weight: bold; margin-right: 10px; min-width: 20px; }
        .section-title { font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        table, th, td { border: 1px solid #000; }
        th, td { padding: 8px; text-align: left; vertical-align: top; }
        th { background-color: #d9d9d9; font-weight: bold; text-align: center; }
        .checkbox-cell { width: 30px; text-align: center; }
        input[type="text"], input[type="email"], input[type="date"], select, textarea {
            width: 100%; border: none; background: transparent;
            font-family: 'Times New Roman', Times, serif; font-size: 11pt; padding: 2px;
        }
        input[type="text"]:focus, select:focus { outline: none; background: #ffffcc; }
        input[readonly], input[disabled] { background: #f5f5f5 !important; cursor: default; }
        .locked-value {
            display: block; text-align: center; padding: 2px 4px;
            font-family: 'Times New Roman', Times, serif; font-size: 11pt;
            background: #f5f5f5; color: #333;
        }
        .exam-type-table td { padding: 5px; }
        .exam-type-table .checkbox-col { width: 40px; text-align: center; }
        .personal-details-table td { padding: 10px; }
        .personal-details-table td:first-child { width: 40%; }
        .units-table th, .units-table td { text-align: center; padding: 8px; }
        .units-table th:first-child, .units-table td:first-child { width: 60px; }
        .units-table input { text-align: center; }
        .student-signature-display {
            font-style: italic; font-family: Georgia, 'Times New Roman', serif;
            font-size: 16pt; color: #1a1a2e; border-bottom: 2px solid #000;
            display: inline-block; min-width: 250px; padding: 4px 0; margin: 4px 0;
        }
        .declaration-text { font-size: 10pt; text-align: justify; margin-bottom: 15px; line-height: 1.5; }
        .signature-section { margin-top: 20px; }
        .signature-row { display: flex; justify-content: space-between; margin-bottom: 15px; align-items: flex-end; }
        .signature-field { flex: 1; margin-right: 20px; }
        .signature-field:last-child { margin-right: 0; }
        .approval-section { margin-top: 30px; }
        .approval-title { font-weight: bold; margin-bottom: 15px; }
        .approval-row { display: flex; margin-bottom: 15px; align-items: center; }
        .approval-label { width: 150px; font-weight: bold; }
        .approval-field { flex: 1; border-bottom: 1px solid #000; margin: 0 10px; min-height: 25px; }
        .approval-date { width: 100px; border-bottom: 1px solid #000; margin-left: 10px; min-height: 25px; }
        .payment-section { margin-top: 20px; border-top: 2px solid #000; padding-top: 15px; }
        .payment-title { font-weight: bold; margin-bottom: 10px; font-style: italic; }
        .form-actions { margin-top: 30px; text-align: center; padding-top: 20px; border-top: 2px solid #333; }
        .btn { padding: 12px 30px; margin: 0 10px; font-family: 'Times New Roman', Times, serif; font-size: 12pt; cursor: pointer; border: 2px solid #333; background: white; }
        .btn:hover { background: #f0f0f0; }
        .btn-primary { background: #333; color: white; }
        .btn-primary:hover { background: #555; }
        .alert { padding: 15px; margin-bottom: 20px; border: 2px solid; font-weight: bold; }
        .alert-error { background: #fee; border-color: #c00; color: #c00; }
        .alert-success { background: #efe; border-color: #0c0; color: #060; }
        .required { color: #c00; font-weight: bold; }
        input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .unit-actions { text-align: center; margin-top: 10px; }
        .btn-unit { padding: 5px 15px; font-size: 10pt; margin: 0 5px; cursor: pointer; border: 1px solid #333; background: white; font-family: 'Times New Roman', Times, serif; }
        .btn-unit:hover { background: #f0f0f0; }
        .btn-remove-unit { color: #c00; border-color: #c00; }
        .btn-remove-unit:hover { background: #fee; }
        @media print {
            body { background: white; padding: 0; }
            .form-container { box-shadow: none; padding: 0; }
            .form-actions, .unit-actions, .no-print { display: none; }
            input, select { border: none !important; }
        }
    </style>
</head>
<body>
<div class="form-container">
    <div class="header">
        <div class="form-code">MUT/F/ASAA/015</div>
        <img src="assets/images/mut_logo.png" alt="MUT Logo" class="logo">
        <div class="university-name">MURANG'A UNIVERSITY OF TECHNOLOGY</div>
        <div class="office-name">OFFICE OF REGISTRAR (Academic and Student Affairs)</div>
        <div class="form-title">SPECIAL/RESIT/RETAKE EXAM REGISTRATION FORM</div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <div class="form-actions">
            <a href="index.php" class="btn btn-primary">Back to Dashboard</a>
        </div>
    <?php else: ?>

    <form method="POST" action="" id="examForm">
        <input type="hidden" name="title" id="formTitle" value="">

        <!-- Section 1: Examination Type -->
        <div class="form-section">
            <div class="section-header">
                <span class="section-number">1.</span>
                <span class="section-title">For which Examination do you wish to register for?</span>
            </div>
            <table class="exam-type-table">
                <tr>
                    <td class="checkbox-col">
                        <?php if ($is_special_flow): ?>
                            <!-- Hidden input carries the value; visual checkbox is locked -->
                            <input type="hidden" name="exam_type" value="Special">
                            <input type="checkbox" checked disabled style="width:18px;height:18px;">
                        <?php else: ?>
                            <input type="radio" name="exam_type" value="Special" id="special"
                                <?php echo $exam_type_url === 'Special' ? 'checked' : ''; ?> required>
                        <?php endif; ?>
                    </td>
                    <td><label for="special">Special Exam</label></td>

                    <td class="checkbox-col">
                        <?php if ($is_special_flow): ?>
                            <input type="checkbox" disabled style="width:18px;height:18px;">
                        <?php else: ?>
                            <input type="radio" name="exam_type" value="Resit" id="resit"
                                <?php echo $exam_type_url === 'Resit' ? 'checked' : ''; ?>>
                        <?php endif; ?>
                    </td>
                    <td><label for="resit">Resit Exam</label></td>

                    <td class="checkbox-col">
                        <?php if ($is_special_flow): ?>
                            <input type="checkbox" disabled style="width:18px;height:18px;">
                        <?php else: ?>
                            <input type="radio" name="exam_type" value="Retake" id="retake"
                                <?php echo $exam_type_url === 'Retake' ? 'checked' : ''; ?>>
                        <?php endif; ?>
                    </td>
                    <td><label for="retake">Retake Exam</label></td>
                </tr>
            </table>
        </div>

        <!-- Section 2: Examination Period -->
        <div class="form-section">
            <div class="section-header">
                <span class="section-number">2.</span>
                <span class="section-title">Examination Period</span>
            </div>
            <table>
                <tr><th>Month</th><th>Year</th></tr>
                <tr>
                    <td>
                        <?php if ($is_special_flow && !empty($exam_month_url)): ?>
                            <!-- Locked: hidden input submits, span displays -->
                            <input type="hidden" name="exam_month" value="<?php echo htmlspecialchars($exam_month_url); ?>">
                            <span class="locked-value"><?php echo htmlspecialchars($exam_month_url); ?></span>
                        <?php else: ?>
                            <select name="exam_month" required style="text-align:center;">
                                <option value="">Select Month</option>
                                <?php foreach (['December','April','August'] as $m): ?>
                                <option value="<?php echo $m; ?>"
                                    <?php echo ($m === ($_POST['exam_month'] ?? '')) ? 'selected' : ''; ?>>
                                    <?php echo $m; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($is_special_flow && $exam_year_url > 0): ?>
                            <!-- Locked: hidden input submits, span displays -->
                            <input type="hidden" name="exam_year" value="<?php echo $exam_year_url; ?>">
                            <span class="locked-value"><?php echo $exam_year_url; ?></span>
                        <?php else: ?>
                            <select name="exam_year" required style="text-align:center;">
                                <?php for ($y = date('Y'); $y <= date('Y') + 2; $y++): ?>
                                    <option value="<?php echo $y; ?>"
                                        <?php echo $y == intval($_POST['exam_year'] ?? date('Y')) ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Section 3: Personal Details (auto-filled, read-only) -->
        <div class="form-section">
            <div class="section-header">
                <span class="section-number">3.</span>
                <span class="section-title">Personal Details</span>
            </div>
            <table class="personal-details-table">
                <tr><td>Student Name</td><td><input type="text" value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly></td></tr>
                <tr><td>Student Registration Number</td><td><input type="text" value="<?php echo htmlspecialchars($user['reg_number']); ?>" readonly></td></tr>
                <tr><td>Cell phone</td><td><input type="text" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" readonly></td></tr>
                <tr><td>Email</td><td><input type="text" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" readonly></td></tr>
                <tr><td>Course</td><td><input type="text" value="<?php echo htmlspecialchars($user['course'] ?? ''); ?>" readonly></td></tr>
                <tr><td>Department</td><td><input type="text" value="<?php echo htmlspecialchars($user['dept_name'] ?? ''); ?>" readonly></td></tr>
            </table>
        </div>

        <!-- Section 4: Units -->
        <div class="form-section">
            <div class="section-header">
                <span class="section-number">4.</span>
                <span class="section-title">Units to be written</span>
            </div>
            <table class="units-table">
                <thead>
                    <tr><th>S/No</th><th>Unit Code</th><th>Unit Title</th></tr>
                </thead>
                <tbody id="unitsBody">
                    <?php if ($is_special_flow && !empty($locked_units)): ?>
                        <?php foreach ($locked_units as $idx => $u): ?>
                        <tr>
                            <td><?php echo $idx + 1; ?></td>
                            <td>
                                <input type="hidden" name="unit_codes[]" value="<?php echo htmlspecialchars($u['code']); ?>">
                                <input type="text" value="<?php echo htmlspecialchars($u['code']); ?>" readonly style="background:#f5f5f5;cursor:default;">
                            </td>
                            <td>
                                <input type="hidden" name="unit_titles[]" value="<?php echo htmlspecialchars($u['title']); ?>">
                                <input type="text" value="<?php echo htmlspecialchars($u['title']); ?>" readonly style="background:#f5f5f5;cursor:default;">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <tr>
                            <td><?php echo $i; ?></td>
                            <td><input type="text" name="unit_codes[]" <?php echo $i === 1 ? 'placeholder="e.g. BIT 2101" required' : ''; ?>></td>
                            <td><input type="text" name="unit_titles[]" <?php echo $i === 1 ? 'placeholder="e.g. Database Systems" required' : ''; ?>></td>
                        </tr>
                        <?php endfor; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if (!$is_special_flow): ?>
            <div class="unit-actions no-print">
                <button type="button" class="btn-unit" onclick="addUnitRow()">+ Add Row</button>
                <button type="button" class="btn-unit btn-remove-unit" onclick="removeUnitRow()">− Remove Last Row</button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Section 5: Declaration + Signature -->
        <div class="form-section">
            <p class="declaration-text">
                <strong>DECLARATION BY STUDENT:</strong> I agree to abide by the rules and procedures governing
                Murang'a University of Technology examinations. I understand that I must take my identity document
                with me to write my examination and that I have 14 consecutive days from the Examination
                Registration Closing Date to follow up on my examination registration status. I also declare
                that I have successfully completed the compulsory assignments for the above subject(s).
            </p>
            <div class="signature-section">
                <div class="signature-row">
                    <div class="signature-field">
                        <input type="checkbox" name="declaration" id="declaration" required style="width:auto;margin-right:10px;">
                        <label for="declaration"><strong>I agree to the above declaration</strong> <span class="required">*</span></label>
                    </div>
                </div>
                <div class="signature-row" style="align-items:flex-end; margin-top:16px;">
                    <div class="signature-field">
                        <label style="display:block;margin-bottom:6px;font-weight:bold;">Student Signature</label>
                        <div class="student-signature-display"><?php echo htmlspecialchars($user['full_name']); ?></div>
                        <p style="font-size:9pt;color:#666;font-style:italic;margin-top:4px;">Signature auto-generated from your login credentials</p>
                    </div>
                    <div class="signature-field" style="text-align:right;max-width:200px;">
                        <label style="display:block;margin-bottom:6px;font-weight:bold;">Date</label>
                        <input type="text" value="<?php echo $current_date_display; ?>" readonly
                               style="border-bottom:1px solid #000;text-align:center;background:#f5f5f5;">
                        <p style="font-size:9pt;color:#666;font-style:italic;margin-top:4px;">Auto-filled with today's date</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approval Section -->
        <div class="approval-section">
            <div class="approval-title">Recommended By:</div>
            <div class="approval-row">
                <span class="approval-label">CoD (Name)</span>
                <span class="approval-field"></span>
                <span style="margin:0 10px;">Date:</span>
                <span class="approval-date"></span>
            </div>
            <div class="approval-title">Approved By:</div>
            <div class="approval-row">
                <span class="approval-label">Dean (Name)</span>
                <span class="approval-field"></span>
                <span style="margin:0 10px;">Date:</span>
                <span class="approval-date"></span>
            </div>
        </div>

        <!-- Payment Section -->
        <div class="payment-section">
            <div class="payment-title">Confirmation of Payment:</div>
            <div class="approval-row">
                <span class="approval-label">Amount Paid:</span>
                <span class="approval-field" style="flex:0.5;"></span>
                <span style="margin:0 20px;"></span>
                <span class="approval-label">Signature &amp; Stamp:</span>
                <span class="approval-field" style="flex:0.5;"></span>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="form-actions no-print">
            <a href="index.php" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary" onclick="return validateForm()">Submit Form</button>
        </div>
    </form>

    <?php endif; ?>
</div>

<script>
const isSpecialFlow = <?php echo $is_special_flow ? 'true' : 'false'; ?>;
let rowCount = 5;

function addUnitRow() {
    rowCount++;
    const tbody = document.getElementById('unitsBody');
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${rowCount}</td>
        <td><input type="text" name="unit_codes[]"></td>
        <td><input type="text" name="unit_titles[]"></td>`;
    tbody.appendChild(tr);
}

function removeUnitRow() {
    const tbody = document.getElementById('unitsBody');
    if (tbody.children.length > 1) {
        tbody.removeChild(tbody.lastElementChild);
        rowCount--;
    }
}

function validateForm() {
    let examTypeValue = '';
    let monthValue    = '';
    let yearValue     = '';

    if (isSpecialFlow) {
        // Values come from hidden inputs — already locked
        examTypeValue = document.querySelector('input[type="hidden"][name="exam_type"]').value;
        monthValue    = document.querySelector('input[type="hidden"][name="exam_month"]').value;
        yearValue     = document.querySelector('input[type="hidden"][name="exam_year"]').value;
    } else {
        const radio = document.querySelector('input[name="exam_type"]:checked');
        if (!radio) { alert('Please select an examination type.'); return false; }
        examTypeValue = radio.value;
        monthValue = document.querySelector('select[name="exam_month"]').value;
        yearValue  = document.querySelector('select[name="exam_year"]').value;
        if (!monthValue) { alert('Please select an examination month.'); return false; }
    }

    document.getElementById('formTitle').value = `${examTypeValue} Exam – ${monthValue} ${yearValue}`;

    // For special flow, units are pre-filled hidden inputs — skip manual check
    if (!isSpecialFlow) {
        const unitCodes = document.querySelectorAll('input[type="text"][name="unit_codes[]"]');
        let hasUnit = false;
        unitCodes.forEach(u => { if (u.value.trim()) hasUnit = true; });
        if (!hasUnit) { alert('Please add at least one unit with code and title.'); return false; }
    }

    if (!document.getElementById('declaration').checked) {
        alert('You must agree to the declaration.'); return false;
    }
    return true;
}

// Auto-update title (only for normal resit/retake flow)
if (!isSpecialFlow) {
    document.querySelectorAll('input[name="exam_type"]').forEach(r => r.addEventListener('change', updateTitle));
    document.querySelector('select[name="exam_month"]').addEventListener('change', updateTitle);
    document.querySelector('select[name="exam_year"]').addEventListener('change', updateTitle);
}

function updateTitle() {
    const examType = document.querySelector('input[name="exam_type"]:checked');
    const month    = document.querySelector('select[name="exam_month"]').value;
    const year     = document.querySelector('select[name="exam_year"]').value;
    if (examType && month && year) {
        document.getElementById('formTitle').value = `${examType.value} Exam – ${month} ${year}`;
    }
}
</script>
</body>
</html>