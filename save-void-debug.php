<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

requireLogin();
$user = getCurrentUser();

echo "<h2>Debug: Save Void</h2>";
echo "<pre>";

echo "Request Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "User ID: " . $user['user_id'] . "\n";
echo "POST Data:\n";
print_r($_POST);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "ERROR: Not a POST request\n";
    echo "</pre>";
    exit();
}

// Get form data
$void_type = $conn->real_escape_string($_POST['void_type'] ?? 'individual_item');
$item_name = $conn->real_escape_string($_POST['item_name'] ?? '');
$item_price = (float)($_POST['item_price'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? 0);
$subtotal = (float)($_POST['subtotal'] ?? 0);
$reason = $conn->real_escape_string($_POST['reason'] ?? '');
$cart_items = $conn->real_escape_string($_POST['cart_items'] ?? '[]');

echo "\nProcessed Data:\n";
echo "void_type: $void_type\n";
echo "item_name: $item_name\n";
echo "reason: $reason\n";
echo "subtotal: $subtotal\n";

try {
    // Check table exists
    $result = $conn->query("SHOW TABLES LIKE 'sale_voids'");
    if ($result->num_rows == 0) {
        echo "\nERROR: sale_voids table does not exist!\n";
        echo "</pre>";
        exit();
    }
    echo "\nTable exists: sale_voids\n";
    
    // Start transaction
    $conn->begin_transaction();
    echo "Transaction started\n";
    
    // Insert into sale_voids table
    $insertVoid = "
        INSERT INTO sale_voids 
        (voided_by, void_reason, cart_items, total_amount, voided_at)
        VALUES 
        ({$user['user_id']}, '$reason', '$cart_items', $subtotal, NOW())
    ";
    
    echo "\nSQL Query:\n$insertVoid\n";
    
    if (!$conn->query($insertVoid)) {
        throw new Exception("Error inserting void: " . $conn->error);
    }
    
    $void_id = $conn->insert_id;
    echo "\nSUCCESS! Void ID: $void_id\n";
    
    // Commit transaction
    $conn->commit();
    echo "Transaction committed\n";
    
    // Show all voids
    echo "\nAll Voids in Database:\n";
    $result = $conn->query("SELECT * FROM sale_voids ORDER BY voided_at DESC");
    while ($row = $result->fetch_assoc()) {
        print_r($row);
        echo "\n";
    }
    
} catch (Exception $e) {
    $conn->rollback();
    echo "\nERROR: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<br><a href='pos.php'>Back to POS</a> | <a href='voids.php'>View Voids</a>";
?>
