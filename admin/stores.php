<?php
require_once '../config/config.php';
require_admin();
$page_title = "Stores/Branches";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] == 'add') {
            $store_code = 'STR' . rand(100, 999);
            $store_name = sanitize_input($_POST['store_name']);
            $location = sanitize_input($_POST['location']);
            $phone = sanitize_input($_POST['phone']);
            $email = sanitize_input($_POST['email']);
            $stmt = $conn->prepare("INSERT INTO stores (store_code, store_name, location, phone, email) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$store_code, $store_name, $location, $phone, $email]);
            log_activity($_SESSION['user_id'], 'STORE_CREATED', "Created store: $store_name", $conn);
            set_alert('success', 'Store added successfully!');
            header('Location: stores.php');
            exit();
        }
    } catch(PDOException $e) {
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
}
$stmt = $conn->prepare("SELECT * FROM stores WHERE status = 'active' ORDER BY store_name");
$stmt->execute();
$stores = $stmt->fetchAll();
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>
<div class="main-content">
    <div class="top-navbar">
         <button class="btn btn-link d-md-none sidebar-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                
        <h5 class="mb-0"><i class="fas fa-store me-2"></i>Stores/Branches</h5></div>
    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <?php echo $alert['message']; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fas fa-list me-2"></i>All Stores</span>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-plus me-1"></i>Add Store
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datatable table-hover">
                        <thead><tr><th>Code</th><th>Store Name</th><th>Location</th><th>Phone</th><th>Email</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($stores as $store): ?>
                            <tr>
                                <td><?php echo $store['store_code']; ?></td>
                                <td><?php echo $store['store_name']; ?></td>
                                <td><?php echo $store['location']; ?></td>
                                <td><?php echo $store['phone']; ?></td>
                                <td><?php echo $store['email']; ?></td>
                                <td><span class="badge badge-success">Active</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Store/Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Store Name *</label><input type="text" name="store_name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Location</label><input type="text" name="location" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="add" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
