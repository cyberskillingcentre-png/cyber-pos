<?php
require_once '../config/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $query = isset($_POST['query']) ? sanitize_input($_POST['query']) : '';
    $store_id = isset($_POST['store_id']) ? intval($_POST['store_id']) : 0;
    
    try {
        if ($store_id == 0) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Please select a store first']);
            exit();
        }
        
        // SUM all quantities across all batches for each product
        $stmt = $conn->prepare("
            SELECT 
                p.product_id,
                p.product_code,
                p.barcode,
                p.product_name,
                p.selling_price,
                p.purchase_price,
                p.tax_rate,
                COALESCE(SUM(si.quantity), 0) as quantity,
                c.category_name
            FROM products p
            LEFT JOIN store_inventory si ON p.product_id = si.product_id AND si.store_id = ?
            LEFT JOIN categories c ON p.category_id = c.category_id
            WHERE p.status = 'active' 
            AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ?)
            GROUP BY p.product_id
            ORDER BY p.product_name
            LIMIT 20
        ");
        
        $search_term = "%{$query}%";
        $stmt->execute([$store_id, $search_term, $search_term, $search_term]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode($products);
        
    } catch(PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid request method']);
}
?>