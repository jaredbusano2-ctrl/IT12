<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';

setSecurityHeaders();

// IMPORTANT:
// This endpoint returns ONLY <tr> rows for AJAX/table includes.
// Do not redirect (redirect HTML would get injected into the table).
if (!isLoggedIn()) {
    http_response_code(401);
    echo '<tr><td colspan="12" style="text-align:center;padding:24px;color:var(--text-secondary);">Session expired. Please log in again.</td></tr>';
    exit;
}

$currentUser = getCurrentUser();
$isCashier = ($currentUser['role'] ?? null) === 'cashier';

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 5;
$offset = ($page - 1) * $limit;

// Optional date filter (YYYY-MM-DD)
$date = isset($_GET['date']) && $_GET['date'] !== '' ? $_GET['date'] : null;

$sql = "
    SELECT
        s.sale_id,
        s.invoice_number,
        s.customer_name,
        s.subtotal,
        s.tax,
        s.discount,
        s.total_amount,
        s.payment_method,
        s.sale_date,
        s.status,
        u.full_name,
        COUNT(si.sale_item_id) AS items_count
    FROM sales s
    LEFT JOIN users u ON s.user_id = u.user_id
    LEFT JOIN sale_items si ON si.sale_id = s.sale_id
";

$params = [];
$types = '';

$where = [];
if ($isCashier) {
    $where[] = 's.user_id = ?';
    $params[] = (int)($currentUser['user_id'] ?? 0);
    $types .= 'i';
}

if ($date) {
    $where[] = 'DATE(s.sale_date) = ?';
    $params[] = $date;
    $types .= 's';
}

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where) . ' ';
}

$sql .= "
    GROUP BY s.sale_id
    ORDER BY s.sale_date DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo '<tr><td colspan="12" style="text-align:center;padding:24px;color:var(--text-secondary);">Failed to load sales.</td></tr>';
    exit;
}

// mysqli bind_param requires variables (by reference), so bind explicitly
if ($isCashier && $date) {
    $cashierId = (int)($currentUser['user_id'] ?? 0);
    $stmt->bind_param('isii', $cashierId, $date, $limit, $offset);
} elseif ($isCashier && !$date) {
    $cashierId = (int)($currentUser['user_id'] ?? 0);
    $stmt->bind_param('iii', $cashierId, $limit, $offset);
} elseif (!$isCashier && $date) {
    $stmt->bind_param('sii', $date, $limit, $offset);
} else {
    $stmt->bind_param('ii', $limit, $offset);
}
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows <= 0) {
    echo '<tr><td colspan="12" style="text-align:center;padding:24px;color:var(--text-secondary);">No sales records found.</td></tr>';
    exit;
}

while ($sale = $result->fetch_assoc()) {
    $isVoided = ($sale['status'] ?? 'completed') === 'voided';
    $statusBadge = $isVoided
        ? '<span class="badge" style="background:#d32f2f;color:white;">🔴 Voided</span>'
        : '<span class="badge" style="background:#10b981;color:white;">🟢 Completed</span>';

    echo '<tr' . ($isVoided ? ' style="opacity:0.65;"' : '') . '>';
    echo '<td><strong>' . htmlspecialchars($sale['invoice_number']) . '</strong></td>';
    echo '<td>' . htmlspecialchars(($sale['customer_name'] ?: 'Walk-in')) . '</td>';
    echo '<td>' . htmlspecialchars($sale['full_name'] ?? '') . '</td>';
    echo '<td>' . (int)$sale['items_count'] . ' item(s)</td>';
    echo '<td>₱' . number_format((float)$sale['subtotal'], 2) . '</td>';
    echo '<td>₱' . number_format((float)$sale['tax'], 2) . '</td>';
    echo '<td>₱' . number_format((float)$sale['discount'], 2) . '</td>';
    echo '<td><strong>₱' . number_format((float)$sale['total_amount'], 2) . '</strong></td>';
    echo '<td><span class="badge badge-primary">' . htmlspecialchars(ucfirst($sale['payment_method'])) . '</span></td>';
    echo '<td>' . date('M d, Y H:i', strtotime($sale['sale_date'])) . '</td>';
    echo '<td>' . $statusBadge . '</td>';
    echo '<td>';
    echo '<button class="btn btn-primary btn-sm" onclick="viewSaleDetails(' . (int)$sale['sale_id'] . ')">View</button> ';
    echo '<a href="receipt.php?invoice=' . urlencode($sale['invoice_number']) . '" target="_blank" class="btn btn-success btn-sm">Receipt</a> ';
    echo '</td>';
    echo '</tr>';
}
