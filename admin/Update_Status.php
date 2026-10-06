<?php
require_once '../config/config.php';
require_login();
if (!is_admin()) { header('Location: ../employee/dashboard.php'); exit(); }

$page_title = "Update Order Status";
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$stmt = $conn->prepare("SELECT * FROM client_orders WHERE order_id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    set_alert('danger', 'Order not found');
    header('Location: Client_Orders');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $order_status = $_POST['order_status'];
    $payment_status = $_POST['payment_status'];
    $notes = trim($_POST['notes'] ?? '');
    
    try {
        $stmt = $conn->prepare("
            UPDATE client_orders 
            SET order_status = ?, 
                payment_status = ?, 
                notes = ?, 
                updated_at = CURRENT_TIMESTAMP
            WHERE order_id = ?
        ");
        $stmt->execute([$order_status, $payment_status, $notes, $order_id]);
        
        set_alert('success', 'Order status updated successfully!');
        header('Location: View_Order?id=' . $order_id);
        exit;
    } catch(PDOException $e) {
        set_alert('danger', 'Error updating order: ' . $e->getMessage());
    }
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>
<div class="main-content">
    <div class="top-navbar">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Update Order Status</h5>
            <a href="View_Order?id=<?php echo $order_id; ?>" class="btn btn-sm btn-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>
    <div class="content-area">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-edit me-2"></i>Update Status - <?php echo htmlspecialchars($order['order_number']); ?>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Order Status *</label>
                                <select name="order_status" class="form-select" required>
                                    <option value="confirmed" <?php echo $order['order_status']=='confirmed'?'selected':''; ?>>Confirmed</option>
                                    <option value="processing" <?php echo $order['order_status']=='processing'?'selected':''; ?>>Processing</option>
                                    <option value="ready" <?php echo $order['order_status']=='ready'?'selected':''; ?>>Ready</option>
                                    <option value="shipped" <?php echo $order['order_status']=='shipped'?'selected':''; ?>>Shipped</option>
                                    <option value="delivered" <?php echo $order['order_status']=='delivered'?'selected':''; ?>>Delivered</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Payment Status *</label>
                                <select name="payment_status" class="form-select" required>
                                    <option value="pending" <?php echo $order['payment_status']=='pending'?'selected':''; ?>>Pending</option>
                                    <option value="partial" <?php echo $order['payment_status']=='partial'?'selected':''; ?>>Partial</option>
                                    <option value="paid" <?php echo $order['payment_status']=='paid'?'selected':''; ?>>Paid</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="3"><?php echo htmlspecialchars($order['notes']??''); ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Status
                            </button>
                            <a href="View_Order?id=<?php echo $order_id; ?>" class="btn btn-secondary">Cancel</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
