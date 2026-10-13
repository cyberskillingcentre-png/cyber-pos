<?php
require_once '../config/config.php';
require_admin();

$page_title = "Purchase Management";

// Handle Add/Edit/Delete/Receive/Payment
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    csrf_require();
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] == 'add') {
                $conn->beginTransaction();
                
                $supplier_id = intval($_POST['supplier_id']);
                $purchase_date = $_POST['purchase_date'];
                $payment_method = $_POST['payment_method'];
                $paid_amount = floatval($_POST['paid_amount']);
                $notes = sanitize_input($_POST['notes']);
                $products = json_decode($_POST['products'], true);
                
                // Calculate total
                $total_amount = 0;
                foreach ($products as $product) {
                    $total_amount += $product['quantity'] * $product['unit_cost'];
                }
                
                // Determine payment status
                if ($paid_amount >= $total_amount) {
                    $payment_status = 'paid';
                } elseif ($paid_amount > 0) {
                    $payment_status = 'partial';
                } else {
                    $payment_status = 'unpaid';
                }
                
                // Generate purchase number
                $purchase_number = 'PO' . date('Ymd') . rand(1000, 9999);
                
                // Insert purchase
                $stmt = $conn->prepare("INSERT INTO purchases (purchase_number, supplier_id, purchase_date, total_amount, paid_amount, payment_status, payment_method, notes, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([$purchase_number, $supplier_id, $purchase_date, $total_amount, $paid_amount, $payment_status, $payment_method, $notes, $_SESSION['user_id']]);
                $purchase_id = $conn->lastInsertId();
                
                // Insert purchase items
                foreach ($products as $product) {
                    $total_cost = $product['quantity'] * $product['unit_cost'];
                    $stmt = $conn->prepare("INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_cost, total_cost) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$purchase_id, $product['product_id'], $product['quantity'], $product['unit_cost'], $total_cost]);
                }
                
                // If payment was made during creation, record it
                if ($paid_amount > 0) {
                    $reference_number = 'PAY' . date('Ymd') . rand(1000, 9999);
                    $stmt = $conn->prepare("INSERT INTO supplier_payments (purchase_id, supplier_id, payment_amount, payment_date, payment_method, reference_number, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$purchase_id, $supplier_id, $paid_amount, $purchase_date, $payment_method, $reference_number, "Initial payment during purchase creation", $_SESSION['user_id']]);
                }
                
                log_activity($_SESSION['user_id'], 'PURCHASE_CREATED', "Created purchase order: $purchase_number", $conn);
                
                $conn->commit();
                set_alert('success', 'Purchase order created successfully! PO#: ' . $purchase_number);
                header('Location: Stock_Purchases');
                exit();
                
            } elseif ($_POST['action'] == 'receive') {
                $conn->beginTransaction();
                
                $purchase_id = intval($_POST['purchase_id']);
                $store_id = intval($_POST['store_id']);
                $received_items = json_decode($_POST['received_items'], true);
                
                // Get purchase details
                $stmt = $conn->prepare("SELECT * FROM purchases WHERE purchase_id = ?");
                $stmt->execute([$purchase_id]);
                $purchase = $stmt->fetch();
                
                // Process each received item
                foreach ($received_items as $item) {
                    $product_id = $item['product_id'];
                    $quantity = $item['quantity'];
                    $batch_number = $item['batch_number'];
                    $expiry_date = !empty($item['expiry_date']) ? $item['expiry_date'] : null;
                    
                    // Add to inventory
                    $stmt = $conn->prepare("INSERT INTO store_inventory (store_id, product_id, batch_number, quantity, expiry_date) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$store_id, $product_id, $batch_number, $quantity, $expiry_date]);
                    
                    // Update received quantity in purchase items
                    $stmt = $conn->prepare("UPDATE purchase_items SET received_quantity = received_quantity + ? WHERE purchase_id = ? AND product_id = ?");
                    $stmt->execute([$quantity, $purchase_id, $product_id]);
                }
                
                // Update purchase status to received
                $stmt = $conn->prepare("UPDATE purchases SET status = 'received' WHERE purchase_id = ?");
                $stmt->execute([$purchase_id]);
                
                log_activity($_SESSION['user_id'], 'PURCHASE_RECEIVED', "Received purchase order: " . $purchase['purchase_number'], $conn);
                
                $conn->commit();
                set_alert('success', 'Purchase received and added to inventory!');
                header('Location: Stock_Purchases');
                exit();
                
            } elseif ($_POST['action'] == 'delete') {
                $purchase_id = intval($_POST['purchase_id']);
                
                $stmt = $conn->prepare("UPDATE purchases SET status = 'cancelled' WHERE purchase_id = ?");
                $stmt->execute([$purchase_id]);
                
                log_activity($_SESSION['user_id'], 'PURCHASE_CANCELLED', "Cancelled purchase ID: $purchase_id", $conn);
                set_alert('success', 'Purchase order cancelled');
                header('Location: Stock_Purchases');
                exit();
                
            } elseif ($_POST['action'] == 'make_payment') {
                $conn->beginTransaction();
                
                $purchase_id = intval($_POST['purchase_id']);
                $payment_amount = floatval($_POST['payment_amount']);
                $payment_method = $_POST['payment_method'];
                $payment_date = $_POST['payment_date'];
                $reference_number = sanitize_input($_POST['reference_number']);
                $notes = sanitize_input($_POST['notes']);
                
                // Get purchase details
                $stmt = $conn->prepare("SELECT * FROM purchases WHERE purchase_id = ?");
                $stmt->execute([$purchase_id]);
                $purchase = $stmt->fetch();
                
                if (!$purchase) {
                    throw new Exception("Purchase not found!");
                }
                
                // Calculate new paid amount
                $new_paid_amount = $purchase['paid_amount'] + $payment_amount;
                $balance = $purchase['total_amount'] - $new_paid_amount;
                
                // Determine new payment status
                if ($new_paid_amount >= $purchase['total_amount']) {
                    $new_payment_status = 'paid';
                } elseif ($new_paid_amount > 0) {
                    $new_payment_status = 'partial';
                } else {
                    $new_payment_status = 'unpaid';
                }
                
                // Record payment
                if (empty($reference_number)) {
                    $reference_number = 'PAY' . date('Ymd') . rand(1000, 9999);
                }
                
                $stmt = $conn->prepare("INSERT INTO supplier_payments (purchase_id, supplier_id, payment_amount, payment_date, payment_method, reference_number, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$purchase_id, $purchase['supplier_id'], $payment_amount, $payment_date, $payment_method, $reference_number, $notes, $_SESSION['user_id']]);
                
                // Update purchase
                $stmt = $conn->prepare("UPDATE purchases SET paid_amount = ?, payment_status = ? WHERE purchase_id = ?");
                $stmt->execute([$new_paid_amount, $new_payment_status, $purchase_id]);
                
                log_activity($_SESSION['user_id'], 'SUPPLIER_PAYMENT', "Made payment of UGX " . number_format($payment_amount, 2) . " for PO: " . $purchase['purchase_number'], $conn);
                
                $conn->commit();
                set_alert('success', 'Payment recorded successfully! Balance: UGX ' . number_format($balance, 2));
                header('Location: Stock_Purchases');
                exit();
            }
            
        } catch(Exception $e) {
            if (isset($conn)) $conn->rollBack();
            set_alert('danger', 'Error: ' . $e->getMessage());
        }
    }
}

// Fetch all purchases
$stmt = $conn->prepare("
    SELECT 
        p.*,
        s.supplier_name,
        u.full_name as created_by_name
    FROM purchases p
    LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
    LEFT JOIN users u ON p.created_by = u.user_id
    WHERE p.status != 'cancelled'
    ORDER BY p.created_at DESC
");
$stmt->execute();
$purchases = $stmt->fetchAll();

// Fetch suppliers
$stmt = $conn->prepare("SELECT * FROM suppliers WHERE status = 'active' ORDER BY supplier_name");
$stmt->execute();
$suppliers = $stmt->fetchAll();

// Fetch products
$stmt = $conn->prepare("SELECT product_id, product_code, product_name, purchase_price, has_expiry FROM products WHERE status = 'active' ORDER BY product_name");
$stmt->execute();
$products = $stmt->fetchAll();

// Fetch stores
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
        <h5 class="mb-0"><i class="fas fa-shopping-cart me-2"></i>Purchase Management</h5>
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
                <span><i class="fas fa-list me-2"></i>Purchase Orders</span>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPurchaseModal">
                    <i class="fas fa-plus me-1"></i>New Purchase Order
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datatable table-hover">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Date</th>
                                <th>Supplier</th>
                                <th>Total Amount</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Payment Status</th>
                                <th>Order Status</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purchases as $purchase): 
                                $balance = $purchase['total_amount'] - $purchase['paid_amount'];
                            ?>
                            <tr>
                                <td><strong><?php echo $purchase['purchase_number']; ?></strong></td>
                                <td><?php echo date('d/m/Y', strtotime($purchase['purchase_date'])); ?></td>
                                <td><?php echo $purchase['supplier_name']; ?></td>
                                <td>UGX <?php echo number_format($purchase['total_amount'], 2); ?></td>
                                <td>UGX <?php echo number_format($purchase['paid_amount'], 2); ?></td>
                                <td>UGX <?php echo number_format($balance, 2); ?></td>
                                <td>
                                    <?php if ($purchase['payment_status'] == 'paid'): ?>
                                        <span class="badge bg-success">Paid</span>
                                    <?php elseif ($purchase['payment_status'] == 'partial'): ?>
                                        <span class="badge bg-warning">Partial</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Unpaid</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($purchase['status'] == 'received'): ?>
                                        <span class="badge bg-success">Received</span>
                                    <?php elseif ($purchase['status'] == 'pending'): ?>
                                        <span class="badge bg-warning">Pending</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Cancelled</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $purchase['created_by_name']; ?></td>
                                <td>
                                    <button class="btn btn-sm btn-info view-btn" 
                                            data-id="<?php echo $purchase['purchase_id']; ?>"
                                            title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <?php if ($balance > 0): ?>
                                    <button class="btn btn-sm btn-warning make-payment-btn" 
                                            data-id="<?php echo $purchase['purchase_id']; ?>"
                                            data-balance="<?php echo $balance; ?>"
                                            title="Make Payment">
                                        <i class="fas fa-money-bill"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($purchase['status'] == 'pending'): ?>
                                    <button class="btn btn-sm btn-success receive-btn" 
                                            data-id="<?php echo $purchase['purchase_id']; ?>"
                                            title="Receive Stock">
                                        <i class="fas fa-box"></i>
                                    </button>
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="purchase_id" value="<?php echo $purchase['purchase_id']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-sm btn-danger" 
                                                onclick="return confirm('Cancel this purchase order?');" 
                                                title="Cancel">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Purchase Modal -->
<div class="modal fade" id="addPurchaseModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>New Purchase Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="purchaseForm">
                <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Supplier *</label>
                            <select name="supplier_id" class="form-select" required>
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['supplier_id']; ?>">
                                    <?php echo $supplier['supplier_name']; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Purchase Date *</label>
                            <input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    
                    <hr>
                    <h6>Products</h6>
                    
                    <div class="mb-3">
                        <button type="button" class="btn btn-sm btn-secondary" id="add-product-row">
                            <i class="fas fa-plus me-1"></i>Add Product
                        </button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered" id="products-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Unit Cost (UGX)</th>
                                    <th>Total (UGX)</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="products-tbody">
                                <!-- Products will be added here -->
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="text-end">Grand Total:</th>
                                    <th><span id="grand-total">0.00</span></th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <hr>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="credit">Credit</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Amount Paid (UGX)</label>
                            <input type="number" name="paid_amount" class="form-control" step="0.01" min="0" value="0">
                            <small class="text-muted">Enter 0 for credit purchase</small>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    
                    <input type="hidden" name="products" id="products-json">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="add" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Create Purchase Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Receive Stock Modal -->
<div class="modal fade" id="receiveStockModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-box me-2"></i>Receive Stock</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="receiveForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="purchase_id" id="receive_purchase_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Receive into Store *</label>
                        <select name="store_id" class="form-select" required>
                            <option value="">Select Store</option>
                            <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['store_id']; ?>">
                                <?php echo $store['store_name']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="receive-items-container"></div>
                    
                    <input type="hidden" name="received_items" id="received-items-json">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="receive" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Receive Stock
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Make Payment Modal -->
<div class="modal fade" id="makePaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title"><i class="fas fa-money-bill me-2"></i>Make Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="paymentForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="purchase_id" id="payment_purchase_id">
                <input type="hidden" name="action" value="make_payment">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Purchase Balance</label>
                        <input type="text" id="payment_balance" class="form-control" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Amount (UGX) *</label>
                        <input type="number" name="payment_amount" id="payment_amount" class="form-control" step="0.01" min="0.01" required>
                        <small class="text-muted">Enter amount to pay</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">New Balance</label>
                        <input type="text" id="new_balance" class="form-control" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Date *</label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Method *</label>
                        <select name="payment_method" class="form-select" required>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="check">Check</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reference Number</label>
                        <input type="text" name="reference_number" class="form-control" placeholder="Optional">
                        <small class="text-muted">e.g., Transaction ID, Check Number</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Payment notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-check me-1"></i>Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Purchase Details Modal -->
<div class="modal fade" id="viewPurchaseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Purchase Order Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="purchase-details-container">
                <!-- Details will be loaded here via AJAX -->
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
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
    let productIndex = 0;
    let productsData = [];
    
    // Add product row
    $('#add-product-row').on('click', function() {
        const row = `
            <tr data-index="${productIndex}">
                <td>
                    <select class="form-select product-select" data-index="${productIndex}" required>
                        <option value="">Select Product</option>
                        <?php foreach ($products as $product): ?>
                        <option value="<?php echo $product['product_id']; ?>" 
                                data-cost="<?php echo $product['purchase_price']; ?>">
                            <?php echo $product['product_code']; ?> - <?php echo $product['product_name']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <input type="number" class="form-control quantity-input" data-index="${productIndex}" min="1" value="1" required>
                </td>
                <td>
                    <input type="number" class="form-control cost-input" data-index="${productIndex}" step="0.01" min="0" required>
                </td>
                <td class="total-cell" data-index="${productIndex}">0.00</td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger remove-row" data-index="${productIndex}">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
        $('#products-tbody').append(row);
        productsData[productIndex] = { product_id: null, quantity: 1, unit_cost: 0 };
        productIndex++;
    });
    
    // Product selection
    $(document).on('change', '.product-select', function() {
        const index = $(this).data('index');
        const productId = $(this).val();
        const cost = $(this).find(':selected').data('cost');
        
        $(`input.cost-input[data-index="${index}"]`).val(cost);
        
        productsData[index] = productsData[index] || {};
        productsData[index].product_id = productId;
        productsData[index].unit_cost = cost;
        
        calculateRowTotal(index);
    });
    
    // Quantity change
    $(document).on('input', '.quantity-input', function() {
        const index = $(this).data('index');
        const quantity = $(this).val();
        
        productsData[index] = productsData[index] || {};
        productsData[index].quantity = quantity;
        
        calculateRowTotal(index);
    });
    
    // Cost change
    $(document).on('input', '.cost-input', function() {
        const index = $(this).data('index');
        const cost = $(this).val();
        
        productsData[index] = productsData[index] || {};
        productsData[index].unit_cost = cost;
        
        calculateRowTotal(index);
    });
    
    // Remove row
    $(document).on('click', '.remove-row', function() {
        const index = $(this).data('index');
        $(`tr[data-index="${index}"]`).remove();
        delete productsData[index];
        calculateGrandTotal();
    });
    
    // Calculate row total
    function calculateRowTotal(index) {
        const product = productsData[index];
        if (product && product.quantity && product.unit_cost) {
            const total = product.quantity * product.unit_cost;
            $(`.total-cell[data-index="${index}"]`).text(total.toFixed(2));
            calculateGrandTotal();
        }
    }
    
    // Calculate grand total
    function calculateGrandTotal() {
        let grandTotal = 0;
        productsData.forEach(function(product) {
            if (product && product.quantity && product.unit_cost) {
                grandTotal += product.quantity * product.unit_cost;
            }
        });
        $('#grand-total').text(grandTotal.toFixed(2));
    }
    
    // Form submission
    $('#purchaseForm').on('submit', function(e) {
        const validProducts = productsData.filter(p => p && p.product_id && p.quantity && p.unit_cost);
        
        if (validProducts.length === 0) {
            e.preventDefault();
            alert('Please add at least one product');
            return false;
        }
        
        $('#products-json').val(JSON.stringify(validProducts));
    });
    
    // Receive stock button
    $('.receive-btn').on('click', function() {
        const purchaseId = $(this).data('id');
        
        // Load purchase items via AJAX
        $.ajax({
            url: '../includes/ajax_purchase_items.php',
            method: 'POST',
            data: { purchase_id: purchaseId },
            dataType: 'json',
            success: function(items) {
                let html = '';
                items.forEach(function(item) {
                    const hasExpiry = item.has_expiry === 'yes';
                    html += `
                        <div class="card mb-3">
                            <div class="card-body">
                                <h6>${item.product_name} (${item.product_code})</h6>
                                <div class="row">
                                    <div class="col-md-4">
                                        <label>Quantity Ordered</label>
                                        <input type="number" class="form-control" value="${item.quantity}" readonly>
                                    </div>
                                    <div class="col-md-4">
                                        <label>Quantity Receiving *</label>
                                        <input type="number" class="form-control receive-qty" 
                                               data-product="${item.product_id}" 
                                               min="1" max="${item.quantity}" value="${item.quantity}" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label>Batch Number *</label>
                                        <input type="text" class="form-control receive-batch" 
                                               data-product="${item.product_id}" 
                                               value="BATCH<?php echo date('Ymd'); ?><?php echo rand(100, 999); ?>" required>
                                    </div>
                                    ${hasExpiry ? `
                                    <div class="col-md-4 mt-2">
                                        <label>Expiry Date</label>
                                        <input type="date" class="form-control receive-expiry" 
                                               data-product="${item.product_id}">
                                    </div>` : ''}
                                </div>
                            </div>
                        </div>
                    `;
                });
                $('#receive-items-container').html(html);
                $('#receive_purchase_id').val(purchaseId);
                $('#receiveStockModal').modal('show');
            }
        });
    });
    
    // Receive form submission
    $('#receiveForm').on('submit', function(e) {
        const receivedItems = [];
        
        $('.receive-qty').each(function() {
            const productId = $(this).data('product');
            const quantity = $(this).val();
            const batch = $(`.receive-batch[data-product="${productId}"]`).val();
            const expiry = $(`.receive-expiry[data-product="${productId}"]`).val();
            
            receivedItems.push({
                product_id: productId,
                quantity: quantity,
                batch_number: batch,
                expiry_date: expiry
            });
        });
        
        $('#received-items-json').val(JSON.stringify(receivedItems));
    });
    
    // Make payment button
    $('.make-payment-btn').on('click', function() {
        const purchaseId = $(this).data('id');
        const balance = $(this).data('balance');
        
        $('#payment_purchase_id').val(purchaseId);
        $('#payment_balance').val(formatCurrency(balance));
        $('#payment_amount').val(balance);
        $('#payment_amount').attr('max', balance);
        calculateNewBalance();
        
        $('#makePaymentModal').modal('show');
    });
    
    // Calculate new balance when payment amount changes
    $('#payment_amount').on('input', function() {
        calculateNewBalance();
    });
    
    function calculateNewBalance() {
        const balance = parseFloat($('#payment_balance').val().replace(/[^0-9.-]+/g,""));
        const paymentAmount = parseFloat($('#payment_amount').val()) || 0;
        const newBalance = balance - paymentAmount;
        
        $('#new_balance').val(formatCurrency(newBalance));
        
        // Update payment status colors
        if (newBalance <= 0) {
            $('#new_balance').removeClass('text-danger text-warning').addClass('text-success');
        } else if (paymentAmount > 0) {
            $('#new_balance').removeClass('text-danger text-success').addClass('text-warning');
        } else {
            $('#new_balance').removeClass('text-warning text-success').addClass('text-danger');
        }
    }
    
    function formatCurrency(amount) {
        return 'UGX ' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
    }
    
    // View purchase details
    $('.view-btn').on('click', function() {
        const purchaseId = $(this).data('id');
        
        // Load purchase details via AJAX
        $.ajax({
            url: '../includes/ajax_purchase_details.php',
            method: 'POST',
            data: { purchase_id: purchaseId },
            success: function(html) {
                $('#purchase-details-container').html(html);
                $('#viewPurchaseModal').modal('show');
            },
            error: function() {
                $('#purchase-details-container').html(
                    '<div class="alert alert-danger">Error loading purchase details. Please try again.</div>'
                );
            }
        });
    });
});
</script>