<?php
// This will generate the correct password hash for admin123
$password = 'admin123';
$hash = password_hash($password, PASSWORD_BCRYPT);
echo "Password: $password\n";
echo "Hash: $hash\n";
?>
