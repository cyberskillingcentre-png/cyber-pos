<?php
require_once '../config/config.php';
require_login();

header('Content-Type: application/json');

$sale_id = isset($_POST['sale_id']) ? intval($_POST['sale_id']) : 0;

if ($sale_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid sale ID']);
    exit;
}

try {
    // Fetch sale header
    $stmt = $conn->prepare("
        SELECT 
            s.sale_id,
            s.invoice_number,
            s.store_id,
            s.customer_id,
            s.total_amount,
            s.sale_date,
            s.status,
            c.customer_name,
            st.store_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.customer_id
        LEFT JOIN stores st ON s.store_id = st.store_id
        WHERE s.sale_id = ?
    ");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        echo json_encode(['success' => false, 'error' => 'Sale not found']);
        exit;
    }

    if ($sale['status'] !== 'completed') {
        echo json_encode(['success' => false, 'error' => 'Only completed sales can be returned']);
        exit;
    }

    // Fetch sale items with already-returned quantities
    // Also handles legacy rows where sale_item_id is NULL by falling back to product_id matching
    $stmt = $conn->prepare("
        SELECT 
            si.sale_item_id,
            si.product_id,
            si.quantity,
            si.unit_price,
            si.total_price,
            p.product_code,
            p.product_name,
            COALESCE((
                SELECT SUM(ri.quantity)
                FROM return_items ri
                INNER JOIN returns r ON ri.return_id = r.return_id
                WHERE ri.product_id = si.product_id
                  AND r.sale_id = si.sale_id
                  AND r.status IN ('pending', 'approved')
            ), 0) AS already_returned
        FROM sale_items si
        INNER JOIN products p ON si.product_id = p.product_id
        WHERE si.sale_id = ?
    ");
    $stmt->execute([$sale_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute returnable quantity for each line
    foreach ($items as &$item) {
        $item['quantity']         = (int) $item['quantity'];
        $item['already_returned'] = (int) $item['already_returned'];
        $item['returnable_qty']   = max(0, $item['quantity'] - $item['already_returned']);
    }
    unset($item);

    echo json_encode([
        'success' => true,
        'sale'    => $sale,
        'items'   => $items
    ]);

} catch (PDOException $e) {
    error_log("ajax_sale_details failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}