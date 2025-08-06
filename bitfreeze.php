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
 * - Create backup: php bitfreeze.php store /home/user/documents archive.rar "Daily Snapshot" -p password
 * - List snapshots: php bitfreeze.php list archive.rar -p password
 * - Restore snapshot: php bitfreeze.php restore 1 archive.rar /restore/path -p password
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

define('USAGE_TEXT', <<<USAGE
Usage:
  php {$argv[0]} store <folder> <archive.rar> [comment] [-p password] [--follow-symlinks]
  php {$argv[0]} list <archive.rar> [-p password]
  php {$argv[0]} restore <snapshot_id> <archive.rar> <restore_folder> [-p password]
  php {$argv[0]} diff <version1> <version2> <archive.rar> [-p password]
  php {$argv[0]} repair <archive.rar> [-p password]

When creating a backup, you can optionally provide a comment describing the backup.
Comments are displayed when listing available versions.

Options:
  -p password          Password for archive encryption/access
  --follow-symlinks    Follow symbolic links and backup their contents
                       (default: symlinks are detected but not backed up)

Password can be provided via:
  - Command line argument: -p password

Examples:
  php {$argv[0]} store /home/user/documents archive.rar "Daily Snapshot" -p password
  php {$argv[0]} store /var/www/domain.com archive.rar "Website Snapshot" -p password --follow-symlinks
  php {$argv[0]} list archive.rar -p password
  php {$argv[0]} diff 1 4 archive.rar -p password
  php {$argv[0]} repair archive.rar -p password
  
USAGE);

define('README_TEXT', <<<README
This archive was created by bitfreeze.php.

To restore or list contents, extract bitfreeze.php from this archive and run:

    php bitfreeze.php list <this-archive.rar>
    php bitfreeze.php restore <snapshot_id> <this-archive.rar> <output-folder>

For example:
    rar e <this-archive.rar> bitfreeze.php
    php bitfreeze.php list <this-archive.rar>
    php bitfreeze.php restore 1 <this-archive.rar> restored/

For full usage:
    php bitfreeze.php

---
Files are stored as content hashes in 'files/' and snapshot manifests in 'versions/'.
Comments are stored as .comment files alongside manifests.
README);

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
 * Get file metadata for manifest entry
 * 
 * Collects all relevant file metadata including permissions, ownership,
 * and timestamps. Returns data in a format suitable for manifest storage.
 * 
 * @param string $filepath Full path to the file
 * @return array Array containing metadata fields
 */
function get_file_metadata($filepath, $sudo_password = null) {
    // Check if file exists and is accessible
    if (!file_exists($filepath) || !is_readable($filepath)) {
        // If sudo is available, try to check with sudo
        if ($sudo_password !== null) {
            $escaped_filepath = escapeshellarg($filepath);
            $command = "test -r $escaped_filepath";
            
            $output = [];
            exec("timeout 600 printf '%s\n' " . escapeshellarg($sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
            if ($code !== 0) {
                echo "MANIFEST ERROR: File $filepath does not exist or is not readable (even with sudo)\n";
                // Return default metadata for inaccessible files
                return [
                    'permissions' => '0644',
                    'owner' => 'unknown',
                    'group' => 'unknown',
                    'mtime' => time(),
                    'atime' => time(),
                    'ctime' => time()
                ];
            }
        } else {
            echo "MANIFEST ERROR: File $filepath does not exist or is not readable\n";
            // Return default metadata for inaccessible files
            return [
                'permissions' => '0644',
                'owner' => 'unknown',
                'group' => 'unknown',
                'mtime' => time(),
                'atime' => time(),
                'ctime' => time()
            ];
        }
    }
    
    $stat = @stat($filepath);
    if ($stat === false) {
        // If sudo is available, try to get stat with sudo
        if ($sudo_password !== null) {
            $escaped_filepath = escapeshellarg($filepath);
            $command = "stat -c '%a %u %g %Y %X %Z' $escaped_filepath";
            
            $output = [];
            exec("timeout 600 printf '%s\n' " . escapeshellarg($sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
            if ($code === 0 && !empty($output[0])) {
                $parts = explode(' ', trim($output[0]));
                if (count($parts) >= 6) {
                    $stat = [
                        'mode' => octdec($parts[0]),
                        'uid' => (int)$parts[1],
                        'gid' => (int)$parts[2],
                        'mtime' => (int)$parts[3],
                        'atime' => (int)$parts[4],
                        'ctime' => (int)$parts[5]
                    ];
                }
            }
        }
        
        if ($stat === false) {
            // Return default metadata if stat fails
            return [
                'permissions' => '0644',
                'owner' => 'unknown',
                'group' => 'unknown',
                'mtime' => time(),
                'atime' => time(),
                'ctime' => time()
            ];
        }
    }
    
    // Get permissions (octal format) with error handling
    $permissions = '0644'; // Default
    $perms_result = @fileperms($filepath);
    if ($perms_result !== false) {
        $permissions = substr(sprintf('%o', $perms_result), -4);
    }
    
    // Get owner and group names (fallback to IDs if names not available)
    $owner_id = 'unknown';
    $group_id = 'unknown';
    
    $owner_result = @fileowner($filepath);
    if ($owner_result !== false) {
        $owner_id = $owner_result;
    }
    
    $group_result = @filegroup($filepath);
    if ($group_result !== false) {
        $group_id = $group_result;
    }
    
    // If we got stat data via sudo, use that instead of trying to get permissions/owner/group
    // since the current user might not have access to those functions
    if (isset($stat['mode'])) {
        $permissions = substr(sprintf('%o', $stat['mode']), -4);
        $owner_id = $stat['uid'];
        $group_id = $stat['gid'];
    }
    
    // Try to get names, fallback to IDs
    $owner = $owner_id;
    $group = $group_id;
    
    if (function_exists('posix_getpwuid') && is_numeric($owner_id)) {
        $owner_info = posix_getpwuid((int)$owner_id);
        if ($owner_info !== false) {
            $owner = $owner_info['name'];
        }
    }
    
    if (function_exists('posix_getgrgid') && is_numeric($group_id)) {
        $group_info = posix_getgrgid((int)$group_id);
        if ($group_info !== false) {
            $group = $group_info['name'];
        }
    }
    
    return [
        'permissions' => $permissions,
        'owner' => $owner,
        'group' => $group,
        'mtime' => $stat['mtime'],
        'atime' => $stat['atime'],
        'ctime' => $stat['ctime']
    ];
}

/**
 * Create enhanced manifest entry with metadata
 * 
 * Creates a manifest entry that includes file metadata alongside
 * the path and hash. Format: path\thash\tpermissions\towner\tgroup\tmtime\tatime\tctime
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
        $metadata['ctime']
    ]);
}

/**
 * Create directory manifest entry
 * 
 * Creates a manifest entry for directories with metadata.
 * Format: path\t[DIR]\tpermissions\towner\tgroup\tmtime\tatime\tctime
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
        $metadata['ctime']
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
    
    // Check if this is the new format with metadata (8 parts)
    if (count($parts) >= 8) {
        $entry['metadata'] = [
            'permissions' => $parts[2],
            'owner' => $parts[3],
            'group' => $parts[4],
            'mtime' => (int)$parts[5],
            'atime' => (int)$parts[6],
            'ctime' => (int)$parts[7]
        ];
    }
    
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
function prompt_for_sudo_password() {
    echo "Access to some files/folders is restricted and requires root privileges.\n";
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
    $escaped_command = escapeshellarg($command);
    $escaped_password = escapeshellarg($password);
    
    // Use printf to pipe password to sudo without triggering the prompt
    // The -p option with empty string suppresses the prompt
    $full_command = "printf '%s\n' $escaped_password | sudo -p '' -S $escaped_command 2>/dev/null";
    
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
        return false;
    }
    
    // Restore permissions
    if (isset($metadata['permissions'])) {
        chmod($dest, octdec($metadata['permissions']));
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
                    chown($dest, $owner_id);
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
                    chgrp($dest, $group_id);
                } else {
                    execute_with_sudo("chgrp $group_id " . escapeshellarg($dest), $sudo_password);
                }
            }
        }
    }
    
    // Restore timestamps
    if (isset($metadata['mtime']) && isset($metadata['atime'])) {
        touch($dest, $metadata['mtime'], $metadata['atime']);
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
 * Get absolute path for RAR file
 * 
 * Handles both relative and absolute paths for RAR files.
 * Relative paths are made absolute from current directory.
 * 
 * @param string $rarfile RAR file path
 * @return string Absolute path to RAR file
 */
function get_rar_absolute_path($rarfile) {
    if (pathinfo($rarfile, PATHINFO_DIRNAME) === '.') {
        // Relative path - make it absolute from current directory
        return realpath('.') . '/' . $rarfile;
    } else {
        // Absolute path or path with directory - use as is
        return realpath($rarfile) ?: $rarfile;
    }
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
            // If -p is provided without a value, return null to trigger detection
            return null;
        }
    }
    
    return null; // No password provided
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
    $rarfile_abs = get_rar_absolute_path($rarfile);
    
    if (!file_exists($rarfile_abs)) {
        return false; // File doesn't exist, so not encrypted
    }
    
    // Try to list archive contents without password
    // Redirect input to /dev/null to prevent hanging on password prompt
    $rar_cmd = 'rar lb -inul ' . escapeshellarg($rarfile_abs) . ' </dev/null 2>/dev/null';
    
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
    if (($password === null || $should_prompt) && file_exists($rarfile)) {
        if (is_archive_encrypted($rarfile)) {
            // Create a test function for this specific archive
            $test_function = function($test_password) use ($rarfile) {
                return test_archive_password($rarfile, $test_password);
            };
            $password = prompt_for_password_with_retry(basename($rarfile), $test_function);
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
 * Remove password arguments from argv for cleaner command parsing
 * 
 * Filters out the -p password arguments from the command line arguments
 * to simplify the argument parsing in the main command dispatch.
 * 
 * @return array Cleaned command line arguments without password
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
 * Create symlink manifest entry
 * 
 * Creates a manifest entry for symbolic links with metadata.
 * Format: path\t[LINK]\ttarget\tpermissions\towner\tgroup\tmtime\tatime\tctime
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
        $metadata['ctime']
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
    
    if (count($parts) < 9) {
        return false;
    }
    
    $entry = [
        'path' => $parts[0],
        'type' => $parts[1],
        'target' => $parts[2]
    ];
    
    // Check if this is the new format with metadata (9+ parts)
    if (count($parts) >= 9) {
        $entry['metadata'] = [
            'permissions' => $parts[3],
            'owner' => $parts[4],
            'group' => $parts[5],
            'mtime' => (int)$parts[6],
            'atime' => (int)$parts[7],
            'ctime' => (int)$parts[8]
        ];
    }
    
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
    case 'store':
        if (count($cleaned_argv) < 4 || count($cleaned_argv) > 6) usage();
        $comment = count($cleaned_argv) >= 5 ? $cleaned_argv[4] : "Automated Snapshot";
        $password = get_password();
        store($cleaned_argv[2], $cleaned_argv[3], $comment, $password);
        break;
    case 'list':
        if (count($cleaned_argv) !== 3) usage();
        $password = get_password_with_detection($cleaned_argv[2]);
        list_versions($cleaned_argv[2], $password);
        break;
    case 'restore':
        if (count($cleaned_argv) !== 5) usage();
        $password = get_password_with_detection($cleaned_argv[3]);
        // Only exit if archive is encrypted but no password provided
        if ($password === null && file_exists($cleaned_argv[3]) && is_archive_encrypted($cleaned_argv[3])) {
            echo "ERROR: Archive is password protected but no password provided.\n";
            echo "Use -p password to provide the password.\n";
            exit(1);
        }
        restore($cleaned_argv[2], $cleaned_argv[3], $cleaned_argv[4], $password);
        break;
    case 'diff':
        if (count($cleaned_argv) !== 5) usage();
        $password = get_password_with_detection($cleaned_argv[4]);
        diff_versions($cleaned_argv[2], $cleaned_argv[3], $cleaned_argv[4], $password);
        break;
    case 'repair':
        if (count($cleaned_argv) !== 3) usage();
        $password = get_password_with_detection($cleaned_argv[2]);
        repair($cleaned_argv[2], $password);
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
                // Use yield from for recursion
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
                // Use yield for recursion
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
 * Create a new backup snapshot of the specified folder
 * 
 * Scans the source folder, creates a manifest of all files and directories,
 * deduplicates files using MD5 hashes, and creates a new snapshot in the
 * RAR archive. Includes comment support and password protection.
 * 
 * @param string $folder Source folder to backup
 * @param string $rarfile RAR archive file path
 * @param string $comment Comment describing the backup
 * @param string|null $password Password for archive protection
 * @return void
 */
function store($folder, $rarfile, $comment, $password = null) {
    global $argv;
    $folder = rtrim(realpath($folder), '/');

    if (!is_dir($folder)) {
        echo "ERROR: Folder '{$folder}' does not exist.\n";
        exit(1);
    }

    // Get absolute path for RAR file
    $rarfile_abs = get_rar_absolute_path($rarfile);

    // Use provided comment or default
    if (empty($comment)) {
        $comment = "Automated Snapshot";
    }

    $start = microtime(true);

    // Setup temp workspace
    $temp           = sys_get_temp_dir() . '/rarrepo_' . uniqid(mt_rand(),true);
    $tmp_files      = "$temp/files";
    $tmp_versions   = "$temp/versions";

    mkdir($tmp_files, 0700, true);
    mkdir($tmp_versions, 0700, true);

    echo "Scanning files in '$folder'...\n";

    // Check if sudo permissions will be needed early
    $sudo_password = null;
    $use_sudo_scan = false;
    
    if (needs_sudo_permissions($folder)) {
        $sudo_password = prompt_for_sudo_password();
        if ($sudo_password !== null) {
            $use_sudo_scan = true;
        }
    }

    // First pass: Record files, parent dirs, and prepare deduplication
    $manifest           = [];
    $known_hashes       = get_archive_hashes($rarfile_abs, $password);
    $seen_hashes        = [];
    $file_count         = 0;
    $add_count          = 0;
    $already_count      = 0;
    $skipped_count      = 0; // Track files skipped due to permission issues
    $skipped_files      = []; // Track which files were skipped
    $skipped_reasons    = []; // Track why files were skipped
    $parent_dirs        = [];

    $symlink_count = 0;
    $follow_symlinks = has_follow_symlinks();
    
    // Use sudo scan if needed, otherwise use normal generator
    if ($use_sudo_scan) {
        $sudo_scan_result   = sudo_scan_directory($folder, $sudo_password);
        $processed_count    = 0;
        
        foreach ($sudo_scan_result['files'] as $filepath) {
            // Since we used 'find -type f', we know these are files
            $file       = new SplFileInfo($filepath);
            $fullpath   = $file->getPathname();
            $rel        = ltrim(substr($fullpath, strlen($folder)), '/');
            
            // Show progress every 10 files
            if ($file_count % 10 === 0 && $file_count > 0) {
                echo "\r  Processed $file_count files...";
            }

            // For sudo scan, treat all files as regular files (skip symlink checks)
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
                echo "\r  Processed $file_count files...";
            }
        }

        echo "\r  Processed $file_count files...";
    } else {
        // Use normal generator scan
        $processed_count = 0;
        foreach (scanDirGenerator($folder, $sudo_password) as $filepath) {
            $file       = new SplFileInfo($filepath);
            $fullpath   = $file->getPathname();
            $rel        = ltrim(substr($fullpath, strlen($folder)), '/');

            if ($file->isLink()) {
                // Handle symbolic links first (before checking isFile)
                $symlink_count++;
                
                if ($follow_symlinks) {
                    // Follow the symlink and backup its target
                    $target_path = readlink($fullpath);
                    $real_target = realpath($fullpath);
                    
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
                    echo "\r  Processed $file_count files...";
                }
            
            }
        }

        echo "\r  Processed $file_count files...";
    }

    // Second pass: capture ALL directories with their metadata
    $all_dirs = [];

    if ($use_sudo_scan) {
        // Use directories from sudo scan
        foreach ($sudo_scan_result['directories'] as $dirpath) {
            $rel = ltrim(substr($dirpath, strlen($folder)), '/');

            if ($rel === '') {
                continue; // skip root
            }

            $all_dirs[] = $rel;
        }
    } else {
        // Use normal directory generator
        foreach (scanDirGeneratorForDirs($folder, $sudo_password) as $dirpath) {
            $rel = ltrim(substr($dirpath, strlen($folder)), '/');

            if ($rel === '') {
                continue; // skip root
            }

            $all_dirs[] = $rel;
        }
    }

    foreach ($all_dirs as $dir) {
        $fullpath = $folder . '/' . $dir;
        $manifest[] = create_directory_entry($dir, $fullpath, $sudo_password);
    }

    // Add newline after progress updates
    if ($file_count > 0) {
        echo "\n";
    }

    echo "Scan complete.\n";
    echo "Total files scanned:          " . number_format($file_count) . "\n";
    echo "Unique new files to add:      " . number_format($add_count) . "\n";
    echo "Duplicate files:              " . number_format($already_count) . "\n";
    echo "Files skipped (permissions):  " . number_format($skipped_count) . "\n";
    if ($skipped_count > 0) {
        echo "Skipped files:\n";
        foreach (array_slice($skipped_files, 0, 10) as $skipped_file) {
            $reason = $skipped_reasons[$skipped_file] ?? "Unknown reason";
            echo "  - $skipped_file ($reason)\n";
        }
        if (count($skipped_files) > 10) {
            echo "  ... and " . (count($skipped_files) - 10) . " more\n";
        }
    }
    echo "Directories to record:        " . number_format(count($all_dirs)) . "\n";
    
    if ($symlink_count > 0) {
        if ($follow_symlinks) {
            echo "Symbolic links detected:    " . number_format($symlink_count) . " (followed and backed up)\n";
        } else {
            echo "Symbolic links detected:    " . number_format($symlink_count) . " (stored as links)\n";
        }
    }

    // Check if this snapshot is identical to the last one
    $last_manifest = get_last_manifest($rarfile, $password);
    $current_manifest_content = implode("\n", $manifest) . "\n";
    
    if ($last_manifest && $last_manifest['content'] === $current_manifest_content) {
        echo "Last snapshot:    {$last_manifest['name']}\n";
        echo "Comment:          $comment\n\n";
        echo "NOTE: No changes detected since last backup. Skipping snapshot creation.\n";
        
        // Clean up temp
        exec('rm -rf ' . escapeshellarg($temp));
        return;
    }

    // Snapshot name/id
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

    // Add files, manifest, comment, script, README to archive
    echo "Recording new data to archive...\n";
    
    $cwd = getcwd();
    chdir($temp);
    $rar_cmd = 'rar a -r -rr' . RECOVERY_RECORD_SIZE . '% -m3';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile_abs) . ' files versions bitfreeze.php README.txt';

    passthru($rar_cmd);
    chdir($cwd);

    // Clean up temp
    exec('rm -rf ' . escapeshellarg($temp));

    $duration = round(microtime(true) - $start, 2);

    echo "Backup complete.\n";
    echo "Snapshot: $manifest_filename\n";
    echo "Comment:  $comment\n";

    if ($password) {
        echo "Password protection: Enabled\n";
    }

    echo "Time taken: {$duration} sec\n";

    echo "===== SUMMARY =====\n";
    echo "  Total files scanned:    " . number_format($file_count) . "\n";
    echo "  Unique files added:     " . number_format($add_count) . "\n";
    echo "  Already present:        " . number_format($already_count) . "\n";
    echo "  Files skipped:          " . number_format($skipped_count) . " (permission issues)\n";
    if ($skipped_count > 0) {
        echo "  Skipped files:\n";
        foreach (array_slice($skipped_files, 0, 10) as $skipped_file) {
            $reason = $skipped_reasons[$skipped_file] ?? "Unknown reason";
            echo "    - $skipped_file ($reason)\n";
        }
        if (count($skipped_files) > 10) {
            echo "  ... and " . (count($skipped_files) - 10) . " more\n";
        }
    }
    echo "  Snapshot manifest:      $manifest_filename\n";
    echo "  Comment Filename:       $comment_filename\n";
    echo "  Directories recorded:    " . count($all_dirs) . "\n";
    if ($symlink_count > 0) {
        if ($follow_symlinks) {
            echo "  Symbolic links:          " . number_format($symlink_count) . " (followed)\n";
        } else {
            echo "  Symbolic links:          " . number_format($symlink_count) . " (stored as links)\n";
            echo "  Note: Use --follow-symlinks to backup symlink contents instead\n";
        }
    }

    exit(0);
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

    $rarfile_abs = get_rar_absolute_path($rarfile);
    
    if (!file_exists($rarfile_abs)) {
        return $out;
    }
    
    $rar_cmd = 'rar lb';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile_abs) . ' files/';
    
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
 * Get the next available snapshot ID
 * 
 * Scans existing snapshots in the archive and returns the next
 * available ID number for creating a new snapshot.
 * 
 * @param string $rarfile RAR archive file path
 * @param string|null $password Password for archive access
 * @return int Next available snapshot ID
 */
function get_next_snapshot_id($rarfile, $password = null) {
    $max = 0;
    $rarfile_abs = get_rar_absolute_path($rarfile);

    if (!file_exists($rarfile_abs)) {
        return 1;
    }
    
    $rar_cmd = 'rar lb';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile_abs) . ' versions/';
    
    exec($rar_cmd, $lines, $code);
    
    // If command failed for any reason, return 1 (new archive)
    if ($code !== 0) {
        return 1;
    }

    foreach ($lines as $line) {
        if (preg_match('/^versions\/(\d+)-/', $line, $m)) {
            $v = (int)$m[1];
            if ($v > $max) $max = $v;
        }
    }

    return $max+1;
}

/**
 * List all available backup snapshots
 * 
 * Extracts and displays all snapshots in the archive with their
 * IDs, timestamps, and comments. Shows most recent snapshots first.
 * 
 * @param string $rarfile RAR archive file path
 * @param string|null $password Password for archive access
 * @return void
 */
function list_versions($rarfile, $password = null) {
    $rarfile_abs = get_rar_absolute_path($rarfile);
    
    if (!file_exists($rarfile_abs)) {
        echo "Repository archive not found.\n";
        exit(1);
    }
    
    $rar_cmd = 'rar lb';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile_abs) . ' versions/';
    
    exec($rar_cmd, $lines, $code);
    
    // Check if command failed due to missing password
    if ($code !== 0) {
        if ($code === 10 || $code === 255) {
            echo "ERROR: Archive is password protected but no password provided.\n";
            echo "Use -p password to provide the password.\n";
        } else {
            echo "ERROR: Failed to access archive (exit code: $code).\n";
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
        echo "No snapshots found.\n";
        return;
    }
    
    echo "Available snapshots (most recent first):\n";
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
 * Restore a specific backup snapshot to a directory
 * 
 * Extracts the specified snapshot from the archive and reconstructs
 * the file tree in the output directory. Handles file deduplication
 * and directory structure restoration.
 * 
 * @param int $snapshot_id ID of the snapshot to restore
 * @param string $rarfile RAR archive file path
 * @param string $outdir Output directory for restoration
 * @param string|null $password Password for archive access
 * @return void
 */
function restore($snapshot_id, $rarfile, $outdir, $password = null) {
    $parent = dirname($outdir);

    if (!is_writable($parent)) {
        echo "ERROR: Cannot restore to $outdir: Permission denied\n";
        exit(1);
    }

    if (!file_exists($rarfile)) {
        echo "Repository archive not found.\n";
        exit(1);
    }

    $manifest = find_manifest_for_snapshot($rarfile, $snapshot_id, $password);

    // Check for password errors
    if (is_array($manifest) && isset($manifest['error']) && $manifest['error'] === 'password') {
        echo "ERROR: Archive is password protected but no password provided.\n";
        echo "Use -p password to provide the password.\n";
        exit(1);
    }

    if (!$manifest) {
        echo "Snapshot ID $snapshot_id not found.\n";
        exit(1);
    }

    echo "Restoring snapshot $snapshot_id: {$manifest['name']} ...\n";
    
    // Check if we need sudo for permission restoration
    $sudo_password = null;
    $is_root = function_exists('posix_getuid') && posix_getuid() === 0;
    
    if (!$is_root && can_elevate_privileges()) {
        $sudo_password = prompt_for_sudo_password();
    }

    $temp = sys_get_temp_dir() . '/rarrepo_' . uniqid(mt_rand(),true);

    mkdir($temp, 0700, true);

    // 1. Extract manifest
    $rar_cmd = 'rar e -inul';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' ' . escapeshellarg($manifest['name']) . ' ' . escapeshellarg($temp);
    
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
    $dir_metadata_restore = []; // Store directory metadata for restoration after files
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
                $escaped_dir = escapeshellarg($dir);
                exec("mkdir -p -m 0755 $escaped_dir");
            }
            
            // Store directory metadata for restoration after all files are processed
            if (isset($entry['metadata'])) {
                $dir_metadata_restore[] = [
                    'dir' => $dir,
                    'metadata' => $entry['metadata']
                ];
            }

            continue;
        } elseif (isset($entry['type']) && $entry['type'] === '[LINK]') {
            // Handle symbolic links
            $link_path = rtrim($outdir, '/') . '/' . $entry['path'];
            $link_dir = dirname($link_path);

            if (!is_dir($link_dir)) {
                mkdir($link_dir, 0755, true);
            }
            
            // Create the symlink
            if (symlink($entry['target'], $link_path)) {
                // Restore symlink metadata if available
                if (isset($entry['metadata'])) {
                    // Note: symlink permissions are limited, but we can try
                    if ($is_root || $sudo_password !== null) {
                        $owner_id = is_numeric($entry['metadata']['owner']) ? 
                            $entry['metadata']['owner'] : 
                            (function_exists('posix_getpwnam') ? posix_getpwnam($entry['metadata']['owner'])['uid'] : null);
                        $group_id = is_numeric($entry['metadata']['group']) ? 
                            $entry['metadata']['group'] : 
                            (function_exists('posix_getgrnam') ? posix_getgrnam($entry['metadata']['group'])['gid'] : null);
                        
                        if ($owner_id !== null) {
                            if ($is_root) {
                                if (function_exists('lchown')) {
                                    lchown($link_path, $owner_id);
                                }
                            } else {
                                execute_with_sudo("lchown $owner_id " . escapeshellarg($link_path), $sudo_password);
                            }
                        }
                        if ($group_id !== null) {
                            if ($is_root) {
                                if (function_exists('lchgrp')) {
                                    lchgrp($link_path, $group_id);
                                }
                            } else {
                                execute_with_sudo("lchgrp $group_id " . escapeshellarg($link_path), $sudo_password);
                            }
                        }
                    }
                    // Note: touch() doesn't work on symlinks, but we can try lchmod if available
                }
            } else {
                // Try to create symlink with sudo if available and user is not root
                if (!$is_root && $sudo_password !== null) {
                    $escaped_target = escapeshellarg($entry['target']);
                    $escaped_link_path = escapeshellarg($link_path);
                    $result = execute_with_sudo("ln -s $escaped_target $escaped_link_path", $sudo_password);
                    
                    if ($result === 0) {
                        echo "[INFO] Created symlink with sudo: {$entry['path']} -> {$entry['target']}\n";
                        
                        // Restore metadata if available
                        if (isset($entry['metadata'])) {
                            $owner_id = is_numeric($entry['metadata']['owner']) ? 
                                $entry['metadata']['owner'] : 
                                (function_exists('posix_getpwnam') ? posix_getpwnam($entry['metadata']['owner'])['uid'] : null);
                            $group_id = is_numeric($entry['metadata']['group']) ? 
                                $entry['metadata']['group'] : 
                                (function_exists('posix_getgrnam') ? posix_getgrnam($entry['metadata']['group'])['gid'] : null);
                            
                            if ($owner_id !== null) {
                                execute_with_sudo("lchown $owner_id " . escapeshellarg($link_path), $sudo_password);
                            }
                            if ($group_id !== null) {
                                execute_with_sudo("lchgrp $group_id " . escapeshellarg($link_path), $sudo_password);
                            }
                        }
                    } else {
                        echo "[WARNING] Could not create symlink: {$entry['path']} -> {$entry['target']}\n";
                    }
                } else {
                    echo "[WARNING] Could not create symlink: {$entry['path']} -> {$entry['target']}\n";
                }
            }
            
            continue;
        }

        // Only add file entries to hashmap and restorelist (not symlinks or directories)
        if (isset($entry['hash']) && $entry['hash'] !== '[DIR]') {
            $hashmap[$entry['hash']][] = $entry['path'];
            $restorelist[] = [$entry['path'], $entry['hash'], $entry['metadata'] ?? null];
        }
    }

    echo "Will restore " . number_format(count($restorelist)) . " files (" . number_format(count($hashmap)) . " unique contents).\n";

    // 3. Extract all needed hashes (content blobs) to temp dir
    $all_hashes = array_keys($hashmap);
    $exlist     = [];

    foreach ($all_hashes as $h) {
        $exlist[] = "files/$h";
    }

    // Prepare extraction list file
    $exlistfile = "$temp/extract.lst";

    file_put_contents($exlistfile, implode("\n", $exlist) . "\n");
    echo "Extracting " . number_format(count($all_hashes)) . " unique file contents from archive...\n";
    
    $rar_cmd = 'rar e -idq -inul';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' @"' . $exlistfile . '" ' . escapeshellarg($temp);
    
    exec($rar_cmd, $output2, $code2);

    // 4. Reconstruct file tree in outdir
    $restored   = 0;
    $errors     = 0;
    $count      = count($restorelist);

    foreach ($restorelist as $n => [$path, $md5, $metadata]) {
        $src        = "$temp/$md5";
        $dest       = rtrim($outdir, '/') . '/' . $path;
        $destdir    = dirname($dest);

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
                    echo "[ERROR] Could not restore $path\n";
                    $errors++;
                } else {
                    $restored++;
                }
            } else {
                // Fallback to basic restoration for old format
                if (!copy($src, $dest)) {
                    echo "[ERROR] Could not restore $path\n";
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
            echo "\r  Restored " . ($n+1) . "/$count";
        }
    }

    echo "\r  Restored " . ($n+1) . "/$count";

    // Restore directory metadata after all files are processed
    foreach ($dir_metadata_restore as $dir_meta) {
        $dir = $dir_meta['dir'];
        $metadata = $dir_meta['metadata'];
        
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

    echo "Restore complete.\n";
    echo "===== SUMMARY =====\n";
    echo "  Files restored: " . number_format($restored) . " / " . number_format($count) . "\n";

    if ($errors) {
        echo "  Errors: " . number_format($errors) . "\n";
    }
}

/**
 * Find manifest file for a specific snapshot ID
 * 
 * Searches the archive for manifest files matching the given snapshot ID
 * and returns the most recent one if multiple exist with the same ID.
 * 
 * @param string $rarfile RAR archive file path
 * @param int $snapshot_id ID of the snapshot to find
 * @param string|null $password Password for archive access
 * @return array|null Manifest info or null if not found
 */
function find_manifest_for_snapshot($rarfile, $snapshot_id, $password = null) {
    $rar_cmd = 'rar lb';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' versions/';
    
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
 * Get the most recent manifest from the archive
 * 
 * Finds the latest snapshot in the archive and extracts its manifest
 * content for comparison. Used to detect duplicate snapshots.
 * 
 * @param string $rarfile RAR archive file path
 * @param string|null $password Password for archive access
 * @return array|null Manifest info and content or null if no snapshots exist
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
 * Compare two backup snapshots and show differences
 * 
 * Extracts manifests from two snapshots and compares them to show
 * which files were added, removed, or changed between versions.
 * 
 * @param int $version1_id ID of the first snapshot
 * @param int $version2_id ID of the second snapshot
 * @param string $rarfile RAR archive file path
 * @param string|null $password Password for archive access
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
        echo "One or both snapshot IDs not found.\n";
        exit(1);
    }

    echo "Comparing snapshot $version1_id: {$manifest1['name']} with snapshot $version2_id: {$manifest2['name']}...\n";

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
    echo "\nFile differences between snapshots:\n";
    echo "========================================\n";

    if (empty($added) && empty($removed) && empty($changed)) {
        echo "No differences found between snapshots.\n";
        return;
    }

    if (!empty($added)) {
        echo "\nADDED files in snapshot $version2_id:\n";
        echo "----------------------------------------\n";

        foreach ($added as $path => $hash) {
            echo "  $path\n";
        }
    }

    if (!empty($removed)) {
        echo "\nREMOVED files from snapshot $version1_id:\n";
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
        
        if ($entry && $entry['hash'] !== '[DIR]' && (!isset($entry['type']) || $entry['type'] !== '[LINK]')) {
            $files[$entry['path']] = $entry['hash'];
        }
    }
    
    return $files;
}

/**
 * Attempt to repair a damaged RAR archive
 * 
 * Uses RAR's built-in repair command to attempt recovery of a
 * damaged archive. Leverages recovery records for better repair success.
 * 
 * @param string $rarfile RAR archive file path
 * @param string|null $password Password for archive access
 * @return void
 */
function repair($rarfile, $password = null) {
    if (!file_exists($rarfile)) {
        echo "Repository archive not found.\n";
        exit(1);
    }

    echo "Attempting to repair archive: $rarfile...\n";

    // Use RAR's built-in repair command
    $rar_cmd = 'rar r';

    if ($password) {
        $rar_cmd .= ' -hp' . escapeshellarg($password);
    }

    $rar_cmd .= ' ' . escapeshellarg($rarfile);
    
    echo "Running RAR repair command...\n";
    passthru($rar_cmd, $code);

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
        exec("timeout 600 printf '%s\n' " . escapeshellarg($sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
        if ($code === 0 && !empty($output[0])) {
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
        exec("timeout 600 printf '%s\n' " . escapeshellarg($sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
        
        // If copy succeeded, fix ownership so RAR can read the file
        if ($code === 0) {
            $current_user = posix_getpwuid(posix_geteuid())['name'];
            $escaped_dest = escapeshellarg($dest);
            $chown_command = "chown $current_user:$current_user $escaped_dest";
            
            $output = [];
            exec("timeout 600 printf '%s\n' " . escapeshellarg($sudo_password) . " | sudo -p '' -S $chown_command 2>/dev/null", $output, $code);
            // Don't fail if chown fails, just log it
            if ($code !== 0) {
                error_log("Warning: Failed to fix ownership of $dest");
            }
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
        exec("timeout 600 printf '%s\n' " . escapeshellarg($sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
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
        
        // Check more items to get a better sample
        $count = 0;
        foreach ($iterator as $file) {
            if (!$file->isReadable()) {
                return true;
            }
            $count++;
            if ($count >= 500) { // Increased sample size
                break;
            }
        }
    } catch (UnexpectedValueException $e) {
        // Permission denied accessing directory
        return true;
    }
    
    return false;
}

/**
 * Perform a comprehensive sudo-enabled scan of the directory
 * 
 * Uses sudo to find all files and directories that would otherwise be inaccessible.
 * 
 * @param string $folder Directory to scan
 * @param string $sudo_password Sudo password
 * @return array Array containing 'files' and 'directories' lists
 */
function sudo_scan_directory($folder, $sudo_password) {
    $escaped_folder = escapeshellarg($folder);
    $files = [];
    $directories = [];
    
    // Test sudo access first
    $test_command = "echo '$sudo_password' | sudo -S true 2>/dev/null";
    exec($test_command, $test_output, $test_return);
    
    if ($test_return !== 0) {
        echo "Sudo access test failed. Cannot proceed with sudo scan.\n";
        return ['files' => [], 'directories' => []];
    }
    
    // Use sudo to find all files
    $command = "echo '$sudo_password' | sudo -S find $escaped_folder -type f 2>/dev/null";
    $output = [];
    $return_code = 0;
    exec($command, $output, $return_code);
    
    if ($return_code === 0) {
        $files = $output;
    } else {
        echo "File scan failed with return code: $return_code\n";
    }
    
    // Use sudo to find all directories
    $command = "echo '$sudo_password' | sudo -S find $escaped_folder -type d 2>/dev/null";
    $output = [];
    $return_code = 0;
    exec($command, $output, $return_code);
    
    if ($return_code === 0) {
        $directories = $output;
    } else {
        echo "Directory scan failed with return code: $return_code\n";
    }
    
    return [
        'files' => $files,
        'directories' => $directories
    ];
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
    $rarfile_abs = get_rar_absolute_path($rarfile);
    
    if (!file_exists($rarfile_abs)) {
        return false;
    }
    
    // Try to list archive contents with password
    $rar_cmd = 'rar lb -inul -hp' . escapeshellarg($password) . ' ' . escapeshellarg($rarfile_abs) . ' 2>/dev/null';
    
    exec($rar_cmd, $output, $code);
    
    // RAR returns code 0 for successful access, 10 for password error
    return $code === 0;
}







