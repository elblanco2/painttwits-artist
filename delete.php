<?php
/**
 * Delete Artwork Endpoint
 * Requires authentication
 */

session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['artist_authenticated']) || !$_SESSION['artist_authenticated']) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get filename from request
$input = json_decode(file_get_contents('php://input'), true);
$filename = $input['filename'] ?? '';

if (empty($filename)) {
    http_response_code(400);
    echo json_encode(['error' => 'Filename required']);
    exit;
}

// Validate filename (prevent directory traversal)
$filename = basename($filename);
if (empty($filename) || strpos($filename, '..') !== false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid filename']);
    exit;
}

$uploads_dir = __DIR__ . '/uploads/';
$dzi_dir = $uploads_dir . 'dzi/';

// Get base name without extension
$name = pathinfo($filename, PATHINFO_FILENAME);
$ext = pathinfo($filename, PATHINFO_EXTENSION);

// Delete original and all resized versions
$deleted = [];
$versions = [
    $filename,                      // original
    $name . '_large.' . $ext,       // large
    $name . '_medium.' . $ext,      // medium
    $name . '_small.' . $ext,       // small
    $name . '_social.' . $ext       // social share image
];

foreach ($versions as $version) {
    $path = $uploads_dir . $version;
    if (file_exists($path)) {
        if (unlink($path)) {
            $deleted[] = $version;
        }
    }
}

// Delete DZI files
$dzi_file = $dzi_dir . $name . '.dzi';
$dzi_tiles_dir = $dzi_dir . $name . '_files';

if (file_exists($dzi_file)) {
    if (unlink($dzi_file)) {
        $deleted[] = 'dzi/' . $name . '.dzi';
    }
}

if (is_dir($dzi_tiles_dir)) {
    // Recursively delete tile directory
    $deleted_tiles = deleteDirectory($dzi_tiles_dir);
    if ($deleted_tiles) {
        $deleted[] = 'dzi/' . $name . '_files/ (tiles)';
    }
}

// Remove from artwork_meta.json
$metadata_file = __DIR__ . '/artwork_meta.json';
if (file_exists($metadata_file)) {
    $metadata = json_decode(file_get_contents($metadata_file), true);
    if (is_array($metadata) && isset($metadata[$filename])) {
        unset($metadata[$filename]);
        file_put_contents($metadata_file, json_encode($metadata, JSON_PRETTY_PRINT));
        $deleted[] = 'metadata entry';
    }
}

if (empty($deleted)) {
    http_response_code(404);
    echo json_encode(['error' => 'File not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'deleted' => $deleted
]);

/**
 * Recursively delete a directory
 */
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }

    return rmdir($dir);
}
