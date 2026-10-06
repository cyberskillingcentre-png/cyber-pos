<?php
session_start();
require_once '../config/config.php';
require_login();
if (!is_admin()) { header('Location: ../employee/dashboard.php'); exit(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: Client_Orders.php');
    exit;
}

$order_id = intval($_POST['order_id'] ?? 0);
$store_id = isset($_POST['store_id']) ? intval($_POST['store_id']) : null;
$payment_type = $_POST['payment_type'] ?? 'paid';
$amount_paid = floatval($_POST['amount_paid'] ?? 0);
$transaction_notes = trim($_POST['transaction_notes'] ?? '');
$send_invoice = isset($_POST['send_invoice']);

if ($order_id <= 0) {
    set_alert('danger', 'Invalid order ID');
    header('Location: Client_Orders.php');
    exit;
}

if ($store_id === null) {
    set_alert('danger', 'Please select a store');
    header('Location: Confirm_Order.php?id=' . $order_id);
    exit;
}

try {
    $conn->beginTransaction();
    
    // ============================================
    // 1. GET ORDER
    // ============================================
    $stmt = $conn->prepare("
        SELECT co.*, u.email as client_email, u.full_name as client_name
        FROM client_orders co
        LEFT JOIN users u ON co.client_id = u.user_id
        WHERE co.order_id = ? AND co.order_status = 'pending'
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception('Order not found or already processed');
    }
    
    // ============================================
    // 2. GET ORDER ITEMS
    // ============================================
    $stmt = $conn->prepare("
        SELECT coi.*, p.product_name 
        FROM client_order_items coi
        LEFT JOIN products p ON coi.product_id = p.product_id
        WHERE coi.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($items)) {
        throw new Exception('Order has no items');
    }
    
    // ============================================
    // 3. SAFE FALLBACK: RECALCULATE FROM ITEMS
    // ============================================
    $subtotal = 0;
    $total_from_items = 0;
    foreach ($items as $item) {
        $qty = intval($item['quantity']);
        $price = floatval($item['unit_price']);
        $line_total = $qty * $price;
        $subtotal += $line_total;
        $total_from_items += floatval($item['total_price'] ?? $line_total);
    }
    
    $final_subtotal = (!empty($order['subtotal']) && floatval($order['subtotal']) > 0) 
        ? floatval($order['subtotal']) 
        : $subtotal;
    
    $final_discount = floatval($order['discount_amount'] ?? 0);
    $final_tax = floatval($order['tax_amount'] ?? 0);
    
    $final_grand_total = (!empty($order['grand_total']) && floatval($order['grand_total']) > 0) 
        ? floatval($order['grand_total']) 
        : ($final_subtotal - $final_discount + $final_tax);
    
    // ============================================
    // 4. VALIDATE STOCK
    // ============================================
    $stock_errors = [];
    
    if ($store_id == 0) {
        // All-stores FIFO
        foreach ($items as $item) {
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(quantity), 0) 
                FROM store_inventory 
                WHERE product_id = ?
            ");
            $stmt->execute([$item['product_id']]);
            $total_avail = $stmt->fetchColumn();
            
            if ($total_avail < $item['quantity']) {
                $stock_errors[] = "{$item['product_name']} (Available: $total_avail, Need: {$item['quantity']})";
            }
        }
    } else {
        // Single store
        foreach ($items as $item) {
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(quantity), 0) 
                FROM store_inventory 
                WHERE product_id = ? AND store_id = ?
            ");
            $stmt->execute([$item['product_id'], $store_id]);
            $avail = $stmt->fetchColumn();
            
            if ($avail < $item['quantity']) {
                $stock_errors[] = "{$item['product_name']} (Available: $avail, Need: {$item['quantity']})";
            }
        }
    }
    
    if (!empty($stock_errors)) {
        throw new Exception('INSUFFICIENT STOCK: ' . implode(', ', $stock_errors));
    }
    
    // ============================================
    // 5. DETERMINE FINAL STORE ID
    // ============================================
    $final_store_id = $store_id;
    
    if ($store_id == 0) {
        $stmt = $conn->query("SELECT store_id FROM stores WHERE status = 'active' ORDER BY store_id LIMIT 1");
        $final_store_id = $stmt->fetchColumn() ?: 1;
    }
    
    // ============================================
    // 6. CALCULATE PAYMENT STATUS
    // ============================================
    // IMPORTANT: Different ENUMs for the two tables!
    // client_orders.payment_status ENUM = ('pending','partial','paid')
    // sales.payment_status         ENUM = ('paid','partial','unpaid')
    
    $order_payment_status = 'pending';   // for client_orders
    $sale_payment_status  = 'unpaid';    // for sales
    
    if ($payment_type === 'paid') {
        $order_payment_status = 'paid';
        $sale_payment_status  = 'paid';
        $amount_paid = $final_grand_total;
    } elseif ($payment_type === 'credit') {
        $order_payment_status = 'pending';    // not yet paid (valid in client_orders)
        $sale_payment_status  = 'unpaid';     // not yet paid (valid in sales)
        $amount_paid = 0;
    } else {
        // partial
        if ($amount_paid <= 0 || $amount_paid > $final_grand_total) {
            throw new Exception('Invalid payment amount');
        }
        $order_payment_status = 'partial';
        $sale_payment_status  = 'partial';
    }
    
    $amount_due = max(0, $final_grand_total - $amount_paid);
    
    // ============================================
    // 7. UPDATE ORDER STATUS
    // ============================================
    $notes_addition = "\n\n[Admin Confirmed] " . $transaction_notes;
    if ($store_id == 0) {
        $notes_addition .= " [Multi-store FIFO from all stores]";
    }
    
    $stmt = $conn->prepare("
        UPDATE client_orders 
        SET order_status = 'confirmed',
            payment_status = ?,
            amount_paid = ?,
            amount_due = ?,
            subtotal = ?,
            grand_total = ?,
            store_id = ?,
            notes = CONCAT(COALESCE(notes, ''), ?),
            processed_by = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE order_id = ?
    ");
    $stmt->execute([
        $order_payment_status,
        $amount_paid,
        $amount_due,
        $final_subtotal,
        $final_grand_total,
        $final_store_id,
        $notes_addition,
        $_SESSION['user_id'],
        $order_id
    ]);
    
    // ============================================
    // 8. DEDUCT INVENTORY (FIFO)
    // ============================================
    $stores_used = [];
    
    foreach ($items as $item) {
        $remaining_qty = intval($item['quantity']);
        
        if ($store_id == 0) {
            $stmt = $conn->prepare("
                SELECT inventory_id, quantity, store_id
                FROM store_inventory 
                WHERE product_id = ? AND quantity > 0
                ORDER BY inventory_id ASC
            ");
            $stmt->execute([$item['product_id']]);
        } else {
            $stmt = $conn->prepare("
                SELECT inventory_id, quantity, store_id
                FROM store_inventory 
                WHERE product_id = ? AND store_id = ? AND quantity > 0
                ORDER BY inventory_id ASC
            ");
            $stmt->execute([$item['product_id'], $store_id]);
        }
        
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($batches as $batch) {
            if ($remaining_qty <= 0) break;
            
            $deduct = min($remaining_qty, $batch['quantity']);
            
            if (!isset($stores_used[$batch['store_id']])) {
                $stores_used[$batch['store_id']] = 0;
            }
            $stores_used[$batch['store_id']] += $deduct;
            
            $stmt = $conn->prepare("
                UPDATE store_inventory 
                SET quantity = quantity - ? 
                WHERE inventory_id = ?
            ");
            $stmt->execute([$deduct, $batch['inventory_id']]);
            
            $remaining_qty -= $deduct;
        }
        
        if ($remaining_qty > 0) {
            throw new Exception('Failed to deduct full quantity for ' . $item['product_name']);
        }
    }
    
    // ============================================
    // 9. CREATE SALES RECORD
    // ============================================
    $invoice_number = 'INV' . date('YmdHis') . rand(1000, 9999);
    
    $sale_notes = "Client Order #{$order['order_number']}";
    $sale_notes .= " | Client: {$order['client_name']}";
    
    if ($store_id == 0 && count($stores_used) > 0) {
        $sale_notes .= " | Multi-store FIFO: ";
        $parts = [];
        foreach ($stores_used as $sid => $qty) {
            $stmt_temp = $conn->prepare("SELECT store_name FROM stores WHERE store_id = ?");
            $stmt_temp->execute([$sid]);
            $sname = $stmt_temp->fetchColumn() ?: "Store $sid";
            $parts[] = "$sname ($qty units)";
        }
        $sale_notes .= implode(', ', $parts);
    }
    
    $sale_subtotal = $final_subtotal > 0 ? $final_subtotal : $total_from_items;
    $sale_grand_total = $final_grand_total > 0 ? $final_grand_total : $sale_subtotal;
    
    $stmt = $conn->prepare("
        INSERT INTO sales (
            invoice_number, store_id, customer_id, sale_date,
            subtotal, discount_amount, tax_amount, total_amount,
            payment_method, payment_status,
            amount_paid, amount_due, served_by, notes
        ) VALUES (?, ?, NULL, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $invoice_number,
        $final_store_id,
        $sale_subtotal,
        $final_discount,
        $final_tax,
        $sale_grand_total,
        $order['payment_method'] ?? 'cash',
        $sale_payment_status,
        $amount_paid,
        $amount_due,
        $_SESSION['user_id'],
        $sale_notes
    ]);
    
    $sale_id = $conn->lastInsertId();
    
    // ============================================
    // 10. CREATE SALE ITEMS
    // ============================================
    $stmt = $conn->prepare("
        INSERT INTO sale_items 
        (sale_id, product_id, quantity, unit_price, discount_amount, tax_amount, total_price, profit)
        VALUES (?, ?, ?, ?, 0, 0, ?, 0)
    ");
    
    foreach ($items as $item) {
        $qty = intval($item['quantity']);
        $price = floatval($item['unit_price']);
        $line_total = $qty * $price;
        
        $stmt->execute([
            $sale_id,
            $item['product_id'],
            $qty,
            $price,
            $line_total
        ]);
    }
    
    // ============================================
    // 11. UPDATE CLIENT BALANCE (for credit/partial)
    // ============================================
    if ($payment_type === 'credit' || $payment_type === 'partial') {
        $balance_to_add = $final_grand_total - $amount_paid;
        
        $stmt = $conn->prepare("
            UPDATE client_profiles 
            SET current_balance = COALESCE(current_balance, 0) + ?
            WHERE user_id = ?
        ");
        $stmt->execute([$balance_to_add, $order['client_id']]);
    }
    
    // ============================================
    // 12. LOG ACTIVITY
    // ============================================
    if (function_exists('log_activity')) {
        log_activity(
            $_SESSION['user_id'],
            'ORDER_CONFIRMED',
            "Confirmed order {$order['order_number']} for UGX " . number_format($final_grand_total, 0),
            $conn
        );
    }
    
    // ============================================
    // 13. COMMIT
    // ============================================
    $conn->commit();
    
    // Success message with details
    $success_msg = "Order confirmed successfully!";
    if ($store_id == 0 && count($stores_used) > 0) {
        $success_msg .= " Inventory deducted from multiple stores (FIFO).";
    } else {
        $success_msg .= " Inventory deducted.";
    }
    
    set_alert('success', $success_msg);
    header('Location: View_Order.php?id=' . $order_id);
    exit;
    
} catch(Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Order confirmation error: " . $e->getMessage());
    set_alert('danger', 'Error confirming order: ' . $e->getMessage());
    header('Location: Confirm_Order.php?id=' . $order_id);
    exit;
}