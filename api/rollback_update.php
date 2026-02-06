<?php
/**
 * Rollback to Previous Version
 *
 * Restores from the most recent backup ZIP.
 */

// Must be authenticated
session_start();
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

header('Content-Type: application/json');
set_time_limit(180); // 3 minutes

$rootDir = dirname(__DIR__);
$backupsDir = $rootDir . '/backups';

$steps = [];

try {
    // Find most recent backup
    $backupFiles = glob($backupsDir . '/backup_*.zip');

    if (empty($backupFiles)) {
        throw new Exception('No backup files found');
    }

    // Sort by modification time (newest first)
    usort($backupFiles, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    $latestBackup = $backupFiles[0];
    $steps[] = 'Found backup: ' . basename($latestBackup);

    // Extract backup
    $zip = new ZipArchive();
    if ($zip->open($latestBackup) !== true) {
        throw new Exception('Failed to open backup ZIP');
    }

    // Files/dirs to preserve during rollback
    $preserveFiles = ['artist_config.php'];
    $preserveDirs = ['uploads', 'logs', 'dzi', 'backups', '.git'];

    // Extract all files except preserved ones
    $extractedCount = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);

        // Check if file should be preserved
        $skip = false;
        if (in_array($filename, $preserveFiles)) {
            $skip = true;
        }
        foreach ($preserveDirs as $preserveDir) {
            if (strpos($filename, $preserveDir . '/') === 0 || $filename === $preserveDir) {
                $skip = true;
                break;
            }
        }

        if (!$skip) {
            $destPath = $rootDir . '/' . $filename;

            // Create directory if needed
            if (substr($filename, -1) === '/') {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                // Ensure parent directory exists
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }

                // Extract file
                $fileContent = $zip->getFromIndex($i);
                file_put_contents($destPath, $fileContent);
                $extractedCount++;
            }
        }
    }

    $zip->close();
    $steps[] = "Restored {$extractedCount} files";

    // Get version after rollback
    $versionData = require $rootDir . '/version.php';

    echo json_encode([
        'success' => true,
        'steps' => $steps,
        'restored_version' => $versionData['version'],
        'backup_file' => basename($latestBackup)
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'steps' => $steps
    ], JSON_PRETTY_PRINT);
}
