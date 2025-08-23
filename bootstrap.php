<?php
/**
 * BitFreeze Bootstrap File
 * 
 * This file loads all the class files in the correct order based on dependencies.
 * Include this file to use BitFreeze classes in your projects.
 */

// Define constants first
if (!defined('RECOVERY_RECORD_SIZE')) define('RECOVERY_RECORD_SIZE', 6); // Set as a percentage
if (!defined('PROGRESS_BAR_WIDTH')) define('PROGRESS_BAR_WIDTH', 49); // Sets the width of the progress bar in the terminal
if (!defined('MD5_TIMEOUT')) define('MD5_TIMEOUT', 600); // Maximum time in seconds to calculate MD5 hash of a file
if (!defined('BATCH_SIZE_PERCENTAGE')) define('BATCH_SIZE_PERCENTAGE', 30); // Use 30% of available temp disk space for batch processing
if (!defined('BF_DEBUG_MODE')) define('BF_DEBUG_MODE', true); // Set to false to disable all debug output

/**
 * Debug output wrapper function
 * Only outputs debug messages when BF_DEBUG_MODE is true
 */
function debug_echo(string $message): void {
    if (BF_DEBUG_MODE) {
        echo $message;
    }
}

// Load classes in dependency order
// Level 1: No dependencies
require_once __DIR__ . '/src/Display/DisplayManager.php';

// Level 2: Depends on DisplayManager
require_once __DIR__ . '/src/Utility/UtilityManager.php';
require_once __DIR__ . '/src/Progress/ProgressManager.php';

// Level 3: Core system classes
require_once __DIR__ . '/src/System/SystemManager.php';
require_once __DIR__ . '/src/FileSystem/FileSystemManager.php';
require_once __DIR__ . '/src/Utility/ArgumentHandler.php';

// Level 4: Complex classes that depend on core classes
require_once __DIR__ . '/src/Security/PasswordManager.php';
require_once __DIR__ . '/src/System/SystemHealthManager.php';
require_once __DIR__ . '/src/Archive/ArchiveManager.php';

// Level 5: Command manager (depends on most other classes)
require_once __DIR__ . '/src/Command/CommandManager.php';

// Level 6: Utility classes
// require_once __DIR__ . '/src/Utility/ArgumentHandler.php';

echo "✅ BitFreeze classes loaded successfully!\n";
