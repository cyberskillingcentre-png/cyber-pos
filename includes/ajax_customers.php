<?php
require_once '../config/config.php';
require_login();

try {
    $stmt = $conn->prepare("SELECT customer_id, customer_name, phone FROM customers WHERE status = 'active' ORDER BY customer_name");
    $stmt->execute();
    $customers = $stmt->fetchAll();
    
    header('Content-Type: application/json');
    echo json_encode($customers);
    
} catch(PDOException $e) {
    echo json_encode([]);
}
?>
