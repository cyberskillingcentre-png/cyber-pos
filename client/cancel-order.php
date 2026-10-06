<?php
require_once '../config/config.php';
require_once 'config/client_auth.php';
require_client();

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($order_id <= 0) {
    set_alert('danger', 'Invalid order ID');
    header('Location: orders.php');
    exit;
}

try {
    // Verify order belongs to this client AND is still pending
    $stmt = $conn->prepare("
        SELECT order_id, order_number, order_status
        FROM client_orders
        WHERE order_id = ? AND client_id = ? AND order_status = 'pending'
    ");
    $stmt->execute([$order_id, $_SESSION['user_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        set_alert('danger', 'Order not found or cannot be cancelled');
        header('Location: orders.php');
        exit;
    }
    
    // Cancel the order
    $stmt = $conn->prepare("
        UPDATE client_orders 
        SET order_status = 'cancelled',
            updated_at = CURRENT_TIMESTAMP
        WHERE order_id = ? AND client_id = ? AND order_status = 'pending'
    ");
    $stmt->execute([$order_id, $_SESSION['user_id']]);
    
    if ($stmt->rowCount() > 0) {
        // Log activity
        if (function_exists('log_activity')) {
            log_activity(
                $_SESSION['user_id'],
                'CLIENT_ORDER_CANCELLED',
                "Cancelled order: {$order['order_number']}",
                $conn
            );
        }
        
        set_alert('success', 'Order cancelled successfully');
    } else {
        set_alert('warning', 'Order could not be cancelled');
    }
    
} catch(PDOException $e) {
    error_log("Cancel order error: " . $e->getMessage());
    set_alert('danger', 'Error cancelling order');
}

header('Location: orders.php');
exit;