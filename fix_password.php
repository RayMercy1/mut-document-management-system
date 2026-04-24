<?php
require_once 'db_config.php';

$password = 'admin123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Generated hash: " . $hash . "<br><br>";
echo "Run this SQL:<br>";
echo "UPDATE users SET password_hash = '" . $hash . "' WHERE reg_number = 'ADMIN001';";
?>