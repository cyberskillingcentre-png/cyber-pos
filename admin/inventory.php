<?php
require_once '../config/config.php';
require_admin();

$page_title = "Store Inventory";

// ============================================
// AUTO-MIGRATE
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
} catch (PDOException $e) {}

// ============================================
// POST ACTIONS
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    csrf_require();
    try {

        // ----------------------------------------
        // ADD STOCK
        // ----------------------------------------
        if ($_POST['action'] == 'add_stock') {
            $store_id     = intval($_POST['store_id']);
            $product_id   = intval($_POST['product_id']);
            $quantity     = intval($_POST['quantity']);
            $batch_number = sanitize_input($_POST['batch_number']);
            $expiry_date  = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            $mode         = $_POST['stock_mode'] ?? 'existing';
            $target_id    = intval($_POST['target_inventory_id'] ?? 0);

            if ($quantity <= 0) {
                throw new Exception('Quantity must be greater than zero.');
            }

            if ($mode === 'existing' && $target_id > 0) {
                $stmt = $conn->prepare("SELECT * FROM store_inventory WHERE inventory_id = ?");
                $stmt->execute([$target_id]);
                $existing = $stmt->fetch();

                if (!$existing) {
                    throw new Exception('Target batch not found.');
                }

                $new_quantity = $existing['quantity'] + $quantity;
                $new_initial  = ($existing['initial_quantity'] ?? 0) + $quantity;

                $stmt = $conn->prepare("
                    UPDATE store_inventory 
                    SET quantity = ?,
                        initial_quantity = ?,
                        expiry_date = COALESCE(?, expiry_date),
                        last_updated = NOW()
                    WHERE inventory_id = ?
                ");
                $stmt->execute([$new_quantity, $new_initial, $expiry_date, $target_id]);

                log_activity($_SESSION['user_id'], 'STOCK_ADDED',
                    "Added $quantity unit(s) to existing batch #{$existing['batch_number']}", $conn);

                set_alert('success', "Added {$quantity} unit(s) to existing batch {$existing['batch_number']}.");
            } else {
                if ($batch_number === '') {
                    throw new Exception('Batch number is required for a new batch.');
                }

                $stmt = $conn->prepare("
                    SELECT inventory_id, quantity FROM store_inventory
                    WHERE store_id = ? AND product_id = ? AND batch_number = ?
                ");
                $stmt->execute([$store_id, $product_id, $batch_number]);
                $dupe = $stmt->fetch();

                if ($dupe) {
                    throw new Exception('A batch with this number already exists for this product in this store.');
                }

                $stmt = $conn->prepare("
                    INSERT INTO store_inventory 
                    (store_id, product_id, batch_number, quantity, initial_quantity, expiry_date)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$store_id, $product_id, $batch_number, $quantity, $quantity, $expiry_date]);

                log_activity($_SESSION['user_id'], 'STOCK_ADDED',
                    "Created new batch {$batch_number} with $quantity unit(s)", $conn);

                set_alert('success', "New batch {$batch_number} created with {$quantity} unit(s).");
            }

            header('Location: inventory.php');
            exit();
        }

        // ----------------------------------------
        // DELETE BATCH
        // ----------------------------------------
        if ($_POST['action'] == 'delete') {
            $inventory_id = intval($_POST['inventory_id']);

            $stmt = $conn->prepare("SELECT * FROM store_inventory WHERE inventory_id = ?");
            $stmt->execute([$inventory_id]);
            $batch = $stmt->fetch();

            if ($batch) {
                $stmt = $conn->prepare("DELETE FROM store_inventory WHERE inventory_id = ?");
                $stmt->execute([$inventory_id]);

                log_activity($_SESSION['user_id'], 'INVENTORY_DELETED',
                    "Deleted batch #{$batch['batch_number']} (ID: $inventory_id)", $conn);

                set_alert('success', 'Batch deleted.');
            } else {
                set_alert('warning', 'Batch not found.');
            }

            header('Location: inventory.php');
            exit();
        }

        // ----------------------------------------
        // MERGE DUPLICATES
        // ----------------------------------------
        if ($_POST['action'] == 'merge_duplicates') {
            $store_id   = intval($_POST['store_id'] ?? 0);
            $product_id = intval($_POST['product_id'] ?? 0);

            if ($store_id <= 0 || $product_id <= 0) {
                throw new Exception('Invalid merge target.');
            }

            $stmt = $conn->prepare("
                SELECT * FROM store_inventory
                WHERE store_id = ? AND product_id = ? AND quantity > 0
                ORDER BY (expiry_date IS NULL), expiry_date ASC, inventory_id ASC
            ");
            $stmt->execute([$store_id, $product_id]);
            $batches = $stmt->fetchAll();

            if (count($batches) < 2) {
                throw new Exception('Nothing to merge — need at least two active batches.');
            }

            $primary      = $batches[0];
            $total_qty    = 0;
            $total_init   = 0;
            $delete_ids   = [];

            foreach ($batches as $b) {
                $total_qty  += (int) $b['quantity'];
                $total_init += (int) ($b['initial_quantity'] ?? $b['quantity']);
                if ($b['inventory_id'] != $primary['inventory_id']) {
                    $delete_ids[] = (int) $b['inventory_id'];
                }
            }

            $conn->beginTransaction();

            $stmt = $conn->prepare("
                UPDATE store_inventory
                SET quantity = ?, initial_quantity = ?, last_updated = NOW()
                WHERE inventory_id = ?
            ");
            $stmt->execute([$total_qty, $total_init, $primary['inventory_id']]);

            if (!empty($delete_ids)) {
                $placeholders = implode(',', array_fill(0, count($delete_ids), '?'));
                $stmt = $conn->prepare("DELETE FROM store_inventory WHERE inventory_id IN ($placeholders)");
                $stmt->execute($delete_ids);
            }

            $conn->commit();

            log_activity($_SESSION['user_id'], 'BATCH_MERGED',
                "Merged " . count($batches) . " batch(es) into #{$primary['batch_number']} (total $total_qty units)", $conn);

            set_alert('success', "Merged " . count($batches) . " batches into one. New quantity: $total_qty.");
            header('Location: inventory.php');
            exit();
        }

        // ----------------------------------------
        // UPDATE BATCH
        // ----------------------------------------
        if ($_POST['action'] == 'update_batch') {
            $inventory_id = intval($_POST['inventory_id']);
            $new_quantity = intval($_POST['quantity']);
            $expiry_date  = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

            $stmt = $conn->prepare("
                UPDATE store_inventory
                SET quantity = ?, expiry_date = ?, last_updated = NOW()
                WHERE inventory_id = ?
            ");
            $stmt->execute([$new_quantity, $expiry_date, $inventory_id]);

            log_activity($_SESSION['user_id'], 'BATCH_UPDATED',
                "Updated batch #$inventory_id to $new_quantity units", $conn);

            set_alert('success', 'Batch updated.');
            header('Location: inventory.php');
            exit();
        }

    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
}

// ============================================
// FETCH DATA
// ============================================
$inventory        = [];
$finished_batches = [];
$products         = [];
$stores           = [];
$transfers        = [];
$low_stock_items  = [];
$duplicate_groups = [];

// ---------- Active inventory ----------
try {
    $stmt = $conn->prepare("
        SELECT 
            si.*,
            p.product_name,
            p.product_code,
            p.reorder_level,
            s.store_name,
            s.store_code,
            GREATEST(si.quantity, 0) AS display_quantity
        FROM store_inventory si
        JOIN products p ON si.product_id = p.product_id
        JOIN stores s   ON si.store_id   = s.store_id
        WHERE si.quantity > 0
        ORDER BY p.product_name ASC, si.last_updated DESC
    ");
    $stmt->execute();
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $inventory = []; }

// ---------- Finished batches ----------
try {
    $stmt = $conn->prepare("
        SELECT si.*, p.product_name, p.product_code, s.store_name, s.store_code
        FROM store_inventory si
        JOIN products p ON si.product_id = p.product_id
        JOIN stores s   ON si.store_id   = s.store_id
        WHERE si.quantity <= 0
        ORDER BY si.inventory_id DESC
    ");
    $stmt->execute();
    $finished_batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $finished_batches = []; }

// ---------- Products dropdown ----------
try {
    $stmt = $conn->prepare("
        SELECT product_id, product_code, product_name, has_expiry, purchase_price
        FROM products WHERE status = 'active'
        ORDER BY product_name
    ");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $products = []; }

// ---------- Stores dropdown ----------
try {
    $stmt = $conn->prepare("SELECT store_id, store_code, store_name FROM stores WHERE status = 'active' ORDER BY store_name");
    $stmt->execute();
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $stores = []; }

// ---------- Transfers ----------
try {
    $stmt = $conn->prepare("
        SELECT st.*, p.product_name, p.product_code,
               fs.store_name AS from_store, ts.store_name AS to_store,
               u.full_name AS transferred_by_name
        FROM stock_transfers st
        JOIN products p ON st.product_id = p.product_id
        JOIN stores fs  ON st.from_store_id = fs.store_id
        JOIN stores ts  ON st.to_store_id   = ts.store_id
        JOIN users u    ON st.transferred_by = u.user_id
        ORDER BY st.transfer_date DESC
        LIMIT 50
    ");
    $stmt->execute();
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $transfers = []; }

// ---------- Low stock ----------
try {
    $stmt = $conn->prepare("
        SELECT 
            p.product_id,
            p.product_code,
            p.product_name,
            p.reorder_level,
            s.store_id,
            s.store_name,
            s.store_code,
            COALESCE(SUM(si.quantity), 0) AS total_stock
        FROM products p
        CROSS JOIN stores s
        LEFT JOIN store_inventory si
            ON p.product_id = si.product_id
            AND s.store_id   = si.store_id
            AND si.quantity > 0
        WHERE p.status = 'active' AND s.status = 'active'
        GROUP BY p.product_id, s.store_id
        HAVING total_stock <= p.reorder_level
        ORDER BY (total_stock - p.reorder_level) ASC, p.product_name ASC
    ");
    $stmt->execute();
    $low_stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $low_stock_items = []; }

// ---------- Duplicate batches ----------
try {
    $stmt = $conn->prepare("
        SELECT 
            si.store_id,
            si.product_id,
            COUNT(*) AS batch_count,
            SUM(si.quantity) AS total_quantity,
            GROUP_CONCAT(si.batch_number ORDER BY si.inventory_id ASC SEPARATOR ', ') AS batch_list,
            p.product_name,
            p.product_code,
            s.store_name
        FROM store_inventory si
        JOIN products p ON si.product_id = p.product_id
        JOIN stores s   ON si.store_id   = s.store_id
        WHERE si.quantity > 0
        GROUP BY si.store_id, si.product_id
        HAVING batch_count > 1
        ORDER BY batch_count DESC, p.product_name ASC
    ");
    $stmt->execute();
    $duplicate_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $duplicate_groups = []; }

// ---------- Stats ----------
$total_active_stock = 0;
try {
    $total_active_stock = (int) $conn->query("SELECT COALESCE(SUM(quantity), 0) FROM store_inventory WHERE quantity > 0")->fetchColumn();
} catch (PDOException $e) {}

$stats = [
    'active_batches'     => count($inventory),
    'finished_batches'   => count($finished_batches),
    'total_active_stock' => $total_active_stock,
    'low_stock_count'    => count($low_stock_items),
    'duplicate_count'    => count($duplicate_groups),
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

        <!-- ============================================
             STAT CARDS — flat, no alert styling
             ============================================ -->
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
                    <div class="icon"><i class="fas fa-clipboard-list"></i></div>
                    <div>
                        <h3><?php echo number_format($stats['low_stock_count']); ?></h3>
                        <p>Low Stock Products</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card green">
                    <div class="icon"><i class="fas fa-box-open"></i></div>
                    <div>
                        <h3><?php echo number_format($stats['finished_batches']); ?></h3>
                        <p>Finished Batches</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================
             TABS — no coloured badges, just counts
             ============================================ -->
        <ul class="nav nav-tabs mb-3" role="tablist" style="border-bottom: 2px solid var(--cyber-border);">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#inventory-tab"
                   style="color: var(--cyber-purple); font-weight: 600;">
                    <i class="fas fa-boxes me-1"></i>Active Stock
                    <span class="badge bg-secondary ms-1"><?php echo $stats['active_batches']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#lowstock-tab"
                   style="color: var(--cyber-purple); font-weight: 600;">
                    <i class="fas fa-clipboard-list me-1"></i>Low Stock
                    <span class="badge bg-secondary ms-1"><?php echo $stats['low_stock_count']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#duplicates-tab"
                   style="color: var(--cyber-purple); font-weight: 600;">
                    <i class="fas fa-clone me-1"></i>Duplicates
                    <span class="badge bg-secondary ms-1"><?php echo $stats['duplicate_count']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#finished-tab"
                   style="color: var(--cyber-purple); font-weight: 600;">
                    <i class="fas fa-box-open me-1"></i>Finished
                    <span class="badge bg-secondary ms-1"><?php echo $stats['finished_batches']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#transfers-tab"
                   style="color: var(--cyber-purple); font-weight: 600;">
                    <i class="fas fa-exchange-alt me-1"></i>Transfers
                    <span class="badge bg-secondary ms-1"><?php echo count($transfers); ?></span>
                </a>
            </li>
        </ul>

        <div class="tab-content">

            <!-- ============================================
                 TAB 1: ACTIVE STOCK — no row highlighting, no warning icon
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
                                        <th class="text-center">Initial</th>
                                        <th class="text-center">Sold</th>
                                        <th class="text-center">Stock</th>
                                        <th>Expiry</th>
                                        <th class="text-center">Actions</th>
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
                                        $current_qty  = max(0, (int) $inv['quantity']);
                                        $initial_qty  = (int) ($inv['initial_quantity'] ?? $current_qty);
                                        $sold_qty     = max(0, $initial_qty - $current_qty);
                                        ?>
                                        <tr>
                                            <td><span class="badge bg-primary"><?php echo htmlspecialchars($inv['store_name']); ?></span></td>
                                            <td><code><?php echo htmlspecialchars($inv['product_code']); ?></code></td>
                                            <td><strong><?php echo htmlspecialchars($inv['product_name']); ?></strong></td>
                                            <td><code><?php echo htmlspecialchars($inv['batch_number']); ?></code></td>
                                            <td class="text-center"><span class="badge bg-info"><?php echo number_format($initial_qty); ?></span></td>
                                            <td class="text-center"><span class="badge bg-danger"><?php echo number_format($sold_qty); ?></span></td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?php echo number_format($current_qty); ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($inv['expiry_date'])): ?>
                                                    <?php echo date('d/m/Y', strtotime($inv['expiry_date'])); ?>
                                                <?php else: ?>
                                                    <small class="text-muted">N/A</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center text-nowrap">
                                                <button type="button" class="btn btn-sm btn-primary edit-batch-btn"
                                                        data-id="<?php echo (int) $inv['inventory_id']; ?>"
                                                        data-qty="<?php echo $current_qty; ?>"
                                                        data-expiry="<?php echo htmlspecialchars($inv['expiry_date'] ?? ''); ?>"
                                                        data-product="<?php echo htmlspecialchars($inv['product_name']); ?>"
                                                        data-batch="<?php echo htmlspecialchars($inv['batch_number']); ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this batch?');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="inventory_id" value="<?php echo (int) $inv['inventory_id']; ?>">
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
                 TAB 2: LOW STOCK — plain table, no alarm colors
                 ============================================ -->
            <div id="lowstock-tab" class="tab-pane fade">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-clipboard-list me-2"></i>Low Stock — Products Needing Restock
                    </div>
                    <div class="card-body">
                        <?php if (empty($low_stock_items)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>All good!</strong> No products are at or below their reorder level.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong><?php echo count($low_stock_items); ?></strong> product(s) at or below reorder level.
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover" id="lowStockTable">
                                    <thead>
                                        <tr>
                                            <th>Store</th>
                                            <th>Product Code</th>
                                            <th>Product Name</th>
                                            <th class="text-center">Current Stock</th>
                                            <th class="text-center">Reorder Level</th>
                                            <th class="text-center">Shortage</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($low_stock_items as $item): 
                                            $shortage = max(0, $item['reorder_level'] - $item['total_stock']);
                                        ?>
                                        <tr>
                                            <td><span class="badge bg-primary"><?php echo htmlspecialchars($item['store_name']); ?></span></td>
                                            <td><code><?php echo htmlspecialchars($item['product_code']); ?></code></td>
                                            <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?php echo number_format($item['total_stock']); ?></span>
                                            </td>
                                            <td class="text-center"><?php echo number_format($item['reorder_level']); ?></td>
                                            <td class="text-center">
                                                <?php if ($shortage > 0): ?>
                                                    <span class="badge bg-secondary">-<?php echo number_format($shortage); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-primary restock-btn"
                                                        data-store="<?php echo (int) $item['store_id']; ?>"
                                                        data-product="<?php echo (int) $item['product_id']; ?>"
                                                        data-product-name="<?php echo htmlspecialchars($item['product_name']); ?>"
                                                        data-store-name="<?php echo htmlspecialchars($item['store_name']); ?>"
                                                        data-suggested="<?php echo max(1, $item['reorder_level'] * 2 - $item['total_stock']); ?>">
                                                    <i class="fas fa-plus me-1"></i>Restock
                                                </button>
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
                 TAB 3: DUPLICATES — plain header, no red
                 ============================================ -->
            <div id="duplicates-tab" class="tab-pane fade">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-clone me-2"></i>Duplicate Batches
                    </div>
                    <div class="card-body">
                        <?php if (empty($duplicate_groups)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>Clean!</strong> No duplicate batches found. Each product has one active batch per store.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                These products have <strong>multiple active batches</strong> in the same store.
                                You can merge them into a single batch to simplify inventory.
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Store</th>
                                            <th>Product Code</th>
                                            <th>Product Name</th>
                                            <th class="text-center">Active Batches</th>
                                            <th class="text-center">Total Quantity</th>
                                            <th>Batch Numbers</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($duplicate_groups as $dup): ?>
                                        <tr>
                                            <td><span class="badge bg-primary"><?php echo htmlspecialchars($dup['store_name']); ?></span></td>
                                            <td><code><?php echo htmlspecialchars($dup['product_code']); ?></code></td>
                                            <td><strong><?php echo htmlspecialchars($dup['product_name']); ?></strong></td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?php echo $dup['batch_count']; ?> batches</span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?php echo number_format($dup['total_quantity']); ?> units</span>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?php echo htmlspecialchars($dup['batch_list']); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <form method="POST" style="display:inline;"
                                                      onsubmit="return confirm('Merge all active batches for this product into one? This cannot be undone.');">
                                                      <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="merge_duplicates">
                                                    <input type="hidden" name="store_id" value="<?php echo (int) $dup['store_id']; ?>">
                                                    <input type="hidden" name="product_id" value="<?php echo (int) $dup['product_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-compress-alt me-1"></i>Merge
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
                 TAB 4: FINISHED
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
                                            <th class="text-center">Initial Qty</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($finished_batches as $batch): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($batch['store_name']); ?></td>
                                            <td><?php echo htmlspecialchars($batch['product_code']); ?></td>
                                            <td><?php echo htmlspecialchars($batch['product_name']); ?></td>
                                            <td><code><?php echo htmlspecialchars($batch['batch_number']); ?></code></td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?php echo number_format($batch['initial_quantity'] ?? 0); ?></span>
                                            </td>
                                            <td class="text-center">
                                                <form method="POST" style="display:inline;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="inventory_id" value="<?php echo (int) $batch['inventory_id']; ?>">
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
                 TAB 5: TRANSFERS
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
                                            <th class="text-center">Qty</th>
                                            <th>By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transfers as $transfer): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($transfer['transfer_number']); ?></strong></td>
                                            <td><?php echo date('d/m/Y H:i', strtotime($transfer['transfer_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($transfer['product_code']); ?></td>
                                            <td><?php echo htmlspecialchars($transfer['from_store']); ?></td>
                                            <td><?php echo htmlspecialchars($transfer['to_store']); ?></td>
                                            <td class="text-center"><strong><?php echo $transfer['quantity']; ?></strong></td>
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

<!-- ============================================
     ADD STOCK MODAL
     ============================================ -->
<div class="modal fade" id="addStockModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Stock</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addStockForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_stock">
                <input type="hidden" name="target_inventory_id" id="target_inventory_id" value="0">
                <div class="modal-body">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Store *</label>
                            <select name="store_id" id="stock_store_id" class="form-select" required>
                                <option value="">Select Store</option>
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?php echo (int) $store['store_id']; ?>">
                                        <?php echo htmlspecialchars($store['store_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Product *</label>
                            <select name="product_id" id="stock_product_id" class="form-select" required>
                                <option value="">Select Product</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo (int) $product['product_id']; ?>"
                                            data-expiry="<?php echo $product['has_expiry']; ?>">
                                        <?php echo htmlspecialchars($product['product_code']); ?> — <?php echo htmlspecialchars($product['product_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">How do you want to add this stock?</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="stock_mode" id="mode_existing" value="existing" checked>
                            <label class="btn btn-outline-primary" for="mode_existing">
                                <i class="fas fa-plus-circle me-1"></i>Add to Existing Batch
                            </label>

                            <input type="radio" class="btn-check" name="stock_mode" id="mode_new" value="new">
                            <label class="btn btn-outline-primary" for="mode_new">
                                <i class="fas fa-file-medical me-1"></i>Create New Batch
                            </label>
                        </div>
                    </div>

                    <div id="existing_batches_panel" class="mt-3">
                        <div class="alert alert-info py-2 px-3 small">
                            <i class="fas fa-info-circle me-1"></i>
                            Select an existing batch to add to, or switch to "Create New Batch" above.
                        </div>
                        <div id="existing_batches_list">
                            <div class="text-muted text-center py-3">
                                <i class="fas fa-arrow-up me-1"></i>
                                Select a store and product to see existing batches
                            </div>
                        </div>
                    </div>

                    <div id="new_batch_panel" class="mt-3" style="display:none;">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Batch Number *</label>
                                <input type="text" name="batch_number" id="new_batch_number" class="form-control"
                                       value="BATCH<?php echo date('Ymd') . rand(100, 999); ?>">
                            </div>
                            <div class="col-md-6" id="expiry_field_wrapper" style="display:none;">
                                <label class="form-label">Expiry Date</label>
                                <input type="date" name="expiry_date" id="new_expiry_date" class="form-control">
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">Quantity *</label>
                        <input type="number" name="quantity" class="form-control" min="1" value="1" required>
                    </div>

                    <div class="mt-3" id="existing_expiry_wrapper" style="display:none;">
                        <label class="form-label">Update Expiry Date (optional)</label>
                        <input type="date" name="expiry_date" class="form-control">
                        <small class="text-muted">Leave blank to keep the batch's current expiry date.</small>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================
     EDIT BATCH MODAL
     ============================================ -->
<div class="modal fade" id="editBatchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Batch</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_batch">
                <input type="hidden" name="inventory_id" id="edit_inventory_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <input type="text" id="edit_product_display" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Batch</label>
                        <input type="text" id="edit_batch_display" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity *</label>
                        <input type="number" name="quantity" id="edit_quantity" class="form-control" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Expiry Date</label>
                        <input type="date" name="expiry_date" id="edit_expiry" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const existingPanel = document.getElementById('existing_batches_panel');
    const newPanel      = document.getElementById('new_batch_panel');
    const existingExpiryWrap = document.getElementById('existing_expiry_wrapper');
    const batchesList   = document.getElementById('existing_batches_list');
    const targetIdInput = document.getElementById('target_inventory_id');
    const storeSelect   = document.getElementById('stock_store_id');
    const productSelect = document.getElementById('stock_product_id');
    const newBatchInput = document.getElementById('new_batch_number');

    function updateMode() {
        const mode = document.querySelector('input[name="stock_mode"]:checked').value;
        if (mode === 'existing') {
            existingPanel.style.display = 'block';
            newPanel.style.display      = 'none';
            newBatchInput.removeAttribute('required');
            existingExpiryWrap.style.display = 'none';
        } else {
            existingPanel.style.display = 'none';
            newPanel.style.display      = 'block';
            newBatchInput.setAttribute('required', 'required');
            updateExpiryVisibility();
        }
    }
    document.querySelectorAll('input[name="stock_mode"]').forEach(function(el) {
        el.addEventListener('change', updateMode);
    });

    function updateExpiryVisibility() {
        const opt = productSelect.options[productSelect.selectedIndex];
        const wrapper = document.getElementById('expiry_field_wrapper');
        if (!wrapper) return;
        if (opt && opt.getAttribute('data-expiry') === 'yes') {
            wrapper.style.display = 'block';
        } else {
            wrapper.style.display = 'none';
        }
    }
    productSelect.addEventListener('change', updateExpiryVisibility);

    function loadExistingBatches() {
        const storeId   = storeSelect.value;
        const productId = productSelect.value;

        targetIdInput.value = '0';

        if (!storeId || !productId) {
            batchesList.innerHTML = '<div class="text-muted text-center py-3">' +
                '<i class="fas fa-arrow-up me-1"></i>Select a store and product to see existing batches</div>';
            return;
        }

        batchesList.innerHTML = '<div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Loading batches...</div>';

        fetch('ajax_inventory_batches.php?store_id=' + encodeURIComponent(storeId) + '&product_id=' + encodeURIComponent(productId))
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    batchesList.innerHTML = '<div class="alert alert-danger py-2 small">' + (data.error || 'Error') + '</div>';
                    return;
                }
                if (data.batches.length === 0) {
                    batchesList.innerHTML = '<div class="alert alert-warning py-2 small">' +
                        '<i class="fas fa-info-circle me-1"></i>No existing batches for this product in this store. Switch to <strong>Create New Batch</strong>.</div>';
                    document.getElementById('mode_new').checked = true;
                    updateMode();
                    return;
                }

                let html = '<div class="list-group">';
                data.batches.forEach((b, i) => {
                    const expiryText = b.expiry_date
                        ? 'Expires: ' + b.expiry_date
                        : 'No expiry';
                    const checked = i === 0 ? 'checked' : '';
                    html += `
                        <label class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <input type="radio" name="selected_batch" value="${b.inventory_id}" ${checked} class="form-check-input me-2">
                                <strong>${b.batch_number}</strong>
                                <br>
                                <small class="text-muted ms-4">${expiryText}</small>
                            </div>
                            <span class="badge bg-primary rounded-pill">${b.quantity} units</span>
                        </label>
                    `;
                });
                html += '</div>';
                batchesList.innerHTML = html;

                const first = document.querySelector('input[name="selected_batch"]:checked');
                if (first) {
                    targetIdInput.value = first.value;
                    updateExpiryFromBatch(first.value, data.batches);
                }

                document.querySelectorAll('input[name="selected_batch"]').forEach(radio => {
                    radio.addEventListener('change', function() {
                        targetIdInput.value = this.value;
                        updateExpiryFromBatch(this.value, data.batches);
                    });
                });
            })
            .catch(err => {
                console.error(err);
                batchesList.innerHTML = '<div class="alert alert-danger py-2 small">Network error loading batches.</div>';
            });
    }

    function updateExpiryFromBatch(inventoryId, batches) {
        const batch = batches.find(b => String(b.inventory_id) === String(inventoryId));
        if (!batch) return;
        if (batch.expiry_date) {
            existingExpiryWrap.style.display = 'block';
        } else {
            existingExpiryWrap.style.display = 'none';
        }
    }

    storeSelect.addEventListener('change', loadExistingBatches);
    productSelect.addEventListener('change', function() {
        updateExpiryVisibility();
        loadExistingBatches();
    });

    document.querySelectorAll('.restock-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const storeId    = this.getAttribute('data-store');
            const productId  = this.getAttribute('data-product');
            const suggested  = this.getAttribute('data-suggested');

            storeSelect.value   = storeId;
            productSelect.value = productId;

            document.getElementById('mode_existing').checked = true;
            updateMode();

            const qtyInput = document.querySelector('#addStockForm input[name="quantity"]');
            if (qtyInput) qtyInput.value = suggested;

            loadExistingBatches();
            new bootstrap.Modal(document.getElementById('addStockModal')).show();
        });
    });

    document.querySelectorAll('.edit-batch-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_inventory_id').value  = this.getAttribute('data-id');
            document.getElementById('edit_quantity').value      = this.getAttribute('data-qty');
            document.getElementById('edit_expiry').value        = this.getAttribute('data-expiry') || '';
            document.getElementById('edit_product_display').value = this.getAttribute('data-product');
            document.getElementById('edit_batch_display').value   = this.getAttribute('data-batch');
            new bootstrap.Modal(document.getElementById('editBatchModal')).show();
        });
    });

    document.getElementById('addStockModal').addEventListener('hidden.bs.modal', function() {
        document.getElementById('addStockForm').reset();
        targetIdInput.value = '0';
        batchesList.innerHTML = '<div class="text-muted text-center py-3">' +
            '<i class="fas fa-arrow-up me-1"></i>Select a store and product to see existing batches</div>';
        existingPanel.style.display = 'block';
        newPanel.style.display      = 'none';
        existingExpiryWrap.style.display = 'none';
        document.getElementById('mode_existing').checked = true;
    });

    function initDataTable(selector) {
        const table = document.querySelector(selector);
        if (!table || typeof jQuery === 'undefined' || !jQuery.fn.DataTable) return;

        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const rows = tbody.querySelectorAll('tr');
        if (rows.length === 0) return;

        if (rows.length === 1) {
            const cells = rows[0].querySelectorAll('td');
            if (cells.length === 1 && cells[0].hasAttribute('colspan')) return;
        }

        const headerCount = table.querySelectorAll('thead th').length;
        const firstRowCells = rows[0].querySelectorAll('td').length;
        if (headerCount !== firstRowCells) {
            console.warn('Skipping DataTable init for ' + selector +
                ' — column mismatch: ' + headerCount + ' headers vs ' + firstRowCells + ' cells');
            return;
        }

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
                    last:  '<i class="fas fa-angle-double-right"></i>',
                    next:  '<i class="fas fa-angle-right"></i>',
                    previous: '<i class="fas fa-angle-left"></i>'
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            initDataTable('#inventoryTable');
            initDataTable('#lowStockTable');
            initDataTable('#finishedTable');
            initDataTable('#transfersTable');
        }, 200);
    });

})();
</script>

<?php include '../includes/footer.php'; ?>