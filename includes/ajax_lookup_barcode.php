<?php
/**
 * ============================================
 * AJAX: Lookup Product by Barcode
 * ============================================
 * Used by POS for instant barcode scanning.
 */

require_once '../config/config.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$barcode = trim($_POST['barcode'] ?? '');
$store_id = intval($_POST['store_id'] ?? 0);

if (empty($barcode)) {
    echo json_encode(['success' => false, 'message' => 'No barcode provided']);
    exit;
}

if ($store_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'No store selected']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT 
            p.product_id,
            p.product_code,
            p.product_name,
            p.barcode,
            p.selling_price,
            p.purchase_price,
            p.tax_rate,
            COALESCE(SUM(si.quantity), 0) as quantity
        FROM products p
        LEFT JOIN store_inventory si ON p.product_id = si.product_id AND si.store_id = ?
        WHERE p.barcode = ? 
        AND p.status = 'active'
        GROUP BY p.product_id, p.product_code, p.product_name, p.barcode,
                 p.selling_price, p.purchase_price, p.tax_rate
        LIMIT 1
    ");
    $stmt->execute([$store_id, $barcode]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($product) {
        echo json_encode([
            'success' => true,
            'product' => [
                'product_id'     => intval($product['product_id']),
                'product_code'   => $product['product_code'],
                'product_name'   => $product['product_name'],
                'barcode'        => $product['barcode'],
                'selling_price'  => floatval($product['selling_price']),
                'purchase_price' => floatval($product['purchase_price']),
                'tax_rate'       => floatval($product['tax_rate']),
                'quantity'       => intval($product['quantity'])
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No product found with that barcode'
        ]);
    }
    
} catch(PDOException $e) {
    error_log("Barcode lookup error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
}