<?php
session_start();
require_once '../config/config.php';
require_login();
if (!is_admin()) { 
    header('Location: ../employee/dashboard.php'); 
    exit(); 
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    csrf_require();
    header('Location: Client_Orders');
    exit;
}

$order_id = intval($_POST['order_id']);
$new_status = $_POST['new_status'] ?? '';
$status_notes = trim($_POST['status_notes'] ?? '');

// Valid status transitions
$valid_statuses = ['pending', 'confirmed', 'processing', 'ready', 'shipped', 'delivered', 'cancelled'];

if (!in_array($new_status, $valid_statuses)) {
    set_alert('danger', 'Invalid status selected');
    header('Location: View_Order?id=' . $order_id);
    exit;
}

try {
    $conn->beginTransaction();
    
    // Get current order details
    $stmt = $conn->prepare("
        SELECT co.*, u.full_name as client_name, u.email as client_email
        FROM client_orders co
        LEFT JOIN users u ON co.client_id = u.user_id
        WHERE co.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception('Order not found');
    }
    
    $old_status = $order['order_status'];
    
    // Prevent certain invalid transitions
    if ($old_status == 'delivered' && $new_status != 'delivered') {
        throw new Exception('Cannot change status of delivered order');
    }
    
    if ($old_status == 'cancelled' && $new_status != 'cancelled') {
        throw new Exception('Cannot change status of cancelled order. Create a new order instead.');
    }
    
    // CRITICAL: Handle stock restoration when cancelling
    if ($new_status == 'cancelled' && $old_status != 'cancelled') {
        // Only restore stock if order was previously confirmed (stock was deducted)
        if (in_array($old_status, ['confirmed', 'processing', 'ready', 'shipped'])) {
            
            // Get order items
            $stmt = $conn->prepare("
                SELECT coi.*, p.product_name
                FROM client_order_items coi
                LEFT JOIN products p ON coi.product_id = p.product_id
                WHERE coi.order_id = ?
            ");
            $stmt->execute([$order_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // RESTORE inventory to the store it was deducted from
            $store_id = $order['store_id'];
            
            if ($store_id) {
                foreach ($items as $item) {
                    // Check if inventory record exists for this product in this store
                    $stmt = $conn->prepare("
                        SELECT inventory_id, quantity 
                        FROM store_inventory 
                        WHERE product_id = ? AND store_id = ?
                        ORDER BY inventory_id DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$item['product_id'], $store_id]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        // Update existing inventory record
                        $stmt = $conn->prepare("
                            UPDATE store_inventory 
                            SET quantity = quantity + ?,
                                last_updated = CURRENT_TIMESTAMP
                            WHERE inventory_id = ?
                        ");
                        $stmt->execute([$item['quantity'], $existing['inventory_id']]);
                    } else {
                        // Create new inventory record (in case it was completely depleted)
                        $batch_number = 'RESTORED-' . date('Ymd') . '-' . $order['order_number'];
                        
                        $stmt = $conn->prepare("
                            INSERT INTO store_inventory (
                                store_id,
                                product_id,
                                batch_number,
                                quantity,
                                initial_quantity,
                                last_updated
                            ) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([
                            $store_id,
                            $item['product_id'],
                            $batch_number,
                            $item['quantity'],
                            $item['quantity']
                        ]);
                    }
                }
                
                $status_notes .= " [Stock restored to Store ID: $store_id]";
            }
        }
        
        // Update payment status when cancelling
        $payment_status_update = ", payment_status = 'pending'";
    } else {
        $payment_status_update = "";
    }
    
    // Update order status
    $stmt = $conn->prepare("
        UPDATE client_orders 
        SET order_status = ?,
            notes = CONCAT(COALESCE(notes, ''), ?),
            updated_at = CURRENT_TIMESTAMP
            $payment_status_update
        WHERE order_id = ?
    ");
    
    $notes_addition = "\n\n[Status Updated by Admin] " . ucfirst($old_status) . " → " . ucfirst($new_status);
    if ($status_notes) {
        $notes_addition .= " | Note: " . $status_notes;
    }
    
    $stmt->execute([
        $new_status,
        $notes_addition,
        $order_id
    ]);
    
    // Log activity
    $activity_desc = "Updated order " . $order['order_number'] . " status: " . ucfirst($old_status) . " → " . ucfirst($new_status);
    if ($new_status == 'cancelled') {
        $activity_desc .= " [Stock restored]";
    }
    
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (
            user_id, 
            activity_type, 
            activity_description, 
            ip_address, 
            user_agent
        ) VALUES (?, 'ORDER_STATUS_UPDATE', ?, ?, ?)
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $activity_desc,
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT']
    ]);
    
    // Send email notification to client
    if (!empty($order['client_email'])) {
        try {
            $to = $order['client_email'];
            $subject = "Order Status Updated - " . $order['order_number'];
            
            $status_message = '';
            switch($new_status) {
                case 'processing':
                    $status_message = 'Your order is now being processed.';
                    break;
                case 'ready':
                    $status_message = 'Your order is ready for pickup/shipment!';
                    break;
                case 'shipped':
                    $status_message = 'Your order has been shipped and is on its way to you.';
                    break;
                case 'delivered':
                    $status_message = 'Your order has been delivered. Thank you for your business!';
                    break;
                case 'cancelled':
                    $status_message = 'Your order has been cancelled. If you have any questions, please contact us.';
                    break;
                default:
                    $status_message = 'Your order status has been updated to: ' . ucfirst($new_status);
            }
            
            $message = "<!DOCTYPE html><html><body style='font-family:Arial;'>
                <h2 style='color: #6B46C1;'>Order Status Update</h2>
                <p>Dear " . htmlspecialchars($order['client_name']) . ",</p>
                <p>$status_message</p>
                <table style='margin:20px 0;'>
                    <tr><td><strong>Order Number:</strong></td><td>" . htmlspecialchars($order['order_number']) . "</td></tr>
                    <tr><td><strong>New Status:</strong></td><td style='color:" . ($new_status == 'cancelled' ? 'red' : 'green') . ";'><strong>" . ucfirst($new_status) . "</strong></td></tr>
                    <tr><td><strong>Updated:</strong></td><td>" . date('M d, Y H:i') . "</td></tr>
                </table>";
            
            if ($status_notes) {
                $message .= "<p><strong>Note:</strong> " . nl2br(htmlspecialchars($status_notes)) . "</p>";
            }
            
            $message .= "<p>You can view your order details by logging into your account.</p>
                <p>Thank you,<br>" . htmlspecialchars($_SESSION['org_name']) . "</p>
            </body></html>";
            
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8\r\n";
            $headers .= "From: " . ($_SESSION['org_email'] ?? 'noreply@example.com') . "\r\n";
            
            @mail($to, $subject, $message, $headers);
            
        } catch(Exception $e) {
            error_log("Status email error: " . $e->getMessage());
        }
    }
    
    $conn->commit();
    
    // Success message
    $msg = "Order status updated to: " . ucfirst($new_status);
    if ($new_status == 'cancelled' && in_array($old_status, ['confirmed', 'processing', 'ready', 'shipped'])) {
        $msg .= ". Stock has been restored.";
    }
    
    set_alert('success', $msg);
    header('Location: View_Order?id=' . $order_id);
    exit;
    
} catch(Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    set_alert('danger', 'Error updating status: ' . $e->getMessage());
    header('Location: View_Order?id=' . $order_id);
    exit;
}