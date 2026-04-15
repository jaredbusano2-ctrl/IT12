<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';

setSecurityHeaders();
requireAdmin();

$user = getCurrentUser();

// Prefer audit_logs if present; fall back to void_logs/activity_logs.
$rows = [];

try {
    $pdo = getPDO();

    // Check for audit_logs table
    $hasAudit = (bool) $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetch();

    if ($hasAudit) {
        $stmt = $pdo->prepare("\
            SELECT a.id, a.user_id, a.action, a.description, a.created_at,
                   u.full_name, u.role
            FROM audit_logs a
            LEFT JOIN users u ON u.user_id = a.user_id
            ORDER BY a.created_at DESC
            LIMIT 200
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Fall back: show void_logs if present, else activity_logs filtered by void actions
        $hasVoidLogs = (bool) $pdo->query("SHOW TABLES LIKE 'void_logs'")->fetch();

        if ($hasVoidLogs) {
            $stmt = $pdo->prepare("\
                SELECT v.void_id AS id, v.cashier_id AS user_id, 'VOID_TRANSACTION' AS action,
                       CONCAT('Voided ', v.void_type, ' for sale ID: ', COALESCE(v.order_id, ''),
                              CASE WHEN v.sale_item_id IS NOT NULL THEN CONCAT(' | sale_item_id: ', v.sale_item_id) ELSE '' END,
                              CASE WHEN v.product_name IS NOT NULL THEN CONCAT(' | product: ', v.product_name) ELSE '' END
                       ) AS description,
                       v.created_at,
                       u.full_name,
                       u.role
                FROM void_logs v
                LEFT JOIN users u ON u.user_id = v.cashier_id
                ORDER BY v.created_at DESC
                LIMIT 200
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("\
                SELECT l.log_id AS id, l.user_id, l.action,
                       COALESCE(l.description, l.details) AS description,
                       l.created_at,
                       u.full_name,
                       u.role
                FROM activity_logs l
                LEFT JOIN users u ON u.user_id = l.user_id
                WHERE l.action LIKE '%void%'
                ORDER BY l.created_at DESC
                LIMIT 200
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Exception $e) {
    error_log('logs.php error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Audit Logs - POS & Inventory System</title>
    <link rel="stylesheet" href="css/style.css" />
</head>
<body>
<div class="main-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="header">
            <h1>Audit Logs</h1>
            <div class="header-actions">
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
                    <div class="user-details">
                        <h4><?php echo htmlspecialchars($user['full_name']); ?></h4>
                        <p><?php echo ucfirst($user['role']); ?></p>
                    </div>
                </div>
                <a href="logout.php" class="btn btn-logout btn-sm">Logout</a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Void & Security Actions (Latest 200)</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table>
                        <thead>
                        <tr>
                            <th>When</th>
                            <th>User</th>
                            <th>Role</th>
                            <th>Action</th>
                            <th>Description</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($rows)): ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($r['created_at']))); ?></td>
                                    <td><?php echo htmlspecialchars($r['full_name'] ?? ('User #' . ($r['user_id'] ?? ''))); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($r['role'] ?? '')); ?></td>
                                    <td><span class="badge badge-primary"><?php echo htmlspecialchars($r['action'] ?? ''); ?></span></td>
                                    <td><?php echo htmlspecialchars($r['description'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center;padding:24px;color:var(--text-secondary);">No audit entries found.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>
</body>
</html>
