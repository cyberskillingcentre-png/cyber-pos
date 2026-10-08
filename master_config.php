<?php
/**
 * ============================================
 * CYBERPLAZA POS - MASTER CONFIGURATION
 * ============================================
 * Multi-tenant SaaS base configuration.
 * 
 * Handles:
 * - Master database connection
 * - Organization database creation (template-based)
 * - Subscription status checking
 * - Global constants
 */

// ============================================
// SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// ENVIRONMENT DETECTION
// ============================================
if (!defined('CYBER_ENV')) {
    define('CYBER_ENV', getenv('CYBER_ENV') ?: 'development');
}

$is_production = (CYBER_ENV === 'production');

// ============================================
// ERROR REPORTING
// ============================================
if ($is_production) {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// ============================================
// MASTER DATABASE CREDENTIALS
// ============================================
// Load from local config if it exists (gitignored)
$local_config = __DIR__ . '/master_config.local.php';
if (file_exists($local_config)) {
    require_once $local_config;
}

if (!defined('MASTER_DB_HOST')) define('MASTER_DB_HOST', 'localhost');
if (!defined('MASTER_DB_USER')) define('MASTER_DB_USER', 'root');
if (!defined('MASTER_DB_PASS')) define('MASTER_DB_PASS', '');
if (!defined('MASTER_DB_NAME')) define('MASTER_DB_NAME', 'pos_master');

// ============================================
// TEMPLATE DATABASE
// ============================================
// The template DB is CLONED to create new org databases.
// IMPORTANT: The template should contain SCHEMA ONLY — no user data.
if (!defined('TEMPLATE_DB_NAME')) {
    define('TEMPLATE_DB_NAME', 'pos_template'); // Rename to be explicit
}

// ============================================
// APPLICATION CONSTANTS
// ============================================
if (!defined('APP_NAME')) define('APP_NAME', 'CYBER POS');
if (!defined('APP_VERSION')) define('APP_VERSION', '1.0.0');

// ============================================
// BASE URL - SECURE DETECTION
// ============================================
// Do NOT use $_SERVER['HTTP_HOST'] directly — vulnerable to
// Host Header Injection attacks. Use a whitelist or configured value.
if (!defined('BASE_URL')) {
    // Option 1: Hard-coded (RECOMMENDED for production)
    if (defined('CONFIGURED_BASE_URL')) {
        define('BASE_URL', CONFIGURED_BASE_URL);
    } else {
        // Option 2: Auto-detect from script path (safer than HTTP_HOST)
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Whitelist: only allow known hosts
        $allowed_hosts = [
            'localhost',
            '127.0.0.1',
            'cyberplaza.tech',
            'www.cyberplaza.tech',
        ];
        
        // Strip port for comparison
        $host_no_port = explode(':', $host)[0];
        
        if (!in_array($host_no_port, $allowed_hosts) && !$is_production) {
            // In dev, allow any host
        } elseif (!in_array($host_no_port, $allowed_hosts) && $is_production) {
            // In production, reject unknown hosts
            die('Invalid host header');
        }
        
        // Get the project folder (e.g. /pos_updated/)
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $folder = '';
        
        if (preg_match('#^/([^/]+)/#', $script, $m)) {
            $folder = '/' . $m[1];
        }
        
        define('BASE_URL', rtrim($protocol . $host . $folder, '/') . '/');
    }
}

// ============================================
// SUBSCRIPTION SETTINGS
// ============================================
if (!defined('DEMO_DAYS')) define('DEMO_DAYS', 30);
if (!defined('WARNING_DAYS')) define('WARNING_DAYS', 5);

// ============================================
// SUPPORT CONTACT
// ============================================
if (!defined('SUPPORT_EMAIL')) define('SUPPORT_EMAIL', 'info@cyberplaza.tech');
if (!defined('SUPPORT_PHONE')) define('SUPPORT_PHONE', '+256 757 741 977');

// ============================================
// MASTER CONNECTION
// ============================================
function get_master_connection() {
    static $master_conn = null;
    
    if ($master_conn === null) {
        try {
            $master_conn = new PDO(
                "mysql:host=" . MASTER_DB_HOST . ";dbname=" . MASTER_DB_NAME . ";charset=utf8mb4",
                MASTER_DB_USER,
                MASTER_DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch(PDOException $e) {
            error_log("Master DB connection failed: " . $e->getMessage());
            die($is_production 
                ? "Database connection failed. Please contact support." 
                : "Master Database Connection Failed: " . $e->getMessage());
        }
    }
    
    return $master_conn;
}

$master_conn = get_master_connection();

// ============================================
// TABLE CLONING - SECURE VERSION
// ============================================
/**
 * Create a new organization database by cloning the template schema.
 * 
 * SECURITY: Uses `SHOW CREATE TABLE` to get ONLY the schema (no data).
 * Excludes sensitive tables from data copy entirely.
 * 
 * @param string $org_code       Unique org code (used in DB name)
 * @param string $admin_username Admin username
 * @param string $admin_password Hashed admin password
 * @param string $admin_email    Admin email (optional)
 * @param string $org_name       Organization name (for settings)
 * @return string                Database name
 */
function create_organization_database($org_code, $admin_username, $admin_password, $admin_email = '', $org_name = '') {
    $master_conn = get_master_connection();
    
    // Sanitize DB name — only lowercase letters, numbers, underscores
    $db_name = 'pos_' . strtolower(preg_replace('/[^a-z0-9_]/i', '', $org_code));
    
    try {
        // Verify template exists
        $stmt = $master_conn->prepare("SHOW DATABASES LIKE ?");
        $stmt->execute([TEMPLATE_DB_NAME]);
        if (!$stmt->fetch()) {
            throw new Exception("Template database '" . TEMPLATE_DB_NAME . "' not found.");
        }
        
        // Check for existing DB
        $stmt = $master_conn->prepare("SHOW DATABASES LIKE ?");
        $stmt->execute([$db_name]);
        if ($stmt->fetch()) {
            throw new Exception("Database '$db_name' already exists. Please try a different org code.");
        }
        
        // Create the new database
        $master_conn->exec("CREATE DATABASE `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // Switch to new database
        $master_conn->exec("USE `$db_name`");
        $master_conn->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        // ============================================
        // CLONE SCHEMA ONLY (no data)
        // ============================================
        $tables_stmt = $master_conn->prepare("SHOW TABLES FROM `" . TEMPLATE_DB_NAME . "`");
        $tables_stmt->execute();
        $tables = $tables_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($tables)) {
            throw new Exception("Template database is empty.");
        }
        
        $created_tables = 0;
        $failed_tables = [];
        
        foreach ($tables as $table) {
            // Validate table name (prevent SQL injection)
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                $failed_tables[] = "$table (invalid name)";
                continue;
            }
            
            try {
                // Get CREATE TABLE statement
                $stmt = $master_conn->prepare("SHOW CREATE TABLE `" . TEMPLATE_DB_NAME . "`.`$table`");
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_NUM);
                
                if (!$result || !isset($result[1])) {
                    $failed_tables[] = "$table (no SQL)";
                    continue;
                }
                
                $create_sql = $result[1];
                
                // Execute CREATE TABLE
                $master_conn->exec($create_sql);
                $created_tables++;
                
            } catch(PDOException $e) {
                $failed_tables[] = "$table (" . $e->getMessage() . ")";
            }
        }
        
        if ($created_tables === 0) {
            throw new Exception("Failed to create any tables. Errors: " . implode(", ", array_slice($failed_tables, 0, 5)));
        }
        
        // ============================================
        // INSERT DEFAULT DATA (SAFE, per-org)
        // ============================================
        // We DO NOT copy data from the template. Instead, we insert
        // only the minimum required seed data for a new organization.
        
        // 1. Create the admin user
        try {
            $stmt = $master_conn->prepare("
                INSERT INTO users (username, password, full_name, email, role, status) 
                VALUES (?, ?, 'Administrator', ?, 'admin', 'active')
            ");
            $stmt->execute([
                $admin_username,
                $admin_password,
                $admin_email ?: $admin_username . '@' . strtolower($org_code) . '.local'
            ]);
            $admin_id = $master_conn->lastInsertId();
        } catch(PDOException $e) {
            throw new Exception("Failed to create admin user: " . $e->getMessage());
        }
        
        // 2. Create the default store
        try {
            $stmt = $master_conn->prepare("
                INSERT INTO stores (store_code, store_name, location, status) 
                VALUES ('MAIN', 'Main Store', 'Head Office', 'active')
            ");
            $stmt->execute();
            $store_id = $master_conn->lastInsertId();
        } catch(PDOException $e) {
            // Some schemas don't require stores
            $store_id = null;
        }
        
        // 3. Assign admin to the default store
        if ($store_id) {
            try {
                $stmt = $master_conn->prepare("
                    INSERT INTO user_stores (user_id, store_id) 
                    VALUES (?, ?)
                ");
                $stmt->execute([$admin_id, $store_id]);
                
                // Set admin's default store
                $stmt = $master_conn->prepare("
                    UPDATE users SET default_store_id = ? WHERE user_id = ?
                ");
                $stmt->execute([$store_id, $admin_id]);
            } catch(PDOException $e) {
                // user_stores table might not exist in template
            }
        }
        
        // 4. Insert default system settings
        try {
            $settings = [
                ['company_name', $org_name ?: 'My Business', 'text'],
                ['company_email', $admin_email, 'text'],
                ['company_phone', '', 'text'],
                ['company_address', '', 'text'],
                ['currency', 'UGX', 'text'],
                ['tax_rate', '18', 'number'],
                ['low_stock_threshold', '10', 'number'],
                ['receipt_footer', 'Thank you for your business!', 'text'],
            ];
            
            $stmt = $master_conn->prepare("
                INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_type) 
                VALUES (?, ?, ?)
            ");
            foreach ($settings as $s) {
                $stmt->execute($s);
            }
        } catch(PDOException $e) {
            // system_settings table might not exist
        }
        
        // 5. Log the creation
        try {
            $stmt = $master_conn->prepare("
                INSERT INTO activity_logs (user_id, activity_type, activity_description) 
                VALUES (?, 'ORG_CREATED', ?)
            ");
            $stmt->execute([$admin_id, "Organization '$org_name' created"]);
        } catch(PDOException $e) {
            // activity_logs might not exist
        }
        
        // Re-enable FK checks
        $master_conn->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        // Return to master
        $master_conn->exec("USE `" . MASTER_DB_NAME . "`");
        
        return $db_name;
        
    } catch(Exception $e) {
        // Cleanup on error
        try {
            $master_conn->exec("SET FOREIGN_KEY_CHECKS = 1");
            $master_conn->exec("USE `" . MASTER_DB_NAME . "`");
            
            // Drop the partially-created DB
            $master_conn->exec("DROP DATABASE IF EXISTS `$db_name`");
        } catch(Exception $cleanup_error) {
            error_log("Cleanup failed: " . $cleanup_error->getMessage());
        }
        
        error_log("Org DB creation failed: " . $e->getMessage());
        throw new Exception("Database creation failed: " . $e->getMessage());
    }
}

// ============================================
// SUBSCRIPTION STATUS CHECK
// ============================================
function check_organization_status($org_id) {
    $master_conn = get_master_connection();
    
    if (!$master_conn) {
        return ['valid' => false, 'message' => 'Master database connection not available'];
    }
    
    try {
        $stmt = $master_conn->prepare("SELECT * FROM organizations WHERE org_id = ?");
        $stmt->execute([$org_id]);
        $org = $stmt->fetch();
        
        if (!$org) {
            return ['valid' => false, 'message' => 'Organization not found'];
        }
        
        // Suspended?
        if ($org['status'] === 'suspended') {
            return [
                'valid' => false,
                'message' => 'Your account has been suspended. Please contact support at: ' . SUPPORT_EMAIL . ' or call: ' . SUPPORT_PHONE,
                'org' => $org
            ];
        }
        
        // Calculate days remaining
        $today = new DateTime(date('Y-m-d'));
        $end_date = new DateTime($org['subscription_end']);
        $diff = $today->diff($end_date);
        $days_remaining = $end_date >= $today ? $diff->days : -$diff->days;
        
        // Expired?
        if ($days_remaining < 0) {
            // Auto-suspend
            $stmt = $master_conn->prepare("UPDATE organizations SET status = 'expired' WHERE org_id = ?");
            $stmt->execute([$org_id]);
            
            return [
                'valid' => false,
                'message' => 'Your subscription expired on ' . date('F d, Y', strtotime($org['subscription_end'])) . '. Please renew your subscription.<br><br>Email: ' . SUPPORT_EMAIL . '<br>Phone: ' . SUPPORT_PHONE,
                'org' => $org
            ];
        }
        
        return [
            'valid' => true,
            'days_remaining' => $days_remaining,
            'show_warning' => ($days_remaining <= WARNING_DAYS),
            'org' => $org
        ];
        
    } catch(PDOException $e) {
        error_log("Status check failed: " . $e->getMessage());
        return [
            'valid' => false,
            'message' => 'Error checking subscription status.'
        ];
    }
}

// ============================================
// HELPER: Get organization connection
// ============================================
/**
 * Get a PDO connection to a specific organization's database.
 * 
 * @param string $database_name
 * @return PDO
 * @throws Exception
 */
function get_organization_connection($database_name) {
    // Validate the DB name to prevent injection
    if (!preg_match('/^pos_[a-z0-9_]+$/', $database_name)) {
        throw new Exception("Invalid database name");
    }
    
    try {
        return new PDO(
            "mysql:host=" . MASTER_DB_HOST . ";dbname=" . $database_name . ";charset=utf8mb4",
            MASTER_DB_USER,
            MASTER_DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch(PDOException $e) {
        error_log("Org DB connection failed: " . $e->getMessage());
        throw new Exception("Could not connect to organization database");
    }
}