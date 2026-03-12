<?php
/**
 * Database Connection Handler
 * Provides both PDO (primary) and MySQLi (backward compatibility) connections
 * Uses the new unified database: coffee_shop_pos
 */

// Prevent direct access
if (!defined('DB_HOST')) {
    // Database Configuration Constants
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'coffee_shop_pos'); // New unified database
    define('DB_CHARSET', 'utf8mb4');
}

// Application Configuration
if (!defined('APP_NAME')) {
    define('APP_NAME', 'POPRIE Coffee Shop POS');
    define('APP_VERSION', '2.0.0');
    define('TAX_RATE', 12.00); // 12% tax
    define('ITEMS_PER_PAGE', 5); // Pagination limit
    define('LOW_STOCK_THRESHOLD', 20);
}

/**
 * PDO Connection (Primary - Recommended)
 * Uses prepared statements by default
 */
function getPDO(): PDO {
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
        ];
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please try again later.");
        }
    }
    
    return $pdo;
}

/**
 * MySQLi Connection (Backward Compatibility)
 * For legacy code that still uses mysqli
 */
function getMySQLi(): mysqli {
    static $conn = null;
    
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($conn->connect_error) {
            error_log("MySQLi Connection Error: " . $conn->connect_error);
            die("Database connection failed. Please try again later.");
        }
        
        $conn->set_charset(DB_CHARSET);
    }
    
    return $conn;
}

// Create global connection variables for backward compatibility
$pdo = getPDO();
$conn = getMySQLi();

/**
 * Helper function to execute a simple PDO query
 */
function dbQuery(string $sql, array $params = []): PDOStatement {
    $pdo = getPDO();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Helper to fetch all rows from a query
 */
function dbFetchAll(string $sql, array $params = []): array {
    return dbQuery($sql, $params)->fetchAll();
}

/**
 * Helper to fetch a single row from a query
 */
function dbFetchOne(string $sql, array $params = []): ?array {
    $result = dbQuery($sql, $params)->fetch();
    return $result ?: null;
}

/**
 * Helper to get the last insert ID
 */
function dbLastId(): string {
    return getPDO()->lastInsertId();
}

/**
 * Begin a database transaction
 */
function dbBeginTransaction(): bool {
    return getPDO()->beginTransaction();
}

/**
 * Commit a database transaction
 */
function dbCommit(): bool {
    return getPDO()->commit();
}

/**
 * Rollback a database transaction
 */
function dbRollback(): bool {
    return getPDO()->rollBack();
}
