<?php
/**
 * Real-time Activity Logs API
 * Returns recent activity logs for AJAX polling
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

// Require login and admin access
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required']);
    exit();
}

// Get parameters
$lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 20;
$action = isset($_GET['action']) ? sanitize($_GET['action']) : '';
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

try {
    $pdo = getPDO();
    
    // Build query for new logs since last_id
    $where = [];
    $params = [];
    
    if ($lastId > 0) {
        $where[] = "al.log_id > ?";
        $params[] = $lastId;
    }
    
    if ($action) {
        $where[] = "al.action LIKE ?";
        $params[] = "%$action%";
    }
    
    if ($userId > 0) {
        $where[] = "al.user_id = ?";
        $params[] = $userId;
    }
    
    $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
    
    // Try with new schema first
    try {
        $sql = "SELECT al.log_id, al.user_id, al.action, 
                       COALESCE(al.description, al.details) as details,
                       al.ip_address, al.user_agent, al.created_at,
                       u.full_name, u.username, u.role
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.user_id
                $whereClause
                ORDER BY al.log_id DESC
                LIMIT ?";
        
        $params[] = $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback to old schema
        $sql = "SELECT al.log_id, al.user_id, al.action, al.details,
                       al.ip_address, al.user_agent, al.created_at,
                       u.full_name, u.username, u.role
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.user_id
                $whereClause
                ORDER BY al.log_id DESC
                LIMIT ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get latest log_id for next poll
    $maxLogId = 0;
    if (!empty($logs)) {
        $maxLogId = max(array_column($logs, 'log_id'));
    } elseif ($lastId > 0) {
        $maxLogId = $lastId;
    } else {
        // Get current max ID
        $maxStmt = $pdo->query("SELECT MAX(log_id) as max_id FROM activity_logs");
        $maxLogId = $maxStmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
    }
    
    // Get today's stats
    $statsStmt = $pdo->query("
        SELECT 
            COUNT(*) as total_today,
            COUNT(DISTINCT user_id) as unique_users
        FROM activity_logs 
        WHERE DATE(created_at) = CURDATE()
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Format logs for response
    $formattedLogs = [];
    foreach ($logs as $log) {
        $formattedLogs[] = [
            'log_id' => (int)$log['log_id'],
            'user_id' => $log['user_id'] ? (int)$log['user_id'] : null,
            'user_name' => $log['full_name'] ?? 'System',
            'username' => $log['username'] ?? '',
            'role' => $log['role'] ?? '',
            'action' => $log['action'],
            'details' => $log['details'] ?? '',
            'ip_address' => $log['ip_address'] ?? '',
            'created_at' => $log['created_at'],
            'formatted_time' => date('M d, Y H:i:s', strtotime($log['created_at']))
        ];
    }
    
    echo json_encode([
        'success' => true,
        'logs' => $formattedLogs,
        'last_id' => (int)$maxLogId,
        'stats' => [
            'total_today' => (int)($stats['total_today'] ?? 0),
            'unique_users' => (int)($stats['unique_users'] ?? 0)
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Activity Logs API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'message' => $e->getMessage()]);
}
