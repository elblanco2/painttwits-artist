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
$custom_domain = null;
$domain_purchase = null;

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
            $custom_domain = $status['custom_domain'] ?? null;
            $domain_purchase = $status['domain_purchase'] ?? null;
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
            // Step 1: Notify central API (best effort — don't block local deletion)
            if (!empty($central_api) && !empty($api_key)) {
                $artist_id_val = $config['artist_id'] ?? '';
                $central_result = deleteViaCentralApi($central_api, $api_key, $artist_id_val);
                // Log but don't block — artist should always be able to delete locally
            }

            // Step 2: Local cleanup (always runs)
            $result = deleteLocalAccount();
            if ($result['success']) {
                $delete_success = true;
            } else {
                $delete_error = $result['error'] ?? 'Failed to delete local account.';
            }

            if ($delete_success) {
                session_destroy();
                // Show confirmation page and stop
                echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
                <title>Account Deleted</title>
                <style>body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f5f5f5;color:#333;}
                .card{background:#fff;border-radius:12px;padding:48px;max-width:480px;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,0.08);}
                h1{font-size:1.5rem;margin:0 0 16px;}p{color:#666;line-height:1.6;margin:0 0 12px;}
                .check{font-size:3rem;margin-bottom:16px;}
                a{color:#2563eb;text-decoration:none;}a:hover{text-decoration:underline;}</style></head>
                <body><div class="card">
                <div class="check">&#10003;</div>
                <h1>Account Deleted</h1>
                <p>Your account and all artwork have been permanently removed.</p>
                <p>Your email address has been freed &mdash; you can re-register at any time by running the <a href="/setup.php">setup wizard</a> again.</p>
                <p style="margin-top:24px;"><a href="https://painttwits.com">Visit painttwits.com</a></p>
                </div></body></html>';
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
    <?php include __DIR__ . '/analytics.php'; ?>
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

        <!-- Custom Domain -->
        <?php if (!empty($custom_domain)): ?>
        <div class="settings-section" style="background:#f0f7f0;border:1px solid #c0e0c0;border-radius:6px;padding:1rem;">
            <h3 style="margin-top:0;font-size:1rem;">Custom Domain</h3>
            <p style="margin:0;">Your portfolio is also available at <a href="https://<?= htmlspecialchars($custom_domain) ?>" target="_blank" style="color:#228B22;font-weight:bold;"><?= htmlspecialchars($custom_domain) ?></a></p>
        </div>
        <?php elseif (!empty($domain_purchase)): ?>
        <div class="settings-section" style="background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;padding:1rem;">
            <h3 style="margin-top:0;font-size:1rem;color:#92400e;">Custom Domain — Setup in Progress</h3>
            <p style="margin:0;font-size:0.9rem;">Your domain <strong><?= htmlspecialchars($domain_purchase['domain']) ?></strong> is being set up. We'll send you DNS instructions once it's ready.</p>
        </div>
        <?php elseif (!empty($central_api)): ?>
        <div class="settings-section">
            <p style="font-size:0.85rem;color:#666;">Want your own domain? <a href="https://painttwits.com/custom-domain" style="color:#228B22;">Learn about custom domains</a></p>
        </div>
        <?php endif; ?>

        <!-- Multi-Subdomain Info / Upgrade (only for painttwits-managed subdomain artists) -->
        <?php if (!empty($central_api) && !empty($subdomain)): ?>
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
                    <a href="https://painttwits.com/custom-domain" class="btn-upgrade" style="background:#667eea;color:white;text-decoration:none;display:inline-block;text-align:center;">
                        Go Pro - $50
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="upgrade-box">
                <h2>Go Pro</h2>
                <p>Unlimited subdomains + your own custom domain. One-time payment, lifetime access.</p>
                <ul>
                    <li>Unlimited galleries for different styles or collections</li>
                    <li>Use your own domain (yourdomain.com)</li>
                    <li>SSL, DNS setup &amp; redirect all included</li>
                </ul>
                <div class="price-tag">$50</div>
                <a href="https://painttwits.com/custom-domain" class="btn-upgrade" style="text-decoration:none;display:inline-block;text-align:center;">
                    Go Pro
                </a>
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

        <!-- Exhibits -->
        <div class="settings-section">
            <h2>Exhibits</h2>
            <p>Group artworks into curated exhibits, shows, or series.</p>

            <div id="exhibits-list" style="margin-bottom:1.5rem;">
                <p style="color:#999;">Loading exhibits...</p>
            </div>

            <button type="button" id="new-exhibit-btn" onclick="showExhibitForm()" style="padding:0.6rem 1.2rem;background:var(--black, #111);color:var(--white, #fafafa);border:none;border-radius:4px;cursor:pointer;font-size:0.9rem;">+ New Exhibit</button>

            <div id="exhibit-form" style="display:none;margin-top:1.5rem;padding:1.5rem;border:1px solid #ddd;border-radius:8px;">
                <h3 style="margin:0 0 1rem;font-weight:normal;">Create / Edit Exhibit</h3>
                <input type="hidden" id="exhibit-slug" value="">

                <div style="margin-bottom:1rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">Title *</label>
                    <input type="text" id="exhibit-title" style="width:100%;padding:0.5rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;" placeholder="Summer Landscapes">
                </div>

                <div style="margin-bottom:1rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">Description</label>
                    <textarea id="exhibit-description" rows="3" style="width:100%;padding:0.5rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;resize:vertical;" placeholder="A series exploring..."></textarea>
                </div>

                <div style="display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;">
                    <div>
                        <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">Duration</label>
                        <select id="exhibit-duration" onchange="toggleDateFields()" style="padding:0.5rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;">
                            <option value="temporary">Temporary</option>
                            <option value="permanent">Permanent</option>
                        </select>
                    </div>
                    <div id="date-fields">
                        <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">Start Date</label>
                        <input type="date" id="exhibit-start" style="padding:0.5rem;border:1px solid #ccc;border-radius:4px;">
                        <label style="display:block;font-size:0.85rem;margin:0.5rem 0 0.25rem;">End Date</label>
                        <input type="date" id="exhibit-end" style="padding:0.5rem;border:1px solid #ccc;border-radius:4px;">
                    </div>
                </div>

                <div style="display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;">
                    <div style="flex:1;min-width:200px;">
                        <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">Venue</label>
                        <input type="text" id="exhibit-venue" style="width:100%;padding:0.5rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;" placeholder="Studio 42, Brooklyn NY">
                    </div>
                    <div style="flex:1;min-width:200px;">
                        <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">Opening Reception</label>
                        <input type="datetime-local" id="exhibit-reception" style="width:100%;padding:0.5rem;border:1px solid #ccc;border-radius:4px;">
                    </div>
                </div>

                <div id="exhibit-venue-map-section" style="margin-bottom:1rem;display:none;">
                    <span style="font-size:0.85rem;color:#666;cursor:pointer;text-decoration:underline;" onclick="toggleExhibitVenueMap();" id="exhibit-venue-map-toggle">Pin venue on map</span>
                    <div id="exhibit-venue-map" style="height:200px;margin-top:0.5rem;border-radius:4px;display:none;"></div>
                    <input type="hidden" id="exhibit-venue-lat">
                    <input type="hidden" id="exhibit-venue-lng">
                    <p id="exhibit-venue-coords" style="font-size:0.8rem;color:#888;margin-top:0.25rem;display:none;"></p>
                </div>

                <div style="margin-bottom:1rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">Press Release</label>
                    <textarea id="exhibit-press" rows="3" style="width:100%;padding:0.5rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;resize:vertical;" placeholder="Optional press release text..."></textarea>
                </div>

                <div style="margin-bottom:1rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">Status</label>
                    <select id="exhibit-status" style="padding:0.5rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;">
                        <option value="draft">Draft</option>
                        <option value="published">Published</option>
                    </select>
                </div>

                <div style="margin-bottom:1rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.5rem;">Select Artworks (click to toggle) <span id="artwork-count" style="color:#888;"></span></label>
                    <div id="exhibit-artwork-picker" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:8px;max-height:300px;overflow-y:auto;padding:4px;border:1px solid #eee;border-radius:4px;">
                        <p style="color:#999;grid-column:1/-1;">Loading artworks...</p>
                    </div>
                </div>

                <div style="display:flex;gap:0.75rem;">
                    <button type="button" onclick="saveExhibit()" style="padding:0.6rem 1.2rem;background:#228B22;color:white;border:none;border-radius:4px;cursor:pointer;font-size:0.9rem;">Save Exhibit</button>
                    <button type="button" onclick="hideExhibitForm()" style="padding:0.6rem 1.2rem;background:#eee;color:#333;border:none;border-radius:4px;cursor:pointer;font-size:0.9rem;">Cancel</button>
                </div>
                <div id="exhibit-form-result" style="margin-top:0.75rem;"></div>
            </div>
        </div>

        <!-- Danger Zone -->
        <div class="settings-section">
            <?php $confirm_text = $subdomain ?: ($config['site_domain'] ?? $config['email'] ?? ''); ?>
            <div class="danger-zone">
                <h2>Delete Account</h2>
                <p>
                    Permanently delete your account and all artwork. This action cannot be undone.
                    All uploaded images will be deleted<?= !empty($subdomain) ? ' and your subdomain will be released' : '' ?>.
                    <?php if (!empty($central_api)): ?>Your account will be removed from the painttwits network.<?php endif; ?>
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

                    <button type="submit" id="delete-btn" style="display:inline-block;padding:12px 24px;background:#c00;color:#fff;border-radius:4px;border:none;font-size:16px;cursor:pointer;margin-top:8px;">
                        Delete My Account
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="assets/js/theme.js"></script>
    <script>
        (function() {
            var expectedConfirm = '<?= addslashes(strtolower($confirm_text)) ?>';
            var input = document.getElementById('confirm_subdomain');
            var btn = document.getElementById('delete-btn');

            function updateBtn(matched) {
                if (matched) {
                    btn.disabled = false;
                    btn.style.background = '#c00';
                    btn.style.color = '#fff';
                    btn.style.cursor = 'pointer';
                } else {
                    btn.disabled = true;
                    btn.style.background = '#888';
                    btn.style.color = '#ccc';
                    btn.style.cursor = 'not-allowed';
                }
            }

            if (input && btn && expectedConfirm) {
                updateBtn(false);
                input.addEventListener('input', function() {
                    updateBtn(input.value.trim().toLowerCase() === expectedConfirm);
                });
            }
        })();

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
                        (res.can_upgrade ? '<br><br><a href="https://painttwits.com/custom-domain" style="display:inline-block;background:#667eea;color:white;padding:0.5rem 1rem;border-radius:4px;text-decoration:none;">Go Pro - $50</a>' : '') +
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
                body: JSON.stringify({ action: 'get' })
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
            loadExhibits();
            loadArtworkPicker();
        });

        // ===== EXHIBITS =====
        var csrfToken = '<?= addslashes(csrf_token()) ?>';
        var allExhibits = {};
        var selectedArtworks = [];

        function exhibitApi(data) {
            data.csrf_token = csrfToken;
            return fetch('/update_exhibits.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            }).then(function(r) { return r.json(); });
        }

        function loadExhibits() {
            exhibitApi({ action: 'list' }).then(function(res) {
                if (!res.success) return;
                allExhibits = res.exhibits || {};
                renderExhibitList();
            }).catch(function() {
                document.getElementById('exhibits-list').innerHTML = '<p style="color:#999;">Could not load exhibits</p>';
            });
        }

        function renderExhibitList() {
            var list = document.getElementById('exhibits-list');
            var slugs = Object.keys(allExhibits);
            if (slugs.length === 0) {
                list.innerHTML = '<p style="color:#999;">No exhibits yet. Create one to get started.</p>';
                return;
            }
            var html = '';
            slugs.forEach(function(slug) {
                var ex = allExhibits[slug];
                var count = (ex.artworks || []).length;
                var badge = ex.status === 'published' ? '<span style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:3px;font-size:0.75rem;">published</span>' : '<span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:3px;font-size:0.75rem;">draft</span>';
                var cover = (ex.artworks && ex.artworks.length) ? ex.artworks[0] : null;
                var thumbHtml = cover
                    ? '<img src="/uploads/' + encodeURIComponent(cover) + '" style="width:40px;height:40px;object-fit:cover;border-radius:3px;flex-shrink:0;" alt="">'
                    : '<div style="width:40px;height:40px;background:#f0f0f0;border-radius:3px;flex-shrink:0;"></div>';
                var dotColor = ex.status === 'published' ? '#22c55e' : '#aaa';
                html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:0.75rem;border:1px solid #ddd;border-radius:4px;margin-bottom:0.5rem;">';
                html += '<div style="display:flex;align-items:center;gap:0.75rem;">' + thumbHtml + '<div><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + dotColor + ';margin-right:6px;vertical-align:middle;"></span><strong>' + escHtml(ex.title || 'Untitled') + '</strong> ' + badge + '<br><span style="font-size:0.8rem;color:#666;">' + count + ' work' + (count !== 1 ? 's' : '') + (ex.duration === 'permanent' ? ' · permanent' : '') + '</span></div></div>';
                html += '<div style="display:flex;gap:0.5rem;">';
                html += '<button onclick="editExhibit(\'' + slug + '\')" style="padding:4px 10px;border:1px solid #ccc;background:white;border-radius:4px;cursor:pointer;font-size:0.8rem;">edit</button>';
                html += '<a href="/exhibit/' + slug + '" target="_blank" style="padding:4px 10px;border:1px solid #ccc;background:white;border-radius:4px;font-size:0.8rem;text-decoration:none;color:inherit;">view</a>';
                html += '<button onclick="deleteExhibit(\'' + slug + '\')" style="padding:4px 10px;border:1px solid #dcc;background:white;border-radius:4px;cursor:pointer;font-size:0.8rem;color:#c00;">delete</button>';
                html += '</div></div>';
            });
            list.innerHTML = html;
        }

        function escHtml(s) {
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        function loadArtworkPicker() {
            var picker = document.getElementById('exhibit-artwork-picker');
            var uploadsDir = '/uploads/';
            // Fetch artwork list from the gallery (reuse file listing)
            fetch('/?format=json').then(function(r) { return r.text(); }).catch(function() { return ''; });
            // Simpler: just read from DOM or use a direct scan. Since we can't easily, use a helper endpoint.
            // Instead, let's just populate from files we know about via the meta file.
            fetch('/update_meta.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrfToken, filename: '_list_', field: 'title', value: '' })
            }).catch(function() {});
            // Actually, the simplest approach: load artworks via a small AJAX to list uploads
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '/api/list_artworks.php', true);
            xhr.onload = function() {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.artworks) {
                        renderPicker(data.artworks);
                    } else {
                        pickerFallback(picker);
                    }
                } catch(e) {
                    pickerFallback(picker);
                }
            };
            xhr.onerror = function() { pickerFallback(picker); };
            xhr.send();
        }

        function pickerFallback(picker) {
            // Fallback: scan uploads directory via image tags we build from known exhibits
            picker.innerHTML = '<p style="color:#999;grid-column:1/-1;font-size:0.8rem;">Could not load artwork list. Type filenames manually or create the exhibit and edit artworks later.</p>' +
                '<div style="grid-column:1/-1;"><label style="font-size:0.8rem;">Artwork filenames (comma separated):</label>' +
                '<input type="text" id="exhibit-artworks-manual" style="width:100%;padding:0.5rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;" placeholder="painting1.jpg, painting2.jpg"></div>';
        }

        function renderPicker(artworks) {
            var picker = document.getElementById('exhibit-artwork-picker');
            var html = '';
            artworks.forEach(function(a) {
                var fname = a.original || a.filename || a;
                var thumb = a.thumbnail || '/uploads/' + fname;
                html += '<div class="picker-thumb" data-filename="' + escHtml(fname) + '" onclick="togglePickerThumb(this)" style="cursor:pointer;border:2px solid transparent;border-radius:4px;overflow:hidden;position:relative;">';
                html += '<img src="' + escHtml(thumb) + '" style="width:100%;height:80px;object-fit:cover;display:block;" alt="' + escHtml(fname) + '">';
                html += '<div class="picker-check" style="display:none;position:absolute;top:2px;right:2px;background:#228B22;color:white;width:18px;height:18px;border-radius:50%;font-size:12px;text-align:center;line-height:18px;">✓</div>';
                html += '</div>';
            });
            picker.innerHTML = html;
        }

        function togglePickerThumb(el) {
            var fname = el.getAttribute('data-filename');
            var check = el.querySelector('.picker-check');
            var idx = selectedArtworks.indexOf(fname);
            if (idx >= 0) {
                selectedArtworks.splice(idx, 1);
                el.style.borderColor = 'transparent';
                check.style.display = 'none';
            } else {
                selectedArtworks.push(fname);
                el.style.borderColor = '#228B22';
                check.style.display = 'block';
            }
            var countEl = document.getElementById('artwork-count');
            if (countEl) countEl.textContent = selectedArtworks.length ? selectedArtworks.length + ' selected' : '';
        }

        function toggleDateFields() {
            var dur = document.getElementById('exhibit-duration').value;
            document.getElementById('date-fields').style.display = dur === 'permanent' ? 'none' : 'block';
            document.getElementById('exhibit-venue-map-section').style.display = dur === 'permanent' ? 'none' : 'block';
        }

        // Exhibit venue map picker
        var exhibitVenueMap = null;
        var exhibitVenueMarker = null;

        function initExhibitVenueMap() {
            if (exhibitVenueMap) {
                exhibitVenueMap.invalidateSize();
                return;
            }
            if (typeof L === 'undefined') return;
            exhibitVenueMap = L.map('exhibit-venue-map').setView([39.8, -98.5], 4);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap'
            }).addTo(exhibitVenueMap);
            exhibitVenueMap.on('click', function(e) {
                setExhibitVenueMarker(e.latlng.lat, e.latlng.lng);
            });
        }

        function setExhibitVenueMarker(lat, lng) {
            if (!exhibitVenueMap) initExhibitVenueMap();
            if (exhibitVenueMarker) {
                exhibitVenueMarker.setLatLng([lat, lng]);
            } else if (exhibitVenueMap) {
                exhibitVenueMarker = L.marker([lat, lng]).addTo(exhibitVenueMap);
            }
            document.getElementById('exhibit-venue-lat').value = lat;
            document.getElementById('exhibit-venue-lng').value = lng;
            var coords = document.getElementById('exhibit-venue-coords');
            coords.textContent = 'Venue pin: ' + parseFloat(lat).toFixed(4) + ', ' + parseFloat(lng).toFixed(4);
            coords.style.display = 'block';
            if (exhibitVenueMap) exhibitVenueMap.setView([lat, lng], 14);
        }

        function toggleExhibitVenueMap() {
            var mapDiv = document.getElementById('exhibit-venue-map');
            if (mapDiv.style.display === 'none') {
                mapDiv.style.display = 'block';
                initExhibitVenueMap();
            } else {
                mapDiv.style.display = 'none';
            }
        }

        function showExhibitForm(slug) {
            var form = document.getElementById('exhibit-form');
            form.style.display = 'block';
            // Reset
            document.getElementById('exhibit-slug').value = '';
            document.getElementById('exhibit-title').value = '';
            document.getElementById('exhibit-description').value = '';
            document.getElementById('exhibit-duration').value = 'temporary';
            document.getElementById('exhibit-start').value = '';
            document.getElementById('exhibit-end').value = '';
            document.getElementById('exhibit-venue').value = '';
            document.getElementById('exhibit-venue-lat').value = '';
            document.getElementById('exhibit-venue-lng').value = '';
            document.getElementById('exhibit-venue-coords').style.display = 'none';
            document.getElementById('exhibit-venue-map').style.display = 'none';
            if (exhibitVenueMarker) { exhibitVenueMap.removeLayer(exhibitVenueMarker); exhibitVenueMarker = null; }
            document.getElementById('exhibit-reception').value = '';
            document.getElementById('exhibit-press').value = '';
            document.getElementById('exhibit-status').value = 'draft';
            selectedArtworks = [];
            document.querySelectorAll('.picker-thumb').forEach(function(el) {
                el.style.borderColor = 'transparent';
                el.querySelector('.picker-check').style.display = 'none';
            });
            var countEl = document.getElementById('artwork-count');
            if (countEl) countEl.textContent = '';
            toggleDateFields();
            document.getElementById('exhibit-form-result').innerHTML = '';
            form.scrollIntoView({ behavior: 'smooth' });
        }

        function editExhibit(slug) {
            showExhibitForm();
            var ex = allExhibits[slug];
            if (!ex) return;
            document.getElementById('exhibit-slug').value = slug;
            document.getElementById('exhibit-title').value = ex.title || '';
            document.getElementById('exhibit-description').value = ex.description || '';
            document.getElementById('exhibit-duration').value = ex.duration || 'temporary';
            document.getElementById('exhibit-start').value = ex.start_date || '';
            document.getElementById('exhibit-end').value = ex.end_date || '';
            document.getElementById('exhibit-venue').value = ex.venue || '';
            document.getElementById('exhibit-venue-lat').value = ex.venue_lat || '';
            document.getElementById('exhibit-venue-lng').value = ex.venue_lng || '';
            if (ex.venue_lat && ex.venue_lng) {
                setExhibitVenueMarker(ex.venue_lat, ex.venue_lng);
                document.getElementById('exhibit-venue-map').style.display = 'block';
                initExhibitVenueMap();
            }
            document.getElementById('exhibit-reception').value = ex.opening_reception || '';
            document.getElementById('exhibit-press').value = ex.press_release || '';
            document.getElementById('exhibit-status').value = ex.status || 'draft';
            selectedArtworks = (ex.artworks || []).slice();
            // Highlight selected thumbs
            document.querySelectorAll('.picker-thumb').forEach(function(el) {
                var fname = el.getAttribute('data-filename');
                if (selectedArtworks.indexOf(fname) >= 0) {
                    el.style.borderColor = '#228B22';
                    el.querySelector('.picker-check').style.display = 'block';
                }
            });
            toggleDateFields();
            var countEl = document.getElementById('artwork-count');
            if (countEl) countEl.textContent = selectedArtworks.length ? selectedArtworks.length + ' selected' : '';
        }

        function hideExhibitForm() {
            document.getElementById('exhibit-form').style.display = 'none';
        }

        function saveExhibit() {
            var slug = document.getElementById('exhibit-slug').value;
            var title = document.getElementById('exhibit-title').value.trim();
            var resultDiv = document.getElementById('exhibit-form-result');
            var errors = [];

            // Validate title
            if (!title) errors.push('Title is required');

            // Validate dates
            var duration = document.getElementById('exhibit-duration').value;
            var startDate = document.getElementById('exhibit-start').value;
            var endDate = document.getElementById('exhibit-end').value;
            var reception = document.getElementById('exhibit-reception').value;
            if (duration === 'temporary') {
                if (startDate && endDate && new Date(endDate) < new Date(startDate)) {
                    errors.push('End date must be after start date');
                }
                if (reception && startDate && new Date(reception) < new Date(startDate)) {
                    errors.push('Reception date must be on or after start date');
                }
            }

            if (errors.length > 0) {
                resultDiv.innerHTML = '<span style="color:#c00;">' + errors.join('<br>') + '</span>';
                return;
            }

            // Check for manual artworks input (fallback)
            var manualInput = document.getElementById('exhibit-artworks-manual');
            var arts = selectedArtworks.length > 0 ? selectedArtworks : [];
            if (manualInput && manualInput.value.trim()) {
                arts = manualInput.value.split(',').map(function(s) { return s.trim(); }).filter(Boolean);
            }

            var data = {
                action: slug ? 'update' : 'create',
                title: title,
                description: document.getElementById('exhibit-description').value,
                duration: document.getElementById('exhibit-duration').value,
                start_date: document.getElementById('exhibit-start').value || null,
                end_date: document.getElementById('exhibit-end').value || null,
                venue: document.getElementById('exhibit-venue').value,
                venue_lat: document.getElementById('exhibit-venue-lat').value || null,
                venue_lng: document.getElementById('exhibit-venue-lng').value || null,
                opening_reception: document.getElementById('exhibit-reception').value || null,
                press_release: document.getElementById('exhibit-press').value,
                status: document.getElementById('exhibit-status').value,
                artworks: arts,
                cover: arts.length > 0 ? arts[0] : null
            };
            if (slug) data.slug = slug;

            resultDiv.innerHTML = '<span style="color:#666;">Saving...</span>';

            exhibitApi(data).then(function(res) {
                if (res.success) {
                    resultDiv.innerHTML = '<span style="color:#228B22;">Saved!</span>';
                    hideExhibitForm();
                    loadExhibits();
                } else {
                    resultDiv.innerHTML = '<span style="color:#c00;">Error: ' + escHtml(res.error || 'Unknown') + '</span>';
                }
            }).catch(function(err) {
                resultDiv.innerHTML = '<span style="color:#c00;">Error: ' + err.message + '</span>';
            });
        }

        function deleteExhibit(slug) {
            var ex = allExhibits[slug];
            if (!confirm('Delete "' + (ex ? ex.title : slug) + '"? This cannot be undone.')) return;
            exhibitApi({ action: 'delete', slug: slug }).then(function(res) {
                if (res.success) loadExhibits();
                else alert('Error: ' + (res.error || 'Unknown'));
            });
        }
    </script>
</body>
</html>
