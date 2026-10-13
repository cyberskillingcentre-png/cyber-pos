<?php
require_once '../config/config.php';
require_login();
if (!is_admin()) { header('Location: ../employee/dashboard.php'); exit(); }
csrf_require();

// ============================================
// RESOLVE ORDER ID — accept from POST or GET
// ============================================
$order_id = 0;
if (isset($_POST['order_id']) && intval($_POST['order_id']) > 0) {
    $order_id = intval($_POST['order_id']);
} elseif (isset($_GET['id']) && intval($_GET['id']) > 0) {
    $order_id = intval($_GET['id']);
}

if ($order_id <= 0) {
    // Show exactly what was received so we can diagnose without guessing
    header('Content-Type: text/plain; charset=utf-8');
    echo "Diagnostic — no valid order_id received\n\n";
    echo "FILE: " . __FILE__ . "\n";
    echo "METHOD: " . $_SERVER['REQUEST_METHOD'] . "\n";
    echo "URI: " . ($_SERVER['REQUEST_URI'] ?? '') . "\n\n";
    echo "POST:\n";   print_r($_POST);
    echo "\nGET:\n";  print_r($_GET);
    echo "\nSESSION user_id: " . ($_SESSION['user_id'] ?? 'MISSING') . "\n";
    exit;
}

$store_id          = isset($_POST['store_id']) ? intval($_POST['store_id']) : 0;
$payment_type      = $_POST['payment_type'] ?? 'paid';
$amount_paid       = isset($_POST['amount_paid']) ? floatval($_POST['amount_paid']) : 0;
$transaction_notes = function_exists('sanitize_input') ? sanitize_input($_POST['transaction_notes'] ?? '') : trim($_POST['transaction_notes'] ?? '');
$send_invoice      = isset($_POST['send_invoice']) ? 1 : 0;

$processing_user_id   = (int) $_SESSION['user_id'];
$processing_user_name = $_SESSION['full_name'] ?? 'Unknown';
$processing_user_role = $_SESSION['role'] ?? 'admin';

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("SELECT * FROM client_orders WHERE order_id = ? AND order_status = 'pending' FOR UPDATE");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found or already processed.');
    }

    $grand_total = (float) $order['grand_total'];

    $stmt = $conn->prepare("
        SELECT coi.*, p.product_name 
        FROM client_order_items coi
        LEFT JOIN products p ON coi.product_id = p.product_id
        WHERE coi.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        throw new Exception('Order has no items.');
    }

    // Payment status
    if ($payment_type === 'paid') {
        $amount_paid = $grand_total;
        $payment_status = 'paid';
    } elseif ($payment_type === 'credit') {
        $amount_paid = 0;
        $payment_status = 'pending';
    } else {
        if ($amount_paid <= 0 || $amount_paid > $grand_total) {
            throw new Exception('Invalid partial payment amount.');
        }
        $payment_status = ($amount_paid >= $grand_total) ? 'paid' : 'partial';
    }
    $amount_due = max(0, $grand_total - $amount_paid);

    // Deduct stock (store_id = 0 means FIFO across all stores)
    foreach ($items as $item) {
        $product_id = (int) $item['product_id'];
        $remaining  = (int) $item['quantity'];

        if ($store_id > 0) {
            $stmt = $conn->prepare("
                SELECT inventory_id, quantity FROM store_inventory
                WHERE store_id = ? AND product_id = ? AND quantity > 0
                ORDER BY (expiry_date IS NULL), expiry_date ASC, last_updated ASC, inventory_id ASC
            ");
            $stmt->execute([$store_id, $product_id]);
        } else {
            $stmt = $conn->prepare("
                SELECT inventory_id, quantity FROM store_inventory
                WHERE product_id = ? AND quantity > 0
                ORDER BY (expiry_date IS NULL), expiry_date ASC, last_updated ASC, inventory_id ASC
            ");
            $stmt->execute([$product_id]);
        }
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($batches as $batch) {
            if ($remaining <= 0) break;
            if ($batch['quantity'] >= $remaining) {
                $conn->prepare("UPDATE store_inventory SET quantity = quantity - ? WHERE inventory_id = ?")
                     ->execute([$remaining, $batch['inventory_id']]);
                $remaining = 0;
            } else {
                $conn->prepare("UPDATE store_inventory SET quantity = 0 WHERE inventory_id = ?")
                     ->execute([$batch['inventory_id']]);
                $remaining -= $batch['quantity'];
            }
        }
        if ($remaining > 0) {
            throw new Exception("Insufficient stock for product ID $product_id.");
        }
    }

    // Update order
    $final_store_id = ($store_id > 0) ? $store_id : null;

    $sql = "UPDATE client_orders 
            SET order_status = 'confirmed',
                store_id = ?,
                payment_status = ?,
                amount_paid = ?,
                amount_due = ?,
                processed_by = ?";
    try {
        $probe = $conn->query("SHOW COLUMNS FROM client_orders LIKE 'processed_at'");
        if ($probe && $probe->fetch()) {
            $sql .= ", processed_at = NOW()";
        }
    } catch (PDOException $e) {}
    $sql .= " WHERE order_id = ?";

    $conn->prepare($sql)->execute([
        $final_store_id, $payment_status, $amount_paid, $amount_due,
        $processing_user_id, $order_id
    ]);

    if (!empty($transaction_notes)) {
        $conn->prepare("
            UPDATE client_orders 
            SET notes = CONCAT(COALESCE(notes, ''), '\n\n[Confirmed by ', ?, '] ', ?)
            WHERE order_id = ?
        ")->execute([$processing_user_name, $transaction_notes, $order_id]);
    }

    if (function_exists('log_activity')) {
        log_activity(
            $processing_user_id,
            'ORDER_CONFIRMED',
            "Order {$order['order_number']} confirmed by {$processing_user_name}",
            $conn
        );
    }

    $conn->commit();
    set_alert('success', "Order {$order['order_number']} confirmed by {$processing_user_name}.");
    header('Location: View_Order.php?id=' . $order_id);
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    set_alert('danger', 'Error: ' . $e->getMessage());
    header('Location: Confirm_Order.php?id=' . $order_id);
    exit;
}