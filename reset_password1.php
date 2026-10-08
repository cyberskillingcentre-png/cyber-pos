<?php
/**
 * ============================================
 * PASSWORD RESET UTILITY
 * ============================================
 * 
 * Use this file to reset a user's password when they cannot log in.
 * Supports both single-org and multi-organization setups.
 * 
 * INSTRUCTIONS:
 * 1. Place in project root
 * 2. Open: http://localhost/pos_updated/reset_password.php
 * 3. Enter username and new password
 * 4. DELETE THIS FILE AFTER USE (security risk!)
 */

require_once 'config/config.php';

$success = '';
$error = '';
$debug = '';

// ============================================
// DETECT IF MULTI-ORG OR SINGLE-ORG
// ============================================
$is_multi_org = false;
$master_conn = null;

// Try to load master config if it exists
if (file_exists(__DIR__ . '/master_config.php')) {
    try {
        require_once __DIR__ . '/master_config.php';
        if (function_exists('get_master_connection')) {
            $master_conn = get_master_connection();
            $is_multi_org = ($master_conn !== null);
        }
    } catch(Exception $e) {
        $is_multi_org = false;
    }
}

// ============================================
// HANDLE PASSWORD RESET
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    try {
        // Validation
        if (empty($username) || empty($new_password) || empty($confirm_password)) {
            throw new Exception('Please fill in all fields');
        }
        
        if (strlen($new_password) < 6) {
            throw new Exception('Password must be at least 6 characters long');
        }
        
        if ($new_password !== $confirm_password) {
            throw new Exception('Passwords do not match');
        }
        
        // Hash password
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        
        $updated = false;
        $updated_in = '';
        
        if ($is_multi_org && $master_conn) {
            // ============================================
            // MULTI-ORGANIZATION MODE
            // ============================================
            $stmt = $master_conn->prepare("SELECT * FROM organizations WHERE status IN ('active', 'expired') ORDER BY org_id DESC");
            $stmt->execute();
            $organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($organizations as $org) {
                try {
                    $org_conn = new PDO(
                        "mysql:host=" . MASTER_DB_HOST . ";dbname=" . $org['database_name'],
                        MASTER_DB_USER,
                        MASTER_DB_PASS,
                        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
                    );
                    
                    $stmt = $org_conn->prepare("UPDATE users SET password = ? WHERE username = ?");
                    $stmt->execute([$hashed_password, $username]);
                    
                    if ($stmt->rowCount() > 0) {
                        $updated = true;
                        $updated_in = $org['org_name'];
                        break; // Stop at first match
                    }
                } catch(PDOException $e) {
                    continue;
                }
            }
        } else {
            // ============================================
            // SINGLE-ORGANIZATION MODE (standard config)
            // ============================================
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
            $stmt->execute([$hashed_password, $username]);
            
            if ($stmt->rowCount() > 0) {
                $updated = true;
                $updated_in = $_SESSION['org_name'] ?? 'this organization';
            }
        }
        
        // ============================================
        // RESULT
        // ============================================
        if ($updated) {
            $success = "Password updated successfully for user: <strong>" . htmlspecialchars($username) . "</strong>";
            if ($updated_in) {
                $success .= "<br>Organization: <strong>" . htmlspecialchars($updated_in) . "</strong>";
            }
            $success .= "<br><br><strong style='color: #C53030;'>⚠️ DELETE THIS FILE NOW FOR SECURITY!</strong>";
        } else {
            throw new Exception("User not found: " . htmlspecialchars($username));
        }
        
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
    <title>Password Reset - <?php echo APP_NAME ?? 'POS System'; ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
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
            overflow-x: hidden;
        }
        
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: radial-gradient(
                ellipse at center,
                rgba(0, 0, 0, 0) 0%,
                rgba(0, 0, 0, 0.2) 70%,
                rgba(0, 0, 0, 0.4) 100%
            );
            z-index: 0;
            pointer-events: none;
        }
        
        .reset-card {
            position: relative;
            z-index: 1;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 40px 36px;
            width: 100%;
            max-width: 460px;
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
        .reset-header {
            text-align: center;
            margin-bottom: 24px;
        }
        
        .reset-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.6rem;
            box-shadow: 0 10px 30px rgba(107, 70, 193, 0.4);
            margin-bottom: 14px;
        }
        
        .reset-icon.success {
            background: linear-gradient(135deg, var(--cyber-green), #38A169);
            box-shadow: 0 10px 30px rgba(72, 187, 120, 0.4);
        }
        
        .reset-header h1 {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--cyber-dark);
            margin: 0;
            letter-spacing: -0.5px;
        }
        
        .reset-header p {
            font-size: 0.85rem;
            color: var(--cyber-gray-lighter);
            margin: 6px 0 0 0;
        }
        
        /* Warning banner */
        .warning-banner {
            background: rgba(237, 137, 54, 0.08);
            border: 1px solid rgba(237, 137, 54, 0.25);
            border-left: 4px solid var(--cyber-orange);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 0.8rem;
            color: #744210;
        }
        
        .warning-banner i {
            color: var(--cyber-orange);
            font-size: 1rem;
            flex-shrink: 0;
            margin-top: 2px;
        }
        
        .warning-banner strong {
            display: block;
            margin-bottom: 2px;
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
        }
        
        .alert-success {
            background: rgba(72, 187, 120, 0.08);
            border: 1px solid rgba(72, 187, 120, 0.2);
            border-left: 4px solid var(--cyber-green);
            color: #2F855A;
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
            left: 16px;
            color: #A0AEC0;
            font-size: 0.95rem;
            pointer-events: none;
            transition: color 0.2s ease;
        }
        
        .input-wrapper input {
            width: 100%;
            padding: 13px 44px 13px 46px;
            border: 2px solid var(--cyber-border);
            border-radius: 12px;
            font-size: 0.9rem;
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
            right: 14px;
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
        
        .form-hint {
            display: block;
            margin-top: 5px;
            font-size: 0.72rem;
            color: #A0AEC0;
        }
        
        /* Submit */
        .btn-reset {
            width: 100%;
            padding: 14px 24px;
            background: linear-gradient(135deg, var(--cyber-purple) 0%, var(--cyber-purple-dark) 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 0.9rem;
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
        }
        
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(107, 70, 193, 0.5);
            background: linear-gradient(135deg, var(--cyber-purple-dark) 0%, var(--cyber-purple) 100%);
        }
        
        /* Back link */
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            padding-top: 18px;
            border-top: 1px solid var(--cyber-border);
            color: var(--cyber-purple);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: color 0.2s ease;
        }
        
        .back-link:hover {
            color: var(--cyber-purple-dark);
            text-decoration: underline;
        }
        
        .back-link i {
            margin-right: 6px;
        }
        
        /* Quick Fix card */
        .quick-fix {
            background: #F7FAFC;
            border: 1px dashed var(--cyber-border);
            border-radius: 10px;
            padding: 14px;
            margin-top: 20px;
            font-size: 0.78rem;
            color: var(--cyber-gray);
        }
        
        .quick-fix strong {
            display: block;
            margin-bottom: 6px;
            color: var(--cyber-dark);
            font-size: 0.8rem;
        }
        
        .quick-fix code {
            display: block;
            background: var(--cyber-dark);
            color: #FBD38D;
            padding: 8px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            overflow-x: auto;
            white-space: pre;
            word-break: break-all;
        }
        
        .quick-fix .note {
            margin-top: 8px;
            color: var(--cyber-gray-lighter);
            font-size: 0.72rem;
        }
        
        /* Responsive */
        @media (max-width: 575.98px) {
            body {
                background-attachment: scroll;
                padding: 12px;
            }
            
            .reset-card {
                padding: 30px 24px;
                border-radius: 20px;
            }
            
            .reset-icon {
                width: 56px;
                height: 56px;
                font-size: 1.4rem;
            }
            
            .reset-header h1 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    
    <div class="reset-card">
        
        <!-- Header -->
        <div class="reset-header">
            <div class="reset-icon <?php echo $success ? 'success' : ''; ?>">
                <i class="fas <?php echo $success ? 'fa-check' : 'fa-key'; ?>"></i>
            </div>
            <h1><?php echo $success ? 'Password Reset!' : 'Password Reset'; ?></h1>
            <p>
                <?php if ($success): ?>
                    Your password has been updated
                <?php else: ?>
                    Reset a user's password
                <?php endif; ?>
            </p>
        </div>
        
        <!-- Security Warning -->
        <?php if (!$success): ?>
        <div class="warning-banner">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <strong>Security Warning</strong>
                Delete this file (reset_password.php) immediately after use!
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Error -->
        <?php if ($error): ?>
        <div class="alert-cyber alert-error">
            <i class="fas fa-times-circle"></i>
            <span><?php echo $error; ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Success -->
        <?php if ($success): ?>
        <div class="alert-cyber alert-success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo $success; ?></span>
        </div>
        
        <a href="index.php" class="btn-reset" style="text-decoration: none; margin-top: 10px;">
            <i class="fas fa-sign-in-alt"></i>
            Go to Login
        </a>
        
        <?php else: ?>
        
        <!-- Reset Form -->
        <form method="POST" id="resetForm">
            
            <!-- Username -->
            <div class="form-group">
                <label class="form-label" for="username">Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" 
                           id="username"
                           name="username" 
                           placeholder="Enter username"
                           value="admin"
                           required
                           autocomplete="username">
                </div>
                <small class="form-hint">Default admin username is usually "admin"</small>
            </div>
            
            <!-- New Password -->
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
                           autocomplete="new-password">
                    <button type="button" class="toggle-password" data-target="new_password" aria-label="Toggle">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <small class="form-hint">Minimum 6 characters</small>
            </div>
            
            <!-- Confirm Password -->
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
            
            <!-- Submit -->
            <button type="submit" class="btn-reset">
                <i class="fas fa-key"></i>
                <span>Reset Password</span>
            </button>
        </form>
        
        <!-- Quick Fix -->
        <div class="quick-fix">
            <strong><i class="fas fa-terminal"></i> Quick Fix (SQL)</strong>
            <code>UPDATE users SET password = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy' WHERE username = 'admin';</code>
            <div class="note">
                Sets password to: <strong>admin123</strong> — change immediately after login!
            </div>
        </div>
        
        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Login
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
        const form = document.getElementById('resetForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                const newPass = document.getElementById('new_password').value;
                const confirmPass = document.getElementById('confirm_password').value;
                
                if (newPass !== confirmPass) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                    return false;
                }
                
                if (newPass.length < 6) {
                    e.preventDefault();
                    alert('Password must be at least 6 characters');
                    return false;
                }
                
                const btn = document.querySelector('.btn-reset');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Resetting...</span>';
            });
        }
    </script>
    
</body>
</html>