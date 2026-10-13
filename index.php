<?php
require_once 'master_config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CYBER POS - Cloud-Based POS System</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --cyber-purple: #6B46C1;
            --cyber-purple-dark: #553C9A;
            --cyber-orange: #ED8936;
            --cyber-orange-dark: #DD6B20;
            --cyber-cyan: #00B5D8;
            --cyber-green: #48BB78;
            --cyber-dark: #1A202C;
            --cyber-gray: #2D3748;
            --cyber-gray-lighter: #718096;
            --cyber-border: #E2E8F0;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        html { scroll-behavior: smooth; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--cyber-dark);
            overflow-x: hidden;
        }
        
        /* ============================================
           NAVBAR
           ============================================ */
        .cyber-navbar {
            background: rgba(26, 32, 44, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 14px 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            transition: box-shadow 0.3s ease;
        }
        
        .cyber-navbar .navbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: 1px;
            color: #fff;
            text-decoration: none;
        }
        
        .cyber-navbar .brand-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-orange));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.1rem;
            box-shadow: 0 4px 15px rgba(107, 70, 193, 0.4);
        }
        
        .cyber-navbar .brand-text {
            background: linear-gradient(135deg, #9F7AEA, #ED8936);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .btn-nav-login {
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            color: #fff;
            padding: 10px 22px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 15px rgba(107, 70, 193, 0.35);
        }
        
        .btn-nav-login:hover {
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(107, 70, 193, 0.5);
        }
        
        .btn-nav-register {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            padding: 10px 22px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .btn-nav-register:hover {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            transform: translateY(-2px);
        }
        
        /* ============================================
           HERO SECTION
           ============================================ */
        .hero-section {
            background-image: url('assets/images/login-bg.png');
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            padding: 70px 0;
            position: relative;
            min-height: calc(100vh - 70px);
            display: flex;
            align-items: center;
            overflow: hidden;
        }
        
        .hero-section::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(
                135deg,
                rgba(26, 32, 44, 0.9) 0%,
                rgba(26, 32, 44, 0.75) 50%,
                rgba(26, 32, 44, 0.55) 100%
            );
        }
        
        .hero-section .container {
            position: relative;
            z-index: 1;
        }
        
        /* Hero Text */
        .hero-text h1 {
            font-size: 3.2rem;
            font-weight: 900;
            color: #fff;
            line-height: 1.1;
            margin-bottom: 22px;
            letter-spacing: -1.2px;
        }
        
        .hero-text h1 .highlight {
            background: linear-gradient(135deg, #9F7AEA, #ED8936);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: inline-block;
        }
        
        .hero-text .lead {
            font-size: 1.15rem;
            color: rgba(255, 255, 255, 0.85);
            margin-bottom: 30px;
            line-height: 1.65;
            max-width: 540px;
        }
        
        .hero-features {
            list-style: none;
            padding: 0;
            margin: 0 0 32px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            max-width: 500px;
        }
        
        .hero-features li {
            display: flex;
            align-items: center;
            gap: 10px;
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .hero-features li i {
            color: #48BB78;
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .hero-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 32px;
        }
        
        .btn-hero-primary {
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            color: #fff;
            padding: 16px 32px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 1rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(107, 70, 193, 0.45);
        }
        
        .btn-hero-primary:hover {
            color: #fff;
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(107, 70, 193, 0.6);
        }
        
        .btn-hero-secondary {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            color: #fff;
            padding: 16px 32px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            border: 1.5px solid rgba(255, 255, 255, 0.3);
        }
        
        .btn-hero-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            transform: translateY(-3px);
        }
        
        /* Trust indicators */
        .hero-trust {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }
        
        .trust-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: rgba(255, 255, 255, 0.85);
        }
        
        .trust-item i {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            color: var(--cyber-orange);
            flex-shrink: 0;
        }
        
        .trust-item strong {
            display: block;
            font-size: 0.82rem;
            color: #fff;
            font-weight: 700;
            line-height: 1.2;
        }
        
        .trust-item span {
            font-size: 0.72rem;
            color: rgba(255, 255, 255, 0.6);
        }
        
        /* ============================================
           HERO VISUAL (right column)
           ============================================ */
        .hero-visual {
            position: relative;
            height: 520px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .hero-badge {
            width: 190px;
            height: 190px;
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            color: #fff;
            box-shadow: 
                0 20px 60px rgba(107, 70, 193, 0.5),
                inset 0 0 60px rgba(255, 255, 255, 0.1);
            animation: pulse-glow 3s ease-in-out infinite;
            position: relative;
            z-index: 2;
        }
        
        .hero-badge::before {
            content: '';
            position: absolute;
            inset: -20px;
            border-radius: 50%;
            border: 2px dashed rgba(159, 122, 234, 0.3);
            animation: rotate 20s linear infinite;
        }
        
        .hero-badge::after {
            content: '';
            position: absolute;
            inset: -40px;
            border-radius: 50%;
            border: 1px solid rgba(237, 137, 54, 0.2);
            animation: rotate 30s linear infinite reverse;
        }
        
        @keyframes rotate {
            to { transform: rotate(360deg); }
        }
        
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 20px 60px rgba(107, 70, 193, 0.5), inset 0 0 60px rgba(255, 255, 255, 0.1); }
            50% { box-shadow: 0 20px 80px rgba(107, 70, 193, 0.7), inset 0 0 80px rgba(255, 255, 255, 0.15); }
        }
        
        .hero-badge i {
            font-size: 2.5rem;
            filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.3));
        }
        
        .hero-badge span {
            font-weight: 800;
            letter-spacing: 2px;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        
        /* Floating stat cards */
        .stat-float {
            position: absolute;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 14px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 
                0 15px 40px rgba(0, 0, 0, 0.25),
                inset 0 1px 0 rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.6);
            animation: float-card 6s ease-in-out infinite;
            z-index: 1;
        }
        
        .stat-float strong {
            display: block;
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--cyber-dark);
            line-height: 1;
            margin-bottom: 2px;
        }
        
        .stat-float small {
            font-size: 0.7rem;
            color: var(--cyber-gray-lighter);
            font-weight: 500;
        }
        
        .stat-float-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .stat-float-icon.purple { background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark)); }
        .stat-float-icon.green { background: linear-gradient(135deg, var(--cyber-green), #38A169); }
        .stat-float-icon.orange { background: linear-gradient(135deg, var(--cyber-orange), var(--cyber-orange-dark)); }
        .stat-float-icon.cyan { background: linear-gradient(135deg, var(--cyber-cyan), #0987A0); }
        
        /* Positions */
        .stat-1 { top: 8%; left: 0; animation-delay: 0s; }
        .stat-2 { top: 22%; right: 0; animation-delay: 1.5s; }
        .stat-3 { bottom: 22%; left: 5%; animation-delay: 3s; }
        .stat-4 { bottom: 8%; right: 5%; animation-delay: 4.5s; }
        
        @keyframes float-card {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-12px); }
        }
        
        /* ============================================
           FEATURES SECTION
           ============================================ */
        .features-section {
            padding: 90px 0;
            background: linear-gradient(180deg, #F7FAFC 0%, #EDF2F7 100%);
        }
        
        .section-title-block {
            text-align: center;
            margin-bottom: 60px;
        }
        
        .section-title-block .eyebrow {
            display: inline-block;
            padding: 6px 16px;
            background: rgba(107, 70, 193, 0.1);
            color: var(--cyber-purple);
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 16px;
        }
        
        .section-title-block h2 {
            font-size: 2.25rem;
            font-weight: 800;
            color: var(--cyber-dark);
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }
        
        .section-title-block p {
            font-size: 1rem;
            color: var(--cyber-gray-lighter);
            max-width: 600px;
            margin: 0 auto;
        }
        
        .feature-card {
            background: #fff;
            border-radius: 16px;
            padding: 32px 28px;
            height: 100%;
            transition: all 0.3s ease;
            border: 1px solid var(--cyber-border);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }
        
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--cyber-purple), var(--cyber-orange));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.4s ease;
        }
        
        .feature-card:hover::before {
            transform: scaleX(1);
        }
        
        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(107, 70, 193, 0.15);
            border-color: rgba(107, 70, 193, 0.3);
        }
        
        .feature-icon {
            width: 64px;
            height: 64px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            margin-bottom: 20px;
            color: #fff;
        }
        
        .feature-icon.purple {
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            box-shadow: 0 8px 22px rgba(107, 70, 193, 0.3);
        }
        
        .feature-icon.orange {
            background: linear-gradient(135deg, var(--cyber-orange), var(--cyber-orange-dark));
            box-shadow: 0 8px 22px rgba(237, 137, 54, 0.3);
        }
        
        .feature-icon.cyan {
            background: linear-gradient(135deg, var(--cyber-cyan), #0987A0);
            box-shadow: 0 8px 22px rgba(0, 181, 216, 0.3);
        }
        
        .feature-icon.green {
            background: linear-gradient(135deg, var(--cyber-green), #38A169);
            box-shadow: 0 8px 22px rgba(72, 187, 120, 0.3);
        }
        
        .feature-card h4 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--cyber-dark);
            margin-bottom: 10px;
            letter-spacing: -0.3px;
        }
        
        .feature-card p {
            font-size: 0.9rem;
            color: var(--cyber-gray-lighter);
            line-height: 1.6;
            margin: 0;
        }
        
        /* ============================================
           HOW IT WORKS SECTION
           ============================================ */
        .steps-section {
            padding: 90px 0;
            background: #fff;
        }
        
        .step-card {
            position: relative;
            text-align: center;
            padding: 20px;
        }
        
        .step-number {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 20px;
            box-shadow: 0 8px 25px rgba(107, 70, 193, 0.35);
            position: relative;
            z-index: 2;
        }
        
        .step-card h4 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--cyber-dark);
            margin-bottom: 10px;
        }
        
        .step-card p {
            font-size: 0.9rem;
            color: var(--cyber-gray-lighter);
            margin: 0;
            line-height: 1.6;
        }
        
        /* Connector line between steps */
        .steps-row {
            position: relative;
        }
        
        .steps-row::before {
            content: '';
            position: absolute;
            top: 55px;
            left: 15%;
            right: 15%;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(107, 70, 193, 0.2), rgba(237, 137, 54, 0.2), transparent);
        }
        
        @media (max-width: 991.98px) {
            .steps-row::before {
                display: none;
            }
        }
        
        /* ============================================
           CTA SECTION
           ============================================ */
        .cta-section {
            background: linear-gradient(135deg, var(--cyber-dark) 0%, var(--cyber-gray) 100%);
            padding: 80px 0;
            position: relative;
            overflow: hidden;
        }
        
        .cta-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(107, 70, 193, 0.4), transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        
        .cta-section::after {
            content: '';
            position: absolute;
            bottom: -50%;
            left: -20%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(237, 137, 54, 0.3), transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        
        .cta-content {
            position: relative;
            z-index: 1;
            text-align: center;
        }
        
        .cta-content h2 {
            font-size: 2.25rem;
            font-weight: 800;
            color: #fff;
            margin-bottom: 14px;
            letter-spacing: -0.5px;
        }
        
        .cta-content p {
            font-size: 1.05rem;
            color: rgba(255, 255, 255, 0.75);
            margin-bottom: 32px;
        }
        
        /* ============================================
           FOOTER
           ============================================ */
        .cyber-footer {
            background: var(--cyber-dark);
            color: rgba(255, 255, 255, 0.7);
            padding: 40px 0 24px;
            font-size: 0.85rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
        
        .cyber-footer .footer-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #fff;
            font-weight: 700;
            letter-spacing: 1px;
            margin-bottom: 12px;
            font-size: 1rem;
        }
        
        .cyber-footer .footer-brand-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-orange));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 0.9rem;
        }
        
        .cyber-footer .footer-links {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            justify-content: center;
            margin: 20px 0;
            padding: 20px 0;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        
        .cyber-footer .footer-links a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        
        .cyber-footer .footer-links a:hover {
            color: var(--cyber-orange);
        }
        
        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 991.98px) {
            .hero-section {
                padding: 50px 0;
                background-attachment: scroll;
            }
            
            .hero-text h1 {
                font-size: 2.4rem;
            }
            
            .hero-text .lead {
                font-size: 1rem;
            }
            
            .hero-features {
                grid-template-columns: 1fr;
            }
            
            .hero-visual {
                height: 400px;
                margin-top: 40px;
            }
            
            .hero-badge {
                width: 150px;
                height: 150px;
            }
            
            .hero-badge i {
                font-size: 2rem;
            }
            
            .stat-float {
                padding: 10px 14px;
            }
            
            .stat-float strong {
                font-size: 0.95rem;
            }
            
            .section-title-block h2 {
                font-size: 1.75rem;
            }
            
            .cta-content h2 {
                font-size: 1.75rem;
            }
            
            .features-section,
            .steps-section {
                padding: 60px 0;
            }
            
            .cta-section {
                padding: 60px 0;
            }
        }
        
        @media (max-width: 575.98px) {
            .hero-text h1 {
                font-size: 1.85rem;
            }
            
            .hero-text .lead {
                font-size: 0.95rem;
            }
            
            .btn-hero-primary,
            .btn-hero-secondary {
                padding: 14px 24px;
                font-size: 0.9rem;
                width: 100%;
                justify-content: center;
            }
            
            .hero-visual {
                height: 320px;
            }
            
            .hero-badge {
                width: 120px;
                height: 120px;
                gap: 8px;
            }
            
            .hero-badge i {
                font-size: 1.6rem;
            }
            
            .hero-badge span {
                font-size: 0.7rem;
            }
            
            .stat-float small {
                display: none;
            }
            
            .stat-float strong {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    
    <!-- ============================================
         NAVBAR
         ============================================ -->
    <nav class="cyber-navbar">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center w-100">
                <a class="navbar-brand" href="index.php">
                    <div class="brand-icon">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <span class="brand-text">CYBER POS</span>
                </a>
                
                <div class="d-flex align-items-center gap-2">
                    <a href="register-organization.php" class="btn-nav-register d-none d-md-inline-flex">
                        <i class="fas fa-building"></i>
                        Register
                    </a>
                    <a href="org-login.php" class="btn-nav-login">
                        <i class="fas fa-sign-in-alt"></i>
                        Login
                    </a>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- ============================================
         HERO SECTION
         ============================================ -->
    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center g-5">
                
                <!-- LEFT: Hero Text -->
                <div class="col-lg-6">
                    <div class="hero-text">
                        <h1>
                            Powerful <span class="highlight">Cloud POS</span><br>
                            for Modern Business
                        </h1>
                        
                        <p class="lead">
                            Complete point-of-sale solution for retail businesses. Inventory, sales, customers, and comprehensive reports — all in one place.
                        </p>
                        
                        <ul class="hero-features">
                            <li><i class="fas fa-check-circle"></i>Multi-store Management</li>
                            <li><i class="fas fa-check-circle"></i>Real-time Inventory</li>
                            <li><i class="fas fa-check-circle"></i>Credit & Cash Sales</li>
                            <li><i class="fas fa-check-circle"></i>Comprehensive Reports</li>
                            <li><i class="fas fa-check-circle"></i>Customer Management</li>
                            <li><i class="fas fa-check-circle"></i>Employee Accounts</li>
                        </ul>
                        
                        <div class="hero-buttons">
                            <a href="register-organization.php" class="btn-hero-primary">
                                <i class="fas fa-rocket"></i>
                                Start 30-Day Free Trial
                            </a>
                            <a href="org-login.php" class="btn-hero-secondary">
                                <i class="fas fa-sign-in-alt"></i>
                                Sign In
                            </a>
                        </div>
                        
                        <!-- Trust indicators -->
                        <div class="hero-trust">
                            <div class="trust-item">
                                <i class="fas fa-shield-alt"></i>
                                <div>
                                    <strong>Bank-Level Security</strong>
                                    <span>SSL encrypted</span>
                                </div>
                            </div>
                            <div class="trust-item">
                                <i class="fas fa-clock"></i>
                                <div>
                                    <strong>24/7 Support</strong>
                                    <span>Always here for you</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- RIGHT: Hero Visual -->
                <div class="col-lg-6">
                    <div class="hero-visual">
                        
                        <!-- Floating Stat Cards -->
                        <div class="stat-float stat-1">
                            <div class="stat-float-icon purple">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                            <div>
                                <strong>1,248</strong>
                                <small>Sales today</small>
                            </div>
                        </div>
                        
                        <div class="stat-float stat-2">
                            <div class="stat-float-icon green">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div>
                                <strong>+30%</strong>
                                <small>Revenue growth</small>
                            </div>
                        </div>
                        
                        <div class="stat-float stat-3">
                            <div class="stat-float-icon orange">
                                <i class="fas fa-boxes"></i>
                            </div>
                            <div>
                                <strong>5,892</strong>
                                <small>Products</small>
                            </div>
                        </div>
                        
                        <div class="stat-float stat-4">
                            <div class="stat-float-icon cyan">
                                <i class="fas fa-users"></i>
                            </div>
                            <div>
                                <strong>328</strong>
                                <small>Customers</small>
                            </div>
                        </div>
                        
                        <!-- Center Badge -->
                        <div class="hero-badge">
                            <i class="fas fa-bolt"></i>
                            <span>CYBER POS</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- ============================================
         FEATURES SECTION
         ============================================ -->
    <section class="features-section">
        <div class="container">
            <div class="section-title-block">
                <span class="eyebrow">WHY CHOOSE US</span>
                <h2>Everything You Need to Run Your Business</h2>
                <p>Powerful features designed to help you sell more, manage smarter, and grow faster.</p>
            </div>
            
            <div class="row g-4">
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon purple">
                            <i class="fas fa-cloud"></i>
                        </div>
                        <h4>Cloud-Based</h4>
                        <p>Access your business from anywhere, anytime. All data securely stored in the cloud.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon green">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h4>Secure & Reliable</h4>
                        <p>Bank-level security with automatic backups and 99.9% uptime guarantee.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon orange">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h4>Smart Reports</h4>
                        <p>Real-time analytics and detailed reports to make better business decisions.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon cyan">
                            <i class="fas fa-headset"></i>
                        </div>
                        <h4>24/7 Support</h4>
                        <p>Dedicated support team ready to help you succeed with your business.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- ============================================
         HOW IT WORKS
         ============================================ -->
    <section class="steps-section">
        <div class="container">
            <div class="section-title-block">
                <span class="eyebrow">GET STARTED</span>
                <h2>Up and Running in 3 Steps</h2>
                <p>No credit card. No commitment. Just 30 days of full access.</p>
            </div>
            
            <div class="row steps-row g-4">
                <div class="col-md-4">
                    <div class="step-card">
                        <div class="step-number">1</div>
                        <h4>Register</h4>
                        <p>Create your organization account in under a minute. No credit card required.</p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="step-card">
                        <div class="step-number">2</div>
                        <h4>Set Up</h4>
                        <p>Add your products, invite your team, and configure your business settings.</p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="step-card">
                        <div class="step-number">3</div>
                        <h4>Start Selling</h4>
                        <p>Use the POS, track inventory, manage customers, and grow your business.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- ============================================
         CTA SECTION
         ============================================ -->
    <section class="cta-section">
        <div class="container">
            <div class="cta-content">
                <h2>Ready to Get Started?</h2>
                <p>Join hundreds of businesses already using CYBER POS to power their operations.</p>
                <a href="register-organization.php" class="btn-hero-primary">
                    <i class="fas fa-rocket"></i>
                    Start Your Free Trial
                </a>
            </div>
        </div>
    </section>
    
    <!-- ============================================
         FOOTER
         ============================================ -->
    <footer class="cyber-footer">
        <div class="container">
            <div class="row">
                <div class="col-md-6 mb-4 mb-md-0">
                    <div class="footer-brand">
                        <div class="footer-brand-icon">
                            <i class="fas fa-bolt"></i>
                        </div>
                        CYBER POS
                    </div>
                    <p class="mb-0" style="max-width: 400px;">
                        Complete cloud-based point-of-sale solution for modern retail businesses. 
                        Your ICT &amp; Bio-Tech Solutions partner.
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="fw-bold text-white mb-2">Quick Links</div>
                    <div class="d-flex flex-column gap-1">
                        <a href="register-organization.php" class="text-decoration-none">Register</a>
                        <a href="org-login.php" class="text-decoration-none">Login</a>
                    </div>
                </div>
            </div>
            
            <div class="footer-links">
                <a href="#">About</a>
                <a href="#">Features</a>
                <a href="#">Pricing</a>
                <a href="#">Support</a>
                <a href="#">Contact</a>
            </div>
            
            <div class="text-center">
                &copy; <?php echo date('Y'); ?> Cyber Plaza. All rights reserved.
            </div>
        </div>
    </footer>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Navbar shadow on scroll
        const navbar = document.querySelector('.cyber-navbar');
        window.addEventListener('scroll', function() {
            if (window.pageYOffset > 50) {
                navbar.style.boxShadow = '0 8px 30px rgba(0, 0, 0, 0.25)';
            } else {
                navbar.style.boxShadow = '0 4px 20px rgba(0, 0, 0, 0.15)';
            }
        });
        
        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if (href === '#') return;
                
                const target = document.querySelector(href);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    </script>
</body>
</html>