<?php
require_once '../config/config.php';
require_admin();

$page_title = "Point of Sale";

// Get active stores
$stmt = $conn->prepare("SELECT * FROM stores WHERE status = 'active' ORDER BY store_name");
$stmt->execute();
$stores = $stmt->fetchAll();

// Get payment methods
$payment_methods = ['cash', 'card', 'mobile_money', 'bank_transfer', 'credit'];

// Process sale
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['complete_sale'])) {
    try {
        $conn->beginTransaction();
        
        $store_id = $_POST['store_id'];
        $customer_id = !empty($_POST['customer_id']) ? $_POST['customer_id'] : null;
        $payment_method = $_POST['payment_method'];
        $amount_paid = $_POST['amount_paid'];
        $cart_items = json_decode($_POST['cart_items'], true);
        
        $subtotal = 0;
        $tax_amount = 0;
        $discount_amount = isset($_POST['discount_amount']) ? $_POST['discount_amount'] : 0;
        
        // Calculate totals
        foreach ($cart_items as $item) {
            $subtotal += $item['quantity'] * $item['price'];
            $tax_amount += $item['tax_amount'];
        }
        
        $total_amount = $subtotal + $tax_amount - $discount_amount;
        $amount_due = $total_amount - $amount_paid;
        $payment_status = ($amount_paid >= $total_amount) ? 'paid' : ($amount_paid > 0 ? 'partial' : 'unpaid');
        
        // Generate invoice number
        $invoice_number = generate_invoice_number();
        
        // Insert sale
        $stmt = $conn->prepare("INSERT INTO sales (invoice_number, store_id, customer_id, sale_date, subtotal, discount_amount, tax_amount, total_amount, payment_method, payment_status, amount_paid, amount_due, served_by, status) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')");
        $stmt->execute([$invoice_number, $store_id, $customer_id, $subtotal, $discount_amount, $tax_amount, $total_amount, $payment_method, $payment_status, $amount_paid, $amount_due, $_SESSION['user_id']]);
        $sale_id = $conn->lastInsertId();
        
        // Insert sale items and update inventory
        foreach ($cart_items as $item) {
            $profit = ($item['price'] - $item['cost']) * $item['quantity'];
            
            $stmt = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, discount_amount, tax_amount, total_price, profit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$sale_id, $item['product_id'], $item['quantity'], $item['price'], 0, $item['tax_amount'], $item['quantity'] * $item['price'], $profit]);
            
            // Update inventory
            $stmt = $conn->prepare("UPDATE store_inventory SET quantity = quantity - ? WHERE store_id = ? AND product_id = ?");
            $stmt->execute([$item['quantity'], $store_id, $item['product_id']]);
        }
        
        // Update customer total purchases
        if ($customer_id) {
            $stmt = $conn->prepare("UPDATE customers SET total_purchases = total_purchases + ? WHERE customer_id = ?");
            $stmt->execute([$total_amount, $customer_id]]);
        }
        
        // Log activity
        log_activity($_SESSION['user_id'], 'SALE_CREATED', "Created sale $invoice_number for UGX " . format_currency($total_amount), $conn);
        
        $conn->commit();
        
        set_alert('success', 'Sale completed successfully! Invoice: ' . $invoice_number);
        header('Location: view-sale.php?id=' . $sale_id);
        exit();
        
    } catch(PDOException $e) {
        $conn->rollBack();
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-cash-register me-2"></i>Point of Sale
            </h5>
            <div>
                <span class="badge bg-primary"><?php echo date('l, F j, Y H:i'); ?></span>
            </div>
        </div>
    </div>
    
    <div class="content-area">
        <div class="row">
            <!-- Product Search & Cart -->
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="fas fa-search me-2"></i>Product Search
                    </div>
                    <div class="card-body">
                        <div class="input-group mb-3">
                            <input type="text" id="product-search" class="form-control" placeholder="Search by product name, code, or scan barcode...">
                            <button class="btn btn-primary" id="search-btn">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                        <div id="search-results"></div>
                    </div>
                </div>
                
                <!-- Cart -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between">
                        <span><i class="fas fa-shopping-cart me-2"></i>Shopping Cart</span>
                        <button class="btn btn-sm btn-danger" id="clear-cart">
                            <i class="fas fa-trash"></i> Clear Cart
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="cart-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Price</th>
                                        <th>Qty</th>
                                        <th>Tax</th>
                                        <th>Subtotal</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="cart-items">
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">Cart is empty</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sale Summary -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-calculator me-2"></i>Sale Summary
                    </div>
                    <div class="card-body">
                        <form id="sale-form" method="POST">
                            <div class="mb-3">
                                <label class="form-label">Store/Branch *</label>
                                <select name="store_id" id="store-select" class="form-select" required>
                                    <option value="">Select Store</option>
                                    <?php foreach ($stores as $store): ?>
                                        <option value="<?php echo $store['store_id']; ?>">
                                            <?php echo $store['store_name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Customer (Optional)</label>
                                <select name="customer_id" id="customer-select" class="form-select select2">
                                    <option value="">Walk-in Customer</option>
                                </select>
                            </div>
                            
                            <hr>
                            
                            <div class="mb-2 d-flex justify-content-between">
                                <strong>Subtotal:</strong>
                                <span id="subtotal-display">UGX 0.00</span>
                            </div>
                            
                            <div class="mb-2 d-flex justify-content-between">
                                <strong>Tax:</strong>
                                <span id="tax-display">UGX 0.00</span>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Discount</label>
                                <input type="number" name="discount_amount" id="discount-input" class="form-control" step="0.01" min="0" value="0">
                            </div>
                            
                            <div class="mb-3 d-flex justify-content-between">
                                <h5>Total:</h5>
                                <h5 class="text-primary" id="total-display">UGX 0.00</h5>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Payment Method *</label>
                                <select name="payment_method" class="form-select" required>
                                    <?php foreach ($payment_methods as $method): ?>
                                        <option value="<?php echo $method; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $method)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Amount Paid *</label>
                                <input type="number" name="amount_paid" id="amount-paid" class="form-control" step="0.01" min="0" required>
                            </div>
                            
                            <div class="mb-3 d-flex justify-content-between">
                                <strong>Change:</strong>
                                <strong class="text-success" id="change-display">UGX 0.00</strong>
                            </div>
                            
                            <input type="hidden" name="cart_items" id="cart-items-input">
                            <input type="hidden" name="complete_sale" value="1">
                            
                            <button type="submit" class="btn btn-primary w-100 btn-lg" id="complete-sale-btn" disabled>
                                <i class="fas fa-check-circle me-2"></i>Complete Sale
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let cart = [];
let storeId = null;

$(document).ready(function() {
    // Load customers
    $.ajax({
        url: '../includes/ajax_customers.php',
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            data.forEach(function(customer) {
                $('#customer-select').append(new Option(customer.customer_name + ' - ' + customer.phone, customer.customer_id));
            });
        }
    });
    
    // Product search
    $('#product-search').on('keyup', function(e) {
        if (e.key === 'Enter' || $(this).val().length >= 3) {
            searchProducts();
        }
    });
    
    $('#search-btn').on('click', function() {
        searchProducts();
    });
    
    // Store selection
    $('#store-select').on('change', function() {
        storeId = $(this).val();
        if (cart.length > 0 && storeId) {
            $('#complete-sale-btn').prop('disabled', false);
        }
    });
    
    // Discount calculation
    $('#discount-input').on('input', function() {
        updateTotals();
    });
    
    // Amount paid calculation
    $('#amount-paid').on('input', function() {
        calculateChange();
    });
    
    // Clear cart
    $('#clear-cart').on('click', function() {
        if (confirm('Are you sure you want to clear the cart?')) {
            cart = [];
            updateCart();
        }
    });
    
    // Form submission
    $('#sale-form').on('submit', function(e) {
        if (!storeId) {
            e.preventDefault();
            alert('Please select a store');
            return false;
        }
        if (cart.length === 0) {
            e.preventDefault();
            alert('Cart is empty');
            return false;
        }
        $('#cart-items-input').val(JSON.stringify(cart));
    });
});

function searchProducts() {
    const query = $('#product-search').val();
    const store_id = $('#store-select').val();
    
    if (!store_id) {
        alert('Please select a store first');
        return;
    }
    
    if (query.length < 2) {
        return;
    }
    
    $.ajax({
        url: '../includes/ajax_search_products.php',
        method: 'POST',
        data: { query: query, store_id: store_id },
        dataType: 'json',
        success: function(products) {
            let html = '<div class="row">';
            products.forEach(function(product) {
                html += `
                    <div class="col-md-4 mb-3">
                        <div class="card product-card" onclick="addToCart(${product.product_id}, '${product.product_name}', ${product.selling_price}, ${product.purchase_price}, ${product.tax_rate}, ${product.quantity})">
                            <div class="card-body text-center">
                                <h6>${product.product_name}</h6>
                                <p class="mb-1"><strong>UGX ${parseFloat(product.selling_price).toFixed(2)}</strong></p>
                                <small class="text-muted">Stock: ${product.quantity}</small><br>
                                <small class="text-info">${product.product_code}</small>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            $('#search-results').html(html);
        }
    });
}

function addToCart(productId, productName, price, cost, taxRate, availableQty) {
    const existingItem = cart.find(item => item.product_id === productId);
    
    if (existingItem) {
        if (existingItem.quantity < availableQty) {
            existingItem.quantity++;
        } else {
            alert('Cannot add more. Stock limit reached.');
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
    }
    
    updateCart();
}

function removeFromCart(index) {
    cart.splice(index, 1);
    updateCart();
}

function updateQuantity(index, newQty) {
    if (newQty > 0 && newQty <= cart[index].available_qty) {
        cart[index].quantity = parseInt(newQty);
        updateCart();
    } else if (newQty > cart[index].available_qty) {
        alert('Quantity exceeds available stock');
    }
}

function updateCart() {
    let html = '';
    
    if (cart.length === 0) {
        html = '<tr><td colspan="6" class="text-center text-muted">Cart is empty</td></tr>';
        $('#complete-sale-btn').prop('disabled', true);
    } else {
        cart.forEach(function(item, index) {
            const taxAmount = (item.price * item.quantity * item.tax_rate / 100);
            item.tax_amount = taxAmount;
            const subtotal = item.price * item.quantity;
            
            html += `
                <tr>
                    <td>${item.product_name}</td>
                    <td>UGX ${item.price.toFixed(2)}</td>
                    <td>
                        <input type="number" class="form-control form-control-sm" style="width: 70px;" 
                               value="${item.quantity}" min="1" max="${item.available_qty}" 
                               onchange="updateQuantity(${index}, this.value)">
                    </td>
                    <td>UGX ${taxAmount.toFixed(2)}</td>
                    <td>UGX ${subtotal.toFixed(2)}</td>
                    <td>
                        <button class="btn btn-sm btn-danger" onclick="removeFromCart(${index})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        });
        
        if (storeId) {
            $('#complete-sale-btn').prop('disabled', false);
        }
    }
    
    $('#cart-items').html(html);
    updateTotals();
}

function updateTotals() {
    let subtotal = 0;
    let taxTotal = 0;
    
    cart.forEach(function(item) {
        subtotal += item.price * item.quantity;
        taxTotal += item.tax_amount;
    });
    
    const discount = parseFloat($('#discount-input').val()) || 0;
    const total = subtotal + taxTotal - discount;
    
    $('#subtotal-display').text('UGX ' + subtotal.toFixed(2));
    $('#tax-display').text('UGX ' + taxTotal.toFixed(2));
    $('#total-display').text('UGX ' + total.toFixed(2));
    
    calculateChange();
}

function calculateChange() {
    const total = parseFloat($('#total-display').text().replace('UGX ', ''));
    const paid = parseFloat($('#amount-paid').val()) || 0;
    const change = paid - total;
    
    $('#change-display').text('UGX ' + (change > 0 ? change.toFixed(2) : '0.00'));
}
</script>

<style>
.product-card {
    cursor: pointer;
    transition: all 0.3s;
}
.product-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}
</style>

<?php include '../includes/footer.php'; ?>
