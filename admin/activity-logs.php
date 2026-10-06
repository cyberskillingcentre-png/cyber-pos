<?php
require_once '../config/config.php';
require_admin();
$page_title = "Activity Logs";
$stmt = $conn->prepare("SELECT al.*, u.full_name, u.username FROM activity_logs al LEFT JOIN users u ON al.user_id = u.user_id ORDER BY al.created_at DESC LIMIT 500");
$stmt->execute();
$logs = $stmt->fetchAll();
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content">
    <div class="top-navbar">
         <button class="btn btn-link d-md-none sidebar-toggle">
                    <i class="fas fa-bars"></i>
                </button>
        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Activity Logs</h5></div>
    <div class="content-area">
        <div class="card">
            <div class="card-header"><i class="fas fa-list me-2"></i>Recent Activities (Last 500)</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datatable table-hover table-sm">
                        <thead><tr><th>Date/Time</th><th>User</th><th>Activity</th><th>Description</th><th>IP Address</th></tr></thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></td>
                                <td><?php echo $log['full_name'] ?? 'System'; ?></td>
                                <td><span class="badge badge-info"><?php echo $log['activity_type']; ?></span></td>
                                <td><?php echo $log['activity_description']; ?></td>
                                <td><?php echo $log['ip_address']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
