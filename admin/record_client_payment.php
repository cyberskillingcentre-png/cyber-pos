<?php
require_once '../config/config.php';
require_login();
if (!is_admin()) { header('Location: ../employee/dashboard.php'); exit(); }

$page_title = "Record Client Payment";
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($order_id <= 0) {
    set_alert('danger', 'Invalid order ID');
    header('Location: Client_Orders.php');
    exit;
}

// ============================================
// HANDLE PAYMENT SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    try {
        $conn->beginTransaction();

        $payment_amount   = floatval($_POST['payment_amount'] ?? 0);
        $payment_date     = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_method   = $_POST['payment_method'] ?? 'cash';
        $reference_number = sanitize_input($_POST['reference_number'] ?? '');
        $notes            = sanitize_input($_POST['notes'] ?? '');

        if ($payment_amount <= 0) {
            throw new Exception('Payment amount must be greater than zero.');
        }

        // Lock the order row so two admins can't double-record
        $stmt = $conn->prepare("SELECT * FROM client_orders WHERE order_id = ? FOR UPDATE");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception('Order not found.');
        }

        $grand_total  = floatval($order['grand_total']);
        $already_paid = floatval($order['amount_paid']);
        $balance      = max(0, $grand_total - $already_paid);

        if ($balance <= 0) {
            throw new Exception('This order is already fully paid.');
        }
        if ($payment_amount > $balance) {
            throw new Exception('Payment amount exceeds the outstanding balance (UGX ' . number_format($balance, 2) . ').');
        }

        if (empty($reference_number)) {
            $reference_number = 'PAY' . date('Ymd') . rand(1000, 9999);
        }

        // Insert payment row
        $stmt = $conn->prepare("
            INSERT INTO client_payments 
            (order_id, client_id, payment_amount, payment_date, payment_method,
             reference_number, notes, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $order_id,
            $order['client_id'],
            $payment_amount,
            $payment_date,
            $payment_method,
            $reference_number,
            $notes,
            $_SESSION['user_id']
        ]);

        // New totals
        $new_paid = $already_paid + $payment_amount;
        $new_due  = max(0, $grand_total - $new_paid);

        if ($new_paid >= $grand_total) {
            $new_status = 'paid';
        } elseif ($new_paid > 0) {
            $new_status = 'partial';
        } else {
            $new_status = 'pending';
        }

        $stmt = $conn->prepare("
            UPDATE client_orders 
            SET amount_paid = ?, amount_due = ?, payment_status = ?
            WHERE order_id = ?
        ");
        $stmt->execute([$new_paid, $new_due, $new_status, $order_id]);

        // Adjust client profile balance
        try {
            $stmt = $conn->prepare("
                UPDATE client_profiles 
                SET current_balance = GREATEST(0, current_balance - ?)
                WHERE user_id = ?
            ");
            $stmt->execute([$payment_amount, $order['client_id']]);
        } catch (PDOException $e) { /* non-fatal */ }

        if (function_exists('log_activity')) {
            log_activity(
                $_SESSION['user_id'],
                'CLIENT_PAYMENT',
                "Recorded payment UGX " . number_format($payment_amount, 2)
                    . " for order {$order['order_number']}",
                $conn
            );
        }

        $conn->commit();

        set_alert('success', 'Payment of UGX ' . number_format($payment_amount, 2) . ' recorded. New balance: UGX ' . number_format($new_due, 2));
        header('Location: view_c_order.php?id=' . $order_id);
        exit;

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
}

// ============================================
// LOAD ORDER + PAYMENT HISTORY
// ============================================
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
        WHERE co.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        set_alert('danger', 'Order not found');
        header('Location: client_orders.php');
        exit;
    }
} catch (PDOException $e) {
    set_alert('danger', 'Error loading order.');
    header('Location: client_orders.php');
    exit;
}

$grand_total = floatval($order['grand_total']);
$amount_paid = floatval($order['amount_paid']);
$balance     = max(0, $grand_total - $amount_paid);

$payments = [];
try {
    $stmt = $conn->prepare("
        SELECT cp.*, u.full_name AS recorded_by_name
        FROM client_payments cp
        LEFT JOIN users u ON cp.recorded_by = u.user_id
        WHERE cp.order_id = ?
        ORDER BY cp.created_at DESC
    ");
    $stmt->execute([$order_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payments = [];
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
                    <i class="fas fa-money-bill-wave me-2" style="color: var(--cyber-orange);"></i>
                    Record Payment — <?php echo htmlspecialchars($order['order_number']); ?>
                </h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="view_client_order.php?id=<?php echo (int)$order_id; ?>" class="btn btn-sm btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Order
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

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card cyber-card">
                    <div class="card-header bg-success">
                        <i class="fas fa-plus-circle me-2"></i>New Payment
                    </div>
                    <div class="card-body">
                        <?php if ($balance <= 0): ?>
                            <div class="alert alert-success mb-0">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>This order is fully paid.</strong> No further payments are needed.
                            </div>
                        <?php else: ?>
                            <form method="POST" id="paymentForm">
                                <input type="hidden" name="action" value="record_payment">

                                <div class="mb-3">
                                    <label class="form-label">Outstanding Balance</label>
                                    <input type="text" class="form-control" value="UGX <?php echo number_format($balance, 2); ?>" readonly>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Payment Amount (UGX) *</label>
                                    <input type="number"
                                           name="payment_amount"
                                           id="payment_amount"
                                           class="form-control"
                                           step="0.01"
                                           min="0.01"
                                           max="<?php echo $balance; ?>"
                                           value="<?php echo $balance; ?>"
                                           required>
                                    <small class="text-muted">
                                        Max: UGX <?php echo number_format($balance, 2); ?>
                                        &middot;
                                        <a href="#" id="fillBalance">Fill full balance</a>
                                    </small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Payment Date *</label>
                                    <input type="date" name="payment_date" class="form-control"
                                           value="<?php echo date('Y-m-d'); ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Payment Method *</label>
                                    <select name="payment_method" class="form-select" required>
                                        <option value="cash">💵 Cash</option>
                                        <option value="mobile_money">📱 Mobile Money</option>
                                        <option value="bank_transfer">🏦 Bank Transfer</option>
                                        <option value="card">💳 Card</option>
                                        <option value="cheque">🧾 Cheque</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Reference Number</label>
                                    <input type="text" name="reference_number" class="form-control"
                                           placeholder="Transaction ID, cheque number, etc.">
                                    <small class="text-muted">Leave blank to auto-generate</small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="3"
                                              placeholder="Any additional information..."></textarea>
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fas fa-check me-2"></i>Record Payment
                                    </button>
                                    <a href="view_client_order.php?id=<?php echo (int)$order_id; ?>" class="btn btn-secondary">Cancel</a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card cyber-card mb-4">
                    <div class="card-header"><i class="fas fa-info-circle me-2"></i>Order Summary</div>
                    <div class="card-body">
                        <div class="summary-row">
                            <span>Client:</span>
                            <strong><?php echo htmlspecialchars($order['client_name'] ?? 'N/A'); ?></strong>
                        </div>
                        <?php if (!empty($order['company_name'])): ?>
                        <div class="summary-row">
                            <span>Company:</span>
                            <strong><?php echo htmlspecialchars($order['company_name']); ?></strong>
                        </div>
                        <?php endif; ?>
                        <hr>
                        <div class="summary-row">
                            <span>Order Total:</span>
                            <strong>UGX <?php echo number_format($grand_total, 2); ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Already Paid:</span>
                            <strong class="text-success">UGX <?php echo number_format($amount_paid, 2); ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Balance:</span>
                            <strong class="<?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">
                                UGX <?php echo number_format($balance, 2); ?>
                            </strong>
                        </div>
                        <div class="summary-row">
                            <span>Payment Status:</span>
                            <?php
                            $badge = match($order['payment_status'] ?? 'pending') {
                                'paid'    => 'bg-success',
                                'partial' => 'bg-warning',
                                default   => 'bg-danger',
                            };
                            ?>
                            <span class="badge <?php echo $badge; ?>">
                                <?php echo ucfirst($order['payment_status'] ?? 'pending'); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="card cyber-card">
                    <div class="card-header">
                        <i class="fas fa-history me-2"></i>Payment History (<?php echo count($payments); ?>)
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($payments)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-receipt fa-2x mb-2"></i>
                                <p class="mb-0">No payments recorded yet</p>
                            </div>
                        <?php else: ?>
                            <div class="payment-history-list">
                                <?php foreach ($payments as $p): ?>
                                <div class="payment-history-item">
                                    <div class="d-flex justify-content-between">
                                        <strong class="text-success">UGX <?php echo number_format($p['payment_amount'], 2); ?></strong>
                                        <small class="text-muted"><?php echo date('M d, Y', strtotime($p['payment_date'])); ?></small>
                                    </div>
                                    <div class="small text-muted">
                                        <?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?>
                                        <?php if ($p['reference_number']): ?>
                                            &middot; <?php echo htmlspecialchars($p['reference_number']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($p['recorded_by_name']): ?>
                                        <div class="small text-muted">By <?php echo htmlspecialchars($p['recorded_by_name']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($p['notes']): ?>
                                        <div class="small fst-italic text-muted">"<?php echo htmlspecialchars($p['notes']); ?>"</div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.cyber-card { background: #fff; border: 1px solid #E2E8F0; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.08); }
.cyber-card .card-header { background: linear-gradient(135deg, #1A202C 0%, #2D3748 100%); color: #fff; padding: 14px 20px; border: none; font-weight: 600; display: flex; align-items: center; }
.cyber-card .card-header i { color: #ED8936; margin-right: 8px; }
.cyber-card .card-header.bg-success { background: linear-gradient(135deg, #48BB78, #38A169) !important; }

.summary-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 0.9rem; border-bottom: 1px dashed #E2E8F0; }
.summary-row:last-child { border-bottom: none; }
.summary-row span { color: #718096; }

.payment-history-list { max-height: 400px; overflow-y: auto; }
.payment-history-item { padding: 12px 16px; border-bottom: 1px solid #EDF2F7; }
.payment-history-item:last-child { border-bottom: none; }
</style>

<?php include '../includes/footer.php'; ?>

<script>
document.getElementById('fillBalance')?.addEventListener('click', function(e) {
    e.preventDefault();
    const max = document.getElementById('payment_amount').getAttribute('max');
    document.getElementById('payment_amount').value = max;
});
</script>