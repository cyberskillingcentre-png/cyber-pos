<?php
session_start();
require_once '../config/config.php';
require_login();
if (!is_admin()) { header('Location: ../employee/dashboard.php'); exit(); }

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

try {
    $stmt = $conn->prepare("
        UPDATE client_orders 
        SET order_status = 'cancelled', 
            updated_at = CURRENT_TIMESTAMP 
        WHERE order_id = ? AND order_status = 'pending'
    ");
    $stmt->execute([$order_id]);
    
    if ($stmt->rowCount() > 0) {
        set_alert('success', 'Order cancelled successfully!');
    } else {
        set_alert('warning', 'Order cannot be cancelled (not in pending status)');
    }
} catch(PDOException $e) {
    set_alert('danger', 'Error cancelling order: ' . $e->getMessage());
}

header('Location: Client_Orders');
exit;
