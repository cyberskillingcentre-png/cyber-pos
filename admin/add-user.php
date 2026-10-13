<?php
require_once '../config/config.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    csrf_require();
    $username = trim($_POST['username']);
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']) ?: null;
    $phone = trim($_POST['phone']) ?: null;
    $password = $_POST['password'];
    $role = $_POST['role'];
    $status = $_POST['status'];
    $stores = $_POST['stores'] ?? [];
    
    // Client-specific fields
    $company_name = trim($_POST['company_name'] ?? '') ?: null;
    $tax_id = trim($_POST['tax_id'] ?? '') ?: null;
    $billing_address = trim($_POST['billing_address'] ?? '') ?: null;
    $shipping_address = trim($_POST['shipping_address'] ?? '') ?: null;
    $credit_limit = floatval($_POST['credit_limit'] ?? 0);
    $discount_rate = floatval($_POST['discount_rate'] ?? 0);
    $payment_terms = $_POST['payment_terms'] ?? 'immediate';
    
    try {
        $conn->beginTransaction();
        
        // Check if username exists
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            throw new Exception("Username already exists");
        }
        
        // Insert user
        $stmt = $conn->prepare("
            INSERT INTO users (username, password, full_name, email, phone, role, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt->execute([$username, $hashed_password, $full_name, $email, $phone, $role, $status]);
        $user_id = $conn->lastInsertId();
        
        // If client, create client profile (only if client_profiles table exists)
        if ($role == 'client') {
            try {
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
            } catch(PDOException $e) {
                // client_profiles table doesn't exist, continue without it
                error_log("Client profiles table not found: " . $e->getMessage());
            }
        }
        
        // If employee, assign stores
        if ($role == 'employee' && !empty($stores)) {
            $stmt = $conn->prepare("INSERT INTO user_stores (user_id, store_id) VALUES (?, ?)");
            foreach ($stores as $store_id) {
                $stmt->execute([$user_id, $store_id]);
            }
        }
        
        $conn->commit();
        set_alert('success', ucfirst($role) . ' added successfully');
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