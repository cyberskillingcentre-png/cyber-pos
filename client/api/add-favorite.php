<?php
// ============================================
// ADD FAVORITE API
// ============================================
session_start();
require_once '../../config/config.php';
require_once '../config/client_auth.php';

// Force JSON response
header('Content-Type: application/json');

// Auth check
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

// Verify product exists
try {
    $stmt = $conn->prepare("SELECT product_id FROM products WHERE product_id = ? AND status = 'active'");
    $stmt->execute([$product_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Auto-create client_favorites table if missing
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `client_favorites` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `client_id` INT NOT NULL,
          `product_id` INT NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `unique_favorite` (`client_id`, `product_id`),
          KEY `idx_client` (`client_id`),
          KEY `idx_product` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch(PDOException $e) {}

// Insert favorite (ignore duplicates)
try {
    $stmt = $conn->prepare("
        INSERT INTO client_favorites (client_id, product_id) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$client_id, $product_id]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Added to favorites',
        'product_id' => $product_id
    ]);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to add favorite: ' . $e->getMessage()
    ]);
}