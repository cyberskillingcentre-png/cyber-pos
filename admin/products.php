<?php
require_once '../config/config.php';
require_admin();

$page_title = "Products Management";

// Handle add/edit/delete operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    csrf_require();
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] == 'add') {
                $product_code = sanitize_input($_POST['product_code']);
                $barcode = sanitize_input($_POST['barcode']);
                $barcode = !empty($barcode) ? $barcode : null;
                
                $product_name = sanitize_input($_POST['product_name']);
                $category_id = intval($_POST['category_id']);
                $supplier_id = !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null;
                $description = sanitize_input($_POST['description']);
                $purchase_price = floatval($_POST['purchase_price']);
                $selling_price = floatval($_POST['selling_price']);
                $profit_margin = floatval($_POST['profit_margin']);
                $tax_rate = floatval($_POST['tax_rate']);
                $has_expiry = $_POST['has_expiry'];
                $reorder_level = intval($_POST['reorder_level']);
                
                $product_image = null;
                if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] == 0) {
                    $upload_result = upload_file($_FILES['product_image'], __DIR__ . '/../uploads/products');
                    if ($upload_result['success']) {
                        $product_image = $upload_result['filename'];
                    }
                }
                
                $stmt = $conn->prepare("INSERT INTO products (product_code, barcode, product_name, category_id, supplier_id, description, purchase_price, selling_price, profit_margin, tax_rate, has_expiry, reorder_level, product_image, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$product_code, $barcode, $product_name, $category_id, $supplier_id, $description, $purchase_price, $selling_price, $profit_margin, $tax_rate, $has_expiry, $reorder_level, $product_image, $_SESSION['user_id']]);
                
                log_activity($_SESSION['user_id'], 'PRODUCT_CREATED', "Created product: $product_name", $conn);
                set_alert('success', 'Product added successfully!');
                
            } elseif ($_POST['action'] == 'edit') {
                $product_id = intval($_POST['product_id']);
                $product_code = sanitize_input($_POST['product_code']);
                $barcode = sanitize_input($_POST['barcode']);
                $barcode = !empty($barcode) ? $barcode : null;
                
                $product_name = sanitize_input($_POST['product_name']);
                $category_id = intval($_POST['category_id']);
                $supplier_id = !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null;
                $description = sanitize_input($_POST['description']);
                $purchase_price = floatval($_POST['purchase_price']);
                $selling_price = floatval($_POST['selling_price']);
                $profit_margin = floatval($_POST['profit_margin']);
                $tax_rate = floatval($_POST['tax_rate']);
                $has_expiry = $_POST['has_expiry'];
                $reorder_level = intval($_POST['reorder_level']);
                
                $stmt = $conn->prepare("UPDATE products SET product_code = ?, barcode = ?, product_name = ?, category_id = ?, supplier_id = ?, description = ?, purchase_price = ?, selling_price = ?, profit_margin = ?, tax_rate = ?, has_expiry = ?, reorder_level = ? WHERE product_id = ?");
                $stmt->execute([$product_code, $barcode, $product_name, $category_id, $supplier_id, $description, $purchase_price, $selling_price, $profit_margin, $tax_rate, $has_expiry, $reorder_level, $product_id]);
                
                log_activity($_SESSION['user_id'], 'PRODUCT_UPDATED', "Updated product: $product_name", $conn);
                set_alert('success', 'Product updated successfully!');
                
            } elseif ($_POST['action'] == 'delete') {
                $product_id = intval($_POST['product_id']);
                $stmt = $conn->prepare("UPDATE products SET status = 'inactive' WHERE product_id = ?");
                $stmt->execute([$product_id]);
                
                log_activity($_SESSION['user_id'], 'PRODUCT_DELETED', "Deleted product ID: $product_id", $conn);
                set_alert('success', 'Product deleted successfully!');
            }
            
            header('Location: Products');
            exit();
        } catch(PDOException $e) {
            set_alert('danger', 'Error: ' . $e->getMessage());
        }
    }
}

// Fetch all products
$stmt = $conn->prepare("SELECT p.*, c.category_name, s.supplier_name FROM products p LEFT JOIN categories c ON p.category_id = c.category_id LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id WHERE p.status = 'active' ORDER BY p.product_name");
$stmt->execute();
$products = $stmt->fetchAll();

// Fetch categories for dropdown
$stmt = $conn->prepare("SELECT * FROM categories WHERE status = 'active' ORDER BY category_name");
$stmt->execute();
$categories = $stmt->fetchAll();

// Fetch suppliers for dropdown
$stmt = $conn->prepare("SELECT * FROM suppliers WHERE status = 'active' ORDER BY supplier_name");
$stmt->execute();
$suppliers = $stmt->fetchAll();
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <button class="btn btn-link d-md-none sidebar-toggle">
            <i class="fas fa-bars"></i>
        </button>
        <h5 class="mb-0"><i class="fas fa-box me-2"></i>Products Management</h5>
    </div>
    
    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fas fa-list me-2"></i>All Products (<?php echo count($products); ?>)</span>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    <i class="fas fa-plus me-1"></i>Add Product
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($products)): ?>
                    <!-- Empty State -->
                    <div class="text-center py-5">
                        <i class="fas fa-box-open fa-4x text-muted mb-3" style="opacity:0.2;"></i>
                        <h4 class="text-muted mb-2">No Products Added Yet</h4>
                        <p class="text-muted mb-4" style="font-size:0.9rem;">Your product catalog is currently empty. Add your first product to start selling.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                            <i class="fas fa-plus me-1"></i>Add Your First Product
                        </button>
                    </div>
                <?php else: ?>
                    <!-- Products Table -->
                    <div class="table-responsive">
                        <table class="table datatable table-hover" id="productsTable">
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
                                    <td><?php echo htmlspecialchars($product['product_code']); ?></td>
                                    <td><?php echo htmlspecialchars($product['barcode'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                    <td><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></td>
                                    <td>UGX <?php echo number_format($product['purchase_price'], 0); ?></td>
                                    <td>UGX <?php echo number_format($product['selling_price'], 0); ?></td>
                                    <td><?php echo number_format($product['profit_margin'], 2); ?>%</td>
                                    <td><?php echo number_format($product['tax_rate'], 2); ?>%</td>
                                    <td><?php echo $product['has_expiry'] == 'yes' ? '<span class="badge bg-warning text-dark">Yes</span>' : '<span class="badge bg-info">No</span>'; ?></td>
                                    <td><span class="badge bg-success">Active</span></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary edit-btn" 
                                                data-id="<?php echo $product['product_id']; ?>"
                                                data-code="<?php echo htmlspecialchars($product['product_code']); ?>"
                                                data-barcode="<?php echo htmlspecialchars($product['barcode']); ?>"
                                                data-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                                data-category="<?php echo $product['category_id']; ?>"
                                                data-supplier="<?php echo $product['supplier_id']; ?>"
                                                data-description="<?php echo htmlspecialchars($product['description'] ?? ''); ?>"
                                                data-purchase="<?php echo $product['purchase_price']; ?>"
                                                data-selling="<?php echo $product['selling_price']; ?>"
                                                data-margin="<?php echo $product['profit_margin']; ?>"
                                                data-tax="<?php echo $product['tax_rate']; ?>"
                                                data-expiry="<?php echo $product['has_expiry']; ?>"
                                                data-reorder="<?php echo $product['reorder_level']; ?>"
                                                title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this product?');" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
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
                            <input type="text" name="product_code" class="form-control" value="PRD<?php echo rand(1000, 9999); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Barcode (Optional)</label>
                            <input type="text" name="barcode" class="form-control" placeholder="Leave empty if no barcode">
                            <small class="text-muted">Leave empty if product has no barcode</small>
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
                                <option value="<?php echo $cat['category_id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Supplier (Optional)</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $sup): ?>
                                <option value="<?php echo $sup['supplier_id']; ?>"><?php echo htmlspecialchars($sup['supplier_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Purchase Price (UGX) *</label>
                            <input type="number" name="purchase_price" id="add_purchase_price" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Selling Price (UGX) *</label>
                            <input type="number" name="selling_price" id="add_selling_price" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Profit Margin % <span class="text-success">(Auto)</span></label>
                            <input type="number" name="profit_margin" id="add_profit_margin" class="form-control" step="0.01" value="0" readonly style="background-color: #e9ecef;">
                            <small class="text-muted">Calculated automatically</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tax Rate %</label>
                            <input type="number" name="tax_rate" class="form-control" step="0.01" value="18">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Has Expiry Date? *</label>
                            <select name="has_expiry" class="form-select">
                                <option value="no">No Expiry Date</option>
                                <option value="yes">Has Expiry Date</option>
                            </select>
                            <small class="text-muted">If product has expiry, you'll set the date when adding stock</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reorder Level</label>
                            <input type="number" name="reorder_level" class="form-control" value="10">
                            <small class="text-muted">Alert when stock reaches this level</small>
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

<!-- Edit Product Modal -->
<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="product_id" id="edit_product_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Product Code *</label>
                            <input type="text" name="product_code" id="edit_product_code" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Barcode (Optional)</label>
                            <input type="text" name="barcode" id="edit_barcode" class="form-control" placeholder="Leave empty if no barcode">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Product Name *</label>
                            <input type="text" name="product_name" id="edit_product_name" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category *</label>
                            <select name="category_id" id="edit_category_id" class="form-select" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['category_id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Supplier (Optional)</label>
                            <select name="supplier_id" id="edit_supplier_id" class="form-select">
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $sup): ?>
                                <option value="<?php echo $sup['supplier_id']; ?>"><?php echo htmlspecialchars($sup['supplier_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Purchase Price (UGX) *</label>
                            <input type="number" name="purchase_price" id="edit_purchase_price" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Selling Price (UGX) *</label>
                            <input type="number" name="selling_price" id="edit_selling_price" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Profit Margin % <span class="text-success">(Auto)</span></label>
                            <input type="number" name="profit_margin" id="edit_profit_margin" class="form-control" step="0.01" readonly style="background-color: #e9ecef;">
                            <small class="text-muted">Calculated automatically</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tax Rate %</label>
                            <input type="number" name="tax_rate" id="edit_tax_rate" class="form-control" step="0.01">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Has Expiry Date? *</label>
                            <select name="has_expiry" id="edit_has_expiry" class="form-select">
                                <option value="no">No Expiry Date</option>
                                <option value="yes">Has Expiry Date</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Reorder Level</label>
                            <input type="number" name="reorder_level" id="edit_reorder_level" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="edit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update Product
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
jQuery(document).ready(function($) {
    // NOTE: DataTables init is handled globally in footer.php for .datatable
    // Do NOT initialise #productsTable here again, or you'll get:
    // "DataTables warning: Cannot reinitialise DataTable"

    // Function to calculate profit margin
    function calculateProfitMargin(purchasePrice, sellingPrice, targetField) {
        purchasePrice = parseFloat(purchasePrice) || 0;
        sellingPrice = parseFloat(sellingPrice) || 0;
        
        if (purchasePrice > 0 && sellingPrice > 0 && sellingPrice > purchasePrice) {
            const profit = sellingPrice - purchasePrice;
            const margin = (profit / sellingPrice) * 100;
            $(targetField).val(margin.toFixed(2));
        } else {
            $(targetField).val('0.00');
        }
    }
    
    // AUTO-CALCULATE PROFIT MARGIN FOR ADD MODAL
    $('#add_purchase_price, #add_selling_price').on('input', function() {
        calculateProfitMargin(
            $('#add_purchase_price').val(),
            $('#add_selling_price').val(),
            '#add_profit_margin'
        );
    });
    
    // AUTO-CALCULATE PROFIT MARGIN FOR EDIT MODAL
    $('#edit_purchase_price, #edit_selling_price').on('input', function() {
        calculateProfitMargin(
            $('#edit_purchase_price').val(),
            $('#edit_selling_price').val(),
            '#edit_profit_margin'
        );
    });
    
    // Handle edit button click — use delegated event so it works with DataTables pagination
    $(document).on('click', '.edit-btn', function() {
        const btn = $(this);
        
        $('#edit_product_id').val(btn.data('id'));
        $('#edit_product_code').val(btn.data('code'));
        $('#edit_barcode').val(btn.data('barcode'));
        $('#edit_product_name').val(btn.data('name'));
        $('#edit_category_id').val(btn.data('category'));
        $('#edit_supplier_id').val(btn.data('supplier'));
        $('#edit_description').val(btn.data('description'));
        $('#edit_purchase_price').val(btn.data('purchase'));
        $('#edit_selling_price').val(btn.data('selling'));
        $('#edit_profit_margin').val(btn.data('margin'));
        $('#edit_tax_rate').val(btn.data('tax'));
        $('#edit_has_expiry').val(btn.data('expiry'));
        $('#edit_reorder_level').val(btn.data('reorder'));
        
        $('#editProductModal').modal('show');
    });
});
</script>