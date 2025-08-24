<?php
/**
 * BitFreeze Compiler
 * 
 * Compiles the development version (dev_bitfreeze.php) into a single-file 
 * production version (bitfreeze.php) by inlining all require_once statements.
 * 
 * Process:
 * 1. Run tests on development version
 * 2. Copy dev_bitfreeze.php to bitfreeze.php
 * 3. Replace all require_once statements with actual file contents
 * 4. Run tests on compiled version to verify
 * 
 * @author BitFreeze Development Team
 */

echo "🚀 BitFreeze Compiler v1.0\n";
echo str_repeat("=", 50) . "\n";

// Define paths
define('DEV_FILE', __DIR__ . '/dev_bitfreeze.php');
define('PROD_FILE', __DIR__ . '/bitfreeze.php');
define('SRC_DIR', __DIR__ . '/src/');
define('TEST_DIR', __DIR__ . '/tests/test_files/');

/**
 * Run tests on specified version
 * @param string $version Either 'dev' or 'compiled'
 * @return bool True if all tests pass, false otherwise
 */
function runTests(string $version): bool {
    echo "🧪 Running tests on $version version...\n";
    
    // Get all test files
    $test_files = glob(TEST_DIR . 'test_*.php');
    $passed = 0;
    $failed = 0;
    
    foreach ($test_files as $test_file) {
        $test_name = basename($test_file, '.php');
        echo "   Testing: $test_name... ";
        
        // Run test with version parameter
        $command = "php " . escapeshellarg($test_file) . " --version=" . escapeshellarg($version) . " 2>&1";
        $output = [];
        $return_code = 0;
        exec($command, $output, $return_code);
        
        if ($return_code === 0) {
            echo "✅ PASS\n";
            $passed++;
        } else {
            echo "❌ FAIL\n";
            echo "   Output: " . implode("\n           ", $output) . "\n";
            $failed++;
        }
    }
    
    echo "   Results: $passed passed, $failed failed\n\n";
    return $failed === 0;
}

/**
 * Read and process a PHP file, inlining all require_once statements
 * @param string $file_path Path to the file to process
 * @param array &$processed_files Array to track already processed files (prevent circular includes)
 * @return string Processed file content
 */
function inlineRequires(string $file_path, array &$processed_files = []): string {
    if (in_array($file_path, $processed_files)) {
        // Already processed this file, return empty to prevent circular includes
        return '';
    }
    
    $processed_files[] = $file_path;
    
    if (!file_exists($file_path)) {
        throw new Exception("File not found: $file_path");
    }
    
    $content = file_get_contents($file_path);
    if ($content === false) {
        throw new Exception("Could not read file: $file_path");
    }
    
    // Remove opening <?php tag if it's not the main file
    if ($file_path !== DEV_FILE) {
        $content = preg_replace('/^<\?php\s*\n?/', '', $content);
    }
    
    // Find all require_once statements
    $pattern = '/require_once\s+__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]\s*;/';
    
    $content = preg_replace_callback($pattern, function($matches) use (&$processed_files) {
        $relative_path = $matches[1];
        $full_path = __DIR__ . $relative_path;
        
        echo "   Inlining: $relative_path\n";
        
        // Recursively inline this file
        $inlined_content = inlineRequires($full_path, $processed_files);
        
        return "\n// === INLINED: $relative_path ===\n" . 
               $inlined_content . 
               "\n// === END INLINED: $relative_path ===\n";
    }, $content);
    
    return $content;
}

/**
 * Compile the development version into production version
 * @return bool True if compilation succeeded, false otherwise
 */
function compile(): bool {
    echo "🔨 Compiling dev_bitfreeze.php to bitfreeze.php...\n";
    
    try {
        // Check if source file exists
        if (!file_exists(DEV_FILE)) {
            echo "❌ Error: dev_bitfreeze.php not found!\n";
            return false;
        }
        
        // Create backup of existing bitfreeze.php if it exists
        if (file_exists(PROD_FILE)) {
            $backup_file = PROD_FILE . '.backup.' . date('Y-m-d_H-i-s');
            if (!copy(PROD_FILE, $backup_file)) {
                echo "❌ Error: Could not create backup of bitfreeze.php\n";
                return false;
            }
            echo "   Created backup: " . basename($backup_file) . "\n";
        }
        
        // Process the main file and inline all requires
        $processed_files = [];
        $compiled_content = inlineRequires(DEV_FILE, $processed_files);
        
        // Write compiled version
        if (file_put_contents(PROD_FILE, $compiled_content) === false) {
            echo "❌ Error: Could not write compiled bitfreeze.php\n";
            return false;
        }
        
        echo "   ✅ Compilation successful!\n";
        echo "   📊 Files inlined: " . (count($processed_files) - 1) . "\n"; // -1 for main file
        echo "   📏 Output size: " . number_format(strlen($compiled_content)) . " bytes\n\n";
        
        return true;
        
    } catch (Exception $e) {
        echo "❌ Compilation error: " . $e->getMessage() . "\n";
        return false;
    }
}

// Main compilation process
try {
    // Step 1: Run tests on development version
    if (!runTests('dev')) {
        echo "❌ Development version tests failed! Aborting compilation.\n";
        exit(1);
    }
    
    // Step 2: Compile development version to production version
    if (!compile()) {
        echo "❌ Compilation failed! Aborting.\n";
        exit(1);
    }
    
    // Step 3: Run tests on compiled version
    if (!runTests('compiled')) {
        echo "❌ Compiled version tests failed! Rolling back.\n";
        
        // Restore backup if it exists
        $backup_files = glob(PROD_FILE . '.backup.*');
        if (!empty($backup_files)) {
            $latest_backup = end($backup_files);
            if (copy($latest_backup, PROD_FILE)) {
                echo "   ✅ Restored from backup: " . basename($latest_backup) . "\n";
            }
        }
        exit(1);
    }
    
    echo "🎉 Compilation completed successfully!\n";
    echo "   📁 Production file: bitfreeze.php\n";
    echo "   🧪 All tests passed\n";
    echo "   🚀 Ready for deployment!\n";
    
} catch (Exception $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
?>