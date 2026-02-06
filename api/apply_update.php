<?php
/**
 * Apply Software Update
 *
 * Downloads release ZIP, creates backup, extracts and applies update.
 * Preserves config files and user data.
 */

// Must be authenticated
session_start();
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

header('Content-Type: application/json');
set_time_limit(300); // 5 minutes for download/extract

$rootDir = dirname(__DIR__);
$backupsDir = $rootDir . '/backups';
$tempDir = $rootDir . '/temp_update';

// Get download URL from POST
$input = json_decode(file_get_contents('php://input'), true);
$downloadUrl = $input['download_url'] ?? null;

if (!$downloadUrl) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing download_url']);
    exit;
}

$steps = [];
$errors = [];

try {
    // Step 1: Create backups directory
    if (!is_dir($backupsDir)) {
        mkdir($backupsDir, 0755, true);
    }
    $steps[] = 'Created backups directory';

    // Step 2: Create backup ZIP
    $backupFile = $backupsDir . '/backup_' . date('Y-m-d_His') . '.zip';
    $zip = new ZipArchive();

    if ($zip->open($backupFile, ZipArchive::CREATE) !== true) {
        throw new Exception('Failed to create backup ZIP');
    }

    // Files/dirs to exclude from backup
    $excludeDirs = ['backups', 'temp_update', 'uploads', 'logs', 'dzi', '.git'];
    $excludeFiles = ['artist_config.php'];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($files as $file) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($rootDir) + 1);

        // Skip excluded directories and files
        $skip = false;
        foreach ($excludeDirs as $excludeDir) {
            if (strpos($relativePath, $excludeDir . '/') === 0 || $relativePath === $excludeDir) {
                $skip = true;
                break;
            }
        }
        if (in_array($relativePath, $excludeFiles)) {
            $skip = true;
        }

        if (!$skip) {
            if (is_dir($filePath)) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($filePath, $relativePath);
            }
        }
    }

    $zip->close();
    $steps[] = 'Backup created: ' . basename($backupFile);

    // Step 3: Download update ZIP
    $steps[] = 'Downloading update from GitHub...';

    $updateZip = $tempDir . '/update.zip';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }

    $ch = curl_init($downloadUrl);
    $fp = fopen($updateZip, 'w');

    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Painttwits-Artist-Gallery',
        CURLOPT_TIMEOUT => 120
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$result || $httpCode !== 200) {
        throw new Exception("Failed to download update (HTTP {$httpCode})");
    }

    $steps[] = 'Update downloaded: ' . round(filesize($updateZip) / 1024 / 1024, 2) . ' MB';

    // Step 4: Extract update
    $extractDir = $tempDir . '/extracted';
    if (!is_dir($extractDir)) {
        mkdir($extractDir, 0755, true);
    }

    $zip = new ZipArchive();
    if ($zip->open($updateZip) !== true) {
        throw new Exception('Failed to open update ZIP');
    }

    $zip->extractTo($extractDir);
    $zip->close();
    $steps[] = 'Update extracted';

    // GitHub zipballs have a single root directory (repo-name-commit), find it
    $extractedFiles = scandir($extractDir);
    $rootFolder = null;
    foreach ($extractedFiles as $item) {
        if ($item !== '.' && $item !== '..' && is_dir($extractDir . '/' . $item)) {
            $rootFolder = $extractDir . '/' . $item;
            break;
        }
    }

    if (!$rootFolder) {
        throw new Exception('Invalid update package structure');
    }

    // Step 5: Verify critical files exist
    $criticalFiles = ['index.php', 'art.php', 'auth.php', 'version.php'];
    foreach ($criticalFiles as $criticalFile) {
        if (!file_exists($rootFolder . '/' . $criticalFile)) {
            throw new Exception("Missing critical file: {$criticalFile}");
        }
    }
    $steps[] = 'Critical files verified';

    // Step 6: Apply update (copy files, preserving config)
    $preserveFiles = ['artist_config.php'];
    $preserveDirs = ['uploads', 'logs', 'dzi', 'backups', '.git'];

    $updateFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootFolder, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $copiedCount = 0;
    foreach ($updateFiles as $file) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($rootFolder) + 1);

        // Skip preserved files/dirs
        $skip = false;
        if (in_array($relativePath, $preserveFiles)) {
            $skip = true;
        }
        foreach ($preserveDirs as $preserveDir) {
            if (strpos($relativePath, $preserveDir . '/') === 0 || $relativePath === $preserveDir) {
                $skip = true;
                break;
            }
        }

        if (!$skip) {
            $destPath = $rootDir . '/' . $relativePath;

            if (is_dir($filePath)) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                // Ensure parent directory exists
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                copy($filePath, $destPath);
                $copiedCount++;
            }
        }
    }

    $steps[] = "Updated {$copiedCount} files";

    // Step 7: Cleanup temp files
    function deleteDirectory($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    deleteDirectory($tempDir);
    $steps[] = 'Cleanup completed';

    // Step 8: Delete old backups (keep last 5)
    $backupFiles = glob($backupsDir . '/backup_*.zip');
    if (count($backupFiles) > 5) {
        usort($backupFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        $toDelete = array_slice($backupFiles, 5);
        foreach ($toDelete as $oldBackup) {
            unlink($oldBackup);
        }
        $steps[] = 'Deleted ' . count($toDelete) . ' old backup(s)';
    }

    // Success response
    $newVersion = require $rootDir . '/version.php';
    echo json_encode([
        'success' => true,
        'steps' => $steps,
        'new_version' => $newVersion['version'],
        'backup_file' => basename($backupFile)
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // Cleanup on error
    if (is_dir($tempDir)) {
        function deleteDirectory($dir) {
            if (!is_dir($dir)) return;
            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                $path = $dir . '/' . $file;
                is_dir($path) ? deleteDirectory($path) : unlink($path);
            }
            rmdir($dir);
        }
        deleteDirectory($tempDir);
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'steps' => $steps
    ], JSON_PRETTY_PRINT);
}
