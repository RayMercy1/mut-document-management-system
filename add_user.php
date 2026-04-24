<?php
session_start();
require_once 'db_config.php';
require_once 'audit_functions.php';

if (!isset($_SESSION['reg_number']) || $_SESSION['role'] !== 'super_admin') {
    logAccessDenied($conn, 'add_user.php', 'Not super_admin');
    header("Location: login.php");
    exit();
}

// Fetch data for dropdowns
$depts_result = $conn->query("SELECT * FROM departments ORDER BY school, dept_name");
$all_depts = [];
while ($d = $depts_result->fetch_assoc()) $all_depts[] = $d;

$schools_result = $conn->query("SELECT DISTINCT school FROM departments WHERE school != '' ORDER BY school");
$all_schools = [];
while ($s = $schools_result->fetch_assoc()) $all_schools[] = $s['school'];

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reg_number    = trim($_POST['reg_number'] ?? '');
    $full_name     = trim($_POST['full_name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $password_raw  = $_POST['password'] ?? '';
    $display_role  = $_POST['role'] ?? 'student';
    $dept_id_post  = intval($_POST['department_id'] ?? 0);
    $student_dept_id_post = intval($_POST['student_department_id'] ?? 0);
    $school_post   = trim($_POST['school'] ?? '');

    if (empty($reg_number) || empty($full_name) || empty($password_raw)) {
        $error = "Registration number, full name, and password are required.";
    } elseif (!preg_match('/\d/', $reg_number)) {
        $error = "Registration number appears invalid — it must contain digits (e.g. SC232/0001/2024). Check you have not swapped the fields.";
    } elseif (preg_match('/\d/', $full_name)) {
        $error = "Full name appears invalid — it should contain only letters and spaces. Check you have not swapped the fields.";
    } else {
        // Check duplicate
        $chk = $conn->prepare("SELECT id FROM users WHERE reg_number = ?");
        $chk->bind_param("s", $reg_number);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = "A user with this registration number already exists.";
        }
    }

    if (empty($error)) {
        // Map display role → DB role + admin_role
        if ($display_role === 'super_admin') {
            $db_role = 'super_admin'; $db_admin_role = 'none'; $db_dept_id = null; $db_school = null;
        } elseif ($display_role === 'student') {
            $db_role = 'student'; $db_admin_role = 'none';
            $db_dept_id = $student_dept_id_post > 0 ? $student_dept_id_post : null;
            $db_school = !empty($school_post) ? $school_post : null;
        } elseif ($display_role === 'cod') {
            $db_role = 'admin'; $db_admin_role = 'cod';
            $db_dept_id = $dept_id_post > 0 ? $dept_id_post : null;
            $db_school = null;
        } elseif ($display_role === 'dean') {
            $db_role = 'admin'; $db_admin_role = 'dean';
            $db_dept_id = null;
            $db_school = !empty($school_post) ? $school_post : null;
        } elseif ($display_role === 'registrar') {
            $db_role = 'admin'; $db_admin_role = 'registrar'; $db_dept_id = null; $db_school = null;
        } elseif ($display_role === 'dvc_arsa') {
            $db_role = 'admin'; $db_admin_role = 'dvc_arsa'; $db_dept_id = null; $db_school = null;
        } else {
            $db_role = 'student'; $db_admin_role = 'none'; $db_dept_id = null; $db_school = null;
        }

        $hashed   = password_hash($password_raw, PASSWORD_DEFAULT);
        // email column is UNIQUE NOT NULL — generate placeholder if not provided
        $email_db = !empty($email) ? $email : strtolower(preg_replace('/[^a-zA-Z0-9]/', '.', $reg_number)) . '@mut.local';

        $stmt = $conn->prepare("INSERT INTO users (reg_number, full_name, email, role, admin_role, password, password_hash, department_id, school, is_active)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("sssssssis",
            $reg_number, $full_name, $email_db,
            $db_role, $db_admin_role,
            $hashed, $hashed,
            $db_dept_id,
            $db_school
        );

        if ($stmt->execute()) {
            logUserCreation($conn, $reg_number, $full_name, $display_role);
            header("Location: admin_users.php?success=" . urlencode("User '$full_name' added successfully"));
            exit();
        } else {
            $error = "Registration failed: " . $conn->error;
            logAudit($conn, 'CREATE_USER_FAILED', "Failed to create user $reg_number: " . $conn->error);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add User | MUT DMS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{--primary:#22c55e;--primary-dark:#16a34a;--bg:#0f172a;--card:#1e293b;--card2:#162032;--text:#f8fafc;--text-muted:#94a3b8;--border:#334155;--danger:#ef4444}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:30px 20px}
        .form-container{width:100%;max-width:560px;background:var(--card);border-radius:20px;border:1px solid var(--border);overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.4)}
        .form-header{background:linear-gradient(135deg,#1e293b,#0f172a);padding:28px 32px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:16px}
        .header-icon{width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:white;flex-shrink:0}
        .form-header h2{font-size:1.3rem;font-weight:700}
        .form-header p{font-size:.8rem;color:var(--text-muted);margin-top:3px}
        .form-body{padding:28px 32px}
        .alert{padding:12px 16px;border-radius:10px;margin-bottom:20px;font-size:.875rem;font-weight:600;display:flex;align-items:center;gap:10px}
        .alert-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#f87171}
        .form-group{margin-bottom:20px}
        .form-group label{display:block;font-size:.8rem;font-weight:600;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}
        .badge-optional{font-size:.7rem;background:rgba(148,163,184,.15);color:var(--text-muted);padding:2px 7px;border-radius:4px;margin-left:6px;text-transform:none;letter-spacing:0;font-weight:400}
        .form-control{width:100%;padding:12px 16px;background:var(--card2);border:1.5px solid var(--border);color:var(--text);border-radius:10px;font-size:.9rem;font-family:inherit;transition:border-color .2s}
        .form-control:focus{outline:none;border-color:var(--primary)}
        .form-control:-webkit-autofill{-webkit-box-shadow:0 0 0 30px #162032 inset!important;-webkit-text-fill-color:#f8fafc!important}
        .form-control option{background:#1e293b}
        .section-divider{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--primary);margin:24px 0 16px;padding-bottom:8px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
        .conditional{display:none}
        .conditional.visible{display:block}
        .school-dept-note{font-size:.75rem;color:var(--text-muted);margin-top:-12px;margin-bottom:16px;padding:8px 12px;background:rgba(59,130,246,.08);border-radius:6px;border-left:3px solid #3b82f6}
        .pass-wrapper{position:relative}
        .pass-toggle{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:.9rem}
        .btn-submit{width:100%;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;border:none;padding:14px;border-radius:10px;font-size:.95rem;font-weight:700;cursor:pointer;transition:all .2s;margin-top:8px;display:flex;align-items:center;justify-content:center;gap:8px}
        .btn-submit:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(34,197,94,.3)}
        .btn-cancel{display:block;text-align:center;margin-top:14px;color:var(--text-muted);text-decoration:none;font-size:.875rem}
        .btn-cancel:hover{color:var(--text)}
    </style>
</head>
<body>
<div class="form-container">
    <div class="form-header">
        <div class="header-icon"><i class="fa-solid fa-user-plus"></i></div>
        <div>
            <h2>Register New User</h2>
            <p>Add a student or staff member to the system</p>
        </div>
    </div>

    <div class="form-body">
        <?php if ($error): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">

            <div class="section-divider"><i class="fa-solid fa-id-card"></i> Account Details</div>

            <div class="form-group">
                <label>Registration / Staff Number</label>
                <input type="text" name="reg_number" class="form-control" placeholder="e.g. ST/001/2024 or STAFF/001"
                    required readonly onfocus="this.removeAttribute('readonly')"
                    value="<?php echo htmlspecialchars($_POST['reg_number'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" class="form-control" placeholder="Enter full name"
                    required readonly onfocus="this.removeAttribute('readonly')"
                    value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>Email Address <span class="badge-optional">optional</span></label>
                <input type="email" name="email" class="form-control" placeholder="user@example.com"
                    readonly onfocus="this.removeAttribute('readonly')"
                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>Default Password</label>
                <div class="pass-wrapper">
                    <input type="password" name="password" id="passwordField" class="form-control"
                        placeholder="Enter temporary password" required
                        readonly onfocus="this.removeAttribute('readonly')"
                        style="padding-right:44px;">
                    <button type="button" class="pass-toggle" onclick="togglePw()">
                        <i class="fa-solid fa-eye" id="pwIcon"></i>
                    </button>
                </div>
            </div>

            <div class="section-divider"><i class="fa-solid fa-shield-halved"></i> Role &amp; Assignment</div>

            <div class="form-group">
                <label>System Role</label>
                <select name="role" id="roleSelect" class="form-control" onchange="toggleFields()">
                    <option value="student"    <?php if(($_POST['role']??'student')==='student')    echo 'selected'; ?>>Student</option>
                    <option value="cod"        <?php if(($_POST['role']??'')==='cod')        echo 'selected'; ?>>COD – Head of Department</option>
                    <option value="dean"       <?php if(($_POST['role']??'')==='dean')       echo 'selected'; ?>>Dean</option>
                    <option value="registrar"  <?php if(($_POST['role']??'')==='registrar')  echo 'selected'; ?>>Registrar</option>
                    <option value="dvc_arsa"   <?php if(($_POST['role']??'')==='dvc_arsa')   echo 'selected'; ?>>DVC ARSA</option>
                    <option value="super_admin"<?php if(($_POST['role']??'')==='super_admin')echo 'selected'; ?>>Super Admin</option>
                </select>
            </div>

            <!-- COD Department -->
            <div id="deptGroup" class="conditional">
                <div class="form-group">
                    <label>School</label>
                    <select id="codSchoolSelect" class="form-control" onchange="filterCodDepts(this.value)">
                        <option value="">— Select School first —</option>
                        <?php foreach ($all_schools as $sname): ?>
                        <option value="<?php echo htmlspecialchars($sname); ?>"
                            <?php
                            // Pre-select school if dept was previously chosen
                            if (!empty($_POST['department_id'])) {
                                foreach ($all_depts as $chk) {
                                    if ($chk['id'] == $_POST['department_id'] && $chk['school'] === $sname) { echo 'selected'; break; }
                                }
                            }
                            ?>>
                            <?php echo htmlspecialchars($sname); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assign Department</label>
                    <input type="text" id="deptSearch" class="form-control" placeholder="Type to search…"
                        style="margin-bottom:8px;" oninput="filterDeptOpts(this.value)" autocomplete="off">
                    <select name="department_id" id="deptSelectAdd" class="form-control" size="4" style="min-height:100px;">
                        <option value="">— Select department —</option>
                        <?php foreach ($all_depts as $d): ?>
                        <option value="<?php echo $d['id']; ?>"
                            data-label="<?php echo strtolower(htmlspecialchars($d['dept_name'].' '.$d['school'])); ?>"
                            data-school="<?php echo htmlspecialchars($d['school']); ?>"
                            <?php if (($_POST['department_id'] ?? '') == $d['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($d['dept_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Student School & Department -->
            <div id="studentGroup" class="conditional">
                <div class="school-dept-note">
                    <i class="fa-solid fa-circle-info"></i>
                    Assign the student to their school and department.
                </div>
                <div class="form-group">
                    <label>School</label>
                    <select id="studentSchoolSelect" class="form-control" onchange="filterStudentDepts(this.value)">
                        <option value="">— Select School first —</option>
                        <?php foreach ($all_schools as $sname): ?>
                        <option value="<?php echo htmlspecialchars($sname); ?>"
                            <?php
                            if (!empty($_POST['student_department_id']) && ($_POST['role'] ?? '') === 'student') {
                                foreach ($all_depts as $chk) {
                                    if ($chk['id'] == $_POST['student_department_id'] && $chk['school'] === $sname) { echo 'selected'; break; }
                                }
                            }
                            ?>>
                            <?php echo htmlspecialchars($sname); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assign Department</label>
                    <input type="text" id="studentDeptSearch" class="form-control" placeholder="Type to search…"
                        style="margin-bottom:8px;" oninput="filterStudentDeptOpts(this.value)" autocomplete="off">
                    <select name="student_department_id" id="studentDeptSelect" class="form-control" size="4" style="min-height:100px;">
                        <option value="">— Select department —</option>
                        <?php foreach ($all_depts as $d): ?>
                        <option value="<?php echo $d['id']; ?>"
                            data-label="<?php echo strtolower(htmlspecialchars($d['dept_name'].' '.$d['school'])); ?>"
                            data-school="<?php echo htmlspecialchars($d['school']); ?>"
                            <?php if (($_POST['student_department_id'] ?? '') == $d['id'] && ($_POST['role'] ?? '') === 'student') echo 'selected'; ?>>
                            <?php echo htmlspecialchars($d['dept_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Dean School -->
            <div id="deanGroup" class="conditional">
                <div class="school-dept-note">
                    <i class="fa-solid fa-circle-info"></i>
                    A Dean oversees an entire school. Select the school they are responsible for.
                </div>
                <div class="form-group">
                    <label>School</label>
                    <select name="school" id="schoolSelectAdd" class="form-control">
                        <option value="">— Select School —</option>
                        <?php foreach ($all_schools as $sname): ?>
                        <option value="<?php echo htmlspecialchars($sname); ?>"
                            <?php if (($_POST['school'] ?? '') === $sname) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($sname); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-user-plus"></i> Register User
            </button>
        </form>
        <a href="admin_users.php" class="btn-cancel"><i class="fa-solid fa-arrow-left"></i> Back to Users</a>
    </div>
</div>

<script>
function toggleFields() {
    const role = document.getElementById('roleSelect').value;
    document.getElementById('deptGroup').classList.toggle('visible', role === 'cod');
    document.getElementById('deanGroup').classList.toggle('visible', role === 'dean');
    document.getElementById('studentGroup').classList.toggle('visible', role === 'student');
}

function filterStudentDepts(school) {
    const sel = document.getElementById('studentDeptSelect');
    Array.from(sel.options).forEach(o => {
        if (!o.value) return;
        o.style.display = (!school || o.dataset.school === school) ? '' : 'none';
    });
    sel.value = '';
    document.getElementById('studentDeptSearch').value = '';
}

function filterStudentDeptOpts(val) {
    const school = document.getElementById('studentSchoolSelect').value;
    const v = val.toLowerCase();
    Array.from(document.getElementById('studentDeptSelect').options).forEach(o => {
        if (!o.value) return;
        const schoolMatch = !school || o.dataset.school === school;
        const textMatch   = o.dataset.label.includes(v);
        o.style.display   = (schoolMatch && textMatch) ? '' : 'none';
    });
}

function filterCodDepts(school) {
    const sel = document.getElementById('deptSelectAdd');
    Array.from(sel.options).forEach(o => {
        if (!o.value) return;
        o.style.display = (!school || o.dataset.school === school) ? '' : 'none';
    });
    sel.value = ''; // reset dept selection when school changes
    document.getElementById('deptSearch').value = '';
}

function filterDeptOpts(val) {
    const school = document.getElementById('codSchoolSelect').value;
    const v = val.toLowerCase();
    Array.from(document.getElementById('deptSelectAdd').options).forEach(o => {
        if (!o.value) return;
        const schoolMatch = !school || o.dataset.school === school;
        const textMatch   = o.dataset.label.includes(v);
        o.style.display   = (schoolMatch && textMatch) ? '' : 'none';
    });
}

function togglePw() {
    const f = document.getElementById('passwordField');
    const i = document.getElementById('pwIcon');
    f.type = f.type === 'password' ? 'text' : 'password';
    i.className = f.type === 'password' ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
}

window.addEventListener('DOMContentLoaded', function() {
    toggleFields();
    const codSchool = document.getElementById('codSchoolSelect');
    if (codSchool && codSchool.value) filterCodDepts(codSchool.value);
    const studentSchool = document.getElementById('studentSchoolSelect');
    if (studentSchool && studentSchool.value) filterStudentDepts(studentSchool.value);
});
</script>
</body>
</html>