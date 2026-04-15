<?php
/**
 * Environment Variable Loader
 * Loads configuration from .env file for secure credential management
 * 
 * SECURITY: Keep .env file out of version control!
 */

class Env {
    private static array $variables = [];
    private static bool $loaded = false;
    
    /**
     * Load environment variables from .env file
     */
    public static function load(string $path = null): void {
        if (self::$loaded) {
            return;
        }
        
        $envFile = $path ?? __DIR__ . '/../.env';
        
        if (!file_exists($envFile)) {
            // Fall back to defaults if .env doesn't exist
            self::setDefaults();
            self::$loaded = true;
            return;
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                $value = trim($value, '"\'');
                
                self::$variables[$key] = $value;
                
                // Also set in $_ENV for compatibility
                $_ENV[$key] = $value;
            }
        }
        
        self::$loaded = true;
    }
    
    /**
     * Get environment variable value
     */
    public static function get(string $key, $default = null) {
        if (!self::$loaded) {
            self::load();
        }
        
        return self::$variables[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }
    
    /**
     * Check if environment variable exists
     */
    public static function has(string $key): bool {
        if (!self::$loaded) {
            self::load();
        }
        
        return isset(self::$variables[$key]) || isset($_ENV[$key]);
    }
    
    /**
     * Set default values (used when .env file doesn't exist)
     */
    private static function setDefaults(): void {
        self::$variables = [
            'DB_HOST' => 'localhost',
            'DB_USER' => 'root',
            'DB_PASS' => '',
            'DB_NAME' => 'coffee_shop_pos',
            'DB_CHARSET' => 'utf8mb4',
            'SESSION_TIMEOUT' => '600',
            'MAX_LOGIN_ATTEMPTS' => '5',
            'LOCKOUT_DURATION' => '900',
            'CSRF_TOKEN_EXPIRY' => '3600',
            'HIGH_VALUE_THRESHOLD' => '2000',
            'ALERT_EMAIL' => '',
            'APP_NAME' => 'POPRIE POS',
            'APP_ENV' => 'development',
            'APP_DEBUG' => 'true',
            'FORCE_HTTPS' => 'false'
        ];
    }
    
    /**
     * Get all loaded variables (for debugging)
     */
    public static function all(): array {
        if (!self::$loaded) {
            self::load();
        }
        
        return self::$variables;
    }
}

// Auto-load on include
Env::load();
