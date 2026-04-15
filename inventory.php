<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';

setSecurityHeaders();
requireLogin();
checkPageAccess();
requirePermission('manage_inventory');

$user = getCurrentUser();

// Validate CSRF for POST operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFRequest();
}

// Handle stock adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_stock'])) {
    $product_id = sanitizeInt($_POST['product_id']);
    $movement_type = sanitize($_POST['movement_type']);
    $quantity = sanitizeInt($_POST['quantity']);
    $notes = sanitize($_POST['notes']);
    
    if ($movement_type === 'in') {
        $conn->query("UPDATE products SET stock_quantity = stock_quantity + $quantity WHERE product_id = $product_id");
    } else {
        $conn->query("UPDATE products SET stock_quantity = stock_quantity - $quantity WHERE product_id = $product_id");
    }
    
    $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, notes, user_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("isisi", $product_id, $movement_type, $quantity, $notes, $user['user_id']);
    $stmt->execute();
    
    header('Location: inventory.php?success=1');
    exit();
}

// Get pagination information for products
$products_page = isset($_GET['products_page']) ? max(1, intval($_GET['products_page'])) : 1;
$products_limit = 5;
$products_offset = ($products_page - 1) * $products_limit;

// Get total product count
$total_products = $conn->query("
    SELECT COUNT(*) as count 
    FROM products p 
    WHERE p.status = 'active'
")->fetch_assoc()['count'];
$total_products_pages = ceil($total_products / $products_limit);

// Get products with pagination
$products = $conn->query("
    SELECT p.*, c.category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.category_id 
    WHERE p.status = 'active'
    ORDER BY p.stock_quantity ASC
    LIMIT $products_limit OFFSET $products_offset
");

// Get pagination information for stock movements
$movements_page = isset($_GET['movements_page']) ? max(1, intval($_GET['movements_page'])) : 1;
$movements_limit = 5;
$movements_offset = ($movements_page - 1) * $movements_limit;

// Get total movements count
$total_movements = $conn->query("
    SELECT COUNT(*) as count 
    FROM stock_movements
")->fetch_assoc()['count'];
$total_movements_pages = ceil($total_movements / $movements_limit);

// Get recent stock movements with pagination
$movements = $conn->query("
    SELECT sm.*, p.product_name, u.full_name 
    FROM stock_movements sm 
    LEFT JOIN products p ON sm.product_id = p.product_id 
    LEFT JOIN users u ON sm.user_id = u.user_id 
    ORDER BY sm.created_at DESC 
    LIMIT $movements_limit OFFSET $movements_offset
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - POS & Inventory System</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .pagination-container {
            margin-top: 24px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .pagination-info {
            margin: 0 12px;
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .pagination-controls {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .pagination-controls a,
        .pagination-controls span {
            padding: 6px 10px;
            border: 1px solid var(--border);
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.2s ease;
        }
        
        .pagination-controls a {
            background: var(--bg-secondary);
            color: var(--text-primary);
            cursor: pointer;
        }
        
        .pagination-controls a:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .pagination-controls a.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .pagination-controls span.dots {
            border: none;
            color: var(--text-secondary);
        }
        
        .section-title {
            margin-top: 32px;
            margin-bottom: 16px;
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
        }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>Inventory Management</h1>
                <div class="header-actions">
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
            
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success" style="margin-bottom: 24px; background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7;">
                    Stock updated successfully!
                </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h3>Current Inventory</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Product Code</th>
                                    <th>Product Name</th>
                                    <th>Category</th>
                                    <th>Current Stock</th>
                                    <th>Reorder Level</th>
                                    <th>Cost Price</th>
                                    <th>Selling Price</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($products->num_rows > 0): ?>
                                    <?php while ($product = $products->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($product['product_code']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                            <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                            <td><strong><?php echo $product['stock_quantity']; ?></strong></td>
                                            <td><?php echo $product['reorder_level']; ?></td>
                                            <td>₱<?php echo number_format($product['cost_price'] ?? $product['cost'] ?? 0, 2); ?></td>
                                            <td>₱<?php echo number_format($product['selling_price'] ?? $product['price'] ?? 0, 2); ?></td>
                                            <td>
                                                <?php if ($product['stock_quantity'] == 0): ?>
                                                    <span class="badge badge-danger">Out of Stock</span>
                                                <?php elseif ($product['stock_quantity'] <= $product['reorder_level']): ?>
                                                    <span class="badge badge-warning">Low Stock</span>
                                                <?php else: ?>
                                                    <span class="badge badge-success">In Stock</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-primary btn-sm" onclick="openStockModal(<?php echo htmlspecialchars(json_encode($product)); ?>)">
                                                    Adjust Stock
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center; padding: 24px; color: var(--text-secondary);">
                                            No products found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination Controls for Products -->
                    <?php if ($total_products_pages > 1): ?>
                        <div class="pagination-container">
                            <div class="pagination-controls">
                                <?php if ($products_page > 1): ?>
                                    <a href="?products_page=1<?php echo isset($_GET['movements_page']) ? '&movements_page='.$_GET['movements_page'] : ''; ?>">« First</a>
                                    <a href="?products_page=<?php echo $products_page - 1; ?><?php echo isset($_GET['movements_page']) ? '&movements_page='.$_GET['movements_page'] : ''; ?>">‹ Previous</a>
                                <?php endif; ?>
                                
                                <?php
                                $start_page = max(1, $products_page - 2);
                                $end_page = min($total_products_pages, $products_page + 2);
                                
                                if ($start_page > 1) {
                                    echo '<a href="?products_page=1' . (isset($_GET['movements_page']) ? '&movements_page='.$_GET['movements_page'] : '') . '">1</a>';
                                    if ($start_page > 2) {
                                        echo '<span class="dots">...</span>';
                                    }
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <a href="?products_page=<?php echo $i; ?><?php echo isset($_GET['movements_page']) ? '&movements_page='.$_GET['movements_page'] : ''; ?>" 
                                       class="<?php echo $i == $products_page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php
                                endfor;
                                
                                if ($end_page < $total_products_pages) {
                                    if ($end_page < $total_products_pages - 1) {
                                        echo '<span class="dots">...</span>';
                                    }
                                    echo '<a href="?products_page=' . $total_products_pages . (isset($_GET['movements_page']) ? '&movements_page='.$_GET['movements_page'] : '') . '">' . $total_products_pages . '</a>';
                                }
                                ?>
                                
                                <?php if ($products_page < $total_products_pages): ?>
                                    <a href="?products_page=<?php echo $products_page + 1; ?><?php echo isset($_GET['movements_page']) ? '&movements_page='.$_GET['movements_page'] : ''; ?>">Next ›</a>
                                    <a href="?products_page=<?php echo $total_products_pages; ?><?php echo isset($_GET['movements_page']) ? '&movements_page='.$_GET['movements_page'] : ''; ?>">Last »</a>
                                <?php endif; ?>
                            </div>
                            <div class="pagination-info">
                                Page <?php echo $products_page; ?> of <?php echo $total_products_pages; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Stock Movements Section -->
            <h2 class="section-title">Recent Stock Movements</h2>
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Type</th>
                                    <th>Quantity</th>
                                    <th>Notes</th>
                                    <th>User</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($movements->num_rows > 0): ?>
                                    <?php while ($movement = $movements->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($movement['product_name']); ?></td>
                                            <td>
                                                <?php 
                                                $type = $movement['movement_type'];
                                                $badge_class = in_array($type, ['restock', 'void_restore', 'initial']) ? 'badge-success' : 'badge-danger';
                                                echo "<span class=\"badge $badge_class\">" . ucfirst($type) . "</span>";
                                                ?>
                                            </td>
                                            <td><strong><?php echo $movement['quantity']; ?></strong></td>
                                            <td><?php echo htmlspecialchars($movement['notes'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($movement['full_name'] ?? '-'); ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($movement['created_at'])); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 24px; color: var(--text-secondary);">
                                            No stock movements found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination Controls for Movements -->
                    <?php if ($total_movements_pages > 1): ?>
                        <div class="pagination-container">
                            <div class="pagination-controls">
                                <?php if ($movements_page > 1): ?>
                                    <a href="?movements_page=1<?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?>">« First</a>
                                    <a href="?movements_page=<?php echo $movements_page - 1; ?><?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?>">‹ Previous</a>
                                <?php endif; ?>
                                
                                <?php
                                $start_page = max(1, $movements_page - 2);
                                $end_page = min($total_movements_pages, $movements_page + 2);
                                
                                if ($start_page > 1) {
                                    echo '<a href="?movements_page=1' . (isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : '') . '">1</a>';
                                    if ($start_page > 2) {
                                        echo '<span class="dots">...</span>';
                                    }
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <a href="?movements_page=<?php echo $i; ?><?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?>" 
                                       class="<?php echo $i == $movements_page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php
                                endfor;
                                
                                if ($end_page < $total_movements_pages) {
                                    if ($end_page < $total_movements_pages - 1) {
                                        echo '<span class="dots">...</span>';
                                    }
                                    echo '<a href="?movements_page=' . $total_movements_pages . (isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : '') . '">' . $total_movements_pages . '</a>';
                                }
                                ?>
                                
                                <?php if ($movements_page < $total_movements_pages): ?>
                                    <a href="?movements_page=<?php echo $movements_page + 1; ?><?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?>">Next ›</a>
                                    <a href="?movements_page=<?php echo $total_movements_pages; ?><?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?>">Last »</a>
                                <?php endif; ?>
                            </div>
                            <div class="pagination-info">
                                Page <?php echo $movements_page; ?> of <?php echo $total_movements_pages; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Stock Adjustment Modal -->
    <div id="stockModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Adjust Stock</h2>
                <button class="modal-close" onclick="closeStockModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="adjust_stock" value="1">
                    <input type="hidden" name="product_id" id="modalProductId">
                    
                    <div class="form-group">
                        <label>Product</label>
                        <input type="text" class="form-control" id="modalProductName" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Current Stock</label>
                        <input type="text" class="form-control" id="modalCurrentStock" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Movement Type</label>
                        <select class="form-control" name="movement_type" required>
                            <option value="in">Stock In (Add)</option>
                            <option value="out">Stock Out (Remove)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" class="form-control" name="quantity" min="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Update Stock</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openStockModal(product) {
            document.getElementById('modalProductId').value = product.product_id;
            document.getElementById('modalProductName').value = product.product_name;
            document.getElementById('modalCurrentStock').value = product.stock_quantity;
            document.getElementById('stockModal').classList.add('active');
        }
        
        function closeStockModal() {
            document.getElementById('stockModal').classList.remove('active');
        }
        
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                closeStockModal();
            }
        });
    </script>
</body>
</html>