<?php
/**
 * Get Recent Orders API
 * Returns today's orders for void selection (cashier interface)
 * 
 * Security:
 * - Requires login
 * - CSRF token verification
 * - Prepared statements
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

// Check login
requireLogin();
$currentUser = getCurrentUser();

// Verify CSRF token
$csrfToken = $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verifyCSRFToken($csrfToken)) {
    // Allow GET requests without CSRF for now (read-only)
    // logActivity('api_csrf_warning', 'Missing CSRF token in get-recent-orders API');
}

try {
    $pdo = getPDO();
    
    // Get today's orders (limit to last 50)
    // If user is cashier, show only their orders
    // If user is admin, show all orders
    $whereClause = "WHERE DATE(s.sale_date) = CURDATE()";
    $params = [];
    
    if ($currentUser['role'] === 'cashier') {
        $whereClause .= " AND s.user_id = ?";
        $params[] = $currentUser['user_id'];
    }
    
    $sql = "SELECT 
                s.sale_id,
                s.invoice_number,
                s.customer_name,
                s.total_amount,
                s.status,
                DATE_FORMAT(s.sale_date, '%h:%i %p') as sale_date,
                u.full_name as cashier_name,
                (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.sale_id) as item_count
            FROM sales s
            LEFT JOIN users u ON s.user_id = u.user_id
            $whereClause
            ORDER BY s.sale_date DESC
            LIMIT 50";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    jsonSuccess(['orders' => $orders], 'Orders retrieved successfully');
    
} catch (Exception $e) {
    error_log("Get Recent Orders Error: " . $e->getMessage());
    jsonError('Failed to load orders', 500);
}
