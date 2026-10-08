<?php
require_once '../config/config.php';
require_once 'config/client_auth.php';
require_client();

$order_id = intval($_GET['id'] ?? 0);

if ($order_id <= 0) {
    die('Invalid order ID');
}

// ✅ IDOR-protected fetch
$order = get_order_with_access_check($conn, $order_id);

if (!$order) {
    die('Invoice not found');
}

// Fetch items
$stmt = $conn->prepare("
    SELECT coi.*, p.product_code 
    FROM client_order_items coi
    LEFT JOIN products p ON coi.product_id = p.product_id
    WHERE coi.order_id = ?
");
$stmt->execute([$order_id]);
$items = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice - <?php echo htmlspecialchars($order['order_number']); ?></title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 3px solid #333; padding-bottom: 20px; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .info-box { width: 48%; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #f5f5f5; font-weight: bold; }
        .text-right { text-align: right; }
        .totals { margin-left: auto; width: 300px; }
        .grand-total { font-size: 18px; font-weight: bold; background: #f0f0f0; }
        .print-button { 
            margin-bottom: 20px; 
            padding: 10px 20px; 
            background: #6B46C1; 
            color: white; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer;
            font-size: 16px;
        }
        .print-button:hover { background: #553399; }
        .back-button {
            margin-bottom: 20px; 
            padding: 10px 20px; 
            background: #ddd; 
            color: #333; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
        }
        .back-button:hover { background: #ccc; }
        @media print { 
            .print-button, .back-button { display: none; } 
        }
    </style>
</head>
<body>
    <?php if (is_admin()): ?>
        <a href="View_Order?id=<?php echo $order_id; ?>" class="back-button">← Back to Order</a>
    <?php else: ?>
        <a href="../client/My_Invoices?id=<?php echo $order_id; ?>" class="back-button">← Back to Order</a>
    <?php endif; ?>
    
    <button onclick="window.print()" class="print-button">🖨️ Print Invoice</button>
    
    <div class="header">
        <h1>INVOICE</h1>
        <h2><?php echo htmlspecialchars($_SESSION['org_name'] ?? 'Company Name'); ?></h2>
        <p><?php echo htmlspecialchars($_SESSION['org_phone'] ?? ''); ?></p>
        <?php if (!empty($_SESSION['org_email'])): ?>
            <p><?php echo htmlspecialchars($_SESSION['org_email']); ?></p>
        <?php endif; ?>
    </div>
    
    <div class="info-row">
        <div class="info-box">
            <h3>Bill To:</h3>
            <strong><?php echo htmlspecialchars($order['client_name']); ?></strong><br>
            <?php if ($order['company_name']): ?>
                <?php echo htmlspecialchars($order['company_name']); ?><br>
            <?php endif; ?>
            <?php echo htmlspecialchars($order['email']); ?><br>
            <?php echo htmlspecialchars($order['phone'] ?? 'N/A'); ?>
            <?php if ($order['billing_address']): ?>
                <br><br><strong>Address:</strong><br>
                <?php echo nl2br(htmlspecialchars($order['billing_address'])); ?>
            <?php endif; ?>
        </div>
        <div class="info-box text-right">
            <h3>Invoice Details:</h3>
            <strong>Invoice #:</strong> <?php echo htmlspecialchars($order['order_number']); ?><br>
            <strong>Date:</strong> <?php echo date('M d, Y', strtotime($order['order_date'])); ?><br>
            <strong>Status:</strong> <span style="color: <?php 
                echo $order['order_status'] == 'confirmed' ? 'green' : 
                     ($order['order_status'] == 'cancelled' ? 'red' : 'orange'); 
            ?>;"><?php echo ucfirst($order['order_status']); ?></span><br>
            <strong>Payment:</strong> <span style="color: <?php 
                echo $order['payment_status'] == 'paid' ? 'green' : 
                     ($order['payment_status'] == 'pending' ? 'red' : 'orange'); 
            ?>;"><?php echo ucfirst($order['payment_status']); ?></span>
            <?php if ($order['store_name']): ?>
                <br><strong>Store:</strong> <?php echo htmlspecialchars($order['store_name']); ?>
            <?php endif; ?>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Product</th>
                <th>Quantity</th>
                <th>Unit Price</th>
                <?php if ($order['discount_amount'] > 0): ?>
                <th>Discount</th>
                <?php endif; ?>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach ($items as $item): ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                <td><?php echo $item['quantity']; ?></td>
                <td>UGX <?php echo number_format($item['unit_price'], 0); ?></td>
                <?php if ($order['discount_amount'] > 0): ?>
                <td>UGX <?php echo number_format($item['discount'], 0); ?></td>
                <?php endif; ?>
                <td>UGX <?php echo number_format($item['total_price'], 0); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <table class="totals">
        <tr>
            <td><strong>Subtotal:</strong></td>
            <td class="text-right">UGX <?php echo number_format($order['total_amount'], 0); ?></td>
        </tr>
        <?php if ($order['discount_amount'] > 0): ?>
        <tr>
            <td><strong>Discount:</strong></td>
            <td class="text-right" style="color: green;">- UGX <?php echo number_format($order['discount_amount'], 0); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($order['tax_amount'] > 0): ?>
        <tr>
            <td><strong>Tax:</strong></td>
            <td class="text-right">UGX <?php echo number_format($order['tax_amount'], 0); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($order['shipping_cost'] > 0): ?>
        <tr>
            <td><strong>Shipping:</strong></td>
            <td class="text-right">UGX <?php echo number_format($order['shipping_cost'], 0); ?></td>
        </tr>
        <?php endif; ?>
        <tr class="grand-total">
            <td><strong>GRAND TOTAL:</strong></td>
            <td class="text-right"><strong>UGX <?php echo number_format($order['grand_total'], 0); ?></strong></td>
        </tr>
        <tr>
            <td><strong>Amount Paid:</strong></td>
            <td class="text-right" style="color: <?php echo $order['amount_paid'] > 0 ? 'green' : 'inherit'; ?>;">
                UGX <?php echo number_format($order['amount_paid'], 0); ?>
            </td>
        </tr>
        <tr>
            <td><strong>Balance Due:</strong></td>
            <td class="text-right" style="color: <?php 
                $balance = $order['grand_total'] - $order['amount_paid'];
                echo $balance > 0 ? 'red' : 'green'; 
            ?>;">
                <strong>UGX <?php echo number_format($balance, 0); ?></strong>
            </td>
        </tr>
    </table>
    
    <?php if (!empty($order['notes'])): ?>
    <div style="margin-top: 30px; padding: 15px; background: #f9f9f9; border-left: 4px solid #6B46C1;">
        <strong>Notes:</strong><br>
        <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
    </div>
    <?php endif; ?>
    
    <div style="margin-top: 50px; text-align: center; font-size: 12px; color: #666;">
        <p>Thank you for your business!</p>
        <p><strong><?php echo htmlspecialchars($_SESSION['org_name'] ?? 'Company Name'); ?></strong></p>
        <?php if (!empty($_SESSION['org_email']) || !empty($_SESSION['org_phone'])): ?>
        <p>
            <?php if (!empty($_SESSION['org_email'])): ?>
                Email: <?php echo htmlspecialchars($_SESSION['org_email']); ?>
            <?php endif; ?>
            <?php if (!empty($_SESSION['org_phone'])): ?>
                | Phone: <?php echo htmlspecialchars($_SESSION['org_phone']); ?>
            <?php endif; ?>
        </p>
        <?php endif; ?>
    </div>
</body>
</html>