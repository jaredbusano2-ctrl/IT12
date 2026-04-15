<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Receipt</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .receipt {
            max-width: 400px;
            margin: 0 auto;
            background: white;
            padding: 30px 20px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        
        .receipt-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px dashed #ccc;
        }
        
        .receipt-header h1 {
            font-size: 20px;
            color: #d32f2f;
            margin-bottom: 5px;
        }
        
        .receipt-header p {
            font-size: 12px;
            color: #666;
        }
        
        .receipt-info {
            margin-bottom: 20px;
            font-size: 13px;
        }
        
        .receipt-info p {
            margin: 5px 0;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 12px;
        }
        
        .items-table th {
            text-align: left;
            padding: 8px 0;
            border-bottom: 1px solid #ccc;
            font-weight: bold;
        }
        
        .items-table td {
            padding: 6px 0;
            border-bottom: 1px dotted #eee;
        }
        
        .items-table td:last-child,
        .items-table th:last-child {
            text-align: right;
        }
        
        .totals {
            border-top: 2px solid #333;
            padding-top: 15px;
            margin-top: 15px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
            font-size: 13px;
        }
        
        .total-row.grand {
            font-size: 16px;
            font-weight: bold;
            border-top: 1px solid #ccc;
            margin-top: 10px;
            padding-top: 10px;
        }
        
        .receipt-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px dashed #ccc;
            font-size: 12px;
        }
        
        .receipt-footer p {
            margin: 5px 0;
        }
        
        .no-print {
            text-align: center;
            margin-top: 30px;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 8px;
        }
        
        .btn {
            padding: 12px 24px;
            margin: 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }
        
        .btn-print {
            background: #d32f2f;
            color: white;
        }
        
        .btn-close {
            background: #666;
            color: white;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .receipt {
                box-shadow: none;
                max-width: 100%;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="receipt" id="receipt">
        <div class="receipt-header">
            <h1>POPRIE COFFEE SHOP</h1>
            <p>Sales Receipt</p>
        </div>
        
        <div class="receipt-info" id="receiptInfo">
            <!-- Data will be inserted here -->
        </div>
        
        <table class="items-table" id="itemsTable">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody id="itemsBody">
                <!-- Items will be inserted here -->
            </tbody>
        </table>
        
        <div class="totals" id="totals">
            <!-- Totals will be inserted here -->
        </div>
        
        <div class="receipt-footer">
            <p><strong>Thank you for your purchase!</strong></p>
            <p style="font-size: 10px; color: #999;">This is a computer-generated receipt</p>
        </div>
        
        <div class="no-print">
            <button class="btn btn-print" onclick="window.print()">Print Receipt</button>
            <button class="btn btn-close" onclick="window.location.href='pos.php'">Back to POS</button>
        </div>
    </div>
    
    <script>
        // Load receipt data from localStorage
        window.onload = function() {
            const receiptData = localStorage.getItem('currentReceipt');
            
            if (!receiptData) {
                document.getElementById('receipt').innerHTML = `
                    <div style="text-align: center; padding: 40px;">
                        <h2>No receipt data found</h2>
                        <p>Please complete a sale in the POS system first.</p>
                        <button class="btn btn-close" onclick="window.close()">Close</button>
                    </div>
                `;
                return;
            }
            
            const data = JSON.parse(receiptData);
            
            // Fill receipt info
            document.getElementById('receiptInfo').innerHTML = `
                <p><strong>Invoice:</strong> ${data.invoice}</p>
                <p><strong>Date:</strong> ${data.date}</p>
                <p><strong>Customer:</strong> ${data.customer}</p>
                <p><strong>Payment:</strong> ${data.paymentMethod}</p>
            `;
            
            // Fill items
            const itemsBody = document.getElementById('itemsBody');
            itemsBody.innerHTML = data.items.map(item => `
                <tr>
                    <td>${item.name}</td>
                    <td>${item.quantity}</td>
                    <td>₱${item.price.toFixed(2)}</td>
                    <td>₱${item.subtotal.toFixed(2)}</td>
                </tr>
            `).join('');
            
            // Fill totals
            document.getElementById('totals').innerHTML = `
                <div class="total-row">
                    <span>Subtotal:</span>
                    <span>₱${data.subtotal.toFixed(2)}</span>
                </div>
                <div class="total-row">
                    <span>Tax (12%):</span>
                    <span>₱${data.tax.toFixed(2)}</span>
                </div>
                ${data.discount > 0 ? `
                <div class="total-row">
                    <span>Discount:</span>
                    <span>-₱${data.discount.toFixed(2)}</span>
                </div>
                ` : ''}
                <div class="total-row grand">
                    <span>Grand Total:</span>
                    <span>₱${data.total.toFixed(2)}</span>
                </div>
                <div class="total-row">
                    <span>Amount Paid:</span>
                    <span>₱${data.paid.toFixed(2)}</span>
                </div>
                <div class="total-row">
                    <span>Change:</span>
                    <span>₱${data.change.toFixed(2)}</span>
                </div>
            `;
        };
    </script>
</body>
</html>
