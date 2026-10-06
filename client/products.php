<?php
require_once '../config/config.php';
require_once 'config/client_auth.php';
require_client();

$page_title = "Browse Products";

// ============================================
// GET FILTERS
// ============================================
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$sort = $_GET['sort'] ?? 'name_asc';

// ============================================
// GET CATEGORIES
// ============================================
$categories = [];
try {
    $stmt = $conn->query("SELECT * FROM categories WHERE status = 'active' ORDER BY category_name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $categories = [];
}

// ============================================
// AUTO-CREATE client_favorites TABLE
// ============================================
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `client_favorites` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `client_id` INT NOT NULL,
          `product_id` INT NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `unique_favorite` (`client_id`, `product_id`),
          KEY `idx_client` (`client_id`),
          KEY `idx_product` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch(PDOException $e) {}

// ============================================
// GET CLIENT'S FAVORITES
// ============================================
$favorites = [];
try {
    $stmt = $conn->prepare("SELECT product_id FROM client_favorites WHERE client_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $favorites = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch(PDOException $e) {
    $favorites = [];
}

// ============================================
// BUILD QUERY (with stock from store_inventory)
// ============================================
$where = ["p.status = 'active'"];
$params = [];

if (!empty($search)) {
    $where[] = "(p.product_name LIKE ? OR p.product_code LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($category_filter)) {
    $where[] = "p.category_id = ?";
    $params[] = $category_filter;
}

$orderBy = match($sort) {
    'name_desc' => 'p.product_name DESC',
    'price_asc' => 'p.selling_price ASC',
    'price_desc' => 'p.selling_price DESC',
    default => 'p.product_name ASC'
};

$sql = "
    SELECT p.*, 
           c.category_name,
           COALESCE((
               SELECT SUM(quantity) 
               FROM store_inventory 
               WHERE product_id = p.product_id
           ), 0) as total_stock
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY $orderBy
";

$products = [];
try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $products = [];
}
?>

<?php include '../includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="main-content">
    <!-- Top Navbar -->
    <div class="top-navbar">
        <div class="d-flex justify-content-between align-items-center w-100">
            <div class="d-flex align-items-center">
                <button class="btn btn-link d-lg-none sidebar-toggle me-2">
                    <i class="fas fa-bars"></i>
                </button>
                
                <button type="button" class="btn btn-link sidebar-desktop-toggle me-2" 
                        id="sidebarDesktopToggle" title="Toggle Sidebar (Ctrl+B)">
                    <i class="fas fa-bars"></i>
                </button>
                
                <h5 class="mb-0">
                    <i class="fas fa-shopping-bag me-2" style="color: var(--cyber-orange);"></i>
                    Browse Products
                </h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="favorites.php" class="btn btn-sm btn-danger">
                    <i class="fas fa-heart me-1"></i>
                    My Favorites
                    <span class="badge bg-white text-danger ms-1 favorites-count"><?php echo count($favorites); ?></span>
                </a>
                <div class="user-badge d-none d-md-flex">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="content-area">
        <?php $alert = get_alert(); if ($alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
                <i class="fas fa-<?php echo $alert['type'] == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Filters -->
        <div class="card cyber-card mb-4">
            <div class="card-header">
                <i class="fas fa-filter me-2"></i>Filter Products
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label-cyber">Search</label>
                        <input type="text" name="search" class="form-control form-control-cyber" 
                               placeholder="Product name or code..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label-cyber">Category</label>
                        <select name="category" class="form-select form-select-cyber">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['category_id']; ?>" 
                                    <?php echo $category_filter == $cat['category_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label-cyber">Sort By</label>
                        <select name="sort" class="form-select form-select-cyber">
                            <option value="name_asc" <?php echo $sort == 'name_asc' ? 'selected' : ''; ?>>Name (A-Z)</option>
                            <option value="name_desc" <?php echo $sort == 'name_desc' ? 'selected' : ''; ?>>Name (Z-A)</option>
                            <option value="price_asc" <?php echo $sort == 'price_asc' ? 'selected' : ''; ?>>Price (Low-High)</option>
                            <option value="price_desc" <?php echo $sort == 'price_desc' ? 'selected' : ''; ?>>Price (High-Low)</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-1"></i>Search
                        </button>
                        <?php if ($search || $category_filter || $sort != 'name_asc'): ?>
                        <a href="products.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Results Count -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="results-count">
                <i class="fas fa-box"></i>
                <span><?php echo count($products); ?> product<?php echo count($products) != 1 ? 's' : ''; ?> found</span>
            </div>
        </div>
        
        <!-- Products Grid -->
        <?php if (empty($products)): ?>
            <div class="card cyber-card">
                <div class="card-body">
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <h5>No Products Found</h5>
                        <p>Try adjusting your filters or search term</p>
                        <a href="products.php" class="btn btn-primary mt-3">
                            <i class="fas fa-redo me-2"></i>Clear Filters
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($products as $product): 
                    $stock = intval($product['total_stock'] ?? 0);
                    $isFavorite = in_array($product['product_id'], $favorites);
                ?>
                <div class="col-xl-3 col-lg-4 col-md-6">
                    <div class="product-card">
                        <!-- Favorite Button (JavaScript toggled) -->
                        <div class="product-favorite">
                            <button type="button" 
                                    class="favorite-btn <?php echo $isFavorite ? 'active' : ''; ?>" 
                                    data-product-id="<?php echo $product['product_id']; ?>"
                                    data-is-favorite="<?php echo $isFavorite ? '1' : '0'; ?>"
                                    title="<?php echo $isFavorite ? 'Remove from favorites' : 'Add to favorites'; ?>">
                                <i class="<?php echo $isFavorite ? 'fas' : 'far'; ?> fa-heart"></i>
                            </button>
                        </div>
                        
                        <!-- Product Image -->
                        <div class="product-image">
                            <?php if (!empty($product['product_image']) && file_exists('../uploads/products/' . $product['product_image'])): ?>
                                <img src="../uploads/products/<?php echo htmlspecialchars($product['product_image']); ?>" 
                                     alt="<?php echo htmlspecialchars($product['product_name']); ?>">
                            <?php else: ?>
                                <div class="image-placeholder">
                                    <i class="fas fa-box"></i>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Stock Badge on Image -->
                            <?php if ($stock > 20): ?>
                                <span class="image-badge badge-in">
                                    <i class="fas fa-check-circle"></i> In Stock
                                </span>
                            <?php elseif ($stock > 0): ?>
                                <span class="image-badge badge-low">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo $stock; ?> Left
                                </span>
                            <?php else: ?>
                                <span class="image-badge badge-out">
                                    <i class="fas fa-times-circle"></i> Out of Stock
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Product Body -->
                        <div class="product-body">
                            <div class="product-category">
                                <?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?>
                            </div>
                            
                            <h5 class="product-title">
                                <?php echo htmlspecialchars($product['product_name']); ?>
                            </h5>
                            
                            <div class="product-code">
                                <i class="fas fa-barcode me-1"></i>
                                <?php echo htmlspecialchars($product['product_code']); ?>
                            </div>
                            
                            <div class="product-price">
                                UGX <?php echo format_currency($product['selling_price']); ?>
                            </div>
                            
                            <!-- Stock Info -->
                            <div class="product-stock">
                                <?php if ($stock > 20): ?>
                                    <span class="stock-label stock-in">
                                        <i class="fas fa-check-circle"></i> Available
                                    </span>
                                <?php elseif ($stock > 0): ?>
                                    <span class="stock-label stock-low">
                                        <i class="fas fa-exclamation-triangle"></i> Only <?php echo $stock; ?> left
                                    </span>
                                <?php else: ?>
                                    <span class="stock-label stock-out">
                                        <i class="fas fa-times-circle"></i> Out of Stock
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Action Button -->
                            <?php if ($stock > 0): ?>
                                <a href="new-order.php?product=<?php echo $product['product_id']; ?>" 
                                   class="btn btn-primary w-100 product-action">
                                    <i class="fas fa-cart-plus me-2"></i>Order Now
                                </a>
                            <?php else: ?>
                                <button class="btn btn-secondary w-100 product-action" disabled>
                                    <i class="fas fa-times-circle me-2"></i>Out of Stock
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

<!-- Toast Container -->
<div id="toastContainer" style="position: fixed; top: 80px; right: 20px; z-index: 9999;"></div>

<!-- Products Page Styles -->
<style>
/* Results count */
.results-count {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: #fff;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.875rem;
    color: #4A5568;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.results-count i { color: #6B46C1; }

/* Product Card */
.product-card {
    background: #fff;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
    border: 1px solid #E2E8F0;
    height: 100%;
    display: flex;
    flex-direction: column;
    position: relative;
}

.product-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 15px 35px rgba(107, 70, 193, 0.15);
    border-color: #6B46C1;
}

/* Favorite Button */
.product-favorite {
    position: absolute;
    top: 12px;
    right: 12px;
    z-index: 10;
}

.favorite-btn {
    width: 42px;
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.95);
    border: none;
    border-radius: 50%;
    color: #CBD5E0;
    font-size: 1.1rem;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    backdrop-filter: blur(8px);
    padding: 0;
}

.favorite-btn:hover {
    color: #E53E3E;
    transform: scale(1.15);
    box-shadow: 0 4px 15px rgba(229, 62, 62, 0.35);
}

.favorite-btn.active {
    color: #E53E3E;
    background: #fff;
    animation: heartBeat 0.4s ease;
}

.favorite-btn.loading {
    pointer-events: none;
    opacity: 0.6;
}

@keyframes heartBeat {
    0% { transform: scale(1); }
    25% { transform: scale(1.3); }
    50% { transform: scale(0.95); }
    75% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

/* Product Image */
.product-image {
    position: relative;
    width: 100%;
    height: 180px;
    background: linear-gradient(135deg, #F7FAFC 0%, #EDF2F7 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}

.product-card:hover .product-image img {
    transform: scale(1.08);
}

.image-placeholder {
    font-size: 3.5rem;
    color: #CBD5E0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #F7FAFC 0%, #E2E8F0 100%);
}

.image-placeholder i {
    opacity: 0.4;
    color: #6B46C1;
}

/* Stock Badge on Image */
.image-badge {
    position: absolute;
    top: 12px;
    left: 12px;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    backdrop-filter: blur(8px);
}

.image-badge.badge-in { background: rgba(72, 187, 120, 0.95); color: #fff; }
.image-badge.badge-low { background: rgba(237, 137, 54, 0.95); color: #fff; }
.image-badge.badge-out { background: rgba(229, 62, 62, 0.95); color: #fff; }

/* Product Body */
.product-body {
    padding: 16px;
    display: flex;
    flex-direction: column;
    flex: 1;
}

.product-category {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #6B46C1;
    margin-bottom: 6px;
}

.product-title {
    font-size: 1rem;
    font-weight: 700;
    color: #1A202C;
    margin-bottom: 6px;
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: 2.6em;
}

.product-code {
    font-size: 0.7rem;
    color: #A0AEC0;
    font-family: 'Courier New', monospace;
    margin-bottom: 12px;
}

.product-price {
    font-size: 1.15rem;
    font-weight: 800;
    color: #6B46C1;
    margin-bottom: 10px;
    font-family: 'Courier New', monospace;
}

/* Product Stock */
.product-stock {
    margin-bottom: 12px;
}

.stock-label {
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 12px;
}

.stock-in { background: rgba(72, 187, 120, 0.12); color: #2F855A; }
.stock-low { background: rgba(237, 137, 54, 0.12); color: #C05621; }
.stock-out { background: rgba(229, 62, 62, 0.12); color: #C53030; }

/* Product Action Button */
.product-action {
    margin-top: auto;
    padding: 10px 16px;
    font-size: 0.85rem;
    font-weight: 700;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.btn-primary.product-action {
    background: linear-gradient(135deg, #6B46C1, #553C9A);
    border: none;
    box-shadow: 0 4px 12px rgba(107, 70, 193, 0.3);
}

.btn-primary.product-action:hover {
    background: linear-gradient(135deg, #553C9A, #6B46C1);
    box-shadow: 0 6px 20px rgba(107, 70, 193, 0.4);
    transform: translateY(-2px);
}

.btn-secondary.product-action { background: #E2E8F0; color: #A0AEC0; border: none; }

/* Empty state */
.empty-state { text-align: center; padding: 60px 20px; color: #718096; }
.empty-state i { font-size: 4rem; margin-bottom: 16px; opacity: 0.3; color: #6B46C1; display: block; }
.empty-state h5 { color: #4A5568; font-weight: 700; margin-bottom: 8px; }
.empty-state p { margin: 0; font-size: 0.9rem; }

/* Cyber form styles */
.form-label-cyber {
    display: flex;
    align-items: center;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #4A5568;
    margin-bottom: 8px;
}

.form-control-cyber, .form-select-cyber {
    border: 2px solid #E2E8F0;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}

.form-control-cyber:focus, .form-select-cyber:focus {
    outline: none;
    border-color: #6B46C1;
    box-shadow: 0 0 0 3px rgba(107, 70, 193, 0.15);
}

/* Cyber card */
.cyber-card {
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.08);
}

.cyber-card .card-header {
    background: linear-gradient(135deg, #1A202C 0%, #2D3748 100%);
    color: #fff;
    padding: 16px 20px;
    border: none;
    font-weight: 600;
    display: flex;
    align-items: center;
}

.cyber-card .card-header i { color: #ED8936; }

/* User badge */
.user-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #6B46C1, #553C9A);
    color: #fff;
    border-radius: 30px;
    font-size: 0.85rem;
    font-weight: 600;
    box-shadow: 0 4px 15px rgba(107, 70, 193, 0.3);
}

/* Toast */
.client-toast {
    background: white;
    padding: 14px 20px;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 240px;
    font-weight: 600;
    color: #1A202C;
    font-family: 'Inter', sans-serif;
    margin-bottom: 10px;
    transform: translateX(120%);
    transition: transform 0.3s ease;
}

.client-toast.show { transform: translateX(0); }

/* Responsive */
@media (max-width: 767.98px) {
    .product-image { height: 150px; }
    .product-title { font-size: 0.9rem; min-height: 2.4em; }
    .product-price { font-size: 1rem; }
    .image-badge { font-size: 0.6rem; padding: 4px 8px; }
}
</style>

<?php include 'includes/footer.php'; ?>

<script>
/* ============================================
   SIDEBAR COLLAPSE
   ============================================ */
(function() {
    'use strict';
    
    function initSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const collapseBtn = document.getElementById('sidebarCollapseBtn');
        const desktopToggle = document.getElementById('sidebarDesktopToggle');
        const mobileToggles = document.querySelectorAll('.sidebar-toggle');
        
        if (!sidebar) return;
        
        if (localStorage.getItem('cyberSidebarCollapsed') === '1' && window.innerWidth >= 992) {
            sidebar.classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed');
        }
        
        function toggle() {
            if (window.innerWidth < 992) return;
            sidebar.classList.toggle('collapsed');
            document.body.classList.toggle('sidebar-collapsed');
            localStorage.setItem('cyberSidebarCollapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
            setTimeout(() => window.dispatchEvent(new Event('resize')), 350);
        }
        
        if (collapseBtn) collapseBtn.addEventListener('click', e => { e.preventDefault(); toggle(); });
        if (desktopToggle) desktopToggle.addEventListener('click', e => { e.preventDefault(); toggle(); });
        
        document.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
                e.preventDefault();
                toggle();
            }
        });
        
        mobileToggles.forEach(btn => btn.addEventListener('click', e => {
            e.preventDefault();
            sidebar.classList.toggle('show');
            if (overlay) overlay.classList.toggle('show');
        }));
        
        if (overlay) overlay.addEventListener('click', () => {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
})();

/* ============================================
   FAVORITE BUTTON — WORKING AJAX TOGGLE
   ============================================ */
document.addEventListener('DOMContentLoaded', function() {
    console.log('🔧 Favorite script loaded');
    
    const favoriteButtons = document.querySelectorAll('.favorite-btn');
    console.log('❤️ Found', favoriteButtons.length, 'favorite buttons');
    
    favoriteButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const productId = this.getAttribute('data-product-id');
            const isFavorite = this.getAttribute('data-is-favorite') === '1';
            const btnElement = this;
            
            // Prevent double-clicks
            if (btnElement.classList.contains('loading')) return;
            btnElement.classList.add('loading');
            
            // Determine which API to call
            const apiUrl = isFavorite 
                ? 'api/remove-favorite.php?product=' + productId
                : 'api/add-favorite.php?product=' + productId;
            
            console.log('📡 Calling:', apiUrl);
            
            fetch(apiUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                console.log('📥 HTTP Status:', response.status);
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(data => {
                console.log('📦 Response:', data);
                
                if (data.success) {
                    // Toggle visual state
                    if (isFavorite) {
                        // Was favorite → now removed
                        btnElement.classList.remove('active');
                        btnElement.setAttribute('data-is-favorite', '0');
                        btnElement.setAttribute('title', 'Add to favorites');
                        btnElement.innerHTML = '<i class="far fa-heart"></i>';
                        showToast('Removed from favorites', 'info');
                        updateFavoritesCount(-1);
                    } else {
                        // Was not favorite → now added
                        btnElement.classList.add('active');
                        btnElement.setAttribute('data-is-favorite', '1');
                        btnElement.setAttribute('title', 'Remove from favorites');
                        btnElement.innerHTML = '<i class="fas fa-heart"></i>';
                        showToast('Added to favorites ❤️', 'success');
                        updateFavoritesCount(1);
                    }
                } else {
                    showToast(data.message || 'Action failed', 'error');
                }
            })
            .catch(error => {
                console.error('❌ Favorite error:', error);
                showToast('Error: ' + error.message, 'error');
            })
            .finally(() => {
                btnElement.classList.remove('loading');
            });
        });
    });
    
    // Update favorites count in navbar
    function updateFavoritesCount(delta) {
        const countEl = document.querySelector('.favorites-count');
        if (!countEl) return;
        
        let current = parseInt(countEl.textContent) || 0;
        let newCount = Math.max(0, current + delta);
        countEl.textContent = newCount;
    }
});

/* ============================================
   TOAST NOTIFICATIONS
   ============================================ */
function showToast(message, type) {
    type = type || 'info';
    
    const colors = {
        success: '#48BB78',
        error: '#E53E3E',
        warning: '#ED8936',
        info: '#6B46C1'
    };
    
    const icons = {
        success: 'check-circle',
        error: 'times-circle',
        warning: 'exclamation-triangle',
        info: 'info-circle'
    };
    
    const container = document.getElementById('toastContainer');
    if (!container) {
        console.warn('Toast container not found, using body');
        return;
    }
    
    const toast = document.createElement('div');
    toast.className = 'client-toast';
    toast.style.borderLeft = '4px solid ' + colors[type];
    toast.innerHTML = `
        <i class="fas fa-${icons[type]}" style="color: ${colors[type]}; font-size: 1.2rem;"></i>
        <span>${message}</span>
    `;
    
    container.appendChild(toast);
    
    // Trigger slide in
    requestAnimationFrame(() => {
        setTimeout(() => toast.classList.add('show'), 10);
    });
    
    // Auto remove
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 2500);
}
</script>