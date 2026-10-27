<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$barcode  = trim($_POST['barcode']  ?? '');
$store_id = (int) ($_POST['store_id'] ?? 0);

if ($barcode === '' || $store_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing barcode or store.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT
            p.product_id, p.product_code, p.barcode, p.product_name,
            p.selling_price, p.purchase_price, p.tax_rate,
            COALESCE(SUM(CASE WHEN si.status = 'good' THEN si.quantity ELSE 0 END), 0) AS quantity
        FROM products p
        LEFT JOIN store_inventory si
            ON si.product_id = p.product_id
            AND si.store_id  = ?
            AND si.quantity  > 0
        WHERE p.status = 'active'
          AND (p.barcode = ? OR p.product_code = ?)
        GROUP BY p.product_id, p.product_code, p.barcode, p.product_name,
                 p.selling_price, p.purchase_price, p.tax_rate
        LIMIT 1
    ");
    $stmt->execute([$store_id, $barcode, $barcode]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'No product found for barcode: ' . $barcode]);
        exit;
    }

    $product['product_id']     = (int)   $product['product_id'];
    $product['selling_price']  = (float) $product['selling_price'];
    $product['purchase_price'] = (float) $product['purchase_price'];
    $product['tax_rate']       = (float) $product['tax_rate'];
    $product['quantity']       = (int)   $product['quantity'];

    echo json_encode(['success' => true, 'product' => $product]);

} catch (Throwable $e) {
    error_log('ajax_lookup_barcode error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Lookup failed: ' . $e->getMessage()]);
}