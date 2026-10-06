<?php
require_once '../config/config.php';
require_login();
$page_title = "View Sale";
$sale_id = intval($_GET['id']);
$stmt = $conn->prepare("SELECT s.*, st.store_name, c.customer_name, c.phone as customer_phone, u.full_name as served_by_name FROM sales s LEFT JOIN stores st ON s.store_id = st.store_id LEFT JOIN customers c ON s.customer_id = c.customer_id LEFT JOIN users u ON s.served_by = u.user_id WHERE s.sale_id = ?");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch();
if (!$sale) { die('Sale not found'); }
$stmt = $conn->prepare("SELECT si.*, p.product_name, p.product_code FROM sale_items si JOIN products p ON si.product_id = p.product_id WHERE si.sale_id = ?");
$stmt->execute([$sale_id]);
$items = $stmt->fetchAll();
?>
<?php include '../includes/header.php'; ?>
<div class="sidebar">
    <div class="logo"><i class="fas fa-bolt fa-2x mb-2"></i><h4><?php echo APP_NAME; ?></h4><small class="text-white-50">Employee Panel</small></div>
    <div class="user-info text-center py-3 border-bottom border-white border-opacity-10">
        <div class="mb-2"><i class="fas fa-user-circle fa-3x text-white"></i></div>
        <h6 class="text-white mb-0"><?php echo $_SESSION['full_name']; ?></h6>
        <small class="text-white-50">Employee</small>
    </div>
    <nav class="mt-3">
        <ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link " href="Dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a class="nav-link" href="Point_Of_Sale"><i class="fas fa-cash-register"></i> Point of Sale</a></li>
            <li class="nav-item"><a class="nav-link active" href="My_Sales"><i class="fas fa-receipt"></i> My Sales</a></li>
       
        </ul>
    </nav>
</div>
<div class="main-content">
    <div class="top-navbar">
          <button class="btn btn-link d-md-none sidebar-toggle me-2">
                <i class="fas fa-bars"></i>
            </button>
        <h5 class="mb-0"><i class="fas fa-receipt me-2"></i>Sale Details - <?php echo $sale['invoice_number']; ?></h5>
    </div>
    <div class="content-area">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fas fa-info-circle me-2"></i>Sale Information</span>
                <a href="Receipt?id=<?php echo $sale_id; ?>" class="btn btn-sm btn-warning" target="_blank">
                    <i class="fas fa-print me-1"></i>Print Receipt
                </a>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Invoice Number:</strong> <?php echo $sale['invoice_number']; ?></p>
                        <p><strong>Date:</strong> <?php echo date('F d, Y H:i:s', strtotime($sale['sale_date'])); ?></p>
                        <p><strong>Store:</strong> <?php echo $sale['store_name']; ?></p>
                        <p><strong>Served By:</strong> <?php echo $sale['served_by_name']; ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Customer:</strong> <?php echo $sale['customer_name'] ?? 'Walk-in Customer'; ?></p>
                        <?php if ($sale['customer_phone']): ?>
                        <p><strong>Phone:</strong> <?php echo $sale['customer_phone']; ?></p>
                        <?php endif; ?>
                        <p><strong>Payment Method:</strong> <?php echo ucfirst(str_replace('_', ' ', $sale['payment_method'])); ?></p>
                        <p><strong>Payment Status:</strong> <span class="badge badge-<?php echo $sale['payment_status'] == 'paid' ? 'success' : 'warning'; ?>"><?php echo ucfirst($sale['payment_status']); ?></span></p>
                    </div>
                </div>
                <hr>
                <h6>Sale Items</h6>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead><tr><th>Code</th><th>Product</th><th>Quantity</th><th>Price</th><th>Tax</th><th>Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?php echo $item['product_code']; ?></td>
                                <td><?php echo $item['product_name']; ?></td>
                                <td><?php echo $item['quantity']; ?></td>
                                <td>UGX <?php echo format_currency($item['unit_price']); ?></td>
                                <td>UGX <?php echo format_currency($item['tax_amount']); ?></td>
                                <td>UGX <?php echo format_currency($item['total_price']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr><td colspan="5" class="text-end"><strong>Subtotal:</strong></td><td><strong>UGX <?php echo format_currency($sale['subtotal']); ?></strong></td></tr>
                            <tr><td colspan="5" class="text-end"><strong>Tax:</strong></td><td><strong>UGX <?php echo format_currency($sale['tax_amount']); ?></strong></td></tr>
                            <?php if ($sale['discount_amount'] > 0): ?>
                            <tr><td colspan="5" class="text-end"><strong>Discount:</strong></td><td><strong class="text-danger">- UGX <?php echo format_currency($sale['discount_amount']); ?></strong></td></tr>
                            <?php endif; ?>
                            <tr><td colspan="5" class="text-end"><strong>Total:</strong></td><td><strong class="text-primary">UGX <?php echo format_currency($sale['total_amount']); ?></strong></td></tr>
                            <tr><td colspan="5" class="text-end"><strong>Paid:</strong></td><td><strong>UGX <?php echo format_currency($sale['amount_paid']); ?></strong></td></tr>
                            <?php if ($sale['amount_due'] > 0): ?>
                            <tr><td colspan="5" class="text-end"><strong>Due:</strong></td><td><strong class="text-danger">UGX <?php echo format_currency($sale['amount_due']); ?></strong></td></tr>
                            <?php endif; ?>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <div class="mt-3">
            <a href="<?php echo is_admin() ? 'Sales' : 'My_Sales'; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back to Sales
            </a>
            <a href="Receipt?id=<?php echo $sale_id; ?>" class="btn btn-warning" target="_blank">
                <i class="fas fa-print me-1"></i>Print Receipt
            </a>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
