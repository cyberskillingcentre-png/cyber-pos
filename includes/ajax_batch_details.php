<?php
require_once '../config/config.php';
require_login();

$batch_number = sanitize_input($_POST['batch_number'] ?? '');
$product_id = intval($_POST['product_id'] ?? 0);
$is_finished = intval($_POST['is_finished'] ?? 0);

if (empty($batch_number) || empty($product_id)) {
    echo '<div class="alert alert-danger">Invalid batch information</div>';
    exit;
}

// Get batch information from all stores
$stmt = $conn->prepare("
    SELECT 
        si.*,
        s.store_name,
        p.product_name,
        p.product_code
    FROM store_inventory si
    JOIN stores s ON si.store_id = s.store_id
    JOIN products p ON si.product_id = p.product_id
    WHERE si.batch_number = ? AND si.product_id = ?
    ORDER BY si.created_at ASC
");
$stmt->execute([$batch_number, $product_id]);
$batch_records = $stmt->fetchAll();

if (empty($batch_records)) {
    echo '<div class="alert alert-warning">Batch not found</div>';
    exit;
}

$first_record = $batch_records[0];
$total_quantity = array_sum(array_column($batch_records, 'quantity'));

// Check if batch was created via transfer (contains -TRF in batch number)
$is_transferred = strpos($batch_number, '-TRF') !== false;

// Get activity logs for this batch
$stmt = $conn->prepare("
    SELECT * FROM activity_logs 
    WHERE description LIKE ? OR description LIKE ?
    ORDER BY created_at ASC
    LIMIT 50
");
$stmt->execute(['%' . $batch_number . '%', '%Batch: ' . $batch_number . '%']);
$activity_logs = $stmt->fetchAll();

// Get sales history for this batch (approximate - we don't track batch in sale_items)
$stmt = $conn->prepare("
    SELECT 
        s.invoice_number,
        s.sale_date,
        st.store_name,
        sit.quantity,
        sit.unit_price,
        sit.total_price,
        u.full_name as served_by
    FROM sale_items sit
    JOIN sales s ON sit.sale_id = s.sale_id
    JOIN stores st ON s.store_id = st.store_id
    JOIN users u ON s.served_by = u.user_id
    WHERE sit.product_id = ?
    ORDER BY s.sale_date DESC
    LIMIT 100
");
$stmt->execute([$product_id]);
$sales_history = $stmt->fetchAll();

?>

<!-- Batch Overview -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-info text-white">
                <strong>Batch Information</strong>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th>Batch Number:</th>
                        <td><strong><?php echo htmlspecialchars($batch_number); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Product:</th>
                        <td>
                            <strong><?php echo htmlspecialchars($first_record['product_code']); ?></strong> - 
                            <?php echo htmlspecialchars($first_record['product_name']); ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Origin:</th>
                        <td>
                            <?php if ($is_transferred): ?>
                                <span class="badge bg-warning">
                                    <i class="fas fa-exchange-alt"></i> Transferred from another store
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success">
                                    <i class="fas fa-plus-circle"></i> Created as new stock
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>First Created:</th>
                        <td><?php echo date('d/m/Y H:i', strtotime($first_record['created_at'])); ?></td>
                    </tr>
                    <tr>
                        <th>Expiry Date:</th>
                        <td>
                            <?php if ($first_record['expiry_date']): ?>
                                <?php echo date('d/m/Y', strtotime($first_record['expiry_date'])); ?>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Current Total Quantity:</th>
                        <td>
                            <strong class="fs-5 text-<?php echo $total_quantity > 0 ? 'success' : 'danger'; ?>">
                                <?php echo number_format($total_quantity); ?>
                            </strong>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <strong>Store Distribution</strong>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Store</th>
                            <th>Quantity</th>
                            <th>Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batch_records as $record): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($record['store_name']); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $record['quantity'] > 0 ? 'success' : 'secondary'; ?>">
                                    <?php echo number_format($record['quantity']); ?>
                                </span>
                            </td>
                            <td><small><?php echo date('d/m/Y H:i', strtotime($record['last_updated'])); ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Activity Timeline -->
<?php if (!empty($activity_logs)): ?>
<div class="card mb-4">
    <div class="card-header bg-success text-white">
        <strong><i class="fas fa-history me-2"></i>Batch Activity Timeline</strong>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Action</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activity_logs as $log): ?>
                    <tr>
                        <td><small><?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?></small></td>
                        <td>
                            <span class="badge bg-info"><?php echo htmlspecialchars($log['action']); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($log['description']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Sales History -->
<?php if (!empty($sales_history)): ?>
<div class="card">
    <div class="card-header bg-warning text-dark">
        <strong><i class="fas fa-shopping-cart me-2"></i>Recent Sales for This Product</strong>
        <small class="text-muted">(Note: Sales are tracked by product, not by specific batch)</small>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Store</th>
                        <th>Quantity Sold</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                        <th>Served By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales_history as $sale): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($sale['invoice_number']); ?></strong></td>
                        <td><small><?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?></small></td>
                        <td><?php echo htmlspecialchars($sale['store_name']); ?></td>
                        <td><span class="badge bg-danger"><?php echo $sale['quantity']; ?></span></td>
                        <td><?php echo number_format($sale['unit_price'], 0); ?></td>
                        <td><strong>UGX <?php echo number_format($sale['total_price'], 0); ?></strong></td>
                        <td><?php echo htmlspecialchars($sale['served_by']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>