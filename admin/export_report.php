<?php
require_once '../config/config.php';
require_admin();

/* ============================================================
   INPUTS
   ============================================================ */
$type     = $_GET['type']           ?? 'overview';
$format   = strtolower($_GET['format'] ?? 'csv');

$date_from      = $_GET['date_from']    ?? date('Y-m-01');
$date_to        = $_GET['date_to']      ?? date('Y-m-d');
$store_filter   = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
$payment_filter = $_GET['payment_method'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');
if (!in_array($format, ['csv','xlsx','pdf'], true))   $format    = 'csv';

/* ============================================================
   SHARED WHERE CLAUSES (same as reports.php)
   ============================================================ */
$where  = ["DATE(s.sale_date) BETWEEN ? AND ?", "s.status = 'completed'"];
$params = [$date_from, $date_to];
if ($store_filter > 0) { $where[] = "s.store_id = ?"; $params[] = $store_filter; }
if ($payment_filter !== '') { $where[] = "s.payment_method = ?"; $params[] = $payment_filter; }
$where_sql = 'WHERE ' . implode(' AND ', $where);

/* ============================================================
   BUILD DATASETS
   ============================================================ */
$title    = '';
$filename = 'report_' . $type . '_' . $date_from . '_to_' . $date_to;
$columns  = [];
$rows     = [];
$meta     = [
    'Period'       => $date_from . ' → ' . $date_to,
    'Store'        => $store_filter > 0 ? 'Store #' . $store_filter : 'All Stores',
    'Payment'      => $payment_filter !== '' ? $payment_filter : 'All Methods',
    'Generated At' => date('Y-m-d H:i:s'),
];

switch ($type) {

    /* --------------------------------------------------------
       OVERVIEW / P&L
       -------------------------------------------------------- */
    case 'overview':
        $title = 'Profit & Loss Statement';

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(s.total_amount),0) AS revenue,
                   COUNT(DISTINCT s.sale_id)       AS sales_count,
                   COALESCE(AVG(s.total_amount),0) AS avg_sale
            FROM sales s $where_sql
        ");
        $stmt->execute($params);
        $curr = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(si.quantity * p.purchase_price), 0)
            FROM sale_items si
            JOIN products p ON si.product_id = p.product_id
            JOIN sales s    ON si.sale_id    = s.sale_id
            $where_sql
        ");
        $stmt->execute($params);
        $cogs = (float) $stmt->fetchColumn();

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(e.amount), 0)
            FROM expenses e
            WHERE e.expense_date BETWEEN ? AND ?
            " . ($store_filter > 0 ? " AND e.store_id = ?" : "") . "
        ");
        $exp_params = [$date_from, $date_to];
        if ($store_filter > 0) $exp_params[] = $store_filter;
        $stmt->execute($exp_params);
        $exp_total = (float) $stmt->fetchColumn();

        $gross = $curr['revenue'] - $cogs;
        $net   = $gross - $exp_total;

        $columns = ['Line Item', 'Amount (UGX)'];
        $rows = [
            ['Revenue',           number_format($curr['revenue'], 0, '.', '')],
            ['Sales Count',       (string) $curr['sales_count']],
            ['Average Sale',      number_format($curr['avg_sale'], 0, '.', '')],
            ['Cost of Goods Sold',number_format($cogs, 0, '.', '')],
            ['Gross Profit',      number_format($gross, 0, '.', '')],
            ['Total Expenses',    number_format($exp_total, 0, '.', '')],
            ['NET PROFIT',        number_format($net, 0, '.', '')],
        ];
        break;

    /* --------------------------------------------------------
       SALES
       -------------------------------------------------------- */
    case 'sales':
        $title = 'Sales Transactions';

        $stmt = $conn->prepare("
            SELECT s.invoice_number, s.sale_date, s.total_amount,
                   s.payment_method, s.payment_status,
                   st.store_name, u.full_name AS served_by,
                   COALESCE(c.customer_name, 'Walk-in') AS customer,
                   (SELECT COALESCE(SUM(profit),0) FROM sale_items WHERE sale_id = s.sale_id) AS profit
            FROM sales s
            LEFT JOIN stores st ON s.store_id = st.store_id
            LEFT JOIN users u   ON s.served_by = u.user_id
            LEFT JOIN customers c ON s.customer_id = c.customer_id
            $where_sql
            ORDER BY s.sale_date DESC
        ");
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $columns = ['Invoice', 'Date', 'Customer', 'Store', 'Served By',
                    'Total (UGX)', 'Profit (UGX)', 'Payment', 'Status'];
        foreach ($data as $d) {
            $rows[] = [
                $d['invoice_number'],
                date('Y-m-d H:i', strtotime($d['sale_date'])),
                $d['customer'],
                $d['store_name'] ?? '',
                $d['served_by']  ?? '',
                number_format($d['total_amount'], 0, '.', ''),
                number_format($d['profit'],       0, '.', ''),
                $d['payment_method'],
                $d['payment_status'],
            ];
        }
        break;

    /* --------------------------------------------------------
       PRODUCTS
       -------------------------------------------------------- */
    case 'products':
        $title = 'Product Performance';

        $stmt = $conn->prepare("
            SELECT p.product_code, p.product_name,
                   COALESCE(c.category_name, 'Uncategorized') AS category_name,
                   SUM(si.quantity) AS units_sold,
                   COALESCE(SUM(si.total_price),0) AS revenue,
                   COALESCE(SUM(si.profit),0)      AS profit,
                   COUNT(DISTINCT s.sale_id)       AS txns
            FROM sale_items si
            JOIN products p ON si.product_id = p.product_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            JOIN sales s ON si.sale_id = s.sale_id
            $where_sql
            GROUP BY p.product_id, p.product_code, p.product_name, category_name
            ORDER BY revenue DESC
        ");
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $columns = ['Code', 'Product', 'Category', 'Units', 'Orders',
                    'Revenue (UGX)', 'Profit (UGX)', 'Margin %'];
        foreach ($data as $d) {
            $margin = $d['revenue'] > 0 ? ($d['profit'] / $d['revenue']) * 100 : 0;
            $rows[] = [
                $d['product_code'],
                $d['product_name'],
                $d['category_name'],
                $d['units_sold'],
                $d['txns'],
                number_format($d['revenue'], 0, '.', ''),
                number_format($d['profit'],  0, '.', ''),
                number_format($margin, 1, '.', ''),
            ];
        }
        break;

    /* --------------------------------------------------------
       TEAM
       -------------------------------------------------------- */
    case 'team':
        $title = 'Team Performance';

        $stmt = $conn->prepare("
            SELECT u.full_name, u.role,
                   COUNT(DISTINCT s.sale_id) AS sales_count,
                   COALESCE(SUM(s.total_amount),0) AS revenue,
                   COALESCE(AVG(s.total_amount),0) AS avg_sale
            FROM sales s
            JOIN users u ON s.served_by = u.user_id
            $where_sql
            GROUP BY u.user_id, u.full_name, u.role
            ORDER BY revenue DESC
        ");
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $columns = ['Name', 'Role', 'Sales', 'Revenue (UGX)', 'Avg Sale (UGX)', 'Profit (UGX)'];
        foreach ($data as $d) {
            $stmt2 = $conn->prepare("
                SELECT COALESCE(SUM(si.profit), 0)
                FROM sale_items si JOIN sales s ON si.sale_id = s.sale_id
                $where_sql AND s.served_by = ?
            ");
            $stmt2->execute(array_merge($params, [$d['full_name']]));
            $profit = (float) $stmt2->fetchColumn();

            $rows[] = [
                $d['full_name'],
                $d['role'],
                $d['sales_count'],
                number_format($d['revenue'],  0, '.', ''),
                number_format($d['avg_sale'], 0, '.', ''),
                number_format($profit,        0, '.', ''),
            ];
        }
        break;

    /* --------------------------------------------------------
       OPERATIONS
       -------------------------------------------------------- */
    case 'operations':
        $title = 'Operations Summary';

        // Low stock
        $stmt = $conn->prepare("
            SELECT p.product_code, p.product_name, st.store_name,
                   p.reorder_level, COALESCE(SUM(si.quantity),0) AS stock
            FROM products p CROSS JOIN stores st
            LEFT JOIN store_inventory si
                ON p.product_id = si.product_id
                AND st.store_id = si.store_id
                AND si.quantity > 0 AND si.status = 'good'
            WHERE p.status = 'active' AND st.status = 'active' AND st.store_type = 'sales'
            GROUP BY p.product_id, p.product_code, p.product_name,
                     st.store_id, st.store_name, p.reorder_level
            HAVING stock <= p.reorder_level
            ORDER BY (stock - p.reorder_level) ASC
        ");
        $stmt->execute();
        $low = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $columns = ['Section', 'Product', 'Store/Batch', 'Qty', 'Notes'];
        foreach ($low as $l) {
            $rows[] = ['LOW STOCK', $l['product_name'], $l['store_name'],
                       $l['stock'], 'Reorder level: ' . $l['reorder_level']];
        }

        // Expiring
        $stmt = $conn->prepare("
            SELECT p.product_name, st.store_name, si.batch_number,
                   si.quantity, si.expiry_date,
                   DATEDIFF(si.expiry_date, CURDATE()) AS days_left
            FROM store_inventory si
            JOIN products p ON si.product_id = p.product_id
            JOIN stores   st ON si.store_id  = st.store_id
            WHERE p.has_expiry = 'yes' AND si.expiry_date IS NOT NULL
              AND si.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
              AND si.quantity > 0 AND si.status = 'good' AND st.store_type = 'sales'
            ORDER BY si.expiry_date ASC
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $rows[] = ['EXPIRING', $e['product_name'], $e['store_name'] . ' / ' . $e['batch_number'],
                       $e['quantity'], 'Expires in ' . $e['days_left'] . ' days (' . $e['expiry_date'] . ')'];
        }

        // Damaged
        $stmt = $conn->prepare("
            SELECT p.product_name, st.store_name, si.batch_number, si.quantity
            FROM store_inventory si
            JOIN products p ON si.product_id = p.product_id
            JOIN stores   st ON si.store_id  = st.store_id
            WHERE si.status = 'damaged' AND si.quantity > 0
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $rows[] = ['DAMAGED', $d['product_name'], $d['store_name'] . ' / ' . $d['batch_number'],
                       $d['quantity'], 'Written off'];
        }

        // Credit
        $stmt = $conn->prepare("
            SELECT COALESCE(c.customer_name,'Walk-in') AS customer_name,
                   COALESCE(SUM(s.amount_due),0) AS due
            FROM sales s LEFT JOIN customers c ON s.customer_id = c.customer_id
            WHERE s.payment_method = 'credit' AND s.amount_due > 0
              AND DATE(s.sale_date) BETWEEN ? AND ?
              " . ($store_filter > 0 ? " AND s.store_id = ?" : "") . "
            GROUP BY s.customer_id, customer_name
            HAVING due > 0 ORDER BY due DESC
        ");
        $cp = [$date_from, $date_to];
        if ($store_filter > 0) $cp[] = $store_filter;
        $stmt->execute($cp);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $rows[] = ['CREDIT', $c['customer_name'], '—', number_format($c['due'],0,'.',''),
                       'Outstanding balance'];
        }
        break;

    default:
        die('Unknown report type');
}

/* ============================================================
   CSV OUTPUT
   ============================================================ */
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    $out = fopen('php://output', 'w');

    // BOM for Excel UTF-8 support
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Meta header
    fputcsv($out, [strtoupper($title)]);
    foreach ($meta as $k => $v) fputcsv($out, [$k, $v]);
    fputcsv($out, []);

    // Data
    fputcsv($out, $columns);
    foreach ($rows as $r) fputcsv($out, $r);

    fclose($out);
    exit;
}

/* ============================================================
   XLSX OUTPUT — SpreadsheetML (no PhpSpreadsheet needed)
   ============================================================ */
if ($format === 'xlsx') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');

    // Escape helper
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
    echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
    echo '<Styles>' . "\n";
    echo '<Style ss:ID="title"><Font ss:Bold="1" ss:Size="14"/></Style>' . "\n";
    echo '<Style ss:ID="header"><Font ss:Bold="1" ss:Color="#FFFFFF"/>';
    echo '<Interior ss:Color="#6B46C1" ss:Pattern="Solid"/></Style>' . "\n";
    echo '<Style ss:ID="meta"><Font ss:Italic="1" ss:Color="#718096"/></Style>' . "\n";
    echo '</Styles>' . "\n";

    echo '<Worksheet ss:Name="' . $esc(substr($title, 0, 30)) . '"><Table>' . "\n";

    // Title
    echo '<Row><Cell ss:StyleID="title"><Data ss:Type="String">' . $esc($title) . '</Data></Cell></Row>';
    // Meta
    foreach ($meta as $k => $v) {
        echo '<Row>';
        echo '<Cell ss:StyleID="meta"><Data ss:Type="String">' . $esc($k) . '</Data></Cell>';
        echo '<Cell ss:StyleID="meta"><Data ss:Type="String">' . $esc($v) . '</Data></Cell>';
        echo '</Row>';
    }
    echo '<Row></Row>';

    // Headers
    echo '<Row>';
    foreach ($columns as $c) {
        echo '<Cell ss:StyleID="header"><Data ss:Type="String">' . $esc($c) . '</Data></Cell>';
    }
    echo '</Row>';

    // Rows
    foreach ($rows as $r) {
        echo '<Row>';
        foreach ($r as $cell) {
            $num = is_numeric(str_replace(',', '', (string)$cell));
            echo '<Cell><Data ss:Type="' . ($num ? 'Number' : 'String') . '">' . $esc($cell) . '</Data></Cell>';
        }
        echo '</Row>';
    }

    echo '</Table></Worksheet></Workbook>';
    exit;
}

/* ============================================================
   PDF OUTPUT — browser print dialog (no library needed)
   ============================================================ */
if ($format === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title><?php echo htmlspecialchars($title); ?></title>
        <style>
            * { box-sizing: border-box; }
            body { font-family: 'Helvetica', Arial, sans-serif; color: #1A202C; margin: 24px; font-size: 11px; }
            h1 { font-size: 18px; margin: 0 0 4px; color: #6B46C1; }
            .meta { font-size: 10px; color: #718096; margin-bottom: 16px; }
            .meta span { margin-right: 16px; }
            table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            th { background: #6B46C1; color: #fff; padding: 8px; text-align: left; font-size: 10px; text-transform: uppercase; }
            td { padding: 7px 8px; border-bottom: 1px solid #E2E8F0; }
            tr:nth-child(even) td { background: #F7FAFC; }
            .num { text-align: right; font-family: 'Courier New', monospace; }
            .footer { margin-top: 24px; font-size: 9px; color: #A0AEC0; text-align: center; }
            @media print {
                body { margin: 10mm; }
                @page { size: A4; margin: 15mm; }
                .no-print { display: none; }
            }
            .toolbar {
                position: fixed; top: 12px; right: 12px;
                padding: 10px 16px; background: #48BB78; color: #fff;
                border-radius: 8px; cursor: pointer; font-weight: 700;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                border: none; font-size: 13px;
            }
            .toolbar:hover { background: #38A169; }
        </style>
    </head>
    <body>
        <button class="toolbar no-print" onclick="window.print()">
            🖨️ Print / Save as PDF
        </button>

        <h1><?php echo htmlspecialchars($title); ?></h1>
        <div class="meta">
            <?php foreach ($meta as $k => $v): ?>
                <span><strong><?php echo htmlspecialchars($k); ?>:</strong> <?php echo htmlspecialchars($v); ?></span>
            <?php endforeach; ?>
        </div>

        <table>
            <thead>
                <tr>
                    <?php foreach ($columns as $c): ?>
                        <th><?php echo htmlspecialchars($c); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="<?php echo count($columns); ?>" style="text-align:center;padding:24px;color:#A0AEC0;">
                        No data for this period.
                    </td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <?php foreach ($r as $i => $cell): ?>
                            <td class="<?php echo ($i > 0 && is_numeric(str_replace(',', '', (string)$cell))) ? 'num' : ''; ?>">
                                <?php echo htmlspecialchars($cell); ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <div class="footer">
            Generated by <?php echo htmlspecialchars($meta['Generated At']); ?> —
            <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Safety
http_response_code(400);
echo 'Unsupported format';