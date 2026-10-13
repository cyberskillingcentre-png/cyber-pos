<?php
require_once '../config/config.php';
require_admin();

$page_title = "View Sale";
$sale_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$sale_id) {
    set_alert('danger', 'Invalid sale ID');
    header('Location: sales.php');
    exit();
}

// ============================================
// FETCH SALE
// ============================================
$stmt = $conn->prepare("
    SELECT 
        s.*,
        st.store_name,
        c.customer_name,
        c.phone as customer_phone,
        c.email as customer_email,
        u.full_name as served_by_name
    FROM sales s
    LEFT JOIN stores st ON s.store_id = st.store_id
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    LEFT JOIN users u ON s.served_by = u.user_id
    WHERE s.sale_id = ?
");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    set_alert('danger', 'Sale not found');
    header('Location: sales.php');
    exit();
}

// ============================================
// FETCH SALE ITEMS
// ============================================
$stmt = $conn->prepare("
    SELECT si.*, p.product_name, p.product_code
    FROM sale_items si
    JOIN products p ON si.product_id = p.product_id
    WHERE si.sale_id = ?
    ORDER BY si.sale_item_id
");
$stmt->execute([$sale_id]);
$sale_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FETCH CREDIT PAYMENTS (defensive)
// ============================================
$credit_payments = [];
try {
    $stmt = $conn->prepare("
        SELECT cp.*, u.full_name as recorded_by_name
        FROM customer_credit_payments cp
        LEFT JOIN users u ON cp.recorded_by = u.user_id
        WHERE cp.sale_id = ?
        ORDER BY cp.payment_date DESC, cp.created_at DESC
    ");
    $stmt->execute([$sale_id]);
    $credit_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $credit_payments = [];
}

// Calculate total payments made
$total_paid = 0;
foreach ($credit_payments as $payment) {
    $total_paid += floatval($payment['payment_amount']);
}

// ============================================
// HANDLE PAYMENT SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    csrf_require();
    try {
        $conn->beginTransaction();
        
        $payment_amount = floatval($_POST['payment_amount'] ?? 0);
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $reference_number = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($payment_amount <= 0) {
            throw new Exception("Payment amount must be greater than 0");
        }
        
        $outstanding = floatval($sale['total_amount']) - floatval($sale['amount_paid']);
        
        if ($payment_amount > $outstanding) {
            throw new Exception("Payment amount cannot exceed outstanding balance (UGX " . number_format($outstanding, 2) . ")");
        }
        
        // Record payment
        $stmt = $conn->prepare("
            INSERT INTO customer_credit_payments 
            (sale_id, customer_id, payment_amount, payment_date, payment_method, reference_number, notes, recorded_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $sale_id, 
            $sale['customer_id'], 
            $payment_amount, 
            $payment_date, 
            $payment_method, 
            $reference_number, 
            $notes, 
            $_SESSION['user_id']
        ]);
        
        // Update sale
        $new_amount_paid = floatval($sale['amount_paid']) + $payment_amount;
        $new_amount_due = floatval($sale['total_amount']) - $new_amount_paid;
        
        if ($new_amount_paid >= floatval($sale['total_amount'])) {
            $new_payment_status = 'paid';
        } elseif ($new_amount_paid > 0) {
            $new_payment_status = 'partial';
        } else {
            $new_payment_status = 'unpaid';
        }
        
        $stmt = $conn->prepare("
            UPDATE sales 
            SET amount_paid = ?, amount_due = ?, payment_status = ? 
            WHERE sale_id = ?
        ");
        $stmt->execute([$new_amount_paid, $new_amount_due, $new_payment_status, $sale_id]);
        
        // Update customer total
        if ($sale['customer_id']) {
            $stmt = $conn->prepare("UPDATE customers SET total_purchases = total_purchases + ? WHERE customer_id = ?");
            $stmt->execute([$payment_amount, $sale['customer_id']]);
        }
        
        // Log activity
        if (function_exists('log_activity')) {
            log_activity(
                $_SESSION['user_id'], 
                'CREDIT_PAYMENT_RECEIVED', 
                "Received credit payment of UGX " . number_format($payment_amount, 2) . " for sale: " . $sale['invoice_number'], 
                $conn
            );
        }
        
        $conn->commit();
        set_alert('success', 'Payment of UGX ' . number_format($payment_amount, 2) . ' recorded! Remaining: UGX ' . number_format($new_amount_due, 2));
        header('Location: view-sale.php?id=' . $sale_id);
        exit();
        
    } catch(Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        set_alert('danger', 'Error: ' . $e->getMessage());
        header('Location: view-sale.php?id=' . $sale_id);
        exit();
    }
}
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
                <button class="btn btn-link sidebar-desktop-toggle me-2" 
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
                <a href="sales.php" class="btn btn-sm btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Sales
                </a>
            </div>
        </div>
    </div>
    
    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <i class="fas fa-info-circle me-2"></i>
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- ============================================
             ACTION BUTTONS
             ============================================ -->
        <div class="d-flex justify-content-end gap-2 mb-3 flex-wrap">
            <?php if ($sale['sale_type'] === 'credit' && floatval($sale['amount_due']) > 0): ?>
                <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                    <i class="fas fa-money-bill-wave me-1"></i>Record Payment
                </button>
            <?php endif; ?>
            
            <?php if ($sale['sale_type'] === 'credit'): ?>
                <a href="credit-invoice.php?id=<?php echo $sale_id; ?>" 
                   class="btn btn-danger" target="_blank">
                    <i class="fas fa-file-invoice-dollar me-1"></i>Print Credit Invoice
                </a>
            <?php else: ?>
                <a href="receipt.php?id=<?php echo $sale_id; ?>" 
                   class="btn btn-primary" target="_blank">
                    <i class="fas fa-receipt me-1"></i>Print Receipt
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
                            <div class="info-label">Sale Date</div>
                            <div class="info-value">
                                <?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Sale Type</div>
                            <div class="info-value">
                                <?php if ($sale['sale_type'] === 'credit'): ?>
                                    <span class="badge bg-danger">
                                        <i class="fas fa-credit-card me-1"></i>Credit Sale
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success">
                                        <i class="fas fa-money-bill-wave me-1"></i>Cash Sale
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Store</div>
                            <div class="info-value"><?php echo htmlspecialchars($sale['store_name']); ?></div>
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
                        
                        <div class="info-item">
                            <div class="info-label">Served By</div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($sale['served_by_name']); ?>
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
                                    <?php echo ucwords(str_replace('_', ' ', $sale['payment_method'])); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Payment Status</div>
                            <div class="info-value">
                                <?php if ($sale['payment_status'] === 'paid'): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif ($sale['payment_status'] === 'partial'): ?>
                                    <span class="badge bg-warning">Partial</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Unpaid</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="amount-row">
                            <span>Subtotal</span>
                            <strong>UGX <?php echo number_format(floatval($sale['subtotal']), 2); ?></strong>
                        </div>
                        
                        <?php if (floatval($sale['tax_amount']) > 0): ?>
                        <div class="amount-row">
                            <span>Tax</span>
                            <strong>UGX <?php echo number_format(floatval($sale['tax_amount']), 2); ?></strong>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (floatval($sale['discount_amount']) > 0): ?>
                        <div class="amount-row">
                            <span>Discount</span>
                            <strong class="text-success">- UGX <?php echo number_format(floatval($sale['discount_amount']), 2); ?></strong>
                        </div>
                        <?php endif; ?>
                        
                        <div class="amount-row total">
                            <span>TOTAL</span>
                            <strong class="text-primary">UGX <?php echo number_format(floatval($sale['total_amount']), 2); ?></strong>
                        </div>
                        
                        <div class="amount-row">
                            <span>Amount Paid</span>
                            <strong class="text-success">UGX <?php echo number_format(floatval($sale['amount_paid']), 2); ?></strong>
                        </div>
                        
                        <?php if (floatval($sale['amount_due']) > 0): ?>
                        <div class="amount-row">
                            <span>Amount Due</span>
                            <strong class="text-danger">UGX <?php echo number_format(floatval($sale['amount_due']), 2); ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ============================================
             PAYMENT HISTORY (for credit sales)
             ============================================ -->
        <?php if ($sale['sale_type'] === 'credit' && count($credit_payments) > 0): ?>
        <div class="card cyber-card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-history me-2"></i>
                    Payment History (<?php echo count($credit_payments); ?> payment<?php echo count($credit_payments) > 1 ? 's' : ''; ?>)
                </span>
                <span class="badge bg-success">
                    Total: UGX <?php echo number_format($total_paid, 2); ?>
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table cyber-table mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th class="text-end">Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Recorded By</th>
                                <th>Notes</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; foreach ($credit_payments as $payment): ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                <td class="text-end text-success">
                                    <strong>UGX <?php echo number_format(floatval($payment['payment_amount']), 2); ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($payment['reference_number'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($payment['recorded_by_name'] ?? 'System'); ?></td>
                                <td><?php echo htmlspecialchars($payment['notes'] ?? '-'); ?></td>
                                <td class="text-center">
                                    <form method="POST" action="delete_payment.php" 
                                          onsubmit="return confirm('Delete this payment record? This action cannot be undone.');" 
                                          style="display:inline;">
                                          <?php echo csrf_field(); ?>
                                        <input type="hidden" name="payment_id" value="<?php echo $payment['payment_id']; ?>">
                                        <input type="hidden" name="sale_id" value="<?php echo $sale_id; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete Payment">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- ============================================
             SALE ITEMS
             ============================================ -->
        <div class="card cyber-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-list me-2"></i>Sale Items
                </span>
                <span class="badge bg-warning text-dark"><?php echo count($sale_items); ?> item(s)</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table cyber-table mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product Code</th>
                                <th>Product Name</th>
                                <th class="text-center">Qty</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Tax</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            $total_profit = 0;
                            foreach ($sale_items as $item): 
                                $total_profit += floatval($item['profit'] ?? 0);
                            ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td><code><?php echo htmlspecialchars($item['product_code']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></td>
                                <td class="text-center"><?php echo intval($item['quantity']); ?></td>
                                <td class="text-end">UGX <?php echo number_format(floatval($item['unit_price']), 2); ?></td>
                                <td class="text-end">UGX <?php echo number_format(floatval($item['tax_amount']), 2); ?></td>
                                <td class="text-end"><strong>UGX <?php echo number_format(floatval($item['total_price']), 2); ?></strong></td>
                                <td class="text-end text-success">UGX <?php echo number_format(floatval($item['profit']), 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="6" class="text-end">Totals:</th>
                                <th class="text-end">UGX <?php echo number_format(floatval($sale['total_amount']), 2); ?></th>
                                <th class="text-end text-success">UGX <?php echo number_format($total_profit, 2); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
     RECORD PAYMENT MODAL
     ============================================ -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">
                    <i class="fas fa-money-bill-wave me-2"></i>Record Credit Payment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="recordPaymentForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="record_payment">
                <div class="modal-body">
                    
                    <!-- Sale Summary -->
                    <div class="payment-summary mb-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="summary-box">
                                    <div class="summary-label">Invoice Total</div>
                                    <div class="summary-value">
                                        UGX <?php echo number_format(floatval($sale['total_amount']), 2); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="summary-box green">
                                    <div class="summary-label">Already Paid</div>
                                    <div class="summary-value">
                                        UGX <?php echo number_format(floatval($sale['amount_paid']), 2); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="summary-box red">
                                    <div class="summary-label">Outstanding</div>
                                    <div class="summary-value">
                                        UGX <?php echo number_format(floatval($sale['amount_due']), 2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Form -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-cyber">
                                <i class="fas fa-money-bill me-1"></i>Payment Amount *
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">UGX</span>
                                <input type="number" 
                                       name="payment_amount" 
                                       id="payment_amount_input"
                                       class="form-control" 
                                       step="0.01" 
                                       min="0.01" 
                                       max="<?php echo floatval($sale['amount_due']); ?>" 
                                       value="<?php echo floatval($sale['amount_due']); ?>"
                                       required>
                            </div>
                            <small class="text-muted">
                                Maximum: UGX <?php echo number_format(floatval($sale['amount_due']), 2); ?>
                            </small>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label-cyber">
                                <i class="fas fa-calendar me-1"></i>Payment Date *
                            </label>
                            <input type="date" 
                                   name="payment_date" 
                                   class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" 
                                   required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label-cyber">
                                <i class="fas fa-credit-card me-1"></i>Payment Method *
                            </label>
                            <select name="payment_method" class="form-select" required>
                                <option value="cash">💵 Cash</option>
                                <option value="mobile_money">📱 Mobile Money</option>
                                <option value="bank_transfer">🏦 Bank Transfer</option>
                                <option value="check">📝 Check</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label-cyber">
                                <i class="fas fa-hashtag me-1"></i>Reference Number
                            </label>
                            <input type="text" 
                                   name="reference_number" 
                                   class="form-control" 
                                   placeholder="Transaction ID, Check #, etc.">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label-cyber">
                                <i class="fas fa-sticky-note me-1"></i>Notes
                            </label>
                            <textarea name="notes" 
                                      class="form-control" 
                                      rows="2" 
                                      placeholder="Optional payment notes..."></textarea>
                        </div>
                    </div>
                    
                    <!-- New Balance Preview -->
                    <div class="new-balance-preview mt-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>New Outstanding Balance:</span>
                            <strong id="new_balance_display" class="fs-5">
                                UGX <?php echo number_format(floatval($sale['amount_due']), 2); ?>
                            </strong>
                        </div>
                        <div id="payment_status_hint" class="small text-muted mt-1">
                            After this payment, the sale will be marked as <strong>Paid</strong>.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-warning" id="submitPaymentBtn">
                        <i class="fas fa-check me-1"></i>Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================
     PAGE STYLES
     ============================================ -->
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
    text-align: left;
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

/* Modal Specific */
.payment-summary {
    padding: 0;
}

.summary-box {
    background: #F7FAFC;
    border-radius: 10px;
    padding: 14px 16px;
    border-left: 4px solid #6B46C1;
    height: 100%;
}

.summary-box.green { border-left-color: #48BB78; }
.summary-box.red { border-left-color: #E53E3E; }

.summary-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #718096;
    margin-bottom: 4px;
}

.summary-value {
    font-size: 1rem;
    font-weight: 700;
    color: #1A202C;
    font-family: 'Courier New', monospace;
}

.form-label-cyber {
    display: block;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #4A5568;
    margin-bottom: 6px;
}

.form-label-cyber i { color: #6B46C1; }

.new-balance-preview {
    background: linear-gradient(135deg, #F7FAFC, #EDF2F7);
    padding: 16px 20px;
    border-radius: 10px;
    border-left: 4px solid #ED8936;
}

.new-balance-preview strong {
    color: #C05621;
    font-family: 'Courier New', monospace;
}
</style>

<?php include '../includes/footer.php'; ?>

<script>
jQuery(document).ready(function($) {
    
    // ============================================
    // UPDATE NEW BALANCE PREVIEW
    // ============================================
    const outstandingBalance = <?php echo floatval($sale['amount_due']); ?>;
    
    $('#payment_amount_input').on('input', function() {
        const paymentAmount = parseFloat($(this).val()) || 0;
        const newBalance = Math.max(0, outstandingBalance - paymentAmount);
        
        // Format with commas
        const formattedBalance = newBalance.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        $('#new_balance_display').text('UGX ' + formattedBalance);
        
        // Update color and hint
        if (newBalance <= 0) {
            $('#new_balance_display').css('color', '#38A169');
            $('#payment_status_hint').html('After this payment, the sale will be marked as <strong class="text-success">PAID</strong>.');
        } else if (paymentAmount > 0) {
            $('#new_balance_display').css('color', '#DD6B20');
            $('#payment_status_hint').html('After this payment, the sale will be marked as <strong class="text-warning">PARTIAL</strong>.');
        } else {
            $('#new_balance_display').css('color', '#C05621');
            $('#payment_status_hint').html('Enter a payment amount to continue.');
        }
    });
    
    // ============================================
    // FORM VALIDATION
    // ============================================
    $('#recordPaymentForm').on('submit', function(e) {
        const paymentAmount = parseFloat($('#payment_amount_input').val()) || 0;
        
        if (paymentAmount <= 0) {
            e.preventDefault();
            alert('Payment amount must be greater than 0');
            return false;
        }
        
        if (paymentAmount > outstandingBalance) {
            e.preventDefault();
            alert('Payment amount cannot exceed the outstanding balance of UGX ' + outstandingBalance.toFixed(2));
            return false;
        }
        
        // Show loading state
        $('#submitPaymentBtn')
            .prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-1"></i>Recording...');
    });
    
    // ============================================
    // AUTO-FOCUS ON MODAL OPEN
    // ============================================
    $('#recordPaymentModal').on('shown.bs.modal', function() {
        $('#payment_amount_input').focus().select();
    });
});
</script>