<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';

setSecurityHeaders();
requireLogin();
checkPageAccess();

$user = getCurrentUser();

// Get CSRF token for AJAX requests
$csrfToken = getCSRFTokenForAjax();

// Get sales with pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 5;
$offset = ($page - 1) * $limit;

// Build filter query (date)
$filter_params = "";
$date = null;
if (isset($_GET['date']) && $_GET['date'] !== '') {
    $date = $_GET['date'];
    $filter_params = "&date=" . urlencode($_GET['date']);
}

if ($date) {
    if (($user['role'] ?? null) === 'cashier') {
        $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM sales s WHERE s.user_id = ? AND DATE(s.sale_date) = ?");
        $cashierId = (int)($user['user_id'] ?? 0);
        $countStmt->bind_param('is', $cashierId, $date);
    } else {
        $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM sales s WHERE DATE(s.sale_date) = ?");
        $countStmt->bind_param('s', $date);
    }
    $countStmt->execute();
    $total_sales = (int)($countStmt->get_result()->fetch_assoc()['count'] ?? 0);
} else {
    if (($user['role'] ?? null) === 'cashier') {
        $cashierId = (int)($user['user_id'] ?? 0);
        $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM sales s WHERE s.user_id = ?");
        $countStmt->bind_param('i', $cashierId);
        $countStmt->execute();
        $total_sales = (int)($countStmt->get_result()->fetch_assoc()['count'] ?? 0);
    } else {
        $total_sales = (int)($conn->query("SELECT COUNT(*) as count FROM sales s")->fetch_assoc()['count'] ?? 0);
    }
}
$total_pages = ceil($total_sales / $limit);
$isCashierView = (($user['role'] ?? null) === 'cashier');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales History - POS & Inventory System</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Cashier Sales History: center content when sidebar is hidden */
        body.cashier-view .main-content {
            margin-left: 0;
        }

        body.cashier-view .cashier-center {
            max-width: 1200px;
            margin: 0 auto;
        }

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
<body class="<?php echo $isCashierView ? 'cashier-view' : ''; ?>">
    <div class="main-wrapper">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <?php if ($isCashierView): ?><div class="cashier-center"><?php endif; ?>
            <div class="header">
                <div class="header-left">
                    <?php if ($isCashierView): ?>
                        <a href="pos.php" class="btn btn-return">← Return to POS</a>
                    <?php endif; ?>
                    <h1>Sales History</h1>
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
            
            <div class="card">
                <div class="card-header">
                    <h3>All Sales Transactions</h3>
                    <div style="display: flex; gap: 8px;">
                        <input type="date" class="form-control" id="dateFilter" style="width: auto;" value="<?php echo isset($_GET['date']) ? htmlspecialchars($_GET['date']) : ''; ?>">
                        <button class="btn btn-primary btn-sm" onclick="filterByDate()">Filter</button>
                        <?php if (isset($_GET['date'])): ?>
                            <a href="sales.php" class="btn btn-secondary btn-sm">Clear</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Customer</th>
                                    <th>Cashier</th>
                                    <th>Items</th>
                                    <th>Subtotal</th>
                                    <th>Tax</th>
                                    <th>Discount</th>
                                    <th>Total</th>
                                    <th>Payment</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="salesTable">
                                <?php
                                // Initial render uses the same renderer as AJAX
                                $_GET['page'] = $page;
                                include __DIR__ . '/fetch_sales.php';
                                ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination Controls -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-container">
                            <div class="pagination-controls">
                                <?php if ($page > 1): ?>
                                    <a href="?page=1<?php echo $filter_params; ?>">« First</a>
                                    <a href="?page=<?php echo $page - 1; ?><?php echo $filter_params; ?>">‹ Previous</a>
                                <?php endif; ?>
                                
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                if ($start_page > 1) {
                                    echo '<a href="?page=1' . $filter_params . '">1</a>';
                                    if ($start_page > 2) {
                                        echo '<span class="dots">...</span>';
                                    }
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <a href="?page=<?php echo $i; ?><?php echo $filter_params; ?>" 
                                       class="<?php echo $i == $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php
                                endfor;
                                
                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) {
                                        echo '<span class="dots">...</span>';
                                    }
                                    echo '<a href="?page=' . $total_pages . $filter_params . '">' . $total_pages . '</a>';
                                }
                                ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?><?php echo $filter_params; ?>">Next ›</a>
                                    <a href="?page=<?php echo $total_pages; ?><?php echo $filter_params; ?>">Last »</a>
                                <?php endif; ?>
                            </div>
                            <div class="pagination-info">
                                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($isCashierView): ?></div><?php endif; ?>
        </div>
    </div>
    
    <!-- Sale Details Modal -->
    <div id="saleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Sale Details</h2>
                <button class="modal-close" onclick="closeSaleModal()">&times;</button>
            </div>
            <div class="modal-body" id="saleDetails">
                Loading...
            </div>
        </div>
    </div>
    
    <script>
        // Store current sale data globally
        let currentSaleData = null;
        
        // CSRF Token
        const csrfToken = '<?php echo htmlspecialchars($csrfToken); ?>';
        const isCashierUser = <?php echo (($user['role'] ?? null) === 'cashier') ? 'true' : 'false'; ?>;

        function getVoidCredentials() {
            const adminPassword = (document.getElementById('voidAdminPassword')?.value || '').trim();
            const voidReason = (document.getElementById('voidReason')?.value || '').trim();
            return { adminPassword, voidReason };
        }

        async function postVoidRequest(payload) {
            const res = await fetch('api/void_item.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                credentials: 'same-origin',
                cache: 'no-store',
                body: JSON.stringify(payload)
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data?.success) {
                throw new Error(data?.message || data?.error || 'Void request failed');
            }
            return data;
        }
        
        async function viewSaleDetails(saleId) {
            document.getElementById('saleModal').classList.add('active');
            
            try {
                const response = await fetch(`api/get-sale-details.php?sale_id=${saleId}`);
                const data = await response.json();
                
                if (data.success) {
                    const sale = data.sale;
                    const items = data.items;
                    currentSaleData = { sale, items, saleId };
                    
                    // Check if sale is already voided
                    const isVoided = sale.status === 'voided';
                    const voidedClass = isVoided ? 'opacity: 0.6;' : '';
                    const voidedBadge = isVoided ? '<span style="background: #d32f2f; color: white; padding: 2px 8px; border-radius: 4px; font-size: 11px; margin-left: 8px;">VOIDED</span>' : '';

                    const voidControls = isCashierUser ? `
                        <div style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap; margin: 12px 0 4px 0;">
                            <div style="flex: 1; min-width: 220px;">
                                <label style="display:block; font-size:12px; margin-bottom:6px; color: var(--text-secondary);">Admin Password</label>
                                <input id="voidAdminPassword" type="password" class="form-control" placeholder="Admin password" style="margin-bottom:0;">
                            </div>
                            <div style="flex: 2; min-width: 280px;">
                                <label style="display:block; font-size:12px; margin-bottom:6px; color: var(--text-secondary);">Void Reason</label>
                                <input id="voidReason" type="text" class="form-control" placeholder="Reason (required)" maxlength="500" style="margin-bottom:0;">
                            </div>
                            <div style="display:flex; gap:8px; align-items:flex-end;">
                                <button type="button" class="btn btn-danger btn-sm" ${isVoided ? 'disabled' : ''} onclick="voidEntireSale(${saleId})">Void Entire Sale</button>
                            </div>
                        </div>
                        <p style="margin: 6px 0 0 0; font-size: 12px; color: var(--text-secondary);">
                            Tip: Click a row’s Void button to void a specific item.
                        </p>
                    ` : '';
                    
                    let html = `
                        <div style="margin-bottom: 24px; ${voidedClass}">
                            <p><strong>Invoice:</strong> ${sale.invoice_number} ${voidedBadge}</p>
                            <p><strong>Customer:</strong> ${sale.customer_name || 'Walk-in'}</p>
                            <p><strong>Cashier:</strong> ${sale.full_name || sale.cashier_name || ''}</p>
                            <p><strong>Date:</strong> ${new Date(sale.sale_date).toLocaleString()}</p>
                            <p><strong>Status:</strong> <span style="color: ${isVoided ? '#d32f2f' : '#10b981'};">${isVoided ? 'Voided' : 'Completed'}</span></p>
                            ${voidControls}
                        </div>
                        
                        <h4 style="margin-bottom: 12px;">Items</h4>
                        <table style="width: 100%; margin-bottom: 24px;">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Qty</th>
                                    <th>Price</th>
                                    <th>Subtotal</th>
                                    ${isCashierUser ? '<th style="width: 120px;">Actions</th>' : ''}
                                </tr>
                            </thead>
                            <tbody>
                    `;
                    
                    items.forEach(item => {
                        const itemVoided = item.is_voided == 1;
                        const itemStyle = itemVoided ? 'text-decoration: line-through; opacity: 0.5;' : '';

                        let actionCell = '';
                        if (isCashierUser) {
                            const disabled = (isVoided || itemVoided) ? 'disabled' : '';
                            const btnLabel = itemVoided ? 'Voided' : 'Void';
                            actionCell = `
                                <td>
                                    <button type="button" class="btn btn-warning btn-sm" ${disabled} onclick="voidSaleItem(${item.sale_item_id})">${btnLabel}</button>
                                </td>
                            `;
                        }
                        
                        html += `
                            <tr style="${itemStyle}">
                                <td>${item.product_name}${item.cup_size ? ' (' + item.cup_size + ')' : ''}</td>
                                <td>${item.quantity}</td>
                                <td>₱${parseFloat(item.unit_price).toFixed(2)}</td>
                                <td>₱${parseFloat(item.subtotal).toFixed(2)}</td>
                                ${isCashierUser ? actionCell : ''}
                            </tr>
                        `;
                    });
                    
                    html += `
                            </tbody>
                        </table>
                        
                        <div style="border-top: 2px solid var(--border); padding-top: 16px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span>Subtotal:</span>
                                <strong>₱${parseFloat(sale.subtotal).toFixed(2)}</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span>Tax:</span>
                                <strong>₱${parseFloat(sale.tax).toFixed(2)}</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span>Discount:</span>
                                <strong>₱${parseFloat(sale.discount).toFixed(2)}</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-top: 12px; padding-top: 12px; border-top: 2px solid var(--border); font-size: 18px;">
                                <span><strong>Grand Total:</strong></span>
                                <strong style="color: var(--primary);">₱${parseFloat(sale.total_amount).toFixed(2)}</strong>
                            </div>
                        </div>
                    `;
                    
                    document.getElementById('saleDetails').innerHTML = html;
                } else {
                    document.getElementById('saleDetails').innerHTML = '<p>Error loading sale details</p>';
                }
            } catch (error) {
                document.getElementById('saleDetails').innerHTML = '<p>Error loading sale details</p>';
                console.error('Error:', error);
            }
        }

        async function voidSaleItem(saleItemId) {
            if (!currentSaleData?.sale) return;
            const isVoided = currentSaleData.sale.status === 'voided';
            if (isVoided) {
                alert('This sale is already voided.');
                return;
            }

            const { adminPassword, voidReason } = getVoidCredentials();
            if (!adminPassword) {
                alert('Admin password is required.');
                return;
            }
            if (!voidReason) {
                alert('Void reason is required.');
                return;
            }

            if (!confirm('Void this item? This will restore inventory.')) return;

            try {
                await postVoidRequest({
                    void_type: 'item',
                    sale_item_id: saleItemId,
                    admin_password: adminPassword,
                    void_reason: voidReason
                });
                await viewSaleDetails(currentSaleData.saleId);
                refreshSalesTable();
            } catch (e) {
                alert(e.message || 'Failed to void item');
            }
        }

        async function voidEntireSale(saleId) {
            if (!currentSaleData?.sale) return;
            const isVoided = currentSaleData.sale.status === 'voided';
            if (isVoided) {
                alert('This sale is already voided.');
                return;
            }

            const { adminPassword, voidReason } = getVoidCredentials();
            if (!adminPassword) {
                alert('Admin password is required.');
                return;
            }
            if (!voidReason) {
                alert('Void reason is required.');
                return;
            }

            if (!confirm('Void the entire sale? This will void all items and restore inventory.')) return;

            try {
                await postVoidRequest({
                    void_type: 'sale',
                    sale_id: saleId,
                    admin_password: adminPassword,
                    void_reason: voidReason
                });
                await viewSaleDetails(saleId);
                refreshSalesTable();
            } catch (e) {
                alert(e.message || 'Failed to void sale');
            }
        }
        
        function closeSaleModal() {
            document.getElementById('saleModal').classList.remove('active');
            currentSaleData = null;
        }
        
        function filterByDate() {
            const date = document.getElementById('dateFilter').value;
            if (date) {
                window.location.href = '?date=' + date + '&page=1';
            }
        }

        // Real-time sales history refresh (AJAX)
        async function refreshSalesTable() {
            try {
                const params = new URLSearchParams(window.location.search);
                if (!params.get('page')) params.set('page', '1');
                const url = 'fetch_sales.php?' + params.toString();
                const res = await fetch(url, { cache: 'no-store' });
                if (!res.ok) return;
                const html = await res.text();
                const tbody = document.getElementById('salesTable');
                if (tbody) tbody.innerHTML = html;
            } catch (e) {
                // Silent fail to avoid disrupting cashier workflow
                console.error('Sales refresh failed', e);
            }
        }

        // Auto-refresh every 7 seconds (within requested 5–10s)
        setInterval(refreshSalesTable, 7000);
        
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                if (e.target.id === 'saleModal') {
                    closeSaleModal();
                }
            }
        });
    </script>
</body>

</html>