# BitFreeze Repository & Versioned Backup System

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP](https://img.shields.io/badge/PHP-7.0+-blue.svg)](https://php.net/)

**Advanced Repository & Versioned Backup System with Content Aware Deduplication and Recovery**

BitFreeze transforms the traditional RAR archive tool into a sophisticated, enterprise-grade repository and file management application. By layering content-aware deduplication, robust bitrot and corruption countermeasures, and powerful metadata tracking on top of standard RAR archives, BitFreeze brings features typically found only in dedicated version control or enterprise backup solutions to your command line. It stores each unique file content just once, saving immense storage space even as files are moved or reorganized, and also provides versioned snapshots, advanced AES-256 encryption, and automatic integrity checks.

Every backup is shielded with automatic configurable recovery records and built-in repair capabilities, defending your data against corruption and silent bitrot. You gain detailed version tracking with easy diffs and granular progress reporting, as well as comprehensive metadata and symlink preservation, including intelligent fallback handling for environments with restricted permissions. BitFreeze even integrates automatic privilege escalation (sudo), resource-friendly CPU management, and flexible symlink strategies to ensure reliable, complete backups in any scenario. With BitFreeze, your RAR software becomes a secure, efficient, and resilient backup and archival system, empowering you to manage, restore, and audit your files with confidence.

## 🚀 Key Features

### 🔄 Content-Based Deduplication
- **Smart Storage**: Moving or reorganizing files doesn't increase archive size
- **Efficient Space Usage**: Only unique file contents are stored in the archive
- **MD5 Hash Verification**: Ensures data integrity and prevents corruption

### 🛡️ Enterprise-Grade Security
- **AES-256 Encryption**: Strong encryption with password protection
- **Recovery Records**: 6% (configurable) recovery records for bitrot protection
- **Built-in Repair**: Automatic repair capabilities for damaged archives

### 📊 Version Control & Management
- **Snapshot-Based Versioning**: Create named snapshots with comments
- **Duplicate Detection**: Automatically prevents duplicate snapshots
- **Diff Functionality**: Compare versions to see what changed
- **Progress Tracking**: Detailed reporting and progress indicators

### 🔧 Advanced Operations
- **List Commits**: View all available backup versions
- **Status Analysis**: Compare current folder state with latest commit to detect changes
- **Checkout Capabilities**: Checkout specific commits to any location with full metadata preservation
- **Archive Repair**: Built-in repair for damaged archives
- **Password Protection**: AES-256 encryption with command-line password support
- **Metadata Preservation**: File permissions, ownership, timestamps, and attributes are preserved
- **Enhanced Symbolic Link Support**: Handles symlinks with configurable behavior and intelligent fallback
- **Sudo Integration**: Automatic privilege escalation for protected files
- **Resource Management**: CPU priority control to minimize system impact

### 🔗 Enhanced Symlink Handling

Bitfreeze provides industry-standard symlink handling that ensures data safety and flexibility:

#### **Commit with `--follow-symlinks`**
When you use `--follow-symlinks` during commit:
- **Symlink information is preserved** (path, target, permissions, ownership)
- **Target files are also backed up** to the symlink path
- This gives you both options during checkout

#### **Checkout Behavior**
During checkout, the system will:
1. **First attempt** to recreate the symlink
2. **If symlink creation succeeds**: Files are accessible via the symlink
3. **If symlink creation fails**: Files are restored directly to the symlink path as a directory

#### **Force Directory Mode**
Use `--force-directory` during checkout to:
- Skip symlink recreation entirely
- Always restore files as a directory structure
- Useful when symlinks aren't desired or can't be created

#### **Example Workflow**
```bash
# Commit with symlink following
php bitfreeze.php commit /home/net/documents archive.rar "Documents with symlinks" --follow-symlinks

# Checkout with automatic fallback
php bitfreeze.php checkout 1 archive.rar /restored/path

# Checkout forcing directory creation
php bitfreeze.php checkout 1 archive.rar /restored/path --force-directory
```

## 📋 Requirements

### System Requirements
- **PHP 7.0+** with required extensions
- **RAR 5.0+** command line tools
- **Linux/Unix/macOS** (with RAR support) (Not tested or designed to work on Windows!)

### PHP Extensions Required
- `exec` - Command execution
- `passthru` - Command passthrough
- `file_exists`, `is_dir`, `realpath` - File system operations
- `mkdir`, `copy`, `file_put_contents` - File operations
- `md5_file` - Hash generation
- And other standard PHP functions

### RAR Installation

#### Ubuntu/Debian
```bash
sudo apt-get install rar unrar php-cli
```

#### CentOS/RHEL
```bash
sudo yum install rar unrar php-cli
```

#### macOS
```bash
brew install rar php
```

## 🚀 Quick Start

### Basic Usage

```bash
# Create a new repository or add a snapshot to an existing repository
php bitfreeze.php commit /source/path archive.rar "Daily Snapshot"

# Create encrypted repository or add a snapshot to an existing encrypted repository
php bitfreeze.php commit /source/path archive.rar "Daily Snapshot" -p securepass

# List available snapshots
php bitfreeze.php list archive.rar

# Checkout a commit
php bitfreeze.php checkout 1 archive.rar /checkout/path

# Checkout forcing directory creation instead of symlinks
php bitfreeze.php checkout 1 archive.rar /checkout/path --force-directory

# Compare commits
php bitfreeze.php diff 1 4 archive.rar

# Check status of current folder vs latest commit
php bitfreeze.php status /source/path archive.rar

# Repair damaged archive
php bitfreeze.php repair archive.rar
```

## 📖 Detailed Usage

### Creating Commits

#### Basic Commit
```bash
php bitfreeze.php commit /home/user/documents archive.rar "Daily Commit"
```

#### Encrypted Commit (New Repositories or Existing Encrypted Repositories Only)
```bash
php bitfreeze.php commit /var/www/website archive.rar "Website Commit" -p securepass
```

#### Low Priority Backup (Minimizes System Impact)
```bash
php bitfreeze.php commit /var/www/website archive.rar "Website Commit" --low-priority
```

#### Following Symbolic Links
```bash
php bitfreeze.php commit /path/with/symlinks archive.rar "Symlink Commit" --follow-symlinks
```

### Listing Commits

```bash
# List all commits in an archive
php bitfreeze.php list archive.rar -p securepass

# Output example:
# Available commits (most recent first):
# ID    Date/Time           Comment
# ----------------------------------------
# 4     2024-01-15 14:30:22 Daily Snapshot
# 3     2024-01-14 14:30:15 Weekly Snapshot
# 2     2024-01-07 14:30:10 Monthly Snapshot
# 1     2024-01-01 14:30:05 Initial Snapshot
```

### Checking Out Commits

```bash
# Checkout commit ID 2 to /checkout/path
php bitfreeze.php checkout 2 archive.rar /checkout/path

# Checkout to current directory
php bitfreeze.php checkout 1 archive.rar ./checked-out

# Checkout with low priority (minimizes system impact)
php bitfreeze.php checkout 1 archive.rar /checkout/path --low-priority

# Force directory creation instead of symlink recreation
php bitfreeze.php checkout 1 archive.rar /checkout/path --force-directory
```

### Comparing Commits

```bash
# Compare commit 1 with commit 4
php bitfreeze.php diff 1 4 archive.rar

# Output shows:
# - Added files
# - Removed files  
# - Changed files
# - Summary statistics
```

### Status Analysis

The status command analyzes the current state of a folder and compares it with the latest commit in an archive. This is useful for:

- **Change Detection**: Identify what files have been added, modified, or deleted
- **Audit Purposes**: Verify the current state against a known good backup
- **Security Monitoring**: Detect unauthorized file modifications
- **Pre-backup Review**: See what changes exist before creating a new snapshot

#### Basic Status Check

```bash
# Check status of current folder vs latest commit
php bitfreeze.php status /home/user/documents archive.rar

# Output shows:
# - New files (added since last backup)
# - Modified files (content changed)
# - Deleted files (removed since last backup)
# - Summary of total changes
```

#### Include Metadata Changes

```bash
# Check status including permission and ownership changes
php bitfreeze.php status /home/user/documents archive.rar --include-meta

# Additional output shows:
# - Files with changed permissions
# - Files with changed ownership
# - Files with changed group membership
# - Detailed change information for each file
```

#### Checksum Verification

```bash
# Detect files with changed content but unchanged modification dates
php bitfreeze.php status /home/user/documents archive.rar --checksum

# This is useful for:
# - Detecting tampered files
# - Identifying corrupted files
# - Security audits where timestamps may have been manipulated
# - Files modified without updating timestamps

# Output shows:
# - Files with content changes but identical modification dates
# - Current and archive MD5 hashes
# - Modification date information
# - Red warning headers for suspicious changes
```

#### Combined Options

```bash
# Use both metadata and checksum detection
php bitfreeze.php status /home/user/documents archive.rar --include-meta --checksum

# This provides comprehensive analysis:
# - All file changes (new, modified, deleted)
# - All metadata changes (permissions, ownership)
# - All suspicious checksum changes
# - Complete summary with all change types
```

#### Status Output Example

```bash
============================================================
                     STATUS ANALYSIS                      
============================================================
  🔍 Analyzing folder: /home/user/documents
  📦 Archive: /home/user/documents.rar
  📋 Latest snapshot: 1-2024-01-15 14:30:22.txt

============================================================
                     CHANGES DETECTED                     
============================================================

--------------------------------------------------
                   NEW FILES                    
--------------------------------------------------
  + new_document.txt

--------------------------------------------------
                 MODIFIED FILES                 
--------------------------------------------------
  ~ updated_file.txt

--------------------------------------------------
                 DELETED FILES                  
--------------------------------------------------
  - old_file.txt

--------------------------------------------------
                METADATA CHANGES                
--------------------------------------------------
  🔄 config.txt
     Permissions: 0644 → 0755

--------------------------------------------------
                CHECKSUM CHANGES                
--------------------------------------------------
  🔍 possible_corruption.txt
     Current Hash: a1b2c3d4e5f6...
     Archive Hash: f6e5d4c3b2a1...
     Modification Date: 2024-01-15 14:30:22

============================================================
                         SUMMARY                          
============================================================
New Files             1           
Modified Files        1           
Deleted Files         1           
Metadata Changes      1           
Checksum Changes      1           
Total Changes         5           
```

### Metadata Preservation

BitFreeze preserves complete file metadata during backup and checkout:

- **File Permissions**: Unix permissions (read/write/execute) are preserved
- **Ownership**: User and group ownership information is maintained
- **Timestamps**: Access time, modification time, and creation time are preserved
- **Symbolic Links**: Symlink targets and attributes are maintained
- **Extended Attributes**: File attributes and extended metadata are preserved

### Symbolic Link Support

```bash
# Default behavior: Store symlinks as links (not their targets)
php bitfreeze.php commit /path/with/symlinks archive.rar "Snapshot"

# Follow symlinks and backup their contents
php bitfreeze.php commit /path/with/symlinks archive.rar "Commit" --follow-symlinks
```

### Sudo Integration

BitFreeze automatically detects when elevated privileges are needed:

- **Automatic Detection**: Scans for permission issues before backup
- **Sudo Prompt**: Prompts for sudo password when needed
- **Privileged Access**: Uses sudo to access protected files and directories
- **Secure Handling**: Password is handled securely and not stored

### Resource Management

BitFreeze includes intelligent resource management to minimize system impact:

- **CPU Priority Control**: Use `--low-priority` flag to run with reduced CPU priority (nice level 10)
- **Default Priority**: Normal operations use slightly reduced priority (nice level 1)
- **System-Friendly**: Designed to avoid impacting other system processes
- **Progress Tracking**: Real-time progress indicators for long operations

### Repairing Archives

```bash
# Repair a damaged archive
php bitfreeze.php repair archive.rar

# Repair with low priority (minimizes system impact)
php bitfreeze.php repair archive.rar --low-priority
```

## 🔧 How It Works

### Content-Aware Deduplication

1. **File Scanning**: BitFreeze scans all files in the source directory
2. **Hash Generation**: MD5 hashes are computed for each file
3. **Deduplication**: Only unique file contents are stored in the repository `files/` directory
4. **Manifest Creation**: Snapshots track which files existed at each point in time using a Manifest file
5. **Efficient Storage**: Moving large files or creating copies doesn't increase archive size!

### Archive Structure

```
archive.rar/
├── files/           # Unique file contents (MD5 hashed)
│   ├── a1b2c3d4...  # File content blob
│   └── e5f6g7h8...  # Another file content blob
├── versions/        # Snapshot manifests
│   ├── 1-2024-01-01 14:30:05.txt
│   ├── 1-2024-01-01 14:30:05.comment
│   ├── 2-2024-01-07 14:30:10.txt
│   └── 2-2024-01-07 14:30:10.comment
├── bitfreeze.php    # Self-contained script
└── README.txt       # Usage instructions
```

### Recovery and Safety Features

- **6% Recovery Records**: Protect against bitrot and corruption
- **Built-in Repair**: RAR's repair command can recover damaged archives
- **Password Protection**: AES-256 encryption secures sensitive backups
- **Automatic Integrity Checking**: Verifies data during operations
- **Metadata Preservation**: Complete file attributes and permissions are maintained
- **Privilege Escalation**: Automatic sudo integration for protected files
- **Enhanced Symbolic Link Handling**: Configurable symlink behavior with `--follow-symlinks` and `--force-directory`
- **Resource Management**: CPU priority control with `--low-priority` option

## 📊 Benefits Over Traditional Backup Systems

| Feature | Traditional Backup | BitFreeze |
|---------|-------------------|-----------|
| **Storage Efficiency** | Files stored multiple times | Unique content stored once |
| **Moving Files** | Increases archive size | No size increase |
| **Version Control** | Limited or none | Full commit history |
| **Recovery** | Basic | Advanced with checkout and repair |
| **Encryption** | Often separate | Built-in AES-256 |
| **Deduplication** | None | Content Aware |
| **Corruption Protection** | Limited | Robust Recovery Records |
| **Metadata Preservation** | Limited | Complete File Attributes |
| **Symlink Handling** | Basic or none | Enhanced with fallback support |
| **Change Detection** | None | Built-in Status Analysis |
| **Privilege Handling** | Manual | Automatic Sudo Integration |
| **Resource Management** | None | CPU Priority Control |

## 🔍 Use Cases

### Web Development
```bash
# Backup website before deployment
php bitfreeze.php commit /var/www/mywebsite archive.rar "Pre-deployment commit"

# Backup after changes
php bitfreeze.php commit /var/www/mywebsite archive.rar "Post-deployment commit"

# Compare what changed
php bitfreeze.php diff 1 2 archive.rar

# Check current status before next deployment
php bitfreeze.php status /var/www/mywebsite archive.rar --include-meta
```

### Document Management
```bash
# Daily document backup
php bitfreeze.php commit /home/user/documents archive.rar "Daily Commit"

# Weekly backup with comment
php bitfreeze.php commit /home/user/documents archive.rar "Weekly document commit"

# Check what documents have changed since last backup
php bitfreeze.php status /home/user/documents archive.rar

# Verify document integrity with checksum verification
php bitfreeze.php status /home/user/documents archive.rar --checksum
```

### System Administration
```bash
# Backup configuration files
php bitfreeze.php commit /etc archive.rar "System config commit"

# Backup user data
php bitfreeze.php commit /home archive.rar "User data commit"

# Audit system changes since last backup
php bitfreeze.php status /etc archive.rar --include-meta --checksum

# Monitor user data changes
php bitfreeze.php status /home archive.rar --include-meta
```

### Production Server Backups
```bash
# Low-priority backup to avoid impacting production services
php bitfreeze.php commit /var/www/production archive.rar "Nightly commit" --low-priority

# Encrypted backup with low priority
php bitfreeze.php commit /var/www/production archive.rar "Nightly commit" -p securepass --low-priority
```

### Security and Auditing
```bash
# Comprehensive security audit with all change types
php bitfreeze.php status /critical/system/path archive.rar --include-meta --checksum

# Monitor for unauthorized file modifications
php bitfreeze.php status /var/log archive.rar --checksum

# Audit permission changes on sensitive directories
php bitfreeze.php status /etc/ssh archive.rar --include-meta
```

### Symbolic Link Management
```bash
# Backup directory with symlinks, preserving both link info and target files
php bitfreeze.php commit /home/net/documents archive.rar "Documents with symlinks" --follow-symlinks

# Checkout with automatic symlink recreation (falls back to directory if needed)
php bitfreeze.php checkout 1 archive.rar /restored/path

# Force directory creation instead of symlink recreation
php bitfreeze.php checkout 1 archive.rar /restored/path --force-directory
```

## 🛠️ Advanced Configuration

### Recovery Record Size

The recovery record size is configurable in the script:

```php
define('RECOVERY_RECORD_SIZE', 6); // Set as a percentage
```

### CPU Priority Levels

BitFreeze uses different CPU priority levels to manage system impact:

- **Default Priority**: `nice 1` - Slightly reduced priority for normal operations
- **Low Priority**: `nice 10` - Significantly reduced priority when using `--low-priority`
- **System Impact**: Lower priority means other processes get CPU time first

## 🔧 Troubleshooting

### Common Issues

#### RAR Command Not Found
```bash
# Install RAR on Ubuntu/Debian
sudo apt-get install rar unrar

# Install RAR on CentOS/RHEL  
sudo yum install rar unrar

# Install RAR on macOS
brew install rar
```

#### Permission Denied
```bash
# Make script executable
chmod +x bitfreeze.php

# Check file permissions
ls -la bitfreeze.php
```

#### Archive Corrupted
```bash
# Attempt repair
php bitfreeze.php repair archive.rar

# Check archive integrity
rar t archive.rar

# Use status command to detect corrupted files
php bitfreeze.php status /path/to/backup archive.rar --checksum
```

#### Permission Issues
```bash
# BitFreeze will automatically prompt for sudo when needed
# For manual permission checks:
ls -la /path/to/backup/directory

# Check if files are readable
find /path/to/backup -type f -exec test -r {} \; -print

# Use status command to diagnose permission and ownership issues
php bitfreeze.php status /path/to/backup archive.rar --include-meta
```

#### Symlink Issues
```bash
# If symlinks can't be recreated during checkout, use force directory mode
php bitfreeze.php checkout 1 archive.rar /checkout/path --force-directory

# This will restore files directly to the symlink path as a directory

# Check symlink status during checkout
php bitfreeze.php checkout 1 archive.rar /checkout/path
# System will automatically fallback to directory creation if symlink fails
```

### Performance Tips

1. **Use SSD Storage**: Faster read/write operations
2. **Adequate RAM**: Large files benefit from more memory
3. **Network Storage**: Consider network-attached storage for large archives
4. **Regular Maintenance**: Periodically verify archive integrity
5. **Sudo Caching**: Use `sudo -n` or configure sudoers for passwordless sudo
6. **Symlink Strategy**: Use `--follow-symlinks` only when necessary to avoid duplicates
7. **Enhanced Symlink Handling**: When `--follow-symlinks` is used, both symlink information and target files are stored, allowing for flexible restoration
7. **Resource Management**: Use `--low-priority` for production environments to minimize system impact

## 📈 Performance Characteristics

- **Backup Speed**: Depends on file count and size
- **Storage Efficiency**: 50-90% space savings vs traditional backups
- **Checkout Speed**: Fast due to deduplication
- **Memory Usage**: Minimal, processes files sequentially
- **CPU Impact**: Configurable priority levels minimize system impact

## 🤝 Contributing

We welcome contributions! Please feel free to submit issues, feature requests, or pull requests.

### Development Setup

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 👨‍💻 Author

**Benjamin Lewis** - [net@p9b.org](mailto:net@p9b.org)

## 🙏 Acknowledgments

- RAR Labs for the excellent RAR format
- PHP community for the robust language
- Open source community for inspiration

## 📞 Support

For support, please:
1. Check the troubleshooting section above
2. Review existing issues on GitHub
3. Create a new issue with detailed information

**BitFreeze** - Intelligent backup with content-aware deduplication and enterprise-grade security.
