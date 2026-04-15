<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';

setSecurityHeaders();
requireLogin();
checkPageAccess();
requirePermission('manage_users'); // Only admin can access this page
$user = getCurrentUser();

// Handle user actions
$message = '';
$error = '';

// Handle add/edit user
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $username = $_POST['username'] ?? '';
    $full_name = $_POST['full_name'] ?? '';
    $role = $_POST['role'] ?? 'cashier';
    $password = $_POST['password'] ?? '';
    $user_id = $_POST['user_id'] ?? '';
    
    try {
        if ($action === 'add') {
            // Validate
            if (empty($username) || empty($full_name) || empty($password)) {
                throw new Exception("All fields are required");
            }
            
            // Check if username exists
            $check = $conn->prepare("SELECT user_id FROM users WHERE username = ?" . ($user_id ? " AND user_id != ?" : ""));
            if ($user_id) {
                $check->bind_param("si", $username, $user_id);
            } else {
                $check->bind_param("s", $username);
            }
            $check->execute();
            
            if ($check->get_result()->num_rows > 0) {
                throw new Exception("Username already exists");
            }
            
            // Add/update user
            if ($user_id) {
                // Update user
                $sql = "UPDATE users SET username = ?, full_name = ?, role = ?" . ($password ? ", password = ?" : "") . " WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                if ($password) {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt->bind_param("ssssi", $username, $full_name, $role, $passwordHash, $user_id);
                } else {
                    $stmt->bind_param("sssi", $username, $full_name, $role, $user_id);
                }
                $stmt->execute();
                $message = "User updated successfully!";
            } else {
                // Add new user
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $username, $passwordHash, $full_name, $role);
                $stmt->execute();
                $message = "User added successfully!";
            }
            
        } elseif ($action === 'delete') {
            $user_id = $_POST['user_id'] ?? '';
            if ($user_id == $_SESSION['user_id']) {
                throw new Exception("You cannot delete your own account");
            }
            
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $message = "User deleted successfully!";
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get pagination information
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 5;
$offset = ($page - 1) * $limit;

// Get total user count
$total_users = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$total_pages = ceil($total_users / $limit);

// Get users with pagination
$users = $conn->query("SELECT user_id, username, full_name, role, created_at FROM users ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - POPRIE</title>
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
                <h1>User Management</h1>
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
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h3>Add New User</h3>
                </div>
                <div class="card-body">
                    <form method="POST" class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" class="form-control" name="full_name" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Role</label>
                            <select class="form-control" name="role" required>
                                <option value="cashier">Cashier</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Password</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        
                        <div style="grid-column: 1 / -1;">
                            <button type="submit" class="btn btn-primary">Add User</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3>Existing Users</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Role</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($user = $users->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $user['user_id']; ?></td>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $user['role'] === 'admin' ? 'badge-primary' : 'badge-secondary'; ?>">
                                                <?php echo ucfirst($user['role']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                        <td>
                                            <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                                <button onclick="editUser(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['full_name']); ?>', '<?php echo $user['role']; ?>')" class="btn btn-sm btn-primary">Edit</button>
                                                <button onclick="deleteUser(<?php echo $user['user_id']; ?>)" class="btn btn-sm btn-danger">Delete</button>
                                            <?php else: ?>
                                                <span class="text-muted">Current User</span>
                                            <?php endif; ?>
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
    
    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit User</h2>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editUserForm">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="user_id"     id="editUserId">
                    
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" class="form-control" name="username" id="editUsername" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" class="form-control" name="full_name" id="editFullName" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Role</label>
                        <select class="form-control" name="role" id="editRole" required>
                            <option value="cashier">Cashier</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>New Password (leave empty to keep current)</label>
                        <input type="password" class="form-control" name="password" id="editPassword">
                    </div>
                    
                    <div style="display: flex; gap: 8px; margin-top: 10x;">
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function editUser(id, username, fullName, role) {
            document.getElementById('editUserId').value = id;
            document.getElementById('editUsername').value = username;
            document.getElementById('editFullName').value = fullName;
            document.getElementById('editRole').value = role;
            document.getElementById('editPassword').value = '';
            document.getElementById('editUserModal').classList.add('active');
        }
        
        function closeEditModal() {
            document.getElementById('editUserModal').classList.remove('active');
        }
        
        function deleteUser(userId) {
            if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="${userId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editUserModal');
            if (event.target === modal) {
                closeEditModal();
            }
        }
    </script>
</body>
</html>
