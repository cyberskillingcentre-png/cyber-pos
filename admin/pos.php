<?php
require_once '../config/config.php';
require_admin();

$page_title = "Point of Sale";

// ============================================
// AUTO-MIGRATE: Ensure required columns exist
// ============================================
try {
    // Ensure sale_type column exists
    $check = $conn->query("SHOW COLUMNS FROM sales LIKE 'sale_type'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE sales ADD COLUMN sale_type ENUM('cash','credit') DEFAULT 'cash' AFTER payment_method");
    }
    
    // Ensure transaction_id column exists
    $check = $conn->query("SHOW COLUMNS FROM sales LIKE 'transaction_id'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE sales ADD COLUMN transaction_id VARCHAR(100) DEFAULT NULL AFTER amount_due");
    }
    
    // Ensure updated_at column exists
    $check = $conn->query("SHOW COLUMNS FROM sales LIKE 'updated_at'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE sales ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }
    
    // Ensure profit column exists in sale_items
    $check = $conn->query("SHOW COLUMNS FROM sale_items LIKE 'profit'");
    if ($check->rowCount() == 0) {
        $conn->exec("ALTER TABLE sale_items ADD COLUMN profit DECIMAL(15,2) DEFAULT 0 AFTER total_price");
    }
} catch(PDOException $e) {
    // Silently continue
}

// Get active stores
$stmt = $conn->prepare("SELECT * FROM stores WHERE status = 'active' ORDER BY store_name");
$stmt->execute();
$stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment methods
$payment_methods = ['cash', 'card', 'mobile_money', 'bank_transfer', 'credit'];

// ============================================
// PROCESS SALE
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['complete_sale'])) {
    try {
        $conn->beginTransaction();
        
        // ============================================
        // SERVER-SIDE VALIDATION
        // ============================================
        $store_id = isset($_POST['store_id']) ? intval($_POST['store_id']) : 0;
        
        if ($store_id <= 0) {
            throw new Exception('Please select a store before completing the sale.');
        }
        
        // Verify store exists
        $check = $conn->prepare("SELECT store_id FROM stores WHERE store_id = ? AND status = 'active'");
        $check->execute([$store_id]);
        if (!$check->fetch()) {
            throw new Exception('Selected store is invalid or inactive.');
        }
        
        $customer_id = !empty($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
        $payment_method = $_POST['payment_method'] ?? '';
        $amount_paid = floatval($_POST['amount_paid'] ?? 0);
        $transaction_id = isset($_POST['transaction_id']) && !empty($_POST['transaction_id']) 
                          ? sanitize_input($_POST['transaction_id']) 
                          : null;
        $discount_amount = isset($_POST['discount_amount']) ? floatval($_POST['discount_amount']) : 0;
        $cart_items = json_decode($_POST['cart_items'] ?? '[]', true);
        
        if (empty($cart_items)) {
            throw new Exception('Cart is empty.');
        }
        
        if (empty($payment_method)) {
            throw new Exception('Please select a payment method.');
        }
        
        // Determine sale type
        $sale_type = ($payment_method == 'credit') ? 'credit' : 'cash';
        
        // For credit sales, require customer
        if ($sale_type == 'credit' && !$customer_id) {
            throw new Exception('Customer is required for credit sales');
        }
        
        // ============================================
        // CALCULATE TOTALS
        // ============================================
        $subtotal = 0;
        $tax_amount = 0;
        
        foreach ($cart_items as $item) {
            $subtotal += $item['quantity'] * $item['price'];
            $tax_amount += $item['tax_amount'] ?? 0;
        }
        
        $total_amount = $subtotal + $tax_amount - $discount_amount;
        
        // Validate payment amount (except for credit)
        if ($payment_method != 'credit' && $amount_paid < $total_amount) {
            throw new Exception('Amount paid cannot be less than total amount');
        }
        
        // For credit, amount_paid can be 0 or partial
        $amount_due = max(0, $total_amount - $amount_paid);
        $payment_status = ($amount_paid >= $total_amount) ? 'paid' : ($amount_paid > 0 ? 'partial' : 'unpaid');
        
        // ============================================
        // GENERATE INVOICE NUMBER
        // ============================================
        $invoice_number = function_exists('generate_invoice_number') 
                          ? generate_invoice_number() 
                          : 'INV' . date('YmdHis') . rand(100, 999);
        
        // ============================================
        // INSERT SALE
        // ============================================
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
            $invoice_number, 
            $store_id, 
            $customer_id, 
            $subtotal, 
            $discount_amount, 
            $tax_amount, 
            $total_amount, 
            $payment_method, 
            $sale_type, 
            $payment_status, 
            $amount_paid, 
            $amount_due, 
            $transaction_id, 
            $_SESSION['user_id']
        ]);
        
        $sale_id = $conn->lastInsertId();
        
        // ============================================
        // INSERT SALE ITEMS & UPDATE INVENTORY (FIFO)
        // ============================================
        foreach ($cart_items as $item) {
            $product_id = intval($item['product_id']);
            $quantity = intval($item['quantity']);
            $price = floatval($item['price']);
            $cost = floatval($item['cost']);
            $tax = floatval($item['tax_amount'] ?? 0);
            $line_total = $quantity * $price;
            $profit = ($price - $cost) * $quantity;
            
            // Insert sale item
            $stmt = $conn->prepare("
                INSERT INTO sale_items 
                (sale_id, product_id, quantity, unit_price, discount_amount, tax_amount, total_price, profit) 
                VALUES (?, ?, ?, ?, 0, ?, ?, ?)
            ");
            $stmt->execute([$sale_id, $product_id, $quantity, $price, $tax, $line_total, $profit]);
            
            // ============================================
            // FIFO INVENTORY DEDUCTION
            // ============================================
            $remaining_qty = $quantity;
            
            $stmt = $conn->prepare("
                SELECT inventory_id, quantity 
                FROM store_inventory 
                WHERE store_id = ? AND product_id = ? AND quantity > 0 
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
        
        // ============================================
        // UPDATE CUSTOMER TOTAL PURCHASES
        // ============================================
        if ($customer_id) {
            $stmt = $conn->prepare("UPDATE customers SET total_purchases = total_purchases + ? WHERE customer_id = ?");
            $stmt->execute([$total_amount, $customer_id]);
        }
        
        // ============================================
        // LOG ACTIVITY
        // ============================================
        if (function_exists('log_activity')) {
            $sale_type_label = ($sale_type == 'credit') ? 'credit sale' : 'cash sale';
            log_activity($_SESSION['user_id'], 'SALE_CREATED', "Created $sale_type_label $invoice_number for UGX " . number_format($total_amount, 2), $conn);
        }
        
        $conn->commit();
        
        // ============================================
        // REDIRECT
        // ============================================
        if ($sale_type == 'credit') {
            set_alert('success', 'Credit sale completed! Invoice: ' . $invoice_number);
            header('Location: Credit_Invoice?id=' . $sale_id);
        } else {
            set_alert('success', 'Sale completed successfully! Invoice: ' . $invoice_number);
            header('Location: Receipt?id=' . $sale_id);
        }
        exit();
        
    } catch(Exception $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

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
        
        <div class="row g-3">
            <!-- LEFT: Product Search & Cart -->
            <div class="col-lg-8">
                
                <!-- Store Selection -->
                <div class="card cyber-card mb-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div class="store-icon-badge">
                                <i class="fas fa-store"></i>
                            </div>
                            <div class="flex-grow-1">
                                <label class="form-label-cyber mb-1" style="font-size: 0.7rem;">
                                    <i class="fas fa-map-marker-alt me-1"></i>Active Store
                                </label>
                                <select id="store-select" class="form-select form-select-cyber" required>
                                    <option value="">-- Select Store to Begin --</option>
                                    <?php foreach ($stores as $store): ?>
                                        <option value="<?php echo $store['store_id']; ?>">
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
                
                <!-- Product Search -->
                <div class="pos-search-card mb-3">
                    <div class="search-input-wrapper">
                        <div class="search-icon">
                            <i class="fas fa-search"></i>
                        </div>
                        <input type="text" id="product-search" class="pos-search-input" 
                               placeholder="Search by product name, code, or scan barcode..." 
                               autofocus autocomplete="off">
                        <div class="search-hints">
                            <span class="hint-key">↑↓</span>
                            <span class="hint-text">Navigate</span>
                            <span class="hint-key">Enter</span>
                            <span class="hint-text">Add</span>
                            <span class="hint-key">Esc</span>
                            <span class="hint-text">Clear</span>
                        </div>
                    </div>
                </div>
                
                <!-- Search Results -->
                <div id="search-results-container" class="search-results-container mb-3" style="display:none;">
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
            
            <!-- RIGHT: Sale Summary -->
            <div class="col-lg-4">
                <div class="card cyber-card summary-card">
                    <div class="card-header">
                        <i class="fas fa-calculator me-2"></i>Sale Summary
                    </div>
                    <div class="card-body">
                        <form id="sale-form" method="POST">
                            <!-- Hidden store_id (mirrored from top selector) -->
                            <input type="hidden" name="store_id" id="store-id-hidden" value="">
                            
                            <!-- Customer -->
                            <div class="mb-3">
                                <label class="form-label-cyber">
                                    <i class="fas fa-user me-2"></i>Customer
                                    <span class="badge bg-danger ms-2" id="customer-required" style="display:none;">Required</span>
                                </label>
                                <select name="customer_id" id="customer-select" class="form-select form-select-cyber">
                                    <option value="">🚶 Walk-in Customer</option>
                                </select>
                            </div>
                            
                            <!-- Totals Display -->
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
                                    <span class="total-label">
                                        <i class="fas fa-tag me-1"></i>Discount
                                    </span>
                                    <div class="discount-input-wrapper">
                                        <span class="currency-prefix">UGX</span>
                                        <input type="number" name="discount_amount" id="discount-input" 
                                               class="discount-input" step="0.01" min="0" value="0">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Grand Total -->
                            <div class="grand-total-box">
                                <span class="grand-total-label">TOTAL</span>
                                <span class="grand-total-value" id="total-display">UGX 0</span>
                                <input type="hidden" id="total-value" value="0">
                            </div>
                            
                            <!-- Payment Method -->
                            <div class="mb-3">
                                <label class="form-label-cyber">
                                    <i class="fas fa-credit-card me-2"></i>Payment Method
                                </label>
                                <div class="payment-methods-grid">
                                    <div class="payment-method-option" data-value="cash">
                                        <i class="fas fa-money-bill-wave"></i>
                                        <span>Cash</span>
                                    </div>
                                    <div class="payment-method-option" data-value="card">
                                        <i class="fas fa-credit-card"></i>
                                        <span>Card</span>
                                    </div>
                                    <div class="payment-method-option" data-value="mobile_money">
                                        <i class="fas fa-mobile-alt"></i>
                                        <span>Mobile</span>
                                    </div>
                                    <div class="payment-method-option" data-value="bank_transfer">
                                        <i class="fas fa-university"></i>
                                        <span>Bank</span>
                                    </div>
                                    <div class="payment-method-option" data-value="credit">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                        <span>Credit</span>
                                    </div>
                                </div>
                                <select name="payment_method" id="payment-method" class="d-none" required>
                                    <option value="">Select Payment Method</option>
                                    <?php foreach ($payment_methods as $method): ?>
                                        <option value="<?php echo $method; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $method)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Transaction ID -->
                            <div id="transaction-id-section" class="mb-3" style="display:none;">
                                <label class="form-label-cyber">
                                    <i class="fas fa-hashtag me-2"></i>Transaction ID
                                    <span class="badge bg-danger ms-2">Required</span>
                                </label>
                                <input type="text" name="transaction_id" id="transaction-id" 
                                       class="form-control form-control-cyber" 
                                       placeholder="Enter transaction ID">
                            </div>
                            
                            <!-- Payment Section -->
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
                                        <input type="number" name="amount_paid" id="amount-paid" 
                                               class="amount-input" step="0.01" min="0" value="0" required>
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
                            
                            <!-- Credit Notice -->
                            <div id="credit-notice" class="credit-notice" style="display:none;">
                                <div class="credit-notice-icon">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="credit-notice-content">
                                    <strong>Credit Sale</strong>
                                    <p>Customer is required. Payment can be partial or zero.</p>
                                </div>
                            </div>
                            
                            <input type="hidden" name="cart_items" id="cart-items-input">
                            <input type="hidden" name="complete_sale" value="1">
                            
                            <!-- Complete Sale Button -->
                            <button type="submit" class="btn-complete-sale" id="complete-sale-btn" disabled>
                                <i class="fas fa-check-circle"></i>
                                <span>Complete Sale</span>
                                <span class="btn-shortcut">F12</span>
                            </button>
                            
                            <!-- Keyboard Shortcuts -->
                            <div class="keyboard-shortcuts">
                                <div class="shortcut-item">
                                    <span class="shortcut-key">F2</span>
                                    <span class="shortcut-desc">Focus</span>
                                </div>
                                <div class="shortcut-item">
                                    <span class="shortcut-key">F4</span>
                                    <span class="shortcut-desc">Clear</span>
                                </div>
                                <div class="shortcut-item">
                                    <span class="shortcut-key">F12</span>
                                    <span class="shortcut-desc">Complete</span>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- POS Specific Styles -->
<style>
.pos-content {
    background: linear-gradient(135deg, #F7FAFC 0%, #EDF2F7 100%);
    min-height: calc(100vh - 60px);
}

.pos-clock {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    background: rgba(72, 187, 120, 0.15);
    border-radius: 20px;
    color: #2F855A;
    font-weight: 600;
    font-family: 'Courier New', monospace;
    font-size: 0.9rem;
}

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

.store-icon-badge {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #6B46C1, #553C9A);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.25rem;
    flex-shrink: 0;
    box-shadow: 0 4px 15px rgba(107, 70, 193, 0.3);
}

.store-status-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: #EDF2F7;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    color: #718096;
    white-space: nowrap;
}

.status-dot-inactive {
    width: 8px;
    height: 8px;
    background: #A0AEC0;
    border-radius: 50%;
}

.status-dot-active {
    width: 8px;
    height: 8px;
    background: #48BB78;
    border-radius: 50%;
    animation: pulse-dot 2s infinite;
}

@keyframes pulse-dot {
    0%, 100% { box-shadow: 0 0 0 0 rgba(72, 187, 120, 0.5); }
    50% { box-shadow: 0 0 0 5px rgba(72, 187, 120, 0); }
}

.pos-search-card {
    background: #fff;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    border: 1px solid #E2E8F0;
}

.search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.search-icon {
    position: absolute;
    left: 20px;
    color: #6B46C1;
    font-size: 1.1rem;
    z-index: 2;
}

.pos-search-input {
    width: 100%;
    padding: 16px 20px 16px 56px;
    border: 2px solid #E2E8F0;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 500;
    color: #1A202C;
    transition: all 0.3s ease;
}

.pos-search-input:focus {
    outline: none;
    border-color: #6B46C1;
    box-shadow: 0 0 0 4px rgba(107, 70, 193, 0.15);
}

.search-hints {
    position: absolute;
    right: 16px;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.7rem;
    color: #A0AEC0;
}

.hint-key {
    padding: 2px 6px;
    background: #EDF2F7;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    font-weight: 700;
    font-size: 0.65rem;
    color: #4A5568;
    border: 1px solid #E2E8F0;
}

.search-results-container {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    border: 2px solid #6B46C1;
    overflow: hidden;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.search-results-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 20px;
    background: linear-gradient(135deg, #6B46C1, #553C9A);
    color: #fff;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.btn-close-results {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: #fff;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-close-results:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: rotate(90deg);
}

.search-results-list {
    max-height: 400px;
    overflow-y: auto;
    padding: 8px;
}

.product-item {
    padding: 16px 18px;
    border: 2px solid #E2E8F0;
    border-radius: 10px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    background: #fff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}

.product-item:last-child { margin-bottom: 0; }

.product-item:hover {
    background: linear-gradient(135deg, rgba(107, 70, 193, 0.05), rgba(237, 137, 54, 0.03));
    border-color: #6B46C1;
    transform: translateX(6px);
    box-shadow: 0 4px 15px rgba(107, 70, 193, 0.15);
}

.product-item.selected {
    background: linear-gradient(135deg, #6B46C1, #553C9A);
    color: #fff;
    border-color: #553C9A;
}

.product-item.selected .product-name,
.product-item.selected .product-code,
.product-item.selected .product-price { color: #fff; }

.product-item.out-of-stock {
    opacity: 0.5;
    cursor: not-allowed;
    background: #F7FAFC;
}

.product-item.out-of-stock:hover {
    background: #F7FAFC;
    transform: none;
    border-color: #E2E8F0;
    box-shadow: none;
}

.product-info { flex: 1; min-width: 0; }

.product-name {
    font-weight: 700;
    font-size: 0.95rem;
    color: #1A202C;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.product-code {
    font-size: 0.75rem;
    color: #718096;
    font-family: 'Courier New', monospace;
    font-weight: 600;
}

.product-details { text-align: right; flex-shrink: 0; }

.product-price {
    font-weight: 700;
    font-size: 1rem;
    color: #6B46C1;
    margin-bottom: 4px;
}

.stock-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 700;
    background: #EDF2F7;
    color: #4A5568;
}

.stock-badge.stock-good { background: rgba(72, 187, 120, 0.15); color: #2F855A; }
.stock-badge.stock-low { background: rgba(237, 137, 54, 0.15); color: #C05621; }
.stock-badge.stock-out { background: rgba(229, 62, 62, 0.15); color: #C53030; }

.pos-cart-table {
    width: 100%;
    border-collapse: collapse;
}

.pos-cart-table thead th {
    background: #F7FAFC;
    color: #4A5568;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 14px 16px;
    border-bottom: 2px solid #E2E8F0;
    text-align: left;
}

.pos-cart-table tbody td {
    padding: 16px;
    border-bottom: 1px solid #EDF2F7;
    vertical-align: middle;
    font-size: 0.875rem;
}

.pos-cart-table tbody tr:hover { background: #F7FAFC; }

.cart-product-name {
    font-weight: 600;
    color: #1A202C;
    margin-bottom: 2px;
}

.cart-qty-input {
    width: 70px;
    padding: 6px 8px;
    border: 2px solid #E2E8F0;
    border-radius: 6px;
    text-align: center;
    font-weight: 700;
    font-size: 0.9rem;
}

.cart-qty-input:focus {
    outline: none;
    border-color: #6B46C1;
    box-shadow: 0 0 0 3px rgba(107, 70, 193, 0.15);
}

.btn-remove-item {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: rgba(229, 62, 62, 0.1);
    color: #E53E3E;
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-remove-item:hover {
    background: #E53E3E;
    color: #fff;
    transform: scale(1.1);
}

.empty-cart {
    text-align: center;
    padding: 60px 20px;
    color: #A0AEC0;
}

.empty-cart i {
    font-size: 4rem;
    margin-bottom: 16px;
    opacity: 0.3;
    color: #6B46C1;
}

.empty-cart h5 {
    color: #4A5568;
    font-weight: 700;
    margin-bottom: 8px;
}

.summary-card {
    position: sticky;
    top: 80px;
}

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

.form-label-cyber i { color: #6B46C1; }

.form-control-cyber,
.form-select-cyber {
    border: 2px solid #E2E8F0;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}

.form-control-cyber:focus,
.form-select-cyber:focus {
    outline: none;
    border-color: #6B46C1;
    box-shadow: 0 0 0 3px rgba(107, 70, 193, 0.15);
}

.totals-display {
    background: #F7FAFC;
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 16px;
    border: 1px solid #E2E8F0;
}

.total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px dashed #E2E8F0;
}

.total-row:last-child { border-bottom: none; }

.total-label {
    font-size: 0.85rem;
    color: #4A5568;
    font-weight: 600;
}

.total-value {
    font-size: 0.95rem;
    color: #1A202C;
    font-weight: 700;
    font-family: 'Courier New', monospace;
}

.discount-input-wrapper {
    display: flex;
    align-items: center;
    gap: 6px;
}

.currency-prefix {
    font-size: 0.75rem;
    color: #6B46C1;
    font-weight: 800;
}

.discount-input {
    width: 100px;
    padding: 6px 10px;
    border: 2px solid #E2E8F0;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 700;
    text-align: right;
    font-family: 'Courier New', monospace;
}

.discount-input:focus {
    outline: none;
    border-color: #ED8936;
    box-shadow: 0 0 0 3px rgba(237, 137, 54, 0.15);
}

.grand-total-box {
    background: linear-gradient(135deg, #1A202C, #2D3748);
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    overflow: hidden;
}

.grand-total-label {
    color: #ED8936;
    font-weight: 800;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 2px;
}

.grand-total-value {
    color: #fff;
    font-weight: 800;
    font-size: 1.25rem;
    font-family: 'Courier New', monospace;
}

.payment-methods-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}

.payment-method-option {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 12px 8px;
    background: #F7FAFC;
    border: 2px solid #E2E8F0;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s ease;
    gap: 6px;
}

.payment-method-option:hover {
    border-color: #6B46C1;
    background: rgba(107, 70, 193, 0.05);
    transform: translateY(-2px);
}

.payment-method-option.selected {
    background: linear-gradient(135deg, #6B46C1, #553C9A);
    border-color: #553C9A;
    color: #fff;
    box-shadow: 0 4px 15px rgba(107, 70, 193, 0.35);
}

.payment-method-option i {
    font-size: 1.25rem;
    color: #6B46C1;
}

.payment-method-option.selected i { color: #ED8936; }

.payment-method-option span {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #4A5568;
}

.payment-method-option.selected span { color: #fff; }

.btn-auto-fill {
    background: rgba(237, 137, 54, 0.15);
    color: #C05621;
    border: none;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-auto-fill:hover {
    background: #ED8936;
    color: #fff;
}

.amount-input-wrapper {
    display: flex;
    align-items: center;
    background: #F7FAFC;
    border: 2px solid #E2E8F0;
    border-radius: 8px;
    overflow: hidden;
}

.amount-input-wrapper:focus-within {
    border-color: #48BB78;
    box-shadow: 0 0 0 3px rgba(72, 187, 120, 0.15);
}

.amount-input-wrapper .currency-prefix {
    padding: 0 12px;
    background: rgba(72, 187, 120, 0.1);
    color: #2F855A;
    height: 100%;
    display: flex;
    align-items: center;
    border-right: 1px solid #E2E8F0;
}

.amount-input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 12px 16px;
    font-size: 1.1rem;
    font-weight: 700;
    font-family: 'Courier New', monospace;
    color: #1A202C;
    outline: none;
}

.change-display {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: rgba(72, 187, 120, 0.1);
    border: 2px solid rgba(72, 187, 120, 0.3);
    border-radius: 10px;
    padding: 14px 18px;
    margin-top: 12px;
    animation: popIn 0.3s ease;
}

@keyframes popIn {
    0% { transform: scale(0.95); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
}

.change-label {
    font-size: 0.85rem;
    font-weight: 700;
    color: #2F855A;
    text-transform: uppercase;
}

.change-value {
    font-size: 1.1rem;
    font-weight: 800;
    font-family: 'Courier New', monospace;
    color: #2F855A;
}

.credit-notice {
    display: flex;
    gap: 12px;
    background: rgba(237, 137, 54, 0.1);
    border-left: 4px solid #ED8936;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 16px;
}

.credit-notice-icon {
    width: 36px;
    height: 36px;
    background: #ED8936;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    flex-shrink: 0;
}

.credit-notice-content strong {
    display: block;
    color: #C05621;
    font-size: 0.85rem;
    font-weight: 700;
    margin-bottom: 2px;
}

.credit-notice-content p {
    margin: 0;
    font-size: 0.75rem;
    color: #744210;
}

.btn-complete-sale {
    width: 100%;
    padding: 18px 24px;
    background: linear-gradient(135deg, #48BB78, #38A169);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
    box-shadow: 0 6px 20px rgba(72, 187, 120, 0.35);
    margin-top: 8px;
}

.btn-complete-sale:hover:not(:disabled) {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(72, 187, 120, 0.5);
}

.btn-complete-sale:disabled {
    background: #CBD5E0;
    cursor: not-allowed;
    box-shadow: none;
    transform: none;
    opacity: 0.6;
}

.btn-shortcut {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    padding: 3px 8px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 6px;
    font-size: 0.65rem;
    font-weight: 700;
    font-family: 'Courier New', monospace;
}

.keyboard-shortcuts {
    display: flex;
    justify-content: center;
    gap: 16px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #E2E8F0;
    flex-wrap: wrap;
}

.shortcut-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.7rem;
    color: #A0AEC0;
}

.shortcut-key {
    padding: 2px 8px;
    background: #EDF2F7;
    border: 1px solid #E2E8F0;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    font-weight: 700;
    color: #4A5568;
    font-size: 0.65rem;
}

@media (max-width: 991.98px) {
    .summary-card { position: static; margin-top: 20px; }
    .search-hints { display: none; }
}
</style>

<?php include '../includes/footer.php'; ?>

<script>
// ============================================
// POS SYSTEM - GLOBAL VARIABLES
// ============================================
let cart = [];
let storeId = null;
let selectedProductIndex = -1;
let productsData = [];
let selectedPaymentMethod = '';
let searchTimeout;

// ============================================
// LIVE CLOCK
// ============================================
function updateClock() {
    const now = new Date();
    const time = now.toLocaleTimeString('en-US', { hour12: false });
    const clockEl = document.getElementById('live-clock');
    if (clockEl) clockEl.textContent = time;
}

// ============================================
// MAIN INIT
// ============================================
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
    setupKeyboardShortcuts();
    setupAutoFill();
});

// ============================================
// LOAD CUSTOMERS
// ============================================
function loadCustomers() {
    const customerSelect = document.getElementById('customer-select');
    if (!customerSelect) return;
    
    fetch('../includes/ajax_customers.php')
        .then(response => response.json())
        .then(data => {
            if (Array.isArray(data)) {
                data.forEach(customer => {
                    const option = document.createElement('option');
                    option.value = customer.customer_id;
                    option.textContent = customer.customer_name + (customer.phone ? ' - ' + customer.phone : '');
                    customerSelect.appendChild(option);
                });
            }
        })
        .catch(error => console.error('Error loading customers:', error));
}

// ============================================
// STORE SELECTOR
// ============================================
function setupStoreSelector() {
    const storeSelect = document.getElementById('store-select');
    const storeStatus = document.getElementById('store-status');
    const hiddenInput = document.getElementById('store-id-hidden');
    
    if (!storeSelect) return;
    
    storeSelect.addEventListener('change', function() {
        storeId = this.value;
        
        if (hiddenInput) hiddenInput.value = storeId;
        
        if (storeId) {
            storeStatus.innerHTML = '<span class="status-dot-active"></span><span>Store Active</span>';
            storeStatus.style.background = 'rgba(72, 187, 120, 0.15)';
            storeStatus.style.color = '#2F855A';
            
            const searchInput = document.getElementById('product-search');
            if (searchInput) searchInput.focus();
        } else {
            storeStatus.innerHTML = '<span class="status-dot-inactive"></span><span>Not Selected</span>';
            storeStatus.style.background = '#EDF2F7';
            storeStatus.style.color = '#718096';
        }
        
        updateCompleteButton();
    });
    
    // Set initial value
    if (storeSelect.value) {
        storeId = storeSelect.value;
        if (hiddenInput) hiddenInput.value = storeId;
    }
}

// ============================================
// PAYMENT METHODS
// ============================================
function setupPaymentMethods() {
    document.querySelectorAll('.payment-method-option').forEach(function(option) {
        option.addEventListener('click', function() {
            document.querySelectorAll('.payment-method-option').forEach(o => o.classList.remove('selected'));
            this.classList.add('selected');
            
            selectedPaymentMethod = this.dataset.value;
            document.getElementById('payment-method').value = selectedPaymentMethod;
            
            updatePaymentUI(selectedPaymentMethod);
            updateCompleteButton();
        });
    });
}

function updatePaymentUI(paymentMethod) {
    const paymentSection = document.getElementById('payment-section');
    const creditNotice = document.getElementById('credit-notice');
    const customerRequired = document.getElementById('customer-required');
    const transactionSection = document.getElementById('transaction-id-section');
    const transactionInput = document.getElementById('transaction-id');
    const amountPaid = document.getElementById('amount-paid');
    
    if (paymentMethod === 'credit') {
        paymentSection.style.display = 'block';
        creditNotice.style.display = 'flex';
        customerRequired.style.display = 'inline-block';
        transactionSection.style.display = 'none';
        transactionInput.removeAttribute('required');
        amountPaid.value = 0;
        amountPaid.removeAttribute('required');
    } else if (paymentMethod === 'mobile_money') {
        paymentSection.style.display = 'block';
        creditNotice.style.display = 'none';
        customerRequired.style.display = 'none';
        transactionSection.style.display = 'block';
        transactionInput.setAttribute('required', 'required');
        amountPaid.setAttribute('required', 'required');
    } else {
        paymentSection.style.display = 'block';
        creditNotice.style.display = 'none';
        customerRequired.style.display = 'none';
        transactionSection.style.display = 'none';
        transactionInput.removeAttribute('required');
        amountPaid.setAttribute('required', 'required');
    }
    
    calculateChange();
}

// ============================================
// PRODUCT SEARCH
// ============================================
function setupProductSearch() {
    const searchInput = document.getElementById('product-search');
    if (!searchInput) return;
    
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (selectedProductIndex >= 0 && productsData[selectedProductIndex]) {
                const product = productsData[selectedProductIndex];
                if (product.quantity > 0) {
                    addToCart(
                        product.product_id, 
                        product.product_name, 
                        product.selling_price, 
                        product.purchase_price, 
                        product.tax_rate, 
                        product.quantity
                    );
                }
            } else {
                searchProducts();
            }
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            navigateProducts(e.key);
        } else if (e.key === 'Escape') {
            clearSearchResults();
        }
    });
    
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value;
        
        if (query.length >= 2) {
            searchTimeout = setTimeout(searchProducts, 300);
        } else if (query.length === 0) {
            clearSearchResults();
        }
    });
}

function searchProducts() {
    const searchInput = document.getElementById('product-search');
    const query = searchInput.value;
    const storeSelect = document.getElementById('store-select');
    const store_id = storeSelect.value;
    
    if (!store_id) {
        showToast('Please select a store first', 'warning');
        return;
    }
    
    if (query.length < 2) return;
    
    fetch('../includes/ajax_search_products.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'query=' + encodeURIComponent(query) + '&store_id=' + encodeURIComponent(store_id)
    })
    .then(response => response.json())
    .then(products => {
        if (products.error) {
            document.getElementById('search-results').innerHTML = 
                '<div style="padding:20px;color:#E53E3E;"><i class="fas fa-exclamation-triangle me-2"></i>' + products.error + '</div>';
            document.getElementById('search-results-container').style.display = 'block';
            return;
        }
        
        if (products.length === 0) {
            document.getElementById('search-results').innerHTML = 
                '<div style="text-align:center;padding:40px;color:#A0AEC0;"><i class="fas fa-search" style="font-size:3rem;opacity:0.3;margin-bottom:16px;display:block;"></i><p>No products found</p></div>';
            document.getElementById('search-results-container').style.display = 'block';
            productsData = [];
            selectedProductIndex = -1;
            return;
        }
        
        productsData = products;
        selectedProductIndex = products.length > 0 ? 0 : -1;
        renderProducts();
        document.getElementById('search-results-container').style.display = 'block';
    })
    .catch(error => {
        console.error('Search error:', error);
        showToast('Error searching products', 'error');
    });
}

function renderProducts() {
    let html = '';
    
    productsData.forEach(function(product, index) {
        const outOfStock = product.quantity <= 0;
        const selectedClass = index === selectedProductIndex ? 'selected' : '';
        const disabledClass = outOfStock ? 'out-of-stock' : '';
        
        let stockClass = 'stock-good';
        if (outOfStock) stockClass = 'stock-out';
        else if (product.quantity <= 10) stockClass = 'stock-low';
        
        const clickHandler = outOfStock ? '' : 
            `onclick="addToCart(${product.product_id}, '${String(product.product_name).replace(/'/g, "\\'")}', ${product.selling_price}, ${product.purchase_price}, ${product.tax_rate}, ${product.quantity})"`;
        
        html += `
            <div class="product-item ${selectedClass} ${disabledClass}" data-index="${index}" ${clickHandler}>
                <div class="product-info">
                    <div class="product-name">${product.product_name}</div>
                    <div class="product-code">
                        <i class="fas fa-barcode me-1"></i>${product.product_code}
                    </div>
                </div>
                <div class="product-details">
                    <div class="product-price">UGX ${parseFloat(product.selling_price).toFixed(2)}</div>
                    <span class="stock-badge ${stockClass}">
                        ${outOfStock ? '❌ Out of Stock' : '📦 Stock: ' + product.quantity}
                    </span>
                </div>
            </div>
        `;
    });
    
    document.getElementById('search-results').innerHTML = html;
}

function navigateProducts(key) {
    if (productsData.length === 0) return;
    
    if (key === 'ArrowDown') {
        selectedProductIndex = (selectedProductIndex + 1) % productsData.length;
    } else if (key === 'ArrowUp') {
        selectedProductIndex = selectedProductIndex <= 0 ? productsData.length - 1 : selectedProductIndex - 1;
    }
    
    renderProducts();
    
    const selectedElement = document.querySelector('.product-item.selected');
    if (selectedElement) {
        selectedElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

function clearSearchResults() {
    document.getElementById('search-results-container').style.display = 'none';
    document.getElementById('search-results').innerHTML = '';
    productsData = [];
    selectedProductIndex = -1;
}

// ============================================
// ADD TO CART
// ============================================
function addToCart(productId, productName, price, cost, taxRate, availableQty) {
    if (availableQty <= 0) {
        showToast('Product is out of stock', 'warning');
        return;
    }
    
    const existingItem = cart.find(item => item.product_id === productId);
    
    if (existingItem) {
        if (existingItem.quantity < availableQty) {
            existingItem.quantity++;
            showToast(`${productName} - Qty: ${existingItem.quantity}`, 'success');
        } else {
            showToast('Stock limit reached', 'warning');
            return;
        }
    } else {
        cart.push({
            product_id: productId,
            product_name: productName,
            price: parseFloat(price),
            cost: parseFloat(cost),
            tax_rate: parseFloat(taxRate),
            quantity: 1,
            available_qty: parseInt(availableQty)
        });
        showToast(`${productName} added to cart`, 'success');
    }
    
    updateCart();
    clearSearchResults();
    
    const searchInput = document.getElementById('product-search');
    if (searchInput) {
        searchInput.value = '';
        searchInput.focus();
    }
}

// ============================================
// REMOVE FROM CART
// ============================================
function removeFromCart(index) {
    const item = cart[index];
    if (!item) return;
    
    cart.splice(index, 1);
    updateCart();
    showToast(`${item.product_name} removed`, 'info');
}

// ============================================
// UPDATE QUANTITY
// ============================================
function updateQuantity(index, newQty) {
    newQty = parseInt(newQty);
    
    if (newQty > 0 && newQty <= cart[index].available_qty) {
        cart[index].quantity = newQty;
        updateCart();
    } else if (newQty > cart[index].available_qty) {
        showToast('Quantity exceeds available stock', 'warning');
        updateCart();
    } else if (newQty <= 0) {
        updateCart();
    }
}

// ============================================
// UPDATE CART
// ============================================
function updateCart() {
    const cartContainer = document.getElementById('cart-items');
    const cartCount = document.getElementById('cart-count');
    
    cartCount.textContent = cart.length;
    
    if (cart.length === 0) {
        cartContainer.innerHTML = `
            <tr class="empty-cart-row">
                <td colspan="6">
                    <div class="empty-cart">
                        <i class="fas fa-shopping-basket"></i>
                        <h5>Cart is Empty</h5>
                        <p>Search and add products to begin a sale</p>
                    </div>
                </td>
            </tr>
        `;
    } else {
        let html = '';
        
        cart.forEach(function(item, index) {
            const taxAmount = (item.price * item.quantity * item.tax_rate / 100);
            item.tax_amount = taxAmount;
            const subtotal = item.price * item.quantity;
            
            html += `
                <tr>
                    <td>
                        <div class="cart-product-name">${item.product_name}</div>
                    </td>
                    <td>
                        <span style="font-family:'Courier New',monospace;font-weight:600;">
                            ${item.price.toFixed(2)}
                        </span>
                    </td>
                    <td>
                        <input type="number" class="cart-qty-input" 
                               value="${item.quantity}" min="1" max="${item.available_qty}" 
                               onchange="updateQuantity(${index}, this.value)">
                    </td>
                    <td>
                        <span style="font-family:'Courier New',monospace;color:#718096;font-size:0.8rem;">
                            ${taxAmount.toFixed(2)}
                        </span>
                    </td>
                    <td>
                        <span style="font-family:'Courier New',monospace;font-weight:700;color:#6B46C1;">
                            ${subtotal.toFixed(2)}
                        </span>
                    </td>
                    <td>
                        <button type="button" class="btn-remove-item" onclick="removeFromCart(${index})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        });
        
        cartContainer.innerHTML = html;
    }
    
    updateTotals();
}

// ============================================
// UPDATE TOTALS
// ============================================
function updateTotals() {
    let subtotal = 0;
    let taxTotal = 0;
    
    cart.forEach(function(item) {
        subtotal += item.price * item.quantity;
        taxTotal += item.tax_amount || 0;
    });
    
    const discountInput = document.getElementById('discount-input');
    const discount = parseFloat(discountInput.value) || 0;
    const total = Math.max(0, subtotal + taxTotal - discount);
    
    document.getElementById('subtotal-display').textContent = 'UGX ' + subtotal.toFixed(2);
    document.getElementById('tax-display').textContent = 'UGX ' + taxTotal.toFixed(2);
    document.getElementById('total-display').textContent = 'UGX ' + total.toFixed(2);
    document.getElementById('total-value').value = total.toFixed(2);
    
    calculateChange();
    updateCompleteButton();
}

// ============================================
// CALCULATE CHANGE
// ============================================
function calculateChange() {
    const total = parseFloat(document.getElementById('total-value').value) || 0;
    const amountPaid = document.getElementById('amount-paid');
    const paid = parseFloat(amountPaid.value) || 0;
    const change = paid - total;
    
    const changeContainer = document.getElementById('change-container');
    const changeDisplay = document.getElementById('change-display');
    
    if (change > 0) {
        changeDisplay.textContent = 'UGX ' + change.toFixed(2);
        changeContainer.style.display = 'flex';
    } else {
        changeContainer.style.display = 'none';
    }
}

// ============================================
// UPDATE COMPLETE BUTTON
// ============================================
function updateCompleteButton() {
    const completeBtn = document.getElementById('complete-sale-btn');
    const paymentMethod = document.getElementById('payment-method').value;
    const total = parseFloat(document.getElementById('total-value').value) || 0;
    const amountPaid = parseFloat(document.getElementById('amount-paid').value) || 0;
    const amountError = document.getElementById('amount-error');
    
    const hasStore = storeId !== null && storeId !== '';
    const hasItems = cart.length > 0;
    const hasPayment = paymentMethod !== '';
    
    let canComplete = hasStore && hasItems && hasPayment;
    
    // For non-credit payments, amount paid must cover total
    if (paymentMethod && paymentMethod !== 'credit') {
        if (amountPaid < total) {
            amountError.classList.remove('d-none');
            canComplete = false;
        } else {
            amountError.classList.add('d-none');
        }
    } else {
        amountError.classList.add('d-none');
    }
    
    completeBtn.disabled = !canComplete;
}

// ============================================
// DISCOUNT INPUT
// ============================================
function setupDiscount() {
    const discountInput = document.getElementById('discount-input');
    if (discountInput) {
        discountInput.addEventListener('input', updateTotals);
    }
}

// ============================================
// AMOUNT PAID
// ============================================
function setupAmountPaid() {
    const amountPaid = document.getElementById('amount-paid');
    if (amountPaid) {
        amountPaid.addEventListener('input', function() {
            calculateChange();
            updateCompleteButton();
        });
    }
}

// ============================================
// AUTO-FILL BUTTON
// ============================================
function setupAutoFill() {
    const btn = document.getElementById('auto-fill-btn');
    if (btn) {
        btn.addEventListener('click', function() {
            const total = parseFloat(document.getElementById('total-value').value) || 0;
            document.getElementById('amount-paid').value = total.toFixed(2);
            calculateChange();
            updateCompleteButton();
        });
    }
}

// ============================================
// CLEAR CART
// ============================================
function setupClearCart() {
    const btn = document.getElementById('clear-cart');
    if (!btn) return;
    
    btn.addEventListener('click', function() {
        if (cart.length === 0) return;
        
        if (confirm('Clear cart? All items will be removed.')) {
            cart = [];
            updateCart();
            showToast('Cart cleared', 'info');
        }
    });
}

// ============================================
// FORM SUBMISSION
// ============================================
function setupFormSubmit() {
    const form = document.getElementById('sale-form');
    if (!form) return;
    
    form.addEventListener('submit', function(e) {
        // Validation
        if (!storeId) {
            e.preventDefault();
            showToast('Please select a store', 'warning');
            return false;
        }
        
        if (cart.length === 0) {
            e.preventDefault();
            showToast('Cart is empty', 'warning');
            return false;
        }
        
        const paymentMethod = document.getElementById('payment-method').value;
        if (!paymentMethod) {
            e.preventDefault();
            showToast('Please select a payment method', 'warning');
            return false;
        }
        
        if (paymentMethod === 'credit') {
            const customerId = document.getElementById('customer-select').value;
            if (!customerId) {
                e.preventDefault();
                showToast('Customer is required for credit sales', 'warning');
                return false;
            }
        }
        
        if (paymentMethod === 'mobile_money') {
            const transactionId = document.getElementById('transaction-id').value;
            if (!transactionId) {
                e.preventDefault();
                showToast('Transaction ID is required', 'warning');
                return false;
            }
        }
        
        const total = parseFloat(document.getElementById('total-value').value) || 0;
        const amountPaid = parseFloat(document.getElementById('amount-paid').value) || 0;
        
        if (paymentMethod !== 'credit' && amountPaid < total) {
            e.preventDefault();
            showToast('Amount paid cannot be less than total', 'warning');
            return false;
        }
        
        // Sync hidden store_id
        const hiddenStore = document.getElementById('store-id-hidden');
        hiddenStore.value = storeId;
        
        // Set cart items
        document.getElementById('cart-items-input').value = JSON.stringify(cart);
        
        // Show loading state
        const submitBtn = document.getElementById('complete-sale-btn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    });
}

// ============================================
// KEYBOARD SHORTCUTS
// ============================================
function setupKeyboardShortcuts() {
    document.addEventListener('keydown', function(e) {
        if (e.key === 'F2') {
            e.preventDefault();
            const searchInput = document.getElementById('product-search');
            if (searchInput) searchInput.focus();
        } else if (e.key === 'F4') {
            e.preventDefault();
            document.getElementById('clear-cart').click();
        } else if (e.key === 'F12') {
            e.preventDefault();
            const completeBtn = document.getElementById('complete-sale-btn');
            if (!completeBtn.disabled) {
                document.getElementById('sale-form').submit();
            }
        }
    });
}

// ============================================
// TOAST NOTIFICATIONS
// ============================================
function showToast(message, type) {
    type = type || 'info';
    
    const icons = {
        success: 'check-circle',
        warning: 'exclamation-triangle',
        error: 'times-circle',
        info: 'info-circle'
    };
    
    const colors = {
        success: '#48BB78',
        warning: '#ED8936',
        error: '#E53E3E',
        info: '#6B46C1'
    };
    
    const existing = document.querySelector('.pos-toast');
    if (existing) existing.remove();
    
    const toast = document.createElement('div');
    toast.className = 'pos-toast';
    toast.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        background: white;
        padding: 14px 20px;
        border-radius: 10px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        border-left: 4px solid ${colors[type]};
        display: flex;
        align-items: center;
        gap: 12px;
        z-index: 9999;
        animation: slideInRight 0.3s ease;
        min-width: 250px;
        font-weight: 600;
        color: #1A202C;
        font-family: 'Inter', sans-serif;
    `;
    toast.innerHTML = `
        <i class="fas fa-${icons[type]}" style="color: ${colors[type]}; font-size: 1.2rem;"></i>
        <span>${message}</span>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(function() {
        toast.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(function() { toast.remove(); }, 300);
    }, 2500);
}

// Add animation styles
const toastStyles = document.createElement('style');
toastStyles.textContent = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(toastStyles);
</script>