<?php
require_once 'includes/config.php';

echo "<h2>Direct Database Check</h2>";

// Check if table exists and show structure
$result = $conn->query("SHOW TABLES LIKE 'sale_voids'");
if ($result->num_rows == 0) {
    echo "<p style='color:red'>Table 'sale_voids' does not exist!</p>";
} else {
    echo "<p style='color:green'>Table 'sale_voids' exists!</p>";
    
    // Show table structure
    echo "<h3>Table Structure:</h3>";
    $result = $conn->query("DESCRIBE sale_voids");
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
    
    // Show ALL records
    echo "<h3>All Records in sale_voids:</h3>";
    $result = $conn->query("SELECT * FROM sale_voids ORDER BY voided_at DESC");
    if ($result && $result->num_rows > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>void_id</th><th>voided_by</th><th>void_reason</th><th>total_amount</th><th>voided_at</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['void_id'] . "</td>";
            echo "<td>" . $row['voided_by'] . "</td>";
            echo "<td>" . htmlspecialchars($row['void_reason']) . "</td>";
            echo "<td>" . $row['total_amount'] . "</td>";
            echo "<td>" . $row['voided_at'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:orange'>No records found in sale_voids table</p>";
    }
}

echo "<br><a href='pos.php'>Back to POS</a> | <a href='voids.php'>View Voids</a>";
?>
