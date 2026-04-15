<?php
/**
 * Utility script to clear void lockouts and verify admin password
 * Run this once to reset the state, then delete this file
 * 
 * Access via: http://localhost/IT12/clear_void_lockout.php
 */

require_once 'includes/db.php';

echo "<h2>Void Authorization Debug & Reset Tool</h2>";

try {
    $pdo = getPDO();
    
    // 1. Clear all void lockouts
    echo "<h3>1. Clearing all void lockouts...</h3>";
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE attempt_type = 'void'");
    $stmt->execute();
    echo "<p style='color:green;'>✓ Cleared " . $stmt->rowCount() . " void lockout records.</p>";
    
    // 2. Check admin users
    echo "<h3>2. Checking admin users in database...</h3>";
    $stmt = $pdo->query("SELECT user_id, username, password, role FROM users WHERE role = 'admin'");
    $admins = $stmt->fetchAll();
    
    if (empty($admins)) {
        echo "<p style='color:red;'>✗ No admin users found in database!</p>";
    } else {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Username</th><th>Role</th><th>Password Hash (first 20 chars)</th><th>admin123 Valid?</th></tr>";
        
        foreach ($admins as $admin) {
            $isValid = password_verify('admin123', $admin['password']);
            $validColor = $isValid ? 'green' : 'red';
            $validText = $isValid ? '✓ YES' : '✗ NO';
            
            echo "<tr>";
            echo "<td>{$admin['user_id']}</td>";
            echo "<td>{$admin['username']}</td>";
            echo "<td>{$admin['role']}</td>";
            echo "<td>" . substr($admin['password'], 0, 20) . "...</td>";
            echo "<td style='color:$validColor;'>$validText</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 3. Check if status column exists
    echo "<h3>3. Checking users table structure...</h3>";
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $hasStatus = in_array('status', $columns);
    if ($hasStatus) {
        echo "<p style='color:green;'>✓ 'status' column exists in users table.</p>";
        
        // Check admin status values
        $stmt = $pdo->query("SELECT username, status FROM users WHERE role = 'admin'");
        $adminStatuses = $stmt->fetchAll();
        foreach ($adminStatuses as $admin) {
            $status = $admin['status'] ?? 'NULL';
            echo "<p>Admin '{$admin['username']}' status: <strong>$status</strong></p>";
        }
    } else {
        echo "<p style='color:orange;'>⚠ 'status' column does NOT exist in users table (this is OK, code handles it).</p>";
    }
    
    // 4. Test password verification
    echo "<h3>4. Testing password_verify() function...</h3>";
    $testHash = password_hash('admin123', PASSWORD_DEFAULT);
    $testVerify = password_verify('admin123', $testHash);
    echo "<p>Test hash created: " . substr($testHash, 0, 20) . "...</p>";
    echo "<p>Verification: " . ($testVerify ? "<span style='color:green;'>✓ WORKING</span>" : "<span style='color:red;'>✗ FAILED</span>") . "</p>";
    
    // 5. Recreate admin password if needed
    echo "<h3>5. Option to reset admin password...</h3>";
    if (isset($_GET['reset_password'])) {
        $newHash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
        $stmt->execute([$newHash]);
        echo "<p style='color:green;'>✓ Admin password reset to 'admin123'</p>";
        echo "<p>New hash: $newHash</p>";
    } else {
        echo "<p><a href='?reset_password=1' style='color:blue;'>Click here to reset admin password to 'admin123'</a></p>";
    }
    
    echo "<hr>";
    echo "<p><strong>Done!</strong> You can now try the void authorization again with password 'admin123'.</p>";
    echo "<p style='color:red;'><strong>IMPORTANT:</strong> Delete this file after use for security!</p>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>ERROR: " . $e->getMessage() . "</p>";
}
?>
