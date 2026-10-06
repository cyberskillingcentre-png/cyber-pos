<?php
/**
 * SESSION DIAGNOSTIC TOOL
 * Check what user you're logged in as
 */

session_start();

echo "<h2>Session Diagnostic Tool</h2>";
echo "<hr>";

echo "<h3>Current Session Status:</h3>";

if (isset($_SESSION['user_id'])) {
    echo "✅ You ARE logged in<br><br>";
    
    echo "<strong>Session Data:</strong><br>";
    echo "User ID: " . $_SESSION['user_id'] . "<br>";
    echo "Username: " . ($_SESSION['username'] ?? 'Not set') . "<br>";
    echo "Full Name: " . ($_SESSION['full_name'] ?? 'Not set') . "<br>";
    echo "Email: " . ($_SESSION['email'] ?? 'Not set') . "<br>";
    echo "Role: <strong style='color: " . (($_SESSION['role'] ?? '') == 'admin' ? 'green' : 'red') . ";'>" . ($_SESSION['role'] ?? 'Not set') . "</strong><br>";
    
    echo "<hr>";
    
    if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
        echo "✅ <strong style='color: green;'>You are logged in as ADMIN</strong><br>";
        echo "<p>You should have access to all admin pages.</p>";
        echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
    } else {
        echo "❌ <strong style='color: red;'>You are logged in as EMPLOYEE</strong><br>";
        echo "<p>This is why you're being redirected to employee/dashboard.php</p>";
        
        echo "<hr>";
        echo "<h3>🔧 FIX: Logout and Login as Admin</h3>";
        echo "<ol>";
        echo "<li><a href='logout.php'><strong>Click here to Logout</strong></a></li>";
        echo "<li>Then login with:<br>";
        echo "&nbsp;&nbsp;&nbsp;Username: <strong>admin</strong><br>";
        echo "&nbsp;&nbsp;&nbsp;Password: <strong>admin123</strong></li>";
        echo "</ol>";
    }
    
} else {
    echo "❌ <strong style='color: red;'>You are NOT logged in</strong><br>";
    echo "<p><a href='login.php'><strong>Click here to Login</strong></a></p>";
    echo "<p>Use:<br>";
    echo "Username: <strong>admin</strong><br>";
    echo "Password: <strong>admin123</strong></p>";
}

echo "<hr>";
echo "<h3>📋 User Roles Explained:</h3>";
echo "<ul>";
echo "<li><strong>admin</strong> - Full access to all features (admin panel)</li>";
echo "<li><strong>employee</strong> - Limited access (employee panel)</li>";
echo "</ul>";

echo "<hr>";
echo "<h3>🔍 Check Database Users:</h3>";

try {
    require_once 'config/config.php';
    $stmt = $conn->prepare("SELECT user_id, username, full_name, role, status, last_login FROM users WHERE status = 'active' ORDER BY role DESC");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Username</th><th>Full Name</th><th>Role</th><th>Status</th><th>Last Login</th></tr>";
    
    foreach ($users as $user) {
        $role_color = $user['role'] == 'admin' ? 'green' : 'blue';
        echo "<tr>";
        echo "<td><strong>" . $user['username'] . "</strong></td>";
        echo "<td>" . $user['full_name'] . "</td>";
        echo "<td><strong style='color: $role_color;'>" . strtoupper($user['role']) . "</strong></td>";
        echo "<td>" . $user['status'] . "</td>";
        echo "<td>" . ($user['last_login'] ? date('M d, Y H:i', strtotime($user['last_login'])) : 'Never') . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
} catch(Exception $e) {
    echo "Error checking database: " . $e->getMessage();
}

echo "<hr>";
echo "<h3>✅ Solution:</h3>";
echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107;'>";
echo "<strong>To access admin panel:</strong><br><br>";
echo "1. <a href='logout.php' style='color: #d9534f;'><strong>LOGOUT</strong></a> (if logged in)<br>";
echo "2. Go to <a href='login.php'><strong>LOGIN PAGE</strong></a><br>";
echo "3. Login with:<br>";
echo "&nbsp;&nbsp;&nbsp;• Username: <strong>admin</strong><br>";
echo "&nbsp;&nbsp;&nbsp;• Password: <strong>admin123</strong><br>";
echo "4. You'll be redirected to Admin Dashboard<br>";
echo "</div>";

?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
h2 { color: #6B46C1; }
h3 { color: #333; margin-top: 20px; }
hr { border: none; border-top: 2px solid #ddd; margin: 20px 0; }
table { background: white; padding: 10px; }
th { background: #6B46C1; color: white; padding: 10px; }
td { padding: 8px; }
a { color: #6B46C1; text-decoration: none; font-weight: bold; }
a:hover { text-decoration: underline; }
</style>
