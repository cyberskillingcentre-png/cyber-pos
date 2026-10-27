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
            --cyber-gray-lighter: #4A5568;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        html, body {
            width: 100%;
            height: 100%; /* Changed to 100% to prevent page scrolling */
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            overflow: hidden; /* Forced no scroll */
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
        
        /* Vignette overlay */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: radial-gradient(
                ellipse at center,
                rgba(0, 0, 0, 0) 0%,
                rgba(0, 0, 0, 0.15) 60%,
                rgba(0, 0, 0, 0.45) 100%
            );
            z-index: 0;
            pointer-events: none;
        }
        
        /* ============================================
           SUCCESS CARD - COMPACT FITTING
           ============================================ */
        .success-card {
            position: relative;
            z-index: 1;
            
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            
            border-radius: 16px;
            padding: 24px 30px;
            width: 100%;
            max-width: 720px; /* Narrower to fit comfortably */
            
            border: 1px solid rgba(255, 255, 255, 0.7);
            box-shadow: 
                0 20px 50px rgba(0, 0, 0, 0.35),
                inset 0 1px 0 rgba(255, 255, 255, 0.8);
            
            animation: scaleIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        
        /* ============================================
           SUCCESS ICON
           ============================================ */
        .success-icon-wrap {
            display: flex;
            justify-content: center;
            margin-bottom: 12px;
            position: relative;
        }
        
        .success-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--cyber-green), var(--cyber-green-dark));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.6rem;
            box-shadow: 0 10px 25px rgba(72, 187, 120, 0.4);
            animation: popIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) 0.1s both;
            position: relative;
            z-index: 2;
        }
        
        .success-icon::before,
        .success-icon::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 2px solid rgba(72, 187, 120, 0.4);
            animation: pulse-ring 2s ease-out infinite;
        }
        
        .success-icon::after { animation-delay: 1s; }
        
        @keyframes popIn {
            from { transform: scale(0); }
            to { transform: scale(1); }
        }
        
        @keyframes pulse-ring {
            0% { transform: scale(1); opacity: 1; }
            100% { transform: scale(1.6); opacity: 0; }
        }
        
        /* ============================================
           HEADER
           ============================================ */
        .success-header {
            text-align: center;
            margin-bottom: 16px;
        }
        
        .success-header h1 {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--cyber-dark);
            margin-bottom: 4px;
        }
        
        .success-header p {
            font-size: 0.8rem;
            color: var(--cyber-gray-lighter);
            margin: 0;
        }
        
        .success-header .org-name {
            display: inline-block;
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            color: #fff;
            padding: 3px 10px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.75rem;
            margin-bottom: 6px;
            box-shadow: 0 2px 8px rgba(107, 70, 193, 0.3);
        }
        
        /* ============================================
           TWO-COLUMN CONTENT LAYOUT
           ============================================ */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        
        /* ============================================
           CREDENTIALS BOX
           ============================================ */
        .credentials-box {
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            
            border-radius: 12px;
            padding: 14px 16px;
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-left: 3px solid var(--cyber-purple);
        }
        
        .credentials-box .box-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--cyber-purple);
            margin-bottom: 10px;
        }
        
        .credentials-box .box-title i {
            color: var(--cyber-orange);
        }
        
        .cred-row {
            display: flex;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px dashed rgba(226, 232, 240, 0.8);
        }
        
        .cred-row:last-child { border-bottom: none; padding-bottom: 0; }
        .cred-row:first-of-type { padding-top: 0; }
        
        .cred-label {
            flex: 0 0 90px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--cyber-gray-lighter);
        }
        
        .cred-value {
            flex: 1;
            font-size: 0.82rem;
            color: var(--cyber-dark);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .cred-value code {
            background: rgba(107, 70, 193, 0.1);
            color: var(--cyber-purple);
            padding: 2px 8px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
        }
        
        .cred-value .badge-trial {
            background: var(--cyber-orange);
            color: #fff;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 0.65rem;
            font-weight: 700;
        }
        
        /* ============================================
           INFO ALERTS (Right Column)
           ============================================ */
        .info-col {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .info-card {
            border-radius: 10px;
            padding: 10px 12px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 0.75rem;
            line-height: 1.4;
        }
        
        .info-card.warning {
            background: rgba(237, 137, 54, 0.12);
            border: 1px solid rgba(237, 137, 54, 0.3);
            color: #744210;
        }
        
        .info-card.tip {
            background: rgba(0, 181, 216, 0.1);
            border: 1px solid rgba(0, 181, 216, 0.25);
            color: #086F83;
        }
        
        .info-card i { font-size: 0.9rem; margin-top: 2px; }
        .info-card.warning i { color: var(--cyber-orange); }
        .info-card.tip i { color: var(--cyber-cyan); }
        
        .info-card strong {
            display: block;
            margin-bottom: 2px;
            color: inherit;
        }
        
        /* ============================================
           BUTTONS
           ============================================ */
        .btn-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 16px;
        }
        
        .btn-primary-cyber,
        .btn-secondary-cyber {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            border: none;
        }
        
        .btn-primary-cyber {
            background: var(--cyber-purple);
            color: #fff;
            box-shadow: 0 4px 12px rgba(107, 70, 193, 0.3);
        }
        
        .btn-primary-cyber:hover {
            background: var(--cyber-purple-dark);
            transform: translateY(-1px);
            color: #fff;
        }
        
        .btn-secondary-cyber {
            background: rgba(255, 255, 255, 0.8);
            color: var(--cyber-gray);
            border: 1px solid rgba(160, 174, 192, 0.5);
        }
        
        .btn-secondary-cyber:hover {
            background: #fff;
            color: var(--cyber-dark);
        }
        
        /* ============================================
           SUPPORT FOOTER
           ============================================ */
        .support-footer {
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px solid rgba(226, 232, 240, 0.6);
            text-align: center;
            font-size: 0.7rem;
            color: var(--cyber-gray-lighter);
        }
        
        .support-links {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-top: 6px;
        }
        
        .support-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: var(--cyber-gray);
            text-decoration: none;
            font-weight: 600;
        }
        
        .support-item:hover { color: var(--cyber-purple); }
        .support-item i { color: var(--cyber-orange); }
        
        /* ============================================
           CONFETTI
           ============================================ */
        .confetti {
            position: fixed;
            top: -10px;
            width: 8px;
            height: 8px;
            background: var(--cyber-purple);
            opacity: 0.8;
            z-index: 999;
            pointer-events: none;
            animation: fall linear forwards;
        }
        
        @keyframes fall {
            to { transform: translateY(100vh) rotate(720deg); opacity: 0; }
        }
        
        /* ============================================
           RESPONSIVE (Mobile fix)
           ============================================ */
        @media (max-width: 767.98px) {
            html, body {
                overflow-y: auto; /* Allow scroll on mobile */
                height: auto;
                min-height: 100vh;
            }
            
            .content-grid {
                grid-template-columns: 1fr; /* Stack columns on mobile */
                gap: 10px;
            }
            
            .success-card {
                padding: 20px;
                margin: 10px;
            }
            
            .btn-row {
                grid-template-columns: 1fr;
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
                <i class="fas fa-building me-1"></i> <?php echo htmlspecialchars($org['org_name']); ?>
            </span>
            <h1>Registration Successful!</h1>
            <p>Your 30-day free trial starts now.</p>
        </div>
        
        <!-- 2-Column Layout to save height -->
        <div class="content-grid">
            
            <!-- LEFT: Credentials -->
            <div class="credentials-box">
                <div class="box-title">
                    <i class="fas fa-key"></i> Login Details
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
                    <div class="cred-label">Trial Ends</div>
                    <div class="cred-value">
                        <span><?php echo date('M d, Y', strtotime($org['subscription_end'])); ?></span>
                        <span class="badge-trial"><?php echo DEMO_DAYS; ?> Days</span>
                    </div>
                </div>
            </div>
            
            <!-- RIGHT: Alerts -->
            <div class="info-col">
                <div class="info-card warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Save Credentials</strong>
                        Please save your login details. You'll need them to access your account.
                    </div>
                </div>
                
                <div class="info-card tip">
                    <i class="fas fa-lightbulb"></i>
                    <div>
                        <strong>Tip</strong>
                        You can change your password after logging in from the My Profile page.
                    </div>
                </div>
            </div>
            
        </div>
        
        <!-- Action Buttons -->
        <div class="btn-row">
            <a href="index.php" class="btn-secondary-cyber">
                <i class="fas fa-home"></i> <span>Home</span>
            </a>
            
            <a href="org-login.php" class="btn-primary-cyber">
                <i class="fas fa-sign-in-alt"></i> <span>Login Now</span>
            </a>
        </div>
        
        <!-- Support Footer -->
        <div class="support-footer">
            Need help? 
            <div class="support-links">
                <a href="mailto:info@cyberplaza.tech" class="support-item">
                    <i class="fas fa-envelope"></i> info@cyberplaza.tech
                </a>
                <a href="tel:+256757741977" class="support-item">
                    <i class="fas fa-phone"></i> +256 757 741 977
                </a>
            </div>
        </div>
    </div>
    
    <script>
        // ============================================
        // CELEBRATION CONFETTI (Reduced count for compactness)
        // ============================================
        (function createConfetti() {
            const colors = ['#6B46C1', '#ED8936', '#48BB78', '#00B5D8', '#9F7AEA', '#FBD38D'];
            const confettiCount = 30; // Reduced from 40
            
            for (let i = 0; i < confettiCount; i++) {
                setTimeout(() => {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    
                    const left = Math.random() * 100;
                    const duration = 2.5 + Math.random() * 2;
                    const size = 6 + Math.random() * 6;
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
                    setTimeout(() => confetti.remove(), (duration + 1) * 1000);
                }, i * 40);
            }
        })();
        
        // ============================================
        // COPY TO CLIPBOARD
        // ============================================
        document.querySelectorAll('.cred-value code').forEach(function(codeEl) {
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