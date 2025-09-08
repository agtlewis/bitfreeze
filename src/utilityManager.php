<?php
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
