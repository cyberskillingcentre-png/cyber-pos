<?php
require_once '../config/config.php';
require_admin();

$page_title = "User Management";

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    csrf_require();
    try {
        $username = sanitize_input($_POST['username']);
        $password = $_POST['password'];
        $full_name = sanitize_input($_POST['full_name']);
        $email = sanitize_input($_POST['email']);
        $phone = sanitize_input($_POST['phone']);
        $role = $_POST['role'];
        $status = $_POST['status'];
        $admin_note = sanitize_input($_POST['admin_note'] ?? '');
        
        // Check if username exists
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            throw new Exception("Username already exists.");
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $conn->beginTransaction();

        $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, phone, role, status, admin_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $hashed_password, $full_name, $email, $phone, $role, $status, $admin_note]);
        $user_id = $conn->lastInsertId();

        // Handle Client Profile
        if ($role === 'client') {
            $stmt = $conn->prepare("INSERT INTO client_profiles (user_id, company_name, tax_id, credit_limit, discount_rate, payment_terms) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $user_id, 
                sanitize_input($_POST['company_name'] ?? ''), 
                sanitize_input($_POST['tax_id'] ?? ''), 
                floatval($_POST['credit_limit'] ?? 0), 
                floatval($_POST['discount_rate'] ?? 0), 
                $_POST['payment_terms'] ?? 'immediate'
            ]);
        }

        // Handle Store Assignment
        if ($role === 'employee' && !empty($_POST['stores'])) {
            $stmt = $conn->prepare("INSERT INTO user_stores (user_id, store_id) VALUES (?, ?)");
            foreach ($_POST['stores'] as $store_id) {
                $stmt->execute([$user_id, intval($store_id)]);
            }
        }

        $conn->commit();
        set_alert('success', 'User added successfully!');
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
    header('Location: users.php');
    exit();
}

// Handle Edit User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit') {
    csrf_require();
    try {
        $user_id = intval($_POST['user_id']);
        $full_name = sanitize_input($_POST['full_name']);
        $email = sanitize_input($_POST['email']);
        $phone = sanitize_input($_POST['phone']);
        $role = $_POST['role'];
        $status = $_POST['status'];
        $admin_note = sanitize_input($_POST['admin_note'] ?? '');
        $password = $_POST['password'] ?? '';

        $conn->beginTransaction();

        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, phone=?, role=?, status=?, admin_note=?, password=? WHERE user_id=?");
            $stmt->execute([$full_name, $email, $phone, $role, $status, $admin_note, $hashed_password, $user_id]);
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, phone=?, role=?, status=?, admin_note=? WHERE user_id=?");
            $stmt->execute([$full_name, $email, $phone, $role, $status, $admin_note, $user_id]);
        }

        // Handle Client Profile
        if ($role === 'client') {
            $stmt = $conn->prepare("SELECT profile_id FROM client_profiles WHERE user_id = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetch()) {
                $stmt = $conn->prepare("UPDATE client_profiles SET company_name=?, tax_id=?, credit_limit=?, discount_rate=?, payment_terms=? WHERE user_id=?");
                $stmt->execute([
                    sanitize_input($_POST['company_name'] ?? ''), 
                    sanitize_input($_POST['tax_id'] ?? ''), 
                    floatval($_POST['credit_limit'] ?? 0), 
                    floatval($_POST['discount_rate'] ?? 0), 
                    $_POST['payment_terms'] ?? 'immediate',
                    $user_id
                ]);
            } else {
                $stmt = $conn->prepare("INSERT INTO client_profiles (user_id, company_name, tax_id, credit_limit, discount_rate, payment_terms) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $user_id, 
                    sanitize_input($_POST['company_name'] ?? ''), 
                    sanitize_input($_POST['tax_id'] ?? ''), 
                    floatval($_POST['credit_limit'] ?? 0), 
                    floatval($_POST['discount_rate'] ?? 0), 
                    $_POST['payment_terms'] ?? 'immediate'
                ]);
            }
        }

        // Handle Store Assignment
        if ($role === 'employee') {
            $stmt = $conn->prepare("DELETE FROM user_stores WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            if (!empty($_POST['stores'])) {
                $stmt = $conn->prepare("INSERT INTO user_stores (user_id, store_id) VALUES (?, ?)");
                foreach ($_POST['stores'] as $store_id) {
                    $stmt->execute([$user_id, intval($store_id)]);
                }
            }
        }

        $conn->commit();
        set_alert('success', 'User updated successfully!');
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
    header('Location: users.php');
    exit();
}

// Fetch all users
try {
    $stmt = $conn->prepare("
        SELECT u.*, 
               (SELECT COUNT(*) FROM user_stores us WHERE us.user_id = u.user_id) as store_count
        FROM users u
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $client_profiles = [];
    try {
        $cp_stmt = $conn->query("SELECT user_id, company_name, credit_limit, current_balance FROM client_profiles");
        while ($row = $cp_stmt->fetch(PDO::FETCH_ASSOC)) {
            $client_profiles[$row['user_id']] = $row;
        }
    } catch(PDOException $e) {}
    
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
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Users (<?php echo count($users); ?>)</h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="fas fa-plus me-1"></i>Add New User
                </button>
            </div>
            <div class="card-body">
                
                <?php if (empty($users)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-4x text-muted mb-3" style="opacity:0.2;"></i>
                        <h4 class="text-muted mb-2">No Users Found</h4>
                        <p class="text-muted mb-4" style="font-size:0.9rem;">There are no users registered in the system yet.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                            <i class="fas fa-plus me-1"></i>Add Your First User
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="usersTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Role</th>
                                    <th>Company/Stores</th>
                                    <th>Status</th>
                                    <th>Note</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <?php 
                                $client_info = isset($client_profiles[$user['user_id']]) ? $client_profiles[$user['user_id']] : null;
                                ?>
                                <tr>
                                    <td><?php echo $user['user_id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
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
                                            <span class="badge bg-warning text-dark">No Stores</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['status'] == 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($user['admin_note'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo htmlspecialchars($user['admin_note']); ?>">
                                                <i class="fas fa-sticky-note"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <!-- FIXED: viewUser now calls the JS function -->
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
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================== -->
<!-- VIEW USER MODAL (NEW) -->
<!-- ============================================== -->
<div class="modal fade" id="viewUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-user-circle me-2"></i>User Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success rounded-circle mb-3" style="width: 80px; height: 80px;">
                        <i class="fas fa-user fa-3x"></i>
                    </div>
                    <h4 id="view_full_name" class="mb-1"></h4>
                    <p id="view_username" class="text-muted mb-0"></p>
                </div>
                
                <table class="table table-sm table-borderless">
                    <tbody>
                        <tr>
                            <td class="text-muted" style="width: 35%;">Email</td>
                            <td id="view_email" class="fw-bold"></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Phone</td>
                            <td id="view_phone" class="fw-bold"></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Role</td>
                            <td id="view_role" class="fw-bold"></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Status</td>
                            <td id="view_status"></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Created On</td>
                            <td id="view_created" class="fw-bold"></td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- Admin Note Section -->
                <div id="view_note_section" class="mt-3 p-3 bg-light rounded border" style="display:none;">
                    <small class="text-uppercase text-muted fw-bold d-block mb-1">Admin Note</small>
                    <p id="view_admin_note" class="mb-0 text-dark"></p>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ADD USER MODAL -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-body p-0">
                    <ul class="nav nav-tabs px-3 pt-2" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#add-basic" type="button">
                                <i class="fas fa-user me-1"></i>Basic Info
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#add-role" type="button">
                                <i class="fas fa-user-tag me-1"></i>Role & Settings
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content p-3">
                        <!-- TAB 1: Basic Info -->
                        <div class="tab-pane fade show active" id="add-basic">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Username *</label>
                                    <input type="text" name="username" class="form-control" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Full Name *</label>
                                    <input type="text" name="full_name" class="form-control" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Phone</label>
                                    <input type="tel" name="phone" class="form-control">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Password *</label>
                                    <input type="password" name="password" class="form-control" required minlength="6">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Status *</label>
                                    <select name="status" class="form-select" required>
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Admin Note (Password Hint)</label>
                                    <textarea name="admin_note" class="form-control" rows="2" placeholder="e.g., Password is JohnDoe2024!"></textarea>
                                    <small class="text-muted">Only visible to Admins.</small>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 2: Role & Settings -->
                        <div class="tab-pane fade" id="add-role">
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label class="form-label">User Role *</label>
                                    <select name="role" id="add_role" class="form-select" required onchange="toggleRoleFields('add')">
                                        <option value="employee">Employee</option>
                                        <option value="client">Client</option>
                                        <option value="admin">Administrator</option>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Client-Specific -->
                            <div id="add_client_fields" style="display:none;">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Company Name</label>
                                        <input type="text" name="company_name" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Tax ID</label>
                                        <input type="text" name="tax_id" class="form-control">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Credit Limit (UGX)</label>
                                        <input type="number" name="credit_limit" class="form-control" value="0">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Discount Rate (%)</label>
                                        <input type="number" name="discount_rate" class="form-control" value="0" step="0.1">
                                    </div>
                                </div>
                            </div>

                            <!-- Store Assignment -->
                            <div id="add_store_section" style="display:none;">
                                <label class="form-label">Assign Stores</label>
                                <div class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                                    <?php foreach ($stores as $store): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="stores[]" value="<?php echo $store['store_id']; ?>" id="add_store_<?php echo $store['store_id']; ?>">
                                            <label class="form-check-label" for="add_store_<?php echo $store['store_id']; ?>">
                                                <?php echo htmlspecialchars($store['store_name']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT USER MODAL -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-body p-0">
                    <ul class="nav nav-tabs px-3 pt-2" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#edit-basic" type="button">
                                <i class="fas fa-user me-1"></i>Basic Info
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#edit-role" type="button">
                                <i class="fas fa-user-tag me-1"></i>Role & Settings
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content p-3">
                        <!-- TAB 1: Basic Info -->
                        <div class="tab-pane fade show active" id="edit-basic">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Username</label>
                                    <input type="text" id="edit_username" class="form-control" readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Full Name *</label>
                                    <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" id="edit_email" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Phone</label>
                                    <input type="tel" name="phone" id="edit_phone" class="form-control">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">New Password</label>
                                    <input type="password" name="password" class="form-control" minlength="6">
                                    <small class="text-muted">Leave blank to keep current</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Status *</label>
                                    <select name="status" id="edit_status" class="form-select" required>
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label text-primary"><i class="fas fa-sticky-note me-1"></i>Admin Note (Password Hint)</label>
                                    <textarea name="admin_note" id="edit_admin_note" class="form-control bg-light" rows="2"></textarea>
                                    <small class="text-muted">Only visible to Admins.</small>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 2: Role & Settings -->
                        <div class="tab-pane fade" id="edit-role">
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label class="form-label">User Role *</label>
                                    <select name="role" id="edit_role" class="form-select" required onchange="toggleRoleFields('edit')">
                                        <option value="employee">Employee</option>
                                        <option value="client">Client</option>
                                        <option value="admin">Administrator</option>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Client-Specific -->
                            <div id="edit_client_fields" style="display:none;">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Company Name</label>
                                        <input type="text" name="company_name" id="edit_company_name" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Tax ID</label>
                                        <input type="text" name="tax_id" id="edit_tax_id" class="form-control">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Credit Limit (UGX)</label>
                                        <input type="number" name="credit_limit" id="edit_credit_limit" class="form-control" value="0">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Discount Rate (%)</label>
                                        <input type="number" name="discount_rate" id="edit_discount_rate" class="form-control" value="0" step="0.1">
                                    </div>
                                </div>
                            </div>

                            <!-- Store Assignment -->
                            <div id="edit_store_section" style="display:none;">
                                <label class="form-label">Assign Stores</label>
                                <div class="border rounded p-2" style="max-height: 150px; overflow-y: auto;" id="edit_stores_list"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
jQuery(document).ready(function($) {
    // Enable Tooltips for the Admin Note
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Safe DataTable init
    setTimeout(function() {
        var tableSelector = '#usersTable';
        var $table = $(tableSelector);
        if ($table.length > 0 && !$.fn.DataTable.isDataTable(tableSelector)) {
            $table.DataTable({
                pageLength: 25,
                responsive: false,
                order: [[0, 'desc']],
                language: { search: "", searchPlaceholder: "Search users..." }
            });
        }
    }, 200);
});

function toggleRoleFields(mode) {
    const role = document.getElementById(mode + '_role').value;
    const clientFields = document.getElementById(mode + '_client_fields');
    const storeSection = document.getElementById(mode + '_store_section');
    
    if (clientFields) clientFields.style.display = 'none';
    if (storeSection) storeSection.style.display = 'none';
    
    if (role === 'client' && clientFields) clientFields.style.display = 'block';
    else if (role === 'employee' && storeSection) storeSection.style.display = 'block';
}

/* ============================================== */
/* VIEW USER (NEW) */
/* ============================================== */
function viewUser(userId) {
    fetch('get-user.php?id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const user = data.user;
                
                // Populate View Modal
                document.getElementById('view_full_name').textContent = user.full_name;
                document.getElementById('view_username').textContent = '@' + user.username;
                document.getElementById('view_email').textContent = user.email || 'N/A';
                document.getElementById('view_phone').textContent = user.phone || 'N/A';
                
                // Format role badge
                let roleBadge = '';
                if (user.role === 'admin') roleBadge = '<span class="badge bg-danger">Administrator</span>';
                else if (user.role === 'client') roleBadge = '<span class="badge bg-success">Client</span>';
                else roleBadge = '<span class="badge bg-primary">Employee</span>';
                document.getElementById('view_role').innerHTML = roleBadge;
                
                // Format status badge
                let statusBadge = '';
                if (user.status === 'active') statusBadge = '<span class="badge bg-success">Active</span>';
                else statusBadge = '<span class="badge bg-secondary">Inactive</span>';
                document.getElementById('view_status').innerHTML = statusBadge;
                
                // Created Date
                if (user.created_at) {
                    const date = new Date(user.created_at);
                    document.getElementById('view_created').textContent = date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
                }
                
                // Admin Note
                const noteSection = document.getElementById('view_note_section');
                if (user.admin_note && user.admin_note.trim() !== '') {
                    noteSection.style.display = 'block';
                    document.getElementById('view_admin_note').textContent = user.admin_note;
                } else {
                    noteSection.style.display = 'none';
                }
                
                // Show Modal
                new bootstrap.Modal(document.getElementById('viewUserModal')).show();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error('Error fetching user details:', err);
            alert('Failed to load user details. Please check your connection.');
        });
}

/* ============================================== */
/* EDIT USER */
/* ============================================== */
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
                document.getElementById('edit_admin_note').value = data.user.admin_note || '';
                
                if (data.user.role === 'client' && data.client_profile) {
                    document.getElementById('edit_company_name').value = data.client_profile.company_name || '';
                    document.getElementById('edit_tax_id').value = data.client_profile.tax_id || '';
                    document.getElementById('edit_credit_limit').value = data.client_profile.credit_limit || 0;
                    document.getElementById('edit_discount_rate').value = data.client_profile.discount_rate || 0;
                }
                
                if (data.user.role === 'employee') {
                    loadUserStores(userId);
                }
                
                toggleRoleFields('edit');
                new bootstrap.Modal(document.getElementById('editUserModal')).show();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        });
}

function loadUserStores(userId) {
    const container = document.getElementById('edit_stores_list');
    container.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
    
    fetch('get-user-stores.php?user_id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '';
                data.stores.forEach(store => {
                    const checked = data.assigned_stores.includes(store.store_id) ? 'checked' : '';
                    html += `
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="stores[]" value="${store.store_id}" id="edit_store_${store.store_id}" ${checked}>
                            <label class="form-check-label" for="edit_store_${store.store_id}">${store.store_name}</label>
                        </div>
                    `;
                });
                container.innerHTML = html;
            }
        });
}

document.addEventListener('DOMContentLoaded', function() {
    toggleRoleFields('add');
    toggleRoleFields('edit');
});
</script>