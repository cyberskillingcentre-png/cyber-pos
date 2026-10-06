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
                    <span class="nav-icon">
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
            
            <!-- My Profile -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'profile') ? 'active' : ''; ?>" 
                   href="profile.php" data-tooltip="My Profile">
                    <span class="nav-icon"><i class="fas fa-user-cog"></i></span>
                    <span class="nav-text">My Profile</span>
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
    
    <!-- ============================================
         FOOTER - COLLAPSE BUTTON (MUST BE HERE)
         ============================================ -->
    <div class="sidebar-footer">
        <button type="button" class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Toggle Sidebar (Ctrl+B)">
            <i class="fas fa-chevron-left"></i>
            <span class="nav-text">Collapse</span>
        </button>
    </div>
</aside>