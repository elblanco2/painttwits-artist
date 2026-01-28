<?php
/**
 * Artist Portfolio Setup Wizard
 *
 * First-run setup for self-hosted artist portfolios.
 * Collects artist info, optionally registers with painttwits.com network,
 * and writes configuration file.
 *
 * This file should be deleted after successful setup.
 */

// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Check if already configured
$config_locations = [
    dirname(__DIR__) . '/artist_config.php',  // Above webroot (preferred)
    __DIR__ . '/artist_config.php',            // In webroot (fallback)
];

foreach ($config_locations as $loc) {
    if (file_exists($loc)) {
        $config = @include $loc;
        if (is_array($config) && !empty($config['email'])) {
            // Already configured - redirect to home
            header('Location: /');
            exit;
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleSetup();
    exit;
}

// Handle network registration callback (polling endpoint)
if (isset($_GET['check_registration'])) {
    checkRegistrationStatus();
    exit;
}

/**
 * Process setup form submission
 */
function handleSetup() {
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        return;
    }

    // Validate required fields
    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $location = trim($input['location'] ?? '');
    $bio = trim($input['bio'] ?? '');
    $site_title = trim($input['site_title'] ?? '') ?: $name . "'s Gallery";
    $join_network = !empty($input['join_network']);

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Name is required']);
        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Valid email is required']);
        return;
    }

    // Detect site URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $site_url = $protocol . '://' . $host;

    // Extract domain for config
    $site_domain = $host;

    // Build initial config
    $config = [
        'site_name' => $site_title,
        'site_domain' => $site_domain,
        'site_url' => $site_url,
        'central_api' => '',
        'api_key' => '',
        'video_tool_url' => '',
        'artist_id' => 1,
        'name' => $name,
        'email' => $email,
        'location' => $location,
        'bio' => $bio,
        'website' => '',
        'oauth' => [
            'google_client_id' => '',
            'callback_url' => '',
        ],
        'auth_signing_secret' => bin2hex(random_bytes(32)),
        'show_prices' => false,
        'contact_form' => true,
        'show_site_badge' => true,
        'painttwits_network' => [
            'enabled' => false,
            'sample_artwork' => '',
        ],
    ];

    // If joining network, initiate registration
    $registration_pending = false;
    $registration_token = null;

    if ($join_network) {
        $result = initiateNetworkRegistration($email, $site_url, $name);
        if ($result['success']) {
            $registration_pending = true;
            $registration_token = $result['token'] ?? null;

            // Store pending registration info
            session_start();
            $_SESSION['setup_pending'] = [
                'config' => $config,
                'registration_token' => $registration_token,
                'email' => $email,
                'initiated_at' => time(),
            ];

            echo json_encode([
                'success' => true,
                'pending_verification' => true,
                'message' => 'Check your email to verify and complete registration.',
                'token' => $registration_token,
            ]);
            return;
        } else {
            // Registration failed but user can still proceed without network
            echo json_encode([
                'success' => false,
                'error' => 'Network registration failed: ' . ($result['error'] ?? 'Unknown error'),
                'can_continue_standalone' => true,
            ]);
            return;
        }
    }

    // Write config file (standalone mode - no network)
    $write_result = writeConfig($config);
    if (!$write_result['success']) {
        echo json_encode(['success' => false, 'error' => $write_result['error']]);
        return;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Setup complete! Your gallery is ready.',
        'config_location' => $write_result['location'],
        'next_step' => 'upload_artwork',
    ]);
}

/**
 * Initiate registration with painttwits.com network
 */
function initiateNetworkRegistration($email, $site_url, $name) {
    $registration_url = 'https://painttwits.com/api/artist/register.php';

    $payload = [
        'email' => $email,
        'site_url' => $site_url,
        'artist_name' => $name,
        'action' => 'initiate',
    ];

    $ch = curl_init($registration_url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['success' => false, 'error' => 'Connection failed: ' . $curl_error];
    }

    if ($http_code !== 200) {
        return ['success' => false, 'error' => 'Server returned status ' . $http_code];
    }

    $result = json_decode($response, true);
    if (!$result) {
        return ['success' => false, 'error' => 'Invalid response from server'];
    }

    return $result;
}

/**
 * Check registration status (called via polling)
 */
function checkRegistrationStatus() {
    header('Content-Type: application/json');

    session_start();
    if (empty($_SESSION['setup_pending'])) {
        echo json_encode(['success' => false, 'error' => 'No pending registration']);
        return;
    }

    $pending = $_SESSION['setup_pending'];
    $token = $pending['registration_token'];

    // Check with painttwits.com
    $check_url = 'https://painttwits.com/api/artist/register.php?action=check&token=' . urlencode($token);

    $ch = curl_init($check_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        echo json_encode(['status' => 'pending']);
        return;
    }

    $result = json_decode($response, true);
    if (!$result) {
        echo json_encode(['status' => 'pending']);
        return;
    }

    if ($result['status'] === 'verified') {
        // Registration complete - update config with network credentials
        $config = $pending['config'];
        $config['central_api'] = 'https://painttwits.com/api';
        $config['api_key'] = $result['api_key'] ?? '';
        $config['painttwits_network']['enabled'] = true;

        // If painttwits provides OAuth credentials, use them
        if (!empty($result['google_client_id'])) {
            $config['oauth']['google_client_id'] = $result['google_client_id'];
            $config['oauth']['callback_url'] = 'https://painttwits.com/auth/callback.php';
        }

        if (!empty($result['auth_signing_secret'])) {
            $config['auth_signing_secret'] = $result['auth_signing_secret'];
        }

        // Write config
        $write_result = writeConfig($config);
        if (!$write_result['success']) {
            echo json_encode(['success' => false, 'error' => $write_result['error']]);
            return;
        }

        // Clear pending session
        unset($_SESSION['setup_pending']);

        echo json_encode([
            'status' => 'complete',
            'success' => true,
            'message' => 'Setup complete! Your gallery is connected to the painttwits network.',
            'config_location' => $write_result['location'],
        ]);
        return;
    }

    // Still pending
    echo json_encode(['status' => 'pending']);
}

/**
 * Write configuration file
 * Tries above webroot first, falls back to protected directory in webroot
 */
function writeConfig($config) {
    $webroot = __DIR__;
    $above_webroot = dirname($webroot);

    // Write to webroot - this is where all PHP files load config from.
    // The .htaccess already blocks direct web access to artist_config.php.
    $locations = [
        [
            'path' => $webroot . '/artist_config.php',
            'include_path' => __DIR__ . '/artist_config.php',
            'name' => 'webroot',
        ],
    ];

    foreach ($locations as $loc) {
        $dir = dirname($loc['path']);

        // Check if directory is writable
        if (!is_writable($dir)) {
            continue;
        }

        // Generate config file content
        $content = generateConfigFile($config);

        // Write file
        $result = @file_put_contents($loc['path'], $content, LOCK_EX);
        if ($result === false) {
            continue;
        }

        // Verify it was written correctly
        if (!file_exists($loc['path'])) {
            continue;
        }

        // Success!
        return [
            'success' => true,
            'location' => $loc['name'],
            'path' => $loc['path'],
        ];
    }

    return [
        'success' => false,
        'error' => 'Could not write configuration file. Please check directory permissions.',
    ];
}

/**
 * Generate PHP config file content
 */
function generateConfigFile($config) {
    $content = "<?php\n";
    $content .= "/**\n";
    $content .= " * Artist Portfolio Configuration\n";
    $content .= " * Generated by setup wizard on " . date('Y-m-d H:i:s') . "\n";
    $content .= " */\n\n";
    $content .= "return " . varExportPretty($config) . ";\n";

    return $content;
}

/**
 * Pretty-print array for config file
 */
function varExportPretty($var, $indent = '') {
    if (is_array($var)) {
        $indexed = array_keys($var) === range(0, count($var) - 1);
        $lines = [];
        foreach ($var as $key => $value) {
            $keyStr = $indexed ? '' : var_export($key, true) . ' => ';
            $lines[] = $indent . '    ' . $keyStr . varExportPretty($value, $indent . '    ');
        }
        return "[\n" . implode(",\n", $lines) . ",\n" . $indent . ']';
    }
    return var_export($var, true);
}

// Display setup form
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Your Gallery</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 100%;
            overflow: hidden;
        }

        .header {
            background: #111;
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 24px;
            margin-bottom: 8px;
        }

        .header p {
            opacity: 0.8;
            font-size: 14px;
        }

        .form-container {
            padding: 30px;
        }

        .step {
            display: none;
        }

        .step.active {
            display: block;
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 24px;
        }

        .step-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #ddd;
        }

        .step-dot.active {
            background: #667eea;
        }

        .step-dot.completed {
            background: #10b981;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #333;
            font-size: 14px;
        }

        .helper-text {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }

        input[type="text"],
        input[type="email"],
        textarea {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.2s;
        }

        input:focus,
        textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px;
            background: #f8f9ff;
            border-radius: 8px;
            border: 2px solid #e8ebff;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-top: 2px;
            cursor: pointer;
        }

        .checkbox-label {
            flex: 1;
        }

        .checkbox-label strong {
            display: block;
            margin-bottom: 4px;
        }

        .checkbox-label small {
            color: #666;
            font-size: 13px;
            line-height: 1.4;
        }

        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #111;
            color: white;
        }

        .btn-primary:hover {
            background: #333;
        }

        .btn-secondary {
            background: #f0f0f0;
            color: #333;
            margin-top: 10px;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .info {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .verification-status {
            text-align: center;
            padding: 40px 20px;
        }

        .spinner {
            width: 48px;
            height: 48px;
            border: 4px solid #e0e0e0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .detected-info {
            background: #f9fafb;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .detected-info strong {
            color: #667eea;
        }

        .complete-icon {
            width: 64px;
            height: 64px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .complete-icon svg {
            width: 32px;
            height: 32px;
            fill: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Setup Your Gallery</h1>
            <p>Get your artist portfolio running in minutes</p>
        </div>

        <div class="form-container">
            <!-- Step 1: Artist Info -->
            <div class="step active" id="step-1">
                <div class="step-indicator">
                    <div class="step-dot active"></div>
                    <div class="step-dot"></div>
                    <div class="step-dot"></div>
                </div>

                <div class="detected-info">
                    Your gallery URL: <strong id="detected-url"></strong>
                </div>

                <div id="error-1" class="error" style="display: none;"></div>

                <div class="form-group">
                    <label for="name">Your Name *</label>
                    <input type="text" id="name" placeholder="Jane Artist" required>
                </div>

                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input type="email" id="email" placeholder="jane@example.com" required>
                    <p class="helper-text">Used for login and notifications</p>
                </div>

                <div class="form-group">
                    <label for="location">Location</label>
                    <input type="text" id="location" placeholder="Brooklyn, NY">
                </div>

                <button class="btn btn-primary" onclick="nextStep(1)">Continue</button>
            </div>

            <!-- Step 2: Gallery Settings -->
            <div class="step" id="step-2">
                <div class="step-indicator">
                    <div class="step-dot completed"></div>
                    <div class="step-dot active"></div>
                    <div class="step-dot"></div>
                </div>

                <div id="error-2" class="error" style="display: none;"></div>

                <div class="form-group">
                    <label for="site_title">Gallery Name</label>
                    <input type="text" id="site_title" placeholder="Jane's Art Studio">
                    <p class="helper-text">Leave blank to use "[Your Name]'s Gallery"</p>
                </div>

                <div class="form-group">
                    <label for="bio">About You</label>
                    <textarea id="bio" placeholder="Tell visitors about yourself and your art..."></textarea>
                </div>

                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="join_network">
                        <div class="checkbox-label">
                            <strong>Join the painttwits network</strong>
                            <small>Get discovered by collectors. Your gallery will be listed in the painttwits artist directory and you'll get access to shared features like OAuth login and video intros. You keep your own domain.</small>
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary" onclick="nextStep(2)">Continue</button>
                <button class="btn btn-secondary" onclick="prevStep(2)">Back</button>
            </div>

            <!-- Step 3: Processing / Verification -->
            <div class="step" id="step-3">
                <div class="step-indicator">
                    <div class="step-dot completed"></div>
                    <div class="step-dot completed"></div>
                    <div class="step-dot active"></div>
                </div>

                <div id="processing" class="verification-status">
                    <div class="spinner"></div>
                    <h2>Setting up your gallery...</h2>
                    <p style="color: #666; margin-top: 8px;">This will just take a moment.</p>
                </div>

                <div id="verification-pending" class="verification-status" style="display: none;">
                    <div class="spinner"></div>
                    <h2>Check your email</h2>
                    <p style="color: #666; margin-top: 8px;">
                        We sent a verification link to <strong id="verification-email"></strong>.<br>
                        Click the link to complete setup.
                    </p>
                    <p style="color: #999; margin-top: 16px; font-size: 13px;">
                        Waiting for verification...
                    </p>
                    <button class="btn btn-secondary" onclick="skipNetwork()" style="margin-top: 20px;">
                        Skip network registration
                    </button>
                </div>

                <div id="complete" class="verification-status" style="display: none;">
                    <div class="complete-icon">
                        <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                    </div>
                    <h2>Your gallery is ready!</h2>
                    <p id="complete-message" style="color: #666; margin-top: 8px;"></p>
                    <button class="btn btn-primary" onclick="window.location.href='/'" style="margin-top: 20px;">
                        Go to your gallery
                    </button>
                </div>

                <div id="error-3" class="error" style="display: none;"></div>
            </div>
        </div>
    </div>

    <script>
        // Detect and display URL
        document.getElementById('detected-url').textContent = window.location.origin;

        // Form data
        const formData = {
            name: '',
            email: '',
            location: '',
            site_title: '',
            bio: '',
            join_network: false
        };

        let verificationInterval = null;

        function showError(step, message) {
            const el = document.getElementById('error-' + step);
            el.textContent = message;
            el.style.display = 'block';
        }

        function hideError(step) {
            document.getElementById('error-' + step).style.display = 'none';
        }

        function nextStep(current) {
            hideError(current);

            if (current === 1) {
                // Validate step 1
                formData.name = document.getElementById('name').value.trim();
                formData.email = document.getElementById('email').value.trim();
                formData.location = document.getElementById('location').value.trim();

                if (!formData.name) {
                    showError(1, 'Please enter your name');
                    return;
                }
                if (!formData.email || !formData.email.includes('@')) {
                    showError(1, 'Please enter a valid email address');
                    return;
                }

                document.getElementById('step-1').classList.remove('active');
                document.getElementById('step-2').classList.add('active');
            }
            else if (current === 2) {
                // Validate step 2
                formData.site_title = document.getElementById('site_title').value.trim();
                formData.bio = document.getElementById('bio').value.trim();
                formData.join_network = document.getElementById('join_network').checked;

                document.getElementById('step-2').classList.remove('active');
                document.getElementById('step-3').classList.add('active');

                // Start setup
                runSetup();
            }
        }

        function prevStep(current) {
            document.getElementById('step-' + current).classList.remove('active');
            document.getElementById('step-' + (current - 1)).classList.add('active');
        }

        async function runSetup() {
            document.getElementById('processing').style.display = 'block';
            document.getElementById('verification-pending').style.display = 'none';
            document.getElementById('complete').style.display = 'none';
            document.getElementById('error-3').style.display = 'none';

            try {
                const response = await fetch('/setup.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });

                const result = await response.json();

                if (result.success) {
                    if (result.pending_verification) {
                        // Need email verification
                        document.getElementById('processing').style.display = 'none';
                        document.getElementById('verification-pending').style.display = 'block';
                        document.getElementById('verification-email').textContent = formData.email;

                        // Start polling for verification
                        startVerificationPolling();
                    } else {
                        // Setup complete
                        showComplete(result.message);
                    }
                } else {
                    if (result.can_continue_standalone) {
                        // Network failed but can continue
                        document.getElementById('processing').style.display = 'none';
                        showError(3, result.error + ' You can continue without network features.');
                        document.getElementById('error-3').innerHTML +=
                            '<br><button class="btn btn-secondary" onclick="continueStandalone()" style="margin-top: 12px;">Continue without network</button>';
                    } else {
                        document.getElementById('processing').style.display = 'none';
                        showError(3, result.error);
                    }
                }
            } catch (e) {
                document.getElementById('processing').style.display = 'none';
                showError(3, 'Setup failed: ' + e.message);
            }
        }

        function startVerificationPolling() {
            verificationInterval = setInterval(async () => {
                try {
                    const response = await fetch('/setup.php?check_registration=1');
                    const result = await response.json();

                    if (result.status === 'complete') {
                        clearInterval(verificationInterval);
                        showComplete(result.message);
                    }
                } catch (e) {
                    // Ignore polling errors
                }
            }, 3000); // Check every 3 seconds
        }

        function showComplete(message) {
            document.getElementById('processing').style.display = 'none';
            document.getElementById('verification-pending').style.display = 'none';
            document.getElementById('complete').style.display = 'block';
            document.getElementById('complete-message').textContent = message || 'Your gallery is ready. Upload your artwork to get started!';
        }

        function skipNetwork() {
            if (verificationInterval) {
                clearInterval(verificationInterval);
            }
            formData.join_network = false;
            runSetup();
        }

        async function continueStandalone() {
            formData.join_network = false;
            hideError(3);
            document.getElementById('processing').style.display = 'block';
            await runSetup();
        }
    </script>
</body>
</html>
