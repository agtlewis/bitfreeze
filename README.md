# BitFreeze Backup System

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP](https://img.shields.io/badge/PHP-7.0+-blue.svg)](https://php.net/)

**Advanced Backup System with Content Aware Deduplication and Recovery**

BitFreeze is a powerful, intelligent backup system that creates version based backup archives with content aware deduplication. Unlike traditional backup systems that store files multiple times, BitFreeze stores unique file contents only once, dramatically reducing archive size and improving performance.

## 🚀 Key Features

### 🔄 Content-Based Deduplication
- **Smart Storage**: Only unique file contents are stored in the archive
- **Efficient Space Usage**: Moving or reorganizing files doesn't increase archive size
- **MD5 Hash Verification**: Ensures data integrity and prevents corruption

### 🛡️ Enterprise-Grade Security
- **AES-256 Encryption**: Strong encryption with password protection
- **Recovery Records**: 10% (configurable) recovery records for bitrot protection
- **Built-in Repair**: Automatic repair capabilities for damaged archives

### 📊 Version Control & Management
- **Snapshot-Based Versioning**: Create named snapshots with comments
- **Duplicate Detection**: Automatically prevents duplicate snapshots
- **Diff Functionality**: Compare versions to see what changed
- **Progress Tracking**: Detailed reporting and progress indicators

### 🔧 Advanced Operations
- **List Snapshots**: View all available backup versions
- **Restore Capabilities**: Restore specific snapshots to any location
- **Archive Repair**: Built-in repair for damaged archives
- **Password Management**: Environment variable support for secure password handling

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
php bitfreeze.php store /source/path archive.rar "Daily Snapshot"

# Create encrypted repository or add a snapshot to an existing encrypted repository
php bitfreeze.php store /source/path archive.rar "Daily Snapshot" -p securepass

# List available snapshots
php bitfreeze.php list archive.rar

# Restore a snapshot
php bitfreeze.php restore 1 archive.rar /restore/path

# Compare snapshots
php bitfreeze.php diff 1 4 archive.rar

# Repair damaged archive
php bitfreeze.php repair archive.rar
```

## 📖 Detailed Usage

### Creating Snapshots

#### Basic Snapshot
```bash
php bitfreeze.php store /home/user/documents archive.rar "Daily Snapshot"
```

#### Encrypted Snapshot (New Repositories or Existing Encrypted Repositories Only)
```bash
php bitfreeze.php store /var/www/website archive.rar "Website Snapshot" -p securepass
```

#### Using Environment Variable for Password
```bash
export RAR_PASSWORD="securepass"
php bitfreeze.php store /important/data archive.rar "Secure Backup" -p
```

### Managing Passwords

#### Set Password Environment Variable
```bash
php bitfreeze.php passwd
```

#### Clear Password Environment Variable
```bash
php bitfreeze.php passwd -c
```

### Listing Snapshots

```bash
# List all snapshots in an archive
php bitfreeze.php list archive.rar -p securepass

# Output example:
# Available snapshots (most recent first):
# ID    Date/Time           Comment
# ----------------------------------------
# 4     2024-01-15 14:30:22 Daily Snapshot
# 3     2024-01-14 14:30:15 Weekly Snapshot
# 2     2024-01-07 14:30:10 Monthly Snapshot
# 1     2024-01-01 14:30:05 Initial Snapshot
```

### Restoring Snapshots

```bash
# Restore snapshot ID 2 to /restore/path
php bitfreeze.php restore 2 archive.rar /restore/path

# Restore to current directory
php bitfreeze.php restore 1 archive.rar ./restored
```

### Comparing Snapshots

```bash
# Compare snapshot 1 with snapshot 4
php bitfreeze.php diff 1 4 archive.rar

# Output shows:
# - Added files
# - Removed files  
# - Changed files
# - Summary statistics
```

### Repairing Archives

```bash
# Repair a damaged archive
php bitfreeze.php repair archive.rar
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

- **10% Recovery Records**: Protect against bitrot and corruption
- **Built-in Repair**: RAR's repair command can recover damaged archives
- **Password Protection**: AES-256 encryption secures sensitive backups
- **Automatic Integrity Checking**: Verifies data during operations

## 📊 Benefits Over Traditional Backup Systems

| Feature | Traditional Backup | BitFreeze |
|---------|-------------------|-----------|
| **Storage Efficiency** | Files stored multiple times | Unique content stored once |
| **Moving Files** | Increases archive size | No size increase |
| **Version Control** | Limited or none | Full snapshot history |
| **Recovery** | Basic | Advanced with repair |
| **Encryption** | Often separate | Built-in AES-256 |
| **Deduplication** | None | Content Aware |
| **Corruption Protection** | Limited | Robust Recovery Records |

## 🔍 Use Cases

### Web Development
```bash
# Backup website before deployment
php bitfreeze.php store /var/www/mywebsite archive.rar "Pre-deployment snapshot"

# Backup after changes
php bitfreeze.php store /var/www/mywebsite archive.rar "Post-deployment snapshot"

# Compare what changed
php bitfreeze.php diff 1 2 archive.rar
```

### Document Management
```bash
# Daily document backup
php bitfreeze.php store /home/user/documents archive.rar "Daily Snapshot"

# Weekly backup with comment
php bitfreeze.php store /home/user/documents archive.rar "Weekly document snapshot"
```

### System Administration
```bash
# Backup configuration files
php bitfreeze.php store /etc archive.rar "System config snapshot"

# Backup user data
php bitfreeze.php store /home archive.rar "User data snapshot"
```

## 🛠️ Advanced Configuration

### Recovery Record Size

The recovery record size is configurable in the script:

```php
define('RECOVERY_RECORD_SIZE', 10); // Set as a percentage
```

### Environment Variables

- `RAR_PASSWORD`: Set password for all operations

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
```

### Performance Tips

1. **Use SSD Storage**: Faster read/write operations
2. **Adequate RAM**: Large files benefit from more memory
3. **Network Storage**: Consider network-attached storage for large archives
4. **Regular Maintenance**: Periodically verify archive integrity

## 📈 Performance Characteristics

- **Backup Speed**: Depends on file count and size
- **Storage Efficiency**: 50-90% space savings vs traditional backups
- **Restore Speed**: Fast due to deduplication
- **Memory Usage**: Minimal, processes files sequentially

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

---

**BitFreeze** - Intelligent backup with content-aware deduplication and enterprise-grade security.
