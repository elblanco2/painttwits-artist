<?php
/**
 * Receive Artwork API
 *
 * Accepts artwork pushed from painttwits.com email handler.
 * Used when a federated artist emails artwork to newart@painttwits.com
 * and the hub pushes the processed images to this self-hosted site.
 *
 * Auth: X-API-Key header must match api_key in artist_config.php
 * Input: multipart/form-data with images[] files + metadata JSON
 */

// Load config
$config_file = dirname(__DIR__) . '/artist_config.php';
if (!file_exists($config_file)) {
    http_response_code(503);
    echo json_encode(['error' => 'Site not configured']);
    exit;
}
$config = require $config_file;

header('Content-Type: application/json');

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Verify API key
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected_key = $config['api_key'] ?? '';

if (empty($api_key) || empty($expected_key) || !hash_equals($expected_key, $api_key)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

// Simple rate limit via file-based counter
$rate_file = dirname(__DIR__) . '/logs/receive_rate.json';
$rate_dir = dirname($rate_file);
if (!is_dir($rate_dir)) {
    mkdir($rate_dir, 0755, true);
}
$rate_data = file_exists($rate_file) ? json_decode(file_get_contents($rate_file), true) : [];
$current_hour = date('Y-m-d-H');
if (($rate_data['hour'] ?? '') !== $current_hour) {
    $rate_data = ['hour' => $current_hour, 'count' => 0];
}
if ($rate_data['count'] >= 20) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Max 20 uploads per hour.']);
    exit;
}
$rate_data['count']++;
file_put_contents($rate_file, json_encode($rate_data), LOCK_EX);

// Parse metadata
$metadata_json = $_POST['metadata'] ?? '{}';
$metadata = json_decode($metadata_json, true);
if (!$metadata || empty($metadata['filename_base'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing metadata or filename_base']);
    exit;
}

$filename_base = preg_replace('/[^a-zA-Z0-9_\-]/', '', $metadata['filename_base']);
if (empty($filename_base)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid filename']);
    exit;
}

$uploads_dir = dirname(__DIR__) . '/uploads';
$dzi_dir = $uploads_dir . '/dzi';

if (!is_dir($uploads_dir)) {
    mkdir($uploads_dir, 0755, true);
}
if (!is_dir($dzi_dir)) {
    mkdir($dzi_dir, 0755, true);
}

// Save uploaded image files
$saved_files = [];
if (!empty($_FILES['images'])) {
    $files = $_FILES['images'];
    $count = is_array($files['name']) ? count($files['name']) : 1;

    for ($i = 0; $i < $count; $i++) {
        $tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];

        if ($error !== UPLOAD_ERR_OK || empty($tmp)) {
            continue;
        }

        // Validate file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp);
        finfo_close($finfo);

        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowed_mimes)) {
            continue;
        }

        // Sanitize the filename - only allow expected suffixes
        $safe_name = basename($name);
        $safe_name = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $safe_name);

        // Verify filename starts with expected base
        if (strpos($safe_name, $filename_base) !== 0) {
            continue;
        }

        $dest = $uploads_dir . '/' . $safe_name;
        if (move_uploaded_file($tmp, $dest)) {
            chmod($dest, 0644);
            $saved_files[] = $safe_name;
        }
    }
}

// Handle DZI archive if present
if (!empty($_FILES['dzi_archive']) && $_FILES['dzi_archive']['error'] === UPLOAD_ERR_OK) {
    $zip_tmp = $_FILES['dzi_archive']['tmp_name'];
    $zip = new ZipArchive();
    if ($zip->open($zip_tmp) === true) {
        // Extract to dzi directory
        $zip->extractTo($dzi_dir);
        $zip->close();
    }
}

if (empty($saved_files)) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid images received']);
    exit;
}

// Update artwork_meta.json
$meta_file = dirname(__DIR__) . '/artwork_meta.json';
$all_meta = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : [];
if (!is_array($all_meta)) {
    $all_meta = [];
}

// Find the original image (the one without a suffix like _large, _medium, etc.)
$original_file = $filename_base . '.jpg';
foreach ($saved_files as $f) {
    if ($f === $filename_base . '.jpg' || $f === $filename_base . '.png') {
        $original_file = $f;
        break;
    }
}

$new_entry = [
    'filename' => $original_file,
    'title' => $metadata['title'] ?? '',
    'dimensions' => $metadata['dimensions'] ?? '',
    'medium' => $metadata['medium'] ?? '',
    'price' => $metadata['price'] ?? '',
    'description' => $metadata['description'] ?? '',
    'tags' => $metadata['tags'] ?? [],
    'uploaded_at' => date('Y-m-d H:i:s'),
    'uploaded_via' => 'email',
    'status' => 'published',
];

$all_meta[$original_file] = $new_entry;
file_put_contents($meta_file, json_encode($all_meta, JSON_PRETTY_PRINT), LOCK_EX);

// Log
$log_file = dirname(__DIR__) . '/logs/receive_artwork.log';
$log_msg = date('Y-m-d H:i:s') . " Received: {$original_file} (" . count($saved_files) . " files)\n";
@file_put_contents($log_file, $log_msg, FILE_APPEND | LOCK_EX);

echo json_encode([
    'success' => true,
    'filename' => $original_file,
    'files_saved' => count($saved_files),
    'message' => 'Artwork received successfully',
]);
