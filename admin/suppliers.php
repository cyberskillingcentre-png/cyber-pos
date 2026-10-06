<?php
require_once '../config/config.php';
require_admin();
$page_title = "Suppliers";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] == 'add') {
            $supplier_code = 'SUP' . rand(1000, 9999);
            $supplier_name = sanitize_input($_POST['supplier_name']);
            $contact_person = sanitize_input($_POST['contact_person']);
            $email = sanitize_input($_POST['email']);
            $phone = sanitize_input($_POST['phone']);
            $address = sanitize_input($_POST['address']);
            $stmt = $conn->prepare("INSERT INTO suppliers (supplier_code, supplier_name, contact_person, email, phone, address) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$supplier_code, $supplier_name, $contact_person, $email, $phone, $address]);
            log_activity($_SESSION['user_id'], 'SUPPLIER_CREATED', "Created supplier: $supplier_name", $conn);
            set_alert('success', 'Supplier added successfully!');
            header('Location: Suppliers');
            exit();
        }
    } catch(PDOException $e) {
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
}
$stmt = $conn->prepare("SELECT * FROM suppliers WHERE status = 'active' ORDER BY supplier_name");
$stmt->execute();
$suppliers = $stmt->fetchAll();
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>
<div class="main-content">
    <div class="top-navbar">
         <button class="btn btn-link d-md-none sidebar-toggle">
                    <i class="fas fa-bars"></i>
                </button>
        <h5 class="mb-0"><i class="fas fa-truck me-2"></i>Suppliers</h5></div>
    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <?php echo $alert['message']; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fas fa-list me-2"></i>All Suppliers</span>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-plus me-1"></i>Add Supplier
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datatable table-hover">
                        <thead><tr><th>Code</th><th>Name</th><th>Contact Person</th><th>Email</th><th>Phone</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($suppliers as $sup): ?>
                            <tr>
                                <td><?php echo $sup['supplier_code']; ?></td>
                                <td><?php echo $sup['supplier_name']; ?></td>
                                <td><?php echo $sup['contact_person']; ?></td>
                                <td><?php echo $sup['email']; ?></td>
                                <td><?php echo $sup['phone']; ?></td>
                                <td><button class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></button></td>
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
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Supplier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Supplier Name *</label><input type="text" name="supplier_name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Contact Person</label><input type="text" name="contact_person" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="3"></textarea></div>
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
