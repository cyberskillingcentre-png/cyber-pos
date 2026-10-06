<?php
require_once 'auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action'])) {

    $action = $_POST['action'] ?? $_GET['action'];

    try {
        $master_conn->beginTransaction();

        switch ($action) {

            /* =========================
               DELETE ORGANIZATION
            ========================= */
            case 'delete':

                if (!isset($_GET['id'])) {
                    throw new Exception('Invalid organization ID');
                }

                $org_id = (int) $_GET['id'];

                // Fetch org
                $stmt = $master_conn->prepare(
                    "SELECT org_name, org_code, database_name 
                     FROM organizations 
                     WHERE org_id = ?"
                );
                $stmt->execute([$org_id]);
                $org = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$org) {
                    throw new Exception('Organization not found');
                }

                // Drop tenant DB (if exists)
                if (!empty($org['database_name'])) {
                    $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $org['database_name']);
                    $master_conn->exec("DROP DATABASE IF EXISTS `$dbName`");
                }

                // Delete org record
                $stmt = $master_conn->prepare(
                    "DELETE FROM organizations WHERE org_id = ?"
                );
                $stmt->execute([$org_id]);

                // Audit log
                $stmt = $master_conn->prepare("
                    INSERT INTO audit_logs 
                    (user_id, action, description, created_at)
                    VALUES (?, 'DELETE_ORGANIZATION', ?, NOW())
                ");
                $stmt->execute([
                    $_SESSION['super_admin_id'],
                    "Deleted organization: {$org['org_name']} ({$org['org_code']})"
                ]);

                $message = "Organization deleted successfully";
                break;

            /* =========================
               EXTEND SUBSCRIPTION
            ========================= */
            case 'extend':

                $org_id = (int) $_POST['org_id'];
                $days   = (int) $_POST['days'];
                $notes  = trim($_POST['notes']);

                $stmt = $master_conn->prepare(
                    "SELECT subscription_end FROM organizations WHERE org_id = ?"
                );
                $stmt->execute([$org_id]);
                $org = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$org) {
                    throw new Exception('Organization not found');
                }

                $current_end = $org['subscription_end'];
                $new_end = date('Y-m-d', strtotime("$current_end +$days days"));

                $stmt = $master_conn->prepare(
                    "UPDATE organizations 
                     SET subscription_end = ?, status = 'active' 
                     WHERE org_id = ?"
                );
                $stmt->execute([$new_end, $org_id]);

                $stmt = $master_conn->prepare("
                    INSERT INTO subscription_history
                    (org_id, action, previous_end_date, new_end_date, days_added, notes, performed_by)
                    VALUES (?, 'extended', ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $org_id, $current_end, $new_end, $days, $notes, $_SESSION['super_admin_id']
                ]);

                $message = "Subscription extended successfully";
                break;

            /* =========================
               UPGRADE TO PAID
            ========================= */
            case 'upgrade':

                $org_id = (int) $_POST['org_id'];
                $period = (int) $_POST['period'];
                $notes  = trim($_POST['notes']);

                $stmt = $master_conn->prepare(
                    "SELECT subscription_end FROM organizations WHERE org_id = ?"
                );
                $stmt->execute([$org_id]);
                $org = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$org) {
                    throw new Exception('Organization not found');
                }

                $current_end = $org['subscription_end'];
                $new_end = date('Y-m-d', strtotime("$current_end +$period days"));

                $stmt = $master_conn->prepare("
                    UPDATE organizations
                    SET subscription_type = 'paid',
                        subscription_end = ?,
                        status = 'active'
                    WHERE org_id = ?
                ");
                $stmt->execute([$new_end, $org_id]);

                $stmt = $master_conn->prepare("
                    INSERT INTO subscription_history
                    (org_id, action, previous_end_date, new_end_date, days_added, notes, performed_by)
                    VALUES (?, 'upgraded', ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $org_id, $current_end, $new_end, $period, $notes, $_SESSION['super_admin_id']
                ]);

                $message = "Organization upgraded successfully";
                break;

            /* =========================
               SUSPEND
            ========================= */
            case 'suspend':

                $org_id = (int) $_GET['id'];

                $stmt = $master_conn->prepare(
                    "UPDATE organizations SET status = 'suspended' WHERE org_id = ?"
                );
                $stmt->execute([$org_id]);

                $stmt = $master_conn->prepare("
                    INSERT INTO subscription_history
                    (org_id, action, notes, performed_by)
                    VALUES (?, 'suspended', 'Suspended by admin', ?)
                ");
                $stmt->execute([$org_id, $_SESSION['super_admin_id']]);

                $message = "Organization suspended successfully";
                break;

            /* =========================
               ACTIVATE
            ========================= */
            case 'activate':

                $org_id = (int) $_GET['id'];

                $stmt = $master_conn->prepare(
                    "UPDATE organizations SET status = 'active' WHERE org_id = ?"
                );
                $stmt->execute([$org_id]);

                $stmt = $master_conn->prepare("
                    INSERT INTO subscription_history
                    (org_id, action, notes, performed_by)
                    VALUES (?, 'reactivated', 'Reactivated by admin', ?)
                ");
                $stmt->execute([$org_id, $_SESSION['super_admin_id']]);

                $message = "Organization activated successfully";
                break;

            default:
                throw new Exception('Invalid action');
        }

        $master_conn->commit();

        $_SESSION['success_message'] = $message;
        header('Location: organizations.php');
        exit;

    } catch (Exception $e) {

        $master_conn->rollBack();
        $_SESSION['error_message'] = $e->getMessage();
        header('Location: organizations.php');
        exit;
    }
}

header('Location: organizations.php');
exit;
