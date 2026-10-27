<?php
require_once '../config/config.php';
require_admin();

$page_title = "Dashboard";

// ============================================
// STORE FILTER FOR EXPIRING ALERT
// ============================================
$store_filter = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
if ($store_filter <= 0 && !empty($_SESSION['default_store_id'])) {
    $store_filter = intval($_SESSION['default_store_id']);
}

// Load active SALES stores (excludes damages store)
$stores = $conn->query("
    SELECT store_id, store_name 
    FROM stores 
    WHERE status = 'active' AND store_type = 'sales'
    ORDER BY store_name
")->fetchAll(PDO::FETCH_ASSOC);

$selected_store_name = 'All Stores';
foreach ($stores as $s) {
    if ((int)$s['store_id'] === $store_filter) {
        $selected_store_name = $s['store_name'];
        break;
    }
}

try {
    // ============================================
    // TODAY'S SALES
    // ============================================
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total
        FROM sales
        WHERE DATE(sale_date) = CURDATE() AND status = 'completed'
    ");
    $stmt->execute();
    $today_sales = $stmt->fetch();

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) AS total
        FROM sales
        WHERE DATE(sale_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
          AND status = 'completed'
    ");
    $stmt->execute();
    $yesterday_total = (float) $stmt->fetchColumn();
    $today_delta = $yesterday_total > 0
        ? (($today_sales['total'] - $yesterday_total) / $yesterday_total) * 100
        : 0;

    // ============================================
    // POS SALES MTD + LAST MONTH
    // ============================================
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total
        FROM sales
        WHERE MONTH(sale_date) = MONTH(CURDATE())
          AND YEAR(sale_date) = YEAR(CURDATE())
          AND status = 'completed'
    ");
    $stmt->execute();
    $month_sales = $stmt->fetch();

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) AS total
        FROM sales
        WHERE MONTH(sale_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
          AND YEAR(sale_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
          AND status = 'completed'
    ");
    $stmt->execute();
    $last_month_total = (float) $stmt->fetchColumn();
    $month_delta = $last_month_total > 0
        ? (($month_sales['total'] - $last_month_total) / $last_month_total) * 100
        : 0;

    // ============================================
    // CLIENT ORDERS MTD
    // ============================================
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) AS order_count,
            COALESCE(SUM(grand_total), 0) AS order_total,
            COALESCE(SUM(amount_paid), 0) AS amount_received,
            COALESCE(SUM(amount_due), 0) AS amount_outstanding
        FROM client_orders
        WHERE MONTH(order_date) = MONTH(CURDATE())
          AND YEAR(order_date) = YEAR(CURDATE())
          AND order_status != 'cancelled'
    ");
    $stmt->execute();
    $client_orders_month = $stmt->fetch();

    $total_revenue_month = [
        'total' => floatval($month_sales['total']) + floatval($client_orders_month['order_total']),
        'count' => intval($month_sales['count']) + intval($client_orders_month['order_count']),
    ];

    // ============================================
    // TOTAL PRODUCTS + LOW STOCK
    // ============================================
    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM products WHERE status = 'active'");
    $stmt->execute();
    $total_products = $stmt->fetch();

    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT p.product_id) AS count
        FROM products p
        LEFT JOIN store_inventory si ON p.product_id = si.product_id
        WHERE p.status = 'active'
          AND (si.quantity <= p.reorder_level OR si.quantity IS NULL)
    ");
    $stmt->execute();
    $low_stock = $stmt->fetch();

    // ============================================
    // TOTAL CUSTOMERS
    // ============================================
    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM customers WHERE status = 'active'");
    $stmt->execute();
    $total_customers = $stmt->fetch();

    // ============================================
    // EXPIRING PRODUCTS (filtered by store, excluding damages)
    // ============================================
    $expiring_sql = "
        SELECT COUNT(DISTINCT si.inventory_id) AS count,
               COALESCE(SUM(si.quantity * p.purchase_price), 0) AS total_value
        FROM store_inventory si
        JOIN products p ON si.product_id = p.product_id
        JOIN stores st  ON si.store_id   = st.store_id
        WHERE p.has_expiry = 'yes'
          AND si.expiry_date IS NOT NULL
          AND si.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND si.quantity > 0
          AND st.store_type = 'sales'
    ";
    $expiring_params = [];
    if ($store_filter > 0) {
        $expiring_sql .= " AND si.store_id = ?";
        $expiring_params[] = $store_filter;
    }
    $stmt = $conn->prepare($expiring_sql);
    $stmt->execute($expiring_params);
    $expiring_products = $stmt->fetch();

    // ============================================
    // DAMAGED / EXPIRED STOCK (in damages store)
    // ============================================
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT si.inventory_id) AS batch_count,
            COALESCE(SUM(si.quantity * p.purchase_price), 0) AS total_value,
            COALESCE(SUM(si.quantity), 0) AS total_units
        FROM store_inventory si
        JOIN products p ON si.product_id = p.product_id
        JOIN stores st  ON si.store_id   = st.store_id
        WHERE st.store_type = 'damages'
          AND si.quantity > 0
    ");
    $stmt->execute();
    $damages_summary = $stmt->fetch();

    // ============================================
    // 7-DAY TREND SPARKLINE
    // ============================================
    $stmt = $conn->prepare("
        SELECT DATE(sale_date) AS day, COALESCE(SUM(total_amount), 0) AS revenue
        FROM sales
        WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
          AND status = 'completed'
        GROUP BY DATE(sale_date)
        ORDER BY day ASC
    ");
    $stmt->execute();
    $spark_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spark_by_date = [];
    foreach ($spark_raw as $row) {
        $spark_by_date[$row['day']] = $row['revenue'];
    }
    $spark_labels = [];
    $spark_values = [];
    for ($i = 6; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $spark_labels[] = date('D', strtotime($day));
        $spark_values[] = isset($spark_by_date[$day]) ? (float) $spark_by_date[$day] : 0;
    }

    // ============================================
    // TOP PRODUCTS
    // ============================================
    $stmt = $conn->prepare("
        SELECT p.product_name, SUM(si.quantity) AS total_sold,
               SUM(si.total_price) AS revenue
        FROM sale_items si
        JOIN products p ON si.product_id = p.product_id
        JOIN sales s ON si.sale_id = s.sale_id
        WHERE s.status = 'completed'
          AND MONTH(s.sale_date) = MONTH(CURDATE())
        GROUP BY p.product_id, p.product_name
        ORDER BY total_sold DESC
        LIMIT 5
    ");
    $stmt->execute();
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // RECENT SALES
    // ============================================
    $stmt = $conn->prepare("
        SELECT s.*, st.store_name, c.customer_name, u.full_name AS served_by_name
        FROM sales s
        LEFT JOIN stores st ON s.store_id = st.store_id
        LEFT JOIN customers c ON s.customer_id = c.customer_id
        LEFT JOIN users u ON s.served_by = u.user_id
        ORDER BY s.sale_date DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recent_sales = $stmt->fetchAll();

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<style>
/* ============================================
   DASHBOARD COMPACT REDESIGN
   ============================================ */
.dash-wrap { 
    background: #F8FAFC; 
    min-height: calc(100vh - 60px); 
    padding: 20px !important; 
}

/* Compact Hero */
.dash-hero {
    background: linear-gradient(135deg, #1A202C 0%, #2D3748 100%);
    border-radius: 12px;
    padding: 20px 24px;
    color: #fff;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
.dash-hero-title { font-size: 1.3rem; font-weight: 800; margin: 0 0 4px; }
.dash-hero-title span { color: #ED8936; }
.dash-hero-subtitle { color: rgba(255,255,255,0.7); font-size: 0.85rem; margin: 0; }
.dash-hero-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 8px;
    font-weight: 700; font-size: 0.75rem;
    text-transform: uppercase; text-decoration: none; 
    transition: all 0.2s ease;
}
.dash-hero-btn-primary { background: #6B46C1; color: #fff; }
.dash-hero-btn-primary:hover { background: #553C9A; color: #fff; }
.dash-hero-btn-ghost { background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2); }
.dash-hero-btn-ghost:hover { background: rgba(255,255,255,0.2); color: #fff; }

/* KPI Grid - 5 Columns for compactness */
.kpi-grid { 
    display: grid; 
    grid-template-columns: repeat(5, 1fr); 
    gap: 12px; 
    margin-bottom: 20px; 
}
@media (max-width: 1400px) { .kpi-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 991px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 575px) { .kpi-grid { grid-template-columns: 1fr; } }

/* Compact KPI Cards */
.kpi {
    background: #fff; border-radius: 10px; 
    padding: 14px 16px; /* Tighter padding */
    border: 1px solid #E2E8F0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    display: flex; flex-direction: column;
    transition: all 0.2s ease;
    min-height: auto;
}
.kpi:hover { 
    transform: translateY(-2px); 
    box-shadow: 0 6px 12px rgba(0,0,0,0.06); 
    border-color: #CBD5E0;
}

.kpi-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
.kpi-icon {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.9rem;
}
.kpi-purple .kpi-icon { background: rgba(107,70,193,0.12); color: #6B46C1; }
.kpi-orange .kpi-icon { background: rgba(237,137,54,0.12); color: #ED8936; }
.kpi-pink   .kpi-icon { background: rgba(237,100,166,0.12); color: #ED64A6; }
.kpi-indigo .kpi-icon { background: rgba(90,103,216,0.12); color: #5A67D8; }
.kpi-green  .kpi-icon { background: rgba(72,187,120,0.12); color: #48BB78; }
.kpi-red    .kpi-icon { background: rgba(229,62,62,0.12); color: #E53E3E; }

.kpi-delta {
    display: inline-flex; align-items: center; gap: 2px;
    padding: 2px 6px; border-radius: 12px;
    font-size: 0.6rem; font-weight: 800;
}
.kpi-delta.up   { background: rgba(72,187,120,0.15); color: #2F855A; }
.kpi-delta.down { background: rgba(229,62,62,0.15);  color: #C53030; }
.kpi-delta.flat { background: rgba(113,128,150,0.15); color: #4A5568; }

.kpi-label {
    font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.5px; color: #718096; margin-bottom: 4px;
}
.kpi-value {
    font-size: 1.3rem; font-weight: 800; color: #1A202C;
    line-height: 1.1; font-family: 'Courier New', monospace;
    margin-bottom: 2px;
}
.kpi-sub { font-size: 0.7rem; color: #718096; display: flex; align-items: center; gap: 4px; }

/* Special styling for damages card */
.kpi-red {
    background: #FFF5F5;
    border-color: rgba(229,62,62,0.2);
}
.kpi-red .kpi-value { color: #C53030; }

/* Expiring Alert */
.dash-alert {
    display: flex; gap: 12px; align-items: center;
    padding: 12px 16px; border-radius: 10px;
    background: #FFFAF0;
    border: 1px solid rgba(237,137,54,0.3);
    margin-bottom: 20px;
}
.dash-alert-icon {
    width: 32px; height: 32px; border-radius: 8px;
    background: #ED8936;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 0.9rem; flex-shrink: 0;
}

/* Chart Cards */
.chart-card {
    background: #fff; border-radius: 12px; border: 1px solid #E2E8F0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    overflow: hidden; height: 100%;
    display: flex; flex-direction: column;
}
.chart-card-header {
    padding: 14px 18px;
    border-bottom: 1px solid #EDF2F7;
    display: flex; justify-content: space-between; align-items: center;
}
.chart-card-title {
    font-size: 0.85rem; font-weight: 800; color: #1A202C;
    display: flex; align-items: center; gap: 8px;
}
.chart-card-title i { color: #6B46C1; }
.chart-card-badge {
    font-size: 0.6rem; font-weight: 800; padding: 3px 8px;
    border-radius: 12px; background: rgba(107,70,193,0.1);
    color: #6B46C1; text-transform: uppercase;
}
.chart-card-body { padding: 16px; flex: 1; min-height: 220px; position: relative; }
.chart-card-body canvas { max-height: 220px; }

/* Quick Actions - Compact */
.qa-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; }
@media (max-width: 991px) { .qa-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 575px) { .qa-grid { grid-template-columns: repeat(2, 1fr); } }

.qa-btn {
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    padding: 14px 8px; border-radius: 10px; text-decoration: none;
    color: #fff; font-weight: 700; font-size: 0.7rem; text-align: center;
    transition: all 0.2s ease;
}
.qa-btn i { font-size: 1.2rem; }
.qa-btn:hover { transform: translateY(-2px); color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.qa-purple { background: #6B46C1; }
.qa-orange { background: #ED8936; }
.qa-cyan   { background: #00B5D8; }
.qa-green  { background: #48BB78; }
.qa-pink   { background: #ED64A6; }
.qa-blue   { background: #4299E1; }

/* Recent Sales - Compact Table */
.rs-table { width: 100%; border-collapse: collapse; font-size: 0.78rem; }
.rs-table thead th {
    background: #F8FAFC; color: #4A5568;
    font-size: 0.65rem; font-weight: 800; text-transform: uppercase;
    padding: 10px 14px; border-bottom: 1px solid #E2E8F0; text-align: left; white-space: nowrap;
}
.rs-table tbody td { padding: 10px 14px; border-bottom: 1px solid #EDF2F7; vertical-align: middle; }
.rs-table tbody tr:hover { background: #F8FAFC; }
.inv-code { font-family: 'Courier New', monospace; font-weight: 700; color: #6B46C1; }

/* Layout Adjustments */
.dash-row { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 20px; }
@media (max-width: 991px) { .dash-row { grid-template-columns: 1fr; } }
.dash-section { margin-bottom: 20px; }

</style>

<div class="main-content">
    <div class="content-area dash-wrap">

        <!-- HERO -->
        <div class="dash-hero">
            <div>
                <h1 class="dash-hero-title">
                    Welcome, <span><?php echo htmlspecialchars(explode(' ', $_SESSION['full_name'])[0]); ?></span> 👋
                </h1>
                <p class="dash-hero-subtitle">Here's your business at a glance — <?php echo date('l, F j, Y'); ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="pos.php" class="dash-hero-btn dash-hero-btn-primary">
                    <i class="fas fa-cash-register"></i>New Sale
                </a>
                <a href="reports.php" class="dash-hero-btn dash-hero-btn-ghost">
                    <i class="fas fa-chart-line"></i>Reports
                </a>
            </div>
        </div>

        <!-- EXPIRING ALERT -->
        <?php if ($expiring_products['count'] > 0): ?>
        <div class="dash-alert">
            <div class="dash-alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="flex-grow-1">
                <strong style="color:#C05621; font-size:0.85rem;">
                    <?php echo $expiring_products['count']; ?> batch(es) expiring soon (<?php echo htmlspecialchars($selected_store_name); ?>)
                </strong>
                <div style="font-size:0.75rem; color:#744210;">
                    Value at risk: UGX <?php echo number_format($expiring_products['total_value'], 0); ?>
                </div>
            </div>
            <form method="GET" class="d-flex gap-2 align-items-center me-2">
                <select name="store_id" class="form-select form-select-sm" style="min-width:130px; font-size:0.75rem;" onchange="this.form.submit()">
                    <option value="0" <?php echo $store_filter === 0 ? 'selected' : ''; ?>>All Stores</option>
                    <?php foreach ($stores as $s): ?>
                        <option value="<?php echo (int)$s['store_id']; ?>" <?php echo $store_filter === (int)$s['store_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['store_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a href="expiring-products.php?store_id=<?php echo (int)$store_filter; ?>" class="btn btn-sm btn-warning" style="font-size:0.75rem;">
                View
            </a>
        </div>
        <?php endif; ?>

        <!-- KPI CARDS (Top Row) -->
        <div class="kpi-grid">
            <!-- Today's Sales -->
            <div class="kpi kpi-purple">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class="fas fa-shopping-cart"></i></div>
                    <?php if ($yesterday_total > 0): ?>
                    <span class="kpi-delta <?php echo $today_delta > 0 ? 'up' : ($today_delta < 0 ? 'down' : 'flat'); ?>">
                        <?php echo number_format(abs($today_delta), 1); ?>%
                    </span>
                    <?php endif; ?>
                </div>
                <div class="kpi-label">Today's Sales</div>
                <div class="kpi-value">UGX <?php echo number_format($today_sales['total'], 0); ?></div>
                <div class="kpi-sub"><i class="fas fa-receipt"></i> <?php echo $today_sales['count']; ?> txns</div>
            </div>

            <!-- POS Sales MTD -->
            <div class="kpi kpi-orange">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class="fas fa-cash-register"></i></div>
                    <?php if ($last_month_total > 0): ?>
                    <span class="kpi-delta <?php echo $month_delta > 0 ? 'up' : ($month_delta < 0 ? 'down' : 'flat'); ?>">
                        <?php echo number_format(abs($month_delta), 1); ?>%
                    </span>
                    <?php endif; ?>
                </div>
                <div class="kpi-label">POS Sales (MTD)</div>
                <div class="kpi-value">UGX <?php echo number_format($month_sales['total'], 0); ?></div>
                <div class="kpi-sub"><i class="fas fa-calendar"></i> vs UGX <?php echo number_format($last_month_total, 0); ?></div>
            </div>

            <!-- Client Orders MTD -->
            <div class="kpi kpi-pink">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class="fas fa-shopping-bag"></i></div>
                </div>
                <div class="kpi-label">Client Orders (MTD)</div>
                <div class="kpi-value">UGX <?php echo number_format($client_orders_month['order_total'], 0); ?></div>
                <div class="kpi-sub text-success"><i class="fas fa-check-circle"></i> <?php echo $client_orders_month['order_count']; ?> orders</div>
            </div>

            <!-- Total Revenue -->
            <div class="kpi kpi-indigo">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class="fas fa-chart-pie"></i></div>
                </div>
                <div class="kpi-label">Total Revenue (MTD)</div>
                <div class="kpi-value">UGX <?php echo number_format($total_revenue_month['total'], 0); ?></div>
                <div class="kpi-sub"><i class="fas fa-layer-group"></i> Combined</div>
            </div>

            <!-- Damages -->
            <div class="kpi kpi-red">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class="fas fa-trash-alt"></i></div>
                </div>
                <div class="kpi-label">Damaged / Expired</div>
                <div class="kpi-value">UGX <?php echo number_format($damages_summary['total_value'], 0); ?></div>
                <div class="kpi-sub"><i class="fas fa-exclamation-triangle"></i> <?php echo $damages_summary['batch_count']; ?> batches</div>
            </div>
        </div>

        <!-- SMALLER STATS ROW -->
        <div class="row g-2 mb-4">
            <div class="col-md-6">
                <div class="kpi kpi-green d-flex flex-row align-items-center justify-content-between p-3">
                    <div>
                        <div class="kpi-label mb-0">Total Products</div>
                        <div class="kpi-value mb-0" style="font-size:1.1rem;"><?php echo number_format($total_products['count']); ?></div>
                    </div>
                    <div class="kpi-icon bg-white"><i class="fas fa-box text-success"></i></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="kpi kpi-indigo d-flex flex-row align-items-center justify-content-between p-3">
                    <div>
                        <div class="kpi-label mb-0">Total Customers</div>
                        <div class="kpi-value mb-0" style="font-size:1.1rem;"><?php echo number_format($total_customers['count']); ?></div>
                    </div>
                    <div class="kpi-icon bg-white"><i class="fas fa-users text-primary"></i></div>
                </div>
            </div>
        </div>

        <!-- CHARTS ROW -->
        <div class="dash-row">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div class="chart-card-title"><i class="fas fa-chart-area"></i>Revenue Trend</div>
                    <span class="chart-card-badge">7 Days</span>
                </div>
                <div class="chart-card-body">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <div class="chart-card-header">
                    <div class="chart-card-title"><i class="fas fa-fire"></i>Top Products</div>
                    <span class="chart-card-badge">MTD</span>
                </div>
                <div class="chart-card-body">
                    <canvas id="topProductsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- QUICK ACTIONS -->
        <div class="dash-section">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div class="chart-card-title"><i class="fas fa-bolt"></i>Quick Actions</div>
                </div>
                <div class="chart-card-body py-3" style="min-height:auto;">
                    <div class="qa-grid">
                        <a href="pos.php" class="qa-btn qa-purple"><i class="fas fa-cash-register"></i>New Sale</a>
                        <a href="products.php" class="qa-btn qa-orange"><i class="fas fa-plus-circle"></i>Product</a>
                        <a href="customers.php" class="qa-btn qa-cyan"><i class="fas fa-user-plus"></i>Customer</a>
                        <a href="reports.php" class="qa-btn qa-green"><i class="fas fa-chart-bar"></i>Reports</a>
                        <a href="Client_Orders.php" class="qa-btn qa-pink"><i class="fas fa-shopping-bag"></i>Orders</a>
                        <a href="purchases.php" class="qa-btn qa-blue"><i class="fas fa-truck"></i>Purchase</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- RECENT SALES -->
        <div class="chart-card">
            <div class="chart-card-header">
                <div class="chart-card-title"><i class="fas fa-receipt"></i>Recent Sales</div>
                <a href="sales.php" class="btn btn-sm btn-outline-primary" style="font-size:0.7rem;">View All</a>
            </div>
            <div style="overflow-x:auto;">
                <table class="rs-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Store</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($recent_sales) > 0): ?>
                            <?php foreach ($recent_sales as $sale): ?>
                            <tr>
                                <td><span class="inv-code"><?php echo htmlspecialchars($sale['invoice_number']); ?></span></td>
                                <td><?php echo date('M d, H:i', strtotime($sale['sale_date'])); ?></td>
                                <td><?php echo $sale['customer_name'] ? htmlspecialchars($sale['customer_name']) : 'Walk-in'; ?></td>
                                <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($sale['store_name']); ?></span></td>
                                <td><strong>UGX <?php echo number_format($sale['total_amount'], 0); ?></strong></td>
                                <td><span class="badge bg-success">Paid</span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">No recent sales</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    Chart.defaults.font.family = "'Inter', -apple-system, sans-serif";
    Chart.defaults.font.size = 10;
    Chart.defaults.color = '#718096';

    // Trend Chart
    (function() {
        const el = document.getElementById('trendChart');
        if (!el) return;
        const ctx = el.getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 200);
        grad.addColorStop(0, 'rgba(107,70,193,0.2)');
        grad.addColorStop(1, 'rgba(107,70,193,0.0)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($spark_labels); ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?php echo json_encode($spark_values); ?>,
                    borderColor: '#6B46C1',
                    backgroundColor: grad,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 3,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1A202C',
                        padding: 8,
                        displayColors: false,
                        callbacks: { label: ctx => 'UGX ' + ctx.parsed.y.toLocaleString() }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,0.04)' },
                        ticks: {
                            callback: v => v >= 1000000 ? (v/1000000).toFixed(1)+'M' : (v >= 1000 ? (v/1000).toFixed(0)+'K' : v)
                        }
                    }
                }
            }
        });
    })();

    // Top Products Chart
    (function() {
        const el = document.getElementById('topProductsChart');
        if (!el) return;
        new Chart(el.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(fn($p) => $p['product_name'], $top_products)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_map(fn($p) => (int)$p['total_sold'], $top_products)); ?>,
                    backgroundColor: ['#6B46C1','#805AD5','#9F7AEA','#B794F4','#ED8936'],
                    borderRadius: 4,
                    barThickness: 20
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1A202C',
                        padding: 8,
                        displayColors: false,
                        callbacks: { label: ctx => ctx.parsed.x + ' units' }
                    }
                },
                scales: {
                    x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' } },
                    y: { grid: { display: false }, ticks: { font: { size: 9, weight: '600' } } }
                }
            }
        });
    })();
});
</script>