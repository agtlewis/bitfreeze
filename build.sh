#!/bin/bash
# BitFreeze Build Script
# 
# Provides convenient commands for development and release workflow

set -e  # Exit on any error

case "$1" in
    "test-dev")
        echo "🧪 Running tests on development version..."
        for test_file in tests/test_files/test_*.php; do
            if [[ "$(basename "$test_file")" != "test_utils.php" ]]; then
                echo "Testing: $(basename "$test_file" .php)"
                php "$test_file" --version=dev
            fi
        done
        echo "✅ All development tests completed!"
        ;;
    
    "test-compiled")
        echo "🧪 Running tests on compiled version..."
        for test_file in tests/test_files/test_*.php; do
            if [[ "$(basename "$test_file")" != "test_utils.php" ]]; then
                echo "Testing: $(basename "$test_file" .php)"
                php "$test_file" --version=compiled
            fi
        done
        echo "✅ All compiled tests completed!"
        ;;
    
    "compile")
        echo "🔨 Compiling BitFreeze..."
        php compiler.php
        ;;
    
    "release")
        echo "🚀 Running full release process..."
        echo "1. Testing development version..."
        $0 test-dev
        echo ""
        echo "2. Compiling..."
        $0 compile
        echo ""
        echo "🎉 Release completed successfully!"
        ;;
    
    "install")
        echo "📦 Installing BitFreeze to /home/net/bin/..."
        
        # Check if compiled version exists
        if [ ! -f "bitfreeze.php" ]; then
            echo "❌ Error: bitfreeze.php not found! Please compile first using '$0 compile'"
            exit 1
        fi
        
        # Check if destination directory exists
        if [ ! -d "/home/net/bin" ]; then
            echo "❌ Error: Destination directory /home/net/bin does not exist!"
            exit 1
        fi
        
        # Copy file and preserve executable permissions
        if cp bitfreeze.php /home/net/bin/bitfreeze.php; then
            # Ensure the destination file is executable
            chmod +x /home/net/bin/bitfreeze.php
            echo "✅ BitFreeze installed successfully to /home/net/bin/bitfreeze.php"
            echo "   File is executable and ready to use"
        else
            echo "❌ Error: Failed to copy bitfreeze.php to /home/net/bin/"
            exit 1
        fi
        ;;
    
    "clean")
        echo "🧹 Cleaning up test environments and backups..."
        rm -rf tests/test_environment/
        rm -f bitfreeze.php.backup.*
        rm -f logs/*.txt
        echo "✅ Cleanup completed!"
        ;;
    
    "help")
        echo "BitFreeze Build Script"
        echo ""
        echo "Usage: $0 <command>"
        echo ""
        echo "Commands:"
        echo "  test-dev      Run tests on development version"
        echo "  test-compiled Run tests on compiled version" 
        echo "  compile       Compile dev version to production"
        echo "  release       Full release process (test + compile)"
        echo "  install       Install compiled version to /home/net/bin/"
        echo "  clean         Clean up temporary files"
        echo "  help          Show this help message"
        echo ""
        echo "Development workflow:"
        echo "  1. Work on dev_bitfreeze.php"
        echo "  2. Run '$0 test-dev' to test changes"
        echo "  3. Run '$0 release' when ready to deploy"
        ;;
    
    "")
        # Interactive menu when no arguments provided
        echo "🚀 BitFreeze Build Script - Interactive Menu"
        echo "============================================="
        echo ""
        echo "Available actions:"
        echo "  1. Test development version"
        echo "  2. Test compiled version"
        echo "  3. Compile to production"
        echo "  4. Full release process"
        echo "  5. Install to /home/net/bin/"
        echo "  6. Clean up temporary files"
        echo "  7. Show help"
        echo "  0. Exit"
        echo ""
        
        while true; do
            read -p "Enter your choice (0-7): " choice
            case $choice in
                1)
                    echo ""
                    echo "🧪 Running tests on development version..."
                    $0 test-dev
                    break
                    ;;
                2)
                    echo ""
                    echo "🧪 Running tests on compiled version..."
                    $0 test-compiled
                    break
                    ;;
                3)
                    echo ""
                    echo "🔨 Compiling BitFreeze..."
                    $0 compile
                    break
                    ;;
                4)
                    echo ""
                    echo "🚀 Running full release process..."
                    $0 release
                    break
                    ;;
                5)
                    echo ""
                    echo "📦 Installing BitFreeze to /home/net/bin/..."
                    $0 install
                    break
                    ;;
                6)
                    echo ""
                    echo "🧹 Cleaning up temporary files..."
                    $0 clean
                    break
                    ;;
                7)
                    echo ""
                    $0 help
                    break
                    ;;
                0)
                    echo "👋 Goodbye!"
                    exit 0
                    ;;
                *)
                    echo "❌ Invalid choice. Please enter a number between 0 and 7."
                    ;;
            esac
        done
        ;;
    
    *)
        echo "❌ Unknown command: $1"
        echo "Run '$0 help' for available commands"
        exit 1
        ;;
esac
