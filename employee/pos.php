<?php
require_once '../config/config.php';
require_login();

// ============================================
// AUTO-PRINT HANDLER
// ============================================
$auto_print_sale_id = isset($_GET['auto_print']) ? intval($_GET['auto_print']) : 0;
$auto_print_type = $_GET['type'] ?? 'cash';

if (is_admin()) {
    header('Location: ../admin/dashboard.php');
    exit();
}

$page_title = "Point of Sale";

// ============================================
// AUTO-MIGRATE
// ============================================
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `user_stores` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `store_id` INT NOT NULL,
          `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `unique_user_store` (`user_id`, `store_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'default_store_id'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE users ADD COLUMN default_store_id INT DEFAULT NULL AFTER status");
    }

    $check = $conn->query("SHOW COLUMNS FROM sales LIKE 'sale_type'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE sales ADD COLUMN sale_type ENUM('cash','credit') DEFAULT 'cash' AFTER payment_method");
    }

    $check = $conn->query("SHOW COLUMNS FROM sales LIKE 'transaction_id'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE sales ADD COLUMN transaction_id VARCHAR(100) DEFAULT NULL AFTER amount_due");
    }

    $check = $conn->query("SHOW COLUMNS FROM sale_items LIKE 'profit'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE sale_items ADD COLUMN profit DECIMAL(15,2) DEFAULT 0 AFTER total_price");
    }
} catch(PDOException $e) {}

// ============================================
// FETCH STORES ASSIGNED TO THIS EMPLOYEE
// ============================================
$stores = [];
$assigned_store_count = 0;
$assigned_store = null;      
$default_store_id = null;

try {
    $stmt = $conn->prepare("
        SELECT s.*
        FROM stores s
        INNER JOIN user_stores us ON s.store_id = us.store_id
        WHERE us.user_id = ?
          AND s.status = 'active'
        ORDER BY s.store_name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $assigned_store_count = count($stores);

    $stmt = $conn->prepare("SELECT default_store_id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user_row && !empty($user_row['default_store_id'])) {
        $default_store_id = (int) $user_row['default_store_id'];
    }

    if ($assigned_store_count === 1) {
        $assigned_store    = $stores[0];
        $default_store_id  = (int) $assigned_store['store_id'];
    }

    if ($assigned_store_count > 1 && $default_store_id) {
        $valid_ids = array_map('intval', array_column($stores, 'store_id'));
        if (!in_array($default_store_id, $valid_ids, true)) {
            $default_store_id = null;
        }
    }

} catch (PDOException $e) {
    error_log("POS store fetch failed: " . $e->getMessage());
    $stores = [];
    $assigned_store_count = 0;
}

$payment_methods = ['cash', 'card', 'mobile_money', 'bank_transfer', 'credit'];

// ============================================
// PROCESS SALE
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['complete_sale'])) {
    csrf_require();
    try {
        $conn->beginTransaction();

        $store_id = isset($_POST['store_id']) ? intval($_POST['store_id']) : 0;
        if ($store_id <= 0) throw new Exception('Please select a store before completing the sale.');

        $check = $conn->prepare("
            SELECT s.store_id
            FROM stores s
            INNER JOIN user_stores us ON s.store_id = us.store_id
            WHERE s.store_id = ?
              AND s.status = 'active'
              AND us.user_id = ?
        ");
        $check->execute([$store_id, $_SESSION['user_id']]);
        if (!$check->fetch()) {
            throw new Exception('You are not assigned to this store.');
        }

        $customer_id = !empty($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
        $payment_method = $_POST['payment_method'] ?? '';
        $amount_paid = floatval($_POST['amount_paid'] ?? 0);
        $transaction_id = !empty($_POST['transaction_id']) ? sanitize_input($_POST['transaction_id']) : null;
        $discount_amount = isset($_POST['discount_amount']) ? floatval($_POST['discount_amount']) : 0;
        $cart_items = json_decode($_POST['cart_items'] ?? '[]', true);

        if (empty($cart_items)) throw new Exception('Cart is empty.');
        if (empty($payment_method)) throw new Exception('Please select a payment method.');

        $sale_type = ($payment_method == 'credit') ? 'credit' : 'cash';
        if ($sale_type == 'credit' && !$customer_id) throw new Exception('Customer is required for credit sales');

        $subtotal = 0;
        $tax_amount = 0;
        foreach ($cart_items as $item) {
            $subtotal += $item['quantity'] * $item['price'];
            $tax_amount += $item['tax_amount'] ?? 0;
        }

        $total_amount = $subtotal + $tax_amount - $discount_amount;

        if ($payment_method != 'credit' && $amount_paid < $total_amount) {
            throw new Exception('Amount paid cannot be less than total amount');
        }

        $amount_due = max(0, $total_amount - $amount_paid);
        $payment_status = ($amount_paid >= $total_amount) ? 'paid' : ($amount_paid > 0 ? 'partial' : 'unpaid');

        $invoice_number = function_exists('generate_invoice_number')
                          ? generate_invoice_number()
                          : 'INV' . date('YmdHis') . rand(100, 999);

        $stmt = $conn->prepare("
            INSERT INTO sales (
                invoice_number, store_id, customer_id, sale_date,
                subtotal, discount_amount, tax_amount, total_amount,
                payment_method, sale_type, payment_status,
                amount_paid, amount_due, transaction_id,
                served_by, status
            ) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')
        ");
        $stmt->execute([
            $invoice_number, $store_id, $customer_id,
            $subtotal, $discount_amount, $tax_amount, $total_amount,
            $payment_method, $sale_type, $payment_status,
            $amount_paid, $amount_due, $transaction_id,
            $_SESSION['user_id']
        ]);

        $sale_id = $conn->lastInsertId();

        foreach ($cart_items as $item) {
            $product_id = intval($item['product_id']);
            $quantity = intval($item['quantity']);
            $price = floatval($item['price']);
            $cost = floatval($item['cost']);
            $tax = floatval($item['tax_amount'] ?? 0);
            $line_total = $quantity * $price;
            $profit = ($price - $cost) * $quantity;

            $stmt = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, discount_amount, tax_amount, total_price, profit) VALUES (?, ?, ?, ?, 0, ?, ?, ?)");
            $stmt->execute([$sale_id, $product_id, $quantity, $price, $tax, $line_total, $profit]);

            $remaining_qty = $quantity;
            $stmt = $conn->prepare("SELECT inventory_id, quantity FROM store_inventory WHERE store_id = ? AND product_id = ? AND quantity > 0 ORDER BY last_updated ASC, inventory_id ASC");
            $stmt->execute([$store_id, $product_id]);
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($batches as $batch) {
                if ($remaining_qty <= 0) break;
                if ($batch['quantity'] >= $remaining_qty) {
                    $stmt = $conn->prepare("UPDATE store_inventory SET quantity = GREATEST(quantity - ?, 0) WHERE inventory_id = ?");
                    $stmt->execute([$remaining_qty, $batch['inventory_id']]);
                    $remaining_qty = 0;
                } else {
                    $stmt = $conn->prepare("UPDATE store_inventory SET quantity = 0 WHERE inventory_id = ?");
                    $stmt->execute([$batch['inventory_id']]);
                    $remaining_qty -= $batch['quantity'];
                }
            }
        }

        if ($customer_id) {
            $stmt = $conn->prepare("UPDATE customers SET total_purchases = total_purchases + ? WHERE customer_id = ?");
            $stmt->execute([$total_amount, $customer_id]);
        }

        if (function_exists('log_activity')) {
            $sale_type_label = ($sale_type == 'credit') ? 'credit sale' : 'cash sale';
            log_activity($_SESSION['user_id'], 'SALE_CREATED', "Created $sale_type_label $invoice_number", $conn);
        }

        $conn->commit();

        if ($sale_type == 'credit') {
            set_alert('success', 'Credit sale completed! Invoice: ' . $invoice_number);
            header('Location: pos.php?auto_print=' . $sale_id . '&type=credit');
        } else {
            set_alert('success', 'Sale completed! Invoice: ' . $invoice_number);
            header('Location: pos.php?auto_print=' . $sale_id);
        }
        exit();

    } catch(Exception $e) {
        if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/employee_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <div class="d-flex justify-content-between align-items-center w-100">
            <div class="d-flex align-items-center">
                <button class="btn btn-link d-lg-none sidebar-toggle me-2">
                    <i class="fas fa-bars"></i>
                </button>
                <button type="button" class="btn btn-link sidebar-desktop-toggle me-2" id="sidebarDesktopToggle" title="Toggle Sidebar (Ctrl+B)">
                    <i class="fas fa-bars"></i>
                </button>
                <h5 class="mb-0">
                    <i class="fas fa-cash-register me-2" style="color: var(--cyber-orange);"></i>
                    Point of Sale
                </h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="pos-clock">
                    <i class="fas fa-clock"></i>
                    <span id="live-clock"><?php echo date('H:i:s'); ?></span>
                </div>
                <div class="user-badge">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="content-area pos-content">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <i class="fas fa-<?php echo $alert['type'] == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($assigned_store_count === 0): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>No store assigned!</strong> Ask your administrator to assign you to a store before using the POS.
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card cyber-card mb-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div class="store-icon-badge"><i class="fas fa-store"></i></div>
                            <div class="flex-grow-1">
                                <?php if ($assigned_store_count === 0): ?>
                                    <label class="form-label-cyber mb-1" style="font-size: 0.7rem;">
                                        <i class="fas fa-map-marker-alt me-1"></i>Active Store
                                    </label>
                                    <div class="alert alert-danger mb-0 py-2 px-3" style="font-size: 0.85rem;">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <strong>No store assigned.</strong>
                                        Ask your administrator to assign you to a store before using the POS.
                                    </div>
                                <?php elseif ($assigned_store_count === 1): ?>
                                    <label class="form-label-cyber mb-1" style="font-size: 0.7rem;">
                                        <i class="fas fa-map-marker-alt me-1"></i>Active Store
                                        <span class="badge bg-success ms-2" style="font-size: 0.65rem;">
                                            <i class="fas fa-lock me-1"></i>Assigned
                                        </span>
                                    </label>
                                    <div class="assigned-store-display">
                                        <i class="fas fa-store me-2"></i>
                                        <span><?php echo htmlspecialchars($assigned_store['store_name']); ?></span>
                                        <?php if (!empty($assigned_store['location'])): ?>
                                            <small class="text-muted ms-2">— <?php echo htmlspecialchars($assigned_store['location']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <select id="store-select" style="display:none;">
                                        <option value="<?php echo (int) $assigned_store['store_id']; ?>" selected>
                                            <?php echo htmlspecialchars($assigned_store['store_name']); ?>
                                        </option>
                                    </select>
                                <?php else: ?>
                                    <label class="form-label-cyber mb-1" style="font-size: 0.7rem;">
                                        <i class="fas fa-map-marker-alt me-1"></i>Active Store
                                        <span class="badge bg-info ms-2" style="font-size: 0.65rem;">
                                            <?php echo (int) $assigned_store_count; ?> assigned
                                        </span>
                                    </label>
                                    <select id="store-select" class="form-select form-select-cyber" required>
                                        <option value="">-- Select Store to Begin --</option>
                                        <?php foreach ($stores as $store): ?>
                                            <option value="<?php echo (int) $store['store_id']; ?>"
                                                    <?php echo ((int) $default_store_id === (int) $store['store_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($store['store_name']); ?>
                                                <?php if (!empty($store['location'])): ?>
                                                    — <?php echo htmlspecialchars($store['location']); ?>
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                            <div id="store-status" class="store-status-badge">
                                <span class="status-dot-inactive"></span>
                                <span>Not Selected</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pos-search-card mb-3">
                    <div class="search-input-wrapper">
                        <div class="search-icon"><i class="fas fa-search"></i></div>
                        <input type="text" id="product-search" class="pos-search-input"
                               placeholder="Search by product name, code, or scan barcode..."
                               autofocus autocomplete="off">
                    </div>
                </div>

                <div id="search-results-container" class="search-results-container mb-3" style="display:none;">
                    <div class="search-results-header">
                        <span><i class="fas fa-search me-2"></i>Search Results</span>
                        <button type="button" class="btn-close-results" onclick="clearSearchResults()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div id="search-results" class="search-results-list"></div>
                </div>

                <div class="card cyber-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>
                            <i class="fas fa-shopping-cart me-2"></i>
                            Shopping Cart
                            <span class="badge bg-warning text-dark ms-2" id="cart-count">0</span>
                        </span>
                        <button type="button" class="btn btn-sm btn-danger" id="clear-cart">
                            <i class="fas fa-trash-alt me-1"></i>Clear
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="pos-cart-table" id="cart-table">
                                <thead>
                                    <tr>
                                        <th style="width: 35%;">Product</th>
                                        <th style="width: 15%;">Price</th>
                                        <th style="width: 15%;">Qty</th>
                                        <th style="width: 12%;">Tax</th>
                                        <th style="width: 15%;">Subtotal</th>
                                        <th style="width: 8%;"></th>
                                    </tr>
                                </thead>
                                <tbody id="cart-items">
                                    <tr class="empty-cart-row">
                                        <td colspan="6">
                                            <div class="empty-cart">
                                                <i class="fas fa-shopping-basket"></i>
                                                <h5>Cart is Empty</h5>
                                                <p>Search and add products to begin a sale</p>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card cyber-card summary-card">
                    <div class="card-header">
                        <i class="fas fa-calculator me-2"></i>Sale Summary
                    </div>
                    <div class="card-body">
                        <form id="sale-form" method="POST" <?php echo ($assigned_store_count === 0) ? 'style="pointer-events:none; opacity:0.5;"' : ''; ?>>
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="store_id" id="store-id-hidden" value="<?php echo $default_store_id; ?>">

                            <div class="mb-3">
                                <label class="form-label-cyber">
                                    <i class="fas fa-user me-2"></i>Customer
                                    <span class="badge bg-danger ms-2" id="customer-required" style="display:none;">Required</span>
                                </label>
                                <select name="customer_id" id="customer-select" class="form-select form-select-cyber">
                                    <option value="">🚶 Walk-in Customer</option>
                                </select>
                            </div>

                            <div class="totals-display">
                                <div class="total-row">
                                    <span class="total-label">Subtotal</span>
                                    <span class="total-value" id="subtotal-display">UGX 0</span>
                                </div>
                                <div class="total-row">
                                    <span class="total-label">Tax</span>
                                    <span class="total-value" id="tax-display">UGX 0</span>
                                </div>
                                <div class="total-row">
                                    <span class="total-label"><i class="fas fa-tag me-1"></i>Discount</span>
                                    <div class="discount-input-wrapper">
                                        <span class="currency-prefix">UGX</span>
                                        <input type="text"
                                            inputmode="decimal"
                                            name="discount_amount"
                                            id="discount-input"
                                            class="discount-input"
                                            value="0"
                                            autocomplete="off"
                                            onwheel="this.blur()"
                                            onkeydown="return handleAmountKeydown(event)">
                                    </div>
                                </div>
                            </div>

                            <div class="grand-total-box">
                                <span class="grand-total-label">TOTAL</span>
                                <span class="grand-total-value" id="total-display">UGX 0</span>
                                <input type="hidden" id="total-value" value="0">
                            </div>

                            <div class="mb-3">
                                <label class="form-label-cyber">
                                    <i class="fas fa-credit-card me-2"></i>Payment Method
                                </label>
                                <select name="payment_method" id="payment-method" class="form-select payment-select-compact" required>
                                    <option value="">💰 Select Payment Method</option>
                                    <option value="cash">💵 Cash</option>
                                    <option value="card">💳 Card</option>
                                    <option value="mobile_money">📱 Mobile Money</option>
                                    <option value="bank_transfer">🏦 Bank Transfer</option>
                                    <option value="credit">📋 Credit (Pay Later)</option>
                                </select>
                            </div>

                            <div id="transaction-id-section" class="mb-3" style="display:none;">
                                <label class="form-label-cyber">
                                    <i class="fas fa-hashtag me-2"></i>Transaction ID
                                    <span class="badge bg-danger ms-2">Required</span>
                                </label>
                                <input type="text" name="transaction_id" id="transaction-id"
                                       class="form-control form-control-cyber"
                                       placeholder="Enter transaction ID">
                            </div>

                            <div id="payment-section">
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="form-label-cyber mb-0">
                                            <i class="fas fa-money-bill me-2"></i>Amount Paid
                                        </label>
                                        <button type="button" class="btn-auto-fill" id="auto-fill-btn">
                                            <i class="fas fa-magic me-1"></i>Fill Total
                                        </button>
                                    </div>
                                    <div class="amount-input-wrapper">
                                        <span class="currency-prefix">UGX</span>
                                        <input type="text"
                                            inputmode="decimal"
                                            name="amount_paid"
                                            id="amount-paid"
                                            class="amount-input"
                                            value="0"
                                            required
                                            autocomplete="off"
                                            onwheel="this.blur()"
                                            onkeydown="return handleAmountKeydown(event)">
                                    </div>
                                    <small class="text-danger d-none" id="amount-error">
                                        <i class="fas fa-exclamation-circle me-1"></i>Amount cannot be less than total
                                    </small>
                                </div>

                                <div class="change-display" id="change-container" style="display:none;">
                                    <span class="change-label">
                                        <i class="fas fa-hand-holding-usd me-1"></i>Change
                                    </span>
                                    <span class="change-value" id="change-display">UGX 0</span>
                                </div>
                            </div>

                            <div id="credit-notice" class="credit-notice" style="display:none;">
                                <div class="credit-notice-icon"><i class="fas fa-exclamation-triangle"></i></div>
                                <div class="credit-notice-content">
                                    <strong>Credit Sale</strong>
                                    <p>Customer is required. Payment can be partial or zero.</p>
                                </div>
                            </div>

                            <input type="hidden" name="cart_items" id="cart-items-input">
                            <input type="hidden" name="complete_sale" value="1">

                            <button type="submit" class="btn-complete-sale" id="complete-sale-btn"
                                    <?php echo ($assigned_store_count === 0) ? 'disabled' : ''; ?>>
                                <i class="fas fa-check-circle"></i>
                                <span>Complete Sale</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- POS Styles -->
<style>
.pos-content { background: linear-gradient(135deg, #F7FAFC 0%, #EDF2F7 100%); min-height: calc(100vh - 60px); }
.pos-clock { display: flex; align-items: center; gap: 8px; padding: 6px 14px; background: rgba(72, 187, 120, 0.15); border-radius: 20px; color: #2F855A; font-weight: 600; font-family: 'Courier New', monospace; font-size: 0.9rem; }
.user-badge { display: flex; align-items: center; gap: 8px; padding: 8px 16px; background: linear-gradient(135deg, #6B46C1, #553C9A); color: #fff; border-radius: 30px; font-size: 0.85rem; font-weight: 600; box-shadow: 0 4px 15px rgba(107, 70, 193, 0.3); }
.store-icon-badge { width: 48px; height: 48px; background: linear-gradient(135deg, #6B46C1, #553C9A); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.25rem; flex-shrink: 0; box-shadow: 0 4px 15px rgba(107, 70, 193, 0.3); }
.store-status-badge { display: flex; align-items: center; gap: 8px; padding: 8px 16px; background: #EDF2F7; border-radius: 20px; font-size: 0.8rem; font-weight: 600; color: #718096; white-space: nowrap; }
.status-dot-inactive { width: 8px; height: 8px; background: #A0AEC0; border-radius: 50%; }
.status-dot-active { width: 8px; height: 8px; background: #48BB78; border-radius: 50%; animation: pulse-dot 2s infinite; }
@keyframes pulse-dot { 0%, 100% { box-shadow: 0 0 0 0 rgba(72, 187, 120, 0.5); } 50% { box-shadow: 0 0 0 5px rgba(72, 187, 120, 0); } }

.assigned-store-display { display: flex; align-items: center; padding: 12px 16px; background: linear-gradient(135deg, rgba(72, 187, 120, 0.1), rgba(72, 187, 120, 0.05)); border: 2px solid rgba(72, 187, 120, 0.3); border-radius: 10px; font-size: 0.95rem; font-weight: 700; color: #2F855A; }
.assigned-store-display i { color: #38A169; font-size: 1.1rem; }
.assigned-store-display small { font-weight: 500; }

.pos-search-card { background: #fff; border-radius: 12px; padding: 16px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); border: 1px solid #E2E8F0; }
.search-input-wrapper { position: relative; display: flex; align-items: center; }
.search-icon { position: absolute; left: 20px; color: #6B46C1; font-size: 1.1rem; z-index: 2; }
.pos-search-input { width: 100%; padding: 16px 20px 16px 56px; border: 2px solid #E2E8F0; border-radius: 10px; font-size: 1rem; font-weight: 500; color: #1A202C; transition: all 0.3s ease; }
.pos-search-input:focus { outline: none; border-color: #6B46C1; box-shadow: 0 0 0 4px rgba(107, 70, 193, 0.15); }
.search-results-container { background: #fff; border-radius: 12px; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15); border: 2px solid #6B46C1; overflow: hidden; }
.search-results-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; background: linear-gradient(135deg, #6B46C1, #553C9A); color: #fff; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; }
.btn-close-results { background: rgba(255, 255, 255, 0.2); border: none; color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.search-results-list { max-height: 400px; overflow-y: auto; padding: 8px; }
.product-item { padding: 16px 18px; border: 2px solid #E2E8F0; border-radius: 10px; margin-bottom: 8px; cursor: pointer; transition: all 0.2s ease; background: #fff; display: flex; justify-content: space-between; align-items: center; gap: 16px; }
.product-item:hover { background: linear-gradient(135deg, rgba(107, 70, 193, 0.05), rgba(237, 137, 54, 0.03)); border-color: #6B46C1; transform: translateX(6px); box-shadow: 0 4px 15px rgba(107, 70, 193, 0.15); }
.product-item.selected { background: linear-gradient(135deg, #6B46C1, #553C9A); color: #fff; border-color: #553C9A; }
.product-item.selected .product-name, .product-item.selected .product-code, .product-item.selected .product-price { color: #fff; }
.product-item.out-of-stock { opacity: 0.5; cursor: not-allowed; background: #F7FAFC; }
.product-info { flex: 1; min-width: 0; }
.product-name { font-weight: 700; font-size: 0.95rem; color: #1A202C; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.product-code { font-size: 0.75rem; color: #718096; font-family: 'Courier New', monospace; font-weight: 600; }
.product-details { text-align: right; flex-shrink: 0; }
.product-price { font-weight: 700; font-size: 1rem; color: #6B46C1; margin-bottom: 4px; }
.stock-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; background: #EDF2F7; color: #4A5568; }
.stock-badge.stock-good { background: rgba(72, 187, 120, 0.15); color: #2F855A; }
.stock-badge.stock-low { background: rgba(237, 137, 54, 0.15); color: #C05621; }
.stock-badge.stock-out { background: rgba(229, 62, 62, 0.15); color: #C53030; }
.pos-cart-table { width: 100%; border-collapse: collapse; }
.pos-cart-table thead th { background: #F7FAFC; color: #4A5568; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; padding: 14px 16px; border-bottom: 2px solid #E2E8F0; text-align: left; }
.pos-cart-table tbody td { padding: 16px; border-bottom: 1px solid #EDF2F7; vertical-align: middle; font-size: 0.875rem; }
.cart-product-name { font-weight: 600; color: #1A202C; margin-bottom: 2px; }
.cart-qty-input { width: 70px; padding: 6px 8px; border: 2px solid #E2E8F0; border-radius: 6px; text-align: center; font-weight: 700; font-size: 0.9rem; }
.btn-remove-item { width: 32px; height: 32px; border-radius: 8px; background: rgba(229, 62, 62, 0.1); color: #E53E3E; border: none; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
.empty-cart { text-align: center; padding: 60px 20px; color: #A0AEC0; }
.empty-cart i { font-size: 4rem; margin-bottom: 16px; opacity: 0.3; color: #6B46C1; }
.summary-card { position: sticky; top: 80px; }
.form-label-cyber { display: flex; align-items: center; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #4A5568; margin-bottom: 8px; }
.form-label-cyber i { color: #6B46C1; }
.form-control-cyber, .form-select-cyber { border: 2px solid #E2E8F0; border-radius: 8px; padding: 10px 14px; font-size: 0.9rem; }
.totals-display { background: #F7FAFC; border-radius: 10px; padding: 16px; margin-bottom: 16px; border: 1px solid #E2E8F0; }
.total-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px dashed #E2E8F0; }
.total-row:last-child { border-bottom: none; }
.total-label { font-size: 0.85rem; color: #4A5568; font-weight: 600; }
.total-value { font-size: 0.95rem; color: #1A202C; font-weight: 700; font-family: 'Courier New', monospace; }
.discount-input-wrapper { display: flex; align-items: center; gap: 6px; }
.currency-prefix { font-size: 0.75rem; color: #6B46C1; font-weight: 800; }
.discount-input { width: 100px; padding: 6px 10px; border: 2px solid #E2E8F0; border-radius: 6px; font-size: 0.85rem; font-weight: 700; text-align: right; font-family: 'Courier New', monospace; }
.grand-total-box { background: linear-gradient(135deg, #1A202C, #2D3748); border-radius: 10px; padding: 20px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; }
.grand-total-label { color: #ED8936; font-weight: 800; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 2px; }
.grand-total-value { color: #fff; font-weight: 800; font-size: 1.25rem; font-family: 'Courier New', monospace; }
.btn-auto-fill { background: rgba(237, 137, 54, 0.15); color: #C05621; border: none; padding: 4px 10px; border-radius: 6px; font-size: 0.7rem; font-weight: 700; cursor: pointer; }
.amount-input-wrapper { display: flex; align-items: center; background: #F7FAFC; border: 2px solid #E2E8F0; border-radius: 8px; overflow: hidden; }
.amount-input-wrapper .currency-prefix { padding: 0 12px; background: rgba(72, 187, 120, 0.1); color: #2F855A; height: 100%; display: flex; align-items: center; border-right: 1px solid #E2E8F0; }
.amount-input { flex: 1; border: none; background: transparent; padding: 12px 16px; font-size: 1.1rem; font-weight: 700; font-family: 'Courier New', monospace; color: #1A202C; outline: none; }
.change-display { display: flex; justify-content: space-between; align-items: center; background: rgba(72, 187, 120, 0.1); border: 2px solid rgba(72, 187, 120, 0.3); border-radius: 10px; padding: 14px 18px; margin-top: 12px; }
.change-label { font-size: 0.85rem; font-weight: 700; color: #2F855A; text-transform: uppercase; }
.change-value { font-size: 1.1rem; font-weight: 800; font-family: 'Courier New', monospace; color: #2F855A; }
.credit-notice { display: flex; gap: 12px; background: rgba(237, 137, 54, 0.1); border-left: 4px solid #ED8936; border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; }
.credit-notice-icon { width: 36px; height: 36px; background: #ED8936; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; }
.credit-notice-content strong { display: block; color: #C05621; font-size: 0.85rem; font-weight: 700; margin-bottom: 2px; }
.credit-notice-content p { margin: 0; font-size: 0.75rem; color: #744210; }
.btn-complete-sale { width: 100%; padding: 18px 24px; background: linear-gradient(135deg, #48BB78, #38A169); color: #fff; border: none; border-radius: 12px; font-size: 1rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; position: relative; transition: all 0.3s ease; box-shadow: 0 6px 20px rgba(72, 187, 120, 0.35); margin-top: 8px; }
.btn-complete-sale:hover:not(:disabled) { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(72, 187, 120, 0.5); }
.btn-complete-sale:disabled { background: #CBD5E0; cursor: not-allowed; box-shadow: none; opacity: 0.6; }
.payment-select-compact { width: 100%; padding: 12px 16px; border: 2px solid #E2E8F0; border-radius: 10px; font-size: 0.95rem; font-weight: 600; color: #1A202C; background: #F7FAFC; cursor: pointer; transition: all 0.2s ease; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236B46C1' d='M6 9L1 4h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 16px center; padding-right: 40px; }
.payment-select-compact:focus { outline: none; border-color: #6B46C1; background-color: #fff; box-shadow: 0 0 0 3px rgba(107, 70, 193, 0.15); }
</style>

<?php if ($auto_print_sale_id > 0): ?>
<!-- Auto-Print Hidden Iframe (Optimized for Thermal Receipts) -->
<div id="printContainer" style="position: fixed; left: -10000px; top: 0; width: 80mm; height: 1000px; overflow: hidden; visibility: hidden;">
    <?php $print_page = ($auto_print_type === 'credit') ? 'credit-invoice.php' : 'receipt.php'; ?>
    <iframe id="printFrame" src="<?php echo $print_page; ?>?id=<?php echo $auto_print_sale_id; ?>&autoprint=1"
            style="width: 80mm; height: 1000px; border: none; background: #fff;"
            onload="this.style.visibility='visible';"></iframe>
</div>

<script>
(function() {
    const saleId = <?php echo $auto_print_sale_id; ?>;
    console.log('🖨️ Auto-print triggered for sale:', saleId);

    const frame = document.getElementById('printFrame');
    const wrap  = document.getElementById('printContainer');
    let printed = false;

    setTimeout(function() {
        if (window.history.replaceState) {
            const cleanUrl = window.location.pathname;
            window.history.replaceState({}, document.title, cleanUrl);
        }
    }, 1000);

    const killTimer = setTimeout(function() {
        if (wrap) wrap.remove();
    }, 20000);

    window.addEventListener('message', function(e) {
        if (!e.data || e.data.source !== 'receipt') return;
        if (e.data.action === 'printed' || e.data.action === 'closed') {
            printed = true;
            clearTimeout(killTimer);
            setTimeout(function() { 
                if (wrap) wrap.remove(); 
            }, 1500);
        }
    });

    if (frame) {
        frame.addEventListener('error', function() {
            console.error('🖨️ Receipt iframe failed to load');
            if (wrap) wrap.remove();
        });
    }
})();
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<script>
(function() {
    'use strict';
    function initSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const collapseBtn = document.getElementById('sidebarCollapseBtn');
        const desktopToggle = document.getElementById('sidebarDesktopToggle');
        const mobileToggles = document.querySelectorAll('.sidebar-toggle');
        if (!sidebar) return;

        if (localStorage.getItem('cyberSidebarCollapsed') === '1' && window.innerWidth >= 992) {
            sidebar.classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed');
        }

        function toggle() {
            if (window.innerWidth < 992) return;
            sidebar.classList.toggle('collapsed');
            document.body.classList.toggle('sidebar-collapsed');
            localStorage.setItem('cyberSidebarCollapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
            setTimeout(() => window.dispatchEvent(new Event('resize')), 350);
        }

        if (collapseBtn) collapseBtn.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); toggle(); });
        if (desktopToggle) desktopToggle.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); toggle(); });

        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') { e.preventDefault(); toggle(); }
        });

        mobileToggles.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault(); e.stopPropagation();
                sidebar.classList.toggle('show');
                if (overlay) overlay.classList.toggle('show');
            });
        });

        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('show'); overlay.classList.remove('show');
            });
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initSidebar);
    else initSidebar();
})();

let cart = [];
let storeId = null;
let selectedProductIndex = -1;
let productsData = [];
let searchTimeout;

function updateClock() {
    const el = document.getElementById('live-clock');
    if (el) el.textContent = new Date().toLocaleTimeString('en-US', { hour12: false });
}

document.addEventListener('DOMContentLoaded', function() {
    setInterval(updateClock, 1000);
    updateClock();
    loadCustomers();
    setupStoreSelector();
    setupPaymentMethods();
    setupProductSearch();
    setupDiscount();
    setupAmountPaid();
    setupClearCart();
    setupFormSubmit();
    setupAutoFill();
});

function loadCustomers() {
    const el = document.getElementById('customer-select');
    if (!el) return;
    fetch('../includes/ajax_customers.php').then(r => r.json()).then(data => {
        if (Array.isArray(data)) {
            data.forEach(c => {
                const o = document.createElement('option');
                o.value = c.customer_id;
                o.textContent = c.customer_name + (c.phone ? ' - ' + c.phone : '');
                el.appendChild(o);
            });
        }
    }).catch(e => console.error(e));
}

function handleAmountKeydown(e) {
    const allowed = ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'Home', 'End', 'ArrowLeft', 'ArrowRight'];
    if (allowed.includes(e.key)) return true;
    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); return false; }
    if ((e.ctrlKey || e.metaKey) && ['a', 'c', 'v', 'x', 'z'].includes(e.key.toLowerCase())) return true;
    if (/[0-9.]/.test(e.key)) {
        if (e.key === '.' && e.target.value.includes('.')) { e.preventDefault(); return false; }
        return true;
    }
    e.preventDefault(); return false;
}

function sanitizeAmountInput(input) {
    let val = input.value.replace(/[^0-9.]/g, '');
    const parts = val.split('.');
    if (parts.length > 2) val = parts[0] + '.' + parts.slice(1).join('');
    if (parts[1] && parts[1].length > 2) val = parts[0] + '.' + parts[1].substring(0, 2);
    if (val === '' || val === '.') val = '0';
    input.value = val;
}

document.addEventListener('DOMContentLoaded', function() {
    ['amount-paid', 'discount-input'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('blur', function() {
            sanitizeAmountInput(this);
            if (id === 'amount-paid') { calculateChange(); updateCompleteButton(); }
            else { updateTotals(); }
        });
        el.addEventListener('wheel', function(e) { e.preventDefault(); this.blur(); }, { passive: false });
        el.addEventListener('paste', function(e) {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text');
            this.value = text.replace(/[^0-9.]/g, '');
            if (id === 'amount-paid') { calculateChange(); updateCompleteButton(); }
            else { updateTotals(); }
        });
    });
});

function setupStoreSelector() {
    const el = document.getElementById('store-select');
    const badge = document.getElementById('store-status');
    const hidden = document.getElementById('store-id-hidden');
    if (!el) return;
    if (el.value) {
        storeId = el.value;
        if (hidden) hidden.value = storeId;
        updateStoreStatus(storeId, badge);
    }
    el.addEventListener('change', function() {
        storeId = this.value;
        if (hidden) hidden.value = storeId;
        updateStoreStatus(storeId, badge);
        updateCompleteButton();
    });
}

function updateStoreStatus(id, badge) {
    if (id) {
        badge.innerHTML = '<span class="status-dot-active"></span><span>Store Active</span>';
        badge.style.background = 'rgba(72, 187, 120, 0.15)';
        badge.style.color = '#2F855A';
        const s = document.getElementById('product-search');
        if (s) s.focus();
    } else {
        badge.innerHTML = '<span class="status-dot-inactive"></span><span>Not Selected</span>';
        badge.style.background = '#EDF2F7';
        badge.style.color = '#718096';
    }
}

function setupPaymentMethods() {
    const el = document.getElementById('payment-method');
    if (!el) return;
    el.addEventListener('change', function() {
        updatePaymentUI(this.value);
        updateCompleteButton();
    });
}

function updatePaymentUI(pm) {
    const paySec = document.getElementById('payment-section');
    const credit = document.getElementById('credit-notice');
    const custReq = document.getElementById('customer-required');
    const txSec = document.getElementById('transaction-id-section');
    const txInput = document.getElementById('transaction-id');
    const paid = document.getElementById('amount-paid');

    if (pm === 'credit') {
        paySec.style.display = 'block'; credit.style.display = 'flex'; custReq.style.display = 'inline-block';
        txSec.style.display = 'none'; txInput.removeAttribute('required');
        paid.value = 0; paid.removeAttribute('required');
    } else if (pm === 'mobile_money') {
        paySec.style.display = 'block'; credit.style.display = 'none'; custReq.style.display = 'none';
        txSec.style.display = 'block'; txInput.setAttribute('required', 'required');
        paid.setAttribute('required', 'required');
    } else {
        paySec.style.display = 'block'; credit.style.display = 'none'; custReq.style.display = 'none';
        txSec.style.display = 'none'; txInput.removeAttribute('required');
        paid.setAttribute('required', 'required');
    }
    calculateChange();
}

function setupProductSearch() {
    const el = document.getElementById('product-search');
    if (!el) return;
    el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (selectedProductIndex >= 0 && productsData[selectedProductIndex]) {
                const p = productsData[selectedProductIndex];
                if (p.quantity > 0) addToCart(p.product_id, p.product_name, p.selling_price, p.purchase_price, p.tax_rate, p.quantity);
            } else { searchProducts(); }
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault(); navigateProducts(e.key);
        } else if (e.key === 'Escape') { clearSearchResults(); }
    });
    el.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        if (this.value.length >= 2) searchTimeout = setTimeout(searchProducts, 300);
        else if (this.value.length === 0) clearSearchResults();
    });
}

function searchProducts() {
    const q = document.getElementById('product-search').value;
    const sid = document.getElementById('store-select').value;
    if (!sid) { showToast('Select a store', 'warning'); return; }
    if (q.length < 2) return;

    fetch('../includes/ajax_search_products.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'query=' + encodeURIComponent(q) + '&store_id=' + encodeURIComponent(sid)
    }).then(r => r.json()).then(data => {
        if (data.error) {
            document.getElementById('search-results').innerHTML = '<div style="padding:20px;color:#E53E3E;">' + data.error + '</div>';
            document.getElementById('search-results-container').style.display = 'block';
            return;
        }
        if (data.length === 0) {
            document.getElementById('search-results').innerHTML = '<div style="text-align:center;padding:40px;color:#A0AEC0;"><i class="fas fa-search" style="font-size:3rem;opacity:0.3;margin-bottom:16px;display:block;"></i><p>No products found</p></div>';
            document.getElementById('search-results-container').style.display = 'block';
            productsData = []; selectedProductIndex = -1;
            return;
        }
        productsData = data; selectedProductIndex = 0;
        renderProducts();
        document.getElementById('search-results-container').style.display = 'block';
    }).catch(e => { console.error(e); showToast('Search error', 'error'); });
}

function renderProducts() {
    let html = '';
    productsData.forEach((p, i) => {
        const out = p.quantity <= 0;
        const cls = i === selectedProductIndex ? 'selected' : '';
        const dis = out ? 'out-of-stock' : '';
        let stock = 'stock-good';
        if (out) stock = 'stock-out'; else if (p.quantity <= 10) stock = 'stock-low';

        const click = out ? '' : `onclick="addToCart(${p.product_id}, '${String(p.product_name).replace(/'/g, "\\'")}', ${p.selling_price}, ${p.purchase_price}, ${p.tax_rate}, ${p.quantity})"`;

        html += `<div class="product-item ${cls} ${dis}" ${click}>
            <div class="product-info">
                <div class="product-name">${p.product_name}</div>
                <div class="product-code"><i class="fas fa-barcode me-1"></i>${p.product_code}</div>
            </div>
            <div class="product-details">
                <div class="product-price">UGX ${parseFloat(p.selling_price).toFixed(2)}</div>
                <span class="stock-badge ${stock}">${out ? '❌ Out' : '📦 ' + p.quantity}</span>
            </div>
        </div>`;
    });
    document.getElementById('search-results').innerHTML = html;
}

function navigateProducts(key) {
    if (productsData.length === 0) return;
    if (key === 'ArrowDown') selectedProductIndex = (selectedProductIndex + 1) % productsData.length;
    else selectedProductIndex = selectedProductIndex <= 0 ? productsData.length - 1 : selectedProductIndex - 1;
    renderProducts();
    const sel = document.querySelector('.product-item.selected');
    if (sel) sel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function clearSearchResults() {
    document.getElementById('search-results-container').style.display = 'none';
    document.getElementById('search-results').innerHTML = '';
    productsData = []; selectedProductIndex = -1;
}

function addToCart(id, name, price, cost, tax, avail) {
    if (avail <= 0) { showToast('Out of stock', 'warning'); return; }
    const existing = cart.find(i => i.product_id === id);
    if (existing) {
        if (existing.quantity < avail) { existing.quantity++; showToast(`${name} x${existing.quantity}`, 'success'); }
        else { showToast('Stock limit reached', 'warning'); return; }
    } else {
        cart.push({ product_id: id, product_name: name, price: parseFloat(price), cost: parseFloat(cost), tax_rate: parseFloat(tax), quantity: 1, available_qty: parseInt(avail) });
        showToast(`${name} added`, 'success');
    }
    updateCart();
    clearSearchResults();
    const s = document.getElementById('product-search');
    if (s) { s.value = ''; s.focus(); }
}

function removeFromCart(i) {
    const item = cart[i];
    if (!item) return;
    cart.splice(i, 1);
    updateCart();
    showToast(`${item.product_name} removed`, 'info');
}

function updateQuantity(i, qty) {
    qty = parseInt(qty);
    if (qty > 0 && qty <= cart[i].available_qty) { cart[i].quantity = qty; updateCart(); }
    else if (qty > cart[i].available_qty) { showToast('Exceeds stock', 'warning'); updateCart(); }
}

function updateCart() {
    const box = document.getElementById('cart-items');
    document.getElementById('cart-count').textContent = cart.length;

    if (cart.length === 0) {
        box.innerHTML = `<tr><td colspan="6"><div class="empty-cart"><i class="fas fa-shopping-basket"></i><h5>Cart is Empty</h5><p>Search and add products</p></div></td></tr>`;
    } else {
        let html = '';
        cart.forEach((item, i) => {
            const taxAmt = item.price * item.quantity * item.tax_rate / 100;
            item.tax_amount = taxAmt;
            const sub = item.price * item.quantity;
            html += `<tr>
                <td><div class="cart-product-name">${item.product_name}</div></td>
                <td><span style="font-family:'Courier New',monospace;font-weight:600;">${item.price.toFixed(2)}</span></td>
                <td><input type="number" class="cart-qty-input" value="${item.quantity}" min="1" max="${item.available_qty}" onchange="updateQuantity(${i}, this.value)"></td>
                <td><span style="font-family:'Courier New',monospace;color:#718096;font-size:0.8rem;">${taxAmt.toFixed(2)}</span></td>
                <td><span style="font-family:'Courier New',monospace;font-weight:700;color:#6B46C1;">${sub.toFixed(2)}</span></td>
                <td><button type="button" class="btn-remove-item" onclick="removeFromCart(${i})"><i class="fas fa-trash"></i></button></td>
            </tr>`;
        });
        box.innerHTML = html;
    }
    updateTotals();
}

function updateTotals() {
    let sub = 0, tax = 0;
    cart.forEach(i => { sub += i.price * i.quantity; tax += i.tax_amount || 0; });
    const disc = parseFloat(document.getElementById('discount-input').value) || 0;
    const tot = Math.max(0, sub + tax - disc);
    document.getElementById('subtotal-display').textContent = 'UGX ' + sub.toFixed(2);
    document.getElementById('tax-display').textContent = 'UGX ' + tax.toFixed(2);
    document.getElementById('total-display').textContent = 'UGX ' + tot.toFixed(2);
    document.getElementById('total-value').value = tot.toFixed(2);
    calculateChange();
    updateCompleteButton();
}

function calculateChange() {
    const tot = parseFloat(document.getElementById('total-value').value) || 0;
    const paid = parseFloat(document.getElementById('amount-paid').value) || 0;
    const ch = paid - tot;
    const cc = document.getElementById('change-container');
    if (ch > 0) { document.getElementById('change-display').textContent = 'UGX ' + ch.toFixed(2); cc.style.display = 'flex'; }
    else { cc.style.display = 'none'; }
}

function updateCompleteButton() {
    const btn = document.getElementById('complete-sale-btn');
    const pm = document.getElementById('payment-method').value;
    const tot = parseFloat(document.getElementById('total-value').value) || 0;
    const paid = parseFloat(document.getElementById('amount-paid').value) || 0;
    const err = document.getElementById('amount-error');
    let ok = !!storeId && cart.length > 0 && pm !== '';
    if (pm && pm !== 'credit') {
        if (paid < tot) { err.classList.remove('d-none'); ok = false; }
        else err.classList.add('d-none');
    } else err.classList.add('d-none');
    btn.disabled = !ok;
}

function setupDiscount() { const el = document.getElementById('discount-input'); if (el) el.addEventListener('input', updateTotals); }
function setupAmountPaid() { const el = document.getElementById('amount-paid'); if (el) el.addEventListener('input', function() { calculateChange(); updateCompleteButton(); }); }
function setupAutoFill() {
    const btn = document.getElementById('auto-fill-btn');
    if (btn) btn.addEventListener('click', function() {
        const t = parseFloat(document.getElementById('total-value').value) || 0;
        document.getElementById('amount-paid').value = t.toFixed(2);
        calculateChange(); updateCompleteButton();
    });
}
function setupClearCart() {
    const btn = document.getElementById('clear-cart');
    if (!btn) return;
    btn.addEventListener('click', function() {
        if (cart.length === 0) return;
        if (confirm('Clear cart?')) { cart = []; updateCart(); showToast('Cart cleared', 'info'); }
    });
}
function setupFormSubmit() {
    const form = document.getElementById('sale-form');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        if (!storeId) { e.preventDefault(); showToast('Select a store', 'warning'); return false; }
        if (cart.length === 0) { e.preventDefault(); showToast('Cart is empty', 'warning'); return false; }
        const pm = document.getElementById('payment-method').value;
        if (!pm) { e.preventDefault(); showToast('Select payment method', 'warning'); return false; }
        if (pm === 'credit' && !document.getElementById('customer-select').value) { e.preventDefault(); showToast('Customer required for credit', 'warning'); return false; }
        if (pm === 'mobile_money' && !document.getElementById('transaction-id').value) { e.preventDefault(); showToast('Transaction ID required', 'warning'); return false; }
        const tot = parseFloat(document.getElementById('total-value').value) || 0;
        const paid = parseFloat(document.getElementById('amount-paid').value) || 0;
        if (pm !== 'credit' && paid < tot) { e.preventDefault(); showToast('Amount paid < total', 'warning'); return false; }
        document.getElementById('store-id-hidden').value = storeId;
        document.getElementById('cart-items-input').value = JSON.stringify(cart);
        const sb = document.getElementById('complete-sale-btn');
        sb.disabled = true;
        sb.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    });
}

function showToast(msg, type) {
    type = type || 'info';
    const icons = { success: 'check-circle', warning: 'exclamation-triangle', error: 'times-circle', info: 'info-circle' };
    const colors = { success: '#48BB78', warning: '#ED8936', error: '#E53E3E', info: '#6B46C1' };
    const ex = document.querySelector('.pos-toast');
    if (ex) ex.remove();
    const t = document.createElement('div');
    t.className = 'pos-toast';
    t.style.cssText = `position:fixed; top:80px; right:20px; background:#fff; padding:14px 20px; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,0.2); border-left:4px solid ${colors[type]}; display:flex; align-items:center; gap:12px; z-index:9999; min-width:250px; font-weight:600; color:#1A202C; font-family:'Inter',sans-serif;`;
    t.innerHTML = `<i class="fas fa-${icons[type]}" style="color:${colors[type]}; font-size:1.2rem;"></i><span>${msg}</span>`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2500);
}
</script>