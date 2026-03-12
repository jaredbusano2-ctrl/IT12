<?php
/**
 * Cup Inventory Management Page
 * Features: View stock, restock, adjust, movement history with pagination (5 per page)
 */

require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';
require_once 'includes/inventory_functions.php';

setSecurityHeaders();
requireLogin();
checkPageAccess();
requirePermission('manage_cups');

$user = getCurrentUser();

// Validate CSRF for POST operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFRequest();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        redirect('cup_inventory.php', 'Invalid security token', 'error');
    }
    
    if (isset($_POST['restock_cups'])) {
        $cupId = sanitizeInt($_POST['cup_id']);
        $quantity = sanitizeInt($_POST['quantity']);
        $notes = sanitize($_POST['notes'] ?? '');
        
        if ($quantity > 0 && restockCups($cupId, $quantity, $notes, $user['user_id'])) {
            redirect('cup_inventory.php', 'Cups restocked successfully!', 'success');
        } else {
            redirect('cup_inventory.php', 'Failed to restock cups', 'error');
        }
    }
    
    if (isset($_POST['adjust_cups'])) {
        $cupId = sanitizeInt($_POST['cup_id']);
        $quantity = sanitizeInt($_POST['quantity']);
        $notes = sanitize($_POST['notes'] ?? '');
        $type = sanitize($_POST['movement_type']);
        
        if ($quantity > 0 && adjustCupStock($cupId, $quantity, $type, $notes, $user['user_id'])) {
            redirect('cup_inventory.php', 'Cup inventory adjusted successfully!', 'success');
        } else {
            redirect('cup_inventory.php', 'Failed to adjust inventory', 'error');
        }
    }
    
    if (isset($_POST['add_cup_type'])) {
        $cupSize = sanitize($_POST['cup_size']);
        $sizeMl = sanitizeInt($_POST['size_ml'] ?? 0);
        $stock = sanitizeInt($_POST['initial_stock'] ?? 0);
        $reorderLevel = sanitizeInt($_POST['reorder_level'] ?? 50);
        $costPerCup = sanitizeFloat($_POST['cost_per_cup'] ?? 0);
        
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("INSERT INTO cup_inventory (cup_size, size_ml, current_stock, reorder_level, cost_per_cup) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$cupSize, $sizeMl, $stock, $reorderLevel, $costPerCup]);
            
            logActivity('cup_type_added', "Added new cup type: $cupSize", $user['user_id']);
            redirect('cup_inventory.php', 'Cup type added successfully!', 'success');
        } catch (Exception $e) {
            redirect('cup_inventory.php', 'Failed to add cup type', 'error');
        }
    }
}

// Get cup inventory
$cups = getCupInventory();

// Get movements with pagination
$movementPage = max(1, sanitizeInt($_GET['mpage'] ?? 1));
$movements = getCupMovements($movementPage, ITEMS_PER_PAGE);
$totalMovements = countCupMovements();
$totalMovementPages = ceil($totalMovements / ITEMS_PER_PAGE);

// Get today's usage
$todayUsage = dbFetchAll("
    SELECT ci.cup_size, COALESCE(SUM(cm.quantity), 0) as total_used
    FROM cup_inventory ci
    LEFT JOIN cup_movements cm ON ci.cup_id = cm.cup_id 
        AND DATE(cm.created_at) = CURDATE() 
        AND cm.movement_type = 'sale'
    WHERE ci.status = 'active'
    GROUP BY ci.cup_id, ci.cup_size
    ORDER BY ci.cup_size
");

// Flash message
$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cup Inventory - POPRIE POS</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .cup-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .cup-card {
            background: linear-gradient(135deg, #fff 0%, #fafafa 100%);
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .cup-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        
        .cup-card.low-stock {
            border-color: #ff9800;
            background: linear-gradient(135deg, #fff9f0 0%, #fff5e5 100%);
        }
        
        .cup-card.critical-stock {
            border-color: #f44336;
            background: linear-gradient(135deg, #fff5f5 0%, #ffebee 100%);
        }
        
        .cup-size-badge {
            display: inline-block;
            padding: 8px 16px;
            background: #d32f2f;
            color: white;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 12px;
        }
        
        .stock-number {
            font-size: 48px;
            font-weight: 700;
            color: #333;
            margin: 12px 0;
        }
        
        .cup-card.low-stock .stock-number { color: #ff9800; }
        .cup-card.critical-stock .stock-number { color: #f44336; }
        
        .stock-label {
            color: #888;
            font-size: 13px;
            margin-bottom: 16px;
        }
        
        .cup-actions {
            display: flex;
            gap: 8px;
            justify-content: center;
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
                    <h1>Cup Inventory</h1>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary btn-sm" onclick="openAddCupModal()">+ Add Cup Type</button>
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
            
            <!-- Cup Inventory Cards -->
            <div class="cup-grid">
                <?php foreach ($cups as $cup): 
                    $stockClass = '';
                    if ($cup['current_stock'] <= $cup['reorder_level'] / 2) {
                        $stockClass = 'critical-stock';
                    } elseif ($cup['current_stock'] <= $cup['reorder_level']) {
                        $stockClass = 'low-stock';
                    }
                ?>
                    <div class="cup-card <?php echo $stockClass; ?>">
                        <div class="cup-size-badge"><?php echo htmlspecialchars($cup['cup_size']); ?></div>
                        <?php if ($cup['size_ml']): ?>
                            <div style="font-size: 12px; color: #888;"><?php echo $cup['size_ml']; ?>ml</div>
                        <?php endif; ?>
                        <div class="stock-number"><?php echo $cup['current_stock']; ?></div>
                        <div class="stock-label">
                            Reorder at: <?php echo $cup['reorder_level']; ?>
                            <?php if ($cup['is_low_stock']): ?>
                                <span style="color: #f44336;">LOW STOCK!</span>
                            <?php endif; ?>
                        </div>
                        <div class="cup-actions">
                            <button class="btn btn-primary btn-sm" onclick="openRestockModal(<?php echo $cup['cup_id']; ?>, '<?php echo htmlspecialchars($cup['cup_size']); ?>')">
                                + Restock
                            </button>
                            <button class="btn btn-warning btn-sm" onclick="openAdjustModal(<?php echo $cup['cup_id']; ?>, '<?php echo htmlspecialchars($cup['cup_size']); ?>')">
                                Adjust
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Today's Usage -->
            <div class="card">
                <div class="card-header">
                    <h3>Today's Cup Usage</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Cup Size</th>
                                    <th>Used Today</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($todayUsage)): ?>
                                    <?php foreach ($todayUsage as $usage): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($usage['cup_size']); ?></strong></td>
                                            <td><?php echo $usage['total_used']; ?> cups</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="2" style="text-align: center; color: #999;">No usage today</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Movement History -->
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <h3>Cup Movement History</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Cup Size</th>
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
                                            <td><strong><?php echo htmlspecialchars($m['cup_size']); ?></strong></td>
                                            <td>
                                                <span class="movement-badge <?php echo $m['movement_type']; ?>">
                                                    <?php echo str_replace('_', ' ', $m['movement_type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $m['quantity']; ?></td>
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
    
    <!-- Add Cup Type Modal -->
    <div id="addCupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add Cup Type</h2>
                <button class="modal-close" onclick="closeAddCupModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <div class="form-group">
                        <label>Cup Size Name *</label>
                        <input type="text" name="cup_size" class="form-control" required placeholder="e.g., 24oz">
                    </div>
                    <div class="form-group">
                        <label>Size (ml)</label>
                        <input type="number" name="size_ml" class="form-control" placeholder="e.g., 710">
                    </div>
                    <div class="form-group">
                        <label>Initial Stock</label>
                        <input type="number" name="initial_stock" class="form-control" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>Reorder Level</label>
                        <input type="number" name="reorder_level" class="form-control" value="50" min="1">
                    </div>
                    <div class="form-group">
                        <label>Cost per Cup (₱)</label>
                        <input type="number" step="0.01" name="cost_per_cup" class="form-control" value="0" min="0">
                    </div>
                    <button type="submit" name="add_cup_type" class="btn btn-primary" style="width: 100%;">Add Cup Type</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Restock Modal -->
    <div id="restockModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Restock Cups</h2>
                <button class="modal-close" onclick="closeRestockModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="cup_id" id="restockCupId">
                    <div class="form-group">
                        <label>Cup Size</label>
                        <input type="text" id="restockCupSize" class="form-control" readonly>
                    </div>
                    <div class="form-group">
                        <label>Quantity to Add *</label>
                        <input type="number" name="quantity" class="form-control" required min="1" placeholder="Enter quantity">
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                    </div>
                    <button type="submit" name="restock_cups" class="btn btn-primary" style="width: 100%;">Restock Cups</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Adjust Modal -->
    <div id="adjustModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Adjust Inventory</h2>
                <button class="modal-close" onclick="closeAdjustModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="cup_id" id="adjustCupId">
                    <div class="form-group">
                        <label>Cup Size</label>
                        <input type="text" id="adjustCupSize" class="form-control" readonly>
                    </div>
                    <div class="form-group">
                        <label>Adjustment Type *</label>
                        <select name="movement_type" class="form-control" required>
                            <option value="adjustment">Manual Adjustment</option>
                            <option value="waste">Waste/Damaged</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Quantity to Remove *</label>
                        <input type="number" name="quantity" class="form-control" required min="1" placeholder="Enter quantity">
                    </div>
                    <div class="form-group">
                        <label>Notes *</label>
                        <textarea name="notes" class="form-control" rows="2" required placeholder="Reason for adjustment..."></textarea>
                    </div>
                    <button type="submit" name="adjust_cups" class="btn btn-warning" style="width: 100%;">Adjust Inventory</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openAddCupModal() { document.getElementById('addCupModal').classList.add('active'); }
        function closeAddCupModal() { document.getElementById('addCupModal').classList.remove('active'); }
        
        function openRestockModal(cupId, cupSize) {
            document.getElementById('restockCupId').value = cupId;
            document.getElementById('restockCupSize').value = cupSize;
            document.getElementById('restockModal').classList.add('active');
        }
        function closeRestockModal() { document.getElementById('restockModal').classList.remove('active'); }
        
        function openAdjustModal(cupId, cupSize) {
            document.getElementById('adjustCupId').value = cupId;
            document.getElementById('adjustCupSize').value = cupSize;
            document.getElementById('adjustModal').classList.add('active');
        }
        function closeAdjustModal() { document.getElementById('adjustModal').classList.remove('active'); }
        
        // Close modals on outside click
        document.querySelectorAll('.modal').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); });
        });
    </script>
    <script src="js/hamburger.js"></script>
</body>
</html>
