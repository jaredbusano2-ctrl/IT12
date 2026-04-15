<?php
/**
 * Get Sale Details API
 * Returns detailed information about a specific sale
 * 
 * Security:
 * - Requires login
 * - Uses prepared statements
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

requireLogin();

header('Content-Type: application/json');

$sale_id = sanitizeInt($_GET['sale_id'] ?? 0);

if ($sale_id <= 0) {
    jsonError('Invalid sale ID', 400);
}

try {
    $pdo = getPDO();
    
    // Get sale details using prepared statement
    $saleStmt = $pdo->prepare("
        SELECT s.*, u.full_name as cashier_name,
               DATE_FORMAT(s.sale_date, '%M %d, %Y %h:%i %p') as formatted_date
        FROM sales s 
        LEFT JOIN users u ON s.user_id = u.user_id 
        WHERE s.sale_id = ?
    ");
    $saleStmt->execute([$sale_id]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sale) {
        jsonError('Sale not found', 404);
    }
    
    // Get sale items using prepared statement
    $itemsStmt = $pdo->prepare("
        SELECT si.*, p.product_name as db_product_name
        FROM sale_items si
        LEFT JOIN products p ON si.product_id = p.product_id
        WHERE si.sale_id = ?
        ORDER BY si.sale_item_id ASC
    ");
    $itemsStmt->execute([$sale_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    jsonSuccess([
        'sale' => $sale,
        'items' => $items
    ], 'Sale details retrieved');
    
} catch (Exception $e) {
    error_log("Get Sale Details Error: " . $e->getMessage());
    jsonError('Failed to load sale details', 500);
}
?>
