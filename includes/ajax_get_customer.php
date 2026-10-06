<?php
require_once '../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;

if ($customer_id === 0) {
    echo json_encode(['error' => 'Invalid customer ID']);
    exit();
}

try {
    $stmt = $conn->prepare("SELECT customer_id, customer_code, customer_name, email, phone, discount_percentage FROM customers WHERE customer_id = ? AND status = 'active'");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($customer) {
        echo json_encode($customer);
    } else {
        echo json_encode(['error' => 'Customer not found']);
    }
} catch(PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}