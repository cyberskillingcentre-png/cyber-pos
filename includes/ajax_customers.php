<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

try {
    $stmt = $conn->query("
        SELECT customer_id, customer_name, phone
        FROM customers
        WHERE status = 'active'
        ORDER BY customer_name
        LIMIT 500
    ");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    error_log('ajax_customers error: ' . $e->getMessage());
    echo json_encode([]);
}