<?php
// ============================================
// REMOVE FAVORITE API
// ============================================
session_start();
require_once '../../config/config.php';
require_once '../config/client_auth.php';

header('Content-Type: application/json');

try {
    require_client();
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$product_id = isset($_GET['product']) ? intval($_GET['product']) : 0;
$client_id = $_SESSION['user_id'] ?? 0;

if ($product_id <= 0 || $client_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product or user']);
    exit;
}

try {
    $stmt = $conn->prepare("DELETE FROM client_favorites WHERE client_id = ? AND product_id = ?");
    $stmt->execute([$client_id, $product_id]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Removed from favorites',
        'product_id' => $product_id
    ]);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to remove: ' . $e->getMessage()
    ]);
}