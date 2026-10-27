<?php
require_once '../config/config.php';
require_admin();

$page_title = "Inventory Reports & Analytics";

// Get filter parameters
$report_type = $_GET['report'] ?? 'stock_levels';
$filter_store = $_GET['store'] ?? 'all';
$filter_category = $_GET['category'] ?? 'all';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Fetch stores
$stmt = $conn->prepare("SELECT * FROM stores WHERE status = 'active' ORDER BY store_name");
$stmt->execute();
$stores = $stmt->fetchAll();

// Fetch categories
$stmt = $conn->prepare("SELECT * FROM categories WHERE status = 'active' ORDER BY category_name");
$stmt->execute();
$categories = $stmt->fetchAll();

// Build WHERE clause for filters
$where_store = $filter_store != 'all' ? "AND si.store_id = " . intval($filter_store) : "";
$where_category = $filter_category != 'all' ? "AND p.category_id = " . intval($filter_category) : "";

// Initialize report data
$report_data = [];
$summary = [];

switch ($report_type) {
    case 'stock_levels':
        $stmt = $conn->prepare("
            SELECT 
                p.product_id,
                p.product_code,
                p.product_name,
                c.category_name,
                s.store_name,
                COALESCE(SUM(si.quantity), 0) as total_quantity,
                p.reorder_level,
                p.purchase_price,
                p.selling_price,
                CASE 
                    WHEN COALESCE(SUM(si.quantity), 0) = 0 THEN 'Out of Stock'
                    WHEN COALESCE(SUM(si.quantity), 0) <= p.reorder_level THEN 'Low Stock'
                    WHEN COALESCE(SUM(si.quantity), 0) <= p.reorder_level * 2 THEN 'Medium Stock'
                    ELSE 'Good Stock'
                END as stock_status
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN store_inventory si ON p.product_id = si.product_id
            LEFT JOIN stores s ON si.store_id = s.store_id
            WHERE p.status = 'active' $where_category $where_store
            GROUP BY p.product_id, s.store_id
            ORDER BY stock_status ASC, p.product_name
        ");
        $stmt->execute();
        $report_data = $stmt->fetchAll();
        
        $out_of_stock = 0;
        $low_stock = 0;
        $good_stock = 0;
        $total_value = 0;
        
        foreach ($report_data as $item) {
            if ($item['stock_status'] == 'Out of Stock') $out_of_stock++;
            elseif ($item['stock_status'] == 'Low Stock') $low_stock++;
            else $good_stock++;
            $total_value += $item['total_quantity'] * $item['purchase_price'];
        }
        
        $summary = [
            'out_of_stock' => $out_of_stock,
            'low_stock' => $low_stock,
            'good_stock' => $good_stock,
            'total_value' => $total_value
        ];
        break;
        
    case 'inventory_value':
        $stmt = $conn->prepare("
            SELECT 
                p.product_id,
                p.product_code,
                p.product_name,
                c.category_name,
                s.store_name,
                si.batch_number,
                si.quantity,
                p.purchase_price,
                p.selling_price,
                (si.quantity * p.purchase_price) as cost_value,
                (si.quantity * p.selling_price) as retail_value,
                ((si.quantity * p.selling_price) - (si.quantity * p.purchase_price)) as potential_profit
            FROM store_inventory si
            JOIN products p ON si.product_id = p.product_id
            JOIN stores s ON si.store_id = s.store_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            WHERE p.status = 'active' AND si.quantity > 0 $where_store $where_category
            ORDER BY cost_value DESC
        ");
        $stmt->execute();
        $report_data = $stmt->fetchAll();
        
        $total_cost = 0;
        $total_retail = 0;
        $total_profit = 0;
        
        foreach ($report_data as $item) {
            $total_cost += $item['cost_value'];
            $total_retail += $item['retail_value'];
            $total_profit += $item['potential_profit'];
        }
        
        $summary = [
            'total_cost' => $total_cost,
            'total_retail' => $total_retail,
            'total_profit' => $total_profit,
            'profit_margin' => $total_retail > 0 ? ($total_profit / $total_retail * 100) : 0
        ];
        break;
        
    case 'expiry_tracking':
        $stmt = $conn->prepare("
            SELECT 
                p.product_code,
                p.product_name,
                c.category_name,
                s.store_name,
                si.batch_number,
                si.quantity,
                si.expiry_date,
                p.purchase_price,
                DATEDIFF(si.expiry_date, CURDATE()) as days_to_expiry,
                CASE 
                    WHEN si.expiry_date < CURDATE() THEN 'Expired'
                    WHEN DATEDIFF(si.expiry_date, CURDATE()) <= 30 THEN 'Expiring Soon'
                    WHEN DATEDIFF(si.expiry_date, CURDATE()) <= 90 THEN 'Warning'
                    ELSE 'Good'
                END as expiry_status
            FROM store_inventory si
            JOIN products p ON si.product_id = p.product_id
            JOIN stores s ON si.store_id = s.store_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            WHERE p.has_expiry = 'yes' 
            AND si.expiry_date IS NOT NULL
            AND si.quantity > 0
            $where_store $where_category
            ORDER BY si.expiry_date ASC
        ");
        $stmt->execute();
        $report_data = $stmt->fetchAll();
        
        $expired = 0;
        $expiring_soon = 0;
        $warning = 0;
        $expired_value = 0;
        
        foreach ($report_data as $item) {
            if ($item['expiry_status'] == 'Expired') {
                $expired++;
                $expired_value += $item['quantity'] * $item['purchase_price'];
            }
            elseif ($item['expiry_status'] == 'Expiring Soon') $expiring_soon++;
            elseif ($item['expiry_status'] == 'Warning') $warning++;
        }
        
        $summary = [
            'expired' => $expired,
            'expiring_soon' => $expiring_soon,
            'warning' => $warning,
            'expired_value' => $expired_value
        ];
        break;
        
    case 'store_comparison':
        $stmt = $conn->prepare("
            SELECT 
                s.store_name,
                s.store_code,
                COUNT(DISTINCT si.product_id) as unique_products,
                COALESCE(SUM(si.quantity), 0) as total_items,
                COALESCE(SUM(si.quantity * p.purchase_price), 0) as total_cost_value,
                COALESCE(SUM(si.quantity * p.selling_price), 0) as total_retail_value
            FROM stores s
            LEFT JOIN store_inventory si ON s.store_id = si.store_id AND si.quantity > 0
            LEFT JOIN products p ON si.product_id = p.product_id
            WHERE s.status = 'active' $where_category
            GROUP BY s.store_id
            ORDER BY total_cost_value DESC
        ");
        $stmt->execute();
        $report_data = $stmt->fetchAll();
        
        $total_stores = count($report_data);
        $total_products = 0;
        $total_items = 0;
        $total_value = 0;
        
        foreach ($report_data as $item) {
            $total_products += $item['unique_products'];
            $total_items += $item['total_items'];
            $total_value += $item['total_cost_value'];
        }
        
        $summary = [
            'total_stores' => $total_stores,
            'total_products' => $total_products,
            'total_items' => $total_items,
            'total_value' => $total_value
        ];
        break;
        
    case 'batch_tracking':
        $where_date = "";
        if ($start_date && $end_date) {
            $where_date = "AND si.created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'";
        }
        
        $stmt = $conn->prepare("
            SELECT 
                si.batch_number,
                p.product_code,
                p.product_name,
                c.category_name,
                s.store_name,
                si.quantity as current_quantity,
                si.initial_quantity,
                si.expiry_date,
                si.created_at as received_date,
                DATEDIFF(CURDATE(), si.created_at) as age_days,
                p.purchase_price,
                (si.quantity * p.purchase_price) as batch_value,
                CASE 
                    WHEN si.quantity = 0 THEN 'Depleted'
                    WHEN si.quantity <= 10 THEN 'Low'
                    ELSE 'Available'
                END as batch_status
            FROM store_inventory si
            JOIN products p ON si.product_id = p.product_id
            JOIN stores s ON si.store_id = s.store_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            WHERE 1=1 $where_store $where_category $where_date
            ORDER BY si.created_at DESC
        ");
        $stmt->execute();
        $report_data = $stmt->fetchAll();
        
        $total_batches = count($report_data);
        $active_batches = 0;
        $depleted_batches = 0;
        $total_batch_value = 0;
        
        foreach ($report_data as $item) {
            if ($item['current_quantity'] > 0) $active_batches++;
            else $depleted_batches++;
            $total_batch_value += $item['batch_value'];
        }
        
        $summary = [
            'total_batches' => $total_batches,
            'active_batches' => $active_batches,
            'depleted_batches' => $depleted_batches,
            'total_value' => $total_batch_value
        ];
        break;
        
    case 'stock_transfers':
        $where_date = "";
        if ($start_date && $end_date) {
            $where_date = "AND st.transfer_date BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'";
        }
        
        $where_from_store = "";
        if ($filter_store != 'all') {
            $where_from_store = "AND (st.from_store_id = " . intval($filter_store) . " OR st.to_store_id = " . intval($filter_store) . ")";
        }
        
        $stmt = $conn->prepare("
            SELECT 
                st.transfer_number,
                st.transfer_date,
                p.product_code,
                p.product_name,
                c.category_name,
                fs.store_name as from_store,
                ts.store_name as to_store,
                st.quantity,
                st.reason,
                u.full_name as transferred_by,
                st.status,
                p.purchase_price,
                (st.quantity * p.purchase_price) as transfer_value
            FROM stock_transfers st
            JOIN products p ON st.product_id = p.product_id
            JOIN stores fs ON st.from_store_id = fs.store_id
            JOIN stores ts ON st.to_store_id = ts.store_id
            JOIN users u ON st.transferred_by = u.user_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            WHERE 1=1 $where_date $where_from_store $where_category
            ORDER BY st.transfer_date DESC, st.created_at DESC
        ");
        $stmt->execute();
        $report_data = $stmt->fetchAll();
        
        $total_transfers = count($report_data);
        $completed_transfers = 0;
        $pending_transfers = 0;
        $total_transfer_value = 0;
        $total_quantity_transferred = 0;
        
        foreach ($report_data as $item) {
            if ($item['status'] == 'completed') $completed_transfers++;
            else $pending_transfers++;
            $total_transfer_value += $item['transfer_value'];
            $total_quantity_transferred += $item['quantity'];
        }
        
        $summary = [
            'total_transfers' => $total_transfers,
            'completed' => $completed_transfers,
            'pending' => $pending_transfers,
            'total_value' => $total_transfer_value,
            'total_quantity' => $total_quantity_transferred
        ];
        break;
}

$report_titles = [
    'stock_levels' => 'Stock Levels Report',
    'inventory_value' => 'Inventory Value Report',
    'batch_tracking' => 'Batch Tracking Report',
    'stock_transfers' => 'Stock Transfers Report',
    'expiry_tracking' => 'Expiry Tracking Report',
    'store_comparison' => 'Store Comparison Report'
];
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <button class="btn btn-link d-md-none sidebar-toggle">
            <i class="fas fa-bars"></i>
        </button>
        <h5 class="mb-0"><i class="fas fa-warehouse me-2"></i>Inventory Reports & Analytics</h5>
    </div>
    
    <div class="content-area">
        <!-- Report Type Selection & Filters -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-filter me-2"></i>Report Configuration
            </div>
            <div class="card-body">
                <form method="GET" id="report-form">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label"><strong>Report Type</strong></label>
                            <select name="report" class="form-select" onchange="toggleDateFilters()">
                                <option value="stock_levels" <?php echo $report_type == 'stock_levels' ? 'selected' : ''; ?>>📊 Stock Levels</option>
                                <option value="inventory_value" <?php echo $report_type == 'inventory_value' ? 'selected' : ''; ?>>💰 Inventory Value</option>
                                <option value="batch_tracking" <?php echo $report_type == 'batch_tracking' ? 'selected' : ''; ?>>📦 Batch Tracking</option>
                                <option value="stock_transfers" <?php echo $report_type == 'stock_transfers' ? 'selected' : ''; ?>>🔄 Stock Transfers</option>
                                <option value="expiry_tracking" <?php echo $report_type == 'expiry_tracking' ? 'selected' : ''; ?>>⏰ Expiry Tracking</option>
                                <option value="store_comparison" <?php echo $report_type == 'store_comparison' ? 'selected' : ''; ?>>🏪 Store Comparison</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2 mb-3">
                            <label class="form-label"><strong>Store/Branch</strong></label>
                            <select name="store" class="form-select">
                                <option value="all">All Stores</option>
                                <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['store_id']; ?>" <?php echo $filter_store == $store['store_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store['store_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2 mb-3">
                            <label class="form-label"><strong>Category</strong></label>
                            <select name="category" class="form-select">
                                <option value="all">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['category_id']; ?>" <?php echo $filter_category == $category['category_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2 mb-3" id="start-date-field" style="<?php echo in_array($report_type, ['batch_tracking', 'stock_transfers']) ? '' : 'display:none;'; ?>">
                            <label class="form-label"><strong>Start Date</strong></label>
                            <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                        </div>
                        
                        <div class="col-md-2 mb-3" id="end-date-field" style="<?php echo in_array($report_type, ['batch_tracking', 'stock_transfers']) ? '' : 'display:none;'; ?>">
                            <label class="form-label"><strong>End Date</strong></label>
                            <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                        </div>
                        
                        <div class="col-md-1 mb-3">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Summary Cards -->
        <?php if ($report_type == 'stock_levels'): ?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-danger text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Out of Stock</h6>
                        <h3 class="mb-0"><?php echo $summary['out_of_stock']; ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-exclamation-triangle"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Low Stock</h6>
                        <h3 class="mb-0"><?php echo $summary['low_stock']; ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-exclamation-circle"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Good Stock</h6>
                        <h3 class="mb-0"><?php echo $summary['good_stock']; ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Total Value</h6>
                        <h3 class="mb-0">UGX <?php echo number_format($summary['total_value'], 0); ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-money-bill-wave"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($report_type == 'inventory_value'): ?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Cost Value</h6>
                        <h3 class="mb-0">UGX <?php echo number_format($summary['total_cost'], 0); ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-dollar-sign"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Retail Value</h6>
                        <h3 class="mb-0">UGX <?php echo number_format($summary['total_retail'], 0); ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-tag"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Potential Profit</h6>
                        <h3 class="mb-0">UGX <?php echo number_format($summary['total_profit'], 0); ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-chart-line"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Profit Margin</h6>
                        <h3 class="mb-0"><?php echo number_format($summary['profit_margin'], 1); ?>%</h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-percent"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($report_type == 'batch_tracking'): ?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Total Batches</h6>
                        <h3 class="mb-0"><?php echo $summary['total_batches']; ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-boxes"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Active Batches</h6>
                        <h3 class="mb-0"><?php echo $summary['active_batches']; ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-danger text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Depleted</h6>
                        <h3 class="mb-0"><?php echo $summary['depleted_batches']; ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-times-circle"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Total Value</h6>
                        <h3 class="mb-0">UGX <?php echo number_format($summary['total_value'], 0); ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-dollar-sign"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($report_type == 'stock_transfers'): ?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Total Transfers</h6>
                        <h3 class="mb-0"><?php echo $summary['total_transfers']; ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-exchange-alt"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Completed</h6>
                        <h3 class="mb-0"><?php echo $summary['completed']; ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-check"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Items Transferred</h6>
                        <h3 class="mb-0"><?php echo number_format($summary['total_quantity'], 0); ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-dolly"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Transfer Value</h6>
                        <h3 class="mb-0">UGX <?php echo number_format($summary['total_value'], 0); ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-money-bill"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($report_type == 'expiry_tracking'): ?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-danger text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Expired</h6>
                        <h3 class="mb-0"><?php echo $summary['expired']; ?></h3>
                        <small>Loss: UGX <?php echo number_format($summary['expired_value'], 0); ?></small>
                    </div>
                    <div class="fs-1"><i class="fas fa-times-circle"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Expiring Soon</h6>
                        <h3 class="mb-0"><?php echo $summary['expiring_soon']; ?></h3>
                        <small>Within 30 days</small>
                    </div>
                    <div class="fs-1"><i class="fas fa-clock"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Warning</h6>
                        <h3 class="mb-0"><?php echo $summary['warning']; ?></h3>
                        <small>Within 90 days</small>
                    </div>
                    <div class="fs-1"><i class="fas fa-exclamation"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Good</h6>
                        <h3 class="mb-0"><?php echo count($report_data) - $summary['expired'] - $summary['expiring_soon'] - $summary['warning']; ?></h3>
                        <small>Over 90 days</small>
                    </div>
                    <div class="fs-1"><i class="fas fa-check"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($report_type == 'store_comparison'): ?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Total Stores</h6>
                        <h3 class="mb-0"><?php echo $summary['total_stores']; ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-store"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Total Products</h6>
                        <h3 class="mb-0"><?php echo $summary['total_products']; ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-box"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Total Items</h6>
                        <h3 class="mb-0"><?php echo number_format($summary['total_items'], 0); ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-cubes"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Total Value</h6>
                        <h3 class="mb-0">UGX <?php echo number_format($summary['total_value'], 0); ?></h3>
                    </div>
                    <div class="fs-1"><i class="fas fa-dollar-sign"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
        
        <!-- Report Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-table me-2"></i>
                    <?php echo $report_titles[$report_type]; ?>
                </span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                   <?php if ($report_type == 'stock_levels'): ?>
<table class="table export-table table-hover">
    <thead>
        <tr>
            <th>Product Code</th>
            <th>Product Name</th>
            <th>Category</th>
            <th>Store</th>
            <th>Quantity</th>
            <th>Reorder Level</th>
            <th>Unit Cost</th>
            <th>Total Value</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($report_data)): ?>
            <tr><td colspan="9" class="text-center text-muted">No data available</td></tr>
        <?php else: ?>
            <?php foreach ($report_data as $item): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($item['product_code']); ?></strong></td>
                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                <td><?php echo htmlspecialchars($item['category_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($item['store_name'] ?? 'N/A'); ?></td>
                <td><strong><?php echo number_format($item['total_quantity']); ?></strong></td>
                <td><?php echo number_format($item['reorder_level']); ?></td>
                <td>UGX <?php echo number_format($item['purchase_price'], 0); ?></td>
                <td>UGX <?php echo number_format($item['total_quantity'] * $item['purchase_price'], 0); ?></td>
                <td>
                    <?php if ($item['stock_status'] == 'Out of Stock'): ?>
                        <span class="badge bg-danger">Out of Stock</span>
                    <?php elseif ($item['stock_status'] == 'Low Stock'): ?>
                        <span class="badge bg-warning">Low Stock</span>
                    <?php elseif ($item['stock_status'] == 'Medium Stock'): ?>
                        <span class="badge bg-info">Medium Stock</span>
                    <?php else: ?>
                        <span class="badge bg-success">Good Stock</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php elseif ($report_type == 'inventory_value'): ?>
<table class="table export-table table-hover">
    <thead>
        <tr>
            <th>Product Code</th>
            <th>Product Name</th>
            <th>Category</th>
            <th>Store</th>
            <th>Batch</th>
            <th>Quantity</th>
            <th>Unit Cost</th>
            <th>Cost Value</th>
            <th>Unit Price</th>
            <th>Retail Value</th>
            <th>Potential Profit</th>
            <th>Margin %</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($report_data)): ?>
            <tr><td colspan="12" class="text-center text-muted">No data available</td></tr>
        <?php else: ?>
            <?php foreach ($report_data as $item): 
                $margin = $item['retail_value'] > 0 ? (($item['potential_profit'] / $item['retail_value']) * 100) : 0;
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($item['product_code']); ?></strong></td>
                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                <td><?php echo htmlspecialchars($item['category_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($item['store_name']); ?></td>
                <td><?php echo htmlspecialchars($item['batch_number']); ?></td>
                <td><?php echo number_format($item['quantity']); ?></td>
                <td>UGX <?php echo number_format($item['purchase_price'], 0); ?></td>
                <td>UGX <?php echo number_format($item['cost_value'], 0); ?></td>
                <td>UGX <?php echo number_format($item['selling_price'], 0); ?></td>
                <td>UGX <?php echo number_format($item['retail_value'], 0); ?></td>
                <td class="text-success"><strong>UGX <?php echo number_format($item['potential_profit'], 0); ?></strong></td>
                <td>
                    <span class="badge <?php echo $margin >= 30 ? 'bg-success' : ($margin >= 15 ? 'bg-warning' : 'bg-danger'); ?>">
                        <?php echo number_format($margin, 1); ?>%
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php elseif ($report_type == 'batch_tracking'): ?>
<table class="table export-table table-hover">
    <thead>
        <tr>
            <th>Batch Number</th>
            <th>Product Code</th>
            <th>Product Name</th>
            <th>Category</th>
            <th>Store</th>
            <th>Initial Qty</th>
            <th>Current Qty</th>
            <th>Sold</th>
            <th>Received Date</th>
            <th>Age (Days)</th>
            <th>Expiry Date</th>
            <th>Batch Value</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($report_data)): ?>
            <tr><td colspan="13" class="text-center text-muted">No data available</td></tr>
        <?php else: ?>
            <?php foreach ($report_data as $item): 
                $sold = ($item['initial_quantity'] ?? $item['current_quantity']) - $item['current_quantity'];
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($item['batch_number']); ?></strong></td>
                <td><?php echo htmlspecialchars($item['product_code']); ?></td>
                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                <td><?php echo htmlspecialchars($item['category_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($item['store_name']); ?></td>
                <td><span class="badge bg-primary"><?php echo number_format($item['initial_quantity'] ?? $item['current_quantity']); ?></span></td>
                <td><span class="badge bg-success"><?php echo number_format($item['current_quantity']); ?></span></td>
                <td><span class="badge bg-info"><?php echo number_format($sold); ?></span></td>
                <td><?php echo date('d/m/Y', strtotime($item['received_date'])); ?></td>
                <td><?php echo $item['age_days']; ?> days</td>
                <td><?php echo $item['expiry_date'] ? date('d/m/Y', strtotime($item['expiry_date'])) : 'N/A'; ?></td>
                <td>UGX <?php echo number_format($item['batch_value'], 0); ?></td>
                <td>
                    <?php if ($item['batch_status'] == 'Depleted'): ?>
                        <span class="badge bg-danger">Depleted</span>
                    <?php elseif ($item['batch_status'] == 'Low'): ?>
                        <span class="badge bg-warning">Low</span>
                    <?php else: ?>
                        <span class="badge bg-success">Available</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php elseif ($report_type == 'stock_transfers'): ?>
<table class="table export-table table-hover">
    <thead>
        <tr>
            <th>Transfer #</th>
            <th>Date</th>
            <th>Product Code</th>
            <th>Product Name</th>
            <th>Category</th>
            <th>From Store</th>
            <th>To Store</th>
            <th>Quantity</th>
            <th>Unit Cost</th>
            <th>Transfer Value</th>
            <th>Reason</th>
            <th>Transferred By</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($report_data)): ?>
            <tr><td colspan="13" class="text-center text-muted">No transfers found</td></tr>
        <?php else: ?>
            <?php foreach ($report_data as $item): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($item['transfer_number']); ?></strong></td>
                <td><?php echo date('d/m/Y H:i', strtotime($item['transfer_date'])); ?></td>
                <td><?php echo htmlspecialchars($item['product_code']); ?></td>
                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                <td><?php echo htmlspecialchars($item['category_name'] ?? 'N/A'); ?></td>
                <td><span class="badge bg-danger"><?php echo htmlspecialchars($item['from_store']); ?></span></td>
                <td><span class="badge bg-success"><?php echo htmlspecialchars($item['to_store']); ?></span></td>
                <td><strong><?php echo number_format($item['quantity']); ?></strong></td>
                <td>UGX <?php echo number_format($item['purchase_price'], 0); ?></td>
                <td>UGX <?php echo number_format($item['transfer_value'], 0); ?></td>
                <td><?php echo htmlspecialchars($item['reason'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($item['transferred_by']); ?></td>
                <td>
                    <?php if ($item['status'] == 'completed'): ?>
                        <span class="badge bg-success">Completed</span>
                    <?php else: ?>
                        <span class="badge bg-warning">Pending</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php elseif ($report_type == 'expiry_tracking'): ?>
<table class="table export-table table-hover">
    <thead>
        <tr>
            <th>Product Code</th>
            <th>Product Name</th>
            <th>Category</th>
            <th>Store</th>
            <th>Batch Number</th>
            <th>Quantity</th>
            <th>Unit Cost</th>
            <th>Total Value</th>
            <th>Expiry Date</th>
            <th>Days to Expiry</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($report_data)): ?>
            <tr><td colspan="11" class="text-center text-muted">No expiring products found</td></tr>
        <?php else: ?>
            <?php foreach ($report_data as $item): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($item['product_code']); ?></strong></td>
                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                <td><?php echo htmlspecialchars($item['category_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($item['store_name']); ?></td>
                <td><?php echo htmlspecialchars($item['batch_number']); ?></td>
                <td><?php echo number_format($item['quantity']); ?></td>
                <td>UGX <?php echo number_format($item['purchase_price'], 0); ?></td>
                <td>UGX <?php echo number_format($item['quantity'] * $item['purchase_price'], 0); ?></td>
                <td><?php echo date('d/m/Y', strtotime($item['expiry_date'])); ?></td>
                <td>
                    <?php 
                    $days = $item['days_to_expiry'];
                    if ($days < 0) {
                        echo '<span class="text-danger">' . abs($days) . ' days ago</span>';
                    } else {
                        echo $days . ' days';
                    }
                    ?>
                </td>
                <td>
                    <?php if ($item['expiry_status'] == 'Expired'): ?>
                        <span class="badge bg-danger">Expired</span>
                    <?php elseif ($item['expiry_status'] == 'Expiring Soon'): ?>
                        <span class="badge bg-warning">Expiring Soon</span>
                    <?php elseif ($item['expiry_status'] == 'Warning'): ?>
                        <span class="badge bg-info">Warning</span>
                    <?php else: ?>
                        <span class="badge bg-success">Good</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php elseif ($report_type == 'store_comparison'): ?>
<table class="table export-table table-hover">
    <thead>
        <tr>
            <th>Store Code</th>
            <th>Store Name</th>
            <th>Unique Products</th>
            <th>Total Items</th>
            <th>Cost Value</th>
            <th>Retail Value</th>
            <th>Potential Profit</th>
            <th>Profit Margin %</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($report_data)): ?>
            <tr><td colspan="8" class="text-center text-muted">No data available</td></tr>
        <?php else: ?>
            <?php foreach ($report_data as $item): 
                $profit = $item['total_retail_value'] - $item['total_cost_value'];
                $margin = $item['total_retail_value'] > 0 ? (($profit / $item['total_retail_value']) * 100) : 0;
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($item['store_code']); ?></strong></td>
                <td><?php echo htmlspecialchars($item['store_name']); ?></td>
                <td><?php echo number_format($item['unique_products']); ?></td>
                <td><?php echo number_format($item['total_items']); ?></td>
                <td>UGX <?php echo number_format($item['total_cost_value'], 0); ?></td>
                <td>UGX <?php echo number_format($item['total_retail_value'], 0); ?></td>
                <td class="text-success"><strong>UGX <?php echo number_format($profit, 0); ?></strong></td>
                <td>
                    <span class="badge <?php echo $margin >= 30 ? 'bg-success' : ($margin >= 15 ? 'bg-warning' : 'bg-danger'); ?>">
                        <?php echo number_format($margin, 1); ?>%
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
<?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<!-- DataTables Buttons CSS & JS -->
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
function toggleDateFilters() {
    const reportType = document.querySelector('select[name="report"]').value;
    const needsDate = ['batch_tracking', 'stock_transfers'].includes(reportType);
    
    document.getElementById('start-date-field').style.display = needsDate ? '' : 'none';
    document.getElementById('end-date-field').style.display = needsDate ? '' : 'none';
}

// Print styles
const style = document.createElement('style');
style.innerHTML = `
    @media print {
        .top-navbar, .sidebar, .card-header button, .no-print, form, .btn { display: none !important; }
        .main-content { margin: 0 !important; padding: 20px !important; }
        .card { border: 1px solid #ddd !important; box-shadow: none !important; page-break-inside: avoid; }
        table { page-break-inside: auto; font-size: 10px; }
        tr { page-break-inside: avoid; page-break-after: auto; }
        thead { display: table-header-group; }
    }
`;
document.head.appendChild(style);

// Initialize DataTables safely
jQuery(document).ready(function($) {
    setTimeout(function() {
        if ($('.export-table').length) {
            
            // Destroy any existing init to prevent conflicts
            $('.export-table').each(function() {
                if ($.fn.DataTable.isDataTable(this)) {
                    $(this).DataTable().destroy();
                }
            });

            $('.export-table').DataTable({
                dom: 'Bfrtip',
                buttons: [
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm',
                        title: '<?php echo addslashes($report_titles[$report_type]); ?> - <?php echo date("d M Y"); ?>',
                        exportOptions: { columns: ':visible' }
                    },
                    {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-danger btn-sm',
                        title: '<?php echo addslashes($report_titles[$report_type]); ?>',
                        orientation: 'landscape',
                        exportOptions: { columns: ':visible' }
                    },
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-file-csv"></i> CSV',
                        className: 'btn btn-info btn-sm',
                        exportOptions: { columns: ':visible' }
                    },
                    {
                        extend: 'print',
                        text: '<i class="fas fa-print"></i> Print',
                        className: 'btn btn-secondary btn-sm',
                        exportOptions: { columns: ':visible' }
                    }
                ],
                pageLength: 25,
                order: []
            });
        }
    }, 300);
});
</script>