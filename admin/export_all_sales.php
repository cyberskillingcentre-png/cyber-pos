<?php
require_once '../config/config.php';
require_admin();

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$store_id  = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
$payment   = $_GET['payment_method'] ?? '';

$where = ["DATE(s.sale_date) BETWEEN ? AND ?", "s.status = 'completed'"];
$params = [$date_from, $date_to];

if ($store_id > 0) {
    $where[]  = "s.store_id = ?";
    $params[] = $store_id;
}
if ($payment !== '') {
    $where[]  = "s.payment_method = ?";
    $params[] = $payment;
}
$where_clause = 'WHERE ' . implode(' AND ', $where);

// Detect optional columns
$sale_item_cols = [];
try {
    $sale_item_cols = $conn->query("SHOW COLUMNS FROM sale_items")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

$has_profit   = in_array('profit',          $sale_item_cols, true);
$has_discount = in_array('discount_amount', $sale_item_cols, true);
$has_tax      = in_array('tax_amount',      $sale_item_cols, true);

$profit_sql   = $has_profit   ? "si.profit"          : "0";
$discount_sql = $has_discount ? "si.discount_amount" : "0";
$tax_sql      = $has_tax      ? "si.tax_amount"      : "0";

$sql = "
    SELECT 
        s.invoice_number,
        s.sale_date,
        st.store_name,
        u.full_name       AS served_by,
        COALESCE(c.customer_name, 'Walk-in') AS customer_name,
        s.payment_method,
        s.payment_status,
        p.product_code,
        p.product_name,
        si.quantity,
        si.unit_price,
        {$discount_sql} AS discount_amount,
        {$tax_sql}      AS tax_amount,
        si.total_price,
        {$profit_sql}   AS profit
    FROM sale_items si
    JOIN sales s     ON si.sale_id    = s.sale_id
    JOIN products p  ON si.product_id = p.product_id
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    LEFT JOIN stores st   ON s.store_id    = st.store_id
    LEFT JOIN users u     ON s.served_by   = u.user_id
    $where_clause
    ORDER BY s.sale_date DESC, si.sale_item_id DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);

$filename = "all_sales_{$date_from}_to_{$date_to}.csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel opens it correctly
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($out, [
    'Invoice #',
    'Sale Date',
    'Store',
    'Served By',
    'Customer',
    'Payment Method',
    'Payment Status',
    'Product Code',
    'Product Name',
    'Quantity',
    'Unit Price',
    'Discount',
    'Tax',
    'Total Price',
    'Profit',
]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
        $row['invoice_number'],
        $row['sale_date'],
        $row['store_name'],
        $row['served_by'],
        $row['customer_name'],
        $row['payment_method'],
        $row['payment_status'],
        $row['product_code'],
        $row['product_name'],
        $row['quantity'],
        $row['unit_price'],
        $row['discount_amount'],
        $row['tax_amount'],
        $row['total_price'],
        $row['profit'],
    ]);
}

fclose($out);
exit;