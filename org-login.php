<?php
require_once 'master_config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    try {
        // Get master connection
        $master_conn = get_master_connection();
        
        // Get all active organizations
        $stmt = $master_conn->prepare("SELECT * FROM organizations WHERE status IN ('active', 'expired') ORDER BY org_id DESC");
        $stmt->execute();
        $organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($organizations)) {
            throw new Exception('No organizations found in the system');
        }
        
        $user_found = false;
        $found_org = null;
        $found_user = null;
        
        // Search for username in each organization's database
        foreach ($organizations as $org) {
            try {
                // Connect to organization database
                $org_conn = new PDO(
                    "mysql:host=" . MASTER_DB_HOST . ";dbname=" . $org['database_name'],
                    MASTER_DB_USER,
                    MASTER_DB_PASS,
                    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
                );
                
                // Search for user in this organization's database
                $stmt = $org_conn->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    // User found! Verify password
                    if (password_verify($password, $user['password'])) {
                        $user_found = true;
                        $found_org = $org;
                        $found_user = $user;
                        break; // Stop searching
                    } else {
                        // Username exists but wrong password
                        throw new Exception('Invalid username or password');
                    }
                }
                
            } catch(PDOException $e) {
                // Database connection failed for this org, skip to next
                continue;
            }
        }
        
        if (!$user_found) {
            throw new Exception('Invalid username or password');
        }
        
        // Check organization status
        $status = check_organization_status($found_org['org_id']);
        
        if (!$status['valid']) {
            throw new Exception($status['message']);
        }
        
        // Log login attempt
        $stmt = $master_conn->prepare("INSERT INTO org_login_attempts (org_id, ip_address) VALUES (?, ?)");
        $stmt->execute([$found_org['org_id'], $_SERVER['REMOTE_ADDR']]);
        
        // Set session variables - BASIC INFO
        $_SESSION['org_id'] = $found_org['org_id'];
        $_SESSION['org_code'] = $found_org['org_code'];
        $_SESSION['org_name'] = $found_org['org_name'];
        $_SESSION['database_name'] = $found_org['database_name'];
        $_SESSION['user_id'] = $found_user['user_id'];
        $_SESSION['username'] = $found_user['username'];
        $_SESSION['full_name'] = $found_user['full_name'];
        $_SESSION['role'] = $found_user['role'];
        $_SESSION['subscription_end'] = $found_org['subscription_end'];
        $_SESSION['subscription_type'] = $found_org['subscription_type'];
        
        // Set session variables - ORGANIZATION DETAILS
        $_SESSION['org_address'] = $found_org['address'] ?? '';
        $_SESSION['org_phone'] = $found_org['phone'] ?? '';
        $_SESSION['org_email'] = $found_org['email'] ?? '';
        $_SESSION['org_tin'] = $found_org['tin'] ?? '';
        
        // Redirect based on role
        if ($found_user['role'] == 'admin') {
            header('Location: admin/Admin_Dashboard');
        } elseif ($found_user['role'] == 'client') {
            header('Location: client/Client_Dashboard');
        } else {
            header('Location: employee/Employee_Dashboard');
        }
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
    <title>Login - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
            * {
                box-sizing: border-box;
            }

            html,
          body {
            margin: 0;
            padding: 0;
            width: 100%;
            min-height: 100%;
        }

        body {
            min-height: 100vh;
        }

        .login-container {
            min-height: 60vh;
            width: 100%;

            
            background-image: url('assets/images/login.png');
            background-repeat: no-repeat;

            background-size: cover;
            background-position: center center;

            display: flex;
            align-items: center;

            padding: 3px;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.97);
            border-radius: 8px;
            padding: 20px;

            width: 100%;
            max-width: 460px;

            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.35);
        }

        .login-card img {
            width: 200px;
            height: 40px;
            object-fit: contain;
            display: block;
            margin: 0 auto;
        }

        .role-badges {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
        }

        .role-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        /* Desktop */
        @media (min-width: 992px) {
            .login-container {
                padding-right: 7%;
                padding-left: 5%;
            }
        }

        /* Mobile */
        @media (max-width: 767px) {
            .login-container {
                background-position: center center;
                padding: 15px;
            }

            .login-card {
                max-width: 100%;
            }

            .login-card img {
                width: 170px;
            }
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
                            <div class="login-logo">
                                <i class="fas fa-bolt fa-3x text-white"></i>
                            </div>
                            <h3><?php echo APP_NAME; ?></h3>
                            <p class="text-muted">Login to Your Account</p>
                        </div>
                        
                        <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                        </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Username *</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" name="username" class="form-control" placeholder="Enter your username" required autofocus>
                                </div>
                                <small class="text-muted">Your username works across all organizations</small>
                            </div>
                            <div class="mb-4">
                                <label class="form-label">Password *</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 btn-lg">
                                <i class="fas fa-sign-in-alt me-2"></i>Login
                            </button>
                        </form>
                        
                        <hr class="my-4">
                        
                        <div class="text-center">
                            <p class="text-muted mb-2">Don't have an account?</p>
                            <a href="Welcome_to_CYBER_POS#register" class="btn btn-outline-primary">
                                <i class="fas fa-building me-2"></i>Register Organization
                            </a>
                        </div>
                        
                        <!-- User Role Information -->
                        <div class="mt-4 text-center">
                            <small class="text-muted d-block mb-2">
                                <i class="fas fa-info-circle me-1"></i>
                                Login as:
                            </small>
                            <div class="role-badges">
                                <span class="role-badge bg-danger text-white">
                                    <i class="fas fa-user-shield me-1"></i>Admin
                                </span>
                                <span class="role-badge bg-primary text-white">
                                    <i class="fas fa-user-tie me-1"></i>Employee
                                </span>
                                <span class="role-badge bg-success text-white">
                                    <i class="fas fa-user-circle me-1"></i>Client
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>