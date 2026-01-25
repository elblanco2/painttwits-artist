<?php
/**
 * Update Artwork Metadata Endpoint
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

// Get data from request
$input = json_decode(file_get_contents('php://input'), true);
$filename = $input['filename'] ?? '';
$field = $input['field'] ?? '';
$value = $input['value'] ?? '';

if (empty($filename) || empty($field)) {
    http_response_code(400);
    echo json_encode(['error' => 'Filename and field required']);
    exit;
}

// Validate filename (prevent directory traversal)
$filename = basename($filename);
if (empty($filename) || strpos($filename, '..') !== false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid filename']);
    exit;
}

// Allowed fields
$allowed_fields = ['title', 'status', 'price', 'description', 'tags'];
if (!in_array($field, $allowed_fields)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid field']);
    exit;
}

// Sanitize tags (array of lowercase strings)
if ($field === 'tags') {
    if (is_string($value)) {
        // Convert comma-separated string to array
        $value = array_map('trim', explode(',', $value));
    }
    if (is_array($value)) {
        $value = array_values(array_filter(array_map(function($tag) {
            return strtolower(preg_replace('/[^a-z0-9-]/', '', strtolower(trim($tag))));
        }, $value)));
    } else {
        $value = [];
    }
}

// Validate status values
if ($field === 'status' && !in_array($value, ['available', 'sold', 'other'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status value']);
    exit;
}

// Load existing metadata
$metadata_file = __DIR__ . '/artwork_meta.json';
$metadata = file_exists($metadata_file) ? json_decode(file_get_contents($metadata_file), true) : [];

if (!is_array($metadata)) {
    $metadata = [];
}

// Update metadata
if (!isset($metadata[$filename])) {
    $metadata[$filename] = [];
}
$metadata[$filename][$field] = $value;

// Save metadata
if (file_put_contents($metadata_file, json_encode($metadata, JSON_PRETTY_PRINT))) {
    echo json_encode([
        'success' => true,
        'filename' => $filename,
        'field' => $field,
        'value' => $value
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save metadata']);
}
