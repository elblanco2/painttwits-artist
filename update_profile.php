<?php
/**
 * Update Artist Profile Endpoint
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

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Load existing config
$config_file = __DIR__ . '/artist_config.php';
if (!file_exists($config_file)) {
    http_response_code(500);
    echo json_encode(['error' => 'Config file not found']);
    exit;
}

$config = require $config_file;

// Update allowed fields
$updated = false;

if (isset($input['bio'])) {
    $config['bio'] = trim($input['bio']);
    $updated = true;
}

if (isset($input['website'])) {
    $website = trim($input['website']);
    // Basic URL validation
    if ($website && !filter_var($website, FILTER_VALIDATE_URL)) {
        // Try adding https:// if missing
        if (!preg_match('/^https?:\/\//', $website)) {
            $website = 'https://' . $website;
        }
        if (!filter_var($website, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid website URL']);
            exit;
        }
    }
    $config['website'] = $website;
    $updated = true;
}

if (isset($input['location'])) {
    $config['location'] = trim($input['location']);
    $updated = true;
}

if (!$updated) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid fields to update']);
    exit;
}

// Generate new config file content
$config_content = "<?php\n";
$config_content .= "/**\n";
$config_content .= " * Artist Configuration\n";
$config_content .= " * Auto-generated - do not edit directly\n";
$config_content .= " */\n\n";
$config_content .= "return [\n";
$config_content .= "    'name' => " . var_export($config['name'] ?? '', true) . ",\n";
$config_content .= "    'email' => " . var_export($config['email'] ?? '', true) . ",\n";
$config_content .= "    'location' => " . var_export($config['location'] ?? '', true) . ",\n";
$config_content .= "    'bio' => " . var_export($config['bio'] ?? '', true) . ",\n";
$config_content .= "    'website' => " . var_export($config['website'] ?? '', true) . ",\n";
$config_content .= "    'artist_id' => " . var_export($config['artist_id'] ?? '', true) . ",\n";
$config_content .= "    'api_key' => " . var_export($config['api_key'] ?? '', true) . ",\n";
$config_content .= "];\n";

// Save config
if (file_put_contents($config_file, $config_content)) {
    echo json_encode([
        'success' => true,
        'message' => 'Profile updated'
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save config']);
}
