<?php
require_once '../config/config.php';
require_once 'config/client_auth.php';
require_client();

$page_title = "My Orders";

// ============================================
// FETCH ORDERS
// ============================================
$orders = [];
try {
    $stmt = $conn->prepare("
        SELECT co.*, 
               s.store_name,
               (SELECT COUNT(*) FROM client_order_items WHERE order_id = co.order_id) as item_count
        FROM client_orders co
        LEFT JOIN stores s ON co.store_id = s.store_id
        WHERE co.client_id = ?
        ORDER BY co.order_date DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $orders = [];
}
?>

<?php include '../includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="main-content">
    <!-- Top Navbar -->
    <div class="top-navbar">
        <div class="d-flex justify-content-between align-items-center w-100">
            <div class="d-flex align-items-center">
                <button class="btn btn-link d-lg-none sidebar-toggle me-2">
                    <i class="fas fa-bars"></i>
                </button>
                
                <button type="button" class="btn btn-link sidebar-desktop-toggle me-2" 
                        id="sidebarDesktopToggle" title="Toggle Sidebar (Ctrl+B)">
                    <i class="fas fa-bars"></i>
                </button>
                
                <h5 class="mb-0">
                    <i class="fas fa-shopping-cart me-2" style="color: var(--cyber-orange);"></i>
                    My Orders
                </h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="new-order.php" class="btn btn-sm btn-success">
                    <i class="fas fa-plus me-1"></i>New Order
                </a>
                <div class="user-badge d-none d-md-flex">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <i class="fas fa-<?php echo $alert['type'] == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Orders Card -->
        <div class="card cyber-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-list-alt me-2"></i>
                    Order History (<?php echo count($orders); ?>)
                </span>
                <span class="badge bg-warning text-dark">
                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($_SESSION['full_name']); ?>
                </span>
            </div>
            
            <div class="card-body p-0">
                <?php if (empty($orders)): ?>
                    <div class="empty-state">
                        <i class="fas fa-shopping-cart"></i>
                        <h5>No Orders Yet</h5>
                        <p>Start shopping to see your orders here!</p>
                        <a href="products.php" class="btn btn-primary mt-3">
                            <i class="fas fa-shopping-bag me-2"></i>Browse Products
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table cyber-table mb-0">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Date</th>
                                    <th>Store</th>
                                    <th>Items</th>
                                    <th>Total</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td>
                                        <span class="invoice-number">
                                            <?php echo htmlspecialchars($order['order_number']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="date-cell">
                                            <span class="date"><?php echo date('M d, Y', strtotime($order['order_date'])); ?></span>
                                            <span class="time"><?php echo date('H:i A', strtotime($order['order_date'])); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="store-badge">
                                            <?php echo htmlspecialchars($order['store_name'] ?? 'Not assigned'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo intval($order['item_count']); ?> item(s)
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount">
                                            UGX <?php echo number_format(floatval($order['grand_total']), 0); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $p_class = match($order['payment_status'] ?? 'pending') {
                                            'paid'    => 'payment-paid',
                                            'partial' => 'payment-partial',
                                            default   => 'payment-unpaid'
                                        };
                                        ?>
                                        <span class="payment-badge <?php echo $p_class; ?>">
                                            <?php echo ucfirst($order['payment_status'] ?? 'Pending'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $o_class = match($order['order_status'] ?? 'pending') {
                                            'delivered'  => 'status-delivered',
                                            'cancelled'  => 'status-cancelled',
                                            'shipped'    => 'status-shipped',
                                            'ready'      => 'status-ready',
                                            'processing' => 'status-processing',
                                            'confirmed'  => 'status-confirmed',
                                            default      => 'status-pending'
                                        };
                                        ?>
                                        <span class="status-badge <?php echo $o_class; ?>">
                                            <?php echo ucfirst($order['order_status'] ?? 'Pending'); ?>
                                        </span>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <a href="order-details.php?id=<?php echo $order['order_id']; ?>" 
                                           class="btn-action btn-view" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <?php if (($order['order_status'] ?? '') === 'pending'): ?>
                                            <a href="cancel-order.php?id=<?php echo $order['order_id']; ?>" 
                                               class="btn-action btn-cancel" 
                                               title="Cancel Order"
                                               onclick="return confirm('Are you sure you want to cancel this order?');">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        <?php endif; ?>
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

<!-- Page Styles -->
<style>
/* Cyber card */
.cyber-card {
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.08);
}

.cyber-card .card-header {
    background: linear-gradient(135deg, #1A202C 0%, #2D3748 100%);
    color: #fff;
    padding: 16px 20px;
    border: none;
    font-weight: 600;
    display: flex;
    align-items: center;
}

.cyber-card .card-header i { color: #ED8936; }

/* Cyber table */
.cyber-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.cyber-table thead th {
    background: #F7FAFC;
    color: #4A5568;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 14px 16px;
    border-bottom: 2px solid #E2E8F0;
    text-align: left;
    white-space: nowrap;
}

.cyber-table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid #EDF2F7;
    vertical-align: middle;
}

.cyber-table tbody tr:hover { background: #F7FAFC; }
.cyber-table tbody tr:last-child td { border-bottom: none; }

/* Cell styles */
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

.amount {
    font-weight: 700;
    color: #1A202C;
    font-family: 'Courier New', monospace;
}

/* Payment badges */
.payment-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: capitalize;
    white-space: nowrap;
}

.payment-paid    { background: rgba(72, 187, 120, 0.15); color: #2F855A; }
.payment-partial { background: rgba(237, 137, 54, 0.15); color: #C05621; }
.payment-unpaid  { background: rgba(229, 62, 62, 0.15); color: #C53030; }

/* Status badges */
.status-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: capitalize;
    white-space: nowrap;
}

.status-delivered  { background: rgba(72, 187, 120, 0.15); color: #2F855A; }
.status-cancelled  { background: rgba(229, 62, 62, 0.15); color: #C53030; }
.status-shipped    { background: rgba(0, 181, 216, 0.15); color: #0987A0; }
.status-ready      { background: rgba(107, 70, 193, 0.15); color: #553C9A; }
.status-processing { background: rgba(237, 137, 54, 0.15); color: #C05621; }
.status-confirmed  { background: rgba(66, 153, 225, 0.15); color: #2B6CB0; }
.status-pending    { background: rgba(237, 137, 54, 0.15); color: #C05621; }

/* Action buttons */
.btn-action {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
    margin: 0 2px;
}

.btn-view {
    background: rgba(107, 70, 193, 0.1);
    color: #6B46C1;
}
.btn-view:hover { background: #6B46C1; color: #fff; transform: scale(1.05); }

.btn-cancel {
    background: rgba(229, 62, 62, 0.1);
    color: #C53030;
}
.btn-cancel:hover { background: #E53E3E; color: #fff; transform: scale(1.05); }

/* Empty state */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #718096;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 16px;
    opacity: 0.3;
    color: #6B46C1;
    display: block;
}

.empty-state h5 { color: #4A5568; font-weight: 700; margin-bottom: 8px; }
.empty-state p { margin: 0; font-size: 0.9rem; }

/* User badge */
.user-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #6B46C1, #553C9A);
    color: #fff;
    border-radius: 30px;
    font-size: 0.85rem;
    font-weight: 600;
    box-shadow: 0 4px 15px rgba(107, 70, 193, 0.3);
}

/* Responsive */
@media (max-width: 767.98px) {
    .cyber-table thead th,
    .cyber-table tbody td {
        padding: 10px 8px;
        font-size: 0.8rem;
    }
}
</style>

<?php include 'includes/footer.php'; ?>