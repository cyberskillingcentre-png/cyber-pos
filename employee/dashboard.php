<?php
require_once '../config/config.php';
require_login();

if (is_admin()) { 
    header('Location: ../admin/dashboard.php'); 
    exit(); 
}

$page_title = "Employee Dashboard";

// ============================================
// FETCH DASHBOARD STATISTICS
// ============================================
try {
    // Today's sales (this employee only)
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total 
        FROM sales 
        WHERE DATE(sale_date) = CURDATE() 
        AND served_by = ? 
        AND status = 'completed'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $today_sales = $stmt->fetch();
    
    // This week's sales
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total 
        FROM sales 
        WHERE YEARWEEK(sale_date, 1) = YEARWEEK(CURDATE(), 1) 
        AND served_by = ? 
        AND status = 'completed'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $week_sales = $stmt->fetch();
    
    // This month's sales
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total 
        FROM sales 
        WHERE MONTH(sale_date) = MONTH(CURDATE()) 
        AND YEAR(sale_date) = YEAR(CURDATE()) 
        AND served_by = ? 
        AND status = 'completed'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $month_sales = $stmt->fetch();
    
    // Recent sales
    $stmt = $conn->prepare("
        SELECT s.*, st.store_name, c.customer_name 
        FROM sales s 
        LEFT JOIN stores st ON s.store_id = st.store_id 
        LEFT JOIN customers c ON s.customer_id = c.customer_id 
        WHERE s.served_by = ? 
        ORDER BY s.sale_date DESC 
        LIMIT 8
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top selling products (this employee)
    $stmt = $conn->prepare("
        SELECT p.product_name, SUM(si.quantity) as total_sold, 
               SUM(si.total_price) as revenue
        FROM sale_items si
        JOIN products p ON si.product_id = p.product_id
        JOIN sales s ON si.sale_id = s.sale_id
        WHERE s.served_by = ? 
        AND s.status = 'completed'
        AND MONTH(s.sale_date) = MONTH(CURDATE())
        GROUP BY p.product_id, p.product_name
        ORDER BY total_sold DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Sales chart data (last 7 days)
    $stmt = $conn->prepare("
        SELECT DATE(sale_date) as date, 
               COUNT(*) as count, 
               COALESCE(SUM(total_amount), 0) as total 
        FROM sales 
        WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
        AND served_by = ? 
        AND status = 'completed'
        GROUP BY DATE(sale_date) 
        ORDER BY date ASC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $sales_chart_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Prepare chart data
$chart_labels = [];
$chart_values = [];
foreach ($sales_chart_data as $data) {
    $chart_labels[] = date('D', strtotime($data['date']));
    $chart_values[] = floatval($data['total']);
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/employee_sidebar.php'; ?>

<style>
/* ============================================
   COMPACT EMPLOYEE DASHBOARD
   ============================================ */
.dash-wrap {
    background: #F8FAFC;
    min-height: calc(100vh - 60px);
    padding: 20px !important;
}

/* Welcome Banner */
.welcome-banner {
    background: linear-gradient(135deg, #1A202C 0%, #2D3748 100%);
    border-radius: 12px;
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.welcome-text h2 {
    color: #fff;
    font-size: 1.3rem;
    font-weight: 800;
    margin-bottom: 2px;
}

.welcome-text h2 span {
    color: #ED8936;
}

.welcome-text p {
    color: rgba(255, 255, 255, 0.7);
    margin: 0;
    font-size: 0.85rem;
}

.btn-start-sale {
    background: #6B46C1;
    color: #fff;
    font-weight: 700;
    font-size: 0.75rem;
    padding: 10px 18px;
    border-radius: 8px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.btn-start-sale:hover {
    background: #553C9A;
    color: #fff;
    transform: translateY(-2px);
}

/* Stat Cards */
.stat-card-compact {
    background: #fff;
    border-radius: 10px;
    padding: 16px;
    border: 1px solid #E2E8F0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    display: flex;
    align-items: flex-start;
    gap: 12px;
    height: 100%;
    transition: all 0.2s;
}

.stat-card-compact:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.stat-icon-compact {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}

.icon-purple { background: rgba(107, 70, 193, 0.1); color: #6B46C1; }
.icon-orange { background: rgba(237, 137, 54, 0.1); color: #ED8936; }
.icon-cyan { background: rgba(0, 181, 216, 0.1); color: #00B5D8; }

.stat-content-compact {
    flex: 1;
    min-width: 0;
}

.stat-label-compact {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #718096;
    margin-bottom: 2px;
}

.stat-value-compact {
    font-size: 1.3rem;
    font-weight: 800;
    color: #1A202C;
    margin-bottom: 2px;
    line-height: 1;
}

.stat-meta-compact {
    font-size: 0.7rem;
    color: #4A5568;
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Chart & Lists */
.compact-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #E2E8F0;
    overflow: hidden;
    height: 100%;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    display: flex;
    flex-direction: column;
}

.compact-card-header {
    padding: 12px 16px;
    border-bottom: 1px solid #EDF2F7;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 700;
    font-size: 0.85rem;
    color: #1A202C;
}

.compact-card-header i {
    color: #6B46C1;
    margin-right: 6px;
}

.compact-card-body {
    padding: 16px;
    flex: 1;
    position: relative;
}

/* Top Products */
.top-prod-list {
    display: flex;
    flex-direction: column;
}

.top-prod-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px dashed #EDF2F7;
}

.top-prod-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.top-prod-rank {
    width: 24px;
    height: 24px;
    background: #EDF2F7;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    font-weight: 800;
    color: #4A5568;
}

.top-prod-rank.rank-1 { background: #FEFCBF; color: #B7791F; }
.top-prod-rank.rank-2 { background: #E2E8F0; color: #4A5568; }
.top-prod-rank.rank-3 { background: #FBD38D; color: #9C4221; }

.top-prod-info {
    flex: 1;
    min-width: 0;
}

.top-prod-info h6 {
    margin: 0;
    font-size: 0.8rem;
    font-weight: 700;
    color: #1A202C;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.top-prod-info small {
    font-size: 0.7rem;
    color: #718096;
}

.top-prod-rev {
    font-weight: 700;
    font-size: 0.8rem;
    color: #2F855A;
}

/* Quick Actions */
.qa-grid-compact {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}

.qa-btn-compact {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 14px 8px;
    border-radius: 10px;
    text-decoration: none;
    color: #fff;
    font-weight: 700;
    font-size: 0.7rem;
    text-align: center;
    transition: all 0.2s;
}

.qa-btn-compact:hover {
    transform: translateY(-2px);
    color: #fff;
}

.qa-btn-compact i {
    font-size: 1.2rem;
}

.qa-bg-purple { background: #6B46C1; }
.qa-bg-orange { background: #ED8936; }
.qa-bg-cyan { background: #00B5D8; }
.qa-bg-blue { background: #4299E1; }

/* Table */
.compact-table {
    width: 100%;
    border-collapse: collapse;
}

.compact-table th {
    background: #F8FAFC;
    color: #64748B;
    font-size: 0.65rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 10px 14px;
    text-align: left;
    border-bottom: 1px solid #E2E8F0;
}

.compact-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #F1F5F9;
    vertical-align: middle;
    font-size: 0.78rem;
}

.compact-table tr:hover td {
    background: #F8FAFC;
}

.compact-table tr:last-child td {
    border-bottom: none;
}

.inv-num {
    font-family: 'Courier New', monospace;
    font-weight: 700;
    color: #6B46C1;
}

.badge-soft {
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.65rem;
    font-weight: 700;
    display: inline-block;
}

.badge-soft-cash { background: #DEF7EC; color: #03543F; }
.badge-soft-mobile { background: #FEF08A; color: #713F12; }
.badge-soft-credit { background: #FDE8E8; color: #9B1C1C; }
.badge-soft-card { background: #E1EFFE; color: #1E429F; }

.btn-view-sm {
    width: 24px;
    height: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    background: rgba(107, 70, 193, 0.1);
    color: #6B46C1;
    text-decoration: none;
    transition: 0.2s;
}

.btn-view-sm:hover {
    background: #6B46C1;
    color: #fff;
}

.empty-compact {
    text-align: center;
    padding: 30px 15px;
    color: #A0AEC0;
}

.empty-compact i {
    font-size: 2rem;
    opacity: 0.3;
    margin-bottom: 8px;
    display: block;
}

.empty-compact p {
    font-size: 0.8rem;
    margin: 0;
}
</style>

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
                    <i class="fas fa-tachometer-alt me-2" style="color: var(--cyber-orange);"></i>
                    Dashboard
                </h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted d-none d-md-inline" style="font-size:0.8rem;">
                    <i class="fas fa-calendar-alt me-1"></i>
                    <?php echo date('D, M j, Y'); ?>
                </span>
                <div class="user-badge" style="padding: 4px 12px; font-size: 0.75rem;">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="content-area dash-wrap">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show py-2 px-3" style="font-size:0.85rem;">
                <i class="fas fa-<?php echo $alert['type'] == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" style="padding: 0.8rem;"></button>
            </div>
        <?php endif; ?>
        
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div class="welcome-text">
                <h2>Hello, <span><?php echo explode(' ', $_SESSION['full_name'])[0]; ?></span>! 👋</h2>
                <p>Here's your performance summary.</p>
            </div>
            <a href="pos.php" class="btn-start-sale">
                <i class="fas fa-cash-register"></i> New Sale
            </a>
        </div>
        
        <!-- Stats Cards -->
        <div class="row g-3 mb-3">
            <div class="col-lg-4 col-md-6">
                <div class="stat-card-compact">
                    <div class="stat-icon-compact icon-purple">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="stat-content-compact">
                        <div class="stat-label-compact">Today's Sales</div>
                        <div class="stat-value-compact"><?php echo number_format($today_sales['count']); ?></div>
                        <div class="stat-meta-compact">
                            <i class="fas fa-money-bill-wave text-muted"></i>
                            UGX <?php echo number_format($today_sales['total'], 0); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-6">
                <div class="stat-card-compact">
                    <div class="stat-icon-compact icon-orange">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                    <div class="stat-content-compact">
                        <div class="stat-label-compact">This Week</div>
                        <div class="stat-value-compact"><?php echo number_format($week_sales['count']); ?></div>
                        <div class="stat-meta-compact">
                            <i class="fas fa-chart-line text-muted"></i>
                            UGX <?php echo number_format($week_sales['total'], 0); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-6">
                <div class="stat-card-compact">
                    <div class="stat-icon-compact icon-cyan">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="stat-content-compact">
                        <div class="stat-label-compact">This Month</div>
                        <div class="stat-value-compact"><?php echo number_format($month_sales['count']); ?></div>
                        <div class="stat-meta-compact">
                            <i class="fas fa-money-bill-wave text-muted"></i>
                            UGX <?php echo number_format($month_sales['total'], 0); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Chart + Top Products -->
        <div class="row g-3 mb-3">
            <!-- Sales Chart -->
            <div class="col-lg-8">
                <div class="compact-card">
                    <div class="compact-card-header">
                        <span><i class="fas fa-chart-area"></i> My Sales (Last 7 Days)</span>
                    </div>
                    <div class="compact-card-body">
                        <?php if (!empty($chart_values)): ?>
                            <canvas id="mySalesChart" height="80"></canvas>
                        <?php else: ?>
                            <div class="empty-compact">
                                <i class="fas fa-chart-line"></i>
                                <p>No sales yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Top Products -->
            <div class="col-lg-4">
                <div class="compact-card">
                    <div class="compact-card-header">
                        <span><i class="fas fa-fire"></i> My Top Products</span>
                    </div>
                    <div class="compact-card-body p-3">
                        <?php if (count($top_products) > 0): ?>
                            <div class="top-prod-list">
                                <?php $rank = 1; foreach ($top_products as $product): ?>
                                    <div class="top-prod-item">
                                        <div class="top-prod-rank rank-<?php echo $rank; ?>"><?php echo $rank++; ?></div>
                                        <div class="top-prod-info">
                                            <h6><?php echo htmlspecialchars($product['product_name']); ?></h6>
                                            <small><?php echo $product['total_sold']; ?> units</small>
                                        </div>
                                        <div class="top-prod-rev">
                                            <?php echo number_format($product['revenue'], 0); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-compact">
                                <i class="fas fa-chart-bar"></i>
                                <p>No data yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row g-3 mb-3">
            <div class="col-12">
                <div class="compact-card">
                    <div class="compact-card-header">
                        <span><i class="fas fa-bolt"></i> Quick Actions</span>
                    </div>
                    <div class="compact-card-body py-2">
                        <div class="qa-grid-compact">
                            <a href="pos.php" class="qa-btn-compact qa-bg-purple">
                                <i class="fas fa-cash-register"></i><span>New Sale</span>
                            </a>
                            <a href="my-sales.php" class="qa-btn-compact qa-bg-orange">
                                <i class="fas fa-receipt"></i><span>My Sales</span>
                            </a>
                            <a href="profile.php" class="qa-btn-compact qa-bg-cyan">
                                <i class="fas fa-user-cog"></i><span>Profile</span>
                            </a>
                            <a href="../logout.php" class="qa-btn-compact qa-bg-blue" onclick="return confirm('Log out?');">
                                <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Sales -->
        <div class="row g-3">
            <div class="col-12">
                <div class="compact-card">
                    <div class="compact-card-header">
                        <span><i class="fas fa-receipt"></i> Recent Sales</span>
                        <a href="my-sales.php" class="btn btn-sm btn-outline-secondary" style="font-size:0.7rem; padding:2px 8px;">
                            View All
                        </a>
                    </div>
                    <div class="compact-card-body p-0">
                        <div class="table-responsive">
                            <table class="compact-table">
                                <thead>
                                    <tr>
                                        <th>Invoice</th>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Amount</th>
                                        <th>Payment</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($recent_sales) > 0): ?>
                                        <?php foreach ($recent_sales as $sale): ?>
                                            <tr>
                                                <td><span class="inv-num"><?php echo htmlspecialchars($sale['invoice_number']); ?></span></td>
                                                <td>
                                                    <div style="font-weight:600;"><?php echo date('M d', strtotime($sale['sale_date'])); ?></div>
                                                    <small class="text-muted" style="font-size:0.7rem;"><?php echo date('H:i A', strtotime($sale['sale_date'])); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?></td>
                                                <td><strong>UGX <?php echo number_format($sale['total_amount'], 0); ?></strong></td>
                                                <td>
                                                    <?php 
                                                    $pm = $sale['payment_method'];
                                                    $pmClass = 'badge-soft-cash';
                                                    if ($pm == 'mobile_money') $pmClass = 'badge-soft-mobile';
                                                    if ($pm == 'credit') $pmClass = 'badge-soft-credit';
                                                    if ($pm == 'card') $pmClass = 'badge-soft-card';
                                                    ?>
                                                    <span class="badge-soft <?php echo $pmClass; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $pm)); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <a href="view-sale.php?id=<?php echo $sale['sale_id']; ?>" class="btn-view-sm">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6">
                                                <div class="empty-compact">
                                                    <i class="fas fa-receipt"></i>
                                                    <p>No sales yet</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chartCanvas = document.getElementById('mySalesChart');
    if (!chartCanvas) return;

    const ctx = chartCanvas.getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 250);
    gradient.addColorStop(0, 'rgba(107, 70, 193, 0.2)');
    gradient.addColorStop(1, 'rgba(107, 70, 193, 0.0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: 'My Sales',
                data: <?php echo json_encode($chart_values); ?>,
                backgroundColor: gradient,
                borderColor: '#6B46C1',
                borderWidth: 2,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#ED8936',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 4,
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
                    bodyColor: '#ED8936',
                    padding: 8,
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            return 'UGX ' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0, 0, 0, 0.04)' },
                    ticks: {
                        font: { size: 10 },
                        callback: function(value) {
                            if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                            if (value >= 1000) return (value / 1000).toFixed(0) + 'K';
                            return value;
                        }
                    }
                }
            }
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>