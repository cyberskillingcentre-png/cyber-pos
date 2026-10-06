<?php
session_start();

// Master database connection
define('MASTER_DB_HOST', '127.0.0.1');
define('MASTER_DB_USER', 'root');
define('MASTER_DB_PASS', '');
define('MASTER_DB_NAME', 'pos_master');

// Database template (your current POS database structure)
define('TEMPLATE_DB_NAME', 'electrical_pos_system');

// Application settings
define('APP_NAME', 'CloudPOS');
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
define('BASE_URL', $protocol . $host . '/');

define('DEMO_DAYS', 30);
define('WARNING_DAYS', 5);

try {
    $master_conn = new PDO(
        "mysql:host=" . MASTER_DB_HOST . ";dbname=" . MASTER_DB_NAME,
        MASTER_DB_USER,
        MASTER_DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch(PDOException $e) {
    die("Master Database Connection Failed: " . $e->getMessage());
}

// Helper function to create organization database
function create_organization_database($org_code) {
    global $master_conn;
    
    $db_name = 'pos_' . strtolower($org_code);
    
    try {
        // Create new database
        $master_conn->exec("CREATE DATABASE `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // Copy structure from template
        $tables_query = $master_conn->query("SHOW TABLES FROM " . TEMPLATE_DB_NAME);
        $tables = $tables_query->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($tables as $table) {
            // Get CREATE TABLE statement
            $create_query = $master_conn->query("SHOW CREATE TABLE " . TEMPLATE_DB_NAME . ".`$table`");
            $create_statement = $create_query->fetch(PDO::FETCH_ASSOC);
            
            // Create table in new database
            $master_conn->exec("USE `$db_name`");
            $master_conn->exec($create_statement['Create Table']);
        }
        
        // Insert default admin user (username: admin, password: admin123)
        $master_conn->exec("USE `$db_name`");
        $master_conn->exec("INSERT INTO users (username, password, full_name, email, role, status) 
                           VALUES ('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin@organization.com', 'admin', 'active')");
        
        // Insert default store
        $master_conn->exec("INSERT INTO stores (store_name, status) VALUES ('Main Store', 'active')");
        
        return $db_name;
        
    } catch(PDOException $e) {
        throw new Exception("Database creation failed: " . $e->getMessage());
    }
}

// Check organization status
function check_organization_status($org_id) {
    global $master_conn;
    
    $stmt = $master_conn->prepare("SELECT * FROM organizations WHERE org_id = ?");
    $stmt->execute([$org_id]);
    $org = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$org) {
        return ['valid' => false, 'message' => 'Organization not found'];
    }
    
    // Check if expired
    $today = date('Y-m-d');
    $days_remaining = (strtotime($org['subscription_end']) - strtotime($today)) / (60 * 60 * 24);
    
    if ($org['status'] == 'suspended') {
        return [
            'valid' => false, 
            'message' => 'Your account has been suspended. Please contact support.',
            'org' => $org
        ];
    }
    
    if ($days_remaining < 0) {
        // Auto-suspend expired accounts
        $stmt = $master_conn->prepare("UPDATE organizations SET status = 'expired' WHERE org_id = ?");
        $stmt->execute([$org_id]);
        
        return [
            'valid' => false, 
            'message' => 'Your subscription has expired. Please contact administrator at: admin@possystem.com or call: +256-XXX-XXXXXX',
            'org' => $org
        ];
    }
    
    return [
        'valid' => true,
        'days_remaining' => ceil($days_remaining),
        'show_warning' => ($days_remaining <= WARNING_DAYS),
        'org' => $org
    ];
}
?>
