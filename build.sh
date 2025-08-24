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
    
    "clean")
        echo "🧹 Cleaning up test environments and backups..."
        rm -rf tests/test_environment/
        rm -f bitfreeze.php.backup.*
        rm -f logs/*.txt
        echo "✅ Cleanup completed!"
        ;;
    
    "help"|"")
        echo "BitFreeze Build Script"
        echo ""
        echo "Usage: $0 <command>"
        echo ""
        echo "Commands:"
        echo "  test-dev      Run tests on development version"
        echo "  test-compiled Run tests on compiled version" 
        echo "  compile       Compile dev version to production"
        echo "  release       Full release process (test + compile)"
        echo "  clean         Clean up temporary files"
        echo "  help          Show this help message"
        echo ""
        echo "Development workflow:"
        echo "  1. Work on dev_bitfreeze.php"
        echo "  2. Run '$0 test-dev' to test changes"
        echo "  3. Run '$0 release' when ready to deploy"
        ;;
    
    *)
        echo "❌ Unknown command: $1"
        echo "Run '$0 help' for available commands"
        exit 1
        ;;
esac
