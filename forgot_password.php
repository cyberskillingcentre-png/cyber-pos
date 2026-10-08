<?php
require_once 'master_config.php';

$error = '';
$success = '';
$step = 'email'; // email → reset → done
$reset_username = '';

// ============================================
// STEP 1: VERIFY EMAIL/USERNAME
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    $identifier = trim($_POST['identifier'] ?? '');
    
    try {
        if (empty($identifier)) {
            throw new Exception('Please enter your email or username');
        }
        
        $master_conn = get_master_connection();
        $stmt = $master_conn->prepare("SELECT * FROM organizations WHERE status IN ('active', 'expired') ORDER BY org_id DESC");
        $stmt->execute();
        $organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($organizations)) {
            throw new Exception('No organizations found');
        }
        
        $found = false;
        $found_org = null;
        $found_user = null;
        
        foreach ($organizations as $org) {
            try {
                $org_conn = new PDO(
                    "mysql:host=" . MASTER_DB_HOST . ";dbname=" . $org['database_name'],
                    MASTER_DB_USER,
                    MASTER_DB_PASS,
                    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
                );
                
                // Try email first, then username
                $stmt = $org_conn->prepare("
                    SELECT * FROM users 
                    WHERE (email = ? OR username = ?) 
                    AND status = 'active' 
                    LIMIT 1
                ");
                $stmt->execute([$identifier, $identifier]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    $found = true;
                    $found_org = $org;
                    $found_user = $user;
                    break;
                }
            } catch(PDOException $e) {
                continue;
            }
        }
        
        if (!$found) {
            throw new Exception('No account found with that email or username');
        }
        
        // Store in session
        $_SESSION['reset_user_id'] = $found_user['user_id'];
        $_SESSION['reset_org_id'] = $found_org['org_id'];
        $_SESSION['reset_database_name'] = $found_org['database_name'];
        $_SESSION['reset_username'] = $found_user['username'];
        $_SESSION['reset_full_name'] = $found_user['full_name'];
        
        $step = 'reset';
        $reset_username = $found_user['username'];
        
    } catch(Exception $e) {
        $error = $e->getMessage();
        $step = 'email';
    }
}

// ============================================
// STEP 2: RESET PASSWORD
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    try {
        if (!isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_database_name'])) {
            throw new Exception('Session expired. Please start over.');
        }
        
        if (strlen($new_password) < 6) {
            throw new Exception('Password must be at least 6 characters');
        }
        
        if ($new_password !== $confirm_password) {
            throw new Exception('Passwords do not match');
        }
        
        // Connect to user's org database
        $org_conn = new PDO(
            "mysql:host=" . MASTER_DB_HOST . ";dbname=" . $_SESSION['reset_database_name'],
            MASTER_DB_USER,
            MASTER_DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
        );
        
        // Update password
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $org_conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE user_id = ?");
        $stmt->execute([$hashed, $_SESSION['reset_user_id']]);
        
        // Log activity (best effort)
        try {
            $stmt = $org_conn->prepare("
                INSERT INTO activity_logs (user_id, activity_type, activity_description, ip_address, created_at) 
                VALUES (?, 'PASSWORD_RESET', 'Password reset via forgot password', ?, NOW())
            ");
            $stmt->execute([$_SESSION['reset_user_id'], $_SERVER['REMOTE_ADDR']]);
        } catch(PDOException $e) {
            // Log table may not exist - ignore
        }
        
        // Clear reset session
        unset($_SESSION['reset_user_id']);
        unset($_SESSION['reset_org_id']);
        unset($_SESSION['reset_database_name']);
        unset($_SESSION['reset_username']);
        unset($_SESSION['reset_full_name']);
        
        $step = 'done';
        $success = 'Password reset successfully!';
        
    } catch(Exception $e) {
        $error = $e->getMessage();
        $step = 'reset';
        $reset_username = $_SESSION['reset_username'] ?? '';
    }
}

// ============================================
// DETERMINE STEP ON PAGE LOAD
// ============================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_SESSION['reset_user_id'])) {
        $step = 'reset';
        $reset_username = $_SESSION['reset_username'] ?? '';
    } else {
        $step = 'email';
    }
}

if ($step === 'reset' && empty($reset_username) && isset($_SESSION['reset_username'])) {
    $reset_username = $_SESSION['reset_username'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo APP_NAME; ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --cyber-purple: #6B46C1;
            --cyber-purple-dark: #553C9A;
            --cyber-orange: #ED8936;
            --cyber-green: #48BB78;
            --cyber-red: #E53E3E;
            --cyber-dark: #1A202C;
            --cyber-gray: #2D3748;
            --cyber-gray-lighter: #718096;
            --cyber-border: #E2E8F0;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        html, body {
            width: 100%;
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background-image: url('assets/images/login-bg.png');
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
        }
        
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: radial-gradient(
                ellipse at center,
                rgba(0, 0, 0, 0) 0%,
                rgba(0, 0, 0, 0.15) 70%,
                rgba(0, 0, 0, 0.35) 100%
            );
            z-index: 0;
            pointer-events: none;
        }
        
        .forgot-card {
            position: relative;
            z-index: 1;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 44px 40px;
            width: 100%;
            max-width: 440px;
            box-shadow: 
                0 25px 70px rgba(0, 0, 0, 0.45),
                0 0 0 1px rgba(255, 255, 255, 0.15);
            animation: scaleIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.94) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        
        /* Header */
        .forgot-header {
            text-align: center;
            margin-bottom: 28px;
        }
        
        .forgot-header-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.75rem;
            box-shadow: 0 10px 30px rgba(107, 70, 193, 0.4);
            margin-bottom: 16px;
        }
        
        .forgot-header-icon.success {
            background: linear-gradient(135deg, var(--cyber-green), #38A169);
            box-shadow: 0 10px 30px rgba(72, 187, 120, 0.4);
        }
        
        .forgot-header h1 {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--cyber-dark);
            margin: 0;
            letter-spacing: -0.5px;
        }
        
        .forgot-header p {
            font-size: 0.85rem;
            color: var(--cyber-gray-lighter);
            margin: 8px 0 0 0;
            line-height: 1.5;
        }
        
        /* Progress Steps */
        .steps {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 26px;
            gap: 8px;
        }
        
        .step-dot {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #EDF2F7;
            color: #A0AEC0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 700;
            transition: all 0.3s ease;
        }
        
        .step-dot.active {
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            color: #fff;
            box-shadow: 0 4px 12px rgba(107, 70, 193, 0.35);
        }
        
        .step-dot.completed {
            background: linear-gradient(135deg, var(--cyber-green), #38A169);
            color: #fff;
        }
        
        .step-line {
            width: 40px;
            height: 2px;
            background: #EDF2F7;
            border-radius: 2px;
            transition: background 0.3s ease;
        }
        
        .step-line.completed {
            background: var(--cyber-green);
        }
        
        /* Alerts */
        .alert-cyber {
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .alert-error {
            background: rgba(229, 62, 62, 0.08);
            border: 1px solid rgba(229, 62, 62, 0.2);
            border-left: 4px solid var(--cyber-red);
            color: #C53030;
            animation: shake 0.4s ease;
        }
        
        .alert-success {
            background: rgba(72, 187, 120, 0.08);
            border: 1px solid rgba(72, 187, 120, 0.2);
            border-left: 4px solid var(--cyber-green);
            color: #2F855A;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-8px); }
            75% { transform: translateX(8px); }
        }
        
        .alert-cyber i {
            flex-shrink: 0;
            margin-top: 2px;
            font-size: 1rem;
        }
        
        /* Form */
        .form-group {
            margin-bottom: 18px;
        }
        
        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--cyber-gray);
            margin-bottom: 8px;
        }
        
        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .input-wrapper .input-icon {
            position: absolute;
            left: 18px;
            color: #A0AEC0;
            font-size: 1rem;
            pointer-events: none;
            transition: color 0.2s ease;
        }
        
        .input-wrapper input {
            width: 100%;
            padding: 15px 16px 15px 50px;
            border: 2px solid var(--cyber-border);
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--cyber-dark);
            background: #F7FAFC;
            transition: all 0.25s ease;
            font-family: inherit;
        }
        
        .input-wrapper input::placeholder {
            color: #A0AEC0;
            font-weight: 400;
        }
        
        .input-wrapper input:focus {
            outline: none;
            border-color: var(--cyber-purple);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(107, 70, 193, 0.12);
        }
        
        .input-wrapper input:focus ~ .input-icon,
        .input-wrapper:focus-within .input-icon {
            color: var(--cyber-purple);
        }
        
        .toggle-password {
            position: absolute;
            right: 16px;
            background: none;
            border: none;
            color: #A0AEC0;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: color 0.2s ease;
        }
        
        .toggle-password:hover {
            color: var(--cyber-purple);
        }
        
        /* Buttons */
        .btn-primary-cyber {
            width: 100%;
            padding: 15px 24px;
            background: linear-gradient(135deg, var(--cyber-purple) 0%, var(--cyber-purple-dark) 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 6px 20px rgba(107, 70, 193, 0.35);
            margin-top: 6px;
            text-decoration: none;
        }
        
        .btn-primary-cyber:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(107, 70, 193, 0.5);
            color: #fff;
        }
        
        .btn-success-cyber {
            background: linear-gradient(135deg, var(--cyber-green) 0%, #38A169 100%);
            box-shadow: 0 6px 20px rgba(72, 187, 120, 0.35);
        }
        
        .btn-success-cyber:hover {
            box-shadow: 0 10px 30px rgba(72, 187, 120, 0.5);
        }
        
        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 20px 0;
            color: var(--cyber-gray-lighter);
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--cyber-border);
        }
        
        .divider span { padding: 0 12px; }
        
        /* Back link */
        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            color: var(--cyber-purple);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 12px;
            border-radius: 10px;
            transition: all 0.2s ease;
        }
        
        .back-link:hover {
            background: rgba(107, 70, 193, 0.08);
            color: var(--cyber-purple-dark);
        }
        
        /* Reset info box */
        .reset-info {
            background: #F7FAFC;
            border: 1px solid var(--cyber-border);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 20px;
            font-size: 0.85rem;
            color: var(--cyber-gray);
        }
        
        .reset-info i {
            color: var(--cyber-purple);
            margin-right: 6px;
        }
        
        .reset-info strong {
            color: var(--cyber-dark);
        }
        
        /* Success state */
        .success-content {
            text-align: center;
            padding: 12px 0 20px;
        }
        
        .success-content p {
            color: var(--cyber-gray);
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        
        /* Responsive */
        @media (max-width: 575.98px) {
            body { background-attachment: scroll; padding: 12px; }
            .forgot-card { padding: 32px 24px; border-radius: 20px; }
            .forgot-header-icon { width: 56px; height: 56px; font-size: 1.5rem; }
            .forgot-header h1 { font-size: 1.2rem; }
            .input-wrapper input { padding: 13px 44px 13px 46px; font-size: 0.9rem; }
        }
        
        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
    
    <div class="forgot-card">
        
        <!-- Header -->
        <div class="forgot-header">
            <div class="forgot-header-icon <?php echo $step === 'done' ? 'success' : ''; ?>">
                <?php if ($step === 'done'): ?>
                    <i class="fas fa-check"></i>
                <?php elseif ($step === 'reset'): ?>
                    <i class="fas fa-lock"></i>
                <?php else: ?>
                    <i class="fas fa-key"></i>
                <?php endif; ?>
            </div>
            <h1>
                <?php if ($step === 'done'): ?>
                    Password Reset!
                <?php elseif ($step === 'reset'): ?>
                    Set New Password
                <?php else: ?>
                    Forgot Password?
                <?php endif; ?>
            </h1>
            <p>
                <?php if ($step === 'done'): ?>
                    Your password has been updated successfully
                <?php elseif ($step === 'reset'): ?>
                    Choose a new password for your account
                <?php else: ?>
                    Enter your email or username to reset your password
                <?php endif; ?>
            </p>
        </div>
        
        <!-- Progress Steps -->
        <div class="steps">
            <div class="step-dot <?php echo $step === 'email' ? 'active' : 'completed'; ?>">
                <?php if ($step === 'email'): ?>
                    1
                <?php else: ?>
                    <i class="fas fa-check"></i>
                <?php endif; ?>
            </div>
            <div class="step-line <?php echo $step === 'reset' || $step === 'done' ? 'completed' : ''; ?>"></div>
            <div class="step-dot <?php echo $step === 'reset' ? 'active' : ($step === 'done' ? 'completed' : ''); ?>">
                <?php if ($step === 'done'): ?>
                    <i class="fas fa-check"></i>
                <?php else: ?>
                    2
                <?php endif; ?>
            </div>
            <div class="step-line <?php echo $step === 'done' ? 'completed' : ''; ?>"></div>
            <div class="step-dot <?php echo $step === 'done' ? 'active' : ''; ?>">
                3
            </div>
        </div>
        
        <!-- Error Alert -->
        <?php if ($error): ?>
        <div class="alert-cyber alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- ============================================
             STEP 1: EMAIL/USERNAME FORM
             ============================================ -->
        <?php if ($step === 'email'): ?>
        
        <form method="POST">
            <input type="hidden" name="action" value="verify">
            
            <div class="form-group">
                <label class="form-label" for="identifier">Email or Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" 
                           id="identifier"
                           name="identifier" 
                           placeholder="Enter your email or username"
                           required 
                           autofocus
                           autocomplete="username">
                </div>
            </div>
            
            <button type="submit" class="btn-primary-cyber">
                <i class="fas fa-arrow-right"></i>
                <span>Continue</span>
            </button>
        </form>
        
        <div class="divider"><span>or</span></div>
        
        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Login
        </a>
        
        <?php endif; ?>
        
        <!-- ============================================
             STEP 2: RESET FORM
             ============================================ -->
        <?php if ($step === 'reset'): ?>
        
        <div class="reset-info">
            <i class="fas fa-user-check"></i>
            Resetting password for <strong><?php echo htmlspecialchars($reset_username); ?></strong>
        </div>
        
        <form method="POST" id="resetForm">
            <input type="hidden" name="action" value="reset">
            
            <div class="form-group">
                <label class="form-label" for="new_password">New Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" 
                           id="new_password"
                           name="new_password" 
                           placeholder="Enter new password"
                           required 
                           minlength="6"
                           autofocus
                           autocomplete="new-password">
                    <button type="button" class="toggle-password" data-target="new_password" aria-label="Toggle">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="confirm_password">Confirm Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" 
                           id="confirm_password"
                           name="confirm_password" 
                           placeholder="Confirm new password"
                           required 
                           minlength="6"
                           autocomplete="new-password">
                    <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Toggle">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <button type="submit" class="btn-primary-cyber">
                <i class="fas fa-check-circle"></i>
                <span>Reset Password</span>
            </button>
        </form>
        
        <div class="divider"><span>or</span></div>
        
        <a href="forgot_password.php" class="back-link">
            <i class="fas fa-redo"></i>
            Start Over
        </a>
        
        <?php endif; ?>
        
        <!-- ============================================
             STEP 3: SUCCESS
             ============================================ -->
        <?php if ($step === 'done'): ?>
        
        <div class="success-content">
            <p>
                Your password has been changed successfully.<br>
                You can now log in with your new password.
            </p>
        </div>
        
        <a href="index.php" class="btn-primary-cyber btn-success-cyber">
            <i class="fas fa-sign-in-alt"></i>
            <span>Go to Login</span>
        </a>
        
        <?php endif; ?>
        
    </div>
    
    <script>
        // Password toggle
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const input = document.getElementById(targetId);
                if (!input) return;
                
                const type = input.type === 'password' ? 'text' : 'password';
                input.type = type;
                
                const icon = this.querySelector('i');
                icon.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
            });
        });
        
        // Form validation
        const resetForm = document.getElementById('resetForm');
        if (resetForm) {
            resetForm.addEventListener('submit', function(e) {
                const newPass = document.getElementById('new_password').value;
                const confirmPass = document.getElementById('confirm_password').value;
                
                if (newPass.length < 6) {
                    e.preventDefault();
                    alert('Password must be at least 6 characters');
                    return false;
                }
                
                if (newPass !== confirmPass) {
                    e.preventDefault();
                    alert('Passwords do not match');
                    return false;
                }
                
                const btn = resetForm.querySelector('button[type="submit"]');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Resetting...</span>';
            });
        }
    </script>
    
</body>
</html>