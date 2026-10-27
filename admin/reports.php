<?php
require_once '../config/config.php';
require_admin();

$page_title = "Reports";

/* ============================================================
   FILTERS
   ============================================================ */
$date_from      = $_GET['date_from']    ?? date('Y-m-01');
$date_to        = $_GET['date_to']      ?? date('Y-m-d');
$store_filter   = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
$payment_filter = $_GET['payment_method'] ?? '';
$tab            = $_GET['tab']          ?? 'overview';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

/* ============================================================
   BASE WHERE (sales)
   ============================================================ */
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

/* ============================================================
   PREVIOUS PERIOD
   ============================================================ */
$days           = (strtotime($date_to) - strtotime($date_from)) / 86400 + 1;
$prev_date_to   = date('Y-m-d', strtotime($date_from . ' -1 day'));
$prev_date_from = date('Y-m-d', strtotime($prev_date_to . ' -' . ($days - 1) . ' day'));

$prev_where  = ["DATE(s.sale_date) BETWEEN ? AND ?", "s.status = 'completed'"];
$prev_params = [$prev_date_from, $prev_date_to];
if ($store_filter > 0) { $prev_where[] = "s.store_id = ?"; $prev_params[] = $store_filter; }
if ($payment_filter !== '') { $prev_where[] = "s.payment_method = ?"; $prev_params[] = $payment_filter; }
$prev_where_sql = 'WHERE ' . implode(' AND ', $prev_where);

/* ============================================================
   STORES DROPDOWN — only sales stores
   ============================================================ */
$stores = $conn->query("
    SELECT store_id, store_name
    FROM stores
    WHERE status = 'active' AND store_type = 'sales'
    ORDER BY store_name
")->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   CURRENT PERIOD — sales summary
   ============================================================ */
$stmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT s.sale_id)          AS sales_count,
        COUNT(DISTINCT s.customer_id)      AS unique_customers,
        COALESCE(SUM(s.total_amount),0)    AS revenue,
        COALESCE(SUM(s.discount_amount),0) AS discounts,
        COALESCE(SUM(s.tax_amount),0)      AS tax,
        COALESCE(AVG(s.total_amount),0)    AS avg_sale
    FROM sales s
    $where_sql
");
$stmt->execute($params);
$curr = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(si.quantity * p.purchase_price), 0)
    FROM sale_items si
    JOIN products p ON si.product_id = p.product_id
    JOIN sales s    ON si.sale_id    = s.sale_id
    $where_sql
");
$stmt->execute($params);
$cogs = (float) $stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(si.profit), 0)
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.sale_id
    $where_sql
");
$stmt->execute($params);
$curr_profit = (float) $stmt->fetchColumn();

/* ============================================================
   PREVIOUS PERIOD — sales summary
   ============================================================ */
$stmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT s.sale_id)       AS sales_count,
        COALESCE(SUM(s.total_amount),0) AS revenue,
        COALESCE(AVG(s.total_amount),0) AS avg_sale
    FROM sales s
    $prev_where_sql
");
$stmt->execute($prev_params);
$prev = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(si.quantity * p.purchase_price), 0)
    FROM sale_items si
    JOIN products p ON si.product_id = p.product_id
    JOIN sales s    ON si.sale_id    = s.sale_id
    $prev_where_sql
");
$stmt->execute($prev_params);
$prev_cogs = (float) $stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(si.profit), 0)
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.sale_id
    $prev_where_sql
");
$stmt->execute($prev_params);
$prev_profit = (float) $stmt->fetchColumn();

/* ============================================================
   EXPENSES — FIXED (no ROLLUP + ORDER BY)
   ============================================================ */
$exp_where  = ["e.expense_date BETWEEN ? AND ?"];
$exp_params = [$date_from, $date_to];
if ($store_filter > 0) {
    $exp_where[]  = "e.store_id = ?";
    $exp_params[] = $store_filter;
}
$exp_where_sql = 'WHERE ' . implode(' AND ', $exp_where);

// Breakdown by category — grouped, sorted, NO ROLLUP
$stmt = $conn->prepare("
    SELECT
        e.expense_category AS category,
        COALESCE(SUM(e.amount), 0) AS total,
        COUNT(*) AS entries
    FROM expenses e
    $exp_where_sql
    GROUP BY e.expense_category
    ORDER BY total DESC
");
$stmt->execute($exp_params);
$expense_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Grand total — separate query
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(e.amount), 0)
    FROM expenses e
    $exp_where_sql
");
$stmt->execute($exp_params);
$expense_total = (float) $stmt->fetchColumn();

// Previous period expenses total
$prev_exp_where  = ["e.expense_date BETWEEN ? AND ?"];
$prev_exp_params = [$prev_date_from, $prev_date_to];
if ($store_filter > 0) { $prev_exp_where[] = "e.store_id = ?"; $prev_exp_params[] = $store_filter; }
$prev_exp_where_sql = 'WHERE ' . implode(' AND ', $prev_exp_where);

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(e.amount), 0)
    FROM expenses e
    $prev_exp_where_sql
");
$stmt->execute($prev_exp_params);
$prev_expense_total = (float) $stmt->fetchColumn();

// Raw expense list (for reference / future Expenses tab inside reports)
$stmt = $conn->prepare("
    SELECT e.*, s.store_name, u.full_name AS recorded_by_name
    FROM expenses e
    LEFT JOIN stores s ON e.store_id    = s.store_id
    LEFT JOIN users  u ON e.recorded_by = u.user_id
    $exp_where_sql
    ORDER BY e.expense_date DESC, e.expense_id DESC
");
$stmt->execute($exp_params);
$expenses_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   DERIVED P&L
   ============================================================ */
$gross_profit = (float) $curr['revenue'] - $cogs;
$prev_gross   = (float) $prev['revenue'] - $prev_cogs;
$net_profit   = $gross_profit - $expense_total;
$prev_net     = $prev_gross   - $prev_expense_total;

$gross_margin       = $curr['revenue'] > 0 ? ($gross_profit / $curr['revenue']) * 100 : 0;
$prev_gross_margin  = $prev['revenue'] > 0 ? ($prev_gross   / $prev['revenue']) * 100 : 0;
$net_margin         = $curr['revenue'] > 0 ? ($net_profit   / $curr['revenue']) * 100 : 0;
$prev_net_margin    = $prev['revenue'] > 0 ? ($prev_net     / $prev['revenue']) * 100 : 0;
$expense_ratio      = $curr['revenue'] > 0 ? ($expense_total      / $curr['revenue']) * 100 : 0;
$prev_expense_ratio = $prev['revenue'] > 0 ? ($prev_expense_total / $prev['revenue']) * 100 : 0;

function delta($curr, $prev) {
    if ($prev <= 0) return ['pct' => $curr > 0 ? 100 : 0, 'dir' => $curr > 0 ? 'up' : 'flat'];
    $pct = (($curr - $prev) / $prev) * 100;
    return ['pct' => round($pct, 1), 'dir' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat')];
}
$d_revenue = delta($curr['revenue'],     $prev['revenue']);
$d_count   = delta($curr['sales_count'], $prev['sales_count']);
$d_profit  = delta($curr_profit,         $prev_profit);
$d_avg     = delta($curr['avg_sale'],    $prev['avg_sale']);
$d_gross   = delta($gross_profit,        $prev_gross);
$d_expense = delta($expense_total,       $prev_expense_total);
$d_net     = delta($net_profit,          $prev_net);

/* ============================================================
   INVENTORY VALUE ON HAND — sellable, sales stores only
   ============================================================ */
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(si.quantity * p.purchase_price), 0)
    FROM store_inventory si
    JOIN products p ON si.product_id = p.product_id
    JOIN stores   st ON si.store_id  = st.store_id
    WHERE si.status = 'good'
      AND si.quantity > 0
      AND st.status = 'active'
      AND st.store_type = 'sales'
      " . ($store_filter > 0 ? " AND st.store_id = ?" : "") . "
");
$inv_params = [];
if ($store_filter > 0) $inv_params[] = $store_filter;
$stmt->execute($inv_params);
$inventory_value = (float) $stmt->fetchColumn();

/* ============================================================
   SALES TAB
   ============================================================ */
$stmt = $conn->prepare("
    SELECT
        s.sale_id, s.invoice_number, s.sale_date, s.total_amount,
        s.payment_method, s.payment_status, s.amount_paid, s.amount_due,
        st.store_name, u.full_name AS served_by_name,
        COALESCE(c.customer_name, 'Walk-in') AS customer_name,
        (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.sale_id) AS item_count,
        (SELECT COALESCE(SUM(profit), 0) FROM sale_items WHERE sale_id = s.sale_id) AS profit
    FROM sales s
    LEFT JOIN stores st   ON s.store_id    = st.store_id
    LEFT JOIN users u     ON s.served_by   = u.user_id
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    $where_sql
    ORDER BY s.sale_date DESC
");
$stmt->execute($params);
$sales_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   PRODUCTS TAB
   ============================================================ */
$stmt = $conn->prepare("
    SELECT
        p.product_id, p.product_code, p.product_name,
        COALESCE(c.category_name, 'Uncategorized') AS category_name,
        SUM(si.quantity) AS units_sold,
        COALESCE(SUM(si.total_price), 0) AS revenue,
        COALESCE(SUM(si.profit), 0)      AS profit,
        COUNT(DISTINCT s.sale_id)        AS transaction_count
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

/* ============================================================
   TEAM TAB
   ============================================================ */
$stmt = $conn->prepare("
    SELECT
        u.user_id, u.full_name, u.role,
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
    $stmt->execute(array_merge($params, [$member['user_id']]));
    $member['profit'] = (float) $stmt->fetchColumn();
}
unset($member);

/* ============================================================
   OPERATIONS
   ============================================================ */

// Low stock (sales stores, good stock only)
$stmt = $conn->prepare("
    SELECT
        p.product_code, p.product_name, st.store_name,
        p.reorder_level,
        COALESCE(SUM(si.quantity), 0) AS current_stock
    FROM products p
    CROSS JOIN stores st
    LEFT JOIN store_inventory si
        ON p.product_id = si.product_id
        AND st.store_id = si.store_id
        AND si.quantity > 0
        AND si.status   = 'good'
    WHERE p.status = 'active'
      AND st.status = 'active'
      AND st.store_type = 'sales'
      " . ($store_filter > 0 ? " AND st.store_id = ?" : "") . "
    GROUP BY p.product_id, p.product_code, p.product_name,
             st.store_id, st.store_name, p.reorder_level
    HAVING COALESCE(SUM(si.quantity), 0) <= p.reorder_level
    ORDER BY (COALESCE(SUM(si.quantity), 0) - p.reorder_level) ASC
");
$low_params = [];
if ($store_filter > 0) $low_params[] = $store_filter;
$stmt->execute($low_params);
$low_stock_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Expiring — EXCLUDES damaged status and damages store
$stmt = $conn->prepare("
    SELECT
        p.product_code, p.product_name, st.store_name,
        si.batch_number, si.quantity, si.expiry_date,
        DATEDIFF(si.expiry_date, CURDATE()) AS days_left,
        (si.quantity * p.purchase_price) AS value_at_risk
    FROM store_inventory si
    JOIN products p ON si.product_id = p.product_id
    JOIN stores   st ON si.store_id  = st.store_id
    WHERE p.has_expiry = 'yes'
      AND si.expiry_date IS NOT NULL
      AND si.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
      AND si.quantity > 0
      AND si.status   = 'good'
      AND st.status   = 'active'
      AND st.store_type = 'sales'
      " . ($store_filter > 0 ? " AND st.store_id = ?" : "") . "
    ORDER BY si.expiry_date ASC
");
$exp_sql_params = [];
if ($store_filter > 0) $exp_sql_params[] = $store_filter;
$stmt->execute($exp_sql_params);
$expiring_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Damaged / written-off stock
$stmt = $conn->prepare("
    SELECT
        p.product_code, p.product_name, st.store_name,
        si.batch_number, si.quantity, si.expiry_date,
        (si.quantity * p.purchase_price) AS value_lost
    FROM store_inventory si
    JOIN products p ON si.product_id = p.product_id
    JOIN stores   st ON si.store_id  = st.store_id
    WHERE si.status = 'damaged'
      AND si.quantity > 0
      " . ($store_filter > 0 ? " AND st.store_id = ?" : "") . "
    ORDER BY value_lost DESC
");
$dmg_params = [];
if ($store_filter > 0) $dmg_params[] = $store_filter;
$stmt->execute($dmg_params);
$damaged_rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
$damaged_value = array_sum(array_column($damaged_rows, 'value_lost'));

// Outstanding credit
$stmt = $conn->prepare("
    SELECT
        COALESCE(c.customer_name, 'Walk-in') AS customer_name,
        c.phone,
        COUNT(s.sale_id) AS credit_sales,
        COALESCE(SUM(s.total_amount), 0) AS total_credit,
        COALESCE(SUM(s.amount_due), 0)   AS outstanding
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    WHERE s.payment_method = 'credit'
      AND s.amount_due > 0
      AND DATE(s.sale_date) BETWEEN ? AND ?
      AND s.status = 'completed'
      " . ($store_filter > 0 ? " AND s.store_id = ?" : "") . "
    GROUP BY s.customer_id, customer_name, phone
    ORDER BY outstanding DESC
");
$credit_params = [$date_from, $date_to];
if ($store_filter > 0) $credit_params[] = $store_filter;
$stmt->execute($credit_params);
$credit_rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
$credit_total = array_sum(array_column($credit_rows, 'outstanding'));

/* ============================================================
   DAILY TREND
   ============================================================ */
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
    --rp-purple:#6B46C1; --rp-green:#48BB78; --rp-blue:#4299E1;
    --rp-orange:#ED8936; --rp-red:#E53E3E; --rp-gray:#718096; --rp-border:#E2E8F0;
}
.rp-filter-bar { background: linear-gradient(135deg,#1A202C 0%,#2D3748 100%); border-radius:14px; padding:20px 24px; color:#fff; margin-bottom:24px; }
.rp-filter-bar .form-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:rgba(255,255,255,.7); margin-bottom:6px; }
.rp-filter-bar .form-control, .rp-filter-bar .form-select { background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); color:#fff; font-size:.85rem; }
.rp-filter-bar .form-control:focus, .rp-filter-bar .form-select:focus { background:rgba(255,255,255,.12); border-color:var(--rp-purple); box-shadow:0 0 0 3px rgba(107,70,193,.25); color:#fff; }
.rp-filter-bar .form-select option { color:#1A202C; }
.rp-stat { background:#fff; border-radius:14px; padding:20px; border:1px solid var(--rp-border); box-shadow:0 2px 8px rgba(0,0,0,.04); display:flex; align-items:flex-start; gap:14px; height:100%; }
.rp-stat-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.25rem; flex-shrink:0; }
.rp-stat-purple .rp-stat-icon { background:rgba(107,70,193,.12); color:var(--rp-purple); }
.rp-stat-green  .rp-stat-icon { background:rgba(72,187,120,.12); color:var(--rp-green); }
.rp-stat-blue   .rp-stat-icon { background:rgba(66,153,225,.12); color:var(--rp-blue); }
.rp-stat-orange .rp-stat-icon { background:rgba(237,137,54,.12); color:var(--rp-orange); }
.rp-stat-content { flex:1; min-width:0; }
.rp-stat-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--rp-gray); margin-bottom:4px; }
.rp-stat-value { font-size:1.45rem; font-weight:800; color:#1A202C; line-height:1.1; font-family:'Courier New',monospace; word-break:break-word; }
.rp-stat-delta { font-size:.75rem; font-weight:700; margin-top:6px; display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:12px; }
.rp-delta-up { background:rgba(72,187,120,.12); color:#2F855A; }
.rp-delta-down { background:rgba(229,62,62,.12); color:#C53030; }
.rp-delta-flat { background:rgba(113,128,150,.12); color:#4A5568; }
.rp-tab-nav .nav-link { color:var(--rp-gray); font-weight:600; border:none; border-bottom:3px solid transparent; padding:12px 18px; font-size:.85rem; }
.rp-tab-nav .nav-link.active { color:var(--rp-purple); background:transparent; border-bottom-color:var(--rp-purple); }
.rp-tab-nav .nav-link:hover:not(.active) { color:#4A5568; border-bottom-color:var(--rp-border); }
.rp-table thead th { background:#F7FAFC; color:#4A5568; font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.5px; padding:12px; border-bottom:2px solid var(--rp-border); white-space:nowrap; }
.rp-table tbody td { padding:12px; border-bottom:1px solid #EDF2F7; font-size:.85rem; vertical-align:middle; }
.rp-table tbody tr:hover { background:#F7FAFC; }
.rp-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.7rem; font-weight:700; }
.rp-badge-success { background:rgba(72,187,120,.15); color:#2F855A; }
.rp-badge-warning { background:rgba(237,137,54,.15); color:#C05621; }
.rp-badge-danger  { background:rgba(229,62,62,.15);  color:#C53030; }
.rp-badge-info    { background:rgba(66,153,225,.15); color:#2B6CB0; }
.rp-badge-neutral { background:rgba(113,128,150,.15); color:#4A5568; }
.rp-empty { text-align:center; padding:48px 20px; color:var(--rp-gray); }
.rp-empty i { font-size:3rem; opacity:.25; margin-bottom:12px; display:block; }
.rp-empty h6 { color:#4A5568; font-weight:700; margin-bottom:4px; }
.rp-empty p  { font-size:.85rem; margin:0; }
.rp-pnl-line td { padding:10px 12px; }
.rp-pnl-section td { background:#EDF2F7; font-weight:700; }
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
                    <i class="fas fa-chart-line me-2" style="color:var(--rp-orange);"></i>
                    Reports
                </h5>
            </div>
            <div class="d-flex align-items-center gap-2">
    <a href="Expense_Report" class="btn btn-sm btn-outline-primary">
        <i class="fas fa-money-bill-wave me-1"></i>Manage Expenses
    </a>

    <div class="btn-group">
        <button type="button" class="btn btn-sm btn-success dropdown-toggle" data-bs-toggle="dropdown">
            <i class="fas fa-download me-1"></i>Export
        </button>
        <ul class="dropdown-menu dropdown-menu-end" style="min-width: 260px;">

            <?php
            // Preserve all current filters in the export link
            $export_qs = http_build_query([
                'date_from'      => $date_from,
                'date_to'        => $date_to,
                'store_id'       => $store_filter,
                'payment_method' => $payment_filter,
                'tab'            => $tab,
            ]);
            ?>

            <li><h6 class="dropdown-header">Overview / P&amp;L</h6></li>
            <li><a class="dropdown-item" href="export_report.php?type=overview&format=csv&<?php echo $export_qs; ?>"><i class="fas fa-file-csv text-success me-2"></i>P&amp;L — CSV</a></li>
            <li><a class="dropdown-item" href="export_report.php?type=overview&format=xlsx&<?php echo $export_qs; ?>"><i class="fas fa-file-excel text-success me-2"></i>P&amp;L — Excel</a></li>
            <li><a class="dropdown-item" href="export_report.php?type=overview&format=pdf&<?php echo $export_qs; ?>"><i class="fas fa-file-pdf text-danger me-2"></i>P&amp;L — PDF</a></li>

            <li><hr class="dropdown-divider"></li>
            <li><h6 class="dropdown-header">Sales</h6></li>
            <li><a class="dropdown-item" href="export_report.php?type=sales&format=csv&<?php echo $export_qs; ?>"><i class="fas fa-file-csv text-success me-2"></i>Sales — CSV</a></li>
            <li><a class="dropdown-item" href="export_report.php?type=sales&format=xlsx&<?php echo $export_qs; ?>"><i class="fas fa-file-excel text-success me-2"></i>Sales — Excel</a></li>
            <li><a class="dropdown-item" href="export_report.php?type=sales&format=pdf&<?php echo $export_qs; ?>"><i class="fas fa-file-pdf text-danger me-2"></i>Sales — PDF</a></li>

            <li><hr class="dropdown-divider"></li>
            <li><h6 class="dropdown-header">Products</h6></li>
            <li><a class="dropdown-item" href="export_report.php?type=products&format=csv&<?php echo $export_qs; ?>"><i class="fas fa-file-csv text-success me-2"></i>Products — CSV</a></li>
            <li><a class="dropdown-item" href="export_report.php?type=products&format=xlsx&<?php echo $export_qs; ?>"><i class="fas fa-file-excel text-success me-2"></i>Products — Excel</a></li>
            <li><a class="dropdown-item" href="export_report.php?type=products&format=pdf&<?php echo $export_qs; ?>"><i class="fas fa-file-pdf text-danger me-2"></i>Products — PDF</a></li>

            <li><hr class="dropdown-divider"></li>
            <li><h6 class="dropdown-header">Team</h6></li>
            <li><a class="dropdown-item" href="export_report.php?type=team&format=csv&<?php echo $export_qs; ?>"><i class="fas fa-file-csv text-success me-2"></i>Team — CSV</a></li>
            <li><a class="dropdown-item" href="export_report.php?type=team&format=xlsx&<?php echo $export_qs; ?>"><i class="fas fa-file-excel text-success me-2"></i>Team — Excel</a></li>
            <li><a class="dropdown-item" href="export_report.php?type=team&format=pdf&<?php echo $export_qs; ?>"><i class="fas fa-file-pdf text-danger me-2"></i>Team — PDF</a></li>

            <li><hr class="dropdown-divider"></li>
            <li><h6 class="dropdown-header">Operations</h6></li>
            <li><a class="dropdown-item" href="export_report.php?type=operations&format=csv&<?php echo $export_qs; ?>"><i class="fas fa-file-csv text-success me-2"></i>Operations — CSV</a></li>
            <li><a class="dropdown-item" href="export_report.php?type=operations&format=xlsx&<?php echo $export_qs; ?>"><i class="fas fa-file-excel text-success me-2"></i>Operations — Excel</a></li>
            <li><a class="dropdown-item" href="export_report.php?type=operations&format=pdf&<?php echo $export_qs; ?>"><i class="fas fa-file-pdf text-danger me-2"></i>Operations — PDF</a></li>
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

        <!-- FILTER BAR -->
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
                <a href="?date_from=<?php echo date('Y-m-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>&tab=<?php echo $tab; ?>" class="btn btn-sm btn-outline-light">This Month</a>
                <a href="?date_from=<?php echo date('Y-m-d', strtotime('monday this week')); ?>&date_to=<?php echo date('Y-m-d'); ?>&tab=<?php echo $tab; ?>" class="btn btn-sm btn-outline-light">This Week</a>
                <a href="?date_from=<?php echo date('Y-m-d'); ?>&date_to=<?php echo date('Y-m-d'); ?>&tab=<?php echo $tab; ?>" class="btn btn-sm btn-outline-light">Today</a>
                <a href="?date_from=<?php echo date('Y-m-01', strtotime('first day of last month')); ?>&date_to=<?php echo date('Y-m-t', strtotime('last day of last month')); ?>&tab=<?php echo $tab; ?>" class="btn btn-sm btn-outline-light">Last Month</a>
            </div>
        </form>

        <!-- TABS -->
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
                    <?php $ops_count = count($low_stock_rows) + count($expiring_rows) + count($damaged_rows);
                    if ($ops_count > 0): ?>
                        <span class="rp-badge rp-badge-warning ms-1"><?php echo $ops_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>

        <?php if ($tab === 'overview'): ?>

        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="rp-stat rp-stat-purple">
                    <div class="rp-stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="rp-stat-content">
                        <div class="rp-stat-label">Total Sales</div>
                        <div class="rp-stat-value">UGX <?php echo number_format($curr['revenue'],0); ?></div>
                        <div class="rp-stat-delta rp-delta-<?php echo $d_revenue['dir']; ?>">
                            <i class="fas fa-arrow-<?php echo $d_revenue['dir']==='up'?'up':($d_revenue['dir']==='down'?'down':'right'); ?>"></i>
                            <?php echo $d_revenue['pct']; ?>% vs prev
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="rp-stat rp-stat-blue">
                    <div class="rp-stat-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="rp-stat-content">
                        <div class="rp-stat-label">Gross Profit</div>
                        <div class="rp-stat-value">UGX <?php echo number_format($gross_profit,0); ?></div>
                        <div class="rp-stat-delta rp-delta-<?php echo $d_gross['dir']; ?>">
                            <i class="fas fa-arrow-<?php echo $d_gross['dir']==='up'?'up':($d_gross['dir']==='down'?'down':'right'); ?>"></i>
                            <?php echo $d_gross['pct']; ?>% · <?php echo number_format($gross_margin,1); ?>%
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="rp-stat rp-stat-orange">
                    <div class="rp-stat-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                    <div class="rp-stat-content">
                        <div class="rp-stat-label">Total Expenses</div>
                        <div class="rp-stat-value">UGX <?php echo number_format($expense_total,0); ?></div>
                        <div class="rp-stat-delta rp-delta-<?php echo $d_expense['dir']; ?>">
                            <i class="fas fa-arrow-<?php echo $d_expense['dir']==='up'?'up':($d_expense['dir']==='down'?'down':'right'); ?>"></i>
                            <?php echo $d_expense['pct']; ?>% vs prev
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="rp-stat" style="border-left:4px solid <?php echo $net_profit>=0?'#48BB78':'#E53E3E'; ?>">
                    <div class="rp-stat-icon" style="background:<?php echo $net_profit>=0?'rgba(72,187,120,.12)':'rgba(229,62,62,.12)'; ?>;color:<?php echo $net_profit>=0?'#48BB78':'#E53E3E'; ?>">
                        <i class="fas fa-<?php echo $net_profit>=0?'trophy':'exclamation-triangle'; ?>"></i>
                    </div>
                    <div class="rp-stat-content">
                        <div class="rp-stat-label">Net <?php echo $net_profit<0?'Loss':'Profit'; ?></div>
                        <div class="rp-stat-value" style="color:<?php echo $net_profit>=0?'#2F855A':'#C53030'; ?>">
                            UGX <?php echo number_format($net_profit,0); ?>
                        </div>
                        <div class="rp-stat-delta rp-delta-<?php echo $d_net['dir']; ?>">
                            <i class="fas fa-arrow-<?php echo $d_net['dir']==='up'?'up':($d_net['dir']==='down'?'down':'right'); ?>"></i>
                            <?php echo $d_net['pct']; ?>% · <?php echo number_format($net_margin,1); ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="rp-stat">
                    <div class="rp-stat-icon" style="background:rgba(107,70,193,.1);color:#6B46C1"><i class="fas fa-shopping-basket"></i></div>
                    <div class="rp-stat-content">
                        <div class="rp-stat-label">Avg Sale Value</div>
                        <div class="rp-stat-value">UGX <?php echo number_format($curr['avg_sale'],0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="rp-stat">
                    <div class="rp-stat-icon" style="background:rgba(72,187,120,.1);color:#48BB78"><i class="fas fa-percentage"></i></div>
                    <div class="rp-stat-content">
                        <div class="rp-stat-label">Gross Margin</div>
                        <div class="rp-stat-value"><?php echo number_format($gross_margin,1); ?>%</div>
                        <div class="rp-stat-delta rp-delta-flat">Prev: <?php echo number_format($prev_gross_margin,1); ?>%</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="rp-stat">
                    <div class="rp-stat-icon" style="background:rgba(237,137,54,.1);color:#ED8936"><i class="fas fa-balance-scale"></i></div>
                    <div class="rp-stat-content">
                        <div class="rp-stat-label">Expense Ratio</div>
                        <div class="rp-stat-value"><?php echo number_format($expense_ratio,1); ?>%</div>
                        <div class="rp-stat-delta rp-delta-flat">Prev: <?php echo number_format($prev_expense_ratio,1); ?>%</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="rp-stat">
                    <div class="rp-stat-icon" style="background:rgba(66,153,225,.1);color:#4299E1"><i class="fas fa-boxes"></i></div>
                    <div class="rp-stat-content">
                        <div class="rp-stat-label">Inventory on Hand</div>
                        <div class="rp-stat-value">UGX <?php echo number_format($inventory_value,0); ?></div>
                        <div class="rp-stat-delta rp-delta-flat">Sellable stock</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- P&L -->
        <div class="card mb-4">
            <div class="card-header" style="background:#fff;border-bottom:2px solid var(--rp-border);padding:16px 20px;">
                <span style="font-weight:700;color:#1A202C;">
                    <i class="fas fa-file-invoice me-2" style="color:var(--rp-purple)"></i>Profit &amp; Loss Statement
                </span>
                <span class="rp-badge rp-badge-info ms-2">
                    <?php echo date('M j, Y', strtotime($date_from)); ?> – <?php echo date('M j, Y', strtotime($date_to)); ?>
                </span>
            </div>
            <div class="card-body p-0">
                <table class="table rp-table mb-0">
                    <thead>
                        <tr>
                            <th>Line Item</th>
                            <th class="text-end">This Period</th>
                            <th class="text-end">Previous</th>
                            <th class="text-end">Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="rp-pnl-line">
                            <td><strong>Revenue</strong></td>
                            <td class="text-end"><strong>UGX <?php echo number_format($curr['revenue'],0); ?></strong></td>
                            <td class="text-end text-muted">UGX <?php echo number_format($prev['revenue'],0); ?></td>
                            <td class="text-end"><span class="rp-badge rp-badge-<?php echo $d_revenue['dir']==='up'?'success':($d_revenue['dir']==='down'?'danger':'neutral'); ?>"><?php echo $d_revenue['pct']; ?>%</span></td>
                        </tr>
                        <tr class="rp-pnl-line">
                            <td class="ps-4 text-muted">Less: COGS</td>
                            <td class="text-end text-muted">(UGX <?php echo number_format($cogs,0); ?>)</td>
                            <td class="text-end text-muted">(UGX <?php echo number_format($prev_cogs,0); ?>)</td>
                            <td class="text-end text-muted">—</td>
                        </tr>
                        <tr class="rp-pnl-line" style="background:#F7FAFC;">
                            <td><strong>Gross Profit</strong></td>
                            <td class="text-end"><strong>UGX <?php echo number_format($gross_profit,0); ?></strong></td>
                            <td class="text-end text-muted">UGX <?php echo number_format($prev_gross,0); ?></td>
                            <td class="text-end"><span class="rp-badge rp-badge-<?php echo $d_gross['dir']==='up'?'success':($d_gross['dir']==='down'?'danger':'neutral'); ?>"><?php echo $d_gross['pct']; ?>%</span></td>
                        </tr>
                        <tr class="rp-pnl-section">
                            <td colspan="4"><i class="fas fa-receipt me-1"></i>Operating Expenses</td>
                        </tr>
                        <?php if (empty($expense_breakdown)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No expenses recorded for this period.</td></tr>
                        <?php else: foreach ($expense_breakdown as $e): ?>
                            <tr class="rp-pnl-line">
                                <td class="ps-4"><?php echo htmlspecialchars($e['category']); ?>
                                    <small class="text-muted">(<?php echo (int)$e['entries']; ?> entries)</small>
                                </td>
                                <td class="text-end">UGX <?php echo number_format($e['total'],0); ?></td>
                                <td class="text-end text-muted">—</td>
                                <td class="text-end text-muted">—</td>
                            </tr>
                        <?php endforeach; endif; ?>
                        <tr class="rp-pnl-line" style="background:#F7FAFC;">
                            <td><strong>Total Expenses</strong></td>
                            <td class="text-end"><strong class="text-danger">(UGX <?php echo number_format($expense_total,0); ?>)</strong></td>
                            <td class="text-end text-muted">(UGX <?php echo number_format($prev_expense_total,0); ?>)</td>
                            <td class="text-end"><span class="rp-badge rp-badge-<?php echo $d_expense['dir']==='up'?'danger':($d_expense['dir']==='down'?'success':'neutral'); ?>"><?php echo $d_expense['pct']; ?>%</span></td>
                        </tr>
                        <tr class="rp-pnl-line" style="background:<?php echo $net_profit>=0?'rgba(72,187,120,.1)':'rgba(229,62,62,.1)'; ?>;border-top:3px solid <?php echo $net_profit>=0?'#48BB78':'#E53E3E'; ?>;">
                            <td><strong style="font-size:1.05rem;">NET <?php echo $net_profit<0?'LOSS':'PROFIT'; ?></strong></td>
                            <td class="text-end"><strong style="font-size:1.05rem;color:<?php echo $net_profit>=0?'#2F855A':'#C53030'; ?>">UGX <?php echo number_format($net_profit,0); ?></strong></td>
                            <td class="text-end text-muted">UGX <?php echo number_format($prev_net,0); ?></td>
                            <td class="text-end"><span class="rp-badge rp-badge-<?php echo $d_net['dir']==='up'?'success':($d_net['dir']==='down'?'danger':'neutral'); ?>"><?php echo $d_net['pct']; ?>%</span></td>
                        </tr>
                        <tr class="rp-pnl-line" style="background:#F7FAFC;">
                            <td class="text-muted">Net Margin</td>
                            <td class="text-end"><strong><?php echo number_format($net_margin,2); ?>%</strong></td>
                            <td class="text-end text-muted"><?php echo number_format($prev_net_margin,2); ?>%</td>
                            <td class="text-end text-muted">—</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header" style="background:#fff;border-bottom:2px solid var(--rp-border);padding:16px 20px;">
                <span style="font-weight:700;color:#1A202C;">
                    <i class="fas fa-chart-area me-2" style="color:var(--rp-purple)"></i>Daily Revenue Trend
                </span>
                <span class="rp-badge rp-badge-info ms-2"><?php echo count($trend_rows); ?> days</span>
            </div>
            <div class="card-body">
                <?php if (empty($trend_rows)): ?>
                    <div class="rp-empty"><i class="fas fa-chart-area"></i><h6>No sales in this period</h6></div>
                <?php else: ?>
                    <canvas id="trendChart" height="80"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($tab === 'sales'): ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center" style="background:#fff;border-bottom:2px solid var(--rp-border);padding:16px 20px;">
                <span style="font-weight:700;color:#1A202C;">
                    <i class="fas fa-receipt me-2" style="color:var(--rp-purple)"></i>All Sales Transactions
                </span>
                <span class="rp-badge rp-badge-info"><?php echo count($sales_rows); ?> transactions</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($sales_rows)): ?>
                    <div class="rp-empty"><i class="fas fa-receipt"></i><h6>No sales found</h6></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table rp-table mb-0" id="salesTable">
                            <thead>
                                <tr>
                                    <th>Invoice</th><th>Date</th><th>Customer</th><th>Store</th>
                                    <th>Served By</th><th class="text-center">Items</th>
                                    <th class="text-end">Total</th><th class="text-end">Profit</th>
                                    <th>Payment</th><th class="text-center">Action</th>
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
                                    <td class="text-end"><strong>UGX <?php echo number_format($sale['total_amount'],0); ?></strong></td>
                                    <td class="text-end <?php echo $sale['profit']>=0?'text-success':'text-danger'; ?>">UGX <?php echo number_format($sale['profit'],0); ?></td>
                                    <td><span class="rp-badge rp-badge-neutral"><?php echo ucwords(str_replace('_',' ',$sale['payment_method'])); ?></span></td>
                                    <td class="text-center"><a href="view-sale.php?id=<?php echo (int)$sale['sale_id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($tab === 'products'): ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center" style="background:#fff;border-bottom:2px solid var(--rp-border);padding:16px 20px;">
                <span style="font-weight:700;color:#1A202C;">
                    <i class="fas fa-box me-2" style="color:var(--rp-purple)"></i>Product Performance
                </span>
                <span class="rp-badge rp-badge-info"><?php echo count($products_rows); ?> products</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($products_rows)): ?>
                    <div class="rp-empty"><i class="fas fa-box-open"></i><h6>No products sold</h6></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table rp-table mb-0" id="productsTable">
                            <thead>
                                <tr>
                                    <th>Code</th><th>Product</th><th>Category</th>
                                    <th class="text-center">Units</th><th class="text-center">Orders</th>
                                    <th class="text-end">Revenue</th><th class="text-end">Profit</th>
                                    <th class="text-center">Margin</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products_rows as $p): $margin = $p['revenue']>0 ? ($p['profit']/$p['revenue'])*100 : 0; ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($p['product_code']); ?></code></td>
                                    <td><strong><?php echo htmlspecialchars($p['product_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($p['category_name']); ?></td>
                                    <td class="text-center"><?php echo number_format($p['units_sold']); ?></td>
                                    <td class="text-center"><?php echo number_format($p['transaction_count']); ?></td>
                                    <td class="text-end"><strong>UGX <?php echo number_format($p['revenue'],0); ?></strong></td>
                                    <td class="text-end text-success">UGX <?php echo number_format($p['profit'],0); ?></td>
                                    <td class="text-center"><span class="rp-badge rp-badge-<?php echo $margin>30?'success':($margin>15?'warning':'danger'); ?>"><?php echo number_format($margin,1); ?>%</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($tab === 'team'): ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center" style="background:#fff;border-bottom:2px solid var(--rp-border);padding:16px 20px;">
                <span style="font-weight:700;color:#1A202C;">
                    <i class="fas fa-users me-2" style="color:var(--rp-purple)"></i>Team Performance
                </span>
                <span class="rp-badge rp-badge-info"><?php echo count($team_rows); ?> members</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($team_rows)): ?>
                    <div class="rp-empty"><i class="fas fa-user-slash"></i><h6>No team activity</h6></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table rp-table mb-0" id="teamTable">
                            <thead>
                                <tr>
                                    <th>Name</th><th>Role</th>
                                    <th class="text-center">Sales</th>
                                    <th class="text-end">Revenue</th>
                                    <th class="text-end">Profit</th>
                                    <th class="text-end">Avg Sale</th>
                                    <th class="text-center">Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $total_team_revenue = array_sum(array_column($team_rows,'revenue'));
                                foreach ($team_rows as $m): $share = $total_team_revenue>0 ? ($m['revenue']/$total_team_revenue)*100 : 0; ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($m['full_name']); ?></strong></td>
                                    <td><span class="rp-badge rp-badge-neutral"><?php echo htmlspecialchars($m['role']); ?></span></td>
                                    <td class="text-center"><?php echo number_format($m['sales_count']); ?></td>
                                    <td class="text-end"><strong>UGX <?php echo number_format($m['revenue'],0); ?></strong></td>
                                    <td class="text-end text-success">UGX <?php echo number_format($m['profit'],0); ?></td>
                                    <td class="text-end">UGX <?php echo number_format($m['avg_sale'],0); ?></td>
                                    <td>
                                        <div class="progress" style="height:8px;">
                                            <div class="progress-bar bg-primary" style="width:<?php echo $share; ?>%"></div>
                                        </div>
                                        <small class="text-muted"><?php echo number_format($share,1); ?>%</small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($tab === 'operations'): ?>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="rp-stat">
                    <div class="rp-stat-icon" style="background:rgba(229,62,62,.12);color:#E53E3E"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="rp-stat-content">
                        <div class="rp-stat-label">Low Stock</div>
                        <div class="rp-stat-value"><?php echo count($low_stock_rows); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="rp-stat">
                    <div class="rp-stat-icon" style="background:rgba(237,137,54,.12);color:#ED8936"><i class="fas fa-clock"></i></div>
                    <div class="rp-stat-content">
                        <div class="rp-stat-label">Expiring (30d)</div>
                        <div class="rp-stat-value"><?php echo count($expiring_rows); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="rp-stat">
                    <div class="rp-stat-icon" style="background:rgba(229,62,62,.12);color:#C53030"><i class="fas fa-trash-alt"></i></div>
                    <div class="rp-stat-content">
                        <div class="rp-stat-label">Damaged / Written Off</div>
                        <div class="rp-stat-value">UGX <?php echo number_format($damaged_value,0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="rp-stat">
                    <div class="rp-stat-icon" style="background:rgba(66,153,225,.12);color:#4299E1"><i class="fas fa-file-invoice-dollar"></i></div>
                    <div class="rp-stat-content">
                        <div class="rp-stat-label">Credit Outstanding</div>
                        <div class="rp-stat-value">UGX <?php echo number_format($credit_total,0); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($low_stock_rows)): ?>
        <div class="card mb-4">
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
                                <th>Product</th><th>Store</th>
                                <th class="text-center">Current</th>
                                <th class="text-center">Reorder</th>
                                <th class="text-center">Shortage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($low_stock_rows as $row): $shortage = max(0, $row['reorder_level'] - $row['current_stock']); ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['product_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($row['product_code']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($row['store_name']); ?></td>
                                <td class="text-center"><span class="rp-badge rp-badge-<?php echo $row['current_stock']==0?'danger':'warning'; ?>"><?php echo number_format($row['current_stock']); ?></span></td>
                                <td class="text-center"><?php echo number_format($row['reorder_level']); ?></td>
                                <td class="text-center"><span class="rp-badge rp-badge-danger">-<?php echo number_format($shortage); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($expiring_rows)): ?>
        <div class="card mb-4">
            <div class="card-header" style="background:#fff;border-bottom:2px solid var(--rp-border);padding:16px 20px;">
                <span style="font-weight:700;color:#ED8936;">
                    <i class="fas fa-clock me-2"></i>Expiring Within 30 Days
                </span>
                <small class="text-muted ms-2">(excludes items already moved to damages)</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table rp-table mb-0">
                        <thead>
                            <tr>
                                <th>Product</th><th>Store</th><th>Batch</th>
                                <th class="text-center">Qty</th><th>Expiry</th>
                                <th class="text-center">Days Left</th>
                                <th class="text-end">Value at Risk</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expiring_rows as $row): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['product_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($row['product_code']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($row['store_name']); ?></td>
                                <td><code><?php echo htmlspecialchars($row['batch_number']); ?></code></td>
                                <td class="text-center"><?php echo number_format($row['quantity']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($row['expiry_date'])); ?></td>
                                <td class="text-center"><span class="rp-badge rp-badge-<?php echo $row['days_left']<=7?'danger':'warning'; ?>"><?php echo $row['days_left']; ?> days</span></td>
                                <td class="text-end">UGX <?php echo number_format($row['value_at_risk'],0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($damaged_rows)): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center" style="background:#fff;border-bottom:2px solid var(--rp-border);padding:16px 20px;">
                <span style="font-weight:700;color:#C53030;">
                    <i class="fas fa-trash-alt me-2"></i>Damaged / Written-Off Stock
                </span>
                <span class="rp-badge rp-badge-danger">Loss: UGX <?php echo number_format($damaged_value,0); ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table rp-table mb-0">
                        <thead>
                            <tr>
                                <th>Product</th><th>Store</th><th>Batch</th>
                                <th class="text-center">Qty</th><th>Expiry</th>
                                <th class="text-end">Value Lost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($damaged_rows as $row): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['product_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($row['product_code']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($row['store_name']); ?></td>
                                <td><code><?php echo htmlspecialchars($row['batch_number']); ?></code></td>
                                <td class="text-center"><?php echo number_format($row['quantity']); ?></td>
                                <td><?php echo $row['expiry_date'] ? date('d/m/Y', strtotime($row['expiry_date'])) : '-'; ?></td>
                                <td class="text-end text-danger"><strong>UGX <?php echo number_format($row['value_lost'],0); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($credit_rows)): ?>
        <div class="card">
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
                                <th>Customer</th><th>Phone</th>
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
                                <td class="text-end">UGX <?php echo number_format($row['total_credit'],0); ?></td>
                                <td class="text-end"><strong class="text-danger">UGX <?php echo number_format($row['outstanding'],0); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($low_stock_rows) && empty($expiring_rows) && empty($damaged_rows) && empty($credit_rows)): ?>
        <div class="rp-empty">
            <i class="fas fa-check-circle" style="color:#48BB78;opacity:1"></i>
            <h6>All clear</h6>
            <p>No low stock, expiring products, damaged stock, or outstanding credit.</p>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
<?php if ($tab === 'overview' && !empty($trend_rows)): ?>
(function() {
    const ctx = document.getElementById('trendChart');
    if (!ctx) return;
    const gradient = ctx.getContext('2d').createLinearGradient(0,0,0,320);
    gradient.addColorStop(0,'rgba(107,70,193,.35)');
    gradient.addColorStop(1,'rgba(107,70,193,.02)');
    new Chart(ctx.getContext('2d'), {
        type:'line',
        data:{
            labels: <?php echo json_encode(array_map(function($d){ return date('M j', strtotime($d)); }, $chart_labels)); ?>,
            datasets:[{
                label:'Revenue (UGX)',
                data: <?php echo json_encode($chart_values); ?>,
                backgroundColor: gradient,
                borderColor:'#6B46C1',
                borderWidth:3,
                tension:.35,
                fill:true,
                pointBackgroundColor:'#ED8936',
                pointBorderColor:'#fff',
                pointBorderWidth:3,
                pointRadius:5,
                pointHoverRadius:8
            }]
        },
        options:{
            responsive:true, maintainAspectRatio:true,
            interaction:{ intersect:false, mode:'index' },
            plugins:{
                legend:{ display:false },
                tooltip:{
                    backgroundColor:'#1A202C', titleColor:'#fff', bodyColor:'#ED8936',
                    padding:12, cornerRadius:8, displayColors:false,
                    callbacks:{ label: c => 'UGX ' + c.parsed.y.toLocaleString() }
                }
            },
            scales:{
                x:{ grid:{display:false}, ticks:{ color:'#718096', font:{ size:11, weight:'600' } } },
                y:{
                    beginAtZero:true, grid:{ color:'rgba(0,0,0,.05)' },
                    ticks:{
                        color:'#718096', font:{ size:11 },
                        callback: v => v>=1000000 ? (v/1000000).toFixed(1)+'M'
                                  : v>=1000    ? (v/1000).toFixed(0)+'K'
                                  : v
                    }
                }
            }
        }
    });
})();
<?php endif; ?>

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
        if (headers !== firstRowCells) { console.warn('Skipping DataTable for '+selector); return; }
        jQuery(selector).DataTable(Object.assign({
            pageLength: 25, responsive: false, order: [],
            language: {
                search:"", searchPlaceholder:"Search...",
                lengthMenu:"Show _MENU_",
                info:"_START_–_END_ of _TOTAL_",
                infoEmpty:"No entries",
                infoFiltered:"(filtered from _MAX_)",
                paginate:{
                    first:'<i class="fas fa-angle-double-left"></i>',
                    last:'<i class="fas fa-angle-double-right"></i>',
                    next:'<i class="fas fa-angle-right"></i>',
                    previous:'<i class="fas fa-angle-left"></i>'
                }
            }
        }, options || {}));
    }
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            safeDataTable('#salesTable',    { order: [[1,'desc']] });
            safeDataTable('#productsTable', { order: [[5,'desc']] });
            safeDataTable('#teamTable',     { order: [[3,'desc']] });
        }, 100);
    });
})();
</script>