<?php
/**
 * Activity Logs Page - Admin Only
 * Real-time system activity monitoring with filtering and pagination.
 */

require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';

setSecurityHeaders();
requireLogin();
checkPageAccess();
requirePermission('view_activity_logs');

$user = getCurrentUser();

// Filters
$filterAction = sanitize($_GET['action'] ?? '');
$filterUser = sanitizeInt($_GET['user'] ?? 0);
$filterDate = sanitize($_GET['date'] ?? '');
$page = max(1, sanitizeInt($_GET['page'] ?? 1));
$realtime = !isset($_GET['page']);

// Build query
$where = [];
$params = [];

if ($filterAction) {
    $where[] = "al.action LIKE ?";
    $params[] = "%$filterAction%";
}
if ($filterUser) {
    $where[] = "al.user_id = ?";
    $params[] = $filterUser;
}
if ($filterDate) {
    $where[] = "DATE(al.created_at) = ?";
    $params[] = $filterDate;
}

$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Get total count
$countSql = "SELECT COUNT(*) as cnt FROM activity_logs al $whereClause";
$total = dbFetchOne($countSql, $params)['cnt'] ?? 0;
$totalPages = ceil($total / ITEMS_PER_PAGE);
$offset = ($page - 1) * ITEMS_PER_PAGE;

// Get logs - try new schema first, fallback to old
try {
    $sql = "SELECT al.*, COALESCE(al.description, al.details) as details, u.full_name, u.username, u.role
            FROM activity_logs al 
            LEFT JOIN users u ON al.user_id = u.user_id 
            $whereClause 
            ORDER BY al.created_at DESC 
            LIMIT " . ITEMS_PER_PAGE . " OFFSET $offset";
    $logs = dbFetchAll($sql, $params);
} catch (Exception $e) {
    $sql = "SELECT al.*, al.details, u.full_name, u.username, u.role
            FROM activity_logs al 
            LEFT JOIN users u ON al.user_id = u.user_id 
            $whereClause 
            ORDER BY al.created_at DESC 
            LIMIT " . ITEMS_PER_PAGE . " OFFSET $offset";
    $logs = dbFetchAll($sql, $params);
}

// Get users for dropdown
$users = dbFetchAll("SELECT user_id, full_name, username FROM users ORDER BY full_name");

// Get action types for dropdown
$actionTypes = dbFetchAll("SELECT DISTINCT action FROM activity_logs ORDER BY action");

// Today's stats
$todayStats = dbFetchOne("
    SELECT 
        COUNT(*) as total,
        COUNT(DISTINCT user_id) as unique_users
    FROM activity_logs 
    WHERE DATE(created_at) = CURDATE()
");
// Get last log_id for real-time polling
$lastLogId = 0;
if (!empty($logs)) {
    $lastLogId = max(array_column($logs, 'log_id'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - POPRIE POS</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .filter-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 20px;
            padding: 16px;
            background: #f9f9f9;
            border-radius: 8px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: #666;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            min-width: 160px;
        }
        
        .action-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .action-badge.login { background: #e3f2fd; color: #1565c0; }
        .action-badge.logout { background: #f3e5f5; color: #7b1fa2; }
        .action-badge.sale { background: #e8f5e9; color: #2e7d32; }
        .action-badge.void { background: #ffebee; color: #c62828; }
        .action-badge.restock { background: #e0f7fa; color: #00838f; }
        .action-badge.adjustment { background: #fff3e0; color: #ef6c00; }
        .action-badge.product { background: #fce4ec; color: #c2185b; }
        .action-badge.user { background: #e8eaf6; color: #3f51b5; }
        .action-badge.security { background: #ffcdd2; color: #b71c1c; }
        .action-badge.default { background: #f5f5f5; color: #616161; }
        
        .role-badge {
            font-size: 9px;
            padding: 1px 5px;
            border-radius: 3px;
            margin-left: 4px;
            text-transform: uppercase;
        }
        
        .role-badge.admin-badge { background: #d32f2f; color: white; }
        .role-badge.cashier-badge { background: #1976d2; color: white; }
        
        /* Real-time update styles */
        .new-log-row {
            animation: highlightNew 2s ease-out;
        }
        
        @keyframes highlightNew {
            0% { background-color: #fff9c4; }
            100% { background-color: transparent; }
        }
        
        .realtime-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .realtime-card button {
            margin-bottom: 8px;
        }
        
        .realtime-card p {
            font-size: 11px;
            margin: 0;
        }
        
        #lastUpdated {
            color: #888;
            font-size: 10px;
        }

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
        
        .stat-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
        
        .details-column {
            max-width: 300px;
            word-wrap: break-word;
            font-size: 13px;
            color: #555;
        }
        
        .ip-badge {
            font-family: monospace;
            font-size: 11px;
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 4px;
        }
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
                    <h1>Activity Logs</h1>
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
            
            <!-- Stats -->
            <div class="stat-cards">
                <div class="stat-card">
                    <h3><?php echo $total; ?></h3>
                    <p>Total Logs (Filtered)</p>
                </div>
                <div class="stat-card">
                    <h3 id="statTotalToday"><?php echo $todayStats['total'] ?? 0; ?></h3>
                    <p>Today's Activities</p>
                </div>
                <div class="stat-card">
                    <h3 id="statUniqueUsers"><?php echo $todayStats['unique_users'] ?? 0; ?></h3>
                    <p>Active Users Today</p>
                </div>
                <div class="stat-card realtime-card">
                    <button id="realtimeBtn" class="btn <?php echo $realtime ? 'btn-primary' : 'btn-secondary'; ?>" onclick="toggleRealtime()">
                        <?php echo $realtime ? '⏸️ Pause' : '▶️ Resume'; ?>
                    </button>
                    <p id="lastUpdated">Real-time Updates</p>
                </div>
            </div>
            
            <!-- Filters -->
            <form method="GET" class="filter-bar">
                <div class="filter-group">
                    <label>Action Type</label>
                    <select name="action">
                        <option value="">All Actions</option>
                        <?php foreach ($actionTypes as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['action']); ?>" <?php echo $filterAction === $type['action'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['action']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>User</label>
                    <select name="user">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['user_id']; ?>" <?php echo $filterUser == $u['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Date</label>
                    <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="activity_logs.php" class="btn btn-secondary btn-sm">Reset</a>
            </form>
            
            <!-- Logs Table -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="logsTable">
                        <table id="logsTable">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($logs)): ?>
                                    <?php foreach ($logs as $log): ?>
                                        <?php
                                        // Determine badge class
                                        $badgeClass = 'default';
                                        if (stripos($log['action'], 'login') !== false) $badgeClass = 'login';
                                        elseif (stripos($log['action'], 'logout') !== false) $badgeClass = 'logout';
                                        elseif (stripos($log['action'], 'sale') !== false) $badgeClass = 'sale';
                                        elseif (stripos($log['action'], 'void') !== false) $badgeClass = 'void';
                                        elseif (stripos($log['action'], 'restock') !== false) $badgeClass = 'restock';
                                        elseif (stripos($log['action'], 'adjust') !== false) $badgeClass = 'adjustment';
                                        elseif (stripos($log['action'], 'product') !== false) $badgeClass = 'product';
                                        elseif (stripos($log['action'], 'user') !== false) $badgeClass = 'user';
                                        elseif (stripos($log['action'], 'security') !== false) $badgeClass = 'security';
                                        ?>
                                        <tr data-log-id="<?php echo $log['log_id']; ?>">
                                        <tr data-log-id="<?php echo $log['log_id']; ?>">
                                            <td><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></td>
                                            <td>
                                                <?php if ($log['full_name']): ?>
                                                    <strong><?php echo htmlspecialchars($log['full_name']); ?></strong>
                                                    <div style="font-size: 11px; color: #888;">@<?php echo htmlspecialchars($log['username']); ?></div>
                                                <?php else: ?>
                                                    <span style="color: #999;">System</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="action-badge <?php echo $badgeClass; ?>">
                                                    <?php echo htmlspecialchars($log['action']); ?>
                                                </span>
                                            </td>
                                            <td class="details-column"><?php echo htmlspecialchars($log['details'] ?? '-'); ?></td>
                                            <td>
                                                <?php if ($log['ip_address']): ?>
                                                    <span class="ip-badge"><?php echo htmlspecialchars($log['ip_address']); ?></span>
                                                <?php else: ?>
                                                    <span style="color: #ccc;">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; color: #999; padding: 40px;">
                                            No activity logs found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination-controls">
                            <?php 
                            $queryParams = $_GET;
                            unset($queryParams['page']);
                            $baseUrl = 'activity_logs.php?' . http_build_query($queryParams) . '&page=';
                            ?>
                            
                            <?php if ($page > 1): ?>
                                <a href="<?php echo $baseUrl . ($page - 1); ?>">« Prev</a>
                            <?php endif; ?>
                            
                            <?php 
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            for ($i = $startPage; $i <= $endPage; $i++): 
                            ?>
                                <a href="<?php echo $baseUrl . $i; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo $baseUrl . ($page + 1); ?>">Next »</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="js/hamburger.js"></script>
    <script>
        // Real-time Activity Logs
        let lastLogId = <?php echo $lastLogId; ?>;
        let pollInterval = null;
        let isPolling = <?php echo $realtime ? 'true' : 'false'; ?>;
        
        // Get badge class for action type
        function getActionBadgeClass(action) {
            if (action.includes('login')) return 'login';
            if (action.includes('logout')) return 'logout';
            if (action.includes('sale')) return 'sale';
            if (action.includes('void')) return 'void';
            if (action.includes('restock')) return 'restock';
            if (action.includes('adjust') || action.includes('inventory')) return 'adjustment';
            if (action.includes('product')) return 'product';
            if (action.includes('user')) return 'user';
            if (action.includes('security')) return 'security';
            return 'default';
        }
        
        // Create log row HTML
        function createLogRow(log) {
            const badgeClass = getActionBadgeClass(log.action);
            const roleClass = log.role === 'admin' ? 'admin-badge' : 'cashier-badge';
            
            return `
                <tr class="new-log-row" data-log-id="${log.log_id}">
                    <td>${log.formatted_time}</td>
                    <td>
                        ${log.user_name ? `
                            <strong>${escapeHtml(log.user_name)}</strong>
                            <div style="font-size: 11px; color: #888;">
                                @${escapeHtml(log.username)}
                                <span class="role-badge ${roleClass}">${log.role || 'user'}</span>
                            </div>
                        ` : '<span style="color: #999;">System</span>'}
                    </td>
                    <td>
                        <span class="action-badge ${badgeClass}">
                            ${escapeHtml(log.action)}
                        </span>
                    </td>
                    <td class="details-column">${escapeHtml(log.details || '-')}</td>
                    <td>
                        ${log.ip_address ? 
                            `<span class="ip-badge">${escapeHtml(log.ip_address)}</span>` : 
                            '<span style="color: #ccc;">-</span>'
                        }
                    </td>
                </tr>
            `;
        }
        
        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Fetch new logs via AJAX
        async function fetchNewLogs() {
            try {
                const response = await fetch(`api/get-activity-logs.php?last_id=${lastLogId}&limit=10`);
                const data = await response.json();
                
                if (data.success && data.logs.length > 0) {
                    const tbody = document.querySelector('#logsTable tbody');
                    const existingRows = tbody.querySelectorAll('tr');
                    
                    // Add new logs at the top
                    data.logs.reverse().forEach(log => {
                        // Check if log already exists
                        if (!document.querySelector(`tr[data-log-id="${log.log_id}"]`)) {
                            const newRow = document.createElement('tr');
                            newRow.innerHTML = createLogRow(log).trim();
                            const actualRow = newRow.querySelector('tr') || newRow;
                            actualRow.className = 'new-log-row';
                            actualRow.setAttribute('data-log-id', log.log_id);
                            
                            // Insert at the top
                            if (tbody.firstChild) {
                                tbody.insertBefore(actualRow, tbody.firstChild);
                            } else {
                                tbody.appendChild(actualRow);
                            }
                            
                            // Remove "no logs" message if present
                            const emptyRow = tbody.querySelector('tr td[colspan]');
                            if (emptyRow) {
                                emptyRow.closest('tr').remove();
                            }
                            
                            // Highlight animation
                            setTimeout(() => actualRow.classList.remove('new-log-row'), 2000);
                        }
                    });
                    
                    // Update last log ID
                    lastLogId = data.last_id;
                    
                    // Limit displayed rows (keep last 50)
                    while (tbody.children.length > 50) {
                        tbody.removeChild(tbody.lastChild);
                    }
                }
                
                // Update stats
                if (data.stats) {
                    document.getElementById('statTotalToday').textContent = data.stats.total_today;
                    document.getElementById('statUniqueUsers').textContent = data.stats.unique_users;
                }
                
                // Update last updated time
                document.getElementById('lastUpdated').textContent = 'Last updated: ' + new Date().toLocaleTimeString();
                
            } catch (error) {
                console.error('Error fetching logs:', error);
            }
        }
        
        // Start/stop real-time polling
        function toggleRealtime() {
            isPolling = !isPolling;
            const btn = document.getElementById('realtimeBtn');
            
            if (isPolling) {
                btn.innerHTML = '⏸️ Pause';
                btn.classList.remove('btn-secondary');
                btn.classList.add('btn-primary');
                pollInterval = setInterval(fetchNewLogs, 5000);
                fetchNewLogs(); // Fetch immediately
            } else {
                btn.innerHTML = '▶️ Resume';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-secondary');
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            if (isPolling) {
                pollInterval = setInterval(fetchNewLogs, 5000);
            }
        });
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (pollInterval) {
                clearInterval(pollInterval);
            }
        });
    </script>
</body>
</html>
