<?php
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
        $this->display = new DisplayManager();
        $this->progress_bar_width = 49; // Default progress bar width
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
        if ($total <= 0) return '';
        
        $width = $width ?? $this->progress_bar_width;
        $percentage = min(100, ($current / $total) * 100);
        $filled = round(($width * $percentage) / 100);
        $empty = $width - $filled;
        
        $bar = $this->display->colorize(str_repeat('█', $filled), DisplayManager::COLOR_GREEN);
        $bar .= $this->display->colorize(str_repeat('░', $empty), DisplayManager::COLOR_DIM);
        
        $spinner = '';
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
        $encryption_status = strpos($rar_cmd, ' -hp') !== false ? "encrypted " : "";
        echo $this->display->colorize("📦 Saving {$encryption_status}data to repository...", DisplayManager::COLOR_CYAN) . "\n";
        
        // Execute RAR command and monitor output for progress
        $start_time     = microtime(true);
        $output_lines   = 0;
        $expected_lines = $total_files + 20;
        
        // Start the RAR process
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        debug_echo("\r\033[K" . "⚠️  DEBUG: Executing RAR command: ". $rar_cmd . "\n");

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
        $encryption_status = strpos($rar_cmd, ' -hp') !== false ? "encrypted " : "";
        
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