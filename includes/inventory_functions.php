<?php
/**
 * Inventory Management Functions
 * Handles cup inventory, ingredient tracking, and stock movements
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

// ==========================================
// CUP INVENTORY FUNCTIONS
// ==========================================

/**
 * Get all cup inventory with low stock indicator
 */
function getCupInventory(): array {
    $sql = "SELECT *, 
            CASE WHEN current_stock <= reorder_level THEN 1 ELSE 0 END as is_low_stock
            FROM cup_inventory 
            WHERE status = 'active'
            ORDER BY cup_size";
    return dbFetchAll($sql);
}

/**
 * Get single cup by ID
 */
function getCupById(int $cupId): ?array {
    return dbFetchOne("SELECT * FROM cup_inventory WHERE cup_id = ?", [$cupId]);
}

/**
 * Get cup by size name
 */
function getCupBySize(string $size): ?array {
    return dbFetchOne("SELECT * FROM cup_inventory WHERE cup_size = ?", [$size]);
}

/**
 * Check if cup stock is available
 */
function checkCupStock(int $cupId, int $quantity): bool {
    $cup = getCupById($cupId);
    return $cup && $cup['current_stock'] >= $quantity;
}

/**
 * Deduct cup from inventory (on sale)
 */
function deductCupStock(int $cupId, int $quantity, ?int $saleId = null, ?int $saleItemId = null, ?int $userId = null): bool {
    try {
        $pdo = getPDO();
        
        // Update stock
        $sql = "UPDATE cup_inventory 
                SET current_stock = current_stock - ? 
                WHERE cup_id = ? AND current_stock >= ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$quantity, $cupId, $quantity]);
        
        if ($stmt->rowCount() === 0) {
            return false; // Not enough stock
        }
        
        // Log movement
        $moveSql = "INSERT INTO cup_movements 
                    (cup_id, movement_type, quantity, sale_id, sale_item_id, user_id, notes) 
                    VALUES (?, 'sale', ?, ?, ?, ?, 'Cup used for sale')";
        $moveStmt = $pdo->prepare($moveSql);
        $moveStmt->execute([$cupId, $quantity, $saleId, $saleItemId, $userId]);
        
        return true;
    } catch (Exception $e) {
        error_log("Deduct Cup Stock Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Restore cups to inventory (on void)
 */
function restoreCupStock(int $cupId, int $quantity, ?int $saleId = null, ?int $saleItemId = null, ?int $userId = null): bool {
    try {
        $pdo = getPDO();
        
        // Restore stock
        $sql = "UPDATE cup_inventory SET current_stock = current_stock + ? WHERE cup_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$quantity, $cupId]);
        
        // Log movement
        $moveSql = "INSERT INTO cup_movements 
                    (cup_id, movement_type, quantity, sale_id, sale_item_id, user_id, notes) 
                    VALUES (?, 'void_restore', ?, ?, ?, ?, 'Cup restored from void')";
        $moveStmt = $pdo->prepare($moveSql);
        $moveStmt->execute([$cupId, $quantity, $saleId, $saleItemId, $userId]);
        
        logActivity('cup_restored', "Restored $quantity cups (cup_id: $cupId) from void", $userId, 'cup_inventory', $cupId);
        
        return true;
    } catch (Exception $e) {
        error_log("Restore Cup Stock Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Restock cups (admin action)
 */
function restockCups(int $cupId, int $quantity, ?string $notes = null, ?int $userId = null): bool {
    try {
        $pdo = getPDO();
        
        $sql = "UPDATE cup_inventory SET current_stock = current_stock + ? WHERE cup_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$quantity, $cupId]);
        
        // Log movement
        $moveSql = "INSERT INTO cup_movements 
                    (cup_id, movement_type, quantity, user_id, notes) 
                    VALUES (?, 'restock', ?, ?, ?)";
        $moveStmt = $pdo->prepare($moveSql);
        $moveStmt->execute([$cupId, $quantity, $userId, $notes ?: 'Manual restock']);
        
        logActivity('cup_restock', "Restocked $quantity cups (cup_id: $cupId)", $userId, 'cup_inventory', $cupId);
        
        return true;
    } catch (Exception $e) {
        error_log("Restock Cups Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Adjust cup inventory (admin action - waste, correction)
 */
function adjustCupStock(int $cupId, int $quantity, string $type, ?string $notes = null, ?int $userId = null): bool {
    try {
        $pdo = getPDO();
        
        $operator = ($type === 'waste' || $type === 'adjustment') ? '-' : '+';
        $sql = "UPDATE cup_inventory SET current_stock = current_stock $operator ? WHERE cup_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$quantity, $cupId]);
        
        // Log movement
        $moveSql = "INSERT INTO cup_movements 
                    (cup_id, movement_type, quantity, user_id, notes) 
                    VALUES (?, ?, ?, ?, ?)";
        $moveStmt = $pdo->prepare($moveSql);
        $moveStmt->execute([$cupId, $type, $quantity, $userId, $notes ?: ucfirst($type)]);
        
        logActivity('cup_adjustment', "Adjusted cup stock: $type $quantity (cup_id: $cupId)", $userId, 'cup_inventory', $cupId);
        
        return true;
    } catch (Exception $e) {
        error_log("Adjust Cup Stock Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get cup movements with pagination
 */
function getCupMovements(int $page = 1, int $limit = 5, ?int $cupId = null): array {
    $offset = ($page - 1) * $limit;
    
    $sql = "SELECT cm.*, ci.cup_size, u.full_name as user_name
            FROM cup_movements cm
            LEFT JOIN cup_inventory ci ON cm.cup_id = ci.cup_id
            LEFT JOIN users u ON cm.user_id = u.user_id";
    
    $params = [];
    if ($cupId) {
        $sql .= " WHERE cm.cup_id = ?";
        $params[] = $cupId;
    }
    
    $sql .= " ORDER BY cm.created_at DESC LIMIT $limit OFFSET $offset";
    
    return dbFetchAll($sql, $params);
}

/**
 * Count cup movements for pagination
 */
function countCupMovements(?int $cupId = null): int {
    $sql = "SELECT COUNT(*) as total FROM cup_movements";
    $params = [];
    
    if ($cupId) {
        $sql .= " WHERE cup_id = ?";
        $params[] = $cupId;
    }
    
    $result = dbFetchOne($sql, $params);
    return $result['total'] ?? 0;
}

// ==========================================
// INGREDIENT INVENTORY FUNCTIONS
// ==========================================

/**
 * Get all ingredients with low stock indicator
 */
function getIngredients(): array {
    $sql = "SELECT *, 
            CASE WHEN stock_quantity <= reorder_level THEN 1 ELSE 0 END as is_low_stock
            FROM ingredients 
            WHERE status = 'active'
            ORDER BY ingredient_name";
    return dbFetchAll($sql);
}

/**
 * Get single ingredient by ID
 */
function getIngredientById(int $ingredientId): ?array {
    return dbFetchOne("SELECT * FROM ingredients WHERE ingredient_id = ?", [$ingredientId]);
}

/**
 * Get ingredients required for a product (with optional cup size)
 */
function getProductIngredients(int $productId, ?int $cupId = null): array {
    $sql = "SELECT pi.*, i.ingredient_name, i.stock_quantity, i.unit
            FROM product_ingredients pi
            JOIN ingredients i ON pi.ingredient_id = i.ingredient_id
            WHERE pi.product_id = ?";
    
    $params = [$productId];
    
    // Note: cup_id is not in product_ingredients table - ingredients are per product, not per cup size
    
    return dbFetchAll($sql, $params);
}

/**
 * Check if all ingredients are available for a product
 */
function checkIngredientsAvailable(int $productId, ?int $cupId = null, int $quantity = 1): array {
    $ingredients = getProductIngredients($productId, $cupId);
    $unavailable = [];
    
    foreach ($ingredients as $ing) {
        $needed = $ing['quantity_required'] * $quantity;
        if ($ing['stock_quantity'] < $needed) {
            $unavailable[] = [
                'ingredient_name' => $ing['ingredient_name'],
                'needed' => $needed,
                'available' => $ing['stock_quantity'],
                'unit' => $ing['unit']
            ];
        }
    }
    
    return $unavailable;
}

/**
 * Deduct ingredients from inventory (on sale)
 */
function deductIngredients(int $productId, ?int $cupId = null, int $quantity = 1, ?int $saleId = null, ?int $saleItemId = null, ?int $userId = null): bool {
    try {
        $pdo = getPDO();
        $ingredients = getProductIngredients($productId, $cupId);
        
        foreach ($ingredients as $ing) {
            $amountUsed = $ing['quantity_required'] * $quantity;
            
            // Deduct from stock
            $sql = "UPDATE ingredients 
                    SET stock_quantity = stock_quantity - ? 
                    WHERE ingredient_id = ? AND stock_quantity >= ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$amountUsed, $ing['ingredient_id'], $amountUsed]);
            
            if ($stmt->rowCount() === 0) {
                return false; // Not enough stock
            }
            
            // Log movement
            $moveSql = "INSERT INTO ingredient_movements 
                        (ingredient_id, movement_type, quantity, sale_id, sale_item_id, user_id, notes) 
                        VALUES (?, 'sale', ?, ?, ?, ?, ?)";
            $moveStmt = $pdo->prepare($moveSql);
            $notes = "Used {$amountUsed}{$ing['unit']} for sale";
            $moveStmt->execute([$ing['ingredient_id'], $amountUsed, $saleId, $saleItemId, $userId, $notes]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Deduct Ingredients Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Restore ingredients to inventory (on void)
 */
function restoreIngredients(int $productId, ?int $cupId = null, int $quantity = 1, ?int $saleId = null, ?int $saleItemId = null, ?int $userId = null): bool {
    try {
        $pdo = getPDO();
        $ingredients = getProductIngredients($productId, $cupId);
        
        foreach ($ingredients as $ing) {
            $amountUsed = $ing['quantity_required'] * $quantity;
            
            // Restore stock
            $sql = "UPDATE ingredients SET stock_quantity = stock_quantity + ? WHERE ingredient_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$amountUsed, $ing['ingredient_id']]);
            
            // Log movement
            $moveSql = "INSERT INTO ingredient_movements 
                        (ingredient_id, movement_type, quantity, sale_id, sale_item_id, user_id, notes) 
                        VALUES (?, 'void_restore', ?, ?, ?, ?, ?)";
            $moveStmt = $pdo->prepare($moveSql);
            $notes = "Restored {$amountUsed}{$ing['unit']} from void";
            $moveStmt->execute([$ing['ingredient_id'], $amountUsed, $saleId, $saleItemId, $userId, $notes]);
        }
        
        logActivity('ingredients_restored', "Restored ingredients for product_id: $productId from void", $userId, 'products', $productId);
        
        return true;
    } catch (Exception $e) {
        error_log("Restore Ingredients Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Restock an ingredient (admin action)
 */
function restockIngredient(int $ingredientId, float $quantity, ?string $notes = null, ?int $userId = null): bool {
    try {
        $pdo = getPDO();
        
        $sql = "UPDATE ingredients SET stock_quantity = stock_quantity + ? WHERE ingredient_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$quantity, $ingredientId]);
        
        // Log movement
        $moveSql = "INSERT INTO ingredient_movements 
                    (ingredient_id, movement_type, quantity, user_id, notes) 
                    VALUES (?, 'restock', ?, ?, ?)";
        $moveStmt = $pdo->prepare($moveSql);
        $moveStmt->execute([$ingredientId, $quantity, $userId, $notes ?: 'Manual restock']);
        
        logActivity('ingredient_restock', "Restocked ingredient (id: $ingredientId) by $quantity", $userId, 'ingredients', $ingredientId);
        
        return true;
    } catch (Exception $e) {
        error_log("Restock Ingredient Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Adjust ingredient inventory (admin action)
 */
function adjustIngredient(int $ingredientId, float $quantity, string $type, ?string $notes = null, ?int $userId = null): bool {
    try {
        $pdo = getPDO();
        
        $operator = ($type === 'waste' || $type === 'adjustment') ? '-' : '+';
        $sql = "UPDATE ingredients SET stock_quantity = stock_quantity $operator ? WHERE ingredient_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$quantity, $ingredientId]);
        
        // Log movement
        $moveSql = "INSERT INTO ingredient_movements 
                    (ingredient_id, movement_type, quantity, user_id, notes) 
                    VALUES (?, ?, ?, ?, ?)";
        $moveStmt = $pdo->prepare($moveSql);
        $moveStmt->execute([$ingredientId, $type, $quantity, $userId, $notes ?: ucfirst($type)]);
        
        logActivity('ingredient_adjustment', "Adjusted ingredient: $type $quantity (id: $ingredientId)", $userId, 'ingredients', $ingredientId);
        
        return true;
    } catch (Exception $e) {
        error_log("Adjust Ingredient Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Add new ingredient
 */
function addIngredient(string $name, string $unit, float $stock = 0, float $reorderLevel = 100, float $costPerUnit = 0): ?int {
    try {
        $pdo = getPDO();
        
        $sql = "INSERT INTO ingredients (ingredient_name, unit, stock_quantity, reorder_level, cost_per_unit)
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $unit, $stock, $reorderLevel, $costPerUnit]);
        
        $id = (int)$pdo->lastInsertId();
        logActivity('ingredient_added', "Added new ingredient: $name", null, 'ingredients', $id);
        
        return $id;
    } catch (Exception $e) {
        error_log("Add Ingredient Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get ingredient movements with pagination
 */
function getIngredientMovements(?int $ingredientId = null, int $page = 1, int $limit = 5): array {
    $offset = ($page - 1) * $limit;
    
    $sql = "SELECT im.*, i.ingredient_name, i.unit, u.full_name as user_name
            FROM ingredient_movements im
            LEFT JOIN ingredients i ON im.ingredient_id = i.ingredient_id
            LEFT JOIN users u ON im.user_id = u.user_id";
    
    $params = [];
    if ($ingredientId) {
        $sql .= " WHERE im.ingredient_id = ?";
        $params[] = $ingredientId;
    }
    
    $sql .= " ORDER BY im.created_at DESC LIMIT $limit OFFSET $offset";
    
    return dbFetchAll($sql, $params);
}

/**
 * Count ingredient movements for pagination
 */
function countIngredientMovements(?int $ingredientId = null): int {
    $sql = "SELECT COUNT(*) as total FROM ingredient_movements";
    $params = [];
    
    if ($ingredientId) {
        $sql .= " WHERE ingredient_id = ?";
        $params[] = $ingredientId;
    }
    
    $result = dbFetchOne($sql, $params);
    return $result['total'] ?? 0;
}

// ==========================================
// PRODUCT STOCK FUNCTIONS
// ==========================================

/**
 * Deduct product stock (for non-beverage items)
 */
function deductProductStock(int $productId, int $quantity, ?int $saleId = null, ?int $userId = null): bool {
    try {
        $pdo = getPDO();
        
        $sql = "UPDATE products SET stock_quantity = stock_quantity - ? 
                WHERE product_id = ? AND stock_quantity >= ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$quantity, $productId, $quantity]);
        
        if ($stmt->rowCount() === 0) {
            return false;
        }
        
        // Log movement
        $moveSql = "INSERT INTO stock_movements 
                    (product_id, movement_type, quantity, reference_id, user_id, notes) 
                    VALUES (?, 'sale', ?, ?, ?, 'Sale deduction')";
        $moveStmt = $pdo->prepare($moveSql);
        $moveStmt->execute([$productId, $quantity, $saleId, $userId]);
        
        return true;
    } catch (Exception $e) {
        error_log("Deduct Product Stock Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Restore product stock (on void)
 */
function restoreProductStock(int $productId, int $quantity, ?int $saleId = null, ?int $userId = null): bool {
    try {
        $pdo = getPDO();
        
        $sql = "UPDATE products SET stock_quantity = stock_quantity + ? WHERE product_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$quantity, $productId]);
        
        // Log movement
        $moveSql = "INSERT INTO stock_movements 
                    (product_id, movement_type, quantity, reference_id, user_id, notes) 
                    VALUES (?, 'void_restore', ?, ?, ?, 'Stock restored from void')";
        $moveStmt = $pdo->prepare($moveSql);
        $moveStmt->execute([$productId, $quantity, $saleId, $userId]);
        
        logActivity('stock_restored', "Restored $quantity units of product_id: $productId from void", $userId, 'products', $productId);
        
        return true;
    } catch (Exception $e) {
        error_log("Restore Product Stock Error: " . $e->getMessage());
        return false;
    }
}

// ==========================================
// LOW STOCK ALERTS
// ==========================================

/**
 * Get all low stock items (cups, ingredients, products)
 */
function getLowStockAlerts(): array {
    $alerts = [];
    
    // Low stock cups
    $cupsSql = "SELECT cup_id, cup_size as name, current_stock as stock, reorder_level, 'cup' as type
                FROM cup_inventory 
                WHERE current_stock <= reorder_level AND status = 'active'";
    $alerts['cups'] = dbFetchAll($cupsSql);
    
    // Low stock ingredients
    $ingredientsSql = "SELECT ingredient_id, ingredient_name as name, stock_quantity as stock, reorder_level, unit, 'ingredient' as type
                       FROM ingredients 
                       WHERE stock_quantity <= reorder_level AND status = 'active'";
    $alerts['ingredients'] = dbFetchAll($ingredientsSql);
    
    // Low stock products
    $productsSql = "SELECT product_id, product_name as name, stock_quantity as stock, reorder_level, 'product' as type
                    FROM products 
                    WHERE stock_quantity <= reorder_level AND status = 'active'";
    $alerts['products'] = dbFetchAll($productsSql);
    
    return $alerts;
}

/**
 * Check if a cart item is available (stock, cups, ingredients)
 */
function checkCartItemAvailability(int $productId, ?int $cupId, int $quantity): array {
    $errors = [];
    
    // Get product info
    $product = dbFetchOne("SELECT * FROM products WHERE product_id = ?", [$productId]);
    
    if (!$product) {
        $errors[] = "Product not found";
        return $errors;
    }
    
    // Check product stock (for non-beverage items)
    if (!$product['requires_cup'] && $product['stock_quantity'] < $quantity) {
        $errors[] = "{$product['product_name']} is out of stock (available: {$product['stock_quantity']})";
    }
    
    // Check cup stock (for beverages)
    if ($product['requires_cup'] && $cupId) {
        $cup = getCupById($cupId);
        if (!$cup || $cup['current_stock'] < $quantity) {
            $available = $cup ? $cup['current_stock'] : 0;
            $errors[] = "Not enough {$cup['cup_size']} cups (available: $available)";
        }
    }
    
    // Check ingredients
    $unavailableIngredients = checkIngredientsAvailable($productId, $cupId, $quantity);
    foreach ($unavailableIngredients as $ing) {
        $errors[] = "Not enough {$ing['ingredient_name']} (need: {$ing['needed']}{$ing['unit']}, have: {$ing['available']}{$ing['unit']})";
    }
    
    return $errors;
}
