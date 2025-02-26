<?php
/**
 * Cleanup Script for Thumbnail Generator
 * 
 * This script removes files older than 10 minutes from the uploads and thumbnails directories.
 * It can be run manually or set up as a cron job for automatic cleanup.
 * 
 * Example cron entry (runs every 15 minutes):
 * */15 * * * * php /path/to/cleanup.php
 */

// Configuration
$cleanupAge = 10 * 60; // 10 minutes in seconds
$directories = [
    __DIR__ . '/uploads',
    __DIR__ . '/clips',
    __DIR__ . '/thumbnails'
];
$logEnabled = true;
$logFile = __DIR__ . '/cleanup.log';

// Initialize counters
$totalFiles = 0;
$deletedFiles = 0;
$errors = 0;

// Start time for logging
$startTime = time();
$logMessage = "Cleanup started at " . date('Y-m-d H:i:s', $startTime) . "\n";

// Process each directory
foreach ($directories as $directory) {
    if (!file_exists($directory)) {
        $logMessage .= "Directory does not exist: $directory\n";
        continue;
    }
    
    $logMessage .= "Processing directory: $directory\n";
    
    // Get all files in the directory
    $files = glob($directory . '/*');
    $directoryFiles = count($files);
    $totalFiles += $directoryFiles;
    
    $logMessage .= "Found $directoryFiles files\n";
    
    foreach ($files as $file) {
        // Skip if it's a directory
        if (is_dir($file)) {
            continue;
        }
        
        // Get file modification time
        $fileModTime = filemtime($file);
        $fileAge = $startTime - $fileModTime;
        
        // If file is older than the cleanup age, delete it
        if ($fileAge > $cleanupAge) {
            try {
                if (unlink($file)) {
                    $deletedFiles++;
                    $logMessage .= "Deleted: " . basename($file) . " (age: " . formatTime($fileAge) . ")\n";
                } else {
                    $errors++;
                    $logMessage .= "Failed to delete: " . basename($file) . "\n";
                }
            } catch (Exception $e) {
                $errors++;
                $logMessage .= "Error deleting " . basename($file) . ": " . $e->getMessage() . "\n";
            }
        }
    }
}

// Finish log message
$endTime = time();
$duration = $endTime - $startTime;
$logMessage .= "\nCleanup completed at " . date('Y-m-d H:i:s', $endTime) . "\n";
$logMessage .= "Duration: " . formatTime($duration) . "\n";
$logMessage .= "Total files: $totalFiles\n";
$logMessage .= "Deleted files: $deletedFiles\n";
$logMessage .= "Errors: $errors\n";
$logMessage .= "-----------------------------------------------\n";

// Write to log file if enabled
if ($logEnabled) {
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Also output to console if run from command line
if (php_sapi_name() === 'cli') {
    echo $logMessage;
}

// Helper function to format time in a human-readable way
function formatTime($seconds) {
    if ($seconds < 60) {
        return $seconds . " seconds";
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        return $minutes . " min " . $remainingSeconds . " sec";
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;
        return $hours . " hours " . $minutes . " min " . $remainingSeconds . " sec";
    }
}