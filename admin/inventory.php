<?php
require_once '../config/config.php';
require_admin();

$page_title = "Store Inventory";

// ============================================
// AUTO-FIX: Ensure required columns exist
// ============================================
try {
    $check = $conn->query("SHOW COLUMNS FROM store_inventory LIKE 'initial_quantity'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE store_inventory ADD COLUMN initial_quantity INT DEFAULT 0");
        $conn->exec("UPDATE store_inventory SET initial_quantity = quantity WHERE initial_quantity = 0");
    }
    $check = $conn->query("SHOW COLUMNS FROM store_inventory LIKE 'last_updated'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE store_inventory ADD COLUMN last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }
} catch(PDOException $e) {}

// ============================================
// HANDLE POST ACTIONS
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] == 'add_stock') {
            $store_id = intval($_POST['store_id']);
            $product_id = intval($_POST['product_id']);
            $quantity = intval($_POST['quantity']);
            $batch_number = sanitize_input($_POST['batch_number']);
            $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

            $stmt = $conn->prepare("SELECT inventory_id, quantity, COALESCE(initial_quantity, quantity) as initial_quantity FROM store_inventory WHERE store_id = ? AND product_id = ? AND batch_number = ?");
            $stmt->execute([$store_id, $product_id, $batch_number]);
            $existing = $stmt->fetch();

            if ($existing) {
                $new_quantity = $existing['quantity'] + $quantity;
                $new_initial = $existing['initial_quantity'] + $quantity;
                $stmt = $conn->prepare("UPDATE store_inventory SET quantity = ?, initial_quantity = ? WHERE inventory_id = ?");
                $stmt->execute([$new_quantity, $new_initial, $existing['inventory_id']]);
                set_alert('success', "Stock updated for existing batch");
            } else {
                $stmt = $conn->prepare("INSERT INTO store_inventory (store_id, product_id, batch_number, quantity, initial_quantity, expiry_date) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$store_id, $product_id, $batch_number, $quantity, $quantity, $expiry_date]);
                set_alert('success', "New stock batch added");
            }

            log_activity($_SESSION['user_id'], 'STOCK_ADDED', "Added $quantity units to inventory", $conn);
            header('Location: inventory.php');
            exit();

        } elseif ($_POST['action'] == 'delete') {
            $inventory_id = intval($_POST['inventory_id']);
            $stmt = $conn->prepare("DELETE FROM store_inventory WHERE inventory_id = ?");
            $stmt->execute([$inventory_id]);
            log_activity($_SESSION['user_id'], 'INVENTORY_DELETED', "Deleted inventory ID: $inventory_id", $conn);
            set_alert('success', 'Inventory record deleted');
            header('Location: inventory.php');
            exit();

        } elseif ($_POST['action'] == 'restore_batch') {
            $inventory_id = intval($_POST['inventory_id']);
            $quantity = intval($_POST['quantity']);
            $stmt = $conn->prepare("UPDATE store_inventory SET quantity = ?, initial_quantity = ? WHERE inventory_id = ?");
            $stmt->execute([$quantity, $quantity, $inventory_id]);
            log_activity($_SESSION['user_id'], 'BATCH_RESTORED', "Restored batch ID: $inventory_id", $conn);
            set_alert('success', 'Batch restored successfully');
            header('Location: inventory.php');
            exit();
        }
    } catch(Exception $e) {
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
}

// ============================================
// FETCH DATA
// ============================================
$inventory = [];
$finished_batches = [];
$products = [];
$stores = [];
$transfers = [];

// Active inventory
try {
    $stmt = $conn->prepare("
        SELECT 
            si.*,
            p.product_name,
            p.product_code,
            s.store_name,
            s.store_code,
            GREATEST(si.quantity, 0) as display_quantity
        FROM store_inventory si
        JOIN products p ON si.product_id = p.product_id
        JOIN stores s ON si.store_id = s.store_id
        WHERE si.quantity > 0
        ORDER BY si.last_updated DESC
    ");
    $stmt->execute();
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    try {
        $stmt = $conn->prepare("
            SELECT si.*, p.product_name, p.product_code, s.store_name, s.store_code,
                   GREATEST(si.quantity, 0) as display_quantity, NOW() as last_updated
            FROM store_inventory si
            JOIN products p ON si.product_id = p.product_id
            JOIN stores s ON si.store_id = s.store_id
            WHERE si.quantity > 0
            ORDER BY si.inventory_id DESC
        ");
        $stmt->execute();
        $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e2) { $inventory = []; }
}

// Finished batches
try {
    $stmt = $conn->prepare("
        SELECT si.*, p.product_name, p.product_code, s.store_name, s.store_code
        FROM store_inventory si
        JOIN products p ON si.product_id = p.product_id
        JOIN stores s ON si.store_id = s.store_id
        WHERE si.quantity <= 0
        ORDER BY si.inventory_id DESC
    ");
    $stmt->execute();
    $finished_batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) { $finished_batches = []; }

// Products dropdown
try {
    $stmt = $conn->prepare("SELECT product_id, product_code, product_name, has_expiry FROM products WHERE status = 'active' ORDER BY product_name");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) { $products = []; }

// Stores dropdown
try {
    $stmt = $conn->prepare("SELECT store_id, store_code, store_name FROM stores WHERE status = 'active' ORDER BY store_name");
    $stmt->execute();
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) { $stores = []; }

// Transfers
try {
    $stmt = $conn->prepare("
        SELECT st.*, p.product_name, p.product_code,
               fs.store_name as from_store, ts.store_name as to_store,
               u.full_name as transferred_by_name
        FROM stock_transfers st
        JOIN products p ON st.product_id = p.product_id
        JOIN stores fs ON st.from_store_id = fs.store_id
        JOIN stores ts ON st.to_store_id = ts.store_id
        JOIN users u ON st.transferred_by = u.user_id
        ORDER BY st.transfer_date DESC
        LIMIT 50
    ");
    $stmt->execute();
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) { $transfers = []; }

// Stats
$total_active_stock = 0;
try {
    $total_active_stock = $conn->query("SELECT COALESCE(SUM(quantity), 0) FROM store_inventory WHERE quantity > 0")->fetchColumn();
} catch(PDOException $e) {}

$stats = [
    'active_batches' => count($inventory),
    'finished_batches' => count($finished_batches),
    'total_active_stock' => $total_active_stock,
];
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
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
                    <i class="fas fa-warehouse me-2" style="color: var(--cyber-orange);"></i>
                    Store Inventory
                </h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addStockModal">
                    <i class="fas fa-plus me-1"></i>Add Stock
                </button>
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
                    <div class="icon"><i class="fas fa-boxes"></i></div>
                    <div>
                        <h3><?php echo number_format($stats['active_batches']); ?></h3>
                        <p>Active Batches</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card cyan">
                    <div class="icon"><i class="fas fa-cubes"></i></div>
                    <div>
                        <h3><?php echo number_format($stats['total_active_stock']); ?></h3>
                        <p>Total Units</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card orange">
                    <div class="icon"><i class="fas fa-box-open"></i></div>
                    <div>
                        <h3><?php echo number_format($stats['finished_batches']); ?></h3>
                        <p>Finished Batches</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card green">
                    <div class="icon"><i class="fas fa-plus-circle"></i></div>
                    <div>
                        <button class="btn btn-success btn-sm mt-1" data-bs-toggle="modal" data-bs-target="#addStockModal">
                            <i class="fas fa-plus me-1"></i>Add New Stock
                        </button>
                        <p class="mt-2">Quick action</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3" role="tablist" style="border-bottom: 2px solid var(--cyber-border);">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#inventory-tab" style="color: var(--cyber-purple); font-weight: 600;">
                    <i class="fas fa-boxes me-1"></i>Active Stock 
                    <span class="badge bg-success ms-1"><?php echo $stats['active_batches']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#finished-tab" style="color: var(--cyber-purple); font-weight: 600;">
                    <i class="fas fa-box-open me-1"></i>Finished 
                    <span class="badge bg-secondary ms-1"><?php echo $stats['finished_batches']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#transfers-tab" style="color: var(--cyber-purple); font-weight: 600;">
                    <i class="fas fa-exchange-alt me-1"></i>Transfers
                    <span class="badge bg-info ms-1"><?php echo count($transfers); ?></span>
                </a>
            </li>
        </ul>
        
        <div class="tab-content">
            <!-- ============================================
                 TAB 1: ACTIVE INVENTORY (9 columns)
                 ============================================ -->
            <div id="inventory-tab" class="tab-pane fade show active">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-list me-2"></i>Active Stock Levels
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="inventoryTable">
                                <thead>
                                    <tr>
                                        <th>Store</th>
                                        <th>Product Code</th>
                                        <th>Product Name</th>
                                        <th>Batch</th>
                                        <th>Initial</th>
                                        <th>Sold</th>
                                        <th>Stock</th>
                                        <th>Expiry</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($inventory)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">
                                                <i class="fas fa-boxes fa-3x mb-3" style="opacity: 0.3;"></i>
                                                <p>No active inventory found</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($inventory as $inv): ?>
                                        <?php 
                                        $current_qty = max(0, $inv['quantity']);
                                        $initial_qty = $inv['initial_quantity'] ?? $current_qty;
                                        $sold_qty = max(0, $initial_qty - $current_qty);
                                        ?>
                                        <tr>
                                            <td><span class="badge bg-primary"><?php echo htmlspecialchars($inv['store_name']); ?></span></td>
                                            <td><code><?php echo htmlspecialchars($inv['product_code']); ?></code></td>
                                            <td><strong><?php echo htmlspecialchars($inv['product_name']); ?></strong></td>
                                            <td><code><?php echo htmlspecialchars($inv['batch_number']); ?></code></td>
                                            <td><span class="badge bg-info"><?php echo number_format($initial_qty); ?></span></td>
                                            <td><span class="badge bg-danger"><?php echo number_format($sold_qty); ?></span></td>
                                            <td>
                                                <?php if ($current_qty > 20): ?>
                                                    <span class="badge bg-success"><?php echo number_format($current_qty); ?></span>
                                                <?php elseif ($current_qty > 0): ?>
                                                    <span class="badge bg-warning"><?php echo number_format($current_qty); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($inv['expiry_date'])): ?>
                                                    <?php
                                                    $expiry = strtotime($inv['expiry_date']);
                                                    $today = time();
                                                    $days_to_expiry = ($expiry - $today) / (60 * 60 * 24);
                                                    ?>
                                                    <?php if ($days_to_expiry < 0): ?>
                                                        <span class="text-danger"><?php echo date('d/m/Y', $expiry); ?><br><span class="badge bg-danger">EXPIRED</span></span>
                                                    <?php elseif ($days_to_expiry <= 30): ?>
                                                        <span class="text-warning"><?php echo date('d/m/Y', $expiry); ?><br><span class="badge bg-warning">Soon</span></span>
                                                    <?php else: ?>
                                                        <?php echo date('d/m/Y', $expiry); ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <small class="text-muted">N/A</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this record?');">
                                                    <input type="hidden" name="inventory_id" value="<?php echo $inv['inventory_id']; ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ============================================
                 TAB 2: FINISHED BATCHES (6 columns - MATCHED)
                 ============================================ -->
            <div id="finished-tab" class="tab-pane fade">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-box-open me-2"></i>Finished Batches
                    </div>
                    <div class="card-body">
                        <?php if (empty($finished_batches)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>No finished batches!</strong> All stock batches are active.
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="finishedTable">
                                <thead>
                                    <tr>
                                        <th>Store</th>
                                        <th>Product Code</th>
                                        <th>Product Name</th>
                                        <th>Batch</th>
                                        <th>Initial Qty</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($finished_batches as $batch): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($batch['store_name']); ?></td>
                                        <td><?php echo htmlspecialchars($batch['product_code']); ?></td>
                                        <td><?php echo htmlspecialchars($batch['product_name']); ?></td>
                                        <td><code><?php echo htmlspecialchars($batch['batch_number']); ?></code></td>
                                        <td><span class="badge bg-info"><?php echo number_format($batch['initial_quantity'] ?? 0); ?></span></td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="inventory_id" value="<?php echo $batch['inventory_id']; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this batch?');">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
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
            
            <!-- ============================================
                 TAB 3: TRANSFERS (7 columns - MATCHED)
                 ============================================ -->
            <div id="transfers-tab" class="tab-pane fade">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-history me-2"></i>Transfer History
                    </div>
                    <div class="card-body">
                        <?php if (empty($transfers)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            No transfer history found.
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="transfersTable">
                                <thead>
                                    <tr>
                                        <th>Transfer #</th>
                                        <th>Date</th>
                                        <th>Product</th>
                                        <th>From</th>
                                        <th>To</th>
                                        <th>Qty</th>
                                        <th>By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transfers as $transfer): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($transfer['transfer_number']); ?></strong></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($transfer['transfer_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($transfer['product_code']); ?></td>
                                        <td><span class="badge bg-danger"><?php echo htmlspecialchars($transfer['from_store']); ?></span></td>
                                        <td><span class="badge bg-success"><?php echo htmlspecialchars($transfer['to_store']); ?></span></td>
                                        <td><strong><?php echo $transfer['quantity']; ?></strong></td>
                                        <td><?php echo htmlspecialchars($transfer['transferred_by_name']); ?></td>
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

<!-- Add Stock Modal -->
<div class="modal fade" id="addStockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Stock</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_stock">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Store *</label>
                        <select name="store_id" class="form-select" required>
                            <option value="">Select Store</option>
                            <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['store_id']; ?>">
                                <?php echo htmlspecialchars($store['store_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Product *</label>
                        <select name="product_id" id="add_product_id" class="form-select" required>
                            <option value="">Select Product</option>
                            <?php foreach ($products as $product): ?>
                            <option value="<?php echo $product['product_id']; ?>" data-expiry="<?php echo $product['has_expiry']; ?>">
                                <?php echo htmlspecialchars($product['product_code']); ?> - <?php echo htmlspecialchars($product['product_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Quantity *</label>
                        <input type="number" name="quantity" class="form-control" min="1" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Batch Number *</label>
                        <input type="text" name="batch_number" class="form-control" value="BATCH<?php echo date('Ymd') . rand(100, 999); ?>" required>
                    </div>
                    
                    <div class="mb-3" id="expiry_date_field" style="display:none;">
                        <label class="form-label">Expiry Date</label>
                        <input type="date" name="expiry_date" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Add Stock
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Product expiry toggle
    const productSelect = document.getElementById('add_product_id');
    const expiryField = document.getElementById('expiry_date_field');
    
    if (productSelect) {
        productSelect.addEventListener('change', function() {
            const hasExpiry = this.options[this.selectedIndex].getAttribute('data-expiry');
            expiryField.style.display = (hasExpiry === 'yes') ? 'block' : 'none';
        });
    }
    
    // ============================================
    // INITIALIZE DATATABLES PER TABLE (SAFE)
    // ============================================
    // Only initialize tables that have data rows (not just the empty message)
    
    function initDataTable(selector) {
        const table = document.querySelector(selector);
        if (!table) return;
        
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        
        // Skip if only empty-state row exists
        const rows = tbody.querySelectorAll('tr');
        if (rows.length === 1) {
            const onlyRow = rows[0];
            const cells = onlyRow.querySelectorAll('td');
            // If it's a single td with colspan, it's an empty message
            if (cells.length === 1 && cells[0].hasAttribute('colspan')) {
                return;
            }
        }
        
        // Count headers and first row cells to verify match
        const headerCount = table.querySelectorAll('thead th').length;
        const firstRowCells = rows[0] ? rows[0].querySelectorAll('td').length : 0;
        
        if (headerCount !== firstRowCells) {
            console.warn('Skipping DataTable init for ' + selector + ' - column mismatch: ' + headerCount + ' headers vs ' + firstRowCells + ' cells');
            return;
        }
        
        // Initialize DataTable
        if (typeof jQuery !== 'undefined' && jQuery.fn.DataTable) {
            jQuery(selector).DataTable({
                pageLength: 25,
                responsive: false,
                order: [],
                language: {
                    search: "",
                    searchPlaceholder: "Search...",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
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
    
    // Wait a moment for DataTables to load
    setTimeout(function() {
        initDataTable('#inventoryTable');
        initDataTable('#finishedTable');
        initDataTable('#transfersTable');
    }, 200);
});
</script>

<?php include '../includes/footer.php'; ?>