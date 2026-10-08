<?php
require_once '../config/config.php';
require_login();

$sale_id = intval($_GET['id'] ?? 0);

if ($sale_id <= 0) {
    die('Invalid sale ID');
}

// ✅ IDOR-protected fetch
$sale = get_sale_with_access_check($conn, $sale_id);

if (!$sale) {
    // Don't reveal whether it exists
    die('Receipt not found or you do not have permission to view it');
}

// Fetch sale items
$stmt = $conn->prepare("
    SELECT si.*, p.product_name, p.product_code
    FROM sale_items si
    JOIN products p ON si.product_id = p.product_id
    WHERE si.sale_id = ?
    ORDER BY si.sale_item_id
");
$stmt->execute([$sale_id]);
$items = $stmt->fetchAll();

// Fetch settings (key/value style)
$settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch(PDOException $e) {
    $settings = [];
}

// Extract with defaults
$company_name    = $settings['company_name'] ?? APP_NAME;
$company_phone   = $settings['company_phone'] ?? ($sale['store_phone'] ?? '');
$company_email   = $settings['company_email'] ?? '';
$company_address = $settings['company_address'] ?? ($sale['store_location'] ?? '');
$receipt_footer  = $settings['receipt_footer'] ?? 'Thank you for your business!';

$change = ($sale['amount_paid'] ?? 0) - ($sale['total_amount'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo htmlspecialchars($sale['invoice_number']); ?></title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            background: #E2E8F0;
            padding: 20px;
            font-size: 12px;
            color: #1A202C;
        }
        
        .receipt-container {
            max-width: 380px;
            margin: 0 auto;
            background: white;
            padding: 24px 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            border-radius: 8px;
        }
        
        /* Header */
        .receipt-header {
            text-align: center;
            border-bottom: 2px dashed #1A202C;
            padding-bottom: 16px;
            margin-bottom: 16px;
        }
        
        .receipt-header h1 {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 1px;
            margin-bottom: 6px;
            color: #6B46C1;
        }
        
        .receipt-header .company-info {
            font-size: 10px;
            color: #4A5568;
            line-height: 1.6;
        }
        
        .receipt-header .company-info div {
            margin-bottom: 2px;
        }
        
        .receipt-header .company-info i {
            color: #ED8936;
            margin-right: 4px;
        }
        
        .receipt-badge {
            display: inline-block;
            background: linear-gradient(135deg, #6B46C1, #553C9A);
            color: white;
            padding: 4px 14px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
            margin-top: 10px;
        }
        
        /* Sale Info */
        .sale-info {
            margin-bottom: 16px;
            font-size: 11px;
            line-height: 1.8;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            gap: 12px;
        }
        
        .info-row .label {
            color: #718096;
            font-weight: 600;
            flex-shrink: 0;
        }
        
        .info-row .value {
            font-weight: 700;
            color: #1A202C;
            text-align: right;
            word-break: break-word;
        }
        
        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 11px;
        }
        
        .items-table thead th {
            border-top: 2px solid #1A202C;
            border-bottom: 2px solid #1A202C;
            padding: 8px 4px;
            text-align: left;
            font-weight: 700;
            font-size: 10px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        
        .items-table thead th.right {
            text-align: right;
        }
        
        .items-table tbody td {
            padding: 8px 4px;
            border-bottom: 1px dashed #CBD5E0;
            vertical-align: top;
        }
        
        .items-table tbody td.right {
            text-align: right;
        }
        
        .items-table tbody td.product-name {
            font-weight: 600;
            max-width: 140px;
            word-wrap: break-word;
        }
        
        .items-table .product-code {
            font-size: 9px;
            color: #A0AEC0;
            display: block;
            margin-top: 2px;
            font-weight: 500;
        }
        
        /* Totals */
        .totals-section {
            border-top: 2px dashed #1A202C;
            padding-top: 12px;
            margin-bottom: 16px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 11px;
        }
        
        .total-row .label {
            color: #4A5568;
        }
        
        .total-row .value {
            font-weight: 600;
        }
        
        .total-row.grand-total {
            border-top: 2px solid #1A202C;
            border-bottom: 2px solid #1A202C;
            padding: 10px 0;
            margin: 8px 0;
            font-size: 14px;
            font-weight: 800;
            color: #6B46C1;
        }
        
        .total-row.grand-total .label {
            color: #1A202C;
            letter-spacing: 1px;
        }
        
        .total-row.change-row {
            background: rgba(72, 187, 120, 0.1);
            padding: 6px 8px;
            border-radius: 4px;
            margin-top: 6px;
            font-weight: 700;
            color: #2F855A;
        }
        
        /* Payment Badge */
        .payment-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .payment-badge.paid {
            background: rgba(72, 187, 120, 0.15);
            color: #2F855A;
        }
        
        .payment-badge.partial {
            background: rgba(237, 137, 54, 0.15);
            color: #C05621;
        }
        
        .payment-badge.unpaid {
            background: rgba(229, 62, 62, 0.15);
            color: #C53030;
        }
        
        /* Barcode */
        .barcode-container {
            text-align: center;
            margin: 16px 0;
            padding: 12px 0;
            border-top: 1px solid #E2E8F0;
            border-bottom: 1px solid #E2E8F0;
        }
        
        .barcode-container svg {
            max-width: 100%;
        }
        
        /* Footer */
        .receipt-footer {
            text-align: center;
            border-top: 2px dashed #1A202C;
            padding-top: 16px;
            font-size: 10px;
            color: #4A5568;
            line-height: 1.6;
        }
        
        .receipt-footer .thank-you {
            font-size: 13px;
            font-weight: 800;
            color: #6B46C1;
            margin-bottom: 8px;
            letter-spacing: 1px;
        }
        
        .receipt-footer .footer-message {
            font-weight: 700;
            color: #1A202C;
            margin-bottom: 8px;
            font-size: 11px;
        }
        
        .receipt-footer .powered-by {
            margin-top: 12px;
            font-size: 9px;
            color: #A0AEC0;
            letter-spacing: 0.5px;
        }
        
        /* Action Buttons */
        .action-buttons {
            max-width: 380px;
            margin: 20px auto 0;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            font-family: 'Inter', Arial, sans-serif;
            color: white;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
            color: white;
        }
        
        .btn-print {
            background: linear-gradient(135deg, #48BB78, #38A169);
            box-shadow: 0 4px 12px rgba(72, 187, 120, 0.3);
        }
        
        .btn-pos {
            background: linear-gradient(135deg, #6B46C1, #553C9A);
            box-shadow: 0 4px 12px rgba(107, 70, 193, 0.3);
        }
        
        .btn-sales {
            background: linear-gradient(135deg, #ED8936, #DD6B20);
            box-shadow: 0 4px 12px rgba(237, 137, 54, 0.3);
        }
        
        .btn-close {
            background: linear-gradient(135deg, #E53E3E, #C53030);
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.3);
        }
        
        /* Print Styles */
        @media print {
            body {
                background: white;
                padding: 0;
                font-size: 11px;
            }
            
            .receipt-container {
                box-shadow: none;
                border-radius: 0;
                max-width: 100%;
                padding: 8px;
            }
            
            .action-buttons {
                display: none !important;
            }
            
            @page {
                size: 80mm auto;
                margin: 3mm;
            }
        }
    </style>
</head>
<body>

<!-- Receipt -->
<div class="receipt-container">
    
    <!-- Header -->
    <div class="receipt-header">
        <h1><?php echo htmlspecialchars($company_name); ?></h1>
        
        <div class="company-info">
            <?php if (!empty($company_address)): ?>
                <div><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($company_address); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($company_phone)): ?>
                <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($company_phone); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($company_email)): ?>
                <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($company_email); ?></div>
            <?php endif; ?>
        </div>
        
        <div class="receipt-badge">
            <?php if (($sale['sale_type'] ?? 'cash') == 'credit'): ?>
                CREDIT RECEIPT
            <?php else: ?>
                OFFICIAL RECEIPT
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Sale Info -->
    <div class="sale-info">
        <div class="info-row">
            <span class="label">Invoice #:</span>
            <span class="value"><?php echo htmlspecialchars($sale['invoice_number']); ?></span>
        </div>
        <div class="info-row">
            <span class="label">Date:</span>
            <span class="value"><?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?></span>
        </div>
        <?php if (!empty($sale['store_name'])): ?>
        <div class="info-row">
            <span class="label">Store:</span>
            <span class="value"><?php echo htmlspecialchars($sale['store_name']); ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span class="label">Customer:</span>
            <span class="value"><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?></span>
        </div>
        <div class="info-row">
            <span class="label">Served By:</span>
            <span class="value"><?php echo htmlspecialchars($sale['served_by_name'] ?? 'N/A'); ?></span>
        </div>
    </div>
    
    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th>Item</th>
                <th class="right">Qty</th>
                <th class="right">Price</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($items) > 0): ?>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td class="product-name">
                        <?php echo htmlspecialchars($item['product_name']); ?>
                        <?php if (!empty($item['product_code'])): ?>
                            <span class="product-code"><?php echo htmlspecialchars($item['product_code']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="right"><?php echo number_format($item['quantity']); ?></td>
                    <td class="right"><?php echo number_format($item['unit_price'], 0); ?></td>
                    <td class="right"><?php echo number_format($item['total_price'], 0); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="text-align: center; padding: 20px; color: #A0AEC0;">
                        No items found
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <!-- Totals -->
    <div class="totals-section">
        <div class="total-row">
            <span class="label">Subtotal</span>
            <span class="value">UGX <?php echo number_format($sale['subtotal'], 0); ?></span>
        </div>
        
        <?php if (($sale['tax_amount'] ?? 0) > 0): ?>
        <div class="total-row">
            <span class="label">Tax</span>
            <span class="value">UGX <?php echo number_format($sale['tax_amount'], 0); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if (($sale['discount_amount'] ?? 0) > 0): ?>
        <div class="total-row">
            <span class="label">Discount</span>
            <span class="value" style="color: #38A169;">- UGX <?php echo number_format($sale['discount_amount'], 0); ?></span>
        </div>
        <?php endif; ?>
        
        <div class="total-row grand-total">
            <span class="label">TOTAL</span>
            <span class="value">UGX <?php echo number_format($sale['total_amount'], 0); ?></span>
        </div>
        
        <div class="total-row">
            <span class="label">Amount Paid</span>
            <span class="value" style="color: #2F855A;">UGX <?php echo number_format($sale['amount_paid'] ?? 0, 0); ?></span>
        </div>
        
        <?php if (($sale['amount_due'] ?? 0) > 0): ?>
        <div class="total-row">
            <span class="label">Amount Due</span>
            <span class="value" style="color: #C53030;">UGX <?php echo number_format($sale['amount_due'], 0); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($change > 0): ?>
        <div class="total-row change-row">
            <span class="label"><i class="fas fa-hand-holding-usd"></i> Change</span>
            <span class="value">UGX <?php echo number_format($change, 0); ?></span>
        </div>
        <?php endif; ?>
        
        <div class="total-row" style="margin-top: 8px;">
            <span class="label">Payment Method</span>
            <span class="value"><?php echo ucwords(str_replace('_', ' ', $sale['payment_method'] ?? 'Cash')); ?></span>
        </div>
        
        <div class="total-row">
            <span class="label">Payment Status</span>
            <span class="value">
                <?php
                $status = $sale['payment_status'] ?? 'paid';
                $status_label = ucfirst($status);
                ?>
                <span class="payment-badge <?php echo $status; ?>"><?php echo $status_label; ?></span>
            </span>
        </div>
        
        <?php if (!empty($sale['transaction_id'])): ?>
        <div class="total-row">
            <span class="label">Transaction ID</span>
            <span class="value"><?php echo htmlspecialchars($sale['transaction_id']); ?></span>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Barcode -->
    <div class="barcode-container">
        <svg id="barcode"></svg>
    </div>
    
    <!-- Footer -->
    <div class="receipt-footer">
        <div class="thank-you">★ THANK YOU ★</div>
        
        <?php if (!empty($receipt_footer)): ?>
            <div class="footer-message"><?php echo htmlspecialchars($receipt_footer); ?></div>
        <?php endif; ?>
        
        <div>Please keep this receipt for your records</div>
        <div>Visit us again</div>
        
        <div class="powered-by">
            Powered by <?php echo APP_NAME; ?>
        </div>
    </div>
</div>

<!-- Action Buttons -->
<div class="action-buttons">
    <button class="action-btn btn-print" onclick="window.print()">
        <i class="fas fa-print"></i>
        Print
    </button>
    
    <a href="pos.php" class="action-btn btn-pos">
        <i class="fas fa-cash-register"></i>
        New Sale
    </a>
    
    <a href="my-sales.php" class="action-btn btn-sales">
        <i class="fas fa-receipt"></i>
        My Sales
    </a>
    
    <button class="action-btn btn-close" onclick="window.close()">
        <i class="fas fa-times"></i>
        Close
    </button>
</div>

<!-- Barcode Generator -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Generate barcode
        try {
            JsBarcode("#barcode", "<?php echo htmlspecialchars($sale['invoice_number']); ?>", {
                format: "CODE128",
                displayValue: true,
                fontSize: 12,
                height: 45,
                width: 1.8,
                margin: 5,
                font: "monospace"
            });
        } catch(e) {
            console.error('Barcode error:', e);
        }
        
        // Auto-print after 800ms (uncomment if you want auto-print)
        // setTimeout(function() { window.print(); }, 800);
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+P or P = print
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            window.print();
        } else if (e.key === 'p' && !e.ctrlKey && !e.metaKey) {
            window.print();
        }
        // N = new sale
        else if (e.key === 'n') {
            window.location.href = 'pos.php';
        }
        // Esc = close
        else if (e.key === 'Escape') {
            window.close();
        }
    });
</script>

</body>
</html>