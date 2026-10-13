<?php
require_once '../config/config.php';
require_admin();

$page_title = "Reports";

// ============================================
// FILTERS (shared by every tab)
// ============================================
$date_from      = $_GET['date_from']    ?? date('Y-m-01');
$date_to        = $_GET['date_to']      ?? date('Y-m-d');
$store_filter   = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
$payment_filter = $_GET['payment_method'] ?? '';
$tab            = $_GET['tab']          ?? 'overview';

// Sanity check dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

// ============================================
// BASE WHERE CLAUSE
// ============================================
$where  = ["DATE(s.sale_date) BETWEEN ? AND ?", "s.status = 'completed'"];
$params = [$date_from, $date_to];

if ($store_filter > 0) {
    $where[]  = "s.store_id = ?";
    $params[] = $store_filter;
}
if ($payment_filter !== '') {
    $where[]  = "s.payment_method = ?";
    $params[] = $payment_filter;
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

// ============================================
// PREVIOUS PERIOD (for comparison)
// ============================================
$days = (strtotime($date_to) - strtotime($date_from)) / 86400 + 1;
$prev_date_to   = date('Y-m-d', strtotime($date_from . ' -1 day'));
$prev_date_from = date('Y-m-d', strtotime($prev_date_to . ' -' . ($days - 1) . ' day'));

$prev_where  = ["DATE(s.sale_date) BETWEEN ? AND ?", "s.status = 'completed'"];
$prev_params = [$prev_date_from, $prev_date_to];
if ($store_filter > 0) { $prev_where[] = "s.store_id = ?"; $prev_params[] = $store_filter; }
if ($payment_filter !== '') { $prev_where[] = "s.payment_method = ?"; $prev_params[] = $payment_filter; }
$prev_where_sql = 'WHERE ' . implode(' AND ', $prev_where);

// ============================================
// STORES DROPDOWN
// ============================================
$stores = $conn->query("SELECT store_id, store_name FROM stores WHERE status = 'active' ORDER BY store_name")
               ->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// OVERVIEW — this period
// ============================================
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT s.sale_id) AS sales_count,
        COUNT(DISTINCT s.customer_id) AS unique_customers,
        COALESCE(SUM(s.total_amount), 0) AS revenue,
        COALESCE(SUM(s.discount_amount), 0) AS discounts,
        COALESCE(SUM(s.tax_amount), 0) AS tax,
        COALESCE(AVG(s.total_amount), 0) AS avg_sale
    FROM sales s
    $where_sql
");
$stmt->execute($params);
$curr = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(si.profit), 0) AS profit
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.sale_id
    $where_sql
");
$stmt->execute($params);
$curr_profit = (float) $stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT s.sale_id) AS sales_count,
        COALESCE(SUM(s.total_amount), 0) AS revenue,
        COALESCE(AVG(s.total_amount), 0) AS avg_sale
    FROM sales s
    $prev_where_sql
");
$stmt->execute($prev_params);
$prev = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(si.profit), 0) AS profit
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.sale_id
    $prev_where_sql
");
$stmt->execute($prev_params);
$prev_profit = (float) $stmt->fetchColumn();

function delta($curr, $prev) {
    if ($prev <= 0) return ['pct' => $curr > 0 ? 100 : 0, 'dir' => $curr > 0 ? 'up' : 'flat'];
    $pct = (($curr - $prev) / $prev) * 100;
    return ['pct' => round($pct, 1), 'dir' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat')];
}

$d_revenue = delta($curr['revenue'], $prev['revenue']);
$d_count   = delta($curr['sales_count'], $prev['sales_count']);
$d_profit  = delta($curr_profit, $prev_profit);
$d_avg     = delta($curr['avg_sale'], $prev['avg_sale']);

$profit_margin = $curr['revenue'] > 0 ? ($curr_profit / $curr['revenue']) * 100 : 0;
$prev_margin   = $prev['revenue'] > 0 ? ($prev_profit / $prev['revenue']) * 100 : 0;

// ============================================
// SALES TAB
// ============================================
$stmt = $conn->prepare("
    SELECT 
        s.sale_id,
        s.invoice_number,
        s.sale_date,
        s.total_amount,
        s.payment_method,
        s.payment_status,
        s.amount_paid,
        s.amount_due,
        st.store_name,
        u.full_name AS served_by_name,
        COALESCE(c.customer_name, 'Walk-in') AS customer_name,
        (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.sale_id) AS item_count,
        (SELECT COALESCE(SUM(profit), 0) FROM sale_items WHERE sale_id = s.sale_id) AS profit
    FROM sales s
    LEFT JOIN stores st    ON s.store_id  = st.store_id
    LEFT JOIN users u      ON s.served_by = u.user_id
    LEFT JOIN customers c  ON s.customer_id = c.customer_id
    $where_sql
    ORDER BY s.sale_date DESC
");
$stmt->execute($params);
$sales_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PRODUCTS TAB
// ============================================
$stmt = $conn->prepare("
    SELECT 
        p.product_id,
        p.product_code,
        p.product_name,
        COALESCE(c.category_name, 'Uncategorized') AS category_name,
        SUM(si.quantity) AS units_sold,
        COALESCE(SUM(si.total_price), 0) AS revenue,
        COALESCE(SUM(si.profit), 0) AS profit,
        COUNT(DISTINCT s.sale_id) AS transaction_count
    FROM sale_items si
    JOIN products p ON si.product_id = p.product_id
    LEFT JOIN categories c ON p.category_id = c.category_id
    JOIN sales s ON si.sale_id = s.sale_id
    $where_sql
    GROUP BY p.product_id, p.product_code, p.product_name, category_name
    ORDER BY revenue DESC
");
$stmt->execute($params);
$products_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// TEAM TAB
// ============================================
$stmt = $conn->prepare("
    SELECT 
        u.user_id,
        u.full_name,
        u.role,
        COUNT(DISTINCT s.sale_id) AS sales_count,
        COALESCE(SUM(s.total_amount), 0) AS revenue,
        COALESCE(AVG(s.total_amount), 0) AS avg_sale
    FROM sales s
    JOIN users u ON s.served_by = u.user_id
    $where_sql
    GROUP BY u.user_id, u.full_name, u.role
    ORDER BY revenue DESC
");
$stmt->execute($params);
$team_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($team_rows as &$member) {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(si.profit), 0)
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.sale_id
        $where_sql AND s.served_by = ?
    ");
    $team_params = array_merge($params, [$member['user_id']]);
    $stmt->execute($team_params);
    $member['profit'] = (float) $stmt->fetchColumn();
}
unset($member);

// ============================================
// OPERATIONS TAB
// ============================================

// Low stock
$stmt = $conn->prepare("
    SELECT 
        p.product_code,
        p.product_name,
        st.store_name,
        p.reorder_level,
        COALESCE(SUM(si.quantity), 0) AS current_stock
    FROM products p
    CROSS JOIN stores st
    LEFT JOIN store_inventory si 
        ON p.product_id = si.product_id 
        AND st.store_id = si.store_id
        AND si.quantity > 0
    WHERE p.status = 'active' AND st.status = 'active'
    GROUP BY p.product_id, p.product_code, p.product_name, st.store_id, st.store_name, p.reorder_level
    HAVING COALESCE(SUM(si.quantity), 0) <= p.reorder_level
    ORDER BY (COALESCE(SUM(si.quantity), 0) - p.reorder_level) ASC
");
$stmt->execute();
$low_stock_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Expiring
$stmt = $conn->prepare("
    SELECT 
        p.product_code,
        p.product_name,
        st.store_name,
        si.batch_number,
        si.quantity,
        si.expiry_date,
        DATEDIFF(si.expiry_date, CURDATE()) AS days_left,
        (si.quantity * p.purchase_price) AS value_at_risk
    FROM store_inventory si
    JOIN products p ON si.product_id = p.product_id
    JOIN stores st  ON si.store_id   = st.store_id
    WHERE p.has_expiry = 'yes'
      AND si.expiry_date IS NOT NULL
      AND si.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
      AND si.quantity > 0
    ORDER BY si.expiry_date ASC
");
$stmt->execute();
$expiring_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Outstanding credit
$stmt = $conn->prepare("
    SELECT 
        COALESCE(c.customer_name, 'Walk-in') AS customer_name,
        c.phone,
        COUNT(s.sale_id) AS credit_sales,
        COALESCE(SUM(s.total_amount), 0) AS total_credit,
        COALESCE(SUM(s.amount_due), 0) AS outstanding
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    WHERE s.payment_method = 'credit'
      AND s.amount_due > 0
      AND DATE(s.sale_date) BETWEEN ? AND ?
      AND s.status = 'completed'
    GROUP BY s.customer_id, customer_name, phone
    ORDER BY outstanding DESC
");
$stmt->execute([$date_from, $date_to]);
$credit_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// DAILY TREND
// ============================================
$stmt = $conn->prepare("
    SELECT 
        DATE(s.sale_date) AS sale_day,
        COUNT(DISTINCT s.sale_id) AS sales_count,
        COALESCE(SUM(s.total_amount), 0) AS revenue
    FROM sales s
    $where_sql
    GROUP BY DATE(s.sale_date)
    ORDER BY sale_day ASC
");
$stmt->execute($params);
$trend_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$chart_labels = array_column($trend_rows, 'sale_day');
$chart_values = array_map('floatval', array_column($trend_rows, 'revenue'));

$alert = get_alert();
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<style>
:root {
    --rp-purple: #6B46C1;
    --rp-green:  #48BB78;
    --rp-blue:   #4299E1;
    --rp-orange: #ED8936;
    --rp-red:    #E53E3E;
    --rp-gray:   #718096;
    --rp-border: #E2E8F0;
}

.rp-filter-bar {
    background: linear-gradient(135deg, #1A202C 0%, #2D3748 100%);
    border-radius: 14px;
    padding: 20px 24px;
    color: #fff;
    margin-bottom: 24px;
}

.rp-filter-bar .form-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: rgba(255,255,255,0.7);
    margin-bottom: 6px;
}

.rp-filter-bar .form-control,
.rp-filter-bar .form-select {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.15);
    color: #fff;
    font-size: 0.85rem;
}

.rp-filter-bar .form-control:focus,
.rp-filter-bar .form-select:focus {
    background: rgba(255,255,255,0.12);
    border-color: var(--rp-purple);
    box-shadow: 0 0 0 3px rgba(107,70,193,0.25);
    color: #fff;
}

.rp-filter-bar .form-select option { color: #1A202C; }

.rp-stat {
    background: #fff;
    border-radius: 14px;
    padding: 20px;
    border: 1px solid var(--rp-border);
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    display: flex;
    align-items: flex-start;
    gap: 14px;
    height: 100%;
}

.rp-stat-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.rp-stat-purple .rp-stat-icon { background: rgba(107,70,193,0.12); color: var(--rp-purple); }
.rp-stat-green  .rp-stat-icon { background: rgba(72,187,120,0.12); color: var(--rp-green); }
.rp-stat-blue   .rp-stat-icon { background: rgba(66,153,225,0.12); color: var(--rp-blue); }
.rp-stat-orange .rp-stat-icon { background: rgba(237,137,54,0.12); color: var(--rp-orange); }

.rp-stat-content { flex: 1; min-width: 0; }
.rp-stat-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--rp-gray);
    margin-bottom: 4px;
}
.rp-stat-value {
    font-size: 1.45rem;
    font-weight: 800;
    color: #1A202C;
    line-height: 1.1;
    font-family: 'Courier New', monospace;
    word-break: break-word;
}
.rp-stat-delta {
    font-size: 0.75rem;
    font-weight: 700;
    margin-top: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 12px;
}
.rp-delta-up   { background: rgba(72,187,120,0.12); color: #2F855A; }
.rp-delta-down { background: rgba(229,62,62,0.12);  color: #C53030; }
.rp-delta-flat { background: rgba(113,128,150,0.12); color: #4A5568; }

.rp-tab-nav .nav-link {
    color: var(--rp-gray);
    font-weight: 600;
    border: none;
    border-bottom: 3px solid transparent;
    padding: 12px 18px;
    font-size: 0.85rem;
}
.rp-tab-nav .nav-link.active {
    color: var(--rp-purple);
    background: transparent;
    border-bottom-color: var(--rp-purple);
}
.rp-tab-nav .nav-link:hover:not(.active) {
    color: #4A5568;
    border-bottom-color: var(--rp-border);
}

.rp-table thead th {
    background: #F7FAFC;
    color: #4A5568;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px;
    border-bottom: 2px solid var(--rp-border);
    white-space: nowrap;
}

.rp-table tbody td {
    padding: 12px;
    border-bottom: 1px solid #EDF2F7;
    font-size: 0.85rem;
    vertical-align: middle;
}

.rp-table tbody tr:hover { background: #F7FAFC; }

.rp-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
}
.rp-badge-success { background: rgba(72,187,120,0.15); color: #2F855A; }
.rp-badge-warning { background: rgba(237,137,54,0.15); color: #C05621; }
.rp-badge-danger  { background: rgba(229,62,62,0.15);  color: #C53030; }
.rp-badge-info    { background: rgba(66,153,225,0.15); color: #2B6CB0; }
.rp-badge-neutral { background: rgba(113,128,150,0.15); color: #4A5568; }

.rp-empty {
    text-align: center;
    padding: 48px 20px;
    color: var(--rp-gray);
}
.rp-empty i {
    font-size: 3rem;
    opacity: 0.25;
    margin-bottom: 12px;
    display: block;
}
.rp-empty h6 { color: #4A5568; font-weight: 700; margin-bottom: 4px; }
.rp-empty p  { font-size: 0.85rem; margin: 0; }
</style>

<div class="main-content">
    <div class="top-navbar">
        <div class="d-flex justify-content-between align-items-center w-100">
            <div class="d-flex align-items-center">
                <button class="btn btn-link d-lg-none sidebar-toggle me-2"><i class="fas fa-bars"></i></button>
                <button type="button" class="btn btn-link sidebar-desktop-toggle me-2" id="sidebarDesktopToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h5 class="mb-0">
                    <i class="fas fa-chart-line me-2" style="color: var(--rp-orange);"></i>
                    Reports
                </h5>
            </div>

            <!-- ============================================
                 EXPORT DROPDOWN
                 ============================================ -->
            <div class="d-flex align-items-center gap-2">
                <div class="btn-group">
                    <button type="button" class="btn btn-sm btn-success dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><h6 class="dropdown-header">CSV</h6></li>
                        <li>
                            <a class="dropdown-item" href="export_report.php?export=all-sales&format=csv&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&store_id=<?php echo (int)$store_filter; ?>&payment_method=<?php echo urlencode($payment_filter); ?>">
                                <i class="fas fa-file-csv me-2 text-success"></i>All Sales — Summary
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="export_report.php?export=all-sales-lines&format=csv&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&store_id=<?php echo (int)$store_filter; ?>&payment_method=<?php echo urlencode($payment_filter); ?>">
                                <i class="fas fa-file-csv me-2 text-success"></i>All Sales — Full Detail
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="export_report.php?export=products&format=csv&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&store_id=<?php echo (int)$store_filter; ?>&payment_method=<?php echo urlencode($payment_filter); ?>">
                                <i class="fas fa-file-csv me-2 text-success"></i>Products
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="export_report.php?export=categories&format=csv&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&store_id=<?php echo (int)$store_filter; ?>&payment_method=<?php echo urlencode($payment_filter); ?>">
                                <i class="fas fa-file-csv me-2 text-success"></i>Categories
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="export_report.php?export=team&format=csv&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&store_id=<?php echo (int)$store_filter; ?>&payment_method=<?php echo urlencode($payment_filter); ?>">
                                <i class="fas fa-file-csv me-2 text-success"></i>Team
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="export_report.php?export=credit&format=csv&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">
                                <i class="fas fa-file-csv me-2 text-success"></i>Credit
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="export_report.php?export=low-stock&format=csv">
                                <i class="fas fa-file-csv me-2 text-success"></i>Low Stock
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="export_report.php?export=expiring&format=csv">
                                <i class="fas fa-file-csv me-2 text-success"></i>Expiring Products
                            </a>
                        </li>

                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">PDF</h6></li>
                        <li>
                            <a class="dropdown-item" href="export_report.php?export=all-sales&format=pdf&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&store_id=<?php echo (int)$store_filter; ?>&payment_method=<?php echo urlencode($payment_filter); ?>">
                                <i class="fas fa-file-pdf me-2 text-danger"></i>All Sales — Summary
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="export_report.php?export=all-sales-lines&format=pdf&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&store_id=<?php echo (int)$store_filter; ?>&payment_method=<?php echo urlencode($payment_filter); ?>">
                                <i class="fas fa-file-pdf me-2 text-danger"></i>All Sales — Full Detail
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="export_report.php?export=products&format=pdf&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&store_id=<?php echo (int)$store_filter; ?>&payment_method=<?php echo urlencode($payment_filter); ?>">
                                <i class="fas fa-file-pdf me-2 text-danger"></i>Products
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="export_report.php?export=team&format=pdf&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&store_id=<?php echo (int)$store_filter; ?>&payment_method=<?php echo urlencode($payment_filter); ?>">
                                <i class="fas fa-file-pdf me-2 text-danger"></i>Team
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="export_report.php?export=operations&format=pdf">
                                <i class="fas fa-file-pdf me-2 text-danger"></i>Operations Report
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="export_report.php?export=credit&format=pdf&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">
                                <i class="fas fa-file-pdf me-2 text-danger"></i>Credit
                            </a>
                        </li>
                    </ul>
                </div>
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
             FILTER BAR
             ============================================ -->
        <form method="GET" class="rp-filter-bar">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">

            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Store</label>
                    <select name="store_id" class="form-select">
                        <option value="0">All Stores</option>
                        <?php foreach ($stores as $st): ?>
                            <option value="<?php echo (int)$st['store_id']; ?>" <?php echo $store_filter == $st['store_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($st['store_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Payment</label>
                    <select name="payment_method" class="form-select">
                        <option value="">All</option>
                        <?php foreach (['cash','mobile_money','card','bank_transfer','credit'] as $pm): ?>
                            <option value="<?php echo $pm; ?>" <?php echo $payment_filter === $pm ? 'selected' : ''; ?>>
                                <?php echo ucwords(str_replace('_', ' ', $pm)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-sync-alt me-1"></i>Apply
                    </button>
                </div>
            </div>

            <div class="mt-3 d-flex gap-2 flex-wrap">
                <a href="?date_from=<?php echo date('Y-m-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>&tab=<?php echo $tab; ?>" class="btn btn-sm btn-outline-light">
                    This Month
                </a>
                <a href="?date_from=<?php echo date('Y-m-d', strtotime('monday this week')); ?>&date_to=<?php echo date('Y-m-d'); ?>&tab=<?php echo $tab; ?>" class="btn btn-sm btn-outline-light">
                    This Week
                </a>
                <a href="?date_from=<?php echo date('Y-m-d'); ?>&date_to=<?php echo date('Y-m-d'); ?>&tab=<?php echo $tab; ?>" class="btn btn-sm btn-outline-light">
                    Today
                </a>
                <a href="?date_from=<?php echo date('Y-m-d', strtotime('first day of last month')); ?>&date_to=<?php echo date('Y-m-t', strtotime('last day of last month')); ?>&tab=<?php echo $tab; ?>" class="btn btn-sm btn-outline-light">
                    Last Month
                </a>
            </div>
        </form>

        <!-- ============================================
             TABS
             ============================================ -->
        <ul class="nav nav-tabs rp-tab-nav mb-4" role="tablist">
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'overview' ? 'active' : ''; ?>"
                   href="?<?php echo http_build_query(array_merge($_GET, ['tab' => 'overview'])); ?>">
                    <i class="fas fa-tachometer-alt me-1"></i>Overview
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'sales' ? 'active' : ''; ?>"
                   href="?<?php echo http_build_query(array_merge($_GET, ['tab' => 'sales'])); ?>">
                    <i class="fas fa-receipt me-1"></i>Sales
                    <span class="rp-badge rp-badge-neutral ms-1"><?php echo count($sales_rows); ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'products' ? 'active' : ''; ?>"
                   href="?<?php echo http_build_query(array_merge($_GET, ['tab' => 'products'])); ?>">
                    <i class="fas fa-box me-1"></i>Products
                    <span class="rp-badge rp-badge-neutral ms-1"><?php echo count($products_rows); ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'team' ? 'active' : ''; ?>"
                   href="?<?php echo http_build_query(array_merge($_GET, ['tab' => 'team'])); ?>">
                    <i class="fas fa-users me-1"></i>Team
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'operations' ? 'active' : ''; ?>"
                   href="?<?php echo http_build_query(array_merge($_GET, ['tab' => 'operations'])); ?>">
                    <i class="fas fa-exclamation-triangle me-1"></i>Operations
                    <?php 
                    $ops_count = count($low_stock_rows) + count($expiring_rows);
                    if ($ops_count > 0): ?>
                        <span class="rp-badge rp-badge-warning ms-1"><?php echo $ops_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>

        <!-- ============================================
             TAB: OVERVIEW
             ============================================ -->
        <?php if ($tab === 'overview'): ?>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="rp-stat rp-stat-purple">
                        <div class="rp-stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                        <div class="rp-stat-content">
                            <div class="rp-stat-label">Revenue</div>
                            <div class="rp-stat-value">UGX <?php echo number_format($curr['revenue'], 0); ?></div>
                            <div class="rp-stat-delta rp-delta-<?php echo $d_revenue['dir']; ?>">
                                <i class="fas fa-arrow-<?php echo $d_revenue['dir'] === 'up' ? 'up' : ($d_revenue['dir'] === 'down' ? 'down' : 'right'); ?>"></i>
                                <?php echo $d_revenue['pct']; ?>% vs previous
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="rp-stat rp-stat-green">
                        <div class="rp-stat-icon"><i class="fas fa-receipt"></i></div>
                        <div class="rp-stat-content">
                            <div class="rp-stat-label">Sales Count</div>
                            <div class="rp-stat-value"><?php echo number_format($curr['sales_count']); ?></div>
                            <div class="rp-stat-delta rp-delta-<?php echo $d_count['dir']; ?>">
                                <i class="fas fa-arrow-<?php echo $d_count['dir'] === 'up' ? 'up' : ($d_count['dir'] === 'down' ? 'down' : 'right'); ?>"></i>
                                <?php echo $d_count['pct']; ?>% vs previous
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="rp-stat rp-stat-blue">
                        <div class="rp-stat-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="rp-stat-content">
                            <div class="rp-stat-label">Profit</div>
                            <div class="rp-stat-value">UGX <?php echo number_format($curr_profit, 0); ?></div>
                            <div class="rp-stat-delta rp-delta-<?php echo $d_profit['dir']; ?>">
                                <i class="fas fa-arrow-<?php echo $d_profit['dir'] === 'up' ? 'up' : ($d_profit['dir'] === 'down' ? 'down' : 'right'); ?>"></i>
                                <?php echo $d_profit['pct']; ?>% vs previous
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="rp-stat rp-stat-orange">
                        <div class="rp-stat-icon"><i class="fas fa-percentage"></i></div>
                        <div class="rp-stat-content">
                            <div class="rp-stat-label">Profit Margin</div>
                            <div class="rp-stat-value"><?php echo number_format($profit_margin, 1); ?>%</div>
                            <div class="rp-stat-delta rp-delta-flat">
                                Previous: <?php echo number_format($prev_margin, 1); ?>%
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="rp-stat">
                        <div class="rp-stat-icon" style="background:rgba(107,70,193,0.1);color:#6B46C1"><i class="fas fa-shopping-basket"></i></div>
                        <div class="rp-stat-content">
                            <div class="rp-stat-label">Avg Sale Value</div>
                            <div class="rp-stat-value">UGX <?php echo number_format($curr['avg_sale'], 0); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="rp-stat">
                        <div class="rp-stat-icon" style="background:rgba(72,187,120,0.1);color:#48BB78"><i class="fas fa-user-check"></i></div>
                        <div class="rp-stat-content">
                            <div class="rp-stat-label">Unique Customers</div>
                            <div class="rp-stat-value"><?php echo number_format($curr['unique_customers']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="rp-stat">
                        <div class="rp-stat-icon" style="background:rgba(237,137,54,0.1);color:#ED8936"><i class="fas fa-tag"></i></div>
                        <div class="rp-stat-content">
                            <div class="rp-stat-label">Discounts Given</div>
                            <div class="rp-stat-value">UGX <?php echo number_format($curr['discounts'], 0); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="rp-stat">
                        <div class="rp-stat-icon" style="background:rgba(66,153,225,0.1);color:#4299E1"><i class="fas fa-receipt"></i></div>
                        <div class="rp-stat-content">
                            <div class="rp-stat-label">Tax Collected</div>
                            <div class="rp-stat-value">UGX <?php echo number_format($curr['tax'], 0); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card rp-card">
                <div class="card-header" style="background:#fff;border-bottom:2px solid var(--rp-border);padding:16px 20px;">
                    <span style="font-weight:700;color:#1A202C;">
                        <i class="fas fa-chart-area me-2" style="color:var(--rp-purple)"></i>Daily Revenue Trend
                    </span>
                    <span class="rp-badge rp-badge-info ms-2"><?php echo count($trend_rows); ?> days</span>
                </div>
                <div class="card-body">
                    <?php if (empty($trend_rows)): ?>
                        <div class="rp-empty">
                            <i class="fas fa-chart-area"></i>
                            <h6>No sales in this period</h6>
                            <p>Try a different date range or store filter.</p>
                        </div>
                    <?php else: ?>
                        <canvas id="trendChart" height="80"></canvas>
                    <?php endif; ?>
                </div>
            </div>

        <!-- ============================================
             TAB: SALES
             ============================================ -->
        <?php elseif ($tab === 'sales'): ?>

            <div class="card rp-card">
                <div class="card-header d-flex justify-content-between align-items-center" style="background:#fff;border-bottom:2px solid var(--rp-border);padding:16px 20px;">
                    <span style="font-weight:700;color:#1A202C;">
                        <i class="fas fa-receipt me-2" style="color:var(--rp-purple)"></i>All Sales Transactions
                    </span>
                    <span class="rp-badge rp-badge-info"><?php echo count($sales_rows); ?> transactions</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($sales_rows)): ?>
                        <div class="rp-empty">
                            <i class="fas fa-receipt"></i>
                            <h6>No sales found</h6>
                            <p>Adjust the filters above to see transactions.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table rp-table mb-0" id="salesTable">
                                <thead>
                                    <tr>
                                        <th>Invoice</th>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Store</th>
                                        <th>Served By</th>
                                        <th class="text-center">Items</th>
                                        <th class="text-end">Total</th>
                                        <th class="text-end">Profit</th>
                                        <th>Payment</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sales_rows as $sale): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($sale['invoice_number']); ?></code></td>
                                        <td>
                                            <div><?php echo date('M d, Y', strtotime($sale['sale_date'])); ?></div>
                                            <small class="text-muted"><?php echo date('H:i A', strtotime($sale['sale_date'])); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($sale['store_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($sale['served_by_name'] ?? '-'); ?></td>
                                        <td class="text-center"><?php echo (int)$sale['item_count']; ?></td>
                                        <td class="text-end"><strong>UGX <?php echo number_format($sale['total_amount'], 0); ?></strong></td>
                                        <td class="text-end <?php echo $sale['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            UGX <?php echo number_format($sale['profit'], 0); ?>
                                        </td>
                                        <td>
                                            <span class="rp-badge rp-badge-neutral">
                                                <?php echo ucwords(str_replace('_', ' ', $sale['payment_method'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a href="view_sale.php?id=<?php echo (int)$sale['sale_id']; ?>"
                                               class="btn btn-sm btn-outline-primary" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <!-- ============================================
             TAB: PRODUCTS
             ============================================ -->
        <?php elseif ($tab === 'products'): ?>

            <div class="card rp-card">
                <div class="card-header d-flex justify-content-between align-items-center" style="background:#fff;border-bottom:2px solid var(--rp-border);padding:16px 20px;">
                    <span style="font-weight:700;color:#1A202C;">
                        <i class="fas fa-box me-2" style="color:var(--rp-purple)"></i>Product Performance
                    </span>
                    <span class="rp-badge rp-badge-info"><?php echo count($products_rows); ?> products</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($products_rows)): ?>
                        <div class="rp-empty">
                            <i class="fas fa-box-open"></i>
                            <h6>No products sold</h6>
                            <p>Try a different date range.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table rp-table mb-0" id="productsTable">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th class="text-center">Units</th>
                                        <th class="text-center">Orders</th>
                                        <th class="text-end">Revenue</th>
                                        <th class="text-end">Profit</th>
                                        <th class="text-center">Margin</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products_rows as $p): ?>
                                    <?php $margin = $p['revenue'] > 0 ? ($p['profit'] / $p['revenue']) * 100 : 0; ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($p['product_code']); ?></code></td>
                                        <td><strong><?php echo htmlspecialchars($p['product_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($p['category_name']); ?></td>
                                        <td class="text-center"><?php echo number_format($p['units_sold']); ?></td>
                                        <td class="text-center"><?php echo number_format($p['transaction_count']); ?></td>
                                        <td class="text-end"><strong>UGX <?php echo number_format($p['revenue'], 0); ?></strong></td>
                                        <td class="text-end text-success">UGX <?php echo number_format($p['profit'], 0); ?></td>
                                        <td class="text-center">
                                            <span class="rp-badge rp-badge-<?php echo $margin > 30 ? 'success' : ($margin > 15 ? 'warning' : 'danger'); ?>">
                                                <?php echo number_format($margin, 1); ?>%
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <!-- ============================================
             TAB: TEAM
             ============================================ -->
        <?php elseif ($tab === 'team'): ?>

            <div class="card rp-card">
                <div class="card-header d-flex justify-content-between align-items-center" style="background:#fff;border-bottom:2px solid var(--rp-border);padding:16px 20px;">
                    <span style="font-weight:700;color:#1A202C;">
                        <i class="fas fa-users me-2" style="color:var(--rp-purple)"></i>Team Performance
                    </span>
                    <span class="rp-badge rp-badge-info"><?php echo count($team_rows); ?> members</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($team_rows)): ?>
                        <div class="rp-empty">
                            <i class="fas fa-user-slash"></i>
                            <h6>No team activity</h6>
                            <p>No sales were made in this period.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table rp-table mb-0" id="teamTable">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Role</th>
                                        <th class="text-center">Sales</th>
                                        <th class="text-end">Revenue</th>
                                        <th class="text-end">Profit</th>
                                        <th class="text-end">Avg Sale</th>
                                        <th class="text-center">Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_team_revenue = array_sum(array_column($team_rows, 'revenue'));
                                    foreach ($team_rows as $m): 
                                        $share = $total_team_revenue > 0 ? ($m['revenue'] / $total_team_revenue) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($m['full_name']); ?></strong></td>
                                        <td><span class="rp-badge rp-badge-neutral"><?php echo htmlspecialchars($m['role']); ?></span></td>
                                        <td class="text-center"><?php echo number_format($m['sales_count']); ?></td>
                                        <td class="text-end"><strong>UGX <?php echo number_format($m['revenue'], 0); ?></strong></td>
                                        <td class="text-end text-success">UGX <?php echo number_format($m['profit'], 0); ?></td>
                                        <td class="text-end">UGX <?php echo number_format($m['avg_sale'], 0); ?></td>
                                        <td>
                                            <div class="progress" style="height:8px;">
                                                <div class="progress-bar bg-primary" style="width:<?php echo $share; ?>%"></div>
                                            </div>
                                            <small class="text-muted"><?php echo number_format($share, 1); ?>%</small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <!-- ============================================
             TAB: OPERATIONS
             ============================================ -->
        <?php elseif ($tab === 'operations'): ?>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="rp-stat">
                        <div class="rp-stat-icon" style="background:rgba(229,62,62,0.12);color:#E53E3E"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="rp-stat-content">
                            <div class="rp-stat-label">Low Stock Items</div>
                            <div class="rp-stat-value"><?php echo count($low_stock_rows); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="rp-stat">
                        <div class="rp-stat-icon" style="background:rgba(237,137,54,0.12);color:#ED8936"><i class="fas fa-clock"></i></div>
                        <div class="rp-stat-content">
                            <div class="rp-stat-label">Expiring Soon</div>
                            <div class="rp-stat-value"><?php echo count($expiring_rows); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="rp-stat">
                        <div class="rp-stat-icon" style="background:rgba(66,153,225,0.12);color:#4299E1"><i class="fas fa-file-invoice-dollar"></i></div>
                        <div class="rp-stat-content">
                            <div class="rp-stat-label">Credit Outstanding</div>
                            <div class="rp-stat-value">UGX <?php echo number_format(array_sum(array_column($credit_rows, 'outstanding')), 0); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($low_stock_rows)): ?>
            <div class="card rp-card mb-4">
                <div class="card-header" style="background:#fff;border-bottom:2px solid var(--rp-border);padding:16px 20px;">
                    <span style="font-weight:700;color:#E53E3E;">
                        <i class="fas fa-exclamation-triangle me-2"></i>Low Stock — Restock Needed
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table rp-table mb-0">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Store</th>
                                    <th class="text-center">Current</th>
                                    <th class="text-center">Reorder</th>
                                    <th class="text-center">Shortage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($low_stock_rows as $row): 
                                    $shortage = max(0, $row['reorder_level'] - $row['current_stock']);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['product_name']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($row['product_code']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['store_name']); ?></td>
                                    <td class="text-center">
                                        <span class="rp-badge rp-badge-<?php echo $row['current_stock'] == 0 ? 'danger' : 'warning'; ?>">
                                            <?php echo number_format($row['current_stock']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><?php echo number_format($row['reorder_level']); ?></td>
                                    <td class="text-center">
                                        <span class="rp-badge rp-badge-danger">-<?php echo number_format($shortage); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($expiring_rows)): ?>
            <div class="card rp-card mb-4">
                <div class="card-header" style="background:#fff;border-bottom:2px solid var(--rp-border);padding:16px 20px;">
                    <span style="font-weight:700;color:#ED8936;">
                        <i class="fas fa-clock me-2"></i>Expiring Within 30 Days
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table rp-table mb-0">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Store</th>
                                    <th>Batch</th>
                                    <th class="text-center">Qty</th>
                                    <th>Expiry</th>
                                    <th class="text-center">Days Left</th>
                                    <th class="text-end">Value at Risk</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expiring_rows as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['product_name']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($row['product_code']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['store_name']); ?></td>
                                    <td><code><?php echo htmlspecialchars($row['batch_number']); ?></code></td>
                                    <td class="text-center"><?php echo number_format($row['quantity']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($row['expiry_date'])); ?></td>
                                    <td class="text-center">
                                        <span class="rp-badge rp-badge-<?php echo $row['days_left'] <= 7 ? 'danger' : 'warning'; ?>">
                                            <?php echo $row['days_left']; ?> days
                                        </span>
                                    </td>
                                    <td class="text-end">UGX <?php echo number_format($row['value_at_risk'], 0); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($credit_rows)): ?>
            <div class="card rp-card">
                <div class="card-header" style="background:#fff;border-bottom:2px solid var(--rp-border);padding:16px 20px;">
                    <span style="font-weight:700;color:#4299E1;">
                        <i class="fas fa-file-invoice-dollar me-2"></i>Outstanding Credit
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table rp-table mb-0">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Phone</th>
                                    <th class="text-center">Credit Sales</th>
                                    <th class="text-end">Total Credit</th>
                                    <th class="text-end">Outstanding</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($credit_rows as $row): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['customer_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                                    <td class="text-center"><?php echo number_format($row['credit_sales']); ?></td>
                                    <td class="text-end">UGX <?php echo number_format($row['total_credit'], 0); ?></td>
                                    <td class="text-end"><strong class="text-danger">UGX <?php echo number_format($row['outstanding'], 0); ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($low_stock_rows) && empty($expiring_rows) && empty($credit_rows)): ?>
            <div class="rp-empty">
                <i class="fas fa-check-circle" style="color:#48BB78;opacity:1"></i>
                <h6>All clear</h6>
                <p>No low stock, expiring products, or outstanding credit.</p>
            </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ============================================
// TREND CHART
// ============================================
<?php if ($tab === 'overview' && !empty($trend_rows)): ?>
(function() {
    const ctx = document.getElementById('trendChart');
    if (!ctx) return;

    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 320);
    gradient.addColorStop(0, 'rgba(107, 70, 193, 0.35)');
    gradient.addColorStop(1, 'rgba(107, 70, 193, 0.02)');

    new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($d) { return date('M j', strtotime($d)); }, $chart_labels)); ?>,
            datasets: [{
                label: 'Revenue (UGX)',
                data: <?php echo json_encode($chart_values); ?>,
                backgroundColor: gradient,
                borderColor: '#6B46C1',
                borderWidth: 3,
                tension: 0.35,
                fill: true,
                pointBackgroundColor: '#ED8936',
                pointBorderColor: '#fff',
                pointBorderWidth: 3,
                pointRadius: 5,
                pointHoverRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1A202C',
                    titleColor: '#fff',
                    bodyColor: '#ED8936',
                    padding: 12,
                    cornerRadius: 8,
                    displayColors: false,
                    callbacks: {
                        label: function(ctx) {
                            return 'UGX ' + ctx.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#718096', font: { size: 11, weight: '600' } } },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.05)' },
                    ticks: {
                        color: '#718096',
                        font: { size: 11 },
                        callback: function(v) {
                            if (v >= 1000000) return (v / 1000000).toFixed(1) + 'M';
                            if (v >= 1000)    return (v / 1000).toFixed(0) + 'K';
                            return v;
                        }
                    }
                }
            }
        }
    });
})();
<?php endif; ?>

// ============================================
// SAFE DATATABLE INIT
// ============================================
(function() {
    'use strict';

    function safeDataTable(selector, options) {
        const table = document.querySelector(selector);
        if (!table) return;
        if (typeof jQuery === 'undefined' || !jQuery.fn.DataTable) return;

        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const rows = tbody.querySelectorAll('tr');
        if (rows.length === 0) return;

        if (rows.length === 1) {
            const cells = rows[0].querySelectorAll('td');
            if (cells.length === 1 && cells[0].hasAttribute('colspan')) return;
        }

        const headers = table.querySelectorAll('thead th').length;
        const firstRowCells = rows[0].querySelectorAll('td').length;
        if (headers !== firstRowCells) {
            console.warn('Skipping DataTable for ' + selector +
                         ' — header/body mismatch (' + headers + ' vs ' + firstRowCells + ')');
            return;
        }

        jQuery(selector).DataTable(Object.assign({
            pageLength: 25,
            responsive: false,
            order: [],
            language: {
                search: "",
                searchPlaceholder: "Search...",
                lengthMenu: "Show _MENU_",
                info: "_START_–_END_ of _TOTAL_",
                infoEmpty: "No entries",
                infoFiltered: "(filtered from _MAX_)",
                paginate: {
                    first: '<i class="fas fa-angle-double-left"></i>',
                    last:  '<i class="fas fa-angle-double-right"></i>',
                    next:  '<i class="fas fa-angle-right"></i>',
                    previous: '<i class="fas fa-angle-left"></i>'
                }
            }
        }, options || {}));
    }

    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            safeDataTable('#salesTable',    { order: [[1, 'desc']] });
            safeDataTable('#productsTable', { order: [[5, 'desc']] });
            safeDataTable('#teamTable',     { order: [[3, 'desc']] });
        }, 100);
    });
})();
</script>