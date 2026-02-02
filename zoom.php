<?php
/**
 * OpenSeaDragon Zoom Viewer for Painttwits
 *
 * High-resolution deep zoom viewing of artwork
 * With floating share buttons, metadata panel, and prev/next navigation
 * URL: /zoom.php?f=filename.jpg or /zoom.php?f=filename.jpg&exhibit=slug
 */

// Load config
$config_file = __DIR__ . '/artist_config.php';
$config = file_exists($config_file) ? require $config_file : [];

$artist_name = $config['name'] ?? 'Artist';
$artist_location = $config['location'] ?? '';
$site_name = $config['site_name'] ?? 'Gallery';
$site_url = $config['site_url'] ?? '';

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
$artwork_url = $art_url; // For share functions

// Load artwork metadata
$meta_file = __DIR__ . '/artwork_meta.json';
$artwork_meta = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : [];
$art_meta = $artwork_meta[$filename] ?? [];

// Title
$pathinfo_f = pathinfo($filename);
if (!empty($art_meta['title'])) {
    $title = $art_meta['title'];
} else {
    $title = $baseName;
    if (preg_match('/^art_[a-f0-9.]+$/i', $title)) {
        $title = 'Untitled';
    } else {
        $title = str_replace(['_', '-'], ' ', $title);
        $title = ucwords($title);
    }
}

$medium = $art_meta['medium'] ?? '';
$dimensions = $art_meta['dimensions'] ?? '';
$price = $art_meta['price'] ?? '';
$artwork_status = $art_meta['status'] ?? 'available';
$status_labels = ['available' => 'Available', 'sold' => 'Sold', 'on_display' => 'On Display', 'pending' => 'Pending', 'not_for_sale' => 'Not For Sale'];
$status_label = $status_labels[$artwork_status] ?? 'Available';

// Get image dimensions for display
$imageInfo = @getimagesize($filepath);
$imageWidth = $imageInfo[0] ?? 0;
$imageHeight = $imageInfo[1] ?? 0;

// Exhibit-scoped navigation
$exhibit_slug = isset($_GET['exhibit']) ? preg_replace('/[^a-z0-9-]/', '', strtolower($_GET['exhibit'])) : '';
$exhibit_title = '';
$in_exhibit = false;
$artworks = [];

if ($exhibit_slug) {
    $exhibits_file = __DIR__ . '/exhibits.json';
    $exhibits = file_exists($exhibits_file) ? json_decode(file_get_contents($exhibits_file), true) : [];
    if (isset($exhibits[$exhibit_slug]) && $exhibits[$exhibit_slug]['status'] === 'published') {
        $in_exhibit = true;
        $exhibit_title = $exhibits[$exhibit_slug]['title'] ?? '';
        foreach ($exhibits[$exhibit_slug]['artworks'] ?? [] as $fname) {
            if (file_exists($uploads_dir . $fname)) {
                $artworks[] = $fname;
            }
        }
    }
}

if (empty($artworks)) {
    $in_exhibit = false;
    $exhibit_slug = '';
    foreach (glob($uploads_dir . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) as $file) {
        $bn = basename($file);
        if (preg_match('/_(?:large|medium|small|social|map)\.[a-z]+$/i', $bn)) continue;
        $artworks[] = $bn;
    }
}

$current_index = array_search($filename, $artworks);
$prev_artwork = ($current_index !== false && $current_index > 0) ? $artworks[$current_index - 1] : null;
$next_artwork = ($current_index !== false && $current_index < count($artworks) - 1) ? $artworks[$current_index + 1] : null;
$nav_suffix = $in_exhibit ? '&exhibit=' . urlencode($exhibit_slug) : '';

// Build metadata line
$meta_parts = [];
if ($medium) $meta_parts[] = htmlspecialchars($medium);
if ($dimensions) $meta_parts[] = htmlspecialchars($dimensions);
if ($price) $meta_parts[] = htmlspecialchars($price);
$meta_line = implode(' · ', $meta_parts);
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

    <!-- Top Controls Bar -->
    <div class="zoom-controls zoom-overlay">
        <a href="/" class="back-btn">&larr; <?= htmlspecialchars($artist_name) ?></a>
        <div class="artwork-info">
            <span class="title"><?= htmlspecialchars($title) ?></span>
            <span class="artist">by <?= htmlspecialchars($artist_name) ?></span>
        </div>
        <div class="zoom-hint">
            <span class="desktop">Scroll to zoom &bull; Drag to pan</span>
            <span class="mobile">Pinch to zoom &bull; Drag to pan</span>
        </div>
    </div>

    <!-- Prev/Next Navigation Arrows -->
    <?php if ($prev_artwork): ?>
    <a href="/zoom.php?f=<?= urlencode($prev_artwork) ?><?= $nav_suffix ?>" class="nav-arrow nav-prev zoom-overlay" aria-label="Previous artwork">&lsaquo;</a>
    <?php endif; ?>
    <?php if ($next_artwork): ?>
    <a href="/zoom.php?f=<?= urlencode($next_artwork) ?><?= $nav_suffix ?>" class="nav-arrow nav-next zoom-overlay" aria-label="Next artwork">&rsaquo;</a>
    <?php endif; ?>

    <!-- Bottom-Left: Info + Share Panel -->
    <div class="info-panel zoom-overlay">
        <?php if ($meta_line): ?>
        <div class="info-meta"><?= $meta_line ?></div>
        <?php endif; ?>
        <?php if ($artwork_status !== 'available'): ?>
        <div class="info-status status-<?= htmlspecialchars($artwork_status) ?>"><?= htmlspecialchars($status_label) ?></div>
        <?php endif; ?>
        <div class="share-row">
            <button onclick="shareBluesky()" class="share-btn" title="Bluesky">
                <svg viewBox="0 0 600 530" width="16" height="16"><path fill="currentColor" d="m136 39c67 50 139 151 164 206 25-55 97-156 164-206 48-36 126-63 126 25 0 18-10 148-16 169-21 73-97 91-164 80 117 20 147 87 82 154-123 127-177-32-191-72-3-9-5-13-5-9 0-4-2 0-5 9-14 40-68 199-191 72-65-67-35-134 82-154-67 11-143-7-164-80-6-21-16-151-16-169 0-88 78-61 126-25z"/></svg>
            </button>
            <button onclick="shareTwitter()" class="share-btn" title="Twitter/X">
                <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
            </button>
            <button onclick="sharePinterest()" class="share-btn" title="Pinterest">
                <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M12 0C5.373 0 0 5.372 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738.098.119.112.224.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12 0-6.628-5.373-12-12-12z"/></svg>
            </button>
            <button onclick="copyLink()" class="share-btn" title="Copy Link">
                <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>
            </button>
        </div>
        <?php if ($in_exhibit): ?>
        <a href="/exhibit/<?= htmlspecialchars($exhibit_slug) ?>" class="info-exhibit">&larr; <?= htmlspecialchars($exhibit_title) ?></a>
        <?php endif; ?>
    </div>

    <!-- Bottom-Right: Dimension Badge -->
    <div class="dimension-badge zoom-overlay">
        <?= number_format($imageWidth) ?> &times; <?= number_format($imageHeight) ?> px
    </div>

    <!-- Toast -->
    <div class="zoom-toast" id="toast"></div>

    <script src="https://cdn.jsdelivr.net/npm/openseadragon@3.0/build/openseadragon/openseadragon.min.js"></script>
    <script>
        var viewer = OpenSeadragon({
            id: "osd-viewer",
            prefixUrl: "https://cdn.jsdelivr.net/npm/openseadragon@3.0/build/openseadragon/images/",
            tileSources: "<?= $dziPath ?>",
            showNavigator: true,
            navigatorPosition: "BOTTOM_RIGHT",
            navigatorSizeRatio: 0.15,
            navigatorAutoFade: true,
            showZoomControl: true,
            showHomeControl: true,
            showFullPageControl: true,
            showRotationControl: false,
            minZoomLevel: 0.5,
            maxZoomPixelRatio: 2,
            defaultZoomLevel: 1,
            visibilityRatio: 0.5,
            constrainDuringPan: true,
            animationTime: 0.3,
            springStiffness: 10,
            gestureSettingsTouch: {
                pinchRotate: false,
                flickEnabled: true,
                flickMinSpeed: 120,
                flickMomentum: 0.25
            },
            immediateRender: true,
            imageLoaderLimit: 5,
            maxImageCacheCount: 200
        });

        // Share data
        var artistName = <?= json_encode($artist_name) ?>;
        var artworkTitle = <?= json_encode($title) ?>;
        var artworkUrl = <?= json_encode($artwork_url) ?>;
        var imageUrl = <?= json_encode($image_url) ?>;

        function showToast(msg) {
            var toast = document.getElementById('toast');
            toast.textContent = msg;
            toast.classList.add('show');
            setTimeout(function() { toast.classList.remove('show'); }, 2000);
        }

        function shareBluesky() {
            var text = '"' + artworkTitle + '" by ' + artistName + ' ' + artworkUrl;
            window.open('https://bsky.app/intent/compose?text=' + encodeURIComponent(text), '_blank', 'width=550,height=420');
        }

        function shareTwitter() {
            var text = '"' + artworkTitle + '" by ' + artistName;
            window.open('https://twitter.com/intent/tweet?text=' + encodeURIComponent(text) + '&url=' + encodeURIComponent(artworkUrl), '_blank', 'width=550,height=420');
        }

        function sharePinterest() {
            var text = artworkTitle + ' by ' + artistName;
            window.open('https://pinterest.com/pin/create/button/?url=' + encodeURIComponent(artworkUrl) + '&media=' + encodeURIComponent(imageUrl) + '&description=' + encodeURIComponent(text), '_blank', 'width=750,height=550');
        }

        function copyLink() {
            navigator.clipboard.writeText(artworkUrl).then(function() { showToast('Link copied!'); });
        }

        // Auto-hide all overlays
        var hideTimeout;
        var overlays = document.querySelectorAll('.zoom-overlay');

        function showOverlays() {
            overlays.forEach(function(el) { el.classList.remove('hidden'); });
            clearTimeout(hideTimeout);
            hideTimeout = setTimeout(hideOverlays, 3000);
        }

        function hideOverlays() {
            overlays.forEach(function(el) { el.classList.add('hidden'); });
        }

        document.addEventListener('mousemove', showOverlays);
        document.addEventListener('touchstart', showOverlays);
        showOverlays();

        // Keyboard navigation
        var prevUrl = <?= json_encode($prev_artwork ? '/zoom.php?f=' . urlencode($prev_artwork) . $nav_suffix : null) ?>;
        var nextUrl = <?= json_encode($next_artwork ? '/zoom.php?f=' . urlencode($next_artwork) . $nav_suffix : null) ?>;

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                window.location.href = <?= json_encode($in_exhibit ? '/exhibit/' . $exhibit_slug : '/') ?>;
            } else if (e.key === 'ArrowLeft' && prevUrl) {
                window.location.href = prevUrl;
            } else if (e.key === 'ArrowRight' && nextUrl) {
                window.location.href = nextUrl;
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
