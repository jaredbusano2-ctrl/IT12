-- ================================================================
-- POPRIE POS - Limited Database User Setup
-- ================================================================
-- This script creates a restricted MySQL user for the POS application
-- instead of using the default 'root' user with full privileges
-- 
-- SECURITY: This follows the principle of least privilege
-- The application only needs SELECT, INSERT, UPDATE permissions
-- It should NEVER have DROP, TRUNCATE, or GRANT privileges
-- ================================================================

-- Step 1: Create the limited user
-- Change 'your_secure_password' to a strong password!
CREATE USER IF NOT EXISTS 'poprie_pos'@'localhost' IDENTIFIED BY 'your_secure_password_here';

-- Step 2: Grant only necessary permissions
-- NO DROP, TRUNCATE, DELETE*, GRANT, ALTER, CREATE, etc.
GRANT SELECT ON coffee_shop_pos.* TO 'poprie_pos'@'localhost';
GRANT INSERT ON coffee_shop_pos.* TO 'poprie_pos'@'localhost';
GRANT UPDATE ON coffee_shop_pos.* TO 'poprie_pos'@'localhost';

-- Allow DELETE only on specific tables (session cleanup, rate limiting)
-- Add more tables here as needed
GRANT DELETE ON coffee_shop_pos.sessions TO 'poprie_pos'@'localhost';
GRANT DELETE ON coffee_shop_pos.rate_limit_attempts TO 'poprie_pos'@'localhost';
GRANT DELETE ON coffee_shop_pos.csrf_tokens TO 'poprie_pos'@'localhost';

-- Step 3: Apply the changes
FLUSH PRIVILEGES;

-- ================================================================
-- Verification - Run this to confirm permissions are set correctly
-- ================================================================
SHOW GRANTS FOR 'poprie_pos'@'localhost';

-- ================================================================
-- IMPORTANT: Update your .env file with these credentials
-- ================================================================
-- DB_USER=poprie_pos
-- DB_PASS=your_secure_password_here
-- ================================================================

-- ================================================================
-- To revoke all privileges and delete user (if needed):
-- ================================================================
-- REVOKE ALL PRIVILEGES ON coffee_shop_pos.* FROM 'poprie_pos'@'localhost';
-- DROP USER 'poprie_pos'@'localhost';
-- FLUSH PRIVILEGES;
-- ================================================================

-- ================================================================
-- Database Security Best Practices:
-- ================================================================
-- 1. Never use 'root' user in production applications
-- 2. Use strong, unique passwords (16+ characters)
-- 3. Limit privileges to only what the application needs
-- 4. Regular backup of database (see backup_database.php)
-- 5. Enable MySQL logging for audit trail
-- 6. Use SSL/TLS for database connections in production
-- ================================================================
