<?php
require_once '../config/config.php';
require_once 'config/client_auth.php';
require_client();
$page_title = "My Favorites";

try {
    $stmt = $conn->prepare("
        SELECT p.*, c.category_name
        FROM client_favorites cf
        JOIN products p ON cf.product_id = p.product_id
        LEFT JOIN categories c ON p.category_id = c.category_id
        WHERE cf.client_id = ? AND p.status = 'active'
        ORDER BY cf.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $favorites = $stmt->fetchAll();
} catch(PDOException $e) {
    $favorites = [];
}
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
            <h5 class="mb-0">My Favorites</h5>
            <a href="Products" class="btn btn-sm btn-primary">
                <i class="fas fa-shopping-bag me-1"></i>Browse Products
            </a>
        </div>
    </div>
    <div class="content-area">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-heart me-2"></i>Saved Products (<?php echo count($favorites); ?>)
            </div>
            <div class="card-body">
                <?php if (empty($favorites)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-heart fa-3x text-muted mb-3"></i>
                        <h5>No Favorites Yet</h5>
                        <p class="text-muted">Save your favorite products for quick access!</p>
                        <a href="Products" class="btn btn-primary">
                            <i class="fas fa-shopping-bag me-2"></i>Browse Products
                        </a>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($favorites as $product): ?>
                        <div class="col-md-3 mb-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="mb-0"><?php echo htmlspecialchars($product['product_name']); ?></h6>
                                        <a href="api/remove-favorite.php?product=<?php echo $product['product_id']; ?>" 
                                           class="text-danger" 
                                           title="Remove from favorites"
                                           onclick="return confirm('Remove this product from favorites?');">
                                            <i class="fas fa-heart fa-lg"></i>
                                        </a>
                                    </div>
                                    <p class="text-muted small mb-2"><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></p>
                                    <p class="text-muted small mb-3">Code: <?php echo htmlspecialchars($product['product_code']); ?></p>
                                    <h5 class="text-success mb-3">UGX <?php echo format_currency($product['selling_price']); ?></h5>
                                    <?php if (($product['total_stock'] ?? $product['stock_quantity'] ?? 0) > 0): ?>                           
                                            <a href="New_Order?product=<?php echo $product['product_id']; ?>" class="btn btn-primary btn-sm w-100">
                                            <i class="fas fa-cart-plus me-1"></i>Order Now
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm w-100" disabled>
                                            <i class="fas fa-times-circle me-1"></i>Out of Stock
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
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
}

/* Product cards */
.product-card {
    transition: transform 0.2s;
}

.product-card:hover {
    transform: translateY(-5px);
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
});
</script>