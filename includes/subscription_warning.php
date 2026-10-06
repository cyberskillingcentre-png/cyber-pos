<?php
// subscription_warning.php - Checks for subscription expiring within 10 days
if (isset($_SESSION['org_id'])) {
    try {
        // Get organization subscription info
        $stmt = $conn->prepare("SELECT subscription_end, status FROM organizations WHERE org_id = ?");
        $stmt->execute([$_SESSION['org_id']]);
        $org_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($org_data && $org_data['status'] == 'active') {
            $subscription_end = new DateTime($org_data['subscription_end']);
            $today = new DateTime();
            $days_remaining = $today->diff($subscription_end)->days;
            
            // Check if subscription is expiring in less than 10 days
            if ($days_remaining <= 10 && $days_remaining > 0) {
                $_SESSION['subscription_warning'] = [
                    'days_remaining' => $days_remaining,
                    'expiry_date' => $org_data['subscription_end'],
                    'message' => "Your subscription expires in $days_remaining day" . ($days_remaining == 1 ? '' : 's')
                ];
            } 
            // Check if subscription has expired
            elseif ($subscription_end < $today) {
                $_SESSION['subscription_warning'] = [
                    'days_remaining' => 0,
                    'expiry_date' => $org_data['subscription_end'],
                    'message' => "Your subscription has expired!"
                ];
            } else {
                unset($_SESSION['subscription_warning']);
            }
        }
    } catch (PDOException $e) {
        // Log error silently
        error_log("Subscription warning check failed: " . $e->getMessage());
    }
}
?>