<?php
require_once '../config/config.php';
require_admin();

$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$user_id) {
    set_alert('danger', 'Invalid user ID');
    header('Location: Users');
    exit();
}

// Cannot delete self
if ($user_id == $_SESSION['user_id']) {
    set_alert('danger', 'You cannot delete your own account');
    header('Location: Users');
    exit();
}

try {
    // Get user info before deleting
    $stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    // Delete user
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    
    log_activity($_SESSION['user_id'], 'USER_DELETED', "Deleted user: " . $user['username'], $conn);
    
    set_alert('success', 'User deleted successfully');
    
} catch(Exception $e) {
    set_alert('danger', 'Error: ' . $e->getMessage());
}

header('Location: Users');
exit();
?>