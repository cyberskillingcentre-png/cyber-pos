<?php
require_once '../config/config.php';
require_login();

// Only employees can access this page
if (is_admin()) {
    header('Location: ../admin/view-sale.php?id=' . intval($_GET['id'] ?? 0));
    exit;
}

$page_title = "View Sale";
$sale_id = intval($_GET['id'] ?? 0);

if ($sale_id <= 0) {
    set_alert('danger', 'Invalid sale ID');
    header('Location: my-sales.php');
    exit;
}

// ============================================
// FETCH SALE — WITH IDOR PROTECTION
// ============================================
$sale = get_sale_with_access_check($conn, $sale_id);

if (!$sale) {
    set_alert('danger', 'Sale not found or you do not have access to view it');
    header('Location: my-sales.php');
    exit;
}

// ============================================
// FETCH SALE ITEMS
// ============================================
$items = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            si.*,
            p.product_name,
            p.product_code
        FROM sale_items si
        JOIN products p ON si.product_id = p.product_id
        WHERE si.sale_id = ?
        ORDER BY si.sale_item_id ASC
    ");
    $stmt->execute([$sale_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $items = [];
}

// ============================================
// FETCH CREDIT PAYMENTS (if credit sale)
// ============================================
$credit_payments = [];
if (($sale['sale_type'] ?? 'cash') === 'credit') {
    try {
        $stmt = $conn->prepare("
            SELECT cp.*, u.full_name as recorded_by_name
            FROM customer_credit_payments cp
            LEFT JOIN users u ON cp.recorded_by = u.user_id
            WHERE cp.sale_id = ?
            ORDER BY cp.payment_date DESC
        ");
        $stmt->execute([$sale_id]);
        $credit_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        // Table may not exist
        $credit_payments = [];
    }
}

// ============================================
// CALCULATE TOTALS
// ============================================
$total_paid = 0;
foreach ($credit_payments as $payment) {
    $total_paid += floatval($payment['payment_amount']);
}

$alert = get_alert();
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/employee_sidebar.php'; ?>

<div class="main-content">
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
                    <i class="fas fa-receipt me-2" style="color: var(--cyber-orange);"></i>
                    Sale Details
                </h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="badge bg-primary"><?php echo htmlspecialchars($sale['invoice_number']); ?></span>
                <a href="my-sales.php" class="btn btn-sm btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back
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
        
        <!-- Action Buttons -->
        <div class="text-end mb-3">
            <a href="receipt.php?id=<?php echo $sale_id; ?>" target="_blank" class="btn btn-primary">
                <i class="fas fa-receipt me-1"></i>Print Receipt
            </a>
            
            <?php if (($sale['sale_type'] ?? 'cash') === 'credit'): ?>
            <a href="credit-invoice.php?id=<?php echo $sale_id; ?>" target="_blank" class="btn btn-danger">
                <i class="fas fa-file-invoice-dollar me-1"></i>Credit Invoice
            </a>
            <?php endif; ?>
        </div>
        
        <div class="row g-4">
            <!-- LEFT: Sale Information -->
            <div class="col-lg-6">
                <div class="card cyber-card mb-4">
                    <div class="card-header">
                        <i class="fas fa-info-circle me-2"></i>Sale Information
                    </div>
                    <div class="card-body">
                        <div class="info-item">
                            <div class="info-label">Invoice Number</div>
                            <div class="info-value">
                                <code><?php echo htmlspecialchars($sale['invoice_number']); ?></code>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Date</div>
                            <div class="info-value">
                                <?php echo date('F d, Y H:i:s', strtotime($sale['sale_date'])); ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Sale Type</div>
                            <div class="info-value">
                                <?php if (($sale['sale_type'] ?? 'cash') === 'credit'): ?>
                                    <span class="badge bg-danger">Credit Sale</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Cash Sale</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Store</div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($sale['store_name'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Served By</div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($sale['served_by_name'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Customer</div>
                            <div class="info-value">
                                <?php if (!empty($sale['customer_name'])): ?>
                                    <strong><?php echo htmlspecialchars($sale['customer_name']); ?></strong>
                                    <?php if (!empty($sale['customer_phone'])): ?>
                                        <br><small class="text-muted">
                                            <i class="fas fa-phone me-1"></i>
                                            <?php echo htmlspecialchars($sale['customer_phone']); ?>
                                        </small>
                                    <?php endif; ?>
                                    <?php if (!empty($sale['customer_email'])): ?>
                                        <br><small class="text-muted">
                                            <i class="fas fa-envelope me-1"></i>
                                            <?php echo htmlspecialchars($sale['customer_email']); ?>
                                        </small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Walk-in Customer</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- RIGHT: Payment Information -->
            <div class="col-lg-6">
                <div class="card cyber-card mb-4">
                    <div class="card-header">
                        <i class="fas fa-credit-card me-2"></i>Payment Information
                    </div>
                    <div class="card-body">
                        <div class="info-item">
                            <div class="info-label">Payment Method</div>
                            <div class="info-value">
                                <span class="badge bg-info">
                                    <?php echo ucwords(str_replace('_', ' ', $sale['payment_method'] ?? 'Cash')); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Payment Status</div>
                            <div class="info-value">
                                <?php
                                $status = $sale['payment_status'] ?? 'paid';
                                $badge_class = match($status) {
                                    'paid' => 'bg-success',
                                    'partial' => 'bg-warning',
                                    default => 'bg-danger'
                                };
                                ?>
                                <span class="badge <?php echo $badge_class; ?>">
                                    <?php echo ucfirst($status); ?>
                                </span>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="amount-row">
                            <span>Subtotal</span>
                            <strong>UGX <?php echo number_format(floatval($sale['subtotal'] ?? 0), 0); ?></strong>
                        </div>
                        
                        <?php if (floatval($sale['tax_amount'] ?? 0) > 0): ?>
                        <div class="amount-row">
                            <span>Tax</span>
                            <strong>UGX <?php echo number_format(floatval($sale['tax_amount']), 0); ?></strong>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (floatval($sale['discount_amount'] ?? 0) > 0): ?>
                        <div class="amount-row">
                            <span>Discount</span>
                            <strong class="text-success">- UGX <?php echo number_format(floatval($sale['discount_amount']), 0); ?></strong>
                        </div>
                        <?php endif; ?>
                        
                        <div class="amount-row total">
                            <span>TOTAL</span>
                            <strong class="text-primary">UGX <?php echo number_format(floatval($sale['total_amount'] ?? 0), 0); ?></strong>
                        </div>
                        
                        <div class="amount-row">
                            <span>Amount Paid</span>
                            <strong class="text-success">UGX <?php echo number_format(floatval($sale['amount_paid'] ?? 0), 0); ?></strong>
                        </div>
                        
                        <?php if (floatval($sale['amount_due'] ?? 0) > 0): ?>
                        <div class="amount-row">
                            <span>Amount Due</span>
                            <strong class="text-danger">UGX <?php echo number_format(floatval($sale['amount_due']), 0); ?></strong>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($sale['transaction_id'])): ?>
                        <div class="info-item mt-3">
                            <div class="info-label">Transaction ID</div>
                            <div class="info-value">
                                <code><?php echo htmlspecialchars($sale['transaction_id']); ?></code>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Sale Items -->
        <div class="card cyber-card mb-4">
            <div class="card-header">
                <i class="fas fa-list me-2"></i>Sale Items
                <span class="badge bg-warning text-dark ms-auto"><?php echo count($items); ?> item(s)</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($items)): ?>
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <h5>No Items</h5>
                        <p>This sale has no items</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table cyber-table mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Product Code</th>
                                    <th>Product Name</th>
                                    <th class="text-center">Quantity</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Tax</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($items as $item): ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td><code><?php echo htmlspecialchars($item['product_code'] ?? 'N/A'); ?></code></td>
                                    <td><strong><?php echo htmlspecialchars($item['product_name'] ?? 'Unknown'); ?></strong></td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?php echo intval($item['quantity']); ?></span>
                                    </td>
                                    <td class="text-end">
                                        UGX <?php echo number_format(floatval($item['unit_price']), 0); ?>
                                    </td>
                                    <td class="text-end">
                                        UGX <?php echo number_format(floatval($item['tax_amount'] ?? 0), 0); ?>
                                    </td>
                                    <td class="text-end">
                                        <strong>UGX <?php echo number_format(floatval($item['total_price']), 0); ?></strong>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="6" class="text-end">Total:</th>
                                    <th class="text-end">
                                        UGX <?php echo number_format(floatval($sale['total_amount'] ?? 0), 0); ?>
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Credit Payment History (for credit sales) -->
        <?php if (($sale['sale_type'] ?? '') === 'credit' && !empty($credit_payments)): ?>
        <div class="card cyber-card">
            <div class="card-header">
                <i class="fas fa-history me-2"></i>Credit Payment History
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table cyber-table mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($credit_payments as $payment): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                <td class="text-end text-success">
                                    <strong>UGX <?php echo number_format(floatval($payment['payment_amount']), 0); ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($payment['reference_number'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($payment['recorded_by_name'] ?? 'System'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="2" class="text-end">Total Paid:</th>
                                <th class="text-end text-success">
                                    UGX <?php echo number_format($total_paid, 0); ?>
                                </th>
                                <th colspan="3"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
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

/* Info items */
.info-item {
    padding: 10px 0;
    border-bottom: 1px dashed #E2E8F0;
}

.info-item:last-child { border-bottom: none; }

.info-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #718096;
    margin-bottom: 4px;
}

.info-value {
    font-size: 0.9rem;
    color: #1A202C;
    font-weight: 500;
}

.info-value code {
    font-size: 0.8rem;
    color: #6B46C1;
    background: rgba(107, 70, 193, 0.08);
    padding: 2px 6px;
    border-radius: 4px;
}

/* Amount rows */
.amount-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px dashed #E2E8F0;
    font-size: 0.875rem;
}

.amount-row:last-child { border-bottom: none; }

.amount-row.total {
    border-top: 2px solid #E2E8F0;
    border-bottom: 2px solid #E2E8F0;
    padding: 12px 0;
    font-size: 1rem;
    margin-top: 8px;
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

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #718096;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 16px;
    opacity: 0.3;
    color: #6B46C1;
    display: block;
}

.empty-state h5 { color: #4A5568; font-weight: 700; margin-bottom: 8px; }
.empty-state p { margin: 0; font-size: 0.9rem; }
</style>

<?php include '../includes/footer.php'; ?>