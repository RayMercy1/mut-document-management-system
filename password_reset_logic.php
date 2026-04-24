<?php
session_start();
require_once 'db_config.php';
require_once 'mailer.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $identifier = mysqli_real_escape_string($conn, $_POST['identifier']);

    $query  = "SELECT * FROM users WHERE reg_number = '$identifier' OR email = '$identifier' LIMIT 1";
    $result = mysqli_query($conn, $query);

    if (mysqli_num_rows($result) > 0) {
        $user  = mysqli_fetch_assoc($result);
        $email = $user['email'];

        if (empty($email)) {
            header("Location: forgot_password.php?status=no_email");
            exit();
        }

        $token  = bin2hex(random_bytes(32));
        $expiry = date("Y-m-d H:i:s", strtotime('+1 hour'));

        $updateQuery = "UPDATE users SET reset_token = '$token', token_expiry = '$expiry' WHERE email = '$email'";

        if (mysqli_query($conn, $updateQuery)) {

            // Always send via email (works on localhost too via PHPMailer/Gmail SMTP)
            $name      = htmlspecialchars($user['full_name'] ?? $email);
            $resetLink = "http://localhost/mut_dms/reset_password_form.php?token=" . $token;

            $subject = "MUT Portal – Password Reset Request";
            $html    = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:20px;}
  .wrap{max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1);}
  .hdr{background:linear-gradient(135deg,#0f172a,#22c55e);color:#fff;padding:28px 32px;text-align:center;}
  .hdr h1{font-size:20px;margin:0 0 4px;}
  .hdr p{opacity:.8;font-size:13px;margin:0;}
  .body{padding:28px 32px;font-size:14px;color:#334155;line-height:1.7;}
  .btn{display:inline-block;margin:20px 0;padding:14px 32px;background:#22c55e;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;}
  .note{font-size:12px;color:#94a3b8;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:16px;}
  .link-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;font-size:12px;word-break:break-all;color:#475569;margin-top:14px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>MUT Document System</h1>
    <p>Password Reset Request</p>
  </div>
  <div class="body">
    <p>Hello <strong>{$name}</strong>,</p>
    <p>We received a request to reset the password for your MUT Portal account. Click the button below to set a new password:</p>
    <div style="text-align:center;">
      <a href="{$resetLink}" class="btn">Reset My Password</a>
    </div>
    <p>This link will expire in <strong>1 hour</strong>. If you didn't request a password reset, you can safely ignore this email.</p>
    <div class="link-box">If the button doesn't work, copy and paste this link:<br>{$resetLink}</div>
    <div class="note">
      Murang'a University of Technology &nbsp;|&nbsp; This is an automated email — please do not reply.
    </div>
  </div>
</div>
</body>
</html>
HTML;

            $result_mail = sendEmail($email, $name, $subject, $html);

            if ($result_mail['success']) {
                header("Location: forgot_password.php?status=success");
            } else {
                // Still show success to avoid exposing system errors; log internally
                error_log("MUT DMS: Password reset email failed for {$email}: " . ($result_mail['error'] ?? 'unknown'));
                header("Location: forgot_password.php?status=success");
            }
        } else {
            header("Location: forgot_password.php?status=db_error");
        }
    } else {
        header("Location: forgot_password.php?status=not_found");
    }
    exit();
}
