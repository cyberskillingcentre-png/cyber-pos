<?php
session_start();
require_once '../../config/config.php';
require_once '../config/client_auth.php';
require_client();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $payment_method = $_POST['payment_method'];
    $delivery_address = trim($_POST['delivery_address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $discount_rate = floatval($_POST['discount_rate'] ?? 0);
    $products = json_decode($_POST['products'], true);
    
    if (empty($products)) {
        set_alert('danger', 'Please add products to your order');
        header('Location: ../New_Order');
        exit;
    }
    
    try {
        $conn->beginTransaction();
        
        // Get first active store (auto-assign)
        $stmt = $conn->query("SELECT store_id FROM stores WHERE status = 'active' ORDER BY store_id LIMIT 1");
        $store = $stmt->fetch();
        
        if (!$store) {
            throw new Exception('No active store found. Please contact administrator.');
        }
        
        $store_id = $store['store_id'];
        
        // Generate order number
        $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        
        // Calculate totals
        $total_amount = 0;
        foreach ($products as $item) {
            $total_amount += $item['price'] * $item['qty'];
        }
        
        $discount_amount = $total_amount * ($discount_rate / 100);
        $grand_total = $total_amount - $discount_amount;
        
        // Insert order
        $stmt = $conn->prepare("
            INSERT INTO client_orders 
            (order_number, client_id, store_id, delivery_address, total_amount, 
             discount_amount, grand_total, payment_method, notes, order_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $order_number,
            $_SESSION['user_id'],
            $store_id,
            $delivery_address,
            $total_amount,
            $discount_amount,
            $grand_total,
            $payment_method,
            $notes
        ]);
        
        $order_id = $conn->lastInsertId();
        
        // Insert order items
        $stmt = $conn->prepare("
            INSERT INTO client_order_items 
            (order_id, product_id, product_name, quantity, unit_price, discount, total_price)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($products as $item) {
            $item_discount = ($item['price'] * $item['qty']) * ($discount_rate / 100);
            $item_total = ($item['price'] * $item['qty']) - $item_discount;
            
            // Get product name
            $p_stmt = $conn->prepare("SELECT product_name FROM products WHERE product_id = ?");
            $p_stmt->execute([$item['id']]);
            $product = $p_stmt->fetch();
            
            $stmt->execute([
                $order_id,
                $item['id'],
                $product['product_name'],
                $item['qty'],
                $item['price'],
                $item_discount,
                $item_total
            ]);
        }
        
        $conn->commit();
        
        set_alert('success', 'Order placed successfully! Order Number: ' . $order_number);
        header('Location: ../My_Order_Details?id=' . $order_id);
        exit;
        
    } catch(Exception $e) {
        $conn->rollBack();
        set_alert('danger', 'Error placing order: ' . $e->getMessage());
        header('Location: ../New_Order');
        exit;
    }
}

header('Location: ../New_Order');
exit;
