<?php
require_once '../config/config.php';
require_admin();

header('Content-Type: application/json');

if (!isset($_GET['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit;
}

$user_id = intval($_GET['user_id']);

try {
    $stmt = $conn->prepare("SELECT store_id, store_name FROM stores WHERE status = 'active' ORDER BY store_name");
    $stmt->execute();
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $conn->prepare("SELECT store_id FROM user_stores WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $assigned = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode([
        'success' => true,
        'stores' => $stores,
        'assigned_stores' => $assigned
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>