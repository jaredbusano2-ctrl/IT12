<?php
/**
 * Ingredient Inventory Management Page
 * Features: View ingredients, add new, restock, adjust, link to products
 */

require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';
require_once 'includes/inventory_functions.php';

setSecurityHeaders();
requireLogin();
checkPageAccess();
requirePermission('manage_ingredients');

$user = getCurrentUser();

// Validate CSRF for POST operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFRequest();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('ingredients.php', 'Invalid security token', 'error');
    }
    
    if (isset($_POST['add_ingredient'])) {
        $name = sanitize($_POST['ingredient_name']);
        $unit = sanitize($_POST['unit']);
        $stock = sanitizeFloat($_POST['current_stock'] ?? 0);
        $reorder = sanitizeFloat($_POST['reorder_level'] ?? 10);
        $cost = sanitizeFloat($_POST['cost_per_unit'] ?? 0);
        
        if (addIngredient($name, $unit, $stock, $reorder, $cost, $user['user_id'])) {
            redirect('ingredients.php', 'Ingredient added successfully!', 'success');
        } else {
            redirect('ingredients.php', 'Failed to add ingredient', 'error');
        }
    }
    
    if (isset($_POST['restock_ingredient'])) {
        $ingredientId = sanitizeInt($_POST['ingredient_id']);
        $quantity = sanitizeFloat($_POST['quantity']);
        $notes = sanitize($_POST['notes'] ?? '');
        
        if ($quantity > 0 && restockIngredient($ingredientId, $quantity, $notes, $user['user_id'])) {
            redirect('ingredients.php', 'Ingredient restocked successfully!', 'success');
        } else {
            redirect('ingredients.php', 'Failed to restock ingredient', 'error');
        }
    }
    
    if (isset($_POST['adjust_ingredient'])) {
        $ingredientId = sanitizeInt($_POST['ingredient_id']);
        $quantity = sanitizeFloat($_POST['quantity']);
        $notes = sanitize($_POST['notes'] ?? '');
        $type = sanitize($_POST['movement_type']);
        
        if ($quantity > 0 && adjustIngredient($ingredientId, $quantity, $type, $notes, $user['user_id'])) {
            redirect('ingredients.php', 'Ingredient adjusted successfully!', 'success');
        } else {
            redirect('ingredients.php', 'Failed to adjust ingredient', 'error');
        }
    }
    
    if (isset($_POST['update_ingredient'])) {
        $ingredientId = sanitizeInt($_POST['ingredient_id']);
        $name = sanitize($_POST['ingredient_name']);
        $unit = sanitize($_POST['unit']);
        $reorder = sanitizeFloat($_POST['reorder_level']);
        $cost = sanitizeFloat($_POST['cost_per_unit']);
        
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("UPDATE ingredients SET ingredient_name = ?, unit = ?, reorder_level = ?, cost_per_unit = ? WHERE ingredient_id = ?");
            $stmt->execute([$name, $unit, $reorder, $cost, $ingredientId]);
            logActivity('ingredient_updated', "Updated ingredient: $name", $user['user_id']);
            redirect('ingredients.php', 'Ingredient updated successfully!', 'success');
        } catch (Exception $e) {
            redirect('ingredients.php', 'Failed to update ingredient', 'error');
        }
    }
    
    if (isset($_POST['toggle_status'])) {
        $ingredientId = sanitizeInt($_POST['ingredient_id']);
        $newStatus = sanitize($_POST['new_status']);
        
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("UPDATE ingredients SET status = ? WHERE ingredient_id = ?");
            $stmt->execute([$newStatus, $ingredientId]);
            logActivity('ingredient_status_changed', "Changed ingredient #$ingredientId status to $newStatus", $user['user_id']);
            redirect('ingredients.php', 'Status updated!', 'success');
        } catch (Exception $e) {
            redirect('ingredients.php', 'Failed to update status', 'error');
        }
    }
}

// Get ingredients
$ingredients = getIngredients();

// Pagination for movements
$movementPage = max(1, sanitizeInt($_GET['mpage'] ?? 1));
$movements = getIngredientMovements(null, $movementPage, ITEMS_PER_PAGE);
$totalMovements = countIngredientMovements(null);
$totalMovementPages = ceil($totalMovements / ITEMS_PER_PAGE);

// Get low stock count
$lowStockCount = dbFetchOne("SELECT COUNT(*) as cnt FROM ingredients WHERE stock_quantity <= reorder_level AND status = 'active'")['cnt'] ?? 0;

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingredients - POPRIE POS</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .ingredient-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .ingredient-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .ingredient-card:hover {
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
        }
        
        .ingredient-card.low-stock {
            border-color: #ff9800;
            background: #fff9f0;
        }
        
        .ingredient-card.critical-stock {
            border-color: #f44336;
            background: #fff5f5;
        }
        
        .ingredient-card.inactive {
            opacity: 0.6;
            background: #f5f5f5;
        }
        
        .ingredient-name {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }
        
        .ingredient-unit {
            font-size: 13px;
            color: #888;
            margin-bottom: 12px;
        }
        
        .stock-info {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 16px;
        }
        
        .stock-value {
            font-size: 32px;
            font-weight: 700;
        }
        
        .ingredient-card.low-stock .stock-value { color: #ff9800; }
        .ingredient-card.critical-stock .stock-value { color: #f44336; }
        
        .reorder-info {
            font-size: 12px;
            color: #888;
        }
        
        .ingredient-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .movement-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .movement-badge.sale { background: #ffebee; color: #c62828; }
        .movement-badge.restock { background: #e8f5e9; color: #2e7d32; }
        .movement-badge.void_restore { background: #e3f2fd; color: #1565c0; }
        .movement-badge.adjustment { background: #fff3e0; color: #ef6c00; }
        .movement-badge.waste { background: #fce4ec; color: #c2185b; }
        .movement-badge.initial { background: #f3e5f5; color: #7b1fa2; }
        
        .pagination-controls {
            display: flex;
            justify-content: center;
            gap: 4px;
            margin-top: 16px;
        }
        
        .pagination-controls a {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            text-decoration: none;
            color: #666;
            font-size: 13px;
        }
        
        .pagination-controls a:hover,
        .pagination-controls a.active {
            background: #d32f2f;
            color: white;
            border-color: #d32f2f;
        }
        
        .alert {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .stat-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-card h3 {
            font-size: 36px;
            margin: 0;
            color: #d32f2f;
        }
        
        .stat-card p {
            margin: 8px 0 0;
            color: #666;
            font-size: 14px;
        }
        
        .stat-card.warning h3 { color: #ff9800; }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <div class="header-left">
                    <button class="hamburger-menu" onclick="toggleSidebar()">
                        <span></span><span></span><span></span>
                    </button>
                    <h1>Ingredient Inventory</h1>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary btn-sm" onclick="openAddModal()">+ Add Ingredient</button>
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
                        <div class="user-details">
                            <h4><?php echo htmlspecialchars($user['full_name']); ?></h4>
                            <p><?php echo ucfirst($user['role']); ?></p>
                        </div>
                    </div>
                    <a href="logout.php" class="btn btn-logout btn-sm">Logout</a>
                </div>
            </div>
            
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type']; ?>">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="stat-cards">
                <div class="stat-card">
                    <h3><?php echo count($ingredients); ?></h3>
                    <p>Total Ingredients</p>
                </div>
                <div class="stat-card warning">
                    <h3><?php echo $lowStockCount; ?></h3>
                    <p>Low Stock Items</p>
                </div>
            </div>
            
            <!-- Ingredients Grid -->
            <div class="ingredient-grid">
                <?php foreach ($ingredients as $ing): 
                    $stockClass = '';
                    if ($ing['status'] !== 'active') {
                        $stockClass = 'inactive';
                    } elseif ($ing['stock_quantity'] <= $ing['reorder_level'] / 2) {
                        $stockClass = 'critical-stock';
                    } elseif ($ing['stock_quantity'] <= $ing['reorder_level']) {
                        $stockClass = 'low-stock';
                    }
                ?>
                    <div class="ingredient-card <?php echo $stockClass; ?>">
                        <div class="ingredient-name"><?php echo htmlspecialchars($ing['ingredient_name']); ?></div>
                        <div class="ingredient-unit">Unit: <?php echo htmlspecialchars($ing['unit']); ?></div>
                        
                        <div class="stock-info">
                            <div>
                                <div class="stock-value"><?php echo number_format($ing['stock_quantity'], 2); ?></div>
                                <div class="reorder-info">
                                    Reorder at: <?php echo $ing['reorder_level']; ?>
                                    <?php if ($ing['stock_quantity'] <= $ing['reorder_level']): ?>
                                        <span style="color: #f44336;"> - LOW!</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 12px; color: #888;">Cost/Unit</div>
                                <div style="font-weight: 600;"><?php echo formatCurrency($ing['cost_per_unit']); ?></div>
                            </div>
                        </div>
                        
                        <div class="ingredient-actions">
                            <button class="btn btn-primary btn-sm" onclick="openRestockModal(<?php echo $ing['ingredient_id']; ?>, '<?php echo htmlspecialchars($ing['ingredient_name']); ?>', '<?php echo htmlspecialchars($ing['unit']); ?>')">
                                + Restock
                            </button>
                            <button class="btn btn-warning btn-sm" onclick="openAdjustModal(<?php echo $ing['ingredient_id']; ?>, '<?php echo htmlspecialchars($ing['ingredient_name']); ?>', '<?php echo htmlspecialchars($ing['unit']); ?>')">
                                Adjust
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="openEditModal(<?php echo $ing['ingredient_id']; ?>, '<?php echo htmlspecialchars($ing['ingredient_name']); ?>', '<?php echo htmlspecialchars($ing['unit']); ?>', <?php echo $ing['reorder_level']; ?>, <?php echo $ing['cost_per_unit']; ?>)">
                                Edit
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if (empty($ingredients)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #888;">
                        No ingredients added yet. Click "Add Ingredient" to get started.
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Movement History -->
            <div class="card">
                <div class="card-header">
                    <h3>Ingredient Movement History</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Ingredient</th>
                                    <th>Type</th>
                                    <th>Quantity</th>
                                    <th>Notes</th>
                                    <th>User</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($movements)): ?>
                                    <?php foreach ($movements as $m): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y H:i', strtotime($m['created_at'])); ?></td>
                                            <td><strong><?php echo htmlspecialchars($m['ingredient_name']); ?></strong></td>
                                            <td>
                                                <span class="movement-badge <?php echo $m['movement_type']; ?>">
                                                    <?php echo str_replace('_', ' ', $m['movement_type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo number_format($m['quantity'], 2); ?> <?php echo htmlspecialchars($m['unit'] ?? ''); ?></td>
                                            <td style="max-width: 200px;"><?php echo htmlspecialchars($m['notes'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($m['user_name'] ?? 'System'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: #999;">No movements recorded</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if ($totalMovementPages > 1): ?>
                        <div class="pagination-controls">
                            <?php for ($i = 1; $i <= $totalMovementPages; $i++): ?>
                                <a href="?mpage=<?php echo $i; ?>" class="<?php echo $i === $movementPage ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Ingredient Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Ingredient</h2>
                <button class="modal-close" onclick="closeAddModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <div class="form-group">
                        <label>Ingredient Name *</label>
                        <input type="text" name="ingredient_name" class="form-control" required placeholder="e.g., Espresso Beans">
                    </div>
                    <div class="form-group">
                        <label>Unit *</label>
                        <select name="unit" class="form-control" required>
                            <option value="g">Grams (g)</option>
                            <option value="kg">Kilograms (kg)</option>
                            <option value="ml">Milliliters (ml)</option>
                            <option value="L">Liters (L)</option>
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="oz">Ounces (oz)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Initial Stock</label>
                        <input type="number" step="0.01" name="current_stock" class="form-control" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>Reorder Level</label>
                        <input type="number" step="0.01" name="reorder_level" class="form-control" value="10" min="0">
                    </div>
                    <div class="form-group">
                        <label>Cost per Unit (₱)</label>
                        <input type="number" step="0.01" name="cost_per_unit" class="form-control" value="0" min="0">
                    </div>
                    <button type="submit" name="add_ingredient" class="btn btn-primary" style="width: 100%;">Add Ingredient</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Restock Modal -->
    <div id="restockModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Restock Ingredient</h2>
                <button class="modal-close" onclick="closeRestockModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="ingredient_id" id="restockIngredientId">
                    <div class="form-group">
                        <label>Ingredient</label>
                        <input type="text" id="restockIngredientName" class="form-control" readonly>
                    </div>
                    <div class="form-group">
                        <label>Quantity to Add * (<span id="restockUnit"></span>)</label>
                        <input type="number" step="0.01" name="quantity" class="form-control" required min="0.01" placeholder="Enter quantity">
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                    </div>
                    <button type="submit" name="restock_ingredient" class="btn btn-primary" style="width: 100%;">Restock</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Adjust Modal -->
    <div id="adjustModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Adjust Ingredient</h2>
                <button class="modal-close" onclick="closeAdjustModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="ingredient_id" id="adjustIngredientId">
                    <div class="form-group">
                        <label>Ingredient</label>
                        <input type="text" id="adjustIngredientName" class="form-control" readonly>
                    </div>
                    <div class="form-group">
                        <label>Adjustment Type *</label>
                        <select name="movement_type" class="form-control" required>
                            <option value="adjustment">Manual Adjustment</option>
                            <option value="waste">Waste/Damaged</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Quantity to Remove * (<span id="adjustUnit"></span>)</label>
                        <input type="number" step="0.01" name="quantity" class="form-control" required min="0.01" placeholder="Enter quantity">
                    </div>
                    <div class="form-group">
                        <label>Notes *</label>
                        <textarea name="notes" class="form-control" rows="2" required placeholder="Reason for adjustment..."></textarea>
                    </div>
                    <button type="submit" name="adjust_ingredient" class="btn btn-warning" style="width: 100%;">Adjust Inventory</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Ingredient</h2>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="ingredient_id" id="editIngredientId">
                    <div class="form-group">
                        <label>Ingredient Name *</label>
                        <input type="text" name="ingredient_name" id="editIngredientName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Unit *</label>
                        <select name="unit" id="editUnit" class="form-control" required>
                            <option value="g">Grams (g)</option>
                            <option value="kg">Kilograms (kg)</option>
                            <option value="ml">Milliliters (ml)</option>
                            <option value="L">Liters (L)</option>
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="oz">Ounces (oz)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Reorder Level</label>
                        <input type="number" step="0.01" name="reorder_level" id="editReorderLevel" class="form-control" min="0">
                    </div>
                    <div class="form-group">
                        <label>Cost per Unit (₱)</label>
                        <input type="number" step="0.01" name="cost_per_unit" id="editCostPerUnit" class="form-control" min="0">
                    </div>
                    <button type="submit" name="update_ingredient" class="btn btn-primary" style="width: 100%;">Update Ingredient</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openAddModal() { document.getElementById('addModal').classList.add('active'); }
        function closeAddModal() { document.getElementById('addModal').classList.remove('active'); }
        
        function openRestockModal(id, name, unit) {
            document.getElementById('restockIngredientId').value = id;
            document.getElementById('restockIngredientName').value = name;
            document.getElementById('restockUnit').textContent = unit;
            document.getElementById('restockModal').classList.add('active');
        }
        function closeRestockModal() { document.getElementById('restockModal').classList.remove('active'); }
        
        function openAdjustModal(id, name, unit) {
            document.getElementById('adjustIngredientId').value = id;
            document.getElementById('adjustIngredientName').value = name;
            document.getElementById('adjustUnit').textContent = unit;
            document.getElementById('adjustModal').classList.add('active');
        }
        function closeAdjustModal() { document.getElementById('adjustModal').classList.remove('active'); }
        
        function openEditModal(id, name, unit, reorderLevel, costPerUnit) {
            document.getElementById('editIngredientId').value = id;
            document.getElementById('editIngredientName').value = name;
            document.getElementById('editUnit').value = unit;
            document.getElementById('editReorderLevel').value = reorderLevel;
            document.getElementById('editCostPerUnit').value = costPerUnit;
            document.getElementById('editModal').classList.add('active');
        }
        function closeEditModal() { document.getElementById('editModal').classList.remove('active'); }
        
        // Close modals on outside click
        document.querySelectorAll('.modal').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); });
        });
    </script>
    <script src="js/hamburger.js"></script>
</body>
</html>
