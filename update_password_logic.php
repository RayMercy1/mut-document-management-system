<?php
require_once 'db_config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 1. Secure the input
    $token = mysqli_real_escape_string($conn, $_POST['token']);
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    // 2. Validation: Check if passwords match
    if ($new_pass !== $confirm_pass) {
        header("Location: reset_password_form.php?token=$token&error=Passwords do not match. Please try again.");
        exit();
    }

    // 3. Validation: Minimum length check (Good practice for MUT DMS security)
    if (strlen($new_pass) < 8) {
        header("Location: reset_password_form.php?token=$token&error=Password must be at least 8 characters long.");
        exit();
    }

    // 4. Hash the password using modern BCRYPT
    $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);

    // 5. Update the database 
    // We use 'password_hash' as the column name per your table structure
    $query = "UPDATE users SET 
              password_hash = '$hashed_pass', 
              reset_token = NULL, 
              token_expiry = NULL 
              WHERE reset_token = '$token' AND token_expiry > NOW()";

    if (mysqli_query($conn, $query)) {
        if (mysqli_affected_rows($conn) > 0) {
            // Success! Redirect to login with the green success alert
            header("Location: login.php?success=Password updated successfully! You can now sign in.");
            exit();
        } else {
            // Token was likely valid but expired or already used
            header("Location: reset_password_form.php?token=$token&error=This reset link has expired or is no longer valid.");
            exit();
        }
    } else {
        // Database Error
        header("Location: reset_password_form.php?token=$token&error=Database error. Please contact the administrator.");
        exit();
    }
} else {
    // If accessed directly without POST
    header("Location: login.php");
    exit();
}