<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MUT Portal | Reset Password</title>
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
            /* Matching the background of profile.php */
            background: radial-gradient(at 0% 0%, rgba(34,197,94,0.12) 0px, transparent 50%),
                        radial-gradient(at 100% 0%, rgba(15,23,42,0.3) 0px, transparent 50%), #0f172a;
            background-attachment: fixed;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .glass-card {
            background: var(--glass);
            backdrop-filter: blur(15px);
            border-radius: 30px;
            padding: 40px;
            border: 1px solid var(--glass-border);
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 450px;
            text-align: center;
        }

        .icon-circle {
            width: 80px;
            height: 80px;
            background: rgba(34, 197, 94, 0.15);
            color: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 24px;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        h2 {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }

        p {
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .form-group {
            text-align: left;
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-control {
            width: 100%;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--glass-border);
            border-radius: 14px;
            padding: 16px;
            color: white;
            transition: 0.3s;
            font-family: inherit;
            font-size: 0.95rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(255,255,255,0.08);
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.1);
        }

        .btn-action {
            width: 100%;
            background: var(--primary);
            color: white;
            border: none;
            padding: 16px;
            border-radius: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-family: inherit;
            font-size: 1rem;
        }

        .btn-action:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.3);
        }

        .back-to-login {
            margin-top: 24px;
        }

        .back-to-login a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .back-to-login a:hover {
            color: var(--primary);
        }

        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 0.875rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            text-align: left;
        }
        .alert-success { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: #4ade80; }
        .alert-error   { background: rgba(239,68,68,0.1);  border: 1px solid rgba(239,68,68,0.3);  color: #f87171; }
        .alert-warning { background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3); color: #fbbf24; }
    </style>
</head>
<body>

<?php
$status = $_GET['status'] ?? '';
$alert = '';
if ($status === 'success') {
    $alert = '<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> Reset link sent! Check your email inbox.</div>';
} elseif ($status === 'not_found') {
    $alert = '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> No account found with that email or registration number.</div>';
} elseif ($status === 'no_email') {
    $alert = '<div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i> Your account has no email address on file. Please contact the administrator.</div>';
} elseif ($status === 'mail_failed') {
    $link = htmlspecialchars($_GET['link'] ?? '');
    $alert = '<div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i> Email could not be sent. <a href="' . $link . '" style="color:#fbbf24;text-decoration:underline;">Click here to reset your password directly.</a></div>';
} elseif ($status === 'db_error') {
    $alert = '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> A database error occurred. Please try again.</div>';
}
?>

    <div class="glass-card">
        <div class="icon-circle">
            <i class="fa-solid fa-key"></i>
        </div>
        <h2>Forgot Password?</h2>
        <p>No worries! Enter your email or registration number and we'll send you instructions to reset your password.</p>

        <?php echo $alert; ?>
        
        <form action="password_reset_logic.php" method="POST">
            <div class="form-group">
                <label for="identifier">Username / Email Address</label>
                <input type="text" id="identifier" name="identifier" class="form-control" placeholder="Enter your email or Reg. No" required>
            </div>
            
            <button type="submit" class="btn-action">
                <i class="fa-solid fa-paper-plane"></i> Send Reset Link
            </button>
        </form>
        
        <div class="back-to-login">
            <a href="login.php">
                <i class="fa-solid fa-chevron-left"></i> Back to Login
            </a>
        </div>
    </div>

</body>
</html>