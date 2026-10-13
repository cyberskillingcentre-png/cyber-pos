<?php
require_once '../config/config.php';
require_once 'config/client_auth.php';
require_client();

$page_title = "New Order";

// ============================================
// AUTO-CREATE ORDER TABLES IF MISSING
// ============================================
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `client_orders` (
          `order_id` INT(11) NOT NULL AUTO_INCREMENT,
          `order_number` VARCHAR(50) NOT NULL,
          `client_id` INT(11) NOT NULL,
          `store_id` INT(11) DEFAULT NULL,
          `order_date` DATETIME NOT NULL,
          `subtotal` DECIMAL(15,2) DEFAULT 0.00,
          `discount_amount` DECIMAL(15,2) DEFAULT 0.00,
          `tax_amount` DECIMAL(15,2) DEFAULT 0.00,
          `grand_total` DECIMAL(15,2) DEFAULT 0.00,
          `payment_method` VARCHAR(50) DEFAULT 'cash',
          `payment_status` ENUM('pending','partial','paid') DEFAULT 'pending',
          `order_status` ENUM('pending','confirmed','processing','ready','shipped','delivered','cancelled') DEFAULT 'pending',
          `amount_paid` DECIMAL(15,2) DEFAULT 0.00,
          `amount_due` DECIMAL(15,2) DEFAULT 0.00,
          `delivery_address` TEXT DEFAULT NULL,
          `notes` TEXT DEFAULT NULL,
          `processed_by` INT(11) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`order_id`),
          UNIQUE KEY `order_number` (`order_number`),
          KEY `idx_client` (`client_id`),
          KEY `idx_status` (`order_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch(PDOException $e) {}

try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `client_order_items` (
          `order_item_id` INT(11) NOT NULL AUTO_INCREMENT,
          `order_id` INT(11) NOT NULL,
          `product_id` INT(11) NOT NULL,
          `product_name` VARCHAR(255) DEFAULT NULL,
          `quantity` INT(11) NOT NULL DEFAULT 1,
          `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
          `discount` DECIMAL(15,2) DEFAULT 0.00,
          `tax_amount` DECIMAL(15,2) DEFAULT 0.00,
          `total_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`order_item_id`),
          KEY `idx_order` (`order_id`),
          KEY `idx_product` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch(PDOException $e) {}

// ============================================
// HANDLE ORDER SUBMISSION (INLINE)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    csrf_require();
    try {
        $conn->beginTransaction();
        
        // Parse cart
        $cart_items = json_decode($_POST['products'] ?? '[]', true);
        
        if (empty($cart_items) || !is_array($cart_items)) {
            throw new Exception('Cart is empty. Please add at least one product.');
        }
        
        // Form data
        $delivery_address = trim($_POST['delivery_address'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $discount_rate = floatval($_POST['discount_rate'] ?? 0);
        
        // Get first active store
        $store_id = null;
        try {
            $stmt = $conn->query("SELECT store_id FROM stores WHERE status = 'active' ORDER BY store_id LIMIT 1");
            $store_id = $stmt->fetchColumn() ?: null;
        } catch(PDOException $e) {
            $store_id = null;
        }
        
        // Calculate totals (server-side)
        $subtotal = 0;
        foreach ($cart_items as $item) {
            $qty = max(1, intval($item['qty'] ?? 1));
            $price = floatval($item['price'] ?? 0);
            $subtotal += $qty * $price;
        }
        
        $discount_amount = $subtotal * ($discount_rate / 100);
        $tax_amount = 0;
        $grand_total = max(0, $subtotal - $discount_amount + $tax_amount);
        
        // Generate order number
        $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        
        // Insert order header
        $stmt = $conn->prepare("
            INSERT INTO client_orders (
                order_number, client_id, store_id, order_date,
                subtotal, discount_amount, tax_amount, grand_total,
                payment_method, payment_status, order_status,
                amount_paid, amount_due, delivery_address, notes
            ) VALUES (
                ?, ?, ?, NOW(),
                ?, ?, ?, ?,
                ?, 'pending', 'pending',
                0, ?, ?, ?
            )
        ");
        $stmt->execute([
            $order_number,
            $_SESSION['user_id'],
            $store_id,
            $subtotal,
            $discount_amount,
            $tax_amount,
            $grand_total,
            $payment_method,
            $grand_total,
            $delivery_address,
            $notes
        ]);
        
        $order_id = $conn->lastInsertId();
        
        // Insert order items
        $stmt = $conn->prepare("
            INSERT INTO client_order_items 
            (order_id, product_id, product_name, quantity, unit_price, discount, tax_amount, total_price)
            VALUES (?, ?, ?, ?, ?, 0, 0, ?)
        ");
        
        foreach ($cart_items as $item) {
            $qty = max(1, intval($item['qty'] ?? 1));
            $price = floatval($item['price'] ?? 0);
            $line_total = $qty * $price;
            
            $stmt->execute([
                $order_id,
                intval($item['id'] ?? 0),
                $item['name'] ?? 'Unknown Product',
                $qty,
                $price,
                $line_total
            ]);
        }
        
        // Log activity
        if (function_exists('log_activity')) {
            log_activity(
                $_SESSION['user_id'],
                'CLIENT_ORDER_PLACED',
                "Placed order: $order_number (UGX " . number_format($grand_total, 0) . ")",
                $conn
            );
        }
        
        $conn->commit();
        
        set_alert('success', "Order placed successfully! Order #: $order_number");
        header('Location: orders.php');
        exit;
        
    } catch(Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Order placement error: " . $e->getMessage());
        set_alert('danger', "Could not place order: " . $e->getMessage());
    }
}

// ============================================
// GET PRESELECTED PRODUCT
// ============================================
$preselect_product = isset($_GET['product']) ? intval($_GET['product']) : 0;

// ============================================
// GET ACTIVE PRODUCTS (with stock)
// ============================================
$products = [];
try {
    $stmt = $conn->query("
        SELECT p.*, 
               c.category_name,
               COALESCE((
                   SELECT SUM(quantity) 
                   FROM store_inventory 
                   WHERE product_id = p.product_id
               ), 0) as total_stock
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        WHERE p.status = 'active'
        ORDER BY p.product_name
    ");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $products = [];
}

// ============================================
// GET CLIENT DISCOUNT RATE
// ============================================
$discount_rate = 0;
try {
    if (function_exists('get_client_discount_rate')) {
        $discount_rate = floatval(get_client_discount_rate($conn, $_SESSION['user_id']));
    } else {
        $stmt = $conn->prepare("SELECT discount_rate FROM client_profiles WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $discount_rate = floatval($stmt->fetchColumn() ?: 0);
    }
} catch(PDOException $e) {
    $discount_rate = 0;
}
?>

<?php include '../includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

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
                    <i class="fas fa-plus-circle me-2" style="color: var(--cyber-orange);"></i>
                    New Order
                </h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="products.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-shopping-bag me-1"></i>Browse Products
                </a>
                <div class="user-badge d-none d-md-flex">
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
        
        <form method="POST" id="orderForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="place_order" value="1">
            <input type="hidden" name="discount_rate" value="<?php echo $discount_rate; ?>">
            <input type="hidden" name="products" id="products_input" value="[]">
            
            <div class="row g-4">
                <!-- LEFT: Product Selector + Cart -->
                <div class="col-lg-8">
                    <div class="card cyber-card mb-4">
                        <div class="card-header">
                            <i class="fas fa-plus-circle me-2"></i>Add Products to Order
                        </div>
                        <div class="card-body">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-6">
                                    <label class="form-label-cyber">
                                        <i class="fas fa-box me-1"></i>Select Product
                                    </label>
                                    <select id="product_select" class="form-select form-select-cyber">
                                        <option value="">-- Choose Product --</option>
                                        <?php foreach ($products as $p): 
                                            $stock = intval($p['total_stock'] ?? 0);
                                        ?>
                                        <option value="<?php echo $p['product_id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($p['product_name']); ?>"
                                                data-price="<?php echo floatval($p['selling_price']); ?>"
                                                data-stock="<?php echo $stock; ?>"
                                                <?php echo $preselect_product == $p['product_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($p['product_name']); ?> 
                                            — UGX <?php echo format_currency($p['selling_price']); ?>
                                            <?php if ($stock <= 0): ?> (Out of Stock)<?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label-cyber">
                                        <i class="fas fa-sort-numeric-up me-1"></i>Quantity
                                    </label>
                                    <input type="number" id="product_qty" class="form-control form-control-cyber" 
                                           value="1" min="1">
                                </div>
                                
                                <div class="col-md-3">
                                    <button type="button" onclick="addProduct()" class="btn btn-primary w-100">
                                        <i class="fas fa-plus me-1"></i>Add to Order
                                    </button>
                                </div>
                            </div>
                            
                            <div id="cart_items" class="mt-3">
                                <div class="empty-cart-notice">
                                    <i class="fas fa-shopping-cart"></i>
                                    <span>No products added yet. Select a product above to start.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- RIGHT: Order Summary -->
                <div class="col-lg-4">
                    <div class="card cyber-card summary-card">
                        <div class="card-header">
                            <i class="fas fa-info-circle me-2"></i>Order Summary
                        </div>
                        <div class="card-body">
                            <!-- Delivery -->
                            <div class="mb-3">
                                <label class="form-label-cyber">
                                    <i class="fas fa-map-marker-alt me-1"></i>Delivery Address
                                </label>
                                <textarea name="delivery_address" class="form-control form-control-cyber" 
                                          rows="3" 
                                          placeholder="Enter delivery address..."></textarea>
                            </div>
                            
                            <!-- Payment Method -->
                            <div class="mb-3">
                                <label class="form-label-cyber">
                                    <i class="fas fa-credit-card me-1"></i>Payment Method *
                                </label>
                                <select name="payment_method" class="form-select form-select-cyber" required>
                                    <option value="credit">💳 Credit (Pay Later)</option>
                                    <option value="mobile_money">📱 Mobile Money</option>
                                    <option value="bank_transfer">🏦 Bank Transfer</option>
                                    <option value="cash">💵 Cash on Delivery</option>
                                </select>
                            </div>
                            
                            <!-- Notes -->
                            <div class="mb-3">
                                <label class="form-label-cyber">
                                    <i class="fas fa-sticky-note me-1"></i>Order Notes
                                </label>
                                <textarea name="notes" class="form-control form-control-cyber" 
                                          rows="2" 
                                          placeholder="Any special instructions..."></textarea>
                            </div>
                            
                            <hr>
                            
                            <!-- Totals -->
                            <div class="totals-display">
                                <div class="total-row">
                                    <span class="total-label">Subtotal</span>
                                    <span class="total-value" id="subtotal">UGX 0</span>
                                </div>
                                
                                <?php if ($discount_rate > 0): ?>
                                <div class="total-row" id="discount_row" style="display: none;">
                                    <span class="total-label" style="color: #2F855A;">
                                        <i class="fas fa-tag me-1"></i>Discount (<?php echo $discount_rate; ?>%)
                                    </span>
                                    <span class="total-value" id="discount" style="color: #2F855A;">- UGX 0</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="grand-total-box">
                                <span class="grand-total-label">TOTAL</span>
                                <span class="grand-total-value" id="grand_total">UGX 0</span>
                            </div>
                            
                            <!-- Submit -->
                            <button type="submit" class="btn-complete-sale" id="submitBtn" disabled>
                                <i class="fas fa-check-circle"></i>
                                <span>Place Order</span>
                            </button>
                            
                            <div class="order-hint">
                                <i class="fas fa-info-circle me-1"></i>
                                Your order will be reviewed by our team
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Page Styles -->
<style>
/* Card variants */
.cyber-card {
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.08);
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

.cyber-card .card-header i { color: #ED8936; margin-right: 8px; }

.summary-card { position: sticky; top: 80px; }

/* Form styles */
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

/* Empty cart */
.empty-cart-notice {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 30px;
    background: #F7FAFC;
    border: 2px dashed #CBD5E0;
    border-radius: 10px;
    color: #718096;
    font-size: 0.9rem;
}

.empty-cart-notice i {
    font-size: 1.5rem;
    color: #A0AEC0;
}

/* Cart table */
.cart-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.cart-table thead th {
    background: #F7FAFC;
    color: #4A5568;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 12px 10px;
    border-bottom: 2px solid #E2E8F0;
    text-align: left;
}

.cart-table tbody td {
    padding: 12px 10px;
    border-bottom: 1px solid #EDF2F7;
    vertical-align: middle;
}

.cart-table tbody tr:hover { background: #F7FAFC; }

.cart-table .qty-input {
    width: 70px;
    padding: 6px 8px;
    border: 2px solid #E2E8F0;
    border-radius: 6px;
    text-align: center;
    font-weight: 700;
    font-size: 0.85rem;
}

.cart-table .qty-input:focus {
    outline: none;
    border-color: #6B46C1;
    box-shadow: 0 0 0 3px rgba(107, 70, 193, 0.15);
}

.cart-table .remove-btn {
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

.cart-table .remove-btn:hover {
    background: #E53E3E;
    color: #fff;
    transform: scale(1.1);
}

/* Totals */
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

.total-label { font-size: 0.85rem; color: #4A5568; font-weight: 600; }
.total-value { font-size: 0.95rem; color: #1A202C; font-weight: 700; font-family: 'Courier New', monospace; }

.grand-total-box {
    background: linear-gradient(135deg, #1A202C, #2D3748);
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.grand-total-label { color: #ED8936; font-weight: 800; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 2px; }
.grand-total-value { color: #fff; font-weight: 800; font-size: 1.25rem; font-family: 'Courier New', monospace; }

/* Submit button */
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
    opacity: 0.6;
}

.order-hint {
    text-align: center;
    font-size: 0.75rem;
    color: #A0AEC0;
    margin-top: 12px;
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
    .summary-card { position: static; }
}
</style>

<?php include 'includes/footer.php'; ?>

<script>
/* ============================================
   ORDER FORM LOGIC
   (Sidebar handled by shared sidebar.js if loaded)
   ============================================ */
let cart = [];
const discountRate = <?php echo floatval($discount_rate); ?>;

function addProduct() {
    const select = document.getElementById('product_select');
    const qtyInput = document.getElementById('product_qty');
    
    if (!select.value) {
        alert('Please select a product');
        select.focus();
        return;
    }
    
    const option = select.options[select.selectedIndex];
    const qty = parseInt(qtyInput.value) || 1;
    const stock = parseInt(option.dataset.stock || '0');
    
    if (stock <= 0) {
        alert('This product is out of stock');
        return;
    }
    
    if (qty < 1) {
        alert('Quantity must be at least 1');
        return;
    }
    
    if (qty > stock) {
        alert('Only ' + stock + ' units available');
        return;
    }
    
    const existingIndex = cart.findIndex(item => item.id == select.value);
    
    if (existingIndex >= 0) {
        const newQty = cart[existingIndex].qty + qty;
        if (newQty > stock) {
            alert('Total quantity would exceed available stock (' + stock + ')');
            return;
        }
        cart[existingIndex].qty = newQty;
    } else {
        cart.push({
            id: select.value,
            name: option.dataset.name,
            price: parseFloat(option.dataset.price),
            qty: qty,
            stock: stock
        });
    }
    
    updateCart();
    select.value = '';
    qtyInput.value = 1;
    select.focus();
}

function updateQuantity(index, newQty) {
    newQty = parseInt(newQty);
    if (isNaN(newQty) || newQty < 1) {
        removeProduct(index);
        return;
    }
    const stock = cart[index].stock || 999999;
    if (newQty > stock) {
        alert('Only ' + stock + ' units available');
        updateCart();
        return;
    }
    cart[index].qty = newQty;
    updateCart();
}

function removeProduct(index) {
    if (confirm('Remove this product from the order?')) {
        cart.splice(index, 1);
        updateCart();
    }
}

function updateCart() {
    let html = '';
    let subtotal = 0;
    
    if (cart.length > 0) {
        html = '<div class="table-responsive"><table class="cart-table">';
        html += '<thead><tr>';
        html += '<th>Product</th>';
        html += '<th style="width: 100px;">Qty</th>';
        html += '<th>Price</th>';
        html += '<th>Total</th>';
        html += '<th style="width: 50px;"></th>';
        html += '</tr></thead><tbody>';
        
        cart.forEach((item, index) => {
            const itemTotal = item.price * item.qty;
            subtotal += itemTotal;
            
            html += `
                <tr>
                    <td><strong>${item.name}</strong></td>
                    <td>
                        <input type="number" class="qty-input" 
                               value="${item.qty}" min="1" max="${item.stock || 999999}"
                               onchange="updateQuantity(${index}, this.value)">
                    </td>
                    <td>UGX ${item.price.toLocaleString()}</td>
                    <td><strong>UGX ${itemTotal.toLocaleString()}</strong></td>
                    <td>
                        <button type="button" class="remove-btn" onclick="removeProduct(${index})" title="Remove">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        });
        
        html += '</tbody></table></div>';
    } else {
        html = `
            <div class="empty-cart-notice">
                <i class="fas fa-shopping-cart"></i>
                <span>No products added yet. Select a product above to start.</span>
            </div>
        `;
    }
    
    document.getElementById('cart_items').innerHTML = html;
    
    const discount = subtotal * (discountRate / 100);
    const grandTotal = subtotal - discount;
    
    document.getElementById('subtotal').textContent = 'UGX ' + subtotal.toLocaleString();
    
    const discountRow = document.getElementById('discount_row');
    const discountEl = document.getElementById('discount');
    if (discountRow && discountEl) {
        if (discount > 0) {
            discountRow.style.display = 'flex';
            discountEl.textContent = '- UGX ' + discount.toLocaleString();
        } else {
            discountRow.style.display = 'none';
        }
    }
    
    document.getElementById('grand_total').textContent = 'UGX ' + grandTotal.toLocaleString();
    document.getElementById('products_input').value = JSON.stringify(cart);
    document.getElementById('submitBtn').disabled = cart.length === 0;
}

// Preselect product
document.addEventListener('DOMContentLoaded', function() {
    const select = document.getElementById('product_select');
    if (select && select.value) {
        setTimeout(() => addProduct(), 150);
    }
});

// Form submission
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('orderForm');
    if (!form) return;
    
    form.addEventListener('submit', function(e) {
        if (cart.length === 0) {
            e.preventDefault();
            alert('Please add at least one product to the order.');
            return false;
        }
        
        const totalItems = cart.reduce((sum, item) => sum + item.qty, 0);
        const totalAmount = calculateGrandTotal();
        const confirmMsg = `Confirm order?\n\nItems: ${totalItems}\nTotal: UGX ${totalAmount.toLocaleString()}`;
        
        if (!confirm(confirmMsg)) {
            e.preventDefault();
            return false;
        }
        
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Placing Order...';
    });
});

function calculateGrandTotal() {
    let subtotal = 0;
    cart.forEach(item => {
        subtotal += item.price * item.qty;
    });
    const discount = subtotal * (discountRate / 100);
    return subtotal - discount;
}
</script>