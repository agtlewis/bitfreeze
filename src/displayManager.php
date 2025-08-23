<?php
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
        $text_length = strlen($text) + 4; // '  ' before and after
        $line_length = max($width, $text_length);
        $line = str_repeat($char, $line_length);
        
        // Center the text within $line_length
        $padding = $line_length - strlen($text);
        $left = floor($padding / 2);
        $right = $padding - $left;
        $centered_text = str_repeat(' ', $left - 1) . $text . str_repeat(' ', $right - 1);
        
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
            $width = $widths[$i] ?? 20;
            $row .= str_pad($column, $width) . $separator;
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