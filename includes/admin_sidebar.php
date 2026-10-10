<?php
// ============================================
// CURRENT PAGE DETECTION
// ============================================
$current_url  = $_SERVER['REQUEST_URI'];
$current_page = basename($current_url);
$current_page = strtok($current_page, '?');
$current_page = str_replace('.php', '', $current_page);
$current_page = strtolower($current_page);

// Normalize dashboard variants
if (in_array($current_page, ['index', 'dashboard', ''], true)) {
    $current_page = 'dashboard';
}

// ============================================
// SIDEBAR COUNTS
// ============================================

// Pending client orders
$pending_count = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM client_orders WHERE order_status = 'pending'");
    $stmt->execute();
    $pending_count = (int) $stmt->fetchColumn();
} catch (PDOException $e) {}

// Expiring products (within 30 days)
$expiring_count = 0;
try {
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT si.inventory_id)
        FROM store_inventory si
        JOIN products p ON si.product_id = p.product_id
        WHERE p.has_expiry = 'yes'
          AND si.expiry_date IS NOT NULL
          AND si.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND si.quantity > 0
    ");
    $stmt->execute();
    $expiring_count = (int) $stmt->fetchColumn();
} catch (PDOException $e) {}

// Helper for capped badge display
function badge_text($n, $cap = 99) {
    return $n > $cap ? $cap . '+' : (string) $n;
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
                <span>Admin Panel</span>
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
            <h6><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></h6>
            <small><?php echo ucfirst($_SESSION['role'] ?? 'Administrator'); ?></small>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <ul class="nav flex-column">

            <!-- ============================================
                 DASHBOARD
                 ============================================ -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'dashboard') ? 'active' : ''; ?>"
                   href="dashboard.php"
                   data-tooltip="Dashboard">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>

            <!-- ============================================
                 POS
                 ============================================ -->
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'pos') ? 'active' : ''; ?>"
                   href="pos.php"
                   data-tooltip="Point of Sale">
                    <span class="nav-icon"><i class="fas fa-cash-register"></i></span>
                    <span class="nav-text">Point of Sale</span>
                </a>
            </li>

            <!-- ============================================
                 CLIENT ORDERS
                 ============================================ -->
            <li class="nav-item">
                <a class="nav-link section-toggle collapsed"
                   data-bs-toggle="collapse"
                   href="#clientOrdersMenu"
                   role="button"
                   data-tooltip="Client Orders<?php echo $pending_count > 0 ? ' — ' . $pending_count . ' pending' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-users-cog"></i>
                        <?php if ($pending_count > 0): ?>
                            <span class="icon-alert-dot" title="<?php echo $pending_count; ?> pending orders"></span>
                        <?php endif; ?>
                    </span>
                    <span class="nav-text">Client Orders</span>
                    <?php if ($pending_count > 0): ?>
                        <span class="nav-badge" title="<?php echo $pending_count; ?> pending orders">
                            <?php echo badge_text($pending_count); ?>
                        </span>
                    <?php endif; ?>
                    <span class="nav-arrow"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['client_orders', 'client_management', 'view_order'], true) ? 'show' : ''; ?>"
                     id="clientOrdersMenu">
                    <ul class="nav flex-column sub-nav">
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'client_orders') ? 'active' : ''; ?>"
                               href="Client_Orders.php"
                               title="<?php echo $pending_count > 0 ? $pending_count . ' pending order(s)' : 'All Orders'; ?>">
                                <span class="nav-icon">
                                    <i class="fas fa-shopping-cart"></i>
                                    <?php if ($pending_count > 0): ?>
                                        <span class="icon-alert-dot"></span>
                                    <?php endif; ?>
                                </span>
                                <span class="nav-text">All Orders</span>
                                <?php if ($pending_count > 0): ?>
                                    <span class="nav-badge" title="<?php echo $pending_count; ?> pending">
                                        <?php echo badge_text($pending_count); ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'client_management') ? 'active' : ''; ?>"
                               href="Client_Management.php">
                                <span class="nav-icon"><i class="fas fa-user-tie"></i></span>
                                <span class="nav-text">Manage Clients</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- ============================================
                 INVENTORY
                 - Parent has no badge and no dot
                 - "Store Inventory" has no badge and no dot (removed)
                 - "Expiring Products" keeps its alert
                 ============================================ -->
            <li class="nav-item">
                <a class="nav-link section-toggle collapsed"
                   data-bs-toggle="collapse"
                   href="#inventoryMenu"
                   role="button"
                   data-tooltip="Inventory">
                    <span class="nav-icon">
                        <i class="fas fa-boxes"></i>
                    </span>
                    <span class="nav-text">Inventory</span>
                    <span class="nav-arrow"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['products', 'categories', 'inventory', 'purchases', 'expiring-products', 'expiring_products'], true) ? 'show' : ''; ?>"
                     id="inventoryMenu">
                    <ul class="nav flex-column sub-nav">

                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'products') ? 'active' : ''; ?>"
                               href="products.php">
                                <span class="nav-icon"><i class="fas fa-box"></i></span>
                                <span class="nav-text">Products</span>
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'categories') ? 'active' : ''; ?>"
                               href="categories.php">
                                <span class="nav-icon"><i class="fas fa-tags"></i></span>
                                <span class="nav-text">Categories</span>
                            </a>
                        </li>

                        <!-- Store Inventory — CLEAN, no dot, no badge -->
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'inventory') ? 'active' : ''; ?>"
                               href="inventory.php">
                                <span class="nav-icon">
                                    <i class="fas fa-warehouse"></i>
                                </span>
                                <span class="nav-text">Store Inventory</span>
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'purchases') ? 'active' : ''; ?>"
                               href="purchases.php">
                                <span class="nav-icon"><i class="fas fa-shopping-cart"></i></span>
                                <span class="nav-text">Purchases</span>
                            </a>
                        </li>

                        <!-- Expiring Products — keeps the alert -->
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'expiring-products' || $current_page == 'expiring_products') ? 'active' : ''; ?>"
                               href="expiring-products.php"
                               title="<?php echo $expiring_count > 0
                                    ? htmlspecialchars($expiring_count . ' product batch(es) expiring within 30 days')
                                    : 'Expiring Products'; ?>">
                                <span class="nav-icon">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <?php if ($expiring_count > 0): ?>
                                        <span class="icon-alert-dot icon-alert-dot-danger"></span>
                                    <?php endif; ?>
                                </span>
                                <span class="nav-text">Expiring Products</span>
                                <?php if ($expiring_count > 0): ?>
                                    <span class="nav-badge nav-badge-danger" title="<?php echo $expiring_count; ?> expiring soon">
                                        <?php echo badge_text($expiring_count); ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>

                    </ul>
                </div>
            </li>

            <!-- ============================================
                 SALES
                 ============================================ -->
            <li class="nav-item">
                <a class="nav-link section-toggle collapsed"
                   data-bs-toggle="collapse"
                   href="#salesMenu"
                   role="button"
                   data-tooltip="Sales">
                    <span class="nav-icon"><i class="fas fa-receipt"></i></span>
                    <span class="nav-text">Sales</span>
                    <span class="nav-arrow"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['sales', 'returns'], true) ? 'show' : ''; ?>"
                     id="salesMenu">
                    <ul class="nav flex-column sub-nav">
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'sales') ? 'active' : ''; ?>"
                               href="sales.php">
                                <span class="nav-icon"><i class="fas fa-receipt"></i></span>
                                <span class="nav-text">Sales Records</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'returns') ? 'active' : ''; ?>"
                               href="returns.php">
                                <span class="nav-icon"><i class="fas fa-undo"></i></span>
                                <span class="nav-text">Returns</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- ============================================
                 MANAGEMENT
                 ============================================ -->
            <li class="nav-item">
                <a class="nav-link section-toggle collapsed"
                   data-bs-toggle="collapse"
                   href="#managementMenu"
                   role="button"
                   data-tooltip="Management">
                    <span class="nav-icon"><i class="fas fa-cogs"></i></span>
                    <span class="nav-text">Management</span>
                    <span class="nav-arrow"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['customers', 'suppliers', 'stores', 'users'], true) ? 'show' : ''; ?>"
                     id="managementMenu">
                    <ul class="nav flex-column sub-nav">
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'customers') ? 'active' : ''; ?>"
                               href="customers.php">
                                <span class="nav-icon"><i class="fas fa-users"></i></span>
                                <span class="nav-text">Customers</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'suppliers') ? 'active' : ''; ?>"
                               href="suppliers.php">
                                <span class="nav-icon"><i class="fas fa-truck"></i></span>
                                <span class="nav-text">Suppliers</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'stores' || $current_page == 'branches') ? 'active' : ''; ?>"
                               href="stores.php">
                                <span class="nav-icon"><i class="fas fa-store"></i></span>
                                <span class="nav-text">Stores/Branches</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'users') ? 'active' : ''; ?>"
                               href="users.php">
                                <span class="nav-icon"><i class="fas fa-user-cog"></i></span>
                                <span class="nav-text">Users</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- ============================================
                 REPORTS
                 ============================================ -->
            <li class="nav-item">
                <a class="nav-link section-toggle collapsed"
                   data-bs-toggle="collapse"
                   href="#reportsMenu"
                   role="button"
                   data-tooltip="Reports">
                    <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                    <span class="nav-text">Reports</span>
                    <span class="nav-arrow"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['reports', 'inventory-reports'], true) ? 'show' : ''; ?>"
                     id="reportsMenu">
                    <ul class="nav flex-column sub-nav">
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'reports') ? 'active' : ''; ?>"
                               href="reports.php">
                                <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                                <span class="nav-text">Sales Reports</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'inventory-reports') ? 'active' : ''; ?>"
                               href="inventory-reports.php">
                                <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                                <span class="nav-text">Inventory Reports</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'expenses') ? 'active' : ''; ?>"
                               href="expenses.php">
                                <span class="nav-icon"><i class="fas fa-money-bill-wave"></i></span>
                                <span class="nav-text">Expenses</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- ============================================
                 SETTINGS
                 ============================================ -->
            <li class="nav-item">
                <a class="nav-link section-toggle collapsed"
                   data-bs-toggle="collapse"
                   href="#settingsMenu"
                   role="button"
                   data-tooltip="Settings">
                    <span class="nav-icon"><i class="fas fa-cog"></i></span>
                    <span class="nav-text">Settings</span>
                    <span class="nav-arrow"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['settings', 'profile', 'activity-logs'], true) ? 'show' : ''; ?>"
                     id="settingsMenu">
                    <ul class="nav flex-column sub-nav">
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'settings') ? 'active' : ''; ?>"
                               href="settings.php">
                                <span class="nav-icon"><i class="fas fa-cog"></i></span>
                                <span class="nav-text">System Settings</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'profile') ? 'active' : ''; ?>"
                               href="profile.php">
                                <span class="nav-icon"><i class="fas fa-user-cog"></i></span>
                                <span class="nav-text">My Profile</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page == 'activity-logs') ? 'active' : ''; ?>"
                               href="activity-logs.php">
                                <span class="nav-icon"><i class="fas fa-history"></i></span>
                                <span class="nav-text">Activity Logs</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- ============================================
                 LOGOUT
                 ============================================ -->
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
        <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Toggle Sidebar (Ctrl+B)">
            <i class="fas fa-chevron-left"></i>
            <span class="nav-text">Collapse</span>
        </button>
    </div>
</aside>