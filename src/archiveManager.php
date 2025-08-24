<?php
/**
 * Archive Manager Class
 * 
 * Centralizes all RAR archive operations including creation, extraction,
 * password management, and progress tracking. Consolidates archive-related
 * functionality into a single, maintainable class.
 */
class ArchiveManager {
    private $password;
    private $progress_manager;
    private $fs_manager;
    
    /**
     * Constructor
     * 
     * @param string|null $password Password for archive access
     * @param FileSystemManager|null $fs_manager File system manager for sudo operations
     */
    public function __construct(?string $password = null, ?FileSystemManager $fs_manager = null) {
        $this->password = $password;
        $this->progress_manager = new ProgressManager();
        $this->fs_manager = $fs_manager;
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
        
        $output = [];
        $rar_cmd = 'rar lb -hp' . escapeshellarg($password) . ' ' . escapeshellarg($rarfile);
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
        
        $output = [];
        $rar_cmd = 'rar lb ' . escapeshellarg($rarfile);
        
        if ($this->password) {
            $rar_cmd = 'rar lb -hp' . escapeshellarg($this->password) . ' ' . escapeshellarg($rarfile);
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
        
        $output = [];
        $rar_cmd = 'rar lb ' . escapeshellarg($rarfile);
        
        if ($this->password) {
            $rar_cmd = 'rar lb -hp' . escapeshellarg($this->password) . ' ' . escapeshellarg($rarfile);
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
        
        $output = [];
        $rar_cmd = 'rar lb ' . escapeshellarg($rarfile) . ' versions/';
        
        if ($this->password) {
            $rar_cmd = 'rar lb -hp' . escapeshellarg($this->password) . ' ' . escapeshellarg($rarfile) . ' versions/';
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
                $id = (int)$matches[1];
                $year = (int)$matches[2];
                $month = (int)$matches[3];
                $day = (int)$matches[4];
                $hour = (int)$matches[5];
                $minute = (int)$matches[6];
                $second = (int)$matches[7];
                
                $timestamp = mktime($hour, $minute, $second, $month, $day, $year);
                
                $versions[] = [
                    'id' => $id,
                    'name' => $line,
                    'timestamp' => $timestamp,
                    'date' => date('Y-m-d H:i:s', $timestamp),
                    'ts' => date('Y-m-d H:i:s', $timestamp)
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
            $rar_cmd .= ' -hp' . escapeshellarg($this->password);
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
            'id' => $latest['id'],
            'name' => $latest['name'],
            'ts' => $ts,
            'timestamp' => $latest['timestamp'],
            'date' => $latest['date']
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
                    'id' => $version['id'],
                    'name' => $version['name'],
                    'timestamp' => $version['timestamp'],
                    'date' => $version['date']
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
            $rar_cmd .= '-hp' . escapeshellarg($this->password) . ' ';
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
            $rar_cmd = 'rar r -hp' . escapeshellarg($this->password) . ' ' . escapeshellarg($rarfile);
        }
        
        exec($rar_cmd, $output, $code);
        
        return $code === 0;
    }
}