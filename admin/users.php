<?php
require_once '../config/config.php';
require_admin();

$page_title = "User Management";

// Fetch all users with their assigned stores count
try {
    $stmt = $conn->prepare("
        SELECT u.*, 
               COUNT(DISTINCT us.store_id) as store_count
        FROM users u
        LEFT JOIN stores us ON u.user_id = user_id
        GROUP BY user_id, u.username, u.full_name, u.email, u.phone, u.role, u.status, u.created_at, u.updated_at
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch client profiles separately if table exists
    $client_profiles = [];
    try {
        $cp_stmt = $conn->query("SELECT user_id, company_name, credit_limit, current_balance FROM client_profiles");
        while ($row = $cp_stmt->fetch(PDO::FETCH_ASSOC)) {
            $client_profiles[$row['user_id']] = $row;
        }
    } catch(PDOException $e) {
        $client_profiles = [];
    }
    
} catch(PDOException $e) {
    $error = "Error fetching users: " . $e->getMessage();
    $users = [];
}

// Fetch all stores
try {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE status = 'active' ORDER BY store_name");
    $stmt->execute();
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $stores = [];
}

$alert = get_alert();
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <button class="btn btn-link d-md-none sidebar-toggle">
            <i class="fas fa-bars"></i>
        </button>
        <h5 class="mb-0">
            <i class="fas fa-users me-2"></i>User Management
        </h5>
    </div>
    
    <div class="content-area">
        <?php if ($alert): ?>
        <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
            <?php echo $alert['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Users</h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="fas fa-plus me-2"></i>Add New User
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datatable table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Company/Stores</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($users)): ?>
                                <?php foreach ($users as $user): ?>
                                <?php 
                                $client_info = isset($client_profiles[$user['user_id']]) ? $client_profiles[$user['user_id']] : null;
                                ?>
                                <tr>
                                    <td><?php echo $user['user_id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                                    <td>
                                        <?php if ($user['role'] == 'admin'): ?>
                                            <span class="badge bg-danger">Admin</span>
                                        <?php elseif ($user['role'] == 'client'): ?>
                                            <span class="badge bg-success">Client</span>
                                        <?php else: ?>
                                            <span class="badge bg-primary">Employee</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['role'] == 'admin'): ?>
                                            <span class="badge bg-info">All Stores</span>
                                        <?php elseif ($user['role'] == 'client'): ?>
                                            <?php if ($client_info && !empty($client_info['company_name'])): ?>
                                                <span class="badge bg-success"><?php echo htmlspecialchars($client_info['company_name']); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Individual</span>
                                            <?php endif; ?>
                                        <?php elseif ($user['store_count'] > 0): ?>
                                            <span class="badge bg-success"><?php echo $user['store_count']; ?> Store(s)</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">No Stores</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['status'] == 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-success" onclick="viewUser(<?php echo $user['user_id']; ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-info" onclick="editUser(<?php echo $user['user_id']; ?>)" title="Edit User">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                        <a href="delete-user.php?id=<?php echo $user['user_id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this user?')" title="Delete User">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center">No users found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="add-user.php">
                <div class="modal-body">
                    <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-user me-2"></i>Basic Information</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Username *</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="full_name" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" name="phone" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Password *</label>
                                <input type="password" name="password" class="form-control" required minlength="6">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Role *</label>
                                <select name="role" id="add_role" class="form-select" required onchange="toggleRoleFields('add')">
                                    <option value="employee">Employee</option>
                                    <option value="client">Client</option>
                                    <option value="admin">Administrator</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label class="form-label">Status *</label>
                                <select name="status" class="form-select" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Client-Specific Fields -->
                    <div id="add_client_fields" style="display:none;">
                        <h6 class="border-bottom pb-2 mb-3 mt-4"><i class="fas fa-building me-2"></i>Client Information (Optional)</h6>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            All clients are managed by administrators. Fill in additional details if this is a business client.
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Company Name</label>
                                    <input type="text" name="company_name" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tax ID</label>
                                    <input type="text" name="tax_id" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Billing Address</label>
                                    <textarea name="billing_address" class="form-control" rows="2"></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Shipping Address</label>
                                    <textarea name="shipping_address" class="form-control" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Credit Limit (UGX)</label>
                                    <input type="number" name="credit_limit" class="form-control" value="0" min="0" step="1000">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Discount Rate (%)</label>
                                    <input type="number" name="discount_rate" class="form-control" value="0" min="0" max="100" step="0.1">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Payment Terms</label>
                                    <select name="payment_terms" class="form-select">
                                        <option value="immediate">Immediate</option>
                                        <option value="net_7">Net 7 Days</option>
                                        <option value="net_15">Net 15 Days</option>
                                        <option value="net_30">Net 30 Days</option>
                                        <option value="net_60">Net 60 Days</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Store Assignment (for Employees) -->
                    <div id="add_store_section" style="display:none;">
                        <h6 class="border-bottom pb-2 mb-3 mt-4"><i class="fas fa-store me-2"></i>Store Assignment</h6>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Employees must be assigned to at least one store.
                        </div>
                        <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                            <?php if (!empty($stores)): ?>
                                <?php foreach ($stores as $store): ?>
                                    <div class="form-check">
                                        <input class="form-check-input store-checkbox" type="checkbox" 
                                               name="stores[]" value="<?php echo $store['store_id']; ?>" 
                                               id="add_store_<?php echo $store['store_id']; ?>">
                                        <label class="form-check-label" for="add_store_<?php echo $store['store_id']; ?>">
                                            <?php echo htmlspecialchars($store['store_name']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">No stores available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Add User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View User Modal -->
<div class="modal fade" id="viewUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-user me-2"></i>User Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th width="40%">User ID:</th>
                                <td id="view_user_id"></td>
                            </tr>
                            <tr>
                                <th>Username:</th>
                                <td id="view_username"></td>
                            </tr>
                            <tr>
                                <th>Full Name:</th>
                                <td id="view_full_name"></td>
                            </tr>
                            <tr>
                                <th>Email:</th>
                                <td id="view_email"></td>
                            </tr>
                            <tr>
                                <th>Phone:</th>
                                <td id="view_phone"></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th width="40%">Role:</th>
                                <td id="view_role"></td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td id="view_status"></td>
                            </tr>
                            <tr>
                                <th>Created:</th>
                                <td id="view_created"></td>
                            </tr>
                            <tr>
                                <th>Last Updated:</th>
                                <td id="view_updated"></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Client Info Section -->
                <div id="view_client_section" style="display:none;">
                    <hr>
                    <h6><i class="fas fa-building me-2"></i>Client Information</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Company:</strong> <span id="view_company"></span></p>
                            <p><strong>Tax ID:</strong> <span id="view_tax_id"></span></p>
                            <p><strong>Credit Limit:</strong> <span id="view_credit_limit"></span></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Current Balance:</strong> <span id="view_current_balance"></span></p>
                            <p><strong>Payment Terms:</strong> <span id="view_payment_terms"></span></p>
                            <p><strong>Discount Rate:</strong> <span id="view_discount_rate"></span></p>
                        </div>
                    </div>
                </div>
                
                <!-- Assigned Stores Section -->
                <div id="view_stores_section" style="display:none;">
                    <hr>
                    <h6><i class="fas fa-store me-2"></i>Assigned Stores</h6>
                    <div id="view_assigned_stores" class="border rounded p-3 bg-light">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-info" onclick="editUserFromView()">
                    <i class="fas fa-edit me-2"></i>Edit User
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="edit-user.php">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-body">
                    <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-user me-2"></i>Basic Information</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Username *</label>
                                <input type="text" name="username" id="edit_username" class="form-control" required readonly>
                                <small class="text-muted">Username cannot be changed</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" id="edit_email" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" name="phone" id="edit_phone" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">New Password</label>
                                <input type="password" name="password" class="form-control" minlength="6">
                                <small class="text-muted">Leave empty to keep current password</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Role *</label>
                                <select name="role" id="edit_role" class="form-select" required onchange="toggleRoleFields('edit')">
                                    <option value="employee">Employee</option>
                                    <option value="client">Client</option>
                                    <option value="admin">Administrator</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label class="form-label">Status *</label>
                                <select name="status" id="edit_status" class="form-select" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Client-Specific Fields -->
                    <div id="edit_client_fields" style="display:none;">
                        <h6 class="border-bottom pb-2 mb-3 mt-4"><i class="fas fa-building me-2"></i>Client Information</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Company Name</label>
                                    <input type="text" name="company_name" id="edit_company_name" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tax ID</label>
                                    <input type="text" name="tax_id" id="edit_tax_id" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Billing Address</label>
                                    <textarea name="billing_address" id="edit_billing_address" class="form-control" rows="2"></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Shipping Address</label>
                                    <textarea name="shipping_address" id="edit_shipping_address" class="form-control" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Credit Limit (UGX)</label>
                                    <input type="number" name="credit_limit" id="edit_credit_limit" class="form-control" value="0" min="0" step="1000">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Discount Rate (%)</label>
                                    <input type="number" name="discount_rate" id="edit_discount_rate" class="form-control" value="0" min="0" max="100" step="0.1">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Payment Terms</label>
                                    <select name="payment_terms" id="edit_payment_terms" class="form-select">
                                        <option value="immediate">Immediate</option>
                                        <option value="net_7">Net 7 Days</option>
                                        <option value="net_15">Net 15 Days</option>
                                        <option value="net_30">Net 30 Days</option>
                                        <option value="net_60">Net 60 Days</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Store Assignment (for Employees) -->
                    <div id="edit_store_section" style="display:none;">
                        <h6 class="border-bottom pb-2 mb-3 mt-4"><i class="fas fa-store me-2"></i>Store Assignment</h6>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Employees must be assigned to at least one store.
                        </div>
                        <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;" id="edit_stores_list">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
let currentViewUserId = null;

function toggleRoleFields(mode) {
    const role = document.getElementById(mode + '_role').value;
    const clientFields = document.getElementById(mode + '_client_fields');
    const storeSection = document.getElementById(mode + '_store_section');
    
    if (clientFields) clientFields.style.display = 'none';
    if (storeSection) storeSection.style.display = 'none';
    
    if (role === 'client' && clientFields) {
        clientFields.style.display = 'block';
    } else if (role === 'employee' && storeSection) {
        storeSection.style.display = 'block';
    }
}

function viewUser(userId) {
    currentViewUserId = userId;
    
    fetch('get-user.php?id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const user = data.user;
                
                document.getElementById('view_user_id').textContent = user.user_id;
                document.getElementById('view_username').textContent = user.username;
                document.getElementById('view_full_name').textContent = user.full_name;
                document.getElementById('view_email').textContent = user.email || '-';
                document.getElementById('view_phone').textContent = user.phone || '-';
                
                const roleBadge = user.role === 'admin' 
                    ? '<span class="badge bg-danger">Administrator</span>' 
                    : user.role === 'client'
                    ? '<span class="badge bg-success">Client</span>'
                    : '<span class="badge bg-primary">Employee</span>';
                document.getElementById('view_role').innerHTML = roleBadge;
                
                const statusBadge = user.status === 'active' 
                    ? '<span class="badge bg-success">Active</span>' 
                    : '<span class="badge bg-secondary">Inactive</span>';
                document.getElementById('view_status').innerHTML = statusBadge;
                
                document.getElementById('view_created').textContent = new Date(user.created_at).toLocaleString();
                document.getElementById('view_updated').textContent = user.updated_at ? new Date(user.updated_at).toLocaleString() : '-';
                
                if (user.role === 'client' && data.client_profile) {
                    document.getElementById('view_client_section').style.display = 'block';
                    document.getElementById('view_company').textContent = data.client_profile.company_name || '-';
                    document.getElementById('view_tax_id').textContent = data.client_profile.tax_id || '-';
                    document.getElementById('view_credit_limit').textContent = 'UGX ' + Number(data.client_profile.credit_limit || 0).toLocaleString();
                    document.getElementById('view_current_balance').textContent = 'UGX ' + Number(data.client_profile.current_balance || 0).toLocaleString();
                    document.getElementById('view_payment_terms').textContent = data.client_profile.payment_terms ? data.client_profile.payment_terms.replace(/_/g, ' ').toUpperCase() : '-';
                    document.getElementById('view_discount_rate').textContent = (data.client_profile.discount_rate || 0) + '%';
                } else {
                    document.getElementById('view_client_section').style.display = 'none';
                }
                
                if (user.role === 'employee') {
                    loadViewStores(userId);
                } else {
                    document.getElementById('view_stores_section').style.display = 'none';
                }
                
                new bootstrap.Modal(document.getElementById('viewUserModal')).show();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading user data');
        });
}

function loadViewStores(userId) {
    const storesSection = document.getElementById('view_stores_section');
    const storesContainer = document.getElementById('view_assigned_stores');
    
    storesSection.style.display = 'block';
    
    fetch('get-user-stores.php?user_id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.assigned_stores.length === 0) {
                    storesContainer.innerHTML = '<p class="mb-0 text-warning"><i class="fas fa-exclamation-triangle me-2"></i>No stores assigned</p>';
                } else {
                    let html = '<ul class="mb-0">';
                    data.stores.forEach(store => {
                        if (data.assigned_stores.includes(store.store_id)) {
                            html += `<li><strong>${store.store_name}</strong></li>`;
                        }
                    });
                    html += '</ul>';
                    storesContainer.innerHTML = html;
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            storesContainer.innerHTML = '<p class="mb-0 text-danger">Error loading stores</p>';
        });
}

function editUserFromView() {
    bootstrap.Modal.getInstance(document.getElementById('viewUserModal')).hide();
    setTimeout(function() {
        editUser(currentViewUserId);
    }, 300);
}

function editUser(userId) {
    fetch('get-user.php?id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit_user_id').value = data.user.user_id;
                document.getElementById('edit_username').value = data.user.username;
                document.getElementById('edit_full_name').value = data.user.full_name;
                document.getElementById('edit_email').value = data.user.email || '';
                document.getElementById('edit_phone').value = data.user.phone || '';
                document.getElementById('edit_role').value = data.user.role;
                document.getElementById('edit_status').value = data.user.status;
                
                if (data.user.role === 'client' && data.client_profile) {
                    document.getElementById('edit_company_name').value = data.client_profile.company_name || '';
                    document.getElementById('edit_tax_id').value = data.client_profile.tax_id || '';
                    document.getElementById('edit_billing_address').value = data.client_profile.billing_address || '';
                    document.getElementById('edit_shipping_address').value = data.client_profile.shipping_address || '';
                    document.getElementById('edit_credit_limit').value = data.client_profile.credit_limit || 0;
                    document.getElementById('edit_discount_rate').value = data.client_profile.discount_rate || 0;
                    document.getElementById('edit_payment_terms').value = data.client_profile.payment_terms || 'immediate';
                }
                
                if (data.user.role === 'employee') {
                    loadUserStores(userId);
                }
                
                toggleRoleFields('edit');
                
                new bootstrap.Modal(document.getElementById('editUserModal')).show();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading user data');
        });
}

function loadUserStores(userId) {
    fetch('get-user-stores.php?user_id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '';
                data.stores.forEach(store => {
                    const checked = data.assigned_stores.includes(store.store_id) ? 'checked' : '';
                    html += `
                        <div class="form-check">
                            <input class="form-check-input store-checkbox" type="checkbox" 
                                   name="stores[]" value="${store.store_id}" 
                                   id="edit_store_${store.store_id}" ${checked}>
                            <label class="form-check-label" for="edit_store_${store.store_id}">
                                ${store.store_name}
                            </label>
                        </div>
                    `;
                });
                document.getElementById('edit_stores_list').innerHTML = html;
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

document.addEventListener('DOMContentLoaded', function() {
    toggleRoleFields('add');
});
</script>