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
    
    <!-- Void Authorization Modal -->
    <div id="voidAuthModal" class="modal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header">
                <h2>Void Authorization Required</h2>
                <button class="modal-close" onclick="closeVoidAuthModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="color: #666; font-size: 13px; margin-bottom: 16px;">
                    Admin authorization is required to void this item/sale.
                </p>
                
                <div id="voidItemInfo" style="margin-bottom: 16px; padding: 12px; background: #f8f9fa; border-radius: 8px;">
                    <!-- Item info will be inserted here -->
                </div>
                
                <form id="voidAuthForm" onsubmit="submitVoidAuth(event)">
                    <input type="hidden" id="voidType" value="">
                    <input type="hidden" id="voidTargetId" value="">
                    <input type="hidden" id="voidSaleId" value="">
                    
                    <div class="form-group">
                        <label style="font-weight: 600; display: block; margin-bottom: 8px;">Admin Password</label>
                        <input type="password" class="form-control" id="voidAdminPassword" placeholder="Enter admin password" required>
                    </div>
                    
                    <div class="form-group">
                        <label style="font-weight: 600; display: block; margin-bottom: 8px;">Void Reason <span style="color: #d32f2f;">*</span></label>
                        <textarea class="form-control" id="voidReasonText" placeholder="Enter reason for voiding..." rows="3" required style="resize: vertical;"></textarea>
                        <div style="font-size: 11px; color: #999; margin-top: 4px;">
                            <span id="voidCharCount">0</span>/500 characters
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 8px; margin-top: 20px;">
                        <button type="button" class="btn btn-secondary" onclick="closeVoidAuthModal()" style="flex: 1;">Cancel</button>
                        <button type="submit" class="btn btn-danger" id="voidSubmitBtn" style="flex: 1;">Confirm Void</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Store current sale data globally
        let currentSaleData = null;
        
        // CSRF Token
        const csrfToken = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
        
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

                    
                    let html = `
                        <div style="margin-bottom: 24px; ${voidedClass}">
                            <p><strong>Invoice:</strong> ${sale.invoice_number} ${voidedBadge}</p>
                            <p><strong>Customer:</strong> ${sale.customer_name || 'Walk-in'}</p>
                            <p><strong>Cashier:</strong> ${sale.full_name || sale.cashier_name || ''}</p>
                            <p><strong>Date:</strong> ${new Date(sale.sale_date).toLocaleString()}</p>
                            <p><strong>Status:</strong> <span style="color: ${isVoided ? '#d32f2f' : '#10b981'};">${isVoided ? 'Voided' : 'Completed'}</span></p>
                        </div>
                        
                        <h4 style="margin-bottom: 12px;">Items</h4>
                        <table style="width: 100%; margin-bottom: 24px;">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Qty</th>
                                    <th>Price</th>
                                    <th>Subtotal</th>
                                    ${!isVoided ? '<th>Action</th>' : ''}
                                </tr>
                            </thead>
                            <tbody>
                    `;
                    
                    items.forEach(item => {
                        const itemVoided = item.is_voided == 1;
                        const itemStyle = itemVoided ? 'text-decoration: line-through; opacity: 0.5;' : '';
                        const voidBtnHtml = (!isVoided && !itemVoided)
                            ? `<button class="btn btn-danger btn-sm" onclick="openVoidItemModal(${item.sale_item_id}, '${item.product_name}', ${item.quantity}, ${item.subtotal})">Void</button>`
                            : (itemVoided ? '<span style="color: #d32f2f; font-size: 11px;">Voided</span>' : '');
                        
                        html += `
                            <tr style="${itemStyle}">
                                <td>${item.product_name}${item.cup_size ? ' (' + item.cup_size + ')' : ''}</td>
                                <td>${item.quantity}</td>
                                <td>₱${parseFloat(item.unit_price).toFixed(2)}</td>
                                <td>₱${parseFloat(item.subtotal).toFixed(2)}</td>
                                ${!isVoided ? `<td>${voidBtnHtml}</td>` : ''}
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
                    
                    // Add Void Entire Sale button if not already voided
                    if (!isVoided) {
                        html += `
                            <div style="margin-top: 24px; padding-top: 16px; border-top: 2px solid var(--border);">
                                <button class="btn btn-danger" onclick="openVoidSaleModal(${saleId}, '${sale.invoice_number}')" style="width: 100%;">
                                    🚫 Void Entire Sale
                                </button>
                            </div>
                        `;
                    }
                    
                    document.getElementById('saleDetails').innerHTML = html;
                } else {
                    document.getElementById('saleDetails').innerHTML = '<p>Error loading sale details</p>';
                }
            } catch (error) {
                document.getElementById('saleDetails').innerHTML = '<p>Error loading sale details</p>';
                console.error('Error:', error);
            }
        }
        
        function closeSaleModal() {
            document.getElementById('saleModal').classList.remove('active');
            currentSaleData = null;
        }
        
        // Void Item Modal
        function openVoidItemModal(saleItemId, productName, quantity, subtotal) {
            document.getElementById('voidType').value = 'item';
            document.getElementById('voidTargetId').value = saleItemId;
            document.getElementById('voidSaleId').value = currentSaleData?.saleId || '';
            document.getElementById('voidItemInfo').innerHTML = `
                <strong>Item to Void:</strong><br>
                ${productName} × ${quantity}<br>
                <span style="color: #d32f2f;">Amount: ₱${parseFloat(subtotal).toFixed(2)}</span>
            `;
            document.getElementById('voidAdminPassword').value = '';
            document.getElementById('voidReasonText').value = '';
            document.getElementById('voidCharCount').textContent = '0';
            document.getElementById('voidAuthModal').classList.add('active');
            setTimeout(() => document.getElementById('voidAdminPassword').focus(), 100);
        }
        
        // Void Entire Sale Modal
        function openVoidSaleModal(saleId, invoiceNumber) {
            document.getElementById('voidType').value = 'sale';
            document.getElementById('voidTargetId').value = saleId;
            document.getElementById('voidSaleId').value = saleId;
            
            const totalAmount = currentSaleData?.sale?.total_amount || 0;
            const itemCount = currentSaleData?.items?.length || 0;
            
            document.getElementById('voidItemInfo').innerHTML = `
                <strong>Void Entire Sale:</strong><br>
                Invoice: ${invoiceNumber}<br>
                Items: ${itemCount} item(s)<br>
                <span style="color: #d32f2f;">Total Amount: ₱${parseFloat(totalAmount).toFixed(2)}</span>
            `;
            document.getElementById('voidAdminPassword').value = '';
            document.getElementById('voidReasonText').value = '';
            document.getElementById('voidCharCount').textContent = '0';
            document.getElementById('voidAuthModal').classList.add('active');
            setTimeout(() => document.getElementById('voidAdminPassword').focus(), 100);
        }
        
        function closeVoidAuthModal() {
            document.getElementById('voidAuthModal').classList.remove('active');
            document.getElementById('voidAuthForm').reset();
        }
        
        // Character counter for void reason
        document.getElementById('voidReasonText')?.addEventListener('input', function() {
            const cnt = this.value.length;
            document.getElementById('voidCharCount').textContent = cnt;
            if (cnt > 500) {
                this.value = this.value.substring(0, 500);
                document.getElementById('voidCharCount').textContent = '500';
            }
        });
        
        // Submit void authorization
        async function submitVoidAuth(event) {
            event.preventDefault();
            
            const voidType = document.getElementById('voidType').value;
            const targetId = document.getElementById('voidTargetId').value;
            const adminPassword = document.getElementById('voidAdminPassword').value;
            const voidReason = document.getElementById('voidReasonText').value.trim();
            
            if (!voidReason) {
                alert('Please enter a reason for voiding');
                return;
            }
            
            const submitBtn = document.getElementById('voidSubmitBtn');
            const origText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Authorizing...';
            
            try {
                const requestBody = {
                    void_type: voidType,
                    admin_password: adminPassword,
                    void_reason: voidReason
                };
                
                if (voidType === 'item') {
                    requestBody.sale_item_id = parseInt(targetId);
                } else if (voidType === 'sale') {
                    requestBody.sale_id = parseInt(targetId);
                }
                
                const response = await fetch('api/void_item.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify(requestBody)
                });
                
                const result = await response.json();
                
                submitBtn.disabled = false;
                submitBtn.textContent = origText;
                
                if (response.ok && result.success) {
                    closeVoidAuthModal();
                    
                    // Show success message
                    const message = voidType === 'item' 
                        ? 'Item voided successfully! Inventory has been restored.'
                        : 'Sale voided successfully! All inventory has been restored.';
                    alert(message);
                    
                    // Refresh the sale details
                    if (currentSaleData?.saleId) {
                        viewSaleDetails(currentSaleData.saleId);
                    }
                    
                    // Optionally refresh the page to update the list
                    // location.reload();
                } else if (response.status === 429) {
                    alert(result.error || 'Too many failed attempts. Please wait before trying again.');
                } else if (response.status === 401) {
                    alert('Invalid admin password');
                } else {
                    alert('Error: ' + (result.error || 'Unable to process void'));
                }
            } catch (error) {
                submitBtn.disabled = false;
                submitBtn.textContent = origText;
                alert('Error contacting server');
                console.error('Void error:', error);
            }
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
                } else if (e.target.id === 'voidAuthModal') {
                    closeVoidAuthModal();
                }
            }
        });
    </script>
</body>

</html>