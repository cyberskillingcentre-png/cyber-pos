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
.dash-wrap { background: linear-gradient(180deg, #F7FAFC 0%, #EDF2F7 100%); min-height: calc(100vh - 60px); }

/* Hero */
.dash-hero {
    background: linear-gradient(135deg, #1A202C 0%, #2D3748 50%, #4A5568 100%);
    border-radius: 20px;
    padding: 32px 36px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 20px 40px rgba(26,32,44,0.15);
    color: #fff;
    margin-bottom: 28px;
}
.dash-hero::before {
    content: '';
    position: absolute;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(237,137,54,0.25), transparent 70%);
    top: -200px; right: -100px;
    border-radius: 50%;
    filter: blur(40px);
}
.dash-hero-content {
    position: relative;
    z-index: 2;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
}
.dash-hero-title { font-size: 1.75rem; font-weight: 800; letter-spacing: -0.5px; margin: 0 0 6px; }
.dash-hero-title span {
    background: linear-gradient(135deg, #ED8936, #FBD38D);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.dash-hero-subtitle { color: rgba(255,255,255,0.7); font-size: 0.95rem; margin: 0; }
.dash-hero-actions { display: flex; gap: 12px; flex-wrap: wrap; }
.dash-hero-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 22px; border-radius: 12px;
    font-weight: 700; font-size: 0.85rem;
    text-transform: uppercase; letter-spacing: 0.5px;
    text-decoration: none; border: none; cursor: pointer;
    transition: all 0.25s ease;
}
.dash-hero-btn-primary {
    background: linear-gradient(135deg, #6B46C1, #553C9A);
    color: #fff; box-shadow: 0 6px 20px rgba(107,70,193,0.4);
}
.dash-hero-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(107,70,193,0.55); color: #fff; }
.dash-hero-btn-ghost {
    background: rgba(255,255,255,0.1); color: #fff;
    border: 1.5px solid rgba(255,255,255,0.2);
}
.dash-hero-btn-ghost:hover { background: rgba(255,255,255,0.18); color: #fff; }

/* KPI Grid */
.kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px; }
@media (max-width: 1199px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 575px)  { .kpi-grid { grid-template-columns: 1fr; } }

.kpi {
    background: #fff; border-radius: 16px; padding: 22px;
    position: relative; overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.05);
    border: 1px solid #E2E8F0;
    transition: all 0.3s ease;
    min-height: 170px;
    display: flex; flex-direction: column;
}
.kpi:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(0,0,0,0.1); }

.kpi-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
.kpi-icon {
    width: 44px; height: 44px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem;
}
.kpi-purple .kpi-icon { background: rgba(107,70,193,0.12); color: #6B46C1; }
.kpi-orange .kpi-icon { background: rgba(237,137,54,0.12); color: #ED8936; }
.kpi-pink   .kpi-icon { background: rgba(237,100,166,0.12); color: #ED64A6; }
.kpi-indigo .kpi-icon { background: rgba(90,103,216,0.12); color: #5A67D8; }
.kpi-green  .kpi-icon { background: rgba(72,187,120,0.12); color: #48BB78; }
.kpi-red    .kpi-icon { background: rgba(229,62,62,0.12); color: #E53E3E; }

.kpi-delta {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 0.7rem; font-weight: 800;
}
.kpi-delta.up   { background: rgba(72,187,120,0.15); color: #2F855A; }
.kpi-delta.down { background: rgba(229,62,62,0.15);  color: #C53030; }
.kpi-delta.flat { background: rgba(113,128,150,0.15); color: #4A5568; }

.kpi-label {
    font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.6px; color: #718096; margin-bottom: 6px;
}
.kpi-value {
    font-size: 1.65rem; font-weight: 800; color: #1A202C;
    line-height: 1.1; font-family: 'Courier New', monospace;
    letter-spacing: -0.5px; margin-bottom: 4px;
}
.kpi-sub { font-size: 0.78rem; color: #718096; display: flex; align-items: center; gap: 6px; }
.kpi-sparkline { position: absolute; bottom: 0; left: 0; right: 0; height: 44px; opacity: 0.6; pointer-events: none; }

/* Chart Cards */
.chart-card {
    background: #fff; border-radius: 16px; border: 1px solid #E2E8F0;
    box-shadow: 0 4px 20px rgba(0,0,0,0.04);
    overflow: hidden; height: 100%;
    display: flex; flex-direction: column;
    transition: box-shadow 0.3s ease;
}
.chart-card:hover { box-shadow: 0 12px 32px rgba(0,0,0,0.08); }

.chart-card-header {
    padding: 18px 22px;
    border-bottom: 1px solid #EDF2F7;
    display: flex; justify-content: space-between; align-items: center;
}
.chart-card-title {
    font-size: 0.95rem; font-weight: 800; color: #1A202C;
    display: flex; align-items: center; gap: 10px;
}
.chart-card-title i { color: #6B46C1; font-size: 1rem; }
.chart-card-badge {
    font-size: 0.68rem; font-weight: 800; padding: 4px 10px;
    border-radius: 20px; background: rgba(107,70,193,0.1);
    color: #6B46C1; text-transform: uppercase; letter-spacing: 0.5px;
}
.chart-card-body { padding: 20px 22px; flex: 1; min-height: 260px; position: relative; }
.chart-card-body canvas { max-height: 280px; }

/* Quick Actions */
.qa-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; }
@media (max-width: 991px) { .qa-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 575px) { .qa-grid { grid-template-columns: repeat(2, 1fr); } }

.qa-btn {
    display: flex; flex-direction: column; align-items: center; gap: 10px;
    padding: 22px 14px; border-radius: 14px; text-decoration: none;
    color: #fff; font-weight: 700; font-size: 0.8rem; text-align: center;
    transition: all 0.25s ease; position: relative; overflow: hidden;
}
.qa-btn i { font-size: 1.6rem; transition: transform 0.25s ease; }
.qa-btn:hover { transform: translateY(-4px); color: #fff; box-shadow: 0 12px 25px rgba(0,0,0,0.15); }
.qa-btn:hover i { transform: scale(1.15); }
.qa-purple { background: linear-gradient(135deg,#6B46C1,#553C9A); }
.qa-orange { background: linear-gradient(135deg,#ED8936,#DD6B20); }
.qa-cyan   { background: linear-gradient(135deg,#00B5D8,#0987A0); }
.qa-green  { background: linear-gradient(135deg,#48BB78,#38A169); }
.qa-pink   { background: linear-gradient(135deg,#ED64A6,#D53F8C); }
.qa-blue   { background: linear-gradient(135deg,#4299E1,#3182CE); }

/* Recent Sales */
.rs-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.rs-table thead th {
    background: #F7FAFC; color: #4A5568;
    font-size: 0.7rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.5px; padding: 12px 14px;
    border-bottom: 2px solid #E2E8F0; text-align: left; white-space: nowrap;
}
.rs-table tbody td { padding: 12px 14px; border-bottom: 1px solid #EDF2F7; vertical-align: middle; }
.rs-table tbody tr:hover { background: #F7FAFC; }
.inv-code { font-family: 'Courier New', monospace; font-weight: 700; color: #6B46C1; font-size: 0.8rem; }

/* Damages card special styling */
.kpi-red {
    background: linear-gradient(135deg, #FFF5F5 0%, #FED7D7 100%);
    border-color: rgba(229,62,62,0.2);
}
.kpi-red .kpi-value { color: #C53030; }

/* Expiring Alert */
.dash-alert {
    display: flex; gap: 16px; align-items: flex-start;
    padding: 18px 22px; border-radius: 14px;
    background: linear-gradient(135deg, rgba(237,137,54,0.08), rgba(237,137,54,0.03));
    border-left: 4px solid #ED8936;
    margin-bottom: 24px;
}
.dash-alert-icon {
    width: 44px; height: 44px; border-radius: 12px;
    background: linear-gradient(135deg,#ED8936,#DD6B20);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1.15rem; flex-shrink: 0;
    box-shadow: 0 6px 15px rgba(237,137,54,0.3);
}
.dash-row { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 28px; }
@media (max-width: 991px) { .dash-row { grid-template-columns: 1fr; } }
.dash-section { margin-bottom: 28px; }
</style>

<div class="main-content">
    <div class="content-area dash-wrap">

        <!-- HERO -->
        <div class="dash-hero">
            <div class="dash-hero-content">
                <div>
                    <h1 class="dash-hero-title">
                        Welcome back, <span><?php echo htmlspecialchars(explode(' ', $_SESSION['full_name'])[0]); ?></span>! 👋
                    </h1>
                    <p class="dash-hero-subtitle">
                        Here's your business at a glance — <?php echo date('l, F j, Y'); ?>
                    </p>
                </div>
                <div class="dash-hero-actions">
                    <a href="pos.php" class="dash-hero-btn dash-hero-btn-primary">
                        <i class="fas fa-cash-register"></i>New Sale
                    </a>
                    <a href="reports.php" class="dash-hero-btn dash-hero-btn-ghost">
                        <i class="fas fa-chart-line"></i>Reports
                    </a>
                </div>
            </div>
        </div>

        <!-- EXPIRING ALERT (with store filter) -->
        <?php if ($expiring_products['count'] > 0): ?>
        <div class="dash-alert">
            <div class="dash-alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div style="flex:1;">
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                    <h6 style="margin:0;font-weight:800;color:#C05621;">
                        <?php echo $expiring_products['count']; ?> product batch(es) expiring soon —
                        <?php echo htmlspecialchars($selected_store_name); ?>
                    </h6>
                    <form method="GET" class="d-flex gap-2 align-items-center">
                        <select name="store_id" class="form-select form-select-sm"
                                style="min-width:160px;" onchange="this.form.submit()">
                            <option value="0" <?php echo $store_filter === 0 ? 'selected' : ''; ?>>All Stores</option>
                            <?php foreach ($stores as $s): ?>
                                <option value="<?php echo (int)$s['store_id']; ?>"
                                        <?php echo $store_filter === (int)$s['store_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['store_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <p style="margin:0;font-size:0.85rem;color:#744210;">
                    Value at risk: <strong>UGX <?php echo number_format($expiring_products['total_value'], 0); ?></strong>
                </p>
            </div>
            <a href="expiring-products.php?store_id=<?php echo (int)$store_filter; ?>"
               class="btn btn-warning btn-sm">
                <i class="fas fa-eye me-1"></i>View
            </a>
        </div>
        <?php endif; ?>

        <!-- KPI CARDS -->
        <div class="kpi-grid">

            <!-- Today's Sales -->
            <div class="kpi kpi-purple">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class="fas fa-shopping-cart"></i></div>
                    <?php if ($yesterday_total > 0): ?>
                    <span class="kpi-delta <?php echo $today_delta > 0 ? 'up' : ($today_delta < 0 ? 'down' : 'flat'); ?>">
                        <i class="fas fa-arrow-<?php echo $today_delta > 0 ? 'up' : ($today_delta < 0 ? 'down' : 'right'); ?>"></i>
                        <?php echo number_format(abs($today_delta), 1); ?>%
                    </span>
                    <?php endif; ?>
                </div>
                <div class="kpi-label">Today's Sales</div>
                <div class="kpi-value">UGX <?php echo number_format($today_sales['total'], 0); ?></div>
                <div class="kpi-sub">
                    <i class="fas fa-receipt"></i>
                    <?php echo number_format($today_sales['count']); ?> transactions
                </div>
                <canvas class="kpi-sparkline" id="sparkToday"></canvas>
            </div>

            <!-- POS Sales MTD -->
            <div class="kpi kpi-orange">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class="fas fa-cash-register"></i></div>
                    <?php if ($last_month_total > 0): ?>
                    <span class="kpi-delta <?php echo $month_delta > 0 ? 'up' : ($month_delta < 0 ? 'down' : 'flat'); ?>">
                        <i class="fas fa-arrow-<?php echo $month_delta > 0 ? 'up' : ($month_delta < 0 ? 'down' : 'right'); ?>"></i>
                        <?php echo number_format(abs($month_delta), 1); ?>%
                    </span>
                    <?php endif; ?>
                </div>
                <div class="kpi-label">POS Sales (MTD)</div>
                <div class="kpi-value">UGX <?php echo number_format($month_sales['total'], 0); ?></div>
                <div class="kpi-sub">
                    <i class="fas fa-calendar"></i>
                    vs UGX <?php echo number_format($last_month_total, 0); ?> last month
                </div>
            </div>

            <!-- Client Orders MTD -->
            <div class="kpi kpi-pink">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class="fas fa-shopping-bag"></i></div>
                    <span class="kpi-delta flat">
                        <?php echo number_format($client_orders_month['order_count']); ?> orders
                    </span>
                </div>
                <div class="kpi-label">Client Orders (MTD)</div>
                <div class="kpi-value">UGX <?php echo number_format($client_orders_month['order_total'], 0); ?></div>
                <div class="kpi-sub">
                    <i class="fas fa-check-circle text-success"></i>
                    Received: UGX <?php echo number_format($client_orders_month['amount_received'], 0); ?>
                </div>
            </div>

            <!-- Total Revenue -->
            <div class="kpi kpi-indigo">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class="fas fa-chart-pie"></i></div>
                    <span class="kpi-delta up">
                        <i class="fas fa-calculator"></i>Combined
                    </span>
                </div>
                <div class="kpi-label">Total Revenue (MTD)</div>
                <div class="kpi-value">UGX <?php echo number_format($total_revenue_month['total'], 0); ?></div>
                <div class="kpi-sub">
                    <i class="fas fa-layer-group"></i>
                    POS + Client Orders
                </div>
            </div>

        </div>

        <!-- DAMAGES CARD (separate row) -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="kpi kpi-red">
                    <div class="kpi-header">
                        <div class="kpi-icon"><i class="fas fa-trash-alt"></i></div>
                        <span class="kpi-delta down">
                            <i class="fas fa-exclamation-triangle"></i>Written off
                        </span>
                    </div>
                    <div class="kpi-label">Damaged / Expired Stock</div>
                    <div class="kpi-value">UGX <?php echo number_format($damages_summary['total_value'], 0); ?></div>
                    <div class="kpi-sub">
                        <i class="fas fa-boxes"></i>
                        <?php echo number_format($damages_summary['batch_count']); ?> batches
                        (<?php echo number_format($damages_summary['total_units']); ?> units)
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="kpi kpi-green">
                    <div class="kpi-header">
                        <div class="kpi-icon"><i class="fas fa-box"></i></div>
                    </div>
                    <div class="kpi-label">Total Products</div>
                    <div class="kpi-value"><?php echo number_format($total_products['count']); ?></div>
                    <div class="kpi-sub <?php echo $low_stock['count'] > 0 ? 'text-danger' : ''; ?>">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo $low_stock['count']; ?> low stock
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="kpi kpi-indigo">
                    <div class="kpi-header">
                        <div class="kpi-icon"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="kpi-label">Total Customers</div>
                    <div class="kpi-value"><?php echo number_format($total_customers['count']); ?></div>
                    <div class="kpi-sub">
                        <i class="fas fa-user-check"></i>Active
                    </div>
                </div>
            </div>
        </div>

        <!-- CHARTS ROW 1 -->
        <div class="dash-row">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div class="chart-card-title">
                        <i class="fas fa-chart-area"></i>Revenue Trend
                    </div>
                    <span class="chart-card-badge">Last 7 Days</span>
                </div>
                <div class="chart-card-body">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <div class="chart-card-header">
                    <div class="chart-card-title">
                        <i class="fas fa-fire"></i>Top Products
                    </div>
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
                    <div class="chart-card-title">
                        <i class="fas fa-bolt"></i>Quick Actions
                    </div>
                </div>
                <div class="chart-card-body" style="min-height:auto;">
                    <div class="qa-grid">
                        <a href="pos.php" class="qa-btn qa-purple"><i class="fas fa-cash-register"></i><span>New Sale</span></a>
                        <a href="products.php" class="qa-btn qa-orange"><i class="fas fa-plus-circle"></i><span>Add Product</span></a>
                        <a href="customers.php" class="qa-btn qa-cyan"><i class="fas fa-user-plus"></i><span>Add Customer</span></a>
                        <a href="reports.php" class="qa-btn qa-green"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
                        <a href="Client_Orders.php" class="qa-btn qa-pink"><i class="fas fa-shopping-bag"></i><span>Client Orders</span></a>
                        <a href="purchases.php" class="qa-btn qa-blue"><i class="fas fa-truck"></i><span>New Purchase</span></a>
                    </div>
                </div>
            </div>
        </div>

        <!-- RECENT SALES -->
        <div class="chart-card">
            <div class="chart-card-header">
                <div class="chart-card-title">
                    <i class="fas fa-receipt"></i>Recent Sales
                </div>
                <a href="sales.php" class="btn btn-sm btn-outline-primary">
                    View All <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
            <div style="overflow-x:auto;">
                <table class="rs-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Date &amp; Time</th>
                            <th>Customer</th>
                            <th>Store</th>
                            <th>Served By</th>
                            <th>Amount</th>
                            <th>Payment</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($recent_sales) > 0): ?>
                            <?php foreach ($recent_sales as $sale): ?>
                            <tr>
                                <td><span class="inv-code"><?php echo htmlspecialchars($sale['invoice_number']); ?></span></td>
                                <td>
                                    <div><?php echo date('M d, Y', strtotime($sale['sale_date'])); ?></div>
                                    <small class="text-muted"><?php echo date('H:i A', strtotime($sale['sale_date'])); ?></small>
                                </td>
                                <td><?php echo $sale['customer_name'] ? htmlspecialchars($sale['customer_name']) : '<span class="text-muted">Walk-in</span>'; ?></td>
                                <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($sale['store_name']); ?></span></td>
                                <td><?php echo htmlspecialchars($sale['served_by_name']); ?></td>
                                <td><strong>UGX <?php echo number_format($sale['total_amount'], 0); ?></strong></td>
                                <td><span class="badge bg-secondary"><?php echo ucfirst(str_replace('_', ' ', $sale['payment_method'])); ?></span></td>
                                <td><span class="badge bg-success"><?php echo ucfirst($sale['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No recent sales</td></tr>
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
    Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#718096';

    // Sparkline
    (function() {
        const el = document.getElementById('sparkToday');
        if (!el) return;
        const ctx = el.getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 44);
        grad.addColorStop(0, 'rgba(107,70,193,0.35)');
        grad.addColorStop(1, 'rgba(107,70,193,0)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($spark_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($spark_values); ?>,
                    borderColor: '#6B46C1',
                    backgroundColor: grad,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: { x: { display: false }, y: { display: false } }
            }
        });
    })();

    // Trend
    (function() {
        const el = document.getElementById('trendChart');
        if (!el) return;
        const ctx = el.getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 260);
        grad.addColorStop(0, 'rgba(107,70,193,0.35)');
        grad.addColorStop(1, 'rgba(107,70,193,0.01)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($spark_labels); ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?php echo json_encode($spark_values); ?>,
                    borderColor: '#6B46C1',
                    backgroundColor: grad,
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#ED8936',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 3,
                    pointRadius: 5,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1A202C',
                        bodyColor: '#ED8936',
                        padding: 12,
                        displayColors: false,
                        callbacks: {
                            label: ctx => 'UGX ' + ctx.parsed.y.toLocaleString()
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: {
                            callback: v => v >= 1000000 ? (v/1000000).toFixed(1)+'M' : (v >= 1000 ? (v/1000).toFixed(0)+'K' : v)
                        }
                    }
                }
            }
        });
    })();

    // Top Products
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
                    borderRadius: 6
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
                        bodyColor: '#ED8936',
                        padding: 12,
                        displayColors: false,
                        callbacks: { label: ctx => ctx.parsed.x + ' units' }
                    }
                },
                scales: {
                    x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                    y: { grid: { display: false }, ticks: { font: { size: 10, weight: '600' } } }
                }
            }
        });
    })();
});
</script>