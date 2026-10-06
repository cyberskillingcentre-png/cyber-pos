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

// Get pending orders count for badge
$pending_count = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM client_orders WHERE client_id = ? AND order_status IN ('pending', 'confirmed', 'processing')");
    $stmt->execute([$_SESSION['user_id']]);
    $pending_count = $stmt->fetchColumn();
} catch(PDOException $e) {}

// Get cart items count (if favorites/cart table exists)
$cart_count = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM client_favorites WHERE client_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $cart_count = $stmt->fetchColumn();
} catch(PDOException $e) {
    $cart_count = 0;
}
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
                <span>Client Portal</span>
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
            <h6><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Client'); ?></h6>
            <small><?php echo ucfirst($_SESSION['role'] ?? 'Client'); ?></small>
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
            
            <!-- Browse Products -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'products') ? 'active' : ''; ?>" 
                   href="products.php" data-tooltip="Browse Products">
                    <span class="nav-icon"><i class="fas fa-shopping-bag"></i></span>
                    <span class="nav-text">Browse Products</span>
                </a>
            </li>
            
            <!-- New Order -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'new-order' || $current_page == 'new_order') ? 'active' : ''; ?>" 
                   href="new-order.php" data-tooltip="New Order">
                    <span class="nav-icon"><i class="fas fa-plus-circle"></i></span>
                    <span class="nav-text">New Order</span>
                </a>
            </li>
            
            <!-- My Orders -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'orders' || $current_page == 'order-details') ? 'active' : ''; ?>" 
                   href="orders.php" data-tooltip="My Orders">
                    <span class="nav-icon">
                        <i class="fas fa-list-alt"></i>
                        <?php if ($pending_count > 0): ?>
                            <span class="icon-badge"><?php echo $pending_count > 9 ? '9+' : $pending_count; ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="nav-text">My Orders</span>
                    <?php if ($pending_count > 0): ?>
                        <span class="nav-badge"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            
            <!-- Invoices -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'invoices') ? 'active' : ''; ?>" 
                   href="invoices.php" data-tooltip="Invoices">
                    <span class="nav-icon"><i class="fas fa-file-invoice"></i></span>
                    <span class="nav-text">Invoices</span>
                </a>
            </li>
            
            <!-- Favorites -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'favorites') ? 'active' : ''; ?>" 
                   href="favorites.php" data-tooltip="Favorites">
                    <span class="nav-icon">
                        <i class="fas fa-heart"></i>
                        <?php if ($cart_count > 0): ?>
                            <span class="icon-badge icon-badge-warning"><?php echo $cart_count > 9 ? '9+' : $cart_count; ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="nav-text">Favorites</span>
                    <?php if ($cart_count > 0): ?>
                        <span class="nav-badge nav-badge-warning"><?php echo $cart_count; ?></span>
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
    
    <!-- Footer - Collapse Button -->
    <div class="sidebar-footer">
        <button type="button" class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Toggle Sidebar (Ctrl+B)">
            <i class="fas fa-chevron-left"></i>
            <span class="nav-text">Collapse</span>
        </button>
    </div>
</aside>