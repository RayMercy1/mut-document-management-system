<?php
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['reg_number']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: login.php");
    exit();
}

$id = intval($_GET['id']);
$res = mysqli_query($conn, "SELECT * FROM departments WHERE id = $id");
$dept = mysqli_fetch_assoc($res);

if (!$dept) { die("Department not found."); }

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $dept_name = mysqli_real_escape_string($conn, $_POST['dept_name']);
    $school = mysqli_real_escape_string($conn, $_POST['school']);

    $update = "UPDATE departments SET dept_name='$dept_name', school='$school' WHERE id=$id";
    if (mysqli_query($conn, $update)) {
        header("Location: admin_departments.php?success=Department updated successfully");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Department | MUT DMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #22c55e; --bg: #0f172a; --card: #1e293b; --text: #f8fafc; --border: #334155; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); padding: 40px; }
        .form-container { max-width: 500px; margin: 0 auto; background: var(--card); padding: 30px; border-radius: 16px; border: 1px solid var(--border); }
        input { width: 100%; padding: 12px; margin: 10px 0 20px 0; background: #0f172a; border: 1px solid var(--border); color: white; border-radius: 8px; box-sizing: border-box; }
        .btn-submit { background: var(--primary); color: white; border: none; padding: 12px; width: 100%; border-radius: 8px; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Edit Department</h2>
        <form method="POST">
            <label>Department Name</label>
            <input type="text" name="dept_name" value="<?php echo htmlspecialchars($dept['dept_name']); ?>" required>
            
            <label>School / Faculty</label>
            <input type="text" name="school" value="<?php echo htmlspecialchars($dept['school']); ?>" required>

            <button type="submit" class="btn-submit">Update Department</button>
            <a href="admin_departments.php" style="display:block; text-align:center; margin-top:15px; color:#94a3b8; text-decoration:none;">Cancel</a>
        </form>
    </div>
</body>
</html>