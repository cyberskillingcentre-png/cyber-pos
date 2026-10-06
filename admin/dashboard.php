<?php
require_once '../config/config.php';
require_admin();

$page_title = "Dashboard";

// Get dashboard statistics
try {
    // Today's sales
    $stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed'");
    $stmt->execute();
    $today_sales = $stmt->fetch();
    
    // This month's sales
    $stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM sales WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE()) AND status = 'completed'");
    $stmt->execute();
    $month_sales = $stmt->fetch();
    
    // Total products
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE status = 'active'");
    $stmt->execute();
    $total_products = $stmt->fetch();
    
    // Low stock products
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT p.product_id) as count FROM products p LEFT JOIN store_inventory si ON p.product_id = si.product_id WHERE p.status = 'active' AND (si.quantity <= p.reorder_level OR si.quantity IS NULL)");
    $stmt->execute();
    $low_stock = $stmt->fetch();
    
    // Total customers
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM customers WHERE status = 'active'");
    $stmt->execute();
    $total_customers = $stmt->fetch();
    
    // Pending returns
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM returns WHERE status = 'pending'");
    $stmt->execute();
    $pending_returns = $stmt->fetch();
    
    // Expiring products (within 30 days)
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT si.inventory_id) as count,
               COALESCE(SUM(si.quantity * p.purchase_price), 0) as total_value
        FROM store_inventory si
        JOIN products p ON si.product_id = p.product_id
        WHERE p.has_expiry = 'yes' 
        AND si.expiry_date IS NOT NULL
        AND si.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        AND si.quantity > 0
    ");
    $stmt->execute();
    $expiring_products = $stmt->fetch();
    
    // Recent sales for chart (last 7 days)
    $stmt = $conn->prepare("SELECT DATE(sale_date) as date, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM sales WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status = 'completed' GROUP BY DATE(sale_date) ORDER BY date ASC");
    $stmt->execute();
    $sales_chart_data = $stmt->fetchAll();
    
    // Top selling products
    $stmt = $conn->prepare("SELECT p.product_name, SUM(si.quantity) as total_sold, SUM(si.total_price) as revenue FROM sale_items si JOIN products p ON si.product_id = p.product_id JOIN sales s ON si.sale_id = s.sale_id WHERE s.status = 'completed' AND MONTH(s.sale_date) = MONTH(CURDATE()) GROUP BY p.product_id ORDER BY total_sold DESC LIMIT 5");
    $stmt->execute();
    $top_products = $stmt->fetchAll();
    
    // Recent sales
    $stmt = $conn->prepare("SELECT s.*, st.store_name, c.customer_name, u.full_name as served_by_name FROM sales s LEFT JOIN stores st ON s.store_id = st.store_id LEFT JOIN customers c ON s.customer_id = c.customer_id LEFT JOIN users u ON s.served_by = u.user_id ORDER BY s.sale_date DESC LIMIT 10");
    $stmt->execute();
    $recent_sales = $stmt->fetchAll();
    
} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Prepare chart data
$chart_labels = [];
$chart_values = [];
foreach ($sales_chart_data as $data) {
    $chart_labels[] = date('M d', strtotime($data['date']));
    $chart_values[] = $data['total'];
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
       
    <div class="content-area">
              
        <!-- Welcome Banner -->
        <div class="welcome-banner mb-4">
            <div class="welcome-content">
                <div class="welcome-text">
                    <h2>Welcome back, <span><?php echo explode(' ', $_SESSION['full_name'])[0]; ?></span>! 👋</h2>
                    <p>Here's what's happening with your business today.</p>
                </div>
                <div class="welcome-actions">
                    <a href="Point_Of_Sale" class="btn btn-primary btn-lg">
                        <i class="fas fa-cash-register me-2"></i>New Sale
                    </a>
                    <a href="Sales_Report" class="btn btn-outline-light btn-lg">
                        <i class="fas fa-chart-line me-2"></i>View Reports
                    </a>
                </div>
            </div>
            <div class="welcome-decoration">
                <div class="circle circle-1"></div>
                <div class="circle circle-2"></div>
                <div class="circle circle-3"></div>
            </div>
        </div>
        
        <!-- Expiring Products Alert -->
        <?php if ($expiring_products['count'] > 0): ?>
        <div class="alert-cyber alert-cyber-warning mb-4">
            <div class="alert-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="alert-content">
                <h5>Products Expiring Soon!</h5>
                <p>
                    <strong><?php echo $expiring_products['count']; ?> product batches</strong> will expire within the next 30 days.
                    <span class="text-danger">Value at risk: <strong>UGX <?php echo number_format($expiring_products['total_value'], 0); ?></strong></span>
                </p>
                <a href="Expiring_Stock" class="btn btn-warning btn-sm">
                    <i class="fas fa-eye me-1"></i>View & Manage
                </a>
            </div>
            <button type="button" class="alert-close" data-bs-dismiss="alert">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
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
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-orange">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <span class="stat-label">This Month's Revenue</span>
                        <h3 class="stat-value">UGX <?php echo format_currency($month_sales['total']); ?></h3>
                        <span class="stat-meta">
                            <i class="fas fa-receipt"></i>
                            <?php echo number_format($month_sales['count']); ?> transactions
                        </span>
                    </div>
                    <div class="stat-glow"></div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-cyan">
                    <div class="stat-icon">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="stat-content">
                        <span class="stat-label">Total Products</span>
                        <h3 class="stat-value"><?php echo number_format($total_products['count']); ?></h3>
                        <span class="stat-meta <?php echo $low_stock['count'] > 0 ? 'text-danger' : ''; ?>">
                            <i class="fas fa-exclamation-triangle"></i>
                            <?php echo $low_stock['count']; ?> low stock
                        </span>
                    </div>
                    <div class="stat-glow"></div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-green">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <span class="stat-label">Total Customers</span>
                        <h3 class="stat-value"><?php echo number_format($total_customers['count']); ?></h3>
                        <span class="stat-meta">
                            <i class="fas fa-user-check"></i>
                            Active customers
                        </span>
                    </div>
                    <div class="stat-glow"></div>
                </div>
            </div>
        </div>
        
        <!-- Charts & Top Products Row -->
        <div class="row g-4 mb-4">
            <!-- Sales Chart -->
            <div class="col-lg-8">
                <div class="card cyber-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>
                            <i class="fas fa-chart-area me-2"></i>
                            Sales Overview
                        </span>
                        <span class="badge bg-light text-dark">Last 7 Days</span>
                    </div>
                    <div class="card-body">
                        <canvas id="salesChart" height="100"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Top Products -->
            <div class="col-lg-4">
                <div class="card cyber-card h-100">
                    <div class="card-header">
                        <i class="fas fa-fire me-2"></i>
                        Top Selling Products
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
                                            UGX <?php echo format_currency($product['revenue']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-chart-bar"></i>
                                <p>No sales data available</p>
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
                            <a href="Point_Of_Sale" class="quick-action-btn quick-action-purple">
                                <i class="fas fa-cash-register"></i>
                                <span>New Sale</span>
                            </a>
                            <a href="Products" class="quick-action-btn quick-action-orange">
                                <i class="fas fa-plus-circle"></i>
                                <span>Add Product</span>
                            </a>
                            <a href="Customers" class="quick-action-btn quick-action-cyan">
                                <i class="fas fa-user-plus"></i>
                                <span>Add Customer</span>
                            </a>
                            <a href="Sales_Report" class="quick-action-btn quick-action-green">
                                <i class="fas fa-chart-bar"></i>
                                <span>View Reports</span>
                            </a>
                            <a href="Client_Orders" class="quick-action-btn quick-action-pink">
                                <i class="fas fa-shopping-bag"></i>
                                <span>Client Orders</span>
                            </a>
                            <a href="Stock_Purchases" class="quick-action-btn quick-action-blue">
                                <i class="fas fa-truck"></i>
                                <span>New Purchase</span>
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
                            Recent Sales
                        </span>
                        <a href="Sales_Records" class="btn btn-sm btn-outline-light">
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
                                        <th>Served By</th>
                                        <th>Amount</th>
                                        <th>Payment</th>
                                        <th>Status</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($recent_sales) > 0): ?>
                                        <?php foreach ($recent_sales as $sale): ?>
                                            <tr>
                                                <td>
                                                    <span class="invoice-number"><?php echo htmlspecialchars($sale['invoice_number']); ?></span>
                                                </td>
                                                <td>
                                                    <div class="date-cell">
                                                        <span class="date"><?php echo date('M d, Y', strtotime($sale['sale_date'])); ?></span>
                                                        <span class="time"><?php echo date('H:i A', strtotime($sale['sale_date'])); ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($sale['customer_name']): ?>
                                                        <span class="customer-name"><?php echo htmlspecialchars($sale['customer_name']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Walk-in</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="store-badge"><?php echo htmlspecialchars($sale['store_name']); ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars($sale['served_by_name']); ?></td>
                                                <td>
                                                    <span class="amount">UGX <?php echo format_currency($sale['total_amount']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="payment-badge payment-<?php echo $sale['payment_method']; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $sale['payment_method'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $sale['status']; ?>">
                                                        <?php echo ucfirst($sale['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <a href="view-sale.php?id=<?php echo $sale['sale_id']; ?>" class="btn-action btn-view" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9">
                                                <div class="empty-state">
                                                    <i class="fas fa-receipt"></i>
                                                    <p>No recent sales</p>
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

<!-- Dashboard Specific Styles -->
<style>
/* ============================================
   WELCOME BANNER
   ============================================ */
.welcome-banner {
    background: linear-gradient(135deg, var(--cyber-dark) 0%, var(--cyber-gray) 100%);
    border-radius: var(--radius-lg);
    padding: 32px;
    position: relative;
    overflow: hidden;
    box-shadow: var(--shadow-lg);
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
    color: var(--cyber-white);
    font-size: 1.75rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.welcome-text h2 span {
    background: linear-gradient(135deg, var(--cyber-orange), var(--cyber-orange-light));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.welcome-text p {
    color: rgba(255, 255, 255, 0.7);
    margin: 0;
    font-size: 0.95rem;
}

.welcome-actions {
    display: flex;
    gap: 12px;
}

.welcome-actions .btn {
    padding: 12px 24px;
    font-weight: 600;
    border-radius: var(--radius-md);
}

.welcome-actions .btn-primary {
    background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
    border: none;
    box-shadow: 0 4px 15px rgba(107, 70, 193, 0.4);
}

.welcome-actions .btn-primary:hover {
    background: linear-gradient(135deg, var(--cyber-purple-dark), var(--cyber-purple));
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(107, 70, 193, 0.5);
}

.welcome-actions .btn-outline-light {
    border: 2px solid rgba(255, 255, 255, 0.3);
    color: var(--cyber-white);
}

.welcome-actions .btn-outline-light:hover {
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.5);
}

.welcome-decoration {
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    left: 0;
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
    background: var(--cyber-purple);
    top: -150px;
    right: -50px;
}

.welcome-decoration .circle-2 {
    width: 200px;
    height: 200px;
    background: var(--cyber-orange);
    bottom: -100px;
    right: 150px;
}

.welcome-decoration .circle-3 {
    width: 150px;
    height: 150px;
    background: var(--cyber-cyan);
    top: 50%;
    right: 300px;
    transform: translateY(-50%);
}

/* ============================================
   USER BADGE
   ============================================ */
.user-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
    color: var(--cyber-white);
    border-radius: 30px;
    font-size: 0.85rem;
    font-weight: 600;
    box-shadow: 0 4px 15px rgba(107, 70, 193, 0.3);
}

.user-badge i {
    font-size: 1.1rem;
}

/* ============================================
   STAT CARDS
   ============================================ */
.stat-card {
    background: var(--cyber-white);
    border-radius: var(--radius-lg);
    padding: 24px;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: flex-start;
    gap: 20px;
    box-shadow: var(--shadow-md);
    transition: all var(--transition-normal);
    border: 1px solid var(--cyber-border);
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-lg);
}

.stat-card-purple { border-left: 4px solid var(--cyber-purple); }
.stat-card-orange { border-left: 4px solid var(--cyber-orange); }
.stat-card-cyan { border-left: 4px solid var(--cyber-cyan); }
.stat-card-green { border-left: 4px solid var(--cyber-green); }

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.stat-card-purple .stat-icon {
    background: linear-gradient(135deg, rgba(107, 70, 193, 0.15), rgba(107, 70, 193, 0.05));
    color: var(--cyber-purple);
}

.stat-card-orange .stat-icon {
    background: linear-gradient(135deg, rgba(237, 137, 54, 0.15), rgba(237, 137, 54, 0.05));
    color: var(--cyber-orange);
}

.stat-card-cyan .stat-icon {
    background: linear-gradient(135deg, rgba(0, 181, 216, 0.15), rgba(0, 181, 216, 0.05));
    color: var(--cyber-cyan);
}

.stat-card-green .stat-icon {
    background: linear-gradient(135deg, rgba(72, 187, 120, 0.15), rgba(72, 187, 120, 0.05));
    color: var(--cyber-green);
}

.stat-content {
    flex: 1;
    min-width: 0;
}

.stat-label {
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--cyber-gray-lighter);
    display: block;
    margin-bottom: 4px;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--cyber-dark);
    margin-bottom: 6px;
    line-height: 1.2;
}

.stat-meta {
    font-size: 0.8rem;
    color: var(--cyber-gray-lighter);
    display: flex;
    align-items: center;
    gap: 6px;
}

.stat-meta i {
    font-size: 0.75rem;
}

.stat-glow {
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    border-radius: 50%;
    opacity: 0;
    transition: opacity var(--transition-normal);
    pointer-events: none;
}

.stat-card-purple .stat-glow { background: radial-gradient(circle, rgba(107, 70, 193, 0.2), transparent 70%); }
.stat-card-orange .stat-glow { background: radial-gradient(circle, rgba(237, 137, 54, 0.2), transparent 70%); }
.stat-card-cyan .stat-glow { background: radial-gradient(circle, rgba(0, 181, 216, 0.2), transparent 70%); }
.stat-card-green .stat-glow { background: radial-gradient(circle, rgba(72, 187, 120, 0.2), transparent 70%); }

.stat-card:hover .stat-glow {
    opacity: 1;
}

/* ============================================
   CYBER CARD
   ============================================ */
.cyber-card {
    background: var(--cyber-white);
    border: 1px solid var(--cyber-border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-md);
    transition: all var(--transition-normal);
}

.cyber-card:hover {
    box-shadow: var(--shadow-lg);
}

.cyber-card .card-header {
    background: linear-gradient(135deg, var(--cyber-dark) 0%, var(--cyber-gray) 100%);
    color: var(--cyber-white);
    padding: 16px 20px;
    border: none;
    font-weight: 600;
    display: flex;
    align-items: center;
}

.cyber-card .card-header i {
    color: var(--cyber-orange);
}

/* ============================================
   ALERT CYBER
   ============================================ */
.alert-cyber {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 20px;
    border-radius: var(--radius-lg);
    position: relative;
    overflow: hidden;
}

.alert-cyber-warning {
    background: linear-gradient(135deg, rgba(237, 137, 54, 0.1), rgba(237, 137, 54, 0.05));
    border-left: 4px solid var(--cyber-orange);
}

.alert-cyber .alert-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, var(--cyber-orange), var(--cyber-orange-dark));
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.25rem;
    flex-shrink: 0;
    box-shadow: 0 4px 15px rgba(237, 137, 54, 0.3);
}

.alert-cyber .alert-content {
    flex: 1;
}

.alert-cyber .alert-content h5 {
    color: var(--cyber-orange-dark);
    font-weight: 700;
    margin-bottom: 8px;
    font-size: 1.1rem;
}

.alert-cyber .alert-content p {
    margin-bottom: 12px;
    color: var(--cyber-gray);
    font-size: 0.9rem;
}

.alert-cyber .alert-close {
    background: none;
    border: none;
    color: var(--cyber-gray-lighter);
    cursor: pointer;
    padding: 4px;
    font-size: 1rem;
    transition: color var(--transition-fast);
}

.alert-cyber .alert-close:hover {
    color: var(--cyber-orange);
}

/* ============================================
   TOP PRODUCTS LIST
   ============================================ */
.top-products-list {
    padding: 8px 0;
}

.top-product-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 20px;
    border-bottom: 1px solid var(--cyber-border);
    transition: background var(--transition-fast);
}

.top-product-item:last-child {
    border-bottom: none;
}

.top-product-item:hover {
    background: rgba(107, 70, 193, 0.03);
}

.top-product-item .rank {
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
.rank-3 { background: linear-gradient(135deg, #CD7F32, #A0522D); color: white; }
.rank-4, .rank-5 { background: var(--cyber-light-gray); color: var(--cyber-gray); }

.top-product-item .product-info {
    flex: 1;
    min-width: 0;
}

.top-product-item .product-info h6 {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--cyber-dark);
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.top-product-item .product-info small {
    font-size: 0.75rem;
    color: var(--cyber-gray-lighter);
}

.top-product-item .product-revenue {
    font-weight: 700;
    font-size: 0.85rem;
    color: var(--cyber-purple);
    white-space: nowrap;
}

/* ============================================
   QUICK ACTIONS GRID
   ============================================ */
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 16px;
}

@media (max-width: 1200px) {
    .quick-actions-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .quick-actions-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

.quick-action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 24px 16px;
    border-radius: var(--radius-lg);
    text-decoration: none;
    color: var(--cyber-white);
    font-weight: 600;
    font-size: 0.85rem;
    text-align: center;
    transition: all var(--transition-normal);
    position: relative;
    overflow: hidden;
}

.quick-action-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, transparent 50%);
    opacity: 0;
    transition: opacity var(--transition-normal);
}

.quick-action-btn:hover::before {
    opacity: 1;
}

.quick-action-btn i {
    font-size: 1.75rem;
    transition: transform var(--transition-normal);
}

.quick-action-btn:hover {
    transform: translateY(-5px);
    color: var(--cyber-white);
}

.quick-action-btn:hover i {
    transform: scale(1.15);
}

.quick-action-purple {
    background: linear-gradient(135deg, var(--cyber-purple), var(--cyber-purple-dark));
    box-shadow: 0 4px 15px rgba(107, 70, 193, 0.3);
}

.quick-action-purple:hover {
    box-shadow: 0 8px 25px rgba(107, 70, 193, 0.4);
}

.quick-action-orange {
    background: linear-gradient(135deg, var(--cyber-orange), var(--cyber-orange-dark));
    box-shadow: 0 4px 15px rgba(237, 137, 54, 0.3);
}

.quick-action-orange:hover {
    box-shadow: 0 8px 25px rgba(237, 137, 54, 0.4);
}

.quick-action-cyan {
    background: linear-gradient(135deg, var(--cyber-cyan), var(--cyber-cyan-dark));
    box-shadow: 0 4px 15px rgba(0, 181, 216, 0.3);
}

.quick-action-cyan:hover {
    box-shadow: 0 8px 25px rgba(0, 181, 216, 0.4);
}

.quick-action-green {
    background: linear-gradient(135deg, var(--cyber-green), var(--cyber-green-dark));
    box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
}

.quick-action-green:hover {
    box-shadow: 0 8px 25px rgba(72, 187, 120, 0.4);
}

.quick-action-pink {
    background: linear-gradient(135deg, var(--cyber-pink), #D53F8C);
    box-shadow: 0 4px 15px rgba(237, 100, 166, 0.3);
}

.quick-action-pink:hover {
    box-shadow: 0 8px 25px rgba(237, 100, 166, 0.4);
}

.quick-action-blue {
    background: linear-gradient(135deg, #4299E1, #3182CE);
    box-shadow: 0 4px 15px rgba(66, 153, 225, 0.3);
}

.quick-action-blue:hover {
    box-shadow: 0 8px 25px rgba(66, 153, 225, 0.4);
}

/* ============================================
   CYBER TABLE
   ============================================ */
.cyber-table {
    width: 100%;
    border-collapse: collapse;
}

.cyber-table thead th {
    background: var(--cyber-light);
    color: var(--cyber-gray);
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 14px 16px;
    border-bottom: 2px solid var(--cyber-border);
    white-space: nowrap;
}

.cyber-table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--cyber-border);
    vertical-align: middle;
    font-size: 0.875rem;
}

.cyber-table tbody tr {
    transition: background var(--transition-fast);
}

.cyber-table tbody tr:hover {
    background: rgba(107, 70, 193, 0.03);
}

.cyber-table tbody tr:last-child td {
    border-bottom: none;
}

/* Table Elements */
.invoice-number {
    font-family: 'Courier New', monospace;
    font-weight: 700;
    color: var(--cyber-purple);
    font-size: 0.85rem;
}

.date-cell {
    display: flex;
    flex-direction: column;
}

.date-cell .date {
    font-weight: 600;
    color: var(--cyber-dark);
    font-size: 0.85rem;
}

.date-cell .time {
    font-size: 0.75rem;
    color: var(--cyber-gray-lighter);
}

.customer-name {
    font-weight: 600;
    color: var(--cyber-dark);
}

.store-badge {
    display: inline-block;
    padding: 4px 10px;
    background: var(--cyber-light-gray);
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--cyber-gray);
}

.amount {
    font-weight: 700;
    color: var(--cyber-dark);
}

.payment-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: capitalize;
}

.payment-cash { background: rgba(72, 187, 120, 0.15); color: var(--cyber-green-dark); }
.payment-card { background: rgba(66, 153, 225, 0.15); color: #2B6CB0; }
.payment-mobile_money { background: rgba(237, 137, 54, 0.15); color: var(--cyber-orange-dark); }
.payment-bank_transfer { background: rgba(107, 70, 193, 0.15); color: var(--cyber-purple-dark); }
.payment-credit { background: rgba(229, 62, 62, 0.15); color: var(--cyber-red-dark); }

.status-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: capitalize;
}

.status-completed { background: rgba(72, 187, 120, 0.15); color: var(--cyber-green-dark); }
.status-pending { background: rgba(237, 137, 54, 0.15); color: var(--cyber-orange-dark); }
.status-cancelled { background: rgba(229, 62, 62, 0.15); color: var(--cyber-red-dark); }

.btn-action {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-md);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    cursor: pointer;
    transition: all var(--transition-fast);
    text-decoration: none;
}

.btn-view {
    background: rgba(107, 70, 193, 0.1);
    color: var(--cyber-purple);
}

.btn-view:hover {
    background: var(--cyber-purple);
    color: white;
    transform: scale(1.05);
}

/* ============================================
   EMPTY STATE
   ============================================ */
.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--cyber-gray-lighter);
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 16px;
    opacity: 0.3;
}

.empty-state p {
    margin: 0;
    font-size: 0.9rem;
}

/* ============================================
   RESPONSIVE
   ============================================ */
@media (max-width: 991.98px) {
    .welcome-banner {
        padding: 24px;
    }
    
    .welcome-content {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .welcome-text h2 {
        font-size: 1.5rem;
    }
    
    .welcome-actions {
        width: 100%;
    }
    
    .welcome-actions .btn {
        flex: 1;
    }
}

@media (max-width: 767.98px) {
    .stat-card {
        padding: 20px;
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        font-size: 1.25rem;
    }
    
    .stat-value {
        font-size: 1.25rem;
    }
    
    .welcome-actions {
        flex-direction: column;
    }
    
    .welcome-actions .btn {
        width: 100%;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ============================================
    // SALES CHART - CYBER STYLE
    // ============================================
    const ctx = document.getElementById('salesChart');
    if (ctx) {
        const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(107, 70, 193, 0.3)');
        gradient.addColorStop(1, 'rgba(107, 70, 193, 0.01)');
        
        new Chart(ctx.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Sales Revenue',
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
                    pointHoverRadius: 9,
                    pointHoverBackgroundColor: '#6B46C1',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: {
                        display: false
                    },
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
                            title: function(context) {
                                return '📅 ' + context[0].label;
                            },
                            label: function(context) {
                                return '💰 UGX ' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#718096',
                            font: {
                                size: 11,
                                weight: '600'
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)',
                            drawBorder: false
                        },
                        ticks: {
                            color: '#718096',
                            font: {
                                size: 11
                            },
                            callback: function(value) {
                                if (value >= 1000000) {
                                    return (value / 1000000).toFixed(1) + 'M';
                                } else if (value >= 1000) {
                                    return (value / 1000).toFixed(0) + 'K';
                                }
                                return value;
                            }
                        }
                    }
                }
            }
        });
    }
    
    // ============================================
    // ANIMATE STAT CARDS ON LOAD
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
    // ANIMATE QUICK ACTION BUTTONS
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