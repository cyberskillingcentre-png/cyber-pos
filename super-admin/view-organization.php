<?php
require_once 'auth.php';
require_super_admin();

$page_title = "View Organization";

$org_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$org_id) {
    header('Location: organizations.php');
    exit();
}

// Get organization details
$stmt = $master_conn->prepare("SELECT * FROM organizations WHERE org_id = ?");
$stmt->execute([$org_id]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    header('Location: organizations.php');
    exit();
}

// Calculate days remaining
$today = date('Y-m-d');
$days_remaining = (strtotime($org['subscription_end']) - strtotime($today)) / (60 * 60 * 24);
$org['days_remaining'] = ceil($days_remaining);

// Get subscription history
$stmt = $master_conn->prepare("
    SELECT sh.*, sa.full_name as admin_name 
    FROM subscription_history sh 
    LEFT JOIN super_admins sa ON sh.performed_by = sa.admin_id 
    WHERE sh.org_id = ? 
    ORDER BY sh.performed_at DESC
");
$stmt->execute([$org_id]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get login attempts
$stmt = $master_conn->prepare("
    SELECT * FROM org_login_attempts 
    WHERE org_id = ? 
    ORDER BY attempted_at DESC 
    LIMIT 20
");
$stmt->execute([$org_id]);
$login_attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include 'includes/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-10 ms-sm-auto px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-building me-2"></i><?php echo htmlspecialchars($org['org_name']); ?></h1>
                <a href="organizations.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to List
                </a>
            </div>
            
            <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <div class="row">
                <!-- Organization Details -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Organization Details</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Organization Code:</th>
                                    <td><code class="fs-5"><?php echo $org['org_code']; ?></code></td>
                                </tr>
                                <tr>
                                    <th>Organization Name:</th>
                                    <td><strong><?php echo htmlspecialchars($org['org_name']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th>Contact Person:</th>
                                    <td><?php echo htmlspecialchars($org['contact_person']); ?></td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td><a href="mailto:<?php echo $org['email']; ?>"><?php echo $org['email']; ?></a></td>
                                </tr>
                                <tr>
                                    <th>Phone:</th>
                                    <td><?php echo $org['phone']; ?></td>
                                </tr>
                                <tr>
                                    <th>Address:</th>
                                    <td><?php echo $org['address'] ? htmlspecialchars($org['address']) : '-'; ?></td>
                                </tr>
                                <tr>
                                    <th>Database:</th>
                                    <td><code><?php echo $org['database_name']; ?></code></td>
                                </tr>
                                <tr>
                                    <th>Created:</th>
                                    <td><?php echo date('F d, Y H:i', strtotime($org['created_at'])); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Subscription Details -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Subscription Details</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Type:</th>
                                    <td>
                                        <?php if ($org['subscription_type'] == 'demo'): ?>
                                            <span class="badge bg-warning">Demo</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td>
                                        <?php if ($org['status'] == 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php elseif ($org['status'] == 'expired'): ?>
                                            <span class="badge bg-danger">Expired</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Suspended</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Start Date:</th>
                                    <td><?php echo date('F d, Y', strtotime($org['subscription_start'])); ?></td>
                                </tr>
                                <tr>
                                    <th>End Date:</th>
                                    <td>
                                        <strong class="<?php echo $org['days_remaining'] <= 5 ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo date('F d, Y', strtotime($org['subscription_end'])); ?>
                                        </strong>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Days Remaining:</th>
                                    <td>
                                        <?php if ($org['days_remaining'] < 0): ?>
                                            <span class="badge bg-danger fs-5">Expired</span>
                                        <?php elseif ($org['days_remaining'] <= 5): ?>
                                            <span class="badge bg-danger fs-5"><?php echo $org['days_remaining']; ?> days</span>
                                        <?php elseif ($org['days_remaining'] <= 15): ?>
                                            <span class="badge bg-warning fs-5"><?php echo $org['days_remaining']; ?> days</span>
                                        <?php else: ?>
                                            <span class="badge bg-success fs-5"><?php echo $org['days_remaining']; ?> days</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                            
                            <hr>
                            
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-primary" onclick="openExtendModal(<?php echo $org['org_id']; ?>, '<?php echo htmlspecialchars($org['org_name']); ?>', '<?php echo $org['subscription_end']; ?>')">
                                    <i class="fas fa-calendar-plus me-2"></i>Extend Subscription
                                </button>
                                
                                <?php if ($org['subscription_type'] == 'demo'): ?>
                                <button type="button" class="btn btn-success" onclick="openUpgradeModal(<?php echo $org['org_id']; ?>, '<?php echo htmlspecialchars($org['org_name']); ?>')">
                                    <i class="fas fa-arrow-up me-2"></i>Upgrade to Paid
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($org['status'] == 'active'): ?>
                                <a href="actions.php?action=suspend&id=<?php echo $org['org_id']; ?>" class="btn btn-warning" onclick="return confirm('Suspend this organization?')">
                                    <i class="fas fa-ban me-2"></i>Suspend Organization
                                </a>
                                <?php else: ?>
                                <a href="actions.php?action=activate&id=<?php echo $org['org_id']; ?>" class="btn btn-success" onclick="return confirm('Activate this organization?')">
                                    <i class="fas fa-check me-2"></i>Activate Organization
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Subscription History -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-history me-2"></i>Subscription History</h5>
                </div>
                <div class="card-body">
                    <?php if (count($history) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Date</th>
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
                                    <td><?php echo $h['days_added'] ?: '-'; ?></td>
                                    <td><?php echo $h['notes'] ? htmlspecialchars($h['notes']) : '-'; ?></td>
                                    <td><?php echo $h['admin_name'] ?: 'System'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-muted mb-0">No history available</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Login Attempts -->
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="fas fa-sign-in-alt me-2"></i>Recent Login Attempts (Last 20)</h5>
                </div>
                <div class="card-body">
                    <?php if (count($login_attempts) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($login_attempts as $attempt): ?>
                                <tr>
                                    <td><?php echo date('M d, Y H:i:s', strtotime($attempt['attempted_at'])); ?></td>
                                    <td><code><?php echo $attempt['ip_address']; ?></code></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-muted mb-0">No login attempts recorded</p>
                    <?php endif; ?>
                </div>
            </div>
            
        </main>
    </div>
</div>

<!-- Modals (same as in organizations.php) -->
<div class="modal fade" id="extendModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Extend Subscription</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="actions.php">
                <input type="hidden" name="action" value="extend">
                <input type="hidden" name="org_id" id="extend_org_id">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong>Organization:</strong> <span id="extend_org_name"></span><br>
                        <strong>Current End Date:</strong> <span id="extend_current_date"></span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Extend By (Days) *</label>
                        <input type="number" name="days" class="form-control" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Extend</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="upgradeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-arrow-up me-2"></i>Upgrade to Paid</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="actions.php">
                <input type="hidden" name="action" value="upgrade">
                <input type="hidden" name="org_id" id="upgrade_org_id">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong>Organization:</strong> <span id="upgrade_org_name"></span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subscription Period *</label>
                        <select name="period" class="form-select" required>
                            <option value="30">1 Month (30 days)</option>
                            <option value="90">3 Months (90 days)</option>
                            <option value="180">6 Months (180 days)</option>
                            <option value="365" selected>1 Year (365 days)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Upgrade</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openExtendModal(orgId, orgName, currentEndDate) {
    document.getElementById('extend_org_id').value = orgId;
    document.getElementById('extend_org_name').textContent = orgName;
    document.getElementById('extend_current_date').textContent = currentEndDate;
    new bootstrap.Modal(document.getElementById('extendModal')).show();
}

function openUpgradeModal(orgId, orgName) {
    document.getElementById('upgrade_org_id').value = orgId;
    document.getElementById('upgrade_org_name').textContent = orgName;
    new bootstrap.Modal(document.getElementById('upgradeModal')).show();
}
</script>

<?php include 'includes/footer.php'; ?>