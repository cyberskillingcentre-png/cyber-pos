<?php
require_once 'master_config.php';

$org_code = isset($_GET['org_code']) ? $_GET['org_code'] : '';
$username = isset($_GET['username']) ? $_GET['username'] : '';

if (!$org_code || !$username) {
    header('Location: index.php');
    exit();
}

$stmt = $master_conn->prepare("SELECT * FROM organizations WHERE org_code = ?");
$stmt->execute([$org_code]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Successful - <?php echo APP_NAME; ?></title>
    
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
            --cyber-orange-dark: #DD6B20;
            --cyber-cyan: #00B5D8;
            --cyber-green: #48BB78;
            --cyber-green-dark: #38A169;
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
           SUCCESS CARD - GLASS
           ============================================ */
        .success-card {
            position: relative;
            z-index: 1;
            
            background: rgba(255, 255, 255, 0.78);
            backdrop-filter: blur(30px) saturate(180%);
            -webkit-backdrop-filter: blur(30px) saturate(180%);
            
            border-radius: 24px;
            padding: 40px 44px;
            width: 100%;
            max-width: 620px;
            
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 
                0 25px 70px rgba(0, 0, 0, 0.45),
                inset 0 1px 0 rgba(255, 255, 255, 0.6);
            
            animation: scaleIn 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.92) translateY(20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        
        /* ============================================
           SUCCESS ICON
           ============================================ */
        .success-icon-wrap {
            display: flex;
            justify-content: center;
            margin-bottom: 24px;
            position: relative;
        }
        
        .success-icon {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, var(--cyber-green), var(--cyber-green-dark));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 2.5rem;
            box-shadow: 0 15px 40px rgba(72, 187, 120, 0.5);
            animation: popIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.2s both;
            position: relative;
            z-index: 2;
        }
        
        /* Pulsing rings around icon */
        .success-icon::before,
        .success-icon::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 2px solid rgba(72, 187, 120, 0.4);
            animation: pulse-ring 2s ease-out infinite;
        }
        
        .success-icon::after {
            animation-delay: 1s;
        }
        
        @keyframes popIn {
            from { transform: scale(0); }
            to { transform: scale(1); }
        }
        
        @keyframes pulse-ring {
            0% {
                transform: scale(1);
                opacity: 1;
            }
            100% {
                transform: scale(1.8);
                opacity: 0;
            }
        }
        
        /* ============================================
           HEADER
           ============================================ */
        .success-header {
            text-align: center;
            margin-bottom: 28px;
        }
        
        .success-header h1 {
            font-size: 1.65rem;
            font-weight: 800;
            color: var(--cyber-dark);
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        
        .success-header p {
            font-size: 0.9rem;
            color: var(--cyber-gray-lighter);
            line-height: 1.6;
            margin: 0;
            max-width: 400px;
            margin: 0 auto;
        }
        
        .success-header .org-name {
            display: inline-block;
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            color: #fff;
            padding: 4px 12px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.82rem;
            margin-bottom: 10px;
            box-shadow: 0 4px 12px rgba(107, 70, 193, 0.3);
        }
        
        /* ============================================
           CREDENTIALS BOX
           ============================================ */
        .credentials-box {
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            
            border: 1px solid rgba(255, 255, 255, 0.7);
            border-left: 4px solid var(--cyber-purple);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }
        
        .credentials-box .box-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.8rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--cyber-purple);
            margin-bottom: 16px;
        }
        
        .credentials-box .box-title i {
            color: var(--cyber-orange);
            font-size: 0.95rem;
        }
        
        .cred-row {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px dashed rgba(226, 232, 240, 0.7);
        }
        
        .cred-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .cred-row:first-of-type {
            padding-top: 0;
        }
        
        .cred-label {
            flex: 0 0 130px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--cyber-gray-lighter);
        }
        
        .cred-value {
            flex: 1;
            font-size: 0.9rem;
            color: var(--cyber-dark);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .cred-value code {
            background: rgba(107, 70, 193, 0.1);
            color: var(--cyber-purple);
            padding: 4px 10px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 0.88rem;
            font-weight: 700;
        }
        
        .cred-value .badge-trial {
            background: linear-gradient(135deg, var(--cyber-orange), var(--cyber-orange-dark));
            color: #fff;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        
        .cred-value .days-left {
            color: var(--cyber-green);
            font-weight: 700;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        /* ============================================
           INFO ALERTS
           ============================================ */
        .info-card {
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 14px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 0.85rem;
            line-height: 1.5;
        }
        
        .info-card.warning {
            background: rgba(237, 137, 54, 0.12);
            border: 1px solid rgba(237, 137, 54, 0.3);
            border-left: 4px solid var(--cyber-orange);
            color: #744210;
        }
        
        .info-card.tip {
            background: rgba(0, 181, 216, 0.1);
            border: 1px solid rgba(0, 181, 216, 0.25);
            border-left: 4px solid var(--cyber-cyan);
            color: #086F83;
        }
        
        .info-card i {
            font-size: 1rem;
            flex-shrink: 0;
            margin-top: 2px;
        }
        
        .info-card.warning i { color: var(--cyber-orange); }
        .info-card.tip i { color: var(--cyber-cyan); }
        
        .info-card strong {
            display: block;
            margin-bottom: 3px;
        }
        
        /* ============================================
           BUTTONS
           ============================================ */
        .btn-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 24px;
        }
        
        .btn-primary-cyber,
        .btn-secondary-cyber {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 22px;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            cursor: pointer;
            transition: all 0.25s ease;
            text-decoration: none;
            border: none;
            white-space: nowrap;
        }
        
        .btn-primary-cyber {
            background: linear-gradient(135deg, var(--cyber-purple) 0%, var(--cyber-purple-dark) 100%);
            color: #fff;
            box-shadow: 0 6px 20px rgba(107, 70, 193, 0.4);
        }
        
        .btn-primary-cyber:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(107, 70, 193, 0.55);
            color: #fff;
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
           SUPPORT FOOTER
           ============================================ */
        .support-footer {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid rgba(226, 232, 240, 0.6);
            text-align: center;
        }
        
        .support-footer p {
            font-size: 0.78rem;
            color: var(--cyber-gray-lighter);
            margin-bottom: 10px;
        }
        
        .support-links {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .support-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            color: var(--cyber-gray);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s ease;
        }
        
        .support-item:hover {
            color: var(--cyber-purple);
        }
        
        .support-item i {
            color: var(--cyber-orange);
            font-size: 0.85rem;
        }
        
        /* ============================================
           CONFETTI
           ============================================ */
        .confetti {
            position: fixed;
            top: -10px;
            width: 10px;
            height: 10px;
            background: var(--cyber-purple);
            opacity: 0.8;
            z-index: 999;
            pointer-events: none;
            animation: fall linear forwards;
        }
        
        @keyframes fall {
            to {
                transform: translateY(100vh) rotate(720deg);
                opacity: 0;
            }
        }
        
        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 575.98px) {
            body {
                background-attachment: scroll;
                padding: 12px;
            }
            
            .success-card {
                padding: 28px 22px;
                border-radius: 20px;
            }
            
            .success-icon {
                width: 72px;
                height: 72px;
                font-size: 2rem;
            }
            
            .success-header h1 {
                font-size: 1.3rem;
            }
            
            .success-header p {
                font-size: 0.85rem;
            }
            
            .cred-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }
            
            .cred-label {
                flex: none;
            }
            
            .btn-row {
                grid-template-columns: 1fr;
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
    
    <div class="success-card">
        
        <!-- Success Icon -->
        <div class="success-icon-wrap">
            <div class="success-icon">
                <i class="fas fa-check"></i>
            </div>
        </div>
        
        <!-- Header -->
        <div class="success-header">
            <span class="org-name">
                <i class="fas fa-building me-1"></i>
                <?php echo htmlspecialchars($org['org_name']); ?>
            </span>
            <h1>Registration Successful!</h1>
            <p>
                Your organization has been created. Your 30-day free trial starts now — 
                dive in and start managing your business.
            </p>
        </div>
        
        <!-- Credentials -->
        <div class="credentials-box">
            <div class="box-title">
                <i class="fas fa-key"></i>
                Your Login Credentials
            </div>
            
            <div class="cred-row">
                <div class="cred-label">Org Code</div>
                <div class="cred-value">
                    <code><?php echo htmlspecialchars($org['org_code']); ?></code>
                </div>
            </div>
            
            <div class="cred-row">
                <div class="cred-label">Username</div>
                <div class="cred-value">
                    <code><?php echo htmlspecialchars($username); ?></code>
                </div>
            </div>
            
            <div class="cred-row">
                <div class="cred-label">Password</div>
                <div class="cred-value">
                    <em style="color: var(--cyber-gray-lighter);">Your chosen password</em>
                </div>
            </div>
            
            <div class="cred-row">
                <div class="cred-label">Trial Ends</div>
                <div class="cred-value">
                    <span><?php echo date('F d, Y', strtotime($org['subscription_end'])); ?></span>
                    <span class="badge-trial"><?php echo DEMO_DAYS; ?> Days</span>
                </div>
            </div>
        </div>
        
        <!-- Info Alerts -->
        <div class="info-card warning">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <strong>Save Your Credentials</strong>
                Please save these login details. You'll need them to access your account.
            </div>
        </div>
        
        <div class="info-card tip">
            <i class="fas fa-lightbulb"></i>
            <div>
                <strong>Tip</strong>
                You can change your password after logging in from the <strong>My Profile</strong> page.
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="btn-row">
            <a href="index.php" class="btn-secondary-cyber">
                <i class="fas fa-home"></i>
                <span>Home</span>
            </a>
            
            <a href="org-login.php" class="btn-primary-cyber">
                <i class="fas fa-sign-in-alt"></i>
                <span>Login Now</span>
            </a>
        </div>
        
        <!-- Support Footer -->
        <div class="support-footer">
            <p>Need help getting started?</p>
            <div class="support-links">
                <a href="mailto:info@cyberplaza.tech" class="support-item">
                    <i class="fas fa-envelope"></i>
                    info@cyberplaza.tech
                </a>
                <a href="tel:+256757741977" class="support-item">
                    <i class="fas fa-phone"></i>
                    +256 757 741 977
                </a>
            </div>
        </div>
    </div>
    
    <script>
        // ============================================
        // CELEBRATION CONFETTI
        // ============================================
        (function createConfetti() {
            const colors = ['#6B46C1', '#ED8936', '#48BB78', '#00B5D8', '#9F7AEA', '#FBD38D'];
            const confettiCount = 40;
            
            for (let i = 0; i < confettiCount; i++) {
                setTimeout(() => {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    
                    // Random properties
                    const left = Math.random() * 100;
                    const duration = 3 + Math.random() * 3;
                    const size = 6 + Math.random() * 8;
                    const color = colors[Math.floor(Math.random() * colors.length)];
                    const shape = Math.random() > 0.5 ? '50%' : '2px';
                    
                    confetti.style.left = left + '%';
                    confetti.style.width = size + 'px';
                    confetti.style.height = size + 'px';
                    confetti.style.background = color;
                    confetti.style.borderRadius = shape;
                    confetti.style.animationDuration = duration + 's';
                    confetti.style.animationDelay = Math.random() * 0.5 + 's';
                    
                    document.body.appendChild(confetti);
                    
                    // Remove after animation
                    setTimeout(() => confetti.remove(), (duration + 1) * 1000);
                }, i * 50);
            }
        })();
        
        // ============================================
        // COPY TO CLIPBOARD (for credentials)
        // ============================================
        document.querySelectorAll('.cred-value code').forEach(function(codeEl) {
            codeEl.style.cursor = 'pointer';
            codeEl.title = 'Click to copy';
            
            codeEl.addEventListener('click', function() {
                const text = this.textContent;
                
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function() {
                        showCopyFeedback(codeEl);
                    }).catch(function() {
                        fallbackCopy(text, codeEl);
                    });
                } else {
                    fallbackCopy(text, codeEl);
                }
            });
        });
        
        function fallbackCopy(text, el) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            
            try {
                document.execCommand('copy');
                showCopyFeedback(el);
            } catch(e) {
                console.error('Copy failed');
            }
            
            document.body.removeChild(textarea);
        }
        
        function showCopyFeedback(el) {
            const original = el.innerHTML;
            el.innerHTML = '<i class="fas fa-check"></i> Copied!';
            el.style.background = 'rgba(72, 187, 120, 0.15)';
            el.style.color = '#2F855A';
            
            setTimeout(() => {
                el.innerHTML = original;
                el.style.background = '';
                el.style.color = '';
            }, 1500);
        }
    </script>
    
</body>
</html>