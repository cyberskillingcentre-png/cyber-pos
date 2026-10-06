<?php
require_once '../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

$query = isset($_POST['query']) ? trim($_POST['query']) : '';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT 
            customer_id,
            customer_code,
            customer_name,
            phone,
            email,
            discount_percentage
        FROM customers 
        WHERE status = 'active' 
        AND (
            customer_name LIKE ? 
            OR customer_code LIKE ? 
            OR phone LIKE ? 
            OR email LIKE ?
        )
        ORDER BY customer_name ASC
        LIMIT 20
    ");
    
    $search = "%{$query}%";
    $stmt->execute([$search, $search, $search, $search]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($customers);
    
} catch(PDOException $e) {
    error_log("Customer search error: " . $e->getMessage());
    echo json_encode([]);
}