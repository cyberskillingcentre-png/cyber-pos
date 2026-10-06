<?php
/**
 * QUICK SETUP TOOL
 * Add sample data to test POS system immediately
 */

require_once 'config/config.php';
require_admin();

$success_messages = [];
$error_messages = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['setup'])) {
    try {
        $conn->beginTransaction();
        
        // Check if data already exists
        $stmt = $conn->query("SELECT COUNT(*) as count FROM products");
        $has_products = $stmt->fetch()['count'] > 0;
        
        if ($has_products && !isset($_POST['force'])) {
            $error_messages[] = "Data already exists! Check 'Force Reset' to clear and add sample data.";
        } else {
            // Clear existing data if force reset
            if (isset($_POST['force'])) {
                $conn->exec("DELETE FROM sale_items");
                $conn->exec("DELETE FROM sales");
                $conn->exec("DELETE FROM store_inventory");
                $conn->exec("DELETE FROM products");
                $conn->exec("DELETE FROM customers");
                $conn->exec("DELETE FROM suppliers");
                $conn->exec("DELETE FROM categories");
                $conn->exec("DELETE FROM stores WHERE store_id > 1");
                $success_messages[] = "Existing data cleared!";
            }
            
            // Add Categories
            $categories = [
                ['Electrical Cables', 'CAT001', 'Various electrical cables and wires'],
                ['Switches & Sockets', 'CAT002', 'Wall switches and power sockets'],
                ['Lighting', 'CAT003', 'LED bulbs, tubes and fixtures'],
                ['Circuit Breakers', 'CAT004', 'MCBs, RCDs and distribution boards'],
                ['Electronics', 'CAT005', 'Electronic components and devices'],
                ['Power Tools', 'CAT006', 'Electrical power tools'],
                ['Security Systems', 'CAT007', 'CCTV and security equipment'],
                ['Solar Equipment', 'CAT008', 'Solar panels and inverters']
            ];
            
            foreach ($categories as $cat) {
                $stmt = $conn->prepare("INSERT INTO categories (category_name, category_code, description) VALUES (?, ?, ?)");
                $stmt->execute($cat);
            }
            $success_messages[] = "✅ Added 8 categories";
            
            // Add Supplier
            $stmt = $conn->prepare("INSERT INTO suppliers (supplier_name, supplier_code, contact_person, phone, email) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute(['Electrical Wholesale Ltd', 'SUP001', 'John Supplier', '+256-700-123456', 'sales@supplier.com']);
            $success_messages[] = "✅ Added 1 supplier";
            
            // Add Sample Products
            $products = [
                ['PRD001', 'BC001', '2.5mm Twin Cable (100m)', 1, 150000, 180000, 20, 18, 'no'],
                ['PRD002', 'BC002', '4mm Twin Cable (100m)', 1, 250000, 300000, 20, 18, 'no'],
                ['PRD003', 'SW001', 'Single Switch White', 2, 5000, 8000, 60, 18, 'no'],
                ['PRD004', 'SW002', 'Double Switch White', 2, 8000, 12000, 50, 18, 'no'],
                ['PRD005', 'SO001', 'Single Socket 13A', 2, 6000, 10000, 66, 18, 'no'],
                ['PRD006', 'LED001', 'LED Bulb 9W White', 3, 8000, 12000, 50, 18, 'no'],
                ['PRD007', 'LED002', 'LED Bulb 12W White', 3, 12000, 18000, 50, 18, 'no'],
                ['PRD008', 'LED003', 'LED Tube 18W 4ft', 3, 15000, 22000, 46, 18, 'no'],
                ['PRD009', 'MCB001', 'MCB 16A Single Pole', 4, 8000, 12000, 50, 18, 'no'],
                ['PRD010', 'MCB002', 'MCB 32A Single Pole', 4, 10000, 15000, 50, 18, 'no'],
                ['PRD011', 'RCD001', 'RCD 40A 30mA', 4, 45000, 60000, 33, 18, 'no'],
                ['PRD012', 'EXT001', 'Extension Cable 5m', 1, 15000, 25000, 66, 18, 'no'],
                ['PRD013', 'PLG001', 'Plug 13A 3Pin', 5, 2000, 4000, 100, 18, 'no'],
                ['PRD014', 'DRL001', 'Drill Machine 13mm', 6, 120000, 180000, 50, 18, 'no'],
                ['PRD015', 'GRN001', 'Angle Grinder 115mm', 6, 80000, 120000, 50, 18, 'no']
            ];
            
            $product_ids = [];
            foreach ($products as $prod) {
                $stmt = $conn->prepare("INSERT INTO products (product_code, barcode, product_name, category_id, supplier_id, purchase_price, selling_price, profit_margin, tax_rate, has_expiry, created_by) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$prod[0], $prod[1], $prod[2], $prod[3], $prod[4], $prod[5], $prod[6], $prod[7], $prod[8], $_SESSION['user_id']]);
                $product_ids[] = $conn->lastInsertId();
            }
            $success_messages[] = "✅ Added 15 sample products";
            
            // Add Inventory to Main Store
            $stmt = $conn->prepare("SELECT store_id FROM stores WHERE status = 'active' ORDER BY store_id LIMIT 1");
            $stmt->execute();
            $store = $stmt->fetch();
            
            if ($store) {
                $store_id = $store['store_id'];
                
                foreach ($product_ids as $pid) {
                    $quantity = rand(10, 100);
                    $stmt = $conn->prepare("INSERT INTO store_inventory (store_id, product_id, batch_number, quantity) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$store_id, $pid, 'BATCH' . date('Ymd') . rand(100, 999), $quantity]);
                }
                $success_messages[] = "✅ Added inventory to store (10-100 units per product)";
            }
            
            // Add Sample Customers
            $customers = [
                ['CUST001', 'John Doe', 'john@example.com', '+256-700-111111', 'Kampala, Uganda', 'regular', 0],
                ['CUST002', 'Jane Smith', 'jane@example.com', '+256-700-222222', 'Entebbe, Uganda', 'regular', 0],
                ['CUST003', 'ABC Constructions Ltd', 'abc@constructions.com', '+256-700-333333', 'Kampala, Uganda', 'wholesale', 10],
                ['CUST004', 'XYZ Electricals', 'xyz@electricals.com', '+256-700-444444', 'Jinja, Uganda', 'vip', 15]
            ];
            
            foreach ($customers as $cust) {
                $stmt = $conn->prepare("INSERT INTO customers (customer_code, customer_name, email, phone, address, customer_type, discount_percentage) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute($cust);
            }
            $success_messages[] = "✅ Added 4 sample customers";
            
            $conn->commit();
            $success_messages[] = "<br><strong>🎉 SETUP COMPLETE!</strong><br>Your POS system is ready to use!";
        }
    } catch(Exception $e) {
        $conn->rollBack();
        $error_messages[] = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Quick Setup - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #6B46C1 0%, #ED8936 100%); min-height: 100vh; padding: 20px; }
        .setup-card { max-width: 800px; margin: 0 auto; background: white; border-radius: 20px; padding: 30px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); }
        h2 { color: #6B46C1; }
        .feature-box { background: #f8f9fa; padding: 15px; border-radius: 10px; margin: 10px 0; border-left: 4px solid #6B46C1; }
    </style>
</head>
<body>
    <div class="setup-card">
        <h2 class="text-center mb-4">
            <i class="fas fa-rocket me-2"></i>
            Quick Setup Tool
        </h2>
        
        <?php if (!empty($success_messages)): ?>
            <div class="alert alert-success">
                <?php foreach ($success_messages as $msg): ?>
                    <?php echo $msg; ?><br>
                <?php endforeach; ?>
            </div>
            <div class="text-center mt-4">
                <a href="admin/pos.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-cash-register me-2"></i>Open POS System
                </a>
                <a href="admin/dashboard.php" class="btn btn-secondary btn-lg">
                    <i class="fas fa-tachometer-alt me-2"></i>Go to Dashboard
                </a>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_messages)): ?>
            <div class="alert alert-danger">
                <?php foreach ($error_messages as $msg): ?>
                    <?php echo $msg; ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if (empty($success_messages)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                This tool will add sample data to your POS system so you can test it immediately!
            </div>
            
            <div class="feature-box">
                <h5><i class="fas fa-check-circle text-success me-2"></i>What will be added:</h5>
                <ul>
                    <li>✅ 8 Product Categories (Cables, Switches, Lighting, etc.)</li>
                    <li>✅ 15 Sample Products with prices</li>
                    <li>✅ Inventory stock (10-100 units per product)</li>
                    <li>✅ 1 Supplier</li>
                    <li>✅ 4 Sample Customers (Regular, Wholesale, VIP)</li>
                </ul>
            </div>
            
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Note:</strong> This is sample data for testing. You can delete it later and add your real products.
            </div>
            
            <form method="POST">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="force" id="force">
                    <label class="form-check-label" for="force">
                        <strong>Force Reset</strong> - Clear existing data and add fresh sample data
                    </label>
                </div>
                
                <div class="text-center">
                    <button type="submit" name="setup" class="btn btn-primary btn-lg">
                        <i class="fas fa-magic me-2"></i>
                        Setup Sample Data
                    </button>
                    <a href="admin/dashboard.php" class="btn btn-secondary btn-lg">
                        <i class="fas fa-times me-2"></i>
                        Cancel
                    </a>
                </div>
            </form>
            
            <hr class="my-4">
            
            <div class="text-center">
                <h6>Or manually add data:</h6>
                <a href="admin/products.php" class="btn btn-sm btn-outline-primary">Add Products</a>
                <a href="admin/categories.php" class="btn btn-sm btn-outline-primary">Add Categories</a>
                <a href="admin/inventory.php" class="btn btn-sm btn-outline-primary">Add Inventory</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
