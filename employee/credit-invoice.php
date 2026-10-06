<?php
require_once '../config/config.php';
require_admin();

$sale_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$sale_id) {
    set_alert('danger', 'Invalid sale ID');
    header('Location: sales.php');
    exit();
}

// Fetch sale details
$stmt = $conn->prepare("
    SELECT 
        s.*,
        st.store_name,
        c.customer_name,
        c.customer_code,
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
    set_alert('danger', 'Sale not found');
    header('Location: sales.php');
    exit();
}

// Get store details separately
$stmt = $conn->prepare("SELECT * FROM stores WHERE store_id = ?");
$stmt->execute([$sale['store_id']]);
$store = $stmt->fetch();

// Get customer details separately
$customer = null;
if ($sale['customer_id']) {
    $stmt = $conn->prepare("SELECT * FROM customers WHERE customer_id = ?");
    $stmt->execute([$sale['customer_id']]);
    $customer = $stmt->fetch();
}

// Check if customer exists for credit sales
if ($sale['sale_type'] == 'credit' && !$customer) {
    set_alert('danger', 'Customer information required for credit sales');
    header('Location: view-sale.php?id=' . $sale_id);
    exit();
}

// Fetch sale items
$stmt = $conn->prepare("
    SELECT 
        si.*,
        p.product_name,
        p.product_code
    FROM sale_items si
    JOIN products p ON si.product_id = p.product_id
    WHERE si.sale_id = ?
    ORDER BY si.sale_item_id
");
$stmt->execute([$sale_id]);
$sale_items = $stmt->fetchAll();

// Calculate due date (30 days from sale date)
$due_date = date('Y-m-d', strtotime($sale['sale_date'] . ' +30 days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Invoice - <?php echo $sale['invoice_number']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .invoice-container { box-shadow: none !important; border: none !important; }
        }
        
        body {
            background: #f5f5f5;
            font-family: 'Arial', sans-serif;
        }
        
        .invoice-container {
            max-width: 900px;
            margin: 30px auto;
            background: white;
            padding: 40px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            border-radius: 10px;
        }
        
        .invoice-header {
            border-bottom: 3px solid #E53E3E;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        
        .company-logo {
            font-size: 2.5rem;
            color: #6B46C1;
        }
        
        .invoice-title {
            font-size: 2rem;
            font-weight: bold;
            color: #E53E3E;
            text-transform: uppercase;
        }
        
        .invoice-info {
            background: #F7FAFC;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .credit-notice {
            background: #FED7D7;
            border-left: 4px solid #E53E3E;
            padding: 15px;
            margin-bottom: 30px;
            border-radius: 5px;
        }
        
        .credit-notice h5 {
            color: #E53E3E;
            margin-bottom: 5px;
        }
        
        .table th {
            background: #E53E3E;
            color: white;
            padding: 12px;
        }
        
        .table td {
            padding: 10px;
            vertical-align: middle;
        }
        
        .total-section {
            background: #F7FAFC;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        
        .grand-total {
            background: #E53E3E;
            color: white;
            padding: 15px;
            border-radius: 5px;
            font-size: 1.3rem;
            font-weight: bold;
        }
        
        .payment-terms {
            background: #EDF2F7;
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
            border-left: 4px solid #6B46C1;
        }
        
        .signature-section {
            margin-top: 50px;
            padding-top: 30px;
            border-top: 2px solid #E2E8F0;
        }
        
        .signature-box {
            border-top: 2px solid #2D3748;
            padding-top: 10px;
            margin-top: 60px;
            text-align: center;
        }
        
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #E2E8F0;
            text-align: center;
            color: #718096;
        }
        
        .badge-credit {
            background: #E53E3E;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Action Buttons -->
        <div class="text-end mb-3 no-print">
            <button onclick="window.print()" class="btn btn-danger">
                <i class="fas fa-print me-2"></i>Print Invoice
            </button>
            <a href="view-sale.php?id=<?php echo $sale_id; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Sale
            </a>
        </div>
        
        <!-- Invoice Header -->
        <div class="invoice-header">
            <div class="row">
                <div class="col-md-6">
                    <div class="company-logo mb-3">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h3><?php echo APP_NAME; ?></h3>
                    <p class="mb-1"><strong><?php echo $sale['store_name']; ?></strong></p>
                    <?php if (isset($store['address']) && $store['address']): ?>
                    <p class="mb-1"><i class="fas fa-map-marker-alt me-2"></i><?php echo $store['address']; ?></p>
                    <?php endif; ?>
                    <?php if (isset($store['phone']) && $store['phone']): ?>
                    <p class="mb-1"><i class="fas fa-phone me-2"></i><?php echo $store['phone']; ?></p>
                    <?php endif; ?>
                    <?php if (isset($store['email']) && $store['email']): ?>
                    <p class="mb-1"><i class="fas fa-envelope me-2"></i><?php echo $store['email']; ?></p>
                    <?php endif; ?>
                </div>
                <div class="col-md-6 text-end">
                    <div class="invoice-title mb-3">CREDIT INVOICE</div>
                    <span class="badge-credit">PAYMENT REQUIRED</span>
                    <div class="invoice-info text-start mt-3">
                        <p class="mb-2"><strong>Invoice Number:</strong> <?php echo $sale['invoice_number']; ?></p>
                        <p class="mb-2"><strong>Date Issued:</strong> <?php echo date('d/m/Y', strtotime($sale['sale_date'])); ?></p>
                        <p class="mb-2"><strong>Due Date:</strong> <span class="text-danger"><?php echo date('d/m/Y', strtotime($due_date)); ?></span></p>
                        <p class="mb-0"><strong>Served By:</strong> <?php echo $sale['served_by_name']; ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Credit Notice -->
        <div class="credit-notice">
            <h5><i class="fas fa-exclamation-triangle me-2"></i>CREDIT SALE - PAYMENT REQUIRED</h5>
            <p class="mb-1">This is a credit sale transaction. Full payment is required by <strong><?php echo date('d F Y', strtotime($due_date)); ?></strong></p>
            <p class="mb-0">Current Balance Due: <strong class="text-danger">UGX <?php echo number_format($sale['amount_due'], 2); ?></strong></p>
        </div>
        
        <!-- Customer Information -->
        <div class="row mb-4">
            <div class="col-md-6">
                <h5 class="mb-3" style="color: #6B46C1;"><i class="fas fa-user me-2"></i>Bill To:</h5>
                <div class="invoice-info">
                    <p class="mb-2"><strong><?php echo $sale['customer_name']; ?></strong></p>
                    <?php if ($sale['customer_code']): ?>
                    <p class="mb-2"><small class="text-muted">Customer Code: <?php echo $sale['customer_code']; ?></small></p>
                    <?php endif; ?>
                    <?php if ($customer && isset($customer['address']) && $customer['address']): ?>
                    <p class="mb-2"><i class="fas fa-map-marker-alt me-2"></i><?php echo $customer['address']; ?></p>
                    <?php endif; ?>
                    <?php if ($customer && isset($customer['phone']) && $customer['phone']): ?>
                    <p class="mb-2"><i class="fas fa-phone me-2"></i><?php echo $customer['phone']; ?></p>
                    <?php endif; ?>
                    <?php if ($customer && isset($customer['email']) && $customer['email']): ?>
                    <p class="mb-0"><i class="fas fa-envelope me-2"></i><?php echo $customer['email']; ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-6">
                <h5 class="mb-3" style="color: #6B46C1;"><i class="fas fa-file-invoice-dollar me-2"></i>Payment Status:</h5>
                <div class="invoice-info">
                    <p class="mb-2"><strong>Invoice Amount:</strong> 
                        <span style="font-size: 1.3rem; font-weight: bold;">UGX <?php echo number_format($sale['total_amount'], 2); ?></span>
                    </p>
                    <p class="mb-2"><strong>Amount Paid:</strong> 
                        UGX <?php echo number_format($sale['amount_paid'], 2); ?>
                    </p>
                    <p class="mb-2"><strong>Balance Due:</strong> 
                        <span class="text-danger" style="font-size: 1.2rem; font-weight: bold;">
                            UGX <?php echo number_format($sale['amount_due'], 2); ?>
                        </span>
                    </p>
                    <p class="mb-2"><strong>Status:</strong> 
                        <?php if ($sale['payment_status'] == 'unpaid'): ?>
                            <span class="badge bg-danger">UNPAID</span>
                        <?php elseif ($sale['payment_status'] == 'partial'): ?>
                            <span class="badge bg-warning">PARTIAL</span>
                        <?php else: ?>
                            <span class="badge bg-success">PAID</span>
                        <?php endif; ?>
                    </p>
                    <p class="mb-0"><strong>Due Date:</strong> 
                        <span class="text-danger"><?php echo date('d F Y', strtotime($due_date)); ?></span>
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Items Table -->
        <h5 class="mb-3" style="color: #6B46C1;"><i class="fas fa-list me-2"></i>Items:</h5>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 15%;">Product Code</th>
                        <th style="width: 35%;">Product Name</th>
                        <th class="text-center" style="width: 10%;">Quantity</th>
                        <th class="text-end" style="width: 15%;">Unit Price</th>
                        <th class="text-end" style="width: 10%;">Tax</th>
                        <th class="text-end" style="width: 15%;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    foreach ($sale_items as $item): 
                    ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo $item['product_code']; ?></td>
                        <td><strong><?php echo $item['product_name']; ?></strong></td>
                        <td class="text-center"><?php echo $item['quantity']; ?></td>
                        <td class="text-end">UGX <?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="text-end">UGX <?php echo number_format($item['tax_amount'], 2); ?></td>
                        <td class="text-end"><strong>UGX <?php echo number_format($item['total_price'], 2); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Totals -->
        <div class="row">
            <div class="col-md-6"></div>
            <div class="col-md-6">
                <div class="total-section">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal:</span>
                        <strong>UGX <?php echo number_format($sale['subtotal'], 2); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Tax:</span>
                        <strong>UGX <?php echo number_format($sale['tax_amount'], 2); ?></strong>
                    </div>
                    <?php if ($sale['discount_amount'] > 0): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Discount:</span>
                        <strong class="text-success">- UGX <?php echo number_format($sale['discount_amount'], 2); ?></strong>
                    </div>
                    <?php endif; ?>
                    <hr>
                    <div class="grand-total d-flex justify-content-between">
                        <span>TOTAL AMOUNT DUE:</span>
                        <span>UGX <?php echo number_format($sale['amount_due'], 2); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payment Terms -->
        <div class="payment-terms">
            <h5 style="color: #6B46C1;"><i class="fas fa-clipboard-list me-2"></i>Payment Terms & Conditions:</h5>
            <ul class="mb-0">
                <li>Payment is due within <strong>30 days</strong> from the invoice date</li>
                <li>Payment due date: <strong><?php echo date('d F Y', strtotime($due_date)); ?></strong></li>
                <li>Late payments may incur additional charges of 2% per month</li>
                <li>Please reference invoice number <strong><?php echo $sale['invoice_number']; ?></strong> when making payment</li>
                <li>Acceptable payment methods: Bank Transfer, Mobile Money, Cash, Cheque</li>
                <li>All payments are final and non-refundable unless agreed otherwise</li>
                <li>Goods remain the property of the seller until payment is received in full</li>
            </ul>
        </div>
        
        <!-- Bank Details -->
        <div class="payment-terms mt-3">
            <h5 style="color: #6B46C1;"><i class="fas fa-university me-2"></i>Bank Details for Payment:</h5>
            <p class="mb-1"><strong>Bank Name:</strong> [Your Bank Name]</p>
            <p class="mb-1"><strong>Account Name:</strong> <?php echo APP_NAME; ?></p>
            <p class="mb-1"><strong>Account Number:</strong> [Your Account Number]</p>
            <p class="mb-1"><strong>Mobile Money:</strong> [Your Mobile Money Number]</p>
            <?php if (isset($store['phone']) && $store['phone']): ?>
            <p class="mb-0"><small class="text-muted">Please send payment confirmation to: <?php echo $store['phone']; ?></small></p>
            <?php endif; ?>
        </div>
        
        <!-- Signature Section -->
        <div class="signature-section">
            <div class="row">
                <div class="col-md-6">
                    <div class="signature-box">
                        <strong>Customer Signature</strong><br>
                        <small class="text-muted">I acknowledge receipt of goods and agree to the payment terms stated above</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="signature-box">
                        <strong>Authorized Signature</strong><br>
                        <small class="text-muted"><?php echo $sale['served_by_name']; ?></small><br>
                        <small class="text-muted"><?php echo APP_NAME; ?></small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p class="mb-1"><strong>Thank you for your business!</strong></p>
            <?php if (isset($store['phone']) && $store['phone']): ?>
            <p class="mb-2">For any queries regarding this invoice, please contact us at <?php echo $store['phone']; ?></p>
            <?php endif; ?>
            <p class="mb-0"><small>Invoice generated on <?php echo date('d/m/Y H:i:s'); ?></small></p>
            <p class="mb-0"><small>This is a computer-generated invoice and requires signature to be valid</small></p>
        </div>
    </div>
</body>
</html>