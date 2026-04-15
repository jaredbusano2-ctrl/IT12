<?php
/**
 * Void Operations Functions
 * Handles secure void operations with admin authorization and inventory restoration
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/inventory_functions.php';

/**
 * Verify admin credentials for void authorization
 * Returns admin user_id if valid, null otherwise
 * 
 * Security: 
 * - Limited to 3 failed attempts
 * - 30-second lockout after 3 failures
 * - Uses password_verify for secure password comparison
 * - Resets attempts on successful authentication
 */
function verifyAdminForVoid(string $password): ?int {
    try {
        $pdo = getPDO();
        $clientIP = getClientIP();
        
        // Check if user is locked out from void attempts
        // checkVoidRateLimit returns FALSE if locked out, TRUE if can proceed
        if (!checkVoidRateLimit($clientIP)) {
            return null; // Too many attempts - user is locked out
        }
        
        // Get all admin users
        // First try with status check, if that fails try without (in case status column doesn't exist)
        try {
            $stmt = $pdo->prepare("SELECT user_id, username, password FROM users WHERE role = 'admin' AND (status = 'active' OR status IS NULL OR status = '')");
            $stmt->execute();
            $admins = $stmt->fetchAll();
        } catch (Exception $e) {
            // Status column might not exist, try without it
            $stmt = $pdo->prepare("SELECT user_id, username, password FROM users WHERE role = 'admin'");
            $stmt->execute();
            $admins = $stmt->fetchAll();
        }
        
        // If still no admins found, try simple query without status
        if (empty($admins)) {
            $stmt = $pdo->prepare("SELECT user_id, username, password FROM users WHERE role = 'admin'");
            $stmt->execute();
            $admins = $stmt->fetchAll();
        }
        
        if (empty($admins)) {
            error_log("No admin users found for void authorization");
            return null;
        }
        
        foreach ($admins as $admin) {
            if (password_verify($password, $admin['password'])) {
                // Record successful attempt and reset rate limit for this IP
                resetVoidAttempts($clientIP);
                
                // Log successful void authorization
                logActivity(
                    'void_auth_success',
                    "Admin {$admin['username']} authorized void operation",
                    (int)$admin['user_id'],
                    'users',
                    (int)$admin['user_id']
                );
                
                return (int)$admin['user_id'];
            }
        }
        
        // Record failed attempt
        $attemptResult = recordVoidFailedAttempt($clientIP);
        
        // Log failed attempt
        logActivity(
            'void_auth_failed',
            "Failed void authorization attempt from IP: $clientIP. Remaining attempts: {$attemptResult['remaining']}",
            null,
            null,
            null
        );
        
        return null;
        
    } catch (Exception $e) {
        error_log("Verify Admin Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if IP is locked out from void attempts
 * Returns TRUE if can proceed, FALSE if locked out
 */
function checkVoidRateLimit(string $clientIP): bool {
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("
            SELECT locked_until 
            FROM login_attempts 
            WHERE identifier = ? AND attempt_type = 'void' AND locked_until > NOW()
            LIMIT 1
        ");
        $stmt->execute([$clientIP]);
        $result = $stmt->fetch();
        
        // If there's a lock record and it hasn't expired, user is locked out
        return $result === false;
    } catch (Exception $e) {
        error_log("Check Void Rate Limit Error: " . $e->getMessage());
        return true; // Allow on error to prevent lockout issues
    }
}

/**
 * Get remaining lockout time for void attempts
 */
function getVoidLockoutRemaining(string $clientIP): int {
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("
            SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) as remaining 
            FROM login_attempts 
            WHERE identifier = ? AND attempt_type = 'void' AND locked_until > NOW()
            LIMIT 1
        ");
        $stmt->execute([$clientIP]);
        $result = $stmt->fetch();
        
        return $result ? max(0, (int)$result['remaining']) : 0;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Record a failed void attempt
 * Locks out after 3 attempts for 30 seconds
 */
function recordVoidFailedAttempt(string $clientIP): array {
    $maxAttempts = 3;  // Lock after 3 failed attempts
    $lockoutDuration = 30; // 30 seconds lockout
    
    try {
        $pdo = getPDO();
        
        // Check existing attempts for this IP
        $stmt = $pdo->prepare("
            SELECT id, attempts FROM login_attempts 
            WHERE identifier = ? AND attempt_type = 'void'
            LIMIT 1
        ");
        $stmt->execute([$clientIP]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $newAttempts = $existing['attempts'] + 1;
            $lockedUntil = null;
            
            if ($newAttempts >= $maxAttempts) {
                $lockedUntil = date('Y-m-d H:i:s', time() + $lockoutDuration);
            }
            
            $update = $pdo->prepare("
                UPDATE login_attempts 
                SET attempts = ?, last_attempt = NOW(), locked_until = ?
                WHERE id = ?
            ");
            $update->execute([$newAttempts, $lockedUntil, $existing['id']]);
            
            $remaining = max(0, $maxAttempts - $newAttempts);
            return [
                'locked' => $newAttempts >= $maxAttempts,
                'attempts' => $newAttempts,
                'remaining' => $remaining,
                'lockout_seconds' => $lockedUntil ? $lockoutDuration : 0
            ];
        } else {
            // First attempt - create record
            $insert = $pdo->prepare("
                INSERT INTO login_attempts (attempt_type, identifier, attempts, last_attempt)
                VALUES ('void', ?, 1, NOW())
            ");
            $insert->execute([$clientIP]);
            
            return [
                'locked' => false,
                'attempts' => 1,
                'remaining' => $maxAttempts - 1,
                'lockout_seconds' => 0
            ];
        }
    } catch (Exception $e) {
        error_log("Record Void Failed Attempt Error: " . $e->getMessage());
        return ['locked' => false, 'attempts' => 0, 'remaining' => $maxAttempts];
    }
}

/**
 * Reset void attempts after successful authorization
 */
function resetVoidAttempts(string $clientIP): void {
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ? AND attempt_type = 'void'");
        $stmt->execute([$clientIP]);
    } catch (Exception $e) {
        error_log("Reset Void Attempts Error: " . $e->getMessage());
    }
}

/**
 * Void a single sale item
 * Restores cups, ingredients, and product stock
 */
function voidSaleItem(int $saleItemId, int $adminId, int $requesterId, string $reason): array {
    try {
        $pdo = getPDO();
        
        // Get the sale item with product info
        $stmt = $pdo->prepare("
            SELECT si.*, s.invoice_number, s.user_id as cashier_id, p.requires_cup, p.product_name
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.sale_id
            LEFT JOIN products p ON si.product_id = p.product_id
            WHERE si.sale_item_id = ?
        ");
        $stmt->execute([$saleItemId]);
        $saleItem = $stmt->fetch();
        
        if (!$saleItem) {
            return ['success' => false, 'error' => 'Sale item not found'];
        }
        
        if ($saleItem['is_voided']) {
            return ['success' => false, 'error' => 'Item is already voided'];
        }
        
        // Begin transaction
        $pdo->beginTransaction();
        
        // Mark item as voided
        $updateStmt = $pdo->prepare("
            UPDATE sale_items 
            SET is_voided = 1, voided_at = NOW(), voided_by = ?, void_reason = ?
            WHERE sale_item_id = ?
        ");
        $updateStmt->execute([$adminId, $reason, $saleItemId]);
        
        // Restore inventory
        $cupsRestored = false;
        $ingredientsRestored = false;
        
        // Restore product stock
        if ($saleItem['product_id']) {
            restoreProductStock($saleItem['product_id'], $saleItem['quantity'], $saleItem['sale_id'], $adminId);
        }
        
        // Restore cup stock if applicable
        if ($saleItem['cup_id']) {
            restoreCupStock($saleItem['cup_id'], $saleItem['quantity'], $saleItem['sale_id'], $saleItemId, $adminId);
            $cupsRestored = true;
        }
        
        // Restore ingredients if product requires them
        if ($saleItem['product_id'] && $saleItem['requires_cup']) {
            restoreIngredients($saleItem['product_id'], $saleItem['cup_id'], $saleItem['quantity'], $saleItem['sale_id'], $saleItemId, $adminId);
            $ingredientsRestored = true;
        }
        
        // Record in voided_orders table
        try {
            $voidStmt = $pdo->prepare("
                INSERT INTO voided_orders 
                (void_type, sale_id, sale_item_id, void_reason, voided_by, authorized_by, 
                 original_total, cups_restored, ingredients_restored)
                VALUES ('item', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $voidStmt->execute([
                $saleItem['sale_id'],
                $saleItemId,
                $reason,
                $requesterId,
                $adminId,
                $saleItem['subtotal'],
                $cupsRestored ? 1 : 0,
                $ingredientsRestored ? 1 : 0
            ]);
        } catch (Exception $e) {
            error_log("voided_orders insert failed: " . $e->getMessage());
        }
        
        // Also record in void_logs table for detailed audit trail (optional)
        try {
            $clientIP = getClientIP();
            $voidLogStmt = $pdo->prepare("
                INSERT INTO void_logs 
                (order_id, product_id, cashier_id, admin_id, void_reason, void_type, 
                 product_name, quantity, unit_price, total_amount, cup_size, 
                 cups_restored, ingredients_restored, ip_address)
                VALUES (?, ?, ?, ?, ?, 'item', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $voidLogStmt->execute([
                $saleItem['sale_id'],
                $saleItem['product_id'],
                $requesterId,
                $adminId,
                $reason,
                $saleItem['product_name'],
                $saleItem['quantity'],
                $saleItem['unit_price'],
                $saleItem['subtotal'],
                $saleItem['cup_size'],
                $cupsRestored ? 1 : 0,
                $ingredientsRestored ? 1 : 0,
                $clientIP
            ]);
        } catch (Exception $e) {
            error_log("void_logs insert failed: " . $e->getMessage());
        }
        
        // Update sale totals
        updateSaleTotals($saleItem['sale_id']);
        
        $pdo->commit();
        
        // Log activity
        logActivity(
            'item_voided',
            "Voided item: {$saleItem['product_name']} x{$saleItem['quantity']} from invoice {$saleItem['invoice_number']}. Reason: $reason",
            $adminId,
            'sale_items',
            $saleItemId
        );
        
        return [
            'success' => true,
            'message' => 'Item voided successfully',
            'cups_restored' => $cupsRestored,
            'ingredients_restored' => $ingredientsRestored
        ];
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Void Sale Item Error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error during void operation'];
    }
}

/**
 * Void entire sale (all items)
 */
function voidEntireSale(int $saleId, int $adminId, int $requesterId, string $reason): array {
    try {
        $pdo = getPDO();
        
        // Get sale info
        $saleStmt = $pdo->prepare("SELECT * FROM sales WHERE sale_id = ?");
        $saleStmt->execute([$saleId]);
        $sale = $saleStmt->fetch();
        
        if (!$sale) {
            return ['success' => false, 'error' => 'Sale not found'];
        }
        
        if ($sale['status'] === 'voided') {
            return ['success' => false, 'error' => 'Sale is already voided'];
        }
        
        // Get all non-voided items
        $itemsStmt = $pdo->prepare("
            SELECT si.*, p.requires_cup, p.product_name
            FROM sale_items si
            LEFT JOIN products p ON si.product_id = p.product_id
            WHERE si.sale_id = ? AND si.is_voided = 0
        ");
        $itemsStmt->execute([$saleId]);
        $items = $itemsStmt->fetchAll();
        
        if (empty($items)) {
            return ['success' => false, 'error' => 'No items to void'];
        }
        
        // Begin transaction
        $pdo->beginTransaction();
        
        $cupsRestored = false;
        $ingredientsRestored = false;
        
        // Void each item
        $clientIP = getClientIP();
        foreach ($items as $item) {
            // Mark as voided
            $updateStmt = $pdo->prepare("
                UPDATE sale_items 
                SET is_voided = 1, voided_at = NOW(), voided_by = ?, void_reason = ?
                WHERE sale_item_id = ?
            ");
            $updateStmt->execute([$adminId, $reason, $item['sale_item_id']]);
            
            // Restore product stock
            if ($item['product_id']) {
                restoreProductStock($item['product_id'], $item['quantity'], $saleId, $adminId);
            }
            
            // Restore cups
            $itemCupsRestored = false;
            if ($item['cup_id']) {
                restoreCupStock($item['cup_id'], $item['quantity'], $saleId, $item['sale_item_id'], $adminId);
                $itemCupsRestored = true;
                $cupsRestored = true;
            }
            
            // Restore ingredients
            $itemIngredientsRestored = false;
            if ($item['product_id'] && $item['requires_cup']) {
                restoreIngredients($item['product_id'], $item['cup_id'], $item['quantity'], $saleId, $item['sale_item_id'], $adminId);
                $itemIngredientsRestored = true;
                $ingredientsRestored = true;
            }
            
            // Record in voided_orders
            try {
                $voidStmt = $pdo->prepare("
                    INSERT INTO voided_orders 
                    (void_type, sale_id, sale_item_id, void_reason, voided_by, authorized_by,
                     original_total, cups_restored, ingredients_restored)
                    VALUES ('sale', ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $voidStmt->execute([
                    $saleId,
                    $item['sale_item_id'],
                    $reason,
                    $requesterId,
                    $adminId,
                    $item['subtotal'],
                    $itemCupsRestored ? 1 : 0,
                    $itemIngredientsRestored ? 1 : 0
                ]);
            } catch (Exception $e) {
                error_log("voided_orders insert failed: " . $e->getMessage());
            }
            
            // Also record in void_logs table for detailed audit trail (optional)
            try {
                $voidLogStmt = $pdo->prepare("
                    INSERT INTO void_logs 
                    (order_id, product_id, cashier_id, admin_id, void_reason, void_type, 
                     product_name, quantity, unit_price, total_amount, cup_size, 
                     cups_restored, ingredients_restored, ip_address)
                    VALUES (?, ?, ?, ?, ?, 'sale', ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $voidLogStmt->execute([
                    $saleId,
                    $item['product_id'],
                    $requesterId,
                    $adminId,
                    $reason,
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['subtotal'],
                    $item['cup_size'],
                    $itemCupsRestored ? 1 : 0,
                    $itemIngredientsRestored ? 1 : 0,
                    $clientIP
                ]);
            } catch (Exception $e) {
                error_log("void_logs insert failed: " . $e->getMessage());
            }
        }
        
        // Update sale status
        $statusStmt = $pdo->prepare("UPDATE sales SET status = 'voided' WHERE sale_id = ?");
        $statusStmt->execute([$saleId]);
        
        $pdo->commit();
        
        // Log activity
        logActivity(
            'sale_voided',
            "Voided entire sale: {$sale['invoice_number']}. Reason: $reason",
            $adminId,
            'sales',
            $saleId
        );
        
        // Check for high-value void alert
        if (function_exists('checkHighValueAlert')) {
            $saleTotal = (float) $sale['total_amount'];
            checkHighValueAlert(
                $saleTotal,
                'sale_void',
                "Voided entire sale {$sale['invoice_number']} with " . count($items) . " items. Reason: $reason",
                $requesterId
            );
        }
        
        return [
            'success' => true,
            'message' => 'Sale voided successfully',
            'items_voided' => count($items),
            'cups_restored' => $cupsRestored,
            'ingredients_restored' => $ingredientsRestored
        ];
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Void Entire Sale Error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error during void operation'];
    }
}

/**
 * Void cart before checkout (no sale record yet)
 * Records the audit trail
 */
function voidCart(array $cartItems, int $adminId, int $requesterId, string $reason, float $totalAmount = 0): array {
    try {
        $pdo = getPDO();
        
        $voidId = 0;
        
        // Try to record in sale_voids table (handle different column structures)
        try {
            $cartJson = json_encode($cartItems);
            
            // Try with authorized_by column first
            $stmt = $pdo->prepare("
                INSERT INTO sale_voids 
                (voided_by, authorized_by, void_reason, total_amount, void_type)
                VALUES (?, ?, ?, ?, 'cart')
            ");
            $stmt->execute([$requesterId, $adminId, $reason, $totalAmount]);
            $voidId = $pdo->lastInsertId();
        } catch (Exception $e) {
            // Try alternative column structure
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO sale_voids 
                    (voided_by, void_reason, total_amount)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$adminId, $reason, $totalAmount]);
                $voidId = $pdo->lastInsertId();
            } catch (Exception $e2) {
                // Table might not exist or have different structure - log but continue
                error_log("sale_voids insert failed: " . $e2->getMessage());
            }
        }
        
        // Try to record in void_logs table (optional - table might not exist)
        try {
            $clientIP = getClientIP();
            foreach ($cartItems as $item) {
                $productId = isset($item['product_id']) ? (int)$item['product_id'] : null;
                $productName = $item['product_name'] ?? $item['name'] ?? 'Unknown Product';
                $quantity = (int)($item['quantity'] ?? 1);
                $unitPrice = (float)($item['price'] ?? 0);
                $itemTotal = (float)($item['subtotal'] ?? ($unitPrice * $quantity));
                $cupSize = $item['cup_size'] ?? null;
                
                $logStmt = $pdo->prepare("
                    INSERT INTO void_logs 
                    (order_id, product_id, cashier_id, admin_id, void_reason, void_type, 
                     product_name, quantity, unit_price, total_amount, cup_size, ip_address)
                    VALUES (NULL, ?, ?, ?, ?, 'cart', ?, ?, ?, ?, ?, ?)
                ");
                $logStmt->execute([
                    $productId,
                    $requesterId,
                    $adminId,
                    $reason,
                    $productName,
                    $quantity,
                    $unitPrice,
                    $itemTotal,
                    $cupSize,
                    $clientIP
                ]);
            }
        } catch (Exception $e) {
            // void_logs table might not exist - that's OK, just log it
            error_log("void_logs insert skipped (table may not exist): " . $e->getMessage());
        }
        
        // Log activity
        logActivity(
            'cart_voided',
            "Voided cart with " . count($cartItems) . " items. Total: ₱" . number_format($totalAmount, 2) . ". Reason: $reason",
            $adminId,
            'sale_voids',
            (int)$voidId
        );
        
        // Check for high-value void alert
        if (function_exists('checkHighValueAlert')) {
            $itemNames = array_map(function($item) {
                return ($item['product_name'] ?? $item['name'] ?? 'Unknown') . ' x' . ($item['quantity'] ?? 1);
            }, $cartItems);
            checkHighValueAlert(
                $totalAmount,
                'cart_void',
                "Cart void with items: " . implode(', ', $itemNames) . ". Reason: $reason",
                $requesterId
            );
        }
        
        return [
            'success' => true,
            'message' => 'Cart voided successfully',
            'void_id' => $voidId
        ];
        
    } catch (Exception $e) {
        error_log("Void Cart Error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error during void operation: ' . $e->getMessage()];
    }
}

/**
 * Update sale totals after voiding items
 */
function updateSaleTotals(int $saleId): bool {
    try {
        $pdo = getPDO();
        
        // Calculate new totals from non-voided items
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(subtotal), 0) as new_subtotal
            FROM sale_items 
            WHERE sale_id = ? AND is_voided = 0
        ");
        $stmt->execute([$saleId]);
        $result = $stmt->fetch();
        
        $newSubtotal = (float)$result['new_subtotal'];
        
        // Get sale tax rate
        $saleStmt = $pdo->prepare("SELECT tax_rate FROM sales WHERE sale_id = ?");
        $saleStmt->execute([$saleId]);
        $sale = $saleStmt->fetch();
        $taxRate = $sale['tax_rate'] ?? 12.00;
        
        $newTax = $newSubtotal * ($taxRate / 100);
        $newTotal = $newSubtotal + $newTax;
        
        // Update sale
        $updateStmt = $pdo->prepare("
            UPDATE sales 
            SET subtotal = ?, tax = ?, total_amount = ?
            WHERE sale_id = ?
        ");
        $updateStmt->execute([$newSubtotal, $newTax, $newTotal, $saleId]);
        
        // Check if all items are voided
        $checkStmt = $pdo->prepare("SELECT COUNT(*) as remaining FROM sale_items WHERE sale_id = ? AND is_voided = 0");
        $checkStmt->execute([$saleId]);
        $check = $checkStmt->fetch();
        
        if ($check['remaining'] == 0) {
            $statusStmt = $pdo->prepare("UPDATE sales SET status = 'voided' WHERE sale_id = ?");
            $statusStmt->execute([$saleId]);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Update Sale Totals Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get voided orders with pagination (for admin page)
 */
function getVoidedOrders(int $page = 1, int $limit = 5, ?string $dateFilter = null, ?string $search = null): array {
    $offset = ($page - 1) * $limit;
    
    $sql = "SELECT vo.*, 
            ua.full_name as admin_name, 
            uc.full_name as cashier_name,
            s.invoice_number,
            si.product_name,
            si.cup_size,
            si.quantity,
            si.unit_price,
            si.subtotal as total_amount,
            vo.created_at as voided_at
            FROM voided_orders vo
            LEFT JOIN users ua ON vo.authorized_by = ua.user_id
            LEFT JOIN users uc ON vo.voided_by = uc.user_id
            LEFT JOIN sale_items si ON vo.sale_item_id = si.sale_item_id
            LEFT JOIN sales s ON vo.sale_id = s.sale_id
            WHERE vo.void_type IN ('item', 'sale')";
    
    $params = [];
    
    if ($dateFilter) {
        $sql .= " AND DATE(vo.created_at) = ?";
        $params[] = $dateFilter;
    }
    
    if ($search) {
        $sql .= " AND (vo.void_reason LIKE ? OR si.product_name LIKE ? OR s.invoice_number LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $sql .= " ORDER BY vo.created_at DESC LIMIT $limit OFFSET $offset";
    
    return dbFetchAll($sql, $params);
}

/**
 * Get cart voids (pre-checkout cancellations) with pagination
 */
function getCartVoids(int $page = 1, int $limit = 5, ?string $dateFilter = null): array {
    $offset = ($page - 1) * $limit;
    
    $sql = "SELECT sv.*, 
            ua.full_name as admin_name, 
            ur.full_name as requester_name
            FROM sale_voids sv
            LEFT JOIN users ua ON sv.authorized_by = ua.user_id
            LEFT JOIN users ur ON sv.voided_by = ur.user_id
            WHERE 1=1";
    
    $params = [];
    
    if ($dateFilter) {
        $sql .= " AND DATE(sv.created_at) = ?";
        $params[] = $dateFilter;
    }
    
    $sql .= " ORDER BY sv.created_at DESC LIMIT $limit OFFSET $offset";
    
    return dbFetchAll($sql, $params);
}

/**
 * Count voided orders for pagination
 */
function countVoidedOrders(?string $dateFilter = null, ?string $search = null): int {
    $sql = "SELECT COUNT(*) as total FROM voided_orders vo
            LEFT JOIN sale_items si ON vo.sale_item_id = si.sale_item_id
            LEFT JOIN sales s ON vo.sale_id = s.sale_id
            WHERE vo.void_type IN ('item', 'sale')";
    $params = [];
    
    if ($dateFilter) {
        $sql .= " AND DATE(vo.created_at) = ?";
        $params[] = $dateFilter;
    }
    
    if ($search) {
        $sql .= " AND (vo.void_reason LIKE ? OR si.product_name LIKE ? OR s.invoice_number LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $result = dbFetchOne($sql, $params);
    return $result['total'] ?? 0;
}

/**
 * Count cart voids for pagination
 */
function countCartVoids(?string $dateFilter = null): int {
    $sql = "SELECT COUNT(*) as total FROM sale_voids WHERE 1=1";
    $params = [];
    
    if ($dateFilter) {
        $sql .= " AND DATE(created_at) = ?";
        $params[] = $dateFilter;
    }
    
    $result = dbFetchOne($sql, $params);
    return $result['total'] ?? 0;
}

/**
 * Get void statistics for dashboard
 */
function getVoidStatistics(?string $period = 'today'): array {
    $dateCondition = "";
    
    switch ($period) {
        case 'today':
            $dateCondition = "DATE(created_at) = CURDATE()";
            break;
        case 'week':
            $dateCondition = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $dateCondition = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        default:
            $dateCondition = "1=1";
    }
    
    // Count voided items
    $itemsSql = "SELECT COUNT(*) as count, COALESCE(SUM(original_total), 0) as amount 
                 FROM voided_orders WHERE $dateCondition";
    $itemsResult = dbFetchOne($itemsSql);
    
    // Count cart voids (use voided_orders with void_type = 'cart')
    $cartsSql = "SELECT COUNT(*) as count, COALESCE(SUM(original_total), 0) as amount 
                 FROM voided_orders WHERE void_type = 'cart' AND $dateCondition";
    $cartsResult = dbFetchOne($cartsSql);
    
    return [
        'voided_items' => [
            'count' => $itemsResult['count'] ?? 0,
            'amount' => $itemsResult['amount'] ?? 0
        ],
        'voided_carts' => [
            'count' => $cartsResult['count'] ?? 0,
            'amount' => $cartsResult['amount'] ?? 0
        ],
        'total_count' => ($itemsResult['count'] ?? 0) + ($cartsResult['count'] ?? 0),
        'total_amount' => ($itemsResult['amount'] ?? 0) + ($cartsResult['amount'] ?? 0)
    ];
}
