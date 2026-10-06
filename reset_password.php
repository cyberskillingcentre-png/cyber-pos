<?php
/**
 * PASSWORD RESET UTILITY
 * 
 * This file helps you reset the admin password if you're having login issues.
 * 
 * INSTRUCTIONS:
 * 1. Upload this file to your electrical_pos_system folder
 * 2. Open it in browser: http://localhost/electrical_pos_system/reset_password.php
 * 3. Enter new password
 * 4. Delete this file after use for security
 */

require_once 'config/config.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $new_password = $_POST['new_password'];
    
    if (empty($username) || empty($new_password)) {
        $error = 'Please fill in all fields';
    } else {
        try {
            $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
            
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
            $stmt->execute([$hashed_password, $username]);
            
            if ($stmt->rowCount() > 0) {
                $success = "Password updated successfully for user: $username<br>New password: $new_password<br><br><strong>DELETE THIS FILE NOW FOR SECURITY!</strong>";
            } else {
                $error = "User not found: $username";
            }
        } catch(PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #6B46C1 0%, #ED8936 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .reset-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 500px;
            width: 100%;
        }
        .alert-danger {
            background-color: #fee;
            border-color: #fcc;
            color: #c00;
        }
        .alert-success {
            background-color: #efe;
            border-color: #cfc;
            color: #0c0;
        }
    </style>
</head>
<body>
    <div class="reset-card">
        <h2 class="text-center mb-4" style="color: #6B46C1;">
            <i class="fas fa-key"></i> Password Reset
        </h2>
        
        <div class="alert alert-warning">
            <strong>⚠️ Security Warning:</strong><br>
            Delete this file (reset_password.php) after resetting your password!
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php else: ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" value="admin" required>
                    <small class="text-muted">Default: admin</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <input type="text" name="new_password" class="form-control" placeholder="Enter new password" required>
                    <small class="text-muted">Enter your desired password</small>
                </div>
                
                <button type="submit" class="btn btn-primary w-100">
                    Reset Password
                </button>
            </form>
            
            <div class="mt-4 p-3" style="background: #f8f9fa; border-radius: 10px;">
                <strong>Quick Fix Option:</strong>
                <p class="mb-0 small">Or run this SQL in phpMyAdmin:</p>
                <code style="font-size: 11px;">
                    UPDATE users SET password = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy' WHERE username = 'admin';
                </code>
                <p class="mb-0 small mt-2">This will set password to: <strong>admin123</strong></p>
            </div>
        <?php endif; ?>
        
        <div class="text-center mt-3">
            <a href="login.php" class="btn btn-link">Back to Login</a>
        </div>
    </div>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</body>
</html>
