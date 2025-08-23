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
 * @version 1.0
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
define('BATCH_SIZE_PERCENTAGE', 30); // Use 30% of available temp disk space for batch processing
define('BF_DEBUG_MODE', true); // Set to false to disable all debug output

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
 */
function debug_echo(string $message): void {
    if (BF_DEBUG_MODE) {
        echo $message;
    }
}

// CLI helper functions removed - FileSystemManager should be used instead

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

if (!defined('STDIN')) {
    echo "NOTICE: STDIN not defined, using php://stdin\n";
    define('STDIN', fopen('php://stdin', 'r'));
}

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
        
        // Extract password from -p flag if present
        $password = null;
        for ($i = 0; $i < count($cleaned); $i++) {
            if ($cleaned[$i] === '-p' && isset($cleaned[$i + 1])) {
                $password = $cleaned[$i + 1];
                break;
            }
        }
        
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
        
        // Extract password from -p flag if present
        $password = null;
        for ($i = 0; $i < count($cleaned); $i++) {
            if ($cleaned[$i] === '-p' && isset($cleaned[$i + 1])) {
                $password = $cleaned[$i + 1];
                break;
            }
        }
        
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
        
        // Convert archive path to absolute and validate
        $archive = $system_manager->getAbsolutePath($cleaned[1]);
        
        if (!$fs_manager->fileExists($archive)) {
            echo "ERROR: Archive '$archive' does not exist.\n";
            exit(1);
        }
        
        // Extract password from -p flag if present
        $password = null;
        for ($i = 0; $i < count($cleaned); $i++) {
            if ($cleaned[$i] === '-p' && isset($cleaned[$i + 1])) {
                $password = $cleaned[$i + 1];
                break;
            }
        }
        
        $archive_manager = new ArchiveManager($password);
        $versions = $archive_manager->listVersions($archive);
        
        if (empty($versions)) {
            echo "No commits found in repository.\n";
            return;
        }
        
        $display = new DisplayManager();
        $display->header("COMMITS");
        
        foreach ($versions as $version) {
            $commit_display = $display->formatCommitDisplay($version);
            echo "Commit {$commit_display}\n";
        }
        break;
    case 'checkout':
        if ($args->getCount() < 4 || $args->getCount() > 5) {
        $command_manager = new CommandManager();
        $command_manager->showUsage();
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
        
        // Extract password from -p flag if present
        $password = null;
        for ($i = 0; $i < count($cleaned); $i++) {
            if ($cleaned[$i] === '-p' && isset($cleaned[$i + 1])) {
                $password = $cleaned[$i + 1];
                break;
            }
        }
        
        $command_manager = new CommandManager();
        $command_manager->checkout($commit_id, $repository, $outdir, $password);
        break;
    case 'diff':
        if ($args->getCount() !== 5) {
            $command_manager = new CommandManager();
            $command_manager->showUsage();
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
        
        // Extract password from -p flag if present
        $password = null;
        for ($i = 0; $i < count($cleaned); $i++) {
            if ($cleaned[$i] === '-p' && isset($cleaned[$i + 1])) {
                $password = $cleaned[$i + 1];
                break;
            }
        }
        
        $include_meta = $args->getFlag('--include-meta');
        $include_checksum = $args->getFlag('--checksum');
        $command_manager = new CommandManager();
        $command_manager->status($folder, $archive, $password, $include_meta, $include_checksum);
        break;
    case 'repair':
        if ($args->getCount() < 2 || $args->getCount() > 4) {
            $command_manager = new CommandManager();
            $command_manager->showUsage();
        }
        $cleaned = $args->getCleanedFromZero();
        $system_manager = new SystemManager();
        
        // Convert archive path to absolute and validate
        $archive = $system_manager->getAbsolutePath($cleaned[1]);
        
        if (!$fs_manager->fileExists($archive)) {
            echo "ERROR: Archive '$archive' does not exist.\n";
            exit(1);
        }
        
        // Extract password from -p flag if present
        $password = null;
        for ($i = 0; $i < count($cleaned); $i++) {
            if ($cleaned[$i] === '-p' && isset($cleaned[$i + 1])) {
                $password = $cleaned[$i + 1];
                break;
            }
        }
        
        $command_manager = new CommandManager();
        $command_manager->repair($archive, $password);
        break;
    default:
        $command_manager = new CommandManager();
        $command_manager->showUsage();
        break;
    } // End of switch statement
} // End of if statement for direct execution








