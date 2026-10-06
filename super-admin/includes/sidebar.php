<nav class="col-md-2 d-md-block sidebar">
    <div class="sidebar-sticky">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php') ? 'active' : ''; ?>" href="dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'organizations.php') ? 'active' : ''; ?>" href="organizations.php">
                    <i class="fas fa-building me-2"></i>All Organizations
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="organizations.php?type=demo">
                    <i class="fas fa-flask me-2"></i>Demo Accounts
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="organizations.php?type=paid">
                    <i class="fas fa-dollar-sign me-2"></i>Paid Subscribers
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="organizations.php?status=active">
                    <i class="fas fa-check-circle me-2"></i>Active
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="organizations.php?status=expired">
                    <i class="fas fa-times-circle me-2"></i>Expired
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="organizations.php?status=suspended">
                    <i class="fas fa-ban me-2"></i>Suspended
                </a>
            </li>
            <li class="nav-item mt-3">
                <hr class="text-white">
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'subscription-history.php') ? 'active' : ''; ?>" href="subscription-history.php">
                    <i class="fas fa-history me-2"></i>Subscription History
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings.php') ? 'active' : ''; ?>" href="settings.php">
                    <i class="fas fa-cog me-2"></i>Settings
                </a>
            </li>
        </ul>
    </div>
</nav>