<?php
/**
 * PasswordManager Class
 * 
 * Centralizes all password-related functionality including command line parsing,
 * interactive prompting, encryption detection, and password validation.
 * Eliminates duplicate code across multiple password functions.
 */
class PasswordManager {
    private $archive_manager;

    public function __construct() {
        $this->archive_manager = new ArchiveManager();
    }
    
    /**
     * Get password from command line arguments
     * 
     * @return string|null The password or null if not provided
     */
    public function getPasswordFromArgs(): ?string {
        global $argv;

        // Check for -p argument
        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] === '-p') {
                // If -p is followed by a value, return it
                if (isset($argv[$i + 1]) && $argv[$i + 1][0] !== '-') {
                    return $argv[$i + 1];
                }

                // If -p is provided without a value, return null
                return null;
            }
        }

        return null;
    }

    /**
     * Check if -p flag was used without a value (indicating user wants to be prompted)
     * 
     * @return bool True if -p was used without a value
     */
    public function shouldPromptForPassword(): bool {
        global $argv;

        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] === '-p') {
                // If -p is followed by a value, password was already provided
                if (isset($argv[$i + 1]) && $argv[$i + 1][0] !== '-') {
                    return false;
                }

                // If -p is provided without a value, we should prompt
                return true;
            }
        }

        return false;
    }

    /**
     * Prompt user for encryption password interactively
     * 
     * @return string|null The password entered by user, or null if cancelled
     */
    public function promptForEncryptionPassword(): ?string {
        echo "Enter encryption password: ";

        $password = $this->getHiddenInput();

        if (empty($password)) {
            echo "No password provided. Exiting.\n";
            exit(1);
        }

        return $password;
    }

    /**
     * Prompt user for repository password interactively
     * 
     * @param string $repository_name Name of the repository for the prompt
     * @return string|null The password entered by user, or null if cancelled
     */
    public function promptForRepositoryPassword(string $repository_name): ?string {
        echo "Repository '$repository_name' is password protected.\n";
        echo "Enter password: ";

        $password = $this->getHiddenInput();

        if (empty($password)) {
            echo "No password provided. Exiting.\n";
            exit(1);
        }

        return $password;
    }

    /**
     * Prompt for password with retry logic
     * 
     * @param string $repository_name Name of the repository for the prompt
     * @param callable $test_function Function to test if password is correct
     * @return string|null The correct password or null if user cancels
     */
    public function promptForPasswordWithRetry(string $repository_name, callable $test_function): ?string {
        $max_attempts   = 3;
        $attempt        = 0;
        
        while ($attempt < $max_attempts) {
            $attempt++;

            echo "Repository '$repository_name' is password protected.\n";
            echo "Enter password: ";

            $password = $this->getHiddenInput();

            if (empty($password)) {
                echo "No password provided. Exiting.\n";
                exit(1);
            }

            // Test the password
            if ($test_function($password)) {
                return $password;
            }

            // Password was incorrect
            echo "Incorrect password for $repository_name\n";

            if ($attempt < $max_attempts) {
                echo "Please try again.\n";
            } else {
                echo "Maximum attempts reached. Exiting.\n";
                exit(1);
            }
        }
        
        return null;
    }
    
    /**
     * Get password with smart detection and retry logic
     * 
     * @param string $rarfile RAR archive file path
     * @return string|null The password or null if user cancels
     */
    public function getPasswordWithDetection(string $rarfile): ?string {
        $password       = $this->getPasswordFromArgs();
        $should_prompt  = $this->shouldPromptForPassword();
        
        // If no password provided or -p was used without value, check if repository  is encrypted
        if ($password === null || $should_prompt) {
            if (file_exists($rarfile)) {
                // Repository exists - check if it's encrypted
                if ($this->archive_manager->isEncrypted($rarfile)) {

                    // Create a test function for this specific repository
                    $test_function = function($test_password) use ($rarfile) {
                        return $this->archive_manager->testPassword($rarfile, $test_password);
                    };

                    $password = $this->promptForPasswordWithRetry(basename($rarfile), $test_function);
                }
            } else if ($should_prompt) {
                // Repository doesn't exist but -p was used without value - prompt for encryption password
                $password = $this->promptForEncryptionPassword();
            }
        } else if ($password !== null && file_exists($rarfile) && $this->archive_manager->isEncrypted($rarfile)) {
            // If password was provided via command line, test it
            if (!$this->archive_manager->testPassword($rarfile, $password)) {

                echo "Incorrect password for " . basename($rarfile) . "\n";

                // Create a test function for this specific archive
                $test_function = function($test_password) use ($rarfile) {
                    return $this->archive_manager->testPassword($rarfile, $test_password);
                };

                $password = $this->promptForPasswordWithRetry(basename($rarfile), $test_function);
            }
        }
        
        return $password;
    }

    /**
     * Get hidden input from user (password input)
     * 
     * @return string The input entered by user
     */
    private function getHiddenInput(): string {
        // Hide input for security (only if we're in an interactive terminal)
        if (posix_isatty(STDIN)) {
            system('stty -echo');
            $input = trim(fgets(STDIN));
            system('stty echo');
            echo "\n";
        } else {
            $input = trim(fgets(STDIN));
            echo "\n";
        }
        
        return $input;
    }
}

