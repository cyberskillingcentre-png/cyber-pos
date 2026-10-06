<?php
require_once '../config/config.php';
require_login();
if (!is_admin()) { header('Location: ../employee/dashboard.php'); exit(); }

$page_title = "Confirm Client Order";
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($order_id <= 0) {
    set_alert('danger', 'Invalid order ID');
    header('Location: Client_Orders.php');
    exit;
}

// ============================================
// GET ORDER DETAILS
// ============================================
$order = null;
try {
    $stmt = $conn->prepare("
        SELECT co.*, 
               u.full_name as client_name, 
               u.email as client_email,
               u.phone as client_phone,
               cp.company_name
        FROM client_orders co
        LEFT JOIN users u ON co.client_id = u.user_id
        LEFT JOIN client_profiles cp ON u.user_id = cp.user_id
        WHERE co.order_id = ? AND co.order_status = 'pending'
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        set_alert('danger', 'Order not found or already processed');
        header('Location: Client_Orders.php');
        exit;
    }
} catch(PDOException $e) {
    set_alert('danger', 'Error loading order: ' . $e->getMessage());
    header('Location: Client_Orders.php');
    exit;
}

// ============================================
// GET ORDER ITEMS
// ============================================
$items = [];
try {
    $stmt = $conn->prepare("
        SELECT coi.*, p.product_code, p.product_name as full_product_name
        FROM client_order_items coi
        LEFT JOIN products p ON coi.product_id = p.product_id
        WHERE coi.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $items = [];
}

// ============================================
// GET ACTIVE STORES
// ============================================
$stores = [];
try {
    $stmt = $conn->query("SELECT * FROM stores WHERE status = 'active' ORDER BY store_name");
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $stores = [];
}

// ============================================
// STOCK CHECKING
// ============================================
$total_stock_data = [];
foreach ($items as $item) {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(quantity), 0) as total_available
        FROM store_inventory
        WHERE product_id = ?
    ");
    $stmt->execute([$item['product_id']]);
    $total_avail = $stmt->fetchColumn();
    
    $total_stock_data[$item['product_id']] = [
        'total_available' => $total_avail,
        'required' => $item['quantity'],
        'sufficient' => $total_avail >= $item['quantity']
    ];
}

// Check if all stores together can fulfill
$all_stores_can_fulfill = true;
foreach ($items as $item) {
    if (!$total_stock_data[$item['product_id']]['sufficient']) {
        $all_stores_can_fulfill = false;
        break;
    }
}

// Check stock for each item in each store
$stock_data = [];
foreach ($items as $item) {
    $stock_data[$item['product_id']] = [
        'product_name' => $item['product_name'],
        'product_code' => $item['product_code'],
        'required_qty' => $item['quantity'],
        'stores' => []
    ];
    
    foreach ($stores as $store) {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(quantity), 0) as available_stock
            FROM store_inventory
            WHERE product_id = ? AND store_id = ?
        ");
        $stmt->execute([$item['product_id'], $store['store_id']]);
        $available = $stmt->fetchColumn();
        
        $stock_data[$item['product_id']]['stores'][$store['store_id']] = [
            'available' => $available,
            'sufficient' => $available >= $item['quantity']
        ];
    }
}

// Determine which stores can fulfill the entire order
$can_fulfill = [];
foreach ($stores as $store) {
    $store_can_do_it = true;
    foreach ($items as $item) {
        if (!$stock_data[$item['product_id']]['stores'][$store['store_id']]['sufficient']) {
            $store_can_do_it = false;
            break;
        }
    }
    $can_fulfill[$store['store_id']] = $store_can_do_it;
}

$alert = get_alert();
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
                
                <button type="button" class="btn btn-link sidebar-desktop-toggle me-2" 
                        id="sidebarDesktopToggle" title="Toggle Sidebar (Ctrl+B)">
                    <i class="fas fa-bars"></i>
                </button>
                
                <h5 class="mb-0">
                    <i class="fas fa-check-circle me-2" style="color: var(--cyber-orange);"></i>
                    Confirm Order: <?php echo htmlspecialchars($order['order_number']); ?>
                </h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="View_Order.php?id=<?php echo $order_id; ?>" class="btn btn-sm btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>
    
    <div class="content-area">
        <?php if ($alert): ?>
        <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
            <i class="fas fa-info-circle me-2"></i>
            <?php echo $alert['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Info Alert -->
        <div class="alert alert-warning alert-cyber">
            <div class="d-flex align-items-start gap-3">
                <div class="alert-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div>
                    <h6 class="mb-1"><strong>Important</strong></h6>
                    <p class="mb-0">Verify stock availability and select a store before confirming. Inventory will be deducted and the transaction recorded automatically.</p>
                </div>
            </div>
        </div>
        
        <form method="POST" action="process-confirmation.php" id="confirmForm">
            <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
            
            <div class="row g-4">
                <!-- LEFT COLUMN -->
                <div class="col-lg-8">
                    
                    <!-- Order Items -->
                    <div class="card cyber-card mb-4">
                        <div class="card-header">
                            <i class="fas fa-box me-2"></i>Order Items
                            <span class="badge bg-warning text-dark ms-auto"><?php echo count($items); ?> item(s)</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table cyber-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Code</th>
                                            <th class="text-center">Qty</th>
                                            <th class="text-end">Unit Price</th>
                                            <th class="text-end">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $item): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></td>
                                            <td><code><?php echo htmlspecialchars($item['product_code'] ?? 'N/A'); ?></code></td>
                                            <td class="text-center">
                                                <span class="badge bg-primary"><?php echo intval($item['quantity']); ?></span>
                                            </td>
                                            <td class="text-end">
                                                UGX <?php echo number_format(floatval($item['unit_price']), 0); ?>
                                            </td>
                                            <td class="text-end">
                                                <strong>UGX <?php echo number_format(floatval($item['total_price']), 0); ?></strong>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="grand-total">
                                            <td colspan="4" class="text-end"><strong>GRAND TOTAL:</strong></td>
                                            <td class="text-end">
                                                <strong class="fs-5">UGX <?php echo number_format(floatval($order['grand_total']), 0); ?></strong>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stock Availability Matrix -->
                    <div class="card cyber-card">
                        <div class="card-header bg-info">
                            <i class="fas fa-warehouse me-2"></i>Stock Availability by Store
                        </div>
                        <div class="card-body">
                            <div id="stockAlert"></div>
                            
                            <div class="table-responsive">
                                <table class="table table-bordered stock-matrix">
                                    <thead>
                                        <tr>
                                            <th style="min-width: 180px;">Store</th>
                                            <?php foreach ($items as $item): ?>
                                            <th class="text-center">
                                                <?php echo htmlspecialchars($item['product_name']); ?>
                                                <br><small class="text-muted">Need: <?php echo $item['quantity']; ?></small>
                                            </th>
                                            <?php endforeach; ?>
                                            <th class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- All Stores (FIFO) -->
                                        <tr class="<?php echo $all_stores_can_fulfill ? 'stock-ok' : 'stock-fail'; ?>">
                                            <td>
                                                <strong>
                                                    <i class="fas fa-layer-group me-2" style="color: var(--cyber-purple);"></i>
                                                    All Stores (FIFO)
                                                </strong>
                                            </td>
                                            <?php foreach ($items as $item): 
                                                $total_info = $total_stock_data[$item['product_id']];
                                            ?>
                                            <td class="text-center">
                                                <?php if ($total_info['sufficient']): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check me-1"></i><?php echo number_format($total_info['total_available']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">
                                                        <i class="fas fa-times me-1"></i><?php echo number_format($total_info['total_available']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <?php endforeach; ?>
                                            <td class="text-center">
                                                <?php if ($all_stores_can_fulfill): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check"></i> Can Fulfill
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">
                                                        <i class="fas fa-times"></i> Insufficient
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        
                                        <!-- Individual Stores -->
                                        <?php foreach ($stores as $store): ?>
                                        <tr class="<?php echo $can_fulfill[$store['store_id']] ? 'stock-ok' : 'stock-fail'; ?>">
                                            <td>
                                                <i class="fas fa-store me-2" style="color: var(--cyber-orange);"></i>
                                                <strong><?php echo htmlspecialchars($store['store_name']); ?></strong>
                                            </td>
                                            <?php foreach ($items as $item): 
                                                $stock_info = $stock_data[$item['product_id']]['stores'][$store['store_id']];
                                            ?>
                                            <td class="text-center">
                                                <?php if ($stock_info['sufficient']): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check me-1"></i><?php echo number_format($stock_info['available']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">
                                                        <i class="fas fa-times me-1"></i><?php echo number_format($stock_info['available']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <?php endforeach; ?>
                                            <td class="text-center">
                                                <?php if ($can_fulfill[$store['store_id']]): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check"></i> Can Fulfill
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">
                                                        <i class="fas fa-times"></i> Insufficient
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- RIGHT COLUMN -->
                <div class="col-lg-4">
                    
                    <!-- Store Selection -->
                    <div class="card cyber-card mb-4">
                        <div class="card-header bg-warning text-dark">
                            <i class="fas fa-store me-2"></i>Select Store *
                        </div>
                        <div class="card-body">
                            <select name="store_id" id="storeSelect" class="form-select form-select-cyber" required onchange="validateStoreSelection()">
                                <option value="">-- Select Store --</option>
                                
                                <option value="0" 
                                        data-can-fulfill="<?php echo $all_stores_can_fulfill ? '1' : '0'; ?>"
                                        <?php echo !$all_stores_can_fulfill ? 'disabled' : ''; ?>>
                                    🏢 All Stores (FIFO)
                                    <?php if (!$all_stores_can_fulfill): ?>
                                        — Insufficient Total Stock
                                    <?php endif; ?>
                                </option>
                                
                                <option disabled>──────────────</option>
                                
                                <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['store_id']; ?>" 
                                        data-can-fulfill="<?php echo $can_fulfill[$store['store_id']] ? '1' : '0'; ?>"
                                        <?php echo !$can_fulfill[$store['store_id']] ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($store['store_name']); ?>
                                    <?php if (!$can_fulfill[$store['store_id']]): ?>
                                        — Insufficient
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <div class="info-box mt-3">
                                <i class="fas fa-info-circle me-2" style="color: var(--cyber-purple);"></i>
                                <small>
                                    <strong>All Stores (FIFO):</strong> Deducts inventory from multiple stores using First-In-First-Out method
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Details -->
                    <div class="card cyber-card mb-4">
                        <div class="card-header bg-success">
                            <i class="fas fa-money-bill-wave me-2"></i>Payment Details *
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label-cyber">Payment Type *</label>
                                <select name="payment_type" id="paymentType" class="form-select form-select-cyber" required onchange="togglePaymentFields()">
                                    <option value="paid">✅ Paid (Full Payment)</option>
                                    <option value="credit">📋 Credit (Pay Later)</option>
                                    <option value="partial">💰 Partial Payment</option>
                                </select>
                            </div>
                            
                            <div id="amountPaidField" style="display:none;">
                                <label class="form-label-cyber">Amount Paid *</label>
                                <div class="amount-input-wrapper">
                                    <span class="currency-prefix">UGX</span>
                                    <input type="number" name="amount_paid" class="amount-input" 
                                           step="0.01" min="0" max="<?php echo floatval($order['grand_total']); ?>" 
                                           placeholder="0.00">
                                </div>
                                <small class="text-muted">
                                    Total: <strong>UGX <?php echo number_format(floatval($order['grand_total']), 0); ?></strong>
                                </small>
                            </div>
                            
                            <div class="mt-3">
                                <label class="form-label-cyber">Transaction Notes</label>
                                <textarea name="transaction_notes" class="form-control form-control-cyber" rows="2" 
                                          placeholder="Payment reference, receipt number, etc."></textarea>
                            </div>
                            
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" name="send_invoice" id="sendInvoice" checked>
                                <label class="form-check-label" for="sendInvoice">
                                    <strong>Email invoice to client</strong>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Confirm Button -->
                    <div class="card cyber-card">
                        <div class="card-body text-center">
                            <button type="submit" class="btn-confirm" id="confirmBtn" disabled>
                                <i class="fas fa-check-circle"></i>
                                <span>CONFIRM ORDER</span>
                            </button>
                            <small class="text-muted mt-3 d-block" id="confirmHint">
                                Select a store to enable confirmation
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Page Styles -->
<style>
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
    padding: 14px 20px;
    border: none;
    font-weight: 600;
    display: flex;
    align-items: center;
}

.cyber-card .card-header i { color: #ED8936; margin-right: 8px; }

.cyber-card .card-header.bg-info {
    background: linear-gradient(135deg, #00B5D8, #0987A0) !important;
}

.cyber-card .card-header.bg-warning {
    background: linear-gradient(135deg, #ED8936, #DD6B20) !important;
    color: #fff !important;
}

.cyber-card .card-header.bg-success {
    background: linear-gradient(135deg, #48BB78, #38A169) !important;
}

/* Alert cyber */
.alert-cyber {
    background: linear-gradient(135deg, rgba(237, 137, 54, 0.1), rgba(237, 137, 54, 0.05));
    border: none;
    border-left: 4px solid #ED8936;
    border-radius: 10px;
    padding: 16px 20px;
}

.alert-cyber .alert-icon {
    width: 42px;
    height: 42px;
    background: linear-gradient(135deg, #ED8936, #DD6B20);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.2rem;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(237, 137, 54, 0.3);
}

/* Cyber table */
.cyber-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.cyber-table thead th {
    background: #F7FAFC;
    color: #4A5568;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 14px 16px;
    border-bottom: 2px solid #E2E8F0;
    text-align: left;
    white-space: nowrap;
}

.cyber-table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid #EDF2F7;
    vertical-align: middle;
}

.cyber-table tbody tr:hover { background: #F7FAFC; }

.cyber-table code {
    font-size: 0.8rem;
    color: #6B46C1;
    background: rgba(107, 70, 193, 0.08);
    padding: 2px 6px;
    border-radius: 4px;
}

.cyber-table tfoot .grand-total td {
    background: linear-gradient(135deg, #6B46C1, #553C9A);
    color: #fff;
    font-size: 1rem;
    padding: 16px;
}

/* Stock matrix */
.stock-matrix thead th {
    background: #1A202C;
    color: #fff;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px 10px;
    border-color: #2D3748;
}

.stock-matrix tbody td {
    padding: 12px 10px;
    vertical-align: middle;
    border-color: #E2E8F0;
}

.stock-matrix tr.stock-ok {
    background: rgba(72, 187, 120, 0.05);
}

.stock-matrix tr.stock-fail {
    background: rgba(229, 62, 62, 0.05);
}

.stock-matrix .badge {
    font-size: 0.75rem;
    padding: 5px 10px;
    font-weight: 700;
}

/* Form */
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

.info-box {
    padding: 10px 12px;
    background: rgba(107, 70, 193, 0.05);
    border-radius: 8px;
    border-left: 3px solid #6B46C1;
}

/* Amount input */
.amount-input-wrapper {
    display: flex;
    align-items: center;
    background: #F7FAFC;
    border: 2px solid #E2E8F0;
    border-radius: 8px;
    overflow: hidden;
    transition: all 0.2s ease;
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
    font-weight: 800;
    font-size: 0.75rem;
}

.amount-input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 12px 16px;
    font-size: 1rem;
    font-weight: 700;
    font-family: 'Courier New', monospace;
    outline: none;
}

/* Confirm button */
.btn-confirm {
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
}

.btn-confirm:hover:not(:disabled) {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(72, 187, 120, 0.5);
}

.btn-confirm:disabled {
    background: #CBD5E0;
    cursor: not-allowed;
    box-shadow: none;
    opacity: 0.6;
}

.btn-confirm i { font-size: 1.25rem; }
</style>

<?php include '../includes/footer.php'; ?>

<script>
/* ============================================
   CONFIRM ORDER PAGE LOGIC
   (Sidebar collapse handled by shared sidebar.js)
   ============================================ */

function togglePaymentFields() {
    const paymentType = document.getElementById('paymentType').value;
    const amountField = document.getElementById('amountPaidField');
    const amountInput = document.querySelector('[name="amount_paid"]');
    
    if (paymentType === 'partial') {
        amountField.style.display = 'block';
        amountInput.required = true;
    } else {
        amountField.style.display = 'none';
        amountInput.required = false;
    }
}

function validateStoreSelection() {
    const select = document.getElementById('storeSelect');
    const confirmBtn = document.getElementById('confirmBtn');
    const stockAlert = document.getElementById('stockAlert');
    const confirmHint = document.getElementById('confirmHint');
    
    if (!select.value) {
        confirmBtn.disabled = true;
        stockAlert.innerHTML = '';
        confirmHint.textContent = 'Select a store to enable confirmation';
        return;
    }
    
    const option = select.options[select.selectedIndex];
    const canFulfill = option.getAttribute('data-can-fulfill') === '1';
    const isAllStores = select.value === '0';
    
    if (canFulfill) {
        confirmBtn.disabled = false;
        confirmHint.textContent = 'Ready to confirm';
        
        if (isAllStores) {
            stockAlert.innerHTML = `
                <div class="alert alert-success mb-3">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Multi-Store FIFO!</strong> 
                    Inventory will be deducted from multiple stores using First-In-First-Out method.
                </div>`;
        } else {
            stockAlert.innerHTML = `
                <div class="alert alert-success mb-3">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Stock Available!</strong> 
                    This store can fulfill the entire order.
                </div>`;
        }
    } else {
        confirmBtn.disabled = true;
        confirmHint.textContent = 'Cannot proceed — insufficient stock';
        stockAlert.innerHTML = `
            <div class="alert alert-danger mb-3">
                <i class="fas fa-times-circle me-2"></i>
                <strong>Insufficient Stock!</strong> 
                This store cannot fulfill the order.
            </div>`;
    }
}

// Form submission
document.getElementById('confirmForm').addEventListener('submit', function(e) {
    const store = document.getElementById('storeSelect').value;
    
    if (!store) {
        e.preventDefault();
        alert('Please select a store');
        return false;
    }
    
    const isAllStores = store === '0';
    const confirmMsg = isAllStores 
        ? 'Confirm this order using MULTI-STORE FIFO?\n\n' +
          '• Inventory will be deducted from multiple stores\n' +
          '• Transaction will be recorded\n' +
          '• Invoice will be generated\n\n' +
          'Continue?'
        : 'Confirm this order?\n\n' +
          '• Inventory will be deducted\n' +
          '• Transaction will be recorded\n' +
          '• Invoice will be generated\n\n' +
          'Continue?';
    
    if (!confirm(confirmMsg)) {
        e.preventDefault();
        return false;
    }
    
    // Show loading state
    const btn = document.getElementById('confirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
});

// Auto-init on load
document.addEventListener('DOMContentLoaded', function() {
    // If store was pre-selected, validate it
    const select = document.getElementById('storeSelect');
    if (select.value) {
        validateStoreSelection();
    }
});
</script>