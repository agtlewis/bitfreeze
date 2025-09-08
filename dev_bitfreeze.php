<?php
/**
 * BitFreeze Backup System
 * 
 * Advanced Backup System with Content Aware Deduplication and Recovery
 * 
 * This script creates intelligent, version based, backup archives that protect against bitrot and
 * corruption while maximizing storage efficiency through content-based deduplication. Unlike traditional
 * backup systems that store files multiple times, this system stores unique file contents only once,
 * dramatically reducing archive size and improving performance.  Moving and reorganization of files 
 * does not increase archive size.
 * 
 * Key Features:
 * - Content based deduplication using MD5 hashes
 * - RAR 5.0+ format with 6% (configurable) recovery records for bitrot protection
 * - Strong Enterprise/Government Grade encryption support using AES-256 with password protection
 * - Snapshot based versioning with comments
 * - Automatic duplicate detection to prevent duplicate snapshots
 * - Built in repair capabilities for damaged archives
 * - Diff functionality to compare versions
 * - Progress tracking and detailed reporting
 * 
 * How it works:
 * Files are scanned and their MD5 hashes are computed. Only unique file contents
 * are stored in the archive's 'files/' directory, while manifests in 'versions/'
 * track which files existed in each snapshot. This means moving large files around
 * or creating multiple copies doesn't increase archive size - only truly unique
 * content consumes additional space.
 * 
 * Recovery and Safety:
 * - 6% recovery records protect against bitrot and corruption
 * - Built-in repair command can recover damaged archives
 * - Password protection secures sensitive backups with strong Enterprise/Government Grade Encryption
 * - Automatic integrity checking during operations
 * 
 * Usage Examples:
 * - Create backup: php bitfreeze.php commit /home/user/documents archive.rar "Daily Snapshot" -p password
 * - List snapshots: php bitfreeze.php list archive.rar -p password
 * - Checkout snapshot: php bitfreeze.php checkout 1 archive.rar /checkout/path -p password
 * - Compare snapshots: php bitfreeze.php diff 1 4 archive.rar -p password
 * - Repair archive: php bitfreeze.php repair archive.rar -p password

 * 
 * @author Benjamin Lewis <net@p9b.org>
 * @version 2.0
 * @license MIT
 */

/**
 * Redundant information (recovery record) may be added to RAR archive. While it increases the archive 
 * size, it helps to recover archived files in case of disk failure or data loss of other kind, provided that damage
 *  is not too severe. Such damage recovery can be done with command "r". ZIP archive format does not support 
 * the recovery record.
 *
 * Records are most efficient if data positions in damaged archive are not shifted. If you copy an archive from 
 * damaged media using some special software and if you have a choice to fill damaged areas with zeroes or to 
 * cut out them from copied file, filling with zeroes or any other value is preferable, because it allows to 
 * preserve original data positions. Still, even though it is not an optimal mode, both versions attempt to
 * repair data even in case of deletions or insertions of reasonable size, when data positions were shifted.
 */
define('RECOVERY_RECORD_SIZE', 6); // Set as a percentage
define('PROGRESS_BAR_WIDTH', 49); // Sets the width of the progress bar in the terminal
define('MD5_TIMEOUT', 600); // Maximum time in seconds to calculate MD5 hash of a file
define('BATCH_SIZE_PERCENTAGE', 70); // Use 30% of available temp disk space for batch processing
define('BF_DEBUG_MODE', false); // Set to false to disable all debug output
define('BF_DEBUG_LOGS', true); // write debug logs
define('BF_ENCRYPTION_MODE', '-p'); // -hp = Encrypt files & headers, -p = Encrypted files only (faster)

require_once __DIR__ . '/src/commandManager.php';
require_once __DIR__ . '/src/systemManager.php';
require_once __DIR__ . '/src/passwordManager.php';
require_once __DIR__ . '/src/utilityManager.php';
require_once __DIR__ . '/src/progressManager.php';
require_once __DIR__ . '/src/argumentHandler.php';
require_once __DIR__ . '/src/displayManager.php';
require_once __DIR__ . '/src/fileSystemManager.php';
require_once __DIR__ . '/src/archiveManager.php';
require_once __DIR__ . '/src/systemHealthManager.php';

/**
 * Debug output wrapper function
 * Only outputs debug messages when BF_DEBUG_MODE is true
 * Also writes to log file when BF_DEBUG_LOGS is true
 */
function debug_echo(string $message): array {
    if (BF_DEBUG_MODE) {
        echo $message;
    }
    
    // Write to log file if logging is enabled
    if (BF_DEBUG_LOGS) {
        static $log_file = null;
        static $log_handle = null;
        
        // Determine log file from current archive being processed
        if ($log_file === null) {
            global $argv;
            // Find archive path from command line arguments
            $archive_path = null;
            for ($i = 1; $i < count($argv); $i++) {
                if (isset($argv[$i]) && str_ends_with($argv[$i], '.rar')) {
                    $archive_path = $argv[$i];
                    break;
                }
            }
            
            if ($archive_path !== null) {
                $archive_basename = basename($archive_path);
                $log_file = __DIR__ . '/logs/' . $archive_basename . '.txt';
                
                // Delete existing log file and create new one
                if (file_exists($log_file)) {
                    unlink($log_file);
                }
                
                // Open file handle for writing
                $log_handle = fopen($log_file, 'w');
                if ($log_handle) {
                    fwrite($log_handle, "=== Run Time Log Started: " . date('Y-m-d H:i:s') . " ===\n");
                    fwrite($log_handle, "Archive: $archive_path\n\n");
                }
            }
        }
        
        // Write message to log file if we have a handle
        if ($log_handle) {
            // Strip ANSI color codes and control characters for log file
            $clean_message = preg_replace('/\033\[[0-9;]*[mK]/', '', $message);
            $clean_message = preg_replace('/\r/', '', $clean_message);
            
            // Add timestamp to log entries
            $timestamp = date('H:i:s');
            $log_entry = "[$timestamp] $clean_message";
            
            fwrite($log_handle, $log_entry);
            fflush($log_handle);
        }
    }

    return [
        'log_file' => $log_file,
        'log_handle' => $log_handle,
    ];
}

/**
 * Exit handler to close debug logs
 */
function debug_exit_handler(): void {
    if (BF_DEBUG_LOGS) {
        $result = debug_echo("=== Run Time Log Ended: " . date('Y-m-d H:i:s') . " ===\n");
        if (isset($result['log_handle']) && $result['log_handle']) {
            fclose($result['log_handle']);
        }
    }
}

// Register exit handler
register_shutdown_function('debug_exit_handler');

define('USAGE_TEXT', <<<USAGE
Usage:
  php {$argv[0]} commit <folder> <archive.rar> [comment] [-p password] [--follow-symlinks] [--low-priority]
  php {$argv[0]} system-backup <archive.rar> [comment] [-p password] [--follow-symlinks] [--low-priority]
  php {$argv[0]} list <archive.rar> [-p password]
  php {$argv[0]} checkout <snapshot_id> <archive.rar> <checkout_folder> [-p password] [--low-priority]
  php {$argv[0]} diff <version1> <version2> <archive.rar> [-p password]
  php {$argv[0]} status <folder> <archive.rar> [-p password] [--include-meta] [--checksum]
  php {$argv[0]} repair <archive.rar> [-p password] [--low-priority]

When creating a backup, you can optionally provide a comment describing the backup.
Comments are displayed when listing available versions.

Options:
  -p password          Password for archive encryption/access
  --follow-symlinks    Follow symbolic links and backup their contents
                       (default: symlinks are detected but not backed up)
  --force-directory    Force directory creation instead of symlink recreation during checkout
  --low-priority       Use lower CPU priority (nice level 10) to avoid
                       impacting other system processes
  --include-meta       Include metadata changes (permissions, ownership) in status command
  --checksum           Detect files with changed content but unchanged modification dates

Password can be provided via:
  - Command line argument: -p password

Examples:
  php {$argv[0]} commit /home/user/documents archive.rar "Daily Commit" -p password
  php {$argv[0]} commit /var/www/domain.com archive.rar "Website Commit" -p password --low-priority
  php {$argv[0]} commit /path/with/symlinks archive.rar "Symlink Commit" --follow-symlinks
  php {$argv[0]} system-backup archive.rar "System Backup" -p password
  php {$argv[0]} system-backup system-backup.rar "Web Server Backup" -p password --low-priority
  php {$argv[0]} system-backup system-backup.rar "System Backup with Symlinks" --follow-symlinks
  php {$argv[0]} list archive.rar -p password
  php {$argv[0]} checkout 1 archive.rar /checkout/path -p password --low-priority
  php {$argv[0]} checkout 1 archive.rar /checkout/path -p password --force-directory
  php {$argv[0]} diff 1 4 archive.rar -p password
  php {$argv[0]} status /home/user/documents archive.rar -p password --include-meta --checksum
  php {$argv[0]} repair archive.rar -p password --low-priority
  
USAGE);

define('README_TEXT', <<<README
This archive was created by bitfreeze.php.

To checkout or list contents, extract bitfreeze.php from this archive and run:

    php bitfreeze.php list <this-archive.rar>
    php bitfreeze.php checkout <snapshot_id> <this-archive.rar> <output-folder>

For example:
    rar e <this-archive.rar> bitfreeze.php
    php bitfreeze.php list <this-archive.rar>
    php bitfreeze.php checkout 1 <this-archive.rar> checked-out/

For full usage:
    php bitfreeze.php

---
Files are stored as content hashes in 'files/' and snapshot manifests in 'versions/'.
Comments are stored as .comment files alongside manifests.
README);

// Validate system requirements
$system_health = new SystemHealthManager();
if (!$system_health->validateSystemRequirements()) {
    exit(1);
}

/**
 * Main CLI dispatch
 * 
 * Parses the command line arguments, determines the command to execute,
 * and dispatches to the appropriate function. Handles command-specific
 * argument validation and error handling.
 * 
 *
 * Check if this file is being run directly (not included)
 * @return void
 */
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '')) {
    if (!defined('STDIN')) {
        echo "NOTICE: STDIN not defined, using php://stdin\n";
        define('STDIN', fopen('php://stdin', 'r'));
    }

    if ($argc < 2) {
        $command_manager = new CommandManager();
        $command_manager->showUsage();
    }

    $cmd            = $argv[1];
    $args           = new ArgumentHandler();

    // Initialize FileSystemManager for all CLI operations
    $fs_manager = new FileSystemManager();

    switch ($cmd) {
        case 'commit':
            if ($args->getCount() < 4 || $args->getCount() > 7) {
                $command_manager = new CommandManager();
                $command_manager->showUsage();
            }
            $cleaned = $args->getCleanedFromZero();
            
            // Initialize system manager first
            $system_manager = new SystemManager();
            
            // Convert all paths to absolute paths early and validate
            $folder = $system_manager->getAbsolutePath($cleaned[1]);
            $archive = $system_manager->getAbsolutePath($cleaned[2]);
            
            // Validate paths exist
            if (!$fs_manager->isDirectory($folder)) {
                echo "ERROR: Folder '$folder' does not exist.\n";
                exit(1);
            }
            
            if (!$fs_manager->directoryIsWritable(dirname($archive))) {
                echo "ERROR: Cannot write to archive location '$archive' (permission denied).\n";
                exit(1);
            }
            
            $comment = count($cleaned) >= 4 ? $cleaned[3] : "Automated Commit";
            
            // Use proper password detection - repository may already exist and be encrypted
            $password_manager = new PasswordManager();
            $password = $password_manager->getPasswordWithDetection($archive);
            
            $command_manager = new CommandManager();
            $command_manager->commit($folder, $archive, $comment, $password);
            break;
        case 'system-backup':
            if ($args->getCount() < 2 || $args->getCount() > 4) {
                $command_manager = new CommandManager();
                $command_manager->showUsage();
            }
            $cleaned = $args->getCleanedFromZero();
            
            // Initialize system manager first
            $system_manager = new SystemManager();
            
            // Convert archive path to absolute and validate
            $archive = $system_manager->getAbsolutePath($cleaned[1]);
            
            if (!$fs_manager->directoryIsWritable(dirname($archive))) {
                echo "ERROR: Cannot write to archive location '$archive' (permission denied).\n";
                exit(1);
            }
            
            $comment = count($cleaned) >= 3 ? $cleaned[2] : "System Backup";
            
            // Use proper password detection - repository may already exist and be encrypted
            $password_manager = new PasswordManager();
            $password = $password_manager->getPasswordWithDetection($archive);
            
            $command_manager = new CommandManager();
            $command_manager->systemBackup($archive, $comment, $password);
            break;
        case 'list':
            if ($args->getCount() < 2 || $args->getCount() > 4) {
                $command_manager = new CommandManager();
                $command_manager->showUsage();
            }
            $cleaned = $args->getCleanedFromZero();
            $system_manager = new SystemManager();
            
            // Check if we have enough arguments
            if (count($cleaned) < 2) {
                echo "ERROR: Archive path required for list command.\n";
                $command_manager = new CommandManager();
                $command_manager->showUsage();
                exit(1);
            }
            
            // Convert archive path to absolute and validate
            $archive = $system_manager->getAbsolutePath($cleaned[1]);
            
            if (!$fs_manager->fileExists($archive)) {
                echo "ERROR: Archive '$archive' does not exist.\n";
                exit(1);
            }
            
            // Use the same approach as the working reference code
            $password_manager = new PasswordManager();
            $password = $password_manager->getPasswordWithDetection($archive);
            
            $archive_manager = new ArchiveManager($password);
            $versions = $archive_manager->listVersions($archive);
            
            if (empty($versions)) {
                echo "No commits found.\n";
                return;
            }
            
            // Use header format for list display
            $display_manager = new DisplayManager();
            $display_manager->header("AVAILABLE COMMITS");
            
            // Create table data
            $table_data = [];
            foreach ($versions as $v) {
                $comment = $archive_manager->getCommentFromManifest($archive, $v['name']);
                $table_data[] = [
                    'ID' => $v['id'],
                    'Date/Time' => $v['ts'],
                    'Comment' => $comment
                ];
            }
            
            // Calculate column widths to match header width (60 characters)
            $header_width = 60;
            $id_width = 8;
            $date_width = 20;
            $comment_width = $header_width - $id_width - $date_width - 4; // 4 for separators
            
            $widths = [$id_width, $date_width, $comment_width];
            
            // Print table header
            echo str_pad('ID', $id_width) . '  ' . str_pad('Date/Time', $date_width) . '  ' . str_pad('Comment', $comment_width) . "\n";
            echo str_repeat('-', $header_width) . "\n";
            
            // Print table rows
            foreach ($table_data as $row) {
                $comment_display = strlen($row['Comment']) > $comment_width ? substr($row['Comment'], 0, $comment_width - 3) . '...' : $row['Comment'];
                echo str_pad($row['ID'], $id_width) . '  ' . str_pad($row['Date/Time'], $date_width) . '  ' . str_pad($comment_display, $comment_width) . "\n";
            }
            break;
        case 'checkout':
            if ($args->getCount() < 4 || $args->getCount() > 6) {
                $command_manager = new CommandManager();
                $command_manager->showUsage();
                exit(1);
            }

            $cleaned = $args->getCleanedFromZero();
            $system_manager = new SystemManager();
            
            
            // Convert all paths to absolute paths early and validate
            $commit_id = $cleaned[1];
            $repository = $system_manager->getAbsolutePath($cleaned[2]);
            $outdir = $system_manager->getAbsolutePath($cleaned[3]);
            
            // Validate paths exist
            if (!$fs_manager->fileExists($repository)) {
                echo "ERROR: Archive '$repository' does not exist.\n";
                exit(1);
            }
            
            if (!$fs_manager->directoryIsWritable(dirname($outdir))) {
                echo "ERROR: Cannot write to output directory '$outdir' (permission denied).\n";
                exit(1);
            }
            
            // Use proper password detection with automatic prompting for encrypted archives
            $password_manager = new PasswordManager();
            $password = $password_manager->getPasswordWithDetection($repository);
            
            $command_manager = new CommandManager();
            $command_manager->checkout($commit_id, $repository, $outdir, $password);
            break;
        case 'diff':
            if ($args->getCount() < 5 || $args->getCount() > 7) {
                $command_manager = new CommandManager();
                $command_manager->showUsage();
                exit(1);
            }

            $cleaned = $args->getCleanedFromZero();
            $system_manager = new SystemManager();
            
            // Convert archive path to absolute and validate
            $version1_id = $cleaned[1];
            $version2_id = $cleaned[2];
            $archive = $system_manager->getAbsolutePath($cleaned[3]);
            
            if (!$fs_manager->fileExists($archive)) {
                echo "ERROR: Archive '$archive' does not exist.\n";
                exit(1);
            }
            
            $password_manager = new PasswordManager();
            $password = $password_manager->getPasswordWithDetection($archive);

            $command_manager = new CommandManager();
            $command_manager->diff($version1_id, $version2_id, $archive, $password);
            break;
        case 'status':
            if ($args->getCount() < 3 || $args->getCount() > 5) {
                $command_manager = new CommandManager();
                $command_manager->showUsage();
                exit(1);
            }
            $cleaned = $args->getCleanedFromZero();
            $system_manager = new SystemManager();
            
            // Convert all paths to absolute paths early and validate
            $folder = $system_manager->getAbsolutePath($cleaned[1]);
            $archive = $system_manager->getAbsolutePath($cleaned[2]);
            
            // Validate paths exist
            if (!$fs_manager->isDirectory($folder)) {
                echo "ERROR: Folder '$folder' does not exist.\n";
                exit(1);
            }
            
            if (!$fs_manager->fileExists($archive)) {
                echo "ERROR: Archive '$archive' does not exist.\n";
                exit(1);
            }
            
            // Use proper password detection with automatic prompting for encrypted archives
            $password_manager = new PasswordManager();
            $password = $password_manager->getPasswordWithDetection($archive);
            
            $include_meta = $args->getFlag('--include-meta');
            $include_checksum = $args->getFlag('--checksum');
            $command_manager = new CommandManager();
            $command_manager->status($folder, $archive, $password, $include_meta, $include_checksum);
            break;
        case 'repair':
            if ($args->getCount() < 2 || $args->getCount() > 4) {
                $command_manager = new CommandManager();
                $command_manager->showUsage();
                exit(1);
            }
            $cleaned = $args->getCleanedFromZero();
            $system_manager = new SystemManager();
            
            // Convert archive path to absolute and validate
            $archive = $system_manager->getAbsolutePath($cleaned[1]);
            
            if (!$fs_manager->fileExists($archive)) {
                echo "ERROR: Archive '$archive' does not exist.\n";
                exit(1);
            }
            
            // Use proper password detection with automatic prompting for encrypted archives
            $password_manager = new PasswordManager();
            $password = $password_manager->getPasswordWithDetection($archive);
            
            $command_manager = new CommandManager();
            $command_manager->repair($archive, $password);
            break;
        default:
        $command_manager = new CommandManager();
        $command_manager->showUsage();
        break;
    } // End of switch statement
} // End of if statement for direct execution



