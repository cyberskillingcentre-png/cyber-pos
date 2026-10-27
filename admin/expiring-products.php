<?php
require_once '../config/config.php';
require_admin();

$page_title = "Expiring Products Management";

// ============================================
// STORE FILTER
// ============================================
$store_filter = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;

// ============================================
// HANDLE STOCK TRANSFER
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    csrf_require();

    if ($_POST['action'] == 'transfer') {
        try {
            $conn->beginTransaction();

            $inventory_id  = intval($_POST['inventory_id']);
            $from_store_id = intval($_POST['from_store_id']);
            $to_store_id   = intval($_POST['to_store_id']);
            $product_id    = intval($_POST['product_id']);
            $quantity      = intval($_POST['quantity']);

            if ($from_store_id === $to_store_id) {
                throw new Exception("Source and destination stores cannot be the same!");
            }
            if ($quantity <= 0) {
                throw new Exception("Quantity must be greater than zero.");
            }

            // Load destination store type
            $stmt = $conn->prepare("SELECT store_type FROM stores WHERE store_id = ?");
            $stmt->execute([$to_store_id]);
            $dest_type = $stmt->fetchColumn();

            $reason = ($dest_type === 'damages')
                ? "Damaged/Expired — written off to damages store"
                : "Expiry management — Transfer to faster-moving location";

            // Lock source row
            $stmt = $conn->prepare("
                SELECT quantity, batch_number, expiry_date 
                FROM store_inventory 
                WHERE inventory_id = ? 
                FOR UPDATE
            ");
            $stmt->execute([$inventory_id]);
            $inventory = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventory) {
                throw new Exception("Batch not found.");
            }
            if ($inventory['quantity'] < $quantity) {
                throw new Exception("Insufficient stock! Available: " . $inventory['quantity']);
            }

            $transfer_number = 'TRF' . date('Ymd') . rand(1000, 9999);

            // Record the transfer
            $stmt = $conn->prepare("
                INSERT INTO stock_transfers 
                (transfer_number, product_id, from_store_id, to_store_id, quantity, 
                 transfer_date, reason, transferred_by, status) 
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, 'completed')
            ");
            $stmt->execute([
                $transfer_number, $product_id, $from_store_id, $to_store_id,
                $quantity, $reason, $_SESSION['user_id']
            ]);

            // Deduct from source
            $stmt = $conn->prepare("
                UPDATE store_inventory 
                SET quantity = quantity - ? 
                WHERE inventory_id = ?
            ");
            $stmt->execute([$quantity, $inventory_id]);

            // Delete source row if it's now empty
            $stmt = $conn->prepare("
                DELETE FROM store_inventory 
                WHERE inventory_id = ? AND quantity <= 0
            ");
            $stmt->execute([$inventory_id]);

            // Add to destination
            $new_batch = $inventory['batch_number'] . '-TRF' . substr($transfer_number, -4);

            $stmt = $conn->prepare("
                SELECT inventory_id 
                FROM store_inventory 
                WHERE store_id = ? AND product_id = ? AND batch_number = ?
            ");
            $stmt->execute([$to_store_id, $product_id, $new_batch]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $stmt = $conn->prepare("
                    UPDATE store_inventory 
                    SET quantity = quantity + ? 
                    WHERE inventory_id = ?
                ");
                $stmt->execute([$quantity, $existing['inventory_id']]);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO store_inventory 
                    (store_id, product_id, batch_number, quantity, initial_quantity, expiry_date) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $to_store_id, $product_id, $new_batch,
                    $quantity, $quantity, $inventory['expiry_date']
                ]);
            }

            log_activity(
                $_SESSION['user_id'],
                'EXPIRY_TRANSFER',
                "Transferred $quantity units ($transfer_number) from store $from_store_id to $to_store_id" .
                ($dest_type === 'damages' ? " [DAMAGES]" : ""),
                $conn
            );

            $conn->commit();

            $msg = "Stock transferred successfully! Transfer #: $transfer_number";
            if ($dest_type === 'damages') {
                $msg .= " — batch written off to Damages store.";
            }
            set_alert('success', $msg);

            $redirect = 'expiring-products.php';
            if ($store_filter > 0) $redirect .= '?store_id=' . $store_filter;
            header('Location: ' . $redirect);
            exit();

        } catch (Exception $e) {
            if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
            set_alert('danger', 'Error: ' . $e->getMessage());
        }
    }
}

// ============================================
// STORES
// ============================================
// Sales stores only (for filter dropdown)
$stores = $conn->query("
    SELECT store_id, store_name 
    FROM stores 
    WHERE status = 'active' AND store_type = 'sales'
    ORDER BY store_name
")->fetchAll(PDO::FETCH_ASSOC);

// All transfer targets (sales + damages)
$transfer_targets = $conn->query("
    SELECT store_id, store_name, store_type 
    FROM stores 
    WHERE status = 'active' 
    ORDER BY 
        CASE WHEN store_type = 'damages' THEN 0 ELSE 1 END,
        store_name
")->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// EXPIRING QUERY (with filter, sales stores only)
// ============================================
$expiring_sql = "
    SELECT 
        si.inventory_id, si.batch_number, si.quantity, si.expiry_date,
        DATEDIFF(si.expiry_date, CURDATE()) AS days_to_expiry,
        p.product_id, p.product_code, p.product_name,
        c.category_name,
        s.store_id, s.store_name,
        p.purchase_price, p.selling_price,
        (si.quantity * p.purchase_price) AS value_at_risk,
        CASE 
            WHEN DATEDIFF(si.expiry_date, CURDATE()) <= 7  THEN 'Critical'
            WHEN DATEDIFF(si.expiry_date, CURDATE()) <= 15 THEN 'Urgent'
            ELSE 'Warning'
        END AS urgency_level
    FROM store_inventory si
    JOIN products p ON si.product_id = p.product_id
    JOIN stores s   ON si.store_id   = s.store_id
    LEFT JOIN categories c ON p.category_id = c.category_id
    WHERE p.has_expiry = 'yes' 
      AND si.expiry_date IS NOT NULL
      AND si.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
      AND si.quantity > 0
      AND s.store_type = 'sales'
";

$expiring_params = [];
if ($store_filter > 0) {
    $expiring_sql .= " AND si.store_id = ?";
    $expiring_params[] = $store_filter;
}
$expiring_sql .= " ORDER BY si.expiry_date ASC";

$stmt = $conn->prepare($expiring_sql);
$stmt->execute($expiring_params);
$expiring_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// SUMMARY
// ============================================
$critical = 0;
$urgent = 0;
$warning = 0;
$total_value = 0;
foreach ($expiring_products as $product) {
    if ($product['urgency_level'] === 'Critical')   $critical++;
    elseif ($product['urgency_level'] === 'Urgent') $urgent++;
    else                                            $warning++;
    $total_value += $product['value_at_risk'];
}

// Total across all sales stores (for context when filtered)
$all_count = (int) $conn->query("
    SELECT COUNT(DISTINCT si.inventory_id) 
    FROM store_inventory si
    JOIN products p ON si.product_id = p.product_id
    JOIN stores st  ON si.store_id   = st.store_id
    WHERE p.has_expiry = 'yes'
      AND si.expiry_date IS NOT NULL
      AND si.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
      AND si.quantity > 0
      AND st.store_type = 'sales'
")->fetchColumn();

$selected_store_name = 'All Stores';
foreach ($stores as $s) {
    if ((int)$s['store_id'] === $store_filter) {
        $selected_store_name = $s['store_name'];
        break;
    }
}

$alert = get_alert();
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <div class="d-flex justify-content-between align-items-center w-100">
            <div class="d-flex align-items-center">
                <button class="btn btn-link d-md-none sidebar-toggle me-2"><i class="fas fa-bars"></i></button>
                <button type="button" class="btn btn-link sidebar-desktop-toggle me-2" id="sidebarDesktopToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h5 class="mb-0">
                    <i class="fas fa-exclamation-triangle me-2" style="color: #ED8936;"></i>
                    Expiring Products — <?php echo htmlspecialchars($selected_store_name); ?>
                </h5>
            </div>
            <form method="GET" class="d-flex gap-2 align-items-center">
                <select name="store_id" class="form-select form-select-sm" style="min-width:200px;" onchange="this.form.submit()">
                    <option value="0" <?php echo $store_filter === 0 ? 'selected' : ''; ?>>All Stores</option>
                    <?php foreach ($stores as $s): ?>
                        <option value="<?php echo (int)$s['store_id']; ?>"
                                <?php echo $store_filter === (int)$s['store_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['store_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <div class="content-area">
        <?php if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($store_filter > 0): ?>
        <div class="alert alert-info d-flex justify-content-between align-items-center" style="border-left: 4px solid #4299E1;">
            <div>
                <i class="fas fa-filter me-2"></i>
                Showing <strong><?php echo count($expiring_products); ?></strong> expiring batches at
                <strong><?php echo htmlspecialchars($selected_store_name); ?></strong>.
                <?php if ($all_count > count($expiring_products)): ?>
                    <span class="text-muted">
                        (<?php echo $all_count - count($expiring_products); ?> more at other stores)
                    </span>
                <?php endif; ?>
            </div>
            <a href="expiring-products.php" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-times me-1"></i>Show All
            </a>
        </div>
        <?php endif; ?>

        <?php if ($critical > 0): ?>
        <div class="alert alert-danger border-start border-danger border-5" role="alert"
             style="border-left-width: 5px !important;">
            <div class="d-flex align-items-center">
                <div class="me-3"><i class="fas fa-fire fa-3x text-danger"></i></div>
                <div class="flex-grow-1">
                    <h4 class="alert-heading mb-2" style="font-weight: 700; color: #721c24;">
                        🔥 CRITICAL: <?php echo $critical; ?> batch(es) expiring in ≤7 days
                        <?php if ($store_filter > 0): ?>at <?php echo htmlspecialchars($selected_store_name); ?><?php endif; ?>
                    </h4>
                    <p class="mb-0" style="color: #721c24;">
                        <strong>Immediate action required!</strong>
                        Transfer to a faster-moving store, discount, or write off to Damages.
                    </p>
                </div>
            </div>
        </div>
        <?php elseif ($urgent > 0): ?>
        <div class="alert alert-warning border-start border-warning border-5" role="alert"
             style="border-left-width: 5px !important;">
            <div class="d-flex align-items-center">
                <div class="me-3"><i class="fas fa-exclamation-triangle fa-3x text-warning"></i></div>
                <div class="flex-grow-1">
                    <h5 class="alert-heading mb-2" style="font-weight: 700;">
                        ⚠️ URGENT: <?php echo $urgent; ?> batch(es) expiring in 8–15 days
                    </h5>
                    <p class="mb-0">Transfer to higher-velocity stores to move them faster.</p>
                </div>
            </div>
        </div>
        <?php elseif ($warning > 0): ?>
        <div class="alert alert-info border-start border-info border-5" role="alert"
             style="border-left-width: 5px !important;">
            <div class="d-flex align-items-center">
                <div class="me-3"><i class="fas fa-clock fa-3x text-info"></i></div>
                <div class="flex-grow-1">
                    <h5 class="alert-heading mb-2" style="font-weight: 700;">
                        ℹ️ WARNING: <?php echo $warning; ?> batch(es) expiring in 16–30 days
                    </h5>
                    <p class="mb-0">Monitor and plan transfers.</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <h6><i class="fas fa-fire me-2"></i>Critical (≤7 days)</h6>
                        <h3><?php echo $critical; ?></h3>
                        <small>Immediate action</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h6><i class="fas fa-exclamation me-2"></i>Urgent (8–15 days)</h6>
                        <h3><?php echo $urgent; ?></h3>
                        <small>Transfer or promote</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h6><i class="fas fa-clock me-2"></i>Warning (16–30 days)</h6>
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
                        <small>Inventory value expiring</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="alert alert-info border-start border-info border-5" role="alert"
             style="border-left-width: 5px !important;">
            <h5 class="alert-heading"><i class="fas fa-info-circle me-2"></i>Transfer Strategy</h5>
            <p class="mb-0">
                Transfer expiring batches to a store with higher sales velocity to move them faster, or to the
                <strong>Damages/Expires</strong> store if they can no longer be sold. Batches in the damages store
                are excluded from expiring alerts but remain on the Damage Report for audit.
            </p>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-table me-2"></i>
                    Expiring Products (Next 30 Days)
                    <span class="badge bg-secondary ms-2"><?php echo count($expiring_products); ?></span>
                </span>
                <?php if ($store_filter > 0): ?>
                    <span class="badge bg-primary">
                        <i class="fas fa-filter me-1"></i><?php echo htmlspecialchars($selected_store_name); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (count($expiring_products) > 0): ?>
                    <div class="table-responsive">
                        <table class="table datatable table-hover" id="expiringTable">
                            <thead>
                                <tr>
                                    <th>Urgency</th>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Store</th>
                                    <th>Batch</th>
                                    <th class="text-center">Qty</th>
                                    <th>Expiry</th>
                                    <th class="text-center">Days Left</th>
                                    <th class="text-end">Value</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expiring_products as $product): ?>
                                <tr>
                                    <td>
                                        <?php if ($product['urgency_level'] === 'Critical'): ?>
                                            <span class="badge bg-danger">Critical</span>
                                        <?php elseif ($product['urgency_level'] === 'Urgent'): ?>
                                            <span class="badge bg-warning text-dark">Urgent</span>
                                        <?php else: ?>
                                            <span class="badge bg-info">Warning</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($product['product_code']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($product['product_name']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($product['store_name']); ?></span></td>
                                    <td><small><code><?php echo htmlspecialchars($product['batch_number']); ?></code></small></td>
                                    <td class="text-center"><strong><?php echo number_format($product['quantity']); ?></strong></td>
                                    <td><?php echo date('d/m/Y', strtotime($product['expiry_date'])); ?></td>
                                    <td class="text-center">
                                        <strong class="<?php echo $product['days_to_expiry'] <= 7 ? 'text-danger' : ($product['days_to_expiry'] <= 15 ? 'text-warning' : 'text-info'); ?>">
                                            <?php echo $product['days_to_expiry']; ?> days
                                        </strong>
                                    </td>
                                    <td class="text-end">UGX <?php echo number_format($product['value_at_risk'], 0); ?></td>
                                    <td class="text-center">
                                        <button type="button"
                                                class="btn btn-sm btn-success transfer-btn"
                                                data-inventory-id="<?php echo (int)$product['inventory_id']; ?>"
                                                data-product-id="<?php echo (int)$product['product_id']; ?>"
                                                data-from-store="<?php echo (int)$product['store_id']; ?>"
                                                data-from-store-name="<?php echo htmlspecialchars($product['store_name']); ?>"
                                                data-product-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                                data-product-code="<?php echo htmlspecialchars($product['product_code']); ?>"
                                                data-batch="<?php echo htmlspecialchars($product['batch_number']); ?>"
                                                data-quantity="<?php echo (int)$product['quantity']; ?>"
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
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>All clear!</strong> No products expiring in the next 30 days
                        <?php if ($store_filter > 0): ?>at <strong><?php echo htmlspecialchars($selected_store_name); ?></strong><?php endif; ?>.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- TRANSFER MODAL -->
<div class="modal fade" id="transferModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>Transfer Stock</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="transfer">
                <input type="hidden" name="inventory_id" id="transfer_inventory_id">
                <input type="hidden" name="product_id" id="transfer_product_id">
                <input type="hidden" name="from_store_id" id="transfer_from_store_id">

                <div class="modal-body">
                    <div class="alert alert-warning">
                        <strong>Batch Details:</strong><br>
                        <strong>Product:</strong> <span id="display_product_name"></span><br>
                        <strong>Code:</strong> <span id="display_product_code"></span><br>
                        <strong>Batch:</strong> <code id="display_batch"></code><br>
                        <strong>Expires:</strong> <span id="display_expiry"></span><br>
                        <strong>Current Location:</strong> <span id="display_from_store"></span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Transfer To *</label>
                        <select name="to_store_id" id="transfer_to_store" class="form-select" required>
                            <option value="">-- Select Destination --</option>
                            <?php foreach ($transfer_targets as $t): ?>
                                <option value="<?php echo (int)$t['store_id']; ?>"
                                        data-type="<?php echo $t['store_type']; ?>">
                                    <?php echo htmlspecialchars($t['store_name']); ?>
                                    <?php if ($t['store_type'] === 'damages'): ?>
                                        (Damages/Expires)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            Choose a sales store to move stock, or the Damages store to write it off.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Quantity *</label>
                        <input type="number" name="quantity" id="transfer_quantity" class="form-control" min="1" required>
                        <small class="text-muted">Available: <strong id="display_available"></strong> units</small>
                    </div>

                    <div class="alert alert-info mb-0" id="damages_warning" style="display:none;">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Note:</strong> Moving stock to the Damages store writes it off.
                        It will no longer appear in expiring alerts but will be visible on the Damage Report.
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
    $(document).on('click', '.transfer-btn', function() {
        const btn = $(this);

        $('#transfer_inventory_id').val(btn.data('inventory-id'));
        $('#transfer_product_id').val(btn.data('product-id'));
        $('#transfer_from_store_id').val(btn.data('from-store'));

        $('#display_product_name').text(btn.data('product-name'));
        $('#display_product_code').text(btn.data('product-code'));
        $('#display_batch').text(btn.data('batch'));
        $('#display_expiry').text(btn.data('expiry'));
        $('#display_from_store').text(btn.data('from-store-name'));
        $('#display_available').text(btn.data('quantity'));

        $('#transfer_quantity').attr('max', btn.data('quantity')).val(btn.data('quantity'));

        const $to = $('#transfer_to_store');
        $to.find('option').prop('disabled', false);
        $to.val('');
        $to.find('option[value="' + btn.data('from-store') + '"]').prop('disabled', true);

        $('#damages_warning').hide();
        $('#transferModal').modal('show');
    });

    // Show damages warning when damages store is selected
    $('#transfer_to_store').on('change', function() {
        const type = $(this).find('option:selected').data('type');
        if (type === 'damages') {
            $('#damages_warning').slideDown();
        } else {
            $('#damages_warning').slideUp();
        }
    });

    // Clamp quantity
    $('#transfer_quantity').on('input change', function() {
        const max = parseInt($(this).attr('max')) || 0;
        let val = parseInt($(this).val()) || 0;
        if (val > max) $(this).val(max);
        if (val < 1) $(this).val(1);
    });

    // Reset on modal close
    $('#transferModal').on('hidden.bs.modal', function() {
        $('#transfer_to_store option').prop('disabled', false);
        $('#transfer_to_store').val('');
        $('#damages_warning').hide();
    });
});
</script>