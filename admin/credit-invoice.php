<?php
require_once '../config/config.php';
require_admin();

$sale_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$sale_id) {
    set_alert('danger', 'Invalid sale ID');
    header('Location: sales.php');
    exit();
}

// ============================================
// FETCH SALE DETAILS
// ============================================
$stmt = $conn->prepare("
    SELECT 
        s.*,
        st.store_name,
        st.phone as store_phone,
        st.location as store_location,
        st.email as store_email,
        c.customer_name,
        c.customer_code,
        c.phone as customer_phone,
        c.email as customer_email,
        c.address as customer_address,
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

// For credit sales, customer is required
if ($sale['sale_type'] == 'credit' && empty($sale['customer_id'])) {
    set_alert('danger', 'Customer information required for credit sales');
    header('Location: view-sale.php?id=' . $sale_id);
    exit();
}

// ============================================
// FETCH SALE ITEMS
// ============================================
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

// ============================================
// FETCH ORGANIZATION DETAILS
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

// Fallback: query master DB if session empty
if (empty($org['address']) && !empty($_SESSION['org_id'])) {
    try {
        $master = get_master_connection();
        $stmt = $master->prepare("
            SELECT org_name, address, phone, email 
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
        }
    } catch(Exception $e) {
        error_log("Org fetch failed: " . $e->getMessage());
    }
}

// Fallback to store info
if (empty($org['phone']) && !empty($sale['store_phone'])) $org['phone'] = $sale['store_phone'];
if (empty($org['address']) && !empty($sale['store_location'])) $org['address'] = $sale['store_location'];
if (empty($org['email']) && !empty($sale['store_email'])) $org['email'] = $sale['store_email'];

// Calculate due date (30 days from sale date)
$due_date = date('Y-m-d', strtotime($sale['sale_date'] . ' +30 days'));

// Auto-print flag
$is_autoprint = isset($_GET['print_only']) || isset($_GET['autoprint']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Invoice - <?php echo htmlspecialchars($sale['invoice_number']); ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        /* ============================================
           A4 PAGE SETUP
           ============================================ */
        @page {
            size: A4;
            margin: 15mm;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            background: #e5e5e5;
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 11pt;
            color: #1a1a1a;
            padding: 20px;
            line-height: 1.4;
        }
        
        /* ============================================
           INVOICE CONTAINER (A4 SIZE)
           ============================================ */
        .invoice-container {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: white;
            padding: 15mm;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            position: relative;
        }
        
        /* ============================================
           HEADER - ORGANIZATION DETAILS
           ============================================ */
        .invoice-header {
            border-bottom: 3px solid #E53E3E;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }
        
        .company-logo {
            font-size: 2.5rem;
            color: #6B46C1;
        }
        
        .org-logo-img {
            max-height: 70px;
            max-width: 180px;
            object-fit: contain;
        }
        
        .org-name {
            font-size: 20pt;
            font-weight: 900;
            color: #6B46C1;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .org-details {
            font-size: 10pt;
            line-height: 1.6;
            color: #4a5568;
        }
        
        .org-details div {
            margin-bottom: 2px;
        }
        
        .invoice-title {
            font-size: 22pt;
            font-weight: 900;
            color: #E53E3E;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .badge-credit {
            background: #E53E3E;
            color: white;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 9pt;
            font-weight: 700;
            letter-spacing: 0.5px;
            display: inline-block;
        }
        
        /* ============================================
           INVOICE INFO BOX
           ============================================ */
        .invoice-info {
            background: #F7FAFC;
            padding: 15px;
            border-radius: 8px;
            border-left: 3px solid #6B46C1;
        }
        
        .invoice-info p {
            margin-bottom: 6px;
            font-size: 10pt;
        }
        
        .invoice-info p:last-child {
            margin-bottom: 0;
        }
        
        /* ============================================
           CREDIT NOTICE
           ============================================ */
        .credit-notice {
            background: #FED7D7;
            border-left: 4px solid #E53E3E;
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 5px;
        }
        
        .credit-notice h5 {
            color: #E53E3E;
            margin-bottom: 6px;
            font-size: 12pt;
            font-weight: 700;
        }
        
        .credit-notice p {
            margin-bottom: 4px;
            font-size: 10pt;
        }
        
        /* ============================================
           SECTION HEADINGS
           ============================================ */
        .section-heading {
            color: #6B46C1;
            font-size: 12pt;
            font-weight: 700;
            margin-bottom: 12px;
            padding-bottom: 5px;
            border-bottom: 1px solid #E2E8F0;
        }
        
        /* ============================================
           ITEMS TABLE
           ============================================ */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .items-table thead th {
            background: #E53E3E;
            color: white;
            padding: 10px 8px;
            font-size: 10pt;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .items-table thead th.text-center { text-align: center; }
        .items-table thead th.text-end { text-align: right; }
        
        .items-table tbody td {
            padding: 9px 8px;
            font-size: 10pt;
            border-bottom: 1px solid #E2E8F0;
            vertical-align: middle;
        }
        
        .items-table tbody tr:nth-child(even) {
            background: #FAFAFA;
        }
        
        .items-table tbody td.text-center { text-align: center; }
        .items-table tbody td.text-end { text-align: right; }
        
        .product-code {
            font-family: 'Courier New', monospace;
            font-size: 9pt;
            color: #718096;
        }
        
        /* ============================================
           TOTALS SECTION
           ============================================ */
        .totals-wrapper {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 25px;
        }
        
        .totals-box {
            width: 60%;
            background: #F7FAFC;
            padding: 15px 18px;
            border-radius: 8px;
            border: 1px solid #E2E8F0;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 10.5pt;
        }
        
        .total-row.grand-total {
            background: #E53E3E;
            color: white;
            padding: 12px 15px;
            border-radius: 5px;
            font-size: 13pt;
            font-weight: 900;
            margin-top: 8px;
        }
        
        /* ============================================
           PAYMENT TERMS
           ============================================ */
        .payment-terms {
            background: #EDF2F7;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #6B46C1;
        }
        
        .payment-terms h5 {
            color: #6B46C1;
            font-size: 11pt;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .payment-terms ul {
            padding-left: 20px;
            margin-bottom: 0;
            font-size: 10pt;
            line-height: 1.7;
        }
        
        .payment-terms ul li {
            margin-bottom: 3px;
        }
        
        /* ============================================
           SIGNATURE SECTION
           ============================================ */
        .signature-section {
            margin-top: 40px;
            padding-top: 25px;
            border-top: 2px solid #E2E8F0;
        }
        
        .signature-box {
            border-top: 2px solid #2D3748;
            padding-top: 10px;
            margin-top: 60px;
            text-align: center;
            font-size: 10pt;
        }
        
        /* ============================================
           FOOTER
           ============================================ */
        .invoice-footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #E2E8F0;
            text-align: center;
            font-size: 9pt;
            color: #718096;
        }
        
        .invoice-footer p {
            margin-bottom: 4px;
        }
        
        /* ============================================
           ACTION BUTTONS (hidden on print)
           ============================================ */
        .action-buttons {
            max-width: 210mm;
            margin: 20px auto 0;
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            color: white;
            font-family: Arial, sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
            color: white;
        }
        
        .btn-print { background: #E53E3E; }
        .btn-print:hover { background: #C53030; }
        
        .btn-pos { background: #6B46C1; }
        .btn-pos:hover { background: #553C9A; }
        
        .btn-back { background: #4A5568; }
        .btn-back:hover { background: #2D3748; }
        
        /* ============================================
           PRINT STYLES
           ============================================ */
        @media print {
            body {
                background: white;
                padding: 0;
                font-size: 10pt;
            }
            
            .invoice-container {
                box-shadow: none;
                border-radius: 0;
                width: 100%;
                min-height: auto;
                padding: 0;
                margin: 0;
            }
            
            .action-buttons {
                display: none !important;
            }
            
            .items-table thead th {
                background: #E53E3E !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .grand-total {
                background: #E53E3E !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .credit-notice {
                background: #FED7D7 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .payment-terms {
                background: #EDF2F7 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .badge-credit {
                background: #E53E3E !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .invoice-info {
                background: #F7FAFC !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    
    <div class="invoice-container">
        
        <!-- ============================================
             HEADER - ORGANIZATION DETAILS
             ============================================ -->
        <div class="invoice-header">
            <div class="row">
                <div class="col-6">
                    <?php if (!empty($org['logo']) && file_exists('../' . $org['logo'])): ?>
                        <img src="../<?php echo htmlspecialchars($org['logo']); ?>" 
                             alt="Logo" class="org-logo-img mb-2">
                    <?php else: ?>
                        <div class="company-logo mb-2">
                            <i class="fas fa-bolt"></i>
                        </div>
                    <?php endif; ?>
                    
                    <div class="org-name"><?php echo htmlspecialchars($org['org_name']); ?></div>
                    
                    <div class="org-details">
                        <?php if (!empty($org['address'])): ?>
                            <div><i class="fas fa-map-marker-alt me-2"></i><?php echo htmlspecialchars($org['address']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($org['phone'])): ?>
                            <div><i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($org['phone']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($org['email'])): ?>
                            <div><i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($org['email']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($org['website'])): ?>
                            <div><i class="fas fa-globe me-2"></i><?php echo htmlspecialchars($org['website']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($org['tin'])): ?>
                            <div><strong>TIN:</strong> <?php echo htmlspecialchars($org['tin']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="col-6 text-end">
                    <div class="invoice-title mb-3">CREDIT INVOICE</div>
                    <span class="badge-credit">PAYMENT REQUIRED</span>
                    
                    <div class="invoice-info text-start mt-3">
                        <p class="mb-2"><strong>Invoice #:</strong> <?php echo htmlspecialchars($sale['invoice_number']); ?></p>
                        <p class="mb-2"><strong>Date Issued:</strong> <?php echo date('d/m/Y', strtotime($sale['sale_date'])); ?></p>
                        <p class="mb-2"><strong>Due Date:</strong> <span class="text-danger"><?php echo date('d/m/Y', strtotime($due_date)); ?></span></p>
                        <p class="mb-0"><strong>Served By:</strong> <?php echo htmlspecialchars($sale['served_by_name'] ?? 'N/A'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ============================================
             CREDIT NOTICE
             ============================================ -->
        <div class="credit-notice">
            <h5><i class="fas fa-exclamation-triangle me-2"></i>CREDIT SALE - PAYMENT REQUIRED</h5>
            <p class="mb-1">
                This is a credit sale transaction. Full payment is required by 
                <strong><?php echo date('d F Y', strtotime($due_date)); ?></strong>
            </p>
            <p class="mb-0">
                Current Balance Due: 
                <strong class="text-danger">UGX <?php echo number_format($sale['amount_due'] ?? 0, 2); ?></strong>
            </p>
        </div>
        
        <!-- ============================================
             CUSTOMER + PAYMENT STATUS
             ============================================ -->
        <div class="row mb-4">
            <div class="col-6">
                <div class="section-heading">
                    <i class="fas fa-user me-2"></i>BILL TO
                </div>
                <div class="invoice-info">
                    <p class="mb-2"><strong><?php echo htmlspecialchars($sale['customer_name'] ?? 'N/A'); ?></strong></p>
                    <?php if (!empty($sale['customer_code'])): ?>
                        <p class="mb-2"><small class="text-muted">Customer Code: <?php echo htmlspecialchars($sale['customer_code']); ?></small></p>
                    <?php endif; ?>
                    <?php if (!empty($sale['customer_address'])): ?>
                        <p class="mb-2"><i class="fas fa-map-marker-alt me-2"></i><?php echo htmlspecialchars($sale['customer_address']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($sale['customer_phone'])): ?>
                        <p class="mb-2"><i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($sale['customer_phone']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($sale['customer_email'])): ?>
                        <p class="mb-0"><i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($sale['customer_email']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-6">
                <div class="section-heading">
                    <i class="fas fa-file-invoice-dollar me-2"></i>PAYMENT STATUS
                </div>
                <div class="invoice-info">
                    <p class="mb-2">
                        <strong>Invoice Amount:</strong> 
                        <span style="font-size: 12pt; font-weight: 900;">UGX <?php echo number_format($sale['total_amount'] ?? 0, 2); ?></span>
                    </p>
                    <p class="mb-2">
                        <strong>Amount Paid:</strong> 
                        UGX <?php echo number_format($sale['amount_paid'] ?? 0, 2); ?>
                    </p>
                    <p class="mb-2">
                        <strong>Balance Due:</strong> 
                        <span class="text-danger" style="font-size: 11pt; font-weight: 900;">
                            UGX <?php echo number_format($sale['amount_due'] ?? 0, 2); ?>
                        </span>
                    </p>
                    <p class="mb-0">
                        <strong>Status:</strong> 
                        <?php if (($sale['payment_status'] ?? '') == 'unpaid'): ?>
                            <span class="badge bg-danger">UNPAID</span>
                        <?php elseif (($sale['payment_status'] ?? '') == 'partial'): ?>
                            <span class="badge bg-warning">PARTIAL</span>
                        <?php else: ?>
                            <span class="badge bg-success">PAID</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        
        <!-- ============================================
             ITEMS TABLE
             ============================================ -->
        <div class="section-heading">
            <i class="fas fa-list me-2"></i>INVOICE ITEMS
        </div>
        
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 15%;">Code</th>
                    <th style="width: 35%;">Product Name</th>
                    <th class="text-center" style="width: 10%;">Qty</th>
                    <th class="text-end" style="width: 12%;">Unit Price</th>
                    <th class="text-end" style="width: 10%;">Tax</th>
                    <th class="text-end" style="width: 13%;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($sale_items as $item): 
                ?>
                <tr>
                    <td><?php echo $counter++; ?></td>
                    <td class="product-code"><?php echo htmlspecialchars($item['product_code'] ?? 'N/A'); ?></td>
                    <td><strong><?php echo htmlspecialchars($item['product_name'] ?? 'Unknown'); ?></strong></td>
                    <td class="text-center"><?php echo intval($item['quantity']); ?></td>
                    <td class="text-end">UGX <?php echo number_format(floatval($item['unit_price']), 2); ?></td>
                    <td class="text-end">UGX <?php echo number_format(floatval($item['tax_amount'] ?? 0), 2); ?></td>
                    <td class="text-end"><strong>UGX <?php echo number_format(floatval($item['total_price']), 2); ?></strong></td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($sale_items)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted" style="padding: 20px;">No items found</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- ============================================
             TOTALS
             ============================================ -->
        <div class="totals-wrapper">
            <div class="totals-box">
                <div class="total-row">
                    <span>Subtotal:</span>
                    <strong>UGX <?php echo number_format(floatval($sale['subtotal'] ?? 0), 2); ?></strong>
                </div>
                
                <?php if (floatval($sale['tax_amount'] ?? 0) > 0): ?>
                <div class="total-row">
                    <span>Tax:</span>
                    <strong>UGX <?php echo number_format(floatval($sale['tax_amount']), 2); ?></strong>
                </div>
                <?php endif; ?>
                
                <?php if (floatval($sale['discount_amount'] ?? 0) > 0): ?>
                <div class="total-row">
                    <span>Discount:</span>
                    <strong class="text-success">- UGX <?php echo number_format(floatval($sale['discount_amount']), 2); ?></strong>
                </div>
                <?php endif; ?>
                
                <div class="total-row grand-total">
                    <span>TOTAL AMOUNT DUE:</span>
                    <span>UGX <?php echo number_format(floatval($sale['amount_due'] ?? 0), 2); ?></span>
                </div>
            </div>
        </div>
        
        <!-- ============================================
             PAYMENT TERMS
             ============================================ -->
        <div class="payment-terms">
            <h5><i class="fas fa-clipboard-list me-2"></i>Payment Terms & Conditions</h5>
            <ul class="mb-0">
                <li>Payment is due within <strong>30 days</strong> from the invoice date</li>
                <li>Payment due date: <strong><?php echo date('d F Y', strtotime($due_date)); ?></strong></li>
                <li>Late payments may incur additional charges of 2% per month</li>
                <li>Please reference invoice number <strong><?php echo htmlspecialchars($sale['invoice_number']); ?></strong> when making payment</li>
                <li>Acceptable payment methods: Bank Transfer, Mobile Money, Cash, Cheque</li>
                <li>All payments are final and non-refundable unless agreed otherwise</li>
                <li>Goods remain the property of the seller until payment is received in full</li>
            </ul>
        </div>
        
        <!-- ============================================
             BANK DETAILS
             ============================================ -->
        <div class="payment-terms mt-3">
            <h5><i class="fas fa-university me-2"></i>Bank Details for Payment</h5>
            <p class="mb-1"><strong>Bank Name:</strong> [Your Bank Name]</p>
            <p class="mb-1"><strong>Account Name:</strong> <?php echo htmlspecialchars($org['org_name']); ?></p>
            <p class="mb-1"><strong>Account Number:</strong> [Your Account Number]</p>
            <p class="mb-1"><strong>Mobile Money:</strong> [Your Mobile Money Number]</p>
            <?php if (!empty($org['phone'])): ?>
            <p class="mb-0"><small class="text-muted">Send payment confirmation to: <strong><?php echo htmlspecialchars($org['phone']); ?></strong></small></p>
            <?php endif; ?>
        </div>
        
        <!-- ============================================
             SIGNATURES
             ============================================ -->
        <div class="signature-section">
            <div class="row">
                <div class="col-6">
                    <div class="signature-box">
                        <strong>Customer Signature</strong><br>
                        <small class="text-muted">I acknowledge receipt of goods and agree to the payment terms stated above</small>
                    </div>
                </div>
                <div class="col-6">
                    <div class="signature-box">
                        <strong>Authorized Signature</strong><br>
                        <small class="text-muted"><?php echo htmlspecialchars($sale['served_by_name'] ?? 'N/A'); ?></small><br>
                        <small class="text-muted"><?php echo htmlspecialchars($org['org_name']); ?></small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ============================================
             FOOTER
             ============================================ -->
        <div class="invoice-footer">
            <p class="mb-1"><strong>Thank you for your business!</strong></p>
            <?php if (!empty($org['phone'])): ?>
                <p class="mb-2">For any queries regarding this invoice, please contact us at <?php echo htmlspecialchars($org['phone']); ?></p>
            <?php endif; ?>
            <p class="mb-0"><small>Invoice generated on <?php echo date('d/m/Y H:i:s'); ?> | This is a computer-generated invoice and requires signature to be valid</small></p>
        </div>
    </div>
    
    <!-- ============================================
         ACTION BUTTONS (hidden on print)
         ============================================ -->
    <?php if (!$is_autoprint): ?>
    <div class="action-buttons">
        <button onclick="window.print()" class="action-btn btn-print">
            <i class="fas fa-print"></i> Print Invoice
        </button>
        <a href="view-sale.php?id=<?php echo $sale_id; ?>" class="action-btn btn-back">
            <i class="fas fa-arrow-left"></i> Back to Sale
        </a>
        <a href="pos.php" class="action-btn btn-pos">
            <i class="fas fa-cash-register"></i> Back to POS
        </a>
    </div>
    <?php endif; ?>
    
    <!-- ============================================
         AUTO-PRINT
         ============================================ -->
    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const shouldAutoPrint = urlParams.get('print_only') === '1' || urlParams.get('autoprint') === '1';
        
        if (shouldAutoPrint) {
            document.querySelectorAll('.action-buttons').forEach(el => {
                el.style.display = 'none';
            });
            
            window.addEventListener('load', function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            });
        }
    </script>
    
</body>
</html>