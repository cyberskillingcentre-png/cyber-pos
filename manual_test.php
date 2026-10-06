<?php
// MANUAL PASSWORD TEST
// Upload this file and visit it in browser

$password = 'admin123';
$hash = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';

echo "<h2>Password Verification Test</h2>";
echo "Testing password: <strong>$password</strong><br>";
echo "Against hash: <strong>" . substr($hash, 0, 40) . "...</strong><br><br>";

if (password_verify($password, $hash)) {
    echo "✅ <span style='color: green; font-size: 20px;'><strong>SUCCESS!</strong></span><br>";
    echo "Password 'admin123' WORKS with this hash!<br><br>";
    echo "<strong>Your login should be:</strong><br>";
    echo "Username: <strong>admin</strong><br>";
    echo "Password: <strong>admin123</strong><br>";
} else {
    echo "❌ <span style='color: red;'><strong>FAILED!</strong></span><br>";
    echo "This hash doesn't match 'admin123'<br>";
}

echo "<hr>";
echo "<h3>Test Current Database</h3>";

try {
    require_once 'config/config.php';
    $stmt = $conn->prepare("SELECT username, password FROM users WHERE username = 'admin'");
    $stmt->execute();
    $user = $stmt->fetch();
    
    if ($user) {
        echo "Database admin user found!<br>";
        echo "Username: " . $user['username'] . "<br>";
        echo "Hash in DB: " . substr($user['password'], 0, 40) . "...<br><br>";
        
        if (password_verify('admin123', $user['password'])) {
            echo "✅ <strong style='color: green;'>Database password IS admin123!</strong><br>";
        } else {
            echo "❌ <strong style='color: red;'>Database password is NOT admin123!</strong><br>";
            echo "<br><strong>RUN THIS SQL:</strong><br>";
            echo "<code>UPDATE users SET password = '$hash' WHERE username = 'admin';</code>";
        }
    } else {
        echo "❌ No admin user in database!<br>";
        echo "<br><strong>RUN THIS SQL:</strong><br>";
        echo "<code>INSERT INTO users (username, password, full_name, email, role, status) VALUES ('admin', '$hash', 'Administrator', 'admin@shop.com', 'admin', 'active');</code>";
    }
} catch(Exception $e) {
    echo "❌ Database error: " . $e->getMessage();
}
?>
