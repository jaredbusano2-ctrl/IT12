<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

requireLogin();
$user = getCurrentUser();

// Get cart from sessionStorage (passed via JavaScript)
$cart = [];
// For now, we'll use a simple approach - get cart from URL parameter
if (isset($_GET['cart'])) {
    $cart = json_decode(base64_decode($_GET['cart']), true);
}

if (empty($cart)) {
    // If no cart, redirect back to POS
    header('Location: pos.php');
    exit();
}

// Handle checkout submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_sale'])) {
    $customer_name = $_POST['customer_name'] ?? '';
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $discount = floatval($_POST['discount'] ?? 0);
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    
    // Calculate totals
    $subtotal = 0;
    foreach ($cart as $item) {
        $subtotal += $item['price'] * $item['quantity'];
    }
    $tax = $subtotal * 0.12;
    $total = $subtotal + $tax - $discount;
    $change = $amount_paid - $total;
    
    if ($amount_paid < $total) {
        $error = "Amount paid is less than total!";
    } else {
        // Process sale
        $conn->begin_transaction();
        
        try {
            // Generate invoice number
            $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Insert sale
            $stmt = $conn->prepare("
                INSERT INTO sales (invoice_number, user_id, customer_name, subtotal, tax, discount, total_amount, amount_paid, change_amount, payment_method) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sisdddddds", 
                $invoice_number, 
                $user['user_id'], 
                $customer_name, 
                $subtotal, 
                $tax, 
                $discount, 
                $total, 
                $amount_paid, 
                $change, 
                $payment_method
            );
            $stmt->execute();
            $sale_id = $conn->insert_id;
            
            // Insert sale items
            foreach ($cart as $item) {
                $stmt = $conn->prepare("
                    INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("iiidd", 
                    $sale_id, 
                    $item['id'], 
                    $item['quantity'], 
                    $item['price'], 
                    $item['subtotal']
                );
                $stmt->execute();
                
                // Update stock
                $conn->query("UPDATE products SET stock_quantity = stock_quantity - {$item['quantity']} WHERE product_id = {$item['id']}");
                
                // Record stock movement
                $stmt = $conn->prepare("
                    INSERT INTO stock_movements (product_id, movement_type, quantity, notes, user_id) 
                    VALUES (?, 'out', ?, ?, ?)
                ");
                $stmt->bind_param("iisi", 
                    $item['id'], 
                    $item['quantity'], 
                    "Sale - " . $invoice_number, 
                    $user['user_id']
                );
                $stmt->execute();
            }
            
            $conn->commit();
            
            header('Location: receipt.php?invoice=' . $invoice_number . '&success=1');
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error processing sale: " . $e->getMessage();
        }
    }
}

// Calculate totals for display
$subtotal = 0;
foreach ($cart as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$tax = $subtotal * 0.12;
$total = $subtotal + $tax;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - POPRIE</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="main-wrapper">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>Checkout</h1>
                <div class="header-actions">
                    <a href="pos.php" class="btn btn-secondary btn-sm">← Back to POS</a>
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
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="checkout-container">
                <div class="checkout-grid">
                    <!-- Order Summary -->
                    <div class="order-summary">
                        <h3>Order Summary</h3>
                        <div class="cart-items">
                            <?php foreach ($cart as $item): ?>
                                <div class="cart-item">
                                    <div class="cart-item-details">
                                        <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                                        <p>₱<?php echo number_format($item['price'], 2); ?> × <?php echo $item['quantity']; ?></p>
                                    </div>
                                    <div class="cart-item-actions">
                                        <strong style="color: var(--primary);">₱<?php echo number_format($item['subtotal'], 2); ?></strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="cart-total">
                            <div class="total-row">
                                <span>Subtotal:</span>
                                <strong>₱<?php echo number_format($subtotal, 2); ?></strong>
                            </div>
                            <div class="total-row">
                                <span>Tax (12%):</span>
                                <strong>₱<?php echo number_format($tax, 2); ?></strong>
                            </div>
                            <div class="total-row">
                                <span>Discount:</span>
                                <strong>₱<?php echo number_format($discount, 2); ?></strong>
                            </div>
                            <div class="total-row grand-total">
                                <span>Total:</span>
                                <strong>₱<?php echo number_format($total, 2); ?></strong>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Details -->
                    <div class="payment-details">
                        <h3>Payment Details</h3>
                        <form method="POST" action="">
                            <input type="hidden" name="complete_sale" value="1">
                            <input type="hidden" name="cart" value="<?php echo htmlspecialchars(json_encode($cart)); ?>">
                            
                            <div class="form-group">
                                <label>Customer Name (Optional)</label>
                                <input type="text" class="form-control" name="customer_name" placeholder="Enter customer name" value="<?php echo htmlspecialchars($_POST['customer_name'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Payment Method</label>
                                <select class="form-control" name="payment_method" required>
                                    <option value="cash" <?php echo (($_POST['payment_method'] ?? 'cash') === 'cash' ? 'selected' : ''); ?>>Cash</option>
                                    <option value="card" <?php echo (($_POST['payment_method'] ?? '') === 'card' ? 'selected' : ''); ?>>Card</option>
                                    <option value="online" <?php echo (($_POST['payment_method'] ?? '') === 'online' ? 'selected' : ''); ?>>Online Payment</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Discount Amount</label>
                                <input type="number" class="form-control" name="discount" value="<?php echo htmlspecialchars($_POST['discount'] ?? '0'); ?>" min="0" step="0.01" placeholder="0.00">
                            </div>
                            
                            <div class="form-group">
                                <label>Amount Paid</label>
                                <input type="number" class="form-control" name="amount_paid" value="<?php echo htmlspecialchars($_POST['amount_paid'] ?? ''); ?>" required min="0" step="0.01" placeholder="0.00">
                            </div>
                            
                            <button type="submit" class="btn btn-success" style="width: 100%;">Complete Sale</button>
                        </form>
                                
                                <div style="display: flex; gap: 8px; margin-top: 16px;">
                                    <button type="button" class="btn btn-secondary" onclick="history.back()" style="flex: 1;">Cancel</button>
                                    <button type="submit" class="btn btn-success" style="flex: 2;">Complete Sale</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Cart data from sessionStorage
        let cart = [];
        
        // Load cart from sessionStorage
        function loadCart() {
            const savedCart = sessionStorage.getItem('posCart');
            if (savedCart) {
                cart = JSON.parse(savedCart);
                displayCart();
                updateTotals();
            } else {
                // Redirect to POS if no cart
                window.location.href = 'pos.php';
            }
        }
        
        // Display cart items
        function displayCart() {
            const cartItemsDiv = document.getElementById('cartItems');
            
            if (cart.length === 0) {
                cartItemsDiv.innerHTML = '<p style="text-align: center; color: var(--text-secondary); padding: 20px;">Cart is empty</p>';
            } else {
                cartItemsDiv.innerHTML = cart.map(item => `
                    <div class="cart-item">
                        <div class="cart-item-details">
                            <h4>${item.name}</h4>
                            <p>₱${item.price.toFixed(2)} × ${item.quantity}</p>
                        </div>
                        <div class="cart-item-actions">
                            <strong style="color: var(--primary);">₱${item.subtotal.toFixed(2)}</strong>
                        </div>
                    </div>
                `).join('');
            }
        }
        
        // Update totals
        function updateTotals() {
            const subtotal = cart.reduce((sum, item) => sum + item.subtotal, 0);
            const tax = subtotal * 0.12;
            const discount = parseFloat(document.getElementById('discountAmount').value || 0);
            const total = subtotal + tax - discount;
            
            document.getElementById('subtotal').textContent = '₱' + subtotal.toFixed(2);
            document.getElementById('tax').textContent = '₱' + tax.toFixed(2);
            document.getElementById('discount').textContent = '₱' + discount.toFixed(2);
            document.getElementById('grandTotal').textContent = '₱' + total.toFixed(2);
            
            // Update change calculation
            calculateChange();
        }
        
        // Calculate change
        function calculateChange() {
            const subtotal = cart.reduce((sum, item) => sum + item.subtotal, 0);
            const tax = subtotal * 0.12;
            const discount = parseFloat(document.getElementById('discountAmount').value || 0);
            const total = subtotal + tax - discount;
            const paid = parseFloat(document.getElementById('amountPaid').value || 0);
            const change = paid - total;
            
            document.getElementById('changeAmount').value = change >= 0 ? '₱' + change.toFixed(2) : '₱0.00';
        }
        
        // Handle checkout form submission
        document.getElementById('checkoutForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const subtotal = cart.reduce((sum, item) => sum + item.subtotal, 0);
            const tax = subtotal * 0.12;
            const discount = parseFloat(document.getElementById('discountAmount').value || 0);
            const total = subtotal + tax - discount;
            const paid = parseFloat(document.getElementById('amountPaid').value);
            
            if (paid < total) {
                alert('Amount paid is less than total!');
                return;
            }
            
            const saleData = {
                customer_name: document.getElementById('customerName').value,
                payment_method: document.getElementById('paymentMethod').value,
                subtotal: subtotal,
                tax: tax,
                discount: discount,
                total: total,
                paid: paid,
                change: paid - total,
                items: cart
            };
            
            try {
                const response = await fetch('api/process-sale.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(saleData)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Sale completed successfully!\nInvoice: ' + result.invoice);
                    
                    // Clear cart from sessionStorage
                    sessionStorage.removeItem('posCart');
                    
                    // After clicking OK on the alert, show receipt in the same tab
                    const receiptUrl = 'receipt.php?invoice=' + encodeURIComponent(result.invoice);
                    window.location.href = receiptUrl;
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                alert('Error processing sale: ' + error.message);
            }
        });
        
        // Event listeners
        document.getElementById('discountAmount').addEventListener('input', updateTotals);
        document.getElementById('amountPaid').addEventListener('input', calculateChange);
        
        // Load cart when page loads
        document.addEventListener('DOMContentLoaded', loadCart);
    </script>
</body>
</html>
