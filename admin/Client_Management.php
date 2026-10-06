<?php
require_once '../config/config.php';
require_login();
if (!is_admin()) { header('Location: ../employee/dashboard.php'); exit(); }

$page_title = "Client Management";

// Get all clients with their profile and order statistics
try {
    $stmt = $conn->query("
        SELECT u.*, cp.*,
               COUNT(DISTINCT co.order_id) as total_orders,
               COALESCE(SUM(CASE WHEN co.order_status = 'pending' THEN 1 ELSE 0 END), 0) as pending_orders,
               COALESCE(SUM(co.grand_total), 0) as total_spent
        FROM users u
        LEFT JOIN client_profiles cp ON u.user_id = cp.user_id
        LEFT JOIN client_orders co ON u.user_id = co.client_id
        WHERE u.role = 'client'
        GROUP BY u.user_id
        ORDER BY u.created_at DESC
    ");
    $clients = $stmt->fetchAll();
} catch(PDOException $e) {
    $clients = [];
}

$alert = get_alert();
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
        <div class="d-flex justify-content-between align-items-center">
            <!-- MOBILE SIDEBAR TOGGLE -->
            <button class="btn btn-link d-md-none sidebar-toggle me-2">
                <i class="fas fa-bars"></i>
            </button>
            <h5 class="mb-0"><i class="fas fa-user-tie me-2"></i>Client Management</h5>
            <a href="Users" class="btn btn-sm btn-primary">
                <i class="fas fa-user-plus me-1"></i>Add New Client
            </a>
        </div>
    </div>
    
    <div class="content-area">
        <?php if ($alert): ?>
        <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show">
            <?php echo $alert['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card purple">
                    <div class="icon"><i class="fas fa-users"></i></div>
                    <h3><?php echo count($clients); ?></h3>
                    <p>Total Clients</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card orange">
                    <div class="icon"><i class="fas fa-user-check"></i></div>
                    <h3><?php echo count(array_filter($clients, fn($c) => $c['status'] == 'active')); ?></h3>
                    <p>Active Clients</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card green">
                    <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                    <h3>UGX <?php echo format_currency(array_sum(array_column($clients, 'total_spent'))); ?></h3>
                    <p>Total Revenue</p>
                </div>
            </div>
        </div>
        
        <!-- Clients Table -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list me-2"></i>All Clients (<?php echo count($clients); ?>)
            </div>
            <div class="card-body">
                <?php if (empty($clients)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5>No Clients Found</h5>
                        <p class="text-muted">Create client accounts from the Users page</p>
                        <a href="Users" class="btn btn-primary">
                            <i class="fas fa-user-plus me-2"></i>Add New Client
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Client Name</th>
                                    <th>Company</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Credit Limit</th>
                                    <th>Balance</th>
                                    <th>Orders</th>
                                    <th>Total Spent</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clients as $client): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($client['full_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($client['company_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($client['phone'] ?? 'N/A'); ?></td>
                                    <td>UGX <?php echo format_currency($client['credit_limit'] ?? 0); ?></td>
                                    <td>
                                        <?php if (($client['current_balance'] ?? 0) > 0): ?>
                                            <span class="text-danger">UGX <?php echo format_currency($client['current_balance']); ?></span>
                                        <?php else: ?>
                                            <span class="text-success">UGX 0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $client['total_orders']; ?>
                                        <?php if ($client['pending_orders'] > 0): ?>
                                            <span class="badge badge-warning"><?php echo $client['pending_orders']; ?> pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong>UGX <?php echo format_currency($client['total_spent']); ?></strong></td>
                                    <td>
                                        <?php
                                        $status_class = match($client['status']) {
                                            'active' => 'badge-success',
                                            'suspended' => 'badge-warning',
                                            default => 'badge-danger'
                                        };
                                        ?>
                                        <span class="badge <?php echo $status_class; ?>">
                                            <?php echo ucfirst($client['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="Client_Orders?client=<?php echo $client['user_id']; ?>" class="btn btn-sm btn-info" title="View Orders">
                                            <i class="fas fa-shopping-cart"></i>
                                        </a>
                                        <button onclick="viewClient(<?php echo $client['user_id']; ?>)" class="btn btn-sm btn-primary" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
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

<!-- View Client Modal -->
<div class="modal fade" id="viewClientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user me-2"></i>Client Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="clientDetails">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function viewClient(userId) {
    const modal = new bootstrap.Modal(document.getElementById('viewClientModal'));
    modal.show();
    
    fetch('get-client-details.php?id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const client = data.client;
                let html = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Personal Information</h6>
                            <p><strong>Name:</strong> ${client.full_name}</p>
                            <p><strong>Username:</strong> ${client.username}</p>
                            <p><strong>Email:</strong> ${client.email || 'N/A'}</p>
                            <p><strong>Phone:</strong> ${client.phone || 'N/A'}</p>
                            <p><strong>Status:</strong> <span class="badge badge-${client.status=='active'?'success':'danger'}">${client.status}</span></p>
                        </div>
                        <div class="col-md-6">
                            <h6>Business Information</h6>
                            <p><strong>Company:</strong> ${client.company_name || 'N/A'}</p>
                            <p><strong>Tax ID:</strong> ${client.tax_id || 'N/A'}</p>
                            <p><strong>Credit Limit:</strong> UGX ${parseFloat(client.credit_limit || 0).toLocaleString()}</p>
                            <p><strong>Current Balance:</strong> UGX ${parseFloat(client.current_balance || 0).toLocaleString()}</p>
                            <p><strong>Discount Rate:</strong> ${client.discount_rate}%</p>
                            <p><strong>Payment Terms:</strong> ${client.payment_terms}</p>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-md-12">
                            <h6>Order Statistics</h6>
                            <p><strong>Total Orders:</strong> ${data.stats.total_orders}</p>
                            <p><strong>Pending Orders:</strong> ${data.stats.pending_orders}</p>
                            <p><strong>Total Spent:</strong> UGX ${parseFloat(data.stats.total_spent).toLocaleString()}</p>
                        </div>
                    </div>
                `;
                document.getElementById('clientDetails').innerHTML = html;
            } else {
                document.getElementById('clientDetails').innerHTML = '<div class="alert alert-danger">Error loading client details</div>';
            }
        })
        .catch(error => {
            document.getElementById('clientDetails').innerHTML = '<div class="alert alert-danger">Error loading client details</div>';
        });
}
</script>

<?php include '../includes/footer.php'; ?>
