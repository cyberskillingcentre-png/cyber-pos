<?php
require_once '../config/config.php';
require_admin();

$page_title = "Returns Management";

// ============================================
// HANDLE POST ACTIONS
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    csrf_require();
    if (isset($_POST['action'])) {
        try {
            // ----------------------------------------
            // ADD RETURN
            // ----------------------------------------
            if ($_POST['action'] == 'add') {
                $conn->beginTransaction();

                $sale_id       = !empty($_POST['sale_id']) ? intval($_POST['sale_id']) : null;
                $customer_id   = !empty($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
                $store_id      = intval($_POST['store_id']);
                $refund_method = $_POST['refund_method'] ?? 'cash';
                $reason        = sanitize_input($_POST['reason'] ?? '');
                $return_items  = json_decode($_POST['return_items'] ?? '[]', true);

                if (empty($return_items) || !is_array($return_items)) {
                    throw new Exception('No items selected for return.');
                }

                // Build the list of valid items (quantity > 0), compute total
                $valid_items  = [];
                $total_amount = 0.0;

                foreach ($return_items as $item) {
                    $qty = intval($item['quantity'] ?? 0);
                    if ($qty <= 0) continue;

                    $unit_price = floatval($item['unit_price']);
                    $line_total = $qty * $unit_price;
                    $total_amount += $line_total;

                    $valid_items[] = [
                        'sale_item_id'  => !empty($item['sale_item_id']) ? intval($item['sale_item_id']) : null,
                        'product_id'    => intval($item['product_id']),
                        'quantity'      => $qty,
                        'unit_price'    => $unit_price,
                        'total_price'   => $line_total,
                        'return_reason' => sanitize_input($item['return_reason'] ?? 'Other'),
                        'condition'     => sanitize_input($item['condition'] ?? 'unused'),
                    ];
                }

                if (empty($valid_items)) {
                    throw new Exception('Please specify a return quantity for at least one item.');
                }

                $return_number = 'RET' . date('Ymd') . rand(1000, 9999);

                // Insert return header
                $stmt = $conn->prepare("
                    INSERT INTO returns 
                    (return_number, sale_id, customer_id, store_id, return_date,
                     total_amount, refund_method, reason, processed_by, status, refund_status)
                    VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, 'pending', 'pending')
                ");
                $stmt->execute([
                    $return_number, $sale_id, $customer_id, $store_id,
                    $total_amount, $refund_method, $reason, $_SESSION['user_id']
                ]);
                $return_id = (int) $conn->lastInsertId();

                // Insert return items (note: column name is `condition`, not `conditio`)
                $stmt = $conn->prepare("
                    INSERT INTO return_items 
                    (return_id, sale_item_id, product_id, quantity, unit_price,
                     total_price, return_reason, `condition`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($valid_items as $vi) {
                    $stmt->execute([
                        $return_id,
                        $vi['sale_item_id'],
                        $vi['product_id'],
                        $vi['quantity'],
                        $vi['unit_price'],
                        $vi['total_price'],
                        $vi['return_reason'],
                        $vi['condition'],
                    ]);
                }

                log_activity($_SESSION['user_id'], 'RETURN_CREATED', "Created return: $return_number", $conn);

                $conn->commit();
                set_alert('success', 'Return created successfully! Return#: ' . $return_number);
                header('Location: Sales_Returns');
                exit();
            }

            // ----------------------------------------
            // APPROVE RETURN
            // ----------------------------------------
            elseif ($_POST['action'] == 'approve') {
                $conn->beginTransaction();

                $return_id     = intval($_POST['return_id']);
                $refund_amount = floatval($_POST['refund_amount']);
                $restock       = isset($_POST['restock']) ? 1 : 0;

                // Get return details
                $stmt = $conn->prepare("SELECT * FROM returns WHERE return_id = ?");
                $stmt->execute([$return_id]);
                $return = $stmt->fetch();

                if (!$return) {
                    throw new Exception('Return not found.');
                }

                // Determine refund status
                if ($refund_amount >= $return['total_amount']) {
                    $refund_status = 'completed';
                } elseif ($refund_amount > 0) {
                    $refund_status = 'partial';
                } else {
                    $refund_status = 'pending';
                }

                // ------------------------------------------------------------
                // FIX: update `status` AND `refund_status` separately.
                // Do NOT write to the same column twice in one statement.
                // ------------------------------------------------------------
                $stmt = $conn->prepare("
                    UPDATE returns 
                    SET status = 'approved',
                        refund_amount = ?,
                        refund_status = ?
                    WHERE return_id = ?
                ");
                $stmt->execute([$refund_amount, $refund_status, $return_id]);

                // Restock items if checked
                if ($restock) {
                    $stmt = $conn->prepare("SELECT * FROM return_items WHERE return_id = ?");
                    $stmt->execute([$return_id]);
                    $items = $stmt->fetchAll();

                    foreach ($items as $item) {
                        $batch_number = 'RET-' . $return['return_number'];

                        $stmt = $conn->prepare("
                            SELECT inventory_id 
                            FROM store_inventory 
                            WHERE store_id = ? AND product_id = ? AND batch_number = ?
                        ");
                        $stmt->execute([$return['store_id'], $item['product_id'], $batch_number]);
                        $existing = $stmt->fetch();

                        if ($existing) {
                            $stmt = $conn->prepare("
                                UPDATE store_inventory 
                                SET quantity = quantity + ? 
                                WHERE inventory_id = ?
                            ");
                            $stmt->execute([$item['quantity'], $existing['inventory_id']]);
                        } else {
                            $stmt = $conn->prepare("
                                INSERT INTO store_inventory 
                                (store_id, product_id, batch_number, quantity, initial_quantity)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $return['store_id'],
                                $item['product_id'],
                                $batch_number,
                                $item['quantity'],
                                $item['quantity']
                            ]);
                        }
                    }
                }

                log_activity($_SESSION['user_id'], 'RETURN_APPROVED', "Approved return: " . $return['return_number'], $conn);

                $conn->commit();
                set_alert('success', 'Return approved and processed!');
                header('Location: Sales_Returns');
                exit();
            }

            // ----------------------------------------
            // REJECT RETURN
            // ----------------------------------------
            elseif ($_POST['action'] == 'reject') {
                $return_id     = intval($_POST['return_id']);
                $reject_reason = sanitize_input($_POST['reject_reason'] ?? '');

                $stmt = $conn->prepare("
                    UPDATE returns 
                    SET status = 'rejected',
                        reason = CONCAT(reason, '\n\nRejection Reason: ', ?)
                    WHERE return_id = ?
                ");
                $stmt->execute([$reject_reason, $return_id]);

                log_activity($_SESSION['user_id'], 'RETURN_REJECTED', "Rejected return ID: $return_id", $conn);
                set_alert('warning', 'Return rejected');
                header('Location: Sales_Returns');
                exit();
            }

        } catch (Exception $e) {
            if (isset($conn) && $conn->inTransaction()) {
                $conn->rollBack();
            }
            set_alert('danger', 'Error: ' . $e->getMessage());
        }
    }
}

// ============================================
// FETCH RETURNS LIST
// ============================================
$stmt = $conn->prepare("
    SELECT 
        r.*,
        c.customer_name,
        s.store_name,
        u.full_name AS processed_by_name,
        sa.invoice_number
    FROM returns r
    LEFT JOIN customers c ON r.customer_id = c.customer_id
    LEFT JOIN stores s ON r.store_id = s.store_id
    LEFT JOIN users u ON r.processed_by = u.user_id
    LEFT JOIN sales sa ON r.sale_id = sa.sale_id
    ORDER BY r.created_at DESC
");
$stmt->execute();
$returns = $stmt->fetchAll();

// ============================================
// FETCH RECENT SALES FOR DROPDOWN
// ============================================
$stmt = $conn->prepare("
    SELECT sale_id, invoice_number, total_amount, sale_date 
    FROM sales 
    WHERE status = 'completed' 
    ORDER BY sale_date DESC 
    LIMIT 100
");
$stmt->execute();
$sales = $stmt->fetchAll();
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <button class="btn btn-link d-md-none sidebar-toggle">
            <i class="fas fa-bars"></i>
        </button>
        <h5 class="mb-0"><i class="fas fa-undo me-2"></i>Returns Management</h5>
    </div>

    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fas fa-list me-2"></i>Product Returns</span>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addReturnModal">
                    <i class="fas fa-plus me-1"></i>New Return
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datatable table-hover">
                        <thead>
                            <tr>
                                <th>Return #</th>
                                <th>Date</th>
                                <th>Invoice #</th>
                                <th>Customer</th>
                                <th>Store</th>
                                <th>Amount</th>
                                <th>Refunded</th>
                                <th>Refund Status</th>
                                <th>Status</th>
                                <th>Processed By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($returns as $return): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($return['return_number']); ?></strong></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($return['return_date'])); ?></td>
                                <td><?php echo htmlspecialchars($return['invoice_number'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($return['customer_name'] ?? 'Walk-in'); ?></td>
                                <td><?php echo htmlspecialchars($return['store_name'] ?? '-'); ?></td>
                                <td>UGX <?php echo number_format($return['total_amount'], 2); ?></td>
                                <td>UGX <?php echo number_format($return['refund_amount'] ?? 0, 2); ?></td>
                                <td>
                                    <?php 
                                    $refund_status = $return['refund_status'] ?? 'pending';
                                    if ($refund_status == 'completed'): ?>
                                        <span class="badge bg-success">Completed</span>
                                    <?php elseif ($refund_status == 'partial'): ?>
                                        <span class="badge bg-warning">Partial</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($return['status'] == 'approved'): ?>
                                        <span class="badge bg-success">Approved</span>
                                    <?php elseif ($return['status'] == 'pending'): ?>
                                        <span class="badge bg-warning">Pending</span>
                                    <?php elseif ($return['status'] == 'rejected'): ?>
                                        <span class="badge bg-danger">Rejected</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">Completed</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($return['processed_by_name'] ?? '-'); ?></td>
                                <td>
                                    <?php if ($return['status'] == 'pending'): ?>
                                    <button class="btn btn-sm btn-success approve-btn"
                                            data-id="<?php echo $return['return_id']; ?>"
                                            data-amount="<?php echo $return['total_amount']; ?>"
                                            title="Approve Return">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger reject-btn"
                                            data-id="<?php echo $return['return_id']; ?>"
                                            title="Reject Return">
                                        <i class="fas fa-times"></i>
                                    </button>
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
</div>

<!-- ============================================
     ADD RETURN MODAL
     ============================================ -->
<div class="modal fade" id="addReturnModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-undo me-2"></i>Create Return</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="returnForm">
                <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Select an invoice to automatically load all sale details.</strong>
                        All items, quantities, and prices will be populated. You only need to select return reason and condition for each item.
                    </div>

                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Select Sale Invoice *</label>
                            <select name="sale_id" id="sale-select" class="form-select" required>
                                <option value="">-- Select Invoice to Return --</option>
                                <?php foreach ($sales as $sale): ?>
                                <option value="<?php echo $sale['sale_id']; ?>">
                                    <?php echo htmlspecialchars($sale['invoice_number']); ?> - <?php echo date('d/m/Y', strtotime($sale['sale_date'])); ?> - UGX <?php echo number_format($sale['total_amount'], 0); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="sale-details" style="display:none;">
                        <hr>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Customer</label>
                                <input type="text" id="customer-display" class="form-control" readonly>
                                <input type="hidden" name="customer_id" id="customer-id">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Store</label>
                                <input type="text" id="store-display" class="form-control" readonly>
                                <input type="hidden" name="store_id" id="store-id">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Invoice Total</label>
                                <input type="text" id="invoice-total" class="form-control" readonly>
                            </div>
                        </div>

                        <hr>
                        <h6>Return Items <span class="badge bg-info" id="items-count"></span></h6>

                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product Code</th>
                                        <th>Product Name</th>
                                        <th>Quantity Sold</th>
                                        <th>Unit Price</th>
                                        <th>Total</th>
                                        <th style="width: 150px;">Return Reason *</th>
                                        <th style="width: 120px;">Condition *</th>
                                    </tr>
                                </thead>
                                <tbody id="return-items-tbody">
                                    <!-- Items loaded here -->
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="4" class="text-end">Total Return Amount:</th>
                                        <th colspan="3"><span class="text-primary fs-5">UGX <span id="return-total">0.00</span></span></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <hr>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Refund Method *</label>
                                <select name="refund_method" class="form-select" required>
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="mobile_money">Mobile Money</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="store_credit">Store Credit</option>
                                </select>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Overall Return Reason *</label>
                                <textarea name="reason" class="form-control" rows="3" required placeholder="e.g., Customer not satisfied, Multiple defective items, etc."></textarea>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="return_items" id="return-items-json">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="add" class="btn btn-primary" id="submit-return-btn" disabled>
                        <i class="fas fa-save me-1"></i>Create Return
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================
     APPROVE RETURN MODAL
     ============================================ -->
<div class="modal fade" id="approveReturnModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-check me-2"></i>Approve Return</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="return_id" id="approve_return_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Refund Amount (UGX) *</label>
                        <input type="number" name="refund_amount" id="refund_amount" class="form-control" step="0.01" required>
                        <small class="text-muted">Total return amount: <strong id="return_amount_display"></strong></small>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="restock" id="restock" checked>
                        <label class="form-check-label" for="restock">
                            <strong>Restock items to inventory</strong>
                        </label>
                        <small class="d-block text-muted">Uncheck if items are damaged and cannot be resold</small>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Approving this return will process the refund and optionally add items back to inventory.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="approve" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Approve Return
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================
     REJECT RETURN MODAL
     ============================================ -->
<div class="modal fade" id="rejectReturnModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-times me-2"></i>Reject Return</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="return_id" id="reject_return_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Reason for Rejection *</label>
                        <textarea name="reject_reason" class="form-control" rows="4" required placeholder="Explain why this return is being rejected..."></textarea>
                    </div>

                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This return will be marked as rejected and no refund will be processed.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="reject" class="btn btn-danger">
                        <i class="fas fa-times me-1"></i>Reject Return
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
jQuery(document).ready(function($) {
    let saleItems = [];

    // ============================================
    // LOAD SALE DETAILS WHEN INVOICE IS SELECTED
    // ============================================
    $('#sale-select').on('change', function() {
        const saleId = $(this).val();

        if (!saleId) {
            $('#sale-details').hide();
            $('#submit-return-btn').prop('disabled', true);
            saleItems = [];
            return;
        }

        $('#return-items-tbody').html('<tr><td colspan="7" class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading sale details...</td></tr>');
        $('#sale-details').show();

        $.ajax({
            url: '../includes/ajax_sale_details.php',
            method: 'POST',
            data: { sale_id: saleId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const sale  = response.sale;
                    const items = response.items;

                    $('#customer-display').val(sale.customer_name || 'Walk-in Customer');
                    $('#customer-id').val(sale.customer_id || '');
                    $('#store-display').val(sale.store_name);
                    $('#store-id').val(sale.store_id);
                    $('#invoice-total').val('UGX ' + parseFloat(sale.total_amount).toLocaleString());
                    $('#items-count').text(items.length + ' items');

                    $('#return-items-tbody').empty();
                    saleItems = [];

                    let totalAmount = 0;

                    items.forEach(function(item, index) {
                        const itemTotal = item.quantity * item.unit_price;
                        totalAmount += itemTotal;

                        saleItems.push({
                            sale_item_id:  item.sale_item_id || null,
                            product_id:    item.product_id,
                            quantity:      item.quantity,
                            unit_price:    item.unit_price,
                            return_reason: 'Defective',
                            condition:     'unused'
                        });

                        const row = `
                            <tr>
                                <td><strong>${item.product_code}</strong></td>
                                <td>${item.product_name}</td>
                                <td class="text-center"><span class="badge bg-primary">${item.quantity}</span></td>
                                <td>UGX ${parseFloat(item.unit_price).toFixed(2)}</td>
                                <td><strong>UGX ${itemTotal.toFixed(2)}</strong></td>
                                <td>
                                    <select class="form-select form-select-sm reason-select" data-index="${index}" required>
                                        <option value="Defective">Defective</option>
                                        <option value="Wrong Item">Wrong Item</option>
                                        <option value="Damaged">Damaged</option>
                                        <option value="Changed Mind">Changed Mind</option>
                                        <option value="Not as Described">Not as Described</option>
                                        <option value="Expired">Expired</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </td>
                                <td>
                                    <select class="form-select form-select-sm condition-select" data-index="${index}" required>
                                        <option value="unused">Unused</option>
                                        <option value="opened">Opened</option>
                                        <option value="damaged">Damaged</option>
                                        <option value="defective">Defective</option>
                                    </select>
                                </td>
                            </tr>
                        `;
                        $('#return-items-tbody').append(row);
                    });

                    $('#return-total').text(totalAmount.toFixed(2));
                    $('#submit-return-btn').prop('disabled', false);

                } else {
                    alert('Error loading sale details: ' + (response.error || 'Unknown error'));
                    $('#sale-details').hide();
                }
            },
            error: function(xhr) {
                console.error('AJAX error:', xhr.responseText);
                alert('Error loading sale details. Please try again.');
                $('#sale-details').hide();
            }
        });
    });

    // ============================================
    // UPDATE RETURN REASON
    // ============================================
    $(document).on('change', '.reason-select', function() {
        const index  = $(this).data('index');
        const reason = $(this).val();
        if (saleItems[index]) {
            saleItems[index].return_reason = reason;
        }
    });

    // ============================================
    // UPDATE CONDITION
    // ============================================
    $(document).on('change', '.condition-select', function() {
        const index     = $(this).data('index');
        const condition = $(this).val();
        if (saleItems[index]) {
            saleItems[index].condition = condition;
        }
    });

    // ============================================
    // FORM SUBMISSION
    // ============================================
    $('#returnForm').on('submit', function(e) {
        if (saleItems.length === 0) {
            e.preventDefault();
            alert('Please select an invoice first');
            return false;
        }

        $('#return-items-json').val(JSON.stringify(saleItems));
    });

    // ============================================
    // APPROVE BUTTON
    // ============================================
    $('.approve-btn').on('click', function() {
        const returnId = $(this).data('id');
        const amount   = $(this).data('amount');

        $('#approve_return_id').val(returnId);
        $('#refund_amount').val(amount);
        $('#return_amount_display').text('UGX ' + parseFloat(amount).toFixed(2));
        $('#approveReturnModal').modal('show');
    });

    // ============================================
    // REJECT BUTTON
    // ============================================
    $('.reject-btn').on('click', function() {
        const returnId = $(this).data('id');
        $('#reject_return_id').val(returnId);
        $('#rejectReturnModal').modal('show');
    });
});
</script>