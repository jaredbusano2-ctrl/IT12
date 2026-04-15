<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

requireLogin();
$user = getCurrentUser();

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pos.php');
    exit();
}

// Get form data
$invoice_number = $conn->real_escape_string($_POST['invoice_number'] ?? '');
$customer_name = $conn->real_escape_string($_POST['customer_name'] ?? '');
$payment_method = $conn->real_escape_string($_POST['payment_method'] ?? 'cash');
$subtotal = (float)($_POST['subtotal'] ?? 0);
$tax = (float)($_POST['tax'] ?? 0);
$discount = (float)($_POST['discount'] ?? 0);
$total = (float)($_POST['total'] ?? 0);
$paid = (float)($_POST['paid'] ?? 0);
$change_amount = (float)($_POST['change_amount'] ?? 0);
$items_json = $_POST['items'] ?? '[]';

// Decode items
$items = json_decode($items_json, true);
if (!is_array($items)) {
    $items = [];
}

try {
    // Start transaction
    $conn->begin_transaction();
    
    // Insert into sales table
    $insertSale = "
        INSERT INTO sales 
        (invoice_number, customer_name, user_id, subtotal, tax, discount, total_amount, amount_paid, change_amount, payment_method, sale_date)
        VALUES 
        ('$invoice_number', '$customer_name', {$user['user_id']}, $subtotal, $tax, $discount, $total, $paid, $change_amount, '$payment_method', NOW())
    ";
    
    if (!$conn->query($insertSale)) {
        throw new Exception("Error inserting sale: " . $conn->error);
    }
    
    $sale_id = $conn->insert_id;
    
    // Insert sale items and update stock
    foreach ($items as $item) {
        $product_id = (int)($item['product_id'] ?? 0);
        $quantity = (int)($item['quantity'] ?? 0);
        $unit_price = (float)($item['unit_price'] ?? 0);
        $item_subtotal = (float)($item['subtotal'] ?? 0);
        $product_name = $conn->real_escape_string($item['product_name'] ?? '');
        
        if ($product_id > 0 && $quantity > 0) {
            // Insert into sale_items
            $insertItem = "
                INSERT INTO sale_items 
                (sale_id, product_id, quantity, unit_price, subtotal)
                VALUES
                ($sale_id, $product_id, $quantity, $unit_price, $item_subtotal)
            ";
            
            if (!$conn->query($insertItem)) {
                throw new Exception("Error inserting sale item: " . $conn->error);
            }
            
            // Update product stock
            $updateStock = "
                UPDATE products 
                SET stock_quantity = stock_quantity - $quantity
                WHERE product_id = $product_id
            ";
            
            if (!$conn->query($updateStock)) {
                throw new Exception("Error updating stock: " . $conn->error);
            }
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    // Redirect to receipt
    header("Location: receipt-local.php");
    exit();
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    
    // Store error in session and redirect back to POS
    $_SESSION['sale_error'] = $e->getMessage();
    header('Location: pos.php');
    exit();
}
?>
