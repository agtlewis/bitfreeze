<?php
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

