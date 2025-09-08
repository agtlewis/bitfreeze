<?php
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
