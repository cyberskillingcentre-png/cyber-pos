<?php
// TEMPORARY DEBUG - Remove after fixing
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
/**
 * Logout Script
 * 
 * Clears the session, logs the activity, and redirects to the login page.
 */

// ============================================
// LOAD CONFIG (for DB connection + logging)
// ============================================
require_once __DIR__ . '/config/config.php';

// ============================================
// LOG ACTIVITY (before destroying session)
// ============================================
if (isset($_SESSION['user_id']) && function_exists('log_activity')) {
    try {
        log_activity(
            $_SESSION['user_id'], 
            'LOGOUT', 
            'User logged out', 
            $conn
        );
    } catch(Exception $e) {
        // Don't block logout if logging fails
        error_log("Logout log error: " . $e->getMessage());
    }
}

// ============================================
// CLEAR SESSION DATA
// ============================================
$_SESSION = [];

// ============================================
// DELETE SESSION COOKIE
// ============================================
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

csrf_rotate();

// ============================================
// DESTROY SESSION
// ============================================
session_destroy();

// ============================================
// REDIRECT TO LOGIN
// ============================================
// ✅ FIXED: Use the actual login filename
// Change this based on your setup:
//   - 'index.php'      → if index.php is your login page
//   - 'org-login.php'  → if org-login.php is your login page
//   - 'login.php'      → if login.php is your login page
header('Location: index.php');
exit;