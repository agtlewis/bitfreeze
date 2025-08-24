# BitFreeze System Notes - Assistant Reference

## System Overview
BitFreeze is a file archiving system that uses RAR archives for version control and backup functionality.

## Core Components

### Main Files
- `dev_bitfreeze.php` - Development version (work on this)
- `bitfreeze.php` - Production/compiled version 
- `compiler.php` - Compiles dev version to single-file production version

### Source Files (src/)
- `commandManager.php` - Main command processing (commit, checkout, status, etc.)
- `fileSystemManager.php` - File system operations, directory scanning
- `archiveManager.php` - RAR archive operations
- `displayManager.php` - Output formatting and colors
- `passwordManager.php` - Password handling for encrypted archives
- `progressManager.php` - Progress tracking for long operations
- `systemManager.php` - System-level operations
- `utilityManager.php` - Utility functions
- `argumentHandler.php` - Command line argument parsing
- `systemHealthManager.php` - System health checks

### Test System
- `tests/test_files/` - Contains test files
- Tests can run on either dev or compiled version

## Recent Fixes
- Fixed subdirectory scanning issue in `fileSystemManager.php`
- Partition boundary detection now only applies when specific partitions are selected
- Directory commits (empty selected_partitions) scan ALL subdirectories regardless of partition boundaries
- System backups (with selected_partitions) respect partition boundaries

## Development Workflow
1. **Development**: Work on `dev_bitfreeze.php` (modular version with require_once statements)
2. **Testing**: Run tests during development: `php tests/test_files/test_*.php --version=dev`
3. **Release**: When ready to release, run: `php compiler.php`
4. **Compiler Process**:
   - Runs all tests on dev version
   - Creates backup of existing bitfreeze.php
   - Copies dev_bitfreeze.php to bitfreeze.php
   - Inlines all require_once statements (replaces with actual file contents)
   - Removes extra <?php tags from inlined files
   - Runs all tests on compiled version
   - Reports success or rolls back on failure

## Compiler Features
- **Single-file output**: All source files compiled into one `bitfreeze.php`
- **Automatic testing**: Tests both dev and compiled versions
- **Backup creation**: Creates timestamped backups before compilation
- **Rollback on failure**: Restores backup if compiled version fails tests
- **Inlining tracking**: Shows which files were inlined and final size

## Key Technical Details

### Directory Scanning Logic
- `scanDirGenerator()` in `fileSystemManager.php`
- Uses static variables to prevent infinite recursion
- Partition boundary logic: `!empty($selected_partitions) && shouldSkipUnselectedPartitionFast()`

### Archive Structure
- Uses RAR format with versioned manifests
- Manifests stored as `versions/{id}-{timestamp}.txt`
- File contents stored as `files/{md5hash}`
- Supports deduplication based on MD5 hashes

### Testing
- Tests create temporary environments in `tests/test_environment/`
- Use `testpassword123` as default test password
- Tests verify commit, checkout, status, diff, and other commands
- **Version Selection**: Tests can run on either version:
  - `php test_file.php --version=dev` (tests dev_bitfreeze.php)
  - `php test_file.php --version=compiled` (tests bitfreeze.php, default)
- Test header shows which version is being tested
