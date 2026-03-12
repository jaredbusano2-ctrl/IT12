<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';
require_once 'includes/inventory_functions.php';

// Set security headers
setSecurityHeaders();

requireLogin();

// Check page access based on role
checkPageAccess();

$user = getCurrentUser();

// Redirect cashiers to POS
if ($user['role'] === 'cashier') {
    header('Location: pos.php');
    exit();
}

// Get low stock alerts
$lowStockAlerts = getLowStockAlerts();

// Get statistics
$stats = [];

// Total Products
$result = $conn->query("SELECT COUNT(*) as count FROM products WHERE status = 'active'");
$stats['total_products'] = $result->fetch_assoc()['count'];

// Low Stock Products
$result = $conn->query("SELECT COUNT(*) as count FROM products WHERE stock_quantity <= reorder_level AND status = 'active'");
$stats['low_stock'] = $result->fetch_assoc()['count'];

// Today's Sales
$result = $conn->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM sales WHERE DATE(sale_date) = CURDATE()");
$today = $result->fetch_assoc();
$stats['today_sales'] = $today['total'];
$stats['today_transactions'] = $today['count'];

// Total Inventory Value
$result = $conn->query("SELECT COALESCE(SUM(stock_quantity * cost), 0) as value FROM products WHERE status = 'active'");
$stats['inventory_value'] = $result->fetch_assoc()['value'];

// Recent Sales with pagination
$page = $_GET['sales_page'] ?? 1;
$limit = 5;
$offset = ($page - 1) * $limit;

// Get total recent sales count
$total_recent_sales = $conn->query("SELECT COUNT(*) as count FROM sales")->fetch_assoc()['count'];
$total_sales_pages = ceil($total_recent_sales / $limit);

$recent_sales = $conn->query("
    SELECT s.*, u.full_name 
    FROM sales s 
    LEFT JOIN users u ON s.user_id = u.user_id 
    ORDER BY s.sale_date DESC 
    LIMIT $limit OFFSET $offset
");

// Low Stock Products
$low_stock_products = $conn->query("
    SELECT p.*, c.category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.category_id 
    WHERE p.stock_quantity <= p.reorder_level AND p.status = 'active'
    ORDER BY p.stock_quantity ASC 
    LIMIT 10
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - POPRIE</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Admin Dashboard POS-Style Overrides */
        .header-actions .user-info {
            background: #FFFFFF;
            padding: 10px 14px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            border: 1px solid #f5f5f5;
        }
        
        .btn-logout {
            background: #DC3545;
            color: #FFFFFF;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        
        .btn-logout:hover {
            background: #C82333;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(220, 53, 69, 0.3);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: #FFFFFF;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #f5f5f5;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        }
        
        .stat-card-header {
            padding: 20px 20px 0;
        }
        
        .stat-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-card-icon.primary {
            background: linear-gradient(135deg, #d32f2f 0%, #c62828 100%);
            color: white;
        }
        
        .stat-card-icon.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .stat-card-icon.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        .stat-card-icon.danger {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            color: white;
        }
        
        .stat-card-body {
            padding: 16px 20px 20px;
        }
        
        .stat-card-body h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #1a1a1a;
        }
        
        .card {
            background: #FFFFFF;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #f5f5f5;
            margin-bottom: 24px;
        }
        
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #1a1a1a;
            margin: 0;
        }
        
        .card-body {
            padding: 0;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #fafafa;
        }
        
        th {
            padding: 14px 20px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            color: #666;
            border-bottom: 1px solid #f0f0f0;
        }
        
        td {
            padding: 14px 20px;
            font-size: 14px;
            color: #444;
            border-bottom: 1px solid #f5f5f5;
        }
        
        tbody tr:hover {
            background: #fafafa;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #d32f2f 0%, #c62828 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-block;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(211, 47, 47, 0.3);
        }
        
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-success {
            background: #dcfce7;
            color: #166534;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            padding: 20px;
        }
        
        .pagination a {
            padding: 8px 14px;
            border-radius: 8px;
            background: #f5f5f5;
            color: #444;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .pagination a:hover {
            background: #d32f2f;
            color: white;
        }
        
        .pagination .active {
            background: #d32f2f;
            color: white;
        }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>Dashboard</h1>
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
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-icon primary">
                            📦
                        </div>
                    </div>
                    <div class="stat-card-body">
                        <h3>Total Products</h3>
                        <div class="stat-value"><?php echo number_format($stats['total_products']); ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-icon success">
                            💰
                        </div>
                    </div>
                    <div class="stat-card-body">
                        <h3>Today's Sales</h3>
                        <div class="stat-value">₱<?php echo number_format($stats['today_sales'], 2); ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-icon warning">
                            ⚠️
                        </div>
                    </div>
                    <div class="stat-card-body">
                        <h3>Low Stock Items</h3>
                        <div class="stat-value"><?php echo number_format($stats['low_stock']); ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-icon danger">
                            📊
                        </div>
                    </div>
                    <div class="stat-card-body">
                        <h3>Inventory Value</h3>
                        <div class="stat-value">₱<?php echo number_format($stats['inventory_value'], 2); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Security Alerts Section (Admin Only) -->
            <?php if (isAdmin()): ?>
            <div id="securityAlerts" class="security-alerts-container" style="display: none;">
                <div class="card security-card">
                    <div class="card-header">
                        <h3>🔒 Security Overview</h3>
                        <span id="securityLastUpdate" style="font-size: 11px; color: #888;"></span>
                    </div>
                    <div class="card-body">
                        <div class="security-stats-grid">
                            <div class="security-stat">
                                <span class="security-stat-value" id="failedLoginsToday">-</span>
                                <span class="security-stat-label">Failed Logins Today</span>
                            </div>
                            <div class="security-stat">
                                <span class="security-stat-value" id="lockedAccounts">-</span>
                                <span class="security-stat-label">Locked Accounts</span>
                            </div>
                            <div class="security-stat">
                                <span class="security-stat-value" id="voidsToday">-</span>
                                <span class="security-stat-label">Voids Today</span>
                            </div>
                            <div class="security-stat">
                                <span class="security-stat-value" id="activeUsersToday">-</span>
                                <span class="security-stat-label">Active Users</span>
                            </div>
                        </div>
                        
                        <div id="securityAlertsList" class="security-alerts-list" style="display: none;">
                            <h4 style="margin: 16px 0 8px; color: #c62828;">⚠️ Security Alerts</h4>
                            <div id="alertsContent"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <style>
                .security-alerts-container {
                    margin-bottom: 24px;
                }
                
                .security-card {
                    border-left: 4px solid #1976d2;
                }
                
                .security-card.has-alerts {
                    border-left-color: #c62828;
                }
                
                .security-stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
                    gap: 16px;
                }
                
                .security-stat {
                    text-align: center;
                    padding: 12px;
                    background: #f5f5f5;
                    border-radius: 8px;
                }
                
                .security-stat-value {
                    display: block;
                    font-size: 24px;
                    font-weight: 700;
                    color: #333;
                }
                
                .security-stat-value.alert {
                    color: #c62828;
                }
                
                .security-stat-label {
                    font-size: 11px;
                    color: #666;
                    text-transform: uppercase;
                }
                
                .security-alerts-list {
                    margin-top: 16px;
                    padding-top: 16px;
                    border-top: 1px solid #eee;
                }
                
                .security-alert-item {
                    padding: 8px 12px;
                    background: #ffebee;
                    border-radius: 6px;
                    margin-bottom: 8px;
                    font-size: 13px;
                    border-left: 3px solid #c62828;
                }
                
                .security-alert-item.high { border-left-color: #c62828; }
                .security-alert-item.medium { border-left-color: #ff9800; }
                .security-alert-item.low { border-left-color: #ffc107; background: #fff8e1; }
                
                .alert-time {
                    font-size: 10px;
                    color: #888;
                }
            </style>
            
            <script>
                // Fetch security dashboard data
                async function fetchSecurityData() {
                    try {
                        const response = await fetch('api/get-security-dashboard.php');
                        const data = await response.json();
                        
                        if (data.success) {
                            // Show the security section
                            document.getElementById('securityAlerts').style.display = 'block';
                            
                            // Update stats
                            const failedLogins = data.security.failed_logins_today;
                            const lockedAccounts = data.security.locked_accounts;
                            const voidsToday = data.voids.count_today;
                            const activeUsers = data.activity.active_users_today;
                            
                            document.getElementById('failedLoginsToday').textContent = failedLogins;
                            document.getElementById('failedLoginsToday').className = 
                                'security-stat-value' + (failedLogins > 5 ? ' alert' : '');
                            
                            document.getElementById('lockedAccounts').textContent = lockedAccounts;
                            document.getElementById('lockedAccounts').className = 
                                'security-stat-value' + (lockedAccounts > 0 ? ' alert' : '');
                            
                            document.getElementById('voidsToday').textContent = voidsToday;
                            document.getElementById('activeUsersToday').textContent = activeUsers;
                            
                            // Show alerts if any
                            const alertsList = document.getElementById('securityAlertsList');
                            const alertsContent = document.getElementById('alertsContent');
                            const securityCard = document.querySelector('.security-card');
                            
                            if (data.security.alerts.length > 0 || 
                                data.security.suspicious_activities.length > 0) {
                                alertsList.style.display = 'block';
                                securityCard.classList.add('has-alerts');
                                
                                let alertsHtml = '';
                                
                                // Security alerts
                                data.security.alerts.forEach(alert => {
                                    alertsHtml += `
                                        <div class="security-alert-item ${alert.severity}">
                                            <strong>${alert.alert_type}</strong>: ${escapeHtml(alert.description)}
                                            <span class="alert-time">${alert.created_at}</span>
                                        </div>
                                    `;
                                });
                                
                                // Suspicious activities
                                data.security.suspicious_activities.forEach(activity => {
                                    alertsHtml += `
                                        <div class="security-alert-item medium">
                                            <strong>${activity.action}</strong>: ${escapeHtml(activity.details || '')}
                                            <span class="alert-time">${activity.created_at}</span>
                                        </div>
                                    `;
                                });
                                
                                alertsContent.innerHTML = alertsHtml;
                            } else {
                                alertsList.style.display = 'none';
                                securityCard.classList.remove('has-alerts');
                            }
                            
                            // Update timestamp
                            document.getElementById('securityLastUpdate').textContent = 
                                'Updated: ' + new Date().toLocaleTimeString();
                        }
                    } catch (error) {
                        console.error('Security dashboard error:', error);
                    }
                }
                
                function escapeHtml(text) {
                    if (!text) return '';
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }
                
                // Fetch on load and every 30 seconds
                document.addEventListener('DOMContentLoaded', function() {
                    fetchSecurityData();
                    setInterval(fetchSecurityData, 30000);
                });
            </script>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h3>Recent Sales</h3>
                    <a href="sales.php" class="btn btn-primary btn-sm">View All</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Customer</th>
                                    <th>Cashier</th>
                                    <th>Amount</th>
                                    <th>Payment</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recent_sales->num_rows > 0): ?>
                                    <?php while ($sale = $recent_sales->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($sale['invoice_number']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($sale['customer_name'] ?: 'Walk-in'); ?></td>
                                            <td><?php echo htmlspecialchars($sale['full_name']); ?></td>
                                            <td><strong>₱<?php echo number_format($sale['total_amount'], 2); ?></strong></td>
                                            <td><span class="badge badge-primary"><?php echo ucfirst($sale['payment_method']); ?></span></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($sale['sale_date'])); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: var(--text-secondary);">No sales yet</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Recent Sales Pagination -->
                    <?php if ($total_sales_pages > 1): ?>
                        <div class="pagination" style="display: flex; justify-content: center; gap: 8px; margin-top: 16px;">
                            <?php if ($page > 1): ?>
                                <a href="?sales_page=<?php echo $page - 1; ?>" class="btn btn-secondary btn-sm">« Previous</a>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_sales_pages; $i++): ?>
                                <?php if ($i == $page): ?>
                                    <span class="btn btn-primary btn-sm"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="?sales_page=<?php echo $i; ?>" class="btn btn-secondary btn-sm"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_sales_pages): ?>
                                <a href="?sales_page=<?php echo $page + 1; ?>" class="btn btn-secondary btn-sm">Next »</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div style="text-align: center; margin-top: 12px; color: var(--text-secondary); font-size: 12px;">
                        Showing <?php echo ($offset + 1) . ' - ' . min($offset + $limit, $total_recent_sales); ?> of <?php echo $total_recent_sales; ?> recent sales
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3>Low Stock Alert</h3>
                    <a href="inventory.php" class="btn btn-warning btn-sm">Manage Inventory</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Product Code</th>
                                    <th>Product Name</th>
                                    <th>Category</th>
                                    <th>Stock</th>
                                    <th>Reorder Level</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($low_stock_products->num_rows > 0): ?>
                                    <?php while ($product = $low_stock_products->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($product['product_code']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                            <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                            <td><strong><?php echo $product['stock_quantity']; ?></strong></td>
                                            <td><?php echo $product['reorder_level']; ?></td>
                                            <td>
                                                <?php if ($product['stock_quantity'] == 0): ?>
                                                    <span class="badge badge-danger">Out of Stock</span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">Low Stock</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: var(--text-secondary);">All products are well stocked!</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
