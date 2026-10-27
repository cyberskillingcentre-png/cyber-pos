<?php
require_once '../config/config.php';
require_login();
if (!is_admin()) { header('Location: ../employee/dashboard.php'); exit(); }

$page_title = "Client Orders Management";

// ============================================
// FILTERS
// ============================================
$status_filter = $_GET['status'] ?? '';
$client_filter = $_GET['client'] ?? '';
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to'] ?? '';

// ============================================
// BUILD QUERY
// ============================================
$where  = [];
$params = [];

if ($status_filter !== '') {
    $where[]  = "co.order_status = ?";
    $params[] = $status_filter;
}
if ($client_filter !== '') {
    $where[]  = "co.client_id = ?";
    $params[] = $client_filter;
}
if ($date_from !== '') {
    $where[]  = "DATE(co.order_date) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[]  = "DATE(co.order_date) <= ?";
    $params[] = $date_to;
}
$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ============================================
// FETCH ORDERS
// ============================================
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
            co.amount_due,
            co.delivery_address,
            co.notes,
            u.full_name  AS client_name,
            u.email      AS client_email,
            cp.company_name,
            s.store_name,
            (SELECT COUNT(*) FROM client_order_items WHERE order_id = co.order_id) AS item_count
        FROM client_orders co
        LEFT JOIN users u            ON co.client_id = u.user_id
        LEFT JOIN client_profiles cp ON u.user_id   = cp.user_id
        LEFT JOIN stores s           ON co.store_id = s.store_id
        $where_clause
        ORDER BY co.order_date DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Orders Query Error: " . $e->getMessage());
    $orders = [];
}

// ============================================
// CLIENTS FOR FILTER DROPDOWN
// ============================================
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
} catch (PDOException $e) {
    $clients = [];
}

// ============================================
// STATISTICS (Fixed for PHP 8.1+ NULL deprecation)
// ============================================
$stats = ['total' => 0, 'pending' => 0, 'delivered' => 0, 'cancelled' => 0];
try {
    $stmt = $conn->query("
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN order_status = 'pending'   THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END), 0) AS delivered,
            COALESCE(SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled
        FROM client_orders
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        // Force everything to integer so number_format never gets NULL
        $stats = [
            'total'     => (int) ($result['total'] ?? 0),
            'pending'   => (int) ($result['pending'] ?? 0),
            'delivered' => (int) ($result['delivered'] ?? 0),
            'cancelled' => (int) ($result['cancelled'] ?? 0)
        ];
    }
} catch (PDOException $e) {
    error_log("Stats Query Error: " . $e->getMessage());
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
                <h5 class="mb-0">
                    <i class="fas fa-shopping-cart me-2" style="color: var(--cyber-orange);"></i>
                    Client Orders Management
                </h5>
            </div>
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

        <!-- Statistics Cards (Added (int) cast for extra safety) -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-purple">
                    <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                    <div class="stat-content">
                        <span class="stat-label">Total Orders</span>
                        <h3 class="stat-value"><?php echo number_format((int)($stats['total'] ?? 0)); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-orange">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-content">
                        <span class="stat-label">Pending Orders</span>
                        <h3 class="stat-value"><?php echo number_format((int)($stats['pending'] ?? 0)); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-green">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-content">
                        <span class="stat-label">Delivered</span>
                        <h3 class="stat-value"><?php echo number_format((int)($stats['delivered'] ?? 0)); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-red">
                    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-content">
                        <span class="stat-label">Cancelled</span>
                        <h3 class="stat-value"><?php echo number_format((int)($stats['cancelled'] ?? 0)); ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card cyber-card mb-4">
            <div class="card-header">
                <i class="fas fa-filter me-2"></i>Filter Orders
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Order Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <?php foreach (['pending','confirmed','processing','ready','shipped','delivered','cancelled'] as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo $status_filter === $s ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($s); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select name="client" class="form-select">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $client): ?>
                            <option value="<?php echo (int)$client['user_id']; ?>"
                                    <?php echo (string)$client_filter === (string)$client['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($client['full_name']); ?>
                                <?php if (!empty($client['company_name'])): ?>
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

                <?php if ($status_filter || $client_filter || $date_from || $date_to): ?>
                <div class="mt-3">
                    <a href="Client_Orders.php" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-times me-1"></i>Clear Filters
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="card cyber-card">
            <div class="card-header">
                <i class="fas fa-list me-2"></i>Client Orders
                <span class="badge bg-warning text-dark ms-auto"><?php echo count($orders); ?> order(s)</span>
            </div>
            <div class="card-body">
                <?php if (empty($orders)): ?>
                    <div class="empty-state">
                        <i class="fas fa-shopping-cart"></i>
                        <h5>No Client Orders Found</h5>
                        <p>Orders placed by clients will appear here</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table cyber-table" id="ordersTable">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Date &amp; Time</th>
                                    <th>Client</th>
                                    <th>Store</th>
                                    <th class="text-center">Items</th>
                                    <th class="text-end">Amount</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order):
                                    $grand   = floatval($order['grand_total']);
                                    $paid    = floatval($order['amount_paid']);
                                    $balance = max(0, $grand - $paid);
                                ?>
                                <tr>
                                    <td>
                                        <strong class="invoice-number">
                                            <?php echo htmlspecialchars($order['order_number']); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <div class="date-cell">
                                            <span class="date"><?php echo date('M d, Y', strtotime($order['order_date'])); ?></span>
                                            <span class="time"><?php echo date('H:i A', strtotime($order['order_date'])); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($order['client_name'] ?? 'N/A'); ?></strong>
                                        <?php if (!empty($order['company_name'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($order['company_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="store-badge">
                                            <?php echo htmlspecialchars($order['store_name'] ?? 'Not assigned'); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-info"><?php echo (int)$order['item_count']; ?></span>
                                    </td>
                                    <td class="text-end">
                                        <strong class="amount">UGX <?php echo number_format($grand, 0); ?></strong>
                                        <?php if ($balance > 0): ?>
                                            <br><small class="text-danger">Bal: UGX <?php echo number_format($balance, 0); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $payment_badge = match($order['payment_status'] ?? 'pending') {
                                            'paid'    => 'bg-success',
                                            'partial' => 'bg-warning text-dark',
                                            default   => 'bg-danger',
                                        };
                                        ?>
                                        <span class="badge <?php echo $payment_badge; ?>">
                                            <?php echo ucfirst($order['payment_status'] ?? 'pending'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $status_badge = match($order['order_status'] ?? 'pending') {
                                            'delivered'  => 'bg-success',
                                            'cancelled'  => 'bg-danger',
                                            'shipped', 'confirmed' => 'bg-info',
                                            'processing', 'ready'  => 'bg-primary',
                                            default      => 'bg-warning text-dark',
                                        };
                                        ?>
                                        <span class="badge <?php echo $status_badge; ?>">
                                            <?php echo ucfirst($order['order_status'] ?? 'pending'); ?>
                                        </span>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <a href="View_Order.php?id=<?php echo (int)$order['order_id']; ?>"
                                           class="btn btn-sm btn-primary" title="View Order">
                                            <i class="fas fa-eye"></i>
                                        </a>

                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-secondary dropdown-toggle"
                                                    data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <?php if (($order['order_status'] ?? '') === 'pending'): ?>
                                                <li>
                                                    <a class="dropdown-item text-success fw-bold"
                                                       href="Confirm_Order.php?id=<?php echo (int)$order['order_id']; ?>">
                                                        <i class="fas fa-check-circle me-2"></i>Confirm Order
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <?php endif; ?>

                                                <?php if ($balance > 0 && ($order['order_status'] ?? '') !== 'cancelled'): ?>
                                                <li>
                                                    <a class="dropdown-item text-success fw-bold"
                                                       href="record_client_payment.php?id=<?php echo (int)$order['order_id']; ?>">
                                                        <i class="fas fa-money-bill-wave me-2"></i>Record Payment
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <?php endif; ?>

                                                <?php if (in_array($order['order_status'] ?? '', ['confirmed','processing','ready','shipped'])): ?>
                                                <li>
                                                    <a class="dropdown-item" href="Update_Status.php?id=<?php echo (int)$order['order_id']; ?>">
                                                        <i class="fas fa-edit me-2 text-primary"></i>Update Status
                                                    </a>
                                                </li>
                                                <?php endif; ?>

                                                <li>
                                                    <a class="dropdown-item" href="Print_Invoice.php?id=<?php echo (int)$order['order_id']; ?>" target="_blank">
                                                        <i class="fas fa-print me-2 text-info"></i>Print Invoice
                                                    </a>
                                                </li>

                                                <?php if (!empty($order['client_email'])): ?>
                                                <li>
                                                    <a class="dropdown-item" href="mailto:<?php echo htmlspecialchars($order['client_email']); ?>">
                                                        <i class="fas fa-envelope me-2 text-primary"></i>Email Client
                                                    </a>
                                                </li>
                                                <?php endif; ?>

                                                <?php if (($order['order_status'] ?? '') === 'pending'): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item text-danger" href="#"
                                                       onclick="cancelOrder(<?php echo (int)$order['order_id']; ?>); return false;">
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

<style>
.cyber-card {
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0,0,0,0.08);
}
.cyber-card .card-header {
    background: linear-gradient(135deg, #1A202C 0%, #2D3748 100%);
    color: #fff;
    padding: 14px 20px;
    border: none;
    font-weight: 600;
    display: flex;
    align-items: center;
}
.cyber-card .card-header i { color: #ED8936; }

.stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 22px;
    display: flex;
    align-items: flex-start;
    gap: 18px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.08);
    border: 1px solid #E2E8F0;
}
.stat-card-purple { border-left: 4px solid #6B46C1; }
.stat-card-orange { border-left: 4px solid #ED8936; }
.stat-card-green  { border-left: 4px solid #48BB78; }
.stat-card-red    { border-left: 4px solid #E53E3E; }

.stat-icon {
    width: 56px; height: 56px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
}
.stat-card-purple .stat-icon { background: rgba(107,70,193,0.1); color: #6B46C1; }
.stat-card-orange .stat-icon { background: rgba(237,137,54,0.1); color: #ED8936; }
.stat-card-green  .stat-icon { background: rgba(72,187,120,0.1); color: #48BB78; }
.stat-card-red    .stat-icon { background: rgba(229,62,62,0.1); color: #E53E3E; }

.stat-content { flex: 1; min-width: 0; }
.stat-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #718096;
    margin-bottom: 6px;
}
.stat-value {
    font-size: 1.5rem;
    font-weight: 800;
    color: #1A202C;
    margin: 0;
    line-height: 1.1;
}

.cyber-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
.cyber-table thead th {
    background: #F7FAFC;
    color: #4A5568;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 14px 14px;
    border-bottom: 2px solid #E2E8F0;
    white-space: nowrap;
    text-align: left;
}
.cyber-table tbody td {
    padding: 14px;
    border-bottom: 1px solid #EDF2F7;
    vertical-align: middle;
}
.cyber-table tbody tr:hover { background: #F7FAFC; }

.invoice-number {
    font-family: 'Courier New', monospace;
    font-weight: 700;
    color: #6B46C1;
    font-size: 0.85rem;
}
.date-cell { display: flex; flex-direction: column; }
.date-cell .date { font-weight: 600; color: #1A202C; font-size: 0.85rem; }
.date-cell .time { font-size: 0.75rem; color: #718096; }
.store-badge {
    display: inline-block;
    padding: 4px 10px;
    background: #EDF2F7;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #4A5568;
}
.amount { font-weight: 700; color: #1A202C; font-family: 'Courier New', monospace; }

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #718096;
}
.empty-state i {
    font-size: 3.5rem;
    margin-bottom: 16px;
    opacity: 0.3;
    color: #6B46C1;
    display: block;
}
.empty-state h5 { color: #4A5568; font-weight: 700; margin-bottom: 8px; }
.empty-state p { margin: 0; font-size: 0.9rem; }
</style>

<?php include '../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    if ($.fn.DataTable && $('#ordersTable').length) {
        $('#ordersTable').DataTable({
            "pageLength": 25,
            "order": [[1, "desc"]],
            "columnDefs": [{ "orderable": false, "targets": 8 }]
        });
    }
});

function cancelOrder(orderId) {
    if (confirm('Are you sure you want to cancel this order?\n\nThis action cannot be undone.')) {
        window.location.href = 'cancel-order.php?id=' + orderId;
    }
}
</script>