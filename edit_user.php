<?php
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['reg_number']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: login.php");
    exit();
}

$id = intval($_GET['id']);

// Fetch user with department and school info
$user_query = $conn->prepare("SELECT u.*, d.dept_name, d.school as dept_school FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.id = ?");
$user_query->bind_param("i", $id);
$user_query->execute();
$user = $user_query->get_result()->fetch_assoc();

if (!$user) {
    header("Location: admin_users.php?error=User not found");
    exit();
}

// Fetch all departments (with school grouping)
$depts_result = $conn->query("SELECT * FROM departments ORDER BY school, dept_name");
$all_depts = [];
while ($d = $depts_result->fetch_assoc()) $all_depts[] = $d;

// Fetch unique schools
$schools_result = $conn->query("SELECT DISTINCT school FROM departments WHERE school != '' ORDER BY school");
$all_schools = [];
while ($s = $schools_result->fetch_assoc()) $all_schools[] = $s['school'];

// Determine current display role (combine role + admin_role)
$current_display_role = $user['role'];
if ($user['role'] === 'admin' && !empty($user['admin_role']) && $user['admin_role'] !== 'none') {
    $current_display_role = $user['admin_role']; // 'cod', 'dean', 'registrar', 'dvc_arsa'
}

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name     = trim($_POST['full_name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $display_role  = $_POST['role'] ?? 'student';
    $dept_id_post  = intval($_POST['department_id'] ?? 0);
    $school_post   = trim($_POST['school'] ?? '');

    // Map display role → DB role + admin_role
    if ($display_role === 'super_admin') {
        $db_role       = 'super_admin';
        $db_admin_role = 'none';
        $db_dept_id    = 'NULL';
    } elseif ($display_role === 'student') {
        $db_role       = 'student';
        $db_admin_role = 'none';
        $db_dept_id    = $dept_id_post > 0 ? $dept_id_post : 'NULL';
        $db_school     = !empty($school_post) ? "'" . $conn->real_escape_string($school_post) . "'" : 'NULL';
    } elseif ($display_role === 'cod') {
        $db_role       = 'admin';
        $db_admin_role = 'cod';
        $db_dept_id    = $dept_id_post > 0 ? $dept_id_post : 'NULL';
        $db_school     = !empty($school_post) ? "'" . $conn->real_escape_string($school_post) . "'" : 'NULL';
    } elseif ($display_role === 'dean') {
        $db_role       = 'admin';
        $db_admin_role = 'dean';
        // Dean oversees a school — stored in users.school, department_id = NULL
        $db_dept_id    = 'NULL';
        $db_school     = !empty($school_post) ? "'" . $conn->real_escape_string($school_post) . "'" : 'NULL';
    } elseif ($display_role === 'registrar') {
        $db_role       = 'admin';
        $db_admin_role = 'registrar';
        $db_dept_id    = 'NULL';
    } elseif ($display_role === 'dvc_arsa') {
        $db_role       = 'admin';
        $db_admin_role = 'dvc_arsa';
        $db_dept_id    = 'NULL';
    } else {
        $db_role       = 'student';
        $db_admin_role = 'none';
        $db_dept_id    = 'NULL';
    }

    // For non-dean roles, set school to NULL
    if (!isset($db_school)) $db_school = 'NULL';

    $email_val = !empty($email) ? "'" . $conn->real_escape_string($email) . "'" : "NULL";
    $fn_safe   = $conn->real_escape_string($full_name);
    $ar_safe   = $conn->real_escape_string($db_admin_role);
    $role_safe = $conn->real_escape_string($db_role);

    // Handle optional password reset
    $pw_sql = '';
    if (!empty($_POST['new_password'])) {
        $hashed = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $hashed_safe = $conn->real_escape_string($hashed);
        $pw_sql = ", password = '$hashed_safe', password_hash = '$hashed_safe'";
    }

    // Check for duplicate email before updating
    if (!empty($email)) {
        $email_check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $email_check->bind_param("si", $email, $id);
        $email_check->execute();
        if ($email_check->get_result()->num_rows > 0) {
            $error = "The email address <strong>" . htmlspecialchars($email) . "</strong> is already registered to another user. Please use a different email.";
        }
    }

    if (empty($error)) {
    $update_sql = "UPDATE users SET
        full_name     = '$fn_safe',
        email         = $email_val,
        role          = '$role_safe',
        admin_role    = '$ar_safe',
        department_id = $db_dept_id,
        school        = $db_school
        $pw_sql
        WHERE id = $id";

    if ($conn->query($update_sql)) {
        $success = "User updated successfully!";
        // Re-fetch updated user
        $user_query->execute();
        $user = $user_query->get_result()->fetch_assoc();
        $current_display_role = $user['role'];
        if ($user['role'] === 'admin' && !empty($user['admin_role']) && $user['admin_role'] !== 'none') {
            $current_display_role = $user['admin_role'];
        }
    } else {
        // Catch duplicate email at DB level too
        if (strpos($conn->error, 'Duplicate entry') !== false && strpos($conn->error, 'email') !== false) {
            $error = "The email address <strong>" . htmlspecialchars($email) . "</strong> is already used by another account.";
        } else {
            $error = "Update failed: " . $conn->error;
        }
    }
    } // end empty($error) check
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Staff | MUT DMS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{--primary:#22c55e;--primary-dark:#16a34a;--bg:#0f172a;--card:#1e293b;--card2:#162032;--text:#f8fafc;--text-muted:#94a3b8;--border:#334155;--danger:#ef4444;--warning:#f59e0b}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:30px 20px}
        .form-container{width:100%;max-width:560px;background:var(--card);border-radius:20px;border:1px solid var(--border);overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.4)}
        .form-header{background:linear-gradient(135deg,#1e293b,#0f172a);padding:28px 32px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:16px}
        .header-icon{width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:white;flex-shrink:0}
        .form-header h2{font-size:1.3rem;font-weight:700}
        .form-header p{font-size:.8rem;color:var(--text-muted);margin-top:3px}
        .form-body{padding:28px 32px}
        .alert{padding:12px 16px;border-radius:10px;margin-bottom:20px;font-size:.875rem;font-weight:600;display:flex;align-items:center;gap:10px}
        .alert-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#4ade80}
        .alert-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#f87171}
        .form-group{margin-bottom:20px}
        .form-group label{display:block;font-size:.8rem;font-weight:600;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}
        .form-group label .badge-optional{font-size:.7rem;background:rgba(148,163,184,.15);color:var(--text-muted);padding:2px 7px;border-radius:4px;margin-left:6px;text-transform:none;letter-spacing:0;font-weight:400}
        .form-control{width:100%;padding:12px 16px;background:var(--card2);border:1.5px solid var(--border);color:var(--text);border-radius:10px;font-size:.9rem;font-family:inherit;transition:border-color .2s}
        .form-control:focus{outline:none;border-color:var(--primary)}
        .form-control option{background:#1e293b}
        .two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .section-divider{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--primary);margin:24px 0 16px;padding-bottom:8px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
        .conditional{display:none}
        .conditional.visible{display:block}
        .school-dept-note{font-size:.75rem;color:var(--text-muted);margin-top:-12px;margin-bottom:16px;padding:8px 12px;background:rgba(59,130,246,.08);border-radius:6px;border-left:3px solid #3b82f6}
        .current-info{background:rgba(34,197,94,.07);border:1px solid rgba(34,197,94,.2);border-radius:10px;padding:14px 16px;margin-bottom:22px;font-size:.85rem}
        .current-info .ci-row{display:flex;justify-content:space-between;margin-bottom:6px}
        .current-info .ci-row:last-child{margin-bottom:0}
        .current-info .ci-label{color:var(--text-muted)}
        .current-info .ci-value{font-weight:600;color:var(--text)}
        .btn-submit{width:100%;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;border:none;padding:14px;border-radius:10px;font-size:.95rem;font-weight:700;cursor:pointer;transition:all .2s;margin-top:8px;display:flex;align-items:center;justify-content:center;gap:8px}
        .btn-submit:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(34,197,94,.3)}
        .btn-cancel{display:block;text-align:center;margin-top:14px;color:var(--text-muted);text-decoration:none;font-size:.875rem;transition:color .2s}
        .btn-cancel:hover{color:var(--text)}
        .pass-wrapper{position:relative}
        .pass-toggle{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:.9rem}
    </style>
</head>
<body>
<div class="form-container">
    <div class="form-header">
        <div class="header-icon"><i class="fa-solid fa-user-pen"></i></div>
        <div>
            <h2>Edit Staff Member</h2>
            <p>Update account details, role, and assignment</p>
        </div>
    </div>

    <div class="form-body">
        <?php if ($success): ?>
        <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Current Info Summary -->
        <div class="current-info">
            <div class="ci-row"><span class="ci-label">Reg Number</span><span class="ci-value"><?php echo htmlspecialchars($user['reg_number']); ?></span></div>
            <div class="ci-row"><span class="ci-label">Current Role</span><span class="ci-value"><?php echo ucfirst($current_display_role); ?></span></div>
            <?php if (!empty($user['dept_name'])): ?>
            <div class="ci-row"><span class="ci-label">Department</span><span class="ci-value"><?php echo htmlspecialchars($user['dept_name']); ?></span></div>
            <?php endif; ?>
            <?php
            // Show school: for deans use users.school directly; for COD derive from dept
            $display_school = $user['school'] ?? $user['dept_school'] ?? null;
            ?>
            <?php if (!empty($display_school)): ?>
            <div class="ci-row"><span class="ci-label">School</span><span class="ci-value"><?php echo htmlspecialchars($display_school); ?></span></div>
            <?php endif; ?>
        </div>

        <form method="POST" id="editForm">
            <!-- Basic Info -->
            <div class="section-divider"><i class="fa-solid fa-user"></i> Basic Information</div>

            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
            </div>

            <div class="form-group">
                <label>Email Address <span class="badge-optional">optional</span></label>
                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" placeholder="user@example.com">
            </div>

            <!-- Role -->
            <div class="section-divider"><i class="fa-solid fa-shield-halved"></i> System Role &amp; Assignment</div>

            <div class="form-group">
                <label>System Role</label>
                <select name="role" id="roleSelect" class="form-control" onchange="toggleAssignment()">
                    <option value="student"    <?php if($current_display_role==='student')    echo 'selected'; ?>>Student</option>
                    <option value="cod"        <?php if($current_display_role==='cod')        echo 'selected'; ?>>COD – Head of Department</option>
                    <option value="dean"       <?php if($current_display_role==='dean')       echo 'selected'; ?>>Dean</option>
                    <option value="registrar"  <?php if($current_display_role==='registrar')  echo 'selected'; ?>>Registrar</option>
                    <option value="dvc_arsa"   <?php if($current_display_role==='dvc_arsa')   echo 'selected'; ?>>DVC ARSA</option>
                    <option value="super_admin"<?php if($current_display_role==='super_admin')echo 'selected'; ?>>Super Admin</option>
                </select>
            </div>

            <!-- COD: School + Department selector -->
            <div id="deptGroup" class="conditional <?php echo $current_display_role==='cod'?'visible':''; ?>">
                <div class="form-group">
                    <label>School</label>
                    <select name="school" id="codSchoolSelect" class="form-control" onchange="filterCodDeptsBySchool(this.value)">
                        <option value="">— Select School —</option>
                        <?php foreach ($all_schools as $sname): ?>
                        <option value="<?php echo htmlspecialchars($sname); ?>"
                            <?php if (($user['school'] ?? $user['dept_school'] ?? '') === $sname) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($sname); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assign Department</label>
                    <input type="text" id="deptSearch" class="form-control" placeholder="Type to search department…"
                        style="margin-bottom:8px;" oninput="filterDeptOptions(this.value)" autocomplete="off">
                    <select name="department_id" id="deptSelect" class="form-control" size="4" style="min-height:100px;">
                        <option value="">— Select department —</option>
                        <?php foreach ($all_depts as $d): ?>
                        <option value="<?php echo $d['id']; ?>"
                            data-label="<?php echo strtolower(htmlspecialchars($d['dept_name'].' '.$d['school'])); ?>"
                            data-school="<?php echo htmlspecialchars($d['school']); ?>"
                            <?php if ($user['department_id'] == $d['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($d['dept_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Student: School + Department selector -->
            <div id="studentGroup" class="conditional <?php echo $current_display_role==='student'?'visible':''; ?>">
                <div class="school-dept-note" style="border-left-color:#22c55e;">
                    <i class="fa-solid fa-circle-info"></i>
                    Assign the student to their department so their applications route correctly.
                </div>
                <div class="form-group">
                    <label>School <span class="badge-optional">optional</span></label>
                    <select name="school" id="studentSchoolSelect" class="form-control">
                        <option value="">— Select School —</option>
                        <?php foreach ($all_schools as $sname): ?>
                        <option value="<?php echo htmlspecialchars($sname); ?>"
                            <?php if (($user['school'] ?? $user['dept_school'] ?? '') === $sname) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($sname); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Department <span class="badge-optional">optional</span></label>
                    <input type="text" id="studentDeptSearch" class="form-control"
                        placeholder="Type to search department…"
                        style="margin-bottom:8px;"
                        oninput="filterStudentDepts(this.value)" autocomplete="off">
                    <select name="department_id" id="studentDeptSelect" class="form-control" size="4" style="min-height:100px;">
                        <option value="">— No department —</option>
                        <?php foreach ($all_depts as $d): ?>
                        <option value="<?php echo $d['id']; ?>"
                            data-label="<?php echo strtolower(htmlspecialchars($d['dept_name'].' '.$d['school'])); ?>"
                            <?php if ($user['department_id'] == $d['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($d['dept_name']); ?> (<?php echo htmlspecialchars($d['school']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Dean: School selector ONLY — Dean heads a school, not a department -->
            <div id="deanGroup" class="conditional <?php echo $current_display_role==='dean'?'visible':''; ?>">
                <div class="school-dept-note">
                    <i class="fa-solid fa-circle-info"></i>
                    A Dean is the head of an entire school. Assign the school they oversee.
                </div>
                <div class="form-group">
                    <label>School</label>
                    <select name="school" id="schoolSelect" class="form-control">
                        <option value="">— Select School —</option>
                        <?php foreach ($all_schools as $sname): ?>
                        <option value="<?php echo htmlspecialchars($sname); ?>"
                            <?php if (($user['school'] ?? '') === $sname) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($sname); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Password Reset -->
            <div class="section-divider"><i class="fa-solid fa-key"></i> Password Reset <span style="font-size:.7rem;font-weight:400;color:var(--text-muted);text-transform:none;letter-spacing:0;">(leave blank to keep current)</span></div>

            <div class="form-group">
                <label>New Password <span class="badge-optional">optional</span></label>
                <div class="pass-wrapper">
                    <input type="password" name="new_password" id="newPwField" class="form-control" placeholder="Enter new password to reset" autocomplete="new-password" style="padding-right:44px;">
                    <button type="button" class="pass-toggle" onclick="togglePw()"><i class="fa-solid fa-eye" id="pwIcon"></i></button>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
            </button>
        </form>
        <a href="admin_users.php" class="btn-cancel"><i class="fa-solid fa-arrow-left"></i> Back to Users</a>
    </div>
</div>

<script>
// All departments as JS data for filtering
const allDepts = <?php echo json_encode(array_map(fn($d) => ['id'=>$d['id'],'name'=>$d['dept_name'],'school'=>$d['school']], $all_depts)); ?>;

function toggleAssignment() {
    const role = document.getElementById('roleSelect').value;
    document.getElementById('deptGroup').classList.toggle('visible', role === 'cod');
    document.getElementById('deanGroup').classList.toggle('visible', role === 'dean');
    document.getElementById('studentGroup').classList.toggle('visible', role === 'student');
    // Clear hidden inputs so they don't submit stale values
    if (role !== 'cod') {
        document.getElementById('deptSelect').value = '';
        const codSchool = document.getElementById('codSchoolSelect');
        if (codSchool) codSchool.value = '';
    }
    if (role !== 'student') {
        document.getElementById('studentDeptSelect').value = '';
        const studentSchool = document.getElementById('studentSchoolSelect');
        if (studentSchool) studentSchool.value = '';
    }
    if (role === 'dean') filterDeptsBySchool(document.getElementById('schoolSelect').value);
    if (role === 'cod') {
        const codSchool = document.getElementById('codSchoolSelect');
        if (codSchool) filterCodDeptsBySchool(codSchool.value);
    }
}

function filterCodDeptsBySchool(school) {
    const sel = document.getElementById('deptSelect');
    Array.from(sel.options).forEach(o => {
        if (!o.value) { o.style.display = ''; return; }
        o.style.display = (!school || o.dataset.school === school) ? '' : 'none';
    });
    // Reset dept if it doesn't belong to the newly chosen school
    const cur = sel.options[sel.selectedIndex];
    if (cur && cur.value && cur.dataset.school !== school) sel.value = '';
    // Clear text search
    const search = document.getElementById('deptSearch');
    if (search) search.value = '';
}

function filterStudentDepts(val) {
    const v = val.toLowerCase();
    Array.from(document.getElementById('studentDeptSelect').options).forEach(o => {
        if (!o.value) return;
        o.style.display = o.dataset.label.includes(v) ? '' : 'none';
    });
}

function filterDeptOptions(val) {
    const v = val.toLowerCase();
    const sel = document.getElementById('deptSelect');
    Array.from(sel.options).forEach(o => {
        if (!o.value) return;
        o.style.display = o.dataset.label.includes(v) ? '' : 'none';
    });
}

function filterDeptsBySchool(school) {
    const sel = document.getElementById('deanDeptSelect');
    Array.from(sel.options).forEach(o => {
        if (!o.value) { o.style.display = ''; return; }
        o.style.display = (!school || o.dataset.school === school) ? '' : 'none';
    });
    // Reset selection if current selection doesn't belong to chosen school
    const current = sel.options[sel.selectedIndex];
    if (current && current.value && current.dataset.school !== school) sel.value = '';
}

function togglePw() {
    const f = document.getElementById('newPwField');
    const i = document.getElementById('pwIcon');
    f.type = f.type === 'password' ? 'text' : 'password';
    i.className = f.type === 'password' ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
}

// Init on load
window.addEventListener('DOMContentLoaded', () => {
    toggleAssignment();
    const school = document.getElementById('schoolSelect')?.value;
    if (school) filterDeptsBySchool(school);
    const codSchool = document.getElementById('codSchoolSelect')?.value;
    if (codSchool) filterCodDeptsBySchool(codSchool);
});
</script>
</body>
</html>