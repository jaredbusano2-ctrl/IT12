<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';

setSecurityHeaders();
requireLogin();
checkPageAccess();
requirePermission('manage_categories');

$user = getCurrentUser();

// Resolve return page (used to preserve pagination after POST)
$returnPage = 1;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnPage = isset($_POST['return_page']) ? max(1, (int)$_POST['return_page']) : 1;
} else {
    $returnPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
}

// Validate CSRF for POST operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFRequest();
}

// Generate one CSRF token for this page and reuse it for all POSTs
// NOTE: This MUST run after validateCSRFRequest() for POST requests.
$csrfToken = generateCSRFToken();

// Handle category operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_category'])) {
        $stmt = $conn->prepare("INSERT INTO categories (category_name, description) VALUES (?, ?)");
        $stmt->bind_param("ss", $_POST['category_name'], $_POST['description']);
        $stmt->execute();
        logActivity('category_added', "Added category: " . sanitize($_POST['category_name']));
        header('Location: categories.php?page=' . $returnPage . '&success=added');
        exit();
    } elseif (isset($_POST['edit_category'])) {
        $category_id = intval($_POST['category_id'] ?? 0);
        if ($category_id < 1) {
            header('Location: categories.php?page=' . $returnPage . '&error=invalid_category');
            exit();
        }
        $stmt = $conn->prepare("UPDATE categories SET category_name=?, description=? WHERE category_id=?");
        $stmt->bind_param("ssi", $_POST['category_name'], $_POST['description'], $category_id);

        if (!$stmt->execute()) {
            error_log('Edit category failed (ID ' . $category_id . '): ' . $stmt->error);
            header('Location: categories.php?page=' . $returnPage . '&error=update_failed');
            exit();
        }

        if ($stmt->affected_rows < 1) {
            // Could be not found OR values unchanged; treat as not_updated for feedback
            header('Location: categories.php?page=' . $returnPage . '&error=not_updated');
            exit();
        }

        header('Location: categories.php?page=' . $returnPage . '&success=updated');
        exit();
    } elseif (isset($_POST['delete_category'])) {
        $category_id = intval($_POST['category_id']);
        
        // Check if category has associated products
        $product_check = $conn->query("SELECT COUNT(*) as count FROM products WHERE category_id=$category_id AND status='active'")->fetch_assoc();
        
        if ($product_check['count'] > 0) {
            // Cannot delete category with products
            header('Location: categories.php?page=' . $returnPage . '&error=has_products');
            exit();
        }
        
        // Hard delete category
        $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
        $stmt->bind_param("i", $category_id);

        if (!$stmt->execute()) {
            error_log('Delete category failed (ID ' . $category_id . '): ' . $stmt->error);
            header('Location: categories.php?page=' . $returnPage . '&error=delete_failed');
            exit();
        }

        if ($stmt->affected_rows < 1) {
            header('Location: categories.php?page=' . $returnPage . '&error=not_found');
            exit();
        }

        header('Location: categories.php?page=' . $returnPage . '&success=deleted');
        exit();
    }
}

// Get pagination information
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 5;
$offset = ($page - 1) * $limit;

// Get total category count
$total_categories = $conn->query("
    SELECT COUNT(*) as count 
    FROM categories
")->fetch_assoc()['count'];
$total_pages = ceil($total_categories / $limit);

// Get categories with pagination
$categories = $conn->query("
    SELECT c.*, COUNT(p.product_id) as product_count 
    FROM categories c 
    LEFT JOIN products p ON c.category_id = p.category_id AND p.status = 'active'
    GROUP BY c.category_id 
    ORDER BY c.category_name ASC
    LIMIT $limit OFFSET $offset
");

// Optional: server-side modal state (works even if JS fails)
$modalMode = null; // 'add' | 'edit' | null
$editCategory = null;
if (isset($_GET['add'])) {
    $modalMode = 'add';
}
if (isset($_GET['edit'])) {
    $modalMode = 'edit';
    $editId = max(1, (int)$_GET['edit']);
    $editStmt = $conn->prepare('SELECT category_id, category_name, description FROM categories WHERE category_id = ?');
    $editStmt->bind_param('i', $editId);
    $editStmt->execute();
    $editCategory = $editStmt->get_result()->fetch_assoc();
    $editStmt->close();
    if (!$editCategory) {
        // If invalid id, just fall back to normal view
        $modalMode = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - POS & Inventory System</title>
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
                <h1>Categories</h1>
                <div class="header-actions">
                    <a class="btn btn-primary btn-sm" href="categories.php?page=<?php echo $page; ?>&add=1">+ Add Category</a>
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
                    Category <?php echo htmlspecialchars($_GET['success']); ?> successfully!
                </div>
            <?php endif; ?>

            <?php if (!empty($_SESSION['flash_message'])): ?>
                <div class="alert <?php echo ($_SESSION['flash_type'] ?? '') === 'error' ? 'alert-danger' : 'alert-success'; ?>" style="margin-bottom: 24px;">
                    <?php echo htmlspecialchars((string)$_SESSION['flash_message']); ?>
                </div>
                <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
            <?php endif; ?>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger" style="margin-bottom: 24px; background: #fee2e2; color: #7f1d1d; border: 1px solid #fca5a5;">
                    <?php 
                    if ($_GET['error'] === 'has_products') {
                        echo "Cannot delete category because it has associated products.";
                    } elseif ($_GET['error'] === 'invalid_category') {
                        echo "Invalid category selected.";
                    } elseif ($_GET['error'] === 'not_updated') {
                        echo "No changes were saved (category not found or values unchanged).";
                    } elseif ($_GET['error'] === 'update_failed') {
                        echo "Failed to update category. Please try again.";
                    } elseif ($_GET['error'] === 'delete_failed') {
                        echo "Failed to delete category. It may be referenced by other records.";
                    } elseif ($_GET['error'] === 'not_found') {
                        echo "Category not found.";
                    } else {
                        echo "An error occurred: " . htmlspecialchars($_GET['error']);
                    }
                    ?>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h3>All Categories</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Category Name</th>
                                    <th>Description</th>
                                    <th>Products</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($category = $categories->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($category['category_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($category['description']); ?></td>
                                        <td><span class="badge badge-primary"><?php echo $category['product_count']; ?> products</span></td>
                                        <td><?php echo date('M d, Y', strtotime($category['created_at'])); ?></td>
                                        <td>
                                            <a class="btn btn-warning btn-sm" href="categories.php?page=<?php echo $page; ?>&edit=<?php echo (int)$category['category_id']; ?>">Edit</a>
                                            <form method="POST" action="categories.php?page=<?php echo $page; ?>" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="return_page" value="<?php echo (int)$page; ?>">
                                                <input type="hidden" name="category_id" value="<?php echo (int)$category['category_id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" name="delete_category" value="1" <?php echo ((int)$category['product_count'] > 0) ? 'disabled' : ''; ?>>Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination Controls -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-container">
                            <div class="pagination-controls">
                                <?php if ($page > 1): ?>
                                    <a href="?page=1">« First</a>
                                    <a href="?page=<?php echo $page - 1; ?>">‹ Previous</a>
                                <?php endif; ?>
                                
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                if ($start_page > 1) {
                                    echo '<a href="?page=1">1</a>';
                                    if ($start_page > 2) {
                                        echo '<span class="dots">...</span>';
                                    }
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <a href="?page=<?php echo $i; ?>" 
                                       class="<?php echo $i == $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php
                                endfor;
                                
                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) {
                                        echo '<span class="dots">...</span>';
                                    }
                                    echo '<a href="?page=' . $total_pages . '">' . $total_pages . '</a>';
                                }
                                ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>">Next ›</a>
                                    <a href="?page=<?php echo $total_pages; ?>">Last »</a>
                                <?php endif; ?>
                            </div>
                            <div class="pagination-info">
                                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Category Modal -->
    <div id="categoryModal" class="modal <?php echo $modalMode ? 'active' : ''; ?>">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle"><?php echo $modalMode === 'edit' ? 'Edit Category' : 'Add Category'; ?></h2>
                <a class="modal-close" href="categories.php?page=<?php echo $page; ?>" style="text-decoration:none;">&times;</a>
            </div>
            <div class="modal-body">
                <form method="POST" action="categories.php?page=<?php echo (int)$page; ?>" id="categoryForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="return_page" value="<?php echo (int)$page; ?>">
                    <input type="hidden" name="category_id" id="categoryId" value="<?php echo (int)($editCategory['category_id'] ?? 0); ?>">
                    
                    <div class="form-group">
                        <label>Category Name *</label>
                        <input type="text" class="form-control" name="category_name" id="categoryName" required value="<?php echo htmlspecialchars($editCategory['category_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="description" id="description" rows="3"><?php echo htmlspecialchars($editCategory['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                    
                    <button type="submit" name="<?php echo $modalMode === 'edit' ? 'edit_category' : 'add_category'; ?>" id="submitBtn" class="btn btn-primary" style="width: 100%;">
                        <?php echo $modalMode === 'edit' ? 'Update Category' : 'Add Category'; ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script></script>
</body>
</html>
