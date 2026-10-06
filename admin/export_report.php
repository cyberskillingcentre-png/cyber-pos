<?php
// Start output buffering at the VERY beginning
ob_start();

require_once '../config/config.php';
require_admin();

// Get parameters
$export_type = $_GET['export'] ?? '';
$format = $_GET['format'] ?? 'excel';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$store_filter = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
$payment_filter = $_GET['payment_method'] ?? '';

// Build WHERE clause
$where_conditions = ["DATE(s.sale_date) BETWEEN ? AND ?", "s.status = 'completed'"];
$params = [$date_from, $date_to];

if ($store_filter > 0) {
    $where_conditions[] = "s.store_id = ?";
    $params[] = $store_filter;
}

if (!empty($payment_filter)) {
    $where_conditions[] = "s.payment_method = ?";
    $params[] = $payment_filter;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get organization info
$org_name = $_SESSION['org_name'] ?? 'POS System';

// Get filter details
$filter_info = '';
if ($store_filter > 0) {
    $store_stmt = $conn->prepare("SELECT store_name FROM stores WHERE store_id = ?");
    $store_stmt->execute([$store_filter]);
    $store_name = $store_stmt->fetchColumn();
    $filter_info .= "Store: " . $store_name . " | ";
}
if (!empty($payment_filter)) {
    $filter_info .= "Payment Method: " . ucwords(str_replace('_', ' ', $payment_filter));
}

// Fetch data based on export type
$data = [];
$headers = [];
$title = '';

switch ($export_type) {
    case 'products':
        $title = 'Top Selling Products Report';
        $headers = ['#', 'Product Code', 'Product Name', 'Category', 'Qty Sold', 'Revenue (UGX)', 'Profit (UGX)', 'Margin %', 'Transactions'];
        
        $stmt = $conn->prepare("
            SELECT 
                p.product_code,
                p.product_name,
                c.category_name,
                SUM(si.quantity) as total_sold,
                COALESCE(SUM(si.total_price), 0) as revenue,
                COALESCE(SUM(si.profit), 0) as profit,
                COUNT(DISTINCT s.sale_id) as transaction_count
            FROM sale_items si
            JOIN products p ON si.product_id = p.product_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            JOIN sales s ON si.sale_id = s.sale_id
            $where_clause
            GROUP BY p.product_id
            ORDER BY total_sold DESC
            LIMIT 100
        ");
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        $i = 1;
        foreach ($results as $row) {
            $margin = $row['revenue'] > 0 ? ($row['profit'] / $row['revenue']) * 100 : 0;
            $data[] = [
                $i++,
                $row['product_code'],
                $row['product_name'],
                $row['category_name'] ?? 'N/A',
                number_format($row['total_sold']),
                number_format($row['revenue'], 0),
                number_format($row['profit'], 0),
                number_format($margin, 2) . '%',
                number_format($row['transaction_count'])
            ];
        }
        break;

    case 'sellers':
        $title = 'Top Sellers Performance Report';
        $headers = ['#', 'Employee Name', 'Role', 'Sales Count', 'Total Sales (UGX)', 'Profit (UGX)', 'Avg Sale (UGX)'];
        
        $stmt = $conn->prepare("
            SELECT 
                u.full_name,
                u.role,
                COUNT(s.sale_id) as sales_count,
                COALESCE(SUM(s.total_amount), 0) as total_sales,
                COALESCE(SUM(si.profit), 0) as profit,
                COALESCE(AVG(s.total_amount), 0) as avg_sale_value
            FROM sales s
            JOIN users u ON s.served_by = u.user_id
            LEFT JOIN sale_items si ON s.sale_id = si.sale_id
            $where_clause
            GROUP BY u.user_id
            ORDER BY total_sales DESC
        ");
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        $i = 1;
        foreach ($results as $row) {
            $data[] = [
                $i++,
                $row['full_name'],
                $row['role'],
                number_format($row['sales_count']),
                number_format($row['total_sales'], 0),
                number_format($row['profit'], 0),
                number_format($row['avg_sale_value'], 0)
            ];
        }
        break;

    case 'customers':
        $title = 'Top Customers Report';
        $headers = ['#', 'Customer Name', 'Phone', 'Email', 'Purchases', 'Total Spent (UGX)', 'Avg Purchase (UGX)', 'Last Purchase'];
        
        $check_customers = $conn->query("SELECT COUNT(*) FROM customers");
        if ($check_customers->fetchColumn() == 0) {
            $data[] = ['No customer data available', '', '', '', '', '', '', ''];
            break;
        }
        
        $stmt = $conn->prepare("
            SELECT 
                c.customer_name,
                c.phone,
                c.email,
                COUNT(s.sale_id) as purchase_count,
                COALESCE(SUM(s.total_amount), 0) as total_spent,
                COALESCE(AVG(s.total_amount), 0) as avg_purchase,
                MAX(s.sale_date) as last_purchase
            FROM sales s
            JOIN customers c ON s.customer_id = c.customer_id
            $where_clause
            GROUP BY c.customer_id
            ORDER BY total_spent DESC
            LIMIT 100
        ");
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        if (empty($results)) {
            $data[] = ['No customer purchases in selected period', '', '', '', '', '', '', ''];
            break;
        }
        
        $i = 1;
        foreach ($results as $row) {
            $data[] = [
                $i++,
                $row['customer_name'],
                $row['phone'] ?? 'N/A',
                $row['email'] ?? 'N/A',
                number_format($row['purchase_count']),
                number_format($row['total_spent'], 0),
                number_format($row['avg_purchase'], 0),
                date('d/m/Y', strtotime($row['last_purchase']))
            ];
        }
        break;

    case 'stores':
        $title = 'Store Performance Report';
        $headers = ['Store Code', 'Store Name', 'Sales Count', 'Revenue (UGX)', 'Profit (UGX)', 'Avg Sale (UGX)'];
        
        $stmt = $conn->prepare("
            SELECT 
                st.store_code,
                st.store_name,
                COUNT(s.sale_id) as sales_count,
                COALESCE(SUM(s.total_amount), 0) as revenue,
                COALESCE(SUM(si.profit), 0) as profit,
                COALESCE(AVG(s.total_amount), 0) as avg_sale
            FROM sales s
            JOIN stores st ON s.store_id = st.store_id
            LEFT JOIN sale_items si ON s.sale_id = si.sale_id
            $where_clause
            GROUP BY st.store_id
            ORDER BY revenue DESC
        ");
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        foreach ($results as $row) {
            $data[] = [
                $row['store_code'],
                $row['store_name'],
                number_format($row['sales_count']),
                number_format($row['revenue'], 0),
                number_format($row['profit'], 0),
                number_format($row['avg_sale'], 0)
            ];
        }
        break;

    case 'payments':
        $title = 'Payment Method Analysis Report';
        $headers = ['Payment Method', 'Transactions', 'Total Amount (UGX)', 'Avg Transaction (UGX)', '% of Total'];
        
        $stmt = $conn->prepare("
            SELECT 
                payment_method,
                COUNT(*) as transaction_count,
                COALESCE(SUM(total_amount), 0) as total_amount,
                COALESCE(AVG(total_amount), 0) as avg_amount
            FROM sales s
            $where_clause
            GROUP BY payment_method
            ORDER BY total_amount DESC
        ");
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        $total = array_sum(array_column($results, 'total_amount'));
        foreach ($results as $row) {
            $percentage = $total > 0 ? ($row['total_amount'] / $total) * 100 : 0;
            $data[] = [
                ucwords(str_replace('_', ' ', $row['payment_method'])),
                number_format($row['transaction_count']),
                number_format($row['total_amount'], 0),
                number_format($row['avg_amount'], 0),
                number_format($percentage, 2) . '%'
            ];
        }
        break;

    default:
        ob_end_clean();
        die('Invalid export type');
}

// Handle empty data
if (empty($data)) {
    $data[] = array_fill(0, count($headers), 'No data available');
}

// Export based on format
if ($format == 'excel') {
    // Clear any output buffer before Excel
    ob_end_clean();
    
    // Excel export with professional styling
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $export_type . '_report_' . date('Y-m-d') . '.xls"');
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <style>
            body { font-family: Arial, sans-serif; }
            .header { text-align: center; margin-bottom: 20px; }
            h1 { color: #2c3e50; margin: 10px 0; font-size: 20px; }
            h2 { color: #34495e; margin: 5px 0; font-size: 16px; }
            .info { color: #7f8c8d; font-size: 12px; margin: 5px 0; }
            table { border-collapse: collapse; width: 100%; margin-top: 20px; }
            th { 
                background-color: #3498db; 
                color: white; 
                padding: 10px; 
                font-weight: bold; 
                border: 1px solid #2980b9;
                text-align: left;
            }
            td { 
                padding: 8px; 
                border: 1px solid #bdc3c7; 
                text-align: left;
            }
            tr:nth-child(even) { background-color: #ecf0f1; }
            .footer { 
                text-align: center; 
                margin-top: 20px; 
                font-size: 10px; 
                color: #95a5a6; 
            }
        </style>
    </head>
    <body>';
    
    echo '<div class="header">';
    echo "<h1>" . htmlspecialchars($org_name) . "</h1>";
    echo "<h2>" . htmlspecialchars($title) . "</h2>";
    echo "<p class='info'>Period: <strong>" . date('d M Y', strtotime($date_from)) . "</strong> to <strong>" . date('d M Y', strtotime($date_to)) . "</strong></p>";
    
    if ($filter_info) {
        echo "<p class='info'>" . htmlspecialchars(rtrim($filter_info, ' | ')) . "</p>";
    }
    echo '</div>';
    
    echo "<table>";
    echo "<thead><tr>";
    foreach ($headers as $header) {
        echo "<th>" . htmlspecialchars($header) . "</th>";
    }
    echo "</tr></thead><tbody>";
    
    foreach ($data as $row) {
        echo "<tr>";
        foreach ($row as $cell) {
            echo "<td>" . htmlspecialchars($cell) . "</td>";
        }
        echo "</tr>";
    }
    
    echo "</tbody></table>";
    echo "<p class='footer'>Generated on: " . date('d M Y H:i:s') . "</p>";
    echo "</body></html>";
    
} elseif ($format == 'pdf') {
    // Clear any output buffer before PDF
    ob_end_clean();
    
    // PDF export using TCPDF
    require_once('../includes/tcpdf/tcpdf.php');
    
    // Create new PDF document
    $pdf = new TCPDF('L', PDF_UNIT, 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('POS System');
    $pdf->SetAuthor($org_name);
    $pdf->SetTitle($title);
    $pdf->SetSubject('Sales Report');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(TRUE, 10);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', 'B', 18);
    
    // Organization Name
    $pdf->Cell(0, 10, $org_name, 0, 1, 'C');
    
    // Title
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, $title, 0, 1, 'C');
    
    // Date Range
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Period: ' . date('d M Y', strtotime($date_from)) . ' to ' . date('d M Y', strtotime($date_to)), 0, 1, 'C');
    
    // Filter Info
    if ($filter_info) {
        $pdf->Cell(0, 6, rtrim($filter_info, ' | '), 0, 1, 'C');
    }
    
    $pdf->Ln(5);
    
    // Table Header
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(52, 152, 219);
    $pdf->SetTextColor(255, 255, 255);
    
    // Calculate column widths based on page width
    $pageWidth = $pdf->getPageWidth() - 20; // Minus margins
    $colCount = count($headers);
    $colWidth = $pageWidth / $colCount;
    
    // Adjust column widths for better fit
    $widths = [];
    foreach ($headers as $header) {
        $widths[] = $colWidth;
    }
    
    // Print header row
    foreach ($headers as $i => $header) {
        $pdf->Cell($widths[$i], 8, $header, 1, 0, 'L', true);
    }
    $pdf->Ln();
    
    // Table Body
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->SetFillColor(236, 240, 241);
        foreach ($row as $i => $cell) {
            $pdf->Cell($widths[$i], 7, $cell, 1, 0, 'L', $fill);
        }
        $pdf->Ln();
        $fill = !$fill;
    }
    
    // Footer
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(149, 165, 166);
    $pdf->Cell(0, 5, 'Generated on: ' . date('d M Y H:i:s'), 0, 1, 'R');
    
    // Output PDF
    $pdf->Output($export_type . '_report_' . date('Y-m-d') . '.pdf', 'D');
}

exit;
?>