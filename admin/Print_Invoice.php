<?php
require_once '../config/config.php';
require_login();
if (!is_admin()) { header('Location: ../employee/dashboard.php'); exit(); }

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get order details
$stmt = $conn->prepare("
    SELECT co.*, 
           u.full_name as client_name, u.email, u.phone,
           cp.company_name, cp.billing_address,
           s.store_name
    FROM client_orders co
    LEFT JOIN users u ON co.client_id = u.user_id
    LEFT JOIN client_profiles cp ON u.user_id = cp.user_id
    LEFT JOIN stores s ON co.store_id = s.store_id
    WHERE co.order_id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('Order not found');
}

// Get items
$stmt = $conn->prepare("SELECT * FROM client_order_items WHERE order_id = ?");
$stmt->execute([$order_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        @media print { button { display: none; } }
    </style>
</head>
<body>
    <button onclick="window.print()" style="margin-bottom: 20px; padding: 10px 20px;">Print Invoice</button>
    
    <div class="header">
        <h1>INVOICE</h1>
        <h2><?php echo htmlspecialchars($_SESSION['org_name']); ?></h2>
        <p><?php echo htmlspecialchars($_SESSION['org_phone'] ?? ''); ?></p>
    </div>
    
    <div class="info-row">
        <div class="info-box">
            <h3>Bill To:</h3>
            <strong><?php echo htmlspecialchars($order['client_name']); ?></strong><br>
            <?php if ($order['company_name']): ?>
                <?php echo htmlspecialchars($order['company_name']); ?><br>
            <?php endif; ?>
            <?php echo htmlspecialchars($order['email']); ?><br>
            <?php echo htmlspecialchars($order['phone'] ?? ''); ?>
        </div>
        <div class="info-box text-right">
            <h3>Invoice Details:</h3>
            <strong>Invoice #:</strong> <?php echo htmlspecialchars($order['order_number']); ?><br>
            <strong>Date:</strong> <?php echo date('M d, Y', strtotime($order['order_date'])); ?><br>
            <strong>Status:</strong> <?php echo ucfirst($order['order_status']); ?><br>
            <strong>Payment:</strong> <?php echo ucfirst($order['payment_status']); ?>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Product</th>
                <th>Quantity</th>
                <th>Unit Price</th>
                <th>Discount</th>
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
                <td>UGX <?php echo number_format($item['discount'], 0); ?></td>
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
        <tr class="grand-total">
            <td><strong>GRAND TOTAL:</strong></td>
            <td class="text-right"><strong>UGX <?php echo number_format($order['grand_total'], 0); ?></strong></td>
        </tr>
        <tr>
            <td><strong>Amount Paid:</strong></td>
            <td class="text-right">UGX <?php echo number_format($order['amount_paid'], 0); ?></td>
        </tr>
        <tr>
            <td><strong>Balance Due:</strong></td>
            <td class="text-right"><strong>UGX <?php echo number_format($order['grand_total'] - $order['amount_paid'], 0); ?></strong></td>
        </tr>
    </table>
    
    <div style="margin-top: 50px; text-align: center; font-size: 12px; color: #666;">
        <p>Thank you for your business!</p>
        <p><?php echo htmlspecialchars($_SESSION['org_name']); ?></p>
    </div>
</body>
</html>
