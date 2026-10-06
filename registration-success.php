<?php
require_once 'master_config.php';

$org_code = isset($_GET['org_code']) ? $_GET['org_code'] : '';
$username = isset($_GET['username']) ? $_GET['username'] : '';

if (!$org_code || !$username) {
    header('Location: Welcome_to_CYBER_POS');
    exit();
}

$stmt = $master_conn->prepare("SELECT * FROM organizations WHERE org_code = ?");
$stmt->execute([$org_code]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    header('Location: Welcome_to_CYBER_POS');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Successful - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .success-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            background: linear-gradient(135deg, #6B46C1 0%, #805AD5 100%);
        }
        .success-card {
            background: white;
            border-radius: 15px;
            padding: 50px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: #48BB78;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
        }
        .credentials-box {
            background: #F7FAFC;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #6B46C1;
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="success-card">
                        <div class="success-icon">
                            <i class="fas fa-check fa-3x text-white"></i>
                        </div>
                        
                        <h2 class="text-center mb-4">Registration Successful!</h2>
                        
                        <p class="text-center text-muted mb-4">
                            Your organization has been successfully registered. Your 30-day free trial starts now!
                        </p>
                        
                        <div class="credentials-box mb-4">
                            <h5 class="mb-3"><i class="fas fa-key me-2"></i>Your Login Credentials</h5>
                            <div class="row mb-2">
                                <div class="col-4"><strong>Username:</strong></div>
                                <div class="col-8">
                                    <code class="fs-5 text-primary"><?php echo htmlspecialchars($username); ?></code>
                                </div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-4"><strong>Password:</strong></div>
                                <div class="col-8"><code>Your chosen password</code></div>
                            </div>
                            <div class="row">
                                <div class="col-4"><strong>Trial Expires:</strong></div>
                                <div class="col-8">
                                    <span class="text-danger">
                                        <?php echo date('F d, Y', strtotime($org['subscription_end'])); ?>
                                        (<?php echo DEMO_DAYS; ?> days)
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Important:</strong> Please save your credentials. You will need them to login.
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            You can change your password after logging in from the settings page.
                        </div>
                        
                        <div class="text-center">
                            <a href="Login" class="btn btn-primary btn-lg">
                                <i class="fas fa-sign-in-alt me-2"></i>Login to Your Account
                            </a>
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="text-center">
                            <p class="text-muted mb-2">Need help getting started?</p>
                            <p>
                                Email: <a href="mailto:info@cyberplaza.tech">info@cyberplaza.tech</a><br>
                                Phone: +256 757741977  |  +256 761310916
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>