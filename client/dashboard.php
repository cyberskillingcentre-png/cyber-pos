<?php
require_once '../config/config.php';
require_once 'config/client_auth.php';
require_client();

$page_title = "Client Dashboard";

// ============================================
// GET CLIENT PROFILE
// ============================================
try {
    $client = get_client_profile($conn, $_SESSION['user_id']);
} catch(PDOException $e) {
    $client = ['full_name' => $_SESSION['full_name']];
}

// ============================================
// GET STATISTICS
// ============================================
$stats = [
    'total_orders' => 0,
    'pending_orders' => 0,
    'total_spent' => 0,
    'outstanding_balance' => 0,
    'delivered_orders' => 0,
    'cancelled_orders' => 0,
];

try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM client_orders WHERE client_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $stats['total_orders'] = $stmt->fetchColumn();
} catch(PDOException $e) {}

try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM client_orders WHERE client_id = ? AND order_status IN ('pending', 'confirmed', 'processing', 'ready', 'shipped')");
    $stmt->execute([$_SESSION['user_id']]);
    $stats['pending_orders'] = $stmt->fetchColumn();
} catch(PDOException $e) {}

try {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM client_orders WHERE client_id = ? AND order_status != 'cancelled'");
    $stmt->execute([$_SESSION['user_id']]);
    $stats['total_spent'] = $stmt->fetchColumn();
} catch(PDOException $e) {}

try {
    $stmt = $conn->prepare("SELECT current_balance FROM client_profiles WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $stats['outstanding_balance'] = $stmt->fetchColumn() ?? 0;
} catch(PDOException $e) {}

try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM client_orders WHERE client_id = ? AND order_status = 'delivered'");
    $stmt->execute([$_SESSION['user_id']]);
    $stats['delivered_orders'] = $stmt->fetchColumn();
} catch(PDOException $e) {}

try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM client_orders WHERE client_id = ? AND order_status = 'cancelled'");
    $stmt->execute([$_SESSION['user_id']]);
    $stats['cancelled_orders'] = $stmt->fetchColumn();
} catch(PDOException $e) {}

// ============================================
// GET RECENT ORDERS
// ============================================
$recent_orders = [];
try {
    $stmt = $conn->prepare("
        SELECT co.*, s.store_name,
               (SELECT COUNT(*) FROM client_order_items WHERE order_id = co.order_id) as item_count
        FROM client_orders co
        LEFT JOIN stores s ON co.store_id = s.store_id
        WHERE co.client_id = ?
        ORDER BY co.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $recent_orders = [];
}
?>

<?php include '../includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="main-content">
    <!-- Top Navbar -->
    <div class="top-navbar">
        <div class="d-flex justify-content-between align-items-center w-100">
            <div class="d-flex align-items-center">
                <!-- Mobile toggle -->
                <button class="btn btn-link d-lg-none sidebar-toggle me-2">
                    <i class="fas fa-bars"></i>
                </button>

                <!-- DESKTOP TOGGLE -->
                <button type="button" class="btn btn-link sidebar-desktop-toggle me-2"
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
                    <h2>Welcome back, <span><?php echo explode(' ', $_SESSION['full_name'])[0]; ?></span>! 👋</h2>
                    <p>Here's an overview of your account and recent orders.</p>
                </div>
                <div class="welcome-actions">
                    <a href="products.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-shopping-bag me-2"></i>Browse Products
                    </a>
                </div>
            </div>
            <div class="welcome-decoration">
                <div class="circle circle-1"></div>
                <div class="circle circle-2"></div>
                <div class="circle circle-3"></div>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-purple">
                    <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                    <div class="stat-content">
                        <span class="stat-label">Total Orders</span>
                        <h3 class="stat-value"><?php echo number_format($stats['total_orders']); ?></h3>
                        <span class="stat-meta">
                            <i class="fas fa-check-circle"></i>
                            <?php echo number_format($stats['delivered_orders']); ?> delivered
                        </span>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-orange">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-content">
                        <span class="stat-label">Pending Orders</span>
                        <h3 class="stat-value"><?php echo number_format($stats['pending_orders']); ?></h3>
                        <span class="stat-meta">
                            <i class="fas fa-hourglass-half"></i>
                            In progress
                        </span>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-green">
                    <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="stat-content">
                        <span class="stat-label">Total Spent</span>
                        <h3 class="stat-value">UGX <?php echo format_currency($stats['total_spent']); ?></h3>
                        <span class="stat-meta">
                            <i class="fas fa-chart-line"></i>
                            All purchases
                        </span>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-red">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-content">
                        <span class="stat-label">Outstanding</span>
                        <h3 class="stat-value">UGX <?php echo format_currency($stats['outstanding_balance']); ?></h3>
                        <span class="stat-meta">
                            <i class="fas fa-file-invoice-dollar"></i>
                            Balance due
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card cyber-card">
                    <div class="card-header">
                        <i class="fas fa-bolt me-2"></i>Quick Actions
                    </div>
                    <div class="card-body">
                        <div class="quick-actions-grid">
                            <a href="products.php" class="quick-action-btn quick-action-purple">
                                <i class="fas fa-shopping-bag"></i>
                                <span>Browse Products</span>
                            </a>
                            <a href="new-order.php" class="quick-action-btn quick-action-orange">
                                <i class="fas fa-plus-circle"></i>
                                <span>New Order</span>
                            </a>
                            <a href="orders.php" class="quick-action-btn quick-action-cyan">
                                <i class="fas fa-list-alt"></i>
                                <span>My Orders</span>
                            </a>
                            <a href="invoices.php" class="quick-action-btn quick-action-green">
                                <i class="fas fa-file-invoice"></i>
                                <span>Invoices</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Orders -->
        <div class="row g-4">
            <div class="col-12">
                <div class="card cyber-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>
                            <i class="fas fa-receipt me-2"></i>
                            Recent Orders
                        </span>
                        <a href="orders.php" class="btn btn-sm btn-outline-light">
                            View All <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recent_orders)): ?>
                            <div class="empty-state">
                                <div class="empty-illustration">
                                    <div class="empty-glow"></div>
                                    <i class="fas fa-shopping-bag"></i>
                                    <span class="empty-plus">+</span>
                                </div>

                                <h4 class="empty-title">No Orders Yet</h4>
                                <p class="empty-subtitle">
                                    Browse our catalog and place your first order — it'll appear here.
                                </p>

                                <a href="products.php" class="empty-cta">
                                    <span>Browse Products</span>
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table cyber-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Order #</th>
                                            <th>Date</th>
                                            <th>Store</th>
                                            <th>Items</th>
                                            <th>Amount</th>
                                            <th>Payment</th>
                                            <th>Order Status</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_orders as $order): ?>
                                        <tr>
                                            <td>
                                                <span class="invoice-number">
                                                    <?php echo htmlspecialchars($order['order_number']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="date-cell">
                                                    <span class="date"><?php echo date('M d, Y', strtotime($order['order_date'])); ?></span>
                                                    <span class="time"><?php echo date('H:i A', strtotime($order['order_date'])); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="store-badge">
                                                    <?php echo htmlspecialchars($order['store_name'] ?? 'N/A'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php echo $order['item_count']; ?> item(s)
                                                </span>
                                            </td>
                                            <td>
                                                <span class="amount">
                                                    UGX <?php echo format_currency($order['grand_total']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                $p_class = match($order['payment_status'] ?? 'pending') {
                                                    'paid' => 'payment-paid',
                                                    'partial' => 'payment-partial',
                                                    default => 'payment-unpaid'
                                                };
                                                ?>
                                                <span class="payment-badge <?php echo $p_class; ?>">
                                                    <?php echo ucfirst($order['payment_status'] ?? 'Pending'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                $o_class = match($order['order_status'] ?? 'pending') {
                                                    'delivered' => 'status-delivered',
                                                    'cancelled' => 'status-cancelled',
                                                    'shipped' => 'status-shipped',
                                                    'ready' => 'status-ready',
                                                    'processing' => 'status-processing',
                                                    'confirmed' => 'status-confirmed',
                                                    default => 'status-pending'
                                                };
                                                ?>
                                                <span class="status-badge <?php echo $o_class; ?>">
                                                    <?php echo ucfirst($order['order_status'] ?? 'Pending'); ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <a href="order-details.php?id=<?php echo $order['order_id']; ?>"
                                                   class="btn-action btn-view" title="View Details">
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
            </div>
        </div>
    </div>
</div>

<!-- Client Dashboard Styles -->
<style>
/* ============================================================
   WELCOME BANNER
   ============================================================ */
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
    text-decoration: none;
}

.welcome-actions .btn:hover {
    background: linear-gradient(135deg, #553C9A, #6B46C1);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(107, 70, 193, 0.5);
    color: #fff;
}

.welcome-decoration { position: absolute; inset: 0; pointer-events: none; }
.welcome-decoration .circle { position: absolute; border-radius: 50%; opacity: 0.1; }
.welcome-decoration .circle-1 { width: 300px; height: 300px; background: #6B46C1; top: -150px; right: -50px; }
.welcome-decoration .circle-2 { width: 200px; height: 200px; background: #ED8936; bottom: -100px; right: 150px; }
.welcome-decoration .circle-3 { width: 150px; height: 150px; background: #00B5D8; top: 50%; right: 300px; transform: translateY(-50%); }

/* ============================================================
   STAT CARDS
   ============================================================ */
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
.stat-card-green { border-left: 4px solid #48BB78; }
.stat-card-red { border-left: 4px solid #E53E3E; }
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

.stat-card-purple .stat-icon { background: rgba(107, 70, 193, 0.1); color: #6B46C1; }
.stat-card-orange .stat-icon { background: rgba(237, 137, 54, 0.1); color: #ED8936; }
.stat-card-green .stat-icon { background: rgba(72, 187, 120, 0.1); color: #48BB78; }
.stat-card-red .stat-icon { background: rgba(229, 62, 62, 0.1); color: #E53E3E; }
.stat-card-cyan .stat-icon { background: rgba(0, 181, 216, 0.1); color: #00B5D8; }

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

/* ============================================================
   CYBER CARD
   ============================================================ */
.cyber-card {
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

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

/* ============================================================
   QUICK ACTIONS
   ============================================================ */
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
.quick-action-btn:hover { transform: translateY(-5px); color: #fff; }
.quick-action-btn:hover i { transform: scale(1.15); }

.quick-action-purple {
    background: linear-gradient(135deg, #6B46C1, #553C9A);
    box-shadow: 0 4px 15px rgba(107, 70, 193, 0.3);
}

.quick-action-orange {
    background: linear-gradient(135deg, #ED8936, #DD6B20);
    box-shadow: 0 4px 15px rgba(237, 137, 54, 0.3);
}

.quick-action-cyan {
    background: linear-gradient(135deg, #00B5D8, #0987A0);
    box-shadow: 0 4px 15px rgba(0, 181, 216, 0.3);
}

.quick-action-green {
    background: linear-gradient(135deg, #48BB78, #38A169);
    box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
}

/* ============================================================
   CYBER TABLE
   ============================================================ */
.cyber-table { width: 100%; border-collapse: collapse; }

.cyber-table thead th {
    background: #F7FAFC;
    color: #4A5568;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
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

.store-badge {
    display: inline-block;
    padding: 4px 10px;
    background: #EDF2F7;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #4A5568;
}

.amount { font-weight: 700; color: #1A202C; font-family: 'Courier New', monospace; }

.payment-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: capitalize;
    white-space: nowrap;
}

.payment-paid { background: rgba(72, 187, 120, 0.15); color: #2F855A; }
.payment-partial { background: rgba(237, 137, 54, 0.15); color: #C05621; }
.payment-unpaid { background: rgba(229, 62, 62, 0.15); color: #C53030; }

.status-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: capitalize;
    white-space: nowrap;
}

.status-delivered { background: rgba(72, 187, 120, 0.15); color: #2F855A; }
.status-cancelled { background: rgba(229, 62, 62, 0.15); color: #C53030; }
.status-shipped { background: rgba(0, 181, 216, 0.15); color: #0987A0; }
.status-ready { background: rgba(107, 70, 193, 0.15); color: #553C9A; }
.status-processing { background: rgba(237, 137, 54, 0.15); color: #C05621; }
.status-confirmed { background: rgba(66, 153, 225, 0.15); color: #2B6CB0; }
.status-pending { background: rgba(237, 137, 54, 0.15); color: #C05621; }

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

/* ============================================================
   EMPTY STATE — modern redesign
   ============================================================ */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 72px 24px 80px;
    background:
        radial-gradient(circle at 50% 0%, rgba(107, 70, 193, 0.06), transparent 55%),
        linear-gradient(180deg, #FFFFFF 0%, #FAFBFD 100%);
}

/* Floating illustration with soft glow */
.empty-illustration {
    position: relative;
    width: 120px;
    height: 120px;
    margin-bottom: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.empty-illustration .empty-glow {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(107, 70, 193, 0.25), transparent 65%);
    filter: blur(6px);
    animation: empty-pulse 3.5s ease-in-out infinite;
}

.empty-illustration > i {
    position: relative;
    z-index: 2;
    font-size: 3.5rem;
    color: #6B46C1;
    opacity: 0.85;
    filter: drop-shadow(0 10px 20px rgba(107, 70, 193, 0.25));
    animation: empty-float 4s ease-in-out infinite;
}

.empty-illustration .empty-plus {
    position: absolute;
    top: 12px;
    right: 14px;
    z-index: 3;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: linear-gradient(135deg, #ED8936, #DD6B20);
    color: #fff;
    font-size: 0.95rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 6px 16px rgba(237, 137, 54, 0.45);
    animation: empty-bob 2.5s ease-in-out infinite;
}

@keyframes empty-float {
    0%, 100% { transform: translateY(0); }
    50%      { transform: translateY(-6px); }
}

@keyframes empty-pulse {
    0%, 100% { opacity: 0.7; transform: scale(1); }
    50%      { opacity: 1;   transform: scale(1.08); }
}

@keyframes empty-bob {
    0%, 100% { transform: translateY(0) rotate(0deg); }
    50%      { transform: translateY(-3px) rotate(8deg); }
}

/* Text */
.empty-title {
    font-size: 1.4rem;
    font-weight: 800;
    color: #1A202C;
    margin-bottom: 8px;
    letter-spacing: -0.01em;
}

.empty-subtitle {
    color: #718096;
    font-size: 0.95rem;
    max-width: 380px;
    line-height: 1.55;
    margin: 0 0 26px;
}

/* Modern pill CTA with icon and hover arrow */
.empty-cta {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 14px 26px;
    border-radius: 999px;
    font-size: 0.95rem;
    font-weight: 700;
    color: #fff;
    text-decoration: none;
    background: linear-gradient(135deg, #6B46C1 0%, #553C9A 100%);
    box-shadow:
        0 8px 22px rgba(107, 70, 193, 0.35),
        inset 0 1px 0 rgba(255, 255, 255, 0.15);
    transition: transform 0.25s ease, box-shadow 0.25s ease, background 0.25s ease;
    position: relative;
    overflow: hidden;
    white-space: nowrap;
}

.empty-cta::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.15), transparent 55%);
    opacity: 0;
    transition: opacity 0.25s ease;
}

.empty-cta:hover {
    transform: translateY(-3px);
    box-shadow:
        0 12px 30px rgba(107, 70, 193, 0.5),
        inset 0 1px 0 rgba(255, 255, 255, 0.2);
    color: #fff;
}

.empty-cta:hover::before { opacity: 1; }

.empty-cta:active {
    transform: translateY(-1px);
}

.empty-cta i {
    font-size: 0.9rem;
    transition: transform 0.25s ease;
    opacity: 0.9;
}

.empty-cta:hover i {
    transform: translateX(4px);
    opacity: 1;
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

    .empty-state { padding: 56px 20px 64px; }
    .empty-illustration { width: 100px; height: 100px; margin-bottom: 22px; }
    .empty-illustration > i { font-size: 3rem; }
    .empty-title { font-size: 1.2rem; }
    .empty-cta { padding: 12px 22px; font-size: 0.9rem; }
}
</style>

<?php include 'includes/footer.php'; ?>

<script>
/* ============================================
   SIDEBAR COLLAPSE - GUARANTEED TO WORK
   ============================================ */
(function() {
    'use strict';

    function initSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const collapseBtn = document.getElementById('sidebarCollapseBtn');
        const desktopToggle = document.getElementById('sidebarDesktopToggle');
        const mobileToggles = document.querySelectorAll('.sidebar-toggle');

        console.log('🔧 Client Dashboard: Sidebar init', {
            sidebar: !!sidebar,
            collapseBtn: !!collapseBtn,
            desktopToggle: !!desktopToggle
        });

        if (!sidebar) {
            console.error('❌ No #sidebar found');
            return;
        }

        // Restore saved state
        if (localStorage.getItem('cyberSidebarCollapsed') === '1' && window.innerWidth >= 992) {
            sidebar.classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed');
        }

        function toggle() {
            if (window.innerWidth < 992) return;

            sidebar.classList.toggle('collapsed');
            document.body.classList.toggle('sidebar-collapsed');

            const isNowCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('cyberSidebarCollapsed', isNowCollapsed ? '1' : '0');

            setTimeout(function() {
                window.dispatchEvent(new Event('resize'));
            }, 350);

            console.log('🔄 Sidebar:', isNowCollapsed ? 'COLLAPSED' : 'EXPANDED');
        }

        if (collapseBtn) {
            collapseBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggle();
            });
        }

        if (desktopToggle) {
            desktopToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggle();
            });
        }

        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
                e.preventDefault();
                toggle();
            }
        });

        mobileToggles.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                sidebar.classList.toggle('show');
                if (overlay) overlay.classList.toggle('show');
            });
        });

        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            });
        }

        sidebar.querySelectorAll('.nav-link:not(.section-toggle)').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth < 992) {
                    sidebar.classList.remove('show');
                    if (overlay) overlay.classList.remove('show');
                }
            });
        });

        console.log('🎉 Sidebar ready!');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
})();
</script>