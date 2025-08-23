<?php
/**
 * System Manager Class
 * 
 * Centralizes all system-level utilities including sudo privilege management,
 * nice priority handling, and other system operations. Consolidates system-related
 * functionality into a single, maintainable class.
 */
class SystemManager {
    private $display;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->display = new DisplayManager();
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