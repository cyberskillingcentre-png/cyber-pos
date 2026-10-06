<?php
require_once '../config/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $product_id = intval($_POST['product_id']);
    $store_id = intval($_POST['store_id']);
    
    try {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(quantity), 0) as quantity 
            FROM store_inventory 
            WHERE store_id = ? AND product_id = ?
        ");
        $stmt->execute([$store_id, $product_id]);
        $result = $stmt->fetch();
        
        header('Content-Type: application/json');
        echo json_encode(['quantity' => $result['quantity']]);
        
    } catch(PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>