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
 * - RAR 5.0+ format with 10% (configurable) recovery records for bitrot protection
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
 * - 10% recovery records protect against bitrot and corruption
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
 * - Set RAR_PASSWORD environment variable: php bitfreeze.php passwd
 * - Clear RAR_PASSWORD environment variable: php bitfreeze.php passwd -c
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
define('RECOVERY_RECORD_SIZE', 10); // Set as a percentage

define('USAGE_TEXT', <<<USAGE
Usage:
  php {$argv[0]} store <folder> <archive.rar> [comment] [-p password]
  php {$argv[0]} list <archive.rar> [-p password]
  php {$argv[0]} restore <snapshot_id> <archive.rar> <restore_folder> [-p password]
  php {$argv[0]} diff <version1> <version2> <archive.rar> [-p password]
  php {$argv[0]} repair <archive.rar> [-p password]
  php {$argv[0]} passwd [-c]

When creating a backup, you can optionally provide a comment describing the backup.
Comments are displayed when listing available versions.

Password can be provided via:
  - Command line argument: -p password
  - Environment variable: RAR_PASSWORD

Examples:
  php {$argv[0]} store /home/user/documents archive.rar "Daily Snapshot" -p password
  php {$argv[0]} store /var/www/domain.com archive.rar "Website Snapshot" -p password
  php {$argv[0]} list archive.rar -p password
  php {$argv[0]} diff 1 4 archive.rar -p password
  php {$argv[0]} repair archive.rar -p password
  php {$argv[0]} passwd
  php {$argv[0]} passwd -c
  
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
        'getenv'
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
 * Manage RAR_PASSWORD environment variable
 * 
 * Handles setting, clearing, and querying the RAR_PASSWORD environment variable.
 * Provides interactive prompts for password input with confirmation.
 * 
 * @param string|null $clear_flag If '-c', clears the password
 * @return void
 */
function passwd($clear_flag = null) {
    // Handle clear flag
    if ($clear_flag === '-c') {
        $current_password = getenv('RAR_PASSWORD');
        if ($current_password !== false) {
            // Clear the environment variable
            putenv('RAR_PASSWORD');
            echo "RAR_PASSWORD environment variable has been cleared.\n";
        } else {
            echo "RAR_PASSWORD environment variable is not set.\n";
        }
        return;
    }
    
    // Check if RAR_PASSWORD is currently set
    $current_password = getenv('RAR_PASSWORD');
    
    if ($current_password !== false) {
        // Password is set, ask if user wants to clear it
        echo "RAR_PASSWORD environment variable is currently set.\n";
        echo "Do you want to clear it? (y/N): ";
        $response = trim(fgets(STDIN));
        
        if (strtolower($response) === 'y' || strtolower($response) === 'yes') {
            putenv('RAR_PASSWORD');
            echo "RAR_PASSWORD environment variable has been cleared.\n";
        } else {
            echo "RAR_PASSWORD environment variable remains set.\n";
        }
    } else {
        // Password is not set, ask if user wants to set it
        echo "RAR_PASSWORD environment variable is not set.\n";
        echo "Do you want to set it? (y/N): ";
        $response = trim(fgets(STDIN));
        
        if (strtolower($response) === 'y' || strtolower($response) === 'yes') {
            // Prompt for password (hidden input)
            echo "Enter password: ";
            system('stty -echo');
            $password1 = trim(fgets(STDIN));
            system('stty echo');
            echo "\n";
            
            // Prompt for confirmation
            echo "Confirm password: ";
            system('stty -echo');
            $password2 = trim(fgets(STDIN));
            system('stty echo');
            echo "\n";
            
            // Check if passwords match
            if ($password1 === $password2) {
                putenv("RAR_PASSWORD=$password1");
                echo "RAR_PASSWORD environment variable has been set.\n";
                echo "Note: This setting is only for the current session.\n";
                echo "To make it permanent, add it to your shell profile.\n";
            } else {
                echo "Passwords do not match. RAR_PASSWORD was not set.\n";
            }
        } else {
            echo "RAR_PASSWORD environment variable was not set.\n";
        }
    }
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
 * Get password from command line arguments or environment variable
 * 
 * Searches for the -p password argument in the command line arguments.
 * If not found, falls back to the RAR_PASSWORD environment variable.
 * Returns null if no password is provided.
 * 
 * @return string|null The password or null if not provided
 */
function get_password() {
    global $argv;
    
    // Check for -p argument
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '-p' && isset($argv[$i + 1])) {
            return $argv[$i + 1];
        }
    }
    
    // Fallback to environment variable
    $env_password = getenv('RAR_PASSWORD');
    if ($env_password !== false) {
        return $env_password;
    }
    
    return null; // No password provided
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
    
    // Hide input
    system('stty -echo');
    $password = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";
    
    if (empty($password)) {
        echo "No password provided. Exiting.\n";
        return null;
    }
    
    return $password;
}

/**
 * Get password with encryption detection
 * 
 * Gets password from command line or environment, and if none provided,
 * checks if archive is encrypted and prompts user if needed.
 * 
 * @param string $rarfile RAR archive file path
 * @return string|null The password or null if user cancels
 */
function get_password_with_detection($rarfile) {
    $password = get_password();
    
    // If no password provided, check if archive is encrypted
    if ($password === null && file_exists($rarfile)) {
        if (is_archive_encrypted($rarfile)) {
            $password = prompt_for_password(basename($rarfile));
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
            $skip_next = true;
            continue;
        }

        $cleaned[] = $argv[$i];
    }
    
    return $cleaned;
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
        if (count($cleaned_argv) < 4 || count($cleaned_argv) > 5) usage();
        $comment = count($cleaned_argv) === 5 ? $cleaned_argv[4] : "Automated Snapshot";
        // For store command, only check password if archive exists
        $password = null;
        $rarfile_abs = get_rar_absolute_path($cleaned_argv[3]);
        if (file_exists($rarfile_abs)) {
            $password = get_password_with_detection($cleaned_argv[3]);
            if ($password === null) exit(1);
        } else {
            // For new archives, use regular password detection
            $password = get_password();
        }
        store($cleaned_argv[2], $cleaned_argv[3], $comment, $password);
        break;
    case 'list':
        if (count($cleaned_argv) !== 3) usage();
        $password = get_password_with_detection($cleaned_argv[2]);
        if ($password === null) exit(1);
        list_versions($cleaned_argv[2], $password);
        break;
    case 'restore':
        if (count($cleaned_argv) !== 5) usage();
        $password = get_password_with_detection($cleaned_argv[3]);
        if ($password === null) exit(1);
        restore($cleaned_argv[2], $cleaned_argv[3], $cleaned_argv[4], $password);
        break;
    case 'diff':
        if (count($cleaned_argv) !== 5) usage();
        $password = get_password_with_detection($cleaned_argv[4]);
        if ($password === null) exit(1);
        diff_versions($cleaned_argv[2], $cleaned_argv[3], $cleaned_argv[4], $password);
        break;
    case 'repair':
        if (count($cleaned_argv) !== 3) usage();
        $password = get_password_with_detection($cleaned_argv[2]);
        if ($password === null) exit(1);
        repair($cleaned_argv[2], $password);
        break;
    case 'passwd':
        if (count($cleaned_argv) < 2 || count($cleaned_argv) > 3) usage();
        $clear_flag = count($cleaned_argv) === 3 ? $cleaned_argv[2] : null;
        passwd($clear_flag);
        break;
    default:
        usage();
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

    // Check if we need to prompt for password encryption
    // Only prompt if: 1) archive doesn't exist (new archive), 2) RAR_PASSWORD is set, 3) no -p flag
    if (!file_exists($rarfile_abs)) {
        // Check if -p flag is present in command line arguments
        $has_p_flag = false;
        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] === '-p') {
                $has_p_flag = true;
                break;
            }
        }

        // Only prompt if no -p flag and RAR_PASSWORD is set
        if (!$has_p_flag) {
            $env_password = getenv('RAR_PASSWORD');
            if ($env_password !== false) {
                echo "Creating new archive: $rarfile_abs\n";
                echo "RAR_PASSWORD environment variable is set.\n";
                echo "Do you want to encrypt this new archive with this password? (y/N): ";
                $response = trim(fgets(STDIN));
                
                if (strtolower($response) === 'y' || strtolower($response) === 'yes') {
                    echo "Using password from RAR_PASSWORD environment variable.\n";
                    $password = $env_password;
                } else {
                    echo "Proceeding without password encryption.\n";
                }
            }
        }
    }

    // Use provided comment or default
    if (empty($comment)) {
        $comment = "Automated Snapshot";
    }

    $start = microtime(true);

    // Setup temp workspace
    $temp           = sys_get_temp_dir() . '/rarrepo_' . uniqid(mt_rand(),true);
    $tmp_files      = "$temp/files";
    $tmp_versions   = "$temp/versions";

    mkdir($tmp_files, 0777, true);
    mkdir($tmp_versions, 0777, true);

    echo "Scanning files in '$folder'...\n";

    // First pass: Record files, parent dirs, and prepare deduplication
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)
    );

    $manifest       = [];
    $known_hashes   = get_archive_hashes($rarfile_abs, $password);
    $seen_hashes    = [];
    $file_count     = 0;
    $add_count      = 0;
    $already_count  = 0;
    $parent_dirs    = [];

    foreach ($it as $file) {
        $fullpath   = $file->getPathname();
        $rel        = ltrim(substr($fullpath, strlen($folder)), '/');

        if ($file->isFile()) {
            // Record all parent directories (for later use)
            $parts = explode('/', $rel);

            for ($i = 1; $i < count($parts); $i++) {
                $dir = implode('/', array_slice($parts, 0, $i));
                $parent_dirs[$dir] = 1; // Mark as containing at least one file
            }

            $md5        = md5_file($fullpath);
            $manifest[] = [$rel, $md5];

            if (isset($seen_hashes[$md5])) {
                $already_count++;
            } elseif (!isset($known_hashes[$md5])) {
                copy($fullpath, "$tmp_files/$md5");
                $add_count++;
            } else {
                $already_count++;
            }

            $seen_hashes[$md5] = true;
            $file_count++;

            if ($file_count % 100 === 0) {
                echo "\r  Processed $file_count files...";
            }
        }
    }

    // Second pass: find empty dirs (dir that does NOT appear as a parent in $parent_dirs)
    $empty_dirs = [];

    $dirit = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($dirit as $file) {
        if ($file->isDir()) {
            $rel = ltrim(substr($file->getPathname(), strlen($folder)), '/');

            if ($rel === '') {
                continue; // skip root
            }

            if (!isset($parent_dirs[$rel])) {
                $empty_dirs[] = $rel;
            }
        }
    }

    foreach ($empty_dirs as $dir) {
        $manifest[] = [$dir, '[DIR]'];
    }

    // Add newline after progress updates
    if ($file_count > 0) {
        echo "\n";
    }

    echo "Scan complete.\n";
    echo "Total files scanned:          " . number_format($file_count) . "\n";
    echo "Unique new files to add:      " . number_format($add_count) . "\n";
    echo "Duplicate files:              " . number_format($already_count) . "\n";
    echo "Empty directories to record:  " . number_format(count($empty_dirs)) . "\n";

    // Check if this snapshot is identical to the last one
    $last_manifest = get_last_manifest($rarfile, $password);
    $current_manifest_content = implode("\n", array_map(fn($p) => "{$p[0]}\t{$p[1]}", $manifest)) . "\n";
    
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
    echo "  Snapshot manifest:      $manifest_filename\n";
    echo "  Comment Filename:       $comment_filename\n";
    echo "  Empty dirs recorded:    " . count($empty_dirs) . "\n";
    echo "  bitfreeze.php and README.txt included at archive root.\n";
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
            echo "Use -p password or set RAR_PASSWORD environment variable.\n";
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

    mkdir($temp, 0777, true);
    
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
    if (!file_exists($rarfile)) {
        echo "Repository archive not found.\n";
        exit(1);
    }

    $manifest = find_manifest_for_snapshot($rarfile, $snapshot_id, $password);

    if (!$manifest) {
        echo "Snapshot ID $snapshot_id not found.\n";
        exit(1);
    }

    echo "Restoring snapshot $snapshot_id: {$manifest['name']} ...\n";

    $temp = sys_get_temp_dir() . '/rarrepo_' . uniqid(mt_rand(),true);

    mkdir($temp, 0777, true);

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
            echo "Use -p password or set RAR_PASSWORD environment variable.\n";
        } else {
            echo "ERROR: Manifest not extracted (exit code: $code1).\n";
        }
        exec('rm -rf ' . escapeshellarg($temp));
        exit(1);
    }

    // 2. Gather needed hashes and filepaths
    $hashmap        = []; // hash => [path1, path2,...]
    $restorelist    = [];
    $lines          = file($manifest_path, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $l) {
        $parts = explode("\t", $l, 2);

        if (count($parts) != 2) {
            continue;
        }

        [$path, $md5] = $parts;

        if ($md5 === '[DIR]') {
            $dir = rtrim($outdir, '/') . '/' . $path;

            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            continue;
        }

        $hashmap[$md5][]    = $path;
        $restorelist[]      = [$path, $md5];
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

    foreach ($restorelist as $n => [$path, $md5]) {
        $src        = "$temp/$md5";
        $dest       = rtrim($outdir, '/') . '/' . $path;
        $destdir    = dirname($dest);

        if (!is_dir($destdir)) {
            mkdir($destdir, 0777, true);
        }

        if (file_exists($src)) {
            if (!copy($src, $dest)) {
                echo "[ERROR] Could not restore $path\n";
                $errors++;
            } else {
                $restored++;
            }
        } else {
            echo "[ERROR] Missing blob for $path (hash $md5)\n";
            $errors++;
        }

        if (($n+1) % 100 === 0) {
            echo "  Restored " . ($n+1) . "/$count\n";
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
    
    // If command failed for any reason, return null
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

    mkdir($temp, 0777, true);
    
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

    if (!$manifest1 || !$manifest2) {
        echo "One or both snapshot IDs not found.\n";
        exit(1);
    }

    echo "Comparing snapshot $version1_id: {$manifest1['name']} with snapshot $version2_id: {$manifest2['name']}...\n";

    $temp = sys_get_temp_dir() . '/rarrepo_diff_' . uniqid(mt_rand(), true);

    mkdir($temp, 0777, true);

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
            echo "Use -p password or set RAR_PASSWORD environment variable.\n";
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
 * file paths to their MD5 hashes. Skips directory entries.
 * 
 * @param string $manifest_path Path to the manifest file
 * @return array Associative array of file paths to MD5 hashes
 */
function parse_manifest($manifest_path) {
    $files = [];
    $lines = file($manifest_path, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        $parts = explode("\t", $line, 2);

        if (count($parts) == 2) {
            [$path, $hash] = $parts;

            if ($hash !== '[DIR]') {
                $files[$path] = $hash;
            }
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





