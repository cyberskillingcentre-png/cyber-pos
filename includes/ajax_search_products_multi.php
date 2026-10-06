<?php
require_once '../config/config.php';
require_login();

header('Content-Type: application/json');

$query = sanitize_input($_POST['query'] ?? '');
$aggregate = isset($_POST['aggregate']) && $_POST['aggregate'] === 'true';

if ($aggregate && isset($_POST['store_ids'])) {
    // Multi-store aggregated search
    $store_ids = $_POST['store_ids'];
    
    if (!is_array($store_ids) || empty($store_ids)) {
        echo json_encode(['error' => 'Invalid store selection']);
        exit();
    }
    
    // Build query for multiple stores - aggregate quantities
    $placeholders = implode(',', array_fill(0, count($store_ids), '?'));
    $search_term = "%{$query}%";
    
    $sql = "
        SELECT 
            p.product_id,
            p.product_name,
            p.product_code,
            p.selling_price,
            p.purchase_price,
            p.tax_rate,
            COALESCE(SUM(si.quantity), 0) as quantity
        FROM products p
        LEFT JOIN store_inventory si ON p.product_id = si.product_id 
            AND si.store_id IN ($placeholders)
            AND si.quantity > 0
        WHERE p.status = 'active'
        AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ?)
        GROUP BY p.product_id
        ORDER BY p.product_name ASC
        LIMIT 20
    ";
    
    $params = array_merge($store_ids, [$search_term, $search_term, $search_term]);
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($products);
    } catch(PDOException $e) {
        echo json_encode(['error' => 'Search failed: ' . $e->getMessage()]);
    }
    
} else {
    // Single store search (existing logic)
    $store_id = intval($_POST['store_id'] ?? 0);
    
    if (!$store_id) {
        echo json_encode(['error' => 'Store ID is required']);
        exit();
    }
    
    $search_term = "%{$query}%";
    
    $sql = "
        SELECT 
            p.product_id,
            p.product_name,
            p.product_code,
            p.selling_price,
            p.purchase_price,
            p.tax_rate,
            COALESCE(SUM(CASE WHEN si.quantity > 0 THEN si.quantity ELSE 0 END), 0) as quantity
        FROM products p
        LEFT JOIN store_inventory si ON p.product_id = si.product_id AND si.store_id = ?
        WHERE p.status = 'active'
        AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ?)
        GROUP BY p.product_id
        ORDER BY p.product_name ASC
        LIMIT 20
    ";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$store_id, $search_term, $search_term, $search_term]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($products);
    } catch(PDOException $e) {
        echo json_encode(['error' => 'Search failed: ' . $e->getMessage()]);
    }
}
?>