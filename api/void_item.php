<?php
/**
 * Secure Void Item Endpoint
 * Purpose: Handle authorized void requests with admin password verification
 * Features:
 * - Admin password verification using password_verify()
 * - Rate limiting to prevent brute force attacks
 * - Full inventory restoration (cups, ingredients, product stock)
 * - Audit trail logging
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/void_functions.php';

// Set JSON response header
header('Content-Type: application/json');

// Validate session and user is logged in
requireLogin();
$currentUser = getCurrentUser();

// Verify CSRF token for API request
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verifyCSRFToken($csrfToken)) {
    logActivity('api_csrf_violation', 'Invalid CSRF token in void API');
    jsonError('Invalid security token. Please refresh the page.', 403);
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    jsonError('Invalid JSON input', 400);
}

// Determine void type
$voidType = $input['void_type'] ?? 'cart'; // 'cart', 'item', 'sale'
$adminPassword = $input['admin_password'] ?? '';
$voidReason = sanitize($input['void_reason'] ?? '');

// Validate required fields
if (empty($adminPassword)) {
    jsonError('Admin password is required', 400);
}

if (empty($voidReason)) {
    jsonError('Void reason is required', 400);
}

if (strlen($voidReason) > 500) {
    jsonError('Void reason is too long (max 500 characters)', 400);
}

// Get client IP for rate limiting
$clientIP = getClientIP();

// Check if user is locked out from void attempts
if (!checkVoidRateLimit($clientIP)) {
    $lockoutRemaining = getVoidLockoutRemaining($clientIP);
    logActivity('void_rate_limited', 'Void attempt blocked - IP locked out: ' . $clientIP, $currentUser['user_id']);
    jsonError("Too many failed attempts. Please wait {$lockoutRemaining} seconds before trying again.", 429);
}

// Verify admin credentials
$adminId = verifyAdminForVoid($adminPassword);

if (!$adminId) {
    // Get remaining attempts for error message
    $clientIP = getClientIP();
    
    // Check if now locked out after this attempt
    if (!checkVoidRateLimit($clientIP)) {
        $lockoutRemaining = getVoidLockoutRemaining($clientIP);
        jsonError("Too many failed attempts. Please wait {$lockoutRemaining} seconds before trying again.", 429);
    }
    
    jsonError('Invalid admin password', 401);
}

// Process based on void type
$result = [];

switch ($voidType) {
    case 'cart':
        // Void cart before checkout (no sale record yet)
        $cartItems = $input['cart_items'] ?? [];
        $totalAmount = sanitizeFloat($input['total_amount'] ?? 0);
        
        if (empty($cartItems)) {
            jsonError('Cart is empty', 400);
        }
        
        $result = voidCart($cartItems, $adminId, $currentUser['user_id'], $voidReason, $totalAmount);
        break;
    
    case 'cart_item':
        // Void single item from cart (before checkout)
        $item = $input['item'] ?? null;
        
        if (empty($item)) {
            jsonError('Item data is required', 400);
        }
        
        // Wrap single item in array and use voidCart function
        $itemAmount = sanitizeFloat($item['subtotal'] ?? 0);
        $result = voidCart([$item], $adminId, $currentUser['user_id'], $voidReason, $itemAmount);
        break;
        
    case 'item':
        // Void single sale item
        $saleItemId = sanitizeInt($input['sale_item_id'] ?? 0);
        
        if ($saleItemId <= 0) {
            jsonError('Invalid sale item ID', 400);
        }
        
        $result = voidSaleItem($saleItemId, $adminId, $currentUser['user_id'], $voidReason);
        break;
        
    case 'sale':
        // Void entire sale
        $saleId = sanitizeInt($input['sale_id'] ?? 0);
        
        if ($saleId <= 0) {
            jsonError('Invalid sale ID', 400);
        }
        
        $result = voidEntireSale($saleId, $adminId, $currentUser['user_id'], $voidReason);
        break;
        
    default:
        jsonError('Invalid void type', 400);
}

// Return response
if ($result['success']) {
    jsonSuccess($result, $result['message']);
} else {
    jsonError($result['error'], 400);
}
