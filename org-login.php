<?php
require_once 'config/config.php';
require_once 'master_config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    csrf_require();

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    try {
        $master_conn = get_master_connection();

        $stmt = $master_conn->prepare("SELECT * FROM organizations WHERE status IN ('active', 'expired') ORDER BY org_id DESC");
        $stmt->execute();
        $organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($organizations)) {
            throw new Exception('No organizations found in the system');
        }

        $user_found = false;
        $found_org  = null;
        $found_user = null;

        foreach ($organizations as $org) {
            try {
                $org_conn = new PDO(
                    "mysql:host=" . MASTER_DB_HOST . ";dbname=" . $org['database_name'],
                    MASTER_DB_USER,
                    MASTER_DB_PASS,
                    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
                );

                $stmt = $org_conn->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    if (password_verify($password, $user['password'])) {
                        $user_found = true;
                        $found_org  = $org;
                        $found_user = $user;
                        break;
                    } else {
                        throw new Exception('Invalid username or password');
                    }
                }
            } catch (PDOException $e) {
                continue;
            }
        }

        if (!$user_found) {
            throw new Exception('Invalid username or password');
        }

        $status = check_organization_status($found_org['org_id']);

        if (!$status['valid']) {
            throw new Exception($status['message']);
        }

        $stmt = $master_conn->prepare("INSERT INTO org_login_attempts (org_id, ip_address) VALUES (?, ?)");
        $stmt->execute([$found_org['org_id'], $_SERVER['REMOTE_ADDR']]);

        $_SESSION['org_id']            = $found_org['org_id'];
        $_SESSION['org_code']          = $found_org['org_code'];
        $_SESSION['org_name']          = $found_org['org_name'];
        $_SESSION['database_name']     = $found_org['database_name'];
        $_SESSION['user_id']           = $found_user['user_id'];
        $_SESSION['username']          = $found_user['username'];
        $_SESSION['full_name']         = $found_user['full_name'];
        $_SESSION['role']              = $found_user['role'];
        $_SESSION['subscription_end']  = $found_org['subscription_end'];
        $_SESSION['subscription_type'] = $found_org['subscription_type'];
        $_SESSION['org_address']       = $found_org['address'] ?? '';
        $_SESSION['org_phone']         = $found_org['phone'] ?? '';
        $_SESSION['org_email']         = $found_org['email'] ?? '';
        $_SESSION['org_tin']           = $found_org['tin'] ?? '';
        $_SESSION['org_logo']          = $found_org['logo'] ?? null;
        $_SESSION['org_website']       = $found_org['website'] ?? '';

        // Rotate the CSRF token now that we're authenticated.
        // This prevents session fixation: any token that existed
        // before login is now invalidated.
        csrf_rotate();

        if ($found_user['role'] == 'admin') {
            header('Location: admin/dashboard.php');
        } elseif ($found_user['role'] == 'client') {
            header('Location: client/dashboard.php');
        } else {
            header('Location: employee/dashboard.php');
        }
        exit();

    } catch (Exception $e) {
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

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Google Fonts - Inter -->
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
            padding: 16px;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: radial-gradient(
                ellipse at center,
                rgba(0, 0, 0, 0) 0%,
                rgba(0, 0, 0, 0.12) 70%,
                rgba(0, 0, 0, 0.3) 100%
            );
            z-index: 0;
            pointer-events: none;
        }

        .login-card {
            position: relative;
            z-index: 1;
            background: rgba(255, 255, 255, 0.72);
            backdrop-filter: blur(30px) saturate(180%);
            -webkit-backdrop-filter: blur(30px) saturate(180%);
            border-radius: 20px;
            padding: 30px 32px;
            width: 100%;
            max-width: 400px;
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow:
                0 20px 60px rgba(0, 0, 0, 0.35),
                inset 0 1px 0 rgba(255, 255, 255, 0.6);
            animation: scaleIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.94) translateY(10px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }

        .login-logo { text-align: center; margin-bottom: 22px; }

        .login-logo-icon {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.4rem;
            box-shadow: 0 8px 22px rgba(107, 70, 193, 0.4);
            margin-bottom: 12px;
        }

        .login-logo h1 {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--cyber-dark);
            margin: 0;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .login-logo p {
            font-size: 0.8rem;
            color: var(--cyber-gray-lighter);
            margin: 4px 0 0 0;
        }

        .alert-error {
            background: rgba(229, 62, 62, 0.12);
            border: 1px solid rgba(229, 62, 62, 0.3);
            border-left: 3px solid var(--cyber-red);
            color: #C53030;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-6px); }
            75% { transform: translateX(6px); }
        }

        .alert-error i { font-size: 0.9rem; flex-shrink: 0; }

        .form-group { margin-bottom: 14px; }

        .form-label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--cyber-gray);
            margin-bottom: 6px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper .input-icon {
            position: absolute;
            left: 15px;
            color: #A0AEC0;
            font-size: 0.9rem;
            pointer-events: none;
            transition: color 0.2s ease;
        }

        .input-wrapper input {
            width: 100%;
            padding: 12px 14px 12px 42px;
            border: 1.5px solid rgba(255, 255, 255, 0.6);
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--cyber-dark);
            background: rgba(255, 255, 255, 0.55);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .input-wrapper input::placeholder { color: #A0AEC0; font-weight: 400; }

        .input-wrapper input:focus {
            outline: none;
            border-color: var(--cyber-purple);
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 0 0 3px rgba(107, 70, 193, 0.15);
        }

        .input-wrapper input:focus ~ .input-icon,
        .input-wrapper:focus-within .input-icon { color: var(--cyber-purple); }

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

        .toggle-password:hover { color: var(--cyber-purple); }

        .form-extras {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            font-size: 0.8rem;
        }

        .form-check { display: flex; align-items: center; gap: 6px; }

        .form-check-input {
            width: 14px;
            height: 14px;
            margin: 0;
            cursor: pointer;
            border: 1.5px solid rgba(160, 174, 192, 0.5);
            background-color: rgba(255, 255, 255, 0.7);
        }

        .form-check-input:checked {
            background-color: var(--cyber-purple);
            border-color: var(--cyber-purple);
        }

        .form-check-label { color: var(--cyber-gray); font-weight: 500; cursor: pointer; user-select: none; }

        .forgot-link {
            color: var(--cyber-purple);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s ease;
        }

        .forgot-link:hover { color: var(--cyber-purple-dark); text-decoration: underline; }

        .btn-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 4px;
        }

        .btn-row .btn-login,
        .btn-row .btn-register {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 16px;
            border-radius: 10px;
            font-size: 0.82rem;
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

        .btn-row .btn-login {
            background: linear-gradient(135deg, var(--cyber-purple) 0%, var(--cyber-purple-dark) 100%);
            color: #fff;
            box-shadow: 0 6px 18px rgba(107, 70, 193, 0.35);
        }

        .btn-row .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(107, 70, 193, 0.5);
            background: linear-gradient(135deg, var(--cyber-purple-dark) 0%, var(--cyber-purple) 100%);
        }

        .btn-row .btn-login:disabled { opacity: 0.7; cursor: not-allowed; }

        .btn-row .btn-register {
            background: rgba(255, 255, 255, 0.6);
            color: var(--cyber-purple);
            border: 1.5px solid var(--cyber-purple);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .btn-row .btn-register:hover {
            background: var(--cyber-purple);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(107, 70, 193, 0.35);
        }

        .btn-row .btn-login i,
        .btn-row .btn-register i { font-size: 0.9rem; }

        .login-footer {
            text-align: center;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid rgba(226, 232, 240, 0.6);
            font-size: 0.7rem;
            color: #A0AEC0;
            letter-spacing: 0.3px;
        }

        @media (max-width: 480px) {
            body { background-attachment: scroll; padding: 12px; }
            .login-card { max-width: 100%; padding: 24px 22px; border-radius: 18px; }
            .login-logo-icon { width: 46px; height: 46px; font-size: 1.2rem; }
            .login-logo h1 { font-size: 1rem; }
            .btn-row .btn-login,
            .btn-row .btn-register { padding: 12px 12px; font-size: 0.78rem; }
        }

        @media (max-width: 360px) { .btn-row { grid-template-columns: 1fr; } }

        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>

    <div class="login-card">

        <!-- Logo -->
        <div class="login-logo">
            <div class="login-logo-icon">
                <i class="fas fa-bolt"></i>
            </div>
            <h1><?php echo APP_NAME; ?></h1>
            <p>Sign in to your account</p>
        </div>

        <!-- Error Alert -->
        <?php if ($error): ?>
        <div class="alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" id="loginForm">

            <!-- CSRF token — MUST be the first child of the form -->
            <?php echo csrf_field(); ?>

            <!-- Username -->
            <div class="form-group">
                <label class="form-label" for="username">Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user input-icon"></i>
                    <input type="text"
                           id="username"
                           name="username"
                           placeholder="Enter your username"
                           required
                           autofocus
                           autocomplete="username">
                </div>
            </div>

            <!-- Password -->
            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password"
                           id="password"
                           name="password"
                           placeholder="Enter your password"
                           required
                           autocomplete="current-password">
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Toggle password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Remember / Forgot -->
            <div class="form-extras">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember" name="remember">
                    <label class="form-check-label" for="remember">Remember me</label>
                </div>
                <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
            </div>

            <!-- 50/50 BUTTONS -->
            <div class="btn-row">
                <button type="submit" class="btn-login" id="loginBtn">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Sign In</span>
                </button>

                <a href="register-organization.php" class="btn-register">
                    <i class="fas fa-building"></i>
                    <span>Register</span>
                </a>
            </div>
        </form>

        <!-- Footer -->
        <div class="login-footer">
            <?php echo date('Y'); ?> &copy; <?php echo APP_NAME; ?>
        </div>
    </div>

    <script>
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');

        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.type === 'password' ? 'text' : 'password';
                passwordInput.type = type;
                const icon = this.querySelector('i');
                icon.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
            });
        }

        const form = document.getElementById('loginForm');
        const submitBtn = document.getElementById('loginBtn');

        if (form) {
            form.addEventListener('submit', function() {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Signing In...</span>';
            });
        }
    </script>

</body>
</html>