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

// Fetch user data including department info
$stmt = $conn->prepare("SELECT u.*, d.dept_name, d.school FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.reg_number = ?");
$stmt->bind_param("s", $reg_number);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Fetch all departments for dropdown (used by COD/Dean/Admin and students)
$all_depts = $conn->query("SELECT id, dept_name, school FROM departments ORDER BY school, dept_name");
$dept_list = [];
$schools_list = [];
while ($d = $all_depts->fetch_assoc()) {
    $dept_list[] = $d;
    if (!in_array($d['school'], $schools_list)) $schools_list[] = $d['school'];
}

$role       = $user['role'] ?? $_SESSION['role'] ?? '';
$admin_role = $user['admin_role'] ?? $_SESSION['admin_role'] ?? '';
$is_staff   = ($role === 'admin' || $role === 'super_admin');

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- PART 1: UPDATE PROFILE ---
    if (isset($_POST['update_profile'])) {
        $full_name = htmlspecialchars($_POST['full_name'] ?? '');
        $phone     = htmlspecialchars($_POST['phone'] ?? '');

        $profile_pix = $user['profile_pix'];
        if (isset($_FILES['profile_pix']) && $_FILES['profile_pix']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/profiles/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $file_ext    = pathinfo($_FILES['profile_pix']['name'], PATHINFO_EXTENSION);
            $safe_reg    = str_replace('/', '_', $reg_number);
            $new_filename = $safe_reg . '_' . time() . '.' . $file_ext;
            $upload_path  = $upload_dir . $new_filename;
            if (move_uploaded_file($_FILES['profile_pix']['tmp_name'], $upload_path)) {
                $profile_pix = $upload_path;
            }
        }

        // Staff can update their department
        $dept_id = $user['department_id'];
        if ($is_staff && !empty($_POST['department_id'])) {
            $dept_id = intval($_POST['department_id']);
        }

        // Students also update course + year + department
        if (!$is_staff) {
            $course        = htmlspecialchars($_POST['course'] ?? '');
            $year_of_study = intval($_POST['year_of_study'] ?? 1);
            $email         = htmlspecialchars($_POST['email'] ?? $user['email'] ?? '');
            $student_dept  = !empty($_POST['department_id']) ? intval($_POST['department_id']) : $user['department_id'];
            $upd = $conn->prepare("UPDATE users SET full_name=?, phone=?, course=?, year_of_study=?, profile_pix=?, email=?, department_id=? WHERE reg_number=?");
            $upd->bind_param("sssissis", $full_name, $phone, $course, $year_of_study, $profile_pix, $email, $student_dept, $reg_number);
        } else {
            $upd = $conn->prepare("UPDATE users SET full_name=?, phone=?, department_id=?, profile_pix=? WHERE reg_number=?");
            $upd->bind_param("ssiss", $full_name, $phone, $dept_id, $profile_pix, $reg_number);
        }

        if ($upd->execute()) {
            $success = "Profile updated successfully!";
            // Refresh user data
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $_SESSION['full_name'] = $user['full_name'];
            if (!$is_staff) $_SESSION['email'] = $user['email'];
        } else {
            $error = "Failed to update profile.";
        }
    }

    // --- PART 2: CHANGE PASSWORD ---
    if (isset($_POST['change_password'])) {
        $current_pw = $_POST['current_password'];
        $new_pw     = $_POST['new_password'];
        $confirm_pw = $_POST['confirm_password'];

        // Re-fetch password fresh to avoid stale user array
        $pw_fetch = $conn->prepare("SELECT password_hash, password FROM users WHERE reg_number = ?");
        $pw_fetch->bind_param("s", $reg_number);
        $pw_fetch->execute();
        $pw_row = $pw_fetch->get_result()->fetch_assoc();
        $stored_hash = !empty($pw_row['password']) ? $pw_row['password'] : ($pw_row['password_hash'] ?? '');

        if (empty($stored_hash)) {
            $error = "No password found. Try using Forgot Password to set a new one.";
        } elseif ($new_pw !== $confirm_pw) {
            $error = "New passwords do not match.";
        } elseif (strlen($new_pw) < 6) {
            $error = "New password must be at least 6 characters.";
        } elseif (!password_verify($current_pw, $stored_hash)) {
            $error = "The current password you entered is incorrect.";
        } else {
            $hashed_pw = password_hash($new_pw, PASSWORD_DEFAULT);
            $pw_stmt   = $conn->prepare("UPDATE users SET password_hash=?, password=? WHERE reg_number=?");
            $pw_stmt->bind_param("sss", $hashed_pw, $hashed_pw, $reg_number);
            if ($pw_stmt->execute()) {
                $success = "Password changed successfully!";
            } else {
                $error = "Error updating password.";
            }
        }
    }
}

// Back link based on role
$back_link = 'index.php';
if ($is_staff) {
    $view = $_SESSION['current_admin_view'] ?? $admin_role;
    if ($view === 'cod')       $back_link = 'cod_dashboard.php?dept=' . ($_SESSION['selected_department'] ?? '');
    elseif ($view === 'dean')  $back_link = 'dean_dashboard.php?school=' . urlencode($_SESSION['selected_school'] ?? '');
    else                       $back_link = 'admin_dashboard.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | MUT Portal</title>
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
            background: radial-gradient(at 0% 0%, rgba(34,197,94,0.12) 0px, transparent 50%),
                        radial-gradient(at 100% 0%, rgba(15,23,42,0.3) 0px, transparent 50%), #0f172a;
            background-attachment: fixed;
            padding: 40px 20px;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        .top-bar {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 30px; background: var(--glass); backdrop-filter: blur(12px);
            padding: 20px 30px; border-radius: 24px; border: 1px solid var(--glass-border);
        }
        .back-btn {
            display: flex; align-items: center; gap: 10px; text-decoration: none;
            color: white; font-weight: 600; background: rgba(255,255,255,0.05);
            padding: 10px 20px; border-radius: 12px; border: 1px solid var(--glass-border); transition: 0.3s;
        }
        .back-btn:hover { background: rgba(255,255,255,0.1); transform: translateX(-5px); }
        .profile-layout { display: grid; grid-template-columns: 320px 1fr; gap: 30px; }
        .glass-card {
            background: var(--glass); backdrop-filter: blur(15px); border-radius: 30px;
            padding: 30px; border: 1px solid var(--glass-border);
            box-shadow: 0 10px 40px rgba(0,0,0,0.3); margin-bottom: 30px;
        }
        /* Profile avatar */
        .avatar-wrap { position: relative; width: 140px; margin: 0 auto 20px; }
        .avatar-preview {
            width: 140px; height: 140px; border-radius: 50%; object-fit: cover;
            border: 3px solid var(--primary); display: block;
        }
        .avatar-initials {
            width: 140px; height: 140px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #16a34a);
            border: 3px solid var(--primary); display: flex; align-items: center;
            justify-content: center; font-size: 3rem; font-weight: 800; color: white;
        }
        .role-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 700;
            background: rgba(34,197,94,0.15); color: var(--primary);
            border: 1px solid rgba(34,197,94,0.3); margin-top: 8px;
        }
        .form-section-title {
            font-size: 0.7rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 1.5px; color: var(--primary); margin-bottom: 25px;
            display: flex; align-items: center; gap: 12px;
        }
        .form-section-title::after { content: ""; flex: 1; height: 1px; background: var(--glass-border); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 8px; color: var(--text-muted); }
        .form-control {
            width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--glass-border);
            border-radius: 14px; padding: 14px 18px; color: white; transition: 0.3s; font-family: inherit; font-size: 0.9rem;
        }
        .form-control:focus { outline: none; border-color: var(--primary); background: rgba(255,255,255,0.08); }
        .form-control option { background: #1e293b; color: white; }
        .btn-action {
            width: 100%; background: var(--primary); color: white; border: none;
            padding: 16px; border-radius: 16px; font-weight: 700; cursor: pointer;
            transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 10px;
            font-family: inherit; font-size: 0.95rem;
        }
        .btn-action:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(34,197,94,0.2); }
        .btn-outline { background: transparent; border: 1px solid var(--glass-border); color: var(--text-main); }
        .btn-outline:hover { background: rgba(255,255,255,0.05); }
        .alert { padding: 15px 20px; border-radius: 16px; margin-bottom: 25px; font-weight: 600; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: rgba(34,197,94,0.1); color: #4ade80; border: 1px solid rgba(34,197,94,0.2); }
        .alert-error   { background: rgba(239,68,68,0.1); color: #f87171; border: 1px solid rgba(239,68,68,0.2); }
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 850px) { .profile-layout { grid-template-columns: 1fr; } .two-col { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="container">
    <div class="top-bar">
        <a href="<?php echo htmlspecialchars($back_link); ?>" class="back-btn">
            <i class="fa-solid fa-chevron-left"></i> Dashboard
        </a>
        <h1 style="font-weight:800;font-size:1.4rem;">My Profile &amp; Settings</h1>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success" id="successAlert"><i class="fa-solid fa-circle-check"></i> <?php echo $success; ?></div>
    <script>
        setTimeout(function() {
            var el = document.getElementById('successAlert');
            if (el) {
                el.style.transition = 'opacity 0.8s ease';
                el.style.opacity = '0';
                setTimeout(function() { el.style.display = 'none'; }, 800);
            }
        }, 3000);
    </script>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error; ?></div>
    <?php endif; ?>

    <div class="profile-layout">
        <!-- Left column: avatar + security -->
        <div>
            <!-- Avatar card -->
            <div class="glass-card" style="text-align:center;">
                <div class="avatar-wrap">
                    <?php if (!empty($user['profile_pix'])): ?>
                        <img src="<?php echo htmlspecialchars($user['profile_pix']); ?>" class="avatar-preview" alt="Profile">
                    <?php else: ?>
                        <div class="avatar-initials"><?php echo strtoupper(substr($user['full_name'] ?? '?', 0, 1)); ?></div>
                    <?php endif; ?>
                </div>
                <h2 style="font-weight:800;margin-bottom:4px;"><?php echo htmlspecialchars($user['full_name']); ?></h2>
                <p style="color:var(--text-muted);font-size:0.8rem;"><?php echo htmlspecialchars($user['reg_number']); ?></p>
                <?php if (!empty($user['email'])): ?>
                <p style="color:var(--text-muted);font-size:0.8rem;margin-top:4px;"><?php echo htmlspecialchars($user['email']); ?></p>
                <?php endif; ?>
                <div class="role-badge">
                    <i class="fa-solid fa-shield-halved"></i>
                    <?php
                        if ($role === 'super_admin') echo 'Super Admin';
                        elseif ($role === 'admin')   echo ucfirst($admin_role ?: 'Admin');
                        else                         echo 'Student';
                    ?>
                </div>
                <?php if (!empty($user['dept_name'])): ?>
                <p style="margin-top:12px;font-size:0.8rem;color:var(--text-muted);">
                    <i class="fa-solid fa-building" style="margin-right:6px;"></i><?php echo htmlspecialchars($user['dept_name']); ?>
                </p>
                <?php endif; ?>
            </div>

            <!-- Security / Password -->
            <div class="glass-card">
                <div class="form-section-title"><i class="fa-solid fa-shield-halved"></i> Security</div>
                <form action="" method="POST">
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" class="form-control" required placeholder="Enter current password">
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" class="form-control" required placeholder="Enter new password">
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required placeholder="Repeat new password">
                    </div>
                    <button type="submit" name="change_password" class="btn-action btn-outline">
                        <i class="fa-solid fa-key"></i> Update Password
                    </button>
                </form>
            </div>
        </div>

        <!-- Right column: profile details -->
        <div class="glass-card">
            <div class="form-section-title"><i class="fa-solid fa-user-pen"></i> Profile Details</div>
            <form action="" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Profile Picture</label>
                    <input type="file" name="profile_pix" class="form-control" accept="image/*" style="padding:10px;">
                </div>
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" class="form-control"
                        value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                </div>
                <div class="two-col">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" class="form-control"
                            value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+27…">
                    </div>
                    <?php if (!$is_staff): ?>
                    <div class="form-group">
                        <label>Year of Study</label>
                        <select name="year_of_study" class="form-control">
                            <?php for ($i = 1; $i <= 4; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo ($user['year_of_study'] ?? 1) == $i ? 'selected' : ''; ?>>Year <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <?php if (!$is_staff): ?>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? $_SESSION['email'] ?? ''); ?>">
                    <?php else: ?>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                            disabled style="opacity:0.5;" title="Email cannot be changed here">
                    <?php endif; ?>
                </div>

                <?php if ($is_staff): ?>
                <!-- Department selector for COD / Dean / Admin -->
                <div class="form-group">
                    <label>
                        <i class="fa-solid fa-building" style="margin-right:6px;color:var(--primary);"></i>
                        Department
                    </label>
                    <select name="department_id" class="form-control" id="deptDropdown">
                        <option value="">— No department —</option>
                        <?php
                        $current_school = '';
                        foreach ($dept_list as $d):
                            if ($d['school'] !== $current_school):
                                if ($current_school !== '') echo '</optgroup>';
                                echo '<optgroup label="' . htmlspecialchars($d['school']) . '" style="color:#94a3b8;font-size:0.75rem;">';
                                $current_school = $d['school'];
                            endif;
                        ?>
                            <option value="<?php echo $d['id']; ?>"
                                <?php echo ($user['department_id'] == $d['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['dept_name']); ?>
                            </option>
                        <?php endforeach;
                        if ($current_school !== '') echo '</optgroup>'; ?>
                    </select>
                    <!-- Live search for department -->
                    <input type="text" id="deptSearch" placeholder="Type to search department…"
                        style="margin-top:8px;width:100%;padding:10px 14px;background:rgba(255,255,255,0.04);border:1px solid var(--glass-border);border-radius:10px;color:white;font-family:inherit;font-size:0.85rem;"
                        oninput="filterProfileDepts(this.value)">
                </div>
                <?php else: ?>
                <div class="form-group">
                    <label>Course</label>
                    <input type="text" name="course" class="form-control"
                        value="<?php echo htmlspecialchars($user['course'] ?? ''); ?>" placeholder="e.g. B.Tech Computer Science">
                </div>
                <div class="form-group">
                    <label>School</label>
                    <select id="studentSchoolSelect" class="form-control" onchange="filterStudentDepts(this.value)">
                        <option value="">— Select School —</option>
                        <?php foreach ($schools_list as $sch): ?>
                        <option value="<?php echo htmlspecialchars($sch); ?>"
                            <?php echo ($user['school'] ?? '') === $sch ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sch); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Department</label>
                    <select name="department_id" id="studentDeptSelect" class="form-control">
                        <option value="">— Select Department —</option>
                        <?php foreach ($dept_list as $d): ?>
                        <option value="<?php echo $d['id']; ?>"
                            data-school="<?php echo htmlspecialchars($d['school']); ?>"
                            <?php echo ($user['department_id'] == $d['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($d['dept_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <button type="submit" name="update_profile" class="btn-action">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>
            </form>
        </div>
    </div>
</div>

<script>
// Student: filter departments by selected school
function filterStudentDepts(school) {
    const sel = document.getElementById('studentDeptSelect');
    Array.from(sel.options).forEach(opt => {
        if (!opt.value) return;
        opt.style.display = (!school || opt.dataset.school === school) ? '' : 'none';
    });
    // Reset selection if current selected dept doesn't belong to new school
    const selected = sel.options[sel.selectedIndex];
    if (selected && selected.value && selected.dataset.school !== school) {
        sel.value = '';
    }
}
// Run on page load to filter by current school
window.addEventListener('DOMContentLoaded', function() {
    const schoolSel = document.getElementById('studentSchoolSelect');
    if (schoolSel) filterStudentDepts(schoolSel.value);
});

function filterProfileDepts(val) {
    const sel = document.getElementById('deptDropdown');
    const v = val.toLowerCase();
    Array.from(sel.options).forEach(opt => {
        if (!opt.value) return;
        opt.style.display = opt.text.toLowerCase().includes(v) ? '' : 'none';
    });
    const first = Array.from(sel.options).find(o => o.value && o.style.display !== 'none');
    if (first) sel.value = first.value;
}
</script>
</body>
</html>