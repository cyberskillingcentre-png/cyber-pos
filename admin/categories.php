<?php
require_once '../config/config.php';
require_admin();

$page_title = "Categories Management";

// Handle add/edit/delete operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    csrf_require();
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] == 'add') {
                $category_name = sanitize_input($_POST['category_name']);
                $category_code = sanitize_input($_POST['category_code']);
                $description = sanitize_input($_POST['description']);
                
                $stmt = $conn->prepare("INSERT INTO categories (category_name, category_code, description) VALUES (?, ?, ?)");
                $stmt->execute([$category_name, $category_code, $description]);
                
                log_activity($_SESSION['user_id'], 'CATEGORY_CREATED', "Created category: $category_name", $conn);
                set_alert('success', 'Category added successfully!');
                
            } elseif ($_POST['action'] == 'edit') {
                $category_id = intval($_POST['category_id']);
                $category_name = sanitize_input($_POST['category_name']);
                $category_code = sanitize_input($_POST['category_code']);
                $description = sanitize_input($_POST['description']);
                
                $stmt = $conn->prepare("UPDATE categories SET category_name = ?, category_code = ?, description = ? WHERE category_id = ?");
                $stmt->execute([$category_name, $category_code, $description, $category_id]);
                
                log_activity($_SESSION['user_id'], 'CATEGORY_UPDATED', "Updated category: $category_name", $conn);
                set_alert('success', 'Category updated successfully!');
                
            } elseif ($_POST['action'] == 'delete') {
                $category_id = intval($_POST['category_id']);
                
                // Check if category has products
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ?");
                $stmt->execute([$category_id]);
                $count = $stmt->fetch()['count'];
                
                if ($count > 0) {
                    set_alert('warning', "Cannot delete! This category has $count products assigned to it.");
                } else {
                    $stmt = $conn->prepare("UPDATE categories SET status = 'inactive' WHERE category_id = ?");
                    $stmt->execute([$category_id]);
                    
                    log_activity($_SESSION['user_id'], 'CATEGORY_DELETED', "Deleted category ID: $category_id", $conn);
                    set_alert('success', 'Category deleted successfully!');
                }
            }
            
            header('Location: Categories');
            exit();
        } catch(PDOException $e) {
            set_alert('danger', 'Error: ' . $e->getMessage());
        }
    }
}

// Fetch all categories
$stmt = $conn->prepare("SELECT c.*, COUNT(p.product_id) as product_count FROM categories c LEFT JOIN products p ON c.category_id = p.category_id AND p.status = 'active' WHERE c.status = 'active' GROUP BY c.category_id ORDER BY c.category_name");
$stmt->execute();
$categories = $stmt->fetchAll();
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">

    <div class="top-navbar">
         <button class="btn btn-link d-md-none sidebar-toggle">
                    <i class="fas fa-bars"></i>
                </button>
        <h5 class="mb-0"><i class="fas fa-th-large me-2"></i>Categories Management</h5>
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
                <span><i class="fas fa-list me-2"></i>All Categories (<?php echo count($categories); ?>)</span>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="fas fa-plus me-1"></i>Add Category
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($categories)): ?>
                    <!-- Empty State -->
                    <div class="text-center py-5">
                        <i class="fas fa-tags fa-4x text-muted mb-3" style="opacity:0.2;"></i>
                        <h4 class="text-muted mb-2">No Categories Added Yet</h4>
                        <p class="text-muted mb-4" style="font-size:0.9rem;">You haven't created any product categories. Add one to help organize your inventory.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                            <i class="fas fa-plus me-1"></i>Add Your First Category
                        </button>
                    </div>
                <?php else: ?>
                    <!-- Categories Table -->
                    <div class="table-responsive">
                        <table class="table table-hover" id="categoriesTable">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Category Name</th>
                                    <th>Description</th>
                                    <th>Products</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($category['category_code']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($category['category_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($category['description'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $category['product_count']; ?> products</span>
                                    </td>
                                    <td>
                                        <span class="badge bg-success">Active</span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-primary edit-btn" 
                                                data-id="<?php echo $category['category_id']; ?>"
                                                data-name="<?php echo htmlspecialchars($category['category_name']); ?>"
                                                data-code="<?php echo htmlspecialchars($category['category_code']); ?>"
                                                data-description="<?php echo htmlspecialchars($category['description'] ?? ''); ?>"
                                                title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="category_id" value="<?php echo $category['category_id']; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="btn btn-sm btn-danger" 
                                                    onclick="return confirm('Delete this category? (Only if no products assigned)');" 
                                                    title="Delete">
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

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Code *</label>
                        <input type="text" name="category_code" class="form-control" value="CAT<?php echo rand(100, 999); ?>" required>
                        <small class="text-muted">Unique identifier for this category</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Category Name *</label>
                        <input type="text" name="category_name" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="add" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="category_id" id="edit_category_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Code *</label>
                        <input type="text" name="category_code" id="edit_category_code" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Category Name *</label>
                        <input type="text" name="category_name" id="edit_category_name" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="edit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
jQuery(document).ready(function($) {
    // ==============================================================
    // BULLETPROOF DATATABLE INITIALIZATION
    // Checks if the table is already a DataTable before initializing
    // ==============================================================
    setTimeout(function() {
        var tableSelector = '#categoriesTable';
        var $table = $(tableSelector);
        
        // Only proceed if the table actually exists on the page
        if ($table.length > 0) {
            
            // Check if DataTable is already initialized on this table
            if ($.fn.DataTable.isDataTable(tableSelector)) {
                // It's already initialized! Do nothing.
                console.log('DataTable already initialized, skipping...');
            } else {
                // It's NOT initialized. Safe to initialize it now.
                $table.DataTable({
                    pageLength: 25,
                    responsive: false,
                    order: [],
                    language: {
                        search: "", searchPlaceholder: "Search categories...",
                        lengthMenu: "Show _MENU_",
                        info: "_START_–_END_ of _TOTAL_",
                        infoEmpty: "No entries",
                        infoFiltered: "(filtered from _MAX_)",
                        paginate: {
                            first: '<i class="fas fa-angle-double-left"></i>',
                            last: '<i class="fas fa-angle-double-right"></i>',
                            next: '<i class="fas fa-angle-right"></i>',
                            previous: '<i class="fas fa-angle-left"></i>'
                        }
                    }
                });
            }
        }

        // Handle edit button click
        $('.edit-btn').on('click', function() {
            const btn = $(this);
            
            // Populate edit modal with category data
            $('#edit_category_id').val(btn.data('id'));
            $('#edit_category_code').val(btn.data('code'));
            $('#edit_category_name').val(btn.data('name'));
            $('#edit_description').val(btn.data('description'));
            
            // Show the modal
            $('#editCategoryModal').modal('show');
        });
    }, 200); // 200ms delay ensures the global footer script runs FIRST
});
</script>