<?php
require_once '../config/config.php';
require_login();
$page_title = "Print Receipt";
$sale_id = intval($_GET['id']);

$stmt = $conn->prepare("SELECT s.*, st.store_name, st.phone as store_phone, c.customer_name, u.full_name as served_by_name, settings.setting_value as company_name FROM sales s LEFT JOIN stores st ON s.store_id = st.store_id LEFT JOIN customers c ON s.customer_id = c.customer_id LEFT JOIN users u ON s.served_by = u.user_id LEFT JOIN system_settings settings ON settings.setting_key = 'company_name' WHERE s.sale_id = ?");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch();

if (!$sale) {
    die('Sale not found');
}

$stmt = $conn->prepare("SELECT si.*, p.product_name, p.product_code FROM sale_items si JOIN products p ON si.product_id = p.product_id WHERE si.sale_id = ?");
$stmt->execute([$sale_id]);
$items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt - <?php echo $sale['invoice_number']; ?></title>
    <style>
        @media print { .no-print { display: none; } }
        body { font-family: Arial, sans-serif; font-size: 12px; }
        .receipt { width: 80mm; margin: 0 auto; padding: 10px; }
        .header { text-align: center; margin-bottom: 10px; border-bottom: 2px dashed #000; padding-bottom: 10px; }
        .header h2 { margin: 5px 0; }
        .info { margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px; text-align: left; }
        .total-section { border-top: 2px dashed #000; margin-top: 10px; padding-top: 10px; }
        .footer { text-align: center; margin-top: 20px; border-top: 2px dashed #000; padding-top: 10px; }
        .barcode { text-align: center; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="header">
            <h2><?php echo $sale['company_name'] ?? 'Electrical Shop'; ?></h2>
            <div><?php echo $sale['store_name']; ?></div>
            <div><?php echo $sale['store_phone']; ?></div>
        </div>
        <div class="info">
            <strong>Invoice:</strong> <?php echo $sale['invoice_number']; ?><br>
            <strong>Date:</strong> <?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?><br>
            <strong>Customer:</strong> <?php echo $sale['customer_name'] ?? 'Walk-in'; ?><br>
            <strong>Served By:</strong> <?php echo $sale['served_by_name']; ?>
        </div>
        <table>
            <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo $item['product_name']; ?></td>
                    <td><?php echo $item['quantity']; ?></td>
                    <td><?php echo number_format($item['unit_price'], 0); ?></td>
                    <td><?php echo number_format($item['total_price'], 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="total-section">
            <table>
                <tr><td>Subtotal:</td><td align="right"><strong>UGX <?php echo number_format($sale['subtotal'], 0); ?></strong></td></tr>
                <tr><td>Tax:</td><td align="right"><strong>UGX <?php echo number_format($sale['tax_amount'], 0); ?></strong></td></tr>
                <?php if ($sale['discount_amount'] > 0): ?>
                <tr><td>Discount:</td><td align="right"><strong>- UGX <?php echo number_format($sale['discount_amount'], 0); ?></strong></td></tr>
                <?php endif; ?>
                <tr><td><strong>TOTAL:</strong></td><td align="right"><strong>UGX <?php echo number_format($sale['total_amount'], 0); ?></strong></td></tr>
                <tr><td>Paid:</td><td align="right">UGX <?php echo number_format($sale['amount_paid'], 0); ?></td></tr>
                <?php if ($sale['amount_due'] > 0): ?>
                <tr><td>Due:</td><td align="right" style="color:red;">UGX <?php echo number_format($sale['amount_due'], 0); ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
        <div class="barcode">
            <svg id="barcode"></svg>
        </div>
        <div class="footer">
            <p>Thank you for your business!</p>
            <p>Visit us again</p>
        </div>
    </div>
    <div class="no-print" style="text-align:center; margin:20px;">
        <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">Print Receipt</button>
        <button onclick="window.close()" style="padding: 10px 20px; font-size: 16px; cursor: pointer; margin-left: 10px;">Close</button>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script>
        JsBarcode("#barcode", "<?php echo $sale['invoice_number']; ?>", {format: "CODE128", displayValue: true, fontSize: 14, height: 40});
        setTimeout(function(){ window.print(); }, 500);
    </script>
</body>
</html>