<?php
/**
 * Export All Artwork as ZIP
 *
 * Allows authenticated artist to download a ZIP of all their
 * uploads and metadata before deleting their account.
 */

session_start();

if (!isset($_SESSION['artist_authenticated']) || !$_SESSION['artist_authenticated']) {
    http_response_code(401);
    echo 'Authentication required';
    exit;
}

$uploads_dir = __DIR__ . '/../uploads';
$meta_file = __DIR__ . '/../artwork_meta.json';
$config_file = __DIR__ . '/../artist_config.php';

if (!is_dir($uploads_dir) || count(glob($uploads_dir . '/*')) === 0) {
    http_response_code(404);
    echo 'No artwork to export';
    exit;
}

$config = file_exists($config_file) ? require $config_file : [];
$artist_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $config['name'] ?? 'artist');
$zip_name = $artist_name . '_artwork_' . date('Y-m-d') . '.zip';
$zip_path = sys_get_temp_dir() . '/' . $zip_name;

$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Failed to create ZIP archive';
    exit;
}

// Add all uploads (originals + resized + DZI)
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($uploads_dir, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file->isFile()) {
        $relative = 'uploads/' . substr($file->getPathname(), strlen($uploads_dir) + 1);
        $zip->addFile($file->getPathname(), $relative);
    }
}

// Add metadata
if (file_exists($meta_file)) {
    $zip->addFile($meta_file, 'artwork_meta.json');
}

// Add sanitized config (strip api_key and auth secrets)
if (!empty($config)) {
    $safe_config = $config;
    unset($safe_config['api_key'], $safe_config['auth_signing_secret'], $safe_config['oauth']);
    $zip->addFromString('artist_profile.json', json_encode($safe_config, JSON_PRETTY_PRINT));
}

$zip->close();

// Stream to browser
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_name . '"');
header('Content-Length: ' . filesize($zip_path));
readfile($zip_path);
unlink($zip_path);
exit;
