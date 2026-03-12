<?php
require_once 'includes/config.php';

echo "<h2>Check sale_voids Table Structure</h2>";

// Show current structure
$result = $conn->query("DESCRIBE sale_voids");
echo "<h3>Current Table Structure:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['Field'] . "</td>";
    echo "<td>" . $row['Type'] . "</td>";
    echo "<td>" . $row['Null'] . "</td>";
    echo "<td>" . $row['Key'] . "</td>";
    echo "<td>" . $row['Default'] . "</td>";
    echo "</tr>";
}
echo "</table>";

// Check what columns we need vs what we have
$requiredColumns = ['void_id', 'voided_by', 'void_reason', 'cart_items', 'total_amount', 'voided_at'];
echo "<h3>Column Check:</h3>";
$result = $conn->query("SHOW COLUMNS FROM sale_voids");
$existingColumns = [];
while ($row = $result->fetch_assoc()) {
    $existingColumns[] = $row['Field'];
}

foreach ($requiredColumns as $col) {
    if (in_array($col, $existingColumns)) {
        echo "<p style='color:green'>✅ $col - EXISTS</p>";
    } else {
        echo "<p style='color:red'>❌ $col - MISSING</p>";
    }
}

echo "<br><a href='pos.php'>Back to POS</a> | <a href='voids.php'>View Voids</a>";
?>
