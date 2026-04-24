<?php
session_start();
require_once 'db_config.php';

// If user is already logged in, redirect
if (isset($_SESSION['reg_number'])) {
    if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin') {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

$error = '';
$success = '';

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_type = $_POST['user_type'] ?? 'student';
    $reg_number = sanitize($conn, $_POST['reg_number'] ?? '');
    $email = sanitize($conn, $_POST['email'] ?? '');
    $full_name = sanitize($conn, $_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $department_id = intval($_POST['department_id'] ?? 0);
    
    // Employee-specific fields
    $employee_role = sanitize($conn, $_POST['employee_role'] ?? '');
    $admin_role = 'none';
    
    // Validation
    $phone = sanitize($conn, $_POST['phone'] ?? '');

    if (empty($reg_number) || empty($email) || empty($full_name) || empty($password)) {
        $error = "Please fill in all required fields.";
    }
    elseif (!preg_match("/^[a-zA-Z\s]+$/", $full_name)) {
        $error = "Invalid input in Full Name. Only letters allowed.";
    }
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    }
    elseif (!empty($phone) && !preg_match("/^[0-9]{10}$/", $phone)) {
        $error = "Phone number must be exactly 10 digits.";
    }
    elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    }
    elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    }
    elseif ($user_type === 'employee' && empty($employee_role)) {
        $error = "Please select your employee role.";
    }
    elseif ($department_id === 0 && !($user_type === 'employee' && $employee_role === 'registrar')) {
        $error = "Please select a department.";
    }

    // Check if user already exists
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE reg_number = ? OR email = ?");
    $checkStmt->bind_param("ss", $reg_number, $email);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        $error = "Registration number or email already exists.";
    } else {
        // Determine role and admin_role
        $role = 'student';
        $course = null;
        $year_of_study = null;
        
        if ($user_type === 'employee') {
            $role = 'admin';
            $admin_role = $employee_role; // cod, dean, or registrar
            $course = null;
            $year_of_study = null;
            // Dean only needs school; Registrar needs neither
            if ($employee_role === 'dean') {
                $department_id = 0; // no dept for Dean
            } elseif ($employee_role === 'registrar') {
                $department_id = 0; // no dept or school for Registrar
            }
        } else {
            // Student
            $course = sanitize($conn, $_POST['course'] ?? '');
            $year_of_study = intval($_POST['year_of_study'] ?? 1);
        }
        
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        // Capture school for Dean
        $school_val = null;
        if ($user_type === 'employee' && $employee_role === 'dean') {
            $school_val = sanitize($conn, $_POST['school'] ?? '');
        }
        // Nullable dept
        $dept_val = $department_id > 0 ? $department_id : null;

        // Insert user
        $insertStmt = $conn->prepare("INSERT INTO users 
            (reg_number, email, full_name, password_hash, role, admin_role, department_id, school, course, year_of_study, phone, is_active, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
        $insertStmt->bind_param("ssssssissss", $reg_number, $email, $full_name, $password_hash, $role, $admin_role, $dept_val, $school_val, $course, $year_of_study, $phone);
        
        if ($insertStmt->execute()) {
            // Log activity
            logActivity($conn, $reg_number, 'Account Created', "New $user_type account created");
            $_SESSION['register_success'] = "Account created successfully! You can now log in.";
            header("Location: login.php");
            exit();
        } else {
            $error = "Error creating account. Please try again.";
        }
    }
}

// Get departments and schools
$deptResult = $conn->query("SELECT id, dept_name, school FROM departments ORDER BY school, dept_name");
$departments = [];
$schools = [];
while ($row = $deptResult->fetch_assoc()) {
    $departments[] = $row;
    if (!in_array($row['school'], $schools)) $schools[] = $row['school'];
}

// Get courses linked to departments (safe — no crash if table missing)
$courses = [];
$courseResult = $conn->query("SELECT id, course_name, department_id FROM courses ORDER BY course_name");
if ($courseResult) {
    while ($row = $courseResult->fetch_assoc()) {
        $courses[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | MUT Document Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #22c55e;
            --glass: rgba(255,255,255,0.08);
            --glass-border: rgba(255,255,255,0.12);
            --text-main: #ffffff;
            --text-muted: rgba(255,255,255,0.6);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
            min-height: 100vh;
            /* Radial gradient matching profile.php */
            background: radial-gradient(at 0% 0%, rgba(34,197,94,0.12) 0px, transparent 50%),
                        radial-gradient(at 100% 0%, rgba(15,23,42,0.3) 0px, transparent 50%), #0f172a;
            background-attachment: fixed;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .register-container { width: 100%; max-width: 550px; }

        .logo-section { text-align: center; margin-bottom: 30px; }
        .logo-section img { width: 70px; margin-bottom: 15px; }
        .logo-section h1 { font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px; }
        .logo-section p { color: var(--text-muted); font-size: 0.9rem; }

        .glass-card {
            background: var(--glass);
            backdrop-filter: blur(15px);
            border-radius: 30px;
            padding: 35px;
            border: 1px solid var(--glass-border);
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }

        .alert { padding: 15px; border-radius: 12px; margin-bottom: 20px; font-size: 0.85rem; display: flex; align-items: center; gap: 10px; font-weight: 600; }
        .alert-error { background: rgba(239, 68, 68, 0.1); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.2); }
        .alert-success { background: rgba(34, 197, 94, 0.1); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.2); }

        .form-section-title { font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; color: var(--primary); margin: 25px 0 15px; display: flex; align-items: center; gap: 10px; }
        .form-section-title::after { content: ""; flex: 1; height: 1px; background: var(--glass-border); }

        .selector-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
        .role-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        
        .selectable-option { position: relative; cursor: pointer; }
        .selectable-option input { position: absolute; opacity: 0; }
        .selection-box {
            padding: 15px; background: rgba(255,255,255,0.04); border: 2px solid var(--glass-border);
            border-radius: 15px; text-align: center; transition: 0.3s;
        }
        .selectable-option input:checked + .selection-box { border-color: var(--primary); background: rgba(34, 197, 94, 0.1); }
        .selection-box i { display: block; font-size: 1.4rem; margin-bottom: 6px; color: var(--text-muted); }
        .selectable-option input:checked + .selection-box i { color: var(--primary); }
        .selection-box span { font-size: 0.8rem; font-weight: 700; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 8px; color: var(--text-muted); }
        .form-group label span { color: #f87171; }
        
        .form-control {
            width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--glass-border);
            border-radius: 14px; padding: 14px 18px; color: white; transition: 0.3s; font-family: inherit; font-size: 0.9rem;
        }
        .form-control:focus { outline: none; border-color: var(--primary); background: rgba(255,255,255,0.08); }
        .form-control option { background: #1e293b; }

        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }

        .password-field { position: relative; }
        .password-field i { position: absolute; right: 18px; top: 50%; transform: translateY(-50%); color: var(--text-muted); cursor: pointer; }

        .strength-bar-wrap { display: flex; gap: 5px; margin-top: 8px; }
        .strength-bar-wrap .bar { flex: 1; height: 4px; border-radius: 4px; background: rgba(255,255,255,0.1); transition: background 0.3s; }
        .strength-label { font-size: 0.75rem; font-weight: 700; margin-top: 5px; min-height: 16px; transition: color 0.3s; }

        .btn-submit {
            width: 100%; background: var(--primary); color: white; border: none; padding: 16px; border-radius: 16px;
            font-weight: 700; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 10px;
            font-size: 1rem; margin-top: 10px;
        }
        .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(34, 197, 94, 0.2); }

        .login-link { text-align: center; margin-top: 25px; font-size: 0.9rem; color: var(--text-muted); }
        .login-link a { color: var(--primary); text-decoration: none; font-weight: 700; }

        .employee-roles { display: none; }
        .employee-roles.show { display: block; }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo-section">
            <img src="assets/images/mut_logo.png" alt="MUT Logo">
            <h1>MUT Document Portal</h1>
            <p>Join the digital approval ecosystem</p>
        </div>

        <div class="glass-card">
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="" id="registerForm">
                <div class="form-section-title">Identity Type</div>
                <div class="selector-grid">
                    <label class="selectable-option">
                        <input type="radio" name="user_type" value="student" checked onchange="toggleUserType()">
                        <div class="selection-box">
                            <i class="fa-solid fa-user-graduate"></i>
                            <span>Student</span>
                        </div>
                    </label>
                    <label class="selectable-option">
                        <input type="radio" name="user_type" value="employee" onchange="toggleUserType()">
                        <div class="selection-box">
                            <i class="fa-solid fa-user-tie"></i>
                            <span>Employee</span>
                        </div>
                    </label>
                </div>

                <div class="employee-roles" id="employeeRoles">
                    <div class="form-section-title">Select Administrative Role</div>
                    <div class="role-grid">
                        <label class="selectable-option">
                            <input type="radio" name="employee_role" value="cod">
                            <div class="selection-box"><span>COD</span></div>
                        </label>
                        <label class="selectable-option">
                            <input type="radio" name="employee_role" value="dean">
                            <div class="selection-box"><span>Dean</span></div>
                        </label>
                        <label class="selectable-option">
                            <input type="radio" name="employee_role" value="registrar">
                            <div class="selection-box"><span>Registrar</span></div>
                        </label>
                    </div>
                </div>

                <div class="form-section-title">Personal Details</div>
                <div class="form-group">
                    <label>Registration / Staff Number <span>*</span></label>
                    <input type="text" name="reg_number" class="form-control" placeholder="SC232/1261/2021" required>
                </div>

                <div class="form-group">
                    <label>Full Name <span>*</span></label>
                    <input type="text" name="full_name" class="form-control" placeholder="Enter your full name" required>
                </div>

                <div class="form-group">
                    <label>Email Address <span>*</span></label>
                    <input type="email" name="email" class="form-control" placeholder="your@email.com" required autocomplete="off">
                </div>

                <div id="studentFields">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" class="form-control" placeholder="0712345678">
                    </div>
                    <div class="form-group">
                        <label>Year of Study</label>
                        <select name="year_of_study" class="form-control">
                            <option value="1">1st Year</option>
                            <option value="2">2nd Year</option>
                            <option value="3">3rd Year</option>
                            <option value="4">4th Year</option>
                        </select>
                    </div>
                </div>

                <!-- School + Department — shown/hidden by JS based on role -->
                <!-- Student: school → dept | COD: school → dept | Dean: school only | Registrar: neither -->
                <div id="schoolGroup" class="form-group">
                    <label id="schoolLabel">School <span>*</span></label>
                    <select id="schoolSelect" name="school" class="form-control" onchange="filterDeptsBySchool(this.value)">
                        <option value="">— Select School —</option>
                        <?php foreach ($schools as $sch): ?>
                        <option value="<?php echo htmlspecialchars($sch); ?>"
                            <?php echo (($_POST['school'] ?? '') === $sch) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sch); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="deptGroup" class="form-group">
                    <label>Department <span>*</span></label>
                    <select name="department_id" id="deptSelect" class="form-control" onchange="filterCoursesByDept(this.value)">
                        <option value="">— Select Department —</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>"
                            data-school="<?php echo htmlspecialchars($dept['school']); ?>"
                            <?php echo (($_POST['department_id'] ?? '') == $dept['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['dept_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Course dropdown: student only, populated from DB, filtered by department -->
                <div id="courseGroup" class="form-group" style="display:none;">
                    <label>Course <span>*</span></label>
                    <select name="course" id="courseSelect" class="form-control" disabled>
                        <option value="">— Select Department first —</option>
                        <?php foreach ($courses as $c): ?>
                        <option value="<?php echo htmlspecialchars($c['course_name']); ?>"
                            data-dept="<?php echo htmlspecialchars($c['department_id']); ?>"
                            <?php echo (($_POST['course'] ?? '') === $c['course_name']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['course_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-section-title">Security</div>
                <div class="two-col">
                    <div class="form-group">
                        <label>Password <span>*</span></label>
                        <div class="password-field">
                            <input type="password" name="password" id="password" class="form-control" required minlength="8" autocomplete="new-password" oninput="checkPasswordStrength(this.value)">
                            <i class="fa-solid fa-eye-slash" onclick="togglePassword('password', this)"></i>
                        </div>
                        <div class="strength-bar-wrap">
                            <div class="bar" id="bar1"></div>
                            <div class="bar" id="bar2"></div>
                            <div class="bar" id="bar3"></div>
                            <div class="bar" id="bar4"></div>
                        </div>
                        <div class="strength-label" id="strengthLabel"></div>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password <span>*</span></label>
                        <div class="password-field">
                            <input type="password" name="confirm_password" id="confirmPassword" class="form-control" required autocomplete="new-password">
                            <i class="fa-solid fa-eye-slash" onclick="togglePassword('confirmPassword', this)"></i>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-user-plus"></i> Create Account
                </button>
            </form>

            <div class="login-link">
                Already have an account? <a href="login.php">Sign in</a>
            </div>
        </div>
    </div>

    <script>
        function checkPasswordStrength(val) {
            const bars   = [document.getElementById('bar1'), document.getElementById('bar2'), document.getElementById('bar3'), document.getElementById('bar4')];
            const label  = document.getElementById('strengthLabel');
            let score = 0;
            if (val.length >= 6)  score++;
            if (val.length >= 10) score++;
            if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
            if (/[0-9]/.test(val) && /[^A-Za-z0-9]/.test(val)) score++;

            const levels = [
                { color: '#ef4444', text: 'Weak',        label: '🔴 Weak' },
                { color: '#f59e0b', text: 'Fair',        label: '🟡 Fair' },
                { color: '#3b82f6', text: 'Good',        label: '🔵 Good' },
                { color: '#22c55e', text: 'Strong',      label: '🟢 Strong' },
            ];

            bars.forEach((bar, i) => {
                bar.style.background = i < score ? levels[score - 1].color : 'rgba(255,255,255,0.1)';
            });

            if (val.length === 0) {
                label.textContent = '';
            } else {
                const lvl = levels[score - 1] || levels[0];
                label.textContent  = lvl.label;
                label.style.color  = lvl.color;
            }
        }

        function toggleUserType() {
            const userType = document.querySelector('input[name="user_type"]:checked').value;
            const employeeRoles = document.getElementById('employeeRoles');
            const studentFields  = document.getElementById('studentFields');

            if (userType === 'employee') {
                employeeRoles.classList.add('show');
                studentFields.style.display = 'none';
                document.querySelectorAll('input[name="employee_role"]').forEach(r => r.required = true);
            } else {
                employeeRoles.classList.remove('show');
                studentFields.style.display = 'block';
                document.querySelectorAll('input[name="employee_role"]').forEach(r => {
                    r.required = false;
                    r.checked = false;
                });
            }
            updateSchoolDeptVisibility();
        }

        // Called when employee_role radio changes
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('input[name="employee_role"]').forEach(r => {
                r.addEventListener('change', updateSchoolDeptVisibility);
            });
            // Pre-filter depts if school already selected (e.g. after POST error)
            const school = document.getElementById('schoolSelect').value;
            if (school) filterDeptsBySchool(school);
            // Pre-filter courses if dept already selected (e.g. after POST error)
            const dept = document.getElementById('deptSelect').value;
            if (dept) filterCoursesByDept(dept);
            updateSchoolDeptVisibility();
        });

        function updateSchoolDeptVisibility() {
            const userType = document.querySelector('input[name="user_type"]:checked').value;
            const schoolGroup = document.getElementById('schoolGroup');
            const deptGroup   = document.getElementById('deptGroup');
            const courseGroup = document.getElementById('courseGroup');
            const schoolSel   = document.getElementById('schoolSelect');
            const deptSel     = document.getElementById('deptSelect');

            let showSchool = true;
            let showDept   = true;

            if (userType === 'employee') {
                const roleEl = document.querySelector('input[name="employee_role"]:checked');
                const role   = roleEl ? roleEl.value : '';
                if (role === 'registrar') {
                    showSchool = false;
                    showDept   = false;
                } else if (role === 'dean') {
                    showSchool = true;
                    showDept   = false;
                } else {
                    showSchool = true;
                    showDept   = true;
                }
                // Employees never see the course dropdown
                courseGroup.style.display = 'none';
                document.getElementById('courseSelect').disabled = true;
            } else {
                // Student: show course only if a dept is already selected
                const deptVal = deptSel.value;
                if (!deptVal) {
                    courseGroup.style.display = 'none';
                    document.getElementById('courseSelect').disabled = true;
                }
            }

            schoolGroup.style.display = showSchool ? 'block' : 'none';
            deptGroup.style.display   = showDept   ? 'block' : 'none';
            schoolSel.required = showSchool;
            deptSel.required   = showDept;
        }

        function filterDeptsBySchool(school) {
            const sel = document.getElementById('deptSelect');
            Array.from(sel.options).forEach(opt => {
                if (!opt.value) return;
                opt.style.display = (!school || opt.dataset.school === school) ? '' : 'none';
            });
            // Reset dept if it doesn't belong to new school
            const cur = sel.options[sel.selectedIndex];
            if (cur && cur.value && cur.dataset.school !== school) sel.value = '';
            // Reset course when school changes
            filterCoursesByDept('');
        }

        function filterCoursesByDept(deptId) {
            const courseGroup = document.getElementById('courseGroup');
            const courseSel   = document.getElementById('courseSelect');
            const userType    = document.querySelector('input[name="user_type"]:checked').value;

            if (userType !== 'student') return;

            if (!deptId) {
                courseGroup.style.display = 'none';
                courseSel.disabled = true;
                courseSel.value = '';
                return;
            }

            // Show options that belong to this department
            let hasOptions = false;
            Array.from(courseSel.options).forEach(opt => {
                if (!opt.value) { opt.style.display = ''; return; }
                const show = opt.dataset.dept === String(deptId);
                opt.style.display = show ? '' : 'none';
                if (show) hasOptions = true;
            });

            // Reset selection if current choice doesn't belong to this dept
            const cur = courseSel.options[courseSel.selectedIndex];
            if (cur && cur.value && cur.dataset.dept !== String(deptId)) courseSel.value = '';

            if (hasOptions) {
                courseGroup.style.display = 'block';
                courseSel.disabled = false;
                courseSel.options[0].textContent = '— Select Course —';
            } else {
                courseGroup.style.display = 'none';
                courseSel.disabled = true;
            }
        }

        function togglePassword(fieldId, icon) {
            const field = document.getElementById(fieldId);
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            } else {
                field.type = 'password';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            }
        }

        toggleUserType();
    </script>
</body>
</html>