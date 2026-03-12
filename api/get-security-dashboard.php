<?php
/**
 * Security Dashboard API
 * Provides security alerts, failed login stats, and system health for admin dashboard
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

// Require admin access
requireLogin();
requireAdmin();

try {
    $pdo = getPDO();
    
    // Get today's date
    $today = date('Y-m-d');
    
    // 1. Failed login attempts today
    $failedLogins = $pdo->query("
        SELECT COUNT(*) as count 
        FROM login_attempts 
        WHERE attempt_type = 'login' 
          AND DATE(last_attempt) = CURDATE()
          AND attempts > 0
    ")->fetch(PDO::FETCH_ASSOC);
    
    // 2. Currently locked accounts
    $lockedAccounts = $pdo->query("
        SELECT COUNT(*) as count 
        FROM login_attempts 
        WHERE locked_until > NOW()
    ")->fetch(PDO::FETCH_ASSOC);
    
    // 3. Recent security alerts (unacknowledged)
    $securityAlerts = [];
    try {
        $alertsStmt = $pdo->query("
            SELECT alert_id, alert_type, severity, description, ip_address, created_at
            FROM security_alerts 
            WHERE is_acknowledged = 0
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $securityAlerts = $alertsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not exist yet
        error_log("Security alerts table not found: " . $e->getMessage());
    }
    
    // 4. Void transactions today
    $voidStats = $pdo->query("
        SELECT COUNT(*) as count, COALESCE(SUM(original_total), 0) as amount
        FROM voided_orders 
        WHERE DATE(created_at) = CURDATE()
    ")->fetch(PDO::FETCH_ASSOC);
    
    // 5. Low stock items count
    $lowStockProducts = $pdo->query("
        SELECT COUNT(*) as count 
        FROM products 
        WHERE stock_quantity <= reorder_level 
          AND status = 'active'
    ")->fetch(PDO::FETCH_ASSOC);
    
    // 6. Low stock cups
    $lowStockCups = $pdo->query("
        SELECT COUNT(*) as count 
        FROM cup_inventory 
        WHERE current_stock <= reorder_level 
          AND status = 'active'
    ")->fetch(PDO::FETCH_ASSOC);
    
    // 7. Low stock ingredients
    $lowStockIngredients = $pdo->query("
        SELECT COUNT(*) as count 
        FROM ingredients 
        WHERE current_stock <= reorder_level 
          AND status = 'active'
    ")->fetch(PDO::FETCH_ASSOC);
    
    // 8. Active users today
    $activeUsers = $pdo->query("
        SELECT COUNT(DISTINCT user_id) as count 
        FROM activity_logs 
        WHERE DATE(created_at) = CURDATE()
    ")->fetch(PDO::FETCH_ASSOC);
    
    // 9. Sales today
    $salesToday = $pdo->query("
        SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as amount
        FROM sales 
        WHERE DATE(sale_date) = CURDATE()
          AND status = 'completed'
    ")->fetch(PDO::FETCH_ASSOC);
    
    // 10. Recent suspicious activities (fraud attempts, security events)
    $suspiciousActivities = [];
    try {
        $suspStmt = $pdo->prepare("
            SELECT log_id, action, COALESCE(description, details) as details, ip_address, created_at
            FROM activity_logs 
            WHERE action LIKE '%fraud%' 
               OR action LIKE '%security%'
               OR action LIKE '%unauthorized%'
               OR action LIKE '%hijack%'
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $suspStmt->execute();
        $suspiciousActivities = $suspStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Description column might not exist
        try {
            $suspStmt = $pdo->prepare("
                SELECT log_id, action, details, ip_address, created_at
                FROM activity_logs 
                WHERE action LIKE '%fraud%' 
                   OR action LIKE '%security%'
                   OR action LIKE '%unauthorized%'
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $suspStmt->execute();
            $suspiciousActivities = $suspStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {
            error_log("Activity logs query error: " . $e2->getMessage());
        }
    }
    
    // Build response
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'security' => [
            'failed_logins_today' => (int)($failedLogins['count'] ?? 0),
            'locked_accounts' => (int)($lockedAccounts['count'] ?? 0),
            'security_alerts_pending' => count($securityAlerts),
            'alerts' => $securityAlerts,
            'suspicious_activities' => $suspiciousActivities
        ],
        'voids' => [
            'count_today' => (int)($voidStats['count'] ?? 0),
            'amount_today' => (float)($voidStats['amount'] ?? 0)
        ],
        'inventory_alerts' => [
            'low_stock_products' => (int)($lowStockProducts['count'] ?? 0),
            'low_stock_cups' => (int)($lowStockCups['count'] ?? 0),
            'low_stock_ingredients' => (int)($lowStockIngredients['count'] ?? 0),
            'total_low_stock' => (int)($lowStockProducts['count'] ?? 0) + 
                                 (int)($lowStockCups['count'] ?? 0) + 
                                 (int)($lowStockIngredients['count'] ?? 0)
        ],
        'activity' => [
            'active_users_today' => (int)($activeUsers['count'] ?? 0),
            'sales_count_today' => (int)($salesToday['count'] ?? 0),
            'sales_amount_today' => (float)($salesToday['amount'] ?? 0)
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Security Dashboard API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
}
