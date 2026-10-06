<?php
require_once '../config/config.php';
require_admin();

if (isset($_POST['purchase_id'])) {
    $purchase_id = intval($_POST['purchase_id']);
    
    // Fetch purchase details
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            s.supplier_name, s.contact_person, s.phone, s.email,
            u.full_name as created_by_name
        FROM purchases p
        LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
        LEFT JOIN users u ON p.created_by = u.user_id
        WHERE p.purchase_id = ?
    ");
    $stmt->execute([$purchase_id]);
    $purchase = $stmt->fetch();
    
    // Fetch purchase items
    $stmt = $conn->prepare("
        SELECT 
            pi.*,
            pr.product_code, pr.product_name, pr.unit
        FROM purchase_items pi
        LEFT JOIN products pr ON pi.product_id = pr.product_id
        WHERE pi.purchase_id = ?
    ");
    $stmt->execute([$purchase_id]);
    $items = $stmt->fetchAll();
    
    // Fetch payment history
    $stmt = $conn->prepare("
        SELECT sp.*, u.full_name as recorded_by_name 
        FROM supplier_payments sp
        LEFT JOIN users u ON sp.recorded_by = u.user_id
        WHERE sp.purchase_id = ?
        ORDER BY sp.payment_date DESC, sp.created_at DESC
    ");
    $stmt->execute([$purchase_id]);
    $payments = $stmt->fetchAll();
    
    // Format dates
    $purchase['purchase_date_formatted'] = date('d/m/Y', strtotime($purchase['purchase_date']));
    $purchase['created_at_formatted'] = date('d/m/Y H:i', strtotime($purchase['created_at']));
    
    // Determine status badges
    $payment_status_badge = '';
    if ($purchase['payment_status'] == 'paid') {
        $payment_status_badge = '<span class="badge bg-success">Paid</span>';
    } elseif ($purchase['payment_status'] == 'partial') {
        $payment_status_badge = '<span class="badge bg-warning">Partial</span>';
    } else {
        $payment_status_badge = '<span class="badge bg-danger">Unpaid</span>';
    }
    
    $order_status_badge = '';
    if ($purchase['status'] == 'received') {
        $order_status_badge = '<span class="badge bg-success">Received</span>';
    } elseif ($purchase['status'] == 'pending') {
        $order_status_badge = '<span class="badge bg-warning">Pending</span>';
    } else {
        $order_status_badge = '<span class="badge bg-secondary">Cancelled</span>';
    }
    
    // Build HTML
    $html = '
    <div class="row">
        <div class="col-md-6">
            <h6>PO Information</h6>
            <table class="table table-sm">
                <tr>
                    <th>PO Number:</th>
                    <td><strong>' . $purchase['purchase_number'] . '</strong></td>
                </tr>
                <tr>
                    <th>Date:</th>
                    <td>' . $purchase['purchase_date_formatted'] . '</td>
                </tr>
                <tr>
                    <th>Status:</th>
                    <td>' . $order_status_badge . '</td>
                </tr>
                <tr>
                    <th>Created By:</th>
                    <td>' . $purchase['created_by_name'] . '</td>
                </tr>
                <tr>
                    <th>Created At:</th>
                    <td>' . $purchase['created_at_formatted'] . '</td>
                </tr>
            </table>
        </div>
        <div class="col-md-6">
            <h6>Supplier Information</h6>
            <table class="table table-sm">
                <tr>
                    <th>Supplier:</th>
                    <td>' . $purchase['supplier_name'] . '</td>
                </tr>
                <tr>
                    <th>Contact:</th>
                    <td>' . $purchase['contact_person'] . '</td>
                </tr>
                <tr>
                    <th>Phone:</th>
                    <td>' . $purchase['phone'] . '</td>
                </tr>
                <tr>
                    <th>Email:</th>
                    <td>' . $purchase['email'] . '</td>
                </tr>
            </table>
        </div>
    </div>
    
    <hr>
    
    <div class="row">
        <div class="col-md-6">
            <h6>Payment Information</h6>
            <table class="table table-sm">
                <tr>
                    <th>Total Amount:</th>
                    <td><strong>UGX ' . number_format($purchase['total_amount'], 2) . '</strong></td>
                </tr>
                <tr>
                    <th>Amount Paid:</th>
                    <td>UGX ' . number_format($purchase['paid_amount'], 2) . '</td>
                </tr>
                <tr>
                    <th>Balance:</th>
                    <td>UGX ' . number_format($purchase['total_amount'] - $purchase['paid_amount'], 2) . '</td>
                </tr>
                <tr>
                    <th>Payment Status:</th>
                    <td>' . $payment_status_badge . '</td>
                </tr>
                <tr>
                    <th>Payment Method:</th>
                    <td>' . ucfirst($purchase['payment_method']) . '</td>
                </tr>
            </table>
        </div>
        <div class="col-md-6">
            <h6>Notes</h6>
            <p>' . (!empty($purchase['notes']) ? nl2br($purchase['notes']) : 'No notes') . '</p>
        </div>
    </div>
    
    <hr>
    
    <h6>Products</h6>
    <div class="table-responsive">
        <table class="table table-sm table-bordered">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Received</th>
                    <th>Unit Cost</th>
                    <th>Total Cost</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($items as $item) {
        $html .= '
                <tr>
                    <td>' . $item['product_code'] . ' - ' . $item['product_name'] . '</td>
                    <td>' . $item['quantity'] . ' ' . $item['unit'] . '</td>
                    <td>' . $item['received_quantity'] . ' ' . $item['unit'] . '</td>
                    <td>UGX ' . number_format($item['unit_cost'], 2) . '</td>
                    <td>UGX ' . number_format($item['total_cost'], 2) . '</td>
                </tr>';
    }
    
    $html .= '
            </tbody>
        </table>
    </div>
    
    <hr>
    
    <h6>Payment History</h6>';
    
    if (count($payments) > 0) {
        $html .= '
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Recorded By</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($payments as $payment) {
            $html .= '
                    <tr>
                        <td>' . date('d/m/Y', strtotime($payment['payment_date'])) . '</td>
                        <td>UGX ' . number_format($payment['payment_amount'], 2) . '</td>
                        <td>' . ucfirst(str_replace('_', ' ', $payment['payment_method'])) . '</td>
                        <td>' . $payment['reference_number'] . '</td>
                        <td>' . $payment['recorded_by_name'] . '</td>
                        <td>' . (!empty($payment['notes']) ? $payment['notes'] : '-') . '</td>
                    </tr>';
        }
        
        $html .= '
                </tbody>
            </table>
        </div>';
    } else {
        $html .= '<p class="text-muted">No payment history found.</p>';
    }
    
    echo $html;
}