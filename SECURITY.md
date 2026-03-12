# POPRIE POS - Security Documentation

## Overview

This document outlines the security features implemented in the POPRIE POS system to protect against both external threats (hackers) and internal threats (employee theft, fraud).

---

## 1. Database & Infrastructure Security

### 1.1 Automatic Database Backups

**Location:** `scripts/backup_database.php`

The system includes an automatic backup script that:
- Creates timestamped `.sql` backup files
- Automatically cleans up backups older than 30 days
- Can be run manually or scheduled via Task Scheduler/cron

**Setup for Windows Task Scheduler:**
1. Open Task Scheduler
2. Create Basic Task → Name: "POPRIE POS Daily Backup"
3. Trigger: Daily at 2:00 AM
4. Action: Start a program
5. Program: `C:\xampp\php\php.exe`
6. Arguments: `C:\xampp\htdocs\IT12\IT12\scripts\backup_database.php`

**Setup for Linux cron:**
```bash
# Add to crontab -e
0 2 * * * /usr/bin/php /var/www/html/IT12/scripts/backup_database.php
```

### 1.2 Environment Variables (.env)

**Location:** `.env` (root directory)

Database credentials are stored in a `.env` file, NOT in source code:

```env
DB_HOST=localhost
DB_USER=poprie_pos
DB_PASS=your_secure_password
DB_NAME=coffee_shop_pos
```

**Security Benefits:**
- ✅ Credentials never appear in version control
- ✅ Different credentials for dev/staging/production
- ✅ `.gitignore` excludes `.env` from commits

### 1.3 SSL/HTTPS Configuration

For production deployment:
1. Set `FORCE_HTTPS=true` in `.env`
2. Install SSL certificate (Let's Encrypt is free)
3. Configure Apache/Nginx for HTTPS

The system will auto-redirect HTTP → HTTPS when enabled.

### 1.4 Limited MySQL User Permissions

**Location:** `scripts/setup_limited_mysql_user.sql`

Instead of using `root` user, create a restricted MySQL user:

```sql
CREATE USER 'poprie_pos'@'localhost' IDENTIFIED BY 'secure_password';
GRANT SELECT, INSERT, UPDATE ON coffee_shop_pos.* TO 'poprie_pos'@'localhost';
-- NO DROP, TRUNCATE, or ALTER privileges!
```

**Why This Matters:**
- If application is compromised, attacker cannot DROP tables
- Prevents accidental data deletion
- Follows principle of least privilege

---

## 2. Enhanced Accountability (Anti-Theft)

### 2.1 Session Timeout

**Configuration:** `SESSION_TIMEOUT=600` in `.env` (10 minutes)

- Idle sessions automatically expire after 10 minutes
- Prevents unauthorized access from unattended terminals
- User must re-login after timeout

### 2.2 High-Value Transaction Alerts

**Configuration:** `HIGH_VALUE_THRESHOLD=2000` in `.env`

When any void exceeds ₱2,000:
- Logs a "HIGH PRIORITY" event in activity_logs
- Sends email alert (if ALERT_EMAIL is configured)
- Records IP address and device information

**Example Alert:**
```
HIGH-VALUE ALERT: SALE_VOID of ₱3,500.00 (threshold: ₱2,000.00)
User: John Cashier (cashier)
Time: 2026-03-10 14:30:45
IP: 192.168.1.100
```

### 2.3 Price Integrity Check (Backend Verification)

**Location:** `api/process-sale.php`

All prices are verified server-side before processing:

```php
// Frontend price can be manipulated via Inspect Element
$submittedPrice = $data['items'][0]['price'];

// Backend fetches actual price from database
$actualPrice = getProductPrice($productId, $cupId);

// Mismatch = Fraud attempt logged
if (abs($submittedPrice - $actualPrice) > 0.01) {
    logActivity('fraud_attempt', "Price manipulation detected");
    throw new Exception("Invalid price");
}
```

**Protected Against:**
- ✅ Inspect Element price editing
- ✅ JavaScript console manipulation
- ✅ API request tampering

---

## 3. Advanced Audit Logging

### 3.1 IP & User Agent Logging

Every action logs:
- **IP Address:** Tracks which computer/network
- **User Agent:** Tracks browser/device type
- **Timestamp:** Exact time of action
- **User ID & Role:** Who performed the action

**Red Flag Detection:**
```
Action: cart_voided
User: Manager Account
IP: 192.168.1.50 (Manager's office)
✓ Normal

Action: cart_voided
User: Manager Account
IP: 192.168.1.100 (Cashier terminal)
⚠️ SUSPICIOUS - Manager account used from cashier's computer
```

### 3.2 Immutable Logs

- **No delete button** in Activity Logs UI
- Logs cannot be modified or deleted through the application
- Only database-level access can alter logs (requires DBA privileges)

**Database Protection:**
```sql
-- Activity logs table has no DELETE permission for application user
GRANT SELECT, INSERT ON activity_logs TO 'poprie_pos'@'localhost';
-- NO UPDATE or DELETE granted
```

---

## 4. Technical Hardening

### 4.1 Secure HTTP Headers

**Location:** `includes/security.php` → `setSecurityHeaders()`

```php
header('X-Frame-Options: DENY');                    // Prevent clickjacking
header('X-Content-Type-Options: nosniff');          // Prevent MIME sniffing
header('X-XSS-Protection: 1; mode=block');          // XSS protection
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'"); // Restrict resources
```

### 4.2 CSRF Protection

Every form includes a CSRF token:
```php
<input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
```

API requests verify the token:
```php
if (!verifyCSRFToken($_POST['csrf_token'])) {
    jsonError('Invalid security token', 403);
}
```

### 4.3 Rate Limiting

**Void Attempts:**
- Max 3 failed password attempts
- 5-minute lockout after failures
- Prevents brute-force attacks on admin passwords

**Login Attempts:**
- Max 5 failed login attempts
- 15-minute lockout
- Account lockout logged for review

### 4.4 SQL Injection Prevention

All database queries use prepared statements:
```php
// VULNERABLE (Never do this)
$sql = "SELECT * FROM users WHERE id = " . $_GET['id'];

// SECURE (Always use this)
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_GET['id']]);
```

### 4.5 Password Security

- Passwords hashed with bcrypt (`password_hash()`)
- Minimum 8 character requirement
- Salted automatically by bcrypt
- Never stored in plain text

---

## 5. Security Checklist for Production

### Before Going Live:

- [ ] Change all default passwords
- [ ] Set up `.env` file with production credentials
- [ ] Run `setup_limited_mysql_user.sql`
- [ ] Enable SSL/HTTPS
- [ ] Set `FORCE_HTTPS=true`
- [ ] Set `APP_DEBUG=false`
- [ ] Configure email alerts (`ALERT_EMAIL`)
- [ ] Schedule automatic backups
- [ ] Review activity logs daily
- [ ] Train staff on security procedures

### Regular Maintenance:

- [ ] Weekly: Review high-value alerts
- [ ] Weekly: Check activity logs for anomalies
- [ ] Monthly: Rotate database password
- [ ] Monthly: Review user accounts (remove inactive)
- [ ] Quarterly: Security audit
- [ ] Yearly: Update dependencies

---

## 6. Incident Response

### If You Suspect Fraud:

1. **Don't alert the suspect**
2. Document the suspicious activity
3. Export activity logs for that period
4. Check IP addresses and devices used
5. Compare void amounts to normal patterns
6. Contact management/owner

### If System is Compromised:

1. Disconnect from network immediately
2. Change all passwords (database, users)
3. Check activity logs for unauthorized access
4. Restore from most recent clean backup
5. Review and patch vulnerability
6. Document incident for future reference

---

## 7. File Structure

```
IT12/
├── .env                          # Environment variables (NEVER commit)
├── .gitignore                    # Excludes sensitive files
├── includes/
│   ├── config.php                # Loads from .env
│   ├── env.php                   # Environment loader
│   ├── security.php              # Security functions
│   └── void_functions.php        # Void with high-value alerts
├── scripts/
│   ├── backup_database.php       # Automatic backups
│   └── setup_limited_mysql_user.sql
└── backups/                      # Backup storage (auto-created)
```

---

*Last Updated: March 2026*
*POPRIE POS Security Documentation v1.0*
