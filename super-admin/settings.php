<?php
require_once 'auth.php';
require_super_admin();

$page_title = "System Settings";

$success = '';
$error = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    csrf_require();
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Get current admin
        $stmt = $master_conn->prepare("SELECT * FROM super_admins WHERE admin_id = ?");
        $stmt->execute([$_SESSION['super_admin_id']]);
        $admin = $stmt->fetch();
        
        if (!password_verify($current_password, $admin['password'])) {
            $error = 'Current password is incorrect';
        } elseif ($new_password != $confirm_password) {
            $error = 'New passwords do not match';
        } elseif (strlen($new_password) < 6) {
            $error = 'Password must be at least 6 characters';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $master_conn->prepare("UPDATE super_admins SET password = ? WHERE admin_id = ?");
            $stmt->execute([$hashed, $_SESSION['super_admin_id']]);
            $success = 'Password changed successfully';
        }
    }
}

// Get current admin info
$stmt = $master_conn->prepare("SELECT * FROM super_admins WHERE admin_id = ?");
$stmt->execute([$_SESSION['super_admin_id']]);
$admin = $stmt->fetch();

// Get system statistics
$stats = [];
$stats['total_orgs'] = $master_conn->query("SELECT COUNT(*) FROM organizations")->fetchColumn();
$stats['total_databases'] = $stats['total_orgs'];
$stats['active_orgs'] = $master_conn->query("SELECT COUNT(*) FROM organizations WHERE status = 'active'")->fetchColumn();
$stats['demo_orgs'] = $master_conn->query("SELECT COUNT(*) FROM organizations WHERE subscription_type = 'demo'")->fetchColumn();
$stats['paid_orgs'] = $master_conn->query("SELECT COUNT(*) FROM organizations WHERE subscription_type = 'paid'")->fetchColumn();
?>

<?php include 'includes/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-10 ms-sm-auto px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-cog me-2"></i>System Settings</h1>
            </div>
            
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <div class="row">
                <!-- Admin Profile -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-user me-2"></i>Admin Profile</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Username:</th>
                                    <td><?php echo $admin['username']; ?></td>
                                </tr>
                                <tr>
                                    <th>Full Name:</th>
                                    <td><?php echo $admin['full_name']; ?></td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td><?php echo $admin['email']; ?></td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td><span class="badge bg-success"><?php echo ucfirst($admin['status']); ?></span></td>
                                </tr>
                                <tr>
                                    <th>Created:</th>
                                    <td><?php echo date('F d, Y', strtotime($admin['created_at'])); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Change Password -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-warning text-white">
                            <h5 class="mb-0"><i class="fas fa-lock me-2"></i>Change Password</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Current Password *</label>
                                    <input type="password" name="current_password" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">New Password *</label>
                                    <input type="password" name="new_password" class="form-control" required>
                                    <small class="text-muted">Minimum 6 characters</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Confirm New Password *</label>
                                    <input type="password" name="confirm_password" class="form-control" required>
                                </div>
                                <button type="submit" name="change_password" class="btn btn-warning w-100">
                                    <i class="fas fa-save me-2"></i>Change Password
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- System Statistics -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>System Statistics</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h2 class="text-primary"><?php echo $stats['total_orgs']; ?></h2>
                                    <p class="mb-0">Total Organizations</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h2 class="text-success"><?php echo $stats['active_orgs']; ?></h2>
                                    <p class="mb-0">Active Organizations</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h2 class="text-warning"><?php echo $stats['demo_orgs']; ?></h2>
                                    <p class="mb-0">Demo Accounts</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h2 class="text-success"><?php echo $stats['paid_orgs']; ?></h2>
                                    <p class="mb-0">Paid Subscribers</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h2 class="text-info"><?php echo $stats['total_databases']; ?></h2>
                                    <p class="mb-0">Total Databases</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h2 class="text-dark"><?php echo DEMO_DAYS; ?></h2>
                                    <p class="mb-0">Demo Period (Days)</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- System Configuration -->
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="fas fa-server me-2"></i>System Configuration</h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless">
                        <tr>
                            <th width="30%">Application Name:</th>
                            <td><code><?php echo APP_NAME; ?></code></td>
                        </tr>
                        <tr>
                            <th>Application URL:</th>
                            <td><code><?php echo APP_URL; ?></code></td>
                        </tr>
                        <tr>
                            <th>Master Database:</th>
                            <td><code><?php echo MASTER_DB_NAME; ?></code></td>
                        </tr>
                        <tr>
                            <th>Template Database:</th>
                            <td><code><?php echo TEMPLATE_DB_NAME; ?></code></td>
                        </tr>
                        <tr>
                            <th>Demo Period:</th>
                            <td><code><?php echo DEMO_DAYS; ?> days</code></td>
                        </tr>
                        <tr>
                            <th>Warning Period:</th>
                            <td><code><?php echo WARNING_DAYS; ?> days before expiry</code></td>
                        </tr>
                        <tr>
                            <th>PHP Version:</th>
                            <td><code><?php echo phpversion(); ?></code></td>
                        </tr>
                        <tr>
                            <th>MySQL Version:</th>
                            <td><code><?php echo $master_conn->query('SELECT VERSION()')->fetchColumn(); ?></code></td>
                        </tr>
                    </table>
                </div>
            </div>
            
        </main>
    </div>
</div>

<?php include 'includes/footer.php'; ?>