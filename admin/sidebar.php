<div class="sidebar">
    <div class="logo">
        <i class="fas fa-bolt fa-2x mb-2"></i>
        <h4><?php echo APP_NAME; ?></h4>
        <small class="text-white-50">Admin Panel</small>
    </div>
    
    <div class="user-info text-center py-3 border-bottom border-white border-opacity-10">
        <div class="mb-2">
            <i class="fas fa-user-circle fa-3x text-white"></i>
        </div>
        <h6 class="text-white mb-0"><?php echo $_SESSION['full_name']; ?></h6>
        <small class="text-white-50"><?php echo ucfirst($_SESSION['role']); ?></small>
    </div>
    
    <nav class="mt-3">
        <ul class="nav flex-column">
            <!-- Dashboard -->
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Dashboard') ? 'active' : ''; ?>" href="Dashboard">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            
            <!-- POS -->
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Point_Of_Sale') ? 'active' : ''; ?>" href="Point_Of_Sale">
                    <i class="fas fa-cash-register"></i> Point of Sale
                </a>
            </li>
            
            <!-- CLIENT ORDERS SECTION - NEW -->
            <li class="nav-item mt-2">
                <a class="nav-link section-toggle collapsed" data-bs-toggle="collapse" href="#clientOrdersMenu" role="button" aria-expanded="false">
                    <i class="fas fa-users-cog"></i> CLIENT ORDERS
                    <?php
                    // Get pending orders count for badge
                    try {
                        $stmt = $conn->prepare("SELECT COUNT(*) FROM client_orders WHERE order_status = 'pending'");
                        $stmt->execute();
                        $pending_count = $stmt->fetchColumn();
                        if ($pending_count > 0) {
                            echo '<span class="badge bg-danger ms-2">' . $pending_count . '</span>';
                        }
                    } catch(PDOException $e) {}
                    ?>
                    <i class="fas fa-chevron-down float-end mt-1"></i>
                </a>
                <div class="collapse" id="clientOrdersMenu">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Client_Orders') ? 'active' : ''; ?>" href="Client_Orders">
                                <i class="fas fa-shopping-cart"></i> All Orders
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Client_Management') ? 'active' : ''; ?>" href="Client_Management">
                                <i class="fas fa-user-tie"></i> Manage Clients
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- INVENTORY SECTION - COLLAPSIBLE -->
            <li class="nav-item mt-2">
                <a class="nav-link section-toggle collapsed" data-bs-toggle="collapse" href="#inventoryMenu" role="button" aria-expanded="false">
                    <i class="fas fa-boxes"></i> INVENTORY
                    <i class="fas fa-chevron-down float-end mt-1"></i>
                </a>
                <div class="collapse" id="inventoryMenu">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Products') ? 'active' : ''; ?>" href="Products">
                                <i class="fas fa-box"></i> Products
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Categories') ? 'active' : ''; ?>" href="Categories">
                                <i class="fas fa-tags"></i> Categories
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Stock_Levels') ? 'active' : ''; ?>" href="Stock_Levels">
                                <i class="fas fa-warehouse"></i> Store Inventory
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Stock_Purchases') ? 'active' : ''; ?>" href="Stock_Purchases">
                                <i class="fas fa-shopping-cart"></i> Purchases
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Expiring_Stock') ? 'active' : ''; ?>" href="Expiring_Stock">
                                <i class="fas fa-exclamation-triangle"></i> Expiring Products
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- SALES SECTION - COLLAPSIBLE -->
            <li class="nav-item mt-2">
                <a class="nav-link section-toggle collapsed" data-bs-toggle="collapse" href="#salesMenu" role="button" aria-expanded="false">
                    <i class="fas fa-receipt"></i> SALES
                    <i class="fas fa-chevron-down float-end mt-1"></i>
                </a>
                <div class="collapse" id="salesMenu">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Sales_Records') ? 'active' : ''; ?>" href="Sales_Records">
                                <i class="fas fa-receipt"></i> Sales Records
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Sales_Returns') ? 'active' : ''; ?>" href="Sales_Returns">
                                <i class="fas fa-undo"></i> Returns
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- MANAGEMENT SECTION - COLLAPSIBLE -->
            <li class="nav-item mt-2">
                <a class="nav-link section-toggle collapsed" data-bs-toggle="collapse" href="#managementMenu" role="button" aria-expanded="false">
                    <i class="fas fa-cogs"></i> MANAGEMENT
                    <i class="fas fa-chevron-down float-end mt-1"></i>
                </a>
                <div class="collapse" id="managementMenu">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Customers') ? 'active' : ''; ?>" href="Customers">
                                <i class="fas fa-users"></i> Customers
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Suppliers') ? 'active' : ''; ?>" href="Suppliers">
                                <i class="fas fa-truck"></i> Suppliers
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Branches') ? 'active' : ''; ?>" href="Branches">
                                <i class="fas fa-store"></i> Stores/Branches
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Users') ? 'active' : ''; ?>" href="Users">
                                <i class="fas fa-user-cog"></i> Users
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- REPORTS SECTION - COLLAPSIBLE -->
            <li class="nav-item mt-2">
                <a class="nav-link section-toggle collapsed" data-bs-toggle="collapse" href="#reportsMenu" role="button" aria-expanded="false">
                    <i class="fas fa-chart-line"></i> REPORTS
                    <i class="fas fa-chevron-down float-end mt-1"></i>
                </a>
                <div class="collapse" id="reportsMenu">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Sales_Report') ? 'active' : ''; ?>" href="Sales_Report">
                                <i class="fas fa-chart-line"></i> Sales Reports
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Inventory_Report') ? 'active' : ''; ?>" href="Inventory_Report">
                                <i class="fas fa-chart-bar"></i> Inventory Reports
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Expense_Report') ? 'active' : ''; ?>" href="Expense_Report">
                                <i class="fas fa-money-bill-wave"></i> Expenses
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- SETTINGS SECTION - COLLAPSIBLE -->
            <li class="nav-item mt-2">
                <a class="nav-link section-toggle collapsed" data-bs-toggle="collapse" href="#settingsMenu" role="button" aria-expanded="false">
                    <i class="fas fa-cog"></i> SETTINGS
                    <i class="fas fa-chevron-down float-end mt-1"></i>
                </a>
                <div class="collapse" id="settingsMenu">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'System_Settings') ? 'active' : ''; ?>" href="System_Settings">
                                <i class="fas fa-cog"></i> System Settings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'My_Profile') ? 'active' : ''; ?>" href="My_Profile">
                                <i class="fas fa-user-cog"></i> My Profile
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'Audit_Log') ? 'active' : ''; ?>" href="Audit_Log">
                                <i class="fas fa-history"></i> Activity Logs
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- Logout -->
            <li class="nav-item mt-3">
                <a class="nav-link" href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </nav>
</div>

<style>
/* Collapsible Menu Styling */
.sidebar .section-toggle {
    cursor: pointer;
    font-weight: 600;
    font-size: 0.85rem;
    letter-spacing: 0.5px;
    padding: 10px 15px;
    transition: all 0.3s ease;
}

.sidebar .section-toggle:hover {
    background: rgba(255, 255, 255, 0.1);
}

.sidebar .section-toggle i.fa-chevron-down {
    transition: transform 0.3s ease;
    font-size: 0.75rem;
}

.sidebar .section-toggle:not(.collapsed) i.fa-chevron-down {
    transform: rotate(180deg);
}

.sidebar .collapse .nav-link {
    padding: 8px 15px;
    font-size: 0.9rem;
}

.sidebar .collapse .nav-link:hover {
    background: rgba(255, 255, 255, 0.05);
    padding-left: 20px;
}

.sidebar .collapse .nav-link.active {
    background: rgba(255, 255, 255, 0.15);
    border-left: 3px solid #ED8936;
}

/* Submenu indentation */
.sidebar .ms-3 {
    margin-left: 0.5rem !important;
}

/* Badge styling in sidebar */
.sidebar .badge {
    font-size: 0.7rem;
    padding: 3px 6px;
    border-radius: 10px;
}
</style>

<script>
// Auto-expand active section on page load
document.addEventListener('DOMContentLoaded', function() {
    // Find active link
    const activeLink = document.querySelector('.sidebar .nav-link.active');
    
    if (activeLink) {
        // Check if it's inside a collapse
        const parentCollapse = activeLink.closest('.collapse');
        
        if (parentCollapse) {
            // Show the parent collapse
            const bsCollapse = new bootstrap.Collapse(parentCollapse, {
                toggle: false
            });
            bsCollapse.show();
            
            // Update the toggle button
            const toggleBtn = document.querySelector(`[href="#${parentCollapse.id}"]`);
            if (toggleBtn) {
                toggleBtn.classList.remove('collapsed');
                toggleBtn.setAttribute('aria-expanded', 'true');
            }
        }
    }
});
</script>
