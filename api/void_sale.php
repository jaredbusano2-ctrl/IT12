<?php
/**
 * Void Sale API Endpoint
 * POST /api/void_sale.php
 * SECURITY: Only cashier role can access
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';

// Security headers
setSecurityHeaders();

// Set JSON response header
header('Content-Type: application/json');

// CSRF token validation
validateCSRFToken();

// Check authentication
requireLogin();

// Check if user is cashier
$user = getCurrentUser();
if ($user['role'] !== 'cashier') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input['sale_id']) || !isset($input['admin_password']) || !isset($input['void_reason'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

$sale_id = intval($input['sale_id']);
$admin_password = $input['admin_password'];
$void_reason = trim($input['void_reason']);

// Validate inputs
if ($sale_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid sale ID']);
    exit();
}

if (strlen($void_reason) < 3 || strlen($void_reason) > 500) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Reason must be between 3 and 500 characters']);
    exit();
}

try {
    $pdo = getPDO();
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Get sale details using prepared statement
    $saleStmt = $pdo->prepare("SELECT s.* FROM sales s WHERE s.sale_id = ?");
    $saleStmt->execute([$sale_id]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sale) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Sale not found']);
        exit();
    }
    
    // Check if already voided
    if ($sale['status'] === 'voided') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This transaction is already voided']);
        exit();
    }
    
    // Verify admin password
    $adminStmt = $pdo->prepare("SELECT user_id, password FROM users WHERE role = 'admin' LIMIT 1");
    $adminStmt->execute();
    $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin || !password_verify($admin_password, $admin['password'])) {
        // Rate limiting - record failed attempt
        if (function_exists('recordAttempt')) {
            recordAttempt('void_sale', $_SERVER['REMOTE_ADDR']);
        }
        
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid admin password']);
        exit();
    }
    
    // Get all sale items
    $itemsStmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
    $itemsStmt->execute([$sale_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Restore inventory for each item
    foreach ($items as $item) {
        if ($item['product_id']) {
            $restoreStmt = $pdo->prepare("
                UPDATE products 
                SET stock_quantity = stock_quantity + ? 
                WHERE product_id = ?
            ");
            $restoreStmt->execute([$item['quantity'], $item['product_id']]);
        }
    }
    
    // Update sale status to voided
    $voidStmt = $pdo->prepare("
        UPDATE sales 
        SET status = 'voided', notes = ? 
        WHERE sale_id = ?
    ");
    $voidStmt->execute([$void_reason, $sale_id]);
    
    // Log the void action if activity logging is available
    if (function_exists('logActivity')) {
        logActivity(
            'void_sale',
            "Voided sale: Invoice {$sale['invoice_number']} | Amount: {$sale['total_amount']} | Reason: {$void_reason}",
            $user['user_id']
        );
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Sale voided successfully',
        'sale_id' => $sale_id,
        'invoice_number' => $sale['invoice_number'],
        'items_count' => count($items)
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Void sale error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error occurred']);
    exit();
}
