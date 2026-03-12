<?php
/**
 * Authentication Functions
 * Handles login, logout, session management with security features
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Check if user is admin
function isAdmin(): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Redirect if not logged in
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

// Redirect if not admin
function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        $_SESSION['flash_message'] = 'Admin access required';
        $_SESSION['flash_type'] = 'error';
        header('Location: index.php');
        exit();
    }
}

// Restrict access to categories (admin only)
function requireCategoriesAccess(): void {
    requireLogin();
    if (!isAdmin()) {
        error_log("Unauthorized access attempt to categories by user: " . ($_SESSION['username'] ?? 'unknown'));
        header('Location: index.php');
        exit();
    }
}

/**
 * Secure login with rate limiting and activity logging
 * Works with both mysqli ($conn) and PDO
 */
function loginUser($conn, string $username, string $password): bool {
    // Check rate limit if security.php is loaded
    if (function_exists('checkRateLimit')) {
        if (!checkRateLimit('login')) {
            return false;
        }
    }
    
    // Try PDO first if db.php is loaded
    if (function_exists('getPDO')) {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT user_id, username, password, full_name, role FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                // Set session
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['login_time'] = time();
                
                // Reset rate limit and log activity
                if (function_exists('resetRateLimit')) {
                    resetRateLimit('login', $ip ?? $_SERVER['REMOTE_ADDR']);
                }
                if (function_exists('logActivity')) {
                    logActivity('login', "User logged in: {$user['username']}", $user['user_id']);
                }
                
                return true;
            }
            
            // Record failed attempt
            if (function_exists('recordAttempt')) {
                recordAttempt('login', $ip ?? $_SERVER['REMOTE_ADDR']);
            }
            return false;
        } catch (Exception $e) {
            error_log("PDO login error: " . $e->getMessage());
        }
    }
    
    // Fallback to mysqli if PDO failed or not available
    if ($conn instanceof mysqli) {
        $stmt = $conn->prepare("SELECT user_id, username, password, full_name, role FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['login_time'] = time();
                return true;
            }
        }
        return false;
    }
    
    return false;
}

/**
 * Logout user with activity logging
 */
function logoutUser(): void {
    $userId = $_SESSION['user_id'] ?? null;
    $username = $_SESSION['username'] ?? 'unknown';
    
    // Log activity before destroying session
    if (function_exists('logActivity') && $userId) {
        logActivity('logout', "User logged out: $username", $userId);
    }
    
    // Clear all session data
    $_SESSION = [];
    
    // Destroy session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
    header('Location: login.php');
    exit();
}

/**
 * Get current user info
 */
function getCurrentUser(): array {
    return [
        'user_id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'full_name' => $_SESSION['full_name'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'login_time' => $_SESSION['login_time'] ?? null
    ];
}

/**
 * Check if session is valid (not expired)
 * Session expires after 8 hours of inactivity
 */
function isSessionValid(): bool {
    $maxInactivity = 8 * 60 * 60; // 8 hours
    
    if (!isset($_SESSION['login_time'])) {
        return isLoggedIn();
    }
    
    if (time() - $_SESSION['login_time'] > $maxInactivity) {
        logoutUser();
        return false;
    }
    
    // Update login time on activity
    $_SESSION['login_time'] = time();
    return true;
}

/**
 * Regenerate session ID for security
 */
function regenerateSession(): void {
    session_regenerate_id(true);
}
?>
