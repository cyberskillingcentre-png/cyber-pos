<?php
/**
 * ============================================
 * CYBERPLAZA POS - MASTER CONFIGURATION
 * ============================================
 * Multi-tenant SaaS base configuration.
 *
 * Handles:
 * - Master database connection
 * - Organization database creation (from schema.sql)
 * - Subscription status checking
 * - Global constants
 */

// ============================================
// LOCAL OVERRIDES (loaded first)
// ============================================
// Create master_config.local.php alongside this file to override
// credentials (MASTER_DB_HOST, MASTER_DB_USER, etc.) without
// committing them to version control.
$local_config = __DIR__ . '/master_config.local.php';
if (file_exists($local_config)) {
    require_once $local_config;
}

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
if (!defined('MASTER_DB_HOST')) define('MASTER_DB_HOST', 'localhost');
if (!defined('MASTER_DB_USER')) define('MASTER_DB_USER', 'root');
if (!defined('MASTER_DB_PASS')) define('MASTER_DB_PASS', 'waniaye@Tech1');
if (!defined('MASTER_DB_NAME')) define('MASTER_DB_NAME', 'pos_master');

// ============================================
// ORGANIZATION SCHEMA FILE
// ============================================
// Canonical SQL file used to build every new organization database.
// This file must contain:
//   - CREATE TABLE IF NOT EXISTS statements
//   - Default seed INSERTs (e.g. the MAIN store, system_settings rows)
// It must NOT contain CREATE DATABASE or USE statements — the
// create_organization_database() function handles DB creation itself.
//
// __DIR__ is used so the path works regardless of the calling script's
// current working directory.
if (!defined('ORG_SCHEMA_FILE')) {
    define('ORG_SCHEMA_FILE', __DIR__ . '/config/schema.sql');
}

// ============================================
// APPLICATION CONSTANTS
// ============================================
if (!defined('APP_NAME')) define('APP_NAME', 'CYBER POS');
if (!defined('APP_VERSION')) define('APP_VERSION', '1.0.0');

// ============================================
// BASE URL - SECURE DETECTION
// ============================================
if (!defined('BASE_URL')) {
    if (defined('CONFIGURED_BASE_URL')) {
        define('BASE_URL', CONFIGURED_BASE_URL);
    } else {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $allowed_hosts = [
            'localhost',
            '127.0.0.1',
            'cyberplaza.tech',
            'www.cyberplaza.tech',
        ];

        $host_no_port = explode(':', $host)[0];

        if (!in_array($host_no_port, $allowed_hosts, true) && $is_production) {
            die('Invalid host header');
        }

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
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Native prepares are fine for DML; admin statements
                    // (CREATE DATABASE, SHOW DATABASES, etc.) are executed
                    // via ->exec()/->query() with whitelisted identifiers.
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            error_log("Master DB connection failed: " . $e->getMessage());
            $is_production = (CYBER_ENV === 'production');
            die($is_production
                ? "Database connection failed. Please contact support."
                : "Master Database Connection Failed: " . $e->getMessage());
        }
    }

    return $master_conn;
}

$master_conn = get_master_connection();

// ============================================
// SCHEMA FILE PARSER
// ============================================
/**
 * Split a raw SQL string into individual statements.
 *
 * This is a small, dependency-free parser that:
 *   - ignores line comments (-- ...)
 *   - ignores block comments (# ... and // ...)
 *   - respects single-quoted strings ('...')
 *   - respects double-quoted strings ("...")
 *   - respects backtick identifiers (`...`)
 *   - respects escaped quotes (\' and \")
 *
 * It does NOT handle:
 *   - DELIMITER blocks
 *   - stored procedures / functions with embedded semicolons
 *   - MySQL-specific /*! ... *\/ conditional comments
 *
 * Keep schema.sql simple to stay within these limits.
 *
 * @param string $sql
 * @return string[]  Array of non-empty statements (without trailing ';')
 */
function split_sql_statements(string $sql): array {
    $statements  = [];
    $current     = '';
    $len         = strlen($sql);
    $in_single   = false;
    $in_double   = false;
    $in_backtick = false;
    $in_line_cmt = false;
    $in_blk_cmt  = false;

    for ($i = 0; $i < $len; $i++) {
        $ch   = $sql[$i];
        $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

        // ---- Comment handling ----
        if ($in_line_cmt) {
            if ($ch === "\n") {
                $in_line_cmt = false;
                $current .= "\n";
            }
            continue;
        }
        if ($in_blk_cmt) {
            if ($ch === '*' && $next === '/') {
                $in_blk_cmt = false;
                $i++;
            }
            continue;
        }

        if (!$in_single && !$in_double && !$in_backtick) {
            // Start of line comment: -- (must be followed by space or newline per SQL spec)
            if ($ch === '-' && $next === '-' && ($i + 2 >= $len || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t" || $sql[$i + 2] === "\n" || $sql[$i + 2] === "\r")) {
                $in_line_cmt = true;
                $i++;
                continue;
            }
            // # line comment
            if ($ch === '#') {
                $in_line_cmt = true;
                continue;
            }
            // /* block comment
            if ($ch === '/' && $next === '*') {
                $in_blk_cmt = true;
                $i++;
                continue;
            }
        }

        // ---- String toggling ----
        if ($ch === "'" && !$in_double && !$in_backtick) {
            // Handle escaped quote \'
            if ($i > 0 && $sql[$i - 1] === '\\') {
                $current .= $ch;
                continue;
            }
            $in_single = !$in_single;
            $current .= $ch;
            continue;
        }
        if ($ch === '"' && !$in_single && !$in_backtick) {
            if ($i > 0 && $sql[$i - 1] === '\\') {
                $current .= $ch;
                continue;
            }
            $in_double = !$in_double;
            $current .= $ch;
            continue;
        }
        if ($ch === '`' && !$in_single && !$in_double) {
            $in_backtick = !$in_backtick;
            $current .= $ch;
            continue;
        }

        // ---- Statement terminator ----
        if ($ch === ';' && !$in_single && !$in_double && !$in_backtick) {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    // Trailing statement without a semicolon
    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

// ============================================
// ORGANIZATION DATABASE CREATION
// ============================================
/**
 * Create a new organization database by executing schema.sql.
 *
 * Flow:
 *   1. Validate org_code → DB name.
 *   2. Create the org database.
 *   3. Switch to it and execute every statement in schema.sql.
 *   4. Seed the admin user (not in schema.sql — it's per-org).
 *   5. Link admin to the MAIN store (created by schema.sql).
 *   6. Override company_name / company_email with real values.
 *   7. Log the creation in activity_logs.
 *   8. Return to the master DB.
 *
 * On any failure, the partially-created DB is dropped and the
 * exception is rethrown.
 *
 * @param string $org_code       Unique org code (used in DB name)
 * @param string $admin_username Admin username
 * @param string $admin_password Hashed admin password
 * @param string $admin_email    Admin email (optional)
 * @param string $org_name       Organization name (for settings)
 * @return string                The database name that was created
 * @throws Exception
 */
function create_organization_database($org_code, $admin_username, $admin_password, $admin_email = '', $org_name = '') {
    $master_conn = get_master_connection();

    // ---- 1. Sanitize DB name ----
    $db_name = 'pos_' . strtolower(preg_replace('/[^a-z0-9_]/i', '', $org_code));
    if (!preg_match('/^pos_[a-z0-9_]+$/', $db_name)) {
        throw new Exception("Invalid database name generated from org code.");
    }

    // ---- 2. Validate schema file ----
    if (!defined('ORG_SCHEMA_FILE')) {
        throw new Exception("ORG_SCHEMA_FILE is not defined in configuration.");
    }
    if (!is_file(ORG_SCHEMA_FILE) || !is_readable(ORG_SCHEMA_FILE)) {
        throw new Exception("Schema file not found or not readable: " . ORG_SCHEMA_FILE);
    }

    $schema_sql = file_get_contents(ORG_SCHEMA_FILE);
    if ($schema_sql === false || trim($schema_sql) === '') {
        throw new Exception("Schema file is empty: " . ORG_SCHEMA_FILE);
    }

    // ---- 3. Check for existing DB ----
    $check = $master_conn->query("SHOW DATABASES LIKE " . $master_conn->quote($db_name));
    if ($check->fetch()) {
        throw new Exception("Database '$db_name' already exists. Please try a different org code.");
    }

    try {
        // ---- 4. Create the new database ----
        $master_conn->exec("CREATE DATABASE `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Switch to it
        $master_conn->exec("USE `$db_name`");
        $master_conn->exec("SET FOREIGN_KEY_CHECKS = 0");

        // ---- 5. Execute schema.sql ----
        $statements = split_sql_statements($schema_sql);
        if (empty($statements)) {
            throw new Exception("Schema file contained no executable statements.");
        }

        $executed = 0;
        $errors   = [];

        foreach ($statements as $idx => $sql) {
            // Skip leftover comment-only chunks
            $stripped = trim(preg_replace('/\s+/', ' ', $sql));
            if ($stripped === '' || $stripped === ';') {
                continue;
            }
            try {
                $master_conn->exec($sql);
                $executed++;
            } catch (PDOException $e) {
                $errors[] = "Statement #" . ($idx + 1) . " [" . substr($stripped, 0, 100) . "] => " . $e->getMessage();
            }
        }

        if ($executed === 0) {
            throw new Exception(
                "No SQL statements were executed from schema.sql. First errors: "
                . implode(' | ', array_slice($errors, 0, 3))
            );
        }

        // If some statements failed but others succeeded, log them but
        // continue — many errors are "table already exists" which are
        // benign when re-running a schema with IF NOT EXISTS.
        if (!empty($errors)) {
            error_log("Schema execution warnings for $db_name: " . implode(' | ', $errors));
        }

        // ---- 6. Seed the admin user (per-org) ----
        try {
            $stmt = $master_conn->prepare("
                INSERT INTO users (username, password, full_name, email, role, status)
                VALUES (?, ?, 'Administrator', ?, 'admin', 'active')
            ");
            $stmt->execute([
                $admin_username,
                $admin_password,
                $admin_email ?: ($admin_username . '@' . strtolower(preg_replace('/[^a-z0-9]/i', '', $org_code)) . '.local')
            ]);
            $admin_id = (int) $master_conn->lastInsertId();
        } catch (PDOException $e) {
            throw new Exception("Failed to create admin user: " . $e->getMessage());
        }

        // ---- 7. Link admin to the MAIN store ----
        try {
            $store_id = $master_conn->query("SELECT store_id FROM stores WHERE store_code = 'MAIN' LIMIT 1")
                                     ->fetchColumn();
            if ($store_id) {
                $stmt = $master_conn->prepare("
                    INSERT IGNORE INTO user_stores (user_id, store_id)
                    VALUES (?, ?)
                ");
                $stmt->execute([$admin_id, $store_id]);

                $stmt = $master_conn->prepare("
                    UPDATE users SET default_store_id = ? WHERE user_id = ?
                ");
                $stmt->execute([$store_id, $admin_id]);
            }
        } catch (PDOException $e) {
            // Non-fatal — store or user_stores might not exist
            error_log("Failed to link admin to MAIN store: " . $e->getMessage());
        }

        // ---- 8. Override company_name / company_email ----
        try {
            $stmt = $master_conn->prepare("
                UPDATE system_settings SET setting_value = ?
                WHERE setting_key = 'company_name'
            ");
            $stmt->execute([$org_name ?: 'My Business']);

            $stmt = $master_conn->prepare("
                UPDATE system_settings SET setting_value = ?
                WHERE setting_key = 'company_email'
            ");
            $stmt->execute([$admin_email]);
        } catch (PDOException $e) {
            // Non-fatal — system_settings might have a different structure
            error_log("Failed to update company settings: " . $e->getMessage());
        }

        // ---- 9. Log the creation ----
        try {
            $stmt = $master_conn->prepare("
                INSERT INTO activity_logs (user_id, activity_type, activity_description)
                VALUES (?, 'ORG_CREATED', ?)
            ");
            $stmt->execute([$admin_id, "Organization '$org_name' created"]);
        } catch (PDOException $e) {
            // Non-fatal
            error_log("Failed to log org creation: " . $e->getMessage());
        }

        // ---- 10. Restore master DB ----
        $master_conn->exec("SET FOREIGN_KEY_CHECKS = 1");
        $master_conn->exec("USE `" . MASTER_DB_NAME . "`");

        return $db_name;

    } catch (Exception $e) {
        // Rollback: drop the partially-created DB and return to master
        try {
            $master_conn->exec("SET FOREIGN_KEY_CHECKS = 1");
            $master_conn->exec("USE `" . MASTER_DB_NAME . "`");
            $master_conn->exec("DROP DATABASE IF EXISTS `$db_name`");
        } catch (Exception $cleanup_error) {
            error_log("Cleanup failed: " . $cleanup_error->getMessage());
        }

        error_log("Org DB creation failed for $db_name: " . $e->getMessage());
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

        if ($org['status'] === 'suspended') {
            return [
                'valid'   => false,
                'message' => 'Your account has been suspended. Please contact support at: '
                             . SUPPORT_EMAIL . ' or call: ' . SUPPORT_PHONE,
                'org'     => $org,
            ];
        }

        $today    = new DateTime(date('Y-m-d'));
        $end_date = new DateTime($org['subscription_end']);
        $diff     = $today->diff($end_date);
        $days_remaining = $end_date >= $today ? $diff->days : -$diff->days;

        if ($days_remaining < 0) {
            $stmt = $master_conn->prepare("UPDATE organizations SET status = 'expired' WHERE org_id = ?");
            $stmt->execute([$org_id]);

            return [
                'valid'   => false,
                'message' => 'Your subscription expired on '
                             . date('F d, Y', strtotime($org['subscription_end']))
                             . '. Please renew your subscription.<br><br>Email: '
                             . SUPPORT_EMAIL . '<br>Phone: ' . SUPPORT_PHONE,
                'org'     => $org,
            ];
        }

        return [
            'valid'          => true,
            'days_remaining' => $days_remaining,
            'show_warning'   => ($days_remaining <= WARNING_DAYS),
            'org'            => $org,
        ];

    } catch (PDOException $e) {
        error_log("Status check failed: " . $e->getMessage());
        return [
            'valid'   => false,
            'message' => 'Error checking subscription status.',
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
    if (!preg_match('/^pos_[a-z0-9_]+$/', $database_name)) {
        throw new Exception("Invalid database name");
    }

    try {
        return new PDO(
            "mysql:host=" . MASTER_DB_HOST . ";dbname=" . $database_name . ";charset=utf8mb4",
            MASTER_DB_USER,
            MASTER_DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        error_log("Org DB connection failed: " . $e->getMessage());
        throw new Exception("Could not connect to organization database");
    }
}