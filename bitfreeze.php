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

define('USAGE_TEXT', <<<USAGE
Usage:
  php {$argv[0]} commit <folder> <archive.rar> [comment] [-p password] [--follow-symlinks] [--low-priority]
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

/**
 * Terminal color and formatting functions
 * 
 * Provides ANSI color codes and formatting utilities for beautiful output
 */

// ANSI color codes
define('COLOR_RESET', "\033[0m");
define('COLOR_BOLD', "\033[1m");
define('COLOR_DIM', "\033[2m");
define('COLOR_UNDERLINE', "\033[4m");

// Foreground colors
define('COLOR_BLACK', "\033[30m");
define('COLOR_RED', "\033[31m");
define('COLOR_GREEN', "\033[32m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_BLUE', "\033[34m");
define('COLOR_MAGENTA', "\033[35m");
define('COLOR_CYAN', "\033[36m");
define('COLOR_WHITE', "\033[37m");

// Background colors
define('COLOR_BG_BLACK', "\033[40m");
define('COLOR_BG_RED', "\033[41m");
define('COLOR_BG_GREEN', "\033[42m");
define('COLOR_BG_YELLOW', "\033[43m");
define('COLOR_BG_BLUE', "\033[44m");
define('COLOR_BG_MAGENTA', "\033[45m");
define('COLOR_BG_CYAN', "\033[46m");
define('COLOR_BG_WHITE', "\033[47m");

if (!defined('STDIN')) {
    echo "NOTICE: STDIN not defined, using php://stdin\n";
    define('STDIN', fopen('php://stdin', 'r'));
}

/**
 * Check for required PHP functions and exit if any are missing
 * 
 * This function verifies that all necessary PHP functions are available
 * and that the rar command is installed and accessible before the script proceeds.
 * It checks system functions, file operations, string manipulation, and other
 * critical functions used throughout the script.
 * 
 * @return void
 */
function check_required_functions() {
    $required_functions = [
        'exec',
        'passthru',
        'escapeshellarg',
        'sys_get_temp_dir',
        'file_exists',
        'is_dir',
        'realpath',
        'mkdir',
        'copy',
        'file_put_contents',
        'file_get_contents',
        'unlink',
        'rmdir',
        'getcwd',
        'chdir',
        'microtime',
        'date',
        'uniqid',
        'mt_rand',
        'md5_file',
        'trim',
        'fgets',
        'getenv',
        'fileperms',
        'fileowner',
        'filegroup',
        'filemtime',
        'fileatime',
        'filectime',
        'chmod',
        'touch',
        'is_link',
        'readlink',
        'symlink',
        'lchown',
        'lchgrp'
    ];
    
    $missing_functions = [];

    foreach ($required_functions as $function) {
        if (!function_exists($function)) {
            $missing_functions[] = $function;
        }
    }
    
    if (!empty($missing_functions)) {
        echo "ERROR: Required PHP functions are not available:\n";
        foreach ($missing_functions as $function) {
            echo "  - $function()\n";
        }
        echo "\nPlease ensure these functions are enabled in your PHP installation.\n";
        exit(1);
    }
    
    // Check if rar command is available
    exec('which rar 2>/dev/null', $output, $code);

    if ($code !== 0) {
        echo "ERROR: RAR command is not available on the system.\n";
        echo "Please install RAR (rar/unrar) on your system:\n";
        echo "  - Ubuntu/Debian: sudo apt-get install rar unrar\n";
        echo "  - CentOS/RHEL: sudo yum install rar unrar\n";
        echo "  - macOS: brew install rar\n";
        echo "  - Windows: Download from https://www.rarlab.com/\n";
        exit(1);
    }
    
    // Test rar command functionality
    exec('rar 2>&1', $output, $code);

    if ($code === 127) {
        echo "ERROR: RAR command is installed but not functioning properly.\n";
        echo "Please ensure RAR is properly installed and accessible.\n";
        exit(1);
    }
} check_required_functions();

/**
 * Format file size with appropriate units
 * 
 * Converts bytes to human-readable format with appropriate units
 * (Bytes, KiloBytes, MegaBytes, GigaBytes, TeraBytes, PetaBytes) based on the size.
 * Uses number formatting and long unit names.
 * 
 * @param int $bytes Size in bytes
 * @param int $precision Number of decimal places (default: 2)
 * @return string Formatted size string
 */
function format_file_size($bytes, $precision = 2) {
    static $units = ['Bytes', 'KiloBytes', 'MegaBytes', 'GigaBytes', 'TeraBytes', 'PetaBytes'];
    static $thresholds = [
        1024,              // 1024
        pow(1024, 2),      // 1024*1024
        pow(1024, 3),      // 1024*1024*1024
        pow(1024, 4),      // 1024*1024*1024*1024
        pow(1024, 5),      // 1024*1024*1024*1024*1024
    ];
    
    // For very small sizes, just show bytes
    if ($bytes < 1024) {
        return number_format($bytes) . ' Bytes';
    }
    
    // Find the appropriate unit
    $unit_index = 0;
    $size = $bytes;
    
    for ($i = 0; $i < count($thresholds); $i++) {
        if ($bytes >= $thresholds[$i]) {
            $size = $bytes / $thresholds[$i];
            $unit_index = $i + 1;
        } else {
            break;
        }
    }
    
    return number_format($size, $precision) . ' ' . $units[$unit_index];
}

/**
 * Parse memory limit string to bytes
 * 
 * Converts memory limit strings like "128M", "2G", "512K" to bytes.
 * 
 * @param string $memory_limit Memory limit string (e.g., "128M", "2G", "512K")
 * @return int Memory limit in bytes
 */
function parse_memory_limit($memory_limit) {
    $memory_limit = trim($memory_limit);
    
    // Handle unlimited case
    if ($memory_limit === '-1' || strtolower($memory_limit) === 'unlimited') {
        return -1;
    }
    
    $last = strtolower($memory_limit[strlen($memory_limit) - 1]);
    $value = (int)substr($memory_limit, 0, -1);
    
    // Fallthrough switch, for 'G', multiply by 1024 three times; for 'M', twice; for 'K', once. (Converts to bytes)
    switch ($last) {
        case 'g': $value *= 1024;
        case 'm': $value *= 1024;
        case 'k': $value *= 1024;
    }
    
    return $value;
}

/**
 * Get memory usage statistics
 * 
 * @return array Memory usage data
 */
function get_memory_usage() {
    $memory_usage   = memory_get_usage(true);
    
    // memory_limit in bytes
    $limit_bytes    = parse_memory_limit(ini_get('memory_limit'));
    
    return [
        'current'       => $memory_usage,
        'peak'          => memory_get_peak_usage(true),
        'limit'         => $limit_bytes,
        'percentage'    => $limit_bytes > 0 ? ($memory_usage / $limit_bytes) * 100 : 0,
        'available'     => $limit_bytes > 0 ? $limit_bytes - $memory_usage : 0
    ];
}

/**
 * Check if --low-priority flag is present
 * 
 * @return bool True if --low-priority is present
 */
function has_low_priority_flag() {
    global $argv;
    return in_array('--low-priority', $argv);
}

/**
 * Get nice level based on command line arguments
 * 
 * @return int Nice level to use (1 for default, 10 for --low-priority)
 */
function get_nice_level() {
    return has_low_priority_flag() ? 10 : 1;
}

/**
 * Add nice level to RAR command
 * 
 * @param string $rar_cmd Base RAR command
 * @return string RAR command with nice level
 */
function add_nice_to_rar_command($rar_cmd) {
    return "nice -n " . get_nice_level() . " $rar_cmd";
}

/**
 * Get file metadata for manifest entry
 * 
 * Collects all relevant file metadata including permissions, ownership,
 * timestamps, and filesize. Returns data in a format suitable for manifest storage.
 * 
 * @param string $filepath Full path to the file
 * @return array Array containing metadata fields
 */
function get_file_metadata($filepath, $sudo_password = null) {
    // Try to stat() normally
    $stat = @stat($filepath);

    // If that fails and sudo is available, try stat via sudo
    if ($stat === false && $sudo_password !== null) {
        $escaped_filepath = escapeshellarg($filepath);
        $command = "stat -c '%a %u %g %Y %X %Z %s' $escaped_filepath";
        $output = [];
        exec("timeout 10 printf '%s\n' " . escapeshellarg($sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
        if ($code === 0 && !empty($output[0])) {
            $parts = explode(' ', trim($output[0]));
            if (count($parts) >= 7) {
                $stat = [
                    'mode'  => octdec($parts[0]),
                    'uid'   => (int)$parts[1],
                    'gid'   => (int)$parts[2],
                    'mtime' => (int)$parts[3],
                    'atime' => (int)$parts[4],
                    'ctime' => (int)$parts[5],
                    'size'  => (int)$parts[6]
                ];
            }
        }
    }

    // If still nothing, error/default
    if ($stat === false) {
        echo "\nMANIFEST ERROR: File $filepath does not exist or is not readable";
        if ($sudo_password !== null) echo " (sudo)";
        echo "\n";
        return [
            'permissions'   => '0644',
            'owner'         => 'unknown',
            'group'         => 'unknown',
            'mtime'         => time(),
            'atime'         => time(),
            'ctime'         => time(),
            'size'          => 0
        ];
    }

    // Metadata
    $permissions = substr(sprintf('%o', isset($stat['mode']) ? $stat['mode'] : @fileperms($filepath)), -4);

    $owner_id = isset($stat['uid']) ? $stat['uid'] : @fileowner($filepath);
    $group_id = isset($stat['gid']) ? $stat['gid'] : @filegroup($filepath);

    $owner = (is_numeric($owner_id) && function_exists('posix_getpwuid')) ?
        (posix_getpwuid($owner_id)['name'] ?? $owner_id) :
        ($owner_id !== false ? $owner_id : 'unknown');

    $group = (is_numeric($group_id) && function_exists('posix_getgrgid')) ?
        (posix_getgrgid($group_id)['name'] ?? $group_id) :
        ($group_id !== false ? $group_id : 'unknown');

    return [
        'permissions'   => $permissions,
        'owner'         => $owner,
        'group'         => $group,
        'mtime'         => $stat['mtime'],
        'atime'         => $stat['atime'],
        'ctime'         => $stat['ctime'],
        'size'          => $stat['size']
    ];
}

/**
 * Create enhanced manifest entry with metadata
 * 
 * Creates a manifest entry that includes file metadata alongside
 * the path and hash. Format: path\thash\tpermissions\towner\tgroup\tmtime\tatime\tctime\tsize
 * 
 * @param string $path Relative path to the file
 * @param string $hash MD5 hash of the file
 * @param string $fullpath Full path to the file for metadata collection
 * @return string Tab-delimited manifest entry
 */
function create_manifest_entry($path, $hash, $fullpath, $sudo_password = null) {
    $metadata = get_file_metadata($fullpath, $sudo_password);
    
    return implode("\t", [
        $path,
        $hash,
        $metadata['permissions'],
        $metadata['owner'],
        $metadata['group'],
        $metadata['mtime'],
        $metadata['atime'],
        $metadata['ctime'],
        $metadata['size']
    ]);
}

/**
 * Create directory manifest entry
 * 
 * Creates a manifest entry for directories with metadata.
 * Format: path\t[DIR]\tpermissions\towner\tgroup\tmtime\tatime\tctime\tsize
 * 
 * @param string $path Relative path to the directory
 * @param string $fullpath Full path to the directory for metadata collection
 * @return string Tab-delimited manifest entry
 */
function create_directory_entry($path, $fullpath, $sudo_password = null) {
    $metadata = get_file_metadata($fullpath, $sudo_password);
    
    return implode("\t", [
        $path,
        '[DIR]',
        $metadata['permissions'],
        $metadata['owner'],
        $metadata['group'],
        $metadata['mtime'],
        $metadata['atime'],
        $metadata['ctime'],
        $metadata['size']
    ]);
}

/**
 * Parse manifest entry with metadata support
 * 
 * Parses a manifest entry and returns structured data. Handles both
 * old format (path\thash) and new format with metadata.
 * Also handles symlink entries (path\t[LINK]\ttarget\tmetadata...)
 * 
 * @param string $line Manifest entry line
 * @return array|false Parsed entry or false if invalid
 */
function parse_manifest_entry($line) {
    $parts = explode("\t", $line);
    
    if (count($parts) < 2) {
        return false;
    }
    
    // Check if this is a symlink entry
    if ($parts[1] === '[LINK]') {
        return parse_symlink_entry($line);
    }
    
    $entry = [
        'path' => $parts[0],
        'hash' => $parts[1]
    ];
    
    $entry['metadata'] = [
        'permissions'   => $parts[2],
        'owner'         => $parts[3],
        'group'         => $parts[4],
        'mtime'         => (int)$parts[5],
        'atime'         => (int)$parts[6],
        'ctime'         => (int)$parts[7],
        'size'          => (int)$parts[8]
    ];

    return $entry;
}

/**
 * Check if user can elevate privileges
 * 
 * Tests if the current user can use sudo to elevate privileges.
 * 
 * @return bool True if sudo is available and user can use it
 */
function can_elevate_privileges() {
    // Check if sudo command exists
    exec('which sudo 2>/dev/null', $output, $code);
    if ($code !== 0) {
        return false;
    }
    
    // Test if user can run sudo (this will prompt for password if needed)
    exec('sudo -n true 2>/dev/null', $output, $code);
    return $code === 0;
}

/**
 * Prompt user for sudo password
 * 
 * Prompts the user for their sudo password to enable privilege elevation.
 * 
 * @return string|null The sudo password or null if cancelled
 */
function prompt_for_sudo_password($msg_type = 'read') {
    switch ($msg_type) {
        case 'read':
            echo "Access to some files/folders is restricted and requires root privileges.\n";
            break;
        case 'write':
            echo "Restoring ownership and permissions requires root privileges.\n";
            break;
    }

    echo "Enter your sudo password to continue (or press Enter to skip): ";
    
    // Hide input for security (only if we're in an interactive terminal)
    if (posix_isatty(STDIN)) {
        system('stty -echo');
        $password = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $password = trim(fgets(STDIN));
        echo "\n";
    }
    
    if (empty($password)) {
        echo "Skipping sudo access. Files will be processed with current user permissions.\n";
        return null;
    }
    
    return $password;
}

/**
 * Execute command with elevated privileges
 * 
 * Runs a command with sudo using the provided password.
 * 
 * @param string $command Command to execute
 * @param string $password Sudo password
 * @return bool True if successful, false otherwise
 */
function execute_with_sudo($command, $password) {
    $escaped_password   = escapeshellarg($password);
    
    // Use printf to pipe password to sudo without triggering the prompt
    // The -p option with empty string suppresses the prompt
    $full_command = "printf '%s\n' $escaped_password | sudo -p '' -S $command 2>/dev/null";
    
    exec($full_command, $output, $code);
    return $code === 0;
}

/**
 * Restore file with metadata
 * 
 * Restores a file with its original metadata including permissions,
 * ownership, and timestamps. Prompts for sudo password if needed.
 * 
 * @param string $source Source file path
 * @param string $dest Destination file path
 * @param array $metadata File metadata
 * @param string|null $sudo_password Sudo password for privilege elevation
 * @return bool True if successful, false otherwise
 */
function restore_file_with_metadata($source, $dest, $metadata, $sudo_password = null) {
    // Copy file content
    if (!copy($source, $dest)) {
        // Try with sudo, if password is available
        if ($sudo_password !== null) {
            $copy_cmd = "cp " . escapeshellarg($source) . " " . escapeshellarg($dest);
            if (!execute_with_sudo($copy_cmd, $sudo_password)) {
                echo "Notice: Failed to restore [{$dest}] (sudo)\n";
                return false; // sudo copy also failed
            }
        } else {
            echo "Notice: Failed to restore [{$dest}]\n";
            return false; // no sudo password, cannot proceed
        }
    }

    // Restore permissions
    if (isset($metadata['permissions'])) {
        @chmod($dest, octdec($metadata['permissions']));
    }
    
    // Restore ownership (only if running as root or sudo password provided)
    $is_root = function_exists('posix_getuid') && posix_getuid() === 0;
    
    if ($is_root || $sudo_password !== null) {
        if (isset($metadata['owner'])) {
            $owner_id = is_numeric($metadata['owner']) ? 
                $metadata['owner'] : 
                (function_exists('posix_getpwnam') ? posix_getpwnam($metadata['owner'])['uid'] : null);
            if ($owner_id !== null) {
                if ($is_root) {
                    @chown($dest, $owner_id);
                } else {
                    execute_with_sudo("chown $owner_id " . escapeshellarg($dest), $sudo_password);
                }
            }
        }
        
        if (isset($metadata['group'])) {
            $group_id = is_numeric($metadata['group']) ? 
                $metadata['group'] : 
                (function_exists('posix_getgrnam') ? posix_getgrnam($metadata['group'])['gid'] : null);
            if ($group_id !== null) {
                if ($is_root) {
                    @chgrp($dest, $group_id);
                } else {
                    execute_with_sudo("chgrp $group_id " . escapeshellarg($dest), $sudo_password);
                }
            }
        }
    }
    
    // Restore timestamps
    if (isset($metadata['mtime']) && isset($metadata['atime'])) {
        @touch($dest, $metadata['mtime'], $metadata['atime']);
    }
    
    return true;
}



/**
 * Display usage information and exit
 * 
 * Shows the command line syntax, available commands, and examples
 * for using the backup script. Exits with error code 1.
 * 
 * @return void
 */
function usage() {
    echo USAGE_TEXT;
    exit(1);
}

/**
 * Get absolute path for a given file path
 * 
 * Handles both relative and absolute paths.
 * Relative paths are made absolute from current directory.
 * 
 * @param string $path File path
 * @return string Absolute path to file
 */
function get_absolute_path($path) {
    // If already absolute (Unix or Windows), return as is
    if (
        strpos($path, DIRECTORY_SEPARATOR) === 0 || // Unix
        preg_match('/^[A-Za-z]:[\/\\\\]/', $path)   // Windows
    ) {
        return $path;
    }
    
    // Resolve ".." and "." segments in the path
    $fullPath   = getcwd() . DIRECTORY_SEPARATOR . $path;
    $parts      = []; // Array to build a new absolute path

    foreach (explode(DIRECTORY_SEPARATOR, $fullPath) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            array_pop($parts);
        } else {
            $parts[] = $part;
        }
    }

    return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
}

/**
 * Get password from command line arguments
 * 
 * Searches for the -p password argument in the command line arguments.
 * Returns null if no password is provided.
 * 
 * @return string|null The password or null if not provided
 */
function get_password() {
    global $argv;
    
    // Check for -p argument
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '-p') {
            // If -p is followed by a value, return it
            if (isset($argv[$i + 1]) && $argv[$i + 1][0] !== '-') {
                return $argv[$i + 1];
            }

            // If -p is provided without a value, return null
            return null;
        }
    }
    
    return null;
}

/**
 * Prompt user for encryption password interactively
 * 
 * Displays a password prompt for encryption and returns the entered password.
 * Handles hidden input for security.
 * 
 * @return string|null The password entered by user, or null if cancelled
 */
function prompt_for_encryption_password() {
    echo "Enter encryption password: ";
    
    // Hide input for security (only if we're in an interactive terminal)
    if (posix_isatty(STDIN)) {
        system('stty -echo');
        $password = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $password = trim(fgets(STDIN));
        echo "\n";
    }
    
    if (empty($password)) {
        echo "No password provided. Exiting.\n";
        exit(1);
    }
    
    return $password;
}

/**
 * Check if a RAR archive is encrypted
 * 
 * Attempts to list the archive contents without a password.
 * If the archive is encrypted, RAR will return an error code.
 * 
 * @param string $rarfile RAR archive file path
 * @return bool True if archive is encrypted, false otherwise
 */
function is_archive_encrypted($rarfile) {
    $rarfile_abs = get_absolute_path($rarfile);
    
    if (!file_exists($rarfile_abs)) {
        return false;
    }
    
    $file_size = filesize($rarfile_abs);
    if ($file_size === 0) {
        return false;
    }
    
    // Try to list archive contents without password, using an approach that won't trigger a password prompt
    $rar_cmd = 'printf "" | rar lb -inul ' . escapeshellarg($rarfile_abs) . ' 2>/dev/null';
    
    exec($rar_cmd, $output, $code);
    
    // RAR returns code 10 for password-protected archives
    // Exit code 255 can also indicate password protection when input is redirected
    return $code === 10 || $code === 255;
}

/**
 * Prompt user for password interactively
 * 
 * Displays a password prompt and returns the entered password.
 * Handles hidden input for security.
 * 
 * @param string $archive_name Name of the archive for the prompt
 * @return string|null The password entered by user, or null if cancelled
 */
function prompt_for_password($archive_name) {
    echo "Archive '$archive_name' is password protected.\n";
    echo "Enter password: ";
    
    // Hide input (only if we're in an interactive terminal)
    if (posix_isatty(STDIN)) {
        system('stty -echo');
        $password = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $password = trim(fgets(STDIN));
        echo "\n";
    }
    
    if (empty($password)) {
        echo "No password provided. Exiting.\n";
        exit(1);
    }
    
    return $password;
}

/**
 * Get password with encryption detection and retry logic
 * 
 * Gets password from command line, and if none provided,
 * checks if archive is encrypted and prompts user if needed.
 * Includes retry logic for incorrect passwords.
 * 
 * @param string $rarfile RAR archive file path
 * @return string|null The password or null if user cancels
 */
function get_password_with_detection($rarfile) {
    global $argv;
    
    $password = get_password();
    
    // Check if -p was provided without a value (indicating user wants to be prompted)
    $should_prompt = false;
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '-p') {
            // If -p is followed by a value, password was already returned
            if (isset($argv[$i + 1]) && $argv[$i + 1][0] !== '-') {
                break;
            }
            // If -p is provided without a value, we should prompt
            $should_prompt = true;
            break;
        }
    }
    
    // If no password provided or -p was used without value, check if archive is encrypted
    if ($password === null || $should_prompt) {
        if (file_exists($rarfile)) {
            // Archive exists - check if it's encrypted
            $is_encrypted = is_archive_encrypted($rarfile);
            if ($is_encrypted) {
                // Create a test function for this specific archive
                $test_function = function($test_password) use ($rarfile) {
                    return test_archive_password($rarfile, $test_password);
                };
                $password = prompt_for_password_with_retry(basename($rarfile), $test_function);
            }
        } else if ($should_prompt) {
            // Archive doesn't exist but -p was used without value - prompt for encryption password
            $password = prompt_for_encryption_password();
        }
    } else if ($password !== null && file_exists($rarfile) && is_archive_encrypted($rarfile)) {
        // If password was provided via command line, test it
        if (!test_archive_password($rarfile, $password)) {
            echo "Incorrect password for " . basename($rarfile) . "\n";
            // Create a test function for this specific archive
            $test_function = function($test_password) use ($rarfile) {
                return test_archive_password($rarfile, $test_password);
            };
            $password = prompt_for_password_with_retry(basename($rarfile), $test_function);
        }
    }
    
    return $password;
}

/**
 * Remove some arguments from argv for cleaner command parsing
 * 
 * Filters out the -p password arguments and --low-priority flag from the command line arguments
 * to simplify the argument parsing in the main command dispatch.
 * 
 * @return array Cleaned command line arguments
 */
function clean_argv() {
    global $argv;

    $cleaned    = [];
    $skip_next  = false;
    
    for ($i = 0; $i < count($argv); $i++) {
        if ($skip_next) {
            $skip_next = false;
            continue;
        }

        if ($argv[$i] === '-p') {
            // Skip the next argument only if it's not another option
            if (isset($argv[$i + 1]) && $argv[$i + 1][0] !== '-') {
                $skip_next = true;
            }
            continue;
        }

        // Skip --low-priority flag
        if ($argv[$i] === '--low-priority') {
            continue;
        }

        $cleaned[] = $argv[$i];
    }
    
    return $cleaned;
}

/**
 * Check if --follow-symlinks argument is present
 * 
 * Searches for the --follow-symlinks argument in the command line arguments.
 * 
 * @return bool True if --follow-symlinks is present
 */
function has_follow_symlinks() {
    global $argv;
    
    return in_array('--follow-symlinks', $argv);
}

/**
 * Check if --force-directory argument is present
 * 
 * Searches for the --force-directory argument in the command line arguments.
 * 
 * @return bool True if --force-directory is present
 */
function has_force_directory() {
    global $argv;
    
    return in_array('--force-directory', $argv);
}

/**
 * Create symlink manifest entry
 * 
 * Creates a manifest entry for symbolic links with metadata.
 * Format: path\t[LINK]\ttarget\tpermissions\towner\tgroup\tmtime\tatime\tctime\tsize
 * 
 * @param string $path Relative path to the symlink
 * @param string $fullpath Full path to the symlink for metadata collection
 * @return string Tab-delimited manifest entry
 */
function create_symlink_entry($path, $fullpath, $sudo_password = null) {
    $target = readlink($fullpath);
    $metadata = get_file_metadata($fullpath, $sudo_password);
    
    return implode("\t", [
        $path,
        '[LINK]',
        $target,
        $metadata['permissions'],
        $metadata['owner'],
        $metadata['group'],
        $metadata['mtime'],
        $metadata['atime'],
        $metadata['ctime'],
        $metadata['size']
    ]);
}

/**
 * Parse symlink manifest entry
 * 
 * Parses a symlink manifest entry and returns structured data.
 * 
 * @param string $line Manifest entry line
 * @return array|false Parsed entry or false if invalid
 */
function parse_symlink_entry($line) {
    $parts = explode("\t", $line);
    
    if (count($parts) < 10) {
        return false;
    }
    
    $entry = [
        'path'      => $parts[0],
        'type'      => $parts[1],
        'target'    => $parts[2]
    ];

    $entry['metadata'] = [
        'permissions'   => $parts[3],
        'owner'         => $parts[4],
        'group'         => $parts[5],
        'mtime'         => (int)$parts[6],
        'atime'         => (int)$parts[7],
        'ctime'         => (int)$parts[8],
        'size'          => (int)$parts[9]
    ];

    return $entry;
}

/**
 * Main CLI dispatch
 * 
 * Parses the command line arguments, determines the command to execute,
 * and dispatches to the appropriate function. Handles command-specific
 * argument validation and error handling.
 * 
 * @return void
 */
if (defined('TESTING')) {
    return; // Skip execution when testing
}

if ($argc < 2) {
    usage();
}

$cmd            = $argv[1];
$cleaned_argv   = clean_argv();

switch ($cmd) {
    case 'commit':
        if (count($cleaned_argv) < 4 || count($cleaned_argv) > 7) usage();
        $comment = count($cleaned_argv) >= 5 ? $cleaned_argv[4] : "Automated Commit";
        $password = get_password_with_detection(get_absolute_path($cleaned_argv[3]));
        commit($cleaned_argv[2], get_absolute_path($cleaned_argv[3]), $comment, $password);
        break;
    case 'list':
        if (count($cleaned_argv) !== 3) usage();
        $password = get_password_with_detection(get_absolute_path($cleaned_argv[2]));
        list_versions(get_absolute_path($cleaned_argv[2]), $password);
        break;
    case 'checkout':
        if (count($cleaned_argv) < 5 || count($cleaned_argv) > 6) usage();
        $repository = get_absolute_path($cleaned_argv[3]);
        $password = get_password_with_detection($repository);

        // exit if archive is encrypted but no password provided
        if ($password === null && file_exists($repository) && is_archive_encrypted($repository)) {
            echo "ERROR: Archive is password protected but no password provided.\n";
            echo "Use -p password to provide the password.\n";
            exit(1);
        }
        checkout($cleaned_argv[2], $repository, $cleaned_argv[4], $password);
        break;
    case 'diff':
        if (count($cleaned_argv) !== 5) usage();
        $password = get_password_with_detection(get_absolute_path($cleaned_argv[4]));
        diff_versions($cleaned_argv[2], $cleaned_argv[3], get_absolute_path($cleaned_argv[4]), $password);
        break;
    case 'status':
        if (count($cleaned_argv) < 4 || count($cleaned_argv) > 6) usage();
        $password = get_password_with_detection(get_absolute_path($cleaned_argv[3]));
        $include_meta = in_array('--include-meta', $cleaned_argv);
        $include_checksum = in_array('--checksum', $cleaned_argv);
        status($cleaned_argv[2], get_absolute_path($cleaned_argv[3]), $password, $include_meta, $include_checksum);
        break;
    case 'repair':
        if (count($cleaned_argv) !== 3) usage();
        $password = get_password_with_detection(get_absolute_path($cleaned_argv[2]));
        repair(get_absolute_path($cleaned_argv[2]), $password);
        break;
    default:
        usage();
}

/**
 * Generator function to scan directories recursively, handling permission errors gracefully
 * @param string $dir Directory to scan
 * @return Generator<string> Yields file paths
 */
function scanDirGenerator($dir, $sudo_password = null) {
    try {
        $files = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
        foreach ($files as $file) {
            if ($file->isDir()) {
                yield from scanDirGenerator($file->getPathname(), $sudo_password);
            } else {
                yield $file->getPathname();
            }
        }
    } catch (UnexpectedValueException $e) {
        // Permission denied - if we have sudo, try to access with elevated privileges
        if ($sudo_password !== null && can_elevate_privileges()) {
            // Try to list directory contents with sudo
            $escaped_dir = escapeshellarg($dir);
            $command = "sudo -S find $escaped_dir -type f 2>/dev/null";
            
            $output = [];
            $return_code = 0;
            exec("printf '%s\n' " . escapeshellarg($sudo_password) . " | $command", $output, $return_code);
            
            if ($return_code === 0) {
                // Successfully accessed with sudo, yield the files
                foreach ($output as $filepath) {
                    if (is_file($filepath)) {
                        yield $filepath;
                    }
                }
            }
        }
        // Otherwise, skip this directory silently
    }
}

/**
 * Generator function to scan directories recursively for directories only, handling permission errors gracefully
 * @param string $dir Directory to scan
 * @return Generator<string> Yields directory paths
 */
function scanDirGeneratorForDirs($dir, $sudo_password = null) {
    try {
        $files = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
        foreach ($files as $file) {
            if ($file->isDir()) {
                yield $file->getPathname();
                yield from scanDirGeneratorForDirs($file->getPathname(), $sudo_password);
            }
        }
    } catch (UnexpectedValueException $e) {
        // Permission denied - if we have sudo, try to access with elevated privileges
        if ($sudo_password !== null && can_elevate_privileges()) {
            // Try to list directory contents with sudo
            $escaped_dir = escapeshellarg($dir);
            $command = "sudo -S find $escaped_dir -type d 2>/dev/null";
            
            $output = [];
            $return_code = 0;
            exec("printf '%s\n' " . escapeshellarg($sudo_password) . " | $command", $output, $return_code);
            
            if ($return_code === 0) {
                // Successfully accessed with sudo, yield the directories
                foreach ($output as $dirpath) {
                    if (is_dir($dirpath)) {
                        yield $dirpath;
                    }
                }
            }
        }
        // Otherwise, skip this directory silently
    }
}

/**
 * Commit a new version of the specified folder to the archive
 * 
 * Scans the source folder, creates a manifest of all files and directories,
 * deduplicates files using MD5 hashes, and creates a new commit in the
 * RAR archive. Includes comment support and password protection.
 * 
 * @param string $folder Source folder to backup
 * @param string $rarfile RAR archive file path
 * @param string $comment Comment describing the backup
 * @param string|null $password Password for archive protection
 * @return void
 */
function commit($folder, $rarfile, $comment, $password = null) {
    global $argv;
    $folder = rtrim(realpath($folder), '/');

    if (!is_dir($folder)) {
        echo "ERROR: Folder '{$folder}' does not exist.\n";
        exit(1);
    }

    // Use provided comment or default
    if (empty($comment)) {
        $comment = "Automated Commit";
    }

    $start = microtime(true);

    // Setup temp workspace
    $temp           = sys_get_temp_dir() . '/rarrepo_' . uniqid(mt_rand(),true);
    $tmp_files      = "$temp/files";
    $tmp_versions   = "$temp/versions";

    mkdir($tmp_files, 0700, true);
    mkdir($tmp_versions, 0700, true);

    // Check if sudo permissions will be needed early
    $sudo_password = null;
    
    if (needs_sudo_permissions($folder)) {
        $sudo_password = prompt_for_sudo_password();
    }

    // First pass: Record files, parent dirs, and prepare deduplication
    $manifest           = [];
    $known_hashes       = get_archive_hashes($rarfile, $password);
    $seen_hashes        = [];
    $file_count         = 0;
    $add_count          = 0;
    $already_count      = 0;
    $skipped_count      = 0; // Track files skipped due to permission issues
    $skipped_files      = []; // Track which files were skipped
    $skipped_reasons    = []; // Track why files were skipped
    $parent_dirs        = [];
    $total_size         = 0; // Track total size of all files processed
    $symlink_count      = 0;
    $follow_symlinks    = has_follow_symlinks();
    
    // Get archive size before backup for comparison
    $archive_size_before = get_archive_size($rarfile);
    
    echo "\n";
    print_info("🔍 Scanning and calculating hashes. [$folder]");

    $processed_count = 0;
    foreach (scanDirGenerator($folder, $sudo_password) as $filepath) {
        $file       = new SplFileInfo($filepath);
        $fullpath   = $file->getPathname();
        $rel        = ltrim(substr($fullpath, strlen($folder)), '/');

        if ($file->isLink()) {
            // Handle symbolic links first (before checking isFile)
            $symlink_count++;
            
            if ($follow_symlinks) {
                // Enhanced symlink handling: store both symlink info AND target files
                $target_path = readlink($fullpath);
                $real_target = realpath($fullpath);
                
                // Always store the symlink information for checkout
                $manifest[] = create_symlink_entry($rel, $fullpath, $sudo_password);
                
                if ($real_target && file_exists($real_target)) {
                    // Check if target is within the backup scope
                    if (strpos($real_target, $folder) === 0) {
                        // Target is within backup scope, process it
                        $target_rel = ltrim(substr($real_target, strlen($folder)), '/');
                        
                        // Record parent directories
                        $parts = explode('/', $target_rel);
                        for ($i = 1; $i < count($parts); $i++) {
                            $dir = implode('/', array_slice($parts, 0, $i));
                            $parent_dirs[$dir] = 1;
                        }
                        
                        if (is_file($real_target)) {
                            // Try to get MD5 hash (sudo password already handled if needed)
                            $md5 = get_file_md5($real_target, $sudo_password);
                            if ($md5 === false) {
                                // Skip files we can't read
                                $skipped_count++;
                                $skipped_files[] = $target_rel;
                                $skipped_reasons[$target_rel] = "Symlink target MD5 calculation failed";
                                continue;
                            }
                            
                            // Get file size for tracking
                            $file_size = get_file_metadata($real_target, $sudo_password)['size'];
                            $total_size += $file_size;
                            
                            $manifest[] = create_manifest_entry($target_rel, $md5, $real_target, $sudo_password);
                            
                            if (isset($seen_hashes[$md5])) {
                                $already_count++;
                            } elseif (!isset($known_hashes[$md5])) {
                                if (copy_file($real_target, "$tmp_files/$md5", $sudo_password)) {
                                    $add_count++;
                                } else {
                                    // Skip files we can't copy
                                    $skipped_count++;
                                    $skipped_files[] = $target_rel;
                                    $skipped_reasons[$target_rel] = "Symlink target copy operation failed";
                                    continue;
                                }
                            } else {
                                $already_count++;
                            }
                            
                            $seen_hashes[$md5] = true;
                            $file_count++;
                        }
                    }
                }
            } else {
                // Store the symlink itself (not its target)
                $manifest[] = create_symlink_entry($rel, $fullpath, $sudo_password);
            }
        } elseif ($file->isFile()) {
            // Record all parent directories (for later use)
            $parts = explode('/', $rel);

            for ($i = 1; $i < count($parts); $i++) {
                $dir = implode('/', array_slice($parts, 0, $i));
                $parent_dirs[$dir] = 1; // Mark as containing at least one file
            }

            // Try to get MD5 hash (sudo password already handled if needed)
            $md5 = get_file_md5($fullpath, $sudo_password);
            if ($md5 === false) {
                // Skip files we can't read
                $skipped_count++;
                $skipped_files[] = $rel;
                $skipped_reasons[$rel] = "MD5 calculation failed";
                continue;
            }
            
            // Get file size for tracking
            $file_size = get_file_metadata($fullpath, $sudo_password)['size'];
            $total_size += $file_size;
            
            $manifest[] = create_manifest_entry($rel, $md5, $fullpath, $sudo_password);

            if (isset($seen_hashes[$md5])) {
                $already_count++;
            } elseif (!isset($known_hashes[$md5])) {
                if (copy_file($fullpath, "$tmp_files/$md5", $sudo_password)) {
                    $add_count++;
                } else {
                    // Skip files we can't copy
                    $skipped_count++;
                    $skipped_files[] = $rel;
                    $skipped_reasons[$rel] = "Copy operation failed";
                    continue;
                }
            } else {
                $already_count++;
            }

            $seen_hashes[$md5] = true;
            $file_count++;
            $processed_count++;

            if ($file_count % 10 === 0) {
                echo "\r" . colorize("  📁 Processed " . number_format($file_count) . " files...", COLOR_CYAN);
            }
        
        }
    }

    echo "\r" . colorize("  📁 Processed " . number_format($file_count) . " files...", COLOR_CYAN);
    
    // Second pass: capture ALL directories with their metadata
    $all_dirs = [];

    foreach (scanDirGeneratorForDirs($folder, $sudo_password) as $dirpath) {
        $rel = ltrim(substr($dirpath, strlen($folder)), '/');

        if ($rel === '') {
            continue; // skip root
        }

        $all_dirs[] = $rel;
    }

    foreach ($all_dirs as $dir) {
        $fullpath = $folder . '/' . $dir;
        $manifest[] = create_directory_entry($dir, $fullpath, $sudo_password);
    }

    // Add newline after progress updates
    if ($file_count > 0) {
        echo "\n";
    }

    print_success("✅ Scan complete!");
    
    // Display scan results in a table
    print_header("SCAN RESULTS");
    
    $scan_data = [
        ['Files Scanned', number_format($file_count)],
        ['Unique Files to Add', number_format($add_count)],
        ['Duplicate Files', number_format($already_count)],
        ['Skipped Files', number_format($skipped_count)],
        ['Directories Included', number_format(count($all_dirs))],
        ['Total Size', format_file_size($total_size)]
    ];
    
    if ($symlink_count > 0) {
        $symlink_text = $follow_symlinks ? "followed" : "stored as links";
        $scan_data[] = ['Symbolic Links', number_format($symlink_count) . " ($symlink_text)"];
    }
    
    $widths = [25, 20];
    foreach ($scan_data as $row) {
        print_table_row($row, $widths);
    }
    
    if ($skipped_count > 0) {
        echo "\n";
        print_warning("⚠️  Some files were skipped due to permission issues:");
        foreach (array_slice($skipped_files, 0, 5) as $skipped_file) {
            $reason = $skipped_reasons[$skipped_file] ?? "Unknown reason";
            echo colorize("    • $skipped_file ($reason)", COLOR_YELLOW) . "\n";
        }
        if (count($skipped_files) > 5) {
            echo colorize("    ... and " . (count($skipped_files) - 5) . " more", COLOR_YELLOW) . "\n";
        }
    }

    // Check if this commit is identical to the last one
    $last_manifest = get_last_manifest($rarfile, $password);
    $current_manifest_content = implode("\n", $manifest) . "\n";
    
    if ($last_manifest && $last_manifest['content'] === $current_manifest_content) {
        echo "Last Commit:      " . format_commit_display($last_manifest) . "\n";
        echo "Comment:          $comment\n\n";
        echo "NOTE: No changes detected since last commit. Exiting.\n";
        
        // Clean up temp
        exec('rm -rf ' . escapeshellarg($temp));
        return;
    }

    // Commit name/id
    $snapshot_id        = get_next_snapshot_id($rarfile, $password);
    $timestamp          = date('Y-m-d H:i:s');
    $manifest_filename  = "{$snapshot_id}-{$timestamp}.txt";
    $comment_filename   = "{$snapshot_id}-{$timestamp}.comment";
    $manifest_path      = "$tmp_versions/$manifest_filename";
    $comment_path       = "$tmp_versions/$comment_filename";
    $script_self        = realpath(__FILE__);

    // Write manifest with tab-delimiter
    file_put_contents($manifest_path, $current_manifest_content);

    // Write comment file
    file_put_contents($comment_path, $comment . "\n");

    // Place a copy of the script itself at archive root
    copy($script_self, "$temp/bitfreeze.php");

    // Generate README.txt at archive root
    file_put_contents("$temp/README.txt", README_TEXT);

    $cwd = getcwd();
    chdir($temp);
    $rar_cmd = 'rar a -r -rr' . RECOVERY_RECORD_SIZE . '% -m3';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' files versions bitfreeze.php README.txt';

    // Count files to be archived for progress tracking
    $files_to_archive = count_files_to_archive($temp);

    print_header("WRITING DATA");
    
    // Add nice level to RAR command
    $rar_cmd = add_nice_to_rar_command($rar_cmd);
    
    // Execute RAR with progress tracking
    $rar_success = execute_rar_with_progress($rar_cmd, $files_to_archive);
    chdir($cwd);

    if (!$rar_success) {
        print_error("❌ Failed to commit to RAR repository!");
        exec('rm -rf ' . escapeshellarg($temp));
        exit(1);
    }

    // Clean up temp
    exec('rm -rf ' . escapeshellarg($temp));

    $duration = round(microtime(true) - $start, 2);

    // Get archive size after backup for comparison
    $archive_size_after = get_archive_size($rarfile);
    $compression_stats = calculate_compression_stats($total_size, $archive_size_after);

    print_header("COMMIT SUMMARY");
    
    $summary_data = [
        ['Commit ID', $snapshot_id],
        ['Commit Manifest', $manifest_filename],
        ['Comment', $comment],
        ['Time Elapsed', format_duration($duration)],
        ['Files Scanned', number_format($file_count)],
        ['Total Size', format_file_size($total_size)],
        ['Unique Files Added', number_format($add_count)],
        ['Duplicate Files', number_format($already_count)],
        ['Files Skipped', number_format($skipped_count)],
        ['Directories Recorded', number_format(count($all_dirs))]
    ];
    
    if ($symlink_count > 0) {
        $symlink_text = $follow_symlinks ? "followed" : "stored as links";
        $summary_data[] = ['Symbolic Links', number_format($symlink_count) . " ($symlink_text)"];
    }
    
    if ($password) {
        $summary_data[] = ['Repository Encryption', 'Enabled'];
    }
    
    $widths = [25, 30];
    foreach ($summary_data as $row) {
        print_table_row($row, $widths);
    }
    
    // Display compression statistics
    print_header("REPOSITORY SIZE & COMPRESSION");
    
    $compression_data = [
        ['Original Size', $compression_stats['original_formatted']],
        ['Repository Size', $compression_stats['archive_formatted']],
    ];
    
    // Show size difference with appropriate label
    if ($compression_stats['difference'] >= 0) {
        $compression_data[] = ['Size Reduction', $compression_stats['difference_formatted']];
    } else {
        $compression_data[] = ['Size Increase', format_file_size(abs($compression_stats['difference']))];
    }
    
    $compression_data[] = ['Compression Ratio', $compression_stats['ratio_formatted']];
    
    foreach ($compression_data as $row) {
        print_table_row($row, $widths);
    }
    
    if ($skipped_count > 0) {
        echo "\n";
        print_warning("⚠️  Some files were skipped due to permission issues:");
        foreach (array_slice($skipped_files, 0, 5) as $skipped_file) {
            $reason = $skipped_reasons[$skipped_file] ?? "Unknown reason";
            echo colorize("    • $skipped_file ($reason)", COLOR_YELLOW) . "\n";
        }
        if (count($skipped_files) > 5) {
            echo colorize("    ... and " . (count($skipped_files) - 5) . " more", COLOR_YELLOW) . "\n";
        }
    }

    // Display resource usage summary
    // print_header("RESOURCE USAGE");
    // $memory = get_memory_usage();

    // $resource_data = [
    //     ['Peak Memory Usage', format_file_size($memory['peak'])],
    //     ['Memory Limit', format_file_size($memory['limit'])],
    //     ['CPU Priority', has_low_priority_flag() ? 'Low (nice 10)' : 'Reduced (nice 1)'],
    // ];

    // $widths = [25, 30];
    // foreach ($resource_data as $row) {
    //     print_table_row($row, $widths);
    // }

    exit(0);
}

/**
 * Status command - compare current folder state with latest repository commit
 * 
 * Analyzes the current state of a folder and compares it with the latest
 * commit in the repository to identify new, modified, deleted, metadata-changed, and corrupted files.
 * 
 * @param string $folder Path to the folder to analyze
 * @param string $rarfile Path to the RAR archive
 * @param string|null $password Password for archive access
 * @param bool $include_meta Whether to include metadata changes (permissions, ownership)
 * @param bool $include_checksum Whether to detect files with changed content but unchanged dates
 * @return void
 */
function status($folder, $rarfile, $password = null, $include_meta = false, $include_checksum = false) {
    $real_folder = realpath($folder);
    if ($real_folder === false) {
        echo "ERROR: Folder '{$folder}' does not exist.\n";
        exit(1);
    }
    $folder = rtrim($real_folder, '/');

    if (!is_dir($folder)) {
        echo "ERROR: Folder '{$folder}' does not exist.\n";
        exit(1);
    }

    if (!file_exists($rarfile)) {
        echo "ERROR: Archive '{$rarfile}' does not exist.\n";
        exit(1);
    }

    print_header("STATUS ANALYSIS");
    print_info("🔍 Analyzing folder: $folder");
    print_info("📦 Archive: $rarfile");

    // Check if sudo permissions will be needed early
    $sudo_password = null;
    
    if (needs_sudo_permissions($folder)) {
        $sudo_password = prompt_for_sudo_password();
    }

    // Get the latest manifest from the repository
    $latest_manifest = get_last_manifest($rarfile, $password);
    
    if (!$latest_manifest) {
        echo "ERROR: No commits found in repository.\n";
        exit(1);
    }

    print_info("📋 Latest " . format_commit_display($latest_manifest));

    // Extract the latest manifest to temp
    $temp = sys_get_temp_dir() . '/rarrepo_status_' . uniqid(mt_rand(), true);
    mkdir($temp, 0700, true);

    $rar_cmd = 'rar e -inul';
    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }
    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' ' . escapeshellarg($latest_manifest['name']) . ' ' . escapeshellarg($temp);

    exec($rar_cmd, $output, $code);

    if ($code !== 0) {
        echo "ERROR: Failed to extract manifest from archive.\n";
        exec('rm -rf ' . escapeshellarg($temp));
        exit(1);
    }

    $manifest_path = "$temp/" . basename($latest_manifest['name']);

    if (!file_exists($manifest_path)) {
        echo "ERROR: Manifest not extracted.\n";
        exec('rm -rf ' . escapeshellarg($temp));
        exit(1);
    }

    // Parse the manifest
    $manifest_lines = file($manifest_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $archive_files = [];
    
    foreach ($manifest_lines as $line) {
        $entry = parse_manifest_entry($line);
        if ($entry && isset($entry['path'])) {
            $archive_files[$entry['path']] = $entry;
        }
    }

    // Scan current folder
    $current_files = [];
    $current_dirs = [];
    
    foreach (scanDirGenerator($folder, $sudo_password) as $filepath) {
        $file = new SplFileInfo($filepath);
        $fullpath = $file->getPathname();
        $rel = ltrim(substr($fullpath, strlen($folder)), '/');
        
        if ($file->isFile()) {
            $current_files[$rel] = [
                'path' => $rel,
                'fullpath' => $fullpath,
                'is_file' => true
            ];
        } elseif ($file->isDir()) {
            $current_dirs[$rel] = true;
        }
    }

    // Add directories from manifest that don't exist in current scan
    foreach ($archive_files as $path => $entry) {
        if (isset($entry['hash']) && $entry['hash'] === '[DIR]') {
            $current_dirs[$path] = true;
        }
    }

    // Analyze changes
    $new_files = [];
    $modified_files = [];
    $deleted_files = [];
    $meta_changed_files = [];
    $checksum_changed_files = [];

    // Find new and modified files
    foreach ($current_files as $rel_path => $file_info) {
        if (!isset($archive_files[$rel_path])) {
            $new_files[] = $rel_path;
        } else {
            $archive_entry = $archive_files[$rel_path];
            
            // Check if file content has changed
            $current_md5 = get_file_md5($file_info['fullpath'], $sudo_password);
            if ($current_md5 !== false && $current_md5 !== $archive_entry['hash']) {
                // Check if this is a checksum change (content changed but date didn't)
                if ($include_checksum) {
                    $current_meta = get_file_metadata($file_info['fullpath'], $sudo_password);
                    $archive_meta = $archive_entry['metadata'];
                    
                    if ($current_meta['mtime'] === $archive_meta['mtime']) {
                        // Content changed but modification date is the same - possible corruption!
                        $checksum_changed_files[] = [
                            'path' => $rel_path,
                            'current_hash' => $current_md5,
                            'archive_hash' => $archive_entry['hash'],
                            'mtime' => $current_meta['mtime']
                        ];
                    } else {
                        // Normal modification - content and date both changed
                        $modified_files[] = $rel_path;
                    }
                } else {
                    // Checksum detection not enabled, treat as normal modification
                    $modified_files[] = $rel_path;
                }
            } elseif ($include_meta) {
                // Check metadata changes
                $current_meta = get_file_metadata($file_info['fullpath'], $sudo_password);
                $archive_meta = $archive_entry['metadata'];
                
                if ($current_meta['permissions'] !== $archive_meta['permissions'] ||
                    $current_meta['owner'] !== $archive_meta['owner'] ||
                    $current_meta['group'] !== $archive_meta['group']) {
                    
                    $meta_changed_files[] = [
                        'path' => $rel_path,
                        'current' => $current_meta,
                        'archive' => $archive_meta
                    ];
                }
            }
        }
    }

    // Find deleted files
    foreach ($archive_files as $rel_path => $entry) {
        if (isset($entry['hash']) && $entry['hash'] !== '[DIR]' && !isset($current_files[$rel_path])) {
            $deleted_files[] = $rel_path;
        }
    }

    // Display results
    print_header("CHANGES DETECTED");

    // New files
    if (!empty($new_files)) {
        print_header("NEW FILES", "-", 50);
        foreach ($new_files as $file) {
            echo "  + $file\n";
        }
    } else {
        print_header("NEW FILES", "-", 50);
        echo "  ✅ No new files\n";
    }

    // Modified files
    if (!empty($modified_files)) {
        print_header("MODIFIED FILES", "-", 50);
        foreach ($modified_files as $file) {
            echo "  ~ $file\n";
        }
    } else {
        print_header("MODIFIED FILES", "-", 50);
        echo "  ✅ No modified files\n";
    }

    // Deleted files
    if (!empty($deleted_files)) {
        print_header("DELETED FILES", "-", 50);
        foreach ($deleted_files as $file) {
            echo "  - $file\n";
        }
    } else {
        print_header("DELETED FILES", "-", 50);
        echo "  ✅ No deleted files\n";
    }

    // Metadata changes
    if ($include_meta && !empty($meta_changed_files)) {
        print_header("METADATA CHANGES", "-", 50);
        foreach ($meta_changed_files as $change) {
            echo "  🔄 {$change['path']}\n";
            
            $current = $change['current'];
            $archive = $change['archive'];
            
            if ($current['permissions'] !== $archive['permissions']) {
                echo "     Permissions: {$archive['permissions']} → {$current['permissions']}\n";
            }
            if ($current['owner'] !== $archive['owner']) {
                echo "     Owner: {$archive['owner']} → {$current['owner']}\n";
            }
            if ($current['group'] !== $archive['group']) {
                echo "     Group: {$archive['group']} → {$current['group']}\n";
            }
            echo "\n";
        }
    } elseif ($include_meta) {
        print_header("METADATA CHANGES", "-", 50);
        echo "  ✅ No metadata changes\n";
    }

    // Checksum changes (content changed but date didn't)
    if ($include_checksum && !empty($checksum_changed_files)) {
        // Custom red header for checksum changes
        $header_text = "CHECKSUM CHANGES";
        $line_length = max(50, strlen($header_text) + 4);
        $line = str_repeat('!', $line_length);
        $padding = $line_length - strlen($header_text);
        $left = floor($padding / 2);
        $right = $padding - $left;
        $centered_text = str_repeat(' ', $left - 1) . $header_text . str_repeat(' ', $right - 1);
        
        echo "\n" . colorize($line, COLOR_RED) . "\n";
        echo colorize($centered_text, COLOR_BOLD . COLOR_RED) . "\n";
        echo colorize($line, COLOR_RED) . "\n\n";
        
        print_warning("⚠️  Files with changed content but unchanged modification dates:");
        foreach ($checksum_changed_files as $change) {
            echo colorize("  🔍 {$change['path']}", COLOR_YELLOW) . "\n";
            echo colorize("     Current Hash: {$change['current_hash']}", COLOR_YELLOW) . "\n";
            echo colorize("     Archive Hash: {$change['archive_hash']}", COLOR_YELLOW) . "\n";
            echo colorize("     Modification Date: " . date('Y-m-d H:i:s', $change['mtime']), COLOR_YELLOW) . "\n\n";
        }
    } elseif ($include_checksum) {
        print_header("CHECKSUM CHANGES", "!", 50);
        echo "  ✅ No checksum changes detected\n";
    }

    // Summary
    print_header("SUMMARY");
    $summary_data = [
        ['New Files', count($new_files)],
        ['Modified Files', count($modified_files)],
        ['Deleted Files', count($deleted_files)]
    ];
    
    if ($include_meta) {
        $summary_data[] = ['Metadata Changes', count($meta_changed_files)];
    }
    
    if ($include_checksum) {
        $summary_data[] = ['Checksum Changes', count($checksum_changed_files)];
    }
    
    $summary_data[] = ['Total Changes', count($new_files) + count($modified_files) + count($deleted_files) + ($include_meta ? count($meta_changed_files) : 0) + ($include_checksum ? count($checksum_changed_files) : 0)];
    
    $widths = [20, 10];
    foreach ($summary_data as $row) {
        print_table_row($row, $widths);
    }

    // Clean up
    exec('rm -rf ' . escapeshellarg($temp));
}

/**
 * Get existing file hashes from the RAR archive
 * 
 * Lists all files in the archive's 'files/' directory and returns
 * an associative array mapping MD5 hashes to true for deduplication.
 * 
 * @param string $rarfile RAR archive file path
 * @param string|null $password Password for archive access
 * @return array Associative array of existing MD5 hashes
 */
function get_archive_hashes($rarfile, $password = null) {
    $out = [];

    if (!file_exists($rarfile)) {
        return $out;
    }

    $rar_cmd = 'rar lb';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' files/';

    // Add input redirection to prevent password prompts
    $rar_cmd .= ' </dev/null 2>/dev/null';

    exec($rar_cmd, $lines, $code);

    // If command failed for any reason, return empty array
    if ($code !== 0) {
        return $out;
    }

    foreach ($lines as $line) {
        if (preg_match('#^files/([a-f0-9]{32})$#', trim($line), $m)) {
            $out[$m[1]] = true;
        }
    }

    return $out;
}

/**
 * Get the next available commit ID
 * 
 * Scans existing commits in the archive and returns the next
 * available ID number for creating a new commit.
 * 
 * @param string $rarfile RAR archive file path
 * @param string|null $password Password for archive access
 * @return int Next available commit ID
 */
function get_next_snapshot_id($rarfile, $password = null) {
    $max = 0;

    if (!file_exists($rarfile)) {
        return 1;
    }
    
    $rar_cmd = 'rar lb';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' versions/';
    
    // Add input redirection to prevent password prompts
    $rar_cmd .= ' </dev/null 2>/dev/null';
    
    exec($rar_cmd, $lines, $code);
    
    // If command failed for any reason, return 1 (new archive)
    if ($code !== 0) {
        return 1;
    }

    foreach ($lines as $line) {
        if (preg_match('/^versions\/(\d+)-/', $line, $m)) {
            $v = (int) $m[1];

            if ($v > $max) {
                $max = $v;
            }
        }
    }

    return $max + 1;
}

/**
 * List all available commits
 * 
 * Extracts and displays all commits in the repository with their
 * IDs, timestamps, and comments. Shows most recent commits first.
 * 
 * @param string $rarfile RAR repository file path
 * @param string|null $password Password for repository access
 * @return void
 */
function list_versions($rarfile, $password = null) {
    if (!file_exists($rarfile)) {
        echo "Repository not found.\n";
        exit(1);
    }

    $rar_cmd = 'rar lb';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' versions/';

    // Add input redirection to prevent password prompts
    $rar_cmd .= ' </dev/null 2>/dev/null';

    exec($rar_cmd, $lines, $code);

    // Check if command failed due to missing password
    if ($code !== 0) {
        if ($code === 10 || $code === 255) {
            echo "ERROR: Repository is password protected but no password provided.\n";
            echo "Use -p password to provide the password.\n";
        } else {
            echo "ERROR: Failed to access repository (exit code: $code).\n";
        }

        exit(1);
    }

    // Parse and collect (id, timestamp, full_name)
    $versions = [];

    foreach ($lines as $line) {
        if (preg_match('/^versions\/(\d+)-([\d\-: ]+)\.txt$/', $line, $m)) {
            $versions[] = [
                'id'            => (int)$m[1],
                'ts'            => $m[2],
                'file'          => $line,
                'comment_file'  => "versions/{$m[1]}-{$m[2]}.comment"
            ];
        }
    }

    // Order by timestamp DESC
    usort($versions, fn($a, $b) => strcmp($b['ts'], $a['ts']));

    if (!$versions) {
        echo "No commits found.\n";
        return;
    }
    
    echo "Available Commits (most recent first):\n";
    echo "ID    Date/Time           Comment\n";
    echo "----------------------------------------\n";
    
    foreach ($versions as $v) {
        $comment = get_comment_from_archive($rarfile, $v['comment_file'], $password);
        $comment_display = strlen($comment) > 40 ? substr($comment, 0, 37) . '...' : $comment;
        echo str_pad($v['id'], 4) . "  " . str_pad($v['ts'], 19) . "  $comment_display\n";
    }
}

/**
 * Extract and read comment file from archive
 * 
 * Extracts a specific comment file from the archive and returns
 * its contents. Used for displaying comments in the list command.
 * 
 * @param string $rarfile RAR archive file path
 * @param string $comment_file Path to comment file in archive
 * @param string|null $password Password for archive access
 * @return string Comment text or "No comment" if not found
 */
function get_comment_from_archive($rarfile, $comment_file, $password = null) {
    $temp = sys_get_temp_dir() . '/rarrepo_comment_' . uniqid(mt_rand(), true);

    mkdir($temp, 0700, true);
    
    // Try to extract the comment file
    $rar_cmd = 'rar e -inul';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' ' . escapeshellarg($comment_file) . ' ' . escapeshellarg($temp);
    
    exec($rar_cmd, $output, $code);
    
    // If command failed for any reason, return "No comment"
    if ($code !== 0) {
        exec('rm -rf ' . escapeshellarg($temp));
        return "No comment";
    }
    
    $comment_path = "$temp/" . basename($comment_file);

    if (file_exists($comment_path)) {
        $comment = trim(file_get_contents($comment_path));
        exec('rm -rf ' . escapeshellarg($temp));
        return $comment;
    } else {
        exec('rm -rf ' . escapeshellarg($temp));
        return "No comment";
    }
}

/**
 * Check if a symlink exists and points to the target
 * 
 * @param string $link Path to the symlink
 * @param string $target Path to the target
 * @return bool
 */
function symlink_exists_and_points_to($link, $target) {
    return is_link($link) && (readlink($link) === $target);
}

/**
 * Checkout a specific commit to a directory
 * 
 * Extracts the specified commit from the repository and reconstructs
 * the file tree in the output directory. Handles directory structure
 * restoration.
 * 
 * @param int $snapshot_id ID of the commit to checkout
 * @param string $rarfile RAR repository file path
 * @param string $outdir Output directory for checkout
 * @param string|null $password Password for repository access
 * @return void
 */
function checkout($snapshot_id, $rarfile, $outdir, $password = null) {
    $parent = dirname($outdir);

    $encrypted      = is_archive_encrypted($rarfile) ? 'ENCRYPTED' : '';
    $low_priority   = has_low_priority_flag() ? 'LOW PRIORITY' : '';

    print_header("CHECKOUT $encrypted COMMIT $snapshot_id $low_priority");

    if (!is_writable($parent)) {
        echo "ERROR: Cannot checkout to $outdir: Permission denied\n";
        exit(1);
    }

    if (!file_exists($rarfile)) {
        echo "Repository not found.\n";
        exit(1);
    }

    $manifest = find_manifest_for_snapshot($rarfile, $snapshot_id, $password);

    // Check for password errors
    if (is_array($manifest) && isset($manifest['error']) && $manifest['error'] === 'password') {
        echo "ERROR: Repository is password protected but no password provided.\n";
        echo "Use -p password to provide the password.\n";
        exit(1);
    }

    if (!$manifest) {
        echo "Commit ID $snapshot_id not found.\n";
        exit(1);
    }

    echo "Preparing commit $snapshot_id for checkout.\n";
    echo "Manifest: " . str_replace('versions/', '', $manifest['name']) . "\n";
    
    // Check if we need sudo for permission restoration
    $sudo_password = null;
    $is_root = function_exists('posix_getuid') && posix_getuid() === 0;
    
    if (!$is_root && can_elevate_privileges()) {
        $sudo_password = prompt_for_sudo_password('write');
    }

    $temp = sys_get_temp_dir() . '/rarrepo_' . uniqid(mt_rand(),true);

    mkdir($temp, 0700, true);

    // 1. Extract manifest
    $rar_cmd = 'rar e ';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' ' . escapeshellarg($manifest['name']) . ' ' . escapeshellarg($temp);

    // Add nice level to RAR command
    $rar_cmd = add_nice_to_rar_command($rar_cmd);
    
    // Execute RAR extraction without progress tracking for manifest
    exec($rar_cmd, $output1, $code1);

    $manifest_path = "$temp/" . basename($manifest['name']);

    if (!file_exists($manifest_path)) {
        if ($code1 === 10 || $code1 === 255) {
            echo "ERROR: Archive is password protected but no password provided.\n";
            echo "Use -p password to provide the password.\n";
        } else {
            echo "ERROR: Manifest not extracted (exit code: $code1).\n";
        }
        exec('rm -rf ' . escapeshellarg($temp));
        exit(1);
    }

    // 2. Gather needed hashes and filepaths
    $hashmap        = []; // hash => [path1, path2,...]
    $restorelist    = [];
    $dir_metadata   = []; // Store directory metadata for restoration after files
    $symlink_fallback_dirs = []; // Track symlinks that fell back to directories
    $lines          = file($manifest_path, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    
    // Get current user info for permission restoration
    $is_root = function_exists('posix_getuid') && posix_getuid() === 0;

    foreach ($lines as $l) {
        $entry = parse_manifest_entry($l);
        
        if (!$entry) {
            continue;
        }

        if (isset($entry['hash']) && $entry['hash'] === '[DIR]') {
            $dir = rtrim($outdir, '/') . '/' . $entry['path'];

            if (!is_dir($dir)) {
                // Use system mkdir command to avoid PHP mkdir timestamp issues
                exec("mkdir -p -m 0755 " . escapeshellarg($dir));
            }
            
            // Store directory metadata for restoration after all files are processed
            if (isset($entry['metadata'])) {
                $dir_metadata[] = [
                    'dir'       => $dir,
                    'metadata'  => $entry['metadata']
                ];
            }

            continue;
        } elseif (isset($entry['type']) && $entry['type'] === '[LINK]') {
            // Enhanced symlink handling with fallback to directory
            $link_path  = rtrim($outdir, '/') . '/' . $entry['path'];
            $link_dir   = dirname($link_path);
            $force_dir  = has_force_directory();

            if (!is_dir($link_dir)) {
                mkdir($link_dir, 0755, true);
            }

            $symlink_created = false;
            
            if (!$force_dir) {
                // Attempt to recreate the symlink
                if (!symlink_exists_and_points_to($link_path, $entry['target'])) {
                    // Try native symlink
                    if (!@symlink($entry['target'], $link_path)) {
                        $escaped_target = escapeshellarg($entry['target']);
                        $escaped_link_path = escapeshellarg($link_path);

                        if ($sudo_password !== null) {
                            // Try with sudo
                            $result = execute_with_sudo("ln -s $escaped_target $escaped_link_path", $sudo_password);
                        } else {
                            // Try with direct exec
                            exec("ln -s $escaped_target $escaped_link_path", $output, $ln_result);
                        }
                    }
                }

                if (symlink_exists_and_points_to($link_path, $entry['target'])) {
                    $symlink_created = true;
                    print_success("✅ Symlink created: {$entry['path']} -> {$entry['target']}");
                }
            }

            // If symlink was created, restore metadata
            if ($symlink_created && isset($entry['metadata'])) {
                $owner_id = is_numeric($entry['metadata']['owner']) ?
                    $entry['metadata']['owner'] :
                    (function_exists('posix_getpwnam') ? (posix_getpwnam($entry['metadata']['owner'])['uid'] ?? null) : null);

                $group_id = is_numeric($entry['metadata']['group']) ?
                    $entry['metadata']['group'] :
                    (function_exists('posix_getgrnam') ? (posix_getgrnam($entry['metadata']['group'])['gid'] ?? null) : null);

                if ($owner_id !== null) {
                    if ($is_root) {
                        exec("lchown " . escapeshellarg($owner_id) . " " . escapeshellarg($link_path));
                    } elseif ($sudo_password !== null) {
                        execute_with_sudo("lchown $owner_id " . escapeshellarg($link_path), $sudo_password);
                    }
                }
                if ($group_id !== null) {
                    if ($is_root) {
                        exec("lchgrp " . escapeshellarg($group_id) . " " . escapeshellarg($link_path));
                    } elseif ($sudo_password !== null) {
                        execute_with_sudo("lchgrp $group_id " . escapeshellarg($link_path), $sudo_password);
                    }
                }
            }
            
            if (!$symlink_created) {
                // Symlink creation failed or --force-directory was used
                if ($force_dir) {
                    print_warning("⚠️  Force directory mode: Creating directory instead of symlink for {$entry['path']}");
                } else {
                    print_warning("⚠️  Symlink creation failed for {$entry['path']} -> {$entry['target']}");
                    print_warning("   Falling back to directory creation. Files will be restored directly.");
                }
                
                // Create directory for file restoration
                if (!is_dir($link_path)) {
                    mkdir($link_path, 0755, true);
                }
                
                // Mark this path as a directory for file restoration
                $symlink_fallback_dirs[$entry['path']] = true;
            }
            
            continue;
        }

        // Only add file entries to hashmap and restorelist (not symlinks or directories)
        if (isset($entry['hash']) && $entry['hash'] !== '[DIR]') {
            $hashmap[$entry['hash']][] = $entry['path'];
            $restorelist[] = [$entry['path'], $entry['hash'], $entry['metadata'] ?? null];
        }
    }

    echo "\n";
    echo "Commit $snapshot_id contains " . number_format(count($restorelist)) . " files (" . number_format(count($hashmap)) . " unique contents).\n";

    // 3. Extract all needed hashes (content blobs) to temp dir
    $all_hashes = array_keys($hashmap);
    $exlist     = [];

    foreach ($all_hashes as $h) {
        $exlist[] = "files/$h";
    }

    // Prepare extraction list file
    $exlistfile = "$temp/extract.lst";

    file_put_contents($exlistfile, implode("\n", $exlist) . "\n");
    echo "Preparing to regenerate " . number_format(count($all_hashes)) . " files from repository...\n";
    
    $rar_cmd = 'rar e';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' @"' . $exlistfile . '" ' . escapeshellarg($temp);
    
    // Add nice level to RAR command
    $rar_cmd = add_nice_to_rar_command($rar_cmd);

    print_header("READING DATA");
    echo colorize("⏳ Please wait...", COLOR_CYAN) . "\n";

    // Execute RAR extraction with progress tracking
    $code2 = execute_rar_extract_with_progress($rar_cmd, count($all_hashes)) ? 0 : 1;

    echo "\n";

    // 4. Reconstruct file tree in outdir
    $restored   = 0;
    $errors     = 0;
    $count      = count($restorelist);

    foreach ($restorelist as $n => [$path, $md5, $metadata]) {
        $src        = "$temp/$md5";
        $dest       = rtrim($outdir, '/') . '/' . $path;
        $destdir    = dirname($dest);

        // Check if this file should be restored to a symlink fallback directory
        $symlink_fallback_path = null;
        foreach ($symlink_fallback_dirs as $fallback_dir => $dummy) {
            if (strpos($path, $fallback_dir . '/') === 0) {
                // This file belongs to a symlink that fell back to directory
                $symlink_fallback_path = $fallback_dir;
                break;
            }
        }

        // Only create parent directories if they don't exist
        // This prevents overriding directory metadata that was already restored
        if (!is_dir($destdir)) {
            // Use system mkdir to avoid PHP mkdir timestamp issues
            $escaped_destdir = escapeshellarg($destdir);
            exec("mkdir -p -m 0755 $escaped_destdir");
        }

        if (file_exists($src)) {
            if ($metadata) {
                // Use enhanced restoration with metadata
                if (!restore_file_with_metadata($src, $dest, $metadata, $sudo_password)) {
                    echo "[ERROR] Could not checkout $path\n";
                    $errors++;
                } else {
                    $restored++;
                }
            } else {
                // Fallback to basic restoration for old format
                if (!copy($src, $dest)) {
                    echo "[ERROR] Could not checkout $path\n";
                    $errors++;
                } else {
                    $restored++;
                }
            }
        } else {
            echo "[ERROR] Missing blob for $path (hash $md5)\n";
            $errors++;
        }

        if (($n+1) % 10 === 0) {
            echo "\rFinalizing " . number_format($n+1) . " of " . number_format($count) . " files";
        }
    }

    echo "\rFinalizing " . number_format($n+1) . " of " . number_format($count) . " files";
    echo "\n";

    // Restore directory metadata after all files are processed
    foreach ($dir_metadata as $dm) {
        $dir = $dm['dir'];
        $metadata = $dm['metadata'];
        
        if (is_dir($dir)) {
            // Always restore permissions (this should work for directories we own)
            chmod($dir, octdec($metadata['permissions']));
            
            // Try to restore ownership - this will work if we own the directory
            // or if we have sudo privileges
            $owner_id = is_numeric($metadata['owner']) ? 
                $metadata['owner'] : 
                (function_exists('posix_getpwnam') ? posix_getpwnam($metadata['owner'])['uid'] : null);
            $group_id = is_numeric($metadata['group']) ? 
                $metadata['group'] : 
                (function_exists('posix_getgrnam') ? posix_getgrnam($metadata['group'])['gid'] : null);
            
            if ($owner_id !== null) {
                if ($is_root) {
                    chown($dir, $owner_id);
                } elseif ($sudo_password !== null) {
                    execute_with_sudo("chown $owner_id " . escapeshellarg($dir), $sudo_password);
                } else {
                    // Try to change ownership even without sudo (might work if we own the directory)
                    @chown($dir, $owner_id);
                }
            }

            if ($group_id !== null) {
                if ($is_root) {
                    chgrp($dir, $group_id);
                } elseif ($sudo_password !== null) {
                    execute_with_sudo("chgrp $group_id " . escapeshellarg($dir), $sudo_password);
                } else {
                    // Try to change group even without sudo (might work if we own the directory)
                    @chgrp($dir, $group_id);
                }
            }
            
            // Always restore timestamps LAST - use PHP touch for consistency with file restoration
            // This must be done after all other operations that might modify the timestamp
            if (isset($metadata['mtime']) && isset($metadata['atime'])) {
                touch($dir, $metadata['mtime'], $metadata['atime']);
            }
        }
    }

    // Clean up
    exec('rm -rf ' . escapeshellarg($temp));

    print_header("SUMMARY");

    echo "Files checked out: " . number_format($restored) . " / " . number_format($count) . "\n";

    if ($errors) {
        echo "Errors: " . number_format($errors) . "\n";
    }

    echo colorize("All operations completed successfully", COLOR_GREEN) . "\n";

    // Display resource usage summary
    // print_header("RESOURCE USAGE");
    // $memory = get_memory_usage();

    // $resource_data = [
    //     ['Peak Memory Usage', format_file_size($memory['peak'])],
    //     ['Memory Limit', format_file_size($memory['limit'])],
    //     ['CPU Priority', has_low_priority_flag() ? 'Low (nice 10)' : 'Reduced (nice 1)'],
    // ];

    // $widths = [25, 30];
    // foreach ($resource_data as $row) {
    //     print_table_row($row, $widths);
    // }
}

/**
 * Find manifest file for a specific commit ID
 * 
 * Searches the archive for manifest files matching the given commit ID
 * and returns the most recent one if multiple exist with the same ID.
 * 
 * @param string $rarfile RAR archive file path
 * @param int $snapshot_id ID of the commit to find
 * @param string|null $password Password for archive access
 * @return array|null Manifest info or null if not found
 */
function find_manifest_for_snapshot($rarfile, $snapshot_id, $password = null) {
    $rar_cmd = 'rar lb';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' versions/';
    
    // Add input redirection to prevent password prompts
    $rar_cmd .= ' </dev/null 2>/dev/null';
    
    exec($rar_cmd, $lines, $code);
    
    // If command failed due to password error, return special error indicator
    if ($code === 10 || $code === 255) {
        return ['error' => 'password'];
    }
    
    // If command failed for any other reason, return null
    if ($code !== 0) {
        return null;
    }

    $candidates = [];

    foreach ($lines as $line) {
        if (preg_match('/^versions\/(' . preg_quote($snapshot_id) . ')-([\d\-: ]+)\.txt$/', $line, $m)) {
            $candidates[] = [
                'name'  => $line,
                'ts'    => $m[2]
            ];
        }
    }

    // If multiple with same id, pick the latest timestamp
    if (!$candidates) {
        return null;
    }

    usort($candidates, fn($a, $b) => strcmp($b['ts'], $a['ts']));

    return $candidates[0];
}

/**
 * Get the most recent manifest from the repository
 * 
 * Finds the latest commit in the repository and extracts its manifest
 * content for comparison. 
 * 
 * @param string $rarfile RAR repository file path
 * @param string|null $password Password for repository access
 * @return array|null Manifest info and content or null if no commits exist
 */
function get_last_manifest($rarfile, $password = null) {
    if (!file_exists($rarfile)) {
        return null;
    }
    
    $rar_cmd = 'rar lb';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' versions/';
    
    // Add input redirection to prevent password prompts
    $rar_cmd .= ' </dev/null 2>/dev/null';
    
    exec($rar_cmd, $lines, $code);
    
    // If command failed for any reason, return null
    if ($code !== 0) {
        return null;
    }

    $versions = [];

    foreach ($lines as $line) {
        if (preg_match('/^versions\/(\d+)-([\d\-: ]+)\.txt$/', $line, $m)) {
            $versions[] = [
                'id' => (int)$m[1],
                'ts' => $m[2],
                'name' => $line
            ];
        }
    }
    
    if (empty($versions)) {
        return null;
    }
    
    // Sort by timestamp DESC and get the most recent
    usort($versions, fn($a, $b) => strcmp($b['ts'], $a['ts']));

    $latest = $versions[0];
    
    // Extract and read the manifest content
    $temp = sys_get_temp_dir() . '/rarrepo_last_' . uniqid(mt_rand(), true);

    mkdir($temp, 0700, true);
    
    $rar_cmd = 'rar e -inul';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' ' . escapeshellarg($latest['name']) . ' ' . escapeshellarg($temp);
    
    exec($rar_cmd, $output, $code);

    $manifest_path = "$temp/" . basename($latest['name']);
    
    if (file_exists($manifest_path)) {
        $content = file_get_contents($manifest_path);

        exec('rm -rf ' . escapeshellarg($temp));

        return [
            'name'      => $latest['name'],
            'id'        => $latest['id'],
            'ts'        => $latest['ts'],
            'content'   => $content
        ];
    } else {
        exec('rm -rf ' . escapeshellarg($temp));
        // If extraction failed due to password protection, return null
        if ($code === 10 || $code === 255) {
            return null;
        }
        return null;
    }
}

/**
 * Compare two backup commits and show differences
 * 
 * Extracts manifests from two commits and compares them to show
 * which files were added, removed, or changed between versions.
 * 
 * @param int $version1_id ID of the first commit
 * @param int $version2_id ID of the second commit
 * @param string $rarfile RAR repository file path
 * @param string|null $password Password for repository access
 * @return void
 */
function diff_versions($version1_id, $version2_id, $rarfile, $password = null) {
    if (!file_exists($rarfile)) {
        echo "Repository archive not found.\n";
        exit(1);
    }

    $manifest1 = find_manifest_for_snapshot($rarfile, $version1_id, $password);
    $manifest2 = find_manifest_for_snapshot($rarfile, $version2_id, $password);

    // Check for password errors (both manifests are from the same archive)
    if (is_array($manifest1) && isset($manifest1['error']) && $manifest1['error'] === 'password') {
        echo "ERROR: Archive is password protected but no password provided.\n";
        echo "Use -p password to provide the password.\n";
        exit(1);
    }

    if (!$manifest1 || !$manifest2) {
        echo "One or both commit IDs not found.\n";
        exit(1);
    }

    echo "Comparing commit $version1_id: {$manifest1['name']} with commit $version2_id: {$manifest2['name']}...\n";

    $temp = sys_get_temp_dir() . '/rarrepo_diff_' . uniqid(mt_rand(), true);

    mkdir($temp, 0700, true);

    // Extract both manifests
    $rar_cmd = 'rar e -inul';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' ' . escapeshellarg($manifest1['name']) . ' ' . escapeshellarg($temp);

    exec($rar_cmd, $output1, $code1);

    $manifest1_path = "$temp/" . basename($manifest1['name']);

    $rar_cmd = 'rar e -inul';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' ' . escapeshellarg($manifest2['name']) . ' ' . escapeshellarg($temp);

    exec($rar_cmd, $output2, $code2);

    $manifest2_path = "$temp/" . basename($manifest2['name']);

    if (!file_exists($manifest1_path) || !file_exists($manifest2_path)) {
        if ($code1 === 10 || $code1 === 255 || $code2 === 10 || $code2 === 255) {
            echo "ERROR: Archive is password protected but no password provided.\n";
            echo "Use -p password to provide the password.\n";
        } else {
            echo "ERROR: Manifests not extracted (exit codes: $code1, $code2).\n";
        }
        exec('rm -rf ' . escapeshellarg($temp));
        exit(1);
    }

    // Parse manifests into file lists
    $files1 = parse_manifest($manifest1_path);
    $files2 = parse_manifest($manifest2_path);

    // Find differences
    $added      = array_diff_key($files2, $files1);
    $removed    = array_diff_key($files1, $files2);
    $changed    = [];

    // Find files that exist in both but have different hashes
    foreach ($files1 as $path => $hash1) {
        if (isset($files2[$path]) && $files2[$path] !== $hash1) {
            $changed[$path] = ['old' => $hash1, 'new' => $files2[$path]];
        }
    }

    // Clean up
    exec('rm -rf ' . escapeshellarg($temp));

    // Display results
    echo "\nFile differences between commits:\n";
    echo "========================================\n";

    if (empty($added) && empty($removed) && empty($changed)) {
        echo "No differences found between commits.\n";
        return;
    }

    if (!empty($added)) {
        echo "\nADDED files in commit $version2_id:\n";
        echo "----------------------------------------\n";

        foreach ($added as $path => $hash) {
            echo "  $path\n";
        }
    }

    if (!empty($removed)) {
        echo "\nREMOVED files from commit $version1_id:\n";
        echo "----------------------------------------\n";

        foreach ($removed as $path => $hash) {
            echo "  $path\n";
        }
    }

    if (!empty($changed)) {
        echo "\nCHANGED files:\n";
        echo "----------------------------------------\n";

        foreach ($changed as $path => $hashes) {
            echo "  $path\n";
        }
    }

    echo "\nSummary:\n";
    echo "  Added:   " . count($added) . " files\n";
    echo "  Removed: " . count($removed) . " files\n";
    echo "  Changed: " . count($changed) . " files\n";
}

/**
 * Parse manifest file and extract file information
 * 
 * Reads a manifest file and returns an associative array mapping
 * file paths to their MD5 hashes. Skips directory entries and symlinks.
 * Handles both old format (path\thash) and new format with metadata.
 * 
 * @param string $manifest_path Path to the manifest file
 * @return array Associative array of file paths to MD5 hashes
 */
function parse_manifest($manifest_path) {
    $files = [];
    $lines = file($manifest_path, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        $entry = parse_manifest_entry($line);

        if ($entry && (!isset($entry['type']) || $entry['type'] !== '[LINK]') && $entry['hash'] !== '[DIR]') {
            $files[$entry['path']] = $entry['hash'];
        }
    }
    
    return $files;
}

/**
 * Attempt to repair a damaged RAR archive
 * 
 * Uses RAR's built-in repair command to attempt recovery of a
 * damaged repository. Leverages recovery records for better repair success.
 * 
 * @param string $rarfile RAR repository file path
 * @param string|null $password Password for repository access
 * @return void
 */
function repair($rarfile, $password = null) {
    if (!file_exists($rarfile)) {
        echo "Repository repository not found.\n";
        exit(1);
    }

    echo "Attempting to repair repository: $rarfile...\n";

    // Use RAR's built-in repair command
    $rar_cmd = 'rar r';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile);
    
    // Add nice level to RAR command
    $rar_cmd = add_nice_to_rar_command($rar_cmd);
    
    echo "Running RAR repair command...\n";
    passthru($rar_cmd, $code);

    if ($code === 10 || $code === 255) {
        echo "ERROR: Archive is password protected but no password provided.\n";
        echo "Use -p password to provide the password.\n";
        exit(1);
    }

    if ($code === 0) {
        echo "Repair completed successfully.\n";
        echo "Note: If the archive was severely damaged, some files may have been lost.\n";
        echo "Check the archive contents with 'list' command to verify integrity.\n";
    } else {
        echo "Repair failed with exit code: $code\n";
        echo "The archive may be severely damaged or the password may be incorrect.\n";
        exit(1);
    }
}

/**
 * Get file MD5 hash with sudo fallback
 * @param string $filepath Path to the file
 * @param string|null $sudo_password Sudo password if needed
 * @return string|false MD5 hash or false if failed
 */
function get_file_md5($filepath, $sudo_password = null) {
    // Try normal md5_file first
    $hash = @md5_file($filepath);
    if ($hash !== false) {
        return $hash;
    }
    
    // If failed and sudo is available, try with sudo
    if ($sudo_password !== null) {
        $escaped_filepath = escapeshellarg($filepath);
        $command = "md5sum $escaped_filepath | cut -d' ' -f1";
        
        $output = [];
        exec("timeout " . MD5_TIMEOUT . " printf '%s\n' " . escapeshellarg($sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
        
        if ($code === 124) {
            // Command timed out
            print_error("⚠️  MD5 calculation timed out for: $filepath");
            return false;
        } elseif ($code === 0 && !empty($output[0])) {
            return trim($output[0]);
        }
    }
    
    return false;
}

/**
 * Copy file with sudo fallback
 *
 * @param string $source Source file path
 * @param string $dest Destination file path
 * @param string|null $sudo_password Sudo password if needed
 * @return bool True if successful, false otherwise
 */
function copy_file($source, $dest, $sudo_password = null) {
    // Try normal copy first
    if (@copy($source, $dest)) {
        return true;
    }
    
    // If failed and sudo is available, try with sudo
    if ($sudo_password !== null) {
        $escaped_source = escapeshellarg($source);
        $escaped_dest = escapeshellarg($dest);
        $command = "cp --force $escaped_source $escaped_dest";
        
        $output = [];
        exec("printf '%s\n' " . escapeshellarg($sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
        
        // If copy succeeded, fix ownership so RAR can read the file
        if ($code === 0) {
            $current_user = posix_getpwuid(posix_geteuid())['name'];
            $escaped_dest = escapeshellarg($dest);
            $chown_command = "chown $current_user:$current_user $escaped_dest";
            
            $output = [];
            exec("printf '%s\n' " . escapeshellarg($sudo_password) . " | sudo -p '' -S $chown_command 2>/dev/null", $output, $code);
        }
        
        return $code === 0;
    }
    
    return false;
}

/**
 * Check if file is readable with sudo fallback
 *
 * @param string $filepath Path to the file
 * @param string|null $sudo_password Sudo password if needed
 * @return bool True if readable, false otherwise
 */
function is_file_readable($filepath, $sudo_password = null) {
    // Try normal is_readable first
    if (@is_readable($filepath)) {
        return true;
    }

    // If failed and sudo is available, try with sudo
    if ($sudo_password !== null) {
        $escaped_filepath = escapeshellarg($filepath);
        $command = "test -r $escaped_filepath";

        $output = [];
        exec("printf '%s\n' " . escapeshellarg($sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
        return $code === 0;
    }

    return false;
}

/**
 * Check if sudo permissions will be needed for the backup
 * 
 * Performs a comprehensive scan to detect if any files or directories
 * will require elevated permissions to access.
 * 
 * @param string $folder Directory to check
 * @return bool True if sudo will be needed, false otherwise
 */
function needs_sudo_permissions($folder) {
    // Check if we can already access the folder
    if (!is_readable($folder)) {
        return true;
    }

    // Do a comprehensive scan to see if we encounter any permission issues
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isReadable()) {
                return true;
            }
        }
    } catch (UnexpectedValueException $e) {
        // Permission denied accessing directory
        return true;
    }

    return false;
}

/**
 * Prompt user for password with retry logic
 * 
 * Prompts for password and retries if the password is incorrect.
 * Shows appropriate error messages and allows multiple attempts.
 * 
 * @param string $archive_name Name of the archive for the prompt
 * @param callable $test_function Function to test if password is correct
 * @return string|null The correct password or null if user cancels
 */
function prompt_for_password_with_retry($archive_name, $test_function) {
    $max_attempts = 3;
    $attempt = 0;
    
    while ($attempt < $max_attempts) {
        $attempt++;
        
        echo "Repository '$archive_name' is password protected.\n";
        echo "Enter password: ";
        
        // Hide input (only if we're in an interactive terminal)
        if (posix_isatty(STDIN)) {
            system('stty -echo');
            $password = trim(fgets(STDIN));
            system('stty echo');
            echo "\n";
        } else {
            $password = trim(fgets(STDIN));
            echo "\n";
        }
        
        if (empty($password)) {
            echo "No password provided. Exiting.\n";
            exit(1);
        }
        
        // Test the password
        if ($test_function($password)) {
            return $password;
        }
        
        // Password was incorrect
        echo "Incorrect password for $archive_name\n";
        
        if ($attempt < $max_attempts) {
            echo "Please try again.\n";
        } else {
            echo "Maximum attempts reached. Exiting.\n";
            exit(1);
        }
    }
    
    return null;
}

/**
 * Test if a password is correct for a RAR archive
 * 
 * Attempts to list the archive contents with the provided password.
 * 
 * @param string $rarfile RAR archive file path
 * @param string $password Password to test
 * @return bool True if password is correct, false otherwise
 */
function test_archive_password($rarfile, $password) {
    if (!file_exists($rarfile)) {
        return false;
    }

    // Try to list archive contents with password
    $rar_cmd = 'rar lb -inul -hp' . escapeshellarg($password) . ' ' . escapeshellarg($rarfile) . ' 2>/dev/null';
    
    exec($rar_cmd, $output, $code);
    
    // RAR returns code 0 for successful access, 10 for password error
    return $code === 0;
}

/**
 * Get archive file size
 * 
 * Gets the size of the RAR archive file and returns it in bytes.
 * 
 * @param string $rarfile RAR archive file path
 * @return int Size in bytes, or 0 if file doesn't exist
 */
function get_archive_size($rarfile) {
    if (!file_exists($rarfile)) {
        return 0;
    }
    
    $stat = @stat($rarfile);
    return $stat !== false ? $stat['size'] : 0;
}

/**
 * Calculate compression ratio and size difference
 * 
 * Calculates the compression ratio and size difference between
 * original files and the archive.
 * 
 * @param int $original_size Total size of original files in bytes
 * @param int $archive_size Size of archive file in bytes
 * @return array Array with 'ratio', 'difference', and formatted strings
 */
function calculate_compression_stats($original_size, $archive_size) {
    if ($original_size === 0) {
        return [
            'ratio'                 => 0,
            'difference'            => 0,
            'ratio_formatted'       => '0%',
            'difference_formatted'  => '0 Bytes',
            'original_formatted'    => '0 Bytes',
            'archive_formatted'     => '0 Bytes'
        ];
    }
    
    // Calculate compression ratio: (original - archive) / original * 100
    // Positive ratio means compression (archive smaller than original)
    // Negative ratio means expansion (archive larger than original)
    $ratio = (($original_size - $archive_size) / $original_size) * 100;
    $difference = $original_size - $archive_size;
    
    return [
        'ratio'                 => $ratio,
        'difference'            => $difference,
        'ratio_formatted'       => number_format($ratio, 1) . '%',
        'difference_formatted'  => format_file_size($difference),
        'original_formatted'    => format_file_size($original_size),
        'archive_formatted'     => format_file_size($archive_size)
    ];
}

/**
 * Count files that will be added to the archive
 * 
 * Counts the number of files in the temp directory that will be added to the archive.
 * 
 * @param string $temp_dir Temporary directory containing files to archive
 * @return int Number of files to be archived
 */
function count_files_to_archive($temp_dir) {
    $count = 0;
    
    // Count files in files/ directory
    $files_dir = "$temp_dir/files";
    if (is_dir($files_dir)) {
        $count += count(scandir($files_dir)) - 2; // Subtract . and ..
    }
    
    // Count files in versions/ directory
    $versions_dir = "$temp_dir/versions";
    if (is_dir($versions_dir)) {
        $count += count(scandir($versions_dir)) - 2; // Subtract . and ..
    }
    
    // Add bitfreeze.php and README.txt
    $count += 2;
    
    return $count;
}

/**
 * Create a progress bar with spinning indicator
 * 
 * @param int $current Current progress value
 * @param int $total Total value
 * @param int $width Bar width in characters
 * @param bool $spinning Whether to show spinning indicator
 * @return string Progress bar string
 */
function create_progress_bar($current, $total, $width = PROGRESS_BAR_WIDTH, $spinning = false) {
    if ($total <= 0) return '';
    
    $percentage = min(100, ($current / $total) * 100);
    $filled = round(($width * $percentage) / 100);
    $empty = $width - $filled;
    
    $bar = colorize(str_repeat('█', $filled), COLOR_GREEN);
    $bar .= colorize(str_repeat('░', $empty), COLOR_DIM);
    
    $spinner = '';
    if ($spinning && $percentage < 100) {
        $spinners = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $spinner = ' ' . $spinners[intval(microtime(true) * 10) % count($spinners)];
    }
    
    return sprintf("[%s] %.2f%%%s", $bar, $percentage, $spinner);
}

/**
 * Execute RAR command with progress tracking
 * 
 * Executes the RAR command with suppressed output and shows a progress bar instead.
 * 
 * @param string $rar_cmd RAR command to execute
 * @param int $total_files Total number of files being archived
 * @return bool True if successful, false otherwise
 */
function execute_rar_with_progress($rar_cmd, $total_files) {
    // Start progress display
    $encryption_status = strpos($rar_cmd, ' -hp') !== false ? "encrypted " : "";
    echo colorize("📦 Saving {$encryption_status}data to repository...", COLOR_CYAN) . "\n";
    
    // Execute RAR command and monitor output for progress
    $start_time     = microtime(true);
    $output_lines   = 0;
    $expected_lines = $total_files + 20;
    
    // Start the RAR process
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open($rar_cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        return false;
    }
    
    // Close input pipe
    fclose($pipes[0]);
    
    // Set pipes to non-blocking mode
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    
    $current_progress   = 0;
    $output_buffer      = '';
    $output_lines       = 0;
    $progress           = 0;

    while (true) {
        // Check if process is still running
        $status = proc_get_status($process);

        if (!$status['running']) {
            // After the process exits, there may be a little buffered output to consume:
            while (($output = fgets($pipes[1])) !== false) {
                $output_buffer .= $output;
            }
            break;
        }

        // Prepare for stream_select
        $read   = [$pipes[1]];
        $write  = null;
        $except = null;

        $ready  = @stream_select($read, $write, $except, 0, 50000);

        if ($ready && $ready > 0) {
            $output = fgets($pipes[1]);

            if ($output !== false) {
                $output_buffer .= $output;
                // Now count lines (handle multiple lines at once)
                $lines = explode("\n", $output_buffer);
                // Leave the last partial line in buffer
                $output_buffer = array_pop($lines);

                foreach ($lines as $line) {
                    if (trim($line) !== '') {
                        $output_lines++;
                    }
                }

                // Calculate progress
                $progress = min(100, max(0, ($output_lines / $expected_lines) * 100));

                if ($progress !== $current_progress) {
                    echo "\r" . create_progress_bar($progress, 100, PROGRESS_BAR_WIDTH, true);
                    $current_progress = $progress;
                }
            }
        } else {
            echo "\r" . create_progress_bar($progress, 100, PROGRESS_BAR_WIDTH, true);
            usleep(20000);
        }
    }

    // Close pipes
    fclose($pipes[1]);
    fclose($pipes[2]);

    // Get return code
    $return_code = proc_close($process);

    // Show completion
    echo "\r" . create_progress_bar(100, 100, PROGRESS_BAR_WIDTH, false) . "\n";

    return $return_code === 0;
}

/**
 * Execute RAR extraction command with progress tracking
 * 
 * Executes the RAR extraction command and shows a progress bar based on
 * the number of files being extracted.
 * 
 * @param string $rar_cmd RAR extraction command to execute
 * @param int $total_files Total number of files being extracted
 * @return bool True if successful, false otherwise
 */
function execute_rar_extract_with_progress($rar_cmd, $total_files) {
    // Start progress display
    $encryption_status = strpos($rar_cmd, ' -hp') !== false ? "encrypted " : "";
    //echo colorize("📦 Extracting {$encryption_status}data from repository...", COLOR_CYAN) . "\n";
    
    // Execute RAR command and monitor output for progress
    $start_time         = microtime(true);
    $extracted_files    = 0;
    $expected_files     = $total_files;
    
    // Start the RAR process
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    
    $process = proc_open($rar_cmd, $descriptors, $pipes);
    
    if (!is_resource($process)) {
        return false;
    }
    
    // Close input pipe
    fclose($pipes[0]);
    
    // Set pipes to non-blocking mode
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $current_progress   = 0;
    $output_buffer      = '';
    $progress           = 0;

    while (true) {
        // Check if process is still running
        $status = proc_get_status($process);

        if (!$status['running']) {
            // After the process exits, there may be a little buffered output to consume:
            while (($output = fgets($pipes[1])) !== false) {
                $output_buffer .= $output;
            }
            break;
        }

        // Prepare for stream_select
        $read   = [$pipes[1]];
        $write  = null;
        $except = null;

        $ready  = stream_select($read, $write, $except, 0, 50000);

        if ($ready && $ready > 0) {
            $output = fgets($pipes[1]);

            if ($output !== false) {
                $output_buffer .= $output;
                // Now count extracted files (handle multiple lines at once)
                $lines = explode("\n", $output_buffer);
                // Leave the last partial line in buffer
                $output_buffer = array_pop($lines);

                foreach ($lines as $line) {
                    // Count lines that contain "Extracting" and "OK"
                    if (strpos($line, 'Extracting') !== false && strpos($line, 'OK') !== false) {
                        $extracted_files++;
                    }
                }
                // Calculate progress
                $progress = $expected_files > 0 ? min(100, max(0, ($extracted_files / $expected_files) * 100)) : 0;

                if ($progress !== $current_progress) {
                    echo "\r" . create_progress_bar($progress, 100, PROGRESS_BAR_WIDTH, true);
                    $current_progress = $progress;
                }
            }
        } else {
            echo "\r" . create_progress_bar($progress, 100, PROGRESS_BAR_WIDTH, true);
            usleep(20000);
        }
    }

    // Close pipes
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    // Get return code
    $return_code = proc_close($process);
    
    // Show completion
    echo "\r" . create_progress_bar(100, 100, PROGRESS_BAR_WIDTH, false) . "\n";
    
    return $return_code === 0;
}

/**
 * Format duration in a human-readable way
 * 
 * @param float $seconds Duration in seconds
 * @return string Formatted duration string
 */
function format_duration($seconds) {
    if ($seconds < 60) {
        return $seconds . ' seconds';
    }

    $hours      = intval($seconds / 3600);
    $minutes    = intval(($seconds % 3600) / 60);
    $r_secs     = $seconds % 60;
    $parts      = [];

    if ($hours > 0) {
        $parts[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
    }

    if ($minutes > 0) {
        $parts[] = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
    }

    if ($r_secs > 0 || count($parts) === 0) {
        $parts[] = round($r_secs) . ' second' . ($r_secs != 1 ? 's' : '');
    }

    return implode(', ', $parts);
}

/**
 * Check if terminal supports colors
 * 
 * @return bool True if colors are supported
 */
function supports_colors() {
    return function_exists('posix_isatty') && posix_isatty(STDOUT);
}

/**
 * Apply color to text if colors are supported
 * 
 * @param string $text Text to colorize
 * @param string $color ANSI color code
 * @return string Colored text or original text
 */
function colorize($text, $color) {
    return supports_colors() ? $color . $text . COLOR_RESET : $text;
}

/**
 * Print a formatted table row
 * 
 * @param array $columns Array of column values
 * @param array $widths Array of column widths
 * @param string $separator Column separator
 * @return void
 */
function print_table_row($columns, $widths, $separator = '  ') {
    $row = '';
    foreach ($columns as $i => $column) {
        $width = $widths[$i] ?? 20;
        $row .= str_pad($column, $width) . $separator;
    }
    echo $row . "\n";
}

/**
 * Format manifest information for display
 * 
 * Converts manifest timestamp to readable format: "Commit ID mm/dd/yyyy hh:ii:ss AM/PM"
 * 
 * @param array $manifest Manifest array with 'id' and 'ts' keys
 * @return string Formatted commit information
 */
function format_commit_display($manifest) {
    if (!isset($manifest['id']) || !isset($manifest['ts'])) {
        return "Unknown Commit";
    }
    
    // Parse the timestamp (format: YYYY-MM-DD HH:MM:SS)
    $timestamp = strtotime($manifest['ts']);
    if ($timestamp === false) {
        return "Commit {$manifest['id']} ({$manifest['ts']})";
    }
    
    // Format as mm/dd/yyyy hh:ii:ss AM/PM
    return "Commit " . $manifest['id'] . " " . date('m/d/Y h:i:s A', $timestamp);
}

/**
 * Print a header with styling
 * 
 * @param string $text Header text
 * @param string $char Character to use for underline
 * @param int $width Width of the header
 * @return void
 */
function print_header($text, $char = '=', $width = 60) {
    $text_length    = strlen($text) + 4; // '  ' before and after
    $line_length    = max($width, $text_length);
    $line           = str_repeat($char, $line_length);

    // Center the text within $line_length
    $padding        = $line_length - strlen($text);
    $left           = floor($padding / 2);
    $right          = $padding - $left;
    $centered_text  = str_repeat(' ', $left - 1) . $text . str_repeat(' ', $right - 1);

    echo "\n" . colorize($line, COLOR_CYAN) . "\n";
    echo colorize($centered_text, COLOR_BOLD . COLOR_CYAN) . "\n";
    echo colorize($line, COLOR_CYAN) . "\n\n";
}

/**
 * Print a success message
 * 
 * @param string $message Success message
 * @return void
 */
function print_success($message) {
    echo colorize("  $message", COLOR_GREEN) . "\n";
}

/**
 * Print a warning message
 * 
 * @param string $message Warning message
 * @return void
 */
function print_warning($message) {
    echo colorize("  $message", COLOR_YELLOW) . "\n";
}

/**
 * Print an error message
 * 
 * @param string $message Error message
 * @return void
 */
function print_error($message) {
    echo colorize("  $message", COLOR_RED) . "\n";
}

/**
 * Print an info message
 * 
 * @param string $message Info message
 * @return void
 */
function print_info($message) {
    echo colorize("  $message", COLOR_CYAN) . "\n";
}






