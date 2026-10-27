<?php
require_once '../config/config.php';
require_admin();

header('Content-Type: application/json');

$store_id   = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

if ($store_id <= 0 || $product_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing store or product']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT 
            inventory_id,
            batch_number,
            quantity,
            initial_quantity,
            expiry_date,
            last_updated
        FROM store_inventory
        WHERE store_id = ? AND product_id = ? AND quantity > 0
        ORDER BY (expiry_date IS NULL), expiry_date ASC, last_updated DESC, inventory_id ASC
    ");
    $stmt->execute([$store_id, $product_id]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'batches' => $batches,
    ]);

} catch (PDOException $e) {
    error_log('ajax_inventory_batches failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}