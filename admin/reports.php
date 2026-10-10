<?php
require_once '../config/config.php';
require_admin();

$page_title = "Advanced Reports & Analytics";

// ============================================
// FILTERS
// ============================================
$date_from      = $_GET['date_from'] ?? date('Y-m-01');
$date_to        = $_GET['date_to']   ?? date('Y-m-d');
$store_filter   = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
$payment_filter = $_GET['payment_method'] ?? '';

// ============================================
// WHERE CLAUSE
// ============================================
$where_conditions = ["DATE(s.sale_date) BETWEEN ? AND ?", "s.status = 'completed'"];
$params = [$date_from, $date_to];

if ($store_filter > 0) {
    $where_conditions[] = "s.store_id = ?";
    $params[] = $store_filter;
}
if ($payment_filter !== '') {
    $where_conditions[] = "s.payment_method = ?";
    $params[] = $payment_filter;
}
$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// ============================================
// STORES FOR FILTER
// ============================================
$stmt = $conn->query("SELECT store_id, store_name FROM stores WHERE status = 'active' ORDER BY store_name");
$stores = $stmt->fetchAll();

// ============================================
// SUMMARY STATISTICS
// ============================================
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT s.sale_id) AS total_sales,
        COUNT(DISTINCT s.customer_id) AS unique_customers,
        COALESCE(SUM(s.total_amount), 0) AS total_revenue,
        COALESCE(SUM(s.discount_amount), 0) AS total_discounts,
        COALESCE(SUM(s.tax_amount), 0) AS total_tax,
        COALESCE(SUM(si.profit), 0) AS total_profit,
        COALESCE(AVG(s.total_amount), 0) AS avg_sale_amount
    FROM sales s
    LEFT JOIN sale_items si ON s.sale_id = si.sale_id
    $where_clause
");
$stmt->execute($params);
$summary = $stmt->fetch();

$profit_margin = $summary['total_revenue'] > 0
    ? ($summary['total_profit'] / $summary['total_revenue']) * 100
    : 0;

// ============================================
// DAILY SALES TREND
// ============================================
$stmt = $conn->prepare("
    SELECT 
        DATE(s.sale_date) AS sale_day,
        COUNT(DISTINCT s.sale_id) AS sales_count,
        COALESCE(SUM(s.total_amount), 0) AS revenue,
        COALESCE(SUM(si.profit), 0) AS profit
    FROM sales s
    LEFT JOIN sale_items si ON s.sale_id = si.sale_id
    $where_clause
    GROUP BY DATE(s.sale_date)
    ORDER BY sale_day
");
$stmt->execute($params);
$daily_trend = $stmt->fetchAll();

// ============================================
// TOP SELLING PRODUCTS
// ============================================
$stmt = $conn->prepare("
    SELECT 
        p.product_code,
        p.product_name,
        c.category_name,
        SUM(si.quantity) AS total_sold,
        COALESCE(SUM(si.total_price), 0) AS revenue,
        COALESCE(SUM(si.profit), 0) AS profit,
        COUNT(DISTINCT s.sale_id) AS transaction_count
    FROM sale_items si
    JOIN products p ON si.product_id = p.product_id
    LEFT JOIN categories c ON p.category_id = c.category_id
    JOIN sales s ON si.sale_id = s.sale_id
    $where_clause
    GROUP BY p.product_id
    ORDER BY total_sold DESC
    LIMIT 20
");
$stmt->execute($params);
$top_products = $stmt->fetchAll();

// ============================================
// CATEGORY PERFORMANCE
// ============================================
$stmt = $conn->prepare("
    SELECT 
        COALESCE(c.category_name, 'Uncategorized') AS category_name,
        COUNT(DISTINCT p.product_id) AS product_count,
        SUM(si.quantity) AS total_sold,
        COALESCE(SUM(si.total_price), 0) AS revenue,
        COALESCE(SUM(si.profit), 0) AS profit
    FROM sale_items si
    JOIN products p ON si.product_id = p.product_id
    LEFT JOIN categories c ON p.category_id = c.category_id
    JOIN sales s ON si.sale_id = s.sale_id
    $where_clause
    GROUP BY c.category_id
    ORDER BY revenue DESC
");
$stmt->execute($params);
$category_performance = $stmt->fetchAll();

// ============================================
// TOP SELLERS
// ============================================
$stmt = $conn->prepare("
    SELECT 
        u.full_name,
        u.role,
        COUNT(DISTINCT s.sale_id) AS sales_count,
        COALESCE(SUM(s.total_amount), 0) AS total_sales,
        COALESCE(SUM(si.profit), 0) AS profit,
        COALESCE(AVG(s.total_amount), 0) AS avg_sale_value
    FROM sales s
    JOIN users u ON s.served_by = u.user_id
    LEFT JOIN sale_items si ON s.sale_id = si.sale_id
    $where_clause
    GROUP BY u.user_id
    ORDER BY total_sales DESC
    LIMIT 15
");
$stmt->execute($params);
$top_sellers = $stmt->fetchAll();

// ============================================
// TOP CUSTOMERS
// ============================================
$stmt = $conn->query("SELECT COUNT(*) FROM customers");
$has_customers = $stmt->fetchColumn() > 0;

$top_customers = [];
if ($has_customers) {
    $stmt = $conn->prepare("
        SELECT 
            c.customer_name,
            c.phone,
            c.email,
            COUNT(DISTINCT s.sale_id) AS purchase_count,
            COALESCE(SUM(s.total_amount), 0) AS total_spent,
            COALESCE(AVG(s.total_amount), 0) AS avg_purchase,
            MAX(s.sale_date) AS last_purchase
        FROM sales s
        JOIN customers c ON s.customer_id = c.customer_id
        $where_clause
        GROUP BY c.customer_id
        ORDER BY total_spent DESC
        LIMIT 20
    ");
    $stmt->execute($params);
    $top_customers = $stmt->fetchAll();
}

// ============================================
// PAYMENT METHOD BREAKDOWN
// ============================================
$stmt = $conn->prepare("
    SELECT 
        s.payment_method,
        COUNT(DISTINCT s.sale_id) AS transaction_count,
        COALESCE(SUM(s.total_amount), 0) AS total_amount,
        COALESCE(AVG(s.total_amount), 0) AS avg_amount
    FROM sales s
    $where_clause
    GROUP BY s.payment_method
    ORDER BY total_amount DESC
");
$stmt->execute($params);
$payment_methods = $stmt->fetchAll();

// ============================================
// STORE PERFORMANCE
// ============================================
$stmt = $conn->prepare("
    SELECT 
        st.store_name,
        st.store_code,
        COUNT(DISTINCT s.sale_id) AS sales_count,
        COALESCE(SUM(s.total_amount), 0) AS revenue,
        COALESCE(SUM(si.profit), 0) AS profit,
        COALESCE(AVG(s.total_amount), 0) AS avg_sale
    FROM sales s
    JOIN stores st ON s.store_id = st.store_id
    LEFT JOIN sale_items si ON s.sale_id = si.sale_id
    $where_clause
    GROUP BY st.store_id
    ORDER BY revenue DESC
");
$stmt->execute($params);
$store_performance = $stmt->fetchAll();

// ============================================
// HOURLY SALES PATTERN
// ============================================
$stmt = $conn->prepare("
    SELECT 
        HOUR(s.sale_date) AS sale_hour,
        COUNT(DISTINCT s.sale_id) AS sales_count,
        COALESCE(SUM(s.total_amount), 0) AS revenue
    FROM sales s
    $where_clause
    GROUP BY HOUR(s.sale_date)
    ORDER BY sale_hour
");
$stmt->execute($params);
$hourly_pattern = $stmt->fetchAll();

// ============================================
// LOW STOCK ALERT
// ============================================
$stmt = $conn->query("
    SELECT 
        p.product_code,
        p.product_name,
        s.store_name,
        COALESCE(SUM(si.quantity), 0) AS current_stock,
        p.reorder_level
    FROM products p
    CROSS JOIN stores s
    LEFT JOIN store_inventory si 
        ON p.product_id = si.product_id 
        AND s.store_id = si.store_id 
        AND si.quantity > 0
    WHERE p.status = 'active' AND s.status = 'active'
    GROUP BY p.product_id, s.store_id
    HAVING current_stock <= p.reorder_level
    ORDER BY current_stock ASC
    LIMIT 20
");
$low_stock_items = $stmt->fetchAll();

// ============================================
// CREDIT SALES TRACKING
// ============================================
$stmt = $conn->prepare("
    SELECT 
        'Walk-in Customer' AS customer_name,
        COUNT(s.sale_id) AS credit_sales_count,
        COALESCE(SUM(s.total_amount), 0) AS total_credit,
        COALESCE(SUM(s.amount_paid), 0) AS amount_paid,
        COALESCE(SUM(s.amount_due), 0) AS amount_due
    FROM sales s
    WHERE s.payment_method = 'credit'
      AND DATE(s.sale_date) BETWEEN ? AND ?
      AND s.status = 'completed'
      AND s.customer_id IS NULL
    UNION ALL
    SELECT 
        c.customer_name,
        COUNT(s.sale_id) AS credit_sales_count,
        COALESCE(SUM(s.total_amount), 0) AS total_credit,
        COALESCE(SUM(s.amount_paid), 0) AS amount_paid,
        COALESCE(SUM(s.amount_due), 0) AS amount_due
    FROM sales s
    JOIN customers c ON s.customer_id = c.customer_id
    WHERE s.payment_method = 'credit'
      AND DATE(s.sale_date) BETWEEN ? AND ?
      AND s.status = 'completed'
    GROUP BY c.customer_id
    ORDER BY amount_due DESC
    LIMIT 20
");
$stmt->execute([$date_from, $date_to, $date_from, $date_to]);
$credit_tracking = $stmt->fetchAll();
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<style>
/* ============================================
   MINI STAT CARDS (All Sales totals bar)
   ============================================ */
.mini-stat {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    background: #fff;
    border-radius: 10px;
    border-left: 4px solid #E2E8F0;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
    transition: transform 0.2s ease;
}
.mini-stat:hover { transform: translateY(-2px); }

.mini-stat-purple { border-left-color: #6B46C1; }
.mini-stat-green  { border-left-color: #48BB78; }
.mini-stat-blue   { border-left-color: #4299E1; }
.mini-stat-orange { border-left-color: #ED8936; }

.mini-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}
.mini-stat-purple .mini-stat-icon { background: rgba(107, 70, 193, 0.1); color: #6B46C1; }
.mini-stat-green  .mini-stat-icon { background: rgba(72, 187, 120, 0.1); color: #48BB78; }
.mini-stat-blue   .mini-stat-icon { background: rgba(66, 153, 225, 0.1); color: #4299E1; }
.mini-stat-orange .mini-stat-icon { background: rgba(237, 137, 54, 0.1); color: #ED8936; }

.mini-stat-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #718096;
    margin-bottom: 2px;
}
.mini-stat-value {
    font-size: 1.1rem;
    font-weight: 800;
    color: #1A202C;
    font-family: 'Courier New', monospace;
    line-height: 1.2;
}
</style>

<div class="main-content">
    <div class="top-navbar">
        <button class="btn btn-link d-md-none sidebar-toggle">
            <i class="fas fa-bars"></i>
        </button>
        <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Advanced Reports &amp; Analytics</h5>
    </div>

    <div class="content-area">

        <!-- ============================================
             FILTERS
             ============================================ -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-filter me-2"></i>Report Filters
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Store</label>
                        <select name="store_id" class="form-select">
                            <option value="0">All Stores</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo (int)$store['store_id']; ?>" <?php echo $store_filter == $store['store_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store['store_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="">All Methods</option>
                            <?php foreach (['cash','mobile_money','credit','card','bank_transfer'] as $pm): ?>
                                <option value="<?php echo $pm; ?>" <?php echo $payment_filter === $pm ? 'selected' : ''; ?>>
                                    <?php echo ucwords(str_replace('_', ' ', $pm)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary d-block w-100">
                            <i class="fas fa-search me-1"></i>Generate
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============================================
             SUMMARY STATISTICS
             ============================================ -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0">Total Sales</h6>
                                <h3 class="mb-0"><?php echo number_format($summary['total_sales']); ?></h3>
                            </div>
                            <div class="fs-1"><i class="fas fa-shopping-cart"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0">Total Revenue</h6>
                                <h3 class="mb-0">UGX <?php echo number_format($summary['total_revenue'], 0); ?></h3>
                            </div>
                            <div class="fs-1"><i class="fas fa-money-bill-wave"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0">Total Profit</h6>
                                <h3 class="mb-0">UGX <?php echo number_format($summary['total_profit'], 0); ?></h3>
                            </div>
                            <div class="fs-1"><i class="fas fa-chart-line"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0">Profit Margin</h6>
                                <h3 class="mb-0"><?php echo number_format($profit_margin, 1); ?>%</h3>
                            </div>
                            <div class="fs-1"><i class="fas fa-percentage"></i></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card border-primary">
                    <div class="card-body">
                        <small class="text-muted">Average Sale Value</small>
                        <h4 class="text-primary">UGX <?php echo number_format($summary['avg_sale_amount'], 0); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-success">
                    <div class="card-body">
                        <small class="text-muted">Unique Customers</small>
                        <h4 class="text-success"><?php echo number_format($summary['unique_customers']); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-warning">
                    <div class="card-body">
                        <small class="text-muted">Total Discounts</small>
                        <h4 class="text-warning">UGX <?php echo number_format($summary['total_discounts'], 0); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-info">
                    <div class="card-body">
                        <small class="text-muted">Total Tax Collected</small>
                        <h4 class="text-info">UGX <?php echo number_format($summary['total_tax'], 0); ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================
             TABS
             ============================================ -->
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#products-tab">
                    <i class="fas fa-box me-1"></i>Products
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#all-sales-tab">
                    <i class="fas fa-list me-1"></i>All Sales
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#sellers-tab">
                    <i class="fas fa-user-tie me-1"></i>Sellers
                </a>
            </li>
            <?php if ($has_customers): ?>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#customers-tab">
                    <i class="fas fa-users me-1"></i>Customers
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#stores-tab">
                    <i class="fas fa-store me-1"></i>Stores
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#payments-tab">
                    <i class="fas fa-credit-card me-1"></i>Payments
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#trends-tab">
                    <i class="fas fa-chart-area me-1"></i>Trends
                </a>
            </li>
        </ul>

        <div class="tab-content">

            <!-- ============================================
                 PRODUCTS TAB
                 ============================================ -->
            <div id="products-tab" class="tab-pane fade show active">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-fire me-2"></i>Top Selling Products</span>
                        <div>
                            <button onclick="exportTable('products', 'excel')" class="btn btn-success btn-sm">
                                <i class="fas fa-file-excel me-1"></i>Excel
                            </button>
                            <button onclick="exportTable('products', 'pdf')" class="btn btn-danger btn-sm">
                                <i class="fas fa-file-pdf me-1"></i>PDF
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table datatable table-hover" id="products-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product Code</th>
                                        <th>Product Name</th>
                                        <th>Category</th>
                                        <th>Qty Sold</th>
                                        <th>Revenue</th>
                                        <th>Profit</th>
                                        <th>Margin %</th>
                                        <th>Transactions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($top_products)): ?>
                                        <tr><td colspan="9" class="text-center text-muted">No sales data for the selected period</td></tr>
                                    <?php else: ?>
                                        <?php $i = 1; foreach ($top_products as $product): ?>
                                        <?php $margin = $product['revenue'] > 0 ? ($product['profit'] / $product['revenue']) * 100 : 0; ?>
                                        <tr>
                                            <td><?php echo $i++; ?></td>
                                            <td><?php echo htmlspecialchars($product['product_code']); ?></td>
                                            <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                            <td><?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo number_format($product['total_sold']); ?></td>
                                            <td>UGX <?php echo number_format($product['revenue'], 0); ?></td>
                                            <td>UGX <?php echo number_format($product['profit'], 0); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $margin > 30 ? 'success' : ($margin > 15 ? 'warning' : 'danger'); ?>">
                                                    <?php echo number_format($margin, 1); ?>%
                                                </span>
                                            </td>
                                            <td><?php echo number_format($product['transaction_count']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (!empty($category_performance)): ?>
                        <h6 class="mt-4">Category Performance</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover" id="categories-table">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th>Products</th>
                                        <th>Items Sold</th>
                                        <th>Revenue</th>
                                        <th>Profit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($category_performance as $cat): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                        <td><?php echo number_format($cat['product_count']); ?></td>
                                        <td><?php echo number_format($cat['total_sold']); ?></td>
                                        <td>UGX <?php echo number_format($cat['revenue'], 0); ?></td>
                                        <td>UGX <?php echo number_format($cat['profit'], 0); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ============================================
                 ALL SALES TAB
                 ============================================ -->
            <div id="all-sales-tab" class="tab-pane fade">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>
                            <i class="fas fa-list me-2"></i>All Sales Transactions
                            <span class="badge bg-info ms-2" id="all-sales-total">—</span>
                        </span>
                        <div class="d-flex gap-2">
                            <a href="export_all_sales.php?date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&store_id=<?php echo (int)$store_filter; ?>&payment_method=<?php echo urlencode($payment_filter); ?>"
                               class="btn btn-success btn-sm">
                                <i class="fas fa-file-csv me-1"></i>Export CSV
                            </a>
                            <button onclick="reloadAllSales(1)" class="btn btn-primary btn-sm">
                                <i class="fas fa-sync me-1"></i>Refresh
                            </button>
                        </div>
                    </div>
                    <div class="card-body">

                        <!-- ============================================
                             AGGREGATE TOTALS BAR
                             ============================================ -->
                        <div class="row g-3 mb-3" id="all-sales-totals">
                            <div class="col-md-3">
                                <div class="mini-stat mini-stat-purple">
                                    <div class="mini-stat-icon"><i class="fas fa-receipt"></i></div>
                                    <div>
                                        <div class="mini-stat-label">Transactions</div>
                                        <div class="mini-stat-value" id="all-total-transactions">—</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mini-stat mini-stat-green">
                                    <div class="mini-stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                                    <div>
                                        <div class="mini-stat-label">Total Sales</div>
                                        <div class="mini-stat-value" id="all-total-revenue">—</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mini-stat mini-stat-blue">
                                    <div class="mini-stat-icon"><i class="fas fa-chart-line"></i></div>
                                    <div>
                                        <div class="mini-stat-label">Total Profit</div>
                                        <div class="mini-stat-value" id="all-total-profit">—</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mini-stat mini-stat-orange">
                                    <div class="mini-stat-icon"><i class="fas fa-boxes"></i></div>
                                    <div>
                                        <div class="mini-stat-label">Items Sold</div>
                                        <div class="mini-stat-value" id="all-total-items">—</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Inline filters -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <input type="text" id="all-sales-search" class="form-control form-control-sm"
                                       placeholder="Search invoice, product, customer...">
                            </div>
                            <div class="col-md-2">
                                <select id="all-sales-per-page" class="form-select form-select-sm">
                                    <option value="25">25 / page</option>
                                    <option value="50" selected>50 / page</option>
                                    <option value="100">100 / page</option>
                                    <option value="200">200 / page</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button onclick="reloadAllSales(1)" class="btn btn-sm btn-outline-primary w-100">
                                    <i class="fas fa-search me-1"></i>Apply
                                </button>
                            </div>
                        </div>

                        <!-- Table -->
                        <div class="table-responsive">
                            <table class="table table-sm table-hover" id="all-sales-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Date</th>
                                        <th>Store</th>
                                        <th>Served By</th>
                                        <th>Customer</th>
                                        <th>Product</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Unit</th>
                                        <th class="text-end">Discount</th>
                                        <th class="text-end">Total</th>
                                        <th class="text-end">Profit</th>
                                        <th>Payment</th>
                                    </tr>
                                </thead>
                                <tbody id="all-sales-tbody">
                                    <tr>
                                        <td colspan="12" class="text-center text-muted py-4">
                                            <i class="fas fa-info-circle me-2"></i>Click the "All Sales" tab to load transactions
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <nav>
                            <ul class="pagination pagination-sm justify-content-center mb-0" id="all-sales-pagination"></ul>
                        </nav>
                    </div>
                </div>
            </div>

            <!-- ============================================
                 SELLERS TAB
                 ============================================ -->
            <div id="sellers-tab" class="tab-pane fade">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-trophy me-2"></i>Top Performing Sellers</span>
                        <div>
                            <button onclick="exportTable('sellers', 'excel')" class="btn btn-success btn-sm">
                                <i class="fas fa-file-excel me-1"></i>Excel
                            </button>
                            <button onclick="exportTable('sellers', 'pdf')" class="btn btn-danger btn-sm">
                                <i class="fas fa-file-pdf me-1"></i>PDF
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table datatable table-hover" id="sellers-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Employee Name</th>
                                        <th>Role</th>
                                        <th>Sales Count</th>
                                        <th>Total Sales</th>
                                        <th>Profit Generated</th>
                                        <th>Avg Sale Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($top_sellers)): ?>
                                        <tr><td colspan="7" class="text-center text-muted">No sales data</td></tr>
                                    <?php else: ?>
                                        <?php $i = 1; foreach ($top_sellers as $seller): ?>
                                        <tr>
                                            <td><?php echo $i++; ?></td>
                                            <td><?php echo htmlspecialchars($seller['full_name']); ?></td>
                                            <td><span class="badge bg-primary"><?php echo htmlspecialchars($seller['role']); ?></span></td>
                                            <td><?php echo number_format($seller['sales_count']); ?></td>
                                            <td>UGX <?php echo number_format($seller['total_sales'], 0); ?></td>
                                            <td>UGX <?php echo number_format($seller['profit'], 0); ?></td>
                                            <td>UGX <?php echo number_format($seller['avg_sale_value'], 0); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================
                 CUSTOMERS TAB
                 ============================================ -->
            <?php if ($has_customers): ?>
            <div id="customers-tab" class="tab-pane fade">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-crown me-2"></i>Top Customers</span>
                        <div>
                            <button onclick="exportTable('customers', 'excel')" class="btn btn-success btn-sm">
                                <i class="fas fa-file-excel me-1"></i>Excel
                            </button>
                            <button onclick="exportTable('customers', 'pdf')" class="btn btn-danger btn-sm">
                                <i class="fas fa-file-pdf me-1"></i>PDF
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($top_customers)): ?>
                        <div class="table-responsive">
                            <table class="table datatable table-hover" id="customers-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Customer Name</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th>Purchases</th>
                                        <th>Total Spent</th>
                                        <th>Avg Purchase</th>
                                        <th>Last Purchase</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 1; foreach ($top_customers as $customer): ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['phone'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($customer['email'] ?? 'N/A'); ?></td>
                                        <td><?php echo number_format($customer['purchase_count']); ?></td>
                                        <td>UGX <?php echo number_format($customer['total_spent'], 0); ?></td>
                                        <td>UGX <?php echo number_format($customer['avg_purchase'], 0); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($customer['last_purchase'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="alert alert-info">No customer data for the selected period</div>
                        <?php endif; ?>

                        <?php if (!empty($credit_tracking)): ?>
                        <h6 class="mt-4">Credit Sales Tracking</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover" id="credit-table">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Credit Sales</th>
                                        <th>Total Credit</th>
                                        <th>Amount Paid</th>
                                        <th>Amount Due</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($credit_tracking as $credit): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($credit['customer_name']); ?></td>
                                        <td><?php echo number_format($credit['credit_sales_count']); ?></td>
                                        <td>UGX <?php echo number_format($credit['total_credit'], 0); ?></td>
                                        <td>UGX <?php echo number_format($credit['amount_paid'], 0); ?></td>
                                        <td>UGX <?php echo number_format($credit['amount_due'], 0); ?></td>
                                        <td>
                                            <?php if ($credit['amount_due'] > 0): ?>
                                                <span class="badge bg-warning">Pending</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Paid</span>
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
            <?php endif; ?>

            <!-- ============================================
                 STORES TAB
                 ============================================ -->
            <div id="stores-tab" class="tab-pane fade">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-store-alt me-2"></i>Store Performance</span>
                        <div>
                            <button onclick="exportTable('stores', 'excel')" class="btn btn-success btn-sm">
                                <i class="fas fa-file-excel me-1"></i>Excel
                            </button>
                            <button onclick="exportTable('stores', 'pdf')" class="btn btn-danger btn-sm">
                                <i class="fas fa-file-pdf me-1"></i>PDF
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table datatable table-hover" id="stores-table">
                                <thead>
                                    <tr>
                                        <th>Store Code</th>
                                        <th>Store Name</th>
                                        <th>Sales Count</th>
                                        <th>Revenue</th>
                                        <th>Profit</th>
                                        <th>Avg Sale</th>
                                        <th>Performance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($store_performance)): ?>
                                        <tr><td colspan="7" class="text-center text-muted">No sales data</td></tr>
                                    <?php else: ?>
                                        <?php $total_store_revenue = array_sum(array_column($store_performance, 'revenue')); ?>
                                        <?php foreach ($store_performance as $store): ?>
                                        <?php $performance = $total_store_revenue > 0 ? ($store['revenue'] / $total_store_revenue) * 100 : 0; ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($store['store_code']); ?></td>
                                            <td><?php echo htmlspecialchars($store['store_name']); ?></td>
                                            <td><?php echo number_format($store['sales_count']); ?></td>
                                            <td>UGX <?php echo number_format($store['revenue'], 0); ?></td>
                                            <td>UGX <?php echo number_format($store['profit'], 0); ?></td>
                                            <td>UGX <?php echo number_format($store['avg_sale'], 0); ?></td>
                                            <td>
                                                <div class="progress">
                                                    <div class="progress-bar bg-success" style="width: <?php echo $performance; ?>%">
                                                        <?php echo number_format($performance, 1); ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================
                 PAYMENTS TAB
                 ============================================ -->
            <div id="payments-tab" class="tab-pane fade">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-credit-card me-2"></i>Payment Method Analysis</span>
                        <div>
                            <button onclick="exportTable('payments', 'excel')" class="btn btn-success btn-sm">
                                <i class="fas fa-file-excel me-1"></i>Excel
                            </button>
                            <button onclick="exportTable('payments', 'pdf')" class="btn btn-danger btn-sm">
                                <i class="fas fa-file-pdf me-1"></i>PDF
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($payment_methods)): ?>
                            <?php 
                            $payment_icons = [
                                'cash'          => 'money-bill-wave',
                                'mobile_money'  => 'mobile-alt',
                                'credit'        => 'credit-card',
                                'card'          => 'credit-card',
                                'bank_transfer' => 'university',
                            ];
                            $payment_colors = [
                                'cash'          => 'success',
                                'mobile_money'  => 'primary',
                                'credit'        => 'warning',
                                'card'          => 'info',
                                'bank_transfer' => 'secondary',
                            ];
                            ?>
                            <div class="row mb-4">
                                <?php foreach ($payment_methods as $pm): ?>
                                <div class="col-md-4 mb-3">
                                    <div class="card border-<?php echo $payment_colors[$pm['payment_method']] ?? 'secondary'; ?>">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="text-<?php echo $payment_colors[$pm['payment_method']] ?? 'secondary'; ?>">
                                                        <?php echo ucwords(str_replace('_', ' ', $pm['payment_method'])); ?>
                                                    </h6>
                                                    <h4>UGX <?php echo number_format($pm['total_amount'], 0); ?></h4>
                                                    <small class="text-muted">
                                                        <?php echo number_format($pm['transaction_count']); ?> transactions
                                                    </small>
                                                </div>
                                                <div class="fs-1 text-<?php echo $payment_colors[$pm['payment_method']] ?? 'secondary'; ?>">
                                                    <i class="fas fa-<?php echo $payment_icons[$pm['payment_method']] ?? 'dollar-sign'; ?>"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover" id="payments-table">
                                    <thead>
                                        <tr>
                                            <th>Payment Method</th>
                                            <th>Transactions</th>
                                            <th>Total Amount</th>
                                            <th>Avg Transaction</th>
                                            <th>% of Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $total_payment_amount = array_sum(array_column($payment_methods, 'total_amount')); ?>
                                        <?php foreach ($payment_methods as $pm): ?>
                                        <?php $percentage = $total_payment_amount > 0 ? ($pm['total_amount'] / $total_payment_amount) * 100 : 0; ?>
                                        <tr>
                                            <td><?php echo ucwords(str_replace('_', ' ', $pm['payment_method'])); ?></td>
                                            <td><?php echo number_format($pm['transaction_count']); ?></td>
                                            <td>UGX <?php echo number_format($pm['total_amount'], 0); ?></td>
                                            <td>UGX <?php echo number_format($pm['avg_amount'], 0); ?></td>
                                            <td>
                                                <div class="progress">
                                                    <div class="progress-bar" style="width: <?php echo $percentage; ?>%">
                                                        <?php echo number_format($percentage, 1); ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">No payment data for the selected period</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ============================================
                 TRENDS TAB
                 ============================================ -->
            <div id="trends-tab" class="tab-pane fade">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-line me-2"></i>Sales Trends &amp; Patterns
                    </div>
                    <div class="card-body">
                        <?php if (!empty($daily_trend)): ?>
                            <h6>Daily Sales Trend</h6>
                            <canvas id="dailySalesChart" height="80"></canvas>
                        <?php endif; ?>

                        <?php if (!empty($hourly_pattern)): ?>
                            <h6 class="mt-4">Hourly Sales Pattern</h6>
                            <canvas id="hourlySalesChart" height="80"></canvas>
                        <?php endif; ?>

                        <?php if (!empty($low_stock_items)): ?>
                            <h6 class="mt-4 text-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>Low Stock Alert
                            </h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Product Code</th>
                                            <th>Product Name</th>
                                            <th>Store</th>
                                            <th>Current Stock</th>
                                            <th>Reorder Level</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($low_stock_items as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['product_code']); ?></td>
                                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['store_name']); ?></td>
                                            <td><span class="badge bg-danger"><?php echo number_format($item['current_stock']); ?></span></td>
                                            <td><?php echo number_format($item['reorder_level']); ?></td>
                                            <td><span class="badge bg-warning">Reorder Now</span></td>
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
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ============================================
// CHARTS
// ============================================
<?php if (!empty($daily_trend)): ?>
const dailyCtx = document.getElementById('dailySalesChart').getContext('2d');
new Chart(dailyCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_column($daily_trend, 'sale_day')); ?>,
        datasets: [{
            label: 'Revenue (UGX)',
            data: <?php echo json_encode(array_column($daily_trend, 'revenue')); ?>,
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            tension: 0.1
        }, {
            label: 'Profit (UGX)',
            data: <?php echo json_encode(array_column($daily_trend, 'profit')); ?>,
            borderColor: 'rgb(54, 162, 235)',
            backgroundColor: 'rgba(54, 162, 235, 0.2)',
            tension: 0.1
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } }
    }
});
<?php endif; ?>

<?php if (!empty($hourly_pattern)): ?>
const hourlyCtx = document.getElementById('hourlySalesChart').getContext('2d');
new Chart(hourlyCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_map(function($h) { return $h['sale_hour'] . ':00'; }, $hourly_pattern)); ?>,
        datasets: [{
            label: 'Sales Count',
            data: <?php echo json_encode(array_column($hourly_pattern, 'sales_count')); ?>,
            backgroundColor: 'rgba(255, 99, 132, 0.5)'
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } }
    }
});
<?php endif; ?>

// ============================================
// EXPORT TABLE
// ============================================
function exportTable(type, format) {
    const params = new URLSearchParams(window.location.search);
    params.append('export', type);
    params.append('format', format);
    window.open('export_report.php?' + params.toString(), '_blank');
}

// ============================================
// ALL SALES TAB
// ============================================
let allSalesCurrentPage = 1;
let allSalesLoaded = false;

function reloadAllSales(page) {
    if (page) allSalesCurrentPage = page;

    const params = new URLSearchParams({
        date_from:      '<?php echo $date_from; ?>',
        date_to:        '<?php echo $date_to; ?>',
        store_id:       '<?php echo (int)$store_filter; ?>',
        payment_method: '<?php echo $payment_filter; ?>',
        search:         document.getElementById('all-sales-search').value,
        per_page:       document.getElementById('all-sales-per-page').value,
        page:           allSalesCurrentPage,
    });

    const tbody = document.getElementById('all-sales-tbody');
    tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted py-4">' +
                      '<i class="fas fa-spinner fa-spin me-2"></i>Loading...</td></tr>';

    fetch('../includes/ajax_all_sales.php?' + params.toString())
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                tbody.innerHTML = '<tr><td colspan="12" class="text-center text-danger py-4">' +
                                  'Error: ' + escapeHtml(data.error || 'Unknown error') + '</td></tr>';
                return;
            }

            // Header badge
            document.getElementById('all-sales-total').textContent =
                (data.total || 0).toLocaleString() + ' line(s)';

            // ---------- Aggregate totals bar ----------
            const t = data.totals || {};
            document.getElementById('all-total-transactions').textContent =
                (t.transactions || 0).toLocaleString();
            document.getElementById('all-total-revenue').textContent =
                'UGX ' + (t.revenue || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
            document.getElementById('all-total-profit').textContent =
                'UGX ' + (t.profit || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
            document.getElementById('all-total-items').textContent =
                (t.items || 0).toLocaleString();

            if (data.rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted py-4">' +
                                  'No sales found for the selected filters</td></tr>';
            } else {
                tbody.innerHTML = data.rows.map(r => `
                    <tr>
                        <td><code>${escapeHtml(r.invoice_number)}</code></td>
                        <td>
                            <div>${formatDate(r.sale_date)}</div>
                            <small class="text-muted">${formatTime(r.sale_date)}</small>
                        </td>
                        <td>${escapeHtml(r.store_name || '-')}</td>
                        <td>${escapeHtml(r.served_by_name || '-')}</td>
                        <td>${escapeHtml(r.customer_name || 'Walk-in')}</td>
                        <td>
                            <strong>${escapeHtml(r.product_name)}</strong>
                            <br><small class="text-muted">${escapeHtml(r.product_code)}</small>
                        </td>
                        <td class="text-center">${r.quantity}</td>
                        <td class="text-end">${formatMoney(r.unit_price)}</td>
                        <td class="text-end">${parseFloat(r.discount_amount) > 0 ? '-' + formatMoney(r.discount_amount) : '—'}</td>
                        <td class="text-end"><strong>${formatMoney(r.total_price)}</strong></td>
                        <td class="text-end ${parseFloat(r.profit) >= 0 ? 'text-success' : 'text-danger'}">
                            ${formatMoney(r.profit)}
                        </td>
                        <td>
                            <span class="badge bg-secondary">${paymentLabel(r.payment_method)}</span>
                        </td>
                    </tr>
                `).join('');
            }

            renderAllSalesPagination(data.page, data.pages);
        })
        .catch(err => {
            console.error(err);
            tbody.innerHTML = '<tr><td colspan="12" class="text-center text-danger py-4">' +
                              'Network error</td></tr>';
        });
}

function renderAllSalesPagination(current, total) {
    const ul = document.getElementById('all-sales-pagination');
    ul.innerHTML = '';
    if (total <= 1) return;

    const makeItem = (label, page, disabled, active) => {
        const li = document.createElement('li');
        li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
        const a = document.createElement('a');
        a.className = 'page-link';
        a.href = '#';
        a.textContent = label;
        a.addEventListener('click', e => {
            e.preventDefault();
            if (!disabled && !active) reloadAllSales(page);
        });
        li.appendChild(a);
        return li;
    };

    ul.appendChild(makeItem('«', Math.max(1, current - 1), current === 1, false));

    const start = Math.max(1, current - 2);
    const end   = Math.min(total, start + 4);
    for (let p = start; p <= end; p++) {
        ul.appendChild(makeItem(p, p, false, p === current));
    }

    ul.appendChild(makeItem('»', Math.min(total, current + 1), current === total, false));
}

// ============================================
// HELPERS
// ============================================
function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}

function formatMoney(v) {
    const n = parseFloat(v || 0);
    return 'UGX ' + n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

function formatDate(dt) {
    const d = new Date(String(dt).replace(' ', 'T'));
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatTime(dt) {
    const d = new Date(String(dt).replace(' ', 'T'));
    return d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

function paymentLabel(m) {
    return String(m || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

// ============================================
// TAB EVENTS
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    const allSalesTab = document.querySelector('a[href="#all-sales-tab"]');
    if (allSalesTab) {
        allSalesTab.addEventListener('shown.bs.tab', function() {
            if (!allSalesLoaded) {
                allSalesLoaded = true;
                reloadAllSales(1);
            }
        });
    }

    const searchInput = document.getElementById('all-sales-search');
    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                reloadAllSales(1);
            }
        });
    }

    const perPageSelect = document.getElementById('all-sales-per-page');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function() {
            if (allSalesLoaded) reloadAllSales(1);
        });
    }
});
</script>