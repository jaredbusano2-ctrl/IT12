<?php
require_once 'includes/config.php';

echo "<h2>Checking sale_voids table...</h2>";

// Check if table exists
$result = $conn->query("SHOW TABLES LIKE 'sale_voids'");
if ($result->num_rows == 0) {
    echo "<p style='color:red'>Table 'sale_voids' does not exist!</p>";
    
    // Create the table
    $sql = "CREATE TABLE sale_voids (
        void_id INT AUTO_INCREMENT PRIMARY KEY,
        voided_by INT NOT NULL,
        void_reason TEXT NOT NULL,
        cart_items TEXT,
        total_amount DECIMAL(10,2) DEFAULT 0,
        voided_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_voided_at (voided_at),
        INDEX idx_voided_by (voided_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if ($conn->query($sql)) {
        echo "<p style='color:green'>Table 'sale_voids' created successfully!</p>";
    } else {
        echo "<p style='color:red'>Error creating table: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color:green'>Table 'sale_voids' exists!</p>";
    
    // Show table structure
    $result = $conn->query("DESCRIBE sale_voids");
    echo "<h3>Table Structure:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check existing records
    $count = $conn->query("SELECT COUNT(*) as total FROM sale_voids")->fetch_assoc()['total'];
    echo "<p>Total voids in database: $count</p>";
}

echo "<br><a href='pos.php'>Go to POS</a> | <a href='voids.php'>View Voids</a>";
?>
