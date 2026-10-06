<?php
require_once '../config/config.php';
require_admin();

$page_title = "Credit Purchases";

// Get filter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Build query
$where = "";
if ($filter == 'unpaid') {
    $where = "WHERE sp.payment_status = 'unpaid'";
} elseif ($filter == 'partial') {
    $where = "WHERE sp.payment_status = 'partial'";
} elseif ($filter == 'paid') {
    $where = "WHERE sp.payment_status = 'paid'";
}

// Fetch credit purchases
$query = "
    SELECT sp.*, s.supplier_name, s.phone, s.email
    FROM supplier_purchases sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.supplier_id
    $where
    ORDER BY sp.purchase_date DESC
";
$stmt = $conn->query($query);
$purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [
    'total_unpaid' => $conn->query("SELECT SUM(amount_due) FROM supplier_purchases WHERE payment_status IN ('unpaid', 'partial')")->fetchColumn() ?? 0,
    'total_paid' => $conn->query("SELECT SUM(amount_paid) FROM supplier_purchases WHERE payment_status = 'paid'")->fetchColumn() ?? 0,
    'count_unpaid' => $conn->query("SELECT COUNT(*) FROM supplier_purchases WHERE payment_status IN ('unpaid', 'partial')")->fetchColumn() ?? 0,
];

$alert = get_alert();
?>

<?php include '../includes/header.php'; ?>
<?php //include '../includes/expiry_warning.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <h5 class="mb-0"><i class="fas fa-credit-card me-2"></i>Credit Purchases from Suppliers</h5>
    </div>
    
    <div class="content-area">
        <?php if ($alert): ?>
        <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
            <?php echo $alert['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <h6>Outstanding Credit</h6>
                        <h3>UGX <?php echo number_format($stats['total_unpaid'], 2); ?></h3>
                        <small><?php echo $stats['count_unpaid']; ?> unpaid purchases</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h6>Total Paid</h6>
                        <h3>UGX <?php echo number_format($stats['total_paid'], 2); ?></h3>
                        <small>Cleared credit purchases</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h6>Quick Actions</h6>
                        <a href="new-credit-purchase.php" class="btn btn-light btn-sm">
                            <i class="fas fa-plus me-1"></i>New Credit Purchase
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body">
                <div class="btn-group" role="group">
                    <a href="credit-purchases.php?filter=all" class="btn btn-<?php echo $filter == 'all' ? 'primary' : 'outline-primary'; ?>">
                        All Purchases
                    </a>
                    <a href="credit-purchases.php?filter=unpaid" class="btn btn-<?php echo $filter == 'unpaid' ? 'danger' : 'outline-danger'; ?>">
                        Unpaid
                    </a>
                    <a href="credit-purchases.php?filter=partial" class="btn btn-<?php echo $filter == 'partial' ? 'warning' : 'outline-warning'; ?>">
                        Partial Payment
                    </a>
                    <a href="credit-purchases.php?filter=paid" class="btn btn-<?php echo $filter == 'paid' ? 'success' : 'outline-success'; ?>">
                        Paid
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Purchases Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Credit Purchases (<?php echo count($purchases); ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Supplier</th>
                                <th>Date</th>
                                <th>Total Amount</th>
                                <th>Paid</th>
                                <th>Due</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purchases as $purchase): ?>
                            <tr>
                                <td><strong><?php echo $purchase['invoice_number'] ?? 'N/A'; ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($purchase['supplier_name']); ?><br>
                                    <small class="text-muted"><?php echo $purchase['phone']; ?></small>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($purchase['purchase_date'])); ?></td>
                                <td>UGX <?php echo number_format($purchase['total_amount'], 2); ?></td>
                                <td class="text-success">UGX <?php echo number_format($purchase['amount_paid'], 2); ?></td>
                                <td class="text-danger">UGX <?php echo number_format($purchase['amount_due'], 2); ?></td>
                                <td>
                                    <?php if ($purchase['due_date']): ?>
                                        <?php 
                                        $due = strtotime($purchase['due_date']);
                                        $today = strtotime(date('Y-m-d'));
                                        $overdue = $due < $today;
                                        ?>
                                        <span class="<?php echo $overdue ? 'text-danger' : ''; ?>">
                                            <?php echo date('M d, Y', strtotime($purchase['due_date'])); ?>
                                            <?php if ($overdue && $purchase['payment_status'] != 'paid'): ?>
                                                <br><span class="badge bg-danger">Overdue</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($purchase['payment_status'] == 'paid'): ?>
                                        <span class="badge bg-success">Paid</span>
                                    <?php elseif ($purchase['payment_status'] == 'partial'): ?>
                                        <span class="badge bg-warning">Partial</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Unpaid</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="view-credit-purchase.php?id=<?php echo $purchase['purchase_id']; ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($purchase['payment_status'] != 'paid'): ?>
                                    <button class="btn btn-sm btn-success" onclick="payCredit(<?php echo $purchase['purchase_id']; ?>, '<?php echo htmlspecialchars($purchase['supplier_name']); ?>', <?php echo $purchase['amount_due']; ?>)">
                                        <i class="fas fa-money-bill"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-money-bill me-2"></i>Record Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="record-supplier-payment.php">
                <input type="hidden" name="purchase_id" id="payment_purchase_id">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong>Supplier:</strong> <span id="payment_supplier"></span><br>
                        <strong>Amount Due:</strong> UGX <span id="payment_amount_due"></span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Amount *</label>
                        <input type="number" name="payment_amount" id="payment_amount" class="form-control" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Date *</label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method *</label>
                        <select name="payment_method" class="form-select" required>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="check">Check</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reference Number</label>
                        <input type="text" name="reference_number" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Record Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function payCredit(purchaseId, supplierName, amountDue) {
    document.getElementById('payment_purchase_id').value = purchaseId;
    document.getElementById('payment_supplier').textContent = supplierName;
    document.getElementById('payment_amount_due').textContent = amountDue.toFixed(2);
    document.getElementById('payment_amount').value = amountDue.toFixed(2);
    document.getElementById('payment_amount').max = amountDue;
    
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>