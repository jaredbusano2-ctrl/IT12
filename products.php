<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';

setSecurityHeaders();
requireLogin();
checkPageAccess();
requirePermission('manage_products');

$user = getCurrentUser();

// Pagination settings
$items_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $items_per_page;

// Category filter
$selected_category = isset($_GET['category']) ? (int)$_GET['category'] : '';

// Handle product add/edit/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    validateCSRFRequest();
    
    if (isset($_POST['add_product'])) {
        $stmt = $conn->prepare("INSERT INTO products (product_code, product_name, category_id, description, cost_price, selling_price, stock_quantity, reorder_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssissdii", 
            $_POST['product_code'], 
            $_POST['product_name'], 
            $_POST['category_id'], 
            $_POST['description'], 
            $_POST['cost_price'], 
            $_POST['selling_price'], 
            $_POST['stock_quantity'], 
            $_POST['reorder_level']
        );
        $stmt->execute();
        logActivity('product_added', "Added product: " . sanitize($_POST['product_name']));
        header('Location: products.php?success=added');
        exit();
    } elseif (isset($_POST['edit_product'])) {
        $stmt = $conn->prepare("UPDATE products SET product_code=?, product_name=?, category_id=?, description=?, cost_price=?, selling_price=?, reorder_level=? WHERE product_id=?");
        $stmt->bind_param("ssissdii", 
            $_POST['product_code'], 
            $_POST['product_name'], 
            $_POST['category_id'], 
            $_POST['description'], 
            $_POST['cost_price'], 
            $_POST['selling_price'], 
            $_POST['reorder_level'],
            $_POST['product_id']
        );
        $stmt->execute();
        logActivity('product_updated', "Updated product: " . sanitize($_POST['product_name']));
        header('Location: products.php?success=updated');
        exit();
    } elseif (isset($_POST['delete_product'])) {
        $product_id = sanitizeInt($_POST['product_id']);
        $conn->query("UPDATE products SET status='inactive' WHERE product_id=$product_id");
        logActivity('product_deleted', "Deactivated product ID: $product_id");
        header('Location: products.php?success=deleted');
        exit();
    }
}

// Build query with category filter
$where_clause = "WHERE p.status = 'active'";
$params = [];
$types = "";

if ($selected_category) {
    $where_clause .= " AND p.category_id = ?";
    $params[] = $selected_category;
    $types .= "i";
}

// Get total products count
$count_query = "SELECT COUNT(*) as total FROM products p LEFT JOIN categories c ON p.category_id = c.category_id $where_clause";
$count_stmt = $conn->prepare($count_query);
if ($params) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_result = $count_stmt->get_result();
$total_products = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_products / $items_per_page);

// Get products with pagination
$products_query = "
    SELECT p.*, c.category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.category_id 
    $where_clause
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
";
$products_stmt = $conn->prepare($products_query);
$all_params = array_merge($params, [$items_per_page, $offset]);
$all_types = $types . "ii";
$products_stmt->bind_param($all_types, ...$all_params);
$products_stmt->execute();
$products = $products_stmt->get_result();

// Get categories
$categories = $conn->query("SELECT * FROM categories ORDER BY category_name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - POS & Inventory System</title>
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
    </style>
</head>
<body>
    <div class="main-wrapper">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <div class="header-left">
                    <!-- Hamburger Menu Button -->
                    <button class="hamburger-menu" onclick="toggleSidebar()">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                    <h1>Products</h1>
                </div>
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
                    Product <?php echo htmlspecialchars($_GET['success']); ?> successfully!
                </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h3>All Products</h3>
                    <form method="GET" style="display: flex; gap: 12px; align-items: center;">
                        <select name="category" class="form-control" style="width: 200px;" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <?php 
                            $categories->data_seek(0);
                            while ($cat = $categories->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $cat['category_id']; ?>" <?php echo $selected_category == $cat['category_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <button type="button" class="btn btn-primary btn-sm" onclick="openAddModal()">+ Add Product</button>
                    </form>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th>Cost</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Reorder</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($products->num_rows > 0): ?>
                                    <?php while ($product = $products->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($product['product_code']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                            <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                            <td>₱<?php echo number_format($product['cost_price'] ?? $product['cost'] ?? 0, 2); ?></td>
                                            <td>₱<?php echo number_format($product['selling_price'] ?? $product['price'] ?? 0, 2); ?></td>
                                            <td><strong><?php echo $product['stock_quantity']; ?></strong></td>
                                            <td><?php echo $product['reorder_level']; ?></td>
                                            <td>
                                                <button class="btn btn-warning btn-sm" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($product)); ?>)">Edit</button>
                                                <button class="btn btn-info btn-sm" onclick="openStockModal(<?php echo htmlspecialchars(json_encode($product)); ?>)">Stock</button>
                                                <button class="btn btn-danger btn-sm" onclick="deleteProduct(<?php echo $product['product_id']; ?>)">Delete</button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 24px; color: var(--text-secondary);">
                                            No products found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination" style="display: flex; justify-content: center; gap: 8px; margin-top: 24px;">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&category=<?php echo $selected_category; ?>" class="btn btn-secondary btn-sm">« Previous</a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="btn btn-primary btn-sm"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?>&category=<?php echo $selected_category; ?>" class="btn btn-secondary btn-sm"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&category=<?php echo $selected_category; ?>" class="btn btn-secondary btn-sm">Next »</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div style="text-align: center; margin-top: 16px; color: var(--text-secondary);">
                    Showing <?php echo ($offset + 1) . ' - ' . min($offset + $items_per_page, $total_products); ?> of <?php echo $total_products; ?> products
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Product Modal -->
    <div id="productModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add Product</h2>
                <button class="modal-close" onclick="closeProductModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="productForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="product_id" id="productId">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Product Code *</label>
                            <input type="text" class="form-control" name="product_code" id="productCode" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Product Name *</label>
                            <input type="text" class="form-control" name="product_name" id="productName" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Category</label>
                        <select class="form-control" name="category_id" id="categoryId">
                            <option value="">-- Select Category --</option>
                            <?php 
                            $categories->data_seek(0);
                            while ($cat = $categories->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $cat['category_id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="description" id="description" rows="3"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Cost Price *</label>
                            <input type="number" class="form-control" name="cost_price" id="costPrice" step="0.01" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Selling Price *</label>
                            <input type="number" class="form-control" name="selling_price" id="sellingPrice" step="0.01" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Initial Stock</label>
                            <input type="number" class="form-control" name="stock_quantity" id="stockQuantity" value="0" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label>Reorder Level *</label>
                            <input type="number" class="form-control" name="reorder_level" id="reorderLevel" value="10" min="0" required>
                        </div>
                    </div>
                    
                    <button type="submit" name="add_product" id="submitBtn" class="btn btn-primary" style="width: 100%;">Add Product</button>
                </form>
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
                <form method="POST" action="" id="stockForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="product_id" id="stockProductId">
                    <input type="hidden" name="adjust_stock" value="1">
                    
                    <div class="form-group">
                        <label>Product Name</label>
                        <input type="text" class="form-control" id="stockProductName" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Current Stock</label>
                        <input type="number" class="form-control" id="currentStock" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Adjustment Type</label>
                        <select class="form-control" name="adjustment_type" id="adjustmentType">
                            <option value="add">Add Stock (+)</option>
                            <option value="subtract">Remove Stock (-)</option>
                            <option value="set">Set Stock</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" class="form-control" name="adjustment_quantity" id="adjustmentQuantity" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea class="form-control" name="adjustment_notes" id="adjustmentNotes" rows="3"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Adjust Stock</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Product';
            document.getElementById('productForm').reset();
            document.getElementById('submitBtn').name = 'add_product';
            document.getElementById('submitBtn').textContent = 'Add Product';
            document.getElementById('stockQuantity').disabled = false;
            document.getElementById('productModal').classList.add('active');
        }
        
        function openEditModal(product) {
            document.getElementById('modalTitle').textContent = 'Edit Product';
            document.getElementById('productId').value = product.product_id;
            document.getElementById('productCode').value = product.product_code;
            document.getElementById('productName').value = product.product_name;
            document.getElementById('categoryId').value = product.category_id || '';
            document.getElementById('description').value = product.description || '';
            document.getElementById('costPrice').value = product.cost;
            document.getElementById('sellingPrice').value = product.price;
            document.getElementById('stockQuantity').value = product.stock_quantity;
            document.getElementById('stockQuantity').disabled = true;
            document.getElementById('reorderLevel').value = product.reorder_level;
            document.getElementById('submitBtn').name = 'edit_product';
            document.getElementById('submitBtn').textContent = 'Update Product';
            document.getElementById('productModal').classList.add('active');
        }
        
        function closeProductModal() {
            document.getElementById('productModal').classList.remove('active');
        }
        
        function openStockModal(product) {
            document.getElementById('stockProductId').value = product.product_id;
            document.getElementById('stockProductName').value = product.product_name;
            document.getElementById('currentStock').value = product.stock_quantity;
            document.getElementById('stockModal').classList.add('active');
        }
        
        function closeStockModal() {
            document.getElementById('stockModal').classList.remove('active');
        }
        
        function deleteProduct(id) {
            if (confirm('Are you sure you want to delete this product?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="delete_product" value="1">
                    <input type="hidden" name="product_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                closeProductModal();
            }
        });
    </script>
    <script src="js/hamburger.js"></script>
</body>
</html>