<?php
/**
 * Artist Location API
 * Proxies location requests to the central painttwits.com API
 */

session_start();

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['artist_authenticated']) || !$_SESSION['artist_authenticated']) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Get artist config
$config_file = __DIR__ . '/../artist_config.php';
$config = file_exists($config_file) ? require $config_file : [];

$central_api = $config['central_api'] ?? $config['painttwits_api'] ?? '';
$api_key = $config['api_key'] ?? '';
$artist_id = $config['artist_id'] ?? '';
$subdomain = $config['subdomain'] ?? '';

if (empty($central_api) || empty($api_key)) {
    echo json_encode(['error' => 'Configuration missing (central_api or api_key)']);
    exit;
}

if (empty($artist_id) && empty($subdomain)) {
    echo json_encode(['error' => 'Configuration missing (artist_id or subdomain)']);
    exit;
}

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? 'get';

$url = rtrim($central_api, '/') . '/artist/location.php';

switch ($action) {
    case 'get':
        $payload = [
            'action' => 'get',
            'api_key' => $api_key,
        ];
        if ($artist_id) $payload['artist_id'] = $artist_id;
        if ($subdomain) $payload['subdomain'] = $subdomain;
        echo json_encode(callApi($url, $payload));
        break;

    case 'update':
        $payload = [
            'action' => 'update',
            'api_key' => $api_key,
            'zip_code' => $input['zip_code'] ?? null,
            'latitude' => $input['latitude'] ?? null,
            'longitude' => $input['longitude'] ?? null,
        ];
        if ($artist_id) $payload['artist_id'] = $artist_id;
        if ($subdomain) $payload['subdomain'] = $subdomain;
        echo json_encode(callApi($url, $payload));
        break;

    case 'lookup':
        echo json_encode(callApi($url, [
            'action' => 'lookup',
            'zip_code' => $input['zip_code'] ?? '',
        ]));
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}

function callApi($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        return json_decode($response, true) ?: ['error' => 'Invalid response'];
    }

    return ['error' => 'API request failed (HTTP ' . $httpCode . ')'];
}
