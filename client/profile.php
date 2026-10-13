<?php
require_once '../config/config.php';
require_once 'config/client_auth.php';
require_client();
$page_title = "My Profile";

$client = get_client_profile($conn, $_SESSION['user_id']);

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    csrf_require();
    try {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        
        // Validation
        if (empty($full_name)) {
            throw new Exception("Full name is required");
        }
        
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format");
        }
        
        // Update only users table
        $stmt = $conn->prepare("
            UPDATE users 
            SET full_name = ?, 
                email = ?, 
                phone = ?
            WHERE user_id = ?
        ");
        
        $stmt->execute([$full_name, $email, $phone, $_SESSION['user_id']]);
        
        $_SESSION['success'] = "Profile updated successfully!";
        $_SESSION['full_name'] = $full_name; // Update session
        
        // Refresh client data
        $client = get_client_profile($conn, $_SESSION['user_id']);
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    csrf_require();
    try {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validation
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            throw new Exception("All password fields are required");
        }
        
        if ($new_password !== $confirm_password) {
            throw new Exception("New passwords do not match");
        }
        
        if (strlen($new_password) < 6) {
            throw new Exception("Password must be at least 6 characters");
        }
        
        // Verify current password
        $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!password_verify($current_password, $user['password'])) {
            throw new Exception("Current password is incorrect");
        }
        
        // Update password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->execute([$hashed_password, $_SESSION['user_id']]);
        
        $_SESSION['success'] = "Password changed successfully!";
        
    } catch (Exception $e) {
        $_SESSION['password_error'] = $e->getMessage();
    }
}
?>
<?php include '../includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
    <div class="top-navbar">
        <!-- MOBILE SIDEBAR TOGGLE -->
        <button class="btn btn-link d-md-none sidebar-toggle me-2">
            <i class="fas fa-bars"></i>
        </button>
        <div class="d-flex justify-content-between align-items-center w-100">
            <h5 class="mb-0"><i class="fas fa-user me-2"></i>My Profile</h5>
            <div>
                <button type="button" class="btn btn-sm btn-primary" id="editBtn">
                    <i class="fas fa-edit me-1"></i>Edit Profile
                </button>
                <button type="button" class="btn btn-sm btn-success d-none" id="saveBtn">
                    <i class="fas fa-save me-1"></i>Save Changes
                </button>
                <button type="button" class="btn btn-sm btn-secondary d-none" id="cancelBtn">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
            </div>
        </div>
    </div>
    
    <div class="content-area">
        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <form method="POST" id="profileForm">
            <?php echo csrf_field(); ?>
            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="fas fa-user me-2"></i>Personal Information
                            <small class="text-muted float-end">Editable</small>
                        </div>
                        <div class="card-body">
                            <!-- Full Name -->
                            <div class="row mb-3">
                                <div class="col-4"><strong>Full Name:</strong></div>
                                <div class="col-8">
                                    <span class="view-mode"><?php echo htmlspecialchars($client['full_name']); ?></span>
                                    <input type="text" class="form-control edit-mode d-none" name="full_name" 
                                           value="<?php echo htmlspecialchars($client['full_name']); ?>" required>
                                </div>
                            </div>
                            
                            <!-- Username (Read-only) -->
                            <div class="row mb-3">
                                <div class="col-4"><strong>Username:</strong></div>
                                <div class="col-8">
                                    <?php echo htmlspecialchars($client['username']); ?>
                                    <small class="text-muted d-block">Cannot be changed</small>
                                </div>
                            </div>
                            
                            <!-- Email -->
                            <div class="row mb-3">
                                <div class="col-4"><strong>Email:</strong></div>
                                <div class="col-8">
                                    <span class="view-mode"><?php echo htmlspecialchars($client['email'] ?? 'Not set'); ?></span>
                                    <input type="email" class="form-control edit-mode d-none" name="email" 
                                           value="<?php echo htmlspecialchars($client['email'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <!-- Phone -->
                            <div class="row mb-3">
                                <div class="col-4"><strong>Phone:</strong></div>
                                <div class="col-8">
                                    <span class="view-mode"><?php echo htmlspecialchars($client['phone'] ?? 'Not set'); ?></span>
                                    <input type="text" class="form-control edit-mode d-none" name="phone" 
                                           value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <!-- Member Since (Read-only) -->
                            <div class="row mb-3">
                                <div class="col-4"><strong>Member Since:</strong></div>
                                <div class="col-8"><?php echo date('F d, Y', strtotime($client['created_at'])); ?></div>
                            </div>
                            
                            <!-- Account Status (Read-only) -->
                            <div class="row mb-3">
                                <div class="col-4"><strong>Account Status:</strong></div>
                                <div class="col-8">
                                    <span class="badge <?php echo $client['status'] == 'active' ? 'bg-success' : 'bg-warning'; ?>">
                                        <?php echo ucfirst($client['status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <?php if (isset($client['company_name'])): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="fas fa-building me-2"></i>Company Information
                            <small class="text-muted float-end">Read Only</small>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-4"><strong>Company:</strong></div>
                                <div class="col-8"><?php echo htmlspecialchars($client['company_name'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-4"><strong>Tax ID:</strong></div>
                                <div class="col-8"><?php echo htmlspecialchars($client['tax_id'] ?? 'N/A'); ?></div>
                            </div>
                            <?php if ($client['billing_address']): ?>
                            <div class="row mb-3">
                                <div class="col-4"><strong>Billing Address:</strong></div>
                                <div class="col-8"><?php echo nl2br(htmlspecialchars($client['billing_address'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-credit-card me-2"></i>Account Terms
                            <small class="text-muted float-end">Read Only</small>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-6"><strong>Credit Limit:</strong></div>
                                <div class="col-6">UGX <?php echo format_currency($client['credit_limit'] ?? 0); ?></div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-6"><strong>Current Balance:</strong></div>
                                <div class="col-6">
                                    <?php $balance = $client['current_balance'] ?? 0; ?>
                                    <span class="<?php echo $balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        UGX <?php echo format_currency(abs($balance)); ?>
                                        <?php echo $balance < 0 ? ' (Overdue)' : ''; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-6"><strong>Payment Terms:</strong></div>
                                <div class="col-6"><?php echo ucwords(str_replace('_', ' ', $client['payment_terms'] ?? 'Immediate')); ?></div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-6"><strong>Discount Rate:</strong></div>
                                <div class="col-6">
                                    <span class="badge bg-success">
                                        <?php echo number_format($client['discount_rate'] ?? 0, 1); ?>%
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <input type="hidden" name="update_profile" value="1">
        </form>
        
        <!-- Change Password Section -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-key me-2"></i>Change Password
                        <button type="button" class="btn btn-sm btn-warning float-end" data-bs-toggle="collapse" data-bs-target="#passwordSection">
                            <i class="fas fa-lock me-1"></i>Change Password
                        </button>
                    </div>
                    <div class="collapse" id="passwordSection">
                        <div class="card-body">
                            <?php if (isset($_SESSION['password_error'])): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['password_error']; unset($_SESSION['password_error']); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                            <?php endif; ?>
                            
                            <form method="POST" id="passwordForm">
                                <?php echo csrf_field(); ?>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Current Password *</label>
                                            <input type="password" class="form-control" name="current_password" required>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">New Password *</label>
                                            <input type="password" class="form-control" name="new_password" required minlength="6">
                                            <small class="text-muted">Minimum 6 characters</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Confirm New Password *</label>
                                            <input type="password" class="form-control" name="confirm_password" required>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="change_password" value="1">
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-save me-1"></i>Update Password
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><i class="fas fa-bolt me-2"></i>Quick Actions</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <a href="My_Orders" class="btn btn-outline-info w-100">
                                    <i class="fas fa-shopping-cart fa-lg mb-2"></i><br>
                                    View Orders
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="My_Invoices" class="btn btn-outline-success w-100">
                                    <i class="fas fa-file-invoice fa-lg mb-2"></i><br>
                                    View Invoices
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="My_Favorites" class="btn btn-outline-danger w-100">
                                    <i class="fas fa-heart fa-lg mb-2"></i><br>
                                    My Favorites
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="New_Order" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-plus-circle fa-lg mb-2"></i><br>
                                    Place New Order
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Sidebar styles */
.sidebar {
    width: 250px;
    transition: all 0.3s ease;
    position: fixed;
    top: 0;
    left: 0;
    height: 100vh;
    z-index: 1000;
    overflow-y: auto;
}

.main-content {
    margin-left: 250px;
    transition: all 0.3s ease;
    min-height: 100vh;
    background-color: #f5f5f5;
}

.sidebar.collapsed {
    margin-left: -250px;
}

.main-content.expanded {
    margin-left: 0;
}

@media (max-width: 768px) {
    .sidebar {
        margin-left: 0;
        z-index: 1040;
    }
    
    .sidebar.collapsed {
        margin-left: -100%;
    }
    
    .main-content {
        margin-left: 0 !important;
    }
}

.sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1039;
}

@media (max-width: 768px) {
    .sidebar:not(.collapsed) ~ .sidebar-overlay {
        display: block;
    }
}

.top-navbar {
    background: #fff;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #eee;
    display: flex;
    align-items: center;
}

.top-navbar h5 {
    font-weight: 600;
    margin: 0;
}

.card {
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border-radius: 10px;
    height: 100%;
}

.card-header {
    background: #fff;
    border-bottom: 1px solid #eee;
    padding: 1rem 1.5rem;
    font-weight: 600;
    display: flex;
    align-items: center;
}

.card-body {
    padding: 1.5rem;
}

.row.mb-3 {
    padding: 0.5rem 0;
    border-bottom: 1px solid #f0f0f0;
}

.row.mb-3:last-child {
    border-bottom: none;
}

.row.mb-3 .col-4 {
    font-weight: 500;
    color: #495057;
}

.row.mb-3 .col-8 {
    color: #212529;
}

.edit-mode {
    transition: all 0.3s ease;
}

.edit-mode .form-control {
    border: 2px solid #0d6efd;
    background-color: #f8f9fa;
}

.edit-mode .form-control:focus {
    border-color: #0a58ca;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    background-color: white;
}

.badge {
    padding: 0.35em 0.65em;
    font-weight: 500;
}

.btn-outline-primary, .btn-outline-warning, .btn-outline-info, .btn-outline-success, .btn-outline-danger {
    border-width: 2px;
    padding: 1rem 0.5rem;
    text-align: center;
    height: 100%;
    transition: all 0.2s;
}

.card.editing {
    border: 2px solid #0d6efd;
    box-shadow: 0 0 15px rgba(13, 110, 253, 0.2);
}

.alert {
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const editBtn = document.getElementById('editBtn');
    const saveBtn = document.getElementById('saveBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const profileForm = document.getElementById('profileForm');
    const viewModes = document.querySelectorAll('.view-mode');
    const editModes = document.querySelectorAll('.edit-mode');
    const personalInfoCard = document.querySelector('.card');
    
    let originalValues = {};
    let isEditing = false;
    
    editBtn.addEventListener('click', function() {
        editModes.forEach(function(input) {
            originalValues[input.name] = input.value;
        });
        
        editBtn.classList.add('d-none');
        saveBtn.classList.remove('d-none');
        cancelBtn.classList.remove('d-none');
        
        viewModes.forEach(el => el.classList.add('d-none'));
        editModes.forEach(el => el.classList.remove('d-none'));
        
        personalInfoCard.classList.add('editing');
        
        const firstInput = document.querySelector('.edit-mode:not(.d-none)');
        if (firstInput) firstInput.focus();
        
        isEditing = true;
    });
    
    saveBtn.addEventListener('click', function() {
        if (!profileForm.checkValidity()) {
            profileForm.reportValidity();
            return;
        }
        
        if (confirm('Save changes to your profile?')) {
            profileForm.submit();
        }
    });
    
    cancelBtn.addEventListener('click', function() {
        editModes.forEach(function(input) {
            if (originalValues.hasOwnProperty(input.name)) {
                input.value = originalValues[input.name];
            }
        });
        
        editBtn.classList.remove('d-none');
        saveBtn.classList.add('d-none');
        cancelBtn.classList.add('d-none');
        
        viewModes.forEach(el => el.classList.remove('d-none'));
        editModes.forEach(el => el.classList.add('d-none'));
        
        personalInfoCard.classList.remove('editing');
        isEditing = false;
    });
    
    window.addEventListener('beforeunload', function(e) {
        if (isEditing) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Leave anyway?';
            return e.returnValue;
        }
    });
    
    // Sidebar functionality
    if (!document.querySelector('.sidebar-overlay')) {
        const overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.querySelector('.sidebar').insertAdjacentElement('afterend', overlay);
    }
    
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            toggleSidebar();
        });
    }
    
    const overlay = document.querySelector('.sidebar-overlay');
    if (overlay) {
        overlay.addEventListener('click', () => toggleSidebar(true));
    }
    
    function toggleSidebar(forceClose = false) {
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        if (sidebar) {
            if (forceClose) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            } else {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
            }
        }
    }
});
</script>

<?php include 'includes/footer.php'; ?>