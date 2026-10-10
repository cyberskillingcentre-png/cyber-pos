<?php
require_once '../config/config.php';
require_admin();

$purchase_id = isset($_POST['purchase_id']) ? intval($_POST['purchase_id']) : 0;

if ($purchase_id <= 0) {
    echo '<div class="alert alert-danger">Invalid purchase ID.</div>';
    exit;
}

try {
    // ------------------------------------------------------------
    // Fetch purchase header + supplier + creator
    // ------------------------------------------------------------
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            s.supplier_name,
            s.contact_person,
            s.email        AS supplier_email,
            s.phone        AS supplier_phone,
            u.full_name    AS created_by_name
        FROM purchases p
        LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
        LEFT JOIN users u     ON p.created_by = u.user_id
        WHERE p.purchase_id = ?
    ");
    $stmt->execute([$purchase_id]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$purchase) {
        echo '<div class="alert alert-danger">Purchase order not found.</div>';
        exit;
    }

    // ------------------------------------------------------------
    // Fetch purchase line items
    //   NOTE: the correct column name is `unit_cost`, not `unit`
    // ------------------------------------------------------------
    $stmt = $conn->prepare("
        SELECT 
            pi.purchase_item_id,
            pi.purchase_id,
            pi.product_id,
            pi.quantity,
            pi.unit_cost,
            pi.total_cost,
            pi.received_quantity,
            pr.product_code,
            pr.product_name
        FROM purchase_items pi
        LEFT JOIN products pr ON pi.product_id = pr.product_id
        WHERE pi.purchase_id = ?
        ORDER BY pi.purchase_item_id ASC
    ");
    $stmt->execute([$purchase_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ------------------------------------------------------------
    // Fetch any payments made against this purchase
    // ------------------------------------------------------------
    $payments = [];
    try {
        $stmt = $conn->prepare("
            SELECT 
                sp.*,
                u.full_name AS recorded_by_name
            FROM supplier_payments sp
            LEFT JOIN users u ON sp.recorded_by = u.user_id
            WHERE sp.purchase_id = ?
            ORDER BY sp.created_at ASC
        ");
        $stmt->execute([$purchase_id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // supplier_payments table may not exist in some orgs
        $payments = [];
    }

    $balance = $purchase['total_amount'] - $purchase['paid_amount'];
    $pay_class = match($purchase['payment_status']) {
        'paid'    => 'success',
        'partial' => 'warning',
        default   => 'danger',
    };
    $status_class = match($purchase['status']) {
        'received'  => 'success',
        'cancelled' => 'secondary',
        default     => 'warning',
    };

} catch (PDOException $e) {
    error_log("ajax_purchase_details failed: " . $e->getMessage());
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}
?>

<!-- ============================================
     PURCHASE HEADER
     ============================================ -->
<div class="row mb-3">
    <div class="col-md-6">
        <h5 class="mb-1">
            <i class="fas fa-file-invoice me-2 text-primary"></i>
            <?php echo htmlspecialchars($purchase['purchase_number']); ?>
        </h5>
        <small class="text-muted">
            <i class="fas fa-calendar me-1"></i>
            <?php echo date('F d, Y', strtotime($purchase['purchase_date'])); ?>
        </small>
    </div>
    <div class="col-md-6 text-end">
        <span class="badge bg-<?php echo $pay_class; ?> me-1">
            Payment: <?php echo ucfirst($purchase['payment_status']); ?>
        </span>
        <span class="badge bg-<?php echo $status_class; ?>">
            Status: <?php echo ucfirst($purchase['status']); ?>
        </span>
    </div>
</div>

<!-- ============================================
     SUPPLIER INFO
     ============================================ -->
<div class="card mb-3">
    <div class="card-header bg-light">
        <i class="fas fa-truck me-2"></i>Supplier
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <strong><?php echo htmlspecialchars($purchase['supplier_name'] ?? 'N/A'); ?></strong>
                <?php if (!empty($purchase['contact_person'])): ?>
                    <div class="text-muted small">
                        <i class="fas fa-user me-1"></i>
                        <?php echo htmlspecialchars($purchase['contact_person']); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-6 text-md-end">
                <?php if (!empty($purchase['supplier_phone'])): ?>
                    <div class="text-muted small">
                        <i class="fas fa-phone me-1"></i>
                        <?php echo htmlspecialchars($purchase['supplier_phone']); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($purchase['supplier_email'])): ?>
                    <div class="text-muted small">
                        <i class="fas fa-envelope me-1"></i>
                        <?php echo htmlspecialchars($purchase['supplier_email']); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
     PURCHASE ITEMS
     ============================================ -->
<div class="card mb-3">
    <div class="card-header bg-light">
        <i class="fas fa-boxes me-2"></i>Items Ordered
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Unit Cost</th>
                        <th class="text-end">Total</th>
                        <th class="text-center">Received</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-3">
                                No items on this purchase order.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($items as $item): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($item['product_name'] ?? 'Unknown'); ?></strong>
                                    <div class="text-muted small">
                                        <?php echo htmlspecialchars($item['product_code'] ?? ''); ?>
                                    </div>
                                </td>
                                <td class="text-center"><?php echo (int) $item['quantity']; ?></td>
                                <td class="text-end">
                                    UGX <?php echo number_format($item['unit_cost'], 2); ?>
                                </td>
                                <td class="text-end">
                                    <strong>UGX <?php echo number_format($item['total_cost'], 2); ?></strong>
                                </td>
                                <td class="text-center">
                                    <?php
                                    $received = (int) $item['received_quantity'];
                                    $ordered  = (int) $item['quantity'];
                                    if ($received >= $ordered && $ordered > 0) {
                                        echo '<span class="badge bg-success">' . $received . ' / ' . $ordered . '</span>';
                                    } elseif ($received > 0) {
                                        echo '<span class="badge bg-warning">' . $received . ' / ' . $ordered . '</span>';
                                    } else {
                                        echo '<span class="badge bg-secondary">' . $received . ' / ' . $ordered . '</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="4" class="text-end">Grand Total:</th>
                        <th class="text-end">
                            UGX <?php echo number_format($purchase['total_amount'], 2); ?>
                        </th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- ============================================
     PAYMENT SUMMARY
     ============================================ -->
<div class="row mb-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-light">
                <i class="fas fa-money-bill-wave me-2"></i>Payment Summary
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Total Amount:</span>
                    <strong>UGX <?php echo number_format($purchase['total_amount'], 2); ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Amount Paid:</span>
                    <strong class="text-success">
                        UGX <?php echo number_format($purchase['paid_amount'], 2); ?>
                    </strong>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between">
                    <span><strong>Balance:</strong></span>
                    <strong class="<?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">
                        UGX <?php echo number_format($balance, 2); ?>
                    </strong>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-light">
                <i class="fas fa-info-circle me-2"></i>Details
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Created By:</span>
                    <strong><?php echo htmlspecialchars($purchase['created_by_name'] ?? 'N/A'); ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Payment Method:</span>
                    <strong><?php echo ucfirst(str_replace('_', ' ', $purchase['payment_method'] ?? 'N/A')); ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Created At:</span>
                    <strong><?php echo date('M d, Y H:i', strtotime($purchase['created_at'])); ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
     NOTES
     ============================================ -->
<?php if (!empty($purchase['notes'])): ?>
<div class="card mb-3">
    <div class="card-header bg-light">
        <i class="fas fa-sticky-note me-2"></i>Notes
    </div>
    <div class="card-body">
        <?php echo nl2br(htmlspecialchars($purchase['notes'])); ?>
    </div>
</div>
<?php endif; ?>

<!-- ============================================
     PAYMENT HISTORY
     ============================================ -->
<?php if (!empty($payments)): ?>
<div class="card">
    <div class="card-header bg-light">
        <i class="fas fa-history me-2"></i>Payment History
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($p['payment_date'])); ?></td>
                            <td>
                                <strong>UGX <?php echo number_format($p['payment_amount'], 2); ?></strong>
                            </td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?></td>
                            <td>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($p['reference_number'] ?? '-'); ?>
                                </small>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($p['recorded_by_name'] ?? '-'); ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>