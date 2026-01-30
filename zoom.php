<?php
/**
 * OpenSeaDragon Zoom Viewer for Painttwits
 *
 * High-resolution deep zoom viewing of artwork
 * URL: /zoom.php?f=filename.jpg
 */

// Load config
$config_file = __DIR__ . '/artist_config.php';
$config = file_exists($config_file) ? require $config_file : [];

$artist_name = $config['name'] ?? 'Artist';
$artist_location = $config['location'] ?? '';

// Get the filename parameter
$filename = isset($_GET['f']) ? basename($_GET['f']) : null;

if (!$filename) {
    header('Location: /');
    exit;
}

// Verify file exists
$uploads_dir = __DIR__ . '/uploads/';
$filepath = $uploads_dir . $filename;

if (!file_exists($filepath)) {
    http_response_code(404);
    die('Artwork not found');
}

// Include DZI generator
require_once __DIR__ . '/generate_dzi.php';

// Check if DZI exists, generate if needed
$baseName = pathinfo($filename, PATHINFO_FILENAME);
$dziPath = '/uploads/dzi/' . $baseName . '.dzi';
$dziFullPath = $uploads_dir . 'dzi/' . $baseName . '.dzi';

$dziReady = file_exists($dziFullPath);
$generatingDzi = false;
$dziError = null;

if (!$dziReady) {
    // Generate DZI on first view
    $result = generateDZI($filename, $uploads_dir);
    $dziReady = $result['success'];
    $dziError = $result['error'] ?? null;
    $generatingDzi = !$dziReady;
}

// Build URLs
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'];
$zoom_url = $base_url . '/zoom.php?f=' . urlencode($filename);
$art_url = $base_url . '/art.php?f=' . urlencode($filename);
$image_url = $base_url . '/uploads/' . $filename;

// Get artwork title from filename
$title = $baseName;
if (preg_match('/^art_[a-f0-9.]+$/i', $title)) {
    $title = 'Untitled';
} else {
    $title = str_replace(['_', '-'], ' ', $title);
    $title = ucwords($title);
}

// Get image dimensions for display
$imageInfo = @getimagesize($filepath);
$imageWidth = $imageInfo[0] ?? 0;
$imageHeight = $imageInfo[1] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/analytics.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($title) ?> - Detail View | <?= htmlspecialchars($artist_name) ?></title>

    <!-- Open Graph -->
    <meta property="og:title" content="<?= htmlspecialchars($title) ?> by <?= htmlspecialchars($artist_name) ?> - Detail View">
    <meta property="og:description" content="Explore artwork details in high resolution">
    <meta property="og:image" content="<?= htmlspecialchars($image_url) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($zoom_url) ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($title) ?> - Detail View">
    <meta name="twitter:image" content="<?= htmlspecialchars($image_url) ?>">

    <link rel="stylesheet" href="assets/css/zoom.css">
</head>
<body>
    <?php if ($dziReady): ?>
    <!-- OpenSeaDragon Viewer -->
    <div id="osd-viewer"></div>

    <!-- Controls Overlay -->
    <div class="zoom-controls">
        <a href="<?= htmlspecialchars($art_url) ?>" class="back-btn">&larr; Back</a>
        <div class="artwork-info">
            <span class="title"><?= htmlspecialchars($title) ?></span>
            <span class="artist">by <?= htmlspecialchars($artist_name) ?></span>
        </div>
        <div class="zoom-hint">
            <span class="desktop">Scroll to zoom &bull; Drag to pan</span>
            <span class="mobile">Pinch to zoom &bull; Drag to pan</span>
        </div>
    </div>

    <!-- Dimension Badge -->
    <div class="dimension-badge">
        <?= number_format($imageWidth) ?> &times; <?= number_format($imageHeight) ?> px
    </div>

    <script src="https://cdn.jsdelivr.net/npm/openseadragon@3.0/build/openseadragon/openseadragon.min.js"></script>
    <script>
        var viewer = OpenSeadragon({
            id: "osd-viewer",
            prefixUrl: "https://cdn.jsdelivr.net/npm/openseadragon@3.0/build/openseadragon/images/",
            tileSources: "<?= $dziPath ?>",

            // Navigator (mini-map)
            showNavigator: true,
            navigatorPosition: "BOTTOM_RIGHT",
            navigatorSizeRatio: 0.15,
            navigatorAutoFade: true,

            // Controls
            showZoomControl: true,
            showHomeControl: true,
            showFullPageControl: true,
            showRotationControl: false,

            // Zoom behavior
            minZoomLevel: 0.5,
            maxZoomPixelRatio: 2,
            defaultZoomLevel: 1,
            visibilityRatio: 0.5,
            constrainDuringPan: true,

            // Animation
            animationTime: 0.3,
            springStiffness: 10,

            // Touch/gesture settings
            gestureSettingsTouch: {
                pinchRotate: false,
                flickEnabled: true,
                flickMinSpeed: 120,
                flickMomentum: 0.25
            },

            // Performance
            immediateRender: true,
            imageLoaderLimit: 5,
            maxImageCacheCount: 200
        });

        // Hide controls after inactivity
        var controlsTimeout;
        var controls = document.querySelector('.zoom-controls');
        var dimensionBadge = document.querySelector('.dimension-badge');

        function showControls() {
            controls.classList.remove('hidden');
            dimensionBadge.classList.remove('hidden');
            clearTimeout(controlsTimeout);
            controlsTimeout = setTimeout(hideControls, 3000);
        }

        function hideControls() {
            controls.classList.add('hidden');
            dimensionBadge.classList.add('hidden');
        }

        document.addEventListener('mousemove', showControls);
        document.addEventListener('touchstart', showControls);
        showControls();

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                window.location.href = '<?= htmlspecialchars($art_url) ?>';
            }
        });
    </script>

    <?php else: ?>
    <!-- Fallback if DZI generation failed -->
    <div class="error-container">
        <h1>Unable to load zoom view</h1>
        <?php if ($dziError): ?>
        <p class="error"><?= htmlspecialchars($dziError) ?></p>
        <?php endif; ?>
        <p>The image may be too small for deep zoom or there was a processing error.</p>
        <a href="<?= htmlspecialchars($art_url) ?>" class="btn">View Artwork</a>
    </div>
    <?php endif; ?>
</body>
</html>
