<?php
require_once __DIR__ . '/../master_config.php';
// TEMPORARY DEBUG - REMOVE AFTER FIXING
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 3600);
    session_start();
}

// Database Credentials - ALWAYS DEFINED
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');

// Determine which database to use
if (isset($_SESSION['database_name']) && !empty($_SESSION['database_name'])) {
    define('DB_NAME', $_SESSION['database_name']);
} else {
    define('DB_NAME', 'electrical_pos_db');
}

// Application Configuration
if (!defined('APP_NAME')) {
    define('APP_NAME', 'CYBER POS');
}

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}

if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
    
}

// Timezone
date_default_timezone_set('Africa/Kampala');

// Database Connection Class
class Database {
    private $host = DB_HOST;
    private $user = DB_USER;
    private $pass = DB_PASS;
    private $dbname = DB_NAME;
    private $conn;
    private $error;

    public function __construct() {
        $dsn = 'mysql:host=' . $this->host . ';dbname=' . $this->dbname . ';charset=utf8mb4';
        $options = array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        );

        try {
            $this->conn = new PDO($dsn, $this->user, $this->pass, $options);
        } catch(PDOException $e) {
            $this->error = $e->getMessage();
            
            // Better error message for debugging
            $error_msg = "Connection failed: " . $this->error . "<br>";
            $error_msg .= "Database: " . $this->dbname . "<br>";
            $error_msg .= "Host: " . $this->host . "<br>";
            $error_msg .= "User: " . $this->user . "<br>";
            $error_msg .= "Password: " . (empty($this->pass) ? 'EMPTY' : 'SET') . "<br>";
            
            die($error_msg);
        }
    }

    public function getConnection() {
        return $this->conn;
    }
}

// Security Functions
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * ============================================
 * IDOR PROTECTION HELPERS
 * ============================================
 */

/**
 * Fetch a sale with ownership check.
 * Admin can see all. Employee can only see their own.
 * 
 * @param PDO $conn
 * @param int $sale_id
 * @return array|null
 */
function get_sale_with_access_check($conn, $sale_id) {
    $user_id = $_SESSION['user_id'] ?? 0;
    $is_admin = (($_SESSION['role'] ?? '') === 'admin');
    
    if ($is_admin) {
        // Admin sees all
        $stmt = $conn->prepare("
            SELECT s.*, 
                   st.store_name, 
                   st.phone as store_phone,
                   st.location as store_location,
                   c.customer_name, 
                   u.full_name as served_by_name
            FROM sales s
            LEFT JOIN stores st ON s.store_id = st.store_id
            LEFT JOIN customers c ON s.customer_id = c.customer_id
            LEFT JOIN users u ON s.served_by = u.user_id
            WHERE s.sale_id = ?
        ");
        $stmt->execute([$sale_id]);
    } else {
        // Employee — only their own
        $stmt = $conn->prepare("
            SELECT s.*, 
                   st.store_name, 
                   st.phone as store_phone,
                   st.location as store_location,
                   c.customer_name, 
                   u.full_name as served_by_name
            FROM sales s
            LEFT JOIN stores st ON s.store_id = st.store_id
            LEFT JOIN customers c ON s.customer_id = c.customer_id
            LEFT JOIN users u ON s.served_by = u.user_id
            WHERE s.sale_id = ? 
            AND s.served_by = ?
        ");
        $stmt->execute([$sale_id, $user_id]);
    }
    
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Fetch a client order with ownership check.
 * Admin can see all. Client can only see their own.
 * 
 * @param PDO $conn
 * @param int $order_id
 * @return array|null
 */
function get_order_with_access_check($conn, $order_id) {
    $user_id = $_SESSION['user_id'] ?? 0;
    $is_admin = (($_SESSION['role'] ?? '') === 'admin');
    
    if ($is_admin) {
        $stmt = $conn->prepare("
            SELECT co.*, 
                   s.store_name,
                   s.phone as store_phone,
                   s.location as store_location,
                   u.full_name as client_name,
                   u.email as client_email,
                   u.phone as client_phone,
                   cp.company_name,
                   cp.billing_address
            FROM client_orders co
            LEFT JOIN stores s ON co.store_id = s.store_id
            LEFT JOIN users u ON co.client_id = u.user_id
            LEFT JOIN client_profiles cp ON u.user_id = cp.user_id
            WHERE co.order_id = ?
        ");
        $stmt->execute([$order_id]);
    } else {
        $stmt = $conn->prepare("
            SELECT co.*, 
                   s.store_name,
                   s.phone as store_phone,
                   s.location as store_location,
                   u.full_name as client_name,
                   u.email as client_email,
                   u.phone as client_phone,
                   cp.company_name,
                   cp.billing_address
            FROM client_orders co
            LEFT JOIN stores s ON co.store_id = s.store_id
            LEFT JOIN users u ON co.client_id = u.user_id
            LEFT JOIN client_profiles cp ON u.user_id = cp.user_id
            WHERE co.order_id = ? 
            AND co.client_id = ?
        ");
        $stmt->execute([$order_id, $user_id]);
    }
    
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Check if current user has access to a sale.
 * Returns true/false without fetching.
 */
function user_can_access_sale($conn, $sale_id) {
    $user_id = $_SESSION['user_id'] ?? 0;
    $is_admin = (($_SESSION['role'] ?? '') === 'admin');
    
    if ($is_admin) return true;
    
    $stmt = $conn->prepare("SELECT 1 FROM sales WHERE sale_id = ? AND served_by = ?");
    $stmt->execute([$sale_id, $user_id]);
    return (bool) $stmt->fetch();
}

/**
 * Check if current user has access to a client order.
 */
function user_can_access_order($conn, $order_id) {
    $user_id = $_SESSION['user_id'] ?? 0;
    $is_admin = (($_SESSION['role'] ?? '') === 'admin');
    
    if ($is_admin) return true;
    
    $stmt = $conn->prepare("SELECT 1 FROM client_orders WHERE order_id = ? AND client_id = ?");
    $stmt->execute([$order_id, $user_id]);
    return (bool) $stmt->fetch();
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    return true;
}

// Authentication Functions
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) && isset($_SESSION['database_name']);
}

function is_admin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function require_login() {
    if (!is_logged_in()) {
        session_destroy();
        header('Location: ' . BASE_URL . 'Login');
        exit();
    }
    
    check_subscription_status();
}

function require_admin() {
    require_login();
    if (!is_admin()) {
        header('Location: ' . BASE_URL . 'Employee_Dashboard');
        exit();
    }
}

// Subscription Status Checker
function check_subscription_status() {
    if (!isset($_SESSION['org_id'])) {
        return;
    }
    
    $master_config_path = __DIR__ . '/../master_config.php';
    if (!file_exists($master_config_path)) {
        return; // Skip if master_config doesn't exist
    }
    
    require_once $master_config_path;
    
    $status = check_organization_status($_SESSION['org_id']);
    
    if (!$status['valid']) {
        session_destroy();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Subscription Expired - <?php echo APP_NAME; ?></title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
            <style>
                .expired-container {
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    background: linear-gradient(135deg, #E53E3E 0%, #C53030 100%);
                }
                .expired-card {
                    background: white;
                    border-radius: 15px;
                    padding: 50px;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                    text-align: center;
                }
            </style>
        </head>
        <body>
            <div class="expired-container">
                <div class="container">
                    <div class="row justify-content-center">
                        <div class="col-md-6">
                            <div class="expired-card">
                                <i class="fas fa-exclamation-circle fa-5x text-danger mb-4"></i>
                                <h2 class="mb-4">Subscription Expired</h2>
                                <div class="alert alert-danger">
                                    <?php echo $status['message']; ?>
                                </div>
                                <a href="<?php echo BASE_URL; ?>Login" class="btn btn-primary">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Login
                                </a>
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
}

// Logging Function
function log_activity($user_id, $activity_type, $description, $conn) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, activity_type, activity_description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $activity_type, $description, $ip_address, $user_agent]);
    } catch(PDOException $e) {
        // Silent fail for logging
    }
}

// Utility Functions
function format_currency($amount) {
    return number_format($amount, 2);
}

function generate_invoice_number() {
    return 'INV' . date('Ymd') . rand(1000, 9999);
}

function generate_barcode($prefix = 'PRD') {
    return $prefix . date('Ymd') . rand(10000, 99999);
}

// Alert Functions
function set_alert($type, $message) {
    $_SESSION['alert'] = [
        'type' => $type,
        'message' => $message
    ];
}

function get_alert() {
    if (isset($_SESSION['alert'])) {
        $alert = $_SESSION['alert'];
        unset($_SESSION['alert']);
        return $alert;
    }
    return null;
}

// File Upload Function
function upload_file($file, $target_dir, $allowed_types = ['jpg', 'jpeg', 'png', 'gif']) {
    if (!isset($file['name']) || empty($file['name'])) {
        return ['success' => false, 'message' => 'No file selected'];
    }

    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_extension, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }

    $new_filename = uniqid() . '_' . time() . '.' . $file_extension;
    $target_file = $target_dir . '/' . $new_filename;

    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        return ['success' => true, 'filename' => $new_filename];
    } else {
        return ['success' => false, 'message' => 'Failed to upload file'];
    }
}

// Get subscription days remaining
function get_days_remaining() {
    if (!isset($_SESSION['subscription_end'])) {
        return null;
    }
    
    $today = date('Y-m-d');
    $days = (strtotime($_SESSION['subscription_end']) - strtotime($today)) / (60 * 60 * 24);
    return ceil($days);
}

// Check if warning should be shown
function show_expiry_warning() {
    $days = get_days_remaining();
    if ($days === null) return false;
    
    return ($days <= 5 && $days > 0);
}

// Initialize Database Connection (only if logged in)
if (isset($_SESSION['database_name'])) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
    } catch(Exception $e) {
        // Database connection failed
        session_destroy();
        header('Location: ' . BASE_URL . 'Login');
        exit();
    }
}
?>