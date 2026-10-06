<?php
require_once 'auth.php';
require_super_admin();

$page_title = "Super Admin Dashboard";

// Get statistics
$stmt = $master_conn->query("SELECT COUNT(*) as total FROM organizations");
$total_orgs = $stmt->fetch()['total'];

$stmt = $master_conn->query("SELECT COUNT(*) as total FROM organizations WHERE subscription_type = 'demo'");
$demo_orgs = $stmt->fetch()['total'];

$stmt = $master_conn->query("SELECT COUNT(*) as total FROM organizations WHERE subscription_type = 'paid'");
$paid_orgs = $stmt->fetch()['total'];

$stmt = $master_conn->query("SELECT COUNT(*) as total FROM organizations WHERE status = 'active'");
$active_orgs = $stmt->fetch()['total'];

$stmt = $master_conn->query("SELECT COUNT(*) as total FROM organizations WHERE status = 'expired'");
$expired_orgs = $stmt->fetch()['total'];

$stmt = $master_conn->query("SELECT COUNT(*) as total FROM organizations WHERE status = 'suspended'");
$suspended_orgs = $stmt->fetch()['total'];

// Get organizations expiring soon (within 7 days)
$stmt = $master_conn->query("
    SELECT COUNT(*) as total 
    FROM organizations 
    WHERE status = 'active' 
    AND subscription_end <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND subscription_end >= CURDATE()
");
$expiring_soon = $stmt->fetch()['total'];

// Get recent registrations (last 7 days)
$stmt = $master_conn->query("
    SELECT COUNT(*) as total 
    FROM organizations 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$recent_registrations = $stmt->fetch()['total'];
?>

<?php include 'includes/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-10 ms-sm-auto px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h1>
                <div>
                    <span class="text-muted">Welcome, <?php echo get_super_admin_name(); ?></span>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card border-left-primary shadow h-100">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                        Total Organizations
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_orgs; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-building fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card border-left-warning shadow h-100">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                        Demo Accounts
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $demo_orgs; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-flask fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card border-left-success shadow h-100">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                        Paid Subscribers
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $paid_orgs; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-dollar-sign fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card border-left-info shadow h-100">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                        Active Organizations
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $active_orgs; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Second Row -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card border-left-danger shadow h-100">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                        Expiring Soon (7 days)
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $expiring_soon; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-exclamation-triangle fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card border-left-secondary shadow h-100">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">
                                        Expired
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $expired_orgs; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-times-circle fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card border-left-dark shadow h-100">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs font-weight-bold text-dark text-uppercase mb-1">
                                        Suspended
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $suspended_orgs; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-ban fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card border-left-primary shadow h-100">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                        New (Last 7 days)
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $recent_registrations; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-user-plus fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <a href="organizations.php" class="btn btn-primary w-100 btn-lg">
                                        <i class="fas fa-building fa-2x mb-2 d-block"></i>
                                        All Organizations
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="organizations.php?type=demo" class="btn btn-warning w-100 btn-lg">
                                        <i class="fas fa-flask fa-2x mb-2 d-block"></i>
                                        Demo Accounts
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="organizations.php?type=paid" class="btn btn-success w-100 btn-lg">
                                        <i class="fas fa-dollar-sign fa-2x mb-2 d-block"></i>
                                        Paid Subscribers
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="organizations.php?status=expired" class="btn btn-danger w-100 btn-lg">
                                        <i class="fas fa-exclamation-triangle fa-2x mb-2 d-block"></i>
                                        Expiring Soon
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
        </main>
    </div>
</div>

<style>
.card {
    border-radius: 10px;
}
.border-left-primary {
    border-left: 4px solid #4e73df !important;
}
.border-left-success {
    border-left: 4px solid #1cc88a !important;
}
.border-left-info {
    border-left: 4px solid #36b9cc !important;
}
.border-left-warning {
    border-left: 4px solid #f6c23e !important;
}
.border-left-danger {
    border-left: 4px solid #e74a3b !important;
}
.border-left-secondary {
    border-left: 4px solid #858796 !important;
}
.border-left-dark {
    border-left: 4px solid #5a5c69 !important;
}
.text-xs {
    font-size: 0.7rem;
}
</style>

<?php include 'includes/footer.php'; ?>