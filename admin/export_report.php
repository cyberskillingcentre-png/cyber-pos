<?php
require_once '../config/config.php';
require_admin();

// ============================================
// INPUTS
// ============================================
$type      = $_GET['export'] ?? '';
$format    = $_GET['format'] ?? 'csv';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$store_id  = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
$payment   = $_GET['payment_method'] ?? '';

$valid_types   = [
    'all-sales','all-sales-lines','sales','products','categories',
    'team','operations','low-stock','expiring','credit',
];
$valid_formats = ['csv','excel','pdf'];

if (!in_array($type, $valid_types, true)) {
    http_response_code(400);
    die('Invalid report type.');
}
if (!in_array($format, $valid_formats, true)) {
    http_response_code(400);
    die('Invalid format.');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

// ============================================
// DETECT OPTIONAL COLUMNS
// ============================================
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

// ============================================
// BASE WHERE CLAUSE
// ============================================
$where  = ["DATE(s.sale_date) BETWEEN ? AND ?", "s.status = 'completed'"];
$params = [$date_from, $date_to];

if ($store_id > 0) {
    $where[]  = "s.store_id = ?";
    $params[] = $store_id;
}
if ($payment !== '') {
    $where[]  = "s.payment_method = ?";
    $params[] = $payment;
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

// ============================================
// REPORT DEFINITIONS
// ============================================
$report_title   = '';
$sql            = '';
$headers        = [];
$column_formats = [];
$query_params   = $params;

switch ($type) {

    case 'all-sales':
    case 'sales':
        $report_title = 'All Sales';
        $sql = "
            SELECT 
                s.invoice_number,
                s.sale_date,
                COALESCE(c.customer_name, 'Walk-in') AS customer_name,
                COALESCE(st.store_name, '-') AS store_name,
                COALESCE(u.full_name, '-') AS served_by,
                (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.sale_id) AS item_count,
                s.total_amount,
                (SELECT COALESCE(SUM(profit), 0) FROM sale_items WHERE sale_id = s.sale_id) AS profit,
                s.payment_method
            FROM sales s
            LEFT JOIN customers c ON s.customer_id = c.customer_id
            LEFT JOIN stores st   ON s.store_id    = st.store_id
            LEFT JOIN users u     ON s.served_by   = u.user_id
            $where_sql
            ORDER BY s.sale_date DESC
        ";
        $headers        = ['Invoice #','Date','Customer','Store','Served By','Items','Total','Profit','Payment'];
        $column_formats = ['text','datetime','text','text','text','int','money','money','text'];
        break;

    case 'all-sales-lines':
        $report_title = 'All Sales — Line Items';
        $sql = "
            SELECT 
                s.invoice_number,
                s.sale_date,
                COALESCE(st.store_name, '-') AS store_name,
                COALESCE(u.full_name, '-') AS served_by,
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
            $where_sql
            ORDER BY s.sale_date DESC, si.sale_item_id DESC
        ";
        $headers        = ['Invoice #','Sale Date','Store','Served By','Customer',
                           'Payment Method','Payment Status','Product Code','Product Name',
                           'Quantity','Unit Price','Discount','Tax','Total Price','Profit'];
        $column_formats = ['text','datetime','text','text','text',
                           'text','text','text','text',
                           'int','money','money','money','money','money'];
        break;

    case 'products':
        $report_title = 'Product Performance';
        $sql = "
            SELECT 
                p.product_code,
                p.product_name,
                COALESCE(c.category_name, 'Uncategorized') AS category_name,
                SUM(si.quantity) AS units_sold,
                COUNT(DISTINCT s.sale_id) AS orders,
                COALESCE(SUM(si.total_price), 0) AS revenue,
                COALESCE(SUM({$profit_sql}), 0) AS profit
            FROM sale_items si
            JOIN products p ON si.product_id = p.product_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            JOIN sales s ON si.sale_id = s.sale_id
            $where_sql
            GROUP BY p.product_id, p.product_code, p.product_name, category_name
            ORDER BY revenue DESC
        ";
        $headers        = ['Code','Product','Category','Units','Orders','Revenue','Profit'];
        $column_formats = ['text','text','text','int','int','money','money'];
        break;

    case 'categories':
        $report_title = 'Category Performance';
        $sql = "
            SELECT 
                COALESCE(c.category_name, 'Uncategorized') AS category_name,
                COUNT(DISTINCT p.product_id) AS product_count,
                COALESCE(SUM(si.quantity), 0) AS units_sold,
                COALESCE(SUM(si.total_price), 0) AS revenue,
                COALESCE(SUM({$profit_sql}), 0) AS profit
            FROM sale_items si
            JOIN products p ON si.product_id = p.product_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            JOIN sales s ON si.sale_id = s.sale_id
            $where_sql
            GROUP BY c.category_id, category_name
            ORDER BY revenue DESC
        ";
        $headers        = ['Category','Products','Units','Revenue','Profit'];
        $column_formats = ['text','int','int','money','money'];
        break;

    case 'team':
        $report_title = 'Team Performance';
        $sql = "
            SELECT 
                u.full_name,
                u.role,
                COUNT(DISTINCT s.sale_id) AS sales_count,
                COALESCE(SUM(s.total_amount), 0) AS revenue,
                COALESCE(AVG(s.total_amount), 0) AS avg_sale
            FROM sales s
            JOIN users u ON s.served_by = u.user_id
            $where_sql
            GROUP BY u.user_id, u.full_name, u.role
            ORDER BY revenue DESC
        ";
        $headers        = ['Name','Role','Sales Count','Revenue','Avg Sale'];
        $column_formats = ['text','text','int','money','money'];
        break;

    case 'low-stock':
        $report_title = 'Low Stock Products';
        $sql = "
            SELECT 
                p.product_code,
                p.product_name,
                st.store_name,
                COALESCE(SUM(si.quantity), 0) AS current_stock,
                p.reorder_level
            FROM products p
            CROSS JOIN stores st
            LEFT JOIN store_inventory si 
                ON p.product_id = si.product_id 
                AND st.store_id = si.store_id
                AND si.quantity > 0
            WHERE p.status = 'active' AND st.status = 'active'
            GROUP BY p.product_id, p.product_code, p.product_name, st.store_id, st.store_name, p.reorder_level
            HAVING COALESCE(SUM(si.quantity), 0) <= p.reorder_level
            ORDER BY (COALESCE(SUM(si.quantity), 0) - p.reorder_level) ASC
        ";
        $headers        = ['Code','Product','Store','Current Stock','Reorder Level'];
        $column_formats = ['text','text','text','int','int'];
        $query_params   = [];
        break;

    case 'expiring':
        $report_title = 'Expiring Products (Next 30 Days)';
        $sql = "
            SELECT 
                p.product_code,
                p.product_name,
                st.store_name,
                si.batch_number,
                si.quantity,
                si.expiry_date,
                DATEDIFF(si.expiry_date, CURDATE()) AS days_left,
                (si.quantity * p.purchase_price) AS value_at_risk
            FROM store_inventory si
            JOIN products p ON si.product_id = p.product_id
            JOIN stores st  ON si.store_id   = st.store_id
            WHERE p.has_expiry = 'yes'
              AND si.expiry_date IS NOT NULL
              AND si.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
              AND si.quantity > 0
            ORDER BY si.expiry_date ASC
        ";
        $headers        = ['Code','Product','Store','Batch','Qty','Expiry','Days Left','Value at Risk'];
        $column_formats = ['text','text','text','text','int','date','int','money'];
        $query_params   = [];
        break;

    case 'credit':
        $report_title = 'Outstanding Credit';
        $sql = "
            SELECT 
                COALESCE(c.customer_name, 'Walk-in') AS customer_name,
                COALESCE(c.phone, '-') AS phone,
                COUNT(s.sale_id) AS credit_sales,
                COALESCE(SUM(s.total_amount), 0) AS total_credit,
                COALESCE(SUM(s.amount_due), 0) AS outstanding
            FROM sales s
            LEFT JOIN customers c ON s.customer_id = c.customer_id
            WHERE s.payment_method = 'credit'
              AND s.amount_due > 0
              AND DATE(s.sale_date) BETWEEN ? AND ?
              AND s.status = 'completed'
            GROUP BY s.customer_id, customer_name, phone
            ORDER BY outstanding DESC
        ";
        $headers        = ['Customer','Phone','Credit Sales','Total Credit','Outstanding'];
        $column_formats = ['text','text','int','money','money'];
        $query_params   = [$date_from, $date_to];
        break;

    case 'operations':
        break;
}

// ============================================
// RUN QUERY
// ============================================
try {
    if ($type === 'operations') {
        $rows = [];

        $stmt = $conn->prepare("
            SELECT 
                p.product_code, p.product_name, st.store_name,
                COALESCE(SUM(si.quantity), 0) AS current_stock,
                p.reorder_level
            FROM products p
            CROSS JOIN stores st
            LEFT JOIN store_inventory si 
                ON p.product_id = si.product_id 
                AND st.store_id = si.store_id
                AND si.quantity > 0
            WHERE p.status = 'active' AND st.status = 'active'
            GROUP BY p.product_id, p.product_code, p.product_name, st.store_id, st.store_name, p.reorder_level
            HAVING COALESCE(SUM(si.quantity), 0) <= p.reorder_level
            ORDER BY (COALESCE(SUM(si.quantity), 0) - p.reorder_level) ASC
        ");
        $stmt->execute();
        $low = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("
            SELECT 
                p.product_code, p.product_name, st.store_name,
                si.batch_number, si.quantity, si.expiry_date,
                DATEDIFF(si.expiry_date, CURDATE()) AS days_left
            FROM store_inventory si
            JOIN products p ON si.product_id = p.product_id
            JOIN stores st  ON si.store_id   = st.store_id
            WHERE p.has_expiry = 'yes'
              AND si.expiry_date IS NOT NULL
              AND si.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
              AND si.quantity > 0
            ORDER BY si.expiry_date ASC
        ");
        $stmt->execute();
        $expiring = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("
            SELECT 
                COALESCE(c.customer_name, 'Walk-in') AS customer_name,
                COALESCE(c.phone, '-') AS phone,
                COALESCE(SUM(s.amount_due), 0) AS outstanding
            FROM sales s
            LEFT JOIN customers c ON s.customer_id = c.customer_id
            WHERE s.payment_method = 'credit'
              AND s.amount_due > 0
              AND s.status = 'completed'
            GROUP BY s.customer_id, customer_name, phone
            ORDER BY outstanding DESC
        ");
        $stmt->execute();
        $credit = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $report_title   = 'Operations Report';
        $headers        = ['Section','Detail','Store / Batch','Qty / Value','Notes'];
        $column_formats = ['text','text','text','text','text'];

        foreach ($low as $row) {
            $rows[] = [
                'section'    => 'Low Stock',
                'detail'     => $row['product_code'] . ' — ' . $row['product_name'],
                'store'      => $row['store_name'],
                'qty_value'  => $row['current_stock'] . ' / ' . $row['reorder_level'],
                'additional' => 'Restock needed',
            ];
        }
        foreach ($expiring as $row) {
            $rows[] = [
                'section'    => 'Expiring',
                'detail'     => $row['product_code'] . ' — ' . $row['product_name'],
                'store'      => $row['store_name'] . ' (batch ' . $row['batch_number'] . ')',
                'qty_value'  => $row['quantity'] . ' units',
                'additional' => 'Expires ' . date('d/m/Y', strtotime($row['expiry_date'])) . ' (' . $row['days_left'] . 'd)',
            ];
        }
        foreach ($credit as $row) {
            $rows[] = [
                'section'    => 'Outstanding Credit',
                'detail'     => $row['customer_name'] . ' (' . $row['phone'] . ')',
                'store'      => '-',
                'qty_value'  => number_format($row['outstanding'], 2) . ' UGX',
                'additional' => 'Contact for payment',
            ];
        }
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->execute($query_params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('export_report failed: ' . $e->getMessage());
    http_response_code(500);
    die('Export failed: ' . $e->getMessage());
}

// ============================================
// COMPANY INFO
// ============================================
$settings = [];
try {
    foreach ($conn->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $settings[$s['setting_key']] = $s['setting_value'];
    }
} catch (PDOException $e) {}

$company_name = $settings['company_name'] ?? 'My Business';

// ============================================
// CELL FORMATTER
// ============================================
function format_cell($value, $type) {
    if ($value === null || $value === '') {
        return $type === 'money' ? '0.00' : '';
    }
    switch ($type) {
        case 'money':    return number_format((float)$value, 2);
        case 'int':      return (string)(int)$value;
        case 'date':     return date('d/m/Y', strtotime($value));
        case 'datetime': return date('d/m/Y H:i', strtotime($value));
        default:         return (string)$value;
    }
}

// ============================================
// CSV / EXCEL
// ============================================
if ($format === 'csv' || $format === 'excel') {
    $ext      = ($format === 'excel') ? 'xls' : 'csv';
    $mime     = ($format === 'excel') ? 'application/vnd.ms-excel' : 'text/csv';
    $filename = "report_{$type}_{$date_from}_to_{$date_to}.{$ext}";

    header("Content-Type: {$mime}; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, [$company_name]);
    fputcsv($out, [$report_title]);
    fputcsv($out, ["Period: " . date('d M Y', strtotime($date_from)) . " to " . date('d M Y', strtotime($date_to))]);
    fputcsv($out, []);
    fputcsv($out, $headers);

    foreach ($rows as $row) {
        $line = [];
        foreach (array_values($row) as $i => $value) {
            $fmt = $column_formats[$i] ?? 'text';
            $line[] = format_cell($value, $fmt);
        }
        fputcsv($out, $line);
    }

    fputcsv($out, []);
    fputcsv($out, ['Total rows:', count($rows)]);
    fputcsv($out, ['Generated:', date('d M Y H:i')]);

    fclose($out);
    exit;
}

// ============================================
// PDF
// ============================================
if ($format === 'pdf') {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        http_response_code(500);
        die('PDF library not installed. Run: composer require dompdf/dompdf');
    }
    require_once $autoload;

    $max_pdf_rows = 2000;
    $truncated = false;
    if (count($rows) > $max_pdf_rows) {
        $rows = array_slice($rows, 0, $max_pdf_rows);
        $truncated = true;
    }

    $column_totals = [];
    foreach ($column_formats as $i => $fmt) {
        if ($fmt === 'money' || $fmt === 'int') {
            $sum = 0;
            foreach ($rows as $row) {
                $vals = array_values($row);
                $sum += (float) ($vals[$i] ?? 0);
            }
            $column_totals[$i] = $sum;
        }
    }

    $html  = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>';
    $html .= '
        @page { margin: 22px 18px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1A202C; margin: 0; }
        .header { border-bottom: 2px solid #6B46C1; padding-bottom: 12px; margin-bottom: 14px; }
        .header h1 { margin: 0; font-size: 16px; color: #6B46C1; letter-spacing: 1px; }
        .header h2 { margin: 4px 0 0; font-size: 12px; color: #2D3748; font-weight: normal; }
        .header .period { margin-top: 4px; font-size: 9px; color: #718096; }
        .header .generated { margin-top: 2px; font-size: 8px; color: #A0AEC0; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        thead th {
            background: #6B46C1; color: #fff; padding: 6px 5px;
            font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px;
            text-align: left; border: 1px solid #553C9A;
        }
        thead th.money, thead th.int { text-align: right; }
        tbody td { padding: 4px 5px; border: 1px solid #E2E8F0; font-size: 8.5px; }
        tbody td.money, tbody td.int { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        tbody tr:nth-child(even) { background: #F7FAFC; }
        tfoot td {
            padding: 6px 5px; border: 1px solid #6B46C1;
            background: #EDE9FE; font-weight: bold; font-size: 9px; text-align: right;
        }
        tfoot td.label { text-align: right; color: #553C9A; }
        .footer {
            margin-top: 14px; padding-top: 8px;
            border-top: 1px solid #E2E8F0;
            font-size: 8px; color: #718096; text-align: center;
        }
        .notice {
            margin-top: 10px; padding: 6px 10px;
            background: #FEFCBF; border-left: 3px solid #ED8936;
            font-size: 8px; color: #744210;
        }
    ';
    $html .= '</style></head><body>';

    $html .= '<div class="header">';
    $html .= '<h1>' . htmlspecialchars($company_name) . '</h1>';
    $html .= '<h2>' . htmlspecialchars($report_title) . '</h2>';
    $html .= '<div class="period">Period: ' . date('d M Y', strtotime($date_from)) . ' to ' . date('d M Y', strtotime($date_to)) . '</div>';
    $html .= '<div class="generated">Generated: ' . date('d M Y H:i') . ' &middot; ' . count($rows) . ' rows</div>';
    $html .= '</div>';

    $html .= '<table><thead><tr>';
    foreach ($headers as $i => $h) {
        $fmt = $column_formats[$i] ?? 'text';
        $cls = in_array($fmt, ['money','int'], true) ? $fmt : '';
        $html .= '<th class="' . $cls . '">' . htmlspecialchars($h) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    if (empty($rows)) {
        $html .= '<tr><td colspan="' . count($headers) . '" style="text-align:center;padding:20px;color:#718096;">No data for the selected filters</td></tr>';
    } else {
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (array_values($row) as $i => $value) {
                $fmt = $column_formats[$i] ?? 'text';
                $cls = in_array($fmt, ['money','int'], true) ? $fmt : '';
                $html .= '<td class="' . $cls . '">' . htmlspecialchars(format_cell($value, $fmt)) . '</td>';
            }
            $html .= '</tr>';
        }
    }
    $html .= '</tbody>';

    if (!empty($column_totals)) {
        $html .= '<tfoot><tr>';
        foreach ($headers as $i => $h) {
            if ($i === 0) {
                $html .= '<td class="label">TOTAL</td>';
            } elseif (isset($column_totals[$i])) {
                $fmt = $column_formats[$i] ?? 'text';
                $val = ($fmt === 'money')
                    ? number_format($column_totals[$i], 2)
                    : number_format($column_totals[$i]);
                $html .= '<td>' . $val . '</td>';
            } else {
                $html .= '<td></td>';
            }
        }
        $html .= '</tr></tfoot>';
    }

    $html .= '</table>';

    if ($truncated) {
        $html .= '<div class="notice"><strong>Note:</strong> Report capped at ' . $max_pdf_rows . ' rows. Use CSV export for the full dataset.</div>';
    }

    $html .= '<div class="footer">Generated by ' . htmlspecialchars(APP_NAME) . ' &middot; ' . date('Y') . '</div>';
    $html .= '</body></html>';

    try {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = "report_{$type}_{$date_from}_to_{$date_to}.pdf";
        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    } catch (Exception $e) {
        error_log('Dompdf failed: ' . $e->getMessage());
        http_response_code(500);
        die('PDF generation failed: ' . $e->getMessage());
    }
}