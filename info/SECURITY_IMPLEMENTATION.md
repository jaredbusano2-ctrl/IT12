# Security Implementation in PHP-Based POS & Inventory System

## Overview

This document outlines the security measures implemented in a PHP-based Point of Sale and Inventory Management System to protect against common vulnerabilities and threats.

---

## 1. Authentication Security

### 1.1 Password Hashing

All passwords are stored using PHP's `password_hash()` with bcrypt algorithm:

```php
// Registration - Hash password before storing
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Login - Verify password against hash
if (password_verify($inputPassword, $storedHash)) {
    // Login successful
}
```

**Why:** Protects passwords even if database is compromised.

### 1.2 Session Management

```php
// Secure session initialization
session_start([
    'cookie_httponly' => true,    // Prevent JavaScript access
    'cookie_secure' => true,      // HTTPS only
    'cookie_samesite' => 'Strict' // Prevent CSRF via cookies
]);

// Session timeout (10 minutes of inactivity)
if (isset($_SESSION['last_activity']) && 
    (time() - $_SESSION['last_activity'] > 600)) {
    session_destroy();
    header('Location: login.php');
    exit;
}
$_SESSION['last_activity'] = time();
```

### 1.3 Login Rate Limiting

Prevents brute-force attacks:

```php
// Check if user is locked out
$maxAttempts = 5;
$lockoutDuration = 900; // 15 minutes

if ($failedAttempts >= $maxAttempts) {
    $remainingTime = $lockoutDuration - (time() - $lastAttemptTime);
    if ($remainingTime > 0) {
        die("Account locked. Try again in " . ceil($remainingTime/60) . " minutes.");
    }
}
```

---

## 2. SQL Injection Prevention

### 2.1 Prepared Statements (PDO)

```php
// ❌ VULNERABLE - Never do this
$sql = "SELECT * FROM users WHERE username = '$username'";

// ✅ SECURE - Use prepared statements
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();
```

### 2.2 Prepared Statements (MySQLi)

```php
$stmt = $conn->prepare("SELECT * FROM products WHERE product_id = ?");
$stmt->bind_param("i", $productId);
$stmt->execute();
$result = $stmt->get_result();
```

---

## 3. Cross-Site Scripting (XSS) Prevention

### 3.1 Output Encoding

Always escape output before displaying:

```php
// When displaying user input in HTML
echo htmlspecialchars($userInput, ENT_QUOTES, 'UTF-8');

// Helper function
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Usage in HTML templates
<p>Customer: <?= h($customerName) ?></p>
```

---

## 4. Cross-Site Request Forgery (CSRF) Protection

### 4.1 Token Generation

```php
// Generate token on form page
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
```

### 4.2 Include Token in Forms

```html
<form method="POST" action="process-sale.php">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <!-- other form fields -->
</form>
```

### 4.3 Validate Token on Submission

```php
if (!isset($_POST['csrf_token']) || 
    $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die('Invalid request');
}
```

---

## 5. Role-Based Access Control (RBAC)

### 5.1 Define Permissions

```php
$rolePermissions = [
    'admin' => [
        'manage_users',
        'view_reports',
        'authorize_voids',
        'manage_inventory',
        'view_activity_logs'
    ],
    'cashier' => [
        'process_sales',
        'view_products',
        'request_void'  // requires admin approval
    ]
];
```

### 5.2 Check Permissions

```php
function hasPermission($permission) {
    global $rolePermissions;
    $role = $_SESSION['user_role'] ?? '';
    return in_array($permission, $rolePermissions[$role] ?? []);
}

// Usage
if (!hasPermission('view_reports')) {
    header('Location: unauthorized.php');
    exit;
}
```

---

## 6. Input Validation & Sanitization

### 6.1 Sanitization Functions

```php
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function sanitizeInt($input) {
    return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
}

function sanitizeFloat($input) {
    return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

function sanitizeEmail($input) {
    return filter_var(trim($input), FILTER_SANITIZE_EMAIL);
}
```

### 6.2 Validation Example

```php
// Validate sale amount
$amount = $_POST['amount'] ?? 0;
if (!is_numeric($amount) || $amount <= 0) {
    die('Invalid amount');
}
```

---

## 7. Secure Configuration

### 7.1 Environment Variables (.env)

Store credentials outside code:

```env
DB_HOST=localhost
DB_USER=pos_user
DB_PASS=secure_password_here
DB_NAME=coffee_shop_pos
```

```php
// Load environment variables
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USER') ?: 'root';
```

### 7.2 Error Handling

```php
// Production: Hide errors from users
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Log errors to file instead
ini_set('error_log', '/path/to/error.log');
```

---

## 8. Activity Logging

### 8.1 Log Important Actions

```php
function logActivity($action, $description, $referenceType = null, $referenceId = null) {
    global $conn;
    
    $stmt = $conn->prepare("
        INSERT INTO activity_logs 
        (user_id, action, description, reference_type, reference_id, ip_address, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $userId = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'];
    
    $stmt->bind_param("isssss", $userId, $action, $description, $referenceType, $referenceId, $ip);
    $stmt->execute();
}

// Usage
logActivity('sale_completed', 'Sale #INV-001 completed for ₱500.00', 'sale', $saleId);
logActivity('void_authorized', 'Voided item: Cappuccino x2', 'sale_item', $itemId);
logActivity('login_failed', 'Failed login attempt for user: admin');
```

---

## 9. Void Authorization

### 9.1 Admin Password Verification

```php
function verifyAdminForVoid($password) {
    global $conn;
    
    // Get all admin users
    $stmt = $conn->prepare("SELECT password FROM users WHERE role = 'admin'");
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($admin = $result->fetch_assoc()) {
        if (password_verify($password, $admin['password'])) {
            return true;
        }
    }
    return false;
}
```

### 9.2 Void Rate Limiting

```php
// Prevent void abuse
$maxVoidAttempts = 3;
$voidLockoutDuration = 30; // seconds

if (!isset($_SESSION['void_attempts'])) {
    $_SESSION['void_attempts'] = 0;
    $_SESSION['void_lockout_until'] = 0;
}

if (time() < $_SESSION['void_lockout_until']) {
    die('Too many void attempts. Please wait.');
}
```

---

## 10. Security Headers

Add to your main include file:

```php
header('X-Frame-Options: DENY');                    // Prevent clickjacking
header('X-Content-Type-Options: nosniff');          // Prevent MIME sniffing
header('X-XSS-Protection: 1; mode=block');          // XSS filter
header('Referrer-Policy: strict-origin-when-cross-origin');
```

---

## Quick Reference Checklist

| Security Measure | Status |
|------------------|--------|
| Password hashing with bcrypt | ✅ |
| Prepared statements for SQL | ✅ |
| XSS prevention (htmlspecialchars) | ✅ |
| CSRF token protection | ✅ |
| Session timeout | ✅ |
| Login rate limiting | ✅ |
| Role-based access control | ✅ |
| Activity logging | ✅ |
| Input validation | ✅ |
| Secure headers | ✅ |

---

## Summary

These security implementations protect the POS system against:

- **Brute Force Attacks** → Rate limiting, account lockout
- **SQL Injection** → Prepared statements
- **XSS Attacks** → Output encoding
- **CSRF Attacks** → Token verification
- **Session Hijacking** → Secure cookies, session timeout
- **Unauthorized Access** → RBAC, authentication checks
- **Fraud/Theft** → Activity logging, void authorization

---

*Document created: March 2026*
