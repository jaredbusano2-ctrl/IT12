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

// Validate session and user is logged in (API-safe: no redirects)
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    jsonError('Authentication required', 401);
}

// Basic session expiry check without triggering redirect-based logout
if (isset($_SESSION['login_time'])) {
    $maxInactivity = 8 * 60 * 60; // 8 hours
    if (time() - (int)$_SESSION['login_time'] > $maxInactivity) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        jsonError('Session expired. Please log in again.', 401);
    }
    $_SESSION['login_time'] = time();
}

$currentUser = getCurrentUser();

// Only cashiers can initiate voids (admin can authorize via password)
if (($currentUser['role'] ?? null) !== 'cashier') {
    logActivity('void_forbidden', 'Non-cashier attempted to initiate a void operation', $currentUser['user_id'] ?? null);
    jsonError('Only cashiers can void transactions.', 403);
}

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

if (!is_array($input)) {
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

        // Ownership enforcement: cashier can only void their own transactions
        try {
            $ownerRow = dbFetchOne(
                "SELECT s.user_id FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id WHERE si.sale_item_id = ?",
                [$saleItemId]
            );
        } catch (Exception $e) {
            error_log('void_item ownership check failed: ' . $e->getMessage());
            jsonError('Unable to validate sale item ownership', 500);
        }

        if (!$ownerRow) {
            jsonError('Sale item not found', 404);
        }

        $ownerUserId = (int)($ownerRow['user_id'] ?? 0);
        $currentUserId = (int)($currentUser['user_id'] ?? 0);
        if ($ownerUserId !== $currentUserId) {
            logActivity('void_forbidden', 'Cashier attempted to void a sale item not owned by them', $currentUserId);
            jsonError('You can only void your own transactions.', 403);
        }
        
        $result = voidSaleItem($saleItemId, $adminId, $currentUser['user_id'], $voidReason);
        break;
        
    case 'sale':
        // Void entire sale
        $saleId = sanitizeInt($input['sale_id'] ?? 0);
        
        if ($saleId <= 0) {
            jsonError('Invalid sale ID', 400);
        }

        // Ownership enforcement: cashier can only void their own transactions
        try {
            $ownerRow = dbFetchOne(
                "SELECT user_id FROM sales WHERE sale_id = ?",
                [$saleId]
            );
        } catch (Exception $e) {
            error_log('void_sale ownership check failed: ' . $e->getMessage());
            jsonError('Unable to validate sale ownership', 500);
        }

        if (!$ownerRow) {
            jsonError('Sale not found', 404);
        }

        $ownerUserId = (int)($ownerRow['user_id'] ?? 0);
        $currentUserId = (int)($currentUser['user_id'] ?? 0);
        if ($ownerUserId !== $currentUserId) {
            logActivity('void_forbidden', 'Cashier attempted to void a sale not owned by them', $currentUserId);
            jsonError('You can only void your own transactions.', 403);
        }
        
        $result = voidEntireSale($saleId, $adminId, $currentUser['user_id'], $voidReason);
        break;
        
    default:
        jsonError('Invalid void type', 400);
}

// Return response
if ($result['success']) {
    // Defense-grade audit trail (optional table).
    // If your DB doesn't have audit_logs, this safely no-ops.
    try {
        $pdo = getPDO();
        $targetDesc = '';
        if ($voidType === 'sale') {
            $targetDesc = 'Voided sale ID: ' . (int)($input['sale_id'] ?? 0);
        } elseif ($voidType === 'item') {
            $targetDesc = 'Voided sale item ID: ' . (int)($input['sale_item_id'] ?? 0);
        } elseif ($voidType === 'cart_item') {
            $targetDesc = 'Voided cart item';
        } else {
            $targetDesc = 'Voided cart';
        }

        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'VOID_TRANSACTION', ?)");
        $stmt->execute([(int)($currentUser['user_id'] ?? 0), $targetDesc]);
    } catch (Exception $e) {
        // Intentionally ignore to avoid breaking the void flow
        error_log('audit_logs insert skipped: ' . $e->getMessage());
    }

    jsonSuccess($result, $result['message']);
} else {
    jsonError($result['error'], 400);
}
