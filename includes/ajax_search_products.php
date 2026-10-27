<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated. Please log in.']);
    exit;
}

$query    = trim($_POST['query']    ?? '');
$store_id = (int) ($_POST['store_id'] ?? 0);

if ($store_id <= 0) {
    echo json_encode(['error' => 'Please select a store first.']);
    exit;
}
if ($query === '') {
    echo json_encode([]);
    exit;
}

try {
    $check = $conn->prepare("
        SELECT store_id FROM stores
        WHERE store_id = ? AND status = 'active' AND store_type = 'sales'
    ");
    $check->execute([$store_id]);
    if (!$check->fetchColumn()) {
        echo json_encode(['error' => 'Invalid store selected.']);
        exit;
    }

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
          AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ?)
        GROUP BY p.product_id, p.product_code, p.barcode, p.product_name,
                 p.selling_price, p.purchase_price, p.tax_rate
        ORDER BY
            CASE
                WHEN p.barcode = ?        THEN 0
                WHEN p.product_code = ?   THEN 1
                WHEN p.product_name LIKE ? THEN 2
                ELSE 3
            END,
            p.product_name ASC
        LIMIT 30
    ");

    $like   = '%' . $query . '%';
    $exact  = $query;
    $prefix = $query . '%';
    $stmt->execute([$store_id, $like, $like, $like, $exact, $exact, $prefix]);

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as &$p) {
        $p['product_id']     = (int)   $p['product_id'];
        $p['selling_price']  = (float) $p['selling_price'];
        $p['purchase_price'] = (float) $p['purchase_price'];
        $p['tax_rate']       = (float) $p['tax_rate'];
        $p['quantity']       = (int)   $p['quantity'];
    }
    unset($p);

    echo json_encode($products);

} catch (Throwable $e) {
    error_log('ajax_search_products error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Search failed: ' . $e->getMessage()]);
}