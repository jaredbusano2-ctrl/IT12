-- =====================================================
-- COFFEE SHOP POS - Complete Database Schema
-- CONSOLIDATED: Core Tables + Security Enhancements
-- Import this file into phpMyAdmin
-- =====================================================

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS `coffee_shop_pos` 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `coffee_shop_pos`;

-- =====================================================
-- USERS TABLE (with security enhancements)
-- =====================================================
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role` ENUM('admin', 'cashier') NOT NULL DEFAULT 'cashier',
    `status` ENUM('active', 'inactive', 'locked') DEFAULT 'active',
    `last_login` TIMESTAMP NULL,
    `failed_attempts` INT DEFAULT 0,
    `locked_until` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_username` (`username`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB;

-- Default users (password: admin123 for admin, cashier123 for cashier)
INSERT INTO `users` (`username`, `password`, `full_name`, `role`) VALUES
('admin', '$2y$10$Q9WZePr0utVJDv2l489O3OogKjYc1dVynggLSyQoweJfEA5Y/KfOS', 'Administrator', 'admin'),
('cashier', '$2y$10$NeVXpOpcSVcyITt9Zm5TuuwOaxUMVjxjGk4QuRkHruD1LioOKFWD6', 'John Cashier', 'cashier');

-- =====================================================
-- CATEGORIES TABLE
-- =====================================================
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
    `category_id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO `categories` (`category_name`, `description`) VALUES
('Hot Coffee', 'Hot coffee beverages'),
('Iced Coffee', 'Cold coffee beverages'),
('Matcha', 'Matcha-based drinks'),
('Frappe', 'Blended frozen drinks'),
('Non-Coffee', 'Non-coffee beverages'),
('Snacks', 'Food items and snacks');

-- =====================================================
-- PRODUCTS TABLE
-- =====================================================
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
    `product_id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_code` VARCHAR(20) NOT NULL UNIQUE,
    `product_name` VARCHAR(100) NOT NULL,
    `category_id` INT,
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `cost` DECIMAL(10,2) DEFAULT 0.00,
    `stock_quantity` INT DEFAULT 0,
    `reorder_level` INT DEFAULT 10,
    `is_drink` TINYINT(1) DEFAULT 0,
    `requires_cup` TINYINT(1) DEFAULT 0,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`category_id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Sample products
INSERT INTO `products` (`product_code`, `product_name`, `category_id`, `price`, `stock_quantity`, `is_drink`, `requires_cup`) VALUES
('HC001', 'Americano', 1, 89.00, 100, 1, 1),
('HC002', 'Cappuccino', 1, 99.00, 100, 1, 1),
('HC003', 'Latte', 1, 109.00, 100, 1, 1),
('HC004', 'Mocha', 1, 119.00, 100, 1, 1),
('IC001', 'Iced Americano', 2, 99.00, 100, 1, 1),
('IC002', 'Iced Latte', 2, 119.00, 100, 1, 1),
('IC003', 'Iced Mocha', 2, 129.00, 100, 1, 1),
('MT001', 'Matcha Latte', 3, 129.00, 100, 1, 1),
('MT002', 'Iced Matcha', 3, 139.00, 100, 1, 1),
('FR001', 'Caramel Frappe', 4, 149.00, 100, 1, 1),
('FR002', 'Mocha Frappe', 4, 149.00, 100, 1, 1),
('NC001', 'Hot Chocolate', 5, 89.00, 100, 1, 1),
('SN001', 'Croissant', 6, 65.00, 50, 0, 0),
('SN002', 'Muffin', 6, 55.00, 50, 0, 0);

-- =====================================================
-- CUP INVENTORY TABLE
-- =====================================================
DROP TABLE IF EXISTS `cup_inventory`;
CREATE TABLE `cup_inventory` (
    `cup_id` INT AUTO_INCREMENT PRIMARY KEY,
    `cup_size` VARCHAR(20) NOT NULL UNIQUE,
    `size_ml` INT DEFAULT NULL,
    `current_stock` INT NOT NULL DEFAULT 0,
    `reorder_level` INT DEFAULT 50,
    `cost_per_cup` DECIMAL(10,2) DEFAULT 0.00,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO `cup_inventory` (`cup_size`, `size_ml`, `current_stock`, `reorder_level`, `cost_per_cup`) VALUES
('12oz', 350, 200, 50, 3.50),
('16oz', 470, 200, 50, 4.50);

-- =====================================================
-- CUP MOVEMENTS TABLE
-- =====================================================
DROP TABLE IF EXISTS `cup_movements`;
CREATE TABLE `cup_movements` (
    `movement_id` INT AUTO_INCREMENT PRIMARY KEY,
    `cup_id` INT NOT NULL,
    `movement_type` ENUM('sale', 'restock', 'adjustment', 'waste', 'void_restore') NOT NULL,
    `quantity` INT NOT NULL,
    `sale_id` INT DEFAULT NULL,
    `sale_item_id` INT DEFAULT NULL,
    `notes` TEXT,
    `user_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`cup_id`) REFERENCES `cup_inventory`(`cup_id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- INGREDIENTS TABLE
-- =====================================================
DROP TABLE IF EXISTS `ingredients`;
CREATE TABLE `ingredients` (
    `ingredient_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ingredient_name` VARCHAR(100) NOT NULL,
    `unit` VARCHAR(20) NOT NULL,
    `stock_quantity` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `reorder_level` DECIMAL(10,2) DEFAULT 10,
    `cost_per_unit` DECIMAL(10,2) DEFAULT 0.00,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO `ingredients` (`ingredient_name`, `unit`, `stock_quantity`, `reorder_level`, `cost_per_unit`) VALUES
('Espresso Beans', 'kg', 10.00, 2.00, 850.00),
('Milk', 'L', 20.00, 5.00, 75.00),
('Vanilla Syrup', 'L', 5.00, 1.00, 350.00),
('Caramel Syrup', 'L', 5.00, 1.00, 350.00),
('Matcha Powder', 'kg', 3.00, 0.50, 1200.00),
('Whipped Cream', 'L', 8.00, 2.00, 180.00),
('Chocolate Syrup', 'L', 5.00, 1.00, 280.00),
('Sugar', 'kg', 10.00, 2.00, 55.00);

-- =====================================================
-- INGREDIENT MOVEMENTS TABLE
-- =====================================================
DROP TABLE IF EXISTS `ingredient_movements`;
CREATE TABLE `ingredient_movements` (
    `movement_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ingredient_id` INT NOT NULL,
    `movement_type` ENUM('sale', 'restock', 'adjustment', 'waste', 'void_restore', 'initial') NOT NULL,
    `quantity` DECIMAL(10,2) NOT NULL,
    `sale_id` INT DEFAULT NULL,
    `sale_item_id` INT DEFAULT NULL,
    `notes` TEXT,
    `user_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients`(`ingredient_id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- PRODUCT INGREDIENTS TABLE (links products to ingredients)
-- =====================================================
DROP TABLE IF EXISTS `product_ingredients`;
CREATE TABLE `product_ingredients` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `ingredient_id` INT NOT NULL,
    `quantity_required` DECIMAL(10,3) NOT NULL,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`product_id`) ON DELETE CASCADE,
    FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients`(`ingredient_id`) ON DELETE CASCADE,
    UNIQUE KEY `product_ingredient_unique` (`product_id`, `ingredient_id`)
) ENGINE=InnoDB;

-- =====================================================
-- PRODUCT INGREDIENT RECIPES
-- ingredient_id reference:
-- 1 = Espresso Beans (kg), 2 = Milk (L), 3 = Vanilla Syrup (L)
-- 4 = Caramel Syrup (L), 5 = Matcha Powder (kg), 6 = Whipped Cream (L)
-- 7 = Chocolate Syrup (L), 8 = Sugar (kg)
-- =====================================================

-- Americano (product_id=1): 18g espresso beans
INSERT INTO `product_ingredients` (`product_id`, `ingredient_id`, `quantity_required`) VALUES
(1, 1, 0.018);

-- Cappuccino (product_id=2): 18g beans + 150ml milk
INSERT INTO `product_ingredients` (`product_id`, `ingredient_id`, `quantity_required`) VALUES
(2, 1, 0.018),
(2, 2, 0.150);

-- Latte (product_id=3): 18g beans + 200ml milk
INSERT INTO `product_ingredients` (`product_id`, `ingredient_id`, `quantity_required`) VALUES
(3, 1, 0.018),
(3, 2, 0.200);

-- Mocha (product_id=4): 18g beans + 200ml milk + 20ml chocolate syrup
INSERT INTO `product_ingredients` (`product_id`, `ingredient_id`, `quantity_required`) VALUES
(4, 1, 0.018),
(4, 2, 0.200),
(4, 7, 0.020);

-- Iced Americano (product_id=5): 18g beans
INSERT INTO `product_ingredients` (`product_id`, `ingredient_id`, `quantity_required`) VALUES
(5, 1, 0.018);

-- Iced Latte (product_id=6): 18g beans + 200ml milk
INSERT INTO `product_ingredients` (`product_id`, `ingredient_id`, `quantity_required`) VALUES
(6, 1, 0.018),
(6, 2, 0.200);

-- Iced Mocha (product_id=7): 18g beans + 200ml milk + 20ml chocolate syrup
INSERT INTO `product_ingredients` (`product_id`, `ingredient_id`, `quantity_required`) VALUES
(7, 1, 0.018),
(7, 2, 0.200),
(7, 7, 0.020);

-- Matcha Latte (product_id=8): 5g matcha powder + 200ml milk
INSERT INTO `product_ingredients` (`product_id`, `ingredient_id`, `quantity_required`) VALUES
(8, 5, 0.005),
(8, 2, 0.200);

-- Iced Matcha (product_id=9): 5g matcha powder + 200ml milk
INSERT INTO `product_ingredients` (`product_id`, `ingredient_id`, `quantity_required`) VALUES
(9, 5, 0.005),
(9, 2, 0.200);

-- Caramel Frappe (product_id=10): 18g beans + 200ml milk + 20ml caramel + 30ml whipped cream
INSERT INTO `product_ingredients` (`product_id`, `ingredient_id`, `quantity_required`) VALUES
(10, 1, 0.018),
(10, 2, 0.200),
(10, 4, 0.020),
(10, 6, 0.030);

-- Mocha Frappe (product_id=11): 18g beans + 200ml milk + 20ml chocolate + 30ml whipped cream
INSERT INTO `product_ingredients` (`product_id`, `ingredient_id`, `quantity_required`) VALUES
(11, 1, 0.018),
(11, 2, 0.200),
(11, 7, 0.020),
(11, 6, 0.030);

-- Hot Chocolate (product_id=12): 200ml milk + 20ml chocolate syrup
INSERT INTO `product_ingredients` (`product_id`, `ingredient_id`, `quantity_required`) VALUES
(12, 2, 0.200),
(12, 7, 0.020);

-- NOTE: Croissant (product_id=13) and Muffin (product_id=14) are food items
-- They do NOT use ingredients - only deduct from stock_quantity in products table

-- =====================================================
-- PRODUCT CUP SIZES TABLE (prices per cup size)
-- =====================================================
DROP TABLE IF EXISTS `product_cup_sizes`;
CREATE TABLE `product_cup_sizes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `cup_id` INT NOT NULL,
    `price` DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`product_id`) ON DELETE CASCADE,
    FOREIGN KEY (`cup_id`) REFERENCES `cup_inventory`(`cup_id`) ON DELETE CASCADE,
    UNIQUE KEY `product_cup_unique` (`product_id`, `cup_id`)
) ENGINE=InnoDB;

-- =====================================================
-- PRODUCT CUP SIZES DATA
-- Hot Coffee & Matcha: 12oz AND 16oz
-- Iced Coffee, Frappe, Non-Coffee: 16oz ONLY
-- =====================================================

-- Americano (product_id=1) - Hot Coffee: 12oz & 16oz
INSERT INTO `product_cup_sizes` (`product_id`, `cup_id`, `price`) VALUES
(1, 1, 89.00),   -- 12oz
(1, 2, 99.00);   -- 16oz

-- Cappuccino (product_id=2) - Hot Coffee: 12oz & 16oz
INSERT INTO `product_cup_sizes` (`product_id`, `cup_id`, `price`) VALUES
(2, 1, 99.00),   -- 12oz
(2, 2, 109.00);  -- 16oz

-- Latte (product_id=3) - Hot Coffee: 12oz & 16oz
INSERT INTO `product_cup_sizes` (`product_id`, `cup_id`, `price`) VALUES
(3, 1, 109.00),  -- 12oz
(3, 2, 119.00);  -- 16oz

-- Mocha (product_id=4) - Hot Coffee: 12oz & 16oz
INSERT INTO `product_cup_sizes` (`product_id`, `cup_id`, `price`) VALUES
(4, 1, 119.00),  -- 12oz
(4, 2, 129.00);  -- 16oz

-- Iced Americano (product_id=5) - Iced Coffee: 16oz ONLY
INSERT INTO `product_cup_sizes` (`product_id`, `cup_id`, `price`) VALUES
(5, 2, 99.00);   -- 16oz

-- Iced Latte (product_id=6) - Iced Coffee: 16oz ONLY
INSERT INTO `product_cup_sizes` (`product_id`, `cup_id`, `price`) VALUES
(6, 2, 119.00);  -- 16oz

-- Iced Mocha (product_id=7) - Iced Coffee: 16oz ONLY
INSERT INTO `product_cup_sizes` (`product_id`, `cup_id`, `price`) VALUES
(7, 2, 129.00);  -- 16oz

-- Matcha Latte (product_id=8) - Matcha: 12oz & 16oz
INSERT INTO `product_cup_sizes` (`product_id`, `cup_id`, `price`) VALUES
(8, 1, 129.00),  -- 12oz
(8, 2, 139.00);  -- 16oz

-- Iced Matcha (product_id=9) - Matcha: 12oz & 16oz
INSERT INTO `product_cup_sizes` (`product_id`, `cup_id`, `price`) VALUES
(9, 1, 139.00),  -- 12oz
(9, 2, 149.00);  -- 16oz

-- Caramel Frappe (product_id=10) - Frappe: 16oz ONLY
INSERT INTO `product_cup_sizes` (`product_id`, `cup_id`, `price`) VALUES
(10, 2, 149.00); -- 16oz

-- Mocha Frappe (product_id=11) - Frappe: 16oz ONLY
INSERT INTO `product_cup_sizes` (`product_id`, `cup_id`, `price`) VALUES
(11, 2, 149.00); -- 16oz

-- Hot Chocolate (product_id=12) - Non-Coffee: 16oz ONLY
INSERT INTO `product_cup_sizes` (`product_id`, `cup_id`, `price`) VALUES
(12, 2, 89.00);  -- 16oz

-- =====================================================
-- SALES TABLE
-- =====================================================
DROP TABLE IF EXISTS `sales`;
CREATE TABLE `sales` (
    `sale_id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
    `customer_name` VARCHAR(100) DEFAULT NULL,
    `user_id` INT,
    `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `tax` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `discount` DECIMAL(10,2) DEFAULT 0.00,
    `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `amount_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `change_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `payment_method` ENUM('cash', 'card', 'gcash', 'maya') DEFAULT 'cash',
    `status` ENUM('completed', 'voided', 'pending') DEFAULT 'completed',
    `notes` TEXT,
    `sale_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- SALE ITEMS TABLE
-- =====================================================
DROP TABLE IF EXISTS `sale_items`;
CREATE TABLE `sale_items` (
    `sale_item_id` INT AUTO_INCREMENT PRIMARY KEY,
    `sale_id` INT NOT NULL,
    `product_id` INT,
    `product_name` VARCHAR(100) NOT NULL,
    `cup_id` INT DEFAULT NULL,
    `cup_size` VARCHAR(20) DEFAULT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(10,2) NOT NULL,
    `subtotal` DECIMAL(10,2) NOT NULL,
    `is_voided` TINYINT(1) DEFAULT 0,
    `void_reason` TEXT,
    `voided_by` INT DEFAULT NULL,
    `voided_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`sale_id`) REFERENCES `sales`(`sale_id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`product_id`) ON DELETE SET NULL,
    FOREIGN KEY (`cup_id`) REFERENCES `cup_inventory`(`cup_id`) ON DELETE SET NULL,
    FOREIGN KEY (`voided_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- VOIDED ORDERS TABLE (for cart voids before checkout)
-- =====================================================
DROP TABLE IF EXISTS `voided_orders`;
CREATE TABLE `voided_orders` (
    `void_id` INT AUTO_INCREMENT PRIMARY KEY,
    `void_type` ENUM('cart', 'item', 'sale') NOT NULL DEFAULT 'cart',
    `sale_id` INT DEFAULT NULL,
    `sale_item_id` INT DEFAULT NULL,
    `cart_data` JSON,
    `void_reason` TEXT NOT NULL,
    `voided_by` INT NOT NULL,
    `authorized_by` INT NOT NULL,
    `original_total` DECIMAL(10,2) DEFAULT 0.00,
    `cups_restored` TINYINT(1) DEFAULT 0,
    `ingredients_restored` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`sale_id`) REFERENCES `sales`(`sale_id`) ON DELETE SET NULL,
    FOREIGN KEY (`voided_by`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
    FOREIGN KEY (`authorized_by`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- STOCK MOVEMENTS TABLE (for products)
-- =====================================================
DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE `stock_movements` (
    `movement_id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `movement_type` ENUM('sale', 'restock', 'adjustment', 'waste', 'void_restore', 'initial') NOT NULL,
    `quantity` INT NOT NULL,
    `notes` TEXT,
    `user_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`product_id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- ACTIVITY LOGS TABLE (with security enhancements)
-- =====================================================
DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT,
    `action` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `reference_type` VARCHAR(50),
    `reference_id` INT,
    `old_values` JSON,
    `new_values` JSON,
    `details` TEXT,
    `ip_address` VARCHAR(45),
    `user_agent` VARCHAR(255),
    `device_info` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_reference` (`reference_type`, `reference_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB;

-- =====================================================
-- AUDIT LOGS TABLE (simple, defense-friendly)
-- =====================================================
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `action` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_action` (`action`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_user_id` (`user_id`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- LOGIN ATTEMPTS TABLE (for rate limiting - enhanced)
-- =====================================================
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `attempt_type` VARCHAR(50) NOT NULL,
    `identifier` VARCHAR(100) NOT NULL,
    `username` VARCHAR(100),
    `ip_address` VARCHAR(45),
    `status` ENUM('failed', 'success', 'locked') DEFAULT 'failed',
    `attempts` INT DEFAULT 1,
    `last_attempt` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `locked_until` TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY `type_identifier` (`attempt_type`, `identifier`),
    INDEX `idx_locked_until` (`locked_until`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB;

-- =====================================================
-- CSRF TOKENS TABLE
-- =====================================================
DROP TABLE IF EXISTS `csrf_tokens`;
CREATE TABLE `csrf_tokens` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `user_id` INT,
    `expires_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB;

-- =====================================================
-- SALE VOIDS TABLE (for cart/sale voids history)
-- =====================================================
DROP TABLE IF EXISTS `sale_voids`;
CREATE TABLE `sale_voids` (
    `void_id` INT AUTO_INCREMENT PRIMARY KEY,
    `sale_id` INT DEFAULT NULL,
    `void_type` ENUM('cart', 'sale', 'item') NOT NULL DEFAULT 'cart',
    `total_amount` DECIMAL(10,2) DEFAULT 0.00,
    `void_reason` TEXT NOT NULL,
    `voided_by` INT NOT NULL,
    `authorized_by` INT NOT NULL,
    `voided_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`sale_id`) REFERENCES `sales`(`sale_id`) ON DELETE SET NULL,
    FOREIGN KEY (`voided_by`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
    FOREIGN KEY (`authorized_by`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- VOID LOGS TABLE (detailed void audit trail)
-- =====================================================
DROP TABLE IF EXISTS `void_logs`;
CREATE TABLE `void_logs` (
    `void_id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NULL,
    `sale_item_id` INT NULL,
    `product_id` INT NULL,
    `cashier_id` INT NOT NULL,
    `admin_id` INT NOT NULL,
    `void_reason` TEXT NOT NULL,
    `void_type` ENUM('cart', 'item', 'sale') NOT NULL DEFAULT 'item',
    `product_name` VARCHAR(255),
    `quantity` INT DEFAULT 1,
    `unit_price` DECIMAL(10,2) DEFAULT 0.00,
    `total_amount` DECIMAL(10,2) DEFAULT 0.00,
    `cup_size` VARCHAR(50),
    `cups_restored` TINYINT(1) DEFAULT 0,
    `ingredients_restored` TINYINT(1) DEFAULT 0,
    `ip_address` VARCHAR(45),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`cashier_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
    FOREIGN KEY (`admin_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
    INDEX `idx_order_id` (`order_id`),
    INDEX `idx_cashier_id` (`cashier_id`),
    INDEX `idx_admin_id` (`admin_id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_void_type` (`void_type`)
) ENGINE=InnoDB;

-- =====================================================
-- INVENTORY MOVEMENTS TABLE (unified audit trail)
-- =====================================================
DROP TABLE IF EXISTS `inventory_movements`;
CREATE TABLE `inventory_movements` (
    `movement_id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NULL,
    `ingredient_id` INT NULL,
    `cup_id` INT NULL,
    `movement_type` ENUM('sale', 'void', 'restock', 'adjustment', 'waste', 'initial', 'transfer') NOT NULL,
    `quantity_change` INT NOT NULL,
    `quantity_before` INT DEFAULT 0,
    `quantity_after` INT DEFAULT 0,
    `reference_type` VARCHAR(50),
    `reference_id` INT,
    `notes` TEXT,
    `user_id` INT,
    `ip_address` VARCHAR(45),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`product_id`) ON DELETE SET NULL,
    FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients`(`ingredient_id`) ON DELETE SET NULL,
    FOREIGN KEY (`cup_id`) REFERENCES `cup_inventory`(`cup_id`) ON DELETE SET NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
    INDEX `idx_product_id` (`product_id`),
    INDEX `idx_ingredient_id` (`ingredient_id`),
    INDEX `idx_cup_id` (`cup_id`),
    INDEX `idx_movement_type` (`movement_type`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB;

-- =====================================================
-- SESSION LOGS TABLE (track active sessions)
-- =====================================================
DROP TABLE IF EXISTS `session_logs`;
CREATE TABLE `session_logs` (
    `session_id` VARCHAR(128) PRIMARY KEY,
    `user_id` INT NOT NULL,
    `ip_address` VARCHAR(45),
    `user_agent` VARCHAR(255),
    `login_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `logout_time` TIMESTAMP NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_last_activity` (`last_activity`)
) ENGINE=InnoDB;

-- =====================================================
-- SECURITY ALERTS TABLE
-- =====================================================
DROP TABLE IF EXISTS `security_alerts`;
CREATE TABLE `security_alerts` (
    `alert_id` INT AUTO_INCREMENT PRIMARY KEY,
    `alert_type` ENUM('failed_login', 'suspicious_activity', 'void_abuse', 'inventory_anomaly', 'session_hijack', 'brute_force') NOT NULL,
    `severity` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    `user_id` INT NULL,
    `description` TEXT NOT NULL,
    `ip_address` VARCHAR(45),
    `additional_data` JSON,
    `is_acknowledged` TINYINT(1) DEFAULT 0,
    `acknowledged_by` INT NULL,
    `acknowledged_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
    FOREIGN KEY (`acknowledged_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
    INDEX `idx_alert_type` (`alert_type`),
    INDEX `idx_severity` (`severity`),
    INDEX `idx_is_acknowledged` (`is_acknowledged`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB;

-- =====================================================
-- PRICE HISTORY TABLE (track price changes)
-- =====================================================
DROP TABLE IF EXISTS `price_history`;
CREATE TABLE `price_history` (
    `history_id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `old_price` DECIMAL(10,2) NOT NULL,
    `new_price` DECIMAL(10,2) NOT NULL,
    `changed_by` INT NOT NULL,
    `reason` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`product_id`) ON DELETE CASCADE,
    FOREIGN KEY (`changed_by`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
    INDEX `idx_product_id` (`product_id`),
    INDEX `idx_changed_by` (`changed_by`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB;

-- =====================================================
-- VOID STATISTICS VIEW (for admin reports)
-- =====================================================
CREATE OR REPLACE VIEW `void_statistics` AS
SELECT 
    DATE(vl.created_at) as void_date,
    vl.void_type,
    COUNT(*) as void_count,
    SUM(vl.total_amount) as total_voided_amount,
    COUNT(DISTINCT vl.cashier_id) as unique_cashiers,
    COUNT(DISTINCT vl.admin_id) as unique_admins
FROM void_logs vl
GROUP BY DATE(vl.created_at), vl.void_type
ORDER BY void_date DESC;

-- =====================================================
-- VOID DETAILS VIEW (for admin reports)
-- =====================================================
CREATE OR REPLACE VIEW `void_details` AS
SELECT 
    vl.void_id,
    vl.order_id,
    vl.void_type,
    vl.product_name,
    vl.quantity,
    vl.unit_price,
    vl.total_amount,
    vl.cup_size,
    vl.void_reason,
    vl.cups_restored,
    vl.ingredients_restored,
    c.username as cashier_username,
    c.full_name as cashier_name,
    a.username as admin_username,
    a.full_name as admin_name,
    vl.ip_address,
    vl.created_at
FROM void_logs vl
JOIN users c ON vl.cashier_id = c.user_id
JOIN users a ON vl.admin_id = a.user_id
ORDER BY vl.created_at DESC;

-- =====================================================
-- ACTIVITY LOG SUMMARY VIEW (for quick reporting)
-- =====================================================
CREATE OR REPLACE VIEW `activity_summary` AS
SELECT 
    DATE(created_at) as log_date,
    action,
    COUNT(*) as action_count,
    COUNT(DISTINCT user_id) as unique_users,
    COUNT(DISTINCT ip_address) as unique_ips
FROM activity_logs
GROUP BY DATE(created_at), action
ORDER BY log_date DESC, action_count DESC;

-- =====================================================
-- Done! Default login credentials:
-- Admin: username = admin, password = admin123
-- Cashier: username = cashier, password = cashier123
-- =====================================================
