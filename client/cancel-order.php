<?php
require_once '../config/config.php';
require_once 'config/client_auth.php';
require_client();

$order_id = intval($_GET['id'] ?? 0);

if ($order_id <= 0) {
    set_alert('danger', 'Invalid order ID');
    header('Location: orders.php');
    exit;
}

// ============================================
// ✅ CRITICAL: Use single query with ownership check
// Prevents: cancelling other clients' orders
// ============================================
try {
    // Verify the order belongs to this client AND is pending
    $stmt = $conn->prepare("
        SELECT order_id, order_number, order_status
        FROM client_orders
        WHERE order_id = ? 
        AND client_id = ? 
        AND order_status = 'pending'
    ");
    $stmt->execute([$order_id, $_SESSION['user_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        // Don't reveal whether it exists, belongs to someone else, or has wrong status
        set_alert('danger', 'Order cannot be cancelled');
        header('Location: orders.php');
        exit;
    }
    
    // Cancel — the WHERE clause ALSO includes client_id as defense-in-depth
    $stmt = $conn->prepare("
        UPDATE client_orders 
        SET order_status = 'cancelled',
            updated_at = CURRENT_TIMESTAMP
        WHERE order_id = ? 
        AND client_id = ? 
        AND order_status = 'pending'
    ");
    $stmt->execute([$order_id, $_SESSION['user_id']]);
    
    if ($stmt->rowCount() > 0) {
        // Log activity
        if (function_exists('log_activity')) {
            log_activity(
                $_SESSION['user_id'],
                'ORDER_CANCELLED',
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