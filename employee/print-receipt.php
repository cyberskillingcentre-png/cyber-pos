<?php
require_once '../config/config.php';
require_login();
$page_title = "Print Receipt";
$sale_id = intval($_GET['id']);

// Get organization details from SESSION (set during login)
$organization = [
    'org_name' => $_SESSION['org_name'] ?? 'POS System',
    'address' => $_SESSION['org_address'] ?? '',
    'phone' => $_SESSION['org_phone'] ?? '',
    'email' => $_SESSION['org_email'] ?? '',
    'tin' => $_SESSION['org_tin'] ?? '',
    'website' => $_SESSION['org_website'] ?? '',
    'logo_path' => $_SESSION['org_logo'] ?? ''
];

// Get sale details
$stmt = $conn->prepare("
    SELECT s.*, 
           st.store_name, 
           c.customer_name, 
           u.full_name as served_by_name 
    FROM sales s 
    LEFT JOIN stores st ON s.store_id = st.store_id 
    LEFT JOIN customers c ON s.customer_id = c.customer_id 
    LEFT JOIN users u ON s.served_by = u.user_id 
    WHERE s.sale_id = ?
");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch();

if (!$sale) {
    die('Sale not found');
}

// Get sale items
$stmt = $conn->prepare("
    SELECT si.*, p.product_name, p.product_code 
    FROM sale_items si 
    JOIN products p ON si.product_id = p.product_id 
    WHERE si.sale_id = ?
");
$stmt->execute([$sale_id]);
$items = $stmt->fetchAll();

// Calculate change
$change = $sale['amount_paid'] - $sale['total_amount'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt - <?php echo $sale['invoice_number']; ?></title>
    <style>
        @media print { 
            .no-print { display: none; }
            @page { margin: 0; }
            body { margin: 0; }
        }
        
        body { 
            font-family: 'Courier New', monospace; 
            font-size: 12px;
            margin: 0;
            padding: 0;
        }
        
        .receipt { 
            width: 80mm; 
            margin: 0 auto; 
            padding: 10px;
            background: white;
        }
        
        .header { 
            text-align: center; 
            margin-bottom: 15px; 
            border-bottom: 2px dashed #000; 
            padding-bottom: 10px; 
        }
        
        .header .logo {
            max-width: 150px;
            max-height: 80px;
            margin-bottom: 10px;
        }
        
        .header h2 { 
            margin: 5px 0; 
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .header .org-details {
            font-size: 11px;
            line-height: 1.6;
            margin-top: 8px;
        }
        
        .info { 
            margin: 10px 0; 
            font-size: 11px;
            line-height: 1.6;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse;
            margin: 10px 0;
        }
        
        th { 
            padding: 5px 2px; 
            text-align: left;
            border-bottom: 1px solid #000;
            font-size: 11px;
            font-weight: bold;
        }
        
        td { 
            padding: 5px 2px; 
            text-align: left;
            font-size: 11px;
        }
        
        td.right {
            text-align: right;
        }
        
        .total-section { 
            border-top: 2px dashed #000; 
            margin-top: 10px; 
            padding-top: 10px; 
        }
        
        .total-section table {
            margin: 0;
        }
        
        .total-section tr {
            line-height: 1.8;
        }
        
        .total-row {
            font-size: 13px;
            font-weight: bold;
        }
        
        .footer { 
            text-align: center; 
            margin-top: 15px; 
            border-top: 2px dashed #000; 
            padding-top: 10px;
            font-size: 11px;
        }
        
        .barcode { 
            text-align: center; 
            margin: 15px 0; 
        }
        
        .payment-method {
            margin: 10px 0;
            padding: 8px;
            background: #f5f5f5;
            border-radius: 3px;
            text-align: center;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="receipt">
        <!-- Organization Header -->
        <div class="header">
            <?php if (!empty($organization['logo_path']) && file_exists('../' . $organization['logo_path'])): ?>
                <img src="../<?php echo htmlspecialchars($organization['logo_path']); ?>" 
                     alt="Logo" class="logo">
            <?php endif; ?>
            
            <h2><?php echo htmlspecialchars($organization['org_name']); ?></h2>
            
            <div class="org-details">
                <?php if (!empty($organization['address'])): ?>
                    <?php echo htmlspecialchars($organization['address']); ?><br>
                <?php endif; ?>
                
                <?php if (!empty($organization['phone'])): ?>
                    <strong>Phone:</strong> <?php echo htmlspecialchars($organization['phone']); ?><br>
                <?php endif; ?>
                
                <?php if (!empty($organization['email'])): ?>
                    <strong>Email:</strong> <?php echo htmlspecialchars($organization['email']); ?><br>
                <?php endif; ?>
                
                <?php if (!empty($organization['tin'])): ?>
                    <strong>TIN:</strong> <?php echo htmlspecialchars($organization['tin']); ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Sale Information -->
        <div class="info">
            <div class="info-row">
                <strong>Invoice #:</strong> 
                <span><?php echo htmlspecialchars($sale['invoice_number']); ?></span>
            </div>
            <div class="info-row">
                <strong>Date:</strong> 
                <span><?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?></span>
            </div>
            <div class="info-row">
                <strong>Customer:</strong> 
                <span><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer'); ?></span>
            </div>
            <div class="info-row">
                <strong>Served By:</strong> 
                <span><?php echo htmlspecialchars($sale['served_by_name']); ?></span>
            </div>
        </div>
        
        <!-- Items Table -->
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="right">Qty</th>
                    <th class="right">Price</th>
                    <th class="right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars($item['product_name']); ?>
                        <?php if (!empty($item['product_code'])): ?>
                            <br><small style="color: #666;"><?php echo htmlspecialchars($item['product_code']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="right"><?php echo number_format($item['quantity']); ?></td>
                    <td class="right"><?php echo number_format($item['unit_price'], 0); ?></td>
                    <td class="right"><?php echo number_format($item['total_price'], 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Totals Section -->
        <div class="total-section">
            <table>
                <tr>
                    <td>Subtotal:</td>
                    <td class="right"><strong>UGX <?php echo number_format($sale['subtotal'], 0); ?></strong></td>
                </tr>
                
                <?php if ($sale['tax_amount'] > 0): ?>
                <tr>
                    <td>Tax:</td>
                    <td class="right"><strong>UGX <?php echo number_format($sale['tax_amount'], 0); ?></strong></td>
                </tr>
                <?php endif; ?>
                
                <?php if ($sale['discount_amount'] > 0): ?>
                <tr>
                    <td>Discount:</td>
                    <td class="right"><strong>- UGX <?php echo number_format($sale['discount_amount'], 0); ?></strong></td>
                </tr>
                <?php endif; ?>
                
                <tr class="total-row">
                    <td><strong>TOTAL:</strong></td>
                    <td class="right"><strong>UGX <?php echo number_format($sale['total_amount'], 0); ?></strong></td>
                </tr>
                
                <tr>
                    <td>Amount Paid:</td>
                    <td class="right">UGX <?php echo number_format($sale['amount_paid'], 0); ?></td>
                </tr>
                
                <?php if ($sale['amount_due'] > 0): ?>
                <tr>
                    <td><strong>Amount Due:</strong></td>
                    <td class="right" style="color: #d9534f;"><strong>UGX <?php echo number_format($sale['amount_due'], 0); ?></strong></td>
                </tr>
                <?php endif; ?>
                
                <?php if ($change > 0): ?>
                <tr>
                    <td>Change:</td>
                    <td class="right">UGX <?php echo number_format($change, 0); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        
        <!-- Payment Method -->
        <div class="payment-method">
            Payment: <?php echo strtoupper(str_replace('_', ' ', $sale['payment_method'])); ?>
            <?php if ($sale['payment_method'] == 'mobile_money' && !empty($sale['transaction_id'])): ?>
                <br><small>Ref: <?php echo htmlspecialchars($sale['transaction_id']); ?></small>
            <?php endif; ?>
        </div>
        
        <!-- Barcode -->
        <div class="barcode">
            <svg id="barcode"></svg>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p><strong>Thank you for your business!</strong></p>
            <p>Goods once sold cannot be returned</p>
            <p>Please keep this receipt for your records</p>
            
            <?php if (!empty($organization['website'])): ?>
                <p style="margin-top: 10px;">
                    Visit us: <?php echo htmlspecialchars($organization['website']); ?>
                </p>
            <?php endif; ?>
            
            <p style="margin-top: 10px; font-size: 10px; color: #999;">
                Powered by <?php echo APP_NAME; ?>
            </p>
        </div>
    </div>
    
    <!-- Print Buttons -->
    <div class="no-print" style="text-align:center; margin:20px;">
        <button onclick="window.print()" 
                style="padding: 12px 24px; font-size: 16px; cursor: pointer; 
                       background: #5cb85c; color: white; border: none; border-radius: 4px;">
            <i class="fas fa-print"></i> Print Receipt
        </button>
        
        <button onclick="window.location.href='pos.php'"
                style="padding: 12px 24px; font-size: 16px; cursor: pointer; 
                       background: #0275d8; color: white; border: none; border-radius: 4px; margin-left: 10px;">
            <i class="fas fa-cash-register"></i> Back to POS
        </button>
        
        <button onclick="window.location.href='my-sales.php'"
                style="padding: 12px 24px; font-size: 16px; cursor: pointer; 
                       background: #f0ad4e; color: white; border: none; border-radius: 4px; margin-left: 10px;">
            <i class="fas fa-list"></i> View Sales
        </button>
        
        <button onclick="window.close()" 
                style="padding: 12px 24px; font-size: 16px; cursor: pointer; 
                       background: #d9534f; color: white; border: none; border-radius: 4px; margin-left: 10px;">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
    
    <!-- Barcode Generator -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script>
        // Generate barcode
        JsBarcode("#barcode", "<?php echo $sale['invoice_number']; ?>", {
            format: "CODE128",
            displayValue: true,
            fontSize: 12,
            height: 50,
            width: 2,
            margin: 5
        });
        
        // Auto-print after 1 second
        setTimeout(function() { 
            window.print(); 
        }, 1000);
    </script>
</body>
</html>