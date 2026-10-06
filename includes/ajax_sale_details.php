<?php
require_once '../config/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $sale_id = intval($_POST['sale_id']);
    
    try {
        // Get sale details
        $stmt = $conn->prepare("
            SELECT s.*, c.customer_id, c.customer_name, st.store_id, st.store_name
            FROM sales s
            LEFT JOIN customers c ON s.customer_id = c.customer_id
            LEFT JOIN stores st ON s.store_id = st.store_id
            WHERE s.sale_id = ?
        ");
        $stmt->execute([$sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get sale items
        $stmt = $conn->prepare("
            SELECT 
                si.*,
                p.product_id,
                p.product_code,
                p.product_name
            FROM sale_items si
            JOIN products p ON si.product_id = p.product_id
            WHERE si.sale_id = ?
        ");
        $stmt->execute([$sale_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'sale' => $sale,
            'items' => $items
        ]);
        
    } catch(PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}
?>