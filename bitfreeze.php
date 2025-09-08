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


// === INLINED: /src/commandManager.php ===
/**
 * CommandManager Class
 * 
 * Centralizes the main command execution functions including commit, status,
 * checkout, diff, list, and repair operations. This class orchestrates the
 * interaction between other manager classes to execute user commands.
 */


class CommandManager {
    private $display;
    private $fs_manager;
    private $archive_manager;
    private $system_manager;
    private $progress_manager;
    private $utility_manager;
    private $password_manager;
    
    public function __construct() {
        $this->display          = new DisplayManager();
        $this->fs_manager       = new FileSystemManager();
        $this->archive_manager  = new ArchiveManager();
        $this->system_manager   = new SystemManager();
        $this->progress_manager = new ProgressManager();
        $this->utility_manager  = new UtilityManager();
        $this->password_manager = new PasswordManager();
    }
    
    /**
     * Display command usage information
     * 
    * @return void
    */
    public function showUsage(): void {
        echo USAGE_TEXT;
    }
    
    /**
     * Execute commit command
     * 
     * @param string $folder Folder to commit
     * @param string $rarfile Archive file path
     * @param string $comment Commit comment
     * @param string|null $password Archive password
     * @return void
     */
    public function commit(string $folder, string $rarfile, string $comment, ?string $password = null): void {
        $folder = rtrim(realpath($folder), '/');

        if (!is_dir($folder)) {
            echo "ERROR: Folder '{$folder}' does not exist.\n";
            exit(1);
        }

        // Use provided comment or default
        if (empty($comment)) {
            $comment = "Automated Commit";
        }

        // Setup temp workspace
        $start          = microtime(true);
        $temp           = sys_get_temp_dir() . '/rarrepo_' . uniqid(mt_rand(),true);
        $tmp_files      = "$temp/files";
        $tmp_versions   = "$temp/versions";

        mkdir($tmp_files, 0700, true);
        mkdir($tmp_versions, 0700, true);

        // Check if sudo permissions will be needed early
        $sudo_password  = null;
        $fs_manager     = new FileSystemManager();

        if ($fs_manager->needsSudoPermissions($folder)) {
            // Prompt for password if needed and not provided via CLI
            if ($password !== null) {
                $sudo_password  = $password;
            } else {
                $system_manager = new SystemManager();
                $sudo_password  = $system_manager->promptForSudoPassword();
            }

            $fs_manager = new FileSystemManager($sudo_password);
        }

        // Initialize managers
        $archive_manager    = new ArchiveManager($password, $fs_manager);
        $system_manager     = new SystemManager();

        // First pass: Record files, parent dirs, and prepare deduplication
        $manifest           = [];
        $known_hashes       = $archive_manager->getHashes($rarfile);
        $seen_hashes        = [];
        $file_count         = 0;
        $add_count          = 0;
        $already_count      = 0;
        $skipped_count      = 0;    // Track files skipped during processing
        $skipped_files      = [];   // Track which files were skipped
        $skipped_reasons    = [];   // Track why files were skipped
        $parent_dirs        = [];
        $total_size         = 0;    // Track total size of all files processed
        $symlink_count      = 0;
        $args               = new ArgumentHandler();
        $follow_symlinks    = $args->getFlag('--follow-symlinks');
    
        // Get archive size before backup for comparison
        $archive_size_before = $archive_manager->getSize($rarfile);
    
        echo "\n";
        $this->display->info("🔍 Scanning and calculating hashes. [$folder]");

        $processed_count = 0;

        foreach ($fs_manager->scanDirGenerator($folder, [], null, [], $follow_symlinks) as $filepath) {
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
                    $manifest[] = $fs_manager->createSymlinkEntry($rel, $fullpath);

                    if ($real_target && $fs_manager->fileExists($real_target)) {
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
                                $md5 = $fs_manager->getFileMd5($real_target);

                                if ($md5 === false) {
                                    // Skip files we can't read
                                    $skipped_count++;
                                    $skipped_files[] = $target_rel;
                                    $skipped_reasons[$target_rel] = "Symlink target MD5 calculation failed";
                                    continue;
                                }
                                
                                // Get file size for tracking
                                $file_size  = $fs_manager->getFileMetadata($real_target)['size'];
                                $total_size += $file_size;
                                $manifest[] = $fs_manager->createManifestEntry($target_rel, $md5, $real_target);
                                
                                if (isset($seen_hashes[$md5])) {
                                    $already_count++;
                                } elseif (!isset($known_hashes[$md5])) {
                                    if ($fs_manager->copyFile($real_target, "$tmp_files/$md5")) {
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
                    $manifest[] = $fs_manager->createSymlinkEntry($rel, $fullpath);
                }
            } elseif ($file->isFile()) {
                // Record all parent directories (for later use)
                $parts = explode('/', $rel);

                for ($i = 1; $i < count($parts); $i++) {
                    $dir = implode('/', array_slice($parts, 0, $i));
                    $parent_dirs[$dir] = 1; // Mark as containing at least one file
                }

                // Try to get MD5 hash (sudo password already handled if needed)
                $md5 = $fs_manager->getFileMd5($fullpath);

                if ($md5 === false) {
                    // Skip files we can't read
                    $skipped_count++;
                    $skipped_files[] = $rel;
                    $skipped_reasons[$rel] = "MD5 calculation failed";
                    continue;
                }
                
                // Get file size for tracking
                $file_size  = $fs_manager->getFileMetadata($fullpath)['size'];
                $total_size += $file_size;
                $manifest[] = $fs_manager->createManifestEntry($rel, $md5, $fullpath);

                if (isset($seen_hashes[$md5])) {
                    $already_count++;
                } elseif (!isset($known_hashes[$md5])) {
                    if ($fs_manager->copyFile($fullpath, "$tmp_files/$md5")) {
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
                    echo "\r\033[K" . $this->display->colorize("  📁 Processed " . number_format($file_count) . " files...", DisplayManager::COLOR_CYAN);
                }
            }
        }

        echo "\r\033[K" . $this->display->colorize("  📁 Processed " . number_format($file_count) . " files...", DisplayManager::COLOR_CYAN);
        
        // Second pass: capture ALL directories with their metadata (including empty ones)
        $all_dirs = [];
        
        foreach ($fs_manager->scanDirGeneratorForDirs($folder) as $dirpath) {
            $rel = ltrim(substr($dirpath, strlen($folder)), '/');
            
            if ($rel === '') {
                continue; // skip root
            }
            
            $all_dirs[] = $rel;
        }
        
        foreach ($all_dirs as $dir) {
            $fullpath = $folder . '/' . $dir;
            $manifest[] = $fs_manager->createDirectoryEntry($dir, $fullpath);
        }

        // Sort manifest by path for consistent ordering
        // Since manifest contains tab-delimited strings, extract path from first column
        usort($manifest, function($a, $b) {
            $path_a = explode("\t", $a)[0];
            $path_b = explode("\t", $b)[0];
            return strcmp($path_a, $path_b);
        });

        // Get next commit ID
        $next_id = $this->utility_manager->getNextCommitId($rarfile, $password);
        
        // Create commit timestamp
        $timestamp = time();
        
        // Create commit filename using consistent helper method
        $commit_filename    = $this->createManifestFilename($next_id, $timestamp);
        $commit_path        = "$tmp_versions/$commit_filename";

        // Write manifest to commit file
        // The manifest contains tab-delimited strings, not arrays
        $fs_manager->writeFileContents($commit_path, implode("\n", $manifest));

        // Add comment to commit file
        $fs_manager->writeFileContents($commit_path, "\n\n# " . $comment . "\n", FILE_APPEND);

        // For new archives (first commit), include bitfreeze.php script and README.txt for self-contained archives
        $include_script_and_readme = ($next_id === 1);

        if ($include_script_and_readme) {
            // Place a copy of the current script at archive root for self-contained archives
            copy(__FILE__, "$temp/bitfreeze.php");
            
            // Generate README.txt at archive root
            file_put_contents("$temp/README.txt", README_TEXT);
        }

        // Create RAR command - change to temp directory to avoid full path in archive
        $current_dir = getcwd();
        chdir($temp);
        
        // Build list of ALL items to add to archive in a single operation
        $items_to_add = ["versions/$commit_filename"];
        $total_items = 1;
        
        // Add script and README for new archives
        if ($include_script_and_readme) {
            if (file_exists("bitfreeze.php")) {
                $items_to_add[] = "bitfreeze.php";
                $total_items++;
            }
            if (file_exists("README.txt")) {
                $items_to_add[] = "README.txt";
                $total_items++;
            }
        }
        
        // Add files directory if there are new files to add
        if ($add_count > 0) {
            $items_to_add[] = "files";
            $total_items += $add_count; // Add count of individual files
        }
        
        // Execute single RAR command for all items
        $rar_cmd = $this->generateRarArchiveCommand($rarfile, implode(" ", array_map('escapeshellarg', $items_to_add)), $password, $args->getFlag('--low-priority'));
        
        $this->display->header("WRITING DATA");
        echo "\n📦 Committing changes to the repository...\n";
        $this->progress_manager->executeRarWithProgress($rar_cmd, $total_items);
        
        // Change back to original directory
        chdir($current_dir);

        // Cleanup temp workspace
        $system_manager->cleanupTempDir($temp);

        // Calculate and display statistics
        $duration           = microtime(true) - $start;
        $archive_size_after = $this->archive_manager->getSize($rarfile);
        $size_diff          = $archive_size_after - $archive_size_before;
        
        echo "\n";
        $this->display->success("✅ Scan complete!");
        
        // Display scan results in a table
        $this->display->header("SCAN RESULTS");
        
        // Count directories
        $dir_count = count($parent_dirs);
        
        $scan_data = [
            ['Files Scanned', number_format($file_count)],
            ['Unique Files to Add', number_format($add_count)],
            ['Duplicate Files', number_format($already_count)],
            ['Skipped Files', number_format($skipped_count)],
            ['Directories Included', number_format($dir_count)],
            ['Total Size', $this->formatFileSizeInline($total_size)]
        ];
        
        if ($symlink_count > 0) {
            $symlink_text = $follow_symlinks ? "followed" : "stored as links";
            $scan_data[] = ['Symbolic Links', number_format($symlink_count) . " ($symlink_text)"];
        }
        
        $widths = [25, 20];
        foreach ($scan_data as $row) {
            $this->display->tableRow($row, $widths);
        }
        
        if ($skipped_count > 0) {
            echo "\n";
            $this->display->warning("⚠️  Some files were skipped due to permission issues:");
            foreach (array_slice($skipped_files, 0, 5) as $skipped_file) {
                $reason = $skipped_reasons[$skipped_file] ?? "Unknown reason";
                echo $this->display->colorize("    • $skipped_file ($reason)", $this->display::COLOR_YELLOW) . "\n";
            }
            if (count($skipped_files) > 5) {
                echo $this->display->colorize("    ... and " . (count($skipped_files) - 5) . " more", $this->display::COLOR_YELLOW) . "\n";
            }
        }

        // Get commit ID for summary (use the actual commit ID that was created)
        $commit_id = $next_id;
        $timestamp = date('Y-m-d H:i:s', $timestamp);
        $manifest_filename = $commit_filename;
        
        // Calculate compression statistics
        $compression_stats = $this->calculateCompressionStats($total_size, $archive_size_after);

        $this->display->header("COMMIT SUMMARY");
        
        $summary_data = [
            ['Commit ID', $commit_id],
            ['Commit Manifest', $manifest_filename],
            ['Comment', $comment],
            ['Time Elapsed', $this->formatDurationInline($duration)],
            ['Files Scanned', number_format($file_count)],
            ['Total Size', $this->formatFileSizeInline($total_size)],
            ['Unique Files Added', number_format($add_count)],
            ['Duplicate Files', number_format($already_count)],
            ['Files Skipped', number_format($skipped_count)],
            ['Directories Recorded', number_format($dir_count)]
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
            $this->display->tableRow($row, $widths);
        }
        
        // Display compression statistics
        $this->display->header("REPOSITORY SIZE & COMPRESSION");
        
        $compression_data = [
            ['Original Size', $compression_stats['original_formatted']],
            ['Repository Size', $compression_stats['archive_formatted']],
        ];
        
        // Show size difference with appropriate label
        if ($compression_stats['difference'] >= 0) {
            $compression_data[] = ['Size Reduction', $compression_stats['difference_formatted']];
        } else {
            $compression_data[] = ['Size Increase', $this->formatFileSizeInline(abs($compression_stats['difference']))];
        }
        
        $compression_data[] = ['Compression Ratio', $compression_stats['ratio_formatted']];
        
        foreach ($compression_data as $row) {
            $this->display->tableRow($row, $widths);
        }
        
        echo "\n";
    }
    
    /**
     * Count files that will be added to the archive
     * 
     * @param string $temp_dir Temporary directory containing files to archive
     * @return int Number of files to be archived
     */
    private function countFilesToArchive(string $temp_dir): int {
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
     * Add nice level to RAR command
     * 
     * @param string $rar_cmd Base RAR command
     * @return string RAR command with nice level
     */
    private function addNiceToRarCommand(string $rar_cmd): string {
        $args = new ArgumentHandler();
        $nice_level = $args->getFlag('--low-priority') ? 10 : 1;
        return "nice -n $nice_level $rar_cmd";
    }
    
    /**
     * Calculate compression ratio and size difference
     * 
     * @param int $original_size Total size of original files in bytes
     * @param int $archive_size Size of archive file in bytes
     * @return array Array with compression statistics
     */
    private function calculateCompressionStats(int $original_size, int $archive_size): array {
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
        $ratio = (($original_size - $archive_size) / $original_size) * 100;
        $difference = $original_size - $archive_size;
        
        return [
            'ratio'                 => $ratio,
            'difference'            => $difference,
            'ratio_formatted'       => number_format($ratio, 1) . '%',
            'difference_formatted'  => $this->formatFileSizeInline($difference),
            'original_formatted'    => $this->formatFileSizeInline($original_size),
            'archive_formatted'     => $this->formatFileSizeInline($archive_size)
        ];
    }
    
    /**
     * Format commit information for display
     * 
     * @param array $manifest Manifest array with 'id' and 'ts' keys
     * @return string Formatted commit information
     */
    private function formatCommitDisplay(array $manifest): string {
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
     * Format file size for display (inline helper)
     * 
     * @param int $bytes Size in bytes
     * @return string Formatted size string
     */
    private function formatFileSizeInline(int $bytes): string {
        $units  = ['Bytes', 'KiloBytes', 'MegaBytes', 'GigaBytes', 'TeraBytes'];
        $bytes  = max($bytes, 0);
        $pow    = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow    = min($pow, count($units) - 1);
        $bytes  /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Format duration for display (inline helper)
     * 
     * @param float $seconds Duration in seconds
     * @return string Formatted duration string
     */
    private function formatDurationInline(float $seconds): string {
        if ($seconds < 1) {
            return number_format($seconds * 1000, 0) . 'ms';
        }
        
        if ($seconds < 60) {
            return number_format($seconds, 2) . 's';
        }
        
        if ($seconds < 3600) {
            $minutes    = floor($seconds / 60);
            $r_seconds  = $seconds % 60;

            return $minutes . 'm ' . number_format($r_seconds, 0) . 's';
        }
        
        $hours      = floor($seconds / 3600);
        $r_minutes  = floor(($seconds % 3600) / 60);

        return $hours . 'h ' . $r_minutes . 'm';
    }
    
    /**
     * Execute status command
     * 
     * @param string $folder Folder to check status
     * @param string $rarfile Archive file path
     * @param string|null $password Archive password
     * @param bool $include_meta Whether to include metadata changes
     * @param bool $include_checksum Whether to include checksum changes
     * @return void
     */
    public function status(string $folder, string $rarfile, ?string $password = null, bool $include_meta = false, bool $include_checksum = false): void {
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

        // Check if sudo permissions will be needed early
        $fs_manager     = new FileSystemManager();
        $system_manager = new SystemManager();
        $sudo_password  = null;

        if ($fs_manager->needsSudoPermissions($folder)) {
            if ($password !== null) {
                $sudo_password = $password;
            } else {
                $sudo_password = $system_manager->promptForSudoPassword();
            }

            $fs_manager = new FileSystemManager($sudo_password);
        }
        
        if (!$fs_manager->fileExists($rarfile)) {
            echo "ERROR: Archive '{$rarfile}' does not exist.\n";
            exit(1);
        }

        $this->display->header("STATUS ANALYSIS");
        $this->display->info("🔍 Analyzing folder: $folder");
        $this->display->info("📦 Repository: $rarfile");

        // Initialize ArchiveManager
        $archive_manager = new ArchiveManager($password, $fs_manager);

        // Get the latest manifest from the repository
        $latest_manifest = $archive_manager->getLastManifest($rarfile);
        
        if (!$latest_manifest) {
            echo "ERROR: No commits found in repository.\n";
            exit(1);
        }

        $this->display->info("📋 Latest " . $this->display->formatCommitDisplay($latest_manifest));

        // Extract the latest manifest to temp
        $temp           = $system_manager->createTempDir('rarrepo_status_');
        $manifest_path  = $archive_manager->extractManifest($rarfile, $latest_manifest['name'], $temp);

        if ($manifest_path === false) {
            echo "ERROR: Failed to extract manifest from repository.\n";
            $system_manager->cleanupTempDir($temp);
            exit(1);
        }

        // Parse the manifest
        $utility_manager    = new UtilityManager();
        $manifest_lines     = file($manifest_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $archive_files      = [];

        foreach ($manifest_lines as $line) {
            $entry = $utility_manager->parseManifestEntry($line);

            if ($entry && isset($entry['path'])) {
                $archive_files[$entry['path']] = $entry;
            }
        }

        // Scan current folder
        $current_files  = [];
        //$current_dirs   = [];
        
        foreach ($fs_manager->scanDirGenerator($folder, [], null, [], false) as $filepath) {
            $file       = new SplFileInfo($filepath);
            $fullpath   = $file->getPathname();
            $rel        = ltrim(substr($filepath, strlen($folder)), '/');
            
            if ($file->isFile()) {
                $current_files[$rel] = [
                    'path'      => $rel,
                    'fullpath'  => $fullpath,
                    'is_file'   => true
                ];
            }
            // elseif ($file->isDir()) {
            //     $current_dirs[$rel] = true;
            // }
        }

        // Add directories from manifest that don't exist in current scan
        // foreach ($archive_files as $path => $entry) {
        //     if (isset($entry['hash']) && $entry['hash'] === '[DIR]') {
        //         $current_dirs[$path] = true;
        //     }
        // }

        // Analyze changes
        $new_files = [];
        $mod_files = [];
        $del_files = [];
        $mta_files = [];
        $md5_files = [];

        // Find new and modified files
        foreach ($current_files as $rel_path => $file_info) {
            if (!isset($archive_files[$rel_path])) {
                $new_files[] = $rel_path;
            } else {
                $archive_entry = $archive_files[$rel_path];
                
                // Check if file content has changed
                $current_md5 = $fs_manager->getFileMd5($file_info['fullpath']);

                if ($current_md5 !== false && $current_md5 !== $archive_entry['hash']) {
                    // Check if this is a checksum change (content changed but date didn't)
                    if ($include_checksum) {
                        $current_meta = $fs_manager->getFileMetadata($file_info['fullpath']);
                        $archive_meta = $archive_entry['metadata'];
                        
                        if ($current_meta['mtime'] === $archive_meta['mtime']) {
                            // Content changed but modification date is the same - possible corruption!
                            $md5_files[] = [
                                'path'          => $rel_path,
                                'current_hash'  => $current_md5,
                                'archive_hash'  => $archive_entry['hash'],
                                'mtime'         => $current_meta['mtime']
                            ];
                        } else {
                            // Normal modification - content and date both changed
                            $mod_files[] = $rel_path;
                        }
                    } else {
                        // Checksum detection not enabled, treat as normal modification
                        $mod_files[] = $rel_path;
                    }
                } elseif ($include_meta) {
                    // Check metadata changes
                    $current_meta = $fs_manager->getFileMetadata($file_info['fullpath']);
                    $archive_meta = $archive_entry['metadata'];
                    
                    if ($current_meta['permissions'] !== $archive_meta['permissions'] || $current_meta['owner'] !== $archive_meta['owner'] || $current_meta['group'] !== $archive_meta['group']) {
                        $mta_files[] = [
                            'path'      => $rel_path,
                            'current'   => $current_meta,
                            'archive'   => $archive_meta
                        ];
                    }
                }
            }
        } // end foreach current_files

        // Find deleted files
        foreach ($archive_files as $rel_path => $entry) {
            if (isset($entry['hash']) && $entry['hash'] !== '[DIR]' && !isset($current_files[$rel_path])) {
                $del_files[] = $rel_path;
            }
        }

        // Display results
        $this->display->header("CHANGES DETECTED");

        // New files
        $this->display->header("NEW FILES", "-", 50);

        if (!empty($new_files)) {
            foreach ($new_files as $file) {
                echo "  + $file\n";
            }
        } else {
            echo "  ✅ No new files\n";
        }

        // Modified files
        $this->display->header("MODIFIED FILES", "-", 50);

        if (!empty($mod_files)) {
            foreach ($mod_files as $file) {
                echo "  ~ $file\n";
            }
        } else {
            echo "  ✅ No modified files\n";
        }

        // Deleted files
        $this->display->header("DELETED FILES", "-", 50);

        if (!empty($del_files)) {
            foreach ($del_files as $file) {
                echo "  - $file\n";
            }
        } else {
            echo "  ✅ No deleted files\n";
        }

        // Metadata changes
        $this->display->header("METADATA CHANGES", "-", 50);

        if ($include_meta && !empty($mta_files)) {
            foreach ($mta_files as $change) {
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
                    echo "     Group: {$archive['group']} → {$current['owner']}\n";
                }

                echo "\n";
            }
        } elseif ($include_meta) {
            echo "  ✅ No metadata changes\n";
        }

        // Checksum changes (content changed but date didn't)
        if ($include_checksum && !empty($md5_files)) {
            // Custom red header for checksum changes
            $header_text    = "CHECKSUM CHANGES";
            $line_length    = max(50, strlen($header_text) + 4);
            $line           = str_repeat('!', $line_length);
            $padding        = $line_length - strlen($header_text);
            $left           = floor($padding / 2);
            $right          = $padding - $left;
            $centered_text  = str_repeat(' ', $left - 1) . $header_text . str_repeat(' ', $right - 1);
            
            echo "\n" . $this->display->colorize($line, DisplayManager::COLOR_RED) . "\n";
            echo $this->display->colorize($centered_text, DisplayManager::COLOR_BOLD . DisplayManager::COLOR_RED) . "\n";
            echo $this->display->colorize($line, DisplayManager::COLOR_RED) . "\n\n";
            
            $this->display->warning("⚠️ Files with changed content but unchanged modification dates:");

            foreach ($md5_files as $change) {
                echo $this->display->colorize("  🔍 {$change['path']}", DisplayManager::COLOR_YELLOW) . "\n";
                echo $this->display->colorize("     Current Hash: {$change['current_hash']}", DisplayManager::COLOR_YELLOW) . "\n";
                echo $this->display->colorize("     Archive Hash: {$change['archive_hash']}", DisplayManager::COLOR_YELLOW) . "\n";
                echo $this->display->colorize("     Modification Date: " . date('Y-m-d H:i:s', $change['mtime']), DisplayManager::COLOR_YELLOW) . "\n\n";
            }
        } elseif ($include_checksum) {
            $this->display->header("CHECKSUM CHANGES", "!", 50);
            echo "  ✅ No checksum changes detected\n";
        }

        // Summary
        $this->display->header("SUMMARY");

        $summary_data = [
            ['New Files', count($new_files)],
            ['Modified Files', count($mod_files)],
            ['Deleted Files', count($del_files)]
        ];
        
        if ($include_meta) {
            $summary_data[] = ['Metadata Changes', count($mta_files)];
        }
        
        if ($include_checksum) {
            $summary_data[] = ['Checksum Changes', count($md5_files)];
        }
        
        $summary_data[] = ['Total Changes', count($new_files) + count($mod_files) + count($del_files) + ($include_meta ? count($mta_files) : 0) + ($include_checksum ? count($md5_files) : 0)];
        
        // Calculate optimal column widths using DisplayManager
        $widths = $this->display->calculateOptimalColumnWidths($summary_data);
        
        foreach ($summary_data as $row) {
            $this->display->tableRow($row, $widths);
        }

        // Clean up
        $system_manager->cleanupTempDir($temp);
    }

    /**
     * Execute checkout command
     * 
     * @param string $commit_id Commit ID to checkout
     * @param string $rarfile Archive file path
     * @param string $outdir Output directory
     * @param string|null $password Archive password
    * @return void
    */
    public function checkout(string $commit_id, string $rarfile, string $outdir, ?string $password = null): void {
        $args           = new ArgumentHandler();
        $system_manager = new SystemManager();
        $parent         = dirname($outdir);
        $low_priority   = $args->getFlag('--low-priority') ? 'LOW PRIORITY' : '';

        $this->display->header("CHECKOUT COMMIT $commit_id $low_priority");
        
        if (!is_writable($parent)) {
            echo "ERROR: Cannot checkout to $outdir: Permission denied\n";
            exit(1);
        }

        // Check if we need sudo for permission restoration
        $sudo_password  = null;
        $is_root        = function_exists('posix_getuid') && posix_getuid() === 0;
        
        if ($password !== null) {
            $sudo_password = $password;
        } elseif (!$is_root) {
            // Only check sudo privileges if we don't have a password and aren't root
            if ($system_manager->canElevatePrivileges()) {
                $sudo_password = $system_manager->promptForSudoPassword('write');
            }
        }
        
        $fs_manager = new FileSystemManager($sudo_password);
        
        if (!$fs_manager->fileExists($rarfile)) {
            echo "Repository not found.\n";
            exit(1);
        }

        
        $archive_manager    = new ArchiveManager($password, $fs_manager); // Initialize managers
        $utility_manager    = new UtilityManager();
        $encrypted          = $archive_manager->isEncrypted($rarfile) ? 'ENCRYPTED' : 'NOT ENCRYPTED'; // Check if archive is encrypted using the password-enabled ArchiveManager
        $manifest           = $archive_manager->findManifestForCommit($rarfile, $commit_id);

        $this->display->header("CHECKOUT $encrypted COMMIT $commit_id $low_priority");

        if (!$manifest) {
            echo "Commit ID $commit_id not found.\n";
            exit(1);
        }

        echo "Preparing commit $commit_id for checkout.\n";
        echo "Manifest: " . str_replace('versions/', '', $manifest['name']) . "\n"; # TODO: Standardize manifest name

        // 1. Extract manifest
        $temp           = $system_manager->createTempDir('rarrepo_');
        $manifest_path  = $archive_manager->extractManifest($rarfile, $manifest['name'], $temp);

        if ($manifest_path === false) {
            echo "ERROR: Failed to extract manifest from archive.\n";
            $system_manager->cleanupTempDir($temp);
            exit(1);
        }

        // 2. Gather needed hashes and filepaths
        $hashmap        = []; // hash => [path1, path2,...]
        $restorelist    = [];
        $dir_metadata   = []; // Store directory metadata for restoration after files
        $slink_fb_dirs  = []; // Track symlinks that fell back to directories
        $lines          = file($manifest_path, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    
        // Get current user info for permission restoration
        $is_root = function_exists('posix_getuid') && posix_getuid() === 0;

        foreach ($lines as $l) {
            $entry = $utility_manager->parseManifestEntry($l);
        
            if (!$entry) {
                continue;
            }

            if (isset($entry['hash']) && $entry['hash'] === '[DIR]') {
                $dir = rtrim($outdir, '/') . '/' . $entry['path'];

                if (!is_dir($dir)) {
                    // Use system mkdir command to avoid PHP mkdir timestamp issues
                    exec("mkdir -p -m 0755 " . escapeshellarg($dir)); # TODO: Should be using fileSystemManager with sudo if needed
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
                $args       = new ArgumentHandler();
                $force_dir  = $args->getFlag('--force-directory');

                if (!is_dir($link_dir)) {
                    mkdir($link_dir, 0755, true);
                }

                $symlink_created = false;

                if (!$force_dir) {
                    // Attempt to recreate the symlink
                    if (!$fs_manager->symlinkExistsAndPointsTo($link_path, $entry['target'])) {
                        // Try native symlink
                        if (!@symlink($entry['target'], $link_path)) {
                            $escaped_target     = escapeshellarg($entry['target']);
                            $escaped_link_path  = escapeshellarg($link_path);

                            if ($sudo_password !== null) {
                                // Try with sudo
                                $result = $fs_manager->executeWithSudo("ln -s $escaped_target $escaped_link_path");
                            } else {
                                // Try with direct exec
                                exec("ln -s $escaped_target $escaped_link_path", $output, $ln_result);
                            }
                        }
                    }

                    if ($fs_manager->symlinkExistsAndPointsTo($link_path, $entry['target'])) {
                        $symlink_created = true;
                        $this->display->success("✅ Symlink created: {$entry['path']} -> {$entry['target']}");
                    }
                }

                // If symlink was created, restore metadata
                if ($symlink_created && isset($entry['metadata'])) {
                    $owner_id = is_numeric($entry['metadata']['owner']) ? $entry['metadata']['owner'] : (function_exists('posix_getpwnam') ? (posix_getpwnam($entry['metadata']['owner'])['uid'] ?? null) : null);
                    $group_id = is_numeric($entry['metadata']['group']) ? $entry['metadata']['group'] : (function_exists('posix_getgrnam') ? (posix_getgrnam($entry['metadata']['group'])['gid'] ?? null) : null);

                    if ($owner_id !== null) {
                        if ($is_root) {
                            exec("lchown " . escapeshellarg($owner_id) . " " . escapeshellarg($link_path));
                        } elseif ($sudo_password !== null) {
                            $fs_manager->executeWithSudo("lchown $owner_id " . escapeshellarg($link_path));
                        }
                    }

                    if ($group_id !== null) {
                        if ($is_root) {
                            exec("lchgrp " . escapeshellarg($group_id) . " " . escapeshellarg($link_path));
                        } elseif ($sudo_password !== null) {
                            $fs_manager->executeWithSudo("lchgrp $group_id " . escapeshellarg($link_path));
                        }
                    }
                }

                if (!$symlink_created) {
                    // Symlink creation failed or --force-directory was used
                    if ($force_dir) {
                        $this->display->warning("⚠️ Force directory mode: Creating directory instead of symlink for {$entry['path']}");
                    } else {
                        $this->display->warning("⚠️ Symlink creation failed for {$entry['path']} -> {$entry['target']}");
                        $this->display->warning("   Falling back to directory creation. Files will be restored directly.");
                    }

                    // Create directory for file restoration
                    if (!is_dir($link_path)) {
                        mkdir($link_path, 0755, true); # TODO: Should be using fileSystemManager with sudo if needed?
                    }

                    // Mark this path as a directory for file restoration
                    $slink_fb_dirs[$entry['path']] = true;
                }

                continue;
            }

            // Only add file entries to hashmap and restorelist (not symlinks or directories)
            if (isset($entry['hash']) && $entry['hash'] !== '[DIR]') {
                $hashmap[$entry['hash']][] = $entry['path'];
                $restorelist[] = [$entry['path'], $entry['hash'], $entry['metadata'] ?? null];
            }
        } // end foreach

        echo "\n";
        echo "Commit $commit_id contains " . number_format(count($restorelist)) . " files (" . number_format(count($hashmap)) . " unique contents).\n";

        // 3. Extract all needed hashes (content blobs) to temp dir
        $all_hashes = array_keys($hashmap);
        $exlist     = [];
        $exlistfile = "$temp/extract.lst";

        foreach ($all_hashes as $h) {
            $exlist[] = "files/$h";
        }

        $fs_manager->writeFileContents($exlistfile, implode("\n", $exlist) . "\n");
        echo "Preparing to reconstruct " . number_format(count($all_hashes)) . " files from repository...\n";

        $rar_cmd = 'rar e';

        if ($password) {
            $rar_cmd .= ' ' . BF_ENCRYPTION_MODE . escapeshellarg($password);
        }

        $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' @"' . $exlistfile . '" ' . escapeshellarg($temp);

        // Add nice level to RAR command
        $rar_cmd        = $system_manager->addNiceToRarCommand($rar_cmd);

        $this->display->header("READING DATA");
        echo $this->display->colorize("⏳ Please wait...", DisplayManager::COLOR_CYAN) . "\n";

        // Execute RAR extraction with progress tracking
        $progress_manager = new ProgressManager();
        $code2 = $progress_manager->executeRarExtractWithProgress($rar_cmd, count($all_hashes)) ? 0 : 1;

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

            foreach ($slink_fb_dirs as $fallback_dir => $dummy) {
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

            if ($fs_manager->fileExists($src)) {
                if ($metadata) {
                    // Use enhanced restoration with metadata
                    if (!$fs_manager->restoreFileWithMetadata($src, $dest, $metadata)) {
                        echo "[ERROR] Could not checkout $path\n";
                        $errors++;
                    } else {
                        $restored++;
                    }
                } else {
                    // Fallback to basic restoration for old format
                    if (!copy($src, $dest)) { # TODO: Should be using fileSystemManager with sudo if needed
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

        echo "\rFinalizing " . number_format($count) . " of " . number_format($count) . " files";
        echo "\n";

        // Restore directory metadata after all files are processed
        foreach ($dir_metadata as $dm) {
            $dir        = $dm['dir'];
            $metadata   = $dm['metadata'];

            if (is_dir($dir)) {
                // Always restore permissions (this should work for directories we own)
                chmod($dir, octdec($metadata['permissions'])); # TODO: Should be using fileSystemManager with sudo if needed

                // Try to restore ownership - this will work if we own the directory or if we have sudo privileges
                $owner_id = is_numeric($metadata['owner']) ? $metadata['owner'] : (function_exists('posix_getpwnam') ? posix_getpwnam($metadata['owner'])['uid'] : null);
                $group_id = is_numeric($metadata['group']) ? $metadata['group'] : (function_exists('posix_getgrnam') ? posix_getgrnam($metadata['group'])['gid'] : null);

                if ($owner_id !== null) {
                    if ($is_root) {
                        chown($dir, $owner_id); # TODO: Should be using fileSystemManager with sudo if needed
                    } elseif ($sudo_password !== null) {
                        $system_manager->executeWithSudo("chown $owner_id " . escapeshellarg($dir), $sudo_password);
                    } else {
                        // Try to change ownership even without sudo (might work if we own the directory)
                        @chown($dir, $owner_id); # TODO: Should be using fileSystemManager with sudo if needed
                    }
                }

                if ($group_id !== null) {
                    if ($is_root) {
                        chgrp($dir, $group_id); # TODO: Should be using fileSystemManager with sudo if needed
                    } elseif ($sudo_password !== null) {
                        $system_manager->executeWithSudo("chgrp $group_id " . escapeshellarg($dir), $sudo_password);
                    } else {
                        // Try to change group even without sudo (might work if we own the directory)
                        @chgrp($dir, $group_id); # TODO: Should be using fileSystemManager with sudo if needed
                    }
                }

                // Always restore timestamps LAST - use PHP touch for consistency with file restoration
                // This must be done after all other operations that might modify the timestamp
                if (isset($metadata['mtime']) && isset($metadata['atime'])) {
                    touch($dir, $metadata['mtime'], $metadata['atime']); # TODO: Should be using fileSystemManager with sudo if needed
                }
            }
        }

        // Clean up
        $system_manager->cleanupTempDir($temp);

        $this->display->header("SUMMARY");

        echo "Files checked out: " . number_format($restored) . " / " . number_format($count) . "\n";

        if ($errors) {
            echo "Errors: " . number_format($errors) . "\n";
        }

        echo $this->display->colorize("All operations completed successfully", DisplayManager::COLOR_GREEN) . "\n";

        // Display resource usage summary
        $this->display->header("RESOURCE USAGE");
        
        // Note: Resource usage display commented out as these functions don't exist
        // $memory = get_memory_usage();
        // $resource_data = [
        //     ['Peak Memory Usage', format_file_size($memory['peak'])],
        //     ['Memory Limit', format_file_size($memory['limit'])],
        //     ['CPU Priority', $low_priority ? 'Low (nice 10)' : 'Reduced (nice 1)'],
        // ];
        // $widths = [25, 30];
        // foreach ($resource_data as $row) {
        //     $this->display->tableRow($row, $widths);
        // }
    }

    /**
     * Execute diff command
     * 
     * @param string $version1_id First version ID
     * @param string $version2_id Second version ID
     * @param string $rarfile Archive file path
     * @param string|null $password Archive password
     * @return void
    */
    public function diff(string $version1_id, string $version2_id, string $rarfile, ?string $password = null): void {
        if (!file_exists($rarfile)) {
            echo "Repository archive not found.\n";
            exit(1);
        }

        $system_manager     = new SystemManager();
        $archive_manager    = new ArchiveManager($password);
        $manifest1          = $archive_manager->findManifestForCommit($rarfile, $version1_id);
        $manifest2          = $archive_manager->findManifestForCommit($rarfile, $version2_id);

        // // Check for password errors (both manifests are from the same archive)
        // if (is_array($manifest1) && isset($manifest1['error']) && $manifest1['error'] === 'password') {
        //     echo "ERROR: Archive is password protected but no password provided.\n";
        //     echo "Use -p password to provide the password.\n";
        //     exit(1);
        // }

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
            $rar_cmd .= ' ' . BF_ENCRYPTION_MODE . escapeshellarg($password);
        }

        $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' ' . escapeshellarg($manifest1['name']) . ' ' . escapeshellarg($temp);

        exec($rar_cmd, $output1, $code1);

        $manifest1_path = "$temp/" . basename($manifest1['name']);

        $rar_cmd = 'rar e -inul';

        if ($password) {
            $rar_cmd .= ' ' . BF_ENCRYPTION_MODE . escapeshellarg($password);
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
        $files1 = $this->parseManifest($manifest1_path);
        $files2 = $this->parseManifest($manifest2_path);

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
        $system_manager->cleanupTempDir($temp);

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
     * Execute repair command
     * 
     * @param string $rarfile Archive file path
     * @param string|null $password Archive password
     * @return void
     */
    public function repair(string $rarfile, ?string $password = null): void {
        $this->display->header("REPAIR ARCHIVE");
        
        if (!file_exists($rarfile)) {
            echo "ERROR: Archive '$rarfile' does not exist.\n";
            exit(1);
        }
        
        $archive_manager = new ArchiveManager($password);
        
        echo "Repairing archive: $rarfile\n";
        
        if ($archive_manager->repair($rarfile)) { # TODO: Add nice level functionality
            echo "✅ Repair completed successfully.\n";
        } else {
            echo "❌ Repair failed.\n";
            exit(1);
        }
    }
    
    /**
     * Execute system backup command
     * 
     * @param string $folder Folder to backup (typically root directory)
     * @param string $rarfile Archive file path
     * @param string $comment Backup comment
     * @param string|null $password Archive password
     * @return void
     */
    public function systemBackup(string $rarfile, string $comment, ?string $password = null): void {
        // System backup always starts from root directory
        $folder = '/';

        // Use provided comment or default
        if (empty($comment)) {
            $comment = "System Backup";
        }

        // Setup temp workspace with enhanced structure for system backups
        $args               = new ArgumentHandler();
        $fs_manager         = new FileSystemManager();
        $password_manager   = new PasswordManager();
        $system_manager     = new SystemManager();
        $start              = microtime(true);
        $temp               = sys_get_temp_dir() . '/rarrepo_' . uniqid(mt_rand(),true);
        $sudo_password      = null;
        $tmp_files          = "$temp/files";
        $tmp_versions       = "$temp/versions";
        $tmp_kernels        = "$temp/kernels";
        $tmp_system_meta    = "$temp/system_meta";

        debug_echo("\r\033[K" . "⚠️ DEBUG: Setting up temp workspace: $temp\n");

        if (!mkdir($tmp_files, 0700, true)) {
            $this->display->error("❌ CRITICAL ERROR: Failed to create files directory: $tmp_files");
            exit(1);
        }

        if (!mkdir($tmp_versions, 0700, true)) {
            $this->display->error("❌ CRITICAL ERROR: Failed to create versions directory: $tmp_versions");
            exit(1);
        }

        if (!mkdir($tmp_kernels, 0700, true)) {
            $this->display->error("❌ CRITICAL ERROR: Failed to create kernels directory: $tmp_kernels");
            exit(1);
        }

        if (!mkdir($tmp_system_meta, 0700, true)) {
            $this->display->error("❌ CRITICAL ERROR: Failed to create system_meta directory: $tmp_system_meta");
            exit(1);
        }

        // Check if sudo permissions will be needed early
        if ($fs_manager->needsSudoPermissions($folder)) {
            if ($password !== null) {
                $sudo_password  = $password;
            } else {
                $sudo_password  = $system_manager->promptForSudoPassword();
            }

            $fs_manager = new FileSystemManager($sudo_password);
        }

        // Initialize ArchiveManager
        $archive_manager        = new ArchiveManager($password, $fs_manager);

        // Check if archive exists and get its size
        $archive_exists         = $fs_manager->fileExists($rarfile);
        $archive_size_before    = $archive_exists ? $archive_manager->getSize($rarfile) : 0;
        
        // First pass: Record files, parent dirs, and prepare deduplication
        $manifest               = [];
        $known_hashes           = $archive_exists ? $archive_manager->getHashes($rarfile) : [];
        $seen_hashes            = [];
        $file_count             = 0;
        $add_count              = 0;
        $already_count          = 0;
        $skipped_count          = 0;    // Track files skipped during processing
        $skipped_files          = [];   // Track which files were skipped
        $skipped_reasons        = [];   // Track why files were skipped
        $parent_dirs            = [];
        $total_size             = 0;    // Track total size of all files processed
        $symlink_count          = 0;
        $kernel_count           = 0;    // Track kernel files
        $system_meta_count      = 0;    // Track system metadata files
        
        // Get command line arguments for symlink handling and password
        $follow_symlinks        = $args->getFlag('--follow-symlinks');
        $low_priority           = $args->getFlag('--low-priority');
        
        // Handle password logic the same way as commit command
        $password = $password_manager->getPasswordFromArgs();

        if ($password === null && $args->has('-p')) {   // -p was used without value, prompt for encryption password
            $password = $password_manager->promptForEncryptionPassword();
        }
        
        if ($archive_exists) {
            echo "📦 Committing to existing repository: $rarfile\n";
        } else {
            echo "📦 Committing to new repository: $rarfile\n";
        }

        echo "\n";
        $this->display->header("SYSTEM BACKUP");
        $this->display->info("🔍 Scanning system files and calculating hashes. [$folder]");
        $processed_count = 0;
        
        // Collect system metadata first
        //echo "📋 Collecting system metadata...\n";
        $this->collectSystemMetadata($tmp_system_meta, $system_manager, $fs_manager);
        //echo "✅ System metadata collection completed\n";
        
        $start_time         = time();
        $last_progress_time = $start_time;
        
        echo "🔍 Processing started...\n";
        // echo "🔍 Starting batch file processing...\n";
        // echo "📝 Note: Files are scanned and copied to temp directory in batches, then added to RAR archive incrementally\n";
        
        // Analyze partitions and get user selection
        $this->display->info("🔍 Analyzing system partitions...");
        $partitions = $fs_manager->analyzePartitions();
        
        if (empty($partitions)) {
            $this->display->error("No partitions found. Cannot proceed with system backup.");
            exit(1);
        }
        
        // Show partition selection screen
        $selected_indices       = $this->selectPartitions($partitions);
        $selected_partitions    = [];

        foreach ($selected_indices as $index) {
            $selected_partitions[] = $partitions[$index];
        }

        // Calculate total size and validate destination space
        $total_backup_size = 0;

        foreach ($selected_partitions as $partition) {
            $total_backup_size += $partition['size'];
        }
        
        $this->display->info("📊 Total backup size: " . $this->formatBytes($total_backup_size));
        
        // Build exclusion patterns based on selected partitions
        # TODO: Move these to an exclusion manager class
        $exclude_patterns = [
            'proc',             // Entire proc directory (avoid iteration)
            'sys',              // Entire sys directory (avoid iteration)
            'tmp',              // Entire tmp directory (avoid iteration)
            'run',              // Entire run directory (avoid iteration)
            'dev',              // Entire dev directory (avoid iteration)
            'lost+found',       // Lost+found directory
            'var/cache',        // Entire cache directory (avoid iteration)
            'var/tmp',          // Entire tmp directory (avoid iteration)
            'var/log',          // Entire log directory (avoid iteration)
            'var/run',          // Entire run directory (avoid iteration)
            'var/lock',         // Entire lock directory (avoid iteration)
            'var/spool',        // Entire spool directory (avoid iteration)
            'home/*/\.*',       // Hidden directories in home (e.g., .steam, .cache)
            'home/*/\.*/*',     // All contents of hidden home directories
            'root/\.*',         // Hidden directories in root home
            'root/\.*/*',       // All contents of hidden root directories
        ];
        
        // Add patterns for non-selected partitions
        foreach ($partitions as $partition) {
            if (!in_array($partition, $selected_partitions)) {
                // Convert mount point to relative path (remove leading slash)
                $relative_mount = ltrim($partition['mount_point'], '/');
                // Exclude both the directory itself and all its contents
                $exclude_patterns[] = $relative_mount;           // media/windows
                $exclude_patterns[] = $relative_mount . '/*';    // media/windows/*
            }
        }
        
        // Get maximum batch size based on available temp space
        $max_batch_size = $fs_manager->getMaxBatchSize();
        echo "📊 Batch processing: Using " . BATCH_SIZE_PERCENTAGE . "% of available temp space (" . $this->formatBytes($max_batch_size) . " per batch)\n";
        
        // Process partition images if any partitions need imaging
        $images_info    = [];
        $cp_partitions  = array_filter($selected_partitions, function($p) { return $p['should_image']; });
        
        if (!empty($cp_partitions)) {
            $this->display->header("PARTITION IMAGING");
            $this->display->info("🔧 Creating partition images for " . count($cp_partitions) . " partition(s)...");

            // Create temporary images directory
            $tmp_images = "$temp/images";

            if (!is_dir($tmp_images)) {
                mkdir($tmp_images, 0755, true);
            }

            // Validate disk space for imaging
            $validation = $fs_manager->validateImageDiskSpace($cp_partitions, $temp, $max_batch_size);
            
            if (!$validation['valid']) {
                $this->display->error("Imaging validation failed:");
                foreach ($validation['errors'] as $error) {
                    echo "  ❌ $error\n";
                }
                exit(1);
            }
            
            if ($validation['exceeds_batch_size']) {
                $this->display->warning("⚠️ Images will exceed max batch size (" . $this->formatBytes($validation['total_estimated']) . " > " . $this->formatBytes($max_batch_size) . ")");
                echo "   Images will be processed in their own batch.\n";
            }

            echo "💾 Estimated image space needed: " . $this->formatBytes($validation['total_estimated']) . "\n";
            echo "💿 Available temp space: " . $this->formatBytes($validation['available_space']) . "\n";

            // Progress callback for imaging
            $imaging_progress_callback = function($message, $percent) {
                echo "\r\033[K" . $this->display->colorize("📸 $message (" . number_format($percent, 1) . "%)", DisplayManager::COLOR_CYAN);
            };

            // Process the images
            $imaging_result = $fs_manager->processPartitionImages($selected_partitions, $tmp_images, $imaging_progress_callback);
            
            if (!$imaging_result['success']) {
                echo "\n";
                $this->display->error("Imaging failed:");
                foreach ($imaging_result['errors'] as $error) {
                    echo "  ❌ $error\n";
                }
                exit(1);
            }
            
            $images_info = $imaging_result['images_info'];
            
            echo "\n";
            $this->display->success("✅ Successfully created " . count($images_info) . " partition image(s)");
            
            if (!empty($imaging_result['errors'])) {
                $this->display->warning("⚠️  Some imaging operations had issues:");
                foreach ($imaging_result['errors'] as $error) {
                    echo "  ⚠️  $error\n";
                }
            }

            echo "\n";
        }

        // Collect files for batch processing
        $file_batch         = [];
        $current_batch_size = 0;
        $batch_number       = 1;
        $gen_dbg_count      = 0;

        debug_echo("\r\033[K" . "⚠️ DEBUG: Starting file scan for: $folder\n");

        foreach ($fs_manager->scanDirGenerator($folder, $exclude_patterns, null, $selected_partitions, $follow_symlinks) as $filepath) {
            $gen_dbg_count++;
            
            // Debug: Show what files we're getting from the generator
            if ($gen_dbg_count % 10000 === 0) {
                debug_echo("\r\033[K" . "📁 DEBUG: Generator yielded file #" . number_format($gen_dbg_count) . ": " . substr($filepath, 0, 80) . "...\n");
            }

            // Check for circular references using realpath
            $real_path = realpath($filepath);

            if ($real_path === false && !is_link($filepath)) {
                debug_echo("\r\033[K" . "⚠️ DEBUG: Skipping unresolvable path: $filepath\n");
                continue; // Skip unresolvable paths (circular references)
            }

            // For symlinks, we need to handle broken targets differently
            if (is_link($filepath)) {
                if ($real_path === false) {
                    // Broken symlink - still process it, but use original path
                    $file       = new SplFileInfo($filepath);
                    $fullpath   = $filepath;
                } else {
                    // Valid symlink - use resolved path
                    $file       = new SplFileInfo($real_path);
                    $fullpath   = $real_path;
                }
            } else {
                // Regular file - use the realpath we already calculated
                $file       = new SplFileInfo($real_path);
                $fullpath   = $real_path;
            }

            $rel = ltrim(substr($fullpath, strlen($folder)), '/');

            // Check if this file should be excluded based on exclusion patterns
            if ($fs_manager->shouldSkipFile($rel, $exclude_patterns)) {
                debug_echo("\r\033[K" . "⚠️ DEBUG: Skipping excluded file: $rel\n");

                $skipped_count++;
                $skipped_files[] = $rel;
                $skipped_reasons[$rel] = "File excluded by pattern";
                continue;
            }
            
            if ($file->isLink()) {
                // Handle symbolic links first (before checking isFile) - identical to commit command
                $symlink_count++;
                
                if ($follow_symlinks) {
                    // Enhanced symlink handling: store both symlink info AND target files
                    $real_target = realpath($fullpath);
                        
                    // Always store the symlink information for checkout
                    $manifest[] = $fs_manager->createSymlinkEntry($rel, $fullpath);
                    
                    if ($real_target && $fs_manager->fileExists($real_target)) {
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
                                $md5 = $fs_manager->getFileMd5($real_target);

                                if ($md5 === false) {
                                    // Skip files we can't read
                                    $skipped_count++;
                                    $skipped_files[] = $target_rel;
                                    $skipped_reasons[$target_rel] = "Symlink target MD5 calculation failed";
                                    continue;
                                }
                                
                                // Get file size for tracking
                                $file_size  = $fs_manager->getFileMetadata($real_target)['size'];
                                $total_size += $file_size;
                                $manifest[] = $fs_manager->createManifestEntry($target_rel, $md5, $real_target);
                                
                                if (isset($seen_hashes[$md5])) {
                                    $already_count++;
                                } elseif (!isset($known_hashes[$md5])) {
                                    if ($fs_manager->copyFile($real_target, "$tmp_files/$md5")) {
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
                                
                                // Show real-time progress for symlink targets
                                if ($file_count % 10 === 0 || (time() - $last_progress_time) >= 10) {
                                    echo "\r\033[K" . $this->display->colorize("📁 Processed " . number_format($file_count) . " files...", DisplayManager::COLOR_BRIGHT_GREEN);
                                    $last_progress_time = time();
                                }
                            }
                        }
                    }
                } else {
                    // Store the symlink itself (not its target)
                    $manifest[] = $fs_manager->createSymlinkEntry($rel, $fullpath);
                }
            } elseif ($file->isFile()) {
                $md5 = $fs_manager->getFileMd5($fullpath);

                // Check if this is a kernel file & copy kernel file to kernels directory
                if ($this->isKernelFile($rel, $fullpath)) {
                    $kernel_count++;
                    
                    if ($md5 !== false) {
                        $kernel_filename = basename($fullpath);

                        if ($fs_manager->copyFile($fullpath, "$tmp_kernels/$kernel_filename")) {
                            $manifest[] = $fs_manager->createKernelEntry("kernels/$kernel_filename", $md5, $fullpath);
                            $file_count++; // Count kernel files too
                        }
                    }
                    continue; // Skip normal file processing for kernel files
                }

                // Record all parent directories (for later use)
                $parts = explode('/', $rel);

                for ($i = 1; $i < count($parts); $i++) {
                    $dir = implode('/', array_slice($parts, 0, $i));
                    $parent_dirs[$dir] = 1; // Mark as containing at least one file
                }

                // Get file size for batch size calculation
                $file_size = @filesize($fullpath); # TODO: Should be using fileSystemManager with sudo if needed
                if ($file_size === false) {
                    // If we can't get the file size, we can't process it
                    $skipped_count++;
                    $skipped_files[] = $rel;
                    $skipped_reasons[$rel] = "Cannot determine file size";
                    continue;
                }
                
                // Check if adding this file would exceed batch size
                if ($current_batch_size + $file_size > $max_batch_size && !empty($file_batch)) {
                    echo "\r\033[K" . $this->display->colorize("📦 Batch $batch_number full (" . $this->formatBytes($current_batch_size) . "), processing...", DisplayManager::COLOR_BRIGHT_GREEN);
                    
                    debug_echo("\r\033[K" . "🚀 DEBUG: Processing batch $batch_number with " . count($file_batch) . " files (" . $this->formatBytes($current_batch_size) . ")\n");
                    
                    // Add this batch to the repository with progress tracking
                    echo "\r\033[K" . $this->display->colorize("📦 Adding batch $batch_number to repository...", DisplayManager::COLOR_BRIGHT_GREEN);

                    if ($this->addBatchToRepository($rarfile, $file_batch, $fs_manager, $manifest, $known_hashes, $seen_hashes, $file_count, $add_count, $already_count, $skipped_count, $skipped_files, $skipped_reasons, $total_size, $processed_count, $tmp_files, $batch_number, $comment, $args->getFlag('--low-priority'), $password)) {
                        echo "\r\033[K" . $this->display->colorize("✅ Batch $batch_number added to repository, " . number_format($file_count) . " files processed...", DisplayManager::COLOR_BRIGHT_GREEN);
                        
                        // Clean up temp files for this batch to free space
                        $this->cleanupBatchTempFiles($file_batch, $tmp_files);
                    } else {
                        echo "\r\033[K" . $this->display->colorize("❌ Failed to add batch $batch_number to repository!", DisplayManager::COLOR_BRIGHT_GREEN);
                        exit(1);
                    }
                    
                    // Reset batch
                    $file_batch         = [];
                    $current_batch_size = 0;
                    $batch_number       ++;

                    debug_echo("\r\033[K" . "🔄 DEBUG: Batch $batch_number reset, continuing with next batch...\n");
                }

                // Add file to current batch
                $file_batch[] = [
                    'filepath'      => $fullpath,
                    'relative_path' => $rel,
                    'size'          => $file_size,
                    'md5'           => $md5
                ];

                $current_batch_size += $file_size;
                $file_count         ++; // Count files as they're added to batches

                // Debug: Show batch status
                if ($file_count % 1000 === 0) {
                    debug_echo("\r\033[K" . "📦 DEBUG: Batch $batch_number: " . count($file_batch) . " files, " . $this->formatBytes($current_batch_size) . " / " . $this->formatBytes($max_batch_size) . "\n");
                }
                
                // Show real-time progress every 10 files and also every 10 seconds
                if ($file_count % 10 === 0 || (time() - $last_progress_time) >= 10) {
                    echo "\r\033[K" . $this->display->colorize("📁 Processed " . number_format($file_count) . " files...", DisplayManager::COLOR_BRIGHT_GREEN);
                    $last_progress_time = time();
                }
            }
        }
        
        // Process final batch
        if (!empty($file_batch)) {
            // Add final batch to the RAR repository
            echo "\r\033[K" . $this->display->colorize("📦 Adding final batch to repository...", DisplayManager::COLOR_BRIGHT_GREEN);

            if ($this->addBatchToRepository($rarfile, $file_batch, $fs_manager, $manifest, $known_hashes, $seen_hashes, $file_count, $add_count, $already_count, $skipped_count, $skipped_files, $skipped_reasons, $total_size, $processed_count, $tmp_files, $batch_number, $comment, $args->getFlag('--low-priority'), $password)) {
                echo "\r\033[K" . $this->display->colorize("✅ Final batch added to repository, " . number_format($file_count) . " files processed...", DisplayManager::COLOR_BRIGHT_GREEN);

                // Clean up temp files for final batch
                $this->cleanupBatchTempFiles($file_batch, $tmp_files);
            } else {
                echo "\r\033[K" . $this->display->colorize("❌ Failed to add final batch to repository!", DisplayManager::COLOR_BRIGHT_GREEN);
                exit(1);
            }
        }

        // Record parent directories
        foreach ($parent_dirs as $dir => $dummy) {
            $manifest[] = $fs_manager->createDirectoryEntry($dir, $folder . '/' . $dir);
        }

        // Create system metadata manifest
        $system_meta_files = $this->getDirectoryContents($tmp_system_meta);

        foreach ($system_meta_files as $meta_file) {
            $meta_filename  = basename($meta_file);
            $md5            = $fs_manager->getFileMd5($meta_file);

            if ($md5 !== false) {
                $manifest[] = $fs_manager->createSystemMetaEntry("system_meta/$meta_filename", $md5, $meta_file);
                $system_meta_count++;
            }
        }

        // Create version manifest
        $commit_id          = $archive_manager->getNextCommitId($rarfile);
        $timestamp          = time();
        $manifest_content   = implode("\n", $manifest);
        $manifest_filename  = $this->createManifestFilename($commit_id, $timestamp);

        if (!$fs_manager->writeFileContents("$tmp_versions/$manifest_filename", $manifest_content)) {
            $this->display->error("❌ CRITICAL ERROR: Failed to create manifest file: $manifest_filename");
            $this->display->error("❌ Check permissions for directory: $tmp_versions");
            exit(1);
        }

        $this->display->success("✅ Created manifest file: $manifest_filename");

        // For new archives (first commit), include bitfreeze.php script and README.txt for self-contained archives
        $include_script_and_readme = ($commit_id === 1);

        if ($include_script_and_readme) {
            // Place a copy of the current script at archive root for self-contained archives
            copy(__FILE__, "$temp/bitfreeze.php");

            // Generate README.txt at archive root
            file_put_contents("$temp/README.txt", README_TEXT);
        }

        // Create partition images metadata if we have any images
        $has_images = false;

        if (!empty($images_info)) {
            $images_json_filename   = $this->createImagesFilename($commit_id, $timestamp);
            $images_json_path       = "$tmp_versions/$images_json_filename";

            if ($fs_manager->createImagesMetadata($images_info, $images_json_path)) {
                $has_images = true;
                $this->display->success("✅ Created images metadata: $images_json_filename");
            } else {
                $this->display->error("❌ Failed to create images metadata file");
            }
        }

        // Now we need to actually create the RAR repository with all the files
        echo "\n";
        $this->display->info("📦 Finalizing repository...");

        // Change to temp directory to avoid full path in archive (like commit command)
        $current_dir = getcwd();
        chdir($temp);
        
        // Prepare all directories and files to be added in a single RAR operation
        $total_items_to_add = 1; // manifest file

        if ($system_meta_count > 0) {
            $total_items_to_add += $system_meta_count;
        }

        if ($kernel_count > 0) {
            $total_items_to_add += $kernel_count;
        }

        if ($has_images) {
            $total_items_to_add += 1; // images.json file
            $total_items_to_add += count($images_info); // image files
        }

        // Build the RAR command to add all items at once
        $items_to_add = ["versions/$manifest_filename"];

        // Add script and README for new archives
        if ($include_script_and_readme) {
            if (file_exists("bitfreeze.php")) {
                $items_to_add[] = "bitfreeze.php";
                $total_items_to_add++;
            }

            if (file_exists("README.txt")) {
                $items_to_add[] = "README.txt";
                $total_items_to_add++;
            }
        }
        
        if ($system_meta_count > 0) {
            $items_to_add[] = "system_meta";
        }

        if ($kernel_count > 0) {
            $items_to_add[] = "kernels";
        }

        if ($has_images) {
            $items_to_add[] = "versions/$images_json_filename";
            $items_to_add[] = "images";
        }

        // Build RAR command with password if needed
        $rar_cmd = $this->generateRarArchiveCommand($rarfile, implode(" ", $items_to_add), $password, $low_priority);

        echo "\r\033[K" . $this->display->colorize("📦 Adding all files to repository in single operation...", DisplayManager::COLOR_BRIGHT_GREEN);
        $this->progress_manager->executeRarWithProgress($rar_cmd, $total_items_to_add);

        // Change back to original directory
        chdir($current_dir);

        // Verify the repository was created
        if (!$fs_manager->fileExists($rarfile)) {
            $this->display->error("❌ CRITICAL ERROR: Repository creation failed!");
            exit(1);
        }

        // Get archive size after backup for comparison
        $archive_size_after = $archive_manager->getSize($rarfile);
        $size_difference    = $archive_size_after - $archive_size_before;
        $end                = microtime(true);
        $duration           = $end - $start;

        $this->display->header("SYSTEM BACKUP COMPLETE");
        
        echo "✅ System backup completed successfully!\n\n";
        echo "📊 Backup Statistics:\n";

        // Display backup statistics in table format
        $backup_stats = [
            ['📁 Total files processed', number_format($file_count)],
            ['➕ New files added', number_format($add_count)],
            ['🔄 Existing files', number_format($already_count)],
            ['🔗 Symbolic links', number_format($symlink_count)],
            ['🐧 Kernel files', number_format($kernel_count)],
            ['📋 System metadata', number_format($system_meta_count)]
        ];
        
        if (!empty($images_info)) {
            $total_image_size   = array_sum(array_column($images_info, 'image_size'));
            $backup_stats[]     = ['📸 Partition images', number_format(count($images_info)) . " (" . $this->formatBytes($total_image_size) . ")"];
        }

        $backup_stats = array_merge($backup_stats, [
            ['⏭️ Skipped files', number_format($skipped_count)],
            ['💾 Total size processed', $this->formatBytes($total_size)],
            ['📦 Archive size change', $this->formatBytes($size_difference)],
            ['⏱️ Duration', $this->formatDuration($duration)],
            ['🆔 Commit ID', $commit_id],
            ['💬 Comment', $comment]
        ]);

        // Calculate optimal column widths using DisplayManager
        $widths = $this->display->calculateOptimalColumnWidths($backup_stats);
        
        foreach ($backup_stats as $row) {
            $this->display->tableRow($row, $widths);
        }
        echo "\n";

        if (!empty($skipped_files)) {
            $this->display->warning("⚠️ Some files were skipped:");
            foreach (array_slice($skipped_files, 0, 10) as $skipped) {
                $reason = $skipped_reasons[$skipped] ?? "Unknown reason";
                echo "    - $skipped ($reason)\n";
            }

            if (count($skipped_files) > 10) {
                echo "    ... and " . (count($skipped_files) - 10) . " more\n";
            }

            echo "\n";
        }

        $this->display->success("🎉 System backup completed successfully!");

        // Clean up
        $system_manager->cleanupTempDir($temp);
    }
    
    /**
     * Create consistent manifest filename
     * 
     * @param int $commit_id Commit ID
     * @param int $timestamp Unix timestamp
     * @return string Manifest filename
     */
    private function createManifestFilename(int $commit_id, int $timestamp): string {
        return "$commit_id-" . date('Y-m-d H-i-s', $timestamp) . ".txt";
    }
    
    /**
     * Create consistent images metadata filename
     * 
     * @param int $commit_id Commit ID
     * @param int $timestamp Unix timestamp
     * @return string Images metadata filename
     */
    private function createImagesFilename(int $commit_id, int $timestamp): string {
        return "$commit_id-" . date('Y-m-d H-i-s', $timestamp) . ".images.json";
    }
    
    /**
     * Generate RAR archive command with standard options
     * 
     * @param string $rarfile Path to RAR archive file
     * @param string $items Items to add to archive (space-separated)
     * @param string|null $password Optional password for encryption
     * @param bool $low_priority Whether to use low priority (nice)
     * @return string Complete RAR command
     */
    private function generateRarArchiveCommand(string $rarfile, string $items, ?string $password = null, bool $low_priority = false): string {
        $rar_cmd = "rar a -r -rr" . RECOVERY_RECORD_SIZE . "% -m3";

        if ($low_priority) {
            $rar_cmd = "nice -n 10 $rar_cmd";
        }

        if ($password !== null) {
            $rar_cmd .= ' ' . BF_ENCRYPTION_MODE . escapeshellarg($password);
        }

        $rar_cmd .= " " . escapeshellarg($rarfile) . " " . $items;

        return $rar_cmd;
    }
    
    /**
     * Format bytes to human readable format
     * TODO: This is a duplicate function, combine them
     */
    private function formatBytes(int $bytes): string {
        $units  = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes  = max($bytes, 0);
        $pow    = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow    = min($pow, count($units) - 1);
        $bytes  /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Format duration in seconds to human readable format
     * TODO: This is a duplicate function, combine them
     */
    private function formatDuration(float $seconds): string {
        if ($seconds < 1) {
            return number_format($seconds, 2) . ' seconds';
        } elseif ($seconds < 60) {
            return number_format($seconds, 1) . ' seconds';
        } elseif ($seconds < 3600) {
            $minutes    = floor($seconds / 60);
            $r_seconds  = $seconds % 60;

            return $minutes . ' minutes ' . number_format($r_seconds, 1) . ' seconds';
        } else {
            $hours      = floor($seconds / 3600);
            $r_minutes  = floor(($seconds % 3600) / 60);

            return $hours . ' hours ' . $r_minutes . ' minutes';
        }
    }

    /**
     * Check if a file is a kernel-related file
     * 
     * @param string $relative_path Relative path from backup root
     * @param string $full_path Full file path
     * @return bool True if kernel file, false otherwise
     */
    private function isKernelFile(string $relative_path, string $full_path): bool {
        // Kernel images
        if (strpos($relative_path, 'boot/vmlinuz-') === 0) {
            return true;
        }

        // Initramfs files
        if (strpos($relative_path, 'boot/initrd.img-') === 0) {
            return true;
        }

        // GRUB configuration
        if (strpos($relative_path, 'boot/grub/') === 0) {
            return true;
        }

        // EFI boot files
        if (strpos($relative_path, 'boot/efi/') === 0) {
            return true;
        }

        // Kernel modules (optional - can be large)
        if (strpos($relative_path, 'lib/modules/') === 0) {
            return true;
        }

        return false;
    }

    /**
     * Collect system metadata for backup
     * 
     * @param string $meta_dir Directory to store metadata files
     * @param SystemManager $system_manager System manager instance
     * @return void
     */
    private function collectSystemMetadata(string $meta_dir, SystemManager $system_manager, FileSystemManager $fs_manager): void {
        // Package list
        $this->collectPackageList("$meta_dir/package_list.txt");
        
        // Partition information
        $this->collectPartitionInfo("$meta_dir/partition_info.json", $fs_manager);
        
        // Mount points
        $this->collectMountPoints("$meta_dir/mount_points.json", $fs_manager);
        
        // Boot configuration
        $this->collectBootConfig("$meta_dir/boot_config.json", $fs_manager);
        
        // System information
        $this->collectSystemInfo("$meta_dir/system_info.json", $fs_manager);
    }

    /**
     * Collect installed package list
     * 
     * @param string $output_file Output file path
     * @return void
     */
    private function collectPackageList(string $output_file): void {
        // Map of package manager binary => command to list packages
        $package_managers = [
            'dpkg'   => 'dpkg -l > %s 2>/dev/null',             // Debian/Ubuntu
            'rpm'    => 'rpm -qa > %s 2>/dev/null',             // Red Hat/CentOS
            'pacman' => 'pacman -Q > %s 2>/dev/null',           // Arch
            'zypper' => 'zypper packages > %s 2>/dev/null',     // SUSE
        ];

        foreach ($package_managers as $bin => $cmd_template) {
            // Check if the binary exists
            $check_installed = sprintf("command -v %s", escapeshellarg($bin));
            exec($check_installed, $output, $return_code);
            if ($return_code === 0) {
                // Found the package manager
                $cmd = sprintf($cmd_template, escapeshellarg($output_file));
                exec($cmd, $o, $rc);
                if ($rc === 0) {
                    return; // Success
                }
            }
        }
    }

    /**
     * Collect partition information
     * 
     * @param string $output_file Output file path
     * @return void
     */
    private function collectPartitionInfo(string $output_file, FileSystemManager $fs_manager): void {
        $partitions = [];

        // Get partition table info
        exec('parted -l 2>/dev/null', $parted_output, $parted_return_code);
        if ($parted_return_code === 0) {
            $partitions['parted_output'] = implode("\n", $parted_output);
        } else {
            $partitions['parted_output'] = "Failed to get output from parted.";
        }

        // Get block device info
        exec('lsblk -f 2>/dev/null', $lsblk_output, $lsblk_return_code);
        if ($lsblk_return_code === 0) {
            $partitions['lsblk_output'] = implode("\n", $lsblk_output);
        } else {
            $partitions['lsblk_output'] = "Failed to get output from lsblk.";
        }

        // Get fstab
        if ($fs_manager->fileExists('/etc/fstab') && $fs_manager->isFileReadable('/etc/fstab')) {
            $fstabContent = $fs_manager->readFileContents('/etc/fstab');
            $partitions['fstab'] = ($fstabContent !== false) ? $fstabContent : "Error reading file: unknown error";
        } else {
            $partitions['fstab'] = "File /etc/fstab not found or is not readable.";
        }

        // Encode and write safely
        $json = json_encode($partitions, JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Failed to encode partition info to JSON: ' . json_last_error_msg());
        }

        $result = $fs_manager->writeFileContents($output_file, $json);
        if ($result === false) {
            throw new RuntimeException('Failed to write partition info to file: ' . $output_file);
        }
    }

    /**
     * Collect mount point information
     * 
     * @param string $output_file Output file path
     * @return void
     */
    private function collectMountPoints(string $output_file, FileSystemManager $fs_manager): void {
        $commands = [
            'mount_output' => 'mount',
            'df_output'    => 'df -h'
        ];

        $mounts = [];
        $errors = [];

        foreach ($commands as $key => $cmd) {
            $output     = [];
            $returnCode = 0;

            exec($cmd, $output, $returnCode);

            if ($returnCode === 0) {
                $mounts[$key] = $output;
            } else {
                $errors[$key] = "Command '{$cmd}' returned code {$returnCode}";
            }
        }

        $result = ['mounts' => $mounts];

        if (!empty($errors)) {
            $result['errors'] = $errors;
        }

        $encoded = json_encode($result, JSON_PRETTY_PRINT);

        if ($encoded === false) {
            // Handle encoding error: write basic error info.
            $fs_manager->writeFileContents($output_file, json_encode(['error' => 'JSON encoding failed', 'data' => $result]));
        } else {
            $fs_manager->writeFileContents($output_file, $encoded);
        }
    }

    /**
     * Collect boot configuration
     * 
     * @param string $output_file Output file path
     * @return void
     * @return void
     */
    private function collectBootConfig(string $output_file, FileSystemManager $fs_manager): void {
        $boot_config = [];

        // /etc/default/grub
        $grub_default_path = '/etc/default/grub';
        if ($fs_manager->fileExists($grub_default_path) && $fs_manager->isFileReadable($grub_default_path)) {
            $content = $fs_manager->readFileContents($grub_default_path);
            $boot_config['grub_default'] = ($content !== false) ? $content : "Error reading file: $grub_default_path";
        } else {
            $boot_config['grub_default'] = "File not found or not readable: $grub_default_path";
        }

        // /boot/grub/grub.cfg
        $grub_cfg_path = '/boot/grub/grub.cfg';
        if ($fs_manager->fileExists($grub_cfg_path) && $fs_manager->isFileReadable($grub_cfg_path)) {
            $content = $fs_manager->readFileContents($grub_cfg_path);
            $boot_config['grub_cfg'] = ($content !== false) ? $content : "Error reading file: $grub_cfg_path";
        } else {
            $boot_config['grub_cfg'] = "File not found or not readable: $grub_cfg_path";
        }

        // /proc/cmdline
        $cmdline_path = '/proc/cmdline';
        if ($fs_manager->fileExists($cmdline_path) && $fs_manager->isFileReadable($cmdline_path)) {
            $content = $fs_manager->readFileContents($cmdline_path);
            $boot_config['kernel_cmdline'] = ($content !== false) ? $content : "Error reading file: $cmdline_path";
        } else {
            $boot_config['kernel_cmdline'] = "File not found or not readable: $cmdline_path";
        }

        $json = json_encode($boot_config, JSON_PRETTY_PRINT);

        if ($json === false) {
            throw new RuntimeException('Failed to encode boot config to JSON: ' . json_last_error_msg());
        }

        $result = $fs_manager->writeFileContents($output_file, $json);

        if ($result === false) {
            throw new RuntimeException('Failed to write boot config to file: ' . $output_file);
        }
    }

    /**
     * Collect general system information
     * 
     * @param string $output_file Output file path
     * @return void
     */
    private function collectSystemInfo(string $output_file, FileSystemManager $fs_manager): void {
        $system_info = [];

        // Parse /etc/os-release (if it exists) to array
        if ($fs_manager->isFileReadable('/etc/os-release')) {
            $content    = $fs_manager->readFileContents('/etc/os-release');
            $lines      = $content !== false ? explode("\n", $content) : [];
            $os_release = [];

            foreach ($lines as $line) {
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    // Remove surrounding quotes
                    $os_release[$key] = trim($value, "\"'");
                }
            }
            $system_info['os_release'] = $os_release;
        } else {
            $system_info['os_release'] = null;
        }

        // Kernel version
        $system_info['kernel'] = [
            'version' => php_uname('r'),
            'os_type' => php_uname('s'),
        ];

        // Architecture
        $system_info['architecture'] = php_uname('m');

        // Hostname
        $system_info['hostname'] = php_uname('n');

        // Uptime (if available)
        if ($fs_manager->isFileReadable('/proc/uptime')) {
            $uptime_content = $fs_manager->readFileContents('/proc/uptime');
            $uptime_info    = $uptime_content !== false ? explode(' ', $uptime_content) : [];

            $system_info['uptime_seconds'] = isset($uptime_info[0]) ? (float)$uptime_info[0] : null;
        }

        // Current time (ISO 8601 for portability)
        $system_info['backup_timestamp'] = date('c');

        $json = json_encode($system_info, JSON_PRETTY_PRINT);

        if ($json === false) {
            throw new RuntimeException('Failed to encode system info as JSON: ' . json_last_error_msg());
        }

        // Attempt writing with error handling
        $result = $fs_manager->writeFileContents($output_file, $json, LOCK_EX);

        if ($result === false) {
            throw new RuntimeException('Failed to write system info to ' . $output_file);
        }
    }

    /**
     * Parse manifest file
     * 
     * @param string $manifest_path Path to manifest file
     * @return array Associative array of file paths to MD5 hashes
     */
    public function parseManifest(string $manifest_path): array {
        $files = [];
        $lines = file($manifest_path, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $entry = $this->utility_manager->parseManifestEntry($line);

            if ($entry && (!isset($entry['type']) || $entry['type'] !== '[LINK]') && $entry['hash'] !== '[DIR]') {
                $files[$entry['path']] = $entry['hash'];
            }
        }

        return $files;
    }
    
    /**
     * Adds a batch of files to a RAR archive repository.
     *
     * @param string   $rarfile       The path to the archive file.
     * @param string   $source_dir    The directory containing files to add.
     * @param array    $manifest      Associative array of existing MD5 hashes: ['md5hash' => true, ...].
     * @param int      $batch_number  The batch number (for progress tracking, if needed).
     * @param string   $comment       A comment (unused here, but available).
     * @param bool     $low_priority  If true, use "nice -n 10" for lower priority.
     * @param string   $password      Optional archive password.
     * @return bool
     */
    private function addBatchToRepository(string $rarfile, array $file_batch, FileSystemManager $fs_manager, array &$manifest, array &$known_hashes, array &$seen_hashes, int &$file_count, int &$add_count, int &$already_count, int &$skipped_count, array &$skipped_files, array &$skipped_reasons, int &$total_size, int &$processed_count, string $tmp_files, int $batch_number, string $comment, bool $low_priority, ?string $password = null): bool {
        $batch_temp         = sys_get_temp_dir() . '/rarrepo_batch_' . uniqid(mt_rand(), true);
        $batch_files_dir    = $batch_temp . '/files';

        mkdir($batch_files_dir, 0700, true);

        debug_echo("\r\033[K" . "⚠️ DEBUG: Creating batch temp directory: $batch_temp\n");

        $batch_size         = count($file_batch);
        $processed_in_batch = 0;
        $copied_files       = 0;

        debug_echo("\r\033[K" . "⚠️ DEBUG: Manifest size: " . number_format(count($manifest)) . "\n");

        foreach ($file_batch as $file_info) {
            $md5 = $file_info['md5'];

            if ($md5 === false) {
                // If we can't hash the file, we can't process it
                $skipped_count++;
                $skipped_files[] = $file_info['relative_path'];
                $skipped_reasons[$file_info['relative_path']] = "MD5 calculation failed";
                $processed_in_batch++;
                continue;
            }
            
            // Add to total size
            $total_size += $file_info['size'];
            
            // Create manifest entry using the helper function
            $manifest[] = $fs_manager->createManifestEntry($file_info['relative_path'], $md5, $file_info['filepath']);

            if (isset($seen_hashes[$md5])) {
                $already_count++;
            } elseif (!isset($known_hashes[$md5])) {
                $temp_filepath = $batch_files_dir . '/' . $md5;
                
                if ($fs_manager->copyFile($file_info['filepath'], $temp_filepath)) {
                    $add_count++;
                    $copied_files++;
                } else {
                    // If copy fails, we can't process the file
                    $skipped_count++;
                    $skipped_files[] = $file_info['relative_path'];
                    $skipped_reasons[$file_info['relative_path']] = "Copy operation failed";
                    continue;
                }
            } else {
                $already_count++;
            }

            $seen_hashes[$md5] = true;
            $file_count++;
            $processed_count++;
            $processed_in_batch++;
            
            // Show real-time progress for batch processing (every 10 files to avoid spam)
            if ($processed_in_batch % 10 === 0 || $processed_in_batch === $batch_size) {
                $progress_percent   = ($processed_in_batch / $batch_size) * 100;
                $progress_bar       = $this->createProgressBar($progress_percent, 30);

                echo "\r\033[K" . $this->display->colorize("📦 Processing batch " . $batch_number . ": " . $progress_bar . " " . number_format($progress_percent, 1) . "% (" . $processed_in_batch . "/" . $batch_size . " files)", DisplayManager::COLOR_BRIGHT_GREEN);
            }
            
            // Also show a simple counter for very small batches
            if ($batch_size <= 100) {
                echo "\r\033[K" . $this->display->colorize("📦 Processing batch " . $batch_number . ": " . $processed_in_batch . "/" . $batch_size . " files", DisplayManager::COLOR_BRIGHT_GREEN);
            }
            
            // Debug: Show file details for first few files in each batch
            if ($processed_in_batch <= 5) {
                debug_echo("\r\033[K" . "🔍 DEBUG: File " . $processed_in_batch . " in batch " . $batch_number . ": " . $file_info['relative_path'] . " (MD5: " . substr($md5, 0, 8) . "...) (Size: " . $this->formatBytes($file_info['size']) . ")\n");
            }
            
            // Force output flush to ensure progress is visible
            if ($processed_in_batch % 10 === 0) {
                flush();
            }
        }

        echo "\r\033[K\n";  // Clear the progress line and move to next line
        echo "\r\033[K";    // Final progress clear

        // Gather all copied (to-be-added) files in the 'files' directory
        $batch_files_list = $this->getDirectoryContents($batch_files_dir);

        if (empty($batch_files_list)) {
            echo "No new files to add in batch $batch_number. Skipped " . number_format(count($skipped_files)) . " files already archived.\n";
            $this->cleanupTempDirectory($batch_temp);
            return true;
        }

        echo "📦 Adding " . count($batch_files_list) . " new files to RAR archive...\n";

        // Add new files to archive, under "files/" in the archive
        $current_dir = getcwd();
        chdir($batch_temp);

        // Prepare RAR command
        $rar_cmd = $this->generateRarArchiveCommand($rarfile, "files", $password, $low_priority);

        // Progress manager for RAR (if you have such a method)
        $this->progress_manager->executeRarWithProgress($rar_cmd, count($batch_files_list));

        chdir($current_dir);

        // Clean up temporary directory
        $this->cleanupTempDirectory($batch_temp);

        echo "✅ Batch $batch_number complete! $copied_files new files added, " . number_format(count($skipped_files)) . " already present.\n";
        return true;
    }

    /**
     * Clean up temporary files for a processed batch
     * 
     * @param array $file_batch Array of file info arrays
     * @param string $tmp_files Temporary files directory
     * @return void
     */
    private function cleanupBatchTempFiles(array $file_batch, string $tmp_files): void {
        debug_echo("\r\033[K" . "⚠️ DEBUG: Cleaning up batch temp files: ". $tmp_files . ", total files: " . number_format(count($file_batch)) . "\n");
        
        $total_files        = count($file_batch); // Get total count of files that should be in temp directory
        $temp_files_list    = $this->getDirectoryContents($tmp_files);
        $actual_files_found = count($temp_files_list);
        $cleaned            = 0;
        $failed             = 0;
        
        foreach ($temp_files_list as $temp_file) {
            if (is_file($temp_file)) {
                if (@unlink($temp_file)) {
                    $cleaned++;
                } else {
                    $failed++;
                    // Log failed cleanup for debugging
                    debug_echo("\r\033[K" . "⚠️ DEBUG: Failed to cleanup temp file: $temp_file\n");
                }
            }
        }

        if ($failed > 0) {
            echo "\r\033[K" . $this->display->colorize("⚠️ Warning: $failed temp files could not be cleaned up", DisplayManager::COLOR_YELLOW) . "\n";
        }

        // Verify cleanup was successful
        $remaining = count($this->getDirectoryContents($tmp_files));

        if ($remaining > 0) {
            echo "\r\033[K" . $this->display->colorize("❌ Critical: $remaining temp files still exist after cleanup!", DisplayManager::COLOR_RED) . "\n";
            $this->forceCleanupDirectory($tmp_files); // Force cleanup with more aggressive approach
        } else {
            echo "\r\033[K" . $this->display->colorize("✅ Batch cleanup completed: $cleaned/$actual_files_found files removed (batch had $total_files files)", DisplayManager::COLOR_BRIGHT_GREEN) . "\n";
        }
    }

    /**
     * Clean up a temporary directory recursively
     * 
     * @param string $dir Directory to clean up
     * @return void
     */
    private function cleanupTempDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        
        // Use system command for better performance
        $escaped_dir = escapeshellarg($dir);
        exec("rm -rf $escaped_dir", $output, $return_code);
        
        if ($return_code !== 0) {
            // Log error if cleanup fails
            error_log("Failed to cleanup temp directory: $dir (exit code: $return_code)");
        }
    }
    
    /**
     * Get directory contents using system command for better performance
     * 
     * @param string $dir Directory path
     * @return array Array of file paths
     */
    private function getDirectoryContents(string $dir): array {
        if (!is_dir($dir)) {
            return [];
        }
        
        // Use system command for better performance than glob()
        $escaped_dir = escapeshellarg($dir);
        exec("find $escaped_dir -maxdepth 1 -mindepth 1 -print0 2>/dev/null", $output, $return_code);
        
        if ($return_code !== 0 || empty($output)) {
            return [];
        }
        
        // Split by null bytes and filter out empty strings
        $files = explode("\0", $output[0]);
        return array_filter($files, 'strlen');
    }

    /**
     * Force cleanup of a directory with aggressive error handling
     * 
     * @param string $dir Directory to force clean
     * @return void
     */
    private function forceCleanupDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        
        // Use system commands for more aggressive cleanup
        $escaped_dir    = escapeshellarg($dir);
        $command        = "rm -rf $escaped_dir/* 2>/dev/null";

        exec($command);

        // Verify cleanup
        $remaining = count($this->getDirectoryContents($dir));

        if ($remaining > 0) {
            echo "\r\033[K" . $this->display->colorize("🚨 Emergency: Using sudo to force cleanup remaining files", DisplayManager::COLOR_RED) . "\n";
            $command = "sudo rm -rf $escaped_dir/* 2>/dev/null";
            exec($command);
        }
    }

    /**
     * Display partition selection screen and get user confirmation
     * 
     * @param array $partitions Array of partition information
     * @return array Selected partition indices
     */
    private function selectPartitions(array $partitions): array {
        // Auto-select system partitions
        $selected = [];

        foreach ($partitions as $i => $partition) {
            if ($partition['is_system']) {
                $selected[] = $i;
            }
        }

        $current = 0;
        
        while (true) {
            // Clear screen and show partitions
            system('clear');
            $this->display->header("PARTITION SELECTION");
            $this->display->info("Select which partitions to include in system backup:");
            echo "\n";

            foreach ($partitions as $i => $partition) {
                $marker         = $i === $current ? '▶' : ' ';
                $checkbox       = in_array($i, $selected) ? '[X]' : '[ ]';
                $size           = $this->formatBytes($partition['size']);
                $used           = $this->formatBytes($partition['used']);
                $free           = $this->formatBytes($partition['free']);
                $status         = $partition['is_system'] ? 'SYSTEM' : 'DATA';
                $description    = $partition['description'];

                // Color coding based on type and selection
                if (in_array($i, $selected)) {
                    $checkbox = $this->display->colorize('[X]', DisplayManager::COLOR_BRIGHT_GREEN);
                } else {
                    $checkbox = '[ ]';
                }

                $status_color   = $partition['is_system'] ? DisplayManager::COLOR_BRIGHT_GREEN : DisplayManager::COLOR_YELLOW;
                $status_text    = $this->display->colorize("[$status]", $status_color);

                // Add imaging indicator
                $imaging_indicator = '';

                if ($partition['should_image']) {
                    $imaging_indicator = $this->display->colorize(" [DD IMAGE]", DisplayManager::COLOR_CYAN);
                }

                // Compact layout - all info on one line
                echo "  $marker $checkbox {$partition['mount_point']} ($size) - $description - Used: $used, Free: $free ({$partition['usage_percent']}%) $status_text$imaging_indicator\n";
            }

            // Show selection summary
            if (!empty($selected)) {
                $selected_size  = 0;
                $system_count   = 0;
                $data_count     = 0;

                foreach ($selected as $index) {
                    $selected_size += $partitions[$index]['size'];

                    if ($partitions[$index]['is_system']) {
                        $system_count++;
                    } else {
                        $data_count++;
                    }
                }

                echo "\n" . $this->display->colorize("Selected: " . count($selected) . " partitions (" . $this->formatBytes($selected_size) . ")", DisplayManager::COLOR_BRIGHT_GREEN) . "\n";

                if ($system_count > 0) {
                    echo $this->display->colorize("  • System partitions: $system_count (auto-selected)", DisplayManager::COLOR_BRIGHT_GREEN) . "\n";
                }

                if ($data_count > 0) {
                    echo $this->display->colorize("  • Data partitions: $data_count", DisplayManager::COLOR_YELLOW) . "\n";
                }
            }
            
            # TODO: Simplify partition controls
            echo "\nControls: W/S or ↑/↓ arrows, SPACE/X to select, ENTER to confirm, Q to quit\n";
            echo "Navigation: 'w' (up), 's' (down) or ↑/↓ arrows | Selection: 'x' or SPACE | Confirm: ENTER | Quit: 'q'\n";

            // Handle input
            $input = $this->getArrowKeyInput();

            if ($input === 'up' && $current > 0) {
                $current--;
            } elseif ($input === 'down' && $current < count($partitions) - 1) {
                $current++;
            } elseif ($input === 'space') {
                if (in_array($current, $selected)) {
                    $selected = array_diff($selected, [$current]);
                } else {
                    $selected[] = $current;
                }
            } elseif ($input === 'enter') {
                if (!empty($selected)) {
                    break;
                } else {
                    echo "\nPlease select at least one partition.\n";
                    sleep(2);
                }
            } elseif ($input === 'q') {
                echo "\nOperation cancelled by user.\n";
                exit(0);
            }
        }
        
        return $selected;
    }

    /**
     * Get user input with better terminal compatibility
     * 
     * @return string Input character
     */
    private function getArrowKeyInput(): string {
        $stty_result    = system('stty -icanon -echo 2>/dev/null', $stty_exit);  // Try to set terminal to raw mode for immediate input
        $handle         = fopen("php://stdin", "r");  // Get input and handle arrow keys properly
        $input          = fgetc($handle);

        // Check for arrow key escape sequence
        if ($input === "\033") {
            $next = fgetc($handle); // Should be '['

            if ($next === '[') {
                $arrow = fgetc($handle); // A=up, B=down, C=right, D=left
                fclose($handle);
                
                // Restore terminal if we changed it
                if ($stty_exit === 0) {
                    system('stty icanon echo 2>/dev/null');
                }

                if ($arrow === 'A') return 'up';
                if ($arrow === 'B') return 'down';
                if ($arrow === 'C') return 'right';
                if ($arrow === 'D') return 'left';
            }
        }

        fclose($handle);

        // Restore terminal if we changed it
        if ($stty_exit === 0) {
            system('stty icanon echo 2>/dev/null');
        }

        $input = strtolower($input);

        // Map common input variations
        if (in_array($input, ['w', 'k', '8'])) return 'up';
        if (in_array($input, ['s', 'j', '2'])) return 'down';
        if (in_array($input, [' ', 'x'])) return 'space';
        if (in_array($input, ["\n", "\r"])) return 'enter';
        if (in_array($input, ['q', 'n'])) return 'q';

        return $input;
    }
    
    /**
     * Create a simple progress bar
     * 
     * @param float $percentage Percentage complete (0-100)
     * @return string Progress bar string
     */
    private function createProgressBar(float $percentage, int $width = 30): string {
        $filled = (int)($percentage / 100 * $width);
        $empty  = $width - $filled;

        return "[" . str_repeat("█", $filled) . str_repeat("░", $empty) . "]";
    }

}

// === END INLINED: /src/commandManager.php ===


// === INLINED: /src/systemManager.php ===
/**
 * System Manager Class
 * 
 * Centralizes all system-level utilities including sudo privilege management,
 * nice priority handling, and other system operations. Consolidates system-related
 * functionality into a single, maintainable class.
 */
class SystemManager {
    
    /**
     * Constructor
     */
    public function __construct() {

    }
    
    /**
     * Check if the current user can elevate privileges with sudo
     * 
     * @return bool True if sudo is available and user can use it
    */
    public function canElevatePrivileges(): bool {
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
    * @param string $msg_type Type of message to display ('read' or 'write')
    * @return string|null The sudo password or null if cancelled
    */
    public function promptForSudoPassword(string $msg_type = 'read'): ?string {
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
    public function executeWithSudo(string $command, string $password): bool {
        $escaped_password = escapeshellarg($password);

        // Use printf to pipe password to sudo without triggering the prompt
        // The -p option with empty string suppresses the prompt
        $full_command = "printf '%s\n' $escaped_password | sudo -p '' -S $command 2>/dev/null";

        exec($full_command, $output, $code);
        return $code === 0;
    }

    /**
     * Get nice level for low priority operations
     * 
     * @return string Nice command prefix if low priority is requested
     */
    public function getNiceLevel(): string {
        global $argv;

        if (!$argv) {
            return '';
        }

        // Check if low priority is requested
        foreach ($argv as $arg) {
            if ($arg === '--low-priority') {
                return 'nice -n 10 ';
            }
        }

        return '';
    }

    /**
     * Add nice level to RAR command
     * 
     * @param string $rar_cmd RAR command to modify
     * @return string Modified RAR command with nice level if requested
     */
    public function addNiceToRarCommand(string $rar_cmd): string {
        $nice_prefix = $this->getNiceLevel();

        if (!empty($nice_prefix)) {
            return $nice_prefix . $rar_cmd;
        }

        return $rar_cmd;
    }

    /**
     * Check if current user is root
     * 
     * @return bool True if running as root, false otherwise
     */
    public function isRoot(): bool {
        return function_exists('posix_getuid') && posix_getuid() === 0;
    }

    /**
     * Get system temporary directory
     * 
     * @return string Path to system temporary directory
     */
    public function getTempDir(): string {
        return sys_get_temp_dir();
    }

    /**
     * Create temporary directory with unique name
     * 
     * @param string $prefix Prefix for temporary directory name
     * @return string Path to created temporary directory
     */
    public function createTempDir(string $prefix = 'rarrepo_'): string {
        $temp_dir = $this->getTempDir() . '/' . $prefix . uniqid(mt_rand(), true);

        mkdir($temp_dir, 0700, true);

        return $temp_dir;
    }
    
    /**
     * Clean up temporary directory
     * 
     * @param string $temp_dir Path to temporary directory to remove
     * @return bool True if successful, false otherwise
     */
    public function cleanupTempDir(string $temp_dir): bool {
        if (!is_dir($temp_dir)) {
            return false;
        }

        $command = 'rm -rf ' . escapeshellarg($temp_dir);

        exec($command, $output, $code);

        return $code === 0;
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
    public function getAbsolutePath(string $path): string {
        // If already absolute (Unix or Windows), return as is
        if (
            $path[0] === '/' || // Unix absolute
            (strlen($path) > 2 && $path[1] === ':' && $path[2] === '\\') || // Windows absolute
            (strlen($path) > 2 && $path[1] === ':' && $path[2] === '/') // Windows absolute (forward slash)
        ) {
            return $path;
        }

        // Make relative path absolute from current directory
        return getcwd() . '/' . $path;
    }

}

// === END INLINED: /src/systemManager.php ===


// === INLINED: /src/passwordManager.php ===
/**
 * PasswordManager Class
 * 
 * Centralizes all password-related functionality including command line parsing,
 * interactive prompting, encryption detection, and password validation.
 * Eliminates duplicate code across multiple password functions.
 */
class PasswordManager {
    private $archive_manager;

    public function __construct() {
        $this->archive_manager = new ArchiveManager();
    }
    
    /**
     * Get password from command line arguments
     * 
     * @return string|null The password or null if not provided
     */
    public function getPasswordFromArgs(): ?string {
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
     * Check if -p flag was used without a value (indicating user wants to be prompted)
     * 
     * @return bool True if -p was used without a value
     */
    public function shouldPromptForPassword(): bool {
        global $argv;

        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] === '-p') {
                // If -p is followed by a value, password was already provided
                if (isset($argv[$i + 1]) && $argv[$i + 1][0] !== '-') {
                    return false;
                }

                // If -p is provided without a value, we should prompt
                return true;
            }
        }

        return false;
    }

    /**
     * Prompt user for encryption password interactively
     * 
     * @return string|null The password entered by user, or null if cancelled
     */
    public function promptForEncryptionPassword(): ?string {
        echo "Enter encryption password: ";

        $password = $this->getHiddenInput();

        if (empty($password)) {
            echo "No password provided. Exiting.\n";
            exit(1);
        }

        return $password;
    }

    /**
     * Prompt user for repository password interactively
     * 
     * @param string $repository_name Name of the repository for the prompt
     * @return string|null The password entered by user, or null if cancelled
     */
    public function promptForRepositoryPassword(string $repository_name): ?string {
        echo "Repository '$repository_name' is password protected.\n";
        echo "Enter password: ";

        $password = $this->getHiddenInput();

        if (empty($password)) {
            echo "No password provided. Exiting.\n";
            exit(1);
        }

        return $password;
    }

    /**
     * Prompt for password with retry logic
     * 
     * @param string $repository_name Name of the repository for the prompt
     * @param callable $test_function Function to test if password is correct
     * @return string|null The correct password or null if user cancels
     */
    public function promptForPasswordWithRetry(string $repository_name, callable $test_function): ?string {
        $max_attempts   = 3;
        $attempt        = 0;
        
        while ($attempt < $max_attempts) {
            $attempt++;

            echo "Repository '$repository_name' is password protected.\n";
            echo "Enter password: ";

            $password = $this->getHiddenInput();

            if (empty($password)) {
                echo "No password provided. Exiting.\n";
                exit(1);
            }

            // Test the password
            if ($test_function($password)) {
                return $password;
            }

            // Password was incorrect
            echo "Incorrect password for $repository_name\n";

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
     * Get password with smart detection and retry logic
     * 
     * @param string $rarfile RAR archive file path
     * @return string|null The password or null if user cancels
     */
    public function getPasswordWithDetection(string $rarfile): ?string {
        $password       = $this->getPasswordFromArgs();
        $should_prompt  = $this->shouldPromptForPassword();
        
        // If no password provided or -p was used without value, check if repository  is encrypted
        if ($password === null || $should_prompt) {
            if (file_exists($rarfile)) {
                // Repository exists - check if it's encrypted
                if ($this->archive_manager->isEncrypted($rarfile)) {

                    // Create a test function for this specific repository
                    $test_function = function($test_password) use ($rarfile) {
                        return $this->archive_manager->testPassword($rarfile, $test_password);
                    };

                    $password = $this->promptForPasswordWithRetry(basename($rarfile), $test_function);
                }
            } else if ($should_prompt) {
                // Repository doesn't exist but -p was used without value - prompt for encryption password
                $password = $this->promptForEncryptionPassword();
            }
        } else if ($password !== null && file_exists($rarfile) && $this->archive_manager->isEncrypted($rarfile)) {
            // If password was provided via command line, test it
            if (!$this->archive_manager->testPassword($rarfile, $password)) {

                echo "Incorrect password for " . basename($rarfile) . "\n";

                // Create a test function for this specific archive
                $test_function = function($test_password) use ($rarfile) {
                    return $this->archive_manager->testPassword($rarfile, $test_password);
                };

                $password = $this->promptForPasswordWithRetry(basename($rarfile), $test_function);
            }
        }
        
        return $password;
    }

    /**
     * Get hidden input from user (password input)
     * 
     * @return string The input entered by user
     */
    private function getHiddenInput(): string {
        // Hide input for security (only if we're in an interactive terminal)
        if (posix_isatty(STDIN)) {
            system('stty -echo');
            $input = trim(fgets(STDIN));
            system('stty echo');
            echo "\n";
        } else {
            $input = trim(fgets(STDIN));
            echo "\n";
        }
        
        return $input;
    }
}


// === END INLINED: /src/passwordManager.php ===


// === INLINED: /src/utilityManager.php ===
/**
 * UtilityManager Class
 * 
 * Centralizes miscellaneous utility functions that don't fit into the other specialized classes.
 * Handles manifest parsing, archive utilities, and other general-purpose functions.
 */
class UtilityManager {
    
    public function __construct() {

    }
    
    /**
     * Parse manifest entry line
    * 
    * @param string $line Manifest entry line
    * @return array|false Parsed entry or false if invalid
    */
    public function parseManifestEntry(string $line) {
        $parts = explode("\t", $line);

        if (count($parts) < 2) {
            return false;
        }

        // Check if this is a symlink entry
        if ($parts[1] === '[LINK]') {
            return $this->parseSymlinkEntry($line);
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
     * Parse symlink manifest entry
     * 
     * @param string $line Manifest entry line
     * @return array|false Parsed symlink entry or false if invalid
     */
    public function parseSymlinkEntry(string $line) {
        $parts = explode("\t", $line);

        if (count($parts) < 4) {
            return false;
        }

        return [
            'path'      => $parts[0],
            'hash'      => $parts[1],
            'target'    => $parts[2],
            'metadata'  => [
                'permissions'   => $parts[3],
                'owner'         => $parts[4],
                'group'         => $parts[5],
                'mtime'         => (int)$parts[6],
                'atime'         => (int)$parts[7],
                'ctime'         => (int)$parts[8],
                'size'          => (int)$parts[9]
            ]
        ];
    }

    /**
     * Get the next available commit ID
     * 
     * @param string $rarfile RAR archive file path
     * @param string|null $password Password for archive access
     * @return int Next available commit ID
     */
    public function getNextCommitId(string $rarfile, ?string $password = null): int {
        $max = 0;

        if (!file_exists($rarfile)) {
            return 1;
        }

        $rar_cmd = 'rar lb';

        if ($password) {
            $rar_cmd .= ' ' . BF_ENCRYPTION_MODE . escapeshellarg($password);
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
     * Format file size in human-readable format
     * 
     * @param int $bytes Size in bytes
     * @param int $precision Number of decimal places
     * @return string Formatted file size
     */
    private function formatFileSize(int $bytes, int $precision = 2): string {
        $units = ['Bytes', 'KiloBytes', 'MegaBytes', 'GigaBytes', 'TeraBytes'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

// === END INLINED: /src/utilityManager.php ===


// === INLINED: /src/progressManager.php ===
/**
 * ProgressManager Class
 * 
 * Centralizes progress tracking functionality for RAR operations and other long-running tasks.
 * Handles progress bars, spinning indicators, and process monitoring.
 */
class ProgressManager {
    private $display;
    private $progress_bar_width;

    public function __construct() {
        $this->display              = new DisplayManager();
        $this->progress_bar_width   = 49; // Default progress bar width
    }

    /**
     * Create a progress bar with optional spinning indicator
     * 
     * @param float $current Current progress value
     * @param float $total Total value for 100%
     * @param int $width Width of the progress bar
     * @param bool $spinning Whether to show spinning indicator
     * @return string Formatted progress bar string
     */
    public function createProgressBar(float $current, float $total, int $width = null, bool $spinning = false): string {
        if ($total <= 0) {
            return '';
        }

        $width      = $width ?? $this->progress_bar_width;
        $percentage = min(100, ($current / $total) * 100);
        $filled     = round(($width * $percentage) / 100);
        $empty      = $width - $filled;

        $bar        = $this->display->colorize(str_repeat('█', $filled), DisplayManager::COLOR_GREEN);
        $bar        .= $this->display->colorize(str_repeat('░', $empty), DisplayManager::COLOR_DIM);
        $spinner    = '';

        if ($spinning && $percentage < 100) {
            $spinners = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
            $spinner = ' ' . $spinners[intval(microtime(true) * 10) % count($spinners)];
        }

        return sprintf("[%s] %.2f%%%s", $bar, $percentage, $spinner);
    }

    /**
     * Execute RAR command with progress tracking
     * 
     * @param string $rar_cmd RAR command to execute
     * @param int $total_files Total number of files being processed
     * @return bool True if successful, false otherwise
     */
    public function executeRarWithProgress(string $rar_cmd, int $total_files): bool {
        // Start progress display
        $encryption_status = strpos($rar_cmd, ' ' . BF_ENCRYPTION_MODE) !== false ? 'encrypted ' : '';
        echo $this->display->colorize("📦 Saving {$encryption_status}data to repository...", DisplayManager::COLOR_CYAN) . "\n";

        // Execute RAR command and monitor output for progress
        $output_lines   = 0;
        $expected_lines = $total_files + 20;

        // DEBUG: Show the actual RAR command being executed
        debug_echo("DEBUG: Executing RAR command: " . $rar_cmd . "\n");
        debug_echo("DEBUG: Expected files: " . $total_files . "\n");
        debug_echo("DEBUG: Starting RAR process...\n");

        // Start the RAR process
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        debug_echo("\r\033[K" . "⚠️ DEBUG: Executing RAR command: ". $rar_cmd . "\n");

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
                        echo "\r" . $this->createProgressBar($progress, 100, $this->progress_bar_width, true);
                        $current_progress = $progress;
                    }
                }
            } else {
                echo "\r" . $this->createProgressBar($progress, 100, $this->progress_bar_width, true);
                usleep(20000);
            }
        }

        // Close pipes
        fclose($pipes[1]);
        fclose($pipes[2]);

        // Get return code
        $return_code = proc_close($process);

        // DEBUG: Show final output and return code
        debug_echo("DEBUG: Final output buffer:\n");
        debug_echo("DEBUG: " . $output_buffer . "\n");
        debug_echo("DEBUG: Return code: " . $return_code . "\n");
        debug_echo("DEBUG: Total output lines processed: " . $output_lines . "\n");

        // Show completion
        echo "\r" . $this->createProgressBar(100, 100, $this->progress_bar_width, false) . "\n";

        return $return_code === 0;
    }
    
    /**
     * Execute RAR extraction command with progress tracking
     * 
     * @param string $rar_cmd RAR extraction command to execute
     * @param int $total_files Total number of files being extracted
     * @return bool True if successful, false otherwise
     */
    public function executeRarExtractWithProgress(string $rar_cmd, int $total_files): bool {
        // Start progress display
        $encryption_status = strpos($rar_cmd, ' ' . BF_ENCRYPTION_MODE) !== false ? "encrypted " : "";

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
                        echo "\r" . $this->createProgressBar($progress, 100, $this->progress_bar_width, true);
                        $current_progress = $progress;
                    }
                }
            } else {
                echo "\r" . $this->createProgressBar($progress, 100, $this->progress_bar_width, true);
                usleep(20000);
            }
        }

        // Close pipes
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        // Get return code
        $return_code = proc_close($process);
        
        // Show completion
        echo "\r" . $this->createProgressBar(100, 100, $this->progress_bar_width, false) . "\n";
        
        return $return_code === 0;
    }
    
}
// === END INLINED: /src/progressManager.php ===


// === INLINED: /src/argumentHandler.php ===
/**
 * Argument Handler Class
 * 
 * Centralized argument parsing and validation for command line arguments.
 * Eliminates redundancy in argument checking functions and provides
 * consistent interface for argument access.
 */
class ArgumentHandler {
    private $argv;
    private $cleaned_argv;
    
    public function __construct(array $argv = null) {
        // If no argv provided, get it from global scope
        if ($argv === null) {
            global $argv;
            $this->argv = $argv;
        } else {
            $this->argv = $argv;
        }

        $this->cleaned_argv = $this->clean_argv();
    }
    
    /**
     * Check if a specific argument exists
     * 
     * @param string $arg_name Argument name to check (e.g., '--follow-symlinks')
     * @return bool True if argument is present
     */
    public function has(string $arg_name): bool {
        return in_array($arg_name, $this->argv);
    }
    
    /**
     * Get boolean flag value (e.g., --follow-symlinks)
     * 
     * @param string $flag_name Flag name to check
     * @param bool $default Default value if flag not present
     * @return bool True if flag is present, false otherwise
     */
    public function getFlag(string $flag_name, bool $default = false): bool {
        return $this->has($flag_name) ? true : $default;
    }
    
    /**
     * Get count of cleaned arguments
     * 
     * @return int Number of arguments
     */
    public function getCount(): int {
        return count($this->cleaned_argv);
    }
    
    /**
     * Clean command line arguments by removing script name and command
     * 
     * @return array Cleaned arguments
     */
    private function clean_argv(): array {
        $cleaned    = [];
        $skip_next  = false;
        
        for ($i = 1; $i < count($this->argv); $i++) {
            if ($skip_next) {
                $skip_next = false;
                continue;
            }

            $arg = $this->argv[$i];

            if ($arg === '-p' && isset($this->argv[$i + 1])) {
                $cleaned[] = $arg;
                $cleaned[] = $this->argv[$i + 1];
                $skip_next = true;
            } else {
                $cleaned[] = $arg;
            }
        }

        return $cleaned;
    }
    
    /**
     * Get cleaned arguments starting from index 0 (for backward compatibility)
     * 
     * @return array Cleaned arguments starting from index 0
     */
    public function getCleanedFromZero(): array {
        $cleaned = $this->clean_argv();
        // This should match the old clean_argv() behavior exactly
        return $cleaned;
    }
}

// === END INLINED: /src/argumentHandler.php ===


// === INLINED: /src/displayManager.php ===
/**
 * Display Manager Class
 * 
 * Centralized display functions and color management for terminal output.
 * Consolidates all display-related functionality into a single, maintainable class.
 */
class DisplayManager {
    // ANSI color codes
    const COLOR_RESET = "\033[0m";
    const COLOR_BOLD = "\033[1m";
    const COLOR_DIM = "\033[2m";
    const COLOR_UNDERLINE = "\033[4m";
    
    // Foreground colors
    const COLOR_BLACK = "\033[30m";
    const COLOR_RED = "\033[31m";
    const COLOR_GREEN = "\033[32m";
    const COLOR_BRIGHT_GREEN = "\033[92m"; // Bright green (00FF00)
    const COLOR_YELLOW = "\033[33m";
    const COLOR_BLUE = "\033[34m";
    const COLOR_MAGENTA = "\033[35m";
    const COLOR_CYAN = "\033[36m";
    const COLOR_WHITE = "\033[37m";
    
    // Background colors
    const COLOR_BG_BLACK = "\033[40m";
    const COLOR_BG_RED = "\033[41m";
    const COLOR_BG_GREEN = "\033[42m";
    const COLOR_BG_YELLOW = "\033[43m";
    const COLOR_BG_BLUE = "\033[44m";
    const COLOR_BG_MAGENTA = "\033[45m";
    const COLOR_BG_CYAN = "\033[46m";
    const COLOR_BG_WHITE = "\033[47m";
    
    /**
     * Check if the current terminal supports colors
     * 
     * @return bool True if colors are supported
     */
    private function supportsColors(): bool {
        // Check if we're in a terminal and colors are supported
        if (function_exists('posix_isatty') && !posix_isatty(STDOUT)) {
            return false;
        }
        
        // Check if NO_COLOR environment variable is set
        if (getenv('NO_COLOR') !== false) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Apply color formatting to text
     * 
     * @param string $text Text to colorize
     * @param string $color Color code(s) to apply
     * @return string Colored text or original text if colors not supported
     */
    public function colorize(string $text, string $color): string {
        return $this->supportsColors() ? $color . $text . self::COLOR_RESET : $text;
    }
    
    /**
     * Print a formatted header with styling
     * 
     * @param string $text Header text
     * @param string $char Character to use for underline
     * @param int $width Width of the header
     * @return void
     */
    public function header(string $text, string $char = '=', int $width = 60): void {
        $text_length    = strlen($text) + 4; // '  ' before and after
        $line_length    = max($width, $text_length);
        $line           = str_repeat($char, $line_length);

        // Center the text within $line_length
        $padding        = $line_length - strlen($text);
        $left           = floor($padding / 2);
        $right          = $padding - $left;
        $centered_text  = str_repeat(' ', $left - 1) . $text . str_repeat(' ', $right - 1);

        echo "\n" . $this->colorize($line, self::COLOR_CYAN) . "\n";
        echo $this->colorize($centered_text, self::COLOR_BOLD . self::COLOR_CYAN) . "\n";
        echo $this->colorize($line, self::COLOR_CYAN) . "\n\n";
    }

    /**
     * Print a success message
     * 
     * @param string $message Success message
     * @return void
     */
    public function success(string $message): void {
        echo $this->colorize("  $message", self::COLOR_GREEN) . "\n";
    }
    
    /**
     * Print a warning message
     * 
     * @param string $message Warning message
     * @return void
     */
    public function warning(string $message): void {
        echo $this->colorize("  $message", self::COLOR_YELLOW) . "\n";
    }
    
    /**
     * Print an error message
     * 
     * @param string $message Error message
     * @return void
     */
    public function error(string $message): void {
        echo $this->colorize("  $message", self::COLOR_RED) . "\n";
    }
    
    /**
     * Print an info message
     * 
     * @param string $message Info message
     * @return void
     */
    public function info(string $message): void {
        echo $this->colorize("$message", self::COLOR_CYAN) . "\n";
    }
    
    /**
     * Calculate optimal column widths for a table based on content
     * 
     * @param array $table_data Array of rows, where each row is an array of columns
     * @param int $padding Additional padding to add to each column width
     * @return array Array of calculated column widths
     */
    public function calculateOptimalColumnWidths(array $table_data, int $padding = 2): array {
        if (empty($table_data)) {
            return [20, 20]; // Default fallback
        }
        
        $num_columns = count($table_data[0]);
        $column_widths = array_fill(0, $num_columns, 0);
        
        foreach ($table_data as $row) {
            foreach ($row as $col_index => $cell) {
                $column_widths[$col_index] = max($column_widths[$col_index], mb_strlen($cell));
            }
        }
        
        // Add padding to each column
        foreach ($column_widths as &$width) {
            $width += $padding;
        }
        
        return $column_widths;
    }
    
    /**
     * Print a formatted table row
     * 
     * @param array $columns Array of column values
     * @param array $widths Array of column widths
     * @param string $separator Column separator
     * @return void
     */
    public function tableRow(array $columns, array $widths, string $separator = '  '): void {
        $row = '';

        foreach ($columns as $i => $column) {
            $width  = $widths[$i] ?? 20;
            $row    .= str_pad($column, $width) . $separator;
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
    public function formatCommitDisplay(array $manifest): string {
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
}
// === END INLINED: /src/displayManager.php ===


// === INLINED: /src/fileSystemManager.php ===
/**
 * File System Manager Class
 * 
 * Centralizes all file system operations, metadata handling, and sudo privilege management.
 * Carefully preserves the existing sudo system to ensure file access continues to work correctly.
 */
class FileSystemManager {
    private $sudo_password;
    private $can_elevate;
    private $display;
    
    /**
     * Constructor
     * 
     * @param string|null $sudo_password Sudo password for privilege elevation
     */
    public function __construct(?string $sudo_password = null) {
        $this->sudo_password    = $sudo_password;
        $this->can_elevate      = $this->canElevatePrivileges();
        $this->display          = new DisplayManager();
    }
    
    /**
     * Check if the current user can elevate privileges with sudo
     * 
     * @return bool True if sudo is available and user can use it
     */
    private function canElevatePrivileges(): bool {
        // Check if sudo command exists
        exec('which sudo 2>/dev/null', $output, $code);

        if ($code !== 0) {
            return false;
        }
        
        // If we have a password, assume sudo will work (we'll test it when needed)
        if ($this->sudo_password !== null) {
            return true;
        }
        
        // Only test non-interactive sudo if no password is provided
        exec('sudo -n true 2>/dev/null', $output, $code);
        return $code === 0;
    }

    /**
     * Execute command with elevated privileges
     * 
     * Runs a command with sudo using the stored password.
     * 
     * @param string $command Command to execute
     * @return bool True if successful, false otherwise
     */
    public function executeWithSudo(string $command): bool {
        if ($this->sudo_password === null || !$this->can_elevate) {
            return false;
        }
        
        $escaped_password = escapeshellarg($this->sudo_password);
        
        // Use printf to pipe password to sudo without triggering the prompt
        // The -p option with empty string suppresses the prompt
        $full_command = "printf '%s\n' $escaped_password | sudo -p '' -S $command 2>/dev/null";
        
        exec($full_command, $output, $code);
        return $code === 0;
    }
    
    /**
     * Get file metadata with sudo fallback
     * 
     * Retrieves file metadata including permissions, ownership, timestamps, and size.
     * Falls back to sudo if normal access fails.
     * 
     * @param string $filepath Path to the file
     * @param bool $suppress_warnings Whether to suppress warning messages (default: false)
     * @return array File metadata array
     */
    public function getFileMetadata(string $filepath, bool $suppress_warnings = false): array {
        // Try to stat() normally
        $stat = @stat($filepath);

        // If that fails and sudo is available, try stat via sudo
        if ($stat === false && $this->sudo_password !== null && $this->can_elevate) {
            $escaped_filepath   = escapeshellarg($filepath);
            $command            = "stat -c '%a %u %g %Y %X %Z %s' $escaped_filepath";
            $output             = [];

            exec("printf '%s\n' " . escapeshellarg($this->sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);

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
            if (!$suppress_warnings) {
                $msg = "MANIFEST ERROR: File $filepath does not exist or is not readable";
                if ($this->sudo_password !== null) $msg .= " (sudo)";
                $this->display->error($msg);
            }

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

        $owner = (is_numeric($owner_id) && function_exists('posix_getpwuid')) ? (posix_getpwuid($owner_id)['name'] ?? $owner_id) : ($owner_id !== false ? $owner_id : 'unknown');
        $group = (is_numeric($group_id) && function_exists('posix_getgrgid')) ? (posix_getgrgid($group_id)['name'] ?? $group_id) : ($group_id !== false ? $group_id : 'unknown');

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
    public function createManifestEntry(string $path, string $hash, string $fullpath): string {
        $metadata = $this->getFileMetadata($fullpath);
    
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
    public function createDirectoryEntry(string $path, string $fullpath): string {
        $metadata = $this->getFileMetadata($fullpath);
    
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
     * Create symlink manifest entry
     * 
     * Creates a manifest entry for symbolic links with metadata.
     * Format: path\t[LINK]\ttarget\tpermissions\towner\tgroup\tmtime\tatime\tctime\tsize
     * 
     * @param string $path Relative path to the symlink
     * @param string $fullpath Full path to the symlink for metadata collection
     * @return string Tab-delimited manifest entry
     */
    public function createSymlinkEntry(string $path, string $fullpath): string {
        $target = readlink($fullpath);  // Get the symlink target - readlink should work even for broken symlinks

        if ($target === false) {
            // If readlink fails, it's likely a permission issue, not a broken symlink, try with sudo if available

            if ($this->sudo_password !== null && $this->can_elevate) {
                $escaped_path   = escapeshellarg($fullpath);
                $command        = "readlink $escaped_path";
                $output         = [];

                exec("printf '%s\n' " . escapeshellarg($this->sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);

                if ($code === 0 && !empty($output[0])) {
                    $target = trim($output[0]);
                } else {
                    $target = 'unknown_target'; // Last resort
                }
            } else {
                $target = 'unknown_target'; // Last resort
            }
        }

        // Get metadata from the symlink file itself, suppressing warnings
        $metadata = $this->getFileMetadata($fullpath, true);

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
     * Restore file with metadata
     * 
     * Restores a file with its original metadata including permissions,
     * ownership, and timestamps. Uses sudo if needed.
     * 
     * @param string $source Source file path
     * @param string $dest Destination file path
     * @param array $metadata File metadata
     * @return bool True if successful, false otherwise
     */
    public function restoreFileWithMetadata(string $source, string $dest, array $metadata): bool {
        if (!copy($source, $dest)) {  // Copy file content
            // Try with sudo, if password is available
            if ($this->sudo_password !== null && $this->can_elevate) {
                $copy_cmd = "cp " . escapeshellarg($source) . " " . escapeshellarg($dest);

                if (!$this->executeWithSudo($copy_cmd)) {
                    $this->display->error("Notice: Failed to restore [{$dest}] (sudo)");
                    return false; // sudo copy also failed
                }
            } else {
                $this->display->error("Notice: Failed to restore [{$dest}]");
                return false; // no sudo password, cannot proceed
            }
        }

        // Restore permissions
        if (!chmod($dest, octdec($metadata['permissions']))) {
            // Try with sudo if available
            if ($this->sudo_password !== null && $this->can_elevate) {
                $chmod_cmd = "chmod " . $metadata['permissions'] . " " . escapeshellarg($dest);
                $this->executeWithSudo($chmod_cmd);
            }
        }

        // Restore ownership
        $owner_id = is_numeric($metadata['owner']) ? $metadata['owner'] : (function_exists('posix_getpwnam') ? (posix_getpwnam($metadata['owner'])['uid'] ?? null) : null);
        $group_id = is_numeric($metadata['group']) ? $metadata['group'] : (function_exists('posix_getgrnam') ? (posix_getgrnam($metadata['group'])['gid'] ?? null) : null);

        if ($owner_id !== null) {
            if (!$this->chown($dest, $owner_id)) {
                // Try with sudo if available
                if ($this->sudo_password !== null && $this->can_elevate) {
                    $chown_cmd = "chown " . escapeshellarg($owner_id) . " " . escapeshellarg($dest);
                    $this->executeWithSudo($chown_cmd);
                }
            }
        }

        if ($group_id !== null) {
            if (!$this->chgrp($dest, $group_id)) {
                // Try with sudo if available
                if ($this->sudo_password !== null && $this->can_elevate) {
                    $chgrp_cmd = "chgrp " . escapeshellarg($group_id) . " " . escapeshellarg($dest);
                    $this->executeWithSudo($chgrp_cmd);
                }
            }
        }

        // Restore timestamps LAST (use PHP touch for consistency)
        if (isset($metadata['mtime']) && isset($metadata['atime'])) {
            touch($dest, $metadata['mtime'], $metadata['atime']);
        }

        return true;
    }
    
    /**
     * Copy file with sudo fallback
     *
     * @param string $source Source file path
     * @param string $dest Destination file path
     * @return bool True if successful, false otherwise
     */
    public function copyFile(string $source, string $dest): bool {
        if (@copy($source, $dest)) {  // Try normal copy first
            return true;
        }
        
        // If failed and sudo is available, try with sudo
        if ($this->sudo_password !== null && $this->can_elevate) {
            $escaped_source = escapeshellarg($source);
            $escaped_dest   = escapeshellarg($dest);
            $command        = "cp --force $escaped_source $escaped_dest";

            if ($this->executeWithSudo($command)) {  // If copy succeeded, fix ownership so RAR can read the file
                $current_user   = posix_getpwuid(posix_geteuid())['name'];
                $escaped_dest   = escapeshellarg($dest);
                $chown_command  = "chown $current_user:$current_user $escaped_dest";

                $this->executeWithSudo($chown_command);
                return true;
            }
        }

        return false;
    }

    /**
     * Check if file is readable with sudo fallback
     *
     * @param string $filepath Path to the file
     * @return bool True if readable, false otherwise
     */
    public function isFileReadable(string $filepath): bool {
        if (@is_readable($filepath)) {  // Try normal is_readable first
            return true;
        }

        // If failed and sudo is available, try with sudo
        if ($this->sudo_password !== null && $this->can_elevate) {
            $escaped_filepath   = escapeshellarg($filepath);
            $command            = "test -r $escaped_filepath";
            $output             = [];

            exec("printf '%s\n' " . escapeshellarg($this->sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
            return $code === 0;
        }

        return false;
    }
    
    /**
     * Get file MD5 hash with sudo fallback
     * 
     * @param string $filepath Path to the file
     * @return string|false MD5 hash or false if failed
     */
    public function getFileMd5(string $filepath) {
        $hash = @md5_file($filepath);  // Try normal md5_file first

        if ($hash !== false) {
            return $hash;
        }
        
        if ($this->sudo_password !== null && $this->can_elevate) {  // If failed and sudo is available, try with sudo
            $escaped_filepath   = escapeshellarg($filepath);
            $command            = "md5sum $escaped_filepath | cut -d' ' -f1";
            $output             = [];

            exec("timeout " . MD5_TIMEOUT . " printf '%s\n' " . escapeshellarg($this->sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);

            if ($code === 124) {  // Command timed out
                $this->display->error("NOTICE: MD5 calculation timed out for: $filepath");
                return false;
            } elseif ($code === 0 && !empty($output[0])) {
                return trim($output[0]);
            }
        }

        return false;
    }

    /**
     * Read file contents with sudo fallback
     * 
     * @param string $filepath Path to the file
     * @return string|false File contents or false if failed
     */
    public function readFileContents(string $filepath) {
        $content = @file_get_contents($filepath);  // Try normal file_get_contents first

        if ($content !== false) {
            return $content;
        }

        if ($this->sudo_password !== null && $this->can_elevate) {  // If failed and sudo is available, try with sudo
            $escaped_filepath   = escapeshellarg($filepath);
            $command            = "cat $escaped_filepath";
            $output             = [];

            exec("printf '%s\n' " . escapeshellarg($this->sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);

            if ($code === 0) {
                return implode("\n", $output);
            }
        }

        return false;
    }

    /**
     * Write file contents with sudo fallback
     * 
     * @param string $filepath Path to the file
     * @param string $content Content to write
     * @param int $flags Additional flags (e.g., FILE_APPEND, LOCK_EX)
     * @return bool True if successful, false otherwise
     */
    public function writeFileContents(string $filepath, string $content, int $flags = 0) {
        $result = @file_put_contents($filepath, $content, $flags);  // Try normal file_put_contents first

        if ($result !== false) {
            return true;
        }
        
        if ($this->sudo_password !== null && $this->can_elevate) {  // If failed and sudo is available, try with sudo
            $escaped_filepath   = escapeshellarg($filepath);
            $escaped_content    = escapeshellarg($content);
            
            if ($flags & FILE_APPEND) {  // Handle different flags
                $command = "echo $escaped_content >> $escaped_filepath";
            } else {
                $command = "echo $escaped_content > $escaped_filepath";
            }

            if ($this->executeWithSudo($command)) {  // Fix ownership so current user can read/write the file
                $current_user   = posix_getpwuid(posix_geteuid())['name'];
                $chown_command  = "chown $current_user:$current_user $escaped_filepath";

                $this->executeWithSudo($chown_command);
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if file exists with sudo fallback
     * 
     * @param string $filepath Path to the file
     * @return bool True if file exists, false otherwise
     */
    public function fileExists(string $filepath): bool {
        if (@file_exists($filepath)) {  // Try normal file_exists first
            return true;
        }
        
        if ($this->sudo_password !== null && $this->can_elevate) {  // If failed and sudo is available, try with sudo
            $escaped_filepath   = escapeshellarg($filepath);
            $command            = "test -e $escaped_filepath";
            $output             = [];

            exec("printf '%s\n' " . escapeshellarg($this->sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
            return $code === 0;
        }

        return false;
    }

    /**
     * Check if file is writable with sudo fallback
     * 
     * @param string $filepath Path to the file
     * @return bool True if writable, false otherwise
     */
    public function fileIsWritable(string $filepath): bool {
        if (@is_writable($filepath)) {  // Try normal is_writable first
            return true;
        }
        
        if ($this->sudo_password !== null && $this->can_elevate) {  // If failed and sudo is available, try with sudo
            $escaped_filepath   = escapeshellarg($filepath);
            $command            = "test -w $escaped_filepath";
            $output             = [];

            exec("printf '%s\n' " . escapeshellarg($this->sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
            return $code === 0;
        }

        return false;
    }

    /**
     * Check if directory is writable with sudo fallback
     * 
     * @param string $dirpath Path to the directory
     * @return bool True if writable, false otherwise
     */
    public function directoryIsWritable(string $dirpath): bool {
        if (@is_writable($dirpath)) {  // Try normal is_writable first
            return true;
        }
        
        if ($this->sudo_password !== null && $this->can_elevate) {  // If failed and sudo is available, try with sudo
            $escaped_dirpath = escapeshellarg($dirpath);
            return $this->executeWithSudo("test -w $escaped_dirpath");
        }

        return false;
    }
    
    /**
     * Check if path is a directory with sudo fallback
     * 
     * @param string $path Path to check
     * @return bool True if directory, false otherwise
     */
    public function isDirectory(string $path): bool {
        if (@is_dir($path)) {  // Try normal is_dir first
            return true;
        }
        
        if ($this->sudo_password !== null && $this->can_elevate) {  // If failed and sudo is available, try with sudo
            $escaped_path = escapeshellarg($path);
            return $this->executeWithSudo("test -d $escaped_path");
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
    public function needsSudoPermissions(string $folder): bool {
        if (!is_readable($folder)) {  // Check if we can already access the folder
            return true;
        }

        // Do a comprehensive scan to see if we encounter any permission issues
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                if (!$file->isReadable()) {
                    return true;
                }
            }
        } catch (UnexpectedValueException $e) {
            return true;
        }

        return false;
    }

    /**
     * Generator function to scan directories recursively, handling permission errors gracefully
     * 
     * @param string $dir Directory to scan
     * @param array $exclude_patterns List of directory names to exclude (e.g., ['proc', 'sys', 'tmp'])
     * @param int|null $start_device_id Device ID of the starting partition (for boundary detection)
     * @param array $selected_partitions List of selected partitions for backup (for boundary validation)
     * @return Generator<string> Yields file paths
     */
    public function scanDirGenerator(string $dir, array $exclude_patterns = [], ?int $start_device_id = null, array $selected_partitions = [], bool $follow_symlinks = false): Generator {
        static $processed_dirs          = [];
        static $file_count              = 0;
        static $depth_tracker           = [];
        static $approved_device_ids     = [];
        static $device_to_mount_cache   = [];
        static $circular_ref_context    = [];
        static $last_root_dir           = null;

        // Reset static variables if this is a new root directory scan
        // Check if this is a top-level call (start_device_id is null) and it's a different directory
        if ($start_device_id === null && $last_root_dir !== $dir) {
            $processed_dirs         = [];
            $file_count             = 0;
            $depth_tracker          = [];
            $approved_device_ids    = [];
            $device_to_mount_cache  = [];
            $circular_ref_context   = [];
            $last_root_dir          = $dir;
        }
        
        // Get device ID of the starting directory for partition boundary detection
        if ($start_device_id === null) {
            $start_stat         = @stat($dir);
            $start_device_id    = $start_stat ? $start_stat['dev'] : null;

            if ($start_device_id === null) {
                debug_echo("\r\033[K" . "⚠️ DEBUG: Cannot determine device ID for: $dir\n");
                return;
            }

            //debug_echo("\r\033[K" . "🔍 DEBUG: Starting partition scan with device ID: $start_device_id for: $dir\n");

            // Pre-compute approved device IDs for O(1) lookups
            $this->buildApprovedDeviceCache($selected_partitions, $approved_device_ids, $device_to_mount_cache);
        }

        // Get relative path for exclusion checking
        $relative_path = $this->getRelativePath($dir);

        // Prevent infinite recursion by tracking processed directories
        if (isset($processed_dirs[$dir])) {
            //debug_echo("\r\033[K" . "⚠️ DEBUG: Skipping already processed directory: $dir\n");
            return;
        }

        $processed_dirs[$dir] = true;
        
        // Track directory depth to prevent circular references
        $current_depth = 0;
        if (isset($depth_tracker[$dir])) {
            $current_depth = $depth_tracker[$dir];
        }
        
        // Prevent excessive directory nesting (more than 50 levels deep)
        if ($current_depth > 50) {
            debug_echo("\r\033[K" . "⚠️ DEBUG: Maximum directory depth [50] exceeded for: $dir\n");
            return;
        }

        // Early exclusion check - if this directory should be skipped, don't process it
        if ($this->shouldSkipDirectory($relative_path, $exclude_patterns)) {
            //debug_echo("\r\033[K" . "⏭️ DEBUG: Skipping excluded directory: $relative_path\n");
            return; // Skip this entire directory and all its contents
        }
        
        // Check for hidden files in home directories (skip .steam, .cache, etc.)
        if ($this->shouldSkipHiddenHomeDirectory($relative_path)) {
            debug_echo("\r\033[K" . "⏭️ DEBUG: Skipping hidden home directory: $relative_path\n");
            return;
        }

        // Use realpath to detect circular references and unresolvable paths
        $real_path = realpath($dir);

        if ($real_path === false) {
            debug_echo("\r\033[K" . "⚠️ DEBUG: Skipping unresolvable path: $dir\n");
            return;
        }

        // Check for circular references using hybrid approach (fast + accurate)
        if ($this->hasCircularReference($real_path, $circular_ref_context)) {
            debug_echo("\r\033[K" . "⚠️ DEBUG: Skipping circular reference path: $real_path\n");
            return;
        }

        // Debug: Show current directory being processed (less verbose)
        if ($file_count % 10000 === 0) {
            //debug_echo("\r\033[K" . "🔍 DEBUG: Processing directory: $relative_path (Total files: " . number_format($file_count) . ")\n");
        }
        
        try {
            $files = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);

            foreach ($files as $file) {
                if ($file->isDir()) {
                    // Set depth for subdirectory
                    $subdir_path = $file->getPathname();

                    // CRITICAL: Check if this directory is actually a symlink
                    if ($file->isLink()) {
                        // This is a symlinked directory - yield it as a file, don't traverse into it, unless --follow-symlinks is enabled
                        if (!$follow_symlinks) {
                            //debug_echo("\r\033[K" . "🔗 DEBUG: Yielding symlinked directory (not traversing): $subdir_path\n");
                            $file_count++;
                            yield $subdir_path; // Yield the symlink itself as a file
                            continue; // Don't traverse into the symlinked directory
                        } else {
                            debug_echo("\r\033[K" . "🔗 DEBUG: Following symlinked directory: $subdir_path\n");
                        }
                    }

                    // Fast partition check using pre-computed device ID cache
                    // Only apply partition boundaries when specific partitions are selected (system backup)
                    if (!empty($selected_partitions) && $this->shouldSkipUnselectedPartitionFast($subdir_path, $approved_device_ids, $device_to_mount_cache)) {
                        //debug_echo("\r\033[K" . "🚫 DEBUG: Skipping unselected partition: $subdir_path\n");
                        continue; // Skip this subdirectory - it's on an unselected partition
                    }

                    $depth_tracker[$subdir_path] = $current_depth + 1;

                    // Recursively scan subdirectories, passing all parameters
                    yield from $this->scanDirGenerator($subdir_path, $exclude_patterns, $start_device_id, $selected_partitions, $follow_symlinks);
                } else {
                    // Skip hidden files in home directories
                    $file_relative_path = $this->getRelativePath($file->getPathname());

                    if ($this->shouldSkipHiddenHomeFile($file_relative_path)) {
                        continue;
                    }
                    
                    $file_count++;
                    yield $file->getPathname();
                }
            }
        } catch (UnexpectedValueException $e) {
            // Permission denied - if we have sudo, try to access with elevated privileges
            if ($this->sudo_password !== null && $this->can_elevate) {
                //debug_echo("\r\033[K" . "🔐 DEBUG: Using sudo fallback for: $dir\n");

                // Try to list directory contents with sudo for complete coverage
                $escaped_dir    = escapeshellarg($dir);
                $command        = "sudo -S find $escaped_dir -type f 2>/dev/null";
                $output         = [];
                $return_code    = 0;

                exec("printf '%s\n' " . escapeshellarg($this->sudo_password) . " | $command", $output, $return_code);

                if ($return_code === 0) {
                    //debug_echo("\r\033[K" . "✅ DEBUG: Sudo fallback successful, found " . count($output) . " files in $dir\n");

                    // Successfully accessed with sudo, yield the files
                    foreach ($output as $filepath) {
                        if (is_file($filepath)) {
                            // Skip hidden files in home directories
                            $file_relative_path = $this->getRelativePath($filepath);
                            if ($this->shouldSkipHiddenHomeFile($file_relative_path)) {
                                continue;
                            }

                            $file_count++;
                            yield $filepath;
                        }
                    }

                    // CRITICAL: Don't recursively process subdirectories when using sudo fallback
                    // This will cause an infinite loop - we're already getting all files from find
                    //debug_echo("\r\033[K" . "⚠️ DEBUG: Skipping recursive processing for sudo fallback directory: $dir\n");
                    return;
                } else {
                    debug_echo("\r\033[K" . "❌ DEBUG: Sudo command failed for: $dir (exit code: $return_code)\n");
                }
            } else {
                // Provide specific reason why sudo fallback is not available
                if ($this->sudo_password === null) {
                    debug_echo("\r\033[K" . "❌ DEBUG: Permission denied, no sudo password available for: $dir\n");
                } elseif (!$this->can_elevate) {
                    debug_echo("\r\033[K" . "❌ DEBUG: Permission denied, sudo not available for: $dir\n");
                } else {
                    debug_echo("\r\033[K" . "❌ DEBUG: Permission denied, unknown sudo issue for: $dir\n");
                }
            }
        }

        // Show circular reference detection statistics when scan completes
        if ($dir === '/' && !empty($circular_ref_context['stats'])) {
            $stats          = $circular_ref_context['stats'];
            $total_checks   = $stats['fast_checks'] + $stats['slow_checks'];

            if ($total_checks > 0) {
                debug_echo("\r\033[K" . "📊 DEBUG: Circular reference detection stats:\n");
                debug_echo("  Fast checks: " . number_format($stats['fast_checks']) . " (" . number_format($stats['fast_checks'] / $total_checks * 100, 1) . "%)\n");
                debug_echo("  Slow checks: " . number_format($stats['slow_checks']) . " (" . number_format($stats['slow_checks'] / $total_checks * 100, 1) . "%)\n");
                debug_echo("  Circular references found: " . number_format($stats['circular_found']) . "\n");
            }
        }
    }

    /**
     * Get relative path from root directory
     * 
     * @param string $full_path Full file path
     * @return string Relative path from root
     */
    private function getRelativePath(string $full_path): string {
        $root_path = '/';  // For system backup, we want to exclude top-level directories

        if (strpos($full_path, $root_path) === 0) {
            $relative = substr($full_path, strlen($root_path));
            return trim($relative, '/');
        }

        return $full_path;
    }
    
    /**
     * Convert glob pattern to regex pattern
     * 
     * @param string $glob_pattern Glob pattern (e.g., /home/*, /proc/*)
     * @return string Regex pattern
     */
    private function globToRegex(string $glob_pattern): string {
        // Escape regex special characters except * and ?
        $regex = preg_quote($glob_pattern, '#');

        // Convert glob wildcards to regex
        $regex = str_replace('\*', '.*', $regex);  // * becomes .*
        $regex = str_replace('\?', '.', $regex);   // ? becomes .

        // Ensure pattern matches from start to end
        return "#^$regex$#";
    }
    
    /**
     * Check if a directory should be skipped based on exclusion patterns
     * 
     * @param string $relative_path Relative path from root
     * @param array $exclude_patterns List of glob/regex patterns to exclude
     * @return bool True if directory should be skipped
     */
    private function shouldSkipDirectory(string $relative_path, array $exclude_patterns): bool {
        if (empty($relative_path)) {
            return false; // Root directory should not be skipped
        }

        // Quick checks for common patterns to avoid regex overhead
        // Define excluded directories for fast lookup
        # TODO: Move to an exclusion class
        static $excluded_dirs = [
            'proc', 'sys', 'tmp', 'run', 'dev',
            'var/cache', 'var/tmp', 'var/log', 'var/run', 'var/lock', 'var/spool'
        ];

        // Check exact matches first (entire directories)
        if (in_array($relative_path, $excluded_dirs, true)) {
            return true;
        }

        // Check subdirectories for paths that were already excluded at parent level
        foreach ($excluded_dirs as $excluded_dir) {
            if (strpos($relative_path, $excluded_dir . '/') === 0) {
                return true;
            }
        }
        
        // Check for hidden home directories
        if (preg_match('#^home/[^/]+/\.[^/]+(/.*)?$#', $relative_path) || preg_match('#^root/\.[^/]+(/.*)?$#', $relative_path)) {
            return true;
        }

        // Check other patterns using regex
        foreach ($exclude_patterns as $pattern) {
            $regex = $this->globToRegex($pattern);  // Convert glob pattern to regex and check if it matches

            if (preg_match($regex, $relative_path)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Check if a file should be skipped based on exclusion patterns
     * 
     * @param string $relative_path Relative path from root
     * @param array $exclude_patterns List of glob/regex patterns to exclude
     * @return bool True if file should be skipped
     */
    public function shouldSkipFile(string $relative_path, array $exclude_patterns): bool {
        if (empty($relative_path)) {
            return false; // Root file should not be skipped
        }

        foreach ($exclude_patterns as $pattern) {
            $regex = $this->globToRegex($pattern);  // Convert glob pattern to regex and check if it matches

            if (preg_match($regex, $relative_path)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Check if a hidden home directory should be skipped
     * 
     * @param string $relative_path Relative path from root
     * @return bool True if hidden home directory should be skipped
     */
    private function shouldSkipHiddenHomeDirectory(string $relative_path): bool {
        // Skip hidden directories in home directories (e.g., .steam, .cache, .config)
        if (preg_match('#^home/[^/]+/\.[^/]+(/.*)?$#', $relative_path)) {
            return true;
        }

        return false;
    }
    
    /**
     * Check if a hidden file in home directory should be skipped
     * 
     * @param string $relative_path Relative path from root
     * @return bool True if hidden home file should be skipped
     */
    private function shouldSkipHiddenHomeFile(string $relative_path): bool {
        if (preg_match('#^home/[^/]+/\.[^/]+(/.*)?$#', $relative_path)) {  // Skip hidden files in home directories (e.g., .steam, .cache, .config)
            return true;
        }

        return false;
    }
    
    /**
     * Check if a path has circular references using hybrid approach (fast + accurate)
     * 
     * Uses fast heuristic checks for 99% of normal paths, and accurate inode-based
     * detection only for suspicious paths. This prevents false positives while
     * maintaining excellent performance in tight loops.
     * 
     * @param string $real_path Full real path to check
     * @param array &$context Context array for tracking across recursive calls
     * @return bool True if genuine circular reference detected
     */
    private function hasCircularReference(string $real_path, array &$context = []): bool {
        // Initialize context for tracking across recursive calls
        if (empty($context)) {
            $context = [
                'visited_inodes'    => [],
                'stats'             => [
                    'fast_checks'       => 0,
                    'slow_checks'       => 0,
                    'circular_found'    => 0
                ]
            ];
        }

        // FAST PATH: Quick heuristic checks (covers 99% of cases)
        $path_length    = strlen($real_path);
        $depth          = substr_count($real_path, '/');

        // Most normal paths pass these quick checks without expensive operations
        // Use realistic filesystem limits: Linux supports ~4096 char paths, ~1000+ depth
        if ($path_length < 1000 && $depth < 100) {
            $context['stats']['fast_checks']++;
            return false; // Almost certainly not circular, skip expensive checks
        }

        // SLOW PATH: Accurate inode-based detection for suspicious paths only
        $context['stats']['slow_checks']++;
        debug_echo("\r\033[K" . "🔍 DEBUG: Checking suspicious path for circular reference: $real_path\n");

        $stat = @stat($real_path);

        if ($stat === false) {
            // Can't stat the path, assume not circular (could be permission issue)
            return false;
        }
        
        // Use device:inode as unique filesystem identifier
        // This is the only reliable way to detect true circular references
        $device_id  = $stat['dev'];
        $inode_id   = $stat['ino'];

        // Initialize device array if not exists
        if (!isset($context['visited_inodes'][$device_id])) {
            $context['visited_inodes'][$device_id] = [];
        }

        // Check if we've seen this exact inode before
        if (isset($context['visited_inodes'][$device_id][$inode_id])) {
            $context['stats']['circular_found']++;
            debug_echo("\r\033[K" . "🔄 DEBUG: TRUE circular reference detected (inode $device_id:$inode_id): $real_path\n");
            debug_echo("     Previously seen at: {$context['visited_inodes'][$device_id][$inode_id]}\n");
            return true; // Genuine circular reference found via inode tracking
        }

        // Mark this inode as visited
        $context['visited_inodes'][$device_id][$inode_id] = $real_path;

        return false; // No circular reference detected
    }
    
    /**
     * Generator function to scan directories recursively for directories only, handling permission errors gracefully
     * 
     * @param string $dir Directory to scan
     * @param array $exclude_patterns List of directory patterns to exclude
     * @param int|null $start_device_id Device ID of the starting partition (for boundary detection)
     * @param array $selected_partitions List of selected partitions for backup (for boundary validation)
     * @return Generator<string> Yields directory paths
     */
    public function scanDirGeneratorForDirs(string $dir, array $exclude_patterns = [], ?int $start_device_id = null, array $selected_partitions = [], bool $follow_symlinks = false): Generator {
        static $processed_dirs          = [];
        static $depth_tracker           = [];
        static $approved_device_ids     = [];
        static $device_to_mount_cache   = [];

        // Reset static variables if this is a new root directory scan
        if ($dir === '/' || $dir === '\\') {
            $processed_dirs         = [];
            $depth_tracker          = [];
            $approved_device_ids    = [];
            $device_to_mount_cache  = [];
        }

        // Get device ID of the starting directory for partition boundary detection
        if ($start_device_id === null) {
            $start_stat         = @stat($dir);
            $start_device_id    = $start_stat ? $start_stat['dev'] : null;

            if ($start_device_id === null) {
                debug_echo("\r\033[K" . "⚠️ DEBUG: Cannot determine device ID for: $dir\n");
                return;
            }

            // Pre-compute approved device IDs for O(1) lookups
            $this->buildApprovedDeviceCache($selected_partitions, $approved_device_ids, $device_to_mount_cache);
        }

        // Prevent infinite recursion by tracking processed directories
        if (isset($processed_dirs[$dir])) {
            return;
        }

        $processed_dirs[$dir] = true;

        // Track directory depth to prevent circular references
        $current_depth = 0;

        if (isset($depth_tracker[$dir])) {
            $current_depth = $depth_tracker[$dir];
        }

        // Prevent excessive directory nesting (more than 50 levels deep)
        if ($current_depth > 50) { # TODO: This needs to use the circular reference check
            return;
        }

        // Get relative path for exclusion checking
        $relative_path = $this->getRelativePath($dir);

        // Early exclusion check - if this directory should be skipped, don't process it
        if ($this->shouldSkipDirectory($relative_path, $exclude_patterns)) {
            return; // Skip this entire directory and all its contents
        }

        try {
            $files = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);

            foreach ($files as $file) {
                if ($file->isDir()) {
                    // Set depth for subdirectory
                    $subdir_path = $file->getPathname();

                    // CRITICAL: Check if this directory is actually a symlink
                    if ($file->isLink()) {
                        // This is a symlinked directory - yield it but don't traverse into it
                        // unless --follow-symlinks is enabled
                        if (!$follow_symlinks) {
                            //debug_echo("\r\033[K" . "🔗 DEBUG: Yielding symlinked directory (not traversing): $subdir_path\n");
                            yield $file->getPathname(); // Yield the symlink directory itself
                            continue; // Don't traverse into the symlinked directory
                        } else {
                            debug_echo("\r\033[K" . "🔗 DEBUG: Following symlinked directory: $subdir_path\n");
                        }
                    }

                    // Fast partition check using pre-computed device ID cache
                    // Only apply partition boundaries when specific partitions are selected (system backup)
                    if (!empty($selected_partitions) && $this->shouldSkipUnselectedPartitionFast($subdir_path, $approved_device_ids, $device_to_mount_cache)) {
                        //debug_echo("\r\033[K" . "🚫 DEBUG: Skipping unselected partition (dirs): $subdir_path\n");
                        continue; // Skip this subdirectory - it's on an unselected partition
                    }

                    $depth_tracker[$subdir_path] = $current_depth + 1;

                    yield $file->getPathname();
                    yield from $this->scanDirGeneratorForDirs($subdir_path, $exclude_patterns, $start_device_id, $selected_partitions, $follow_symlinks);
                }
            }
        } catch (UnexpectedValueException $e) {
            // Permission denied - if we have sudo, try to access with elevated privileges
            if ($this->sudo_password !== null && $this->can_elevate) {
                // Try to list directory contents with sudo
                $escaped_dir    = escapeshellarg($dir);
                $command        = "sudo -S find $escaped_dir -type d 2>/dev/null";
                $output         = [];
                $return_code    = 0;

                exec("printf '%s\n' " . escapeshellarg($this->sudo_password) . " | $command", $output, $return_code);

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
     * Check if symlink exists and points to the expected target
     * 
     * @param string $link Path to the symlink
     * @param string $target Expected target path
     * @return bool True if symlink exists and points to target
     */
    public function symlinkExistsAndPointsTo(string $link, string $target): bool {
        if (!is_link($link)) {
            return false;
        }

        return readlink($link) === $target;
    }
    
    /**
     * Change file ownership with sudo fallback
     * 
     * @param string $filepath Path to the file
     * @param int|string $owner Owner ID or name
     * @return bool True if successful, false otherwise
     */
    private function chown(string $filepath, $owner): bool {
        // Try normal chown first
        if (@chown($filepath, $owner)) {
            return true;
        }

        // If failed and sudo is available, try with sudo
        if ($this->sudo_password !== null && $this->can_elevate) {
            $escaped_filepath   = escapeshellarg($filepath);
            $escaped_owner      = escapeshellarg($owner);
            $command            = "chown $escaped_owner $escaped_filepath";
            $output             = [];

            exec("printf '%s\n' " . escapeshellarg($this->sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
            return $code === 0;
        }

        return false;
    }
    
    /**
     * Change file group with sudo fallback
     * 
     * @param string $filepath Path to the file
     * @param int|string $group Group ID or name
     * @return bool True if successful, false otherwise
     */
    private function chgrp(string $filepath, $group): bool {
        if (@chgrp($filepath, $group)) {  // Try normal chgrp first
            return true;
        }
        
        if ($this->sudo_password !== null && $this->can_elevate) {  // If failed and sudo is available, try with sudo
            $escaped_filepath   = escapeshellarg($filepath);
            $escaped_group      = escapeshellarg($group);
            $command            = "chgrp $escaped_group $escaped_filepath";
            $output             = [];

            exec("printf '%s\n' " . escapeshellarg($this->sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
            return $code === 0;
        }

        return false;
    }
    
    /**
     * Analyze all mounted partitions and classify them
     * 
     * @return array Array of partition information
     */
    public function analyzePartitions(): array {
        $partitions = [];
        $output     = [];

        // Get all mount points with detailed information
        exec('mount | grep -E "^/dev/" | awk \'{print $1, $3, $5, $6}\'', $output);

        foreach ($output as $line) {
            $parts = explode(' ', trim($line));

            if (count($parts) >= 4) {
                $device         = $parts[0];
                $mount_point    = $parts[1];
                $filesystem     = $parts[2];
                $flags          = $parts[3];
                $size           = $this->getPartitionSize($device);  // Get partition size
                $is_system      = $this->isSystemPartition($mount_point, $filesystem);  // Determine if this is a system partition
                $should_image   = $this->shouldImagePartition($mount_point, $filesystem);  // Determine if this partition should be imaged
                $usage          = $this->getPartitionUsage($mount_point);  // Get additional info
                
                $partitions[] = [
                    'device'        => $device,
                    'mount_point'   => $mount_point,
                    'filesystem'    => $filesystem,
                    'size'          => $size,
                    'used'          => $usage['used'],
                    'free'          => $usage['free'],
                    'usage_percent' => $usage['usage_percent'],
                    'flags'         => $flags,
                    'is_system'     => $is_system,
                    'should_image'  => $should_image,
                    'description'   => $this->getPartitionDescription($mount_point, $filesystem)
                ];
            }
        }
        
        return $partitions;
    }
    
    /**
     * Get partition size in bytes
     * 
     * @param string $device Device path
     * @return int Size in bytes
     */
    private function getPartitionSize(string $device): int {
        $output = [];

        exec("lsblk -b -n -o SIZE $device 2>/dev/null", $output);

        if (!empty($output[0])) {
            return (int)trim($output[0]);
        }

        return 0;
    }
    
    /**
     * Get partition usage information
     * 
     * @param string $mount_point Mount point path
     * @return array Usage information
     */
    private function getPartitionUsage(string $mount_point): array {
        $total  = disk_total_space($mount_point);
        $free   = disk_free_space($mount_point);

        if ($total === false || $free === false) {
            return [
                'used'              => 0,
                'free'              => 0,
                 'usage_percent'    => 0
            ];
        }

        $used           = $total - $free;
        $usage_percent  = $total > 0 ? round(($used / $total) * 100, 1) : 0;

        return [
            'used'          => $used,
            'free'          => $free,
            'usage_percent' => $usage_percent
        ];
    }

    /**
     * Determine if a partition is part of the system
     * 
     * @param string $mount_point Mount point path
     * @param string $filesystem Filesystem type
     * @return bool True if system partition
     */
    private function isSystemPartition(string $mount_point, string $filesystem): bool {
        $system_paths   = ['/', '/boot', '/var', '/etc', '/home'];
        $excluded_fs    = ['ntfs', 'vfat', 'nfs', 'cifs', 'tmpfs', 'fuse'];

        // Always include system paths
        if (in_array($mount_point, $system_paths)) {
            return true;
        }

        // Exclude non-Linux filesystems
        if (in_array($filesystem, $excluded_fs)) {
            return false;
        }

        // Exclude media and mount points
        if (strpos($mount_point, '/media/') === 0 || strpos($mount_point, '/mnt/') === 0) {
            return false;
        }

        // Exclude temporary and runtime filesystems
        if (in_array($mount_point, ['/proc', '/sys', '/tmp', '/run', '/dev'])) {
            return false;
        }

        return false; // Default to exclude for safety
    }
    
    /**
     * Determine if a partition should be imaged instead of file-copied
     * 
     * @param string $mount_point Mount point path
     * @param string $filesystem Filesystem type
     * @return bool True if partition should be imaged
     */
    private function shouldImagePartition(string $mount_point, string $filesystem): bool {
        // Boot-related partitions that need bit-for-bit imaging
        $boot_partitions = ['/boot', '/boot/efi', '/efi'];

        // Special filesystems that need imaging
        $special_fs = ['vfat', 'fat16', 'fat32']; // EFI partitions

        // Check if it's a boot partition or special filesystem
        return in_array($mount_point, $boot_partitions) || in_array($filesystem, $special_fs);
    }
    
    /**
     * Get human-readable description of partition
     * 
     * @param string $mount_point Mount point path
     * @param string $filesystem Filesystem type
     * @return string Description
     */
    private function getPartitionDescription(string $mount_point, string $filesystem): string {
        switch (true) {
            case $mount_point === '/':
                return 'Root filesystem';
            case $mount_point === '/home':
                return 'User home directories';
            case $mount_point === '/var':
                return 'Variable data (logs, packages)';
            case $mount_point === '/boot':
                return 'Boot files and kernels';
            case $mount_point === '/etc':
                return 'System configuration';
            case strpos($mount_point, '/media/') === 0:
                return 'External media';
            case strpos($mount_point, '/mnt/') === 0:
                return 'Mounted filesystem';
            default:
                return ucfirst($filesystem) . ' filesystem';
        }
    }
    
    /**
     * Get available disk space in temp directory
     * 
     * @return int Available space in bytes
     */
    public function getAvailableTempSpace(): int {
        $temp_dir   = sys_get_temp_dir();
        $free_space = disk_free_space($temp_dir);

        if ($free_space === false) {  // Fallback: try to get space from current directory
            $free_space = disk_free_space('.');
        }

        if ($free_space === false) {  // Last resort: assume 25GB available
            return 1024 * 1024 * 1024 * 25;
        }

        return $free_space;
    }
    
    /**
     * Calculate maximum batch size based on available temp space
     * 
     * @return int Maximum batch size in bytes
     */
    public function getMaxBatchSize(): int {
        return (int) ($this->getAvailableTempSpace() * (BATCH_SIZE_PERCENTAGE / 100));
    }
    
    /**
     * Create kernel file manifest entry
     * 
     * Creates a manifest entry for kernel files with metadata.
     * Format: path\tmd5\tpermissions\towner\tgroup\tmtime\tatime\tctime\tsize
     * 
     * @param string $path Relative path to the kernel file (e.g., "kernels/vmlinuz")
     * @param string $md5 MD5 hash of the kernel file
     * @param string $fullpath Full path to the kernel file for metadata collection
     * @return string Tab-delimited manifest entry
     */
    public function createKernelEntry(string $path, string $md5, string $fullpath): string {
        $metadata = $this->getFileMetadata($fullpath);
    
        return implode("\t", [
            $path,
            $md5,
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
     * Create system metadata file manifest entry
     * 
     * Creates a manifest entry for system metadata files with metadata.
     * Format: path\tmd5\tpermissions\towner\tgroup\tmtime\tatime\tctime\tsize
     * 
     * @param string $path Relative path to the system metadata file (e.g., "system_meta/package_list.txt")
     * @param string $md5 MD5 hash of the system metadata file
     * @param string $fullpath Full path to the system metadata file for metadata collection
     * @return string Tab-delimited manifest entry
     */
    public function createSystemMetaEntry(string $path, string $md5, string $fullpath): string {
        $metadata = $this->getFileMetadata($fullpath);
    
        return implode("\t", [
            $path,
            $md5,
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
     * Build cache of approved device IDs for fast O(1) partition lookups
     * 
     * @param array $selected_partitions Array of selected partition info
     * @param array &$approved_device_ids Reference to cache array for approved device IDs
     * @param array &$device_to_mount_cache Reference to cache for device ID to mount point mapping
     */
    private function buildApprovedDeviceCache(array $selected_partitions, array &$approved_device_ids, array &$device_to_mount_cache): void {
        $approved_device_ids    = [];
        $device_to_mount_cache  = [];

        foreach ($selected_partitions as $partition) {
            $mount_point    = $partition['mount_point'];
            $stat           = @stat($mount_point);

            if ($stat !== false) {
                $device_id                          = $stat['dev'];
                $approved_device_ids[$device_id]    = true; // O(1) lookup
                $device_to_mount_cache[$device_id]  = $mount_point;

                debug_echo("\r\033[K" . "✅ DEBUG: Approved device ID $device_id for mount point: $mount_point\n");
            } else {
                debug_echo("\r\033[K" . "⚠️ DEBUG: Cannot stat selected partition: $mount_point\n");
            }
        }
        
        debug_echo("\r\033[K" . "🚀 DEBUG: Built fast lookup cache with " . count($approved_device_ids) . " approved device IDs\n");
    }
    
    /**
     * Fast check if a path is on an unselected partition using pre-computed device ID cache
     * 
     * @param string $path Path to check
     * @param array $approved_device_ids Pre-computed cache of approved device IDs
     * @param array $device_to_mount_cache Cache mapping device IDs to mount points
     * @return bool True if path should be skipped (unselected partition), false if should proceed
     */
    private function shouldSkipUnselectedPartitionFast(string $path, array $approved_device_ids, array $device_to_mount_cache): bool {
        $stat       = @stat($path);
        $device_id  = null;

        if ($stat === false) {  // If we can't stat the path, try with sudo (but this should be rare)
            if ($this->sudo_password !== null && $this->can_elevate) {
                $escaped_path   = escapeshellarg($path);
                $command        = "stat -c '%d' $escaped_path";
                $output         = [];

                exec("printf '%s\n' " . escapeshellarg($this->sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);

                if ($code === 0 && !empty($output[0])) {
                    $device_id = (int) trim($output[0]);
                }
            }

            if ($device_id === null) {  // If we can't determine device ID, assume unselected partition for safety
                return true;
            }
        } else {
            $device_id = $stat['dev'];
        }

        if (isset($approved_device_ids[$device_id])) {  // O(1) lookup in approved device IDs cache
            return false; // This device is approved, allow it
        }
        
        // This device is not approved, skip it
        $mount_point = $device_to_mount_cache[$device_id] ?? 'unknown';
        debug_echo("\r\033[K" . "🚫 DEBUG: Skipping unapproved device $device_id (mount: $mount_point) for path: $path\n");
        return true;
    }

    /**
     * Get available disk space for a directory
     * 
     * @param string $directory Directory path to check
     * @return array Array with 'free', 'total', 'used' in bytes
     */
    public function getDiskSpace(string $directory): array {
        $free_bytes     = disk_free_space($directory);
        $total_bytes    = disk_total_space($directory);

        if ($free_bytes === false || $total_bytes === false) {
            return ['free' => 0, 'total' => 0, 'used' => 0];
        }

        return [
            'free'  => $free_bytes,
            'total' => $total_bytes, 
            'used'  => $total_bytes - $free_bytes
        ];
    }

    /**
     * Estimate partition image size
     * 
     * @param string $device_path Device path (e.g., /dev/sda1)
     * @return array Array with 'raw_size', 'estimated_compressed' in bytes
     */
    public function estimateImageSize(string $device_path): array {
        $escaped_device = escapeshellarg($device_path);  // Get partition size using blockdev
        $output         = [];

        // Get size in bytes
        exec("sudo blockdev --getsize64 $escaped_device 2>/dev/null", $output, $code);

        if ($code !== 0 || empty($output[0])) {
            return [
                'raw_size'              => 0,
                'estimated_compressed'  => 0
            ];
        }

        $raw_size = (int)trim($output[0]);

        // No compression applied to images (RAR will compress when archiving), return raw size as the storage requirement
        return [
            'raw_size'              => $raw_size,
            'estimated_compressed'  => $raw_size
        ];
    }

    /**
     * Validate disk space for imaging operations
     * 
     * @param array $partitions_to_image Array of partition info arrays 
     * @param string $temp_dir Temporary directory for imaging
     * @param int $max_batch_size Maximum batch size in bytes
     * @return array Validation result with 'valid', 'total_estimated', 'available_space', 'errors'
     */
    public function validateImageDiskSpace(array $partitions_to_image, string $temp_dir, int $max_batch_size): array {
        $total_estimated_size   = 0;
        $errors                 = [];
        $image_details          = [];

        foreach ($partitions_to_image as $partition) {
            if (!$partition['should_image']) {
                continue;
            }

            // Get device path from mount point
            $device_path = $this->getDeviceFromMountPoint($partition['mount_point']);

            if (!$device_path) {
                $errors[] = "Could not determine device for mount point: {$partition['mount_point']}";
                continue;
            }

            // Estimate image size
            $size_info = $this->estimateImageSize($device_path);

            if ($size_info['raw_size'] === 0) {
                $errors[] = "Could not determine size for device: $device_path";
                continue;
            }

            $total_estimated_size += $size_info['estimated_compressed'];
            $image_details[] = [
                'mount_point'           => $partition['mount_point'],
                'device'                => $device_path,
                'raw_size'              => $size_info['raw_size'],
                'estimated_compressed'  => $size_info['estimated_compressed']
            ];
        }

        // Check available space in temp directory
        $disk_info          = $this->getDiskSpace($temp_dir);
        $available_space    = $disk_info['free'];
        $valid              = true;

        if ($total_estimated_size > $available_space) {
            $errors[] = sprintf(
                "Insufficient disk space: need %s, have %s available in %s",
                $this->formatBytes($total_estimated_size),
                $this->formatBytes($available_space),
                $temp_dir
            );
            $valid = false;
        }

        // Warning if exceeds max batch size (but still allow it)
        $exceeds_batch = $total_estimated_size > $max_batch_size;

        return [
            'valid'                 => $valid,
            'exceeds_batch_size'    => $exceeds_batch,
            'total_estimated'       => $total_estimated_size,
            'available_space'       => $available_space,
            'image_details'         => $image_details,
            'errors'                => $errors
        ];
    }

    /**
     * Get device path from mount point
     * 
     * @param string $mount_point Mount point path
     * @return string|null Device path or null if not found
     */
    private function getDeviceFromMountPoint(string $mount_point): ?string {
        $escaped_mount  = escapeshellarg($mount_point);
        $output         = [];

        // Use findmnt to get device for mount point
        exec("findmnt -n -o SOURCE $escaped_mount 2>/dev/null", $output, $code);

        if ($code === 0 && !empty($output[0])) {
            return trim($output[0]);
        }

        return null;
    }
    
    /**
     * Create partition image using dd (uncompressed, named by MD5)
     * 
     * @param string $device_path Source device path (e.g., /dev/sda1)
     * @param string $output_dir Output directory for image
     * @param string $mount_point Mount point for metadata collection
     * @param callable|null $progress_callback Optional progress callback
     * @return array Result with 'success', 'hash', 'size', 'metadata', 'error'
     */
    public function createPartitionImage(string $device_path, string $output_dir, string $mount_point, ?callable $progress_callback = null): array {
        // Collect partition metadata before imaging
        $metadata = $this->collectPartitionMetadata($device_path, $mount_point);
        
        // Create temporary file for image with unique name in output directory
        $temp_image = $output_dir . '/temp_image_' . uniqid(mt_rand(), true) . '.img';
        
        try {
            // Step 1: Create raw image using dd
            $dd_result = $this->createRawImage($device_path, $temp_image, $progress_callback);

            if (!$dd_result['success']) {
                @unlink($temp_image);
                return ['success' => false, 'error' => 'DD operation failed: ' . $dd_result['error']];
            }

            // Step 2: Calculate hash of raw image
            if ($progress_callback) {
                $progress_callback('Calculating image hash...', 90);
            }

            $hash = $this->getFileMd5($temp_image);

            if ($hash === false) {
                @unlink($temp_image);
                return ['success' => false, 'error' => 'Failed to calculate image hash'];
            }

            // Step 3: Move to final location with MD5 name
            $final_path = $output_dir . '/' . $hash;

            // Check if image with this hash already exists (deduplication)
            if (file_exists($final_path)) {
                @unlink($temp_image);

                if ($progress_callback) {
                    $progress_callback('Image already exists (deduplicated)', 100);
                }
                
                return [
                    'success'       => true,
                    'hash'          => $hash,
                    'size'          => filesize($final_path),
                    'metadata'      => $metadata,
                    'deduplicated'  => true
                ];
            }

            // Rename temp image to final MD5-based name
            if (!rename($temp_image, $final_path)) {
                @unlink($temp_image);

                return [
                    'success'   => false,
                    'error'     => 'Failed to rename image to MD5 filename'
                ];
            }

            if ($progress_callback) {
                $progress_callback('Image creation complete', 100);
            }

            return [
                'success'       => true,
                'hash'          => $hash,
                'size'          => filesize($final_path),
                'metadata'      => $metadata,
                'deduplicated'  => false
            ];
        } catch (Exception $e) {
            // Clean up on error
            @unlink($temp_image);
            return ['success' => false, 'error' => 'Exception: ' . $e->getMessage()];
        }
    }

    /**
     * Create raw partition image using dd
     * 
     * @param string $device_path Source device
     * @param string $output_path Output file
     * @param callable|null $progress_callback Progress callback
     * @return array Result with 'success' and 'error'
     */
    private function createRawImage(string $device_path, string $output_path, ?callable $progress_callback = null): array {
        $escaped_device = escapeshellarg($device_path);
        $escaped_output = escapeshellarg($output_path);

        // Use dd with status=progress for monitoring
        $dd_cmd = "sudo dd if=$escaped_device of=$escaped_output bs=1M status=progress 2>&1";

        // Execute dd with progress monitoring
        $process = popen($dd_cmd, 'r');

        if (!$process) {
            return ['success' => false, 'error' => 'Failed to start dd process'];
        }

        $output_lines   = [];
        $last_progress  = 0;

        while (!feof($process)) {
            $line = fgets($process);

            if ($line !== false) {
                $output_lines[] = trim($line);
                
                // Parse dd progress output
                if ($progress_callback && preg_match('/(\d+) bytes.* copied/i', $line, $matches)) {
                    $bytes_copied = (int)$matches[1];
                    // Estimate progress (we don't know total size during dd), use time-based estimation instead
                    $current_time = time();

                    if (!isset($start_time)) {
                        $start_time = $current_time;
                    }

                    // Progress estimation based on time (rough)
                    $elapsed    = $current_time - $start_time;
                    $progress   = min(85, $elapsed * 3); // Assume max 85% for dd phase (no compression)

                    if ($progress > $last_progress + 5) { // Update every 5%
                        $progress_callback("Creating raw image: " . $this->formatBytes($bytes_copied) . " copied", $progress);
                        $last_progress = $progress;
                    }
                }
            }
        }

        $exit_code = pclose($process);

        if ($exit_code !== 0) {
            return [
                'success'   => false,
                'error'     => 'DD failed with exit code ' . $exit_code . ': ' . implode("\n", $output_lines)
            ];
        }

        if (!file_exists($output_path)) {
            return [
                'success'   => false,
                'error'     => 'DD completed but output file not found'
            ];
        }

        return [
            'success' => true
        ];
    }

    /**
     * Collect partition metadata for restore purposes
     * 
     * @param string $device_path Device path
     * @param string $mount_point Mount point
     * @return array Metadata array
     */
    private function collectPartitionMetadata(string $device_path, string $mount_point): array {
        $metadata = [
            'source_device' => $device_path,
            'source_mount'  => $mount_point,
            'creation_time' => time()
        ];

        // Get filesystem type
        $escaped_device = escapeshellarg($device_path);
        $output         = [];

        exec("blkid -s TYPE -o value $escaped_device 2>/dev/null", $output);

        if (!empty($output[0])) {
            $metadata['filesystem'] = trim($output[0]);
        }

        // Get UUID
        $output         = [];

        exec("blkid -s UUID -o value $escaped_device 2>/dev/null", $output);

        if (!empty($output[0])) {
            $metadata['uuid'] = trim($output[0]);
        }

        // Get label
        $output         = [];

        exec("blkid -s LABEL -o value $escaped_device 2>/dev/null", $output);

        if (!empty($output[0])) {
            $metadata['label'] = trim($output[0]);
        }

        // Get block size and total blocks
        $output         = [];

        exec("sudo blockdev --getbsz $escaped_device 2>/dev/null", $output);

        if (!empty($output[0])) {
            $metadata['block_size'] = (int)trim($output[0]);
        }

        $output         = [];

        exec("sudo blockdev --getsize $escaped_device 2>/dev/null", $output);

        if (!empty($output[0])) {
            $metadata['total_blocks'] = (int)trim($output[0]);
        }

        return $metadata;
    }
    
    /**
     * Create images.json metadata file for manifest
     * 
     * @param array $image_info Array of image information
     * @param string $output_path Path for the .images.json file
     * @return bool True if successful, false otherwise
     */
    public function createImagesMetadata(array $image_info, string $output_path): bool {
        $metadata = [
            'created_time'      => time(),
            'format_version'    => '1.0',
            'images'            => $image_info
        ];

        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        return $this->writeFileContents($output_path, $json) !== false;
    }
    
    /**
     * Process all selected partitions for imaging
     * 
     * @param array $selected_partitions Array of selected partition info
     * @param string $temp_images_dir Temporary directory for images
     * @param callable|null $progress_callback Progress callback
     * @return array Result with 'success', 'images_info', 'errors'
     */
    public function processPartitionImages(array $selected_partitions, string $temp_images_dir, ?callable $progress_callback = null): array {
        $images_info        = [];
        $errors             = [];
        $total_images       = 0;
        $processed_images   = 0;

        // Count total images to process
        foreach ($selected_partitions as $partition) {
            if ($partition['should_image']) {
                $total_images++;
            }
        }

        if ($total_images === 0) {
            return [
                'success'       => true,
                'images_info'   => [],
                'errors'        => []
            ];
        }

        // Create images directory
        if (!is_dir($temp_images_dir)) {
            mkdir($temp_images_dir, 0755, true);
        }
        
        foreach ($selected_partitions as $partition) {
            if (!$partition['should_image']) {
                continue;
            }

            $processed_images   = $processed_images + 1;
            $mount_point        = $partition['mount_point'];
            $device_path        = $this->getDeviceFromMountPoint($mount_point);

            if (!$device_path) {
                $errors[] = "Could not determine device for mount point: $mount_point";
                continue;
            }

            // Progress callback for this image
            $image_progress_callback = null;

            if ($progress_callback) {
                $image_progress_callback = function($message, $percent) use ($progress_callback, $processed_images, $total_images) {
                    $overall_percent = (($processed_images - 1) / $total_images) * 100 + ($percent / $total_images);
                    $progress_callback("Image $processed_images/$total_images: $message", $overall_percent);
                };
            }

            // Create the image (MD5-named, stored in temp_images_dir)
            $result = $this->createPartitionImage($device_path, $temp_images_dir, $mount_point, $image_progress_callback);

            if ($result['success']) {
                $images_info[] = [
                    'source_device' => $device_path,
                    'source_mount'  => $mount_point,
                    'filesystem'    => $result['metadata']['filesystem'] ?? 'unknown',
                    'image_size'    => $result['size'],
                    'image_hash'    => $result['hash'],  // MD5 hash - used as filename in images/ directory
                    'creation_time' => $result['metadata']['creation_time'],
                    'restore_info'  => $result['metadata'],
                    'deduplicated'  => $result['deduplicated'] ?? false
                ];
            } else {
                $errors[] = "Failed to create image for $mount_point: " . $result['error'];
            }
        }

        $success = empty($errors) || count($images_info) > 0; // Success if we got at least one image

        return [
            'success'       => $success,
            'images_info'   => $images_info,
            'errors'        => $errors
        ];
    }

    /**
     * Format bytes into human readable format
     * 
     * @param int $bytes Number of bytes
     * @return string Formatted string
     */
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

}

// === END INLINED: /src/fileSystemManager.php ===


// === INLINED: /src/archiveManager.php ===
/**
 * Archive Manager Class
 * 
 * Centralizes all RAR archive operations including creation, extraction,
 * password management, and progress tracking. Consolidates archive-related
 * functionality into a single, maintainable class.
 */
class ArchiveManager {
    private $password;

    private $fs_manager;
    
    /**
     * Constructor
     * 
     * @param string|null $password Password for archive access
     * @param FileSystemManager|null $fs_manager File system manager for sudo operations
     */
    public function __construct(?string $password = null, ?FileSystemManager $fs_manager = null) {
        $this->password     = $password;
        $this->fs_manager   = $fs_manager;
    }
    
    /**
     * Check if archive is encrypted
     * 
     * @param string $rarfile Path to RAR archive
     * @param string $rarfile Path to RAR archive
     * @return bool True if encrypted, false otherwise
     */
    public function isEncrypted(string $rarfile): bool {
        if ($this->fs_manager) {
            if (!$this->fs_manager->fileExists($rarfile)) {
                return false;
            }
        } else {
            if (!file_exists($rarfile)) {
                return false;
            }
        }
        
        $file_size = filesize($rarfile);

        if ($file_size === 0) {
            return false;
        }
        
        // Try to list archive contents without password, using an approach that won't trigger a password prompt
        $rar_cmd = 'printf "" | rar lb -inul ' . escapeshellarg($rarfile) . ' 2>/dev/null';
        
        exec($rar_cmd, $output, $code);
        
        // If RAR command fails, it's likely encrypted
        return $code !== 0;
    }
    
    /**
     * Test if a password works for an archive
     * 
     * @param string $rarfile Path to RAR archive
     * @param string $password Password to test
     * @return bool True if password works, false otherwise
     */
    public function testPassword(string $rarfile, string $password): bool {
        // Check if file exists first
        if ($this->fs_manager) {
            if (!$this->fs_manager->fileExists($rarfile)) {
                return false;
            }
        } else {
            if (!file_exists($rarfile)) {
                return false;
            }
        }
        
        $output     = [];
        $rar_cmd    = 'rar lb ' . BF_ENCRYPTION_MODE . escapeshellarg($password) . ' ' . escapeshellarg($rarfile);

        exec($rar_cmd, $output, $code);
        
        return $code === 0;
    }
    
    /**
     * Get archive size in bytes
     * 
     * @param string $rarfile Path to RAR archive
     * @return int Archive size in bytes
     */
    public function getSize(string $rarfile): int {
        if ($this->fs_manager) {
            if (!$this->fs_manager->fileExists($rarfile)) {
                return 0;
            }
        } else {
            if (!file_exists($rarfile)) {
                return 0;
            }
        }
        
        return filesize($rarfile);
    }
    
    /**
     * Get archive hashes for deduplication
     * 
     * @param string $rarfile Path to RAR archive
     * @return array Hash map for deduplication
     */
    public function getHashes(string $rarfile): array {
        $hashmap = [];

        if ($this->fs_manager) {
            if (!$this->fs_manager->fileExists($rarfile)) {
                return $hashmap;
            }
        } else {
            if (!file_exists($rarfile)) {
                return $hashmap;
            }
        }
        
        if (!$this->password && $this->isEncrypted($rarfile)) {
            return $hashmap; // Can't access encrypted archive without password
        }
        
        $output     = [];
        $rar_cmd    = 'rar lb ' . escapeshellarg($rarfile);
        
        if ($this->password) {
            $rar_cmd = 'rar lb ' . BF_ENCRYPTION_MODE . escapeshellarg($this->password) . ' ' . escapeshellarg($rarfile);
        }
        
        exec($rar_cmd, $output, $code);
        
        if ($code !== 0) {
            return $hashmap;
        }

        foreach ($output as $line) {
            if (strpos($line, 'files/') === 0) {
                $hash = basename($line);
                $hashmap[$hash] = true;
            }
        }

        return $hashmap;
    }
    
    /**
     * Get next commit ID
     * 
     * @param string $rarfile Path to RAR archive
     * @return int Next commit ID
     */
    public function getNextCommitId(string $rarfile): int {
        $next_id = 1;
        
        if (!$this->password && $this->isEncrypted($rarfile)) {
            return $next_id; // Can't access encrypted archive without password
        }
        
        $output     = [];
        $rar_cmd    = 'rar lb ' . escapeshellarg($rarfile);
        
        if ($this->password) {
            $rar_cmd = 'rar lb ' . BF_ENCRYPTION_MODE . escapeshellarg($this->password) . ' ' . escapeshellarg($rarfile);
        }
        
        exec($rar_cmd, $output, $code);
        
        if ($code !== 0) {
            return $next_id;
        }

        foreach ($output as $line) {
            if (preg_match('/^(\d+)-.*\.txt$/', $line, $matches)) {
                $id = (int)$matches[1];
                if ($id >= $next_id) {
                    $next_id = $id + 1;
                }
            }
        }

        return $next_id;
    }
    
    /**
     * List all versions in archive
     * 
     * @param string $rarfile Path to RAR archive
     * @return array Array of version information
     */
    public function listVersions(string $rarfile): array {
        $versions = [];
        
        if (!$this->password && $this->isEncrypted($rarfile)) {
            return $versions; // Can't access encrypted archive without password
        }

        $output     = [];
        $rar_cmd    = 'rar lb ' . escapeshellarg($rarfile) . ' versions/';

        if ($this->password) {
            $rar_cmd = 'rar lb ' . BF_ENCRYPTION_MODE . escapeshellarg($this->password) . ' ' . escapeshellarg($rarfile) . ' versions/';
        }

        exec($rar_cmd, $output, $code);

        if ($code !== 0) {
            // If command failed and we have a password, it might be wrong
            if ($this->password && ($code === 10 || $code === 11)) {
                throw new Exception("Incorrect password for archive");
            }
            return $versions;
        }

        foreach ($output as $line) {
            if (preg_match('/^versions\/(\d+)-(\d{4})-(\d{2})-(\d{2}) (\d{2})[-:](\d{2})[-:](\d{2})\.txt$/', $line, $matches)) {
                $id     = (int) $matches[1];
                $year   = (int) $matches[2];
                $month  = (int) $matches[3];
                $day    = (int) $matches[4];
                $hour   = (int) $matches[5];
                $minute = (int) $matches[6];
                $second = (int) $matches[7];
                
                $timestamp = mktime($hour, $minute, $second, $month, $day, $year);
                
                $versions[] = [
                    'id'        => $id,
                    'name'      => $line,
                    'timestamp' => $timestamp,
                    'date'      => date('Y-m-d H:i:s', $timestamp),
                    'ts'        => date('Y-m-d H:i:s', $timestamp)
                ];
            }
        }

        // Sort by ID (newest first)
        usort($versions, function($a, $b) {
            return $b['id'] - $a['id'];
        });

        return $versions;
    }
    
    /**
     * Get comment from manifest file
     * 
     * @param string $rarfile Path to RAR archive
     * @param string $manifest_name Manifest file name
     * @return string Comment text or "No comment"
     */
    public function getCommentFromManifest(string $rarfile, string $manifest_name): string {
        $temp = sys_get_temp_dir() . '/rarrepo_comment_' . uniqid(mt_rand(), true);
        mkdir($temp, 0700, true);

        // Extract the manifest file
        $rar_cmd = 'rar e -inul';

        if ($this->password) {
            $rar_cmd .= ' ' . BF_ENCRYPTION_MODE . escapeshellarg($this->password);
        }

        $rar_cmd .= ' ' . escapeshellarg($rarfile) . ' ' . escapeshellarg($manifest_name) . ' ' . escapeshellarg($temp);

        exec($rar_cmd, $output, $code);

        if ($code !== 0) {
            exec('rm -rf ' . escapeshellarg($temp));
            return "No comment";
        }

        $manifest_path = "$temp/" . basename($manifest_name);
        if (!file_exists($manifest_path)) {
            exec('rm -rf ' . escapeshellarg($temp));
            return "No comment";
        }
        
        // Read the manifest file and extract comment
        $content = file_get_contents($manifest_path);
        exec('rm -rf ' . escapeshellarg($temp));
        
        // Look for comment in the format "# comment text"
        if (preg_match('/^# (.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }
        
        return "No comment";
    }
    
    /**
     * Get last manifest from archive
     * 
     * @param string $rarfile Path to RAR archive
     * @return array|null Manifest information or null if not found
     */
    public function getLastManifest(string $rarfile): ?array {
        $versions = $this->listVersions($rarfile);
        
        if (empty($versions)) {
            return null;
        }
        
        $latest = $versions[0];
        
        // Extract the timestamp string from the name (e.g., "1-2025-08-15 21:21:30.txt" -> "2025-08-15 21:21:30")
        if (preg_match('/^versions\/(\d+)-(.+)\.txt$/', $latest['name'], $matches)) {
            $ts = $matches[2];
        } else {
            $ts = date('Y-m-d H:i:s', $latest['timestamp']);
        }

        return [
            'id'        => $latest['id'],
            'name'      => $latest['name'],
            'ts'        => $ts,
            'timestamp' => $latest['timestamp'],
            'date'      => $latest['date']
        ];
    }
    
    /**
     * Find manifest for specific commit ID
     * 
     * @param string $rarfile Path to RAR archive
     * @param int $commit_id Commit ID to find
     * @return array|null Manifest information or null if not found
     */
    public function findManifestForCommit(string $rarfile, int $commit_id): ?array {
        $versions = $this->listVersions($rarfile);
        
        foreach ($versions as $version) {
            if ($version['id'] === $commit_id) {
                return [
                    'id'        => $version['id'],
                    'name'      => $version['name'],
                    'timestamp' => $version['timestamp'],
                    'date'      => $version['date']
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Extract manifest from archive
     * 
     * @param string $rarfile Path to RAR archive
     * @param string $manifest_name Name of manifest to extract
     * @param string $temp_dir Temporary directory for extraction
     * @return string|false Path to extracted manifest or false on failure
     */
    public function extractManifest(string $rarfile, string $manifest_name, string $temp_dir): string|false {
        $rar_cmd = 'rar e ';
        
        if ($this->password) {
            $rar_cmd .= BF_ENCRYPTION_MODE . escapeshellarg($this->password) . ' ';
        }
        
        $rar_cmd .= escapeshellarg($rarfile) . ' ' . escapeshellarg($manifest_name) . ' ' . escapeshellarg($temp_dir);
        
        exec($rar_cmd, $output, $code);
        
        if ($code !== 0) {
            return false;
        }
        
        $manifest_path = $temp_dir . '/' . basename($manifest_name);
        
        if ($this->fs_manager) {
            return $this->fs_manager->fileExists($manifest_path) ? $manifest_path : false;
        } else {
            return file_exists($manifest_path) ? $manifest_path : false;
        }
    }
    
    /**
     * Repair archive if possible
     * 
     * @param string $rarfile Path to RAR archive
     * @return bool True if repair was successful, false otherwise
     */
    public function repair(string $rarfile): bool {
        if (!$this->password && $this->isEncrypted($rarfile)) {
            return false; // Can't repair encrypted archive without password
        }

        $rar_cmd = 'rar r ' . escapeshellarg($rarfile);

        if ($this->password) {
            $rar_cmd = 'rar r ' . BF_ENCRYPTION_MODE . escapeshellarg($this->password) . ' ' . escapeshellarg($rarfile);
        }

        exec($rar_cmd, $output, $code);

        return $code === 0;
    }
}

// === END INLINED: /src/archiveManager.php ===


// === INLINED: /src/systemHealthManager.php ===
/**
 * SystemHealthManager Class
 * 
 * Centralizes system health, validation, and monitoring functions.
 * Handles required function checks, memory management, and system validation.
 */
class SystemHealthManager {
    
    public function __construct() {

    }

    /**
     * Check if all required PHP functions are available
     * 
     * @return bool True if all required functions exist, false otherwise
     */
    public function checkRequiredFunctions(): bool {
        $required_functions = [
            'exec',
            'passthru',
            'posix_getuid',
            'posix_getpwnam',
            'posix_getgrnam',
            'posix_getpwuid',
            'posix_getgrgid',
            'posix_isatty',
            'function_exists',
            'file_exists',
            'is_file',
            'is_dir',
            'is_link',
            'readlink',
            'realpath',
            'stat',
            'lstat',
            'chmod',
            'chown',
            'chgrp',
            'copy',
            'mkdir',
            'rmdir',
            'unlink',
            'symlink',
            'link',
            'rename',
            'scandir',
            'file',
            'filesize',
            'filemtime',
            'fileatime',
            'filectime',
            'fileperms',
            'fileowner',
            'filegroup',
            'md5_file',
            'uniqid',
            'mt_rand',
            'sys_get_temp_dir',
            'time',
            'microtime',
            'date',
            'number_format',
            'round',
            'explode',
            'implode',
            'array_slice',
            'count',
            'trim',
            'substr',
            'strlen',
            'strpos',
            'preg_match',
            'escapeshellarg'
        ];

        $missing_functions = [];

        foreach ($required_functions as $function) {
            if (!function_exists($function)) {
                $missing_functions[] = $function;
            }
        }

        if (!empty($missing_functions)) {
            echo "ERROR: Required PHP functions are missing:\n";

            foreach ($missing_functions as $function) {
                echo "  - $function()\n";
            }

            return false;
        }

        return true;
    }

    /**
     * Validate system requirements for bitfreeze
     * 
     * @return bool True if system meets requirements, false otherwise
     */
    public function validateSystemRequirements(): bool {
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4.0', '<')) {
            echo "ERROR: PHP 7.4 or higher is required. Current version: " . PHP_VERSION . "\n";
            return false;
        }

        // Check required functions
        if (!$this->checkRequiredFunctions()) {
            return false;
        }

        // Check if we're on a supported OS
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Unix'])) {
            echo "WARNING: bitfreeze is primarily designed for Linux/Unix systems.\n";
            echo "Current OS: " . PHP_OS_FAMILY . "\n";
        }

        // Check if RAR command is available
        $output = [];

        exec('which rar 2>/dev/null', $output, $code);

        if ($code !== 0) {
            echo "ERROR: RAR command not found. Please install RAR archiver.\n";
            echo "On Ubuntu/Debian: sudo apt-get install rar\n";
            echo "On CentOS/RHEL: sudo yum install rar\n";
            return false;
        }

        return true;
    }
}


// === END INLINED: /src/systemHealthManager.php ===


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



