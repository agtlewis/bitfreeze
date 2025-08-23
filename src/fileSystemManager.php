<?php
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
        $this->sudo_password = $sudo_password;
        $this->can_elevate = $this->canElevatePrivileges();
        $this->display = new DisplayManager();
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
        
        // Test if user can run sudo (this will prompt for password if needed)
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
            $escaped_filepath = escapeshellarg($filepath);
            $command = "stat -c '%a %u %g %Y %X %Z %s' $escaped_filepath";
            $output = [];
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
        // Get the symlink target - readlink should work even for broken symlinks
        $target = readlink($fullpath);
        if ($target === false) {
            // If readlink fails, it's likely a permission issue, not a broken symlink
            // Try with sudo if available
            if ($this->sudo_password !== null && $this->can_elevate) {
                $escaped_path = escapeshellarg($fullpath);
                $command = "readlink $escaped_path";
                $output = [];
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
        // Copy file content
        if (!copy($source, $dest)) {
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
        $owner_id = is_numeric($metadata['owner']) ? 
            $metadata['owner'] : 
            (function_exists('posix_getpwnam') ? (posix_getpwnam($metadata['owner'])['uid'] ?? null) : null);
        
        $group_id = is_numeric($metadata['group']) ? 
            $metadata['group'] : 
            (function_exists('posix_getgrnam') ? (posix_getgrnam($metadata['group'])['gid'] ?? null) : null);

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
        // Try normal copy first
        if (@copy($source, $dest)) {
            return true;
        }
        
        // If failed and sudo is available, try with sudo
        if ($this->sudo_password !== null && $this->can_elevate) {
            $escaped_source = escapeshellarg($source);
            $escaped_dest = escapeshellarg($dest);
            $command = "cp --force $escaped_source $escaped_dest";
            
            if ($this->executeWithSudo($command)) {
                // If copy succeeded, fix ownership so RAR can read the file
                $current_user = posix_getpwuid(posix_geteuid())['name'];
                $escaped_dest = escapeshellarg($dest);
                $chown_command = "chown $current_user:$current_user $escaped_dest";
                
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
        // Try normal is_readable first
        if (@is_readable($filepath)) {
            return true;
        }

        // If failed and sudo is available, try with sudo
        if ($this->sudo_password !== null && $this->can_elevate) {
            $escaped_filepath = escapeshellarg($filepath);
            $command = "test -r $escaped_filepath";

            $output = [];
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
        // Try normal md5_file first
        $hash = @md5_file($filepath);
        if ($hash !== false) {
            return $hash;
        }
        
        // If failed and sudo is available, try with sudo
        if ($this->sudo_password !== null && $this->can_elevate) {
            $escaped_filepath = escapeshellarg($filepath);
            $command = "md5sum $escaped_filepath | cut -d' ' -f1";
            
            $output = [];
            exec("timeout " . MD5_TIMEOUT . " printf '%s\n' " . escapeshellarg($this->sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
            
            if ($code === 124) {
                // Command timed out
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
        // Try normal file_get_contents first
        $content = @file_get_contents($filepath);
        if ($content !== false) {
            return $content;
        }
        
        // If failed and sudo is available, try with sudo
        if ($this->sudo_password !== null && $this->can_elevate) {
            $escaped_filepath = escapeshellarg($filepath);
            $command = "cat $escaped_filepath";
            
            $output = [];
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
        // Try normal file_put_contents first
        $result = @file_put_contents($filepath, $content, $flags);
        if ($result !== false) {
            return true;
        }
        
        // If failed and sudo is available, try with sudo
        if ($this->sudo_password !== null && $this->can_elevate) {
            $escaped_filepath = escapeshellarg($filepath);
            $escaped_content = escapeshellarg($content);
            
            // Handle different flags
            if ($flags & FILE_APPEND) {
                $command = "echo $escaped_content >> $escaped_filepath";
            } else {
                $command = "echo $escaped_content > $escaped_filepath";
            }
            
            if ($this->executeWithSudo($command)) {
                // Fix ownership so current user can read/write the file
                $current_user = posix_getpwuid(posix_geteuid())['name'];
                $chown_command = "chown $current_user:$current_user $escaped_filepath";
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
        // Try normal file_exists first
        if (@file_exists($filepath)) {
            return true;
        }
        
        // If failed and sudo is available, try with sudo
        if ($this->sudo_password !== null && $this->can_elevate) {
            $escaped_filepath = escapeshellarg($filepath);
            $command = "test -e $escaped_filepath";
            
            $output = [];
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
        // Try normal is_writable first
        if (@is_writable($filepath)) {
            return true;
        }
        
        // If failed and sudo is available, try with sudo
        if ($this->sudo_password !== null && $this->can_elevate) {
            $escaped_filepath = escapeshellarg($filepath);
            $command = "test -w $escaped_filepath";
            
            $output = [];
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
        // Try normal is_writable first
        if (@is_writable($dirpath)) {
            return true;
        }
        
        // If failed and sudo is available, try with sudo
        if ($this->sudo_password !== null && $this->can_elevate) {
            $escaped_dirpath = escapeshellarg($dirpath);
            $command = "test -w $escaped_dirpath";
            
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
        // Try normal is_dir first
        if (@is_dir($path)) {
            return true;
        }
        
        // If failed and sudo is available, try with sudo
        if ($this->sudo_password !== null && $this->can_elevate) {
            $escaped_path = escapeshellarg($path);
            $command = "test -d $escaped_path";
            
            return $this->executeWithSudo($command);
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
        // Check if we can already access the folder
        if (!is_readable($folder)) {
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
        static $processed_dirs = [];
        static $file_count = 0;
        static $depth_tracker = [];
        static $approved_device_ids = [];
        static $device_to_mount_cache = [];
        static $circular_ref_context = [];
        
        // Reset static variables if this is a new root directory scan
        if ($dir === '/' || $dir === '\\') {
            $processed_dirs = [];
            $file_count = 0;
            $depth_tracker = [];
            $approved_device_ids = [];
            $device_to_mount_cache = [];
            $circular_ref_context = [];
        }
        
        // Get device ID of the starting directory for partition boundary detection
        if ($start_device_id === null) {
            $start_stat = @stat($dir);
            $start_device_id = $start_stat ? $start_stat['dev'] : null;
            if ($start_device_id === null) {
                debug_echo("\r\033[K" . "⚠️  DEBUG: Cannot determine device ID for: $dir\n");
                return;
            }
            debug_echo("\r\033[K" . "🔍 DEBUG: Starting partition scan with device ID: $start_device_id for: $dir\n");
            
            // Pre-compute approved device IDs for O(1) lookups
            $this->buildApprovedDeviceCache($selected_partitions, $approved_device_ids, $device_to_mount_cache);
        }
        
        // Get relative path for exclusion checking
        $relative_path = $this->getRelativePath($dir);
        
        // Prevent infinite recursion by tracking processed directories
        if (isset($processed_dirs[$dir])) {
            debug_echo("\r\033[K" . "⚠️  DEBUG: Skipping already processed directory: $dir\n");
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
            debug_echo("\r\033[K" . "⚠️  DEBUG: Maximum directory depth exceeded for: $dir\n");
            return;
        }
        
        // Early exclusion check - if this directory should be skipped, don't process it
        if ($this->shouldSkipDirectory($relative_path, $exclude_patterns)) {
            debug_echo("\r\033[K" . "⏭️  DEBUG: Skipping excluded directory: $relative_path\n");
            return; // Skip this entire directory and all its contents
        }
        
        // Check for hidden files in home directories (skip .steam, .cache, etc.)
        if ($this->shouldSkipHiddenHomeDirectory($relative_path)) {
            debug_echo("\r\033[K" . "⏭️  DEBUG: Skipping hidden home directory: $relative_path\n");
            return;
        }
        
        // Use realpath to detect circular references and unresolvable paths
        $real_path = realpath($dir);
        if ($real_path === false) {
            debug_echo("\r\033[K" . "⚠️  DEBUG: Skipping unresolvable path: $dir\n");
            return;
        }
        
        // Check for circular references using hybrid approach (fast + accurate)
        if ($this->hasCircularReference($real_path, $circular_ref_context)) {
            debug_echo("\r\033[K" . "⚠️  DEBUG: Skipping circular reference path: $real_path\n");
            return;
        }
        
        // Debug: Show current directory being processed (less verbose)
        if ($file_count % 10000 === 0) {
            debug_echo("\r\033[K" . "🔍 DEBUG: Processing directory: $relative_path (Total files: " . number_format($file_count) . ")\n");
        }
        
        try {
            $files = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
            foreach ($files as $file) {
                if ($file->isDir()) {
                    // Set depth for subdirectory
                    $subdir_path = $file->getPathname();
                    
                    // CRITICAL: Check if this directory is actually a symlink
                    if ($file->isLink()) {
                        // This is a symlinked directory - yield it as a file, don't traverse into it
                        // unless --follow-symlinks is enabled
                        if (!$follow_symlinks) {
                            debug_echo("\r\033[K" . "🔗 DEBUG: Yielding symlinked directory (not traversing): $subdir_path\n");
                            $file_count++;
                            yield $subdir_path; // Yield the symlink itself as a file
                            continue; // Don't traverse into the symlinked directory
                        } else {
                            debug_echo("\r\033[K" . "🔗 DEBUG: Following symlinked directory: $subdir_path\n");
                        }
                    }
                    
                    // Fast partition check using pre-computed device ID cache
                    if ($this->shouldSkipUnselectedPartitionFast($subdir_path, $approved_device_ids, $device_to_mount_cache)) {
                        debug_echo("\r\033[K" . "🚫 DEBUG: Skipping unselected partition: $subdir_path\n");
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
                debug_echo("\r\033[K" . "🔐 DEBUG: Using sudo fallback for: $dir\n");
                
                // Try to list directory contents with sudo for complete coverage
                $escaped_dir = escapeshellarg($dir);
                $command = "sudo -S find $escaped_dir -type f 2>/dev/null";
                
                $output = [];
                $return_code = 0;
                exec("printf '%s\n' " . escapeshellarg($this->sudo_password) . " | $command", $output, $return_code);
                
                if ($return_code === 0) {
                    debug_echo("\r\033[K" . "✅ DEBUG: Sudo fallback successful, found " . count($output) . " files in $dir\n");
                    
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
                    // This was causing the infinite loop - we're already getting all files from find
                    debug_echo("\r\033[K" . "⚠️  DEBUG: Skipping recursive processing for sudo fallback directory: $dir\n");
                    return;
                }
            }
            // Otherwise, skip this directory silently
            debug_echo("\r\033[K" . "❌ DEBUG: Permission denied and no sudo fallback for: $dir\n");
        }
        
        // Show circular reference detection statistics when scan completes
        if ($dir === '/' && !empty($circular_ref_context['stats'])) {
            $stats = $circular_ref_context['stats'];
            $total_checks = $stats['fast_checks'] + $stats['slow_checks'];
            if ($total_checks > 0) {
                debug_echo("\r\033[K" . "📊 DEBUG: Circular reference detection stats:\n");
                debug_echo("  Fast checks: " . number_format($stats['fast_checks']) . " (" . 
                          number_format($stats['fast_checks'] / $total_checks * 100, 1) . "%)\n");
                debug_echo("  Slow checks: " . number_format($stats['slow_checks']) . " (" . 
                          number_format($stats['slow_checks'] / $total_checks * 100, 1) . "%)\n");
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
        // For system backup, we want to exclude top-level directories
        $root_path = '/';
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
        if (strpos($relative_path, 'proc/') === 0 || 
            strpos($relative_path, 'sys/') === 0 || 
            strpos($relative_path, 'tmp/') === 0 || 
            strpos($relative_path, 'run/') === 0 || 
            strpos($relative_path, 'dev/') === 0 ||
            strpos($relative_path, 'var/cache/') === 0 ||
            strpos($relative_path, 'var/tmp/') === 0 ||
            strpos($relative_path, 'var/log/') === 0 ||
            strpos($relative_path, 'var/run/') === 0 ||
            strpos($relative_path, 'var/lock/') === 0 ||
            strpos($relative_path, 'var/spool/') === 0) {
            return true;
        }
        
        // Check for hidden home directories
        if (preg_match('#^home/[^/]+/\.[^/]+(/.*)?$#', $relative_path) ||
            preg_match('#^root/\.[^/]+(/.*)?$#', $relative_path)) {
            return true;
        }
        
        // Check other patterns using regex
        foreach ($exclude_patterns as $pattern) {
            // Convert glob pattern to regex and check if it matches
            $regex = $this->globToRegex($pattern);
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
            // Convert glob pattern to regex and check if it matches
            $regex = $this->globToRegex($pattern);
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
        // Skip hidden files in home directories (e.g., .steam, .cache, .config)
        if (preg_match('#^home/[^/]+/\.[^/]+(/.*)?$#', $relative_path)) {
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
                'visited_inodes' => [],
                'stats' => ['fast_checks' => 0, 'slow_checks' => 0, 'circular_found' => 0]
            ];
        }
        
        // FAST PATH: Quick heuristic checks (covers 99% of cases)
        $path_length = strlen($real_path);
        $depth = substr_count($real_path, '/');
        
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
        $device_id = $stat['dev'];
        $inode_id = $stat['ino'];
        
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
        static $processed_dirs = [];
        static $depth_tracker = [];
        static $approved_device_ids = [];
        static $device_to_mount_cache = [];
        
        // Reset static variables if this is a new root directory scan
        if ($dir === '/' || $dir === '\\') {
            $processed_dirs = [];
            $depth_tracker = [];
            $approved_device_ids = [];
            $device_to_mount_cache = [];
        }
        
        // Get device ID of the starting directory for partition boundary detection
        if ($start_device_id === null) {
            $start_stat = @stat($dir);
            $start_device_id = $start_stat ? $start_stat['dev'] : null;
            if ($start_device_id === null) {
                debug_echo("\r\033[K" . "⚠️  DEBUG: Cannot determine device ID for: $dir\n");
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
        if ($current_depth > 50) {
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
                            debug_echo("\r\033[K" . "🔗 DEBUG: Yielding symlinked directory (not traversing): $subdir_path\n");
                            yield $file->getPathname(); // Yield the symlink directory itself
                            continue; // Don't traverse into the symlinked directory
                        } else {
                            debug_echo("\r\033[K" . "🔗 DEBUG: Following symlinked directory: $subdir_path\n");
                        }
                    }
                    
                    // Fast partition check using pre-computed device ID cache
                    if ($this->shouldSkipUnselectedPartitionFast($subdir_path, $approved_device_ids, $device_to_mount_cache)) {
                        debug_echo("\r\033[K" . "🚫 DEBUG: Skipping unselected partition (dirs): $subdir_path\n");
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
                $escaped_dir = escapeshellarg($dir);
                $command = "sudo -S find $escaped_dir -type d 2>/dev/null";
                
                $output = [];
                $return_code = 0;
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
        
        $actual_target = readlink($link);
        return $actual_target === $target;
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
            $escaped_filepath = escapeshellarg($filepath);
            $escaped_owner = escapeshellarg($owner);
            $command = "chown $escaped_owner $escaped_filepath";
            
            $output = [];
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
        // Try normal chgrp first
        if (@chgrp($filepath, $group)) {
            return true;
        }
        
        // If failed and sudo is available, try with sudo
        if ($this->sudo_password !== null && $this->can_elevate) {
            $escaped_filepath = escapeshellarg($filepath);
            $escaped_group = escapeshellarg($group);
            $command = "chgrp $escaped_group $escaped_filepath";
            
            $output = [];
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
        $output = [];
        
        // Get all mount points with detailed information
        exec('mount | grep -E "^/dev/" | awk \'{print $1, $3, $5, $6}\'', $output);
        
        foreach ($output as $line) {
            $parts = explode(' ', trim($line));
            if (count($parts) >= 4) {
                $device = $parts[0];
                $mount_point = $parts[1];
                $filesystem = $parts[2];
                $flags = $parts[3];
                
                // Get partition size
                $size = $this->getPartitionSize($device);
                
                // Determine if this is a system partition
                $is_system = $this->isSystemPartition($mount_point, $filesystem);
                
                // Determine if this partition should be imaged
                $should_image = $this->shouldImagePartition($mount_point, $filesystem);
                
                // Get additional info
                $usage = $this->getPartitionUsage($mount_point);
                
                $partitions[] = [
                    'device' => $device,
                    'mount_point' => $mount_point,
                    'filesystem' => $filesystem,
                    'size' => $size,
                    'used' => $usage['used'],
                    'free' => $usage['free'],
                    'usage_percent' => $usage['usage_percent'],
                    'flags' => $flags,
                    'is_system' => $is_system,
                    'should_image' => $should_image,
                    'description' => $this->getPartitionDescription($mount_point, $filesystem)
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
        $total = disk_total_space($mount_point);
        $free = disk_free_space($mount_point);
        
        if ($total === false || $free === false) {
            return ['used' => 0, 'free' => 0, 'usage_percent' => 0];
        }
        
        $used = $total - $free;
        $usage_percent = $total > 0 ? round(($used / $total) * 100, 1) : 0;
        
        return [
            'used' => $used,
            'free' => $free,
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
        $system_paths = ['/', '/boot', '/var', '/etc', '/home'];
        $excluded_fs = ['ntfs', 'vfat', 'nfs', 'cifs', 'tmpfs', 'fuse'];
        
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
        return in_array($mount_point, $boot_partitions) || 
               in_array($filesystem, $special_fs);
    }
    
    /**
     * Get human-readable description of partition
     * 
     * @param string $mount_point Mount point path
     * @param string $filesystem Filesystem type
     * @return string Description
     */
    private function getPartitionDescription(string $mount_point, string $filesystem): string {
        if ($mount_point === '/') {
            return 'Root filesystem';
        } elseif ($mount_point === '/home') {
            return 'User home directories';
        } elseif ($mount_point === '/var') {
            return 'Variable data (logs, packages)';
        } elseif ($mount_point === '/boot') {
            return 'Boot files and kernels';
        } elseif ($mount_point === '/etc') {
            return 'System configuration';
        } elseif (strpos($mount_point, '/media/') === 0) {
            return 'External media';
        } elseif (strpos($mount_point, '/mnt/') === 0) {
            return 'Mounted filesystem';
        } else {
            return ucfirst($filesystem) . ' filesystem';
        }
    }
    
    /**
     * Get available disk space in temp directory
     * 
     * @return int Available space in bytes
     */
    public function getAvailableTempSpace(): int {
        $temp_dir = sys_get_temp_dir();
        $free_space = disk_free_space($temp_dir);
        
        if ($free_space === false) {
            // Fallback: try to get space from current directory
            $free_space = disk_free_space('.');
        }
        
        if ($free_space === false) {
            // Last resort: assume 25GB available
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
        $available_space = $this->getAvailableTempSpace();
        return (int)($available_space * (BATCH_SIZE_PERCENTAGE / 100));
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
        $approved_device_ids = [];
        $device_to_mount_cache = [];
        
        foreach ($selected_partitions as $partition) {
            $mount_point = $partition['mount_point'];
            $stat = @stat($mount_point);
            
            if ($stat !== false) {
                $device_id = $stat['dev'];
                $approved_device_ids[$device_id] = true; // O(1) lookup
                $device_to_mount_cache[$device_id] = $mount_point;
                debug_echo("\r\033[K" . "✅ DEBUG: Approved device ID $device_id for mount point: $mount_point\n");
            } else {
                debug_echo("\r\033[K" . "⚠️  DEBUG: Cannot stat selected partition: $mount_point\n");
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
        $stat = @stat($path);
        $device_id = null;
        
        if ($stat === false) {
            // If we can't stat the path, try with sudo (but this should be rare)
            if ($this->sudo_password !== null && $this->can_elevate) {
                $escaped_path = escapeshellarg($path);
                $command = "stat -c '%d' $escaped_path";
                $output = [];
                exec("printf '%s\n' " . escapeshellarg($this->sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
                if ($code === 0 && !empty($output[0])) {
                    $device_id = (int)trim($output[0]);
                }
            }
            
            if ($device_id === null) {
                // If we can't determine device ID, assume unselected partition for safety
                return true;
            }
        } else {
            $device_id = $stat['dev'];
        }
        
        // O(1) lookup in approved device IDs cache
        if (isset($approved_device_ids[$device_id])) {
            // This device is approved, allow it
            return false;
        }
        
        // This device is not approved, skip it
        $mount_point = $device_to_mount_cache[$device_id] ?? 'unknown';
        debug_echo("\r\033[K" . "🚫 DEBUG: Skipping unapproved device $device_id (mount: $mount_point) for path: $path\n");
        return true;
    }
    
    /**
     * Check if a path is on an unselected partition and should be skipped
     * 
     * @param string $path Path to check
     * @param int $reference_device_id Reference device ID of the starting partition
     * @param array $selected_partitions Array of selected partition info
     * @return bool True if path should be skipped (unselected partition), false if should proceed
     */
    private function shouldSkipUnselectedPartition(string $path, int $reference_device_id, array $selected_partitions): bool {
        $stat = @stat($path);
        $device_id = null;
        
        if ($stat === false) {
            // If we can't stat the path, try with sudo
            if ($this->sudo_password !== null && $this->can_elevate) {
                $escaped_path = escapeshellarg($path);
                $command = "stat -c '%d' $escaped_path"; // %d gives device ID in decimal
                $output = [];
                exec("printf '%s\n' " . escapeshellarg($this->sudo_password) . " | sudo -p '' -S $command 2>/dev/null", $output, $code);
                if ($code === 0 && !empty($output[0])) {
                    $device_id = (int)trim($output[0]);
                }
            }
            
            if ($device_id === null) {
                // If we can't determine device ID, assume unselected partition for safety
                debug_echo("\r\033[K" . "⚠️  DEBUG: Cannot determine device ID for: $path (assuming unselected partition)\n");
                return true;
            }
        } else {
            $device_id = $stat['dev'];
        }
        
        // If it's the same device as the starting partition, allow it
        if ($device_id === $reference_device_id) {
            return false;
        }
        
        // Check if this device ID matches any of the selected partitions
        $mount_point = $this->getPartitionMountPointForPath($path);
        foreach ($selected_partitions as $partition) {
            if ($partition['mount_point'] === $mount_point) {
                debug_echo("\r\033[K" . "✅ DEBUG: Allowing selected partition: $path (mount: $mount_point)\n");
                return false; // This partition is selected, don't skip
            }
        }
        
        // This partition is not selected, skip it
        $this->logPartitionBoundary($path, $device_id, $reference_device_id, $mount_point, false);
        return true;
    }
    
    /**
     * Get the mount point for a given path
     * 
     * @param string $path Path to check
     * @return string Mount point path
     */
    private function getPartitionMountPointForPath(string $path): string {
        // Use df command to get mount point for the path
        $escaped_path = escapeshellarg($path);
        $output = [];
        exec("df --output=target $escaped_path 2>/dev/null | tail -n 1", $output);
        
        if (!empty($output[0])) {
            return trim($output[0]);
        }
        
        // Fallback: try to determine mount point by walking up the directory tree
        $current_path = realpath($path);
        if ($current_path === false) {
            return '/'; // Default fallback
        }
        
        $path_stat = @stat($current_path);
        if ($path_stat === false) {
            return '/'; // Default fallback
        }
        
        $current_device = $path_stat['dev'];
        
        // Walk up the directory tree until device changes
        while ($current_path !== '/' && $current_path !== '') {
            $parent_path = dirname($current_path);
            $parent_stat = @stat($parent_path);
            
            if ($parent_stat && $parent_stat['dev'] !== $current_device) {
                // Device changed, current_path is the mount point
                return $current_path;
            }
            
            $current_path = $parent_path;
        }
        
        return '/'; // Root filesystem
    }
    
    /**
     * Log partition boundary detection for debugging
     * 
     * @param string $path Path being checked
     * @param int $current_device Device ID of current path
     * @param int $reference_device Reference device ID
     * @param string $mount_point Mount point of the path
     * @param bool $is_selected Whether this partition is selected for backup
     */
    private function logPartitionBoundary(string $path, int $current_device, int $reference_device, string $mount_point = '', bool $is_selected = false): void {
        debug_echo("\r\033[K" . "🔍 DEBUG: Partition boundary detected:\n");
        debug_echo("  Path: $path\n");
        debug_echo("  Current device: $current_device\n");
        debug_echo("  Reference device: $reference_device\n");
        debug_echo("  Mount point: $mount_point\n");
        debug_echo("  Selected for backup: " . ($is_selected ? 'Yes' : 'No') . "\n");
    }

}