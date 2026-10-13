<?php
require_once 'auth.php';
require_super_admin();

$page_title = "Organizations Management";

/* =========================
   GET FILTER PARAMETERS
========================= */
$filter_type   = $_GET['type']   ?? 'all';
$filter_status = $_GET['status'] ?? 'all';

/* =========================
   BUILD QUERY
========================= */
$where_clauses = [];
$params = [];

if ($filter_type !== 'all') {
    $where_clauses[] = "subscription_type = ?";
    $params[] = $filter_type;
}

if ($filter_status !== 'all') {
    $where_clauses[] = "status = ?";
    $params[] = $filter_status;
}

$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$query = "SELECT * FROM organizations $where_sql ORDER BY created_at DESC";
$stmt  = $master_conn->prepare($query);
$stmt->execute($params);
$organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   CALCULATE DAYS REMAINING
   (NO REFERENCES = SAFE)
========================= */
$today = strtotime(date('Y-m-d'));

foreach ($organizations as $key => $org) {
    $end = strtotime($org['subscription_end']);
    $days = ceil(($end - $today) / 86400);
    $organizations[$key]['days_remaining'] = $days;
}
?>
<?php include 'includes/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-10 ms-sm-auto px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-building me-2"></i>Organizations Management</h1>
            </div>
            
            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">Filter by Type</label>
                            <select class="form-select" onchange="window.location.href='organizations.php?type=' + this.value + '&status=<?php echo $filter_status; ?>'">
                                <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="demo" <?php echo $filter_type == 'demo' ? 'selected' : ''; ?>>Demo</option>
                                <option value="paid" <?php echo $filter_type == 'paid' ? 'selected' : ''; ?>>Paid</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Filter by Status</label>
                            <select class="form-select" onchange="window.location.href='organizations.php?type=<?php echo $filter_type; ?>&status=' + this.value">
                                <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $filter_status == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="expired" <?php echo $filter_status == 'expired' ? 'selected' : ''; ?>>Expired</option>
                                <option value="suspended" <?php echo $filter_status == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">&nbsp;</label>
                            <a href="organizations.php" class="btn btn-secondary w-100">
                                <i class="fas fa-redo me-2"></i>Clear Filters
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Organizations Table -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>Organizations (<?php echo count($organizations); ?>)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover datatable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Organization</th>
                                    <th>Code</th>
                                    <th>Contact</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Expires</th>
                                    <th>Days Left</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($organizations as $org): ?>
                                <tr>
                                    <td><?php echo $org['org_id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($org['org_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($org['email']); ?></small>
                                    </td>
                                    <td><code><?php echo $org['org_code']; ?></code></td>
                                    <td>
                                        <?php echo htmlspecialchars($org['contact_person']); ?><br>
                                        <small class="text-muted"><?php echo $org['phone']; ?></small>
                                    </td>
                                    <td>
                                        <?php if ($org['subscription_type'] == 'demo'): ?>
                                            <span class="badge bg-warning">Demo</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($org['status'] == 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php elseif ($org['status'] == 'expired'): ?>
                                            <span class="badge bg-danger">Expired</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Suspended</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($org['created_at'])); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($org['subscription_end'])); ?></td>
                                    <td>
                                        <?php if ($org['days_remaining'] < 0): ?>
                                            <span class="badge bg-danger">Expired</span>
                                        <?php elseif ($org['days_remaining'] <= 5): ?>
                                            <span class="badge bg-danger"><?php echo $org['days_remaining']; ?> days</span>
                                        <?php elseif ($org['days_remaining'] <= 15): ?>
                                            <span class="badge bg-warning"><?php echo $org['days_remaining']; ?> days</span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><?php echo $org['days_remaining']; ?> days</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="view-organization.php?id=<?php echo $org['org_id']; ?>" class="btn btn-sm btn-info" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-primary" onclick="openExtendModal(<?php echo $org['org_id']; ?>, '<?php echo htmlspecialchars($org['org_name']); ?>', '<?php echo $org['subscription_end']; ?>')" title="Extend Subscription">
                                                <i class="fas fa-calendar-plus"></i>
                                            </button>
                                            
                                            <?php if ($org['status'] == 'active'): ?>
                                            <a href="actions.php?action=suspend&id=<?php echo $org['org_id']; ?>" class="btn btn-sm btn-warning" onclick="return confirm('Suspend this organization?')" title="Suspend">
                                                <i class="fas fa-ban"></i>
                                            </a>
                                            <?php else: ?>
                                            <a href="actions.php?action=activate&id=<?php echo $org['org_id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Activate this organization?')" title="Activate">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <button type="button" class="btn btn-sm btn-secondary" onclick="openUpgradeModal(<?php echo $org['org_id']; ?>, '<?php echo htmlspecialchars($org['org_name']); ?>')" title="Upgrade to Paid">
                                                <i class="fas fa-arrow-up"></i>
                                            </button>
                                            <a href="actions.php?action=delete&id=<?= $org['org_id']; ?>"
   class="btn btn-sm btn-danger"
   onclick="return confirm(
       '⚠ WARNING!\n\nThis will permanently DELETE:\n- Organization\n- All tenant data\n- All users\n\nThis action CANNOT be undone.\n\nContinue?'
   )"
   title="Delete Organization">
    <i class="fas fa-trash"></i>
</a>

                                        </div>
                                    </td>
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

<!-- Extend Subscription Modal -->
<div class="modal fade" id="extendModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Extend Subscription</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="actions.php">
                <?php echo csrf_field(); ?>
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
                        <small class="text-muted">Enter number of days to add</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check me-1"></i>Extend Subscription
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upgrade to Paid Modal -->
<div class="modal fade" id="upgradeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-arrow-up me-2"></i>Upgrade to Paid Subscription</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="actions.php">
                <?php echo csrf_field(); ?>
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
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Upgrade to Paid
                    </button>
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