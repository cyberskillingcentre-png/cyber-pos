<?php
require_once '../config/config.php';
require_once 'config/client_auth.php';
require_client();

$page_title = "My Invoices";

// Get all confirmed/delivered orders (invoices)
try {
    $stmt = $conn->prepare("
        SELECT co.*, s.store_name,
               COUNT(coi.order_item_id) as item_count
        FROM client_orders co
        LEFT JOIN stores s ON co.store_id = s.store_id
        LEFT JOIN client_order_items coi ON co.order_id = coi.order_id
        WHERE co.client_id = ? AND co.order_status IN ('confirmed', 'processing', 'ready', 'shipped', 'delivered')
        GROUP BY co.order_id
        ORDER BY co.order_date DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $invoices = [];
}

$alert = get_alert();
?>

<?php include '../includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <!-- MOBILE SIDEBAR TOGGLE -->
        <button class="btn btn-link d-md-none sidebar-toggle me-2">
            <i class="fas fa-bars"></i>
        </button>
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-file-invoice me-2"></i>My Invoices</h5>
            <span class="badge bg-primary"><?php echo date('l, F j, Y'); ?></span>
        </div>
    </div>
    
    <div class="content-area">
        <?php if ($alert): ?>
        <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
            <?php echo $alert['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list me-2"></i>Invoice History (<?php echo count($invoices); ?> invoices)
            </div>
            <div class="card-body">
                <?php if (empty($invoices)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-file-invoice fa-3x text-muted mb-3"></i>
                        <h5>No Invoices Yet</h5>
                        <p class="text-muted">Your invoices will appear here once orders are confirmed</p>
                        <a href="Products" class="btn btn-primary">
                            <i class="fas fa-shopping-bag me-2"></i>Browse Products
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Date</th>
                                    <th>Store</th>
                                    <th>Items</th>
                                    <th>Amount</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Payment Status</th>
                                    <th>Order Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($invoice['order_number']); ?></strong></td>
                                    <td><?php echo date('M d, Y', strtotime($invoice['order_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($invoice['store_name'] ?? 'Multiple Stores'); ?></td>
                                    <td><span class="badge bg-info"><?php echo $invoice['item_count']; ?> item(s)</span></td>
                                    <td><strong class="text-success">UGX <?php echo format_currency($invoice['grand_total']); ?></strong></td>
                                    <td>UGX <?php echo format_currency($invoice['amount_paid']); ?></td>
                                    <td>
                                        <?php 
                                        $balance = $invoice['grand_total'] - $invoice['amount_paid'];
                                        if ($balance > 0): ?>
                                            <span class="text-danger"><strong>UGX <?php echo format_currency($balance); ?></strong></span>
                                        <?php else: ?>
                                            <span class="text-success"><strong>UGX 0</strong></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $p_class = match($invoice['payment_status']) {
                                            'paid' => 'bg-success',
                                            'partial' => 'bg-warning',
                                            default => 'bg-danger'
                                        };
                                        ?>
                                        <span class="badge <?php echo $p_class; ?>">
                                            <?php echo ucfirst($invoice['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $o_class = match($invoice['order_status']) {
                                            'delivered' => 'bg-success',
                                            'shipped' => 'bg-info',
                                            'ready' => 'bg-primary',
                                            'processing' => 'bg-warning',
                                            default => 'bg-secondary'
                                        };
                                        ?>
                                        <span class="badge <?php echo $o_class; ?>">
                                            <?php echo ucfirst($invoice['order_status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-nowrap">
                                        <a href="My_Order_Details?id=<?php echo $invoice['order_id']; ?>" class="btn btn-sm btn-primary" title="View Invoice">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="Print_Invoice?id=<?php echo $invoice['order_id']; ?>" target="_blank" class="btn btn-sm btn-info" title="Print Invoice">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<style>
/* Sidebar styles */
.sidebar {
    width: 250px;
    transition: all 0.3s ease;
    position: fixed;
    top: 0;
    left: 0;
    height: 100vh;
    z-index: 1000;
    overflow-y: auto;
}

.main-content {
    margin-left: 250px;
    transition: all 0.3s ease;
    min-height: 100vh;
    background-color: #f5f5f5;
}

/* When sidebar is collapsed */
.sidebar.collapsed {
    margin-left: -250px;
}

.main-content.expanded {
    margin-left: 0;
}

/* Mobile styles */
@media (max-width: 768px) {
    .sidebar {
        margin-left: 0;
        z-index: 1040;
    }
    
    .sidebar.collapsed {
        margin-left: -100%;
    }
    
    .main-content {
        margin-left: 0 !important;
    }
}

/* Mobile overlay for sidebar */
.sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1039;
}

@media (max-width: 768px) {
    .sidebar:not(.collapsed) ~ .sidebar-overlay {
        display: block;
    }
}

/* Top Navbar */
.top-navbar {
    background: #fff;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #eee;
    display: flex;
    align-items: center;
}

.top-navbar h5 {
    font-weight: 600;
    margin: 0;
}

/* Card styles */
.card {
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border-radius: 10px;
}

.card-header {
    background: #fff;
    border-bottom: 1px solid #eee;
    padding: 1rem 1.5rem;
    font-weight: 600;
    display: flex;
    align-items: center;
}

/* Table styles */
.table th {
    font-weight: 600;
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.table td {
    vertical-align: middle;
}

/* Badge styles */
.badge {
    padding: 0.35em 0.65em;
    font-weight: 500;
}
</style>

<script>
// Sidebar toggle functionality
document.addEventListener('DOMContentLoaded', function() {
    // Add mobile overlay after sidebar if it doesn't exist
    if (!document.querySelector('.sidebar-overlay')) {
        const overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.querySelector('.sidebar').insertAdjacentElement('afterend', overlay);
    }
    
    // Toggle button click handler
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            toggleSidebar();
        });
    }
    
    // Close sidebar when clicking on overlay (mobile only)
    const overlay = document.querySelector('.sidebar-overlay');
    if (overlay) {
        overlay.addEventListener('click', function() {
            toggleSidebar(true); // Force close
        });
    }
    
    // Function to toggle sidebar
    function toggleSidebar(forceClose = false) {
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        if (sidebar) {
            if (forceClose) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            } else {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
            }
        }
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 768) {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const toggleBtn = document.querySelector('.sidebar-toggle');
            
            // If sidebar is open and click is outside sidebar and not on toggle button
            if (sidebar && !sidebar.classList.contains('collapsed') && 
                !sidebar.contains(e.target) && 
                (!toggleBtn || !toggleBtn.contains(e.target))) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            }
        }
    });
    
    // Handle window resize
    window.addEventListener('resize', function() {
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        
        // On larger screens, ensure sidebar is visible
        if (window.innerWidth > 768) {
            sidebar.classList.remove('collapsed');
            mainContent.classList.remove('expanded');
        }
    });
    
    // Fix for Bootstrap 5 badge classes (changed from Bootstrap 4)
    document.querySelectorAll('.badge').forEach(function(badge) {
        const oldClass = badge.className.match(/badge-(success|danger|warning|info|primary|secondary)/);
        if (oldClass) {
            badge.className = badge.className.replace(`badge-${oldClass[1]}`, `bg-${oldClass[1]}`);
        }
    });
});
</script>