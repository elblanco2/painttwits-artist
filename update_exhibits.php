<?php
/**
 * Exhibit CRUD Endpoint
 * Manages exhibits.json — create, update, delete, reorder exhibits
 * Requires authentication + CSRF
 */

session_start();
require_once __DIR__ . '/security_helpers.php';
header('Content-Type: application/json');

// Load config for hub sync
$config_file = __DIR__ . '/artist_config.php';
$_exhibitConfig = file_exists($config_file) ? require $config_file : [];

/**
 * Best-effort sync exhibit to central painttwits hub
 */
function syncExhibitToHub($slug, $exhibit) {
    global $_exhibitConfig;
    $api = $_exhibitConfig['central_api'] ?? $_exhibitConfig['painttwits_api'] ?? '';
    $api_key = $_exhibitConfig['api_key'] ?? '';
    $artist_id = $_exhibitConfig['artist_id'] ?? '';
    if (empty($api) || empty($api_key) || empty($artist_id)) return;

    $cover_filename = $exhibit['cover'] ?? ($exhibit['artworks'][0] ?? '');
    $syncData = [
        'artist_id' => $artist_id,
        'api_key' => $api_key,
        'slug' => $slug,
        'title' => $exhibit['title'] ?? '',
        'description' => $exhibit['description'] ?? '',
        'cover_filename' => $cover_filename,
        'artwork_filenames' => $exhibit['artworks'] ?? [],
        'exhibit_type' => $exhibit['type'] ?? 'solo',
        'duration' => $exhibit['duration'] ?? 'temporary',
        'start_date' => $exhibit['start_date'] ?? null,
        'end_date' => $exhibit['end_date'] ?? null,
        'opening_reception' => $exhibit['opening_reception'] ?? null,
        'venue' => $exhibit['venue'] ?? '',
        'press_release' => $exhibit['press_release'] ?? '',
        'status' => $exhibit['status'] ?? 'draft'
    ];

    $ch = curl_init(rtrim($api, '/') . '/sync_exhibit.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($syncData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    curl_close($ch);
}

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

// Parse input
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
    $input = $_POST;
}

// Verify CSRF
$csrf = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!$csrf || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$action = $input['action'] ?? '';
$exhibits_file = __DIR__ . '/exhibits.json';
$exhibits = file_exists($exhibits_file) ? json_decode(file_get_contents($exhibits_file), true) : [];
if (!is_array($exhibits)) $exhibits = [];

switch ($action) {
    case 'list':
        echo json_encode(['success' => true, 'exhibits' => $exhibits]);
        break;

    case 'create':
        $title = trim($input['title'] ?? '');
        if (empty($title)) {
            http_response_code(400);
            echo json_encode(['error' => 'Title is required']);
            exit;
        }

        // Generate slug
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
        $slug = trim($slug, '-');
        if (empty($slug)) $slug = 'exhibit-' . time();

        // Ensure unique slug
        $base_slug = $slug;
        $counter = 2;
        while (isset($exhibits[$slug])) {
            $slug = $base_slug . '-' . $counter++;
        }

        $exhibits[$slug] = [
            'title' => $title,
            'description' => trim($input['description'] ?? ''),
            'cover' => $input['cover'] ?? null,
            'artworks' => $input['artworks'] ?? [],
            'type' => 'solo',
            'duration' => $input['duration'] ?? 'temporary',
            'start_date' => $input['start_date'] ?? null,
            'end_date' => $input['end_date'] ?? null,
            'opening_reception' => $input['opening_reception'] ?? null,
            'venue' => trim($input['venue'] ?? ''),
            'press_release' => trim($input['press_release'] ?? ''),
            'status' => $input['status'] ?? 'draft',
            'created_at' => date('Y-m-d H:i:s')
        ];

        if (file_put_contents($exhibits_file, json_encode($exhibits, JSON_PRETTY_PRINT), LOCK_EX)) {
            if ($exhibits[$slug]['status'] === 'published') {
                syncExhibitToHub($slug, $exhibits[$slug]);
            }
            echo json_encode(['success' => true, 'slug' => $slug, 'exhibit' => $exhibits[$slug]]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save']);
        }
        break;

    case 'update':
        $slug = $input['slug'] ?? '';
        if (empty($slug) || !isset($exhibits[$slug])) {
            http_response_code(404);
            echo json_encode(['error' => 'Exhibit not found']);
            exit;
        }

        $allowed = ['title', 'description', 'cover', 'artworks', 'duration', 'start_date', 'end_date', 'opening_reception', 'venue', 'press_release', 'status'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $val = $input[$field];
                if (is_string($val)) $val = trim($val);
                $exhibits[$slug][$field] = $val;
            }
        }
        $exhibits[$slug]['updated_at'] = date('Y-m-d H:i:s');

        if (file_put_contents($exhibits_file, json_encode($exhibits, JSON_PRETTY_PRINT), LOCK_EX)) {
            if ($exhibits[$slug]['status'] === 'published') {
                syncExhibitToHub($slug, $exhibits[$slug]);
            }
            echo json_encode(['success' => true, 'slug' => $slug, 'exhibit' => $exhibits[$slug]]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save']);
        }
        break;

    case 'delete':
        $slug = $input['slug'] ?? '';
        if (empty($slug) || !isset($exhibits[$slug])) {
            http_response_code(404);
            echo json_encode(['error' => 'Exhibit not found']);
            exit;
        }

        unset($exhibits[$slug]);

        if (file_put_contents($exhibits_file, json_encode($exhibits, JSON_PRETTY_PRINT), LOCK_EX)) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save']);
        }
        break;

    case 'reorder':
        $slug = $input['slug'] ?? '';
        $artworks = $input['artworks'] ?? [];
        if (empty($slug) || !isset($exhibits[$slug])) {
            http_response_code(404);
            echo json_encode(['error' => 'Exhibit not found']);
            exit;
        }
        if (!is_array($artworks)) {
            http_response_code(400);
            echo json_encode(['error' => 'artworks must be an array']);
            exit;
        }

        $exhibits[$slug]['artworks'] = $artworks;
        $exhibits[$slug]['updated_at'] = date('Y-m-d H:i:s');

        if (file_put_contents($exhibits_file, json_encode($exhibits, JSON_PRETTY_PRINT), LOCK_EX)) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Use: list, create, update, delete, reorder']);
}
