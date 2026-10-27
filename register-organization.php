<?php
require_once 'config/config.php';
require_once 'master_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    csrf_require();
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
            --cyber-red: #E53E3E;
            --cyber-dark: #1A202C;
            --cyber-gray: #2D3748;
            --cyber-gray-lighter: #4A5568;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        html, body {
            width: 100%;
            height: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            overflow: hidden;
        }
        
        body {
            background-image: url('assets/images/login_im.png');
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            
            display: flex;
            align-items: center;
            justify-content: center;
            
            padding: 10px;
            position: relative;
        }
        
        /* Vignette overlay - keeps the edges slightly dark so the card pops */
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
           REGISTRATION CARD - CLEAR GLASS
           ============================================ */
        .register-card {
            position: relative;
            z-index: 1;
            
            /* Almost entirely transparent, just a faint white tint */
            background: rgba(255, 255, 255, 0.10); 
            
            /* Removed the heavy blur so the background person is clearly visible */
            backdrop-filter: blur(2px) saturate(120%);
            -webkit-backdrop-filter: blur(2px) saturate(120%);
            
            border-radius: 14px;
            padding: 20px 24px; 
            width: 100%;
            max-width: 820px;
            
            border: 1px solid rgba(255, 255, 255, 0.4);
            box-shadow: 
                0 20px 40px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.3);
            
            animation: scaleIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            
            /* Slight shadow for text readability against the photo */
            text-shadow: 0 1px 3px rgba(255,255,255,0.8);
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
            gap: 12px;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .register-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.1rem;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(107, 70, 193, 0.4);
            text-shadow: none;
        }
        
        .register-header-text h1 {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--cyber-dark);
            margin: 0;
        }
        
        .register-header-text p {
            font-size: 0.75rem;
            color: var(--cyber-dark);
            margin: 2px 0 0 0;
            font-weight: 600;
        }
        
        /* Error Alert */
        .alert-error {
            background: rgba(229, 62, 62, 0.2);
            border: 1px solid rgba(229, 62, 62, 0.5);
            color: #C53030;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 12px;
            text-shadow: none;
        }
        
        /* ============================================
           TWO-COLUMN LAYOUT
           ============================================ */
        .form-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .section-title {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #fff; /* White text with a dark drop shadow for contrast */
            text-shadow: 0 1px 3px rgba(0,0,0,0.8);
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.4);
        }
        
        .form-group {
            margin-bottom: 10px;
        }
        
        .form-label {
            display: block;
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #1A202C;
            margin-bottom: 4px;
        }
        
        .form-label .required {
            color: var(--cyber-red);
        }
        
        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .input-wrapper .input-icon {
            position: absolute;
            left: 10px;
            color: var(--cyber-gray);
            font-size: 0.8rem;
            pointer-events: none;
            z-index: 2;
        }
        
        /* Inputs are slightly less transparent than the card so you can read them */
        .input-wrapper input,
        .input-wrapper textarea {
            width: 100%;
            padding: 8px 10px 8px 32px;
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--cyber-dark);
            background: rgba(255, 255, 255, 0.35); /* 35% opaque so text is readable */
            transition: all 0.2s ease;
            text-shadow: none; /* Crisp text */
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        }
        
        .input-wrapper input::placeholder {
            color: rgba(45, 55, 72, 0.6);
            font-weight: 500;
        }
        
        .input-wrapper textarea {
            resize: none;
            height: 38px;
            padding-top: 8px;
        }
        
        .input-wrapper input:focus,
        .input-wrapper textarea:focus {
            outline: none;
            border-color: var(--cyber-purple);
            background: rgba(255, 255, 255, 0.8); /* Solid whiter focus */
            box-shadow: 0 0 0 3px rgba(107, 70, 193, 0.3);
        }
        
        /* ============================================
           BUTTONS
           ============================================ */
        .btn-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .btn-primary-cyber,
        .btn-secondary-cyber {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            text-decoration: none;
            text-shadow: none;
        }
        
        .btn-primary-cyber {
            background: var(--cyber-purple);
            color: #fff;
            box-shadow: 0 4px 10px rgba(107, 70, 193, 0.4);
        }
        
        .btn-primary-cyber:hover:not(:disabled) {
            background: var(--cyber-purple-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 15px rgba(107, 70, 193, 0.5);
        }
        
        .btn-secondary-cyber {
            background: rgba(255, 255, 255, 0.3);
            color: var(--cyber-dark);
            border: 1px solid rgba(255, 255, 255, 0.5);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        }
        
        .btn-secondary-cyber:hover {
            background: rgba(255, 255, 255, 0.6);
        }
        
        /* Footer */
        .register-footer {
            text-align: center;
            margin-top: 12px;
            font-size: 0.65rem;
            color: var(--cyber-dark);
            font-weight: 600;
        }
        
        /* ============================================
           RESPONSIVE (Mobile)
           ============================================ */
        @media (max-width: 767.98px) {
            body {
                overflow-y: auto;
                height: auto;
                min-height: 100vh;
                padding: 15px;
            }
            
            .form-layout {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .register-card {
                padding: 15px;
                margin: 10px 0;
                background: rgba(255, 255, 255, 0.25); /* Slightly more solid on mobile */
            }
            
            .btn-row {
                grid-template-columns: 1fr;
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
                <h1>Create Organization</h1>
                <p>Get started with <?php echo APP_NAME; ?></p>
            </div>
        </div>
        
        <!-- Error Alert -->
        <?php if (!empty($error_message)): ?>
        <div class="alert-error">
            <i class="fas fa-exclamation-circle me-1"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>
        
        <!-- Registration Form -->
        <form method="POST" id="registerForm">
            <?php echo csrf_field(); ?>
            
            <div class="form-layout">
                
                <!-- LEFT COLUMN -->
                <div>
                    <div class="section-title">Organization Details</div>
                    
                    <div class="form-group">
                        <label class="form-label" for="org_name">Org Name <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fas fa-briefcase input-icon"></i>
                            <input type="text" id="org_name" name="org_name" placeholder="e.g. Cyber Plaza Ltd" value="<?php echo htmlspecialchars($_POST['org_name'] ?? ''); ?>" required autofocus>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="contact_person">Contact Person <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fas fa-user-tie input-icon"></i>
                            <input type="text" id="contact_person" name="contact_person" placeholder="Full name" value="<?php echo htmlspecialchars($_POST['contact_person'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="phone">Phone <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fas fa-phone input-icon"></i>
                            <input type="tel" id="phone" name="phone" placeholder="+256 XXX XXX XXX" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="email">Email Address <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" id="email" name="email" placeholder="you@company.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="address">Address</label>
                        <div class="input-wrapper">
                            <i class="fas fa-map-marker-alt input-icon" style="top:8px;"></i>
                            <textarea id="address" name="address" placeholder="Business address (optional)"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- RIGHT COLUMN -->
                <div>
                    <div class="section-title">Admin Account</div>
                    
                    <div class="form-group">
                        <label class="form-label" for="username">Username <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" id="username" name="username" placeholder="Choose username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" minlength="4" pattern="[a-zA-Z0-9_]+" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="password">Password <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="password" name="password" placeholder="Choose password" minlength="6" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm Password <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password" minlength="6" required>
                        </div>
                    </div>
                </div>
                
            </div>
            
            <!-- Buttons -->
            <div class="btn-row">
                <a href="index.php" class="btn-secondary-cyber">
                    <i class="fas fa-arrow-left"></i> <span>Back to Login</span>
                </a>
                
                <button type="submit" class="btn-primary-cyber" id="submitBtn">
                    <i class="fas fa-user-plus"></i> <span>Create Account</span>
                </button>
            </div>
        </form>
        
        <!-- Footer -->
        <div class="register-footer">
            <?php echo date('Y'); ?> &copy; <?php echo APP_NAME; ?>
        </div>
    </div>
    
    <script>
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
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Creating...</span>';
            });
        }
    </script>
    
</body>
</html>