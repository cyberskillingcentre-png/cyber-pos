<?php
require_once '../config/config.php';
require_login();

// Only employees can access this
if (is_admin()) {
    header('Location: ../admin/view-sale.php?id=' . intval($_GET['id']));
    exit;
}


$page_title = "My Sales";

// ============================================
// FETCH FILTERS
// ============================================
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$payment_filter = $_GET['payment'] ?? '';
$status_filter = $_GET['status'] ?? '';

// ============================================
// BUILD QUERY WITH FILTERS
// ============================================
$where = ["s.served_by = ?"];
$params = [$_SESSION['user_id']];

if (!empty($date_from)) {
    $where[] = "DATE(s.sale_date) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where[] = "DATE(s.sale_date) <= ?";
    $params[] = $date_to;
}

if (!empty($payment_filter)) {
    $where[] = "s.payment_method = ?";
    $params[] = $payment_filter;
}

if (!empty($status_filter)) {
    $where[] = "s.status = ?";
    $params[] = $status_filter;
}

$where_clause = implode(' AND ', $where);

// ============================================
// FETCH SALES
// ============================================
$sales = [];
try {
    $stmt = $conn->prepare("
        SELECT s.*, 
               st.store_name, 
               c.customer_name 
        FROM sales s 
        LEFT JOIN stores st ON s.store_id = st.store_id 
        LEFT JOIN customers c ON s.customer_id = c.customer_id 
        WHERE $where_clause
        ORDER BY s.sale_date DESC
    ");
    $stmt->execute($params);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $sales = [];
}

// ============================================
// CALCULATE STATS
// ============================================
$total_sales = count($sales);
$total_revenue = 0;
$total_paid = 0;
$total_due = 0;
$today_count = 0;
$today_revenue = 0;

foreach ($sales as $s) {
    $total_revenue += floatval($s['total_amount'] ?? 0);
    $total_paid += floatval($s['amount_paid'] ?? 0);
    $total_due += floatval($s['amount_due'] ?? 0);
    
    if (date('Y-m-d', strtotime($s['sale_date'])) === date('Y-m-d')) {
        $today_count++;
        $today_revenue += floatval($s['total_amount'] ?? 0);
    }
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
                
                <!-- DESKTOP TOGGLE -->
                <button type="button" class="btn btn-link sidebar-desktop-toggle me-2" 
                        id="sidebarDesktopToggle" title="Toggle Sidebar (Ctrl+B)">
                    <i class="fas fa-bars"></i>
                </button>
                
                <h5 class="mb-0">
                    <i class="fas fa-receipt me-2" style="color: var(--cyber-orange);"></i>
                    My Sales
                </h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted d-none d-md-inline">
                    <i class="fas fa-calendar-alt me-2"></i>
                    <?php echo date('D, M j, Y'); ?>
                </span>
                <a href="pos.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-cash-register me-1"></i>New Sale
                </a>
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
        
        <!-- Stat Cards -->
        <div class="row g-4 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stat-card purple">
                    <div class="icon"><i class="fas fa-shopping-cart"></i></div>
                    <div>
                        <h3><?php echo number_format($today_count); ?></h3>
                        <p>Today's Sales</p>
                        <div class="text-muted small">UGX <?php echo format_currency($today_revenue); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="stat-card orange">
                    <div class="icon"><i class="fas fa-chart-line"></i></div>
                    <div>
                        <h3><?php echo number_format($total_sales); ?></h3>
                        <p>Total Sales</p>
                        <div class="text-muted small">All time</div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="stat-card green">
                    <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div>
                        <h3>UGX <?php echo format_currency($total_revenue); ?></h3>
                        <p>Total Revenue</p>
                        <div class="text-muted small">All time</div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="stat-card cyan">
                    <div class="icon"><i class="fas fa-hand-holding-usd"></i></div>
                    <div>
                        <h3>UGX <?php echo format_currency($total_due); ?></h3>
                        <p>Outstanding</p>
                        <div class="text-muted small">Unpaid balance</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card cyber-card mb-4">
            <div class="card-header">
                <i class="fas fa-filter me-2"></i>Filter Sales
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label-cyber">Date From</label>
                        <input type="date" name="date_from" class="form-control form-control-cyber" 
                               value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label-cyber">Date To</label>
                        <input type="date" name="date_to" class="form-control form-control-cyber" 
                               value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label-cyber">Payment</label>
                        <select name="payment" class="form-select form-select-cyber">
                            <option value="">All Methods</option>
                            <option value="cash" <?php echo $payment_filter == 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="card" <?php echo $payment_filter == 'card' ? 'selected' : ''; ?>>Card</option>
                            <option value="mobile_money" <?php echo $payment_filter == 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                            <option value="bank_transfer" <?php echo $payment_filter == 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="credit" <?php echo $payment_filter == 'credit' ? 'selected' : ''; ?>>Credit</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label-cyber">Status</label>
                        <select name="status" class="form-select form-select-cyber">
                            <option value="">All Status</option>
                            <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="returned" <?php echo $status_filter == 'returned' ? 'selected' : ''; ?>>Returned</option>
                            <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-1"></i>Apply
                        </button>
                        <a href="my-sales.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Sales Table -->
        <div class="card cyber-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-list me-2"></i>
                    All My Sales (<?php echo count($sales); ?>)
                </span>
                <span class="badge bg-warning text-dark">
                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($_SESSION['full_name']); ?>
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($sales)): ?>
                    <div class="empty-state">
                        <i class="fas fa-receipt"></i>
                        <h5>No Sales Found</h5>
                        <p>Try adjusting filters or start a new sale</p>
                        <a href="pos.php" class="btn btn-primary mt-3">
                            <i class="fas fa-cash-register me-2"></i>Start Selling
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="salesTable">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Date & Time</th>
                                    <th>Customer</th>
                                    <th>Store</th>
                                    <th>Amount</th>
                                    <th>Paid</th>
                                    <th>Due</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sales as $sale): ?>
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
                                        <span class="amount text-success">
                                            UGX <?php echo format_currency($sale['amount_paid'] ?? 0); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (($sale['amount_due'] ?? 0) > 0): ?>
                                            <span class="amount text-danger">
                                                UGX <?php echo format_currency($sale['amount_due']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-success">UGX 0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="payment-badge payment-<?php echo $sale['payment_method']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $sale['payment_method'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = match($sale['status']) {
                                            'completed' => 'status-completed',
                                            'returned' => 'status-warning',
                                            'cancelled' => 'status-cancelled',
                                            default => 'status-secondary'
                                        };
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo ucfirst($sale['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <a href="view-sale.php?id=<?php echo $sale['sale_id']; ?>" 
                                           class="btn-action btn-view" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <a href="receipt.php?id=<?php echo $sale['sale_id']; ?>" 
                                           class="btn-action btn-print" title="Print Receipt" target="_blank">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        
                                        <?php if (($sale['sale_type'] ?? 'cash') == 'credit' && ($sale['amount_due'] ?? 0) > 0): ?>
                                        <a href="credit-invoice.php?id=<?php echo $sale['sale_id']; ?>" 
                                           class="btn-action btn-credit" title="Credit Invoice" target="_blank">
                                            <i class="fas fa-file-invoice-dollar"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="4" class="text-end">TOTALS:</th>
                                    <th>UGX <?php echo format_currency($total_revenue); ?></th>
                                    <th class="text-success">UGX <?php echo format_currency($total_paid); ?></th>
                                    <th class="text-danger">UGX <?php echo format_currency($total_due); ?></th>
                                    <th colspan="3"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Page Styles -->
<style>
/* Stat cards (employee page specific overrides) */
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
    border-left: 4px solid #6B46C1;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
}

.stat-card.purple { border-left-color: #6B46C1; }
.stat-card.orange { border-left-color: #ED8936; }
.stat-card.green { border-left-color: #48BB78; }
.stat-card.cyan { border-left-color: #00B5D8; }

.stat-card .icon {
    width: 60px;
    height: 60px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
    background: rgba(107, 70, 193, 0.1);
    color: #6B46C1;
}

.stat-card.orange .icon { background: rgba(237, 137, 54, 0.1); color: #ED8936; }
.stat-card.green .icon { background: rgba(72, 187, 120, 0.1); color: #48BB78; }
.stat-card.cyan .icon { background: rgba(0, 181, 216, 0.1); color: #00B5D8; }

.stat-card h3 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1A202C;
    margin: 0 0 6px 0;
}

.stat-card p {
    font-size: 0.8rem;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
    font-weight: 600;
}

/* Cyber Card */
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

/* Form labels */
.form-label-cyber {
    display: flex;
    align-items: center;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #4A5568;
    margin-bottom: 8px;
}

.form-control-cyber, .form-select-cyber {
    border: 2px solid #E2E8F0;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}

.form-control-cyber:focus, .form-select-cyber:focus {
    outline: none;
    border-color: #6B46C1;
    box-shadow: 0 0 0 3px rgba(107, 70, 193, 0.15);
}

/* Table styling */
.cyber-card table.dataTable thead th {
    background: #F7FAFC;
    color: #4A5568;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 14px 12px;
    border-bottom: 2px solid #E2E8F0;
    white-space: nowrap;
}

.cyber-card table.dataTable tbody td {
    padding: 12px;
    font-size: 0.85rem;
    vertical-align: middle;
}

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

.amount { font-weight: 700; color: #1A202C; font-family: 'Courier New', monospace; }
.amount.text-success { color: #2F855A; }
.amount.text-danger { color: #C53030; }

.payment-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: capitalize;
    white-space: nowrap;
}

.payment-cash { background: rgba(72, 187, 120, 0.15); color: #2F855A; }
.payment-card { background: rgba(66, 153, 225, 0.15); color: #2B6CB0; }
.payment-mobile_money { background: rgba(237, 137, 54, 0.15); color: #C05621; }
.payment-bank_transfer { background: rgba(107, 70, 193, 0.15); color: #553C9A; }
.payment-credit { background: rgba(229, 62, 62, 0.15); color: #C53030; }

.status-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: capitalize;
    white-space: nowrap;
}

.status-completed { background: rgba(72, 187, 120, 0.15); color: #2F855A; }
.status-warning { background: rgba(237, 137, 54, 0.15); color: #C05621; }
.status-cancelled { background: rgba(229, 62, 62, 0.15); color: #C53030; }
.status-secondary { background: #EDF2F7; color: #4A5568; }

/* Action buttons */
.btn-action {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
    margin: 0 2px;
}

.btn-view {
    background: rgba(107, 70, 193, 0.1);
    color: #6B46C1;
}
.btn-view:hover { background: #6B46C1; color: #fff; transform: scale(1.05); }

.btn-print {
    background: rgba(237, 137, 54, 0.1);
    color: #C05621;
}
.btn-print:hover { background: #ED8936; color: #fff; transform: scale(1.05); }

.btn-credit {
    background: rgba(229, 62, 62, 0.1);
    color: #C53030;
}
.btn-credit:hover { background: #E53E3E; color: #fff; transform: scale(1.05); }

/* Empty state */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #718096;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 16px;
    opacity: 0.3;
    color: #6B46C1;
    display: block;
}

.empty-state h5 {
    color: #4A5568;
    font-weight: 700;
    margin-bottom: 8px;
}

.empty-state p { margin: 0; font-size: 0.9rem; }

/* Table footer */
.table tfoot th {
    background: #F7FAFC;
    color: #1A202C;
    font-weight: 700;
    font-size: 0.85rem;
    padding: 14px 12px;
    border-top: 2px solid #E2E8F0;
    font-family: 'Courier New', monospace;
}

/* Responsive */
@media (max-width: 991.98px) {
    .stat-card { padding: 20px; }
    .stat-card h3 { font-size: 1.25rem; }
    .stat-card .icon { width: 48px; height: 48px; font-size: 1.2rem; }
}
</style>

<?php include '../includes/footer.php'; ?>

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
        
        console.log('🔧 My Sales: Sidebar init', {
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
        
        // Wire collapse button
        if (collapseBtn) {
            collapseBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggle();
            });
            console.log('✅ Collapse button wired');
        }
        
        // Wire desktop toggle
        if (desktopToggle) {
            desktopToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggle();
            });
            console.log('✅ Desktop toggle wired');
        }
        
        // Ctrl+B
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
                e.preventDefault();
                toggle();
            }
        });
        
        // Mobile toggle
        mobileToggles.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                sidebar.classList.toggle('show');
                if (overlay) overlay.classList.toggle('show');
            });
        });
        
        // Overlay close
        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            });
        }
        
        console.log('🎉 Sidebar ready!');
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
})();


</script>