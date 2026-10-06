<?php
require_once '../config/config.php';
require_admin();
$page_title = "Sales Records";
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$payment_method = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$query = "SELECT s.*, st.store_name, c.customer_name, u.full_name as served_by_name FROM sales s LEFT JOIN stores st ON s.store_id = st.store_id LEFT JOIN customers c ON s.customer_id = c.customer_id LEFT JOIN users u ON s.served_by = u.user_id WHERE DATE(s.sale_date) BETWEEN ? AND ?";
$params = [$date_from, $date_to];
if ($payment_method) { $query .= " AND s.payment_method = ?"; $params[] = $payment_method; }
if ($status) { $query .= " AND s.status = ?"; $params[] = $status; }
$query .= " ORDER BY s.sale_date DESC";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$sales = $stmt->fetchAll();
$stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM sales WHERE DATE(sale_date) BETWEEN ? AND ? AND status = 'completed'");
$stmt->execute([$date_from, $date_to]);
$summary = $stmt->fetch();
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>
<div class="main-content">
    <div class="top-navbar">

         <button class="btn btn-link d-md-none sidebar-toggle">
                    <i class="fas fa-bars"></i>
                </button><h5 class="mb-0"><i class="fas fa-receipt me-2"></i>Sales Records</h5></div>
    <div class="content-area">
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-filter me-2"></i>Filter Sales</div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="">All Methods</option>
                            <option value="cash" <?php echo $payment_method == 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="card" <?php echo $payment_method == 'card' ? 'selected' : ''; ?>>Card</option>
                            <option value="mobile_money" <?php echo $payment_method == 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="returned" <?php echo $status == 'returned' ? 'selected' : ''; ?>>Returned</option>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i>Filter</button>
                        <a href="Sales_Records" class="btn btn-secondary"><i class="fas fa-redo me-1"></i>Reset</a>
                    </div>
                </form>
            </div>
        </div>
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="stat-card purple">
                    <div class="icon"><i class="fas fa-shopping-cart"></i></div>
                    <h3><?php echo number_format($summary['count']); ?></h3>
                    <p>Total Sales</p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-card orange">
                    <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                    <h3>UGX <?php echo format_currency($summary['total']); ?></h3>
                    <p>Total Revenue</p>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="fas fa-list me-2"></i>All Sales</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datatable table-hover">
    <thead>
        <tr>
            <th>Invoice #</th>
            <th>Date</th>
            <th>Customer</th>
            <th>Store</th>
            <th>Sale Type</th>
            <th>Payment Method</th>
            <th>Total Amount</th>
            <th>Payment Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($sales as $sale): ?>
        <tr>
            <td><strong><?php echo $sale['invoice_number']; ?></strong></td>
            <td><?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?></td>
            <td><?php echo $sale['customer_name'] ?? 'Walk-in'; ?></td>
            <td><?php echo $sale['store_name']; ?></td>
            <td>
                <?php if ($sale['sale_type'] == 'credit'): ?>
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
            <td><strong>UGX <?php echo number_format($sale['total_amount'], 2); ?></strong></td>
            <td>
                <?php if ($sale['payment_status'] == 'paid'): ?>
                    <span class="badge bg-success">Paid</span>
                <?php elseif ($sale['payment_status'] == 'partial'): ?>
                    <span class="badge bg-warning">Partial</span>
                <?php else: ?>
                    <span class="badge bg-danger">Unpaid</span>
                <?php endif; ?>
            </td>
            <td>
                <a href="View_Sales?id=<?php echo $sale['sale_id']; ?>" class="btn btn-sm btn-info" title="View">
                    <i class="fas fa-eye"></i>
                </a>
                <?php if ($sale['sale_type'] == 'credit'): ?>
                <a href="Credit_Invoice?id=<?php echo $sale['sale_id']; ?>" class="btn btn-sm btn-danger" target="_blank" title="Credit Invoice">
                    <i class="fas fa-file-invoice-dollar"></i>
                </a>
                <?php else: ?>
                <a href="Receipt?id=<?php echo $sale['sale_id']; ?>" class="btn btn-sm btn-primary" target="_blank" title="Receipt">
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
