<?php
require_once '../config/config.php';
require_admin();

$page_title = "Record Credit Payment";

// ============================================
// AUTO-MIGRATE: ensure the payments table exists
// ============================================
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS customer_credit_payments (
            payment_id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT NOT NULL,
            customer_id INT DEFAULT NULL,
            payment_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            payment_date DATE NOT NULL,
            payment_method VARCHAR(50) DEFAULT 'cash',
            reference_number VARCHAR(100) DEFAULT NULL,
            notes TEXT,
            recorded_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sale (sale_id),
            KEY idx_customer (customer_id),
            KEY idx_date (payment_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {
    die("Could not prepare credit payments table: " . $e->getMessage());
}

// ============================================
// RESOLVE SALE ID
// ============================================
$sale_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['sale_id']) ? intval($_POST['sale_id']) : 0);
if ($sale_id <= 0) {
    set_alert('danger', 'Invalid sale ID');
    header('Location: sales.php');
    exit;
}

// ============================================
// HANDLE SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

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

        // Load the sale FOR UPDATE (prevent double-recording)
        $stmt = $conn->prepare("
            SELECT s.*, COALESCE(c.customer_name, 'Walk-in') AS customer_name
            FROM sales s
            LEFT JOIN customers c ON s.customer_id = c.customer_id
            WHERE s.sale_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            throw new Exception('Sale not found.');
        }
        if ($sale['payment_method'] !== 'credit') {
            throw new Exception('This is not a credit sale. Payments can only be recorded for credit sales.');
        }

        $total     = floatval($sale['total_amount']);
        $already   = floatval($sale['amount_paid']);
        $balance   = max(0, $total - $already);

        if ($balance <= 0) {
            throw new Exception('This sale is already fully paid.');
        }
        if ($payment_amount > $balance) {
            throw new Exception('Payment exceeds the outstanding balance (UGX ' . number_format($balance, 2) . ').');
        }

        if ($reference_number === '') {
            $reference_number = 'PAY' . date('Ymd') . rand(1000, 9999);
        }

        // Record the payment
        $stmt = $conn->prepare("
            INSERT INTO customer_credit_payments 
            (sale_id, customer_id, payment_amount, payment_date, payment_method,
             reference_number, notes, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $sale_id,
            $sale['customer_id'],
            $payment_amount,
            $payment_date,
            $payment_method,
            $reference_number,
            $notes,
            $_SESSION['user_id'],
        ]);

        // Recompute the sale totals
        $new_paid   = $already + $payment_amount;
        $new_due    = max(0, $total - $new_paid);

        if ($new_paid >= $total) {
            $new_status = 'paid';
        } elseif ($new_paid > 0) {
            $new_status = 'partial';
        } else {
            $new_status = 'unpaid';
        }

        $stmt = $conn->prepare("
            UPDATE sales 
            SET amount_paid = ?, amount_due = ?, payment_status = ?
            WHERE sale_id = ?
        ");
        $stmt->execute([$new_paid, $new_due, $new_status, $sale_id]);

        // Update the customer's balance if there's a linked customer
        if (!empty($sale['customer_id'])) {
            try {
                $stmt = $conn->prepare("
                    UPDATE client_profiles 
                    SET current_balance = GREATEST(0, current_balance - ?)
                    WHERE user_id = ?
                ");
                $stmt->execute([$payment_amount, $sale['customer_id']]);
            } catch (PDOException $e) {
                // client_profiles may not exist, or customer_id may not map to a user
                error_log("Balance update skipped: " . $e->getMessage());
            }
        }

        log_activity(
            $_SESSION['user_id'],
            'CREDIT_PAYMENT',
            "Recorded UGX " . number_format($payment_amount, 2) . " for sale {$sale['invoice_number']}",
            $conn
        );

        $conn->commit();

        set_alert('success', 'Payment of UGX ' . number_format($payment_amount, 2) . ' recorded. New balance: UGX ' . number_format($new_due, 2));
        header('Location: view_sale.php?id=' . $sale_id);
        exit;

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
}

// ============================================
// LOAD SALE + HISTORY FOR DISPLAY
// ============================================
try {
    $stmt = $conn->prepare("
        SELECT s.*, COALESCE(c.customer_name, 'Walk-in') AS customer_name,
               c.phone, c.email, st.store_name, u.full_name AS served_by_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.customer_id
        LEFT JOIN stores st   ON s.store_id    = st.store_id
        LEFT JOIN users u     ON s.served_by   = u.user_id
        WHERE s.sale_id = ?
    ");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        set_alert('danger', 'Sale not found');
        header('Location: sales.php');
        exit;
    }
} catch (PDOException $e) {
    set_alert('danger', 'Error loading sale.');
    header('Location: sales.php');
    exit;
}

$total   = floatval($sale['total_amount']);
$paid    = floatval($sale['amount_paid']);
$balance = max(0, $total - $paid);

$payments = [];
try {
    $stmt = $conn->prepare("
        SELECT p.*, u.full_name AS recorded_by_name
        FROM customer_credit_payments p
        LEFT JOIN users u ON p.recorded_by = u.user_id
        WHERE p.sale_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$sale_id]);
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
                    Record Credit Payment — <?php echo htmlspecialchars($sale['invoice_number']); ?>
                </h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="view_sale.php?id=<?php echo (int)$sale_id; ?>" class="btn btn-sm btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Sale
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
            <!-- LEFT: Payment form -->
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header" style="background: linear-gradient(135deg,#48BB78,#38A169);color:#fff;">
                        <i class="fas fa-plus-circle me-2"></i>New Payment
                    </div>
                    <div class="card-body">
                        <?php if ($balance <= 0): ?>
                            <div class="alert alert-success mb-0">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>This sale is fully paid.</strong> No further payments are needed.
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="sale_id" value="<?php echo (int)$sale_id; ?>">

                                <div class="mb-3">
                                    <label class="form-label">Outstanding Balance</label>
                                    <input type="text" class="form-control" value="UGX <?php echo number_format($balance, 2); ?>" readonly>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Payment Amount (UGX) *</label>
                                    <input type="number" name="payment_amount" class="form-control"
                                           step="0.01" min="0.01" max="<?php echo $balance; ?>" value="<?php echo $balance; ?>" required>
                                    <small class="text-muted">Maximum: UGX <?php echo number_format($balance, 2); ?></small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Payment Date *</label>
                                    <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Payment Method *</label>
                                    <select name="payment_method" class="form-select" required>
                                        <option value="cash">💵 Cash</option>
                                        <option value="mobile_money">📱 Mobile Money</option>
                                        <option value="bank_transfer">🏦 Bank Transfer</option>
                                        <option value="card">💳 Card</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Reference Number</label>
                                    <input type="text" name="reference_number" class="form-control" placeholder="Optional — leave blank to auto-generate">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="2" placeholder="Any additional information"></textarea>
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fas fa-check me-2"></i>Record Payment
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Sale summary + history -->
            <div class="col-lg-5">
                <div class="card mb-4">
                    <div class="card-header" style="background: linear-gradient(135deg,#1A202C,#2D3748);color:#fff;">
                        <i class="fas fa-info-circle me-2"></i>Sale Summary
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span>Invoice:</span>
                            <strong><?php echo htmlspecialchars($sale['invoice_number']); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span>Date:</span>
                            <strong><?php echo date('d M Y H:i', strtotime($sale['sale_date'])); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span>Customer:</span>
                            <strong><?php echo htmlspecialchars($sale['customer_name']); ?></strong>
                        </div>
                        <?php if (!empty($sale['phone'])): ?>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span>Phone:</span>
                            <strong><?php echo htmlspecialchars($sale['phone']); ?></strong>
                        </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span>Served By:</span>
                            <strong><?php echo htmlspecialchars($sale['served_by_name'] ?? '-'); ?></strong>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between py-2">
                            <span>Total:</span>
                            <strong>UGX <?php echo number_format($total, 2); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-2">
                            <span>Paid So Far:</span>
                            <strong class="text-success">UGX <?php echo number_format($paid, 2); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-2">
                            <span><strong>Balance:</strong></span>
                            <strong class="<?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">
                                UGX <?php echo number_format($balance, 2); ?>
                            </strong>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header" style="background: linear-gradient(135deg,#1A202C,#2D3748);color:#fff;">
                        <i class="fas fa-history me-2"></i>Payment History (<?php echo count($payments); ?>)
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($payments)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-receipt fa-2x mb-2"></i>
                                <p class="mb-0">No payments recorded yet</p>
                            </div>
                        <?php else: ?>
                            <div style="max-height:400px;overflow-y:auto;">
                                <?php foreach ($payments as $p): ?>
                                <div class="p-3 border-bottom">
                                    <div class="d-flex justify-content-between">
                                        <strong class="text-success">UGX <?php echo number_format($p['payment_amount'], 2); ?></strong>
                                        <small class="text-muted"><?php echo date('d M Y', strtotime($p['payment_date'])); ?></small>
                                    </div>
                                    <div class="small text-muted">
                                        <?php echo ucwords(str_replace('_', ' ', $p['payment_method'])); ?>
                                        <?php if (!empty($p['reference_number'])): ?>
                                            &middot; <?php echo htmlspecialchars($p['reference_number']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($p['recorded_by_name'])): ?>
                                        <div class="small text-muted">By <?php echo htmlspecialchars($p['recorded_by_name']); ?></div>
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

<?php include '../includes/footer.php'; ?>