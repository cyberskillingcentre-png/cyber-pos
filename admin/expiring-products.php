<?php
require_once '../config/config.php';
require_admin();

$page_title = "Expiring Products Management";

// Handle stock transfer
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    csrf_require();
    if ($_POST['action'] == 'transfer') {
        try {
            $conn->beginTransaction();
            
            $inventory_id = intval($_POST['inventory_id']);
            $from_store_id = intval($_POST['from_store_id']);
            $to_store_id = intval($_POST['to_store_id']);
            $product_id = intval($_POST['product_id']);
            $quantity = intval($_POST['quantity']);
            $reason = "Expiry management - Transfer to faster-moving location";
            
            // Validate stores are different
            if ($from_store_id == $to_store_id) {
                throw new Exception("Source and destination stores cannot be the same!");
            }
            
            // Check available stock
            $stmt = $conn->prepare("SELECT quantity, batch_number, expiry_date FROM store_inventory WHERE inventory_id = ?");
            $stmt->execute([$inventory_id]);
            $inventory = $stmt->fetch();
            
            if (!$inventory || $inventory['quantity'] < $quantity) {
                throw new Exception("Insufficient stock! Available: " . ($inventory['quantity'] ?? 0));
            }
            
            // Generate transfer number
            $transfer_number = 'TRF' . date('Ymd') . rand(1000, 9999);
            
            // Record transfer
            $stmt = $conn->prepare("INSERT INTO stock_transfers (transfer_number, product_id, from_store_id, to_store_id, quantity, transfer_date, reason, transferred_by, status) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, 'completed')");
            $stmt->execute([$transfer_number, $product_id, $from_store_id, $to_store_id, $quantity, $reason, $_SESSION['user_id']]);
            
            // Deduct from source
            $stmt = $conn->prepare("UPDATE store_inventory SET quantity = quantity - ? WHERE inventory_id = ?");
            $stmt->execute([$quantity, $inventory_id]);
            
            // Add to destination (same batch)
            $new_batch = $inventory['batch_number'] . '-TRF' . substr($transfer_number, -4);
            
            $stmt = $conn->prepare("SELECT inventory_id FROM store_inventory WHERE store_id = ? AND product_id = ? AND batch_number = ?");
            $stmt->execute([$to_store_id, $product_id, $new_batch]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                $stmt = $conn->prepare("UPDATE store_inventory SET quantity = quantity + ? WHERE inventory_id = ?");
                $stmt->execute([$quantity, $existing['inventory_id']]);
            } else {
                $stmt = $conn->prepare("INSERT INTO store_inventory (store_id, product_id, batch_number, quantity, expiry_date) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$to_store_id, $product_id, $new_batch, $quantity, $inventory['expiry_date']]);
            }
            
            log_activity($_SESSION['user_id'], 'EXPIRY_TRANSFER', "Transferred $quantity units - $transfer_number", $conn);
            
            $conn->commit();
            set_alert('success', "Stock transferred successfully! Transfer #: $transfer_number");
            header('Location: expiring-products.php');
            exit();
            
        } catch(Exception $e) {
            if (isset($conn)) $conn->rollBack();
            set_alert('danger', 'Error: ' . $e->getMessage());
        }
    }
}

// Get expiring products (within 30 days)
$stmt = $conn->prepare("
    SELECT 
        si.inventory_id,
        si.batch_number,
        si.quantity,
        si.expiry_date,
        DATEDIFF(si.expiry_date, CURDATE()) as days_to_expiry,
        p.product_id,
        p.product_code,
        p.product_name,
        c.category_name,
        s.store_id,
        s.store_name,
        p.purchase_price,
        p.selling_price,
        (si.quantity * p.purchase_price) as value_at_risk,
        CASE 
            WHEN DATEDIFF(si.expiry_date, CURDATE()) <= 7 THEN 'Critical'
            WHEN DATEDIFF(si.expiry_date, CURDATE()) <= 15 THEN 'Urgent'
            WHEN DATEDIFF(si.expiry_date, CURDATE()) <= 30 THEN 'Warning'
            ELSE 'Monitor'
        END as urgency_level
    FROM store_inventory si
    JOIN products p ON si.product_id = p.product_id
    JOIN stores s ON si.store_id = s.store_id
    LEFT JOIN categories c ON p.category_id = c.category_id
    WHERE p.has_expiry = 'yes' 
    AND si.expiry_date IS NOT NULL
    AND si.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    AND si.quantity > 0
    ORDER BY si.expiry_date ASC
");
$stmt->execute();
$expiring_products = $stmt->fetchAll();

// Get all stores for transfer dropdown
$stmt = $conn->prepare("SELECT * FROM stores WHERE status = 'active' ORDER BY store_name");
$stmt->execute();
$stores = $stmt->fetchAll();

// Calculate summary
$critical = 0;
$urgent = 0;
$warning = 0;
$total_value = 0;

foreach ($expiring_products as $product) {
    if ($product['urgency_level'] == 'Critical') $critical++;
    elseif ($product['urgency_level'] == 'Urgent') $urgent++;
    else $warning++;
    $total_value += $product['value_at_risk'];
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
         <button class="btn btn-link d-md-none sidebar-toggle">
                    <i class="fas fa-bars"></i>
                </button>
        <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Expiring Products Management</h5>
    </div>
    
    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- PERMANENT Critical Alert - NO CLOSE BUTTON -->
        <?php if ($critical > 0): ?>
        <div class="alert alert-danger border-start border-danger border-5" role="alert" style="border-left-width: 5px !important; box-shadow: 0 4px 8px rgba(244, 67, 54, 0.3);">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <i class="fas fa-fire fa-3x text-danger"></i>
                </div>
                <div class="flex-grow-1">
                    <h4 class="alert-heading mb-2" style="font-weight: 700; color: #721c24;">
                        🔥 CRITICAL ALERT: <?php echo $critical; ?> Products Expiring in ≤7 Days!
                    </h4>
                    <p class="mb-0" style="font-size: 1.05rem; color: #721c24;">
                        <strong>Immediate action required!</strong> These products must be transferred to high-velocity stores or sold urgently to prevent losses.
                        <strong>Take action now before it's too late!</strong>
                    </p>
                </div>
            </div>
        </div>
        <?php elseif ($urgent > 0): ?>
        <div class="alert alert-warning border-start border-warning border-5" role="alert" style="border-left-width: 5px !important; box-shadow: 0 4px 8px rgba(255, 152, 0, 0.3);">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <i class="fas fa-exclamation-triangle fa-3x text-warning"></i>
                </div>
                <div class="flex-grow-1">
                    <h5 class="alert-heading mb-2" style="font-weight: 700;">
                        ⚠️ URGENT: <?php echo $urgent; ?> Products Expiring in 8-15 Days
                    </h5>
                    <p class="mb-0" style="font-size: 1.05rem;">
                        <strong>Action needed soon.</strong> Transfer these products to stores with higher sales velocity to move them faster.
                    </p>
                </div>
            </div>
        </div>
        <?php elseif ($warning > 0): ?>
        <div class="alert alert-info border-start border-info border-5" role="alert" style="border-left-width: 5px !important; box-shadow: 0 4px 8px rgba(13, 110, 253, 0.3);">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <i class="fas fa-clock fa-3x text-info"></i>
                </div>
                <div class="flex-grow-1">
                    <h5 class="alert-heading mb-2" style="font-weight: 700;">
                        ℹ️ WARNING: <?php echo $warning; ?> Products Expiring in 16-30 Days
                    </h5>
                    <p class="mb-0" style="font-size: 1.05rem;">
                        Monitor these products and plan transfers if needed.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <h6><i class="fas fa-fire me-2"></i>Critical (≤7 days)</h6>
                        <h3><?php echo $critical; ?></h3>
                        <small>Immediate action required</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h6><i class="fas fa-exclamation me-2"></i>Urgent (8-15 days)</h6>
                        <h3><?php echo $urgent; ?></h3>
                        <small>Transfer or promote</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h6><i class="fas fa-clock me-2"></i>Warning (16-30 days)</h6>
                        <h3><?php echo $warning; ?></h3>
                        <small>Monitor closely</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-dark text-white">
                    <div class="card-body">
                        <h6><i class="fas fa-dollar-sign me-2"></i>Value at Risk</h6>
                        <h3>UGX <?php echo number_format($total_value, 0); ?></h3>
                        <small>Total inventory value</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- PERMANENT Info Alert - NO CLOSE BUTTON -->
        <div class="alert alert-info border-start border-info border-5" role="alert" style="border-left-width: 5px !important;">
            <h5 class="alert-heading"><i class="fas fa-info-circle me-2"></i>Stock Transfer Strategy</h5>
            <p class="mb-0">
                Transfer expiring products to stores with higher sales velocity to move them faster. 
                The system will maintain batch numbers and expiry dates during transfer for full traceability.
            </p>
        </div>
        
        <!-- Expiring Products Table -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-table me-2"></i>Expiring Products (Next 30 Days)
            </div>
            <div class="card-body">
                <?php if (count($expiring_products) > 0): ?>
                <div class="table-responsive">
                    <table class="table datatable table-hover">
                        <thead>
                            <tr>
                                <th>Urgency</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Current Store</th>
                                <th>Batch</th>
                                <th>Quantity</th>
                                <th>Expiry Date</th>
                                <th>Days Left</th>
                                <th>Value</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expiring_products as $product): ?>
                            <tr>
                                <td>
                                    <?php if ($product['urgency_level'] == 'Critical'): ?>
                                        <span class="badge bg-danger">Critical</span>
                                    <?php elseif ($product['urgency_level'] == 'Urgent'): ?>
                                        <span class="badge bg-warning">Urgent</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">Warning</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo $product['product_code']; ?></strong><br>
                                    <small><?php echo $product['product_name']; ?></small>
                                </td>
                                <td><?php echo $product['category_name']; ?></td>
                                <td><span class="badge bg-secondary"><?php echo $product['store_name']; ?></span></td>
                                <td><small><?php echo $product['batch_number']; ?></small></td>
                                <td><strong><?php echo $product['quantity']; ?></strong></td>
                                <td><?php echo date('d/m/Y', strtotime($product['expiry_date'])); ?></td>
                                <td>
                                    <strong class="<?php echo $product['days_to_expiry'] <= 7 ? 'text-danger' : ($product['days_to_expiry'] <= 15 ? 'text-warning' : 'text-info'); ?>">
                                        <?php echo $product['days_to_expiry']; ?> days
                                    </strong>
                                </td>
                                <td>UGX <?php echo number_format($product['value_at_risk'], 0); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-success transfer-btn" 
                                            data-inventory-id="<?php echo $product['inventory_id']; ?>"
                                            data-product-id="<?php echo $product['product_id']; ?>"
                                            data-from-store="<?php echo $product['store_id']; ?>"
                                            data-from-store-name="<?php echo $product['store_name']; ?>"
                                            data-product-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                            data-batch="<?php echo $product['batch_number']; ?>"
                                            data-quantity="<?php echo $product['quantity']; ?>"
                                            data-expiry="<?php echo date('d/m/Y', strtotime($product['expiry_date'])); ?>"
                                            title="Transfer Stock">
                                        <i class="fas fa-exchange-alt"></i> Transfer
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Great!</strong> No products expiring in the next 30 days.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Transfer Stock Modal -->
<div class="modal fade" id="transferModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>Transfer Expiring Stock</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="transfer">
                <input type="hidden" name="inventory_id" id="transfer_inventory_id">
                <input type="hidden" name="product_id" id="transfer_product_id">
                <input type="hidden" name="from_store_id" id="transfer_from_store_id">
                
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <strong><i class="fas fa-info-circle me-2"></i>Product Details:</strong><br>
                        <strong>Product:</strong> <span id="display_product_name"></span><br>
                        <strong>Batch:</strong> <span id="display_batch"></span><br>
                        <strong>Expires:</strong> <span id="display_expiry"></span><br>
                        <strong>Current Location:</strong> <span id="display_from_store"></span>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Transfer To Store *</label>
                        <select name="to_store_id" class="form-select" required>
                            <option value="">-- Select Destination Store --</option>
                            <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['store_id']; ?>"><?php echo $store['store_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Choose a store with higher sales velocity to move stock faster</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Quantity to Transfer *</label>
                        <input type="number" name="quantity" id="transfer_quantity" class="form-control" min="1" required>
                        <small class="text-muted">Available: <strong id="display_available"></strong> units</small>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> The batch number and expiry date will be maintained at the destination store for full traceability.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Confirm Transfer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
jQuery(document).ready(function($) {
    // Transfer button click
    $('.transfer-btn').on('click', function() {
        const btn = $(this);
        
        $('#transfer_inventory_id').val(btn.data('inventory-id'));
        $('#transfer_product_id').val(btn.data('product-id'));
        $('#transfer_from_store_id').val(btn.data('from-store'));
        
        $('#display_product_name').text(btn.data('product-name'));
        $('#display_batch').text(btn.data('batch'));
        $('#display_expiry').text(btn.data('expiry'));
        $('#display_from_store').text(btn.data('from-store-name'));
        $('#display_available').text(btn.data('quantity'));
        
        $('#transfer_quantity').attr('max', btn.data('quantity'));
        $('#transfer_quantity').val(btn.data('quantity'));
        
        // Remove current store from destination dropdown
        $('select[name="to_store_id"]').val('');
        $('select[name="to_store_id"] option[value="' + btn.data('from-store') + '"]').prop('disabled', true);
        
        $('#transferModal').modal('show');
    });
    
    // Reset dropdown when modal closes
    $('#transferModal').on('hidden.bs.modal', function() {
        $('select[name="to_store_id"] option').prop('disabled', false);
    });
});
</script>