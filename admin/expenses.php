<?php
require_once '../config/config.php';
require_admin();

$page_title = "Expenses Management";

// Handle Add/Edit/Delete
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    csrf_require();
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] == 'add') {
                $expense_number = 'EXP' . date('Ymd') . rand(1000, 9999);
                $expense_category = sanitize_input($_POST['expense_category']);
                $expense_date = $_POST['expense_date'];
                $amount = floatval($_POST['amount']);
                $payment_method = $_POST['payment_method'];
                $vendor_name = sanitize_input($_POST['vendor_name']);
                $description = sanitize_input($_POST['description']);
                $store_id = !empty($_POST['store_id']) ? intval($_POST['store_id']) : null;
                
                // Handle receipt upload
                $receipt_file = null;
                if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] == 0) {
                    $upload_result = upload_file($_FILES['receipt_file'], __DIR__ . '/../uploads/receipts');
                    if ($upload_result['success']) {
                        $receipt_file = $upload_result['filename'];
                    }
                }
                
                $stmt = $conn->prepare("INSERT INTO expenses (expense_number, expense_category, expense_date, amount, payment_method, vendor_name, description, receipt_file, store_id, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$expense_number, $expense_category, $expense_date, $amount, $payment_method, $vendor_name, $description, $receipt_file, $store_id, $_SESSION['user_id']]);
                
                log_activity($_SESSION['user_id'], 'EXPENSE_CREATED', "Created expense: $expense_number - UGX " . format_currency($amount), $conn);
                set_alert('success', 'Expense recorded successfully! Expense#: ' . $expense_number);
                
            } elseif ($_POST['action'] == 'edit') {
                $expense_id = intval($_POST['expense_id']);
                $expense_category = sanitize_input($_POST['expense_category']);
                $expense_date = $_POST['expense_date'];
                $amount = floatval($_POST['amount']);
                $payment_method = $_POST['payment_method'];
                $vendor_name = sanitize_input($_POST['vendor_name']);
                $description = sanitize_input($_POST['description']);
                $store_id = !empty($_POST['store_id']) ? intval($_POST['store_id']) : null;
                
                $stmt = $conn->prepare("UPDATE expenses SET expense_category = ?, expense_date = ?, amount = ?, payment_method = ?, vendor_name = ?, description = ?, store_id = ? WHERE expense_id = ?");
                $stmt->execute([$expense_category, $expense_date, $amount, $payment_method, $vendor_name, $description, $store_id, $expense_id]);
                
                log_activity($_SESSION['user_id'], 'EXPENSE_UPDATED', "Updated expense ID: $expense_id", $conn);
                set_alert('success', 'Expense updated successfully!');
                
            } elseif ($_POST['action'] == 'delete') {
                $expense_id = intval($_POST['expense_id']);
                $stmt = $conn->prepare("DELETE FROM expenses WHERE expense_id = ?");
                $stmt->execute([$expense_id]);
                
                log_activity($_SESSION['user_id'], 'EXPENSE_DELETED', "Deleted expense ID: $expense_id", $conn);
                set_alert('success', 'Expense deleted successfully!');
            }
            
            header('Location: Expense_Report');
            exit();
        } catch(PDOException $e) {
            set_alert('danger', 'Error: ' . $e->getMessage());
        }
    }
}

// Get filter parameters
$filter_period = $_GET['period'] ?? 'all';
$filter_category = $_GET['category'] ?? 'all';
$filter_store = $_GET['store'] ?? 'all';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Build WHERE clause based on filters
$where_clauses = [];
$params = [];

if ($filter_period == 'today') {
    $where_clauses[] = "DATE(e.expense_date) = CURDATE()";
} elseif ($filter_period == 'yesterday') {
    $where_clauses[] = "DATE(e.expense_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
} elseif ($filter_period == 'this_week') {
    $where_clauses[] = "YEARWEEK(e.expense_date, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($filter_period == 'this_month') {
    $where_clauses[] = "MONTH(e.expense_date) = MONTH(CURDATE()) AND YEAR(e.expense_date) = YEAR(CURDATE())";
} elseif ($filter_period == 'last_month') {
    $where_clauses[] = "MONTH(e.expense_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(e.expense_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
} elseif ($filter_period == 'this_year') {
    $where_clauses[] = "YEAR(e.expense_date) = YEAR(CURDATE())";
} elseif ($filter_period == 'custom' && $start_date && $end_date) {
    $where_clauses[] = "e.expense_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
}

if ($filter_category != 'all') {
    $where_clauses[] = "e.expense_category = ?";
    $params[] = $filter_category;
}

if ($filter_store != 'all') {
    $where_clauses[] = "e.store_id = ?";
    $params[] = intval($filter_store);
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Fetch filtered expenses
$stmt = $conn->prepare("
    SELECT 
        e.*,
        s.store_name,
        u.full_name as recorded_by_name
    FROM expenses e
    LEFT JOIN stores s ON e.store_id = s.store_id
    LEFT JOIN users u ON e.recorded_by = u.user_id
    $where_sql
    ORDER BY e.expense_date DESC, e.created_at DESC
");
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// Calculate totals for filtered period
$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(amount), 0) as total_expenses,
        COUNT(*) as expense_count,
        COALESCE(AVG(amount), 0) as average_expense
    FROM expenses e
    $where_sql
");
$stmt->execute($params);
$totals = $stmt->fetch();

// Get expenses for this month (always show)
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total_this_month
    FROM expenses 
    WHERE MONTH(expense_date) = MONTH(CURDATE()) 
    AND YEAR(expense_date) = YEAR(CURDATE())
");
$stmt->execute();
$this_month = $stmt->fetch();

// Fetch stores
$stmt = $conn->prepare("SELECT * FROM stores WHERE status = 'active' ORDER BY store_name");
$stmt->execute();
$stores = $stmt->fetchAll();

// Get all categories from database
$stmt = $conn->prepare("SELECT DISTINCT expense_category FROM expenses ORDER BY expense_category");
$stmt->execute();
$db_categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Expense categories
$categories = [
    'Rent',
    'Utilities (Electricity, Water)',
    'Salaries & Wages',
    'Office Supplies',
    'Marketing & Advertising',
    'Transportation',
    'Maintenance & Repairs',
    'Internet & Phone',
    'Insurance',
    'Bank Charges',
    'Taxes & Licenses',
    'Professional Services',
    'Equipment Purchase',
    'Inventory Purchase',
    'Miscellaneous'
];

// Merge with database categories
$categories = array_unique(array_merge($categories, $db_categories));
sort($categories);
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <div class="d-flex justify-content-between align-items-center">
            <!-- MOBILE SIDEBAR TOGGLE - CORRECTED -->
            <button class="btn btn-link d-md-none sidebar-toggle me-2">
                <i class="fas fa-bars"></i>
            </button>
            <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Expenses Management</h5>
            <span class="badge bg-primary"><?php echo date('l, F j, Y'); ?></span>
        </div>
    </div>
    
    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card purple">
                    <div class="icon"><i class="fas fa-receipt"></i></div>
                    <h3>UGX <?php echo number_format($totals['total_expenses'], 0); ?></h3>
                    <p>Total Expenses</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card blue">
                    <div class="icon"><i class="fas fa-calendar-alt"></i></div>
                    <h3>UGX <?php echo number_format($this_month['total_this_month'], 0); ?></h3>
                    <p>This Month</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card orange">
                    <div class="icon"><i class="fas fa-chart-line"></i></div>
                    <h3>UGX <?php echo number_format($totals['average_expense'], 0); ?></h3>
                    <p>Average Expense</p>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-filter me-2"></i>Filter Expenses
            </div>
            <div class="card-body">
                <form method="GET" id="filter-form">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Period</label>
                            <select name="period" id="period-select" class="form-select">
                                <option value="all" <?php echo $filter_period == 'all' ? 'selected' : ''; ?>>All Time</option>
                                <option value="today" <?php echo $filter_period == 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="yesterday" <?php echo $filter_period == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                <option value="this_week" <?php echo $filter_period == 'this_week' ? 'selected' : ''; ?>>This Week</option>
                                <option value="this_month" <?php echo $filter_period == 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                <option value="last_month" <?php echo $filter_period == 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                                <option value="this_year" <?php echo $filter_period == 'this_year' ? 'selected' : ''; ?>>This Year</option>
                                <option value="custom" <?php echo $filter_period == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3" id="custom-dates" style="<?php echo $filter_period == 'custom' ? '' : 'display:none;'; ?>">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                        </div>
                        
                        <div class="col-md-3 mb-3" id="custom-dates-end" style="<?php echo $filter_period == 'custom' ? '' : 'display:none;'; ?>">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <option value="all">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat; ?>" <?php echo $filter_category == $cat ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Store/Branch</label>
                            <select name="store" class="form-select">
                                <option value="all">All Stores</option>
                                <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['store_id']; ?>" <?php echo $filter_store == $store['store_id'] ? 'selected' : ''; ?>><?php echo $store['store_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">&nbsp;</label>
                            <div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-1"></i>Apply Filter
                                </button>
                                <a href="Expense_Report" class="btn btn-secondary">
                                    <i class="fas fa-redo me-1"></i>Reset
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fas fa-list me-2"></i>Expenses List (<?php echo count($expenses); ?> expenses)</span>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                    <i class="fas fa-plus me-1"></i>Record Expense
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <!-- SIMPLIFIED TABLE - NO DATATABLES CLASS -->
                    <table class="table table-hover table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Expense #</th>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Vendor</th>
                                <th>Amount</th>
                                <th>Payment Method</th>
                                <th>Store</th>
                                <th>Recorded By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($expenses) > 0): ?>
                                <?php foreach ($expenses as $expense): ?>
                                <tr>
                                    <td><strong><?php echo $expense['expense_number']; ?></strong></td>
                                    <td><?php echo date('d/m/Y', strtotime($expense['expense_date'])); ?></td>
                                    <td><?php echo $expense['expense_category']; ?></td>
                                    <td><?php echo $expense['vendor_name'] ?? 'N/A'; ?></td>
                                    <td><strong class="text-danger">UGX <?php echo number_format($expense['amount'], 2); ?></strong></td>
                                    <td><?php echo ucwords(str_replace('_', ' ', $expense['payment_method'])); ?></td>
                                    <td><?php echo $expense['store_name'] ?? 'General'; ?></td>
                                    <td><?php echo $expense['recorded_by_name']; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info view-btn" 
                                                data-id="<?php echo $expense['expense_id']; ?>"
                                                data-number="<?php echo $expense['expense_number']; ?>"
                                                data-category="<?php echo $expense['expense_category']; ?>"
                                                data-date="<?php echo date('d/m/Y', strtotime($expense['expense_date'])); ?>"
                                                data-amount="<?php echo $expense['amount']; ?>"
                                                data-payment="<?php echo $expense['payment_method']; ?>"
                                                data-vendor="<?php echo htmlspecialchars($expense['vendor_name']); ?>"
                                                data-description="<?php echo htmlspecialchars($expense['description']); ?>"
                                                data-store="<?php echo $expense['store_name'] ?? 'General'; ?>"
                                                data-recorded="<?php echo $expense['recorded_by_name']; ?>"
                                                data-created="<?php echo date('d/m/Y H:i', strtotime($expense['created_at'])); ?>"
                                                data-receipt="<?php echo $expense['receipt_file']; ?>"
                                                title="View">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-primary edit-btn" 
                                                data-id="<?php echo $expense['expense_id']; ?>"
                                                data-category="<?php echo $expense['expense_category']; ?>"
                                                data-date="<?php echo $expense['expense_date']; ?>"
                                                data-amount="<?php echo $expense['amount']; ?>"
                                                data-payment="<?php echo $expense['payment_method']; ?>"
                                                data-vendor="<?php echo htmlspecialchars($expense['vendor_name']); ?>"
                                                data-description="<?php echo htmlspecialchars($expense['description']); ?>"
                                                data-store="<?php echo $expense['store_id']; ?>"
                                                title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="expense_id" value="<?php echo $expense['expense_id']; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this expense?');" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                                        <h5>No Expenses Found</h5>
                                        <p class="text-muted">No expenses recorded for the selected period</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php if (count($expenses) > 0): ?>
                    <div class="table-footer p-3 bg-light border-top">
                        <div class="row">
                            <div class="col-md-12 text-end">
                                <h5 class="mb-0">
                                    Total Expenses: 
                                    <span class="text-danger">UGX <?php echo number_format($totals['total_expenses'], 2); ?></span>
                                </h5>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Expense Modal -->
<div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Record New Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Expense Category *</label>
                        <select name="expense_category" class="form-select" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Expense Date *</label>
                        <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount (UGX) *</label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method *</label>
                        <select name="payment_method" class="form-select" required>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="mobile_money">Mobile Money</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Vendor/Payee Name</label>
                        <input type="text" name="vendor_name" class="form-control" placeholder="e.g., Umeme, Landlord, etc.">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Details about this expense..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Store/Branch (Optional)</label>
                        <select name="store_id" class="form-select">
                            <option value="">General Expense</option>
                            <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['store_id']; ?>"><?php echo $store['store_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Receipt/Invoice (Optional)</label>
                        <input type="file" name="receipt_file" class="form-control" accept="image/*,application/pdf">
                        <small class="text-muted">Upload receipt or invoice image/PDF</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="add" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Record Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Expense Modal -->
<div class="modal fade" id="editExpenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="expense_id" id="edit_expense_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Expense Category *</label>
                        <select name="expense_category" id="edit_category" class="form-select" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Expense Date *</label>
                        <input type="date" name="expense_date" id="edit_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount (UGX) *</label>
                        <input type="number" name="amount" id="edit_amount" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method *</label>
                        <select name="payment_method" id="edit_payment" class="form-select" required>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="mobile_money">Mobile Money</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Vendor/Payee Name</label>
                        <input type="text" name="vendor_name" id="edit_vendor" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Store/Branch (Optional)</label>
                        <select name="store_id" id="edit_store" class="form-select">
                            <option value="">General Expense</option>
                            <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['store_id']; ?>"><?php echo $store['store_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="edit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Expense Modal -->
<div class="modal fade" id="viewExpenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Expense Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-6"><strong>Expense Number:</strong></div>
                    <div class="col-6"><span id="view_number"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Category:</strong></div>
                    <div class="col-6"><span id="view_category"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Date:</strong></div>
                    <div class="col-6"><span id="view_date"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Amount:</strong></div>
                    <div class="col-6"><span class="text-danger"><strong>UGX <span id="view_amount"></span></strong></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Payment Method:</strong></div>
                    <div class="col-6"><span id="view_payment"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Vendor:</strong></div>
                    <div class="col-6"><span id="view_vendor"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Description:</strong></div>
                    <div class="col-6"><span id="view_description"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Store/Branch:</strong></div>
                    <div class="col-6"><span id="view_store"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Recorded By:</strong></div>
                    <div class="col-6"><span id="view_recorded"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Created On:</strong></div>
                    <div class="col-6"><span id="view_created"></span></div>
                </div>
                <div id="receipt-section" style="display:none;">
                    <hr>
                    <div class="text-center">
                        <strong>Receipt/Invoice:</strong><br>
                        <a id="receipt-link" href="#" target="_blank" class="btn btn-sm btn-primary mt-2">
                            <i class="fas fa-file-download me-1"></i>View Receipt
                        </a>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
jQuery(document).ready(function($) {
    // Show/hide custom date range
    $('#period-select').on('change', function() {
        if ($(this).val() === 'custom') {
            $('#custom-dates, #custom-dates-end').show();
        } else {
            $('#custom-dates, #custom-dates-end').hide();
        }
    });
    
    // Edit button
    $('.edit-btn').on('click', function() {
        const btn = $(this);
        
        $('#edit_expense_id').val(btn.data('id'));
        $('#edit_category').val(btn.data('category'));
        $('#edit_date').val(btn.data('date'));
        $('#edit_amount').val(btn.data('amount'));
        $('#edit_payment').val(btn.data('payment'));
        $('#edit_vendor').val(btn.data('vendor'));
        $('#edit_description').val(btn.data('description'));
        $('#edit_store').val(btn.data('store') || '');
        
        $('#editExpenseModal').modal('show');
    });
    
    // View button
    $('.view-btn').on('click', function() {
        const btn = $(this);
        
        $('#view_number').text(btn.data('number'));
        $('#view_category').text(btn.data('category'));
        $('#view_date').text(btn.data('date'));
        $('#view_amount').text(parseFloat(btn.data('amount')).toLocaleString());
        $('#view_payment').text(btn.data('payment').replace('_', ' ').toUpperCase());
        $('#view_vendor').text(btn.data('vendor') || 'N/A');
        $('#view_description').text(btn.data('description') || 'N/A');
        $('#view_store').text(btn.data('store'));
        $('#view_recorded').text(btn.data('recorded'));
        $('#view_created').text(btn.data('created'));
        
        // Handle receipt
        const receipt = btn.data('receipt');
        if (receipt) {
            $('#receipt-link').attr('href', '../uploads/receipts/' + receipt);
            $('#receipt-section').show();
        } else {
            $('#receipt-section').hide();
        }
        
        $('#viewExpenseModal').modal('show');
    });
});
</script>