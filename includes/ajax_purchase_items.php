<?php
require_once '../config/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $purchase_id = intval($_POST['purchase_id']);
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                pi.*,
                p.product_code,
                p.product_name,
                p.has_expiry
            FROM purchase_items pi
            JOIN products p ON pi.product_id = p.product_id
            WHERE pi.purchase_id = ?
        ");
        $stmt->execute([$purchase_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode($items);
        
    } catch(PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>