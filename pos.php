<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';

// Set security headers
setSecurityHeaders();

requireLogin();

// Check page access based on role
checkPageAccess();

$user = getCurrentUser();

// Generate CSRF token for AJAX requests
$csrfToken = getCSRFTokenForAjax();

// Get all active products with categories
$products = $conn->query("
    SELECT p.*, c.category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.category_id 
    WHERE p.status = 'active' 
    ORDER BY p.product_name ASC
");

// Get categories for filter
$categories = $conn->query("SELECT * FROM categories ORDER BY category_name ASC");

// Get product cup sizes for JavaScript
$cupSizesQuery = $conn->query("
    SELECT pcs.product_id, pcs.cup_id, pcs.price, ci.cup_size
    FROM product_cup_sizes pcs
    JOIN cup_inventory ci ON pcs.cup_id = ci.cup_id
    WHERE ci.status = 'active'
    ORDER BY pcs.product_id, ci.cup_id
");

$productCupSizes = [];
if ($cupSizesQuery) {
    while ($row = $cupSizesQuery->fetch_assoc()) {
        $productCupSizes[(int)$row['product_id']][] = [
            'cup_id' => (int)$row['cup_id'],
            'cup_size' => $row['cup_size'],
            'price' => (float)$row['price']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point of Sale - POS & Inventory System</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        <?php if ($user['role'] === 'cashier'): ?>
        /* CASHIER-ONLY LAYOUT */
        .main-wrapper {
            display: flex;
            height: 100vh;
            flex-direction: column;
            background: #E8E4C9;
        }

        .sidebar {
            display: none !important;
        }

        .main-content {
            width: 100%;
            margin-left: 0;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        .header {
            padding: 30px 40px;
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F8F8 100%);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12), 0 4px 12px rgba(0, 0, 0, 0.08);
            flex-shrink: 0;
            margin: 20px 20px 20px 20px;
            border-radius: 12px;
            position: relative;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #d32f2f;
            margin: 0 0 12px 0;
        }

        .header-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 20px;
            margin-top: 12px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #d32f2f 0%, #c62828 100%);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
        }

        .user-details h4 {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin: 0;
        }

        .user-details p {
            font-size: 12px;
            color: #999;
            margin: 0;
        }

        .pos-grid {
            display: flex;
            flex: 1;
            gap: 20px;
            overflow: visible;
            padding: 20px;
        }

        .pos-products {
            flex: 1;
            width: 70%;
            display: flex;
            flex-direction: column;
            border-right: none;
            background: #FFFFFF;
            padding: 24px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            position: relative;
            transition: all 0.3s ease;
        }

        .pos-products:hover {
            box-shadow: 0 4px 12px rgba(211, 47, 47, 0.08);
            transform: translateY(-1px);
        }

        .pos-cart {
            width: 30%;
            display: flex;
            flex-direction: column;
            background: #FFFFFF;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-radius: 10px;
            position: relative;
            transition: box-shadow 0.3s ease;
            border: 1px solid #f5f5f5;
            overflow: visible;
        }

        .pos-cart:hover {
            box-shadow: 0 4px 12px rgba(211, 47, 47, 0.08);
        }

        .pos-products > div:first-child {
            padding: 0;
            border-bottom: none;
            background: transparent;
            margin-bottom: 20px;
            border-radius: 0;
            box-shadow: none;
        }

        .product-grid {
            flex: 1;
            overflow-y: auto;
            padding: 12px 0;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 16px;
        }

        .product-card {
            background: #FFFFFF;
            border: 2px solid #f0f0f0;
            border-radius: 10px;
            padding: 16px 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .product-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: #d32f2f;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(211, 47, 47, 0.15);
            border-color: #d32f2f;
            background: #FFFFFF;
        }

        .product-card:hover::before {
            opacity: 1;
        }

        .cup-size-buttons {
            display: flex;
            gap: 6px;
            justify-content: center;
            margin: 8px 0;
        }

        .cup-btn {
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 600;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            background: #f8f8f8;
            cursor: pointer;
            transition: all 0.2s ease;
            color: #555;
        }

        .cup-btn:hover {
            border-color: #d32f2f;
            color: #d32f2f;
            background: #fff;
        }

        .cup-btn.selected {
            border-color: #d32f2f;
            background: #d32f2f;
            color: white;
        }

        .product-card h4 {
            font-size: 14px;
            font-weight: 700;
            color: #222;
            margin: 0 0 10px 0;
            word-break: break-word;
            line-height: 1.3;
        }

        .product-card .price {
            font-size: 16px;
            font-weight: 800;
            color: #d32f2f;
            margin: 8px 0;
        }

        .product-card .stock {
            font-size: 12px;
            color: #888;
            font-weight: 500;
        }

        .pos-cart h3 {
            padding: 24px 24px 0 24px;
            font-size: 15px;
            font-weight: 800;
            color: #d32f2f;
            margin-bottom: 16px;
            border-bottom: 3px solid #d32f2f;
            padding-bottom: 14px;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            text-align: center;
        }

        .cart-items {
            flex: 1;
            overflow-y: auto;
            margin-bottom: 16px;
            border-top: none;
            padding: 16px 24px;
        }

        .cart-item {
            background: #FFFFFF;
            border: 1px solid #f0f0f0;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
        }

        .cart-item:hover {
            box-shadow: 0 4px 12px rgba(211, 47, 47, 0.12);
            transform: translateX(3px);
            border-color: #d32f2f;
        }

        .cart-item-info {
            flex: 1;
        }

        .cart-item-name {
            font-weight: 700;
            font-size: 13px;
            color: #222;
            margin-bottom: 4px;
        }

        .cart-item-price {
            font-size: 13px;
            color: #d32f2f;
            font-weight: 600;
        }

        .cart-item-actions {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .cart-item-qty {
            width: 30px;
            padding: 4px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            text-align: center;
            font-size: 12px;
        }

        .btn-void {
            background: #ffc107;
            color: #212529;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-void:hover {
            background: #e0a800;
            transform: scale(1.05);
        }

        /* Voided item styling */
        .voided-item {
            opacity: 0.6;
            background: #f5f5f5 !important;
            border-color: #ddd !important;
        }

        .voided-item .cart-item-name {
            text-decoration: line-through;
            color: #999;
        }

        .voided-item .cart-item-price {
            text-decoration: line-through;
            color: #ccc;
        }

        .voided-item .cart-item-actions {
            opacity: 0.5;
            pointer-events: none;
        }

        .voided-badge {
            display: inline-block;
            background: #d32f2f;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 3px;
            margin-left: 4px;
            font-weight: 600;
        }

        .cart-total {
            background: #FFFFFF;
            padding: 20px 24px;
            border-radius: 10px;
            border-top: 3px solid #d32f2f;
            margin: 0 24px 20px 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            position: relative;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 13px;
            color: #333;
        }

        .total-row:last-child {
            font-weight: 800;
            font-size: 15px;
            color: #d32f2f;
            border-top: 2px solid #e8e8e8;
            padding-top: 12px;
            margin-bottom: 0;
        }

        .cart-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 16px 24px 24px 24px;
            position: relative;
            z-index: 10;
        }

        .cart-actions button {
            width: 100%;
            padding: 12px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            font-size: 13px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .cart-actions button:hover {
            opacity: 0.9;
        }

        .cart-actions button:active {
            opacity: 0.8;
        }

        .form-control {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            margin-bottom: 10px;
            background: #FAFAFA;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #d32f2f;
            background: #FFFFFF;
            box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.08);
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
            color: #FFFFFF;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(220, 53, 69, 0.3);
        }

        /* additional standout box styles */
        .search-filter-container {
            background: #FFFFFF;
            padding: 16px 14px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #f5f5f5;
        }

        .search-filter-container input,
        .search-filter-container select {
            margin-bottom: 12px;
        }

        .search-filter-container input:last-child,
        .search-filter-container select:last-child {
            margin-bottom: 0;
        }

        .header-actions .user-info {
            background: #FFFFFF;
            padding: 10px 14px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            border: 1px solid #f5f5f5;
        }

        /* MODAL STYLING */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: #FFFFFF;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 24px;
            border-bottom: 2px solid #d32f2f;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 18px;
            color: #d32f2f;
            font-weight: 800;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #999;
            transition: color 0.2s ease;
        }

        .modal-close:hover {
            color: #d32f2f;
        }

        .modal-body {
            padding: 24px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            color: #333;
        }

        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            background: #FAFAFA;
            transition: all 0.2s ease;
        }

        .form-group textarea:focus {
            outline: none;
            border-color: #d32f2f;
            background: #FFFFFF;
            box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.08);
        }

        .btn {
            padding: 12px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-secondary {
            background: #E8E8E8;
            color: #444;
            border: 1px solid #d0d0d0;
        }

        .btn-secondary:hover {
            background: #D8D8D8;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: #d32f2f;
            color: white;
        }

        .btn-danger:hover {
            background: #c62828;
            transform: translateY(-2px);
        }

        /* SCROLLBAR STYLING */
        .cart-items::-webkit-scrollbar,
        .product-grid::-webkit-scrollbar {
            width: 6px;
        }

        .cart-items::-webkit-scrollbar-track,
        .product-grid::-webkit-scrollbar-track {
            background: #f0f0f0;
        }

        .cart-items::-webkit-scrollbar-thumb,
        .product-grid::-webkit-scrollbar-thumb {
            background: #ddd;
            border-radius: 3px;
        }

        .cart-items::-webkit-scrollbar-thumb:hover,
        .product-grid::-webkit-scrollbar-thumb:hover {
            background: #ccc;
        }

        @media (max-width: 1024px) {
            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 14px;
                padding: 12px 0;
            }
        }

        @media (max-width: 768px) {
            .pos-grid {
                flex-direction: column;
            }

            .pos-products {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid #e0e0e0;
            }

            .pos-cart {
                width: 100%;
                min-height: 300px;
            }

            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
                gap: 12px;
                padding: 12px 0;
            }
        }
        <?php endif; ?>
    </style>
</head>
<body>
<div class="main-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <!-- Hamburger Menu Button -->
                <button class="hamburger-menu" onclick="toggleSidebar()">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <h1>Point of Sale</h1>
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
        
        <div class="pos-grid">
            <!-- PRODUCTS -->
            <div class="pos-products">
                <div class="search-filter-container" style="margin-bottom: 16px;">
                    <input type="text" id="searchProduct" class="form-control" placeholder="Search products..." style="margin-bottom: 12px;">
                    
                    <select id="filterCategory" class="form-control">
                        <option value="">All Categories</option>
                        <?php 
                        $categories->data_seek(0);
                        while ($cat = $categories->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $cat['category_id']; ?>">
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="product-grid">
                    <?php while ($product = $products->fetch_assoc()): 
                        $productId = (int)$product['product_id'];
                        $hasCupSizes = !empty($productCupSizes[$productId]);
                        $cupSizesJson = $hasCupSizes
                            ? htmlspecialchars(json_encode($productCupSizes[$productId]), ENT_QUOTES, 'UTF-8')
                            : '[]';

                        // Support both 'price' and 'selling_price' column names
                        $basePrice = $product['selling_price'] ?? $product['price'] ?? 0;
                        // If product has cup sizes, use the first cup size price as base
                        if ($hasCupSizes && isset($productCupSizes[$productId][0]['price'])) {
                            $basePrice = $productCupSizes[$productId][0]['price'];
                        }

                        $isDrinkFlag = (!empty($product['requires_cup']) || !empty($product['is_drink']));
                        $isDrink = $isDrinkFlag || $hasCupSizes;
                    ?>
                        <div class="product-card"
                             data-id="<?php echo $productId; ?>"
                             data-code="<?php echo htmlspecialchars($product['product_code']); ?>"
                             data-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                             data-price="<?php echo $basePrice; ?>"
                             data-stock="<?php echo $product['stock_quantity']; ?>"
                             data-category="<?php echo $product['category_id']; ?>"
                             data-is-drink="<?php echo $isDrink ? 'true' : 'false'; ?>"
                             data-cup-sizes='<?php echo $cupSizesJson; ?>'
                             onclick="handleProductClick(this, event)">
                             
                            <h4><?php echo htmlspecialchars($product['product_name']); ?></h4>
                            
                            <?php if ($hasCupSizes): ?>
                                <div class="cup-size-buttons">
                                    <?php foreach ($productCupSizes[$productId] as $cupSize): ?>
                                        <button type="button" class="cup-btn" 
                                                data-cup-id="<?php echo $cupSize['cup_id']; ?>"
                                                data-cup-size="<?php echo $cupSize['cup_size']; ?>"
                                                data-price="<?php echo $cupSize['price']; ?>"
                                                onclick="selectCupSize(this, event)">
                                            <?php echo $cupSize['cup_size']; ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <div class="price" data-base-price="<?php echo $basePrice; ?>">
                                    ₱<?php echo number_format($basePrice, 2); ?>
                                </div>
                            <?php else: ?>
                                <div class="price">₱<?php echo number_format($basePrice, 2); ?></div>
                            <?php endif; ?>
                            
                            <div class="stock">Stock: <?php echo $product['stock_quantity']; ?></div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
            
            <!-- CART -->
            <div class="pos-cart">
                <h3 style="margin-bottom: 16px;">Shopping Cart</h3>
                
                <div class="cart-items" id="cartItems">
                    <p style="text-align: center; padding: 40px 0;">Cart is empty</p>
                </div>
                
                <div class="cart-total">
                    <div class="total-row">
                        <span>Subtotal:</span>
                        <strong id="subtotal">₱0.00</strong>
                    </div>
                    <div class="total-row">
                        <span>Tax (12%):</span>
                        <strong id="tax">₱0.00</strong>
                    </div>
                    <div class="total-row">
                        <span>Discount:</span>
                        <strong id="discount">₱0.00</strong>
                    </div>
                    <div class="total-row grand-total">
                        <span>Total:</span>
                        <strong id="grandTotal">₱0.00</strong>
                    </div>
                </div>
                
                <!-- Cart Action Buttons -->
                <div class="cart-actions">
                    <button type="button" id="clearCartBtn" style="background: #dc3545; color: white;">
                        🗑️ CLEAR CART
                    </button>
                    <button type="button" id="voidCartBtn" style="background: #6c757d; color: white;">
                        ⚠️ VOID CART
                    </button>
                    <button type="button" id="completeSaleBtn" style="background: #28a745; color: white;">
                        ✓ COMPLETE SALE
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SINGLE CLEAN CHECKOUT MODAL -->
<div id="checkoutModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Complete Sale</h2>
            <button class="modal-close" onclick="closeCheckout()">&times;</button>
        </div>
        
        <div class="modal-body">
            <div class="checkout-modal-grid">
                
                <!-- ORDER SUMMARY -->
                <div class="order-summary">
                    <h3>Order Summary</h3>
                    <div id="modalCartItems"></div>
                    
                    <div class="modal-cart-total">
                        <div class="total-row">
                            <span>Subtotal:</span>
                            <strong id="modalSubtotal">₱0.00</strong>
                        </div>
                        <div class="total-row">
                            <span>Tax (12%):</span>
                            <strong id="modalTax">₱0.00</strong>
                        </div>
                        <div class="total-row">
                            <span>Discount:</span>
                            <strong id="modalDiscount">₱0.00</strong>
                        </div>
                        <div class="total-row grand-total">
                            <span>Total:</span>
                            <strong id="modalGrandTotal">₱0.00</strong>
                        </div>
                    </div>
                </div>

                <!-- PAYMENT FORM -->
                <div class="payment-details">
                    <h3>Payment Details</h3>
                    
                    <form id="checkoutForm">
                        <div class="form-group">
                            <label>Customer Name (Optional)</label>
                            <input type="text" class="form-control" id="customerName">
                        </div>

                        <div class="form-group">
                            <label>Payment Method</label>
                            <select class="form-control" id="paymentMethod" required>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="online">Online Payment</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Discount Amount</label>
                            <input type="number" class="form-control" id="discountAmount" value="0" min="0" step="0.01">
                        </div>

                        <div class="form-group">
                            <label>Amount Paid</label>
                            <input type="number" class="form-control" id="amountPaid" required min="0" step="0.01">
                        </div>

                        <div class="form-group">
                            <label>Change</label>
                            <input type="text" class="form-control" id="changeAmount" readonly>
                        </div>

                        <div style="display:flex; gap:8px; margin-top:16px;">
                            <button type="button" class="btn btn-secondary" onclick="closeCheckout()" style="flex:1;">Cancel</button>
                            <button type="submit" class="btn btn-success" style="flex:2;">Complete Sale</button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- CART VOID AUTHORIZATION MODAL -->
<div id="voidModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2>Void Cart Authorization</h2>
            <button class="modal-close" onclick="closeVoidModal()">&times;</button>
        </div>

        <div class="modal-body">
            <div style="margin-bottom: 16px;">
                <p style="color: #666; font-size: 13px; margin: 0 0 16px 0;">
                    All items below will be voided. This requires admin authorization.
                </p>

                <div style="margin-bottom: 16px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 8px; color: #333;">
                        Items to Void
                    </label>
                    <div id="voidItemsList" style="max-height: 200px; overflow-y: auto; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 12px; font-size: 14px; color: #333;"></div>
                </div>

                <form id="voidForm" method="POST" action="">
                    <input type="hidden" id="voidSaleItemId" name="sale_item_id">
                    <input type="hidden" name="void_item" value="1">

                    <div class="form-group">
                        <label style="font-weight: 600; display: block; margin-bottom: 8px; color: #333;">
                            Admin Password
                        </label>
                        <input type="password" class="form-control" id="adminPassword" name="admin_password"
                               placeholder="Enter admin password" required>
                    </div>

                    <div style="display: flex; gap: 8px; margin-top: 20px;">
                        <button type="button" class="btn btn-secondary" onclick="closeVoidModal()" style="flex: 1;">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-danger" style="flex: 1;">
                            Confirm Void
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- SINGLE ITEM VOID MODAL -->
<div id="itemVoidModal" class="modal">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <h2>Void Item</h2>
            <button class="modal-close" onclick="closeItemVoidModal()">&times;</button>
        </div>

        <div class="modal-body">
            <div style="margin-bottom: 16px;">
                <p style="color: #666; font-size: 13px; margin: 0 0 16px 0;">
                    This action requires admin authorization to void.
                </p>

                <!-- Item Details Display -->
                <div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <tr>
                            <td style="padding: 4px 0; color: #666;">Product:</td>
                            <td style="padding: 4px 0; font-weight: 600; text-align: right;" id="itemVoidName">-</td>
                        </tr>
                        <tr>
                            <td style="padding: 4px 0; color: #666;">Quantity:</td>
                            <td style="padding: 4px 0; font-weight: 600; text-align: right;" id="itemVoidQty">-</td>
                        </tr>
                        <tr>
                            <td style="padding: 4px 0; color: #666;">Price:</td>
                            <td style="padding: 4px 0; font-weight: 600; text-align: right;" id="itemVoidPrice">-</td>
                        </tr>
                        <tr style="border-top: 1px solid #dee2e6;">
                            <td style="padding: 8px 0 4px 0; color: #333; font-weight: 600;">Subtotal:</td>
                            <td style="padding: 8px 0 4px 0; font-weight: 700; text-align: right; color: #d32f2f;" id="itemVoidSubtotal">-</td>
                        </tr>
                    </table>
                </div>

                <form id="itemVoidForm" onsubmit="handleItemVoid(event)">
                    <div class="form-group">
                        <label style="font-weight: 600; display: block; margin-bottom: 8px; color: #333;">
                            Admin Password <span style="color: #d32f2f;">*</span>
                        </label>
                        <input type="password" class="form-control" id="itemVoidAdminPassword" name="admin_password"
                               placeholder="Enter admin password" required>
                    </div>

                    <div class="form-group">
                        <label style="font-weight: 600; display: block; margin-bottom: 8px; color: #333;">
                            Void Reason <span style="color: #d32f2f;">*</span>
                        </label>
                        <textarea class="form-control" id="itemVoidReason" name="void_reason"
                                  placeholder="Enter reason for voiding this item..." 
                                  rows="3" required 
                                  style="resize: vertical; font-family: inherit;"></textarea>
                        <div style="font-size: 11px; color: #999; margin-top: 4px;">
                            <span id="itemVoidCharCount">0</span>/500 characters
                        </div>
                    </div>

                    <div style="display: flex; gap: 8px; margin-top: 20px;">
                        <button type="button" class="btn btn-secondary" onclick="closeItemVoidModal()" style="flex: 1;">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-danger" style="flex: 1;">
                            Void Item
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Security: CSRF Token for AJAX requests -->
<script>
    window.POS_CONFIG = {
        csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
        userRole: '<?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?>',
        userId: '<?php echo $user['user_id']; ?>'
    };
</script>
<script src="js/pos.js?v=<?php $v = @filemtime(__DIR__ . '/js/pos.js'); echo urlencode((string)($v ?: time())); ?>"></script>

<!-- Button Event Listeners -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('POS Page loaded successfully');
    console.log('Cart functions available:', {
        clearCartConfirm: typeof window.clearCartConfirm,
        openSaleVoidModal: typeof window.openSaleVoidModal,
        openCheckout: typeof window.openCheckout
    });
    
    // Item Void Reason character counter
    const itemVoidReason = document.getElementById('itemVoidReason');
    if (itemVoidReason) {
        itemVoidReason.addEventListener('input', function() {
            const cnt = this.value.length;
            document.getElementById('itemVoidCharCount').textContent = cnt;
            if (cnt > 500) {
                this.value = this.value.substring(0, 500);
                document.getElementById('itemVoidCharCount').textContent = '500';
            }
        });
    }
});
</script>
</body>
</html>
