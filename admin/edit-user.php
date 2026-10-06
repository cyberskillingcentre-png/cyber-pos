<?php
require_once '../config/config.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = intval($_POST['user_id']);
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']) ?: null;
    $phone = trim($_POST['phone']) ?: null;
    $password = trim($_POST['password']);
    $role = $_POST['role'];
    $status = $_POST['status'];
    $stores = $_POST['stores'] ?? [];
    
    $company_name = trim($_POST['company_name'] ?? '') ?: null;
    $tax_id = trim($_POST['tax_id'] ?? '') ?: null;
    $billing_address = trim($_POST['billing_address'] ?? '') ?: null;
    $shipping_address = trim($_POST['shipping_address'] ?? '') ?: null;
    $credit_limit = floatval($_POST['credit_limit'] ?? 0);
    $discount_rate = floatval($_POST['discount_rate'] ?? 0);
    $payment_terms = $_POST['payment_terms'] ?? 'immediate';
    
    try {
        $conn->beginTransaction();
        
        if (!empty($password)) {
            $stmt = $conn->prepare("
                UPDATE users 
                SET full_name = ?, email = ?, phone = ?, password = ?, role = ?, status = ? 
                WHERE user_id = ?
            ");
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt->execute([$full_name, $email, $phone, $hashed_password, $role, $status, $user_id]);
        } else {
            $stmt = $conn->prepare("
                UPDATE users 
                SET full_name = ?, email = ?, phone = ?, role = ?, status = ? 
                WHERE user_id = ?
            ");
            $stmt->execute([$full_name, $email, $phone, $role, $status, $user_id]);
        }
        
        if ($role == 'client') {
            try {
                $stmt = $conn->prepare("SELECT profile_id FROM client_profiles WHERE user_id = ?");
                $stmt->execute([$user_id]);
                
                if ($stmt->fetch()) {
                    $stmt = $conn->prepare("
                        UPDATE client_profiles 
                        SET company_name = ?, tax_id = ?, billing_address = ?, shipping_address = ?,
                            credit_limit = ?, discount_rate = ?, payment_terms = ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([
                        $company_name, $tax_id, $billing_address, $shipping_address,
                        $credit_limit, $discount_rate, $payment_terms, $user_id
                    ]);
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO client_profiles 
                        (user_id, company_name, tax_id, billing_address, shipping_address,
                         credit_limit, discount_rate, payment_terms)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $user_id, $company_name, $tax_id, $billing_address, $shipping_address,
                        $credit_limit, $discount_rate, $payment_terms
                    ]);
                }
            } catch(PDOException $e) {
                error_log("Client profiles error: " . $e->getMessage());
            }
        }
        
        if ($role == 'employee') {
            $stmt = $conn->prepare("DELETE FROM user_stores WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            if (!empty($stores)) {
                $stmt = $conn->prepare("INSERT INTO user_stores (user_id, store_id) VALUES (?, ?)");
                foreach ($stores as $store_id) {
                    $stmt->execute([$user_id, $store_id]);
                }
            }
        }
        
        $conn->commit();
        set_alert('success', 'User updated successfully');
        header('Location: Users');
        exit;
        
    } catch(Exception $e) {
        $conn->rollBack();
        set_alert('danger', 'Error: ' . $e->getMessage());
        header('Location: Users');
        exit;
    }
}
?>