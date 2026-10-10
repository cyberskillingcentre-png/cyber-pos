<?php
require_once '../config/config.php';
require_once 'config/client_auth.php';
require_client();

// ============================================
// VALIDATE ORDER ID
// ============================================
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($order_id <= 0) {
    die('Invalid order ID');
}

// ============================================
// LOAD ORDER — MUST belong to the logged-in client
// ============================================
try {
    $stmt = $conn->prepare("
        SELECT 
            co.*,
            u.full_name  AS client_name,
            u.email      AS client_email,
            u.phone      AS client_phone,
            cp.company_name,
            cp.billing_address,
            cp.shipping_address,
            cp.tax_id,
            s.store_name,
            s.location   AS store_location,
            s.phone      AS store_phone,
            proc.full_name AS processed_by_name
        FROM client_orders co
        LEFT JOIN users u            ON co.client_id    = u.user_id
        LEFT JOIN client_profiles cp ON u.user_id       = cp.user_id
        LEFT JOIN stores s           ON co.store_id     = s.store_id
        LEFT JOIN users proc         ON co.processed_by = proc.user_id
        WHERE co.order_id = ?
          AND co.client_id = ?
    ");
    $stmt->execute([$order_id, $_SESSION['user_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        die('Order not found or does not belong to you.');
    }
} catch (PDOException $e) {
    die('Error loading order: ' . $e->getMessage());
}

// ============================================
// SAFE VALUE EXTRACTION
// ============================================
$subtotal        = floatval($order['subtotal'] ?? 0);
$discount_amount = floatval($order['discount_amount'] ?? 0);
$tax_amount      = floatval($order['tax_amount'] ?? 0);
$grand_total     = floatval($order['grand_total'] ?? 0);
$amount_paid     = floatval($order['amount_paid'] ?? 0);
$amount_due      = floatval($order['amount_due'] ?? max(0, $grand_total - $amount_paid));
$balance         = max(0, $grand_total - $amount_paid);

// ============================================
// LOAD ORDER ITEMS
// ============================================
$items = [];
try {
    $stmt = $conn->prepare("
        SELECT coi.*, p.product_code
        FROM client_order_items coi
        LEFT JOIN products p ON coi.product_id = p.product_id
        WHERE coi.order_id = ?
        ORDER BY coi.order_item_id ASC
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $items = [];
}

// ============================================
// ORG / COMPANY INFO
// ============================================
$org_name  = $_SESSION['org_name']  ?? 'My Business';
$org_phone = $_SESSION['org_phone'] ?? '';
$org_email = $_SESSION['org_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?php echo htmlspecialchars($order['order_number']); ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 24px;
            color: #1A202C;
            font-size: 13px;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #6B46C1;
            padding-bottom: 20px;
        }
        .header h1 {
            margin: 0 0 6px;
            letter-spacing: 3px;
            font-size: 26px;
            color: #6B46C1;
        }
        .header h2 {
            margin: 0;
            color: #1A202C;
            font-size: 17px;
        }
        .header p {
            margin: 4px 0 0;
            color: #718096;
            font-size: 12px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            gap: 24px;
        }
        .info-box { width: 48%; line-height: 1.6; }
        .info-box h3 {
            margin: 0 0 6px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6B46C1;
        }
        .info-box strong { color: #1A202C; }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        table.items th, table.items td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        table.items th {
            background: #f5f5f5;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
        }
        table.items .product-code {
            font-size: 11px;
            color: #718096;
            font-family: 'Courier New', monospace;
        }

        .text-right  { text-align: right; }
        .text-center { text-align: center; }

        table.totals {
            margin-left: auto;
            width: 340px;
            border-collapse: collapse;
        }
        table.totals td {
            border: none;
            padding: 6px 0;
            font-size: 13px;
        }
        table.totals tr.grand-total td {
            font-size: 16px;
            font-weight: bold;
            background: #f0f0f0;
            padding: 10px;
            border-top: 2px solid #6B46C1;
            border-bottom: 2px solid #6B46C1;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-confirmed { background: #C6F6D5; color: #22543D; }
        .badge-pending   { background: #FEFCBF; color: #744210; }
        .badge-other     { background: #E2E8F0; color: #2D3748; }

        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 12px;
            color: #666;
            border-top: 1px dashed #ddd;
            padding-top: 20px;
        }

        @media print {
            button, .no-print { display: none !important; }
            body { padding: 0; max-width: 100%; }
        }
    </style>
</head>
<body>

<button class="no-print"
        onclick="window.print()"
        style="margin-bottom: 20px; padding: 10px 24px; background: linear-gradient(135deg, #6B46C1, #553C9A); color: #fff; border: none; border-radius: 6px; font-weight: 700; cursor: pointer;">
    🖨️ Print Invoice
</button>

<!-- Header -->
<div class="header">
    <h1>INVOICE</h1>
    <h2><?php echo htmlspecialchars($org_name); ?></h2>
    <?php if ($org_phone): ?>
        <p>Phone: <?php echo htmlspecialchars($org_phone); ?></p>
    <?php endif; ?>
    <?php if ($org_email): ?>
        <p>Email: <?php echo htmlspecialchars($org_email); ?></p>
    <?php endif; ?>
</div>

<!-- Bill To / Invoice Details -->
<div class="info-row">
    <div class="info-box">
        <h3>Bill To</h3>
        <strong><?php echo htmlspecialchars($order['client_name'] ?? 'Walk-in Customer'); ?></strong><br>

        <?php if (!empty($order['company_name'])): ?>
            <?php echo htmlspecialchars($order['company_name']); ?><br>
        <?php endif; ?>

        <?php if (!empty($order['client_email'])): ?>
            <?php echo htmlspecialchars($order['client_email']); ?><br>
        <?php endif; ?>

        <?php if (!empty($order['client_phone'])): ?>
            <?php echo htmlspecialchars($order['client_phone']); ?><br>
        <?php endif; ?>

        <?php if (!empty($order['tax_id'])): ?>
            <em>TIN: <?php echo htmlspecialchars($order['tax_id']); ?></em><br>
        <?php endif; ?>

        <?php if (!empty($order['billing_address'])): ?>
            <?php echo nl2br(htmlspecialchars($order['billing_address'])); ?>
        <?php elseif (!empty($order['shipping_address'])): ?>
            <?php echo nl2br(htmlspecialchars($order['shipping_address'])); ?>
        <?php endif; ?>
    </div>

    <div class="info-box text-right">
        <h3>Invoice Details</h3>
        <strong>Invoice #:</strong> <?php echo htmlspecialchars($order['order_number']); ?><br>
        <strong>Date:</strong> <?php echo date('M d, Y', strtotime($order['order_date'])); ?><br>
        <strong>Time:</strong> <?php echo date('H:i A', strtotime($order['order_date'])); ?><br>

        <?php
        $status = $order['order_status'] ?? 'pending';
        $badgeClass = 'badge-other';
        if ($status === 'confirmed' || $status === 'delivered') $badgeClass = 'badge-confirmed';
        elseif ($status === 'pending') $badgeClass = 'badge-pending';
        ?>
        <strong>Status:</strong>
        <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($status); ?></span><br>

        <strong>Payment:</strong> <?php echo ucfirst($order['payment_status'] ?? 'pending'); ?><br>

        <?php if (!empty($order['store_name'])): ?>
            <strong>Store:</strong> <?php echo htmlspecialchars($order['store_name']); ?>
        <?php endif; ?>
    </div>
</div>

<!-- Items -->
<table class="items">
    <thead>
        <tr>
            <th style="width: 40px;">#</th>
            <th>Product</th>
            <th class="text-center" style="width: 70px;">Qty</th>
            <th class="text-right" style="width: 110px;">Unit Price</th>
            <th class="text-right" style="width: 100px;">Discount</th>
            <th class="text-right" style="width: 110px;">Total</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($items)): ?>
            <tr>
                <td colspan="6" class="text-center" style="padding: 30px; color: #718096;">
                    No items on this order
                </td>
            </tr>
        <?php else: ?>
            <?php $i = 1; foreach ($items as $item): ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td>
                    <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                    <?php if (!empty($item['product_code'])): ?>
                        <div class="product-code"><?php echo htmlspecialchars($item['product_code']); ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-center"><?php echo intval($item['quantity']); ?></td>
                <td class="text-right">UGX <?php echo number_format(floatval($item['unit_price']), 0); ?></td>
                <td class="text-right">
                    <?php if (floatval($item['discount'] ?? 0) > 0): ?>
                        UGX <?php echo number_format(floatval($item['discount']), 0); ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td class="text-right"><strong>UGX <?php echo number_format(floatval($item['total_price']), 0); ?></strong></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<!-- Totals -->
<table class="totals">
    <tr>
        <td><strong>Subtotal:</strong></td>
        <td class="text-right">UGX <?php echo number_format($subtotal, 0); ?></td>
    </tr>

    <?php if ($discount_amount > 0): ?>
    <tr>
        <td><strong>Discount:</strong></td>
        <td class="text-right" style="color: #2F855A;">- UGX <?php echo number_format($discount_amount, 0); ?></td>
    </tr>
    <?php endif; ?>

    <?php if ($tax_amount > 0): ?>
    <tr>
        <td><strong>Tax:</strong></td>
        <td class="text-right">UGX <?php echo number_format($tax_amount, 0); ?></td>
    </tr>
    <?php endif; ?>

    <tr class="grand-total">
        <td><strong>GRAND TOTAL:</strong></td>
        <td class="text-right"><strong>UGX <?php echo number_format($grand_total, 0); ?></strong></td>
    </tr>

    <tr>
        <td><strong>Amount Paid:</strong></td>
        <td class="text-right">UGX <?php echo number_format($amount_paid, 0); ?></td>
    </tr>

    <tr>
        <td><strong>Balance Due:</strong></td>
        <td class="text-right" style="color: <?php echo $balance > 0 ? '#C53030' : '#2F855A'; ?>;">
            <strong>UGX <?php echo number_format($balance, 0); ?></strong>
        </td>
    </tr>
</table>

<!-- Footer -->
<div class="footer">
    <p>Thank you for your business!</p>
    <p><?php echo htmlspecialchars($org_name); ?></p>
</div>

</body>
</html>