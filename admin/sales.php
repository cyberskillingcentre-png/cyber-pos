<?php
require_once '../config/config.php';
require_admin();

$page_title = "Sales Records";

// ============================================
// FILTERS
// ============================================
$date_from      = $_GET['date_from']      ?? date('Y-m-01');
$date_to        = $_GET['date_to']        ?? date('Y-m-d');
$payment_method = $_GET['payment_method'] ?? '';
$status         = $_GET['status']         ?? '';

// Sanity check date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

// ============================================
// BUILD QUERY
// ============================================
$query  = "
    SELECT 
        s.*,
        st.store_name,
        c.customer_name,
        u.full_name AS served_by_name
    FROM sales s
    LEFT JOIN stores st    ON s.store_id    = st.store_id
    LEFT JOIN customers c  ON s.customer_id = c.customer_id
    LEFT JOIN users u      ON s.served_by   = u.user_id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
";
$params = [$date_from, $date_to];

// Special filter: show only credit sales with outstanding balance
if ($payment_method === 'credit_outstanding') {
    $query .= " AND s.payment_method = 'credit' AND s.amount_due > 0";
} elseif ($payment_method !== '') {
    $query .= " AND s.payment_method = ?";
    $params[] = $payment_method;
}

if ($status !== '') {
    $query .= " AND s.status = ?";
    $params[] = $status;
}

$query .= " ORDER BY s.sale_date DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// SUMMARY STATISTICS
// ============================================
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) AS count,
        COALESCE(SUM(total_amount), 0) AS total
    FROM sales
    WHERE DATE(sale_date) BETWEEN ? AND ?
      AND status = 'completed'
");
$stmt->execute([$date_from, $date_to]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// Outstanding credit in the period
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) AS count,
        COALESCE(SUM(amount_due), 0) AS outstanding
    FROM sales
    WHERE DATE(sale_date) BETWEEN ? AND ?
      AND status = 'completed'
      AND payment_method = 'credit'
      AND amount_due > 0
");
$stmt->execute([$date_from, $date_to]);
$credit_summary = $stmt->fetch(PDO::FETCH_ASSOC);

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
                    <i class="fas fa-receipt me-2" style="color: var(--cyber-orange);"></i>
                    Sales Records
                </h5>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if ($credit_summary['count'] > 0): ?>
                <a href="Sales_Records.php?payment_method=credit_outstanding&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>"
                   class="btn btn-sm btn-warning">
                    <i class="fas fa-exclamation-circle me-1"></i>
                    <?php echo $credit_summary['count']; ?> Credit Sale(s) — UGX <?php echo number_format($credit_summary['outstanding'], 0); ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="content-area">
        <?php if ($alert): ?>
        <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
            <?php echo $alert['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- ============================================
             FILTERS
             ============================================ -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-filter me-2"></i>Filter Sales
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="">All Methods</option>
                            <option value="cash" <?php echo $payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="card" <?php echo $payment_method === 'card' ? 'selected' : ''; ?>>Card</option>
                            <option value="mobile_money" <?php echo $payment_method === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                            <option value="bank_transfer" <?php echo $payment_method === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="credit" <?php echo $payment_method === 'credit' ? 'selected' : ''; ?>>Credit (All)</option>
                            <option value="credit_outstanding" <?php echo $payment_method === 'credit_outstanding' ? 'selected' : ''; ?>>⚠ Credit — Outstanding Only</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="returned" <?php echo $status === 'returned' ? 'selected' : ''; ?>>Returned</option>
                            <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i>Filter
                        </button>
                        <a href="sales.php" class="btn btn-secondary">
                            <i class="fas fa-redo me-1"></i>Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============================================
             SUMMARY STATS
             ============================================ -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card purple">
                    <div class="icon"><i class="fas fa-shopping-cart"></i></div>
                    <div>
                        <h3><?php echo number_format($summary['count']); ?></h3>
                        <p>Total Sales</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card orange">
                    <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div>
                        <h3>UGX <?php echo format_currency($summary['total']); ?></h3>
                        <p>Total Revenue</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card <?php echo $credit_summary['outstanding'] > 0 ? 'red' : 'green'; ?>">
                    <div class="icon"><i class="fas fa-file-invoice-dollar"></i></div>
                    <div>
                        <h3>UGX <?php echo format_currency($credit_summary['outstanding']); ?></h3>
                        <p>Credit Outstanding (<?php echo $credit_summary['count']; ?>)</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================
             SALES TABLE
             ============================================ -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-list me-2"></i>All Sales (<?php echo count($sales); ?>)</span>
                <?php if ($payment_method === 'credit_outstanding'): ?>
                <span class="badge bg-warning text-dark">
                    <i class="fas fa-filter me-1"></i>Filtered: Credit Outstanding
                </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datatable table-hover" id="salesTable">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Store</th>
                                <th>Sale Type</th>
                                <th>Payment Method</th>
                                <th class="text-end">Total Amount</th>
                                <th class="text-end">Balance</th>
                                <th>Payment Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales as $sale): 
                                $balance = floatval($sale['amount_due'] ?? 0);
                                $is_credit = ($sale['payment_method'] === 'credit');
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($sale['invoice_number']); ?></strong></td>
                                <td>
                                    <div><?php echo date('d/m/Y', strtotime($sale['sale_date'])); ?></div>
                                    <small class="text-muted"><?php echo date('H:i', strtotime($sale['sale_date'])); ?></small>
                                </td>
                                <td>
                                    <?php if ($sale['customer_name']): ?>
                                        <strong><?php echo htmlspecialchars($sale['customer_name']); ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted">Walk-in</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($sale['store_name'] ?? '-'); ?></td>
                                <td>
                                    <?php if ($sale['sale_type'] === 'credit'): ?>
                                        <span class="badge bg-danger"><i class="fas fa-credit-card me-1"></i>Credit</span>
                                    <?php else: ?>
                                        <span class="badge bg-success"><i class="fas fa-money-bill-wave me-1"></i>Cash</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php echo ucwords(str_replace('_', ' ', $sale['payment_method'])); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <strong>UGX <?php echo number_format($sale['total_amount'], 2); ?></strong>
                                </td>
                                <td class="text-end">
                                    <?php if ($is_credit): ?>
                                        <strong class="<?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">
                                            UGX <?php echo number_format($balance, 2); ?>
                                        </strong>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($sale['payment_status'] === 'paid'): ?>
                                        <span class="badge bg-success">Paid</span>
                                    <?php elseif ($sale['payment_status'] === 'partial'): ?>
                                        <span class="badge bg-warning text-dark">Partial</span>
                                    <?php elseif ($sale['payment_status'] === 'unpaid'): ?>
                                        <span class="badge bg-danger">Unpaid</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo ucfirst($sale['payment_status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap">
                                    <!-- View -->
                                    <a href="view-sale.php?id=<?php echo (int)$sale['sale_id']; ?>"
                                       class="btn btn-sm btn-info" title="View Sale">
                                        <i class="fas fa-eye"></i>
                                    </a>

                                    <!-- Record Payment (only for credit sales with balance) -->
                                    <?php if ($is_credit && $balance > 0): ?>
                                    <a href="record_credit_payment.php?id=<?php echo (int)$sale['sale_id']; ?>"
                                       class="btn btn-sm btn-warning" title="Record Payment">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </a>
                                    <?php endif; ?>

                                    <!-- Invoice / Receipt -->
                                    <?php if ($is_credit): ?>
                                        <a href="credit-invoice.php?id=<?php echo (int)$sale['sale_id']; ?>"
                                           class="btn btn-sm btn-danger" target="_blank" title="Credit Invoice">
                                            <i class="fas fa-file-invoice-dollar"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="receipt.php?id=<?php echo (int)$sale['sale_id']; ?>"
                                           class="btn btn-sm btn-primary" target="_blank" title="Receipt">
                                            <i class="fas fa-receipt"></i>
                                        </a>
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

<?php include '../includes/footer.php'; ?>