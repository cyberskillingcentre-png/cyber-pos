<?php
require_once '../config/config.php';
require_admin();

$page_title = "Customers Management";

// Handle operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    csrf_require();
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] == 'add') {
                $customer_code = 'CUST' . rand(1000, 9999);
                $customer_name = sanitize_input($_POST['customer_name']);
                $email = sanitize_input($_POST['email']);
                $phone = sanitize_input($_POST['phone']);
                $address = sanitize_input($_POST['address']);
                $customer_type = $_POST['customer_type'];
                $discount_percentage = floatval($_POST['discount_percentage']);
                
                $stmt = $conn->prepare("INSERT INTO customers (customer_code, customer_name, email, phone, address, customer_type, discount_percentage) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$customer_code, $customer_name, $email, $phone, $address, $customer_type, $discount_percentage]);
                
                log_activity($_SESSION['user_id'], 'CUSTOMER_CREATED', "Created customer: $customer_name", $conn);
                set_alert('success', 'Customer added successfully!');
                
            } elseif ($_POST['action'] == 'edit') {
                $customer_id = intval($_POST['customer_id']);
                $customer_name = sanitize_input($_POST['customer_name']);
                $email = sanitize_input($_POST['email']);
                $phone = sanitize_input($_POST['phone']);
                $address = sanitize_input($_POST['address']);
                $customer_type = $_POST['customer_type'];
                $discount_percentage = floatval($_POST['discount_percentage']);
                
                $stmt = $conn->prepare("UPDATE customers SET customer_name = ?, email = ?, phone = ?, address = ?, customer_type = ?, discount_percentage = ? WHERE customer_id = ?");
                $stmt->execute([$customer_name, $email, $phone, $address, $customer_type, $discount_percentage, $customer_id]);
                
                log_activity($_SESSION['user_id'], 'CUSTOMER_UPDATED', "Updated customer: $customer_name", $conn);
                set_alert('success', 'Customer updated successfully!');
                
            } elseif ($_POST['action'] == 'delete') {
                $customer_id = intval($_POST['customer_id']);
                $stmt = $conn->prepare("UPDATE customers SET status = 'inactive' WHERE customer_id = ?");
                $stmt->execute([$customer_id]);
                
                log_activity($_SESSION['user_id'], 'CUSTOMER_DELETED', "Deleted customer ID: $customer_id", $conn);
                set_alert('success', 'Customer deleted successfully!');
            }
            
            header('Location: Customers');
            exit();
        } catch(PDOException $e) {
            set_alert('danger', 'Error: ' . $e->getMessage());
        }
    }
}

$stmt = $conn->prepare("SELECT * FROM customers WHERE status = 'active' ORDER BY customer_name");
$stmt->execute();
$customers = $stmt->fetchAll();
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
         <button class="btn btn-link d-md-none sidebar-toggle">
                    <i class="fas fa-bars"></i>
                </button>
        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Customers Management</h5>
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
                <span><i class="fas fa-list me-2"></i>All Customers (<?php echo count($customers); ?>)</span>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                    <i class="fas fa-plus me-1"></i>Add Customer
                </button>
            </div>
            <div class="card-body">
                
                <?php if (empty($customers)): ?>
                    <!-- Empty State -->
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-4x text-muted mb-3" style="opacity:0.2;"></i>
                        <h4 class="text-muted mb-2">No Customers Added Yet</h4>
                        <p class="text-muted mb-4" style="font-size:0.9rem;">Your customer list is empty. Add your first customer to start tracking purchases.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                            <i class="fas fa-plus me-1"></i>Add Your First Customer
                        </button>
                    </div>
                <?php else: ?>
                    <!-- Customers Table -->
                    <div class="table-responsive">
                        <table class="table table-hover" id="customersTable">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Type</th>
                                    <th>Discount %</th>
                                    <th>Total Purchases</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($customer['customer_code']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['email'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></td>
                                    <td><span class="badge bg-info"><?php echo ucfirst($customer['customer_type']); ?></span></td>
                                    <td><?php echo htmlspecialchars($customer['discount_percentage']); ?>%</td>
                                    <td>UGX <?php echo number_format($customer['total_purchases'], 0); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary edit-btn" 
                                                data-id="<?php echo $customer['customer_id']; ?>"
                                                data-code="<?php echo htmlspecialchars($customer['customer_code']); ?>"
                                                data-name="<?php echo htmlspecialchars($customer['customer_name']); ?>"
                                                data-email="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>"
                                                data-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                                                data-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                                data-type="<?php echo htmlspecialchars($customer['customer_type']); ?>"
                                                data-discount="<?php echo htmlspecialchars($customer['discount_percentage']); ?>"
                                                title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-info view-btn" 
                                                data-id="<?php echo $customer['customer_id']; ?>"
                                                data-code="<?php echo htmlspecialchars($customer['customer_code']); ?>"
                                                data-name="<?php echo htmlspecialchars($customer['customer_name']); ?>"
                                                data-email="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>"
                                                data-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                                                data-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                                data-type="<?php echo htmlspecialchars($customer['customer_type']); ?>"
                                                data-discount="<?php echo htmlspecialchars($customer['discount_percentage']); ?>"
                                                data-purchases="<?php echo htmlspecialchars($customer['total_purchases']); ?>"
                                                data-created="<?php echo date('d/m/Y H:i', strtotime($customer['created_at'])); ?>"
                                                title="View">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <form method="POST" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="customer_id" value="<?php echo $customer['customer_id']; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this customer?');" title="Delete">
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

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Customer Name *</label>
                        <input type="text" name="customer_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone *</label>
                        <input type="text" name="phone" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Customer Type</label>
                        <select name="customer_type" class="form-select">
                            <option value="regular">Regular</option>
                            <option value="wholesale">Wholesale</option>
                            <option value="vip">VIP</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discount %</label>
                        <input type="number" name="discount_percentage" class="form-control" step="0.01" value="0" min="0" max="100">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="add" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save Customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="customer_id" id="edit_customer_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Customer Code</label>
                        <input type="text" id="edit_customer_code" class="form-control" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Customer Name *</label>
                        <input type="text" name="customer_name" id="edit_customer_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="edit_email" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone *</label>
                        <input type="text" name="phone" id="edit_phone" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" id="edit_address" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Customer Type</label>
                        <select name="customer_type" id="edit_customer_type" class="form-select">
                            <option value="regular">Regular</option>
                            <option value="wholesale">Wholesale</option>
                            <option value="vip">VIP</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discount %</label>
                        <input type="number" name="discount_percentage" id="edit_discount_percentage" class="form-control" step="0.01" min="0" max="100">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="edit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update Customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Customer Modal -->
<div class="modal fade" id="viewCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-user me-2"></i>Customer Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-6"><strong>Customer Code:</strong></div>
                    <div class="col-6"><span id="view_customer_code"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Name:</strong></div>
                    <div class="col-6"><span id="view_customer_name"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Email:</strong></div>
                    <div class="col-6"><span id="view_email"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Phone:</strong></div>
                    <div class="col-6"><span id="view_phone"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Address:</strong></div>
                    <div class="col-6"><span id="view_address"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Customer Type:</strong></div>
                    <div class="col-6"><span id="view_customer_type"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Discount:</strong></div>
                    <div class="col-6"><span id="view_discount"></span>%</div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Total Purchases:</strong></div>
                    <div class="col-6"><span class="text-success"><strong>UGX <span id="view_purchases"></span></strong></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Created On:</strong></div>
                    <div class="col-6"><span id="view_created"></span></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
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
        var tableSelector = '#customersTable';
        var $table = $(tableSelector);
        
        if ($table.length > 0) {
            if (!$.fn.DataTable.isDataTable(tableSelector)) {
                $table.DataTable({
                    pageLength: 25,
                    responsive: false,
                    order: [],
                    language: {
                        search: "", searchPlaceholder: "Search customers...",
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
    $('.edit-btn').on('click', function() {
        const btn = $(this);
        
        $('#edit_customer_id').val(btn.data('id'));
        $('#edit_customer_code').val(btn.data('code'));
        $('#edit_customer_name').val(btn.data('name'));
        $('#edit_email').val(btn.data('email'));
        $('#edit_phone').val(btn.data('phone'));
        $('#edit_address').val(btn.data('address'));
        $('#edit_customer_type').val(btn.data('type'));
        $('#edit_discount_percentage').val(btn.data('discount'));
        
        $('#editCustomerModal').modal('show');
    });
    
    // Handle view button click
    $('.view-btn').on('click', function() {
        const btn = $(this);
        
        $('#view_customer_code').text(btn.data('code'));
        $('#view_customer_name').text(btn.data('name'));
        $('#view_email').text(btn.data('email') || 'N/A');
        $('#view_phone').text(btn.data('phone'));
        $('#view_address').text(btn.data('address') || 'N/A');
        $('#view_customer_type').html('<span class="badge bg-info">' + btn.data('type').charAt(0).toUpperCase() + btn.data('type').slice(1) + '</span>');
        $('#view_discount').text(btn.data('discount'));
        $('#view_purchases').text(parseFloat(btn.data('purchases')).toLocaleString());
        $('#view_created').text(btn.data('created'));
        
        $('#viewCustomerModal').modal('show');
    });
});
</script>