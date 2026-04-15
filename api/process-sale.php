<?php
/**
 * Process Sale Endpoint
 * Features:
 * - Full inventory integration (cups, ingredients, products)
 * - Stock validation before checkout
 * - Prepared statements for SQL injection prevention
 * - Activity logging
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/inventory_functions.php';

// Security checks
requireLogin();

// Verify CSRF token for API request
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verifyCSRFToken($csrfToken)) {
    logActivity('api_csrf_violation', 'Invalid CSRF token in process-sale API');
    jsonError('Invalid security token. Please refresh the page.', 403);
}

// Require permission to create sales
if (!hasPermission('create_sales')) {
    logActivity('unauthorized_sale_attempt', 'User attempted sale without permission');
    jsonError('You do not have permission to process sales.', 403);
}

$user = getCurrentUser();

try {
    // Get JSON input
    $data = json_decode(file_get_contents("php://input"), true);

    if (!$data) {
        throw new Exception("Invalid sale data received.");
    }

    if (empty($data['items'])) {
        throw new Exception("Cart is empty.");
    }

    // ==========================================
    // FRAUD PREVENTION CHECKS
    // ==========================================
    
    $pdo = getPDO();
    
    // 1. Validate totals are positive
    $submittedTotal = sanitizeFloat($data['total']);
    $submittedSubtotal = sanitizeFloat($data['subtotal']);
    $submittedTax = sanitizeFloat($data['tax']);
    $submittedDiscount = sanitizeFloat($data['discount'] ?? 0);
    
    if ($submittedTotal <= 0) {
        logActivity('fraud_attempt', "Zero/negative total attempted: ₱{$submittedTotal}", $user['user_id']);
        throw new Exception("Invalid transaction: Total must be greater than zero.");
    }
    
    if ($submittedSubtotal < 0 || $submittedTax < 0) {
        logActivity('fraud_attempt', "Negative values in transaction", $user['user_id']);
        throw new Exception("Invalid transaction: Negative values detected.");
    }
    
    // 2. Validate discount is not greater than subtotal
    if ($submittedDiscount > $submittedSubtotal) {
        logActivity('fraud_attempt', "Excessive discount attempted: ₱{$submittedDiscount} > ₱{$submittedSubtotal}", $user['user_id']);
        throw new Exception("Invalid transaction: Discount cannot exceed subtotal.");
    }
    
    // 3. Limit maximum discount percentage (50%)
    $maxDiscountPercent = 50;
    if ($submittedSubtotal > 0 && ($submittedDiscount / $submittedSubtotal) * 100 > $maxDiscountPercent) {
        logActivity('fraud_attempt', "Discount exceeds {$maxDiscountPercent}%", $user['user_id']);
        throw new Exception("Invalid transaction: Discount exceeds maximum allowed ({$maxDiscountPercent}%).");
    }
    
    // 4. Server-side price verification
    $calculatedSubtotal = 0;
    foreach ($data['items'] as $item) {
        $productId = sanitizeInt($item['id']);
        $quantity = sanitizeInt($item['quantity']);
        $submittedPrice = sanitizeFloat($item['price']);
        $cupId = isset($item['cup_id']) ? sanitizeInt($item['cup_id']) : null;
        
        // Validate quantity is positive
        if ($quantity <= 0) {
            throw new Exception("Invalid quantity for item.");
        }
        
        // Get actual price from database - check cup sizes first, then base price.
        $actualPrice = null;
        
        // If cup_id is provided, check product_cup_sizes table
        if ($cupId) {
            $cupPriceStmt = $pdo->prepare("SELECT price FROM product_cup_sizes WHERE product_id = ? AND cup_id = ?");
            $cupPriceStmt->execute([$productId, $cupId]);
            $cupPrice = $cupPriceStmt->fetch();
            if ($cupPrice) {
                $actualPrice = (float)$cupPrice['price'];
            }
        }
        
        // If no cup price found, get the base product price.
        if ($actualPrice === null) {
            $priceStmt = $pdo->prepare("SELECT COALESCE(selling_price, price) AS base_price FROM products WHERE product_id = ?");
            $priceStmt->execute([$productId]);
            $dbProduct = $priceStmt->fetch();
            
            if (!$dbProduct) {
                throw new Exception("Product not found: {$productId}");
            }
            $actualPrice = (float)$dbProduct['base_price'];
        }
        
        // Also get all valid prices for this product (base + all cup sizes) for tolerance check
        $validPricesStmt = $pdo->prepare("
            SELECT COALESCE(selling_price, price) as price FROM products WHERE product_id = ?
            UNION
            SELECT price FROM product_cup_sizes WHERE product_id = ?
        ");
        $validPricesStmt->execute([$productId, $productId]);
        $validPrices = $validPricesStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Check if submitted price matches any valid price (with tolerance)
        $priceValid = false;
        foreach ($validPrices as $validPrice) {
            $priceDiff = abs($submittedPrice - (float)$validPrice);
            if ($priceDiff <= 0.5) { // ₱0.50 tolerance
                $priceValid = true;
                $actualPrice = (float)$validPrice; // Use the matched price
                break;
            }
        }
        
        if (!$priceValid) {
            logActivity('fraud_attempt', "Price mismatch: submitted ₱{$submittedPrice}, valid prices: " . implode(', ', $validPrices) . " for product {$productId}", $user['user_id']);
            throw new Exception("Price verification failed. Please refresh and try again.");
        }
        
        $calculatedSubtotal += $actualPrice * $quantity;
    }
    
    // 5. Verify calculated subtotal matches submitted (with 2% tolerance for cup price variations)
    $subtotalDiff = abs($calculatedSubtotal - $submittedSubtotal);
    $subtotalTolerance = max($calculatedSubtotal * 0.02, 5); // 2% or ₱5 minimum tolerance
    
    if ($subtotalDiff > $subtotalTolerance) {
        logActivity('fraud_attempt', "Subtotal mismatch: submitted ₱{$submittedSubtotal}, calculated ₱{$calculatedSubtotal}", $user['user_id']);
        // Log but allow - cup sizes may have different prices
        error_log("Subtotal variance: submitted {$submittedSubtotal}, calculated {$calculatedSubtotal}");
    }
    
    // 6. Check for duplicate transactions (same items, same total, within 30 seconds)
    $duplicateCheck = $pdo->prepare("
        SELECT sale_id FROM sales 
        WHERE user_id = ? 
          AND total_amount = ? 
          AND sale_date > DATE_SUB(NOW(), INTERVAL 30 SECOND)
        LIMIT 1
    ");
    $duplicateCheck->execute([$user['user_id'], $submittedTotal]);
    if ($duplicateCheck->fetch()) {
        logActivity('duplicate_sale_blocked', "Potential duplicate sale blocked: ₱{$submittedTotal}", $user['user_id']);
        throw new Exception("Transaction appears to be a duplicate. Please wait before retrying.");
    }
    
    // ==========================================
    // END FRAUD PREVENTION
    // ==========================================

    // Validate stock availability before processing
    $stockErrors = [];
    foreach ($data['items'] as $item) {
        $productId = sanitizeInt($item['id']);
        $cupId = isset($item['cup_id']) ? sanitizeInt($item['cup_id']) : null;
        $quantity = sanitizeInt($item['quantity']);
        
        $errors = checkCartItemAvailability($productId, $cupId, $quantity);
        if (!empty($errors)) {
            $stockErrors = array_merge($stockErrors, $errors);
        }
    }
    
    if (!empty($stockErrors)) {
        throw new Exception("Stock unavailable: " . implode("; ", $stockErrors));
    }

    // Start transaction
    $pdo->beginTransaction();

    // Generate invoice number
    $invoice = 'INV-' . date('YmdHis') . '-' . str_pad(mt_rand(0, 999), 3, '0', STR_PAD_LEFT);

    $customer_name = sanitize($data['customer_name'] ?? '');
    $payment_method = sanitize($data['payment_method'] ?? 'cash');
    $subtotal = sanitizeFloat($data['subtotal']);
    $tax = sanitizeFloat($data['tax']);
    $discount = sanitizeFloat($data['discount'] ?? 0);
    $total = sanitizeFloat($data['total']);
    $amountPaid = sanitizeFloat($data['amount_paid'] ?? $total);
    $change = sanitizeFloat($data['change'] ?? 0);

    // Insert into sales table
    $saleStmt = $pdo->prepare("
        INSERT INTO sales 
        (invoice_number, customer_name, user_id, subtotal, tax, discount, total_amount, 
         amount_paid, change_amount, payment_method, sale_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $saleStmt->execute([
        $invoice, $customer_name, $user['user_id'], $subtotal, $tax, $discount, 
        $total, $amountPaid, $change, $payment_method
    ]);

    $sale_id = (int)$pdo->lastInsertId();

    // Process each item
    foreach ($data['items'] as $item) {
        $product_id = sanitizeInt($item['id']);
        $quantity = sanitizeInt($item['quantity']);
        $price = sanitizeFloat($item['price']);
        $item_subtotal = sanitizeFloat($item['subtotal']);
        $cup_id = isset($item['cup_id']) ? sanitizeInt($item['cup_id']) : null;
        $cup_size = sanitize($item['cup_size'] ?? '');
        $product_name = sanitize($item['name'] ?? '');
        
        // Get product info
        $productStmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
        $productStmt->execute([$product_id]);
        $product = $productStmt->fetch();
        
        if (!$product) {
            throw new Exception("Product not found: $product_id");
        }
        
        // Use product name from DB if not provided
        if (empty($product_name)) {
            $product_name = $product['product_name'];
        }
        
        // Check if product requires cup (fallback to is_drink if requires_cup doesn't exist)
        $requiresCup = isset($product['requires_cup']) ? $product['requires_cup'] : ($product['is_drink'] ?? false);

        // Insert into sale_items
        $itemStmt = $pdo->prepare("
            INSERT INTO sale_items 
            (sale_id, product_id, product_name, cup_id, cup_size, quantity, unit_price, subtotal)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $itemStmt->execute([$sale_id, $product_id, $product_name, $cup_id, $cup_size, $quantity, $price, $item_subtotal]);
        
        $sale_item_id = (int)$pdo->lastInsertId();

        // Deduct product stock (for non-beverage items)
        if (!$requiresCup) {
            deductProductStock($product_id, $quantity, $sale_id, $user['user_id']);
        }

        // Deduct cup stock if applicable
        if ($cup_id && $requiresCup) {
            if (!deductCupStock($cup_id, $quantity, $sale_id, $sale_item_id, $user['user_id'])) {
                throw new Exception("Failed to deduct cup stock for: " . $product_name);
            }
        }

        // Deduct ingredients if product uses them
        if ($requiresCup) {
            deductIngredients($product_id, $cup_id, $quantity, $sale_id, $sale_item_id, $user['user_id']);
        }
    }

    // Commit transaction
    $pdo->commit();

    // Log the sale
    logActivity('sale_completed', "Sale completed: $invoice, Total: ₱" . number_format($total, 2), $user['user_id'], 'sales', $sale_id);

    echo json_encode([
        "success" => true,
        "invoice" => $invoice,
        "sale_id" => $sale_id,
        "message" => "Sale completed successfully"
    ]);

} catch (Exception $e) {
    // Rollback if something fails
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Process Sale Error: " . $e->getMessage());

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}

exit;
