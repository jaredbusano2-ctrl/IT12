<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';

// Set security headers
setSecurityHeaders();

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: index.php');
    exit();
}

$error = '';
$lockoutMessage = '';
$attemptsRemaining = SECURITY_CONFIG['max_login_attempts'];

// Check for session timeout message
if (isset($_GET['timeout'])) {
    $error = 'Your session has expired. Please log in again.';
}

// Check IP-based lockout
$clientIP = getClientIP();
if (isLockedOut($clientIP, 'login')) {
    $remainingSeconds = getLockoutRemaining($clientIP, 'login');
    $remainingMinutes = ceil($remainingSeconds / 60);
    $lockoutMessage = "Too many failed attempts. Please try again in {$remainingMinutes} minute(s).";
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($lockoutMessage)) {
    // Verify CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        $error = 'Security token expired. Please try again.';
        logActivity('csrf_failure', 'Login form CSRF validation failed');
    } else {
        $username = sanitize($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Check username-specific lockout
        if (isLockedOut($username, 'login')) {
            $remainingSeconds = getLockoutRemaining($username, 'login');
            $remainingMinutes = ceil($remainingSeconds / 60);
            $error = "Account temporarily locked. Please try again in {$remainingMinutes} minute(s).";
        } else {
            if (loginUser($conn, $username, $password)) {
                // Successful login - reset attempts
                resetAttempts($username, 'login');
                resetAttempts($clientIP, 'login');
                
                // Regenerate session ID for security
                session_regenerate_id(true);
                $_SESSION['session_created'] = time();
                
                logActivity('login_success', "User '{$username}' logged in successfully");
                
                header('Location: index.php');
                exit();
            } else {
                // Failed login - record attempt
                $usernameResult = recordFailedAttempt($username, 'login');
                $ipResult = recordFailedAttempt($clientIP, 'login');
                
                logActivity('login_failed', "Failed login attempt for user '{$username}'");
                
                if ($usernameResult['locked'] || $ipResult['locked']) {
                    $error = 'Account temporarily locked due to too many failed attempts.';
                } else {
                    $remaining = min($usernameResult['remaining'], $ipResult['remaining']);
                    $attemptsRemaining = $remaining;
                    $error = "Invalid username or password. {$remaining} attempt(s) remaining.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - POPRIE</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #f4f4f4;
}

/* SPLIT LAYOUT */
.login-split-container {
    display: flex;
    height: 100vh;
}

/* LEFT SIDE - BRANDING */
.login-left {
    width: 50%;
    background: #E8E4C9;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px;
}

.brand-card {
    text-align: center;
    max-width: 500px;
}

.brand-logo {
    width: 120px;
    height: 120px;
    border-radius: 20px;
    object-fit: cover;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
}

.brand-title {
    font-size: 64px;
    font-weight: 900;
    color: #d32f2f;
    margin-bottom: 15px;
}

.brand-underline {
    width: 260px;
    height: 6px;
    background: #000;
    margin: 20px auto 30px;
    border-radius: 3px;
}

.brand-tagline {
    font-size: 22px;
    font-weight: 600;
    color: #333;
    line-height: 1.4;
}

.brand-illustration {
    font-size: 110px;
    margin-top: 50px;
}

/* RIGHT SIDE - LOGIN */
.login-right {
    width: 50%;
    background: linear-gradient(135deg, #d32f2f 0%, #c62828 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px;
}

/* LOGIN CARD (Original Clean Style) */
.login-box {
    width: 100%;
    max-width: 400px;
    background: #ffffff;
    padding: 40px;
    border-radius: 15px;
    box-shadow: 0 15px 40px rgba(0,0,0,0.15);
}

.login-header {
    text-align: center;
    margin-bottom: 30px;
}

.login-logo-img {
    width: 80px;
    height: 80px;
    border-radius: 12px;
    object-fit: cover;
    margin-bottom: 20px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.login-header h1 {
    font-size: 26px;
    font-weight: 700;
    color: #333;
    margin-bottom: 6px;
}

.login-header p {
    font-size: 14px;
    color: #777;
}

.form-group {
    margin-bottom: 18px;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
    color: #333;
}

.form-control {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
    transition: 0.3s;
}

.form-control:focus {
    outline: none;
    border-color: #d32f2f;
    box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.1);
}

button {
    width: 100%;
    padding: 13px;
    background: linear-gradient(135deg, #d32f2f 0%, #c62828 100%);
    border: none;
    border-radius: 8px;
    color: white;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    margin-top: 10px;
    transition: 0.3s;
}

button:hover {
    opacity: 0.9;
}

.alert {
    padding: 12px;
    margin-bottom: 15px;
    border-radius: 6px;
    font-size: 13px;
}

.alert-danger {
    background: #ffebee;
    color: #c62828;
}

/* RESPONSIVE */
@media (max-width: 768px) {
    .login-split-container {
        flex-direction: column;
        height: auto;
    }

    .login-left,
    .login-right {
        width: 100%;
        padding: 60px 20px;
    }

    .brand-title {
        font-size: 42px;
    }

    .brand-illustration {
        font-size: 80px;
    }
}
</style>
</head>

<body>

<div class="login-split-container">

    <!-- LEFT SIDE -->
    <div class="login-left">
        <div class="brand-card">
            <img src="pictures/poprie.jpg" alt="POPRIE Logo" class="brand-logo">
            <div class="brand-title">POPRIE</div>
            <div class="brand-underline"></div>
            <div class="brand-tagline">
                Powered by Coffee,<br>
                Driven by Dreams
            </div>
            <div class="brand-illustration">☕❤️</div>
        </div>
    </div>

    <!-- RIGHT SIDE -->
    <div class="login-right">
        <div class="login-box">

            <div class="login-header">
                <h1>Login</h1>
                <p>Sign in to your account</p>
            </div>

            <?php if ($lockoutMessage): ?>
                <div class="alert alert-danger">
                    🔒 <?php echo htmlspecialchars($lockoutMessage); ?>
                </div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" <?php echo $lockoutMessage ? 'style="opacity: 0.5; pointer-events: none;"' : ''; ?>>
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" class="form-control" required autofocus <?php echo $lockoutMessage ? 'disabled' : ''; ?>>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" class="form-control" required <?php echo $lockoutMessage ? 'disabled' : ''; ?>>
                </div>

                <button type="submit" <?php echo $lockoutMessage ? 'disabled' : ''; ?>>LOGIN</button>
            </form>

        </div>
    </div>

</div>

</body>
</html>