<?php
require_once 'master_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $transaction_started = false;
    
    try {
        $org_name = trim($_POST['org_name']);
        $contact_person = trim($_POST['contact_person']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address');
        }
        
        // Validate username
        if (strlen($username) < 4) {
            throw new Exception('Username must be at least 4 characters long');
        }
        
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            throw new Exception('Username can only contain letters, numbers and underscore');
        }
        
        // Validate password
        if (strlen($password) < 6) {
            throw new Exception('Password must be at least 6 characters long');
        }
        
        if ($password !== $confirm_password) {
            throw new Exception('Passwords do not match');
        }
        
        // Check if email already exists
        $stmt = $master_conn->prepare("SELECT org_id FROM organizations WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception('Email already registered. Please use a different email address.');
        }
        
        // Check if username already exists in any organization
        $stmt = $master_conn->prepare("SELECT org_id FROM organizations WHERE LOWER(admin_username) = LOWER(?)");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            throw new Exception('Username already taken. Please choose a different username.');
        }
        
        // Generate unique org code
        $org_code = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($org_name)), 0, 6)) . rand(100, 999);
        
        $stmt = $master_conn->prepare("SELECT org_id FROM organizations WHERE org_code = ?");
        $stmt->execute([$org_code]);
        while ($stmt->fetch()) {
            $org_code = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($org_name)), 0, 6)) . rand(100, 999);
            $stmt = $master_conn->prepare("SELECT org_id FROM organizations WHERE org_code = ?");
            $stmt->execute([$org_code]);
        }
        
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Create organization database
        $db_name = create_organization_database($org_code, $username, $hashed_password);
        
        // Calculate subscription dates
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime('+' . DEMO_DAYS . ' days'));
        
        // Start transaction
        $master_conn->beginTransaction();
        $transaction_started = true;
        
        // Insert organization
        $stmt = $master_conn->prepare("
            INSERT INTO organizations 
            (org_name, org_code, contact_person, email, phone, address, database_name, 
             admin_username, subscription_type, subscription_start, subscription_end, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'demo', ?, ?, 'active')
        ");
        $stmt->execute([
            $org_name, $org_code, $contact_person, $email, $phone, 
            $address, $db_name, $username, $start_date, $end_date
        ]);
        
        $org_id = $master_conn->lastInsertId();
        
        // Log subscription history
        $stmt = $master_conn->prepare("
            INSERT INTO subscription_history 
            (org_id, action, new_end_date, days_added, notes) 
            VALUES (?, 'created', ?, ?, 'Demo account created')
        ");
        $stmt->execute([$org_id, $end_date, DEMO_DAYS]);
        
        $master_conn->commit();
        
        header('Location: Registered_Successfully?org_code=' . $org_code . '&username=' . urlencode($username));
        exit();
        
    } catch(Exception $e) {
        if ($transaction_started && isset($master_conn)) {
            $master_conn->rollBack();
        }
        
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Organization - <?php echo APP_NAME; ?></title>
    
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
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        html, body {
            width: 100%;
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
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
        
        /* Vignette overlay */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: radial-gradient(
                ellipse at center,
                rgba(0, 0, 0, 0) 0%,
                rgba(0, 0, 0, 0.15) 60%,
                rgba(0, 0, 0, 0.4) 100%
            );
            z-index: 0;
            pointer-events: none;
        }
        
        /* ============================================
           REGISTRATION CARD - GLASS
           ============================================ */
        .register-card {
            position: relative;
            z-index: 1;
            
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(30px) saturate(180%);
            -webkit-backdrop-filter: blur(30px) saturate(180%);
            
            border-radius: 22px;
            padding: 32px 36px;
            width: 100%;
            max-width: 780px;
            
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 
                0 25px 70px rgba(0, 0, 0, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.6);
            
            animation: scaleIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        
        /* ============================================
           HEADER
           ============================================ */
        .register-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(226, 232, 240, 0.6);
        }
        
        .register-icon {
            width: 54px;
            height: 54px;
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.4rem;
            flex-shrink: 0;
            box-shadow: 0 8px 22px rgba(107, 70, 193, 0.4);
        }
        
        .register-header-text h1 {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--cyber-dark);
            margin: 0;
            letter-spacing: 0.3px;
        }
        
        .register-header-text p {
            font-size: 0.82rem;
            color: var(--cyber-gray-lighter);
            margin: 3px 0 0 0;
        }
        
        /* Error Alert */
        .alert-error {
            background: rgba(229, 62, 62, 0.12);
            border: 1px solid rgba(229, 62, 62, 0.3);
            border-left: 3px solid var(--cyber-red);
            color: #C53030;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            animation: shake 0.4s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-6px); }
            75% { transform: translateX(6px); }
        }
        
        .alert-error i {
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        /* ============================================
           SECTION HEADERS
           ============================================ */
        .form-section {
            margin-bottom: 20px;
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--cyber-purple);
            margin-bottom: 14px;
        }
        
        .section-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, rgba(107, 70, 193, 0.2), transparent);
        }
        
        .section-title i {
            font-size: 0.85rem;
            color: var(--cyber-orange);
        }
        
        /* ============================================
           FORM
           ============================================ */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        
        .form-grid.full-width {
            grid-template-columns: 1fr;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--cyber-gray);
            margin-bottom: 6px;
        }
        
        .form-label .required {
            color: var(--cyber-red);
            margin-left: 2px;
        }
        
        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .input-wrapper .input-icon {
            position: absolute;
            left: 14px;
            color: #A0AEC0;
            font-size: 0.9rem;
            pointer-events: none;
            transition: color 0.2s ease;
        }
        
        .input-wrapper input,
        .input-wrapper textarea {
            width: 100%;
            padding: 11px 14px 11px 40px;
            border: 1.5px solid rgba(255, 255, 255, 0.6);
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 500;
            color: var(--cyber-dark);
            
            background: rgba(255, 255, 255, 0.55);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            
            transition: all 0.2s ease;
            font-family: inherit;
        }
        
        .input-wrapper textarea {
            resize: vertical;
            min-height: 60px;
            padding-top: 10px;
            padding-bottom: 10px;
        }
        
        .input-wrapper input::placeholder,
        .input-wrapper textarea::placeholder {
            color: #A0AEC0;
            font-weight: 400;
        }
        
        .input-wrapper input:focus,
        .input-wrapper textarea:focus {
            outline: none;
            border-color: var(--cyber-purple);
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 0 0 3px rgba(107, 70, 193, 0.15);
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
            font-size: 0.9rem;
        }
        
        .toggle-password:hover {
            color: var(--cyber-purple);
        }
        
        .input-hint {
            display: block;
            margin-top: 4px;
            font-size: 0.7rem;
            color: #A0AEC0;
        }
        
        /* ============================================
           PASSWORD STRENGTH
           ============================================ */
        .password-strength {
            margin-top: 6px;
            height: 4px;
            background: rgba(226, 232, 240, 0.6);
            border-radius: 2px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }
        
        .strength-weak { background: #E53E3E; width: 33%; }
        .strength-medium { background: #ED8936; width: 66%; }
        .strength-strong { background: #48BB78; width: 100%; }
        
        /* ============================================
           BUTTON ROW
           ============================================ */
        .btn-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid rgba(226, 232, 240, 0.6);
        }
        
        .btn-primary-cyber,
        .btn-secondary-cyber {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 20px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            cursor: pointer;
            transition: all 0.25s ease;
            border: none;
            text-decoration: none;
            white-space: nowrap;
            font-family: inherit;
        }
        
        .btn-primary-cyber {
            background: linear-gradient(135deg, var(--cyber-purple) 0%, var(--cyber-purple-dark) 100%);
            color: #fff;
            box-shadow: 0 6px 18px rgba(107, 70, 193, 0.35);
        }
        
        .btn-primary-cyber:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(107, 70, 193, 0.5);
            background: linear-gradient(135deg, var(--cyber-purple-dark) 0%, var(--cyber-purple) 100%);
            color: #fff;
        }
        
        .btn-primary-cyber:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .btn-secondary-cyber {
            background: rgba(255, 255, 255, 0.6);
            color: var(--cyber-gray);
            border: 1.5px solid rgba(160, 174, 192, 0.4);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        
        .btn-secondary-cyber:hover {
            background: rgba(255, 255, 255, 0.9);
            color: var(--cyber-dark);
            border-color: var(--cyber-gray-lighter);
            transform: translateY(-2px);
        }
        
        /* ============================================
           FOOTER
           ============================================ */
        .register-footer {
            text-align: center;
            margin-top: 18px;
            padding-top: 14px;
            font-size: 0.72rem;
            color: #A0AEC0;
            letter-spacing: 0.3px;
        }
        
        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 767.98px) {
            body {
                background-attachment: scroll;
                padding: 12px;
            }
            
            .register-card {
                padding: 24px 20px;
                border-radius: 18px;
                max-width: 100%;
            }
            
            .register-header {
                gap: 12px;
                margin-bottom: 20px;
                padding-bottom: 16px;
            }
            
            .register-icon {
                width: 46px;
                height: 46px;
                font-size: 1.15rem;
            }
            
            .register-header-text h1 {
                font-size: 1.05rem;
            }
            
            .register-header-text p {
                font-size: 0.75rem;
            }
            
            /* Stack the form grid on mobile */
            .form-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .btn-row {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 360px) {
            .register-card {
                padding: 20px 16px;
            }
        }
        
        @media (prefers-reduced-motion: reduce) {
            * {
                animation: none !important;
                transition: none !important;
            }
        }
    </style>
</head>
<body>
    
    <div class="register-card">
        
        <!-- Header -->
        <div class="register-header">
            <div class="register-icon">
                <i class="fas fa-building"></i>
            </div>
            <div class="register-header-text">
                <h1>Create Your Organization</h1>
                <p>Get started with <?php echo APP_NAME; ?> in under a minute</p>
            </div>
        </div>
        
        <!-- Error Alert -->
        <?php if (!empty($error_message)): ?>
        <div class="alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error_message); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Registration Form -->
        <form method="POST" id="registerForm">
            
            <!-- Section: Organization Info -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-building"></i>
                    Organization Information
                </div>
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label" for="org_name">
                            Organization Name <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-briefcase input-icon"></i>
                            <input type="text" 
                                   id="org_name"
                                   name="org_name" 
                                   placeholder="e.g. Cyber Plaza Retail Ltd"
                                   value="<?php echo htmlspecialchars($_POST['org_name'] ?? ''); ?>"
                                   required 
                                   autofocus>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="contact_person">
                            Contact Person <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-user-tie input-icon"></i>
                            <input type="text" 
                                   id="contact_person"
                                   name="contact_person" 
                                   placeholder="Full name"
                                   value="<?php echo htmlspecialchars($_POST['contact_person'] ?? ''); ?>"
                                   required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="phone">
                            Phone <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-phone input-icon"></i>
                            <input type="tel" 
                                   id="phone"
                                   name="phone" 
                                   placeholder="+256 XXX XXX XXX"
                                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                   required>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label" for="email">
                            Email Address <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" 
                                   id="email"
                                   name="email" 
                                   placeholder="you@company.com"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                   required>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label" for="address">Address</label>
                        <div class="input-wrapper">
                            <i class="fas fa-map-marker-alt input-icon" style="top: 12px;"></i>
                            <textarea id="address"
                                      name="address" 
                                      placeholder="Business address (optional)"
                                      rows="2"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section: Account Info -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-user-shield"></i>
                    Admin Account
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="username">
                            Username <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" 
                                   id="username"
                                   name="username" 
                                   placeholder="Choose username"
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                                   minlength="4"
                                   pattern="[a-zA-Z0-9_]+"
                                   required>
                        </div>
                        <small class="input-hint">4+ chars, letters/numbers/underscore</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="password">
                            Password <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" 
                                   id="password"
                                   name="password" 
                                   placeholder="Choose password"
                                   minlength="6"
                                   required>
                            <button type="button" class="toggle-password" data-target="password" aria-label="Toggle">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength">
                            <div class="password-strength-bar" id="strengthBar"></div>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label" for="confirm_password">
                            Confirm Password <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" 
                                   id="confirm_password"
                                   name="confirm_password" 
                                   placeholder="Re-enter password"
                                   minlength="6"
                                   required>
                            <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Toggle">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Buttons -->
            <div class="btn-row">
                <a href="index.php" class="btn-secondary-cyber">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Login</span>
                </a>
                
                <button type="submit" class="btn-primary-cyber" id="submitBtn">
                    <i class="fas fa-user-plus"></i>
                    <span>Create Account</span>
                </button>
            </div>
        </form>
        
        <!-- Footer -->
        <div class="register-footer">
            <?php echo date('Y'); ?> &copy; <?php echo APP_NAME; ?> &middot; Your data is safe &amp; secure
        </div>
    </div>
    
    <script>
        // ============================================
        // PASSWORD TOGGLE
        // ============================================
        document.querySelectorAll('.toggle-password').forEach(function(button) {
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
        
        // ============================================
        // PASSWORD STRENGTH METER
        // ============================================
        const passwordInput = document.getElementById('password');
        const strengthBar = document.getElementById('strengthBar');
        
        if (passwordInput && strengthBar) {
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                
                if (password.length >= 6) strength++;
                if (password.length >= 10) strength++;
                if (/[A-Z]/.test(password)) strength++;
                if (/[0-9]/.test(password)) strength++;
                if (/[^A-Za-z0-9]/.test(password)) strength++;
                
                // Reset classes
                strengthBar.className = 'password-strength-bar';
                
                if (password.length === 0) {
                    strengthBar.style.width = '0%';
                } else if (strength <= 2) {
                    strengthBar.classList.add('strength-weak');
                } else if (strength <= 3) {
                    strengthBar.classList.add('strength-medium');
                } else {
                    strengthBar.classList.add('strength-strong');
                }
            });
        }
        
        // ============================================
        // FORM VALIDATION
        // ============================================
        const form = document.getElementById('registerForm');
        const submitBtn = document.getElementById('submitBtn');
        
        if (form) {
            form.addEventListener('submit', function(e) {
                const password = document.getElementById('password').value;
                const confirm = document.getElementById('confirm_password').value;
                
                if (password !== confirm) {
                    e.preventDefault();
                    alert('Passwords do not match');
                    return false;
                }
                
                if (password.length < 6) {
                    e.preventDefault();
                    alert('Password must be at least 6 characters');
                    return false;
                }
                
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Creating Account...</span>';
            });
        }
    </script>
    
</body>
</html>