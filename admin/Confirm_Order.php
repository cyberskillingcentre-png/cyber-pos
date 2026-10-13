<?php
require_once '../config/config.php';
require_login();
if (!is_admin()) { header('Location: ../employee/dashboard.php'); exit(); }

$page_title = "Confirm Client Order";

// ============================================
// RESOLVE ORDER ID — accept from GET or POST
// ============================================
$order_id = 0;
if (isset($_GET['id']))  $order_id = intval($_GET['id']);
if ($order_id <= 0 && isset($_POST['order_id'])) $order_id = intval($_POST['order_id']);

if ($order_id <= 0) {
    set_alert('danger', 'Invalid order ID');
    header('Location: Client_Orders.php');
    exit;
}

// ============================================
// LOAD ORDER (pending only)
// ============================================
$order = null;
try {
    $stmt = $conn->prepare("
        SELECT co.*,
               u.full_name AS client_name,
               u.email     AS client_email,
               u.phone     AS client_phone,
               cp.company_name
        FROM client_orders co
        LEFT JOIN users u            ON co.client_id = u.user_id
        LEFT JOIN client_profiles cp ON u.user_id   = cp.user_id
        WHERE co.order_id = ? AND co.order_status = 'pending'
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        set_alert('danger', 'Order not found or already processed');
        header('Location: Client_Orders.php');
        exit;
    }
} catch (PDOException $e) {
    set_alert('danger', 'Error loading order: ' . $e->getMessage());
    header('Location: Client_Orders.php');
    exit;
}

// ============================================
// LOAD ITEMS
// ============================================
$items = [];
try {
    $stmt = $conn->prepare("
        SELECT coi.*, p.product_code, p.product_name AS full_product_name
        FROM client_order_items coi
        LEFT JOIN products p ON coi.product_id = p.product_id
        WHERE coi.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $items = []; }

// ============================================
// LOAD STORES
// ============================================
$stores = [];
try {
    $stores = $conn->query("SELECT * FROM stores WHERE status = 'active' ORDER BY store_name")
                   ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $stores = []; }

// ============================================
// STOCK CHECK
// ============================================
$total_stock_data = [];
foreach ($items as $item) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) FROM store_inventory WHERE product_id = ?");
    $stmt->execute([$item['product_id']]);
    $avail = (int) $stmt->fetchColumn();
    $total_stock_data[$item['product_id']] = [
        'total_available' => $avail,
        'required'        => (int) $item['quantity'],
        'sufficient'      => $avail >= (int) $item['quantity'],
    ];
}

$all_stores_can_fulfill = true;
foreach ($items as $item) {
    if (!$total_stock_data[$item['product_id']]['sufficient']) {
        $all_stores_can_fulfill = false;
        break;
    }
}

$stock_data = [];
foreach ($items as $item) {
    $stock_data[$item['product_id']] = ['stores' => []];
    foreach ($stores as $store) {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) FROM store_inventory WHERE product_id = ? AND store_id = ?");
        $stmt->execute([$item['product_id'], $store['store_id']]);
        $a = (int) $stmt->fetchColumn();
        $stock_data[$item['product_id']]['stores'][$store['store_id']] = [
            'available'  => $a,
            'sufficient' => $a >= (int) $item['quantity'],
        ];
    }
}

$can_fulfill = [];
foreach ($stores as $store) {
    $ok = true;
    foreach ($items as $item) {
        if (!$stock_data[$item['product_id']]['stores'][$store['store_id']]['sufficient']) {
            $ok = false; break;
        }
    }
    $can_fulfill[$store['store_id']] = $ok;
}

$alert = get_alert();
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <div class="d-flex justify-content-between align-items-center w-100">
            <div class="d-flex align-items-center">
                <button class="btn btn-link d-lg-none sidebar-toggle me-2"><i class="fas fa-bars"></i></button>
                <button type="button" class="btn btn-link sidebar-desktop-toggle me-2" id="sidebarDesktopToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h5 class="mb-0">
                    <i class="fas fa-check-circle me-2" style="color: var(--cyber-orange);"></i>
                    Confirm Order: <?php echo htmlspecialchars($order['order_number']); ?>
                </h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="View_Order.php?id=<?php echo (int)$order_id; ?>" class="btn btn-sm btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>

    <div class="content-area">
        <?php if ($alert): ?>
        <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
            <?php echo $alert['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="alert alert-warning alert-cyber">
            <div class="d-flex align-items-start gap-3">
                <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div>
                    <h6 class="mb-1"><strong>Important</strong></h6>
                    <p class="mb-0">Verify stock availability and select a store before confirming.</p>
                </div>
            </div>
        </div>

        <form method="POST" action="process-confirmation.php" id="confirmForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="order_id" value="<?php echo (int) $order_id; ?>">

            <div class="row g-4">
                <div class="col-lg-8">
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
                                            <td class="text-center"><span class="badge bg-primary"><?php echo (int)$item['quantity']; ?></span></td>
                                            <td class="text-end">UGX <?php echo number_format((float)$item['unit_price'], 0); ?></td>
                                            <td class="text-end"><strong>UGX <?php echo number_format((float)$item['total_price'], 0); ?></strong></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="grand-total">
                                            <td colspan="4" class="text-end"><strong>GRAND TOTAL:</strong></td>
                                            <td class="text-end"><strong class="fs-5">UGX <?php echo number_format((float)$order['grand_total'], 0); ?></strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

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
                                                <br><small class="text-muted">Need: <?php echo (int)$item['quantity']; ?></small>
                                            </th>
                                            <?php endforeach; ?>
                                            <th class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="<?php echo $all_stores_can_fulfill ? 'stock-ok' : 'stock-fail'; ?>">
                                            <td><strong><i class="fas fa-layer-group me-2" style="color: var(--cyber-purple);"></i>All Stores (FIFO)</strong></td>
                                            <?php foreach ($items as $item): $t = $total_stock_data[$item['product_id']]; ?>
                                            <td class="text-center">
                                                <span class="badge bg-<?php echo $t['sufficient'] ? 'success' : 'danger'; ?>">
                                                    <i class="fas fa-<?php echo $t['sufficient'] ? 'check' : 'times'; ?> me-1"></i><?php echo number_format($t['total_available']); ?>
                                                </span>
                                            </td>
                                            <?php endforeach; ?>
                                            <td class="text-center">
                                                <span class="badge bg-<?php echo $all_stores_can_fulfill ? 'success' : 'danger'; ?>">
                                                    <?php echo $all_stores_can_fulfill ? 'Can Fulfill' : 'Insufficient'; ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php foreach ($stores as $store): ?>
                                        <tr class="<?php echo $can_fulfill[$store['store_id']] ? 'stock-ok' : 'stock-fail'; ?>">
                                            <td><i class="fas fa-store me-2" style="color: var(--cyber-orange);"></i><strong><?php echo htmlspecialchars($store['store_name']); ?></strong></td>
                                            <?php foreach ($items as $item): $s = $stock_data[$item['product_id']]['stores'][$store['store_id']]; ?>
                                            <td class="text-center">
                                                <span class="badge bg-<?php echo $s['sufficient'] ? 'success' : 'danger'; ?>">
                                                    <i class="fas fa-<?php echo $s['sufficient'] ? 'check' : 'times'; ?> me-1"></i><?php echo number_format($s['available']); ?>
                                                </span>
                                            </td>
                                            <?php endforeach; ?>
                                            <td class="text-center">
                                                <span class="badge bg-<?php echo $can_fulfill[$store['store_id']] ? 'success' : 'danger'; ?>">
                                                    <?php echo $can_fulfill[$store['store_id']] ? 'Can Fulfill' : 'Insufficient'; ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card cyber-card mb-4">
                        <div class="card-header bg-warning text-dark">
                            <i class="fas fa-store me-2"></i>Select Store *
                        </div>
                        <div class="card-body">
                            <select name="store_id" id="storeSelect" class="form-select form-select-cyber" required onchange="validateStoreSelection()">
                                <option value="">-- Select Store --</option>
                                <option value="0" data-can-fulfill="<?php echo $all_stores_can_fulfill ? '1' : '0'; ?>" <?php echo !$all_stores_can_fulfill ? 'disabled' : ''; ?>>
                                    🏢 All Stores (FIFO)<?php echo !$all_stores_can_fulfill ? ' — Insufficient Total Stock' : ''; ?>
                                </option>
                                <option disabled>──────────────</option>
                                <?php foreach ($stores as $store): ?>
                                <option value="<?php echo (int)$store['store_id']; ?>"
                                        data-can-fulfill="<?php echo $can_fulfill[$store['store_id']] ? '1' : '0'; ?>"
                                        <?php echo !$can_fulfill[$store['store_id']] ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($store['store_name']); ?><?php echo !$can_fulfill[$store['store_id']] ? ' — Insufficient' : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="info-box mt-3">
                                <i class="fas fa-info-circle me-2" style="color: var(--cyber-purple);"></i>
                                <small><strong>All Stores (FIFO):</strong> Deducts inventory from multiple stores using First-In-First-Out.</small>
                            </div>
                        </div>
                    </div>

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
                                           step="0.01" min="0" max="<?php echo (float)$order['grand_total']; ?>" placeholder="0.00">
                                </div>
                                <small class="text-muted">Total: <strong>UGX <?php echo number_format((float)$order['grand_total'], 0); ?></strong></small>
                            </div>
                            <div class="mt-3">
                                <label class="form-label-cyber">Transaction Notes</label>
                                <textarea name="transaction_notes" class="form-control form-control-cyber" rows="2" placeholder="Payment reference..."></textarea>
                            </div>
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" name="send_invoice" id="sendInvoice" value="1" checked>
                                <label class="form-check-label" for="sendInvoice"><strong>Email invoice to client</strong></label>
                            </div>
                        </div>
                    </div>

                    <div class="card cyber-card mb-4">
                        <div class="card-header bg-purple">
                            <i class="fas fa-user-check me-2"></i>Confirming As
                        </div>
                        <div class="card-body">
                            <div class="confirmed-by-preview">
                                <div class="confirmed-by-avatar">
                                    <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                                </div>
                                <div class="confirmed-by-info">
                                    <div class="confirmed-by-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Unknown'); ?></div>
                                    <div class="confirmed-by-role"><?php echo htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin')); ?></div>
                                </div>
                            </div>
                            <p class="confirmed-by-note mb-0">
                                <i class="fas fa-info-circle me-1"></i>This order will be recorded under your account.
                            </p>
                        </div>
                    </div>

                    <div class="card cyber-card">
                        <div class="card-body text-center">
                            <button type="submit" class="btn-confirm" id="confirmBtn" disabled>
                                <i class="fas fa-check-circle"></i>
                                <span>CONFIRM ORDER</span>
                            </button>
                            <small class="text-muted mt-3 d-block" id="confirmHint">Select a store to enable confirmation</small>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
.cyber-card { background: #fff; border: 1px solid #E2E8F0; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.08); }
.cyber-card .card-header { background: linear-gradient(135deg, #1A202C 0%, #2D3748 100%); color: #fff; padding: 14px 20px; border: none; font-weight: 600; display: flex; align-items: center; }
.cyber-card .card-header i { color: #ED8936; margin-right: 8px; }
.cyber-card .card-header.bg-info    { background: linear-gradient(135deg, #00B5D8, #0987A0) !important; }
.cyber-card .card-header.bg-warning { background: linear-gradient(135deg, #ED8936, #DD6B20) !important; color: #fff !important; }
.cyber-card .card-header.bg-success { background: linear-gradient(135deg, #48BB78, #38A169) !important; }
.cyber-card .card-header.bg-purple  { background: linear-gradient(135deg, #6B46C1, #553C9A) !important; }

.alert-cyber { background: linear-gradient(135deg, rgba(237,137,54,0.1), rgba(237,137,54,0.05)); border: none; border-left: 4px solid #ED8936; border-radius: 10px; padding: 16px 20px; }
.alert-cyber .alert-icon { width: 42px; height: 42px; background: linear-gradient(135deg, #ED8936, #DD6B20); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.2rem; flex-shrink: 0; }

.cyber-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
.cyber-table thead th { background: #F7FAFC; color: #4A5568; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; padding: 14px 16px; border-bottom: 2px solid #E2E8F0; text-align: left; }
.cyber-table tbody td { padding: 14px 16px; border-bottom: 1px solid #EDF2F7; vertical-align: middle; }
.cyber-table code { font-size: 0.8rem; color: #6B46C1; background: rgba(107,70,193,0.08); padding: 2px 6px; border-radius: 4px; }
.cyber-table tfoot .grand-total td { background: linear-gradient(135deg, #6B46C1, #553C9A); color: #fff; padding: 16px; }

.stock-matrix thead th { background: #1A202C; color: #fff; font-size: 0.75rem; text-transform: uppercase; padding: 12px 10px; border-color: #2D3748; }
.stock-matrix tbody td { padding: 12px 10px; vertical-align: middle; border-color: #E2E8F0; }
.stock-matrix tr.stock-ok   { background: rgba(72,187,120,0.05); }
.stock-matrix tr.stock-fail { background: rgba(229,62,62,0.05); }
.stock-matrix .badge { font-size: 0.75rem; padding: 5px 10px; font-weight: 700; }

.form-label-cyber { display: flex; align-items: center; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #4A5568; margin-bottom: 8px; }
.form-control-cyber, .form-select-cyber { border: 2px solid #E2E8F0; border-radius: 8px; padding: 10px 14px; font-size: 0.9rem; }
.form-control-cyber:focus, .form-select-cyber:focus { outline: none; border-color: #6B46C1; box-shadow: 0 0 0 3px rgba(107,70,193,0.15); }

.info-box { padding: 10px 12px; background: rgba(107,70,193,0.05); border-radius: 8px; border-left: 3px solid #6B46C1; }

.amount-input-wrapper { display: flex; align-items: center; background: #F7FAFC; border: 2px solid #E2E8F0; border-radius: 8px; overflow: hidden; }
.amount-input-wrapper:focus-within { border-color: #48BB78; box-shadow: 0 0 0 3px rgba(72,187,120,0.15); }
.amount-input-wrapper .currency-prefix { padding: 0 12px; background: rgba(72,187,120,0.1); color: #2F855A; height: 100%; display: flex; align-items: center; border-right: 1px solid #E2E8F0; font-weight: 800; font-size: 0.75rem; }
.amount-input { flex: 1; border: none; background: transparent; padding: 12px 16px; font-size: 1rem; font-weight: 700; font-family: 'Courier New', monospace; outline: none; }

.btn-confirm { width: 100%; padding: 18px 24px; background: linear-gradient(135deg, #48BB78, #38A169); color: #fff; border: none; border-radius: 12px; font-size: 1rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.3s ease; box-shadow: 0 6px 20px rgba(72,187,120,0.35); }
.btn-confirm:hover:not(:disabled) { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(72,187,120,0.5); }
.btn-confirm:disabled { background: #CBD5E0; cursor: not-allowed; box-shadow: none; opacity: 0.6; }

.confirmed-by-preview { display: flex; align-items: center; gap: 14px; padding: 14px; background: linear-gradient(135deg, rgba(107,70,193,0.06), rgba(107,70,193,0.02)); border: 2px solid rgba(107,70,193,0.15); border-radius: 10px; margin-bottom: 12px; }
.confirmed-by-avatar { width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, #6B46C1, #553C9A); color: #fff; font-size: 1.4rem; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 12px rgba(107,70,193,0.35); }
.confirmed-by-info { flex: 1; min-width: 0; }
.confirmed-by-name { font-size: 1rem; font-weight: 700; color: #1A202C; line-height: 1.2; }
.confirmed-by-role { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #6B46C1; margin-top: 2px; }
.confirmed-by-note { font-size: 0.8rem; color: #718096; font-style: italic; }
</style>

<?php include '../includes/footer.php'; ?>

<script>
function togglePaymentFields() {
    const t = document.getElementById('paymentType').value;
    const f = document.getElementById('amountPaidField');
    const i = document.querySelector('[name="amount_paid"]');
    if (t === 'partial') { f.style.display = 'block'; i.required = true; }
    else { f.style.display = 'none'; i.required = false; }
}
function validateStoreSelection() {
    const s = document.getElementById('storeSelect');
    const b = document.getElementById('confirmBtn');
    const a = document.getElementById('stockAlert');
    const h = document.getElementById('confirmHint');
    if (!s.value) {
        b.disabled = true; a.innerHTML = '';
        h.textContent = 'Select a store to enable confirmation';
        return;
    }
    const ok = s.options[s.selectedIndex].getAttribute('data-can-fulfill') === '1';
    const all = s.value === '0';
    if (ok) {
        b.disabled = false;
        h.textContent = 'Ready to confirm';
        a.innerHTML = all
            ? '<div class="alert alert-success mb-3"><i class="fas fa-check-circle me-2"></i><strong>Multi-Store FIFO!</strong> Inventory will be deducted from multiple stores.</div>'
            : '<div class="alert alert-success mb-3"><i class="fas fa-check-circle me-2"></i><strong>Stock Available!</strong> This store can fulfill the entire order.</div>';
    } else {
        b.disabled = true;
        h.textContent = 'Cannot proceed — insufficient stock';
        a.innerHTML = '<div class="alert alert-danger mb-3"><i class="fas fa-times-circle me-2"></i><strong>Insufficient Stock!</strong> This store cannot fulfill the order.</div>';
    }
}
document.getElementById('confirmForm').addEventListener('submit', function(e) {
    if (!document.getElementById('storeSelect').value) {
        e.preventDefault(); alert('Please select a store'); return false;
    }
    if (!confirm('Confirm this order? Inventory will be deducted.')) {
        e.preventDefault(); return false;
    }
    const b = document.getElementById('confirmBtn');
    b.disabled = true;
    b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
});
</script>