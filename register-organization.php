<?php
require_once 'master_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $transaction_started = false;
    
    try {
        $org_name = trim($_POST['org_name']);
        $contact_person = trim($_POST['contact_person']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address');
        }
        
        // Validate username
        if (strlen($username) < 4) {
            throw new Exception('Username must be at least 4 characters long');
        }
        
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            throw new Exception('Username can only contain letters, numbers and underscore');
        }
        
        // Validate password
        if (strlen($password) < 6) {
            throw new Exception('Password must be at least 6 characters long');
        }
        
        if ($password !== $confirm_password) {
            throw new Exception('Passwords do not match');
        }
        
        // Check if email already exists
        $stmt = $master_conn->prepare("SELECT org_id FROM organizations WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception('Email already registered. Please use a different email address.');
        }
        
        // Check if username already exists in any organization
        $stmt = $master_conn->prepare("SELECT org_id FROM organizations WHERE LOWER(admin_username) = LOWER(?)");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            throw new Exception('Username already taken. Please choose a different username.');
        }
        
        // Generate unique org code
        $org_code = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($org_name)), 0, 6)) . rand(100, 999);
        
        // Check if code exists
        $stmt = $master_conn->prepare("SELECT org_id FROM organizations WHERE org_code = ?");
        $stmt->execute([$org_code]);
        while ($stmt->fetch()) {
            $org_code = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($org_name)), 0, 6)) . rand(100, 999);
            $stmt = $master_conn->prepare("SELECT org_id FROM organizations WHERE org_code = ?");
            $stmt->execute([$org_code]);
        }
        
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Create organization database with custom credentials
        $db_name = create_organization_database($org_code, $username, $hashed_password);
        
        // Calculate subscription dates
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime('+' . DEMO_DAYS . ' days'));
        
        // NOW start transaction
        $master_conn->beginTransaction();
        $transaction_started = true;
        
        // Insert organization
        $stmt = $master_conn->prepare("
            INSERT INTO organizations 
            (org_name, org_code, contact_person, email, phone, address, database_name, 
             admin_username, subscription_type, subscription_start, subscription_end, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'demo', ?, ?, 'active')
        ");
        $stmt->execute([
            $org_name, $org_code, $contact_person, $email, $phone, 
            $address, $db_name, $username, $start_date, $end_date
        ]);
        
        $org_id = $master_conn->lastInsertId();
        
        // Log subscription history
        $stmt = $master_conn->prepare("
            INSERT INTO subscription_history 
            (org_id, action, new_end_date, days_added, notes) 
            VALUES (?, 'created', ?, ?, 'Demo account created')
        ");
        $stmt->execute([$org_id, $end_date, DEMO_DAYS]);
        
        $master_conn->commit();
        
        // Redirect to success page
        header('Location: Registered_Successfully?org_code=' . $org_code . '&username=' . urlencode($username));
        exit();
        
    } catch(Exception $e) {
        if ($transaction_started && isset($master_conn)) {
            $master_conn->rollBack();
        }
        
        // Show error page
        $error_message = $e->getMessage();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Registration Error - CYBER POS</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
            <style>
                .error-container {
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    background: linear-gradient(135deg, #6B46C1 0%, #805AD5 100%);
                }
                .error-card {
                    background: white;
                    border-radius: 15px;
                    padding: 40px;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="container">
                    <div class="row justify-content-center">
                        <div class="col-md-6">
                            <div class="error-card">
                                <div class="text-center mb-4">
                                    <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                                    <h3>Registration Error</h3>
                                </div>
                                
                                <div class="alert alert-danger">
                                    <strong>Error:</strong> <?php echo htmlspecialchars($error_message); ?>
                                </div>
                                
                                <div class="text-center">
                                    <a href="Welcome_to_CYBER_POS#register" class="btn btn-primary">
                                        <i class="fas fa-arrow-left me-2"></i>Try Again
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit();
    }
} else {
    header('Location: Welcome_to_CYBER_POS');
    exit();
}
?>