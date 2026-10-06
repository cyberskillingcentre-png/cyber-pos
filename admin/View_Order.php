<?php
require_once '../config/config.php';
require_login();
if (!is_admin()) { header('Location: ../employee/dashboard.php'); exit(); }

$page_title = "View Client Order";
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($order_id <= 0) {
    set_alert('danger', 'Invalid order ID');
    header('Location: Client_Orders');
    exit;
}

// ============================================
// GET ORDER DETAILS
// ============================================
$order = null;
try {
    $stmt = $conn->prepare("
        SELECT co.*, 
               u.full_name as client_name, 
               u.email as client_email,
               u.phone as client_phone,
               cp.company_name, 
               cp.billing_address,
               s.store_name,
               proc.full_name as processed_by_name
        FROM client_orders co
        LEFT JOIN users u ON co.client_id = u.user_id
        LEFT JOIN client_profiles cp ON u.user_id = cp.user_id
        LEFT JOIN stores s ON co.store_id = s.store_id
        LEFT JOIN users proc ON co.processed_by = proc.user_id
        WHERE co.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        set_alert('danger', 'Order not found');
        header('Location: Client_Orders');
        exit;
    }
} catch(PDOException $e) {
    set_alert('danger', 'Error loading order: ' . $e->getMessage());
    header('Location: Client_Orders');
    exit;
}

// ============================================
// SAFE VALUE EXTRACTION (prevents undefined key warnings)
// ============================================
$subtotal       = floatval($order['subtotal'] ?? 0);
$discount_amount = floatval($order['discount_amount'] ?? 0);
$tax_amount     = floatval($order['tax_amount'] ?? 0);
$grand_total    = floatval($order['grand_total'] ?? 0);
$amount_paid    = floatval($order['amount_paid'] ?? 0);
$amount_due     = floatval($order['amount_due'] ?? ($grand_total - $amount_paid));
$balance        = max(0, $grand_total - $amount_paid);

// ============================================
// GET ORDER ITEMS
// ============================================
$items = [];
try {
    $stmt = $conn->prepare("
        SELECT coi.*, p.product_code
        FROM client_order_items coi
        LEFT JOIN products p ON coi.product_id = p.product_id
        WHERE coi.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $items = [];
}

$alert = get_alert();
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

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
                    <i class="fas fa-file-invoice me-2" style="color: var(--cyber-orange);"></i>
                    Order: <?php echo htmlspecialchars($order['order_number']); ?>
                </h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="Client_Orders.php" class="btn btn-sm btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Orders
                </a>
            </div>
        </div>
    </div>
    
    <div class="content-area">
        <?php if ($alert): ?>
        <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
            <i class="fas fa-info-circle me-2"></i>
            <?php echo $alert['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <div class="row g-4">
            <!-- LEFT COLUMN: Order Items -->
            <div class="col-lg-8">
                
                <!-- Order Items Card -->
                <div class="card cyber-card mb-4">
                    <div class="card-header">
                        <i class="fas fa-box me-2"></i>Order Items
                        <span class="badge bg-warning text-dark ms-auto"><?php echo count($items); ?> item(s)</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($items)): ?>
                            <div class="empty-state">
                                <i class="fas fa-box-open"></i>
                                <h5>No Items</h5>
                                <p>This order has no items</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table cyber-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Code</th>
                                            <th class="text-center">Qty</th>
                                            <th class="text-end">Unit Price</th>
                                            <th class="text-end">Discount</th>
                                            <th class="text-end">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $item): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($item['product_code'] ?? 'N/A'); ?></code>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-primary"><?php echo intval($item['quantity']); ?></span>
                                            </td>
                                            <td class="text-end">
                                                UGX <?php echo number_format(floatval($item['unit_price']), 0); ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if (floatval($item['discount'] ?? 0) > 0): ?>
                                                    <span class="text-success">- UGX <?php echo number_format(floatval($item['discount']), 0); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <strong>UGX <?php echo number_format(floatval($item['total_price']), 0); ?></strong>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="5" class="text-end"><strong>Subtotal:</strong></td>
                                            <td class="text-end"><strong>UGX <?php echo number_format($subtotal, 0); ?></strong></td>
                                        </tr>
                                        <?php if ($discount_amount > 0): ?>
                                        <tr>
                                            <td colspan="5" class="text-end"><strong>Discount:</strong></td>
                                            <td class="text-end"><strong class="text-success">- UGX <?php echo number_format($discount_amount, 0); ?></strong></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ($tax_amount > 0): ?>
                                        <tr>
                                            <td colspan="5" class="text-end"><strong>Tax:</strong></td>
                                            <td class="text-end"><strong>UGX <?php echo number_format($tax_amount, 0); ?></strong></td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr class="table-primary">
                                            <td colspan="5" class="text-end"><strong>GRAND TOTAL:</strong></td>
                                            <td class="text-end"><strong class="fs-5">UGX <?php echo number_format($grand_total, 0); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td colspan="5" class="text-end"><strong>Amount Paid:</strong></td>
                                            <td class="text-end"><strong class="text-success">UGX <?php echo number_format($amount_paid, 0); ?></strong></td>
                                        </tr>
                                        <?php if ($balance > 0): ?>
                                        <tr>
                                            <td colspan="5" class="text-end"><strong>Balance Due:</strong></td>
                                            <td class="text-end"><strong class="text-danger">UGX <?php echo number_format($balance, 0); ?></strong></td>
                                        </tr>
                                        <?php endif; ?>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Order Notes -->
                <?php if (!empty($order['notes'])): ?>
                <div class="card cyber-card">
                    <div class="card-header">
                        <i class="fas fa-sticky-note me-2"></i>Order Notes
                    </div>
                    <div class="card-body">
                        <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Delivery Address -->
                <?php if (!empty($order['delivery_address'])): ?>
                <div class="card cyber-card mt-4">
                    <div class="card-header">
                        <i class="fas fa-map-marker-alt me-2"></i>Delivery Address
                    </div>
                    <div class="card-body">
                        <?php echo nl2br(htmlspecialchars($order['delivery_address'])); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- RIGHT COLUMN: Status, Client, Actions -->
            <div class="col-lg-4">
                
                <!-- Order Status Card -->
                <div class="card cyber-card mb-4">
                    <div class="card-header">
                        <i class="fas fa-info-circle me-2"></i>Order Status
                    </div>
                    <div class="card-body">
                        <!-- Order Status -->
                        <div class="status-block mb-3">
                            <div class="status-label">Order Status</div>
                            <?php
                            $o_badge = match($order['order_status'] ?? 'pending') {
                                'delivered' => 'status-delivered',
                                'cancelled' => 'status-cancelled',
                                'shipped'   => 'status-shipped',
                                'ready'     => 'status-ready',
                                'processing' => 'status-processing',
                                'confirmed' => 'status-confirmed',
                                default     => 'status-pending'
                            };
                            ?>
                            <span class="status-badge-lg <?php echo $o_badge; ?>">
                                <i class="fas fa-circle"></i>
                                <?php echo ucfirst($order['order_status'] ?? 'Pending'); ?>
                            </span>
                        </div>
                        
                        <!-- Payment Status -->
                        <div class="status-block mb-3">
                            <div class="status-label">Payment Status</div>
                            <?php
                            $p_badge = match($order['payment_status'] ?? 'pending') {
                                'paid'    => 'payment-paid',
                                'partial' => 'payment-partial',
                                default   => 'payment-unpaid'
                            };
                            ?>
                            <span class="status-badge-lg <?php echo $p_badge; ?>">
                                <i class="fas fa-circle"></i>
                                <?php echo ucfirst($order['payment_status'] ?? 'Pending'); ?>
                            </span>
                        </div>
                        
                        <!-- Payment Method -->
                        <div class="status-block mb-3">
                            <div class="status-label">Payment Method</div>
                            <div class="status-value">
                                <i class="fas fa-credit-card me-1" style="color: var(--cyber-purple);"></i>
                                <?php echo ucwords(str_replace('_', ' ', $order['payment_method'] ?? 'N/A')); ?>
                            </div>
                        </div>
                        
                        <!-- Amount Summary -->
                        <div class="amount-summary">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total:</span>
                                <strong>UGX <?php echo number_format($grand_total, 0); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Paid:</span>
                                <strong class="text-success">UGX <?php echo number_format($amount_paid, 0); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Balance:</span>
                                <strong class="<?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">
                                    UGX <?php echo number_format($balance, 0); ?>
                                </strong>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Client Information -->
                <div class="card cyber-card mb-4">
                    <div class="card-header">
                        <i class="fas fa-user me-2"></i>Client Information
                    </div>
                    <div class="card-body">
                        <div class="info-item">
                            <div class="info-label">Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($order['client_name'] ?? 'N/A'); ?></div>
                        </div>
                        
                        <?php if (!empty($order['company_name'])): ?>
                        <div class="info-item">
                            <div class="info-label">Company</div>
                            <div class="info-value"><?php echo htmlspecialchars($order['company_name']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($order['client_email'])): ?>
                        <div class="info-item">
                            <div class="info-label">Email</div>
                            <div class="info-value">
                                <a href="mailto:<?php echo htmlspecialchars($order['client_email']); ?>">
                                    <?php echo htmlspecialchars($order['client_email']); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($order['client_phone'])): ?>
                        <div class="info-item">
                            <div class="info-label">Phone</div>
                            <div class="info-value">
                                <a href="tel:<?php echo htmlspecialchars($order['client_phone']); ?>">
                                    <?php echo htmlspecialchars($order['client_phone']); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($order['billing_address'])): ?>
                        <div class="info-item">
                            <div class="info-label">Billing Address</div>
                            <div class="info-value"><?php echo nl2br(htmlspecialchars($order['billing_address'])); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Order Details -->
                <div class="card cyber-card mb-4">
                    <div class="card-header">
                        <i class="fas fa-info-circle me-2"></i>Order Details
                    </div>
                    <div class="card-body">
                        <div class="info-item">
                            <div class="info-label">Order Number</div>
                            <div class="info-value">
                                <code><?php echo htmlspecialchars($order['order_number']); ?></code>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Order Date</div>
                            <div class="info-value">
                                <?php echo date('M d, Y H:i', strtotime($order['order_date'])); ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Store</div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($order['store_name'] ?? 'Not assigned'); ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($order['processed_by_name'])): ?>
                        <div class="info-item">
                            <div class="info-label">Processed By</div>
                            <div class="info-value"><?php echo htmlspecialchars($order['processed_by_name']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="card cyber-card">
                    <div class="card-header">
                        <i class="fas fa-cogs me-2"></i>Quick Actions
                    </div>
                    <div class="card-body d-grid gap-2">
                        <?php if (($order['order_status'] ?? '') == 'pending'): ?>
                        <a href="Confirm_Order.php?id=<?php echo $order_id; ?>" class="btn btn-success">
                            <i class="fas fa-check-circle me-2"></i>Confirm Order
                        </a>
                        <?php endif; ?>
                        
                        <?php if (in_array($order['order_status'] ?? '', ['confirmed', 'processing', 'ready', 'shipped'])): ?>
                        <a href="Update_Status.php?id=<?php echo $order_id; ?>" class="btn btn-primary">
                            <i class="fas fa-edit me-2"></i>Update Status
                        </a>
                        <?php endif; ?>
                        
                        <a href="Print_Invoice.php?id=<?php echo $order_id; ?>" target="_blank" class="btn btn-info">
                            <i class="fas fa-print me-2"></i>Print Invoice
                        </a>
                        
                        <?php if (!empty($order['client_email'])): ?>
                        <a href="mailto:<?php echo htmlspecialchars($order['client_email']); ?>" class="btn btn-secondary">
                            <i class="fas fa-envelope me-2"></i>Email Client
                        </a>
                        <?php endif; ?>
                        
                        <?php if (($order['order_status'] ?? '') == 'pending'): ?>
                        <button onclick="if(confirm('Cancel this order?')) location.href='cancel-order.php?id=<?php echo $order_id; ?>'" 
                                class="btn btn-danger">
                            <i class="fas fa-times-circle me-2"></i>Cancel Order
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Page Styles -->
<style>
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
    padding: 14px 20px;
    border: none;
    font-weight: 600;
    display: flex;
    align-items: center;
}

.cyber-card .card-header i { color: #ED8936; }

/* Status blocks */
.status-block {
    padding: 12px;
    background: #F7FAFC;
    border-radius: 8px;
    border-left: 3px solid #E2E8F0;
}

.status-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #718096;
    margin-bottom: 6px;
}

.status-value {
    font-size: 0.9rem;
    font-weight: 600;
    color: #1A202C;
}

.status-badge-lg {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 700;
    text-transform: capitalize;
}

.status-badge-lg i {
    font-size: 0.6rem;
}

/* Order status colors */
.status-delivered { background: rgba(72, 187, 120, 0.15); color: #2F855A; }
.status-cancelled { background: rgba(229, 62, 62, 0.15); color: #C53030; }
.status-shipped { background: rgba(0, 181, 216, 0.15); color: #0987A0; }
.status-ready { background: rgba(107, 70, 193, 0.15); color: #553C9A; }
.status-processing { background: rgba(237, 137, 54, 0.15); color: #C05621; }
.status-confirmed { background: rgba(66, 153, 225, 0.15); color: #2B6CB0; }
.status-pending { background: rgba(237, 137, 54, 0.15); color: #C05621; }

/* Payment status colors */
.payment-paid { background: rgba(72, 187, 120, 0.15); color: #2F855A; }
.payment-partial { background: rgba(237, 137, 54, 0.15); color: #C05621; }
.payment-unpaid { background: rgba(229, 62, 62, 0.15); color: #C53030; }

/* Amount summary */
.amount-summary {
    padding: 12px;
    background: #F7FAFC;
    border-radius: 8px;
    border-left: 3px solid #6B46C1;
    font-size: 0.875rem;
    margin-top: 12px;
}

/* Info items */
.info-item {
    padding: 8px 0;
    border-bottom: 1px dashed #E2E8F0;
}

.info-item:last-child { border-bottom: none; }

.info-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #718096;
    margin-bottom: 3px;
}

.info-value {
    font-size: 0.9rem;
    color: #1A202C;
    font-weight: 500;
}

.info-value a {
    color: #6B46C1;
    text-decoration: none;
}

.info-value a:hover {
    text-decoration: underline;
}

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
    white-space: nowrap;
}

.cyber-table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid #EDF2F7;
    vertical-align: middle;
}

.cyber-table tbody tr:hover { background: #F7FAFC; }

.cyber-table code {
    font-size: 0.8rem;
    color: #6B46C1;
    background: rgba(107, 70, 193, 0.08);
    padding: 2px 6px;
    border-radius: 4px;
}

/* Empty state */
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