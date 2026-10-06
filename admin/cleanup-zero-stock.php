<?php
require_once '../config/config.php';
require_admin();

try {
    // Delete all batches with zero or negative quantity
    $stmt = $conn->prepare("DELETE FROM store_inventory WHERE quantity <= 0");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    
    log_activity($_SESSION['user_id'], 'INVENTORY_CLEANUP', "Cleaned up $deleted zero stock batches", $conn);
    
    set_alert('success', "Successfully cleaned up $deleted zero stock batches");
    
} catch(Exception $e) {
    set_alert('danger', 'Error: ' . $e->getMessage());
}

header('Location: Stock_Levels');
exit();
?>