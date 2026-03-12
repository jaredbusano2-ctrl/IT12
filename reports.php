<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';

setSecurityHeaders();
requireLogin();
checkPageAccess();
requirePermission('view_reports');

$user = getCurrentUser();

// Get date range (default to today)
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Pagination setup
$daily_page = isset($_GET['daily_page']) ? max(1, intval($_GET['daily_page'])) : 1;
$products_page = isset($_GET['products_page']) ? max(1, intval($_GET['products_page'])) : 1;
$payment_page = isset($_GET['payment_page']) ? max(1, intval($_GET['payment_page'])) : 1;
$limit = 5;

// Get daily sales data with pagination
$daily_sales = [];
$total_sales = 0;
$total_transactions = 0;
$total_items = 0;

// Get total daily sales count
$count_query = "
    SELECT COUNT(DISTINCT DATE(sale_date)) as count 
    FROM sales 
    WHERE DATE(sale_date) BETWEEN ? AND ?
";
$stmt = $conn->prepare($count_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$total_daily_count = $stmt->get_result()->fetch_assoc()['count'];
$total_daily_pages = ceil($total_daily_count / $limit);
$daily_offset = ($daily_page - 1) * $limit;

$sales_query = "
    SELECT 
        DATE(sale_date) as sale_date,
        COUNT(*) as transaction_count,
        SUM(total_amount) as total_sales,
        SUM(amount_paid) as total_paid,
        SUM(discount) as total_discount,
        SUM(tax) as total_tax
    FROM sales 
    WHERE DATE(sale_date) BETWEEN ? AND ?
    GROUP BY DATE(sale_date)
    ORDER BY sale_date DESC
    LIMIT $limit OFFSET $daily_offset
";

$stmt = $conn->prepare($sales_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $daily_sales[] = $row;
    $total_sales += $row['total_sales'];
    $total_transactions += $row['transaction_count'];
}

// Get all sales data for summary (without pagination)
$summary_query = "
    SELECT 
        SUM(total_amount) as total_sales,
        COUNT(*) as transaction_count,
        SUM(1) as item_count
    FROM sales 
    WHERE DATE(sale_date) BETWEEN ? AND ?
";
$stmt = $conn->prepare($summary_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$total_sales = $summary['total_sales'] ?? 0;
$total_transactions = $summary['transaction_count'] ?? 0;

// Get top selling products count
$product_count_query = "
    SELECT COUNT(DISTINCT si.product_id) as count
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.sale_id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
";
$stmt = $conn->prepare($product_count_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$total_products_count = $stmt->get_result()->fetch_assoc()['count'];
$total_products_pages = ceil($total_products_count / $limit);
$products_offset = ($products_page - 1) * $limit;

$top_products = [];
$products_query = "
    SELECT 
        p.product_name,
        c.category_name,
        SUM(si.quantity) as total_quantity,
        SUM(si.subtotal) as total_revenue
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.sale_id
    JOIN products p ON si.product_id = p.product_id
    JOIN categories c ON p.category_id = c.category_id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY si.product_id, p.product_name, c.category_name
    ORDER BY total_quantity DESC
    LIMIT $limit OFFSET $products_offset
";

$stmt = $conn->prepare($products_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $top_products[] = $row;
    $total_items += $row['total_quantity'];
}

// Get payment method count
$payment_count_query = "
    SELECT COUNT(DISTINCT payment_method) as count
    FROM sales 
    WHERE DATE(sale_date) BETWEEN ? AND ?
";
$stmt = $conn->prepare($payment_count_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$total_payment_count = $stmt->get_result()->fetch_assoc()['count'];
$total_payment_pages = ceil($total_payment_count / $limit);
$payment_offset = ($payment_page - 1) * $limit;

// Get payment method breakdown
$payment_methods = [];
$payment_query = "
    SELECT 
        payment_method,
        COUNT(*) as count,
        SUM(total_amount) as total
    FROM sales 
    WHERE DATE(sale_date) BETWEEN ? AND ?
    GROUP BY payment_method
    ORDER BY total DESC
    LIMIT $limit OFFSET $payment_offset
";

$stmt = $conn->prepare($payment_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $payment_methods[] = $row;
}

// Get cup inventory data for drinks
$cup_inventory_query = "
    SELECT 
        p.product_name,
        si.cup_size,
        COUNT(*) as total_cups_sold,
        SUM(si.quantity) as total_quantity,
        SUM(si.subtotal) as total_revenue
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.sale_id
    JOIN products p ON si.product_id = p.product_id
    WHERE DATE(s.sale_date) BETWEEN ? AND ? 
    AND si.cup_size != 'none'
    GROUP BY si.product_id, p.product_name, si.cup_size
    ORDER BY total_cups_sold DESC
";

$stmt = $conn->prepare($cup_inventory_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

$cup_inventory = [];
$total_cups_sold = 0;
while ($row = $result->fetch_assoc()) {
    $cup_inventory[] = $row;
    $total_cups_sold += $row['total_cups_sold'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Reports - POPRIE</title>
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
                <h1>Daily Reports</h1>
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
            
            <!-- Date Range Filter -->
            <div class="card">
                <div class="card-header">
                    <h3>Date Range</h3>
                </div>
                <div class="card-body">
                    <form method="GET" class="form-row" style="display: flex; gap: 12px; align-items: flex-end;">
                        <div class="form-group" style="flex: 1;">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="form-control" required>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>End Date</label>
                            <div style="display: flex; gap: 8px;">
                                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="form-control" required style="flex: 1;">
                                <button type="submit" class="btn btn-primary">Generate Report</button>
                                <a href="reports.php" class="btn btn-secondary">Today</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Summary Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-icon success">
                            💰
                        </div>
                    </div>
                    <div class="stat-card-body">
                        <h3>Total Sales</h3>
                        <div class="stat-value">₱<?php echo number_format($total_sales, 2); ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-icon primary">
                            📊
                        </div>
                    </div>
                    <div class="stat-card-body">
                        <h3>Transactions</h3>
                        <div class="stat-value"><?php echo number_format($total_transactions); ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-icon warning">
                            🛍️
                        </div>
                    </div>
                    <div class="stat-card-body">
                        <h3>Items Sold</h3>
                        <div class="stat-value"><?php echo number_format($total_items); ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-icon info">
                            📈
                        </div>
                    </div>
                    <div class="stat-card-body">
                        <h3>Avg Transaction</h3>
                        <div class="stat-value">₱<?php echo $total_transactions > 0 ? number_format($total_sales / $total_transactions, 2) : '0.00'; ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Daily Sales Table -->
            <div class="card">
                <div class="card-header">
                    <h3>Daily Sales Breakdown</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Transactions</th>
                                    <th>Gross Sales</th>
                                    <th>Discount</th>
                                    <th>Tax</th>
                                    <th>Net Sales</th>
                                    <th>Avg Sale</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($daily_sales)): ?>
                                    <?php foreach ($daily_sales as $day): ?>
                                        <tr>
                                            <td><strong><?php echo date('M d, Y', strtotime($day['sale_date'])); ?></strong></td>
                                            <td><?php echo $day['transaction_count']; ?></td>
                                            <td>₱<?php echo number_format($day['total_sales'], 2); ?></td>
                                            <td>₱<?php echo number_format($day['total_discount'], 2); ?></td>
                                            <td>₱<?php echo number_format($day['total_tax'], 2); ?></td>
                                            <td><strong>₱<?php echo number_format($day['total_sales'], 2); ?></strong></td>
                                            <td>₱<?php echo number_format($day['total_sales'] / $day['transaction_count'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; color: var(--text-secondary);">No sales data found for selected period</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination Controls for Daily Sales -->
                    <?php if ($total_daily_pages > 1): ?>
                        <div class="pagination-container">
                            <div class="pagination-controls">
                                <?php if ($daily_page > 1): ?>
                                    <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&daily_page=1<?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?><?php echo isset($_GET['payment_page']) ? '&payment_page='.$_GET['payment_page'] : ''; ?>">« First</a>
                                    <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&daily_page=<?php echo $daily_page - 1; ?><?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?><?php echo isset($_GET['payment_page']) ? '&payment_page='.$_GET['payment_page'] : ''; ?>">‹ Previous</a>
                                <?php endif; ?>
                                
                                <?php
                                $start_page = max(1, $daily_page - 2);
                                $end_page = min($total_daily_pages, $daily_page + 2);
                                
                                if ($start_page > 1) {
                                    echo '<a href="?start_date=' . htmlspecialchars($start_date) . '&end_date=' . htmlspecialchars($end_date) . '&daily_page=1' . (isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : '') . (isset($_GET['payment_page']) ? '&payment_page='.$_GET['payment_page'] : '') . '">1</a>';
                                    if ($start_page > 2) {
                                        echo '<span class="dots">...</span>';
                                    }
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&daily_page=<?php echo $i; ?><?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?><?php echo isset($_GET['payment_page']) ? '&payment_page='.$_GET['payment_page'] : ''; ?>" 
                                       class="<?php echo $i == $daily_page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php
                                endfor;
                                
                                if ($end_page < $total_daily_pages) {
                                    if ($end_page < $total_daily_pages - 1) {
                                        echo '<span class="dots">...</span>';
                                    }
                                    echo '<a href="?start_date=' . htmlspecialchars($start_date) . '&end_date=' . htmlspecialchars($end_date) . '&daily_page=' . $total_daily_pages . (isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : '') . (isset($_GET['payment_page']) ? '&payment_page='.$_GET['payment_page'] : '') . '">' . $total_daily_pages . '</a>';
                                }
                                ?>
                                
                                <?php if ($daily_page < $total_daily_pages): ?>
                                    <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&daily_page=<?php echo $daily_page + 1; ?><?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?><?php echo isset($_GET['payment_page']) ? '&payment_page='.$_GET['payment_page'] : ''; ?>">Next ›</a>
                                    <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&daily_page=<?php echo $total_daily_pages; ?><?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?><?php echo isset($_GET['payment_page']) ? '&payment_page='.$_GET['payment_page'] : ''; ?>">Last »</a>
                                <?php endif; ?>
                            </div>
                            <div class="pagination-info">
                                Page <?php echo $daily_page; ?> of <?php echo $total_daily_pages; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Top Products and Payment Methods -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 24px;">
                <!-- Top Selling Products -->
                <div class="card">
                    <div class="card-header">
                        <h3>Top Selling Products</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Quantity</th>
                                        <th>Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($top_products)): ?>
                                        <?php foreach ($top_products as $product): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($product['product_name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                                <td><?php echo $product['total_quantity']; ?></td>
                                                <td>₱<?php echo number_format($product['total_revenue'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" style="text-align: center; color: var(--text-secondary);">No sales data found</td>
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
                                        <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&products_page=1<?php echo isset($_GET['daily_page']) ? '&daily_page='.$_GET['daily_page'] : ''; ?><?php echo isset($_GET['payment_page']) ? '&payment_page='.$_GET['payment_page'] : ''; ?>">« First</a>
                                        <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&products_page=<?php echo $products_page - 1; ?><?php echo isset($_GET['daily_page']) ? '&daily_page='.$_GET['daily_page'] : ''; ?><?php echo isset($_GET['payment_page']) ? '&payment_page='.$_GET['payment_page'] : ''; ?>">‹ Previous</a>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $start_page = max(1, $products_page - 2);
                                    $end_page = min($total_products_pages, $products_page + 2);
                                    
                                    if ($start_page > 1) {
                                        echo '<a href="?start_date=' . htmlspecialchars($start_date) . '&end_date=' . htmlspecialchars($end_date) . '&products_page=1' . (isset($_GET['daily_page']) ? '&daily_page='.$_GET['daily_page'] : '') . (isset($_GET['payment_page']) ? '&payment_page='.$_GET['payment_page'] : '') . '">1</a>';
                                        if ($start_page > 2) {
                                            echo '<span class="dots">...</span>';
                                        }
                                    }
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++):
                                    ?>
                                        <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&products_page=<?php echo $i; ?><?php echo isset($_GET['daily_page']) ? '&daily_page='.$_GET['daily_page'] : ''; ?><?php echo isset($_GET['payment_page']) ? '&payment_page='.$_GET['payment_page'] : ''; ?>" 
                                           class="<?php echo $i == $products_page ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php
                                    endfor;
                                    
                                    if ($end_page < $total_products_pages) {
                                        if ($end_page < $total_products_pages - 1) {
                                            echo '<span class="dots">...</span>';
                                        }
                                        echo '<a href="?start_date=' . htmlspecialchars($start_date) . '&end_date=' . htmlspecialchars($end_date) . '&products_page=' . $total_products_pages . (isset($_GET['daily_page']) ? '&daily_page='.$_GET['daily_page'] : '') . (isset($_GET['payment_page']) ? '&payment_page='.$_GET['payment_page'] : '') . '">' . $total_products_pages . '</a>';
                                    }
                                    ?>
                                    
                                    <?php if ($products_page < $total_products_pages): ?>
                                        <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&products_page=<?php echo $products_page + 1; ?><?php echo isset($_GET['daily_page']) ? '&daily_page='.$_GET['daily_page'] : ''; ?><?php echo isset($_GET['payment_page']) ? '&payment_page='.$_GET['payment_page'] : ''; ?>">Next ›</a>
                                        <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&products_page=<?php echo $total_products_pages; ?><?php echo isset($_GET['daily_page']) ? '&daily_page='.$_GET['daily_page'] : ''; ?><?php echo isset($_GET['payment_page']) ? '&payment_page='.$_GET['payment_page'] : ''; ?>">Last »</a>
                                    <?php endif; ?>
                                </div>
                                <div class="pagination-info">
                                    Page <?php echo $products_page; ?> of <?php echo $total_products_pages; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Payment Methods -->
                <div class="card">
                    <div class="card-header">
                        <h3>Payment Methods</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Method</th>
                                        <th>Transactions</th>
                                        <th>Total Amount</th>
                                        <th>Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($payment_methods)): ?>
                                        <?php foreach ($payment_methods as $method): ?>
                                            <tr>
                                                <td><strong><?php echo ucfirst($method['payment_method']); ?></strong></td>
                                                <td><?php echo $method['count']; ?></td>
                                                <td>₱<?php echo number_format($method['total'], 2); ?></td>
                                                <td><?php echo $total_sales > 0 ? number_format(($method['total'] / $total_sales) * 100, 1) : 0; ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" style="text-align: center; color: var(--text-secondary);">No payment data found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination Controls for Payment Methods -->
                        <?php if ($total_payment_pages > 1): ?>
                            <div class="pagination-container">
                                <div class="pagination-controls">
                                    <?php if ($payment_page > 1): ?>
                                        <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&payment_page=1<?php echo isset($_GET['daily_page']) ? '&daily_page='.$_GET['daily_page'] : ''; ?><?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?>">« First</a>
                                        <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&payment_page=<?php echo $payment_page - 1; ?><?php echo isset($_GET['daily_page']) ? '&daily_page='.$_GET['daily_page'] : ''; ?><?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?>">‹ Previous</a>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $start_page = max(1, $payment_page - 2);
                                    $end_page = min($total_payment_pages, $payment_page + 2);
                                    
                                    if ($start_page > 1) {
                                        echo '<a href="?start_date=' . htmlspecialchars($start_date) . '&end_date=' . htmlspecialchars($end_date) . '&payment_page=1' . (isset($_GET['daily_page']) ? '&daily_page='.$_GET['daily_page'] : '') . (isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : '') . '">1</a>';
                                        if ($start_page > 2) {
                                            echo '<span class="dots">...</span>';
                                        }
                                    }
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++):
                                    ?>
                                        <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&payment_page=<?php echo $i; ?><?php echo isset($_GET['daily_page']) ? '&daily_page='.$_GET['daily_page'] : ''; ?><?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?>" 
                                           class="<?php echo $i == $payment_page ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php
                                    endfor;
                                    
                                    if ($end_page < $total_payment_pages) {
                                        if ($end_page < $total_payment_pages - 1) {
                                            echo '<span class="dots">...</span>';
                                        }
                                        echo '<a href="?start_date=' . htmlspecialchars($start_date) . '&end_date=' . htmlspecialchars($end_date) . '&payment_page=' . $total_payment_pages . (isset($_GET['daily_page']) ? '&daily_page='.$_GET['daily_page'] : '') . (isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : '') . '">' . $total_payment_pages . '</a>';
                                    }
                                    ?>
                                    
                                    <?php if ($payment_page < $total_payment_pages): ?>
                                        <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&payment_page=<?php echo $payment_page + 1; ?><?php echo isset($_GET['daily_page']) ? '&daily_page='.$_GET['daily_page'] : ''; ?><?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?>">Next ›</a>
                                        <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&payment_page=<?php echo $total_payment_pages; ?><?php echo isset($_GET['daily_page']) ? '&daily_page='.$_GET['daily_page'] : ''; ?><?php echo isset($_GET['products_page']) ? '&products_page='.$_GET['products_page'] : ''; ?>">Last »</a>
                                    <?php endif; ?>
                                </div>
                                <div class="pagination-info">
                                    Page <?php echo $payment_page; ?> of <?php echo $total_payment_pages; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Cup Inventory for Drinks -->
            <div class="card" style="margin-top: 24px;">
                <div class="card-header">
                    <h3>🥤 Cup Inventory for Drinks</h3>
                    <div style="font-size: 14px; color: var(--text-secondary); margin-top: 4px;">
                        Total Cups Sold: <strong><?php echo $total_cups_sold; ?></strong>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Drink Product</th>
                                    <th>Cup Size</th>
                                    <th>Cups Sold</th>
                                    <th>Total Quantity</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($cup_inventory)): ?>
                                    <?php foreach ($cup_inventory as $cup): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($cup['product_name']); ?></strong></td>
                                            <td>
                                                <span style="display: inline-block; padding: 4px 8px; background: var(--primary); color: white; border-radius: 4px; font-size: 12px;">
                                                    <?php echo htmlspecialchars($cup['cup_size']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $cup['total_cups_sold']; ?></td>
                                            <td><?php echo $cup['total_quantity']; ?></td>
                                            <td>₱<?php echo number_format($cup['total_revenue'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; color: var(--text-secondary);">No cup inventory data found for this period</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if (!empty($cup_inventory)): ?>
                        <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid var(--primary);">
                            <h4 style="margin: 0 0 10px 0; color: var(--primary);">📊 Cup Size Summary</h4>
                            <?php
                            $cup_summary = [];
                            foreach ($cup_inventory as $cup) {
                                if (!isset($cup_summary[$cup['cup_size']])) {
                                    $cup_summary[$cup['cup_size']] = [
                                        'cups_sold' => 0,
                                        'revenue' => 0
                                    ];
                                }
                                $cup_summary[$cup['cup_size']]['cups_sold'] += $cup['total_cups_sold'];
                                $cup_summary[$cup['cup_size']]['revenue'] += $cup['total_revenue'];
                            }
                            
                            foreach ($cup_summary as $size => $data):
                            ?>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                    <span>
                                        <strong><?php echo htmlspecialchars($size); ?>:</strong> 
                                        <?php echo $data['cups_sold']; ?> cups
                                    </span>
                                    <span>₱<?php echo number_format($data['revenue'], 2); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
