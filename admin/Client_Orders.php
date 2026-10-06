<?php
require_once '../config/config.php';
require_login();
if (!is_admin()) { header('Location: ../employee/dashboard.php'); exit(); }

$page_title = "Client Orders Management";

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$client_filter = $_GET['client'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$where = [];
$params = [];

if ($status_filter) {
    $where[] = "co.order_status = ?";
    $params[] = $status_filter;
}

if ($client_filter) {
    $where[] = "co.client_id = ?";
    $params[] = $client_filter;
}

if ($date_from) {
    $where[] = "DATE(co.order_date) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where[] = "DATE(co.order_date) <= ?";
    $params[] = $date_to;
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get all client orders - FIXED QUERY
$orders = [];
try {
    $sql = "
        SELECT 
            co.order_id,
            co.order_number,
            co.order_date,
            co.order_status,
            co.payment_status,
            co.payment_method,
            co.grand_total,
            co.amount_paid,
            co.delivery_address,
            co.notes,
            u.full_name as client_name, 
            u.email as client_email,
            cp.company_name,
            s.store_name,
            (SELECT COUNT(*) FROM client_order_items WHERE order_id = co.order_id) as item_count
        FROM client_orders co
        LEFT JOIN users u ON co.client_id = u.user_id
        LEFT JOIN client_profiles cp ON u.user_id = cp.user_id
        LEFT JOIN stores s ON co.store_id = s.store_id
        $where_clause
        ORDER BY co.order_date DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log("Orders Query Error: " . $e->getMessage());
    $orders = [];
}

// Get all clients for filter
$clients = [];
try {
    $stmt = $conn->query("
        SELECT u.user_id, u.full_name, cp.company_name 
        FROM users u 
        LEFT JOIN client_profiles cp ON u.user_id = cp.user_id 
        WHERE u.role = 'client' 
        ORDER BY u.full_name
    ");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $clients = [];
}

// Get order statistics
$stats = ['total' => 0, 'pending' => 0, 'delivered' => 0, 'cancelled' => 0];
try {
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM client_orders
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $stats = $result;
    }
} catch(PDOException $e) {
    // Keep default stats
}

$alert = get_alert();
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <div class="d-flex justify-content-between align-items-center">
            <!-- MOBILE SIDEBAR TOGGLE -->
            <button class="btn btn-link d-md-none sidebar-toggle me-2">
                <i class="fas fa-bars"></i>
            </button>
            <h5 class="mb-0"><i class="fas fa-shopping-cart me-2"></i>Client Orders Management</h5>
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
        
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card purple">
                    <div class="icon"><i class="fas fa-shopping-cart"></i></div>
                    <h3><?php echo number_format($stats['total']); ?></h3>
                    <p>Total Orders</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card orange">
                    <div class="icon"><i class="fas fa-clock"></i></div>
                    <h3><?php echo number_format($stats['pending']); ?></h3>
                    <p>Pending Orders</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card green">
                    <div class="icon"><i class="fas fa-check-circle"></i></div>
                    <h3><?php echo number_format($stats['delivered']); ?></h3>
                    <p>Delivered</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card blue">
                    <div class="icon"><i class="fas fa-times-circle"></i></div>
                    <h3><?php echo number_format($stats['cancelled']); ?></h3>
                    <p>Cancelled</p>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-filter me-2"></i>Filter Orders
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Order Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="confirmed" <?php echo $status_filter == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                            <option value="processing" <?php echo $status_filter == 'processing' ? 'selected' : ''; ?>>Processing</option>
                            <option value="ready" <?php echo $status_filter == 'ready' ? 'selected' : ''; ?>>Ready</option>
                            <option value="shipped" <?php echo $status_filter == 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                            <option value="delivered" <?php echo $status_filter == 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                            <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select name="client" class="form-select">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $client): ?>
                            <option value="<?php echo $client['user_id']; ?>" <?php echo $client_filter == $client['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($client['full_name']); ?>
                                <?php if ($client['company_name']): ?>
                                    (<?php echo htmlspecialchars($client['company_name']); ?>)
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-1"></i>Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Orders Table -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list me-2"></i>Client Orders (<?php echo count($orders); ?> orders)
            </div>
            <div class="card-body">
                <?php if (empty($orders)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                        <h5>No Client Orders Found</h5>
                        <p class="text-muted">Orders placed by clients will appear here</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered" id="ordersTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Order #</th>
                                    <th>Date & Time</th>
                                    <th>Client Name</th>
                                    <th>Store</th>
                                    <th>Items</th>
                                    <th>Amount</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($order['order_number']); ?></strong></td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($order['order_date'])); ?>
                                        <br><small class="text-muted"><?php echo date('H:i', strtotime($order['order_date'])); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($order['client_name']); ?>
                                        <?php if ($order['company_name']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($order['company_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($order['store_name'] ?? 'Not assigned'); ?></td>
                                    <td><span class="badge bg-info"><?php echo $order['item_count']; ?> item(s)</span></td>
                                    <td><strong class="text-success">UGX <?php echo number_format($order['grand_total'], 0); ?></strong></td>
                                    <td>
                                        <?php
                                        $payment_badge = match($order['payment_status']) {
                                            'paid' => 'bg-success',
                                            'partial' => 'bg-warning',
                                            default => 'bg-danger'
                                        };
                                        ?>
                                        <span class="badge <?php echo $payment_badge; ?>">
                                            <?php echo ucfirst($order['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $status_badge = match($order['order_status']) {
                                            'delivered' => 'bg-success',
                                            'cancelled' => 'bg-danger',
                                            'shipped', 'confirmed' => 'bg-info',
                                            'processing', 'ready' => 'bg-primary',
                                            default => 'bg-warning'
                                        };
                                        ?>
                                        <span class="badge <?php echo $status_badge; ?>">
                                            <?php echo ucfirst($order['order_status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-nowrap">
                                        <a href="View_Order?id=<?php echo $order['order_id']; ?>" class="btn btn-sm btn-primary" title="View Order Details">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <?php if ($order['order_status'] == 'pending'): ?>
                                                <li>
                                                    <a class="dropdown-item text-success" href="Confirm_Order?id=<?php echo $order['order_id']; ?>">
                                                        <i class="fas fa-check-circle me-2"></i>Confirm Order
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <?php endif; ?>
                                                
                                                <?php if (in_array($order['order_status'], ['confirmed', 'processing', 'ready', 'shipped'])): ?>
                                                <li>
                                                    <a class="dropdown-item" href="Update_Status?id=<?php echo $order['order_id']; ?>">
                                                        <i class="fas fa-edit me-2 text-primary"></i>Update Status
                                                    </a>
                                                </li>
                                                <?php endif; ?>
                                                
                                                <li>
                                                    <a class="dropdown-item" href="Print_Invoice?id=<?php echo $order['order_id']; ?>" target="_blank">
                                                        <i class="fas fa-print me-2 text-info"></i>Print Invoice
                                                    </a>
                                                </li>
                                                
                                                <li>
                                                    <a class="dropdown-item" href="mailto:<?php echo htmlspecialchars($order['client_email']); ?>">
                                                        <i class="fas fa-envelope me-2 text-primary"></i>Email Client
                                                    </a>
                                                </li>
                                                
                                                <?php if ($order['order_status'] == 'pending'): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item text-danger" href="#" onclick="cancelOrder(<?php echo $order['order_id']; ?>); return false;">
                                                        <i class="fas fa-times-circle me-2"></i>Cancel Order
                                                    </a>
                                                </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
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

<script>
// Initialize DataTables
<?php if (!empty($orders)): ?>
$(document).ready(function() {
    $('#ordersTable').DataTable({
        "pageLength": 25,
        "order": [[1, "desc"]],
        "columnDefs": [
            { "orderable": false, "targets": 8 }
        ]
    });
});
<?php endif; ?>

function cancelOrder(orderId) {
    if (confirm('Are you sure you want to cancel this order?\n\nThis action cannot be undone.')) {
        window.location.href = 'cancel-order.php?id=' + orderId;
    }
}
</script>

<?php include '../includes/footer.php'; ?>
