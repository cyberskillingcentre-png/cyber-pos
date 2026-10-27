<?php
// Get current page
$current_url = $_SERVER['REQUEST_URI'];
$current_page = basename($current_url);
$current_page = strtok($current_page, '?');
$current_page = str_replace('.php', '', $current_page);
$current_page = strtolower($current_page);

if (in_array($current_page, ['index', 'dashboard', ''])) {
    $current_page = 'dashboard';
}

// Get today's sales count for badge
$today_count = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM sales WHERE DATE(sale_date) = CURDATE() AND served_by = ? AND status = 'completed'");
    $stmt->execute([$_SESSION['user_id']]);
    $today_count = $stmt->fetchColumn();
} catch(PDOException $e) {}
?>

<!-- ============================================
     COMPACT LIGHT EMPLOYEE SIDEBAR
     ============================================ -->
<style>
    /* 1. Force Sidebar Width to be Narrower */
    :root {
        --sidebar-width: 200px !important; 
    }

    .sidebar, .sidebar.active {
        width: 200px !important;
        min-width: 200px !important;
        max-width: 200px !important;
        
        /* LIGHT THEME COLORS */
        background-color: #ffffff !important;
        border-right: 1px solid #e2e8f0 !important;
        box-shadow: 2px 0 10px rgba(0,0,0,0.03) !important;
    }

    /* 2. Adjust Main Content to match the new sidebar width */
    .main-content {
        margin-left: 200px !important;
        width: calc(100% - 200px) !important;
    }

    /* Mobile Responsive Fix */
    @media (max-width: 991.98px) {
        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
        }
    }

    /* 3. Header Text (Visible Dark Color) */
    .sidebar-header {
        padding: 12px 14px !important;
        border-bottom: 1px solid #f1f5f9 !important;
    }
    .sidebar-header h4, 
    .sidebar-header .logo-text h4 {
        font-size: 0.95rem !important;
        margin-bottom: 0 !important;
        color: #1e293b !important; /* Dark Slate */
        font-weight: 800 !important;
    }
    .sidebar-header span {
        font-size: 0.65rem !important;
        color: #64748b !important; /* Gray */
    }

    /* 4. User Profile Section */
    .sidebar-user {
        padding: 12px 14px !important;
        margin-bottom: 8px !important;
        gap: 10px !important;
        background: #f8fafc !important;
        border-bottom: 1px solid #f1f5f9 !important;
    }
    .sidebar-user .user-avatar i {
        font-size: 1.5rem !important;
        color: #6B46C1 !important;
    }
    .sidebar-user h6 {
        font-size: 0.8rem !important;
        margin-bottom: 0 !important;
        color: #1e293b !important; /* Dark Slate */
        font-weight: 700 !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .sidebar-user small {
        font-size: 0.65rem !important;
        color: #64748b !important;
    }

    /* 5. Navigation Links */
    .sidebar-nav {
        padding: 10px 8px !important;
    }
    
    .sidebar-nav .nav-link {
        padding: 8px 10px !important;
        margin-bottom: 2px !important;
        border-radius: 6px !important;
        gap: 8px !important; 
        transition: all 0.2s;
        display: flex;
        align-items: center;
        background: transparent !important;
    }
    
    /* Force Text Color to be Dark */
    .sidebar-nav .nav-link .nav-text {
        font-size: 0.85rem !important;
        color: #334155 !important; /* Dark Slate Gray for visibility */
        font-weight: 600 !important;
    }

    /* Force Icons to be Gray */
    .sidebar-nav .nav-icon i {
        font-size: 0.9rem !important;
        width: 18px !important;
        text-align: center !important;
        color: #64748b !important; 
    }
    
    /* Hover State */
    .sidebar-nav .nav-link:hover {
        background-color: #f1f5f9 !important;
    }
    .sidebar-nav .nav-link:hover .nav-text {
        color: #0f172a !important; /* Almost Black */
    }

    /* Active State */
    .sidebar-nav .nav-link.active {
        background-color: #f3e8ff !important; /* Light purple background */
    }
    .sidebar-nav .nav-link.active .nav-text {
        color: #6B46C1 !important; /* Purple text */
        font-weight: 800 !important;
    }
    .sidebar-nav .nav-link.active .nav-icon i {
        color: #6B46C1 !important; 
    }
    
    /* 6. Badges and Arrows */
    .sidebar-nav .nav-arrow i {
        font-size: 0.65rem !important;
        color: #94a3b8 !important;
    }
    .sidebar-nav .nav-badge {
        font-size: 0.65rem !important;
        padding: 2px 6px !important;
        background-color: #ef4444 !important;
        color: #ffffff !important;
        font-weight: 700;
        border-radius: 10px;
        margin-left: auto;
    }
    
    .icon-badge {
        position: absolute;
        top: -2px;
        right: -4px;
        background: #ef4444;
        color: white;
        font-size: 0.55rem;
        font-weight: 800;
        padding: 1px 4px;
        border-radius: 8px;
    }

    /* 7. Footer */
    .sidebar-footer {
        padding: 10px 14px !important;
        border-top: 1px solid #f1f5f9 !important;
    }
    .sidebar-collapse-btn {
        padding: 8px !important;
        font-size: 0.78rem !important;
        color: #475569 !important;
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
        font-weight: 600;
        border-radius: 6px;
    }
    .sidebar-collapse-btn:hover {
        background: #f1f5f9 !important;
    }

    /* Logout button specific color */
    .logout-item .nav-link .nav-text {
        color: #ef4444 !important; /* Red text for logout */
    }
    .logout-item .nav-link .nav-icon i {
        color: #ef4444 !important;
    }
    .logout-item .nav-link:hover {
        background-color: #fef2f2 !important;
    }

    /* Hide scrollbar for cleaner look */
    .sidebar-nav::-webkit-scrollbar { width: 4px; }
    .sidebar-nav::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
</style>

<!-- Sidebar Overlay (Mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    
    <!-- Header -->
    <div class="sidebar-header">
        <div class="logo-container">
            <img src="../assets/images/5.png" alt="Logo" class="logo-img" onerror="this.style.display='none'">
            <div class="logo-text">
                <h4><?php echo APP_NAME; ?></h4>
                <span>Employee Panel</span>
            </div>
        </div>
    </div>
    
    <!-- User Info -->
    <div class="sidebar-user">
        <div class="user-avatar">
            <i class="fas fa-user-circle"></i>
            <span class="status-dot"></span>
        </div>
        <div class="user-details">
            <h6><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Employee'); ?></h6>
            <small><?php echo ucfirst($_SESSION['role'] ?? 'Employee'); ?></small>
        </div>
    </div>
    
    <!-- Navigation -->
    <nav class="sidebar-nav">
        <ul class="nav flex-column">
            
            <!-- Dashboard -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'dashboard') ? 'active' : ''; ?>" 
                   href="dashboard.php" data-tooltip="Dashboard">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>
            
            <!-- POS -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'pos') ? 'active' : ''; ?>" 
                   href="pos.php" data-tooltip="Point of Sale">
                    <span class="nav-icon"><i class="fas fa-cash-register"></i></span>
                    <span class="nav-text">Point of Sale</span>
                </a>
            </li>
            
            <!-- My Sales -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'my-sales' || $current_page == 'my_sales') ? 'active' : ''; ?>" 
                   href="my-sales.php" data-tooltip="My Sales">
                    <span class="nav-icon" style="position:relative;">
                        <i class="fas fa-receipt"></i>
                        <?php if ($today_count > 0): ?>
                            <span class="icon-badge"><?php echo $today_count > 9 ? '9+' : $today_count; ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="nav-text">My Sales</span>
                    <?php if ($today_count > 0): ?>
                        <span class="nav-badge"><?php echo $today_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            
            <!-- Logout -->
            <li class="nav-item logout-item">
                <a class="nav-link" href="../logout.php" data-tooltip="Logout">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span class="nav-text">Logout</span>
                </a>
            </li>
        </ul>
    </nav>
    
    <!-- Footer - Collapse Button -->
    <div class="sidebar-footer">
        <button type="button" class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Toggle Sidebar (Ctrl+B)">
            <i class="fas fa-chevron-left"></i>
            <span class="nav-text">Collapse</span>
        </button>
    </div>
</aside>