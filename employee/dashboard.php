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
        LIMIT 10
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
        GROUP BY p.product_id
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
                <span class="text-muted d-none d-md-inline">
                    <i class="fas fa-calendar-alt me-2"></i>
                    <?php echo date('D, M j, Y'); ?>
                </span>
                <div class="user-badge">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <i class="fas fa-<?php echo $alert['type'] == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Welcome Banner -->
        <div class="welcome-banner mb-4">
            <div class="welcome-content">
                <div class="welcome-text">
                    <h2>Hello, <span><?php echo explode(' ', $_SESSION['full_name'])[0]; ?></span>! 👋</h2>
                    <p>Here's your performance summary for today.</p>
                </div>
                <div class="welcome-actions">
                    <a href="pos.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-cash-register me-2"></i>Start New Sale
                    </a>
                </div>
            </div>
            <div class="welcome-decoration">
                <div class="circle circle-1"></div>
                <div class="circle circle-2"></div>
                <div class="circle circle-3"></div>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-lg-4 col-md-6">
                <div class="stat-card stat-card-purple">
                    <div class="stat-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="stat-content">
                        <span class="stat-label">Today's Sales</span>
                        <h3 class="stat-value"><?php echo number_format($today_sales['count']); ?></h3>
                        <span class="stat-meta">
                            <i class="fas fa-money-bill-wave"></i>
                            UGX <?php echo format_currency($today_sales['total']); ?>
                        </span>
                    </div>
                    <div class="stat-glow"></div>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-6">
                <div class="stat-card stat-card-orange">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                    <div class="stat-content">
                        <span class="stat-label">This Week</span>
                        <h3 class="stat-value"><?php echo number_format($week_sales['count']); ?></h3>
                        <span class="stat-meta">
                            <i class="fas fa-chart-line"></i>
                            UGX <?php echo format_currency($week_sales['total']); ?>
                        </span>
                    </div>
                    <div class="stat-glow"></div>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-6">
                <div class="stat-card stat-card-cyan">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <span class="stat-label">This Month</span>
                        <h3 class="stat-value"><?php echo number_format($month_sales['count']); ?></h3>
                        <span class="stat-meta">
                            <i class="fas fa-money-bill-wave"></i>
                            UGX <?php echo format_currency($month_sales['total']); ?>
                        </span>
                    </div>
                    <div class="stat-glow"></div>
                </div>
            </div>
        </div>
        
        <!-- Chart + Top Products Row -->
        <div class="row g-4 mb-4">
            <!-- Sales Chart -->
            <div class="col-lg-8">
                <div class="card cyber-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>
                            <i class="fas fa-chart-area me-2"></i>
                            My Sales (Last 7 Days)
                        </span>
                        <span class="badge bg-warning text-dark">Weekly</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($chart_values)): ?>
                            <canvas id="mySalesChart" height="100"></canvas>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-chart-line"></i>
                                <h5>No sales yet</h5>
                                <p>Start selling to see your performance chart</p>
                                <a href="pos.php" class="btn btn-primary mt-3">
                                    <i class="fas fa-cash-register me-2"></i>Go to POS
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Top Products -->
            <div class="col-lg-4">
                <div class="card cyber-card h-100">
                    <div class="card-header">
                        <i class="fas fa-fire me-2"></i>
                        My Top Products
                    </div>
                    <div class="card-body p-0">
                        <?php if (count($top_products) > 0): ?>
                            <div class="top-products-list">
                                <?php $rank = 1; foreach ($top_products as $product): ?>
                                    <div class="top-product-item">
                                        <div class="rank rank-<?php echo $rank; ?>"><?php echo $rank++; ?></div>
                                        <div class="product-info">
                                            <h6><?php echo htmlspecialchars($product['product_name']); ?></h6>
                                            <small><?php echo $product['total_sold']; ?> units sold</small>
                                        </div>
                                        <div class="product-revenue">
                                            <?php echo format_currency($product['revenue']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-chart-bar"></i>
                                <p>No sales data yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card cyber-card">
                    <div class="card-header">
                        <i class="fas fa-bolt me-2"></i>
                        Quick Actions
                    </div>
                    <div class="card-body">
                        <div class="quick-actions-grid">
                            <a href="pos.php" class="quick-action-btn quick-action-purple">
                                <i class="fas fa-cash-register"></i>
                                <span>New Sale</span>
                            </a>
                            <a href="my-sales.php" class="quick-action-btn quick-action-orange">
                                <i class="fas fa-receipt"></i>
                                <span>My Sales</span>
                            </a>
                            <a href="profile.php" class="quick-action-btn quick-action-cyan">
                                <i class="fas fa-user-cog"></i>
                                <span>My Profile</span>
                            </a>
                            <a href="../logout.php" class="quick-action-btn quick-action-blue"
                               onclick="return confirm('Log out?');">
                                <i class="fas fa-sign-out-alt"></i>
                                <span>Logout</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Sales -->
        <div class="row g-4">
            <div class="col-12">
                <div class="card cyber-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>
                            <i class="fas fa-receipt me-2"></i>
                            My Recent Sales
                        </span>
                        <a href="my-sales.php" class="btn btn-sm btn-outline-light">
                            View All <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table cyber-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Date & Time</th>
                                        <th>Customer</th>
                                        <th>Store</th>
                                        <th>Amount</th>
                                        <th>Payment</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($recent_sales) > 0): ?>
                                        <?php foreach ($recent_sales as $sale): ?>
                                            <tr>
                                                <td>
                                                    <span class="invoice-number">
                                                        <?php echo htmlspecialchars($sale['invoice_number']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="date-cell">
                                                        <span class="date"><?php echo date('M d, Y', strtotime($sale['sale_date'])); ?></span>
                                                        <span class="time"><?php echo date('H:i A', strtotime($sale['sale_date'])); ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($sale['customer_name'])): ?>
                                                        <span class="customer-name">
                                                            <?php echo htmlspecialchars($sale['customer_name']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Walk-in</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="store-badge">
                                                        <?php echo htmlspecialchars($sale['store_name'] ?? 'N/A'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="amount">
                                                        UGX <?php echo format_currency($sale['total_amount']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="payment-badge payment-<?php echo $sale['payment_method']; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $sale['payment_method'])); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <a href="view-sale.php?id=<?php echo $sale['sale_id']; ?>" 
                                                       class="btn-action btn-view" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7">
                                                <div class="empty-state">
                                                    <i class="fas fa-receipt"></i>
                                                    <h5>No sales yet</h5>
                                                    <p>Your completed sales will appear here</p>
                                                    <a href="pos.php" class="btn btn-primary mt-3">
                                                        <i class="fas fa-cash-register me-2"></i>Make Your First Sale
                                                    </a>
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

<!-- Employee Dashboard Styles (uses shared classes + additions) -->
<style>
/* Welcome Banner */
.welcome-banner {
    background: linear-gradient(135deg, #1A202C 0%, #2D3748 100%);
    border-radius: 12px;
    padding: 32px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
}

.welcome-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    z-index: 2;
    flex-wrap: wrap;
    gap: 20px;
}

.welcome-text h2 {
    color: #fff;
    font-size: 1.75rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.welcome-text h2 span {
    background: linear-gradient(135deg, #ED8936, #FBD38D);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.welcome-text p {
    color: rgba(255, 255, 255, 0.7);
    margin: 0;
    font-size: 0.95rem;
}

.welcome-actions .btn {
    padding: 12px 24px;
    font-weight: 600;
    border-radius: 8px;
    background: linear-gradient(135deg, #6B46C1, #553C9A);
    color: #fff;
    border: none;
    box-shadow: 0 4px 15px rgba(107, 70, 193, 0.4);
    transition: all 0.3s ease;
}

.welcome-actions .btn:hover {
    background: linear-gradient(135deg, #553C9A, #6B46C1);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(107, 70, 193, 0.5);
    color: #fff;
}

.welcome-decoration {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.welcome-decoration .circle {
    position: absolute;
    border-radius: 50%;
    opacity: 0.1;
}

.welcome-decoration .circle-1 {
    width: 300px;
    height: 300px;
    background: #6B46C1;
    top: -150px;
    right: -50px;
}

.welcome-decoration .circle-2 {
    width: 200px;
    height: 200px;
    background: #ED8936;
    bottom: -100px;
    right: 150px;
}

.welcome-decoration .circle-3 {
    width: 150px;
    height: 150px;
    background: #00B5D8;
    top: 50%;
    right: 300px;
    transform: translateY(-50%);
}

/* Stat Cards */
.stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 24px;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: flex-start;
    gap: 20px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    border: 1px solid #E2E8F0;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
}

.stat-card-purple { border-left: 4px solid #6B46C1; }
.stat-card-orange { border-left: 4px solid #ED8936; }
.stat-card-cyan { border-left: 4px solid #00B5D8; }

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.stat-card-purple .stat-icon {
    background: linear-gradient(135deg, rgba(107, 70, 193, 0.15), rgba(107, 70, 193, 0.05));
    color: #6B46C1;
}

.stat-card-orange .stat-icon {
    background: linear-gradient(135deg, rgba(237, 137, 54, 0.15), rgba(237, 137, 54, 0.05));
    color: #ED8936;
}

.stat-card-cyan .stat-icon {
    background: linear-gradient(135deg, rgba(0, 181, 216, 0.15), rgba(0, 181, 216, 0.05));
    color: #00B5D8;
}

.stat-content { flex: 1; min-width: 0; }

.stat-label {
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #718096;
    display: block;
    margin-bottom: 4px;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1A202C;
    margin-bottom: 6px;
    line-height: 1.2;
}

.stat-meta {
    font-size: 0.8rem;
    color: #718096;
    display: flex;
    align-items: center;
    gap: 6px;
}

.stat-glow {
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    border-radius: 50%;
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
}

.stat-card-purple .stat-glow { background: radial-gradient(circle, rgba(107, 70, 193, 0.2), transparent 70%); }
.stat-card-orange .stat-glow { background: radial-gradient(circle, rgba(237, 137, 54, 0.2), transparent 70%); }
.stat-card-cyan .stat-glow { background: radial-gradient(circle, rgba(0, 181, 216, 0.2), transparent 70%); }

.stat-card:hover .stat-glow { opacity: 1; }

/* Cyber Card */
.cyber-card {
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
}

.cyber-card:hover { box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15); }

.cyber-card .card-header {
    background: linear-gradient(135deg, #1A202C 0%, #2D3748 100%);
    color: #fff;
    padding: 16px 20px;
    border: none;
    font-weight: 600;
    display: flex;
    align-items: center;
}

.cyber-card .card-header i { color: #ED8936; }

/* Top Products List */
.top-products-list { padding: 8px 0; }

.top-product-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 20px;
    border-bottom: 1px solid #E2E8F0;
    transition: background 0.15s ease;
}

.top-product-item:last-child { border-bottom: none; }
.top-product-item:hover { background: rgba(107, 70, 193, 0.03); }

.rank {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.85rem;
    flex-shrink: 0;
}

.rank-1 { background: linear-gradient(135deg, #FFD700, #FFA500); color: #7B341E; }
.rank-2 { background: linear-gradient(135deg, #C0C0C0, #A0A0A0); color: #2D3748; }
.rank-3 { background: linear-gradient(135deg, #CD7F32, #A0522D); color: #fff; }
.rank-4, .rank-5 { background: #EDF2F7; color: #4A5568; }

.top-product-item .product-info { flex: 1; min-width: 0; }

.top-product-item .product-info h6 {
    font-size: 0.9rem;
    font-weight: 600;
    color: #1A202C;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.top-product-item .product-info small {
    font-size: 0.75rem;
    color: #718096;
}

.top-product-item .product-revenue {
    font-weight: 700;
    font-size: 0.85rem;
    color: #6B46C1;
    white-space: nowrap;
}

/* Quick Actions Grid */
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}

@media (max-width: 768px) {
    .quick-actions-grid { grid-template-columns: repeat(2, 1fr); }
}

.quick-action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 24px 16px;
    border-radius: 12px;
    text-decoration: none;
    color: #fff;
    font-weight: 600;
    font-size: 0.85rem;
    text-align: center;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.quick-action-btn i { font-size: 1.75rem; transition: transform 0.3s ease; }

.quick-action-btn:hover {
    transform: translateY(-5px);
    color: #fff;
}

.quick-action-btn:hover i { transform: scale(1.15); }

.quick-action-purple {
    background: linear-gradient(135deg, #6B46C1, #553C9A);
    box-shadow: 0 4px 15px rgba(107, 70, 193, 0.3);
}
.quick-action-purple:hover { box-shadow: 0 8px 25px rgba(107, 70, 193, 0.4); }

.quick-action-orange {
    background: linear-gradient(135deg, #ED8936, #DD6B20);
    box-shadow: 0 4px 15px rgba(237, 137, 54, 0.3);
}
.quick-action-orange:hover { box-shadow: 0 8px 25px rgba(237, 137, 54, 0.4); }

.quick-action-cyan {
    background: linear-gradient(135deg, #00B5D8, #0987A0);
    box-shadow: 0 4px 15px rgba(0, 181, 216, 0.3);
}
.quick-action-cyan:hover { box-shadow: 0 8px 25px rgba(0, 181, 216, 0.4); }

.quick-action-blue {
    background: linear-gradient(135deg, #4299E1, #3182CE);
    box-shadow: 0 4px 15px rgba(66, 153, 225, 0.3);
}
.quick-action-blue:hover { box-shadow: 0 8px 25px rgba(66, 153, 225, 0.4); }

/* Cyber Table */
.cyber-table { width: 100%; border-collapse: collapse; }

.cyber-table thead th {
    background: #F7FAFC;
    color: #4A5568;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 14px 16px;
    border-bottom: 2px solid #E2E8F0;
    white-space: nowrap;
    text-align: left;
}

.cyber-table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid #E2E8F0;
    vertical-align: middle;
    font-size: 0.875rem;
}

.cyber-table tbody tr:hover { background: rgba(107, 70, 193, 0.03); }
.cyber-table tbody tr:last-child td { border-bottom: none; }

.invoice-number {
    font-family: 'Courier New', monospace;
    font-weight: 700;
    color: #6B46C1;
    font-size: 0.85rem;
}

.date-cell { display: flex; flex-direction: column; }
.date-cell .date { font-weight: 600; color: #1A202C; font-size: 0.85rem; }
.date-cell .time { font-size: 0.75rem; color: #718096; }

.customer-name { font-weight: 600; color: #1A202C; }

.store-badge {
    display: inline-block;
    padding: 4px 10px;
    background: #EDF2F7;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #4A5568;
}

.amount { font-weight: 700; color: #1A202C; }

.payment-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: capitalize;
}

.payment-cash { background: rgba(72, 187, 120, 0.15); color: #2F855A; }
.payment-card { background: rgba(66, 153, 225, 0.15); color: #2B6CB0; }
.payment-mobile_money { background: rgba(237, 137, 54, 0.15); color: #C05621; }
.payment-bank_transfer { background: rgba(107, 70, 193, 0.15); color: #553C9A; }
.payment-credit { background: rgba(229, 62, 62, 0.15); color: #C53030; }

.btn-action {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
}

.btn-view { background: rgba(107, 70, 193, 0.1); color: #6B46C1; }
.btn-view:hover { background: #6B46C1; color: #fff; transform: scale(1.05); }

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #718096;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 16px;
    opacity: 0.3;
    color: #6B46C1;
}

.empty-state h5 {
    color: #4A5568;
    font-weight: 700;
    margin-bottom: 8px;
}

.empty-state p {
    margin: 0;
    font-size: 0.9rem;
}

/* User badge */
.user-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #6B46C1, #553C9A);
    color: #fff;
    border-radius: 30px;
    font-size: 0.85rem;
    font-weight: 600;
    box-shadow: 0 4px 15px rgba(107, 70, 193, 0.3);
}

/* Responsive */
@media (max-width: 991.98px) {
    .welcome-content { flex-direction: column; align-items: flex-start; }
    .welcome-text h2 { font-size: 1.5rem; }
    .welcome-actions { width: 100%; }
    .welcome-actions .btn { width: 100%; }
}

@media (max-width: 767.98px) {
    .stat-card { padding: 20px; }
    .stat-icon { width: 50px; height: 50px; font-size: 1.25rem; }
    .stat-value { font-size: 1.25rem; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ============================================
    // MY SALES CHART
    // ============================================
    const chartCanvas = document.getElementById('mySalesChart');
    
    if (chartCanvas) {
        const ctx = chartCanvas.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(107, 70, 193, 0.3)');
        gradient.addColorStop(1, 'rgba(107, 70, 193, 0.01)');
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'My Sales',
                    data: <?php echo json_encode($chart_values); ?>,
                    backgroundColor: gradient,
                    borderColor: '#6B46C1',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#ED8936',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 3,
                    pointRadius: 6,
                    pointHoverRadius: 9
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1A202C',
                        titleColor: '#fff',
                        bodyColor: '#ED8936',
                        borderColor: '#6B46C1',
                        borderWidth: 2,
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return '💰 UGX ' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#718096', font: { size: 11, weight: '600' } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0, 0, 0, 0.05)', drawBorder: false },
                        ticks: {
                            color: '#718096',
                            font: { size: 11 },
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
    }
    
    // ============================================
    // ANIMATE STAT CARDS
    // ============================================
    const statCards = document.querySelectorAll('.stat-card');
    statCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
    
    // ============================================
    // ANIMATE QUICK ACTIONS
    // ============================================
    const quickActions = document.querySelectorAll('.quick-action-btn');
    quickActions.forEach((btn, index) => {
        btn.style.opacity = '0';
        btn.style.transform = 'translateY(20px)';
        setTimeout(() => {
            btn.style.transition = 'all 0.4s ease';
            btn.style.opacity = '1';
            btn.style.transform = 'translateY(0)';
        }, 500 + index * 50);
    });
});
</script>

<?php include '../includes/footer.php'; ?>