<?php
/**
 * Check for Software Updates
 *
 * Fetches latest release from GitHub and compares with current version.
 * Returns update availability info.
 */

header('Content-Type: application/json');

// Load current version
$currentVersionData = require __DIR__ . '/../version.php';
$currentVersion = $currentVersionData['version'];
$githubRepo = $currentVersionData['github_repo'];

// Fetch latest release from GitHub API
$githubApiUrl = "https://api.github.com/repos/{$githubRepo}/releases/latest";

$ch = curl_init($githubApiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => 'Painttwits-Artist-Gallery',
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Handle API errors
if ($httpCode !== 200) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch release info from GitHub',
        'details' => $curlError ?: "HTTP {$httpCode}"
    ]);
    exit;
}

$releaseData = json_decode($response, true);

if (!$releaseData || !isset($releaseData['tag_name'])) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid response from GitHub API'
    ]);
    exit;
}

// Parse versions (strip 'v' prefix if present)
$latestVersion = ltrim($releaseData['tag_name'], 'v');
$updateAvailable = version_compare($latestVersion, $currentVersion, '>');

// Find ZIP asset URL
$zipballUrl = $releaseData['zipball_url'] ?? null;
$downloadUrl = null;

// Prefer release asset ZIP if available, otherwise use zipball
if (isset($releaseData['assets']) && is_array($releaseData['assets'])) {
    foreach ($releaseData['assets'] as $asset) {
        if (isset($asset['name']) && preg_match('/\.zip$/i', $asset['name'])) {
            $downloadUrl = $asset['browser_download_url'];
            break;
        }
    }
}

if (!$downloadUrl) {
    $downloadUrl = $zipballUrl;
}

// Response
echo json_encode([
    'success' => true,
    'update_available' => $updateAvailable,
    'current_version' => $currentVersion,
    'latest_version' => $latestVersion,
    'release_date' => $releaseData['published_at'] ?? null,
    'release_notes' => $releaseData['body'] ?? '',
    'download_url' => $downloadUrl,
    'changelog_url' => $releaseData['html_url'] ?? null
], JSON_PRETTY_PRINT);
