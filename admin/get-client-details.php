<?php
session_start();
require_once '../config/config.php';
require_login();
if (!is_admin()) { die(json_encode(['success' => false])); }

$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

try {
    $stmt = $conn->prepare("
        SELECT u.*, cp.*
        FROM users u
        LEFT JOIN client_profiles cp ON u.user_id = cp.user_id
        WHERE u.user_id = ? AND u.role = 'client'
    ");
    $stmt->execute([$user_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($client) {
        // Get order statistics
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_orders,
                COALESCE(SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END), 0) as pending_orders,
                COALESCE(SUM(grand_total), 0) as total_spent
            FROM client_orders
            WHERE client_id = ?
        ");
        $stmt->execute([$user_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'client' => $client,
            'stats' => $stats
        ]);
    } else {
        echo json_encode(['success' => false]);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false]);
}
