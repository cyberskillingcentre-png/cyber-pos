<?php
require_once '../config/config.php';
require_once 'config/client_auth.php';
require_client();

$page_title = "My Invoices";

// Get all confirmed/delivered orders (invoices)
try {
    $stmt = $conn->prepare("
        SELECT co.*, s.store_name,
               COUNT(coi.order_item_id) as item_count
        FROM client_orders co
        LEFT JOIN stores s ON co.store_id = s.store_id
        LEFT JOIN client_order_items coi ON co.order_id = coi.order_id
        WHERE co.client_id = ? AND co.order_status IN ('confirmed', 'processing', 'ready', 'shipped', 'delivered')
        GROUP BY co.order_id
        ORDER BY co.order_date DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $invoices = [];
}

$alert = get_alert();
?>

<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-file-invoice me-2"></i>My Invoices</h5>
            <span class="badge bg-primary"><?php echo date('l, F j, Y'); ?></span>
        </div>
    </div>
    
    <div class="content-area">
        <?php if ($alert): ?>
        <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
            <?php echo $alert['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list me-2"></i>Invoice History (<?php echo count($invoices); ?> invoices)
            </div>
            <div class="card-body">
                <?php if (empty($invoices)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-file-invoice fa-3x text-muted mb-3"></i>
                        <h5>No Invoices Yet</h5>
                        <p class="text-muted">Your invoices will appear here once orders are confirmed</p>
                        <a href="products.php" class="btn btn-primary">
                            <i class="fas fa-shopping-bag me-2"></i>Browse Products
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Date</th>
                                    <th>Store</th>
                                    <th>Items</th>
                                    <th>Amount</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Payment Status</th>
                                    <th>Order Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($invoice['order_number']); ?></strong></td>
                                    <td><?php echo date('M d, Y', strtotime($invoice['order_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($invoice['store_name'] ?? 'Multiple Stores'); ?></td>
                                    <td><span class="badge bg-info"><?php echo $invoice['item_count']; ?> item(s)</span></td>
                                    <td><strong>UGX <?php echo format_currency($invoice['grand_total']); ?></strong></td>
                                    <td>UGX <?php echo format_currency($invoice['amount_paid']); ?></td>
                                    <td>
                                        <?php 
                                        $balance = $invoice['grand_total'] - $invoice['amount_paid'];
                                        if ($balance > 0): ?>
                                            <span class="text-danger"><strong>UGX <?php echo format_currency($balance); ?></strong></span>
                                        <?php else: ?>
                                            <span class="text-success"><strong>UGX 0</strong></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $p_class = match($invoice['payment_status']) {
                                            'paid' => 'success',
                                            'partial' => 'warning',
                                            default => 'danger'
                                        };
                                        ?>
                                        <span class="badge badge-<?php echo $p_class; ?>">
                                            <?php echo ucfirst($invoice['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $o_class = match($invoice['order_status']) {
                                            'delivered' => 'success',
                                            'shipped' => 'info',
                                            'ready' => 'primary',
                                            'processing' => 'warning',
                                            default => 'secondary'
                                        };
                                        ?>
                                        <span class="badge badge-<?php echo $o_class; ?>">
                                            <?php echo ucfirst($invoice['order_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="order-details.php?id=<?php echo $invoice['order_id']; ?>" class="btn btn-sm btn-primary" title="View Invoice">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="print-invoice.php?id=<?php echo $invoice['order_id']; ?>" target="_blank" class="btn btn-sm btn-info" title="Print Invoice">
                                            <i class="fas fa-print"></i>
                                        </a>
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

<?php include 'includes/footer.php'; ?>
