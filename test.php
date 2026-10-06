<?php
session_start();
require_once '../config/config.php';
require_login();

echo "<style>
body { font-family: Arial; padding: 20px; background: #f5f5f5; }
.test-box { background: white; padding: 20px; margin: 10px 0; border-radius: 8px; border-left: 4px solid #6B46C1; }
.success { border-left-color: #48BB78; }
.error { border-left-color: #F56565; }
.warning { border-left-color: #ED8936; }
h1 { color: #6B46C1; }
h3 { margin-top: 0; color: #2D3748; }
pre { background: #f7fafc; padding: 10px; border-radius: 4px; overflow-x: auto; }
.label { font-weight: bold; color: #4A5568; }
.value { color: #2D3748; font-family: monospace; }
</style>";

echo "<h1>🔍 Subscription Alert Diagnostic Tool</h1>";
echo "<p>This will help identify why the alert isn't showing...</p>";

// TEST 1: Check if user is admin
echo "<div class='test-box " . (is_admin() ? "success" : "error") . "'>";
echo "<h3>Test 1: Admin Check</h3>";
echo "<p><span class='label'>Current User:</span> <span class='value'>" . htmlspecialchars($_SESSION['full_name'] ?? 'Unknown') . "</span></p>";
echo "<p><span class='label'>User ID:</span> <span class='value'>" . ($_SESSION['user_id'] ?? 'Not set') . "</span></p>";
echo "<p><span class='label'>User Role:</span> <span class='value'>" . ($_SESSION['role'] ?? 'Not set') . "</span></p>";

// Try different admin check methods
$method1 = isset($_SESSION['role']) && $_SESSION['role'] == 'admin';
$method2 = function_exists('is_admin') && is_admin();

echo "<p><span class='label'>is_admin() function exists:</span> <span class='value'>" . (function_exists('is_admin') ? 'YES ✓' : 'NO ✗') . "</span></p>";
echo "<p><span class='label'>is_admin() returns:</span> <span class='value'>" . (function_exists('is_admin') && is_admin() ? 'TRUE ✓' : 'FALSE ✗') . "</span></p>";
echo "<p><span class='label'>Session role == 'admin':</span> <span class='value'>" . ($method1 ? 'TRUE ✓' : 'FALSE ✗') . "</span></p>";

if (!$method2 && !$method1) {
    echo "<p style='color: red;'><strong>❌ PROBLEM FOUND:</strong> You are not recognized as an admin!</p>";
    echo "<p>The alert only shows for admins. Make sure your account has role='admin' in the users table.</p>";
} else {
    echo "<p style='color: green;'><strong>✓ Admin check passed</strong></p>";
}
echo "</div>";

// TEST 2: Check if system_settings table exists
echo "<div class='test-box'>";
echo "<h3>Test 2: Database Table Check</h3>";
try {
    $stmt = $conn->query("SHOW TABLES LIKE 'system_settings'");
    $table_exists = $stmt->rowCount() > 0;
    
    if ($table_exists) {
        echo "<p style='color: green;'><strong>✓ system_settings table exists</strong></p>";
    } else {
        echo "<p style='color: red;'><strong>❌ PROBLEM FOUND:</strong> system_settings table does NOT exist!</p>";
        echo "<p>Run this SQL to create it:</p>";
        echo "<pre>CREATE TABLE IF NOT EXISTS system_settings (
  setting_id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(100) UNIQUE NOT NULL,
  setting_value TEXT,
  setting_type VARCHAR(50) DEFAULT 'text',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);</pre>";
    }
} catch(Exception $e) {
    echo "<p style='color: red;'><strong>❌ DATABASE ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// TEST 3: Check for subscription_expiry setting
echo "<div class='test-box'>";
echo "<h3>Test 3: Subscription Setting Check</h3>";
try {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'subscription_expiry' LIMIT 1");
    $stmt->execute();
    $expiry_date_str = $stmt->fetchColumn();
    
    if ($expiry_date_str) {
        echo "<p style='color: green;'><strong>✓ Subscription expiry setting found</strong></p>";
        echo "<p><span class='label'>Expiry Date:</span> <span class='value'>" . htmlspecialchars($expiry_date_str) . "</span></p>";
        
        // Calculate days remaining
        try {
            $expiry_date = new DateTime($expiry_date_str);
            $today = new DateTime();
            $interval = $today->diff($expiry_date);
            
            if ($expiry_date > $today) {
                $days_remaining = $interval->days;
                echo "<p><span class='label'>Days Remaining:</span> <span class='value'>" . $days_remaining . " days</span></p>";
                
                if ($days_remaining > 14) {
                    echo "<p style='color: orange;'><strong>⚠️ REASON FOUND:</strong> More than 14 days remaining!</p>";
                    echo "<p>Alert only shows when 14 days or less. Current threshold: " . $days_remaining . " days.</p>";
                    echo "<p>To test immediately, run this SQL:</p>";
                    echo "<pre>UPDATE system_settings 
SET setting_value = DATE_ADD(CURDATE(), INTERVAL 10 DAY)
WHERE setting_key = 'subscription_expiry';</pre>";
                } else if ($days_remaining <= 7) {
                    echo "<p style='color: red;'><strong>Alert should show as RED (DANGER)</strong></p>";
                } else {
                    echo "<p style='color: orange;'><strong>Alert should show as ORANGE (WARNING)</strong></p>";
                }
            } else {
                $days_expired = $interval->days;
                echo "<p><span class='label'>Status:</span> <span class='value' style='color: red;'>EXPIRED " . $days_expired . " days ago</span></p>";
                echo "<p style='color: red;'><strong>Alert should show as RED (EXPIRED)</strong></p>";
            }
            
        } catch(Exception $e) {
            echo "<p style='color: red;'><strong>❌ DATE ERROR:</strong> Invalid date format</p>";
            echo "<p>Date must be in format: YYYY-MM-DD (e.g., 2026-02-15)</p>";
        }
        
    } else {
        echo "<p style='color: red;'><strong>❌ PROBLEM FOUND:</strong> subscription_expiry setting NOT found in database!</p>";
        echo "<p>Run this SQL to add it:</p>";
        echo "<pre>INSERT INTO system_settings (setting_key, setting_value, setting_type) 
VALUES ('subscription_expiry', DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'text');</pre>";
    }
} catch(Exception $e) {
    echo "<p style='color: red;'><strong>❌ QUERY ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// TEST 4: Show all settings in system_settings
echo "<div class='test-box'>";
echo "<h3>Test 4: All System Settings</h3>";
try {
    $stmt = $conn->query("SELECT * FROM system_settings ORDER BY setting_id");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($settings) > 0) {
        echo "<p>Found " . count($settings) . " setting(s) in database:</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th style='padding:8px; background:#6B46C1; color:white;'>Key</th><th style='padding:8px; background:#6B46C1; color:white;'>Value</th></tr>";
        foreach ($settings as $setting) {
            echo "<tr>";
            echo "<td style='padding:8px;'>" . htmlspecialchars($setting['setting_key']) . "</td>";
            echo "<td style='padding:8px;'>" . htmlspecialchars($setting['setting_value']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'><strong>⚠️ Table is empty!</strong> No settings found.</p>";
    }
} catch(Exception $e) {
    echo "<p style='color: red;'><strong>❌ ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// TEST 5: Simulate the alert logic
echo "<div class='test-box'>";
echo "<h3>Test 5: Alert Logic Simulation</h3>";

$show_alert = false;
$alert_message = "Alert should NOT show (no conditions met)";
$alert_color = "gray";

// Check admin
if (function_exists('is_admin') && is_admin()) {
    echo "<p>✓ Step 1: User is admin</p>";
    
    // Try to get expiry
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'subscription_expiry' LIMIT 1");
        $stmt->execute();
        $expiry_str = $stmt->fetchColumn();
        
        if ($expiry_str) {
            echo "<p>✓ Step 2: Expiry date found: " . htmlspecialchars($expiry_str) . "</p>";
            
            $expiry = new DateTime($expiry_str);
            $now = new DateTime();
            $diff = $now->diff($expiry);
            
            if ($expiry > $now) {
                $days = $diff->days;
                echo "<p>✓ Step 3: Not expired, " . $days . " days remaining</p>";
                
                if ($days <= 14) {
                    $show_alert = true;
                    if ($days <= 7) {
                        $alert_color = "RED";
                        $alert_message = "Alert SHOULD show (RED - DANGER)";
                    } else {
                        $alert_color = "ORANGE";
                        $alert_message = "Alert SHOULD show (ORANGE - WARNING)";
                    }
                    echo "<p>✓ Step 4: Within 14 day threshold</p>";
                } else {
                    $alert_message = "Alert will NOT show (more than 14 days remaining)";
                    echo "<p>✗ Step 4: Outside 14 day threshold (" . $days . " days)</p>";
                }
            } else {
                $show_alert = true;
                $alert_color = "RED";
                $alert_message = "Alert SHOULD show (EXPIRED)";
                echo "<p>✓ Step 3: Subscription EXPIRED</p>";
            }
        } else {
            $alert_message = "Alert will NOT show (no expiry date in database)";
            echo "<p>✗ Step 2: No expiry date found</p>";
        }
    } catch(Exception $e) {
        $alert_message = "Alert will NOT show (database error)";
        echo "<p>✗ Step 2: Database error - " . htmlspecialchars($e->getMessage()) . "</p>";
    }
} else {
    $alert_message = "Alert will NOT show (user is not admin)";
    echo "<p>✗ Step 1: User is NOT admin</p>";
}

echo "<hr>";
echo "<p style='font-size: 18px; font-weight: bold; color: " . ($show_alert ? "green" : "red") . ";'>";
echo $alert_message;
echo "</p>";

if ($show_alert) {
    echo "<p style='color: " . ($alert_color == 'RED' ? 'red' : 'orange') . "; font-weight: bold;'>";
    echo "Expected Alert Color: " . $alert_color;
    echo "</p>";
}
echo "</div>";

// TEST 6: Check PHP errors
echo "<div class='test-box'>";
echo "<h3>Test 6: PHP Error Reporting</h3>";
echo "<p><span class='label'>Error Reporting:</span> <span class='value'>" . ini_get('display_errors') . "</span></p>";
echo "<p><span class='label'>Error Level:</span> <span class='value'>" . error_reporting() . "</span></p>";
echo "<p>If errors are hidden, enable them temporarily to see what's wrong:</p>";
echo "<pre>error_reporting(E_ALL);
ini_set('display_errors', 1);</pre>";
echo "</div>";

// SUMMARY
echo "<div class='test-box " . ($show_alert ? "success" : "warning") . "'>";
echo "<h3>📋 Summary & Recommendations</h3>";

if (!function_exists('is_admin') || !is_admin()) {
    echo "<p>⚠️ <strong>Main Issue:</strong> You are not logged in as an admin or is_admin() function doesn't work</p>";
    echo "<p><strong>Solution:</strong> Make sure your users table has role='admin' for your account</p>";
    echo "<pre>UPDATE users SET role = 'admin' WHERE user_id = " . ($_SESSION['user_id'] ?? 0) . ";</pre>";
}

try {
    $stmt = $conn->query("SHOW TABLES LIKE 'system_settings'");
    if ($stmt->rowCount() == 0) {
        echo "<p>⚠️ <strong>Issue:</strong> system_settings table doesn't exist</p>";
        echo "<p><strong>Solution:</strong> Create the table (see Test 2 above)</p>";
    }
} catch(Exception $e) {}

try {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'subscription_expiry'");
    $stmt->execute();
    if (!$stmt->fetchColumn()) {
        echo "<p>⚠️ <strong>Issue:</strong> No subscription_expiry setting in database</p>";
        echo "<p><strong>Solution:</strong> Insert the setting (see Test 3 above)</p>";
    }
} catch(Exception $e) {}

if ($show_alert) {
    echo "<p style='color: green; font-weight: bold;'>✅ All conditions met! Alert SHOULD be showing on your pages.</p>";
    echo "<p><strong>If you still don't see it:</strong></p>";
    echo "<ol>";
    echo "<li>Make sure you uploaded the new header file</li>";
    echo "<li>Clear your browser cache (Ctrl+F5 or Cmd+Shift+R)</li>";
    echo "<li>Check that the header file is being included on your pages</li>";
    echo "<li>Look at the page source code for '&lt;div class=\"subscription-alert\"'</li>";
    echo "</ol>";
}

echo "</div>";

echo "<p style='text-align: center; margin-top: 30px;'>";
echo "<a href='dashboard.php' style='background: #6B46C1; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px;'>← Back to Dashboard</a>";
echo "</p>";
?>