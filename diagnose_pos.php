<?php
/**
 * POS DIAGNOSTIC TOOL
 * Find out why product search isn't working
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>POS System Diagnostic</h2>";
echo "<hr>";

try {
    require_once 'config/config.php';
    
    // Test 1: Check Products
    echo "<h3>Test 1: Check Products Table</h3>";
    $stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE status = 'active'");
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        echo "✅ Products found: " . $result['count'] . " products<br>";
        
        // Show sample products
        $stmt = $conn->query("SELECT product_id, product_code, product_name, selling_price FROM products WHERE status = 'active' LIMIT 5");
        $products = $stmt->fetchAll();
        
        echo "<strong>Sample products:</strong><br>";
        echo "<ul>";
        foreach ($products as $p) {
            echo "<li>{$p['product_code']} - {$p['product_name']} (UGX {$p['selling_price']})</li>";
        }
        echo "</ul>";
    } else {
        echo "❌ <strong style='color: red;'>NO PRODUCTS FOUND!</strong><br>";
        echo "<strong>FIX:</strong> You need to add products first!<br>";
        echo "<p><a href='admin/products.php'>Click here to add products</a></p>";
    }
    
    // Test 2: Check Stores
    echo "<hr><h3>Test 2: Check Stores</h3>";
    $stmt = $conn->query("SELECT COUNT(*) as count FROM stores WHERE status = 'active'");
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        echo "✅ Stores found: " . $result['count'] . " stores<br>";
        
        $stmt = $conn->query("SELECT store_id, store_code, store_name FROM stores WHERE status = 'active'");
        $stores = $stmt->fetchAll();
        
        echo "<strong>Available stores:</strong><br>";
        echo "<ul>";
        foreach ($stores as $s) {
            echo "<li>{$s['store_code']} - {$s['store_name']}</li>";
        }
        echo "</ul>";
    } else {
        echo "❌ <strong style='color: red;'>NO STORES FOUND!</strong><br>";
        echo "<strong>FIX:</strong> You need to add a store first!<br>";
        echo "<p><a href='admin/stores.php'>Click here to add stores</a></p>";
    }
    
    // Test 3: Check Inventory
    echo "<hr><h3>Test 3: Check Store Inventory</h3>";
    $stmt = $conn->query("SELECT COUNT(*) as count FROM store_inventory");
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        echo "✅ Inventory records found: " . $result['count'] . " records<br>";
        
        // Show inventory summary
        $stmt = $conn->query("
            SELECT s.store_name, p.product_name, si.quantity 
            FROM store_inventory si
            JOIN stores s ON si.store_id = s.store_id
            JOIN products p ON si.product_id = p.product_id
            LIMIT 10
        ");
        $inventory = $stmt->fetchAll();
        
        echo "<strong>Sample inventory:</strong><br>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Store</th><th>Product</th><th>Quantity</th></tr>";
        foreach ($inventory as $inv) {
            echo "<tr><td>{$inv['store_name']}</td><td>{$inv['product_name']}</td><td>{$inv['quantity']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "⚠️ <strong style='color: orange;'>NO INVENTORY FOUND!</strong><br>";
        echo "<strong>FIX:</strong> You need to add stock to your stores!<br>";
        echo "<p><a href='admin/inventory.php'>Click here to add inventory</a></p>";
        echo "<p><em>Note: POS will still work but will show 0 stock for all products</em></p>";
    }
    
    // Test 4: Test AJAX File
    echo "<hr><h3>Test 4: Test Product Search AJAX</h3>";
    
    if (file_exists('includes/ajax_search_products.php')) {
        echo "✅ AJAX file exists: includes/ajax_search_products.php<br>";
    } else {
        echo "❌ <strong style='color: red;'>AJAX FILE MISSING!</strong><br>";
        echo "<strong>FIX:</strong> The file includes/ajax_search_products.php is missing!<br>";
    }
    
    if (file_exists('includes/ajax_customers.php')) {
        echo "✅ AJAX file exists: includes/ajax_customers.php<br>";
    } else {
        echo "❌ <strong style='color: red;'>AJAX FILE MISSING!</strong><br>";
    }
    
    // Test 5: Test Actual Search
    echo "<hr><h3>Test 5: Simulate Product Search</h3>";
    
    $test_store_id = 1;
    $test_query = '%';
    
    $stmt = $conn->prepare("
        SELECT 
            p.product_id,
            p.product_code,
            p.barcode,
            p.product_name,
            p.selling_price,
            p.purchase_price,
            p.tax_rate,
            COALESCE(si.quantity, 0) as quantity
        FROM products p
        LEFT JOIN store_inventory si ON p.product_id = si.product_id AND si.store_id = ?
        WHERE p.status = 'active' 
        AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ?)
        LIMIT 12
    ");
    
    $stmt->execute([$test_store_id, $test_query, $test_query, $test_query]);
    $search_results = $stmt->fetchAll();
    
    if (count($search_results) > 0) {
        echo "✅ Search query works! Found " . count($search_results) . " products<br>";
        echo "<strong>Sample results:</strong><br>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Code</th><th>Name</th><th>Price</th><th>Stock</th></tr>";
        foreach (array_slice($search_results, 0, 5) as $prod) {
            echo "<tr>";
            echo "<td>{$prod['product_code']}</td>";
            echo "<td>{$prod['product_name']}</td>";
            echo "<td>UGX {$prod['selling_price']}</td>";
            echo "<td>{$prod['quantity']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "❌ <strong style='color: red;'>SEARCH RETURNED NO RESULTS!</strong><br>";
    }
    
    // Summary
    echo "<hr><h3>📋 Summary & Next Steps</h3>";
    
    $issues = [];
    $fixes = [];
    
    // Check for issues
    $stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE status = 'active'");
    if ($stmt->fetch()['count'] == 0) {
        $issues[] = "No products in database";
        $fixes[] = "<a href='admin/products.php'><strong>Add Products</strong></a>";
    }
    
    $stmt = $conn->query("SELECT COUNT(*) as count FROM stores WHERE status = 'active'");
    if ($stmt->fetch()['count'] == 0) {
        $issues[] = "No stores in database";
        $fixes[] = "<a href='admin/stores.php'><strong>Add Stores</strong></a>";
    }
    
    $stmt = $conn->query("SELECT COUNT(*) as count FROM store_inventory");
    if ($stmt->fetch()['count'] == 0) {
        $issues[] = "No inventory in stores (optional but recommended)";
        $fixes[] = "<a href='admin/inventory.php'><strong>Add Stock to Stores</strong></a>";
    }
    
    if (count($issues) > 0) {
        echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107;'>";
        echo "<strong>⚠️ Issues Found:</strong><br>";
        echo "<ul>";
        foreach ($issues as $issue) {
            echo "<li>$issue</li>";
        }
        echo "</ul>";
        echo "<strong>🔧 Required Fixes:</strong><br>";
        echo "<ol>";
        foreach ($fixes as $fix) {
            echo "<li>$fix</li>";
        }
        echo "</ol>";
        echo "</div>";
    } else {
        echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; border-left: 4px solid #28a745;'>";
        echo "<strong>✅ Everything looks good!</strong><br>";
        echo "<p>All database tables have data. POS should be working.</p>";
        echo "<p>If search still doesn't work, check browser console for JavaScript errors.</p>";
        echo "</div>";
    }
    
    echo "<hr>";
    echo "<h3>🧪 Test POS System</h3>";
    echo "<p><a href='admin/pos.php'><strong>Open POS System (Admin)</strong></a></p>";
    echo "<p><a href='employee/pos.php'><strong>Open POS System (Employee)</strong></a></p>";
    
} catch(Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; border-left: 4px solid #dc3545;'>";
    echo "❌ <strong>Error:</strong> " . $e->getMessage();
    echo "</div>";
}

?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
h2 { color: #6B46C1; }
h3 { color: #333; margin-top: 20px; background: #f8f9fa; padding: 10px; border-radius: 5px; }
hr { border: none; border-top: 2px solid #ddd; margin: 20px 0; }
table { background: white; margin: 10px 0; width: 100%; }
th { background: #6B46C1; color: white; padding: 10px; }
td { padding: 8px; border: 1px solid #ddd; }
a { color: #6B46C1; text-decoration: none; font-weight: bold; }
a:hover { text-decoration: underline; }
ul, ol { line-height: 1.8; }
</style>
