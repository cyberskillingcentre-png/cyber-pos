<?php
require_once 'master_config.php';
require_once 'config/config.php';
require_once 'config/notifications.php';

$error = '';
$success = '';
$step = 'identify';
$reset_username = '';

// ============================================================
// HELPERS
// ============================================================
function check_ip_rate_limit($conn, $identifier, $action, $max_attempts = 5, $window_minutes = 15) {
    $key = 'rl_' . md5($action . '|' . $identifier);
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM password_reset_audit
            WHERE ip_address = ? AND action = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
              AND success = 0
        ");
        $stmt->execute([$_SERVER['REMOTE_ADDR'], $action, $window_minutes]);
        if ((int)$stmt->fetchColumn() >= $max_attempts) {
            throw new Exception("Too many attempts. Please wait $window_minutes minutes.");
        }
    } catch (PDOException $e) {}
    
    if (!isset($_SESSION[$key])) $_SESSION[$key] = ['count' => 0, 'first' => time()];
    if (time() - $_SESSION[$key]['first'] > $window_minutes * 60) {
        $_SESSION[$key] = ['count' => 0, 'first' => time()];
    }
    $_SESSION[$key]['count']++;
    if ($_SESSION[$key]['count'] > $max_attempts) {
        throw new Exception("Too many attempts. Please wait $window_minutes minutes.");
    }
}

function audit_reset($conn, $user_id, $org_id, $action, $success, $notes = '') {
    try {
        $stmt = $conn->prepare("
            INSERT INTO password_reset_audit
            (user_id, org_id, action, ip_address, user_agent, success, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $user_id, $org_id, $action,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $success ? 1 : 0, $notes
        ]);
    } catch (PDOException $e) {
        error_log('Audit insert failed: ' . $e->getMessage());
    }
}

function find_user_across_orgs($master_conn, $identifier) {
    $stmt = $master_conn->prepare("
        SELECT * FROM organizations
        WHERE status IN ('active','expired')
        ORDER BY org_id DESC
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $org) {
        try {
            $org_conn = new PDO(
                "mysql:host=" . MASTER_DB_HOST . ";dbname=" . $org['database_name'],
                MASTER_DB_USER, MASTER_DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $s = $org_conn->prepare("
                SELECT * FROM users
                WHERE (email = ? OR username = ?) AND status = 'active'
                LIMIT 1
            ");
            $s->execute([$identifier, $identifier]);
            if ($user = $s->fetch(PDO::FETCH_ASSOC)) {
                return ['org' => $org, 'user' => $user, 'conn' => $org_conn];
            }
        } catch (PDOException $e) { continue; }
    }
    return null;
}

function validate_password_strength($password) {
    if (strlen($password) < 8)                    throw new Exception('Password must be at least 8 characters');
    if (!preg_match('/[A-Z]/', $password))        throw new Exception('Password must contain an uppercase letter');
    if (!preg_match('/[a-z]/', $password))        throw new Exception('Password must contain a lowercase letter');
    if (!preg_match('/[0-9]/', $password))        throw new Exception('Password must contain a number');
    if (!preg_match('/[^A-Za-z0-9]/', $password)) throw new Exception('Password must contain a special character');
}

function verify_reset_code($user, $code) {
    if (empty($user['reset_token_hash'])) throw new Exception('No reset code active. Please start over.');
    if (empty($user['reset_token_expires']) || strtotime($user['reset_token_expires']) < time()) {
        throw new Exception('Reset code expired. Please start over.');
    }
    if (!password_verify($code, $user['reset_token_hash'])) {
        throw new Exception('Invalid reset code');
    }
    return true;
}

// ============================================================
// STEP 1: IDENTIFY
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'identify') {
    csrf_require();
    $identifier     = trim($_POST['identifier'] ?? '');
    $verifier_type  = $_POST['verifier_type'] ?? 'phone';
    $verifier_value = $_POST['verifier_value'] ?? '';
    
    try {
        check_ip_rate_limit($master_conn, $identifier, 'identify', 5, 15);
        if (empty($identifier))     throw new Exception('Please enter your username or email');
        if (empty($verifier_value)) throw new Exception('Please fill in the verification field');
        
        $found = find_user_across_orgs($master_conn, $identifier);
        if (!$found) {
            audit_reset($master_conn, 0, 0, 'identify', false, "No user: $identifier");
            throw new Exception('We could not find an account with those details');
        }
        
        $user = $found['user'];
        $org  = $found['org'];
        
        if (!empty($user['reset_locked_until']) && strtotime($user['reset_locked_until']) > time()) {
            $min = ceil((strtotime($user['reset_locked_until']) - time()) / 60);
            throw new Exception("Account temporarily locked. Try again in $min minute(s).");
        }
        
        $delivery_channel = null;
        $delivery_target  = null;
        
        if ($verifier_type === 'phone') {
            $user_phone  = preg_replace('/[^0-9]/', '', $user['phone'] ?? '');
            $input_phone = preg_replace('/[^0-9]/', '', $verifier_value);
            if (empty($user_phone)) throw new Exception('No phone on file. Try email verification instead.');
            if ($input_phone !== $user_phone) {
                audit_reset($master_conn, $user['user_id'], $org['org_id'], 'identify', false, 'Phone mismatch');
                throw new Exception('The phone number does not match our records');
            }
            $delivery_channel = 'sms';
            $delivery_target  = $user['phone'];
        } elseif ($verifier_type === 'email') {
            $user_email  = strtolower(trim($user['email'] ?? ''));
            $input_email = strtolower(trim($verifier_value));
            if (empty($user_email)) throw new Exception('No email on file. Try phone verification instead.');
            if ($input_email !== $user_email) {
                audit_reset($master_conn, $user['user_id'], $org['org_id'], 'identify', false, 'Email mismatch');
                throw new Exception('The email does not match our records');
            }
            $delivery_channel = 'email';
            $delivery_target  = $user['email'];
        } else {
            throw new Exception('Invalid verification method');
        }
        
        $code      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $code_hash = password_hash($code, PASSWORD_DEFAULT);
        $expires   = date('Y-m-d H:i:s', time() + 15 * 60);
        
        $found['conn']->prepare("
            UPDATE users
            SET reset_token_hash = ?, reset_token_expires = ?, reset_attempts = 0
            WHERE user_id = ?
        ")->execute([$code_hash, $expires, $user['user_id']]);
        
        if ($delivery_channel === 'sms') {
            $sms_body = "Your " . APP_NAME . " password reset code is: $code. Expires in 15 minutes.";
            $delivery = send_sms($delivery_target, $sms_body);
        } else {
            $email_html = build_otp_email($user['full_name'], $code, $org['org_name'] ?? '');
            $delivery = send_email($delivery_target, APP_NAME . ' — Password Reset Code', $email_html);
        }
        
        if (!$delivery['success']) {
            audit_reset($master_conn, $user['user_id'], $org['org_id'], 'identify', false,
                'Delivery failed: ' . ($delivery['error'] ?? 'unknown'));
            throw new Exception('Could not send reset code. Please try again.');
        }
        
        $_SESSION['reset_user_id']       = $user['user_id'];
        $_SESSION['reset_org_id']        = $org['org_id'];
        $_SESSION['reset_database_name'] = $org['database_name'];
        $_SESSION['reset_username']      = $user['username'];
        $_SESSION['reset_started_at']    = time();
        $_SESSION['reset_channel']       = $delivery_channel;
        $_SESSION['reset_target_hint']   = $delivery_channel === 'sms'
            ? 'phone ending ' . substr($delivery_target, -3)
            : preg_replace('/(.{2}).+(@.+)/', '$1***$2', $delivery_target);
        
        session_regenerate_id(true);
        
        if (defined('APP_ENV') && APP_ENV === 'development') {
            $_SESSION['dev_reset_code'] = $code;
        }
        
        audit_reset($master_conn, $user['user_id'], $org['org_id'], 'identify', true, "Sent via $delivery_channel");
        
        $step = 'verify_code';
        $reset_username = $user['username'];
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        $step = 'identify';
    }
}

// ============================================================
// STEP 2: VERIFY CODE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_code') {
    csrf_require();
    $code = trim($_POST['code'] ?? '');
    try {
        if (!isset($_SESSION['reset_user_id'], $_SESSION['reset_database_name'])) {
            throw new Exception('Session expired. Please start over.');
        }
        if (time() - ($_SESSION['reset_started_at'] ?? 0) > 15 * 60) {
            throw new Exception('Reset session expired. Please start over.');
        }
        check_ip_rate_limit($master_conn, (string)$_SESSION['reset_user_id'], 'verify_code', 5, 15);
        
        $org_conn = new PDO(
            "mysql:host=" . MASTER_DB_HOST . ";dbname=" . $_SESSION['reset_database_name'],
            MASTER_DB_USER, MASTER_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $stmt = $org_conn->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$_SESSION['reset_user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new Exception('User not found');
        
        $org_conn->prepare("UPDATE users SET reset_attempts = reset_attempts + 1 WHERE user_id = ?")
                 ->execute([$user['user_id']]);
        
        if ($user['reset_attempts'] >= 4) {
            $lock_until = date('Y-m-d H:i:s', time() + 30 * 60);
            $org_conn->prepare("UPDATE users SET reset_locked_until = ? WHERE user_id = ?")
                     ->execute([$lock_until, $user['user_id']]);
            audit_reset($master_conn, $user['user_id'], $_SESSION['reset_org_id'], 'verify_code', false, 'Locked');
            throw new Exception('Too many failed attempts. Account locked for 30 minutes.');
        }
        
        try {
            verify_reset_code($user, $code);
        } catch (Exception $e) {
            audit_reset($master_conn, $user['user_id'], $_SESSION['reset_org_id'], 'verify_code', false, 'Invalid code');
            throw $e;
        }
        
        audit_reset($master_conn, $user['user_id'], $_SESSION['reset_org_id'], 'verify_code', true, 'Verified');
        $_SESSION['reset_code_verified'] = true;
        
        $step = 'reset_password';
        $reset_username = $user['username'];
    } catch (Exception $e) {
        $error = $e->getMessage();
        $step = 'verify_code';
        $reset_username = $_SESSION['reset_username'] ?? '';
    }
}

// ============================================================
// STEP 3: RESET PASSWORD
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    csrf_require();
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    try {
        if (empty($_SESSION['reset_code_verified'])) {
            throw new Exception('Please verify your reset code first');
        }
        if (time() - ($_SESSION['reset_started_at'] ?? 0) > 20 * 60) {
            throw new Exception('Reset session expired. Please start over.');
        }
        validate_password_strength($new_password);
        if ($new_password !== $confirm_password) throw new Exception('Passwords do not match');
        
        $org_conn = new PDO(
            "mysql:host=" . MASTER_DB_HOST . ";dbname=" . $_SESSION['reset_database_name'],
            MASTER_DB_USER, MASTER_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $stmt = $org_conn->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$_SESSION['reset_user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new Exception('User not found');
        
        if (password_verify($new_password, $user['password'])) {
            throw new Exception('New password cannot be the same as your current password');
        }
        
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $org_conn->prepare("
            UPDATE users
            SET password = ?,
                reset_token_hash    = NULL,
                reset_token_expires = NULL,
                reset_attempts      = 0,
                reset_locked_until  = NULL,
                last_password_change = NOW(),
                updated_at = NOW()
            WHERE user_id = ?
        ")->execute([$hashed, $user['user_id']]);
        
        try {
            $org_conn->prepare("
                INSERT INTO activity_logs
                (user_id, activity_type, activity_description, ip_address, created_at)
                VALUES (?, 'PASSWORD_RESET', 'Password reset via forgot password', ?, NOW())
            ")->execute([$user['user_id'], $_SERVER['REMOTE_ADDR']]);
        } catch (PDOException $e) {}
        
        audit_reset($master_conn, $user['user_id'], $_SESSION['reset_org_id'], 'reset_password', true, 'Password changed');
        
        // Clear session
        foreach (['reset_user_id','reset_org_id','reset_database_name','reset_username',
                  'reset_started_at','reset_code_verified','reset_channel','reset_target_hint',
                  'dev_reset_code'] as $k) {
            unset($_SESSION[$k]);
        }
        session_regenerate_id(true);
        
        $step = 'done';
    } catch (Exception $e) {
        $error = $e->getMessage();
        $step = 'reset_password';
        $reset_username = $_SESSION['reset_username'] ?? '';
    }
}

// ============================================================
// DETERMINE STEP ON PAGE LOAD
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    csrf_require();
    if (isset($_SESSION['reset_user_id'], $_SESSION['reset_database_name'])) {
        if (!empty($_SESSION['reset_code_verified']))  $step = 'reset_password';
        elseif (!empty($_SESSION['reset_username']))   $step = 'verify_code';
        else                                           $step = 'identify';
        $reset_username = $_SESSION['reset_username'] ?? '';
    } else {
        $step = 'identify';
    }
}

// Safe session reads — never throw Undefined array key warnings
$safe_channel = $_SESSION['reset_channel'] ?? 'email';
$safe_hint    = $_SESSION['reset_target_hint'] ?? 'your registered contact';
$safe_code    = $_SESSION['dev_reset_code'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --cyber-purple: #6B46C1; --cyber-purple-dark: #553C9A;
            --cyber-green: #48BB78; --cyber-red: #E53E3E;
            --cyber-dark: #1A202C; --cyber-gray: #2D3748;
            --cyber-gray-lighter: #718096; --cyber-border: #E2E8F0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }

        /* ============================================
           NON-SCROLLABLE BODY
           ============================================ */
        html, body {
            width: 100%;
            height: 100%;
            overflow: hidden;         /* No page scroll */
            font-family: 'Inter', sans-serif;
        }
        body {
            background-image: url('assets/images/login_im.png');
            background-size: cover;
            background-position: center;
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
            background: radial-gradient(ellipse at center,
                rgba(0,0,0,0) 0%,
                rgba(0,0,0,0.15) 70%,
                rgba(0,0,0,0.35) 100%);
            z-index: 0;
        }

        /* ============================================
           CARD — CONSTRAINED TO FIT WITHOUT SCROLL
           ============================================ */
        .forgot-card {
            position: relative;
            z-index: 1;
            background: rgba(255,255,255,0.98);
            border-radius: 20px;
            padding: 32px 36px;
            width: 100%;
            max-width: 440px;
            /* Keep inside viewport */
            max-height: calc(100vh - 40px);
            overflow: hidden;          /* Card itself doesn't scroll */
            display: flex;
            flex-direction: column;
            box-shadow: 0 25px 70px rgba(0,0,0,0.45);
            animation: scaleIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.94) translateY(10px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }

        /* Header */
        .forgot-header { text-align: center; margin-bottom: 18px; flex-shrink: 0; }
        .forgot-header-icon {
            width: 56px; height: 56px;
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            border-radius: 16px;
            display: inline-flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.5rem;
            box-shadow: 0 10px 30px rgba(107,70,193,0.4);
            margin-bottom: 12px;
        }
        .forgot-header-icon.success {
            background: linear-gradient(135deg, var(--cyber-green), #38A169);
            box-shadow: 0 10px 30px rgba(72,187,120,0.4);
        }
        .forgot-header h1 {
            font-size: 1.25rem; font-weight: 800;
            color: var(--cyber-dark); margin: 0;
        }
        .forgot-header p {
            font-size: 0.8rem; color: var(--cyber-gray-lighter);
            margin: 6px 0 0 0; line-height: 1.4;
        }

        /* Steps */
        .steps {
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 16px; gap: 6px; flex-shrink: 0;
        }
        .step-dot {
            width: 26px; height: 26px; border-radius: 50%;
            background: #EDF2F7; color: #A0AEC0;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.7rem; font-weight: 700; transition: all 0.3s;
        }
        .step-dot.active {
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            color: #fff; box-shadow: 0 4px 12px rgba(107,70,193,0.35);
        }
        .step-dot.completed { background: var(--cyber-green); color: #fff; }
        .step-line { width: 28px; height: 2px; background: #EDF2F7; transition: background 0.3s; }
        .step-line.completed { background: var(--cyber-green); }

        /* Content area — allows internal scroll only if it truly doesn't fit */
        .forgot-body {
            flex: 1;
            overflow-y: auto;
            margin: 0 -8px;            /* Prevents inner scrollbar from hugging content */
            padding: 0 8px;
        }
        .forgot-body::-webkit-scrollbar { width: 6px; }
        .forgot-body::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 4px; }

        /* Alerts */
        .alert-cyber {
            border-radius: 10px; padding: 10px 14px;
            font-size: 0.8rem; font-weight: 500;
            display: flex; align-items: flex-start; gap: 8px;
            margin-bottom: 14px;
        }
        .alert-error {
            background: rgba(229,62,62,0.08);
            border: 1px solid rgba(229,62,62,0.2);
            border-left: 4px solid var(--cyber-red);
            color: #C53030;
        }
        .alert-cyber i { flex-shrink: 0; margin-top: 2px; }

        /* Form */
        .form-group { margin-bottom: 12px; }
        .form-label {
            display: block; font-size: 0.65rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            color: var(--cyber-gray); margin-bottom: 5px;
        }
        .form-hint { font-size: 0.68rem; color: var(--cyber-gray-lighter); margin-top: 3px; }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-wrapper .input-icon {
            position: absolute; left: 14px; color: #A0AEC0;
            font-size: 0.9rem; pointer-events: none;
        }
        .input-wrapper input {
            width: 100%; padding: 11px 40px 11px 40px;
            border: 2px solid var(--cyber-border); border-radius: 10px;
            font-size: 0.88rem; font-weight: 500; color: var(--cyber-dark);
            background: #F7FAFC; transition: all 0.25s; font-family: inherit;
        }
        .input-wrapper input:focus {
            outline: none; border-color: var(--cyber-purple);
            background: #fff; box-shadow: 0 0 0 4px rgba(107,70,193,0.12);
        }
        .toggle-password {
            position: absolute; right: 12px; background: none; border: none;
            color: #A0AEC0; cursor: pointer; padding: 4px;
        }
        .toggle-password:hover { color: var(--cyber-purple); }
        .code-input {
            letter-spacing: 8px !important; font-size: 1.3rem !important;
            text-align: center; font-weight: 800 !important;
            font-family: 'Courier New', monospace !important;
            padding-left: 14px !important;
        }

        /* Buttons */
        .btn-primary-cyber {
            width: 100%; padding: 12px 20px;
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            color: #fff; border: none; border-radius: 10px;
            font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;
            cursor: pointer; transition: all 0.25s;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 6px 20px rgba(107,70,193,0.35);
            text-decoration: none; margin-top: 4px;
        }
        .btn-primary-cyber:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(107,70,193,0.5); color: #fff;
        }
        .btn-primary-cyber:disabled { opacity: 0.7; cursor: not-allowed; }
        .btn-success-cyber {
            background: linear-gradient(135deg, var(--cyber-green), #38A169);
            box-shadow: 0 6px 20px rgba(72,187,120,0.35);
        }

        /* Divider */
        .divider {
            display: flex; align-items: center; text-align: center;
            margin: 12px 0; color: var(--cyber-gray-lighter);
            font-size: 0.6rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;
        }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--cyber-border); }
        .divider span { padding: 0 10px; }

        /* Back link */
        .back-link {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 6px; width: 100%; color: var(--cyber-purple);
            text-decoration: none; font-size: 0.75rem; font-weight: 600;
            padding: 8px; border-radius: 8px; transition: all 0.2s;
        }
        .back-link:hover { background: rgba(107,70,193,0.08); }

        /* Info box */
        .info-box {
            background: #F0F9FF; border: 1px solid #BAE6FD;
            border-left: 4px solid #0EA5E9;
            border-radius: 8px; padding: 10px 12px;
            font-size: 0.75rem; color: #075985; margin-bottom: 14px;
            line-height: 1.4;
        }
        .info-box i { margin-right: 4px; }

        /* Dev banner */
        .dev-code-banner {
            background: #FEF3C7; border: 1px solid #F59E0B;
            border-radius: 8px; padding: 8px 12px;
            font-size: 0.75rem; color: #92400E; margin-bottom: 14px;
            text-align: center;
        }
        .dev-code-banner code {
            background: #fff; padding: 2px 8px; border-radius: 4px;
            font-weight: 800; letter-spacing: 4px; font-size: 0.95rem;
        }

        /* Verifier toggle */
        .btn-outline-primary {
            border-color: #6B46C1; color: #6B46C1;
            font-size: 0.75rem; padding: 8px 10px;
        }
        .btn-check:checked + .btn-outline-primary {
            background: #6B46C1; color: #fff; border-color: #6B46C1;
        }

        /* Success */
        .success-content { text-align: center; padding: 10px 0 14px; }
        .success-content p { color: var(--cyber-gray); font-size: 0.85rem; line-height: 1.5; }

        /* Small screens */
        @media (max-width: 575.98px) {
            body { padding: 10px; }
            .forgot-card { padding: 22px 20px; border-radius: 16px; max-height: calc(100vh - 20px); }
            .forgot-header-icon { width: 48px; height: 48px; font-size: 1.25rem; margin-bottom: 8px; }
            .forgot-header h1 { font-size: 1.1rem; }
            .forgot-header p { font-size: 0.75rem; }
        }
    </style>
</head>
<body>
<div class="forgot-card">

    <div class="forgot-header">
        <div class="forgot-header-icon <?php echo $step === 'done' ? 'success' : ''; ?>">
            <?php if ($step === 'done'): ?><i class="fas fa-check"></i>
            <?php elseif ($step === 'reset_password'): ?><i class="fas fa-lock"></i>
            <?php elseif ($step === 'verify_code'): ?><i class="fas fa-shield-alt"></i>
            <?php else: ?><i class="fas fa-key"></i><?php endif; ?>
        </div>
        <h1>
            <?php if ($step === 'done'): ?>Password Reset!
            <?php elseif ($step === 'reset_password'): ?>Set New Password
            <?php elseif ($step === 'verify_code'): ?>Enter Reset Code
            <?php else: ?>Forgot Password?<?php endif; ?>
        </h1>
        <p>
            <?php if ($step === 'done'): ?>Your password has been updated
            <?php elseif ($step === 'reset_password'): ?>Choose a strong new password
            <?php elseif ($step === 'verify_code'): ?>Enter the code we sent you
            <?php else: ?>Verify your identity to reset your password<?php endif; ?>
        </p>
    </div>

    <div class="steps">
        <div class="step-dot <?php echo in_array($step, ['verify_code','reset_password','done']) ? 'completed' : 'active'; ?>">
            <?php if (in_array($step, ['verify_code','reset_password','done'])): ?><i class="fas fa-check"></i><?php else: ?>1<?php endif; ?>
        </div>
        <div class="step-line <?php echo in_array($step, ['reset_password','done']) ? 'completed' : ''; ?>"></div>
        <div class="step-dot <?php echo $step === 'reset_password' ? 'completed' : ($step === 'verify_code' ? 'active' : ($step === 'done' ? 'completed' : '')); ?>">
            <?php if ($step === 'reset_password' || $step === 'done'): ?><i class="fas fa-check"></i><?php else: ?>2<?php endif; ?>
        </div>
        <div class="step-line <?php echo $step === 'done' ? 'completed' : ''; ?>"></div>
        <div class="step-dot <?php echo $step === 'done' ? 'completed' : ($step === 'reset_password' ? 'active' : ''); ?>">
            <?php if ($step === 'done'): ?><i class="fas fa-check"></i><?php else: ?>3<?php endif; ?>
        </div>
    </div>

    <div class="forgot-body">

        <?php if ($error): ?>
        <div class="alert-cyber alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <!-- STEP 1: IDENTIFY -->
        <?php if ($step === 'identify'): ?>
        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            Verify your identity using the phone <strong>or</strong> email on your account.
        </div>
        <form method="POST" id="identifyForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="identify">
            
            <div class="form-group">
                <label class="form-label">Verify Using</label>
                <div class="btn-group w-100" role="group">
                    <input type="radio" class="btn-check" name="verifier_type" id="vt_phone" value="phone" checked>
                    <label class="btn btn-outline-primary" for="vt_phone"><i class="fas fa-phone me-1"></i> Phone</label>
                    <input type="radio" class="btn-check" name="verifier_type" id="vt_email" value="email">
                    <label class="btn btn-outline-primary" for="vt_email"><i class="fas fa-envelope me-1"></i> Email</label>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="identifier">Username or Email</label>
                <div class="input-wrapper">
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" id="identifier" name="identifier" placeholder="Enter username or email"
                           required autofocus autocomplete="username"
                           value="<?php echo htmlspecialchars($_POST['identifier'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="form-group" id="phone_group">
                <label class="form-label" for="phone_verifier">Phone on File</label>
                <div class="input-wrapper">
                    <i class="fas fa-phone input-icon"></i>
                    <input type="tel" id="phone_verifier" name="verifier_value" placeholder="Enter phone number" autocomplete="tel">
                </div>
            </div>
            
            <div class="form-group" id="email_group" style="display:none;">
                <label class="form-label" for="email_verifier">Email on File</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" id="email_verifier" name="verifier_value_email" placeholder="Enter email address" autocomplete="email">
                </div>
            </div>
            
            <button type="submit" class="btn-primary-cyber">
                <i class="fas fa-arrow-right"></i><span>Continue</span>
            </button>
        </form>
        <div class="divider"><span>or</span></div>
        <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
        
        <script>
        document.querySelectorAll('input[name="verifier_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const isPhone = this.value === 'phone';
                document.getElementById('phone_group').style.display = isPhone ? 'block' : 'none';
                document.getElementById('email_group').style.display = isPhone ? 'none' : 'block';
                const p = document.getElementById('phone_verifier');
                const e = document.getElementById('email_verifier');
                p.required = isPhone; e.required = !isPhone;
                p.name = isPhone ? 'verifier_value' : 'verifier_value_phone_unused';
                e.name = isPhone ? 'verifier_value_email_unused' : 'verifier_value';
            });
        });
        </script>
        <?php endif; ?>

        <!-- STEP 2: VERIFY CODE -->
        <?php if ($step === 'verify_code'): ?>
        <div class="info-box">
            <i class="fas fa-shield-alt"></i>
            A 6-digit code was sent via <strong><?php echo $safe_channel === 'sms' ? 'SMS' : 'email'; ?></strong>
            to <strong><?php echo htmlspecialchars($safe_hint); ?></strong>. Expires in 15 minutes.
        </div>
        
        <?php if ($safe_code !== ''): ?>
        <div class="dev-code-banner">
            <i class="fas fa-flask me-1"></i> DEV — Code: <code><?php echo htmlspecialchars($safe_code); ?></code>
        </div>
        <?php endif; ?>
        
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="verify_code">
            <div class="form-group">
                <label class="form-label" for="code">6-Digit Code</label>
                <div class="input-wrapper">
                    <i class="fas fa-key input-icon"></i>
                    <input type="text" id="code" name="code" class="code-input" placeholder="000000"
                           maxlength="6" pattern="[0-9]{6}" inputmode="numeric" required autofocus autocomplete="one-time-code">
                </div>
            </div>
            <button type="submit" class="btn-primary-cyber"><i class="fas fa-check"></i><span>Verify Code</span></button>
        </form>
        <div class="divider"><span>or</span></div>
        <a href="forgot_password.php" class="back-link"><i class="fas fa-redo"></i> Start Over</a>
        <?php endif; ?>

        <!-- STEP 3: RESET PASSWORD -->
        <?php if ($step === 'reset_password'): ?>
        <div class="info-box">
            <i class="fas fa-user-check"></i>
            Verified! Set a new password for <strong><?php echo htmlspecialchars($reset_username); ?></strong>
        </div>
        <form method="POST" id="resetForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="reset_password">
            <div class="form-group">
                <label class="form-label" for="new_password">New Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="new_password" name="new_password" placeholder="Enter new password"
                           required autofocus autocomplete="new-password">
                    <button type="button" class="toggle-password" data-target="new_password"><i class="fas fa-eye"></i></button>
                </div>
                <div class="form-hint">8+ chars · A-Z · a-z · 0-9 · special</div>
            </div>
            <div class="form-group">
                <label class="form-label" for="confirm_password">Confirm Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password"
                           required autocomplete="new-password">
                    <button type="button" class="toggle-password" data-target="confirm_password"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <button type="submit" class="btn-primary-cyber"><i class="fas fa-check-circle"></i><span>Reset Password</span></button>
        </form>
        <?php endif; ?>

        <!-- STEP 4: DONE -->
        <?php if ($step === 'done'): ?>
        <div class="success-content">
            <p>Your password has been changed successfully.<br>You can now log in with your new password.</p>
        </div>
        <a href="index.php" class="btn-primary-cyber btn-success-cyber">
            <i class="fas fa-sign-in-alt"></i><span>Go to Login</span>
        </a>
        <?php endif; ?>

    </div>
</div>

<script>
document.querySelectorAll('.toggle-password').forEach(btn => {
    btn.addEventListener('click', function() {
        const t = document.getElementById(this.getAttribute('data-target'));
        if (!t) return;
        t.type = t.type === 'password' ? 'text' : 'password';
        this.querySelector('i').className = t.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
    });
});

const resetForm = document.getElementById('resetForm');
if (resetForm) {
    resetForm.addEventListener('submit', function(e) {
        const p = document.getElementById('new_password').value;
        const c = document.getElementById('confirm_password').value;
        if (p.length < 8)                      { e.preventDefault(); alert('Password must be at least 8 characters'); return; }
        if (!/[A-Z]/.test(p))                  { e.preventDefault(); alert('Password needs an uppercase letter'); return; }
        if (!/[a-z]/.test(p))                  { e.preventDefault(); alert('Password needs a lowercase letter'); return; }
        if (!/[0-9]/.test(p))                  { e.preventDefault(); alert('Password needs a number'); return; }
        if (!/[^A-Za-z0-9]/.test(p))           { e.preventDefault(); alert('Password needs a special character'); return; }
        if (p !== c)                           { e.preventDefault(); alert('Passwords do not match'); return; }
        const btn = resetForm.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Resetting...</span>';
    });
}
</script>
</body>
</html>