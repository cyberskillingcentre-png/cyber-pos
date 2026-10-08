<?php
require_once '../config/config.php';
require_admin();

$page_title = "View Sale";

$sale_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$sale_id) {
    set_alert('danger', 'Invalid sale ID');
    header('Location: My_Sales');
    exit();
}

// Fetch sale details
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
$sale = $stmt->fetch();

if (!$sale) {
    set_alert('danger', 'Sale not found');
    header('Location: My_Sales');
    exit();
}

// Fetch sale items
$stmt = $conn->prepare("
    SELECT 
        si.*,
        p.product_name,
        p.product_code
    FROM sale_items si
    JOIN products p ON si.product_id = p.product_id
    WHERE si.sale_id = ?
    ORDER BY si.sale_item_id
");
$stmt->execute([$sale_id]);
$sale_items = $stmt->fetchAll();

// Fetch credit payments for this sale (defensive)
$credit_payments = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            cp.*,
            u.full_name as recorded_by_name
        FROM customer_credit_payments cp
        LEFT JOIN users u ON cp.recorded_by = u.user_id
        WHERE cp.sale_id = ?
        ORDER BY cp.payment_date DESC, cp.created_at DESC
    ");
    $stmt->execute([$sale_id]);
    $credit_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // Table might not exist in this org's DB — that's fine
    $credit_payments = [];
}

// Calculate total payments made
$total_paid = 0;
foreach ($credit_payments as $payment) {
    $total_paid += $payment['payment_amount'];
}

// Handle credit payment form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'record_payment') {
    try {
        $conn->beginTransaction();
        
        $payment_amount = floatval($_POST['payment_amount']);
        $payment_date = $_POST['payment_date'];
        $payment_method = $_POST['payment_method'];
        $reference_number = sanitize_input($_POST['reference_number']);
        $notes = sanitize_input($_POST['notes']);
        
        if ($payment_amount <= 0) {
            throw new Exception("Payment amount must be greater than 0");
        }
        
        // Calculate remaining balance
        $remaining_balance = $sale['total_amount'] - ($sale['amount_paid'] + $payment_amount);
        if ($remaining_balance < 0) {
            throw new Exception("Payment amount cannot exceed outstanding balance");
        }
        
        // Record payment
        $stmt = $conn->prepare("INSERT INTO customer_credit_payments (sale_id, customer_id, payment_amount, payment_date, payment_method, reference_number, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$sale_id, $sale['customer_id'], $payment_amount, $payment_date, $payment_method, $reference_number, $notes, $_SESSION['user_id']]);
        
        // Update sale payment info
        $new_amount_paid = $sale['amount_paid'] + $payment_amount;
        
        // Determine new payment status
        if ($new_amount_paid >= $sale['total_amount']) {
            $new_payment_status = 'paid';
        } elseif ($new_amount_paid > 0) {
            $new_payment_status = 'partial';
        } else {
            $new_payment_status = 'unpaid';
        }
        
        $stmt = $conn->prepare("UPDATE sales SET amount_paid = ?, amount_due = ?, payment_status = ? WHERE sale_id = ?");
        $stmt->execute([$new_amount_paid, $sale['total_amount'] - $new_amount_paid, $new_payment_status, $sale_id]);
        
        // Update customer's total purchases
        $stmt = $conn->prepare("UPDATE customers SET total_purchases = total_purchases + ? WHERE customer_id = ?");
        $stmt->execute([$payment_amount, $sale['customer_id']]);
        
        log_activity($_SESSION['user_id'], 'CREDIT_PAYMENT_RECEIVED', "Received credit payment of UGX " . number_format($payment_amount, 2) . " for sale: " . $sale['invoice_number'], $conn);
        
        $conn->commit();
        set_alert('success', 'Payment recorded successfully! Remaining balance: UGX ' . number_format($remaining_balance, 2));
        header('Location: View_Sales?id=' . $sale_id);
        exit();
        
    } catch(Exception $e) {
        if (isset($conn)) $conn->rollBack();
        set_alert('danger', 'Error: ' . $e->getMessage());
        header('Location: View_Sales?id=' . $sale_id);
        exit();
    }
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
         <!-- MOBILE SIDEBAR TOGGLE -->
            <button class="btn btn-link d-md-none sidebar-toggle me-2">
                <i class="fas fa-bars"></i>
            </button>
        <h5 class="mb-0"><i class="fas fa-receipt me-2"></i>Sale Details</h5>
    </div>
    
    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <div class="text-end mb-3">
            <?php if ($sale['sale_type'] == 'credit'): ?>
                <?php if ($sale['amount_due'] > 0): ?>
                <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                    <i class="fas fa-money-bill-wave me-1"></i>Record Payment
                </button>
                <?php endif; ?>
                <a href="Credit_Invoice?id=<?php echo $sale_id; ?>" class="btn btn-danger" target="_blank">
                    <i class="fas fa-file-invoice-dollar me-1"></i>Print Credit Invoice
                </a>
            <?php else: ?>
                <a href="Receipt?id=<?php echo $sale_id; ?>" class="btn btn-primary" target="_blank">
                    <i class="fas fa-receipt me-1"></i>Print Receipt
                </a>
            <?php endif; ?>
            <a href="My_Sales" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back to Sales
            </a>
        </div>
        
        <div class="row">
            <!-- Sale Information -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="fas fa-info-circle me-2"></i>Sale Information
                    </div>
                    <div class="card-body">
                        <div class="row mb-2">
                            <div class="col-4"><strong>Invoice Number:</strong></div>
                            <div class="col-8">
                                <span class="badge bg-primary"><?php echo $sale['invoice_number']; ?></span>
                            </div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-4"><strong>Sale Date:</strong></div>
                            <div class="col-8"><?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?></div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-4"><strong>Sale Type:</strong></div>
                            <div class="col-8">
                                <?php if ($sale['sale_type'] == 'credit'): ?>
                                    <span class="badge bg-danger"><i class="fas fa-credit-card me-1"></i>Credit Sale</span>
                                <?php else: ?>
                                    <span class="badge bg-success"><i class="fas fa-money-bill-wave me-1"></i>Cash Sale</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-4"><strong>Store:</strong></div>
                            <div class="col-8"><?php echo $sale['store_name']; ?></div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-4"><strong>Customer:</strong></div>
                            <div class="col-8">
                                <?php if ($sale['customer_name']): ?>
                                    <strong><?php echo $sale['customer_name']; ?></strong>
                                    <?php if ($sale['customer_phone']): ?>
                                        <br><small class="text-muted"><?php echo $sale['customer_phone']; ?></small>
                                    <?php endif; ?>
                                    <?php if ($sale['customer_email']): ?>
                                        <br><small class="text-muted"><?php echo $sale['customer_email']; ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Walk-in Customer</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-4"><strong>Served By:</strong></div>
                            <div class="col-8"><?php echo $sale['served_by_name']; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Payment Information -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="fas fa-credit-card me-2"></i>Payment Information
                    </div>
                    <div class="card-body">
                        <div class="row mb-2">
                            <div class="col-5"><strong>Payment Method:</strong></div>
                            <div class="col-7">
                                <span class="badge bg-info">
                                    <?php echo ucwords(str_replace('_', ' ', $sale['payment_method'])); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-5"><strong>Payment Status:</strong></div>
                            <div class="col-7">
                                <?php if ($sale['payment_status'] == 'paid'): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif ($sale['payment_status'] == 'partial'): ?>
                                    <span class="badge bg-warning">Partial</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Unpaid</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-5"><strong>Subtotal:</strong></div>
                            <div class="col-7">UGX <?php echo number_format($sale['subtotal'], 2); ?></div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-5"><strong>Tax:</strong></div>
                            <div class="col-7">UGX <?php echo number_format($sale['tax_amount'], 2); ?></div>
                        </div>
                        
                        <?php if ($sale['discount_amount'] > 0): ?>
                        <div class="row mb-2">
                            <div class="col-5"><strong>Discount:</strong></div>
                            <div class="col-7 text-success">- UGX <?php echo number_format($sale['discount_amount'], 2); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <hr>
                        
                        <div class="row mb-2">
                            <div class="col-5"><strong>Total Amount:</strong></div>
                            <div class="col-7"><h5 class="mb-0">UGX <?php echo number_format($sale['total_amount'], 2); ?></h5></div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-5"><strong>Amount Paid:</strong></div>
                            <div class="col-7 text-success">
                                <strong>UGX <?php echo number_format($sale['amount_paid'], 2); ?></strong>
                                <?php if ($sale['sale_type'] == 'credit' && count($credit_payments) > 0): ?>
                                    <br><small class="text-muted">(<?php echo count($credit_payments); ?> payment<?php echo count($credit_payments) > 1 ? 's' : ''; ?>)</small>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($sale['amount_due'] > 0): ?>
                        <div class="row">
                            <div class="col-5"><strong>Amount Due:</strong></div>
                            <div class="col-7 text-danger">
                                <h5 class="mb-0">UGX <?php echo number_format($sale['amount_due'], 2); ?></h5>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($sale['sale_type'] == 'credit' && $sale['amount_due'] > 0): ?>
        <!-- Credit Payment Form -->
        <div class="card mb-4">
            <div class="card-header bg-warning text-white">
                <i class="fas fa-money-bill-wave me-2"></i>Record Credit Payment
            </div>
            <div class="card-body">
                <form method="POST" id="creditPaymentForm">
                    <input type="hidden" name="action" value="record_payment">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Outstanding Balance</label>
                            <input type="text" class="form-control" value="UGX <?php echo number_format($sale['amount_due'], 2); ?>" readonly>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Payment Amount (UGX) *</label>
                            <input type="number" name="payment_amount" class="form-control" step="0.01" min="0.01" max="<?php echo $sale['amount_due']; ?>" required id="payment_amount_input">
                            <small class="text-muted">Max: UGX <?php echo number_format($sale['amount_due'], 2); ?></small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Payment Date *</label>
                            <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Payment Method *</label>
                            <select name="payment_method" class="form-select" required>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="check">Check</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reference Number</label>
                            <input type="text" name="reference_number" class="form-control" placeholder="Transaction ID, Check Number, etc.">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="1" placeholder="Payment notes..."></textarea>
                        </div>
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="text-muted">New Balance: </span>
                                    <span id="new_balance_display" class="fw-bold">UGX <?php echo number_format($sale['amount_due'], 2); ?></span>
                                </div>
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-check me-1"></i>Record Payment
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($sale['sale_type'] == 'credit' && count($credit_payments) > 0): ?>
        <!-- Credit Payment History -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-history me-2"></i>Payment History
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Recorded By</th>
                                <th>Notes</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($credit_payments as $payment): 
                            ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                <td class="text-end text-success">UGX <?php echo number_format($payment['payment_amount'], 2); ?></td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?>
                                    </span>
                                </td>
                                <td><?php echo $payment['reference_number'] ?: '-'; ?></td>
                                <td><?php echo $payment['recorded_by_name']; ?></td>
                                <td><?php echo $payment['notes'] ?: '-'; ?></td>
                                <td>
                                    <form method="POST" action="delete_payment.php" onsubmit="return confirm('Delete this payment record?');" style="display:inline;">
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
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="2" class="text-end">Total Paid:</th>
                                <th class="text-end text-success">UGX <?php echo number_format($total_paid, 2); ?></th>
                                <th colspan="5"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Sale Items -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list me-2"></i>Sale Items
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Product Code</th>
                                <th>Product Name</th>
                                <th class="text-center">Quantity</th>
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
                                $total_profit += $item['profit'];
                            ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td><?php echo $item['product_code']; ?></td>
                                <td><?php echo $item['product_name']; ?></td>
                                <td class="text-center"><?php echo $item['quantity']; ?></td>
                                <td class="text-end">UGX <?php echo number_format($item['unit_price'], 2); ?></td>
                                <td class="text-end">UGX <?php echo number_format($item['tax_amount'], 2); ?></td>
                                <td class="text-end"><strong>UGX <?php echo number_format($item['total_price'], 2); ?></strong></td>
                                <td class="text-end text-success">UGX <?php echo number_format($item['profit'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="6" class="text-end">Total:</th>
                                <th class="text-end">UGX <?php echo number_format($sale['total_amount'], 2); ?></th>
                                <th class="text-end text-success">UGX <?php echo number_format($total_profit, 2); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
jQuery(document).ready(function($) {
    // Calculate new balance when payment amount changes
    $('#payment_amount_input').on('input', function() {
        const outstandingBalance = <?php echo $sale['amount_due']; ?>;
        const paymentAmount = parseFloat($(this).val()) || 0;
        const newBalance = outstandingBalance - paymentAmount;
        
        $('#new_balance_display').text('UGX ' + newBalance.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,'));
        
        // Change color based on balance
        if (newBalance <= 0) {
            $('#new_balance_display').removeClass('text-danger text-warning').addClass('text-success');
        } else if (paymentAmount > 0) {
            $('#new_balance_display').removeClass('text-danger text-success').addClass('text-warning');
        } else {
            $('#new_balance_display').removeClass('text-warning text-success').addClass('text-danger');
        }
    });
    
    // Validate payment amount on form submission
    $('#creditPaymentForm').on('submit', function(e) {
        const paymentAmount = parseFloat($('#payment_amount_input').val()) || 0;
        const outstandingBalance = <?php echo $sale['amount_due']; ?>;
        
        if (paymentAmount <= 0) {
            e.preventDefault();
            alert('Payment amount must be greater than 0');
            return false;
        }
        
        if (paymentAmount > outstandingBalance) {
            e.preventDefault();
            alert('Payment amount cannot exceed outstanding balance');
            return false;
        }
    });
});
</script>