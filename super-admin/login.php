<?php
//session_start();
require_once '../master_config.php';

// Redirect if already logged in
if (isset($_SESSION['super_admin_id'])) {
    header('Location: dashboard.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    csrf_require();
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    try {
        $stmt = $master_conn->prepare("SELECT * FROM super_admins WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin || !password_verify($password, $admin['password'])) {
            throw new Exception('Invalid username or password');
        }
        
        // Set session
        $_SESSION['super_admin_id'] = $admin['admin_id'];
        $_SESSION['super_admin_name'] = $admin['full_name'];
        $_SESSION['super_admin_email'] = $admin['email'];
        
        header('Location: dashboard.php');
        exit();
        
    } catch(Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            background: linear-gradient(135deg, #2D3748 0%, #1A202C 100%);
        }
        .login-card {
            background: white;
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        .admin-badge {
            background: linear-gradient(135deg, #E53E3E 0%, #C53030 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 50px;
            display: inline-block;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-5">
                    <div class="login-card">
                        <div class="text-center mb-4">
                            <i class="fas fa-shield-alt fa-3x text-danger mb-3"></i>
                            <h3>Super Admin Panel</h3>
                            <span class="admin-badge">
                                <i class="fas fa-crown me-2"></i>Administrator Access
                            </span>
                        </div>
                        
                        <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                        </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" name="username" class="form-control" required autofocus>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" name="password" class="form-control" required>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-danger w-100 btn-lg">
                                <i class="fas fa-sign-in-alt me-2"></i>Login to Admin Panel
                            </button>
                        </form>
                        
                        <hr class="my-4">
                        
                        <div class="text-center">
                            <a href="../index.php" class="text-muted">
                                <i class="fas fa-arrow-left me-2"></i>Back to Main Site
                            </a>
                        </div>
                        
                       
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>