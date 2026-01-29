<?php
/**
 * Artist Settings Page
 * Account management, theme preferences, etc.
 */

session_start();
require_once __DIR__ . '/security_helpers.php';

// Check authentication
if (!isset($_SESSION['artist_authenticated']) || !$_SESSION['artist_authenticated']) {
    header('Location: /');
    exit;
}

// Load artist config
$config_file = __DIR__ . '/artist_config.php';
$config = file_exists($config_file) ? require $config_file : [];

$artist_name = $config['name'] ?? 'Artist';
$artist_email = $config['email'] ?? '';
// Get subdomain from config or extract from hostname
$subdomain = $config['subdomain'] ?? '';
if (empty($subdomain)) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (preg_match('/^([a-z0-9-]+)\.painttwits\.com$/i', $host, $m)) {
        $subdomain = $m[1];
    }
}
$site_name = $config['site_name'] ?? 'Gallery';
$site_url = $config['site_url'] ?? '';
// Support both 'central_api' and 'painttwits_api' config keys
$central_api = $config['central_api'] ?? $config['painttwits_api'] ?? '';
$api_key = $config['api_key'] ?? '';
$site_domain = $config['site_domain'] ?? 'painttwits.com';

// Check for upgrade success/cancel messages
$upgrade_success = isset($_GET['upgraded']) && $_GET['upgraded'] == '1';
$upgrade_cancelled = isset($_GET['cancelled']) && $_GET['cancelled'] == '1';

// Fetch multi-subdomain status from central API if available
$max_subdomains = 1;
$is_paid_multi = false;
$subdomain_count = 1;

if (!empty($central_api) && !empty($api_key)) {
    // Try to get account status from central API
    $statusUrl = rtrim($central_api, '/') . '/artist/status.php';
    $ch = curl_init($statusUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['api_key' => $api_key, 'email' => $artist_email]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $status = json_decode($response, true);
        if ($status && isset($status['max_subdomains'])) {
            $max_subdomains = intval($status['max_subdomains']);
            $is_paid_multi = !empty($status['is_paid_multi']);
            $subdomain_count = intval($status['subdomain_count'] ?? 1);
        }
    }
}

// Handle delete account request
$delete_error = '';
$delete_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Verify CSRF token
    $csrf = $_POST['csrf_token'] ?? '';
    if (!$csrf || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $delete_error = 'Invalid or expired form token. Please try again.';
    } elseif ($_POST['action'] === 'delete_account') {
        $confirm_input = trim($_POST['confirm_subdomain'] ?? '');
        $confirm_text = $subdomain ?: ($config['site_domain'] ?? $config['email'] ?? '');

        // Verify confirmation matches
        if (strtolower($confirm_input) !== strtolower($confirm_text)) {
            $delete_error = 'Confirmation text does not match. Please type it exactly.';
        } else {
            // Step 1: Notify central API (if connected to painttwits network)
            if (!empty($central_api) && !empty($api_key)) {
                $artist_id_val = $config['artist_id'] ?? '';
                $result = deleteViaCentralApi($central_api, $api_key, $artist_id_val);
                if (!$result['success']) {
                    $delete_error = $result['error'] ?? 'Failed to delete from central server.';
                }
            }

            // Step 2: Local cleanup (always, even if central fails — artist wants out)
            if (empty($delete_error)) {
                $result = deleteLocalAccount();
                if ($result['success']) {
                    $delete_success = true;
                } else {
                    $delete_error = $result['error'] ?? 'Failed to delete local account.';
                }
            }

            if ($delete_success) {
                // Clear session and redirect
                session_destroy();
                header('Location: ' . ($site_url ?: '/') . '?deleted=1');
                exit;
            }
        }
    }
}

/**
 * Delete via central painttwits API
 */
function deleteViaCentralApi($api_url, $api_key, $artist_id) {
    $url = rtrim($api_url, '/') . '/artist/delete.php';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'api_key' => $api_key,
        'artist_id' => $artist_id,
        'confirm' => true,
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        return ['success' => $result['success'] ?? false, 'error' => $result['error'] ?? null];
    }

    return ['success' => false, 'error' => 'API request failed (HTTP ' . $httpCode . ')'];
}

/**
 * Delete local self-hosted account
 */
function deleteLocalAccount() {
    $uploads_dir = __DIR__ . '/uploads';
    $config_file = __DIR__ . '/artist_config.php';
    $meta_file = __DIR__ . '/artwork_meta.json';

    try {
        // Delete all uploads
        if (is_dir($uploads_dir)) {
            $files = glob($uploads_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                } elseif (is_dir($file)) {
                    deleteDirectory($file);
                }
            }
        }

        // Delete artwork metadata
        if (file_exists($meta_file)) {
            unlink($meta_file);
        }

        // Clear config (keep file so site shows "deleted" state)
        if (file_exists($config_file)) {
            $config = require $config_file;
            file_put_contents(__DIR__ . '/deleted_' . date('Y-m-d_His') . '.txt',
                'Account deleted: ' . ($config['name'] ?? 'Unknown') . ' (' . ($config['site_domain'] ?? '') . ')');

            $emptyConfig = "<?php\nreturn [\n    'name' => 'Deleted Account',\n    'email' => '',\n    'deleted' => true,\n    'deleted_at' => '" . date('Y-m-d H:i:s') . "'\n];\n";
            file_put_contents($config_file, $emptyConfig);
        }

        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    rmdir($dir);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - <?= htmlspecialchars($artist_name) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <!-- Leaflet for map picker -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <style>
        .settings-page {
            max-width: 600px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        .settings-section {
            margin-bottom: 3rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--border-color, #eee);
        }
        .settings-section:last-child {
            border-bottom: none;
        }
        .settings-section h2 {
            font-size: 1.25rem;
            margin-bottom: 1rem;
            font-weight: normal;
        }
        .settings-section p {
            color: #666;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        .danger-zone {
            background: #fff5f5;
            border: 1px solid #ffcccc;
            padding: 1.5rem;
            border-radius: 4px;
        }
        .danger-zone h2 {
            color: #c00;
        }
        .delete-form {
            margin-top: 1rem;
        }
        .delete-form label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        .delete-form input[type="text"] {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ccc;
            margin-bottom: 1rem;
            font-family: monospace;
        }
        .btn-delete {
            background: #c00;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            font-size: 1rem;
        }
        .btn-delete:hover {
            background: #900;
        }
        .btn-delete:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .error-message {
            background: #ffebee;
            color: #c00;
            padding: 0.75rem;
            margin-bottom: 1rem;
            border-radius: 4px;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 2rem;
            color: #666;
            text-decoration: none;
        }
        .back-link:hover {
            color: #000;
        }
        .account-info {
            background: #f9f9f9;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        .account-info p {
            margin: 0.25rem 0;
            color: #333;
        }
        .account-info .label {
            color: #666;
            font-size: 0.85rem;
        }
        /* Theme options */
        .theme-options {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .theme-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            padding: 0.5rem;
            border: 2px solid transparent;
            border-radius: 8px;
            transition: border-color 0.2s;
        }
        .theme-option:hover {
            border-color: #ddd;
        }
        .theme-option input {
            display: none;
        }
        .theme-option input:checked + .theme-preview {
            box-shadow: 0 0 0 3px #007bff;
        }
        .theme-preview {
            width: 80px;
            height: 50px;
            border-radius: 6px;
            margin-bottom: 0.5rem;
            border: 1px solid #ddd;
        }
        .light-preview {
            background: linear-gradient(135deg, #fafafa 50%, #eee 50%);
        }
        .dark-preview {
            background: linear-gradient(135deg, #222 50%, #111 50%);
        }
        .system-preview {
            background: linear-gradient(135deg, #fafafa 50%, #222 50%);
        }
        .theme-label {
            font-size: 0.85rem;
            color: #666;
        }
        /* Upgrade section */
        .upgrade-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .upgrade-box h2 {
            color: white;
            margin-bottom: 0.5rem;
        }
        .upgrade-box p {
            color: rgba(255,255,255,0.9);
            margin-bottom: 1rem;
        }
        .upgrade-box ul {
            margin: 1rem 0;
            padding-left: 1.5rem;
        }
        .upgrade-box li {
            margin-bottom: 0.5rem;
            color: rgba(255,255,255,0.9);
        }
        .btn-upgrade {
            background: white;
            color: #667eea;
            border: none;
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            font-weight: bold;
            border-radius: 4px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-upgrade:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .btn-upgrade:disabled {
            opacity: 0.7;
            cursor: wait;
        }
        .price-tag {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        .already-upgraded {
            background: #e8f5e9;
            border: 1px solid #c8e6c9;
            padding: 1rem;
            border-radius: 4px;
            color: #2e7d32;
        }
        .already-upgraded h3 {
            margin: 0 0 0.5rem 0;
            color: #2e7d32;
        }
        .success-message {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 0.75rem;
            margin-bottom: 1rem;
            border-radius: 4px;
            border: 1px solid #c8e6c9;
        }
        .cancel-message {
            background: #fff3e0;
            color: #e65100;
            padding: 0.75rem;
            margin-bottom: 1rem;
            border-radius: 4px;
            border: 1px solid #ffe0b2;
        }
        /* Location settings */
        .location-map {
            height: 200px;
            border-radius: 8px;
            margin: 1rem 0;
            border: 1px solid #ddd;
        }
        .location-form input[type="text"] {
            width: 100%;
            max-width: 200px;
            padding: 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1rem;
        }
        .location-form label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        .location-hint {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.5rem;
        }
        .btn-save-location {
            background: #228B22;
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            margin-top: 1rem;
        }
        .btn-save-location:hover {
            background: #1a6b1a;
        }
        .btn-save-location:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .current-location {
            background: #f0f7ff;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            border: 1px solid #c0d8f0;
        }
    </style>
</head>
<body>
    <div class="settings-page">
        <a href="/" class="back-link">&larr; back to gallery</a>

        <h1>Settings</h1>

        <?php if ($upgrade_success): ?>
        <div class="success-message">
            <strong>Upgrade successful!</strong> You can now create up to <?= $max_subdomains ?> subdomains with your account.
        </div>
        <?php endif; ?>

        <?php if ($upgrade_cancelled): ?>
        <div class="cancel-message">
            Payment was cancelled. You can try again anytime.
        </div>
        <?php endif; ?>

        <!-- Account Info -->
        <div class="settings-section">
            <h2>Account</h2>
            <div class="account-info">
                <p><span class="label">Name:</span> <?= htmlspecialchars($artist_name) ?></p>
                <p><span class="label">Email:</span> <?= htmlspecialchars($artist_email) ?></p>
                <?php if ($subdomain): ?>
                <p><span class="label">Subdomain:</span> <?= htmlspecialchars($subdomain) ?>.<?= htmlspecialchars($config['site_domain'] ?? '') ?></p>
                <?php endif; ?>
                <?php if ($max_subdomains > 1): ?>
                <p><span class="label">Subdomains:</span> <?= $subdomain_count ?> / <?= $max_subdomains ?> used</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Location Settings -->
        <div class="settings-section">
            <h2>Location</h2>
            <p>Your location helps collectors find local artists on the map. Only your city/state is shown publicly.</p>

            <div id="location-status"></div>

            <div class="location-form">
                <label for="location-zip">ZIP Code</label>
                <input type="text" id="location-zip" placeholder="12345" maxlength="10" inputmode="numeric">
                <p class="location-hint">Or click on the map to set your location</p>
            </div>

            <div id="location-map" class="location-map"></div>
            <input type="hidden" id="location-lat">
            <input type="hidden" id="location-lng">
            <p id="location-coords" class="location-hint" style="display:none;"></p>

            <button type="button" id="save-location-btn" class="btn-save-location" onclick="saveLocation()">
                Save Location
            </button>

            <p class="privacy-note" style="font-size: 0.8rem; color: #888; margin-top: 1rem; padding: 0.75rem; background: #f9f9f9; border-radius: 4px;">
                <strong>Privacy:</strong> We never show your exact address. Your ZIP code is converted to an approximate area (within a few miles). If your photos have GPS data, we use that to show general regions only. The goal is to connect local collectors with local artists, not to pinpoint anyone's location.
            </p>
        </div>

        <!-- Multi-Subdomain Info / Upgrade -->
        <?php if (!empty($central_api)): ?>
        <div class="settings-section">
            <?php $canCreateMore = $is_paid_multi || ($subdomain_count < $max_subdomains); ?>

            <?php if ($is_paid_multi): ?>
            <div class="already-upgraded">
                <h3>Unlimited Subdomains</h3>
                <p>You have <strong>unlimited</strong> subdomains with your account.
                   Currently using <?= $subdomain_count ?>.</p>
            </div>
            <?php elseif ($max_subdomains >= 3): ?>
            <div class="already-upgraded" style="background:#f0f7ff;border-color:#c0d8f0;">
                <h3 style="color:#1565c0;">Free Tier: <?= $max_subdomains ?> Subdomains</h3>
                <p style="color:#333;">You can create up to <strong><?= $max_subdomains ?></strong> subdomains for free.
                   Currently using <?= $subdomain_count ?> of <?= $max_subdomains ?>.</p>
                <?php if ($subdomain_count >= $max_subdomains): ?>
                <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #c0d8f0;">
                    <p style="margin:0 0 0.5rem 0;color:#333;"><strong>Need more?</strong> Upgrade to unlimited subdomains.</p>
                    <button type="button" class="btn-upgrade" id="upgrade-btn" onclick="startUpgrade()" style="background:#667eea;color:white;">
                        Upgrade to Unlimited - $100
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="upgrade-box">
                <h2>Want Unlimited Galleries?</h2>
                <p>All artists get 3 subdomains free. Upgrade for unlimited.</p>
                <ul>
                    <li>Perfect for different art styles or collections</li>
                    <li>Separate portfolios for commissions vs personal work</li>
                    <li>One-time payment, lifetime access</li>
                </ul>
                <div class="price-tag">$100</div>
                <button type="button" class="btn-upgrade" id="upgrade-btn" onclick="startUpgrade()">
                    Upgrade Now
                </button>
            </div>
            <?php endif; ?>

            <!-- Your Subdomains List -->
            <?php if ($subdomain_count > 1 || $max_subdomains > 1): ?>
            <div class="your-subdomains-box" style="margin-top:1.5rem;padding:1.5rem;background:#f0f7ff;border:1px solid #c0d8f0;border-radius:8px;">
                <h3 style="margin:0 0 1rem 0;font-weight:normal;color:#1565c0;">Your Galleries</h3>
                <div id="subdomains-list" style="color:#666;">Loading...</div>
            </div>
            <?php endif; ?>

            <?php if ($canCreateMore): ?>
            <!-- Create New Subdomain Form -->
            <div class="create-subdomain-box" style="margin-top:1.5rem;padding:1.5rem;background:#f9f9f9;border:1px solid #ddd;border-radius:8px;">
                <h3 style="margin:0 0 1rem 0;font-weight:normal;">Create New Subdomain</h3>
                <div id="create-subdomain-form">
                    <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                        <input type="text"
                               id="new-subdomain"
                               placeholder="myportfolio"
                               minlength="2"
                               maxlength="50"
                               style="flex:1;min-width:150px;padding:0.5rem;border:1px solid #ccc;border-radius:4px;font-family:monospace;"
                               oninput="this.value = this.value.toLowerCase().replace(/[^a-z0-9-]/g, '')">
                        <span style="color:#666;">.painttwits.com</span>
                    </div>
                    <p style="margin:0.5rem 0;font-size:0.85rem;color:#666;">
                        Lowercase letters, numbers, and hyphens only. Must start with a letter.
                    </p>
                    <button type="button"
                            id="create-subdomain-btn"
                            onclick="createSubdomain()"
                            style="margin-top:0.5rem;padding:0.6rem 1.2rem;background:#228B22;color:white;border:none;border-radius:4px;cursor:pointer;font-size:1rem;">
                        Create Subdomain
                    </button>
                </div>
                <div id="create-subdomain-result" style="display:none;margin-top:1rem;"></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Theme Settings -->
        <div class="settings-section">
            <h2>Appearance</h2>

            <h3 style="font-size: 0.95rem; margin: 1.5rem 0 0.75rem; font-weight: normal;">Light / Dark Mode</h3>
            <div class="theme-options">
                <label class="theme-option">
                    <input type="radio" name="mode" value="light" data-mode-btn="light">
                    <span class="theme-preview light-preview"></span>
                    <span class="theme-label">Light</span>
                </label>
                <label class="theme-option">
                    <input type="radio" name="mode" value="dark" data-mode-btn="dark">
                    <span class="theme-preview dark-preview"></span>
                    <span class="theme-label">Dark</span>
                </label>
                <label class="theme-option">
                    <input type="radio" name="mode" value="system" data-mode-btn="system">
                    <span class="theme-preview system-preview"></span>
                    <span class="theme-label">Auto</span>
                </label>
            </div>

            <h3 style="font-size: 0.95rem; margin: 1.5rem 0 0.75rem; font-weight: normal;">Gallery Style</h3>
            <div class="theme-selector">
                <div class="theme-option" data-theme-preview="minimal">
                    <div class="theme-option-preview"></div>
                    <div class="theme-option-name">Minimal</div>
                    <div class="theme-option-desc">Clean monospace</div>
                </div>
                <div class="theme-option" data-theme-preview="gallery-white">
                    <div class="theme-option-preview"></div>
                    <div class="theme-option-name">Gallery</div>
                    <div class="theme-option-desc">Museum style</div>
                </div>
                <div class="theme-option" data-theme-preview="darkroom">
                    <div class="theme-option-preview"></div>
                    <div class="theme-option-name">Darkroom</div>
                    <div class="theme-option-desc">Photo portfolio</div>
                </div>
                <div class="theme-option" data-theme-preview="editorial">
                    <div class="theme-option-preview"></div>
                    <div class="theme-option-name">Editorial</div>
                    <div class="theme-option-desc">Magazine style</div>
                </div>
                <div class="theme-option" data-theme-preview="brutalist">
                    <div class="theme-option-preview"></div>
                    <div class="theme-option-name">Brutalist</div>
                    <div class="theme-option-desc">Raw & bold</div>
                </div>
                <div class="theme-option" data-theme-preview="soft">
                    <div class="theme-option-preview"></div>
                    <div class="theme-option-name">Soft</div>
                    <div class="theme-option-desc">Friendly & rounded</div>
                </div>
            </div>
        </div>

        <!-- Email Upload Instructions -->
        <div class="settings-section">
            <h2>Email Upload</h2>
            <p>Add artwork to your gallery by sending an email.</p>

            <div class="email-instructions" style="background:#f5f9ff;border:1px solid #d0e3ff;padding:1.5rem;border-radius:8px;">
                <h3 style="margin:0 0 1rem 0;font-weight:600;color:#1565c0;">How to Upload via Email</h3>

                <div style="margin-bottom:1.5rem;">
                    <p style="margin:0 0 0.5rem 0;"><strong>1. Send to:</strong></p>
                    <code style="display:block;background:#fff;padding:0.75rem;border-radius:4px;font-size:1.1rem;color:#333;border:1px solid #ddd;">newart@painttwits.com</code>
                </div>

                <div style="margin-bottom:1.5rem;">
                    <p style="margin:0 0 0.5rem 0;"><strong>2. Attach your artwork</strong> (JPG, PNG, HEIC - up to 10MB)</p>
                </div>

                <div style="margin-bottom:1.5rem;">
                    <p style="margin:0 0 0.5rem 0;"><strong>3. Add details in the subject line:</strong></p>
                    <code style="display:block;background:#fff;padding:0.75rem;border-radius:4px;color:#333;border:1px solid #ddd;">"Sunset Over Miami" 24x36 oil on canvas</code>
                    <p style="margin:0.5rem 0 0 0;font-size:0.85rem;color:#666;">Format: "Title" dimensions medium</p>
                </div>

                <?php if ($subdomain_count > 1 || $max_subdomains > 1): ?>
                <div style="background:#fff3e0;border:1px solid #ffe0b2;padding:1rem;border-radius:4px;margin-top:1.5rem;">
                    <h4 style="margin:0 0 0.75rem 0;color:#e65100;">Multiple Galleries?</h4>
                    <p style="margin:0 0 0.75rem 0;font-size:0.9rem;">
                        If you have multiple subdomains, specify which gallery to upload to by adding <strong>[subdomain]</strong> at the start of your subject line:
                    </p>
                    <code style="display:block;background:#fff;padding:0.75rem;border-radius:4px;color:#333;border:1px solid #ddd;">[<?= htmlspecialchars($subdomain) ?>] "Sunset Over Miami" 24x36</code>
                    <p style="margin:0.75rem 0 0 0;font-size:0.85rem;color:#666;">
                        Without the [subdomain] tag, artwork goes to your primary gallery.
                    </p>
                </div>
                <?php endif; ?>

                <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid #d0e3ff;">
                    <p style="margin:0;font-size:0.9rem;color:#666;">
                        <strong>Tip:</strong> You'll receive a confirmation email with links to your uploaded artwork.
                        High-resolution images (3000-5000px) will automatically get deep zoom enabled.
                    </p>
                </div>
            </div>
        </div>

        <!-- Danger Zone -->
        <div class="settings-section">
            <?php $confirm_text = $subdomain ?: ($config['site_domain'] ?? $config['email'] ?? ''); ?>
            <div class="danger-zone">
                <h2>Delete Account</h2>
                <p>
                    Permanently delete your account and all artwork. This action cannot be undone.
                    All uploaded images will be deleted and your account will be removed from the painttwits network.
                </p>

                <p style="margin-bottom:16px;">
                    <a href="/api/export.php" class="btn-export" style="display:inline-block;padding:8px 16px;background:#2563eb;color:#fff;border-radius:4px;text-decoration:none;font-size:14px;">Download All Artwork (ZIP)</a>
                    <span style="color:#666;font-size:13px;margin-left:8px;">Recommended before deleting</span>
                </p>

                <?php if ($delete_error): ?>
                <div class="error-message"><?= htmlspecialchars($delete_error) ?></div>
                <?php endif; ?>

                <form method="post" class="delete-form" onsubmit="return confirmDelete()">
                    <input type="hidden" name="action" value="delete_account">
                    <?= csrf_field() ?>

                    <label for="confirm_subdomain">
                        Type <strong><?= htmlspecialchars($confirm_text) ?></strong> to confirm:
                    </label>
                    <input type="text"
                           id="confirm_subdomain"
                           name="confirm_subdomain"
                           placeholder="<?= htmlspecialchars($confirm_text) ?>"
                           autocomplete="off"
                           required>

                    <button type="submit" class="btn-delete" id="delete-btn" disabled>
                        Delete My Account
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="assets/js/theme.js"></script>
    <script>
        var expectedConfirm = '<?= addslashes(strtolower($confirm_text)) ?>';
        var input = document.getElementById('confirm_subdomain');
        var btn = document.getElementById('delete-btn');

        input.addEventListener('input', function() {
            btn.disabled = input.value.toLowerCase() !== expectedConfirm;
        });

        function confirmDelete() {
            return confirm('Are you absolutely sure you want to delete your account?\n\nThis will permanently delete:\n- All your uploaded artwork\n- Your gallery and profile\n- Your connection to the painttwits network\n\nThis cannot be undone!');
        }

        // Initialize theme selection
        document.addEventListener('DOMContentLoaded', function() {
            var currentTheme = window.painttwitsTheme ? window.painttwitsTheme.get() : 'system';
            var radio = document.querySelector('input[name="theme"][value="' + currentTheme + '"]');
            if (radio) radio.checked = true;

            // Listen for theme changes
            document.querySelectorAll('input[name="theme"]').forEach(function(input) {
                input.addEventListener('change', function() {
                    if (window.painttwitsTheme) {
                        window.painttwitsTheme.set(this.value);
                    }
                });
            });
        });

        // Stripe upgrade
        function startUpgrade() {
            var btn = document.getElementById('upgrade-btn');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Redirecting...';
            }

            fetch('<?= rtrim($central_api, "/") ?>/purchase-multi.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    artist_email: '<?= addslashes($artist_email) ?>',
                    artist_id: '<?= addslashes($config['artist_id'] ?? '') ?>',
                    subdomain: '<?= addslashes($subdomain) ?>'
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.url) {
                    window.location.href = res.url;
                } else {
                    alert(res.error || 'Failed to start checkout');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Upgrade Now';
                    }
                }
            })
            .catch(function(err) {
                alert('Error: ' + err.message);
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Upgrade Now';
                }
            });
        }

        // Create new subdomain
        function createSubdomain() {
            var input = document.getElementById('new-subdomain');
            var btn = document.getElementById('create-subdomain-btn');
            var resultDiv = document.getElementById('create-subdomain-result');
            var subdomain = input.value.trim().toLowerCase();

            // Validate
            if (!subdomain) {
                alert('Please enter a subdomain name');
                return;
            }

            if (!/^[a-z][a-z0-9-]{1,48}[a-z0-9]$/.test(subdomain) && !/^[a-z][a-z0-9]?$/.test(subdomain)) {
                alert('Invalid subdomain. Use lowercase letters, numbers, and hyphens. Must start with a letter and be at least 2 characters.');
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Creating...';
            resultDiv.style.display = 'none';

            // Use local proxy to avoid CORS issues with Cloudflare
            fetch('/api/add-subdomain.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    new_subdomain: subdomain
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                resultDiv.style.display = 'block';

                if (res.success) {
                    resultDiv.innerHTML = '<div style="background:#e8f5e9;color:#2e7d32;padding:1rem;border-radius:4px;">' +
                        '<strong>Success!</strong> Your new subdomain is ready.<br>' +
                        '<a href="' + res.url + '" target="_blank" style="color:#1565c0;font-weight:bold;">' + res.url + '</a>' +
                        '<p style="margin:0.5rem 0 0 0;font-size:0.9rem;">Log in with the same Google account to manage it.</p>' +
                        '</div>';
                    input.value = '';
                    // Refresh page after a moment to update counts
                    setTimeout(function() { location.reload(); }, 3000);
                } else {
                    resultDiv.innerHTML = '<div style="background:#ffebee;color:#c00;padding:1rem;border-radius:4px;">' +
                        '<strong>Error:</strong> ' + (res.error || 'Failed to create subdomain') +
                        (res.can_upgrade ? '<br><br><button onclick="startUpgrade()" style="background:#667eea;color:white;border:none;padding:0.5rem 1rem;cursor:pointer;border-radius:4px;">Upgrade to Unlimited - $100</button>' : '') +
                        '</div>';
                    btn.disabled = false;
                    btn.textContent = 'Create Subdomain';
                }
            })
            .catch(function(err) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '<div style="background:#ffebee;color:#c00;padding:1rem;border-radius:4px;">' +
                    '<strong>Error:</strong> ' + err.message + '</div>';
                btn.disabled = false;
                btn.textContent = 'Create Subdomain';
            });
        }

        // Load subdomain list on page load
        document.addEventListener('DOMContentLoaded', function() {
            var listDiv = document.getElementById('subdomains-list');
            if (!listDiv) return;

            fetch('/api/subdomains.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success && res.subdomains) {
                    var currentSubdomain = '<?= addslashes($subdomain) ?>';
                    var html = '<div style="display:flex;flex-direction:column;gap:0.75rem;">';
                    res.subdomains.forEach(function(s) {
                        var isCurrent = s.subdomain === currentSubdomain;
                        html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:0.75rem;background:white;border-radius:4px;border:1px solid ' + (isCurrent ? '#1565c0' : '#ddd') + ';">';
                        html += '<div>';
                        html += '<a href="' + s.url + '" target="_blank" style="color:#1565c0;font-weight:500;text-decoration:none;">' + s.subdomain + '.painttwits.com</a>';
                        if (isCurrent) html += ' <span style="font-size:0.75rem;background:#1565c0;color:white;padding:2px 6px;border-radius:3px;margin-left:0.5rem;">current</span>';
                        html += '</div>';
                        html += '<a href="' + s.url + '/settings.php" style="font-size:0.85rem;color:#666;text-decoration:none;">settings &rarr;</a>';
                        html += '</div>';
                    });
                    html += '</div>';
                    listDiv.innerHTML = html;
                } else {
                    listDiv.innerHTML = '<p style="color:#999;">Could not load subdomains</p>';
                }
            })
            .catch(function(err) {
                listDiv.innerHTML = '<p style="color:#999;">Could not load subdomains</p>';
            });
        });

        // Location map picker
        var locationMap = null;
        var locationMarker = null;

        function initLocationMap() {
            if (typeof L === 'undefined') {
                document.getElementById('location-map').innerHTML = '<p style="color:#999;padding:1rem;">Map failed to load</p>';
                return;
            }
            if (locationMap) return;

            // Default to US center
            locationMap = L.map('location-map').setView([39.8, -98.5], 4);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://openstreetmap.org">OpenStreetMap</a>'
            }).addTo(locationMap);

            // Click to set location
            locationMap.on('click', function(e) {
                setLocationMarker(e.latlng.lat, e.latlng.lng);
            });

            // Load current location from API
            loadCurrentLocation();
        }

        function setLocationMarker(lat, lng) {
            if (locationMarker) {
                locationMarker.setLatLng([lat, lng]);
            } else {
                locationMarker = L.marker([lat, lng]).addTo(locationMap);
            }
            document.getElementById('location-lat').value = lat;
            document.getElementById('location-lng').value = lng;
            document.getElementById('location-coords').style.display = 'block';
            document.getElementById('location-coords').textContent = 'Selected: ' + lat.toFixed(4) + ', ' + lng.toFixed(4);
            locationMap.setView([lat, lng], 10);
        }

        function loadCurrentLocation() {
            fetch('/api/location.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get' }
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success && res.latitude && res.longitude) {
                    setLocationMarker(res.latitude, res.longitude);
                    if (res.zip_code) {
                        document.getElementById('location-zip').value = res.zip_code;
                    }
                    var statusDiv = document.getElementById('location-status');
                    statusDiv.innerHTML = '<div class="current-location"><strong>Current:</strong> ' +
                        (res.city ? res.city + ', ' + res.state : 'Location set') +
                        '</div>';
                }
            })
            .catch(function(err) {
                console.log('Could not load location:', err);
            });
        }

        function saveLocation() {
            var btn = document.getElementById('save-location-btn');
            var zipCode = document.getElementById('location-zip').value.trim();
            var lat = document.getElementById('location-lat').value;
            var lng = document.getElementById('location-lng').value;

            if (!zipCode && (!lat || !lng)) {
                alert('Please enter a ZIP code or click on the map to set your location');
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Saving...';

            fetch('/api/location.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update',
                    zip_code: zipCode,
                    latitude: lat ? parseFloat(lat) : null,
                    longitude: lng ? parseFloat(lng) : null
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                btn.disabled = false;
                btn.textContent = 'Save Location';

                if (res.success) {
                    var statusDiv = document.getElementById('location-status');
                    statusDiv.innerHTML = '<div class="success-message">Location saved! Your artwork will now appear on the map.</div>';
                    if (res.latitude && res.longitude) {
                        setLocationMarker(res.latitude, res.longitude);
                    }
                } else {
                    alert('Error: ' + (res.error || 'Failed to save location'));
                }
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.textContent = 'Save Location';
                alert('Error: ' + err.message);
            });
        }

        // ZIP code auto-lookup
        document.getElementById('location-zip').addEventListener('blur', function() {
            var zip = this.value.trim();
            if (zip.length >= 5) {
                fetch('/api/location.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'lookup', zip_code: zip })
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success && res.latitude && res.longitude) {
                        setLocationMarker(res.latitude, res.longitude);
                    }
                });
            }
        });

        // Initialize map when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(initLocationMap, 100);
        });
    </script>
</body>
</html>
