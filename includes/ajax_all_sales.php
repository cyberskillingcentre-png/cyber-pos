<?php
require_once '../config/config.php';
require_admin();

header('Content-Type: application/json');

$date_from   = $_GET['date_from']   ?? date('Y-m-01');
$date_to     = $_GET['date_to']     ?? date('Y-m-d');
$store_id    = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
$payment     = $_GET['payment_method'] ?? '';
$product_id  = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$search      = trim($_GET['search'] ?? '');
$page        = max(1, intval($_GET['page'] ?? 1));
$per_page    = min(200, max(10, intval($_GET['per_page'] ?? 50)));
$offset      = ($page - 1) * $per_page;

// ============================================
// BUILD WHERE CLAUSE
// ============================================
$where = [
    "DATE(s.sale_date) BETWEEN ? AND ?",
    "s.status = 'completed'",
];
$params = [$date_from, $date_to];

if ($store_id > 0) {
    $where[]  = "s.store_id = ?";
    $params[] = $store_id;
}
if ($payment !== '') {
    $where[]  = "s.payment_method = ?";
    $params[] = $payment;
}
if ($product_id > 0) {
    $where[]  = "si.product_id = ?";
    $params[] = $product_id;
}
if ($search !== '') {
    $where[]  = "(s.invoice_number LIKE ? OR p.product_name LIKE ? OR p.product_code LIKE ? OR c.customer_name LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$where_clause = 'WHERE ' . implode(' AND ', $where);

try {
    // ============================================
    // DETECT OPTIONAL COLUMNS
    // (older tenant schemas may lack profit / discount_amount / tax_amount)
    // ============================================
    $sale_item_cols = [];
    try {
        $cols = $conn->query("SHOW COLUMNS FROM sale_items")->fetchAll(PDO::FETCH_COLUMN);
        $sale_item_cols = $cols;
    } catch (PDOException $e) {
        $sale_item_cols = [];
    }

    $has_profit   = in_array('profit',          $sale_item_cols, true);
    $has_discount = in_array('discount_amount', $sale_item_cols, true);
    $has_tax      = in_array('tax_amount',      $sale_item_cols, true);

    $profit_sql   = $has_profit   ? "si.profit"          : "0";
    $discount_sql = $has_discount ? "si.discount_amount" : "0";
    $tax_sql      = $has_tax      ? "si.tax_amount"      : "0";

    // ============================================
    // AGGREGATE TOTALS + COUNT (single query)
    // ============================================
    $totals_sql = "
        SELECT 
            COUNT(DISTINCT s.sale_id)          AS transaction_count,
            COUNT(*)                           AS line_count,
            COALESCE(SUM(si.total_price), 0)   AS total_revenue,
            COALESCE(SUM(si.quantity), 0)      AS total_items,
            COALESCE(SUM({$profit_sql}), 0)    AS total_profit,
            COALESCE(SUM({$discount_sql}), 0)  AS total_discounts,
            COALESCE(SUM({$tax_sql}), 0)       AS total_tax
        FROM sale_items si
        JOIN sales s     ON si.sale_id    = s.sale_id
        JOIN products p  ON si.product_id = p.product_id
        LEFT JOIN customers c ON s.customer_id = c.customer_id
        $where_clause
    ";
    $stmt = $conn->prepare($totals_sql);
    $stmt->execute($params);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);

    $total = (int) ($totals['line_count'] ?? 0);

    // ============================================
    // PAGINATED ROWS
    // ============================================
    $sql = "
        SELECT 
            si.sale_item_id,
            si.sale_id,
            si.product_id,
            si.quantity,
            si.unit_price,
            {$discount_sql} AS discount_amount,
            {$tax_sql}      AS tax_amount,
            si.total_price,
            {$profit_sql}   AS profit,
            s.invoice_number,
            s.sale_date,
            s.payment_method,
            s.payment_status,
            s.sale_type,
            p.product_code,
            p.product_name,
            c.customer_name,
            st.store_name,
            u.full_name AS served_by_name
        FROM sale_items si
        JOIN sales s     ON si.sale_id    = s.sale_id
        JOIN products p  ON si.product_id = p.product_id
        LEFT JOIN customers c ON s.customer_id = c.customer_id
        LEFT JOIN stores st   ON s.store_id    = st.store_id
        LEFT JOIN users u     ON s.served_by   = u.user_id
        $where_clause
        ORDER BY s.sale_date DESC, si.sale_item_id DESC
        LIMIT $per_page OFFSET $offset
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'  => true,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'pages'    => (int) ceil($total / $per_page),
        'rows'     => $rows,
        'totals'   => [
            'transactions' => (int)   $totals['transaction_count'],
            'lines'        => (int)   $totals['line_count'],
            'revenue'      => (float) $totals['total_revenue'],
            'profit'       => (float) $totals['total_profit'],
            'items'        => (int)   $totals['total_items'],
            'discounts'    => (float) $totals['total_discounts'],
            'tax'          => (float) $totals['total_tax'],
        ],
    ]);

} catch (PDOException $e) {
    error_log('ajax_all_sales failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}