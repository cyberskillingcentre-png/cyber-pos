<?php
// Client authentication helper
function require_client() {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'client') {
        header('Location: ../org-login.php');
        exit;
    }
}

function get_client_profile($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT u.*, cp.* 
        FROM users u
        LEFT JOIN client_profiles cp ON u.user_id = cp.user_id
        WHERE u.user_id = ? AND u.role = 'client'
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function get_client_discount_rate($conn, $user_id) {
    try {
        $stmt = $conn->prepare("SELECT discount_rate FROM client_profiles WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? floatval($result['discount_rate']) : 0;
    } catch(PDOException $e) {
        return 0;
    }
}
?>
