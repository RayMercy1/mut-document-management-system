<?php
require_once 'db_config.php';
$token = $_GET['token'] ?? '';
$error = $_GET['error'] ?? '';

// Basic check: Does the token exist and is it still valid?
$isValid = false;
if (!empty($token)) {
    $token = mysqli_real_escape_string($conn, $token);
    $query = "SELECT * FROM users WHERE reset_token = '$token' AND token_expiry > NOW() LIMIT 1";
    $result = mysqli_query($conn, $query);
    if (mysqli_num_rows($result) > 0) {
        $isValid = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MUT Portal | Create New Password</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --mut-green: #22c55e;
            --mut-green-dark: #16a34a;
            --text-dark: #0f172a;
            --white: #ffffff;
            --bg-light: #f8fafc;
            --border: #e2e8f0;
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            display: flex; align-items: center; justify-content: center;
            height: 100vh; padding: 20px; margin: 0;
        }

        .reset-card {
            background: var(--white); width: 100%; max-width: 450px;
            padding: 40px; border-radius: 20px; box-shadow: var(--shadow-xl); text-align: center;
        }

        .icon-circle {
            width: 70px; height: 70px; background: rgba(34, 197, 94, 0.1);
            color: var(--mut-green); border-radius: 50%; display: flex;
            align-items: center; justify-content: center; font-size: 30px; margin: 0 auto 24px;
        }

        h2 { font-size: 1.75rem; color: var(--text-dark); margin-bottom: 12px; }
        p { color: #475569; font-size: 0.95rem; margin-bottom: 30px; }

        .input-group { text-align: left; margin-bottom: 20px; }
        .input-group label { display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 8px; }
        .input-field { position: relative; }
        .input-field input {
            width: 100%; padding: 14px 16px; border: 2px solid var(--border);
            border-radius: 12px; font-size: 0.95rem; outline: none; transition: 0.3s;
        }
        .input-field input:focus { border-color: var(--mut-green); }

        .btn-submit {
            width: 100%; background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: var(--white); padding: 16px; border: none; border-radius: 12px;
            font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 10px;
        }

        .error-msg { color: #dc2626; background: #fef2f2; padding: 10px; border-radius: 8px; margin-bottom: 20px; font-size: 0.875rem; }
    </style>
</head>
<body>

    <div class="reset-card">
        <div class="icon-circle"><i class="fa-solid fa-lock-open"></i></div>
        
        <?php if ($isValid): ?>
            <h2>Set New Password</h2>
            <p>Please enter your new password below.</p>
            
            <?php if ($error): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form action="update_password_logic.php" method="POST">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                
                <div class="input-group">
                    <label>New Password</label>
                    <div class="input-field">
                        <input type="password" name="new_password" placeholder="Min. 8 characters" required minlength="8">
                    </div>
                </div>

                <div class="input-group">
                    <label>Confirm New Password</label>
                    <div class="input-field">
                        <input type="password" name="confirm_password" placeholder="Repeat password" required minlength="8">
                    </div>
                </div>
                
                <button type="submit" class="btn-submit">Update Password</button>
            </form>
        <?php else: ?>
            <h2>Invalid Link</h2>
            <p>This password reset link is invalid or has expired. Please request a new one.</p>
            <a href="forgot_password.php" style="color: var(--mut-green); font-weight: 600; text-decoration: none;">Request New Link</a>
        <?php endif; ?>
    </div>

</body>
</html>