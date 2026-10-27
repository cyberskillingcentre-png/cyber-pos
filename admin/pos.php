<?php
require_once '../config/config.php';
require_admin();

// ============================================
// AUTO-PRINT HANDLER
// ============================================
$auto_print_sale_id = isset($_GET['auto_print']) ? intval($_GET['auto_print']) : 0;
$auto_print_type    = $_GET['type'] ?? 'cash';

$page_title = "Point of Sale";

// ============================================
// AUTO-MIGRATE: Ensure required columns exist
// ============================================
try {
    $check = $conn->query("SHOW COLUMNS FROM sales LIKE 'sale_type'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE sales ADD COLUMN sale_type ENUM('cash','credit') DEFAULT 'cash' AFTER payment_method");
    }
    $check = $conn->query("SHOW COLUMNS FROM sales LIKE 'transaction_id'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE sales ADD COLUMN transaction_id VARCHAR(100) DEFAULT NULL AFTER amount_due");
    }
    $check = $conn->query("SHOW COLUMNS FROM sales LIKE 'updated_at'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE sales ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }
    $check = $conn->query("SHOW COLUMNS FROM sale_items LIKE 'profit'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE sale_items ADD COLUMN profit DECIMAL(15,2) DEFAULT 0 AFTER total_price");
    }
    $check = $conn->query("SHOW COLUMNS FROM products LIKE 'barcode'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE products ADD COLUMN barcode VARCHAR(100) DEFAULT NULL AFTER product_code");
    }
    $check = $conn->query("SHOW COLUMNS FROM store_inventory LIKE 'status'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE store_inventory ADD COLUMN status ENUM('good','damaged','expired','quarantine') NOT NULL DEFAULT 'good' AFTER quantity");
        $conn->exec("ALTER TABLE store_inventory ADD INDEX idx_si_status (status)");
    }
} catch(PDOException $e) {}

// ============================================
// LOAD SALES STORES ONLY
// ============================================
$stmt = $conn->prepare("
    SELECT * FROM stores
    WHERE status = 'active'
      AND store_type = 'sales'
    ORDER BY store_name
");
$stmt->execute();
$stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            SELECT store_id, store_name
            FROM stores
            WHERE store_id = ? AND status = 'active' AND store_type = 'sales'
        ");
        $check->execute([$store_id]);
        $valid_store = $check->fetch(PDO::FETCH_ASSOC);
        if (!$valid_store) {
            throw new Exception('Selected store is invalid, inactive, or is a damages store.');
        }

        $customer_id    = !empty($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
        $payment_method = $_POST['payment_method'] ?? '';
        $amount_paid    = floatval($_POST['amount_paid'] ?? 0);
        $transaction_id = !empty($_POST['transaction_id']) ? trim($_POST['transaction_id']) : null;
        $discount_amount= isset($_POST['discount_amount']) ? floatval($_POST['discount_amount']) : 0;
        $cart_items     = json_decode($_POST['cart_items'] ?? '[]', true);

        if (empty($cart_items)) throw new Exception('Cart is empty.');
        if (empty($payment_method)) throw new Exception('Please select a payment method.');
        if (!in_array($payment_method, $payment_methods, true)) {
            throw new Exception('Invalid payment method.');
        }

        $sale_type = ($payment_method === 'credit') ? 'credit' : 'cash';
        if ($sale_type === 'credit' && !$customer_id) throw new Exception('Customer is required for credit sales');

        $subtotal = 0;
        $tax_amount = 0;
        foreach ($cart_items as $item) {
            $subtotal   += $item['quantity'] * $item['price'];
            $tax_amount += $item['tax_amount'] ?? 0;
        }
        $total_amount = $subtotal + $tax_amount - $discount_amount;

        if ($payment_method !== 'credit' && $amount_paid < $total_amount) {
            throw new Exception('Amount paid cannot be less than total amount');
        }

        $amount_due     = max(0, $total_amount - $amount_paid);
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
            $quantity   = intval($item['quantity']);
            $price      = floatval($item['price']);
            $cost       = floatval($item['cost']);
            $tax        = floatval($item['tax_amount'] ?? 0);
            $line_total = $quantity * $price;
            $profit     = ($price - $cost) * $quantity;

            $stmt = $conn->prepare("
                INSERT INTO sale_items
                (sale_id, product_id, quantity, unit_price, discount_amount, tax_amount, total_price, profit)
                VALUES (?, ?, ?, ?, 0, ?, ?, ?)
            ");
            $stmt->execute([$sale_id, $product_id, $quantity, $price, $tax, $line_total, $profit]);

            // FIFO inventory deduction — only 'good' stock
            $remaining_qty = $quantity;
            $stmt = $conn->prepare("
                SELECT inventory_id, quantity
                FROM store_inventory
                WHERE store_id = ? AND product_id = ? AND quantity > 0 AND status = 'good'
                ORDER BY last_updated ASC, inventory_id ASC
            ");
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
            log_activity($_SESSION['user_id'], 'SALE_CREATED', "Created sale $invoice_number", $conn);
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

        <div class="row g-2">
            <div class="col-lg-8">
                <!-- Store Selection -->
                <div class="card cyber-card mb-2">
                    <div class="card-body py-2">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div class="store-icon-badge"><i class="fas fa-store"></i></div>
                            <div class="flex-grow-1">
                                <label class="form-label-cyber mb-1" style="font-size: 0.65rem;">
                                    <i class="fas fa-map-marker-alt me-1"></i>Active Store
                                </label>
                                <select id="store-select" class="form-select form-select-cyber" required>
                                    <option value="">-- Select Store to Begin --</option>
                                    <?php foreach ($stores as $store): ?>
                                        <option value="<?php echo (int)$store['store_id']; ?>">
                                            <?php echo htmlspecialchars($store['store_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="store-status" class="store-status-badge">
                                <span class="status-dot-inactive"></span>
                                <span>Not Selected</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product Search / Barcode -->
                <div class="pos-search-card mb-2">
                    <div class="search-input-wrapper">
                        <div class="search-icon"><i class="fas fa-barcode"></i></div>
                        <input type="text" id="product-search" class="pos-search-input"
                               placeholder="Scan barcode or search by name/code..."
                               autofocus autocomplete="off">
                        <div class="search-hints">
                            <span class="hint-key">Enter</span>
                            <span class="hint-text">Add</span>
                        </div>
                    </div>
                </div>

                <!-- Search Results -->
                <div id="search-results-container" class="search-results-container mb-2" style="display:none;">
                    <div class="search-results-header">
                        <span><i class="fas fa-search me-2"></i>Search Results</span>
                        <button type="button" class="btn-close-results" onclick="clearSearchResults()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div id="search-results" class="search-results-list"></div>
                </div>

                <!-- Cart -->
                <div class="card cyber-card">
                    <div class="card-header d-flex justify-content-between align-items-center py-2">
                        <span>
                            <i class="fas fa-shopping-cart me-2"></i>
                            Shopping Cart
                            <span class="badge bg-warning text-dark ms-2" id="cart-count">0</span>
                        </span>
                        <button type="button" class="btn btn-sm btn-danger" id="clear-cart" style="padding: 2px 8px; font-size: 0.75rem;">
                            <i class="fas fa-trash-alt"></i> Clear
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
                                                <p>Scan a barcode or search to begin</p>
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
                    <div class="card-header py-2">
                        <i class="fas fa-calculator me-2"></i>Sale Summary
                    </div>
                    <div class="card-body p-3">
                        <form id="sale-form" method="POST">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="store_id" id="store-id-hidden" value="">

                            <div class="mb-2">
                                <label class="form-label-cyber mb-1">
                                    <i class="fas fa-user me-1"></i>Customer
                                    <span class="badge bg-danger ms-2" id="customer-required" style="display:none; font-size:0.6rem;">Required</span>
                                </label>
                                <select name="customer_id" id="customer-select" class="form-select form-select-cyber form-select-sm">
                                    <option value="">🚶 Walk-in Customer</option>
                                </select>
                            </div>

                            <div class="totals-display mb-2">
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
                                        <!-- FIXED: Using text/inputmode to prevent mouse wheel increments -->
                                        <input type="text" inputmode="decimal" name="discount_amount" id="discount-input"
                                               class="discount-input" value="0" autocomplete="off" onwheel="this.blur()" onkeydown="return handleAmountKeydown(event)">
                                    </div>
                                </div>
                            </div>

                            <div class="grand-total-box mb-2">
                                <span class="grand-total-label">TOTAL</span>
                                <span class="grand-total-value" id="total-display">UGX 0</span>
                                <input type="hidden" id="total-value" value="0">
                            </div>

                            <!-- Payment Method -->
                            <div class="mb-2">
                                <label class="form-label-cyber mb-1">
                                    <i class="fas fa-credit-card me-1"></i>Payment Method
                                </label>
                                <select name="payment_method" id="payment-method" class="form-select payment-select-compact" required>
                                    <option value="">-- Select Payment Method --</option>
                                    <option value="cash">💵 Cash</option>
                                    <option value="card">💳 Card</option>
                                    <option value="mobile_money">📱 Mobile Money</option>
                                    <option value="bank_transfer">🏦 Bank Transfer</option>
                                    <option value="credit">📋 Credit (Pay Later)</option>
                                </select>
                            </div>

                            <div id="transaction-id-section" class="mb-2" style="display:none;">
                                <label class="form-label-cyber mb-1">
                                    <i class="fas fa-hashtag me-1"></i>Transaction ID
                                    <span class="badge bg-danger ms-2" style="font-size:0.6rem;">Required</span>
                                </label>
                                <input type="text" name="transaction_id" id="transaction-id"
                                       class="form-control form-control-cyber form-control-sm"
                                       placeholder="Enter transaction ID">
                            </div>

                            <div id="payment-section">
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label-cyber mb-0">
                                            <i class="fas fa-money-bill me-1"></i>Amount Paid
                                        </label>
                                        <button type="button" class="btn-auto-fill" id="auto-fill-btn">
                                            <i class="fas fa-magic me-1"></i>Fill Total
                                        </button>
                                    </div>
                                    <div class="amount-input-wrapper">
                                        <span class="currency-prefix">UGX</span>
                                        <!-- FIXED: Using text/inputmode to prevent mouse wheel increments -->
                                        <input type="text" inputmode="decimal" name="amount_paid" id="amount-paid"
                                               class="amount-input" value="0" required autocomplete="off" onwheel="this.blur()" onkeydown="return handleAmountKeydown(event)">
                                    </div>
                                    <small class="text-danger d-none" id="amount-error" style="font-size:0.65rem;">
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

                            <button type="submit" class="btn-complete-sale" id="complete-sale-btn" disabled>
                                <i class="fas fa-check-circle"></i>
                                <span>Complete Sale</span>
                                <span class="btn-shortcut">F12</span>
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
/* ... (All your CSS styles remain exactly the same) ... */
.pos-content { background: linear-gradient(135deg, #F7FAFC 0%, #EDF2F7 100%); min-height: calc(100vh - 60px); padding: 15px !important; }
.pos-clock { display: flex; align-items: center; gap: 6px; padding: 4px 10px; background: rgba(72, 187, 120, 0.15); border-radius: 20px; color: #2F855A; font-weight: 600; font-family: 'Courier New', monospace; font-size: 0.8rem; }
.user-badge { display: flex; align-items: center; gap: 6px; padding: 4px 12px; background: linear-gradient(135deg, #6B46C1, #553C9A); color: #fff; border-radius: 30px; font-size: 0.75rem; font-weight: 600; box-shadow: 0 4px 15px rgba(107, 70, 193, 0.3); }
.store-icon-badge { width: 36px; height: 36px; background: linear-gradient(135deg, #6B46C1, #553C9A); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1rem; flex-shrink: 0; box-shadow: 0 4px 15px rgba(107, 70, 193, 0.3); }
.store-status-badge { display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: #EDF2F7; border-radius: 20px; font-size: 0.75rem; font-weight: 600; color: #718096; white-space: nowrap; }
.status-dot-inactive { width: 6px; height: 6px; background: #A0AEC0; border-radius: 50%; }
.status-dot-active { width: 6px; height: 6px; background: #48BB78; border-radius: 50%; animation: pulse-dot 2s infinite; }
@keyframes pulse-dot { 0%, 100% { box-shadow: 0 0 0 0 rgba(72, 187, 120, 0.5); } 50% { box-shadow: 0 0 0 5px rgba(72, 187, 120, 0); } }
.pos-search-card { background: #fff; border-radius: 10px; padding: 10px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04); border: 1px solid #E2E8F0; }
.search-input-wrapper { position: relative; display: flex; align-items: center; }
.search-icon { position: absolute; left: 15px; color: #6B46C1; font-size: 1rem; z-index: 2; }
.pos-search-input { width: 100%; padding: 12px 15px 12px 42px; border: 2px solid #E2E8F0; border-radius: 8px; font-size: 0.9rem; font-weight: 500; color: #1A202C; transition: all 0.3s ease; }
.pos-search-input:focus { outline: none; border-color: #6B46C1; box-shadow: 0 0 0 3px rgba(107, 70, 193, 0.15); }
.search-hints { position: absolute; right: 12px; display: flex; align-items: center; gap: 4px; font-size: 0.65rem; color: #A0AEC0; }
.hint-key { padding: 2px 6px; background: #EDF2F7; border-radius: 4px; font-family: 'Courier New', monospace; font-weight: 700; font-size: 0.6rem; color: #4A5568; border: 1px solid #E2E8F0; }
.search-results-container { background: #fff; border-radius: 10px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15); border: 2px solid #6B46C1; overflow: hidden; }
.search-results-header { display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; background: linear-gradient(135deg, #6B46C1, #553C9A); color: #fff; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; }
.btn-close-results { background: rgba(255, 255, 255, 0.2); border: none; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.search-results-list { max-height: 350px; overflow-y: auto; padding: 6px; }
.product-item { padding: 10px 12px; border: 1px solid #E2E8F0; border-radius: 8px; margin-bottom: 6px; cursor: pointer; transition: all 0.2s ease; background: #fff; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.product-item:hover { background: linear-gradient(135deg, rgba(107, 70, 193, 0.05), rgba(237, 137, 54, 0.03)); border-color: #6B46C1; transform: translateX(4px); box-shadow: 0 4px 15px rgba(107, 70, 193, 0.15); }
.product-item.selected { background: linear-gradient(135deg, #6B46C1, #553C9A); color: #fff; border-color: #553C9A; }
.product-item.selected .product-name,
.product-item.selected .product-code,
.product-item.selected .product-price { color: #fff; }
.product-item.out-of-stock { opacity: 0.5; cursor: not-allowed; background: #F7FAFC; }
.product-info { flex: 1; min-width: 0; }
.product-name { font-weight: 700; font-size: 0.85rem; color: #1A202C; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.product-code { font-size: 0.7rem; color: #718096; font-family: 'Courier New', monospace; font-weight: 600; }
.product-details { text-align: right; flex-shrink: 0; }
.product-price { font-weight: 700; font-size: 0.9rem; color: #6B46C1; margin-bottom: 2px; }
.stock-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.65rem; font-weight: 700; background: #EDF2F7; color: #4A5568; }
.stock-badge.stock-good { background: rgba(72, 187, 120, 0.15); color: #2F855A; }
.stock-badge.stock-low { background: rgba(237, 137, 54, 0.15); color: #C05621; }
.stock-badge.stock-out { background: rgba(229, 62, 62, 0.15); color: #C53030; }
.pos-cart-table { width: 100%; border-collapse: collapse; }
.pos-cart-table thead th { background: #F7FAFC; color: #4A5568; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; padding: 10px 12px; border-bottom: 2px solid #E2E8F0; text-align: left; }
.pos-cart-table tbody td { padding: 10px 12px; border-bottom: 1px solid #EDF2F7; vertical-align: middle; font-size: 0.8rem; }
.cart-qty-input { width: 60px; padding: 4px 6px; border: 1px solid #E2E8F0; border-radius: 6px; text-align: center; font-weight: 700; font-size: 0.8rem; }
.btn-remove-item { width: 28px; height: 28px; border-radius: 6px; background: rgba(229, 62, 62, 0.1); color: #E53E3E; border: none; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
.btn-remove-item:hover { background: #E53E3E; color: #fff; }
.empty-cart { text-align: center; padding: 40px 15px; color: #A0AEC0; }
.empty-cart i { font-size: 3rem; margin-bottom: 10px; opacity: 0.3; color: #6B46C1; }
.summary-card { position: sticky; top: 80px; }
.form-label-cyber { display: flex; align-items: center; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #4A5568; margin-bottom: 4px; }
.form-label-cyber i { color: #6B46C1; }
.form-control-cyber, .form-select-cyber { border: 1px solid #E2E8F0; border-radius: 6px; padding: 8px 10px; font-size: 0.8rem; }
.totals-display { background: #F7FAFC; border-radius: 8px; padding: 10px 12px; border: 1px solid #E2E8F0; }
.total-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px dashed #E2E8F0; }
.total-row:last-child { border-bottom: none; padding-bottom: 0; }
.total-label { font-size: 0.75rem; color: #4A5568; font-weight: 600; }
.total-value { font-size: 0.85rem; color: #1A202C; font-weight: 700; font-family: 'Courier New', monospace; }
.discount-input-wrapper { display: flex; align-items: center; gap: 4px; }
.currency-prefix { font-size: 0.65rem; color: #6B46C1; font-weight: 800; }
.discount-input { width: 80px; padding: 4px 8px; border: 1px solid #E2E8F0; border-radius: 4px; font-size: 0.75rem; font-weight: 700; text-align: right; font-family: 'Courier New', monospace; }
.grand-total-box { background: linear-gradient(135deg, #1A202C, #2D3748); border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; }
.grand-total-label { color: #ED8936; font-weight: 800; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; }
.grand-total-value { color: #fff; font-weight: 800; font-size: 1.1rem; font-family: 'Courier New', monospace; }
.payment-select-compact { width: 100%; padding: 8px 12px; border: 1px solid #E2E8F0; border-radius: 6px; font-size: 0.8rem; font-weight: 600; color: #1A202C; background: #F7FAFC; cursor: pointer; transition: all 0.2s ease; appearance: none; -webkit-appearance: none; -moz-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236B46C1' d='M6 9L1 4h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 30px; }
.payment-select-compact:focus { outline: none; border-color: #6B46C1; background-color: #fff; box-shadow: 0 0 0 2px rgba(107, 70, 193, 0.15); }
.payment-select-compact:hover { border-color: #6B46C1; }
.btn-auto-fill { background: rgba(237, 137, 54, 0.15); color: #C05621; border: none; padding: 2px 8px; border-radius: 4px; font-size: 0.65rem; font-weight: 700; cursor: pointer; }
.amount-input-wrapper { display: flex; align-items: center; background: #F7FAFC; border: 1px solid #E2E8F0; border-radius: 6px; overflow: hidden; }
.amount-input-wrapper .currency-prefix { padding: 0 10px; background: rgba(72, 187, 120, 0.1); color: #2F855A; height: 100%; display: flex; align-items: center; border-right: 1px solid #E2E8F0; }
.amount-input { flex: 1; border: none; background: transparent; padding: 8px 12px; font-size: 1rem; font-weight: 700; font-family: 'Courier New', monospace; color: #1A202C; outline: none; }
.change-display { display: flex; justify-content: space-between; align-items: center; background: rgba(72, 187, 120, 0.1); border: 1px solid rgba(72, 187, 120, 0.3); border-radius: 6px; padding: 8px 12px; margin-top: 8px; }
.change-label { font-size: 0.75rem; font-weight: 700; color: #2F855A; text-transform: uppercase; }
.change-value { font-size: 0.9rem; font-weight: 800; font-family: 'Courier New', monospace; color: #2F855A; }
.credit-notice { display: flex; gap: 8px; background: rgba(237, 137, 54, 0.1); border-left: 3px solid #ED8936; border-radius: 6px; padding: 8px 12px; margin-bottom: 8px; }
.credit-notice-icon { width: 28px; height: 28px; background: #ED8936; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; }
.credit-notice-content strong { display: block; color: #C05621; font-size: 0.75rem; font-weight: 700; margin-bottom: 2px; }
.credit-notice-content p { margin: 0; font-size: 0.65rem; color: #744210; }
.btn-complete-sale { width: 100%; padding: 12px 16px; background: linear-gradient(135deg, #48BB78, #38A169); color: #fff; border: none; border-radius: 8px; font-size: 0.9rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; position: relative; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(72, 187, 120, 0.35); margin-top: 6px; }
.btn-complete-sale:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(72, 187, 120, 0.5); }
.btn-complete-sale:disabled { background: #CBD5E0; cursor: not-allowed; box-shadow: none; opacity: 0.6; }
.btn-shortcut { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); padding: 2px 6px; background: rgba(255, 255, 255, 0.2); border-radius: 4px; font-size: 0.6rem; font-weight: 700; font-family: 'Courier New', monospace; }
@media (max-width: 991.98px) {
    .summary-card { position: static; margin-top: 15px; }
    .search-hints { display: none; }
}
</style>

<?php if ($auto_print_sale_id > 0): ?>
<!-- Hidden Print Container -->
<div id="printContainer" style="position:fixed; left:-10000px; top:0; width:400px; height:800px; overflow:hidden; visibility:hidden;">
    <?php $print_page = ($auto_print_type === 'credit') ? 'credit-invoice.php' : 'receipt.php'; ?>
    <iframe id="printFrame"
            src="<?php echo $print_page; ?>?id=<?php echo (int)$auto_print_sale_id; ?>&autoprint=1"
            style="width:400px; height:800px; border:none; background:#fff;"
            onload="this.style.visibility='visible';"></iframe>
</div>

<script>
(function () {
    const frame = document.getElementById('printFrame');
    const wrap  = document.getElementById('printContainer');
    let printed = false;

    setTimeout(function () {
        if (window.history.replaceState) {
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }, 1000);

    const killTimer = setTimeout(function () {
        if (wrap) wrap.remove();
    }, 20000);

    window.addEventListener('message', function (e) {
        if (!e.data || e.data.source !== 'receipt') return;
        if (e.data.action === 'printed' || e.data.action === 'closed') {
            printed = true;
            clearTimeout(killTimer);
            setTimeout(function () { 
                if (wrap) wrap.remove(); 
            }, 1500);
        }
    });

    if (frame) {
        frame.addEventListener('error', function () {
            console.error('🖨️ Receipt iframe failed to load');
            if (wrap) wrap.remove();
        });
    }
})();
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<script>
/* ============================================================
   POS SYSTEM — COMPLETE JS
   ============================================================ */

let cart = [];
let storeId = null;
let selectedProductIndex = -1;
let productsData = [];
let selectedPaymentMethod = '';
let searchTimeout = null;

function updateClock() {
    const el = document.getElementById('live-clock');
    if (el) el.textContent = new Date().toLocaleTimeString('en-US', { hour12: false });
}

function requireStore() {
    if (!storeId) {
        showToast('Please select a store first', 'warning');
        const sel = document.getElementById('store-select');
        if (sel) sel.focus();
        return false;
    }
    return true;
}

function setupKeyboardShortcuts() {
    document.addEventListener('keydown', function (e) {
        if (e.key === 'F12') {
            e.preventDefault();
            const btn = document.getElementById('complete-sale-btn');
            if (btn && !btn.disabled) btn.click();
        }
        if (e.ctrlKey && e.key.toLowerCase() === 'b') {
            e.preventDefault();
            const t = document.getElementById('sidebarDesktopToggle');
            if (t) t.click();
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    setInterval(updateClock, 1000);
    updateClock();

    setupStoreSelector();
    loadCustomers();
    setupPaymentMethods();
    setupProductSearch();
    setupDiscount();
    setupAmountPaid();
    setupClearCart();
    setupFormSubmit();
    setupAutoFill();
    setupKeyboardShortcuts();
});

/* ============================================
   NEW HELPER: PREVENT MOUSE WHEEL & INVALID CHARS
   ============================================ */
function handleAmountKeydown(e) {
    // 1. Allow backspace, delete, tab, escape, enter, and arrows
    const allowedKeys = ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'Home', 'End', 'ArrowLeft', 'ArrowRight'];
    if (allowedKeys.includes(e.key)) return true;

    // 2. Block Up/Down arrow keys (which increment/decrement numbers)
    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
        e.preventDefault();
        return false;
    }

    // 3. Allow Ctrl+C, Ctrl+V, Ctrl+A, Ctrl+X
    if ((e.ctrlKey || e.metaKey) && ['a', 'c', 'v', 'x'].includes(e.key.toLowerCase())) return true;

    // 4. Allow numbers 0-9 and a single decimal point
    if (/[0-9.]/.test(e.key)) {
        if (e.key === '.' && e.target.value.includes('.')) {
            e.preventDefault(); // Prevent multiple decimals
            return false;
        }
        return true;
    }

    // 5. Block everything else (letters, symbols)
    e.preventDefault();
    return false;
}

function sanitizeAmountInput(input) {
    let value = input.value.replace(/[^0-9.]/g, ''); // Remove invalid chars
    const parts = value.split('.');
    
    if (parts.length > 2) value = parts[0] + '.' + parts.slice(1).join(''); // Only 1 dot
    if (parts[1] && parts[1].length > 2) value = parts[0] + '.' + parts[1].substring(0, 2); // Max 2 decimals
    
    if (value === '' || value === '.') value = '0'; // Default to 0
    input.value = value;
}

// Attach blur event to clean up the input
document.addEventListener('DOMContentLoaded', function() {
    ['amount-paid', 'discount-input'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('blur', function() {
                sanitizeAmountInput(this);
                if (id === 'amount-paid') { calculateChange(); updateCompleteButton(); }
                else { updateTotals(); }
            });
        }
    });
});
/* ============================================ */

function setupStoreSelector() {
    const sel = document.getElementById('store-select');
    const hidden = document.getElementById('store-id-hidden');
    if (!sel) return;

    if (sel.options.length === 2 && !sel.value) {
        sel.selectedIndex = 1;
    }

    storeId = sel.value || null;
    if (hidden) hidden.value = storeId || '';
    updateStoreBadge(!!storeId);

    sel.addEventListener('change', function () {
        storeId = this.value || null;
        if (hidden) hidden.value = storeId || '';
        updateStoreBadge(!!storeId);
        updateCompleteButton();
        if (storeId) {
            const s = document.getElementById('product-search');
            if (s) s.focus();
        }
    });
}

function updateStoreBadge(active) {
    const badge = document.getElementById('store-status');
    if (!badge) return;
    if (active) {
        badge.innerHTML = '<span class="status-dot-active"></span><span>Store Active</span>';
        badge.style.background = 'rgba(72, 187, 120, 0.15)';
        badge.style.color = '#2F855A';
    } else {
        badge.innerHTML = '<span class="status-dot-inactive"></span><span>Not Selected</span>';
        badge.style.background = '#EDF2F7';
        badge.style.color = '#718096';
    }
}

function loadCustomers() {
    const sel = document.getElementById('customer-select');
    if (!sel) return;
    fetch('../includes/ajax_customers.php')
        .then(r => r.text())
        .then(text => {
            let data;
            try { data = JSON.parse(text); } catch (e) { return; }
            if (!Array.isArray(data)) return;
            data.forEach(c => {
                const o = document.createElement('option');
                o.value = c.customer_id;
                o.textContent = c.customer_name + (c.phone ? ' - ' + c.phone : '');
                sel.appendChild(o);
            });
        })
        .catch(err => console.warn('Customers load failed:', err));
}

function setupPaymentMethods() {
    const sel = document.getElementById('payment-method');
    if (!sel) return;
    sel.addEventListener('change', function () {
        selectedPaymentMethod = this.value;
        updatePaymentUI(selectedPaymentMethod);
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
        paySec.style.display = 'block';
        credit.style.display = 'flex';
        custReq.style.display = 'inline-block';
        txSec.style.display = 'none';
        txInput.removeAttribute('required');
        paid.value = 0;
        paid.removeAttribute('required');
    } else if (pm === 'mobile_money') {
        paySec.style.display = 'block';
        credit.style.display = 'none';
        custReq.style.display = 'none';
        txSec.style.display = 'block';
        txInput.setAttribute('required', 'required');
        paid.setAttribute('required', 'required');
    } else {
        paySec.style.display = 'block';
        credit.style.display = 'none';
        custReq.style.display = 'none';
        txSec.style.display = 'none';
        txInput.removeAttribute('required');
        paid.setAttribute('required', 'required');
    }
    calculateChange();
}

function setupProductSearch() {
    const input = document.getElementById('product-search');
    if (!input) return;

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const q = this.value.trim();
            if (!q) return;
            if (!requireStore()) { this.value = ''; clearSearchResults(); return; }

            if (/^\d{8,13}$/.test(q)) { lookupByBarcode(q); return; }

            if (selectedProductIndex >= 0 && Array.isArray(productsData) && productsData[selectedProductIndex]) {
                const p = productsData[selectedProductIndex];
                const qty = parseInt(p.quantity) || 0;
                if (qty > 0) {
                    addToCart(p.product_id, p.product_name, p.selling_price, p.purchase_price, p.tax_rate, qty);
                } else {
                    showToast('Out of stock', 'warning');
                }
            } else {
                searchProducts();
            }
            return;
        }

        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            navigateProducts(e.key);
            return;
        }

        if (e.key === 'Escape') clearSearchResults();
    });

    input.addEventListener('input', function () {
        clearTimeout(searchTimeout);
        const q = this.value.trim();
        if (!storeId) { clearSearchResults(); return; }
        if (q.length === 0) { clearSearchResults(); return; }
        if (/^\d{8,}$/.test(q)) return;
        searchTimeout = setTimeout(() => searchProducts(), 200);
    });
}

function searchProducts() {
    const input = document.getElementById('product-search');
    const query = input.value.trim();

    if (!storeId) { showSearchMessage('⚠️ Please select a store first.', 'warning'); return; }
    if (query.length < 1) return;

    fetch('../includes/ajax_search_products.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'query=' + encodeURIComponent(query) + '&store_id=' + encodeURIComponent(storeId)
    })
    .then(async response => {
        const text = await response.text();
        if (!response.ok) throw new Error('HTTP ' + response.status + ' — ' + text.slice(0, 200));
        try { return JSON.parse(text); }
        catch (e) { throw new Error('Non-JSON: ' + text.slice(0, 200)); }
    })
    .then(data => {
        if (data && !Array.isArray(data)) {
            showSearchMessage('⚠️ ' + (data.error || data.message || 'Search failed'), 'danger');
            productsData = [];
            selectedProductIndex = -1;
            return;
        }
        const products = Array.isArray(data) ? data : [];
        if (products.length === 0) {
            showSearchMessage('🔍 No products found for "<strong>' + query + '</strong>"', 'empty');
            productsData = [];
            selectedProductIndex = -1;
            return;
        }
        productsData = products;
        selectedProductIndex = 0;
        renderProducts();
        document.getElementById('search-results-container').style.display = 'block';
    })
    .catch(err => {
        console.error('Search failed:', err);
        showSearchMessage('<strong>Search failed</strong><br><small>' + err.message + '</small>', 'danger');
    });
}

function lookupByBarcode(barcode) {
    if (!storeId) { showSearchMessage('⚠️ Please select a store first.', 'warning'); return; }
    const input = document.getElementById('product-search');

    fetch('../includes/ajax_lookup_barcode.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'barcode=' + encodeURIComponent(barcode) + '&store_id=' + encodeURIComponent(storeId)
    })
    .then(async response => {
        const text = await response.text();
        if (!response.ok) throw new Error('HTTP ' + response.status + ' — ' + text.slice(0, 200));
        try { return JSON.parse(text); }
        catch (e) { throw new Error('Non-JSON: ' + text.slice(0, 200)); }
    })
    .then(data => {
        if (data.success && data.product) {
            const p = data.product;
            if (parseInt(p.quantity) <= 0) {
                showSearchMessage('❌ ' + p.product_name + ' is out of stock', 'warning');
            } else {
                addToCart(p.product_id, p.product_name, p.selling_price, p.purchase_price, p.tax_rate, p.quantity);
            }
        } else {
            showSearchMessage('❌ ' + (data.message || 'No product for barcode: ' + barcode), 'danger');
        }
        input.value = '';
    })
    .catch(err => {
        console.error('Barcode failed:', err);
        showSearchMessage('<strong>Barcode error</strong><br><small>' + err.message + '</small>', 'danger');
        input.value = '';
    });
}

function showSearchMessage(html, kind) {
    const box = document.getElementById('search-results');
    const container = document.getElementById('search-results-container');
    if (!box || !container) return;
    const colors = {
        warning: { bg: '#FEF3C7', border: '#F59E0B', text: '#92400E' },
        danger:  { bg: '#FEE2E2', border: '#EF4444', text: '#991B1B' },
        empty:   { bg: '#F7FAFC', border: '#CBD5E0', text: '#4A5568' },
        info:    { bg: '#DBEAFE', border: '#3B82F6', text: '#1E40AF' }
    };
    const c = colors[kind] || colors.info;
    box.innerHTML = `<div style="padding:15px; background:${c.bg}; border-left:3px solid ${c.border}; border-radius:6px; color:${c.text}; font-size:0.8rem; font-weight:600;">${html}</div>`;
    container.style.display = 'block';
}

function renderProducts() {
    const box = document.getElementById('search-results');
    if (!box) return;
    if (!Array.isArray(productsData) || productsData.length === 0) {
        box.innerHTML = '<div style="text-align:center;padding:30px;color:#A0AEC0;font-size:0.8rem;"><p>No products to display</p></div>';
        return;
    }
    let html = '';
    productsData.forEach(function (p, i) {
        const qty = parseInt(p.quantity) || 0;
        const out = qty <= 0;
        const cls = (i === selectedProductIndex ? 'selected' : '') + (out ? ' out-of-stock' : '');
        let stockCls = 'stock-good';
        if (out) stockCls = 'stock-out';
        else if (qty <= 10) stockCls = 'stock-low';
        const price = parseFloat(p.selling_price) || 0;
        const cost = parseFloat(p.purchase_price) || 0;
        const tax = parseFloat(p.tax_rate) || 0;
        const name = String(p.product_name || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
        const code = p.product_code || '';
        const click = out ? '' : `onclick="addToCart(${p.product_id}, '${name}', ${price}, ${cost}, ${tax}, ${qty})"`;
        html += `
            <div class="product-item ${cls}" data-index="${i}" ${click}>
                <div class="product-info">
                    <div class="product-name">${p.product_name}</div>
                    <div class="product-code"><i class="fas fa-barcode me-1"></i>${code}</div>
                </div>
                <div class="product-details">
                    <div class="product-price">UGX ${price.toFixed(2)}</div>
                    <span class="stock-badge ${stockCls}">
                        ${out ? '❌ Out of Stock' : '📦 Stock: ' + qty}
                    </span>
                </div>
            </div>`;
    });
    box.innerHTML = html;
}

function navigateProducts(key) {
    if (!Array.isArray(productsData) || productsData.length === 0) return;
    if (key === 'ArrowDown') selectedProductIndex = (selectedProductIndex + 1) % productsData.length;
    else selectedProductIndex = selectedProductIndex <= 0 ? productsData.length - 1 : selectedProductIndex - 1;
    renderProducts();
    const sel = document.querySelector('.product-item.selected');
    if (sel) sel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function clearSearchResults() {
    const c = document.getElementById('search-results-container');
    const b = document.getElementById('search-results');
    if (c) c.style.display = 'none';
    if (b) b.innerHTML = '';
    productsData = [];
    selectedProductIndex = -1;
}

function addToCart(productId, name, price, cost, taxRate, availableQty) {
    if (parseInt(availableQty) <= 0) { showToast('Out of stock', 'warning'); return; }
    const existing = cart.find(i => i.product_id === productId);
    if (existing) {
        if (existing.quantity < availableQty) {
            existing.quantity++;
            showToast(`${name} — Qty: ${existing.quantity}`, 'success');
        } else {
            showToast('Stock limit reached', 'warning');
            return;
        }
    } else {
        cart.push({
            product_id: productId,
            product_name: name,
            price: parseFloat(price),
            cost: parseFloat(cost),
            tax_rate: parseFloat(taxRate),
            quantity: 1,
            available_qty: parseInt(availableQty)
        });
        showToast(`✓ ${name}`, 'success');
    }
    updateCart();
    clearSearchResults();
    const input = document.getElementById('product-search');
    if (input) { input.value = ''; input.focus(); }
}

function removeFromCart(i) {
    const item = cart[i];
    if (!item) return;
    cart.splice(i, 1);
    updateCart();
    showToast(`${item.product_name} removed`, 'info');
}

function updateQuantity(i, newQty) {
    newQty = parseInt(newQty);
    if (newQty > 0 && newQty <= cart[i].available_qty) {
        cart[i].quantity = newQty;
        updateCart();
    } else if (newQty > cart[i].available_qty) {
        showToast('Exceeds stock', 'warning');
        updateCart();
    }
}

function updateCart() {
    const box = document.getElementById('cart-items');
    const cnt = document.getElementById('cart-count');
    if (cnt) cnt.textContent = cart.length;

    if (cart.length === 0) {
        box.innerHTML = `<tr class="empty-cart-row"><td colspan="6">
            <div class="empty-cart">
                <i class="fas fa-shopping-basket"></i>
                <h5>Cart is Empty</h5>
                <p>Scan a barcode or search to begin</p>
            </div></td></tr>`;
    } else {
        let html = '';
        cart.forEach((item, i) => {
            const tax = item.price * item.quantity * item.tax_rate / 100;
            item.tax_amount = tax;
            const sub = item.price * item.quantity;
            html += `<tr>
                <td><div class="cart-product-name" style="font-weight:600; font-size:0.8rem;">${item.product_name}</div></td>
                <td><span style="font-family:'Courier New',monospace;font-weight:600; font-size:0.8rem;">${item.price.toFixed(2)}</span></td>
                <td><input type="number" class="cart-qty-input" value="${item.quantity}" min="1" max="${item.available_qty}" onchange="updateQuantity(${i}, this.value)"></td>
                <td><span style="font-family:'Courier New',monospace;color:#718096;font-size:0.75rem;">${tax.toFixed(2)}</span></td>
                <td><span style="font-family:'Courier New',monospace;font-weight:700;color:#6B46C1; font-size:0.8rem;">${sub.toFixed(2)}</span></td>
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
    const total = Math.max(0, sub + tax - disc);
    document.getElementById('subtotal-display').textContent = 'UGX ' + sub.toFixed(2);
    document.getElementById('tax-display').textContent = 'UGX ' + tax.toFixed(2);
    document.getElementById('total-display').textContent = 'UGX ' + total.toFixed(2);
    document.getElementById('total-value').value = total.toFixed(2);
    calculateChange();
    updateCompleteButton();
}

function calculateChange() {
    const total = parseFloat(document.getElementById('total-value').value) || 0;
    const paid = parseFloat(document.getElementById('amount-paid').value) || 0;
    const change = paid - total;
    const cc = document.getElementById('change-container');
    if (change > 0) {
        document.getElementById('change-display').textContent = 'UGX ' + change.toFixed(2);
        cc.style.display = 'flex';
    } else {
        cc.style.display = 'none';
    }
}

function updateCompleteButton() {
    const btn = document.getElementById('complete-sale-btn');
    const pm = document.getElementById('payment-method').value;
    const total = parseFloat(document.getElementById('total-value').value) || 0;
    const paid = parseFloat(document.getElementById('amount-paid').value) || 0;
    const err = document.getElementById('amount-error');
    let ok = !!storeId && cart.length > 0 && pm !== '';
    if (pm && pm !== 'credit') {
        if (paid < total) { err.classList.remove('d-none'); ok = false; }
        else err.classList.add('d-none');
    } else {
        err.classList.add('d-none');
    }
    btn.disabled = !ok;
}

function setupDiscount() {
    const el = document.getElementById('discount-input');
    if (el) el.addEventListener('input', updateTotals);
}

function setupAmountPaid() {
    const el = document.getElementById('amount-paid');
    if (el) el.addEventListener('input', function () { calculateChange(); updateCompleteButton(); });
}

function setupAutoFill() {
    const btn = document.getElementById('auto-fill-btn');
    if (btn) btn.addEventListener('click', function () {
        const t = parseFloat(document.getElementById('total-value').value) || 0;
        document.getElementById('amount-paid').value = t.toFixed(2);
        calculateChange();
        updateCompleteButton();
    });
}

function setupClearCart() {
    const btn = document.getElementById('clear-cart');
    if (!btn) return;
    btn.addEventListener('click', function () {
        if (cart.length === 0) return;
        if (confirm('Clear cart?')) {
            cart = [];
            updateCart();
            showToast('Cart cleared', 'info');
        }
    });
}

function setupFormSubmit() {
    const form = document.getElementById('sale-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        if (!storeId) { e.preventDefault(); showToast('Select a store', 'warning'); return; }
        if (cart.length === 0) { e.preventDefault(); showToast('Cart is empty', 'warning'); return; }
        const pm = document.getElementById('payment-method').value;
        if (!pm) { e.preventDefault(); showToast('Select a payment method', 'warning'); return; }
        if (pm === 'credit' && !document.getElementById('customer-select').value) {
            e.preventDefault(); showToast('Customer required for credit', 'warning'); return;
        }
        if (pm === 'mobile_money' && !document.getElementById('transaction-id').value) {
            e.preventDefault(); showToast('Transaction ID required', 'warning'); return;
        }
        const total = parseFloat(document.getElementById('total-value').value) || 0;
        const paid = parseFloat(document.getElementById('amount-paid').value) || 0;
        if (pm !== 'credit' && paid < total) {
            e.preventDefault(); showToast('Amount paid is less than total', 'warning'); return;
        }
        document.getElementById('store-id-hidden').value = storeId;
        document.getElementById('cart-items-input').value = JSON.stringify(cart);
        const sb = document.getElementById('complete-sale-btn');
        sb.disabled = true;
        sb.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    });
}

function showToast(message, type) {
    type = type || 'info';
    const icons = { success: 'check-circle', warning: 'exclamation-triangle', error: 'times-circle', info: 'info-circle' };
    const colors = { success: '#48BB78', warning: '#ED8936', error: '#E53E3E', info: '#6B46C1' };
    document.querySelectorAll('.pos-toast').forEach(t => t.remove());
    const toast = document.createElement('div');
    toast.className = 'pos-toast';
    toast.style.cssText = `
        position:fixed; top:80px; right:20px; background:white;
        padding:10px 16px; border-radius:8px;
        box-shadow:0 10px 30px rgba(0,0,0,0.2);
        border-left:4px solid ${colors[type]};
        display:flex; align-items:center; gap:10px;
        z-index:9999; min-width:200px;
        font-size:0.85rem; font-weight:600; color:#1A202C;
        animation:slideInRight 0.3s ease;`;
    toast.innerHTML = `<i class="fas fa-${icons[type]}" style="color:${colors[type]};font-size:1.1rem;"></i><span>${message}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 2000);
}

const toastCSS = document.createElement('style');
toastCSS.textContent = `
@keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
@keyframes slideOutRight { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }`;
document.head.appendChild(toastCSS);
</script>