<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Master database connection
if (!defined('MASTER_DB_HOST')) {
    define('MASTER_DB_HOST', 'localhost');
    define('MASTER_DB_USER', 'root');
    define('MASTER_DB_PASS', '');
    define('MASTER_DB_NAME', 'pos_master');
}

// Database template (your current POS database structure)
if (!defined('TEMPLATE_DB_NAME')) {
    define('TEMPLATE_DB_NAME', 'electrical_pos_db'); // Change this to your actual template database name
}

// Application settings (only if not already defined)
if (!defined('APP_NAME')) {
    define('APP_NAME', 'CYBER POS');
}

if (!defined('APP_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
define('BASE_URL', $protocol . $host . '/');

}

if (!defined('DEMO_DAYS')) {
    define('DEMO_DAYS', 30);
    define('WARNING_DAYS', 5);
}

// Function to get or create master connection
function get_master_connection() {
    static $master_conn = null;
    
    if ($master_conn === null) {
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
    }
    
    return $master_conn;
}

// Initialize master connection
$master_conn = get_master_connection();

// Helper function to create organization database
function create_organization_database($org_code, $admin_username, $admin_password) {
    $master_conn = get_master_connection();
    
    $db_name = 'pos_' . strtolower($org_code);
    
    try {
        // Check if template database exists
        $stmt = $master_conn->query("SHOW DATABASES LIKE '" . TEMPLATE_DB_NAME . "'");
        if (!$stmt->fetch()) {
            throw new Exception("Template database '" . TEMPLATE_DB_NAME . "' not found. Please ensure the base POS system is installed first.");
        }
        
        // Drop existing database if any
        $master_conn->exec("DROP DATABASE IF EXISTS `$db_name`");
        
        // Create new database
        $master_conn->exec("CREATE DATABASE `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // Switch to new database
        $master_conn->exec("USE `$db_name`");
        
        // DISABLE FOREIGN KEY CHECKS
        $master_conn->exec("SET FOREIGN_KEY_CHECKS = 0");
        $master_conn->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
        
        // Get all tables from template database
        $tables_stmt = $master_conn->query("SHOW TABLES FROM `" . TEMPLATE_DB_NAME . "`");
        $tables = $tables_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($tables)) {
            throw new Exception("Template database is empty. Cannot create organization database.");
        }
        
        $created_tables = 0;
        $failed_tables = [];
        
        // Copy each table structure
        foreach ($tables as $table) {
            try {
                // Get CREATE TABLE statement - use backticks for table name
                $create_stmt = $master_conn->query("SHOW CREATE TABLE `" . TEMPLATE_DB_NAME . "`.`$table`");
                $result = $create_stmt->fetch(PDO::FETCH_ASSOC);
                
                // Check if we got a valid result
                if (!$result) {
                    $failed_tables[] = $table . " (no result)";
                    continue;
                }
                
                // The column name might be "Create Table" or just the table name
                $create_sql = null;
                if (isset($result['Create Table'])) {
                    $create_sql = $result['Create Table'];
                } elseif (isset($result[$table])) {
                    $create_sql = $result[$table];
                } else {
                    // Try to get the second element (index 1)
                    $values = array_values($result);
                    if (isset($values[1])) {
                        $create_sql = $values[1];
                    }
                }
                
                if (empty($create_sql)) {
                    $failed_tables[] = $table . " (empty SQL)";
                    continue;
                }
                
                // Create table in new database
                $master_conn->exec($create_sql);
                $created_tables++;
                
            } catch(PDOException $e) {
                $failed_tables[] = $table . " (" . $e->getMessage() . ")";
                // Continue with next table
            }
        }
        
        // Check if we created at least some tables
        if ($created_tables == 0) {
            throw new Exception("Failed to create any tables. Errors: " . implode(", ", $failed_tables));
        }
        
        // Copy data for non-transactional tables
        $exclude_tables = ['users', 'sales', 'sale_items', 'purchases', 'purchase_items', 'stock_transfers', 'activity_logs', 'payment_transactions'];
        
        foreach ($tables as $table) {
            if (!in_array($table, $exclude_tables)) {
                try {
                    // Check if table exists in new database
                    $check = $master_conn->query("SHOW TABLES LIKE '$table'")->fetch();
                    if (!$check) continue;
                    
                    // Check if source table has data
                    $count_stmt = $master_conn->query("SELECT COUNT(*) FROM `" . TEMPLATE_DB_NAME . "`.`$table`");
                    $count = $count_stmt->fetchColumn();
                    
                    if ($count > 0) {
                        // Copy data
                        $master_conn->exec("INSERT INTO `$db_name`.`$table` SELECT * FROM `" . TEMPLATE_DB_NAME . "`.`$table`");
                    }
                } catch(PDOException $e) {
                    // Ignore data copy errors
                }
            }
        }
        
        // RE-ENABLE FOREIGN KEY CHECKS
        $master_conn->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        // Insert custom admin user
        try {
            $stmt = $master_conn->query("SHOW TABLES LIKE 'users'");
            if ($stmt->fetch()) {
                $insert_stmt = $master_conn->prepare("
                    INSERT INTO users (username, password, full_name, email, role, status) 
                    VALUES (?, ?, 'Administrator', 'admin@organization.com', 'admin', 'active')
                ");
                $insert_stmt->execute([$admin_username, $admin_password]);
            }
        } catch(PDOException $e) {
            throw new Exception("Failed to create admin user: " . $e->getMessage());
        }
        
        // Insert default store
        try {
            $stmt = $master_conn->query("SHOW TABLES LIKE 'stores'");
            if ($stmt->fetch()) {
                // Check if stores table is empty
                $count = $master_conn->query("SELECT COUNT(*) FROM stores")->fetchColumn();
                if ($count == 0) {
                    $master_conn->exec("INSERT INTO stores (store_name, status) VALUES ('Main Store', 'active')");
                }
            }
        } catch(PDOException $e) {
            // Store creation is optional
        }
        
        // Switch back to master database
        $master_conn->exec("USE `" . MASTER_DB_NAME . "`");
        
        return $db_name;
        
    } catch(Exception $e) {
        // Re-enable foreign key checks if error occurs
        try {
            $master_conn->exec("SET FOREIGN_KEY_CHECKS = 1");
            $master_conn->exec("USE `" . MASTER_DB_NAME . "`");
        } catch(Exception $fk_error) {}
        
        // If database was created but error occurred, drop it
        try {
            $master_conn->exec("DROP DATABASE IF EXISTS `$db_name`");
        } catch(Exception $cleanup_error) {}
        
        throw new Exception("Database creation failed: " . $e->getMessage());
    }
}

// Check organization status
function check_organization_status($org_id) {
    $master_conn = get_master_connection();
    
    // Ensure master connection exists
    if (!$master_conn) {
        return ['valid' => false, 'message' => 'Master database connection not available'];
    }
    
    try {
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
                'message' => 'Your account has been suspended. Please contact support at: support@cyberpos.com or call: +256-XXX-XXXXXX',
                'org' => $org
            ];
        }
        
        if ($days_remaining < 0) {
            // Auto-suspend expired accounts
            $stmt = $master_conn->prepare("UPDATE organizations SET status = 'expired' WHERE org_id = ?");
            $stmt->execute([$org_id]);
            
            return [
                'valid' => false, 
                'message' => 'Your subscription has expired on ' . date('F d, Y', strtotime($org['subscription_end'])) . '. Please contact administrator to renew your subscription.<br><br>Email: admin@cyberpos.com<br>Phone: +256-XXX-XXXXXX',
                'org' => $org
            ];
        }
        
        return [
            'valid' => true,
            'days_remaining' => ceil($days_remaining),
            'show_warning' => ($days_remaining <= WARNING_DAYS),
            'org' => $org
        ];
        
    } catch(PDOException $e) {
        return [
            'valid' => false,
            'message' => 'Error checking subscription status: ' . $e->getMessage()
        ];
    }
}
?>