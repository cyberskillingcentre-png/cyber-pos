<?php
require_once '../config/config.php';
require_once 'config/client_auth.php';
require_client();

$page_title = "Client Dashboard";

// Get client profile
try {
    $client = get_client_profile($conn, $_SESSION['user_id']);
} catch(PDOException $e) {
    $client = ['full_name' => $_SESSION['full_name']];
}

// Get statistics
$stats = [];

// Total orders
try {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM client_orders WHERE client_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $stats['total_orders'] = $stmt->fetchColumn();
} catch(PDOException $e) {
    $stats['total_orders'] = 0;
}

// Pending orders
try {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM client_orders WHERE client_id = ? AND order_status IN ('pending', 'confirmed', 'processing')");
    $stmt->execute([$_SESSION['user_id']]);
    $stats['pending_orders'] = $stmt->fetchColumn();
} catch(PDOException $e) {
    $stats['pending_orders'] = 0;
}

// Total spent
try {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(grand_total), 0) as total FROM client_orders WHERE client_id = ? AND order_status != 'cancelled'");
    $stmt->execute([$_SESSION['user_id']]);
    $stats['total_spent'] = $stmt->fetchColumn();
} catch(PDOException $e) {
    $stats['total_spent'] = 0;
}

// Outstanding balance
try {
    $stmt = $conn->prepare("SELECT current_balance FROM client_profiles WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $stats['outstanding_balance'] = $stmt->fetchColumn() ?? 0;
} catch(PDOException $e) {
    $stats['outstanding_balance'] = 0;
}

// Recent orders
try {
    $stmt = $conn->prepare("
        SELECT co.*, s.store_name,
               COUNT(coi.order_item_id) as item_count
        FROM client_orders co
        LEFT JOIN stores s ON co.store_id = s.store_id
        LEFT JOIN client_order_items coi ON co.order_id = coi.order_id
        WHERE co.client_id = ?
        GROUP BY co.order_id
        ORDER BY co.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_orders = $stmt->fetchAll();
} catch(PDOException $e) {
    $recent_orders = [];
}
?>

<?php include '../includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Dashboard</h5>
            <span class="badge bg-primary"><?php echo date('l, F j, Y'); ?></span>
        </div>
    </div>
    
    <div class="content-area">
        <!-- Statistics Cards -->
        <div class="row">
            <div class="col-md-3">
                <div class="stat-card purple">
                    <div class="icon"><i class="fas fa-shopping-cart"></i></div>
                    <h3><?php echo number_format($stats['total_orders']); ?></h3>
                    <p>Total Orders</p>
                    <div class="text-muted small">All time</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card orange">
                    <div class="icon"><i class="fas fa-clock"></i></div>
                    <h3><?php echo number_format($stats['pending_orders']); ?></h3>
                    <p>Pending Orders</p>
                    <div class="text-muted small">In progress</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card green">
                    <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                    <h3>UGX <?php echo format_currency($stats['total_spent']); ?></h3>
                    <p>Total Spent</p>
                    <div class="text-muted small">All purchases</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card blue">
                    <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <h3>UGX <?php echo format_currency($stats['outstanding_balance']); ?></h3>
                    <p>Outstanding Balance</p>
                    <div class="text-muted small">Due amount</div>
                </div>
            </div>
        </div>
        
        <!-- Recent Orders -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-receipt me-2"></i>Recent Orders
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_orders)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No orders yet. Start shopping!</p>
                                <a href="products.php" class="btn btn-primary">Browse Products</a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Order #</th>
                                            <th>Date</th>
                                            <th>Items</th>
                                            <th>Amount</th>
                                            <th>Payment Status</th>
                                            <th>Order Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_orders as $order): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                            <td><?php echo $order['item_count']; ?> item(s)</td>
                                            <td>UGX <?php echo format_currency($order['grand_total']); ?></td>
                                            <td>
                                                <?php
                                                $p_class = match($order['payment_status']) {
                                                    'paid' => 'badge-success',
                                                    'partial' => 'badge-info',
                                                    default => 'badge-warning'
                                                };
                                                ?>
                                                <span class="badge <?php echo $p_class; ?>">
                                                    <?php echo ucfirst($order['payment_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                $o_class = match($order['order_status']) {
                                                    'delivered' => 'badge-success',
                                                    'cancelled' => 'badge-danger',
                                                    'shipped' => 'badge-info',
                                                    default => 'badge-warning'
                                                };
                                                ?>
                                                <span class="badge <?php echo $o_class; ?>">
                                                    <?php echo ucfirst($order['order_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="order-details.php?id=<?php echo $order['order_id']; ?>" class="btn btn-sm btn-primary">
                                                    View
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
        
        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-bolt me-2"></i>Quick Actions
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <a href="products.php" class="btn btn-primary w-100">
                                    <i class="fas fa-shopping-bag fa-2x mb-2"></i><br>
                                    Browse Products
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="new-order.php" class="btn btn-success w-100">
                                    <i class="fas fa-plus-circle fa-2x mb-2"></i><br>
                                    Place Order
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="orders.php" class="btn btn-warning w-100">
                                    <i class="fas fa-list-alt fa-2x mb-2"></i><br>
                                    My Orders
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="profile.php" class="btn btn-info w-100">
                                    <i class="fas fa-user-cog fa-2x mb-2"></i><br>
                                    My Profile
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
