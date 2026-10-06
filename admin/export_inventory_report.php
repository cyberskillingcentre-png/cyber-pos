<?php
// Start output buffering
ob_start();

require_once '../config/config.php';
require_admin();

// Get parameters
$report_type = $_GET['report'] ?? 'stock_levels';
$export_format = $_GET['export'] ?? 'excel';
$filter_store = $_GET['store'] ?? 'all';
$filter_category = $_GET['category'] ?? 'all';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Get organization info
$org_name = $_SESSION['org_name'] ?? 'POS System';

// Build WHERE clause for filters
$where_store = $filter_store != 'all' ? "AND si.store_id = " . intval($filter_store) : "";
$where_category = $filter_category != 'all' ? "AND p.category_id = " . intval($filter_category) : "";

// Get filter details for display
$filter_info = '';
if ($filter_store != 'all') {
    $stmt = $conn->prepare("SELECT store_name FROM stores WHERE store_id = ?");
    $stmt->execute([$filter_store]);
    $store_name = $stmt->fetchColumn();
    $filter_info .= "Store: " . $store_name . " | ";
}
if ($filter_category != 'all') {
    $stmt = $conn->prepare("SELECT category_name FROM categories WHERE category_id = ?");
    $stmt->execute([$filter_category]);
    $category_name = $stmt->fetchColumn();
    $filter_info .= "Category: " . $category_name . " | ";
}

// Initialize data
$data = [];
$headers = [];
$title = '';

// Fetch data based on report type
switch ($report_type) {
    case 'stock_levels':
        $title = 'Stock Levels Report';
        $headers = ['Product Code', 'Product Name', 'Category', 'Store', 'Quantity', 'Reorder Level', 'Unit Cost', 'Total Value', 'Status'];
        
        $stmt = $conn->prepare("
            SELECT 
                p.product_code,
                p.product_name,
                c.category_name,
                s.store_name,
                COALESCE(SUM(si.quantity), 0) as total_quantity,
                p.reorder_level,
                p.purchase_price,
                CASE 
                    WHEN COALESCE(SUM(si.quantity), 0) = 0 THEN 'Out of Stock'
                    WHEN COALESCE(SUM(si.quantity), 0) <= p.reorder_level THEN 'Low Stock'
                    ELSE 'Good Stock'
                END as stock_status
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN store_inventory si ON p.product_id = si.product_id
            LEFT JOIN stores s ON si.store_id = s.store_id
            WHERE p.status = 'active' $where_category $where_store
            GROUP BY p.product_id, s.store_id
            ORDER BY stock_status ASC, p.product_name
        ");
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        foreach ($results as $row) {
            $data[] = [
                $row['product_code'],
                $row['product_name'],
                $row['category_name'] ?? 'N/A',
                $row['store_name'] ?? 'N/A',
                number_format($row['total_quantity']),
                number_format($row['reorder_level']),
                number_format($row['purchase_price'], 0),
                number_format($row['total_quantity'] * $row['purchase_price'], 0),
                $row['stock_status']
            ];
        }
        break;
        
    case 'inventory_value':
        $title = 'Inventory Value Report';
        $headers = ['Product Code', 'Product Name', 'Category', 'Store', 'Batch', 'Quantity', 'Unit Cost', 'Cost Value', 'Unit Price', 'Retail Value', 'Potential Profit', 'Margin %'];
        
        $stmt = $conn->prepare("
            SELECT 
                p.product_code,
                p.product_name,
                c.category_name,
                s.store_name,
                si.batch_number,
                si.quantity,
                p.purchase_price,
                p.selling_price,
                (si.quantity * p.purchase_price) as cost_value,
                (si.quantity * p.selling_price) as retail_value,
                ((si.quantity * p.selling_price) - (si.quantity * p.purchase_price)) as potential_profit
            FROM store_inventory si
            JOIN products p ON si.product_id = p.product_id
            JOIN stores s ON si.store_id = s.store_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            WHERE p.status = 'active' AND si.quantity > 0 $where_store $where_category
            ORDER BY cost_value DESC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        foreach ($results as $row) {
            $margin = $row['retail_value'] > 0 ? (($row['potential_profit'] / $row['retail_value']) * 100) : 0;
            $data[] = [
                $row['product_code'],
                $row['product_name'],
                $row['category_name'] ?? 'N/A',
                $row['store_name'],
                $row['batch_number'],
                number_format($row['quantity']),
                number_format($row['purchase_price'], 0),
                number_format($row['cost_value'], 0),
                number_format($row['selling_price'], 0),
                number_format($row['retail_value'], 0),
                number_format($row['potential_profit'], 0),
                number_format($margin, 1) . '%'
            ];
        }
        break;
        
    case 'batch_tracking':
        $title = 'Batch Tracking Report';
        $headers = ['Batch Number', 'Product Code', 'Product Name', 'Category', 'Store', 'Initial Qty', 'Current Qty', 'Sold', 'Received Date', 'Age (Days)', 'Expiry Date', 'Batch Value', 'Status'];
        
        $where_date = "";
        if ($start_date && $end_date) {
            $where_date = "AND si.created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'";
        }
        
        $stmt = $conn->prepare("
            SELECT 
                si.batch_number,
                p.product_code,
                p.product_name,
                c.category_name,
                s.store_name,
                si.quantity as current_quantity,
                si.initial_quantity,
                si.expiry_date,
                si.created_at as received_date,
                DATEDIFF(CURDATE(), si.created_at) as age_days,
                p.purchase_price,
                (si.quantity * p.purchase_price) as batch_value,
                CASE 
                    WHEN si.quantity = 0 THEN 'Depleted'
                    WHEN si.quantity <= 10 THEN 'Low'
                    ELSE 'Available'
                END as batch_status
            FROM store_inventory si
            JOIN products p ON si.product_id = p.product_id
            JOIN stores s ON si.store_id = s.store_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            WHERE 1=1 $where_store $where_category $where_date
            ORDER BY si.created_at DESC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        foreach ($results as $row) {
            $initial = $row['initial_quantity'] ?? $row['current_quantity'];
            $sold = $initial - $row['current_quantity'];
            $data[] = [
                $row['batch_number'],
                $row['product_code'],
                $row['product_name'],
                $row['category_name'] ?? 'N/A',
                $row['store_name'],
                number_format($initial),
                number_format($row['current_quantity']),
                number_format($sold),
                date('d/m/Y', strtotime($row['received_date'])),
                $row['age_days'] . ' days',
                $row['expiry_date'] ? date('d/m/Y', strtotime($row['expiry_date'])) : 'N/A',
                number_format($row['batch_value'], 0),
                $row['batch_status']
            ];
        }
        break;
        
    case 'stock_transfers':
        $title = 'Stock Transfers Report';
        $headers = ['Transfer #', 'Date', 'Product Code', 'Product Name', 'Category', 'From Store', 'To Store', 'Quantity', 'Unit Cost', 'Transfer Value', 'Reason', 'Transferred By', 'Status'];
        
        $where_date = "";
        if ($start_date && $end_date) {
            $where_date = "AND st.transfer_date BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'";
        }
        
        $where_from_store = "";
        if ($filter_store != 'all') {
            $where_from_store = "AND (st.from_store_id = " . intval($filter_store) . " OR st.to_store_id = " . intval($filter_store) . ")";
        }
        
        $stmt = $conn->prepare("
            SELECT 
                st.transfer_number,
                st.transfer_date,
                p.product_code,
                p.product_name,
                c.category_name,
                fs.store_name as from_store,
                ts.store_name as to_store,
                st.quantity,
                st.reason,
                u.full_name as transferred_by,
                st.status,
                p.purchase_price,
                (st.quantity * p.purchase_price) as transfer_value
            FROM stock_transfers st
            JOIN products p ON st.product_id = p.product_id
            JOIN stores fs ON st.from_store_id = fs.store_id
            JOIN stores ts ON st.to_store_id = ts.store_id
            JOIN users u ON st.transferred_by = u.user_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            WHERE 1=1 $where_date $where_from_store $where_category
            ORDER BY st.transfer_date DESC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        foreach ($results as $row) {
            $data[] = [
                $row['transfer_number'],
                date('d/m/Y H:i', strtotime($row['transfer_date'])),
                $row['product_code'],
                $row['product_name'],
                $row['category_name'] ?? 'N/A',
                $row['from_store'],
                $row['to_store'],
                number_format($row['quantity']),
                number_format($row['purchase_price'], 0),
                number_format($row['transfer_value'], 0),
                $row['reason'] ?? 'N/A',
                $row['transferred_by'],
                ucfirst($row['status'])
            ];
        }
        break;
        
    case 'expiry_tracking':
        $title = 'Expiry Tracking Report';
        $headers = ['Product Code', 'Product Name', 'Category', 'Store', 'Batch', 'Quantity', 'Unit Cost', 'Total Value', 'Expiry Date', 'Days to Expiry', 'Status'];
        
        $stmt = $conn->prepare("
            SELECT 
                p.product_code,
                p.product_name,
                c.category_name,
                s.store_name,
                si.batch_number,
                si.quantity,
                si.expiry_date,
                p.purchase_price,
                DATEDIFF(si.expiry_date, CURDATE()) as days_to_expiry,
                CASE 
                    WHEN si.expiry_date < CURDATE() THEN 'Expired'
                    WHEN DATEDIFF(si.expiry_date, CURDATE()) <= 30 THEN 'Expiring Soon'
                    WHEN DATEDIFF(si.expiry_date, CURDATE()) <= 90 THEN 'Warning'
                    ELSE 'Good'
                END as expiry_status
            FROM store_inventory si
            JOIN products p ON si.product_id = p.product_id
            JOIN stores s ON si.store_id = s.store_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            WHERE p.has_expiry = 'yes' 
            AND si.expiry_date IS NOT NULL
            AND si.quantity > 0
            $where_store $where_category
            ORDER BY si.expiry_date ASC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        foreach ($results as $row) {
            $data[] = [
                $row['product_code'],
                $row['product_name'],
                $row['category_name'] ?? 'N/A',
                $row['store_name'],
                $row['batch_number'],
                number_format($row['quantity']),
                number_format($row['purchase_price'], 0),
                number_format($row['quantity'] * $row['purchase_price'], 0),
                date('d/m/Y', strtotime($row['expiry_date'])),
                $row['days_to_expiry'] . ' days',
                $row['expiry_status']
            ];
        }
        break;
        
    case 'store_comparison':
        $title = 'Store Comparison Report';
        $headers = ['Store Code', 'Store Name', 'Unique Products', 'Total Items', 'Cost Value', 'Retail Value', 'Potential Profit', 'Profit Margin %'];
        
        $stmt = $conn->prepare("
            SELECT 
                s.store_name,
                s.store_code,
                COUNT(DISTINCT si.product_id) as unique_products,
                COALESCE(SUM(si.quantity), 0) as total_items,
                COALESCE(SUM(si.quantity * p.purchase_price), 0) as total_cost_value,
                COALESCE(SUM(si.quantity * p.selling_price), 0) as total_retail_value
            FROM stores s
            LEFT JOIN store_inventory si ON s.store_id = si.store_id AND si.quantity > 0
            LEFT JOIN products p ON si.product_id = p.product_id
            WHERE s.status = 'active' $where_category
            GROUP BY s.store_id
            ORDER BY total_cost_value DESC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        foreach ($results as $row) {
            $profit = $row['total_retail_value'] - $row['total_cost_value'];
            $margin = $row['total_retail_value'] > 0 ? (($profit / $row['total_retail_value']) * 100) : 0;
            $data[] = [
                $row['store_code'],
                $row['store_name'],
                number_format($row['unique_products']),
                number_format($row['total_items']),
                number_format($row['total_cost_value'], 0),
                number_format($row['total_retail_value'], 0),
                number_format($profit, 0),
                number_format($margin, 1) . '%'
            ];
        }
        break;
        
    default:
        ob_end_clean();
        die('Invalid report type');
}

// Handle empty data
if (empty($data)) {
    $data[] = array_fill(0, count($headers), 'No data available');
}

// Export based on format
if ($export_format == 'excel') {
    ob_end_clean();
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="inventory_' . $report_type . '_' . date('Y-m-d') . '.xls"');
    
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
    
    if (in_array($report_type, ['batch_tracking', 'stock_transfers'])) {
        echo "<p class='info'>Period: <strong>" . date('d M Y', strtotime($start_date)) . "</strong> to <strong>" . date('d M Y', strtotime($end_date)) . "</strong></p>";
    }
    
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
    
} elseif ($export_format == 'pdf') {
    ob_end_clean();
    
    require_once('../includes/tcpdf/tcpdf.php');
    
    $pdf = new TCPDF('L', PDF_UNIT, 'A4', true, 'UTF-8', false);
    
    $pdf->SetCreator('POS System');
    $pdf->SetAuthor($org_name);
    $pdf->SetTitle($title);
    $pdf->SetSubject('Inventory Report');
    
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(TRUE, 10);
    
    $pdf->AddPage();
    
    // Organization Name
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, $org_name, 0, 1, 'C');
    
    // Title
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, $title, 0, 1, 'C');
    
    // Date Range (if applicable)
    if (in_array($report_type, ['batch_tracking', 'stock_transfers'])) {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'Period: ' . date('d M Y', strtotime($start_date)) . ' to ' . date('d M Y', strtotime($end_date)), 0, 1, 'C');
    }
    
    // Filter Info
    if ($filter_info) {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, rtrim($filter_info, ' | '), 0, 1, 'C');
    }
    
    $pdf->Ln(5);
    
    // Table Header
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(52, 152, 219);
    $pdf->SetTextColor(255, 255, 255);
    
    $pageWidth = $pdf->getPageWidth() - 20;
    $colCount = count($headers);
    $colWidth = $pageWidth / $colCount;
    
    $widths = array_fill(0, $colCount, $colWidth);
    
    foreach ($headers as $i => $header) {
        $pdf->Cell($widths[$i], 7, $header, 1, 0, 'L', true);
    }
    $pdf->Ln();
    
    // Table Body
    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->SetFillColor(236, 240, 241);
        foreach ($row as $i => $cell) {
            $pdf->Cell($widths[$i], 6, $cell, 1, 0, 'L', $fill);
        }
        $pdf->Ln();
        $fill = !$fill;
    }
    
    // Footer
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(149, 165, 166);
    $pdf->Cell(0, 5, 'Generated on: ' . date('d M Y H:i:s'), 0, 1, 'R');
    
    $pdf->Output('inventory_' . $report_type . '_' . date('Y-m-d') . '.pdf', 'D');
}

exit;
?>