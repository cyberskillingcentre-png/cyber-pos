<?php
require_once '../config/config.php';
require_admin();

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit;
}

$user_id = intval($_GET['id']);

try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    $response = [
        'success' => true,
        'user' => $user,
        'client_profile' => null
    ];
    
    if ($user['role'] === 'client') {
        try {
            $stmt = $conn->prepare("SELECT * FROM client_profiles WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $response['client_profile'] = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            $response['client_profile'] = null;
        }
    }
    
    echo json_encode($response);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>