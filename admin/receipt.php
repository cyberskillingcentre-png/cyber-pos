<?php
require_once '../config/config.php';
require_login();

if (is_admin() === false && basename($_SERVER['PHP_SELF']) !== 'receipt.php') {
    // Continue for employee version
}

$page_title = "Receipt";
$sale_id = intval($_GET['id'] ?? 0);

if ($sale_id <= 0) {
    die('Invalid sale ID');
}

// ============================================
// FETCH SALE
// ============================================
try {
    $stmt = $conn->prepare("
        SELECT s.*, 
               st.store_name, 
               st.phone as store_phone,
               st.location as store_location,
               c.customer_name,
               c.phone as customer_phone,
               c.email as customer_email,
               u.full_name as served_by_name
        FROM sales s
        LEFT JOIN stores st ON s.store_id = st.store_id
        LEFT JOIN customers c ON s.customer_id = c.customer_id
        LEFT JOIN users u ON s.served_by = u.user_id
        WHERE s.sale_id = ?
    ");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sale) die('Sale not found');
} catch(PDOException $e) {
    die('Error: ' . $e->getMessage());
}

// ============================================
// FETCH SALE ITEMS
// ============================================
$items = [];
try {
    $stmt = $conn->prepare("
        SELECT si.*, p.product_name, p.product_code
        FROM sale_items si
        JOIN products p ON si.product_id = p.product_id
        WHERE si.sale_id = ?
        ORDER BY si.sale_item_id ASC
    ");
    $stmt->execute([$sale_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $items = [];
}

// ============================================
// FETCH ORGANIZATION DETAILS FROM MASTER DB
// ============================================
$org = [
    'org_name' => $_SESSION['org_name'] ?? APP_NAME,
    'address'  => $_SESSION['org_address'] ?? '',
    'phone'    => $_SESSION['org_phone'] ?? '',
    'email'    => $_SESSION['org_email'] ?? '',
    'tin'      => $_SESSION['org_tin'] ?? '',
    'website'  => $_SESSION['org_website'] ?? '',
    'logo'     => $_SESSION['org_logo'] ?? ''
];

// Fallback: If session data is missing, query master DB
if (empty($org['address']) && !empty($_SESSION['org_id'])) {
    try {
        $master = get_master_connection();
        $stmt = $master->prepare("
            SELECT org_name, address, phone, email, 
                   " . (in_array('tin', ['tin']) ? 'tin' : "'' AS tin") . ",
                   " . (in_array('website', ['website']) ? 'website' : "'' AS website") . ",
                   " . (in_array('logo', ['logo']) ? 'logo' : "'' AS logo") . "
            FROM organizations 
            WHERE org_id = ?
        ");
        $stmt->execute([$_SESSION['org_id']]);
        $db_org = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($db_org) {
            $org['org_name'] = $db_org['org_name'] ?? $org['org_name'];
            $org['address']  = $db_org['address'] ?? '';
            $org['phone']    = $db_org['phone'] ?? '';
            $org['email']    = $db_org['email'] ?? '';
            $org['tin']      = $db_org['tin'] ?? '';
            $org['website']  = $db_org['website'] ?? '';
            $org['logo']     = $db_org['logo'] ?? '';
        }
    } catch(Exception $e) {
        error_log("Could not fetch org details: " . $e->getMessage());
    }
}

// Fallback: use store info if org phone/address empty
if (empty($org['phone']) && !empty($sale['store_phone'])) {
    $org['phone'] = $sale['store_phone'];
}
if (empty($org['address']) && !empty($sale['store_location'])) {
    $org['address'] = $sale['store_location'];
}

// ============================================
// FETCH RECEIPT FOOTER SETTING
// ============================================
$receipt_footer = $_SESSION['receipt_footer'] ?? null;

if (empty($receipt_footer)) {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'receipt_footer'");
        $stmt->execute();
        $receipt_footer = $stmt->fetchColumn() ?: 'Thank you for your business!';
    } catch(PDOException $e) {
        $receipt_footer = 'Thank you for your business!';
    }
}

// Calculate change
$change = ($sale['amount_paid'] ?? 0) - ($sale['total_amount'] ?? 0);

// Auto-print detection
$is_autoprint = isset($_GET['print_only']) || isset($_GET['autoprint']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo htmlspecialchars($sale['invoice_number']); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: 'Courier New', Courier, monospace;
            background: #f0f0f0;
            padding: 20px;
            font-size: 12px;
            color: #000;
            line-height: 1.4;
        }
        
        .receipt {
            width: 80mm;
            margin: 0 auto;
            background: #fff;
            padding: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            border-radius: 4px;
        }
        
        /* ============================================
           HEADER
           ============================================ */
        .receipt-header {
            text-align: center;
            padding-bottom: 10px;
            border-bottom: 2px dashed #000;
            margin-bottom: 10px;
        }
        
        .org-logo {
            max-width: 140px;
            max-height: 60px;
            margin: 0 auto 6px;
            display: block;
            object-fit: contain;
        }
        
        .org-name {
            font-size: 15px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
            line-height: 1.2;
        }
        
        .org-details {
            font-size: 10px;
            line-height: 1.5;
            color: #333;
        }
        
        .org-details div {
            margin-bottom: 1px;
        }
        
        .org-tin {
            font-weight: 700;
            margin-top: 3px;
            padding-top: 3px;
            border-top: 1px dotted #999;
            display: inline-block;
        }
        
        .receipt-type {
            display: inline-block;
            padding: 2px 10px;
            font-size: 9px;
            font-weight: 700;
            border: 1px solid #000;
            border-radius: 10px;
            margin-top: 6px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        
        .receipt-type.credit {
            background: #000;
            color: #fff;
        }
        
        /* ============================================
           SALE INFO
           ============================================ */
        .sale-info {
            margin-bottom: 10px;
            font-size: 11px;
            padding-bottom: 8px;
            border-bottom: 1px dotted #999;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            padding: 1px 0;
        }
        
        .info-row .label {
            font-weight: 700;
        }
        
        .info-row .value {
            text-align: right;
            word-break: break-word;
            max-width: 60%;
        }
        
        /* ============================================
           ITEMS TABLE
           ============================================ */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            font-size: 10px;
        }
        
        .items-table thead th {
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 4px 2px;
            text-align: left;
            font-weight: 700;
            font-size: 9px;
            text-transform: uppercase;
        }
        
        .items-table thead th.right { text-align: right; }
        .items-table thead th.center { text-align: center; }
        
        .items-table tbody td {
            padding: 4px 2px;
            border-bottom: 1px dotted #ccc;
            vertical-align: top;
            font-size: 10px;
        }
        
        .items-table tbody td.right { text-align: right; }
        .items-table tbody td.center { text-align: center; }
        
        .product-name {
            font-weight: 700;
            display: block;
        }
        
        .product-code {
            font-size: 8px;
            color: #666;
            display: block;
        }
        
        /* ============================================
           TOTALS
           ============================================ */
        .totals {
            border-top: 2px dashed #000;
            padding-top: 8px;
            margin-bottom: 8px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
            font-size: 11px;
        }
        
        .total-row.grand {
            border-top: 1px solid #000;
            border-bottom: 2px solid #000;
            padding: 6px 0;
            margin-top: 4px;
            font-size: 13px;
            font-weight: 900;
        }
        
        .total-row.paid { color: #059669; }
        .total-row.due { color: #dc2626; font-weight: 700; }
        
        .total-row.change {
            color: #059669;
            background: #ecfdf5;
            padding: 4px 6px;
            border-radius: 3px;
            margin-top: 4px;
            font-weight: 700;
            font-size: 12px;
        }
        
        /* ============================================
           PAYMENT METHOD
           ============================================ */
        .payment-info {
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 3px;
            padding: 5px 8px;
            text-align: center;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        
        /* ============================================
           BARCODE
           ============================================ */
        .barcode-wrap {
            text-align: center;
            padding: 6px 0;
            border-top: 1px solid #ddd;
            border-bottom: 1px solid #ddd;
            margin-bottom: 8px;
        }
        
        .barcode-wrap svg {
            max-width: 100%;
        }
        
        /* ============================================
           FOOTER
           ============================================ */
        .receipt-footer {
            text-align: center;
            font-size: 10px;
            line-height: 1.5;
            padding-top: 8px;
            border-top: 2px dashed #000;
        }
        
        .thank-you {
            font-size: 13px;
            font-weight: 900;
            margin-bottom: 5px;
            letter-spacing: 1px;
        }
        
        .footer-message {
            font-weight: 700;
            margin-bottom: 6px;
            font-size: 10px;
            padding: 4px 6px;
            background: #f9f9f9;
            border-radius: 3px;
        }
        
        .powered-by {
            margin-top: 8px;
            font-size: 8px;
            color: #999;
            padding-top: 5px;
            border-top: 1px solid #eee;
        }
        
        /* ============================================
           ACTION BUTTONS
           ============================================ */
        .action-buttons {
            max-width: 80mm;
            margin: 20px auto 0;
            display: flex;
            gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 10px 18px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            color: #fff;
            font-family: Arial, sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }
        
        .action-btn:hover { transform: translateY(-2px); }
        
        .btn-print { background: #10b981; }
        .btn-print:hover { background: #059669; }
        
        .btn-pos { background: #6B46C1; }
        .btn-pos:hover { background: #553C9A; }
        
        .btn-close { background: #ef4444; }
        .btn-close:hover { background: #dc2626; }
        
        /* ============================================
           PRINT STYLES
           ============================================ */
        @media print {
            body {
                background: #fff;
                padding: 0;
                font-size: 11px;
            }
            
            .receipt {
                box-shadow: none;
                border-radius: 0;
                width: 100%;
                padding: 0;
                max-width: none;
            }
            
            .action-buttons {
                display: none !important;
            }
            
            @page {
                size: 80mm auto;
                margin: 2mm;
            }
        }
    </style>
</head>
<body>
    
    <div class="receipt">
        
        <!-- ============================================
             HEADER - ORGANIZATION
             ============================================ -->
        <div class="receipt-header">
            
            <?php if (!empty($org['logo']) && file_exists('../' . $org['logo'])): ?>
                <img src="../<?php echo htmlspecialchars($org['logo']); ?>" 
                     alt="Logo" class="org-logo">
            <?php endif; ?>
            
            <div class="org-name">
                <?php echo htmlspecialchars($org['org_name']); ?>
            </div>
            
            <div class="org-details">
                <?php if (!empty($org['address'])): ?>
                    <div><?php echo htmlspecialchars($org['address']); ?></div>
                <?php endif; ?>
                
                <?php if (!empty($org['phone'])): ?>
                    <div>Tel: <?php echo htmlspecialchars($org['phone']); ?></div>
                <?php endif; ?>
                
                <?php if (!empty($org['email'])): ?>
                    <div>Email: <?php echo htmlspecialchars($org['email']); ?></div>
                <?php endif; ?>
                
                <?php if (!empty($org['website'])): ?>
                    <div><?php echo htmlspecialchars($org['website']); ?></div>
                <?php endif; ?>
                
                <?php if (!empty($org['tin'])): ?>
                    <div class="org-tin">TIN: <?php echo htmlspecialchars($org['tin']); ?></div>
                <?php endif; ?>
            </div>
            
            <div class="receipt-type <?php echo (($sale['sale_type'] ?? 'cash') === 'credit') ? 'credit' : ''; ?>">
                <?php echo (($sale['sale_type'] ?? 'cash') === 'credit') ? 'CREDIT RECEIPT' : 'OFFICIAL RECEIPT'; ?>
            </div>
        </div>
        
        <!-- ============================================
             SALE INFO
             ============================================ -->
        <div class="sale-info">
            <div class="info-row">
                <span class="label">Invoice:</span>
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
            <?php if (!empty($sale['customer_phone'])): ?>
            <div class="info-row">
                <span class="label">Phone:</span>
                <span class="value"><?php echo htmlspecialchars($sale['customer_phone']); ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="label">Served By:</span>
                <span class="value"><?php echo htmlspecialchars($sale['served_by_name'] ?? 'N/A'); ?></span>
            </div>
        </div>
        
        <!-- ============================================
             ITEMS
             ============================================ -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="center">Qty</th>
                    <th class="right">Price</th>
                    <th class="right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($items) > 0): ?>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <span class="product-name"><?php echo htmlspecialchars($item['product_name']); ?></span>
                            <?php if (!empty($item['product_code'])): ?>
                                <span class="product-code"><?php echo htmlspecialchars($item['product_code']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="center"><?php echo intval($item['quantity']); ?></td>
                        <td class="right"><?php echo number_format(floatval($item['unit_price']), 0); ?></td>
                        <td class="right"><?php echo number_format(floatval($item['total_price']), 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align:center;padding:15px;">No items</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- ============================================
             TOTALS
             ============================================ -->
        <div class="totals">
            <div class="total-row">
                <span>Subtotal:</span>
                <span>UGX <?php echo number_format(floatval($sale['subtotal'] ?? 0), 0); ?></span>
            </div>
            
            <?php if (floatval($sale['tax_amount'] ?? 0) > 0): ?>
            <div class="total-row">
                <span>Tax:</span>
                <span>UGX <?php echo number_format(floatval($sale['tax_amount']), 0); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if (floatval($sale['discount_amount'] ?? 0) > 0): ?>
            <div class="total-row">
                <span>Discount:</span>
                <span>-UGX <?php echo number_format(floatval($sale['discount_amount']), 0); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="total-row grand">
                <span>TOTAL:</span>
                <span>UGX <?php echo number_format(floatval($sale['total_amount'] ?? 0), 0); ?></span>
            </div>
            
            <div class="total-row paid">
                <span>Paid:</span>
                <span>UGX <?php echo number_format(floatval($sale['amount_paid'] ?? 0), 0); ?></span>
            </div>
            
            <?php if (floatval($sale['amount_due'] ?? 0) > 0): ?>
            <div class="total-row due">
                <span>Due:</span>
                <span>UGX <?php echo number_format(floatval($sale['amount_due']), 0); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($change > 0): ?>
            <div class="total-row change">
                <span>CHANGE:</span>
                <span>UGX <?php echo number_format($change, 0); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- ============================================
             PAYMENT
             ============================================ -->
        <div class="payment-info">
            Payment: <?php echo strtoupper(str_replace('_', ' ', $sale['payment_method'] ?? 'Cash')); ?>
            <?php if (!empty($sale['transaction_id'])): ?>
                <br><span style="font-weight: 400; text-transform: none;">Ref: <?php echo htmlspecialchars($sale['transaction_id']); ?></span>
            <?php endif; ?>
        </div>
        
        <!-- ============================================
             BARCODE
             ============================================ -->
        <div class="barcode-wrap">
            <svg id="barcode"></svg>
        </div>
        
        <!-- ============================================
             FOOTER
             ============================================ -->
        <div class="receipt-footer">
            <div class="thank-you">★ THANK YOU ★</div>
            
            <?php if (!empty($receipt_footer)): ?>
                <div class="footer-message"><?php echo htmlspecialchars($receipt_footer); ?></div>
            <?php endif; ?>
            
            <div>Please keep this receipt for your records</div>
            <div>Visit us again!</div>
            
            <div class="powered-by">
                Powered by <?php echo APP_NAME; ?> &middot; <?php echo date('Y'); ?>
            </div>
        </div>
    </div>
    
    <!-- ============================================
         ACTION BUTTONS (hidden when auto-print)
         ============================================ -->
    <?php if (!$is_autoprint): ?>
    <div class="action-buttons">
        <button onclick="window.print()" class="action-btn btn-print">
            <i>🖨️</i> Print
        </button>
        <a href="pos.php" class="action-btn btn-pos">
            ← Back to POS
        </a>
        <button onclick="window.close()" class="action-btn btn-close">
            ✕ Close
        </button>
    </div>
    <?php endif; ?>
    
    <!-- ============================================
         BARCODE + AUTO-PRINT
         ============================================ -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script>
        // Generate barcode
        try {
            JsBarcode("#barcode", "<?php echo htmlspecialchars($sale['invoice_number']); ?>", {
                format: "CODE128",
                displayValue: true,
                fontSize: 11,
                height: 40,
                width: 1.5,
                margin: 4,
                font: "monospace"
            });
        } catch(e) {
            console.error('Barcode error:', e);
        }
        
        // Auto-print when called from POS
        const urlParams = new URLSearchParams(window.location.search);
        const shouldAutoPrint = urlParams.get('print_only') === '1' || urlParams.get('autoprint') === '1';
        
        if (shouldAutoPrint) {
            document.querySelectorAll('.action-buttons').forEach(el => {
                el.style.display = 'none';
            });
            
            window.addEventListener('load', function() {
                setTimeout(function() {
                    window.print();
                }, 400);
            });
        }
    </script>
    
</body>
</html>