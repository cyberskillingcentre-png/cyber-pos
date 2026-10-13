<?php
require_once '../config/config.php';
require_login();
if (!is_admin()) { header('Location: ../employee/dashboard.php'); exit(); }

csrf_require();

// Accept from POST (preferred) or GET (legacy links)
$order_id = 0;
if (isset($_POST['order_id'])) $order_id = intval($_POST['order_id']);
if ($order_id <= 0 && isset($_GET['id'])) $order_id = intval($_GET['id']);

if ($order_id <= 0) {
    set_alert('danger', 'Invalid order ID');
    header('Location: Client_Orders.php');
    exit;
}

try {
    $conn->beginTransaction();

    // Lock the order row
    $stmt = $conn->prepare("SELECT * FROM client_orders WHERE order_id = ? FOR UPDATE");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found');
    }
    if ($order['order_status'] === 'cancelled') {
        throw new Exception('Order is already cancelled');
    }
    if ($order['order_status'] === 'delivered') {
        throw new Exception('Cannot cancel a delivered order. Use the returns process instead.');
    }

    $linked_sale_id    = null;
    $restock_performed = false;

    // If the order was already confirmed, reverse the effects
    if (in_array($order['order_status'], ['confirmed', 'processing', 'ready', 'shipped'], true)) {

        // Find the linked sale row (prefer explicit link)
        $stmt = $conn->prepare("SELECT sale_id FROM sales WHERE client_order_id = ? LIMIT 1");
        $stmt->execute([$order_id]);
        $linked = $stmt->fetch();

        // Fallback for older sales without client_order_id set
        if (!$linked) {
            $stmt = $conn->prepare("
                SELECT sale_id FROM sales 
                WHERE customer_id = ?
                  AND ABS(total_amount - ?) < 1
                  AND DATE(sale_date) BETWEEN DATE(?) AND DATE_ADD(DATE(?), INTERVAL 1 DAY)
                  AND status = 'completed'
                LIMIT 1
            ");
            $stmt->execute([
                $order['client_id'],
                $order['grand_total'],
                $order['order_date'],
                $order['order_date'],
            ]);
            $linked = $stmt->fetch();
        }

        if ($linked) {
            $linked_sale_id = (int) $linked['sale_id'];

            // Mark the sale as cancelled (keeps audit trail, excludes from reports)
            $stmt = $conn->prepare("UPDATE sales SET status = 'cancelled' WHERE sale_id = ?");
            $stmt->execute([$linked_sale_id]);

            // Restock inventory from the order items
            $stmt = $conn->prepare("
                SELECT product_id, quantity 
                FROM client_order_items 
                WHERE order_id = ?
            ");
            $stmt->execute([$order_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $batch_number = 'CANCEL-' . $order['order_number'];
            foreach ($items as $item) {
                $stmt = $conn->prepare("
                    INSERT INTO store_inventory 
                    (store_id, product_id, batch_number, quantity, initial_quantity)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
                ");
                $stmt->execute([
                    $order['store_id'],
                    $item['product_id'],
                    $batch_number,
                    $item['quantity'],
                    $item['quantity'],
                ]);
            }
            $restock_performed = true;
        }
    }

    // Mark the order itself as cancelled
    $stmt = $conn->prepare("
        UPDATE client_orders 
        SET order_status = 'cancelled'
        WHERE order_id = ?
    ");
    $stmt->execute([$order_id]);

    // Log the action with full context
    $log_msg = "Cancelled order {$order['order_number']}";
    if ($linked_sale_id) {
        $log_msg .= " (sale #$linked_sale_id marked cancelled)";
    }
    if ($restock_performed) {
        $log_msg .= " (inventory restocked)";
    }
    if (floatval($order['amount_paid']) > 0) {
        $log_msg .= " — refund owed: UGX " . number_format($order['amount_paid'], 2);
    }

    log_activity($_SESSION['user_id'], 'ORDER_CANCELLED', $log_msg, $conn);

    $conn->commit();

    $msg = "Order {$order['order_number']} cancelled.";
    if ($linked_sale_id) {
        $msg .= " Sale #$linked_sale_id excluded from revenue.";
    }
    if ($restock_performed) {
        $msg .= " Inventory restocked.";
    }
    if (floatval($order['amount_paid']) > 0) {
        $msg .= " Refund of UGX " . number_format($order['amount_paid'], 2) . " owed to client.";
    }
    set_alert('success', $msg);

    header('Location: Client_Orders.php');
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    set_alert('danger', 'Error: ' . $e->getMessage());
    header('Location: Client_Orders.php');
    exit;
}