<?php
require_once '../config/config.php';
require_login();
$page_title = "Print Receipt";

$sale_id = intval($_GET['id']);

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

if (!$sale) {
    die('Sale not found');
}

$stmt = $conn->prepare("
    SELECT si.*, p.product_name, p.product_code
    FROM sale_items si
    JOIN products p ON si.product_id = p.product_id
    WHERE si.sale_id = ?
");
$stmt->execute([$sale_id]);
$items = $stmt->fetchAll();

// Optional: grab a couple more settings if they exist in your system_settings table
$extra = [];
try {
    $s = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_address','company_phone','receipt_footer')");
    $s->execute();
    foreach ($s->fetchAll() as $row) {
        $extra[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) { /* ignore */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt - <?php echo htmlspecialchars($sale['invoice_number']); ?></title>
<style>
    /* ============================================================
       THERMAL RECEIPT — 80mm default
       For 58mm: change @page size to 58mm auto and body width to 48mm
       ============================================================ */
    @page {
        size: 80mm auto;
        margin: 2mm;
    }

    html, body {
        margin: 0;
        padding: 0;
        background: #fff;
        color: #000;
    }

    body {
        font-family: 'Courier New', Courier, monospace;
        font-size: 11px;
        line-height: 1.25;
        width: 72mm;
        margin: 0 auto;
        padding: 4px;
    }

    .center      { text-align: center; }
    .right       { text-align: right; }
    .bold        { font-weight: bold; }
    .small       { font-size: 9px; }

    .company     { font-size: 15px; font-weight: bold; letter-spacing: 1px; }
    .store-info  { font-size: 9px; line-height: 1.3; }

    hr.dashed {
        border: none;
        border-top: 1px dashed #000;
        margin: 4px 0;
    }
    hr.solid {
        border: none;
        border-top: 1px solid #000;
        margin: 3px 0;
    }

    .meta div { font-size: 10px; }

    /* Items table */
    table.items {
        width: 100%;
        border-collapse: collapse;
        font-size: 10.5px;
    }
    table.items th {
        text-align: left;
        border-bottom: 1px solid #000;
        padding: 2px 0;
        font-size: 10px;
        text-transform: uppercase;
    }
    table.items td {
        padding: 2px 0;
        vertical-align: top;
    }
    table.items td.qty { text-align: center; width: 18px; }
    table.items td.amt { text-align: right; white-space: nowrap; }
    table.items td.name { word-break: break-word; }

    /* Totals */
    table.totals {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
    }
    table.totals td        { padding: 1px 0; }
    table.totals td.label  { text-align: right; padding-right: 6px; }
    table.totals td.value  { text-align: right; white-space: nowrap; }
    table.totals tr.grand td {
        font-size: 13px;
        font-weight: bold;
        border-top: 1px solid #000;
        padding-top: 3px;
    }
    table.totals tr.due td { color: #000; font-weight: bold; }

    .barcode     { text-align: center; margin: 6px 0 2px; }
    .footer      { text-align: center; font-size: 9px; line-height: 1.35; margin-top: 6px; }
    .footer .ty  { font-size: 11px; font-weight: bold; margin-bottom: 2px; }

    /* Screen toolbar */
    .toolbar {
        text-align: center;
        margin: 12px 0;
    }
    .toolbar button {
        padding: 9px 18px;
        font-size: 14px;
        cursor: pointer;
        margin: 0 4px;
    }

    @media print {
        .no-print { display: none !important; }
        body      { margin: 0; width: auto; padding: 0; }
        @page     { margin: 0; }
        .barcode svg { max-width: 100%; height: auto; }
    }
</style>
</head>
<body>

<div class="toolbar no-print">
    <button onclick="window.print()">🖨 Print Receipt</button>
    <button onclick="window.close()">✕ Close</button>
</div>

<!-- ==================== RECEIPT ==================== -->

<div class="center company">
    <?php echo htmlspecialchars($sale['company_name'] ?? 'Electrical Shop'); ?>
</div>

<div class="center store-info">
    <?php if (!empty($sale['store_name'])): ?>
        <?php echo htmlspecialchars($sale['store_name']); ?><br>
    <?php endif; ?>
    <?php if (!empty($extra['company_address'])): ?>
        <?php echo htmlspecialchars($extra['company_address']); ?><br>
    <?php endif; ?>
    <?php if (!empty($sale['store_phone'])): ?>
        Tel: <?php echo htmlspecialchars($sale['store_phone']); ?>
    <?php elseif (!empty($extra['company_phone'])): ?>
        Tel: <?php echo htmlspecialchars($extra['company_phone']); ?>
    <?php endif; ?>
</div>

<hr class="dashed">

<div class="meta">
    <div><strong>Invoice:</strong> <?php echo htmlspecialchars($sale['invoice_number']); ?></div>
    <div><strong>Date:</strong> <?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?></div>
    <div><strong>Customer:</strong> <?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?></div>
    <div><strong>Served By:</strong> <?php echo htmlspecialchars($sale['served_by_name'] ?? 'Admin'); ?></div>
</div>

<hr class="dashed">

<!-- ITEMS -->
<table class="items">
    <thead>
        <tr>
            <th>ITEM</th>
            <th class="qty">QTY</th>
            <th class="right">AMOUNT</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr>
            <td class="name" colspan="3">
                <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
            </td>
        </tr>
        <tr>
            <td class="small"><?php echo htmlspecialchars($item['product_code']); ?> @ <?php echo number_format($item['unit_price'], 0); ?></td>
            <td class="qty"><?php echo (int)$item['quantity']; ?></td>
            <td class="amt"><?php echo number_format($item['total_price'], 0); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<hr class="dashed">

<!-- TOTALS -->
<table class="totals">
    <tr>
        <td class="label">Subtotal:</td>
        <td class="value"><?php echo number_format($sale['subtotal'], 0); ?></td>
    </tr>
    <?php if (!empty($sale['tax_amount']) && $sale['tax_amount'] > 0): ?>
    <tr>
        <td class="label">Tax:</td>
        <td class="value"><?php echo number_format($sale['tax_amount'], 0); ?></td>
    </tr>
    <?php endif; ?>
    <?php if (!empty($sale['discount_amount']) && $sale['discount_amount'] > 0): ?>
    <tr>
        <td class="label">Discount:</td>
        <td class="value">-<?php echo number_format($sale['discount_amount'], 0); ?></td>
    </tr>
    <?php endif; ?>
    <tr class="grand">
        <td class="label">TOTAL:</td>
        <td class="value"><?php echo number_format($sale['total_amount'], 0); ?></td>
    </tr>
    <tr>
        <td class="label">Paid:</td>
        <td class="value"><?php echo number_format($sale['amount_paid'], 0); ?></td>
    </tr>
    <?php if (!empty($sale['amount_due']) && $sale['amount_due'] > 0): ?>
    <tr class="due">
        <td class="label">Due:</td>
        <td class="value"><?php echo number_format($sale['amount_due'], 0); ?></td>
    </tr>
    <?php endif; ?>
</table>

<hr class="solid">

<!-- PAYMENT -->
<?php if (!empty($sale['payment_method'])): ?>
<div class="small"><strong>PAYMENT:</strong> <?php echo htmlspecialchars(strtoupper($sale['payment_method'])); ?></div>
<?php endif; ?>
<?php if (!empty($sale['change_amount']) && $sale['change_amount'] > 0): ?>
<div class="small"><strong>CHANGE:</strong> <?php echo number_format($sale['change_amount'], 0); ?></div>
<?php endif; ?>

<!-- BARCODE -->
<div class="barcode">
    <svg id="barcode"></svg>
</div>

<hr class="dashed">

<!-- FOOTER -->
<div class="footer">
    <div class="ty">THANK YOU ★</div>
    <?php if (!empty($extra['receipt_footer'])): ?>
        <?php echo nl2br(htmlspecialchars($extra['receipt_footer'])); ?>
    <?php else: ?>
        Thank you for your business!<br>
        Please keep this receipt for your records.<br>
        Visit us again!
    <?php endif; ?>
</div>

<!-- ==================== END RECEIPT ==================== -->

<div class="no-print" style="text-align:center; margin:20px;">
    <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">Print Receipt</button>
    <button onclick="window.close()" style="padding: 10px 20px; font-size: 16px; cursor: pointer; margin-left: 10px;">Close</button>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script>
    // Barcode — small enough for thermal width
    JsBarcode("#barcode", "<?php echo htmlspecialchars($sale['invoice_number']); ?>", {
        format: "CODE128",
        displayValue: true,
        fontSize: 16,
        height: 30,
        width: 1.2,
        margin: 0
    });

    // Auto-print once loaded (optional — remove setTimeout line to stop auto-print)
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 400);
    });
</script>
</body>
</html>