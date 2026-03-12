<?php
/**
 * Voided Sales Admin Page
 * Shows both voided sale items and cancelled carts
 * Features: Pagination (5 per page), date filter, search, statistics
 */

require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';
require_once 'includes/void_functions.php';

setSecurityHeaders();
requireLogin();
checkPageAccess();
requirePermission('authorize_voids');

$user = getCurrentUser();

// Pagination settings
$page = max(1, sanitizeInt($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE; // 5 per page

// Get view type (items or carts)
$viewType = sanitize($_GET['view'] ?? 'items');

// Filters
$dateFilter = !empty($_GET['date']) ? sanitize($_GET['date']) : null;
$search = !empty($_GET['search']) ? sanitize($_GET['search']) : null;

// Build filter params for pagination links
$filterParams = '';
if ($viewType !== 'items') $filterParams .= '&view=' . urlencode($viewType);
if ($dateFilter) $filterParams .= '&date=' . urlencode($dateFilter);
if ($search) $filterParams .= '&search=' . urlencode($search);

// Get statistics
$stats = getVoidStatistics('today');
$weekStats = getVoidStatistics('week');

// Get data based on view type
if ($viewType === 'carts') {
    $voids = getCartVoids($page, $limit, $dateFilter);
    $total = countCartVoids($dateFilter);
} else {
    $voids = getVoidedOrders($page, $limit, $dateFilter, $search);
    $total = countVoidedOrders($dateFilter, $search);
}

$total_pages = ceil($total / $limit);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voided Sales - POPRIE POS</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #fff 0%, #fafafa 100%);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e0e0e0;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .stat-card.danger {
            border-left: 4px solid #d32f2f;
        }
        
        .stat-card.warning {
            border-left: 4px solid #ff9800;
        }
        
        .stat-card h4 {
            font-size: 28px;
            font-weight: 700;
            color: #d32f2f;
            margin: 0 0 8px 0;
        }
        
        .stat-card p {
            font-size: 13px;
            color: #666;
            margin: 0;
        }
        
        .view-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
        }
        
        .view-tab {
            padding: 10px 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            text-decoration: none;
            color: #666;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .view-tab.active {
            background: #d32f2f;
            color: white;
            border-color: #d32f2f;
        }
        
        .view-tab:hover:not(.active) {
            background: #f5f5f5;
        }
        
        .filters-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
            align-items: center;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .badge-warning {
            background: #fff3e0;
            color: #e65100;
        }
        
        .badge-danger {
            background: #ffebee;
            color: #c62828;
        }
        
        .void-row {
            background: #fff9f9 !important;
            border-left: 3px solid #d32f2f;
        }
        
        .pagination-container {
            margin-top: 24px;
            display: flex;
            justify-content: center;
            gap: 8px;
        }
        
        .pagination-controls {
            display: flex;
            gap: 4px;
        }
        
        .pagination-controls a,
        .pagination-controls span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            color: #666;
            transition: all 0.2s ease;
        }
        
        .pagination-controls a:hover {
            background: #d32f2f;
            color: white;
            border-color: #d32f2f;
        }
        
        .pagination-controls a.active {
            background: #d32f2f;
            color: white;
            border-color: #d32f2f;
        }
        
        .void-item-list {
            font-size: 12px;
            margin: 0;
            padding-left: 16px;
        }
        
        .void-item-list li {
            margin: 3px 0;
        }
        
        .amount {
            font-weight: 600;
            color: #d32f2f;
        }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <?php include 'includes/sidebar.php'; ?>
        <div class="main-content">
            <div class="header">
                <h1>Voided Sales</h1>
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

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card danger">
                    <h4><?php echo $stats['total_count']; ?></h4>
                    <p>Voids Today</p>
                </div>
                <div class="stat-card danger">
                    <h4>₱<?php echo number_format($stats['total_amount'], 2); ?></h4>
                    <p>Voided Amount Today</p>
                </div>
                <div class="stat-card warning">
                    <h4><?php echo $weekStats['total_count']; ?></h4>
                    <p>Voids This Week</p>
                </div>
                <div class="stat-card warning">
                    <h4>₱<?php echo number_format($weekStats['total_amount'], 2); ?></h4>
                    <p>Voided Amount (Week)</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Void Audit Log</h3>
                </div>
                <div class="card-body">
                    <!-- View Type Tabs -->
                    <div class="view-tabs">
                        <a href="?view=items<?php echo $dateFilter ? '&date=' . urlencode($dateFilter) : ''; ?>" 
                           class="view-tab <?php echo $viewType === 'items' ? 'active' : ''; ?>">
                            Voided Items (<?php echo countVoidedOrders($dateFilter); ?>)
                        </a>
                        <a href="?view=carts<?php echo $dateFilter ? '&date=' . urlencode($dateFilter) : ''; ?>" 
                           class="view-tab <?php echo $viewType === 'carts' ? 'active' : ''; ?>">
                            Cancelled Carts (<?php echo countCartVoids($dateFilter); ?>)
                        </a>
                    </div>

                    <!-- Filters -->
                    <div class="filters-row">
                        <input type="date" class="form-control" id="dateFilter" style="width: auto;" 
                               value="<?php echo htmlspecialchars($dateFilter ?? ''); ?>">
                        <?php if ($viewType === 'items'): ?>
                        <input type="text" class="form-control" id="searchFilter" placeholder="Search..." 
                               style="width: 200px;" value="<?php echo htmlspecialchars($search ?? ''); ?>">
                        <?php endif; ?>
                        <button class="btn btn-primary btn-sm" onclick="applyFilters()">Filter</button>
                        <?php if ($dateFilter || $search): ?>
                            <a href="?view=<?php echo $viewType; ?>" class="btn btn-secondary btn-sm">Clear</a>
                        <?php endif; ?>
                    </div>

                    <!-- Data Table -->
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <?php if ($viewType === 'items'): ?>
                                        <th>Invoice</th>
                                        <th>Product</th>
                                        <th>Cup Size</th>
                                        <th>Qty</th>
                                        <th>Amount</th>
                                        <th>Cashier</th>
                                        <th>Admin</th>
                                        <th>Reason</th>
                                        <th>Restored</th>
                                        <th>Date/Time</th>
                                    <?php else: ?>
                                        <th>Date/Time</th>
                                        <th>Requested By</th>
                                        <th>Authorized By</th>
                                        <th>Reason</th>
                                        <th>Cart Items</th>
                                        <th>Amount</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($voids)): ?>
                                    <?php foreach ($voids as $row): ?>
                                        <tr class="void-row">
                                            <?php if ($viewType === 'items'): ?>
                                                <td><code><?php echo htmlspecialchars($row['invoice_number']); ?></code></td>
                                                <td><strong><?php echo htmlspecialchars($row['product_name'] ?? 'N/A'); ?></strong></td>
                                                <td><?php echo htmlspecialchars($row['cup_size'] ?? '-'); ?></td>
                                                <td><?php echo $row['quantity']; ?></td>
                                                <td class="amount">₱<?php echo number_format($row['total_amount'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($row['cashier_name'] ?? 'Unknown'); ?></td>
                                                <td><?php echo htmlspecialchars($row['admin_name'] ?? 'Unknown'); ?></td>
                                                <td style="max-width: 200px;"><?php echo htmlspecialchars($row['void_reason']); ?></td>
                                                <td>
                                                    <?php if ($row['cups_restored']): ?>
                                                        <span class="badge badge-success">Cups</span>
                                                    <?php endif; ?>
                                                    <?php if ($row['ingredients_restored']): ?>
                                                        <span class="badge badge-success">Ingredients</span>
                                                    <?php endif; ?>
                                                    <?php if (!$row['cups_restored'] && !$row['ingredients_restored']): ?>
                                                        <span class="badge badge-warning">Stock Only</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('M d, Y H:i', strtotime($row['voided_at'])); ?></td>
                                            <?php else: ?>
                                                <td><?php echo date('M d, Y H:i', strtotime($row['voided_at'])); ?></td>
                                                <td><?php echo htmlspecialchars($row['requester_name'] ?? 'Unknown'); ?></td>
                                                <td><?php echo htmlspecialchars($row['admin_name'] ?? 'Unknown'); ?></td>
                                                <td style="max-width: 200px;"><?php echo htmlspecialchars($row['void_reason']); ?></td>
                                                <td>
                                                    <?php
                                                    if (!empty($row['cart_items'])) {
                                                        $items = json_decode($row['cart_items'], true);
                                                        if (is_array($items) && count($items) > 0) {
                                                            echo '<ul class="void-item-list">';
                                                            foreach (array_slice($items, 0, 5) as $item) {
                                                                $name = $item['name'] ?? $item['product_name'] ?? 'Unknown';
                                                                $qty = $item['quantity'] ?? 1;
                                                                echo '<li>' . htmlspecialchars($name) . ' ×' . intval($qty) . '</li>';
                                                            }
                                                            if (count($items) > 5) {
                                                                echo '<li><em>+' . (count($items) - 5) . ' more...</em></li>';
                                                            }
                                                            echo '</ul>';
                                                        } else {
                                                            echo '<em style="color:#999;">Empty cart</em>';
                                                        }
                                                    } else {
                                                        echo '<em style="color:#999;">N/A</em>';
                                                    }
                                                    ?>
                                                </td>
                                                <td class="amount">₱<?php echo number_format($row['total_amount'] ?? 0, 2); ?></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="<?php echo $viewType === 'items' ? 10 : 6; ?>" style="text-align:center; padding:40px; color: #999;">
                                            No voided records found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-container">
                            <div class="pagination-controls">
                                <?php if ($page > 1): ?>
                                    <a href="?page=1<?php echo $filterParams; ?>">« First</a>
                                    <a href="?page=<?php echo $page - 1; ?><?php echo $filterParams; ?>">‹ Prev</a>
                                <?php endif; ?>
                                
                                <?php
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);
                                
                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                    <a href="?page=<?php echo $i; ?><?php echo $filterParams; ?>" 
                                       class="<?php echo $i === $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?><?php echo $filterParams; ?>">Next ›</a>
                                    <a href="?page=<?php echo $total_pages; ?><?php echo $filterParams; ?>">Last »</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="text-align: center; margin-top: 12px; color: #888; font-size: 13px;">
                            Showing <?php echo (($page - 1) * $limit) + 1; ?> - <?php echo min($page * $limit, $total); ?> of <?php echo $total; ?> records
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    function applyFilters() {
        const date = document.getElementById('dateFilter').value;
        const search = document.getElementById('searchFilter')?.value || '';
        const view = '<?php echo $viewType; ?>';
        
        let url = 'voids.php?view=' + view;
        if (date) url += '&date=' + encodeURIComponent(date);
        if (search) url += '&search=' + encodeURIComponent(search);
        
        window.location = url;
    }
    
    // Enter key to filter
    document.querySelectorAll('#dateFilter, #searchFilter').forEach(el => {
        el?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') applyFilters();
        });
    });
    </script>
</body>
</html>