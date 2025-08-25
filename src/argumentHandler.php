<?php
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
