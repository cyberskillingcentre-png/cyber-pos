<?php
require_once '../config/config.php';
require_admin();

$page_title = "Damages & Expired Report";

// ============================================
// FIND THE DAMAGES STORE (Graceful fallback)
// ============================================
$damages_store = null;
try {
    $stmt = $conn->query("
        SELECT store_id, store_name 
        FROM stores 
        WHERE store_type = 'damages' AND status = 'active' 
        LIMIT 1
    ");
    if ($stmt) {
        $damages_store = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $damages_store = null;
}

// ============================================
// FETCH DATA (Only if damages store exists)
// ============================================
$damaged_items = [];
$recent_transfers = [];
$total_value = 0;
$total_units = 0;
$expired_count = 0;
$damaged_count = 0;

if ($damages_store) {
    // 1. Current Damages Inventory
    $stmt = $conn->prepare("
        SELECT 
            p.product_code,
            p.product_name,
            si.batch_number,
            si.quantity,
            si.expiry_date,
            p.purchase_price,
            (si.quantity * p.purchase_price) AS value,
            CASE 
                WHEN si.expiry_date IS NULL THEN NULL
                ELSE DATEDIFF(CURDATE(), si.expiry_date)
            END AS days_since_expiry
        FROM store_inventory si
        JOIN products p ON si.product_id = p.product_id
        WHERE si.store_id = ? AND si.quantity > 0
        ORDER BY si.expiry_date ASC
    ");
    $stmt->execute([$damages_store['store_id']]);
    $damaged_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Recent Transfers
    $stmt = $conn->prepare("
        SELECT 
            st.transfer_number,
            st.transfer_date,
            st.quantity,
            st.reason,
            p.product_code,
            p.product_name,
            u.full_name AS transferred_by_name,
            fs.store_name AS from_store_name
        FROM stock_transfers st
        JOIN products p ON st.product_id = p.product_id
        LEFT JOIN users u ON st.transferred_by = u.user_id
        LEFT JOIN stores fs ON st.from_store_id = fs.store_id
        WHERE st.to_store_id = ?
          AND st.transfer_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY st.transfer_date DESC
        LIMIT 100
    ");
    $stmt->execute([$damages_store['store_id']]);
    $recent_transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Calculate Summaries
    foreach ($damaged_items as $item) {
        $total_value += (float) $item['value'];
        $total_units += (int) $item['quantity'];
        if ($item['days_since_expiry'] !== null && $item['days_since_expiry'] > 0) {
            $expired_count++;
        } else {
            $damaged_count++;
        }
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
                <button class="btn btn-link d-md-none sidebar-toggle me-2">
                    <i class="fas fa-bars"></i>
                </button>
                <button type="button" class="btn btn-link sidebar-desktop-toggle me-2"
                        id="sidebarDesktopToggle" title="Toggle Sidebar (Ctrl+B)">
                    <i class="fas fa-bars"></i>
                </button>
                <h5 class="mb-0">
                    <i class="fas fa-trash-alt me-2" style="color: #E53E3E;"></i>
                    Damages &amp; Expired
                    <?php if ($damages_store): ?>
                        — <?php echo htmlspecialchars($damages_store['store_name']); ?>
                    <?php endif; ?>
                </h5>
            </div>
            <a href="expiring-products.php" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-arrow-left me-1"></i>Back to Expiring
            </a>
        </div>
    </div>

    <div class="content-area">
        <?php if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!$damages_store): ?>
            <!-- Graceful empty state if no damages store exists -->
            <div class="alert alert-info border-0 shadow-sm mt-4">
                <h5 class="alert-heading fw-bold"><i class="fas fa-info-circle me-2"></i>Damages Tracking Not Set Up</h5>
                <p class="mb-0">You don't have a store configured to track damaged or expired items. This report will remain empty until you create a store and mark it as a "Damages" store in the system settings.</p>
            </div>
        <?php else: ?>
            
            <!-- Summary cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card" style="border-left: 4px solid #E53E3E;">
                        <div class="card-body">
                            <small class="text-muted text-uppercase fw-bold">Value Written Off</small>
                            <h3 class="text-danger mb-0">UGX <?php echo number_format($total_value, 0); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card" style="border-left: 4px solid #ED8936;">
                        <div class="card-body">
                            <small class="text-muted text-uppercase fw-bold">Total Units</small>
                            <h3 class="mb-0"><?php echo number_format($total_units); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card" style="border-left: 4px solid #4299E1;">
                        <div class="card-body">
                            <small class="text-muted text-uppercase fw-bold">Batches Held</small>
                            <h3 class="mb-0"><?php echo number_format(count($damaged_items)); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card" style="border-left: 4px solid #6B46C1;">
                        <div class="card-body">
                            <small class="text-muted text-uppercase fw-bold">Expired / Damaged</small>
                            <h3 class="mb-0">
                                <?php echo number_format($expired_count); ?>
                                <small class="text-muted fs-6">/</small>
                                <?php echo number_format($damaged_count); ?>
                            </h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current damages inventory -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>
                        <i class="fas fa-boxes me-2"></i>Current Damages Stock
                        <span class="badge bg-secondary ms-2"><?php echo count($damaged_items); ?></span>
                    </span>
                </div>
                <div class="card-body">
                    <?php if (empty($damaged_items)): ?>
                        <div class="alert alert-success mb-0">
                            <i class="fas fa-check-circle me-2"></i>
                            No damaged or expired stock currently held.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table datatable table-hover" id="damagesTable">
                                <thead>
                                    <tr>
                                        <th>Product Code</th>
                                        <th>Product Name</th>
                                        <th>Batch</th>
                                        <th class="text-center">Qty</th>
                                        <th>Expiry</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-end">Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($damaged_items as $item): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($item['product_code']); ?></code></td>
                                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                        <td><small><?php echo htmlspecialchars($item['batch_number']); ?></small></td>
                                        <td class="text-center"><?php echo number_format($item['quantity']); ?></td>
                                        <td>
                                            <?php if (!empty($item['expiry_date'])): ?>
                                                <?php echo date('d/m/Y', strtotime($item['expiry_date'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($item['days_since_expiry'] !== null && $item['days_since_expiry'] > 0): ?>
                                                <span class="badge bg-danger">
                                                    Expired <?php echo (int)$item['days_since_expiry']; ?>d ago
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Damaged</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">UGX <?php echo number_format($item['value'], 0); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-danger">
                                        <th colspan="6" class="text-end">Total Value Written Off:</th>
                                        <th class="text-end">UGX <?php echo number_format($total_value, 0); ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent transfers -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-history me-2"></i>Recent Transfers Into Damages (Last 30 Days)
                    <span class="badge bg-secondary ms-2"><?php echo count($recent_transfers); ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_transfers)): ?>
                        <p class="text-muted mb-0">No transfers into the damages store in the last 30 days.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table datatable table-hover" id="transfersTable">
                                <thead>
                                    <tr>
                                        <th>Transfer #</th>
                                        <th>Date</th>
                                        <th>Product</th>
                                        <th>From Store</th>
                                        <th class="text-center">Qty</th>
                                        <th>Transferred By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_transfers as $t): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($t['transfer_number']); ?></code></td>
                                        <td>
                                            <div><?php echo date('d/m/Y', strtotime($t['transfer_date'])); ?></div>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($t['transfer_date'])); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($t['product_code']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($t['product_name']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($t['from_store_name'] ?? '-'); ?></td>
                                        <td class="text-center"><strong><?php echo number_format($t['quantity']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($t['transferred_by_name'] ?? '-'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>