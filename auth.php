<?php
/**
 * OAuth Authentication Handler for Artist Sites
 * Supports both central callback (multi-tenant) and self-hosted (single artist)
 * Email must match approved artist email in artist_config.php
 */

session_start();
require_once __DIR__ . '/security_helpers.php';

// Logging function for auth debugging
function authLog($message, $data = []) {
    $logFile = __DIR__ . '/logs/auth.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $dataStr = $data ? ' | ' . json_encode($data) : '';
    file_put_contents($logFile, "[{$timestamp}] {$message}{$dataStr}\n", FILE_APPEND);
}

// Load artist config
$config_file = __DIR__ . '/artist_config.php';
if (!file_exists($config_file)) {
    die('Artist configuration not found');
}
$config = require $config_file;

// OAuth credentials (from artist config)
$oauth = $config['oauth'] ?? [];
$google_client_id = $oauth['google_client_id'] ?? '';

// Signing secret for verifying auth tokens (must match central server if using central callback)
$signing_secret = $config['auth_signing_secret'] ?? '';

// Site configuration
$site_domain = $config['site_domain'] ?? '';

// Central callback URL - use config if set, otherwise construct from site_url
$central_callback_url = $oauth['callback_url'] ?? '';
if (empty($central_callback_url) && !empty($config['site_url'])) {
    $central_callback_url = rtrim($config['site_url'], '/') . '/auth/callback.php';
}

// Determine current subdomain (for multi-tenant setups)
$host = $_SERVER['HTTP_HOST'];
$subdomain = '';
if ($site_domain && preg_match('/^([a-z0-9-]+)\.' . preg_quote($site_domain, '/') . '$/i', $host, $matches)) {
    $subdomain = strtolower($matches[1]);
}

// For self-hosted instances, use the full domain
if (empty($subdomain)) {
    $subdomain = $host;
}

$provider = $_GET['provider'] ?? '';
$action = $_GET['action'] ?? '';

authLog('Auth request', ['action' => $action, 'provider' => $provider, 'subdomain' => $subdomain]);

// Enforce session timeout on every request (1 hour for security)
$session_timeout = 3600;
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $session_timeout) {
    $_SESSION = [];
    session_destroy();
    session_start();
}

// Determine if this site has Google OAuth configured
$has_google_oauth = !empty($google_client_id);

// Handle different actions
switch ($action) {
    case 'login':
        check_rate_limit('oauth_login', 10);
        if ($has_google_oauth && $provider === 'google') {
            authLog('Initiating OAuth login', ['provider' => $provider]);
            initiateOAuth($provider, $subdomain, $central_callback_url, $google_client_id);
        } else {
            // No Google OAuth configured - redirect to magic link form
            header('Location: /auth.php?action=magic');
            exit;
        }
        break;

    case 'verify':
        authLog('Verifying token');
        verifyToken($signing_secret, $config);
        break;

    case 'magic_verify':
        // Verify a local magic link token
        check_rate_limit('magic_verify', 20);
        verifyMagicToken($signing_secret, $config);
        break;

    case 'logout':
        logout();
        break;

    case 'status':
        status();
        break;

    case 'video_token':
        // Generate token for video API access (authenticated users only)
        getVideoToken($signing_secret, $subdomain);
        break;

    case 'magic':
        // Show magic link request form
        showMagicLinkForm($subdomain, $config);
        break;

    case 'magic_request':
        check_rate_limit('magic_request', 5);
        // Handle magic link request
        requestMagicLink($subdomain, $config);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        exit;
}

/**
 * Initiate OAuth flow - redirect to provider with central callback
 */
function initiateOAuth($provider, $subdomain, $central_callback_url, $google_client_id) {
    // Generate CSRF state token
    $csrf_token = bin2hex(random_bytes(16));
    $_SESSION['oauth_csrf'] = $csrf_token;

    // State includes subdomain and CSRF token: subdomain|csrf_token
    $state = $subdomain . '|' . $csrf_token;

    if ($provider !== 'google') {
        http_response_code(400);
        die('Only Google OAuth is supported');
    }

    if (empty($google_client_id)) {
        die('Google OAuth not configured');
    }

    $params = [
        'client_id' => $google_client_id,
        'redirect_uri' => $central_callback_url . '?provider=google',
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account'
    ];
    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

    header('Location: ' . $auth_url);
    exit;
}

/**
 * Verify signed auth token from central callback
 */
function verifyToken($signing_secret, $config) {
    $token = $_GET['token'] ?? '';

    if (empty($token)) {
        header('Location: /?error=' . urlencode('No auth token received'));
        exit;
    }

    // Parse token: base64_payload.signature
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        header('Location: /?error=' . urlencode('Invalid token format'));
        exit;
    }

    $payload = $parts[0];
    $signature = $parts[1];

    // Verify signature
    $expected_signature = hash_hmac('sha256', $payload, $signing_secret);
    if (!hash_equals($expected_signature, $signature)) {
        header('Location: /?error=' . urlencode('Invalid token signature'));
        exit;
    }

    // Decode payload
    $auth_data = json_decode(base64_decode($payload), true);
    if (!$auth_data) {
        header('Location: /?error=' . urlencode('Invalid token data'));
        exit;
    }

    // Check expiration
    if (isset($auth_data['exp']) && time() > $auth_data['exp']) {
        header('Location: /?error=' . urlencode('Token expired'));
        exit;
    }

    // Verify CSRF token matches the one we stored
    if (isset($_SESSION['oauth_csrf']) && isset($auth_data['csrf'])) {
        if (!hash_equals($_SESSION['oauth_csrf'], $auth_data['csrf'])) {
            header('Location: /?error=' . urlencode('CSRF token mismatch'));
            exit;
        }
    }
    unset($_SESSION['oauth_csrf']);

    // Verify email matches artist config
    $artist_email = strtolower(trim($config['email'] ?? ''));
    $oauth_email = strtolower(trim($auth_data['email'] ?? ''));

    authLog('Email check', ['artist_email' => $artist_email, 'oauth_email' => $oauth_email]);

    if ($artist_email !== $oauth_email) {
        authLog('REJECTED: Email mismatch', ['expected' => $artist_email, 'got' => $oauth_email]);
        header('Location: /?error=' . urlencode('Email does not match. Please login with ' . $artist_email));
        exit;
    }

    authLog('SUCCESS: Login approved', ['email' => $oauth_email]);

    // Success - set session
    $_SESSION['artist_authenticated'] = true;
    $_SESSION['artist_email'] = $oauth_email;
    $_SESSION['artist_name'] = $auth_data['name'] ?? $config['name'] ?? 'Artist';
    $_SESSION['artist_picture'] = $auth_data['picture'] ?? '';
    $_SESSION['oauth_provider'] = $auth_data['provider'] ?? '';
    $_SESSION['login_time'] = time();

    // Redirect to portfolio
    header('Location: /');
    exit;
}

/**
 * Logout - clear session
 */
function logout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    header('Location: /');
    exit;
}

/**
 * Return auth status as JSON
 */
function status() {
    header('Content-Type: application/json');

    if (isset($_SESSION['artist_authenticated']) && $_SESSION['artist_authenticated']) {
        echo json_encode([
            'authenticated' => true,
            'email' => $_SESSION['artist_email'] ?? '',
            'name' => $_SESSION['artist_name'] ?? '',
            'picture' => $_SESSION['artist_picture'] ?? '',
            'provider' => $_SESSION['oauth_provider'] ?? ''
        ]);
    } else {
        echo json_encode(['authenticated' => false]);
    }
    exit;
}

/**
 * Generate a short-lived token for video API access
 * Only for authenticated artists
 */
function getVideoToken($signing_secret, $subdomain) {
    header('Content-Type: application/json');

    // Must be authenticated
    if (!isset($_SESSION['artist_authenticated']) || !$_SESSION['artist_authenticated']) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    // Rate limiting: max 10 tokens per hour per session
    $rate_key = 'video_token_count';
    $rate_window_key = 'video_token_window';
    $current_hour = floor(time() / 3600);

    if (!isset($_SESSION[$rate_window_key]) || $_SESSION[$rate_window_key] !== $current_hour) {
        $_SESSION[$rate_window_key] = $current_hour;
        $_SESSION[$rate_key] = 0;
    }

    if ($_SESSION[$rate_key] >= 10) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded. Try again later.']);
        exit;
    }
    $_SESSION[$rate_key]++;

    // Generate token payload
    $payload = [
        'sub' => $subdomain,
        'email' => $_SESSION['artist_email'] ?? '',
        'iat' => time(),
        'exp' => time() + 3600, // 1 hour expiry
        'type' => 'video_upload'
    ];

    $payload_encoded = base64_encode(json_encode($payload));
    $signature = hash_hmac('sha256', $payload_encoded, $signing_secret);
    $token = $payload_encoded . '.' . $signature;

    echo json_encode([
        'token' => $token,
        'expires_in' => 3600
    ]);
    exit;
}

/**
 * Show magic link request form
 */
function showMagicLinkForm($subdomain, $config) {
    $artist_email = $config['email'] ?? '';
    $site_name = $config['name'] ?? 'Gallery';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <?php include __DIR__ . '/analytics.php'; ?>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Sign In - <?= htmlspecialchars($site_name) ?></title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: #f5f5f5;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .container {
                background: white;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                max-width: 400px;
                width: 100%;
                padding: 40px;
            }
            h1 { font-size: 24px; margin-bottom: 8px; color: #111; }
            .subtitle { color: #666; margin-bottom: 24px; font-size: 15px; }
            .form-group { margin-bottom: 16px; }
            input[type="email"] {
                width: 100%;
                padding: 14px;
                border: 2px solid #e0e0e0;
                border-radius: 8px;
                font-size: 16px;
            }
            input:focus { outline: none; border-color: #111; }
            .btn {
                width: 100%;
                background: #111;
                color: white;
                border: none;
                padding: 14px;
                border-radius: 8px;
                font-size: 16px;
                cursor: pointer;
                margin-top: 8px;
            }
            .btn:hover { background: #333; }
            .btn:disabled { background: #ccc; cursor: not-allowed; }
            .status { margin-top: 16px; font-size: 14px; }
            .status.success { color: #2e7d32; }
            .status.error { color: #c62828; }
            .divider {
                display: flex;
                align-items: center;
                margin: 24px 0;
                color: #999;
            }
            .divider span { padding: 0 16px; font-size: 14px; }
            .divider::before, .divider::after {
                content: '';
                flex: 1;
                height: 1px;
                background: #ddd;
            }
            .google-btn {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
                width: 100%;
                background: white;
                border: 2px solid #ddd;
                padding: 14px;
                border-radius: 8px;
                font-size: 16px;
                cursor: pointer;
                text-decoration: none;
                color: #333;
            }
            .google-btn:hover { border-color: #4285f4; background: #f8f9ff; }
            .google-btn svg { width: 20px; height: 20px; }
            .back-link {
                display: block;
                text-align: center;
                margin-top: 24px;
                color: #666;
                text-decoration: none;
                font-size: 14px;
            }
            .back-link:hover { color: #111; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Sign in to manage your gallery</h1>
            <p class="subtitle">Enter the email associated with this gallery to receive a sign-in link.</p>

            <div class="form-group">
                <input type="email" id="email" placeholder="your@email.com" autocomplete="email">
            </div>
            <button type="button" class="btn" id="send-btn" onclick="requestMagicLink()">Send Sign-In Link</button>
            <div id="status" class="status"></div>

            <div class="divider">
                <span>or</span>
            </div>

            <a href="/auth.php?action=login&provider=google" class="google-btn">
                <svg viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                Continue with Google
            </a>

            <a href="/" class="back-link">&larr; Back to gallery</a>
        </div>

        <script>
        async function requestMagicLink() {
            const email = document.getElementById('email').value.trim();
            const btn = document.getElementById('send-btn');
            const status = document.getElementById('status');

            if (!email || !email.includes('@')) {
                status.className = 'status error';
                status.textContent = 'Please enter a valid email address';
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Sending...';
            status.textContent = '';

            try {
                const response = await fetch('/auth.php?action=magic_request', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email: email })
                });

                const data = await response.json();

                if (data.success) {
                    status.className = 'status success';
                    status.textContent = 'Check your email for the sign-in link!';
                    btn.textContent = 'Sent!';
                } else {
                    status.className = 'status error';
                    status.textContent = data.error || 'Failed to send. Try again.';
                    btn.disabled = false;
                    btn.textContent = 'Send Sign-In Link';
                }
            } catch (e) {
                status.className = 'status error';
                status.textContent = 'Network error. Please try again.';
                btn.disabled = false;
                btn.textContent = 'Send Sign-In Link';
            }
        }

        // Allow Enter key to submit
        document.getElementById('email').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') requestMagicLink();
        });
        </script>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Handle magic link request - sends email directly from this server
 */
function requestMagicLink($subdomain, $config) {
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    $email = isset($input['email']) ? strtolower(trim($input['email'])) : '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email address']);
        exit;
    }

    // Verify email matches this site's artist
    $artist_email = strtolower(trim($config['email'] ?? ''));
    if ($email !== $artist_email) {
        authLog('Magic link rejected: email mismatch', ['expected' => $artist_email, 'got' => $email]);
        // Don't reveal whether the email exists - always show success to prevent enumeration
        echo json_encode(['success' => true, 'message' => 'If this email is associated with this gallery, a sign-in link has been sent.']);
        exit;
    }

    // Generate a signed magic link token
    $signing_secret = $config['auth_signing_secret'] ?? '';
    if (empty($signing_secret)) {
        authLog('Magic link failed: no signing secret configured');
        echo json_encode(['success' => false, 'error' => 'Authentication is not configured. Please check your setup.']);
        exit;
    }

    $payload = [
        'email' => $email,
        'name' => $config['name'] ?? 'Artist',
        'iat' => time(),
        'exp' => time() + 900, // 15 minute expiry
        'type' => 'magic_link',
    ];

    $payload_encoded = base64_encode(json_encode($payload));
    $signature = hash_hmac('sha256', $payload_encoded, $signing_secret);
    $token = $payload_encoded . '.' . $signature;

    // Build verify URL on this site
    $site_url = rtrim($config['site_url'] ?? '', '/');
    if (empty($site_url)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $site_url = $protocol . '://' . $_SERVER['HTTP_HOST'];
    }
    $verify_url = $site_url . '/auth.php?action=magic_verify&token=' . urlencode($token);

    // Send email
    $site_name = $config['site_name'] ?? $config['name'] ?? 'Gallery';
    $subject = "Sign in to {$site_name}";
    $body = "Hi {$config['name']},\n\n";
    $body .= "Click the link below to sign in to your gallery:\n\n";
    $body .= $verify_url . "\n\n";
    $body .= "This link expires in 15 minutes.\n\n";
    $body .= "If you didn't request this, you can ignore this email.\n";

    $headers = [
        'From: ' . $site_name . ' <noreply@' . ($config['site_domain'] ?? $_SERVER['HTTP_HOST']) . '>',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    $mail_sent = @mail($email, $subject, $body, implode("\r\n", $headers));

    if (!$mail_sent) {
        authLog('Magic link email failed', ['email' => $email]);
        echo json_encode(['success' => false, 'error' => 'Failed to send email. Please check your server mail configuration.']);
        exit;
    }

    authLog('Magic link sent', ['email' => $email]);
    echo json_encode(['success' => true, 'message' => 'Check your email for the sign-in link!']);
    exit;
}

/**
 * Verify a magic link token and log the artist in
 */
function verifyMagicToken($signing_secret, $config) {
    $token = $_GET['token'] ?? '';

    if (empty($token) || empty($signing_secret)) {
        header('Location: /?error=' . urlencode('Invalid sign-in link'));
        exit;
    }

    // Parse token: base64_payload.signature
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        header('Location: /?error=' . urlencode('Invalid sign-in link'));
        exit;
    }

    $payload_encoded = $parts[0];
    $signature = $parts[1];

    // Verify signature
    $expected_signature = hash_hmac('sha256', $payload_encoded, $signing_secret);
    if (!hash_equals($expected_signature, $signature)) {
        authLog('Magic link verification failed: bad signature');
        header('Location: /?error=' . urlencode('Invalid or expired sign-in link'));
        exit;
    }

    // Decode payload
    $payload = json_decode(base64_decode($payload_encoded), true);
    if (!$payload) {
        header('Location: /?error=' . urlencode('Invalid sign-in link'));
        exit;
    }

    // Check expiration
    if (isset($payload['exp']) && time() > $payload['exp']) {
        authLog('Magic link expired', ['email' => $payload['email'] ?? '']);
        header('Location: /?error=' . urlencode('This sign-in link has expired. Please request a new one.'));
        exit;
    }

    // Check type
    if (($payload['type'] ?? '') !== 'magic_link') {
        header('Location: /?error=' . urlencode('Invalid sign-in link'));
        exit;
    }

    // Verify email matches config
    $artist_email = strtolower(trim($config['email'] ?? ''));
    $token_email = strtolower(trim($payload['email'] ?? ''));

    if ($artist_email !== $token_email) {
        authLog('Magic link email mismatch', ['expected' => $artist_email, 'got' => $token_email]);
        header('Location: /?error=' . urlencode('This sign-in link is not valid for this gallery'));
        exit;
    }

    authLog('Magic link login SUCCESS', ['email' => $token_email]);

    // Set session
    session_regenerate_id(true);
    $_SESSION['artist_authenticated'] = true;
    $_SESSION['artist_email'] = $token_email;
    $_SESSION['artist_name'] = $payload['name'] ?? $config['name'] ?? 'Artist';
    $_SESSION['artist_picture'] = '';
    $_SESSION['oauth_provider'] = 'magic_link';
    $_SESSION['login_time'] = time();

    header('Location: /');
    exit;
}
