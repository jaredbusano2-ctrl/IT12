<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

requireLogin();
$user = getCurrentUser();

// Return JSON response for fetch API
header('Content-Type: application/json');

// Debug: Log everything
error_log("=== SAVE-VOID DEBUG ===");
error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
error_log("POST Data: " . print_r($_POST, true));
error_log("User: " . print_r($user, true));

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("ERROR: Not a POST request");
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get form data
$void_type = $conn->real_escape_string($_POST['void_type'] ?? 'individual_item');
$item_price = (float)($_POST['item_price'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? 0);
$subtotal = (float)($_POST['subtotal'] ?? 0);
$reason = $conn->real_escape_string($_POST['reason'] ?? '');
$cart_items = $conn->real_escape_string($_POST['cart_items'] ?? '[]');

error_log("Processed Data:");
error_log("void_type: $void_type");
error_log("reason: $reason");
error_log("subtotal: $subtotal");
error_log("cart_items: $cart_items");

try {
    // Start transaction
    $conn->begin_transaction();
    
    // Insert into sale_voids table - use only existing columns
    $insertVoid = "
        INSERT INTO sale_voids 
        (voided_by, void_reason, cart_items, voided_at)
        VALUES 
        ({$user['user_id']}, '$reason', '$cart_items', NOW())
    ";
    
    error_log("SQL Query: $insertVoid");
    
    if (!$conn->query($insertVoid)) {
        throw new Exception("Error inserting void: " . $conn->error);
    }
    
    $void_id = $conn->insert_id;
    error_log("Insert successful! Void ID: $void_id");
    
    // Commit transaction
    $conn->commit();
    error_log("Transaction committed");
    
    // Return success JSON response
    echo json_encode([
        'success' => true, 
        'void_id' => $void_id,
        'message' => 'Void saved successfully'
    ]);
    exit();
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    error_log("ERROR: " . $e->getMessage());
    
    // Return error JSON response
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
    exit();
}
?>
