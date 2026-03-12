<?php
/**
 * Automatic Database Backup Script
 * Creates timestamped SQL backup files
 * 
 * USAGE:
 * - Manual: Run this script from browser or CLI
 * - Automatic: Set up Windows Task Scheduler or cron job
 * 
 * For Windows Task Scheduler:
 * 1. Open Task Scheduler
 * 2. Create Basic Task
 * 3. Set trigger to Daily
 * 4. Action: Start a program
 * 5. Program: C:\xampp\php\php.exe
 * 6. Arguments: C:\xampp\htdocs\IT12\IT12\scripts\backup_database.php
 * 
 * For Linux cron (add to crontab -e):
 * 0 2 * * * /usr/bin/php /var/www/html/IT12/scripts/backup_database.php
 */

// Configuration
require_once __DIR__ . '/../includes/env.php';

$config = [
    'host' => Env::get('DB_HOST', 'localhost'),
    'user' => Env::get('DB_USER', 'root'),
    'pass' => Env::get('DB_PASS', ''),
    'name' => Env::get('DB_NAME', 'coffee_shop_pos'),
    'backup_dir' => __DIR__ . '/../backups/',
    'keep_days' => 30, // Delete backups older than 30 days
    'mysqldump_path' => 'C:\\xampp\\mysql\\bin\\mysqldump.exe' // Adjust for your system
];

// Create backup directory if it doesn't exist
if (!is_dir($config['backup_dir'])) {
    mkdir($config['backup_dir'], 0755, true);
}

// Generate backup filename with timestamp
$timestamp = date('Y-m-d_H-i-s');
$backupFile = $config['backup_dir'] . "backup_{$config['name']}_{$timestamp}.sql";

// Output header for CLI/browser
if (php_sapi_name() === 'cli') {
    echo "POPRIE POS - Database Backup Script\n";
    echo str_repeat("=", 50) . "\n";
} else {
    header('Content-Type: text/plain');
    echo "POPRIE POS - Database Backup Script\n";
    echo str_repeat("=", 50) . "\n";
}

echo "Starting backup at " . date('Y-m-d H:i:s') . "\n";
echo "Database: {$config['name']}\n";
echo "Backup file: $backupFile\n\n";

// Method 1: Using mysqldump (preferred)
if (file_exists($config['mysqldump_path'])) {
    $command = sprintf(
        '"%s" --host=%s --user=%s %s %s > "%s" 2>&1',
        $config['mysqldump_path'],
        escapeshellarg($config['host']),
        escapeshellarg($config['user']),
        !empty($config['pass']) ? '--password=' . escapeshellarg($config['pass']) : '',
        escapeshellarg($config['name']),
        $backupFile
    );
    
    exec($command, $output, $returnCode);
    
    if ($returnCode === 0 && file_exists($backupFile) && filesize($backupFile) > 0) {
        $size = number_format(filesize($backupFile) / 1024, 2);
        echo "✓ Backup successful using mysqldump!\n";
        echo "  File size: {$size} KB\n";
    } else {
        echo "✗ mysqldump failed, trying PHP backup method...\n";
        $usePHPBackup = true;
    }
} else {
    echo "Note: mysqldump not found at {$config['mysqldump_path']}\n";
    echo "Using PHP backup method...\n";
    $usePHPBackup = true;
}

// Method 2: PHP-based backup (fallback)
if (isset($usePHPBackup) && $usePHPBackup) {
    try {
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4",
            $config['user'],
            $config['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $backup = "-- POPRIE POS Database Backup\n";
        $backup .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $backup .= "-- Database: {$config['name']}\n";
        $backup .= "-- =========================================\n\n";
        $backup .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $backup .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
        
        // Get all tables
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($tables as $table) {
            echo "  Backing up table: $table\n";
            
            // Get CREATE TABLE statement
            $createTable = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $backup .= "-- Table structure for `$table`\n";
            $backup .= "DROP TABLE IF EXISTS `$table`;\n";
            $backup .= $createTable['Create Table'] . ";\n\n";
            
            // Get table data
            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($rows) > 0) {
                $backup .= "-- Data for `$table`\n";
                $columns = array_keys($rows[0]);
                $columnList = '`' . implode('`, `', $columns) . '`';
                
                foreach ($rows as $row) {
                    $values = array_map(function($val) use ($pdo) {
                        if ($val === null) return 'NULL';
                        return $pdo->quote($val);
                    }, array_values($row));
                    
                    $backup .= "INSERT INTO `$table` ($columnList) VALUES (" . implode(', ', $values) . ");\n";
                }
                $backup .= "\n";
            }
        }
        
        $backup .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        // Write to file
        file_put_contents($backupFile, $backup);
        
        $size = number_format(filesize($backupFile) / 1024, 2);
        echo "\n✓ Backup successful using PHP method!\n";
        echo "  Tables backed up: " . count($tables) . "\n";
        echo "  File size: {$size} KB\n";
        
    } catch (PDOException $e) {
        echo "✗ Backup failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Clean up old backups
echo "\nCleaning up old backups (older than {$config['keep_days']} days)...\n";
$cutoffTime = time() - ($config['keep_days'] * 24 * 60 * 60);
$deleted = 0;

$files = glob($config['backup_dir'] . 'backup_*.sql');
foreach ($files as $file) {
    if (filemtime($file) < $cutoffTime) {
        unlink($file);
        echo "  Deleted: " . basename($file) . "\n";
        $deleted++;
    }
}

echo $deleted > 0 ? "  Cleaned up $deleted old backup(s)\n" : "  No old backups to clean\n";

// List current backups
echo "\nCurrent backups in directory:\n";
$files = glob($config['backup_dir'] . 'backup_*.sql');
rsort($files); // Most recent first
$count = 0;
foreach ($files as $file) {
    if ($count >= 5) {
        echo "  ... and " . (count($files) - 5) . " more\n";
        break;
    }
    $size = number_format(filesize($file) / 1024, 2);
    $date = date('Y-m-d H:i:s', filemtime($file));
    echo "  " . basename($file) . " ({$size} KB) - $date\n";
    $count++;
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "Backup completed at " . date('Y-m-d H:i:s') . "\n";

// Log the backup activity
try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4",
        $config['user'],
        $config['pass']
    );
    $stmt = $pdo->prepare("INSERT INTO activity_logs (action, description, ip_address) VALUES (?, ?, ?)");
    $stmt->execute(['database_backup', 'Automatic database backup created: ' . basename($backupFile), '127.0.0.1']);
} catch (Exception $e) {
    // Silent fail for logging
}
