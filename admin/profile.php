<?php
require_once '../config/config.php';
require_login(); // Both admins and employees can access

$page_title = "My Profile & Settings";

// Get user details
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all stores
$stmt = $conn->prepare("SELECT * FROM stores WHERE status = 'active' ORDER BY store_name");
$stmt->execute();
$stores = $stmt->fetchAll();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    csrf_require();
    try {
        $full_name = sanitize_input($_POST['full_name']);
        $email = sanitize_input($_POST['email']);
        $phone = sanitize_input($_POST['phone']);
        $default_store_id = !empty($_POST['default_store_id']) ? intval($_POST['default_store_id']) : null;
        
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, default_store_id = ? WHERE user_id = ?");
        $stmt->execute([$full_name, $email, $phone, $default_store_id, $_SESSION['user_id']]);
        
        // Update session
        $_SESSION['full_name'] = $full_name;
        
        log_activity($_SESSION['user_id'], 'PROFILE_UPDATED', 'Updated profile information', $conn);
        set_alert('success', 'Profile updated successfully');
        header('Location: My_Profile');
        exit();
        
    } catch(Exception $e) {
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    csrf_require();
    try {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify current password
        if (!password_verify($current_password, $user['password'])) {
            throw new Exception('Current password is incorrect');
        }
        
        // Validate new password
        if (strlen($new_password) < 6) {
            throw new Exception('New password must be at least 6 characters');
        }
        
        if ($new_password !== $confirm_password) {
            throw new Exception('New passwords do not match');
        }
        
        // Update password
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->execute([$hashed, $_SESSION['user_id']]);
        
        log_activity($_SESSION['user_id'], 'PASSWORD_CHANGED', 'Changed password', $conn);
        set_alert('success', 'Password changed successfully');
        header('Location: My_Profile');
        exit();
        
    } catch(Exception $e) {
        set_alert('danger', 'Error: ' . $e->getMessage());
    }
}



// Refresh user data after any update
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<?php include '../includes/header.php'; ?>

<?php if ($_SESSION['role'] == 'admin'): ?>
    <?php include '../includes/admin_sidebar.php'; ?>
<?php else: ?>
    <?php include '../includes/employee_sidebar.php'; ?>
<?php endif; ?>

<div class="main-content">
    <div class="top-navbar">
         <!-- MOBILE SIDEBAR TOGGLE -->
            <button class="btn btn-link d-md-none sidebar-toggle me-2">
                <i class="fas fa-bars"></i>
            </button>
        <h5 class="mb-0">
            <i class="fas fa-user-cog me-2"></i>My Profile & Settings
        </h5>
    </div>
    
    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Profile Information -->
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-user me-2"></i>Profile Information
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                                <small class="text-muted">Username cannot be changed</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Role</label>
                                <input type="text" class="form-control" value="<?php echo ucfirst($user['role']); ?>" readonly>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Default Store/Branch</label>
                                <select name="default_store_id" class="form-select">
                                    <option value="">No Default Store</option>
                                    <?php foreach ($stores as $store): ?>
                                        <option value="<?php echo $store['store_id']; ?>" 
                                            <?php echo ($user['default_store_id'] == $store['store_id']) ? 'selected' : ''; ?>>
                                            <?php echo $store['store_name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">This store will be automatically selected in POS and other pages</small>
                            </div>
                            
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Profile
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Change Password -->
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header bg-warning text-dark">
                        <i class="fas fa-lock me-2"></i>Change Password
                    </div>
                    <div class="card-body">
                        <form method="POST" id="password-form">
                            <?php echo csrf_field(); ?>
                            <div class="mb-3">
                                <label class="form-label">Current Password *</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">New Password *</label>
                                <input type="password" name="new_password" id="new_password" class="form-control" minlength="6" required>
                                <small class="text-muted">Minimum 6 characters</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Confirm New Password *</label>
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                                <small class="text-danger" id="password-match-error" style="display:none;">Passwords do not match</small>
                            </div>
                            
                            <button type="submit" name="change_password" class="btn btn-warning" id="change-password-btn">
                                <i class="fas fa-key me-2"></i>Change Password
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Account Information -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <i class="fas fa-info-circle me-2"></i>Account Information
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <td><strong>Organization:</strong></td>
                                <td><?php echo htmlspecialchars($_SESSION['org_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Account Type:</strong></td>
                                <td>
                                    <?php 
                                    $sub_type = $_SESSION['subscription_type'] ?? 'demo';
                                    echo ucfirst($sub_type); 
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Subscription Expires:</strong></td>
                                <td>
                                    <?php 
                                    $sub_end = $_SESSION['subscription_end'] ?? date('Y-m-d');
                                    echo date('F d, Y', strtotime($sub_end)); 
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Member Since:</strong></td>
                                <td><?php echo date('F d, Y', strtotime($user['created_at'])); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Status:</strong></td>
                                <td>
                                    <?php if ($user['status'] == 'active'): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        

    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
jQuery(document).ready(function($) {
    // Password match validation
    $('#confirm_password').on('keyup', function() {
        const newPassword = $('#new_password').val();
        const confirmPassword = $(this).val();
        
        if (confirmPassword && newPassword !== confirmPassword) {
            $('#password-match-error').show();
            $('#change-password-btn').prop('disabled', true);
        } else {
            $('#password-match-error').hide();
            $('#change-password-btn').prop('disabled', false);
        }
    });
    
    // Form validation
    $('#password-form').on('submit', function(e) {
        const newPassword = $('#new_password').val();
        const confirmPassword = $('#confirm_password').val();
        
        if (newPassword !== confirmPassword) {
            e.preventDefault();
            alert('Passwords do not match!');
            return false;
        }
        
        if (newPassword.length < 6) {
            e.preventDefault();
            alert('Password must be at least 6 characters long!');
            return false;
        }
    });
});
</script>