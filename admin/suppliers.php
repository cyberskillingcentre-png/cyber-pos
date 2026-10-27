<?php
require_once '../config/config.php';
require_admin();

$page_title = "Suppliers";

// ============================================
// HANDLE POST ACTIONS
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    csrf_require();
    try {
        // ----------------------------------------
        // ADD SUPPLIER
        // ----------------------------------------
        if ($_POST['action'] == 'add') {
            $supplier_code  = 'SUP' . rand(1000, 9999);
            $supplier_name  = trim($_POST['supplier_name'] ?? '');
            $contact_person = trim($_POST['contact_person'] ?? '');
            $email          = trim($_POST['email'] ?? '');
            $phone          = trim($_POST['phone'] ?? '');
            $address        = trim($_POST['address'] ?? '');

            if ($supplier_name === '') {
                throw new Exception('Supplier name is required.');
            }

            // Ensure supplier_code is unique
            $tries = 0;
            do {
                $check = $conn->prepare("SELECT supplier_id FROM suppliers WHERE supplier_code = ?");
                $check->execute([$supplier_code]);
                if (!$check->fetch()) break;
                $supplier_code = 'SUP' . rand(1000, 9999);
                $tries++;
            } while ($tries < 20);

            $stmt = $conn->prepare("
                INSERT INTO suppliers 
                (supplier_code, supplier_name, contact_person, email, phone, address)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$supplier_code, $supplier_name, $contact_person, $email, $phone, $address]);

            log_activity($_SESSION['user_id'], 'SUPPLIER_CREATED',
                         "Created supplier: $supplier_name", $conn);
            set_alert('success', 'Supplier added successfully!');
            header('Location: Suppliers.php');
            exit();
        }

        // ----------------------------------------
        // UPDATE SUPPLIER
        // ----------------------------------------
        if ($_POST['action'] == 'edit') {
            $supplier_id    = intval($_POST['supplier_id'] ?? 0);
            $supplier_name  = trim($_POST['supplier_name'] ?? '');
            $contact_person = trim($_POST['contact_person'] ?? '');
            $email          = trim($_POST['email'] ?? '');
            $phone          = trim($_POST['phone'] ?? '');
            $address        = trim($_POST['address'] ?? '');
            $status         = $_POST['status'] ?? 'active';

            if ($supplier_id <= 0) {
                throw new Exception('Invalid supplier.');
            }
            if ($supplier_name === '') {
                throw new Exception('Supplier name is required.');
            }
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }

            $check = $conn->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ?");
            $check->execute([$supplier_id]);
            $existing = $check->fetch();
            if (!$existing) {
                throw new Exception('Supplier not found.');
            }

            $stmt = $conn->prepare("
                UPDATE suppliers 
                SET supplier_name = ?,
                    contact_person = ?,
                    email = ?,
                    phone = ?,
                    address = ?,
                    status = ?
                WHERE supplier_id = ?
            ");
            $stmt->execute([
                $supplier_name, $contact_person, $email,
                $phone, $address, $status, $supplier_id
            ]);

            log_activity($_SESSION['user_id'], 'SUPPLIER_UPDATED',
                         "Updated supplier: {$existing['supplier_name']} → $supplier_name", $conn);
            set_alert('success', 'Supplier updated successfully!');
            header('Location: Suppliers.php');
            exit();
        }

        // ----------------------------------------
        // DELETE / DEACTIVATE SUPPLIER
        // ----------------------------------------
        if ($_POST['action'] == 'delete') {
            $supplier_id = intval($_POST['supplier_id'] ?? 0);
            if ($supplier_id <= 0) {
                throw new Exception('Invalid supplier.');
            }

            $stmt = $conn->prepare("UPDATE suppliers SET status = 'inactive' WHERE supplier_id = ?");
            $stmt->execute([$supplier_id]);

            log_activity($_SESSION['user_id'], 'SUPPLIER_DEACTIVATED',
                         "Deactivated supplier ID: $supplier_id", $conn);
            set_alert('success', 'Supplier deactivated.');
            header('Location: Suppliers.php');
            exit();
        }

    } catch (Exception $e) {
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
}

// ============================================
// FETCH SUPPLIERS
// ============================================
$stmt = $conn->prepare("
    SELECT * FROM suppliers 
    WHERE status = 'active' 
    ORDER BY supplier_name
");
$stmt->execute();
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <button class="btn btn-link d-md-none sidebar-toggle">
            <i class="fas fa-bars"></i>
        </button>
        <h5 class="mb-0"><i class="fas fa-truck me-2"></i>Suppliers</h5>
    </div>

    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fas fa-list me-2"></i>All Suppliers (<?php echo count($suppliers); ?>)</span>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-plus me-1"></i>Add Supplier
                </button>
            </div>
            <div class="card-body">
                
                <?php if (empty($suppliers)): ?>
                    <!-- Empty State -->
                    <div class="text-center py-5">
                        <i class="fas fa-truck fa-4x text-muted mb-3" style="opacity:0.2;"></i>
                        <h4 class="text-muted mb-2">No Suppliers Added Yet</h4>
                        <p class="text-muted mb-4" style="font-size:0.9rem;">You haven't added any suppliers. Add one to start purchasing stock.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                            <i class="fas fa-plus me-1"></i>Add Your First Supplier
                        </button>
                    </div>
                <?php else: ?>
                    <!-- Suppliers Table -->
                    <div class="table-responsive">
                        <table class="table table-hover" id="suppliersTable">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Contact Person</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($suppliers as $sup): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($sup['supplier_code']); ?></td>
                                    <td><?php echo htmlspecialchars($sup['supplier_name']); ?></td>
                                    <td><?php echo htmlspecialchars($sup['contact_person'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($sup['email'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($sup['phone'] ?? ''); ?></td>
                                    <td>
                                        <button type="button"
                                                class="btn btn-sm btn-primary edit-btn"
                                                data-id="<?php echo (int) $sup['supplier_id']; ?>"
                                                data-code="<?php echo htmlspecialchars($sup['supplier_code']); ?>"
                                                data-name="<?php echo htmlspecialchars($sup['supplier_name']); ?>"
                                                data-contact="<?php echo htmlspecialchars($sup['contact_person'] ?? ''); ?>"
                                                data-email="<?php echo htmlspecialchars($sup['email'] ?? ''); ?>"
                                                data-phone="<?php echo htmlspecialchars($sup['phone'] ?? ''); ?>"
                                                data-address="<?php echo htmlspecialchars($sup['address'] ?? ''); ?>"
                                                data-status="<?php echo htmlspecialchars($sup['status'] ?? 'active'); ?>"
                                                title="Edit Supplier">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <form method="POST" style="display:inline-block;"
                                              onsubmit="return confirm('Deactivate this supplier?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="supplier_id" value="<?php echo (int) $sup['supplier_id']; ?>">
                                            <button type="submit" name="action" value="delete"
                                                    class="btn btn-sm btn-danger" title="Deactivate Supplier">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
     ADD SUPPLIER MODAL
     ============================================ -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Supplier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Supplier Name *</label>
                        <input type="text" name="supplier_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="add" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================
     EDIT SUPPLIER MODAL
     ============================================ -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Supplier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="supplier_id" id="edit_supplier_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Supplier Code</label>
                        <input type="text" id="edit_supplier_code" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Supplier Name *</label>
                        <input type="text" name="supplier_name" id="edit_supplier_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" id="edit_contact_person" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="edit_email" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" id="edit_phone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" id="edit_address" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="edit_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="edit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
jQuery(document).ready(function($) {
    // ============================================
    // SAFE DATATABLE INIT
    // ============================================
    setTimeout(function() {
        var tableSelector = '#suppliersTable';
        var $table = $(tableSelector);
        
        if ($table.length > 0) {
            if (!$.fn.DataTable.isDataTable(tableSelector)) {
                $table.DataTable({
                    pageLength: 25,
                    responsive: false,
                    order: [],
                    language: {
                        search: "", searchPlaceholder: "Search suppliers...",
                        lengthMenu: "Show _MENU_",
                        info: "_START_–_END_ of _TOTAL_",
                        infoEmpty: "No entries",
                        infoFiltered: "(filtered from _MAX_)",
                        paginate: {
                            first: '<i class="fas fa-angle-double-left"></i>',
                            last: '<i class="fas fa-angle-double-right"></i>',
                            next: '<i class="fas fa-angle-right"></i>',
                            previous: '<i class="fas fa-angle-left"></i>'
                        }
                    }
                });
            }
        }
    }, 200);

    // Handle edit button click
    $(document).on('click', '.edit-btn', function() {
        const btn = $(this);

        $('#edit_supplier_id').val(btn.data('id'));
        $('#edit_supplier_code').val(btn.data('code') || '');
        $('#edit_supplier_name').val(btn.data('name') || '');
        $('#edit_contact_person').val(btn.data('contact') || '');
        $('#edit_email').val(btn.data('email') || '');
        $('#edit_phone').val(btn.data('phone') || '');
        $('#edit_address').val(btn.data('address') || '');
        $('#edit_status').val(btn.data('status') || 'active');

        $('#editModal').modal('show');
    });

    $('#editModal').on('hidden.bs.modal', function() {
        $('#editForm')[0].reset();
    });
});
</script>