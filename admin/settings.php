<?php
require_once '../config/config.php';
require_admin();
$page_title = "System Settings";
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        foreach ($_POST as $key => $value) {
            if ($key != 'csrf_token') {
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
        }
        log_activity($_SESSION['user_id'], 'SETTINGS_UPDATED', 'System settings updated', $conn);
        set_alert('success', 'Settings updated successfully!');
        header('Location: System_Settings');
        exit();
    } catch(PDOException $e) {
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
}
$stmt = $conn->prepare("SELECT * FROM system_settings");
$stmt->execute();
$settings_array = $stmt->fetchAll();
$settings = [];
foreach ($settings_array as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>
<div class="main-content">
    <div class="top-navbar">
         <button class="btn btn-link d-md-none sidebar-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h5 class="mb-0"><i class="fas fa-cog me-2"></i>System Settings</h5></div>
    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <?php echo $alert['message']; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header"><i class="fas fa-building me-2"></i>Company Information</div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Company Name</label>
                            <input type="text" name="company_name" class="form-control" value="<?php echo $settings['company_name'] ?? ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Company Email</label>
                            <input type="email" name="company_email" class="form-control" value="<?php echo $settings['company_email'] ?? ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Company Phone</label>
                            <input type="text" name="company_phone" class="form-control" value="<?php echo $settings['company_phone'] ?? ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Currency</label>
                            <input type="text" name="currency" class="form-control" value="<?php echo $settings['currency'] ?? 'UGX'; ?>">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Company Address</label>
                            <textarea name="company_address" class="form-control" rows="3"><?php echo $settings['company_address'] ?? ''; ?></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Default Tax Rate (%)</label>
                            <input type="number" name="tax_rate" class="form-control" step="0.01" value="<?php echo $settings['tax_rate'] ?? '18'; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Low Stock Threshold</label>
                            <input type="number" name="low_stock_threshold" class="form-control" value="<?php echo $settings['low_stock_threshold'] ?? '10'; ?>">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Receipt Footer</label>
                            <textarea name="receipt_footer" class="form-control" rows="2"><?php echo $settings['receipt_footer'] ?? ''; ?></textarea>
                        </div>
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Settings</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
