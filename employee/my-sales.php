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

<style>
/* ============================================
   MY SALES - COMPACT MODERN UI
   ============================================ */
.sales-wrap {
    background: #F8FAFC;
    min-height: calc(100vh - 60px);
    padding: 20px !important;
}

/* Filter Bar */
.filter-card-compact {
    background: #fff;
    border-radius: 10px;
    border: 1px solid #E2E8F0;
    padding: 14px 18px;
    margin-bottom: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}

.form-label-compact {
    font-size: 0.65rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748B;
    margin-bottom: 4px;
    display: block;
}

.form-control-compact, .form-select-compact {
    border: 1px solid #E2E8F0;
    border-radius: 6px;
    padding: 6px 10px;
    font-size: 0.8rem;
    transition: all 0.2s;
}

.form-control-compact:focus, .form-select-compact:focus {
    outline: none;
    border-color: #6B46C1;
    box-shadow: 0 0 0 2px rgba(107, 70, 193, 0.15);
}

/* Compact Stats */
.stat-mini {
    background: #fff;
    border-radius: 10px;
    padding: 12px 16px;
    border: 1px solid #E2E8F0;
    display: flex;
    align-items: center;
    gap: 12px;
    height: 100%;
    transition: all 0.2s;
}

.stat-mini:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.stat-mini-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    flex-shrink: 0;
}

.stat-mini-purple { background: rgba(107, 70, 193, 0.1); color: #6B46C1; }
.stat-mini-orange { background: rgba(237, 137, 54, 0.1); color: #ED8936; }
.stat-mini-green { background: rgba(72, 187, 120, 0.1); color: #38A169; }
.stat-mini-red { background: rgba(229, 62, 62, 0.1); color: #E53E3E; }

.stat-mini-label {
    font-size: 0.6rem;
    font-weight: 800;
    text-transform: uppercase;
    color: #718096;
    margin-bottom: 2px;
}

.stat-mini-value {
    font-size: 1.05rem;
    font-weight: 800;
    color: #1A202C;
    line-height: 1;
}

/* Table Card */
.table-card-compact {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #E2E8F0;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}

.table-card-header {
    padding: 12px 18px;
    border-bottom: 1px solid #EDF2F7;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 700;
    font-size: 0.85rem;
    color: #1A202C;
}

/* Modern Table */
.modern-table {
    width: 100%;
    border-collapse: collapse;
}

.modern-table thead th {
    background: #F8FAFC;
    color: #64748B;
    font-size: 0.65rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 10px 14px;
    text-align: left;
    border-bottom: 1px solid #E2E8F0;
    white-space: nowrap;
}

.modern-table tbody td {
    padding: 10px 14px;
    border-bottom: 1px solid #F1F5F9;
    vertical-align: middle;
    font-size: 0.78rem;
}

.modern-table tbody tr:hover { background: #F8FAFC; }
.modern-table tbody tr:last-child td { border-bottom: none; }

/* Badges */
.badge-soft {
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.62rem;
    font-weight: 700;
    display: inline-block;
    white-space: nowrap;
}

.badge-cash { background: #DEF7EC; color: #03543F; }
.badge-mobile { background: #FEF08A; color: #713F12; }
.badge-credit { background: #FDE8E8; color: #9B1C1C; }
.badge-card { background: #E1EFFE; color: #1E429F; }
.badge-bank { background: #E0E7FF; color: #3730A3; }

.badge-status-completed { background: #DEF7EC; color: #03543F; }
.badge-status-returned { background: #FEF08A; color: #713F12; }
.badge-status-cancelled { background: #FDE8E8; color: #9B1C1C; }

/* Modern Action Buttons */
.action-btn-group {
    display: flex;
    gap: 6px;
    justify-content: center;
}

.btn-icon-action {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
}

.btn-icon-view {
    background: rgba(66, 153, 225, 0.1);
    color: #3182CE;
}
.btn-icon-view:hover {
    background: #3182CE;
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(49, 130, 206, 0.3);
}

.btn-icon-print {
    background: rgba(107, 70, 193, 0.1);
    color: #6B46C1;
}
.btn-icon-print:hover {
    background: #6B46C1;
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(107, 70, 193, 0.3);
}

.btn-icon-credit {
    background: rgba(237, 137, 54, 0.1);
    color: #DD6B20;
}
.btn-icon-credit:hover {
    background: #ED8936;
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(237, 137, 54, 0.3);
}

/* ============================================
   NEW CENTERED EMPTY STATE
   ============================================ */
.empty-widget {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 30px;
    text-align: center;
}

.empty-widget-icon {
    width: 72px;
    height: 72px;
    background: linear-gradient(135deg, rgba(107, 70, 193, 0.1), rgba(107, 70, 193, 0.05));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
    color: #6B46C1;
    font-size: 1.8rem;
}

.empty-widget h5 {
    font-size: 1.1rem;
    font-weight: 800;
    color: #1A202C;
    margin-bottom: 6px;
}

.empty-widget p {
    font-size: 0.85rem;
    color: #64748B;
    margin-bottom: 0;
    max-width: 300px;
}

/* Table Footer */
.modern-table tfoot th {
    background: #F8FAFC;
    color: #1A202C;
    font-weight: 800;
    font-size: 0.75rem;
    padding: 12px 14px;
    border-top: 2px solid #E2E8F0;
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
                <button type="button" class="btn btn-link sidebar-desktop-toggle me-2" 
                        id="sidebarDesktopToggle" title="Toggle Sidebar (Ctrl+B)">
                    <i class="fas fa-bars"></i>
                </button>
                <h5 class="mb-0">
                    <i class="fas fa-receipt me-2" style="color: var(--cyber-orange);"></i>
                    My Sales
                </h5>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="pos.php" class="btn btn-primary btn-sm" style="font-size:0.75rem; font-weight:700; padding: 6px 14px;">
                    <i class="fas fa-cash-register me-1"></i>New Sale
                </a>
            </div>
        </div>
    </div>
    
    <div class="content-area sales-wrap">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show py-2 px-3" style="font-size:0.85rem;">
                <i class="fas fa-<?php echo $alert['type'] == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" style="padding: 0.8rem;"></button>
            </div>
        <?php endif; ?>
        
        <!-- Filter Bar -->
        <div class="filter-card-compact">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-lg-3 col-md-6">
                    <label class="form-label-compact">Date From</label>
                    <input type="date" name="date_from" class="form-control form-control-compact" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label-compact">Date To</label>
                    <input type="date" name="date_to" class="form-control form-control-compact" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label-compact">Payment</label>
                    <select name="payment" class="form-select form-select-compact">
                        <option value="">All</option>
                        <option value="cash" <?php echo $payment_filter == 'cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="card" <?php echo $payment_filter == 'card' ? 'selected' : ''; ?>>Card</option>
                        <option value="mobile_money" <?php echo $payment_filter == 'mobile_money' ? 'selected' : ''; ?>>Mobile</option>
                        <option value="bank_transfer" <?php echo $payment_filter == 'bank_transfer' ? 'selected' : ''; ?>>Bank</option>
                        <option value="credit" <?php echo $payment_filter == 'credit' ? 'selected' : ''; ?>>Credit</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label-compact">Status</label>
                    <select name="status" class="form-select form-select-compact">
                        <option value="">All</option>
                        <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="returned" <?php echo $status_filter == 'returned' ? 'selected' : ''; ?>>Returned</option>
                        <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-lg-2 col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill" style="font-size:0.75rem; font-weight:700; padding:6px;">
                        <i class="fas fa-filter me-1"></i>Apply
                    </button>
                    <a href="my-sales.php" class="btn btn-outline-secondary" style="padding:6px 10px;">
                        <i class="fas fa-redo"></i>
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Compact Stats -->
        <div class="row g-2 mb-3">
            <div class="col-lg-3 col-md-6">
                <div class="stat-mini">
                    <div class="stat-mini-icon stat-mini-purple"><i class="fas fa-shopping-cart"></i></div>
                    <div>
                        <div class="stat-mini-label">Today's Sales</div>
                        <div class="stat-mini-value"><?php echo number_format($today_count); ?></div>
                        <small class="text-muted" style="font-size:0.65rem;">UGX <?php echo number_format($today_revenue, 0); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-mini">
                    <div class="stat-mini-icon stat-mini-orange"><i class="fas fa-chart-line"></i></div>
                    <div>
                        <div class="stat-mini-label">Total Sales</div>
                        <div class="stat-mini-value"><?php echo number_format($total_sales); ?></div>
                        <small class="text-muted" style="font-size:0.65rem;">All time</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-mini">
                    <div class="stat-mini-icon stat-mini-green"><i class="fas fa-money-bill-wave"></i></div>
                    <div>
                        <div class="stat-mini-label">Revenue</div>
                        <div class="stat-mini-value">UGX <?php echo number_format($total_revenue, 0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-mini">
                    <div class="stat-mini-icon stat-mini-red"><i class="fas fa-hand-holding-usd"></i></div>
                    <div>
                        <div class="stat-mini-label">Outstanding</div>
                        <div class="stat-mini-value">UGX <?php echo number_format($total_due, 0); ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Table Card -->
        <div class="table-card-compact">
            <div class="table-card-header">
                <span><i class="fas fa-list me-2"></i>Sales List</span>
                <span class="badge bg-light text-dark" style="font-size:0.7rem;"><?php echo count($sales); ?> records</span>
            </div>
            
            <div class="table-responsive">
                <?php if (empty($sales)): ?>
                    <!-- Centered Empty Widget -->
                    <div class="empty-widget">
                        <div class="empty-widget-icon">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <h5>No Sales Found</h5>
                        <p>You haven't made any sales in this period. Click "New Sale" in the top bar to get started.</p>
                    </div>
                <?php else: ?>
                    <table class="modern-table" id="salesTable">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Store</th>
                                <th>Amount</th>
                                <th>Paid</th>
                                <th>Due</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales as $sale): 
                                $isCredit = ($sale['sale_type'] ?? 'cash') === 'credit';
                                $balance = floatval($sale['amount_due'] ?? 0);
                            ?>
                            <tr>
                                <td><span style="color:#6B46C1; font-family:'Courier New',monospace; font-weight:700;"><?php echo htmlspecialchars($sale['invoice_number']); ?></span></td>
                                <td>
                                    <div style="font-weight:600;"><?php echo date('M d, Y', strtotime($sale['sale_date'])); ?></div>
                                    <small class="text-muted" style="font-size:0.7rem;"><?php echo date('H:i A', strtotime($sale['sale_date'])); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?></td>
                                <td><?php echo htmlspecialchars($sale['store_name'] ?? '-'); ?></td>
                                <td><strong>UGX <?php echo number_format($sale['total_amount'], 0); ?></strong></td>
                                <td class="text-success" style="font-weight:600;">UGX <?php echo number_format($sale['amount_paid'] ?? 0, 0); ?></td>
                                <td class="<?php echo $balance > 0 ? 'text-danger' : 'text-muted'; ?>" style="font-weight:600;">
                                    UGX <?php echo number_format($balance, 0); ?>
                                </td>
                                <td>
                                    <?php 
                                    $pm = $sale['payment_method'];
                                    $pmClass = 'badge-cash';
                                    if ($pm == 'mobile_money') $pmClass = 'badge-mobile';
                                    if ($pm == 'credit') $pmClass = 'badge-credit';
                                    if ($pm == 'card') $pmClass = 'badge-card';
                                    if ($pm == 'bank_transfer') $pmClass = 'badge-bank';
                                    ?>
                                    <span class="badge-soft <?php echo $pmClass; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $pm)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $st = $sale['status'];
                                    $stClass = 'badge-status-completed';
                                    if ($st == 'returned') $stClass = 'badge-status-returned';
                                    if ($st == 'cancelled') $stClass = 'badge-status-cancelled';
                                    ?>
                                    <span class="badge-soft <?php echo $stClass; ?>">
                                        <?php echo ucfirst($st); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-btn-group">
                                        <a href="view-sale.php?id=<?php echo $sale['sale_id']; ?>" class="btn-icon-action btn-icon-view" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="receipt.php?id=<?php echo $sale['sale_id']; ?>" target="_blank" class="btn-icon-action btn-icon-print" title="Print Receipt">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        <?php if ($isCredit && $balance > 0): ?>
                                        <a href="credit-invoice.php?id=<?php echo $sale['sale_id']; ?>" target="_blank" class="btn-icon-action btn-icon-credit" title="Credit Invoice">
                                            <i class="fas fa-file-invoice-dollar"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-end">TOTALS:</th>
                                <th>UGX <?php echo number_format($total_revenue, 0); ?></th>
                                <th class="text-success">UGX <?php echo number_format($total_paid, 0); ?></th>
                                <th class="text-danger">UGX <?php echo number_format($total_due, 0); ?></th>
                                <th colspan="3"></th>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
jQuery(document).ready(function($) {
    setTimeout(function() {
        var tableSelector = '#salesTable';
        var $table = $(tableSelector);
        
        if ($table.length > 0) {
            if (!$.fn.DataTable.isDataTable(tableSelector)) {
                $table.DataTable({
                    pageLength: 20,
                    responsive: false,
                    order: [[1, 'desc']],
                    language: {
                        search: "", searchPlaceholder: "Search sales...",
                        lengthMenu: "Show _MENU_",
                        info: "_START_–_END_ of _TOTAL_",
                        infoEmpty: "No entries",
                        infoFiltered: "(filtered from _MAX_)",
                        paginate: {
                            first: '<i class="fas fa-angle-double-left"></i>',
                            last: '<i class="fas fa-angle-double-right"></i>',
                            next: '<i class="fas fa-angle-right"></i>',
                            previous: '<i class="fas fa-angle-left"></i>'
                        }
                    }
                });
            }
        }
    }, 200);
});
</script>