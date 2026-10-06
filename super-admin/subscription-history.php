<?php
require_once 'auth.php';
require_super_admin();

$page_title = "Subscription History";

// Get all subscription history
$query = "
    SELECT 
        sh.*,
        o.org_name,
        o.org_code,
        sa.full_name as admin_name
    FROM subscription_history sh
    LEFT JOIN organizations o ON sh.org_id = o.org_id
    LEFT JOIN super_admins sa ON sh.performed_by = sa.admin_id
    ORDER BY sh.performed_at DESC
";
$stmt = $master_conn->query($query);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include 'includes/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-10 ms-sm-auto px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-history me-2"></i>Subscription History</h1>
            </div>
            
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Subscription Changes</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover datatable">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Organization</th>
                                    <th>Action</th>
                                    <th>Previous End</th>
                                    <th>New End</th>
                                    <th>Days Added</th>
                                    <th>Notes</th>
                                    <th>Performed By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $h): ?>
                                <tr>
                                    <td><?php echo date('M d, Y H:i', strtotime($h['performed_at'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($h['org_name']); ?></strong><br>
                                        <small class="text-muted"><code><?php echo $h['org_code']; ?></code></small>
                                    </td>
                                    <td>
                                        <?php
                                        $badge_class = '';
                                        switch($h['action']) {
                                            case 'created': $badge_class = 'bg-primary'; break;
                                            case 'extended': $badge_class = 'bg-success'; break;
                                            case 'upgraded': $badge_class = 'bg-info'; break;
                                            case 'suspended': $badge_class = 'bg-warning'; break;
                                            case 'reactivated': $badge_class = 'bg-success'; break;
                                        }
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst($h['action']); ?></span>
                                    </td>
                                    <td><?php echo $h['previous_end_date'] ? date('M d, Y', strtotime($h['previous_end_date'])) : '-'; ?></td>
                                    <td><?php echo $h['new_end_date'] ? date('M d, Y', strtotime($h['new_end_date'])) : '-'; ?></td>
                                    <td>
                                        <?php if ($h['days_added']): ?>
                                            <span class="badge bg-info">+<?php echo $h['days_added']; ?> days</span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $h['notes'] ? htmlspecialchars($h['notes']) : '-'; ?></td>
                                    <td><?php echo $h['admin_name'] ?: 'System'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
        </main>
    </div>
</div>

<?php include 'includes/footer.php'; ?>