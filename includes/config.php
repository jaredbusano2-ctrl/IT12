<?php
/**
 * Database Configuration
 * Uses environment variables from .env file for secure credential management
 * 
 * SECURITY: Database credentials are loaded from .env file
 * Never commit .env to version control!
 */

require_once __DIR__ . '/env.php';

// Database Configuration from environment
define('DB_HOST', Env::get('DB_HOST', 'localhost'));
define('DB_USER', Env::get('DB_USER', 'root'));
define('DB_PASS', Env::get('DB_PASS', ''));
define('DB_NAME', Env::get('DB_NAME', 'coffee_shop_pos'));
define('DB_CHARSET', Env::get('DB_CHARSET', 'utf8mb4'));

// Application Settings
define('APP_NAME', Env::get('APP_NAME', 'POPRIE POS'));
define('APP_ENV', Env::get('APP_ENV', 'development'));
define('APP_DEBUG', Env::get('APP_DEBUG', 'true') === 'true');

// Security Settings from environment
define('HIGH_VALUE_THRESHOLD', (float) Env::get('HIGH_VALUE_THRESHOLD', 2000));
define('ALERT_EMAIL', Env::get('ALERT_EMAIL', ''));

// Force HTTPS in production
if (Env::get('FORCE_HTTPS', 'false') === 'true' && 
    (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on')) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit();
}

// Create database connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    if (APP_DEBUG) {
        die("Database connection failed: " . $conn->connect_error);
    } else {
        die("Database connection failed. Please try again later.");
    }
}

// Set charset to utf8mb4
$conn->set_charset(DB_CHARSET);
?>
