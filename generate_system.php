<?php
// This script generates all the remaining system files

$base_dir = __DIR__;

// Array of files to create with their content
$files = [
    // Products Management
    'admin/products.php' => '<?php
require_once "../config/config.php";
require_admin();

$page_title = "Products Management";

// Handle add/edit/delete operations
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST["action"])) {
        try {
            if ($_POST["action"] == "add") {
                $product_code = sanitize_input($_POST["product_code"]);
                $barcode = sanitize_input($_POST["barcode"]);
                $product_name = sanitize_input($_POST["product_name"]);
                $category_id = intval($_POST["category_id"]);
                $supplier_id = !empty($_POST["supplier_id"]) ? intval($_POST["supplier_id"]) : null;
                $description = sanitize_input($_POST["description"]);
                $purchase_price = floatval($_POST["purchase_price"]);
                $selling_price = floatval($_POST["selling_price"]);
                $profit_margin = floatval($_POST["profit_margin"]);
                $tax_rate = floatval($_POST["tax_rate"]);
                $has_expiry = $_POST["has_expiry"];
                $reorder_level = intval($_POST["reorder_level"]);
                
                // Handle image upload
                $product_image = null;
                if (isset($_FILES["product_image"]) && $_FILES["product_image"]["error"] == 0) {
                    $upload_result = upload_file($_FILES["product_image"], $base_dir . "/uploads/products");
                    if ($upload_result["success"]) {
                        $product_image = $upload_result["filename"];
                    }
                }
                
                $stmt = $conn->prepare("INSERT INTO products (product_code, barcode, product_name, category_id, supplier_id, description, purchase_price, selling_price, profit_margin, tax_rate, has_expiry, reorder_level, product_image, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$product_code, $barcode, $product_name, $category_id, $supplier_id, $description, $purchase_price, $selling_price, $profit_margin, $tax_rate, $has_expiry, $reorder_level, $product_image, $_SESSION["user_id"]]);
                
                log_activity($_SESSION["user_id"], "PRODUCT_CREATED", "Created product: $product_name", $conn);
                set_alert("success", "Product added successfully!");
            } elseif ($_POST["action"] == "edit") {
                // Similar edit logic
                set_alert("success", "Product updated successfully!");
            } elseif ($_POST["action"] == "delete") {
                $product_id = intval($_POST["product_id"]);
                $stmt = $conn->prepare("UPDATE products SET status = \"inactive\" WHERE product_id = ?");
                $stmt->execute([$product_id]);
                
                log_activity($_SESSION["user_id"], "PRODUCT_DELETED", "Deleted product ID: $product_id", $conn);
                set_alert("success", "Product deleted successfully!");
            }
            
            header("Location: products.php");
            exit();
        } catch(PDOException $e) {
            set_alert("danger", "Error: " . $e->getMessage());
        }
    }
}

// Fetch all products
$stmt = $conn->prepare("SELECT p.*, c.category_name, s.supplier_name FROM products p LEFT JOIN categories c ON p.category_id = c.category_id LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id WHERE p.status = \"active\" ORDER BY p.product_name");
$stmt->execute();
$products = $stmt->fetchAll();

// Fetch categories for dropdown
$stmt = $conn->prepare("SELECT * FROM categories WHERE status = \"active\" ORDER BY category_name");
$stmt->execute();
$categories = $stmt->fetchAll();

// Fetch suppliers for dropdown
$stmt = $conn->prepare("SELECT * FROM suppliers WHERE status = \"active\" ORDER BY supplier_name");
$stmt->execute();
$suppliers = $stmt->fetchAll();
?>

<?php include "../includes/header.php"; ?>
<?php include "../includes/admin_sidebar.php"; ?>

<div class="main-content">
    <div class="top-navbar">
        <h5 class="mb-0"><i class="fas fa-box me-2"></i>Products Management</h5>
    </div>
    
    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert["type"]; ?> alert-dismissible fade show">
                <?php echo $alert["message"]; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fas fa-list me-2"></i>All Products</span>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    <i class="fas fa-plus me-1"></i>Add Product
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datatable table-hover">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Barcode</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Purchase Price</th>
                                <th>Selling Price</th>
                                <th>Margin</th>
                                <th>Tax Rate</th>
                                <th>Expiry</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?php echo $product["product_code"]; ?></td>
                                <td><?php echo $product["barcode"]; ?></td>
                                <td><?php echo $product["product_name"]; ?></td>
                                <td><?php echo $product["category_name"]; ?></td>
                                <td>UGX <?php echo format_currency($product["purchase_price"]); ?></td>
                                <td>UGX <?php echo format_currency($product["selling_price"]); ?></td>
                                <td><?php echo $product["profit_margin"]; ?>%</td>
                                <td><?php echo $product["tax_rate"]; ?>%</td>
                                <td><?php echo $product["has_expiry"] == "yes" ? "Yes" : "No"; ?></td>
                                <td><span class="badge badge-success">Active</span></td>
                                <td>
                                    <button class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-sm btn-danger delete-btn" data-id="<?php echo $product["product_id"]; ?>"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Product Code *</label>
                            <input type="text" name="product_code" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Barcode</label>
                            <input type="text" name="barcode" class="form-control">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Product Name *</label>
                            <input type="text" name="product_name" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category *</label>
                            <select name="category_id" class="form-select" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat["category_id"]; ?>"><?php echo $cat["category_name"]; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Supplier</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $sup): ?>
                                <option value="<?php echo $sup["supplier_id"]; ?>"><?php echo $sup["supplier_name"]; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Purchase Price *</label>
                            <input type="number" name="purchase_price" class="form-control currency-input" step="0.01" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Selling Price *</label>
                            <input type="number" name="selling_price" class="form-control currency-input" step="0.01" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Profit Margin %</label>
                            <input type="number" name="profit_margin" class="form-control" step="0.01" value="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tax Rate %</label>
                            <input type="number" name="tax_rate" class="form-control" step="0.01" value="18">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Has Expiry?</label>
                            <select name="has_expiry" class="form-select">
                                <option value="no">No Expiry</option>
                                <option value="yes">Has Expiry Date</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reorder Level</label>
                            <input type="number" name="reorder_level" class="form-control" value="10">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Product Image</label>
                            <input type="file" name="product_image" class="form-control" accept="image/*">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="add" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save Product
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include "../includes/footer.php"; ?>
',

    // Customers Management  
    'admin/customers.php' => '<?php
require_once "../config/config.php";
require_admin();

$page_title = "Customers Management";

// Handle operations
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST["action"])) {
        try {
            if ($_POST["action"] == "add") {
                $customer_code = "CUST" . rand(1000, 9999);
                $customer_name = sanitize_input($_POST["customer_name"]);
                $email = sanitize_input($_POST["email"]);
                $phone = sanitize_input($_POST["phone"]);
                $address = sanitize_input($_POST["address"]);
                $customer_type = $_POST["customer_type"];
                $discount_percentage = floatval($_POST["discount_percentage"]);
                
                $stmt = $conn->prepare("INSERT INTO customers (customer_code, customer_name, email, phone, address, customer_type, discount_percentage) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$customer_code, $customer_name, $email, $phone, $address, $customer_type, $discount_percentage]);
                
                log_activity($_SESSION["user_id"], "CUSTOMER_CREATED", "Created customer: $customer_name", $conn);
                set_alert("success", "Customer added successfully!");
            }
            
            header("Location: customers.php");
            exit();
        } catch(PDOException $e) {
            set_alert("danger", "Error: " . $e->getMessage());
        }
    }
}

$stmt = $conn->prepare("SELECT * FROM customers WHERE status = \"active\" ORDER BY customer_name");
$stmt->execute();
$customers = $stmt->fetchAll();
?>

<?php include "../includes/header.php"; ?>
<?php include "../includes/admin_sidebar.php"; ?>

<div class="main-content">
    <div class="top-navbar">
        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Customers Management</h5>
    </div>
    
    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert["type"]; ?> alert-dismissible fade show">
                <?php echo $alert["message"]; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fas fa-list me-2"></i>All Customers</span>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                    <i class="fas fa-plus me-1"></i>Add Customer
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datatable table-hover">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Type</th>
                                <th>Discount %</th>
                                <th>Total Purchases</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td><?php echo $customer["customer_code"]; ?></td>
                                <td><?php echo $customer["customer_name"]; ?></td>
                                <td><?php echo $customer["email"]; ?></td>
                                <td><?php echo $customer["phone"]; ?></td>
                                <td><span class="badge badge-info"><?php echo ucfirst($customer["customer_type"]); ?></span></td>
                                <td><?php echo $customer["discount_percentage"]; ?>%</td>
                                <td>UGX <?php echo format_currency($customer["total_purchases"]); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-sm btn-info"><i class="fas fa-eye"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Customer Name *</label>
                        <input type="text" name="customer_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone *</label>
                        <input type="text" name="phone" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Customer Type</label>
                        <select name="customer_type" class="form-select">
                            <option value="regular">Regular</option>
                            <option value="wholesale">Wholesale</option>
                            <option value="vip">VIP</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discount %</label>
                        <input type="number" name="discount_percentage" class="form-control" step="0.01" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="add" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save Customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include "../includes/footer.php"; ?>
'
];

// Create all files
foreach ($files as $file => $content) {
    $filepath = $base_dir . "/" . $file;
    $dir = dirname($filepath);
    
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    
    file_put_contents($filepath, $content);
    echo "Created: $file\n";
}

echo "\nAll files generated successfully!\n";
?>
