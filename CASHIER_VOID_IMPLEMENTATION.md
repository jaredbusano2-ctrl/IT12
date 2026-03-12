# Cashier Void & Activity Log Implementation Guide

## Overview

This document describes the implementation of the Cashier VOID functionality, Clear Cart feature, enhanced Activity Logging, and security improvements for the Coffee Shop POS system.

---

## FEATURE 1: CASHIER VOID FUNCTION

### Capabilities

The cashier can now:
1. **Clear Cart** - Simply remove all items from cart (no database changes)
2. **Void Cart** - Void cart before checkout with admin authorization (logs to void_logs)
3. **Void Completed Orders** - Void orders that have been placed (requires admin password)

### How It Works

#### Void Cart (Before Checkout)
1. Cashier clicks "⚠️ Void Cart" button
2. Modal opens requesting admin password and reason
3. Admin enters password to authorize
4. Cart is cleared and void is logged to `void_logs` table

#### Void Completed Order
1. Cashier clicks "📋 Order History" button
2. Modal shows today's orders
3. Cashier selects order and clicks "Void Order"
4. Authorization modal appears
5. Admin enters password and reason
6. Order is voided, inventory is restored

### Security Features
- Admin password verification using `password_verify()`
- Rate limiting: 3 attempts, 30-second lockout
- CSRF token protection
- All voids logged with IP address
- Only admins can authorize voids

---

## FEATURE 2: ENHANCED ACTIVITY LOGGING

### Logged Activities
| Action | Description |
|--------|-------------|
| `login_success` | Successful user login |
| `login_failed` | Failed login attempt |
| `logout` | User logout |
| `sale_completed` | New sale processed |
| `cart_voided` | Cart voided before checkout |
| `item_voided` | Single item voided |
| `sale_voided` | Entire sale voided |
| `void_auth_success` | Successful void authorization |
| `void_auth_failed` | Failed void authorization |
| `inventory_update` | Stock changes |

### Data Captured
Each log entry includes:
- `user_id` - User performing the action
- `role` - User's role at time of action
- `action` - Action type
- `description` - Human-readable description
- `ip_address` - Client IP
- `user_agent` - Browser info
- `reference_type` & `reference_id` - Related entity
- `created_at` - Timestamp

---

## FEATURE 3: CLEAR CART BUTTON

### Behavior
- Located in cart actions area (🗑️ Clear Cart)
- Shows confirmation dialog
- Clears all cart items instantly
- Resets cup size selections
- Resets total to ₱0.00
- **Does NOT affect database** (no order has been placed)
- **Does NOT require admin authorization**

### JavaScript Function
```javascript
function clearCartConfirm() {
    if (cart.length === 0) {
        alert('Cart is already empty!');
        return;
    }
    
    if (confirm('Are you sure you want to clear all items?')) {
        clearCartCompletely();
    }
}
```

---

## FEATURE 4: SECURITY IMPROVEMENTS

### Implemented Security Measures

1. **Prepared Statements**
   - All SQL queries use PDO prepared statements
   - Parameters are bound separately from SQL

2. **Password Hashing**
   - Passwords stored using `password_hash()` with bcrypt
   - Verification using `password_verify()`

3. **CSRF Protection**
   - Tokens generated for all forms and API calls
   - Database-backed token storage
   - Automatic token validation

4. **Rate Limiting**
   - Login attempts: 5 max, 15-minute lockout
   - Void attempts: 3 max, 30-second lockout
   - IP-based and username-based tracking

5. **Input Sanitization**
   - `sanitize()` - HTML entity encoding
   - `sanitizeInt()` - Integer filtering
   - `sanitizeFloat()` - Decimal filtering

6. **Session Security**
   - HTTP-only cookies
   - Session fingerprinting
   - 30-minute inactivity timeout
   - Session ID regeneration

---

## FEATURE 5: DATABASE STRUCTURE

### New Table: void_logs

```sql
CREATE TABLE `void_logs` (
    `void_id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NULL,
    `sale_item_id` INT NULL,
    `product_id` INT NULL,
    `cashier_id` INT NOT NULL,
    `admin_id` INT NOT NULL,
    `void_reason` TEXT NOT NULL,
    `void_type` ENUM('cart', 'item', 'sale') NOT NULL,
    `product_name` VARCHAR(255),
    `quantity` INT DEFAULT 1,
    `unit_price` DECIMAL(10,2) DEFAULT 0.00,
    `total_amount` DECIMAL(10,2) DEFAULT 0.00,
    `cup_size` VARCHAR(50),
    `cups_restored` TINYINT(1) DEFAULT 0,
    `ingredients_restored` TINYINT(1) DEFAULT 0,
    `ip_address` VARCHAR(45),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (`cashier_id`) REFERENCES `users`(`user_id`),
    FOREIGN KEY (`admin_id`) REFERENCES `users`(`user_id`)
);
```

### Run Enhancement SQL
Import the enhancement file to add new columns and views:
```
database/void_logs_enhancement.sql
```

---

## File Changes Summary

### Modified Files
| File | Changes |
|------|---------|
| `pos.php` | Added Clear Cart, Order History, and Order Void modals |
| `js/pos.js` | Added `clearCartConfirm()`, order history, and void functions |
| `includes/security.php` | Enhanced `logActivity()` to include role |

### New Files
| File | Purpose |
|------|---------|
| `api/get-recent-orders.php` | API to fetch today's orders for void selection |
| `database/void_logs_enhancement.sql` | SQL for new tables and views |
| `CASHIER_VOID_IMPLEMENTATION.md` | This documentation |

---

## Testing Checklist

### Clear Cart
- [ ] Clear Cart button appears in POS
- [ ] Click shows confirmation dialog
- [ ] Cart is emptied after confirm
- [ ] Cup selections are reset
- [ ] Totals reset to ₱0.00

### Void Cart (Before Checkout)
- [ ] Void Cart button works
- [ ] Modal requests admin password
- [ ] Wrong password shows error
- [ ] 3 failed attempts cause lockout
- [ ] Correct password clears cart
- [ ] Void is logged in void_logs

### Void Completed Order
- [ ] Order History shows today's orders
- [ ] Voided orders marked as VOIDED
- [ ] Can select order to void
- [ ] Authorization modal appears
- [ ] Requires reason (min 10 chars)
- [ ] Admin password required
- [ ] Inventory is restored after void
- [ ] Order status changes to 'voided'

### Activity Logging
- [ ] Login attempts logged
- [ ] Void operations logged
- [ ] Sales logged
- [ ] IP address captured
- [ ] Role captured

---

## API Endpoints

### GET /api/get-recent-orders.php
Returns today's orders for void selection.

**Response:**
```json
{
    "success": true,
    "orders": [
        {
            "sale_id": 1,
            "invoice_number": "INV-20260310-001",
            "customer_name": "John",
            "total_amount": "250.00",
            "status": "completed",
            "sale_date": "10:30 AM",
            "item_count": 3
        }
    ]
}
```

### POST /api/void_item.php
Handles void operations (cart, item, sale).

**Request Body:**
```json
{
    "void_type": "sale",
    "sale_id": 1,
    "admin_password": "admin123",
    "void_reason": "Customer requested refund"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Sale voided successfully",
    "cups_restored": true,
    "ingredients_restored": true
}
```

---

## Default Credentials

| Role | Username | Password |
|------|----------|----------|
| Admin | admin | admin123 |
| Cashier | cashier | cashier123 |

---

## Troubleshooting

### "Invalid admin password" error
- Make sure to use the correct admin password
- Check if admin account is active in database

### "Too many failed attempts" error
- Wait 30 seconds and try again
- The lockout is IP-based

### Orders not appearing in history
- Only today's orders are shown
- Cashiers only see their own orders
- Admins see all orders

### Void not restoring inventory
- Check that `cups_restored` and `ingredients_restored` are true in response
- Verify inventory tables have correct foreign keys

---

## Support

For issues or questions:
1. Check the activity_logs table for error details
2. Review PHP error logs in XAMPP
3. Check browser console for JavaScript errors
