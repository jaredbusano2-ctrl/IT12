<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

requireLogin();

$invoice_number = $_GET['invoice'] ?? '';

if (empty($invoice_number)) {
    die('Invalid invoice number');
}

// Get sale details
$sale_result = $conn->query("
    SELECT s.*, u.full_name 
    FROM sales s 
    LEFT JOIN users u ON s.user_id = u.user_id 
    WHERE s.invoice_number = '$invoice_number'
");

if ($sale_result->num_rows === 0) {
    die('Invoice not found');
}

$sale = $sale_result->fetch_assoc();

// Get sale items with product details
$items_result = $conn->query("
    SELECT si.*, si.cup_size as size FROM sale_items si
    WHERE si.sale_id = {$sale['sale_id']}
");
$items = [];
if ($items_result) {
    while ($row = $items_result->fetch_assoc()) {
        $items[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo $invoice_number; ?></title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            background: #f5f5f5;
            padding: 20px;
        }
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .receipt-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px dashed #ddd;
        }
        
        .receipt-header h1 {
            margin: 0 0 10px 0;
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .receipt-header img {
            margin: 10px 0;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .receipt-header p {
            margin: 15px 0 0 0;
            font-size: 20px;
            color: var(--text-secondary);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .receipt-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        .info-block h4 {
            font-size: 12px;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 8px;
        }
        .info-block p {
            margin: 4px 0;
        }
        .items-table {
            width: 100%;
            margin-bottom: 30px;
        }
        .items-table th {
            background: #f5f5f5;
            padding: 12px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
        }
        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        .totals {
            margin-left: auto;
            width: 300px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
        }
        .total-row.grand {
            border-top: 2px solid #333;
            margin-top: 12px;
            padding-top: 12px;
            font-size: 20px;
            font-weight: bold;
        }
        .receipt-footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #333;
        }
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .receipt-container {
                box-shadow: none;
                padding: 20px;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="receipt-header">
            <h1></h1>
            <img src="pictures/poprie.jpg" alt="POPRIE Logo" width="100" height="100"/>
            <p>Sales Receipt</p>
        </div>

        <?php
        // Check if sale is voided
        $void_check = $conn->query("
            SELECT sv.* FROM sale_voids sv 
            WHERE sv.cart_items LIKE '%\"id\":{$sale['sale_id']}%' 
            OR sv.void_id = {$sale['sale_id']}
            LIMIT 1
        ");
        $is_voided = $void_check && $void_check->num_rows > 0 ? $void_check->fetch_assoc() : null;
        ?>

        <?php if ($is_voided): ?>
        <div style="background: #fee; border: 2px solid #c33; color: #c33; padding: 16px; margin: 20px 0; text-align: center; font-weight: bold; font-size: 18px; border-radius: 6px;">
            ❌ VOIDED SALE - NOT VALID FOR TRANSACTION
        </div>
        <?php endif; ?>
        
        <div class="receipt-info">
            <div class="info-block">
                <h4>Invoice Information</h4>
                <p><strong>Invoice #:</strong> <?php echo $invoice_number; ?></p>
                <p><strong>Date:</strong> <?php echo date('F d, Y h:i A', strtotime($sale['sale_date'])); ?></p>
                <p><strong>Payment Method:</strong> <?php echo ucfirst($sale['payment_method']); ?></p>
            </div>
            
            <div class="info-block">
                <h4>Customer Information</h4>
                <p><strong>Name:</strong> <?php echo $sale['customer_name'] ?: 'Walk-in Customer'; ?></p>
                <p><strong>Served by:</strong> <?php echo $sale['full_name']; ?></p>
            </div>
        </div>
        
        <table class="items-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Size</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td><?php echo $item['size'] ?: '—'; ?></td>
                        <td><?php echo $item['quantity']; ?></td>
                        <td>₱<?php echo number_format($item['unit_price'], 2); ?></td>
                        <td>₱<?php echo number_format($item['subtotal'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="totals">
            <div class="total-row">
                <span>Subtotal:</span>
                <strong>₱<?php echo number_format($sale['subtotal'], 2); ?></strong>
            </div>
            <div class="total-row">
                <span>Tax (12%):</span>
                <strong>₱<?php echo number_format($sale['tax'], 2); ?></strong>
            </div>
            <?php if ($sale['discount'] > 0): ?>
                <div class="total-row">
                    <span>Discount:</span>
                    <strong>-₱<?php echo number_format($sale['discount'], 2); ?></strong>
                </div>
            <?php endif; ?>
            <div class="total-row grand">
                <span>Grand Total:</span>
                <strong>₱<?php echo number_format($sale['total_amount'], 2); ?></strong>
            </div>
            <div class="total-row">
                <span>Amount Paid:</span>
                <strong>₱<?php echo number_format($sale['amount_paid'], 2); ?></strong>
            </div>
            <div class="total-row">
                <span>Change:</span>
                <strong>₱<?php echo number_format($sale['change_amount'], 2); ?></strong>
            </div>
        </div>
        
        <div class="receipt-footer">
            <p><strong>Thank you for your purchase!</strong></p>
            <p style="font-size: 12px; color: #666; margin-top: 8px;">
                This is a computer-generated receipt and is valid without signature
            </p>
        </div>
        
        <div class="no-print" style="text-align: center; margin-top: 30px;">
            <button class="btn btn-primary" onclick="window.print()">Print Receipt</button>
            <button class="btn btn-secondary" onclick="window.location.href='pos.php'">Back to POS</button>
        </div>
    </div>
</body>
</html>
