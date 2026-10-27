<?php
require_once '../config/config.php';
require_login();

$sale_id = intval($_GET['id'] ?? 0);
$autoprint = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';

$stmt = $conn->prepare("
    SELECT s.*, st.store_name, st.phone as store_phone,
           c.customer_name, u.full_name as served_by_name,
           settings.setting_value as company_name
    FROM sales s
    LEFT JOIN stores st ON s.store_id = st.store_id
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    LEFT JOIN users u ON s.served_by = u.user_id
    LEFT JOIN system_settings settings ON settings.setting_key = 'company_name'
    WHERE s.sale_id = ?
");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch();
if (!$sale) die('Sale not found');

$stmt = $conn->prepare("
    SELECT si.*, p.product_name, p.product_code
    FROM sale_items si
    JOIN products p ON si.product_id = p.product_id
    WHERE si.sale_id = ?
");
$stmt->execute([$sale_id]);
$items = $stmt->fetchAll();

$extra = [];
try {
    $s = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_address','company_phone','company_email','receipt_footer')");
    $s->execute();
    foreach ($s->fetchAll() as $row) $extra[$row['setting_key']] = $row['setting_value'];
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt - <?php echo htmlspecialchars($sale['invoice_number']); ?></title>
<style>
    /* ============================================================
       THERMAL RECEIPT STYLES (Matching the screenshot precisely)
       ============================================================ */
    @page { 
        size: 80mm auto; 
        margin: 0; 
    }

    html, body {
        margin: 0;
        padding: 0;
        background: #fff;
        color: #000;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    body {
        width: 76mm; /* Fits comfortably on 80mm paper */
        margin: 0 auto;
        padding: 5mm 4mm;
        font-size: 11px;
    }

    /* Typography Utilities */
    .center      { text-align: center; }
    .right       { text-align: right; }
    .bold        { font-weight: 700; }
    .text-upper  { text-transform: uppercase; }
    .text-green  { color: #059669; }
    
    /* Header */
    .company-name { 
        font-size: 16px; 
        font-weight: 800; 
        letter-spacing: 0.5px;
        margin-bottom: 2px;
    }
    .header-info { 
        font-size: 10px; 
        line-height: 1.4; 
        color: #333;
    }
    
    /* "OFFICIAL RECEIPT" Badge */
    .receipt-badge {
        display: inline-block;
        border: 1.5px solid #000;
        border-radius: 20px;
        padding: 3px 14px;
        font-size: 9px;
        font-weight: 800;
        letter-spacing: 1px;
        margin: 8px 0 6px;
        text-transform: uppercase;
    }

    /* Dashed & Dotted Lines */
    .divider-dashed {
        border-top: 2px dashed #000;
        margin: 6px 0;
    }
    .divider-dotted {
        border-top: 1px dotted #999;
        margin: 5px 0;
    }

    /* Meta Information Block */
    .meta-grid {
        display: grid;
        grid-template-columns: 25% 75%;
        font-size: 11px;
        margin: 4px 0;
    }
    .meta-grid > div { padding: 1.5px 0; }
    .meta-label { font-weight: 700; }
    .meta-value { text-align: right; }

    /* Items Table */
    table.items {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
    }
    table.items th {
        text-align: left;
        font-weight: 800;
        font-size: 9px;
        border-bottom: 1px solid #000;
        padding-bottom: 3px;
        text-transform: uppercase;
    }
    table.items td {
        padding: 6px 0 2px;
        vertical-align: top;
    }
    .item-name-row td {
        padding-top: 6px;
    }
    .item-details-row td {
        padding-top: 0;
        padding-bottom: 6px;
    }
    .item-code {
        font-size: 8.5px;
        color: #555;
        text-transform: uppercase;
    }

    /* Totals Block */
    .totals-grid {
        display: grid;
        grid-template-columns: 50% 50%;
        font-size: 11px;
        margin-bottom: 4px;
    }
    .totals-grid > div { padding: 2px 0; }
    .total-label { font-weight: 600; color: #333; }
    .total-value { text-align: right; font-weight: 600; }
    
    .grand-total {
        display: flex;
        justify-content: space-between;
        font-size: 14px;
        font-weight: 800;
        padding: 4px 0;
        border-top: 1px solid #000;
        border-bottom: 1px solid #000;
        margin: 6px 0;
    }

    /* Highlighted Boxes (Payment & Change) */
    .highlight-box {
        border-radius: 4px;
        padding: 5px 8px;
        margin: 4px 0;
        display: flex;
        justify-content: space-between;
        font-weight: 800;
        font-size: 11px;
    }
    .change-box {
        background-color: #ECFDF5; /* light green */
        border: 1px solid #D1FAE5;
        color: #059669; /* green text */
    }
    .payment-box {
        background-color: #F9FAFB; /* light gray */
        border: 1px solid #E5E7EB;
        color: #111;
        justify-content: center;
    }

    /* Barcode & Footer */
    .barcode { 
        text-align: center; 
        margin: 10px 0 2px; 
    }
    .barcode svg { 
        max-width: 100%; 
    }
    .footer-msg {
        text-align: center;
        font-size: 8px;
        color: #777;
        margin-top: 4px;
        letter-spacing: 0.5px;
    }

    /* Screen-only Toolbar */
    .toolbar { text-align: center; margin: 15px 0; }
    .toolbar button {
        padding: 10px 20px;
        font-size: 14px;
        cursor: pointer;
        margin: 0 5px;
        border-radius: 4px;
        border: 1px solid #ccc;
        background: #f8f9fa;
    }
    .toolbar button:hover { background: #e9ecef; }

    @media print {
        .no-print { display: none !important; }
        body { padding: 0; width: 80mm; }
        @page { margin: 3mm 2mm; }
    }
</style>
</head>
<body>

<div class="toolbar no-print">
    <button onclick="window.print()">🖨 Print</button>
    <button onclick="window.close()">✕ Close</button>
</div>

<!-- ==================== RECEIPT START ==================== -->

<div class="center">
    <div class="company-name text-upper"><?php echo htmlspecialchars($sale['company_name'] ?? 'TESTORG'); ?></div>
    
    <div class="header-info">
        <?php if (!empty($extra['company_address'])): ?>
            <?php echo htmlspecialchars($extra['company_address']); ?><br>
        <?php else: ?>
            Jinja<br>
        <?php endif; ?>
        
        <?php if (!empty($sale['store_phone'])): ?>
            Tel: <?php echo htmlspecialchars($sale['store_phone']); ?><br>
        <?php elseif (!empty($extra['company_phone'])): ?>
            Tel: <?php echo htmlspecialchars($extra['company_phone']); ?><br>
        <?php endif; ?>

        <?php if (!empty($extra['company_email'])): ?>
            Email: <?php echo htmlspecialchars($extra['company_email']); ?>
        <?php endif; ?>
    </div>
    
    <div class="receipt-badge">Official Receipt</div>
</div>

<div class="divider-dashed"></div>

<!-- Meta Details -->
<div class="meta-grid">
    <div class="meta-label">Invoice:</div>
    <div class="meta-value"><?php echo htmlspecialchars($sale['invoice_number']); ?></div>
    
    <div class="meta-label">Date:</div>
    <div class="meta-value"><?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?></div>
    
    <div class="meta-label">Store:</div>
    <div class="meta-value"><?php echo htmlspecialchars($sale['store_name'] ?? 'Main Store'); ?></div>
    
    <div class="meta-label">Customer:</div>
    <div class="meta-value"><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?></div>
    
    <div class="meta-label">Served By:</div>
    <div class="meta-value"><?php echo htmlspecialchars($sale['served_by_name'] ?? 'Administrator'); ?></div>
</div>

<div class="divider-dotted"></div>

<!-- Items -->
<table class="items">
    <thead>
        <tr>
            <th>Item</th>
            <th class="center" style="width: 10%;">Qty</th>
            <th class="right" style="width: 20%;">Price</th>
            <th class="right" style="width: 25%;">Total</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr class="item-name-row">
            <td colspan="4" class="bold text-upper"><?php echo htmlspecialchars($item['product_name']); ?></td>
        </tr>
        <tr class="item-details-row">
            <td class="item-code"><?php echo htmlspecialchars($item['product_code']); ?></td>
            <td class="center"><?php echo (int)$item['quantity']; ?></td>
            <td class="right"><?php echo number_format($item['unit_price'], 0); ?></td>
            <td class="right"><?php echo number_format($item['total_price'], 0); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div class="divider-dashed"></div>

<!-- Totals -->
<div class="totals-grid">
    <div class="total-label">Subtotal:</div>
    <div class="total-value">UGX <?php echo number_format($sale['subtotal'], 0); ?></div>
    
    <?php if (!empty($sale['tax_amount']) && $sale['tax_amount'] > 0): ?>
    <div class="total-label">Tax:</div>
    <div class="total-value">UGX <?php echo number_format($sale['tax_amount'], 0); ?></div>
    <?php endif; ?>
    
    <?php if (!empty($sale['discount_amount']) && $sale['discount_amount'] > 0): ?>
    <div class="total-label">Discount:</div>
    <div class="total-value">- UGX <?php echo number_format($sale['discount_amount'], 0); ?></div>
    <?php endif; ?>
</div>

<div class="grand-total">
    <span>TOTAL:</span>
    <span>UGX <?php echo number_format($sale['total_amount'], 0); ?></span>
</div>

<!-- Payment Details -->
<div class="totals-grid" style="margin-bottom: 6px;">
    <div class="total-label">Paid:</div>
    <div class="total-value">UGX <?php echo number_format($sale['amount_paid'], 0); ?></div>
</div>

<?php 
$change = $sale['amount_paid'] - $sale['total_amount'];
if ($change > 0): 
?>
<div class="highlight-box change-box">
    <span>CHANGE:</span>
    <span>UGX <?php echo number_format($change, 0); ?></span>
</div>
<?php elseif (!empty($sale['amount_due']) && $sale['amount_due'] > 0): ?>
<div class="highlight-box" style="background:#FEE2E2; border:1px solid #FECACA; color:#DC2626;">
    <span>BALANCE DUE:</span>
    <span>UGX <?php echo number_format($sale['amount_due'], 0); ?></span>
</div>
<?php endif; ?>

<div class="highlight-box payment-box">
    PAYMENT: <?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', $sale['payment_method'] ?? 'CASH'))); ?>
</div>

<!-- Barcode -->
<div class="barcode">
    <svg id="barcode"></svg>
</div>
<div class="footer-msg text-upper">
    <?php echo htmlspecialchars($sale['invoice_number']); ?>
</div>

<!-- ==================== RECEIPT END ==================== -->

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script>
function notifyParent(action) {
    try {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({ source: 'receipt', action: action }, '*');
        }
    } catch (e) {}
}

// Generate Barcode
JsBarcode("#barcode", "<?php echo htmlspecialchars($sale['invoice_number']); ?>", {
    format: "CODE128", 
    displayValue: false, 
    height: 35, 
    width: 1.5, 
    margin: 0
});

<?php if ($autoprint): ?>
window.addEventListener('load', function () {
    setTimeout(function () {
        window.print();
        notifyParent('printed');
    }, 400);
});
<?php endif; ?>

window.addEventListener('afterprint', function () { notifyParent('closed'); });
</script>
</body>
</html>