<?php
/**
 * Individual Artwork Page
 * Displays a single artwork with proper OG/Twitter meta tags for social sharing
 * URL: /art.php?f=filename.jpg or /art.php?f=art_abc123.jpg
 */

session_start();
require_once __DIR__ . '/security_helpers.php';

$is_authenticated = isset($_SESSION['artist_authenticated']) && $_SESSION['artist_authenticated'];

// Load config (supports both artist_config.php and config.json formats)
$config_php = __DIR__ . '/artist_config.php';
$config_json = __DIR__ . '/config.json';

if (file_exists($config_php)) {
    $config = require $config_php;
    $artist_name = $config['name'] ?? 'Artist';
    $artist_location = $config['location'] ?? '';
} elseif (file_exists($config_json)) {
    $config = json_decode(file_get_contents($config_json), true);
    $artist_name = $config['artist_name'] ?? $config['name'] ?? 'Artist';
    $artist_location = $config['location'] ?? '';
} else {
    http_response_code(500);
    die('Site not configured');
}

// Site branding from config (white-label support)
$site_name = $config['site_name'] ?? 'Gallery';
$site_domain = $config['site_domain'] ?? '';
$site_url = $config['site_url'] ?? '';
$video_tool_url = $config['video_tool_url'] ?? '';

// Get the filename parameter
$filename = isset($_GET['f']) ? basename($_GET['f']) : null;

if (!$filename) {
    // No artwork specified, redirect to gallery
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

// Build URLs
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'];
$artwork_url = $base_url . '/art.php?f=' . urlencode($filename);
$image_url = $base_url . '/uploads/' . $filename;

// Check for social-optimized version (1200x630)
$pathinfo = pathinfo($filename);
$social_filename = $pathinfo['filename'] . '_social.' . $pathinfo['extension'];
$social_filepath = $uploads_dir . $social_filename;

// For OG image, prefer _social version if it exists, otherwise use _large or original
if (file_exists($social_filepath)) {
    $og_image = $base_url . '/uploads/' . $social_filename;
} else {
    // Try _large version
    $large_filename = $pathinfo['filename'] . '_large.' . $pathinfo['extension'];
    if (file_exists($uploads_dir . $large_filename)) {
        $og_image = $base_url . '/uploads/' . $large_filename;
    } else {
        $og_image = $image_url;
    }
}

// Get artwork title - check metadata first, then fall back to filename
$meta_file = __DIR__ . '/artwork_meta.json';
$artwork_meta = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : [];

// Get artwork status
$artwork_status = $artwork_meta[$filename]['status'] ?? 'available';
$status_labels = ['available' => 'Available', 'sold' => 'Sold', 'on_display' => 'On Display', 'pending' => 'Pending', 'not_for_sale' => 'Not For Sale'];
$status_label = $status_labels[$artwork_status] ?? 'Available';

if (isset($artwork_meta[$filename]['title']) && !empty($artwork_meta[$filename]['title'])) {
    // Use title from metadata (set via email upload or manual edit)
    $title = $artwork_meta[$filename]['title'];
} else {
    // Fall back to filename-based title
    $title = $pathinfo['filename'];
    if (preg_match('/^art_[a-f0-9.]+$/i', $title)) {
        $title = 'Untitled';
    } else {
        // Clean up the title
        $title = str_replace(['_', '-'], ' ', $title);
        $title = ucwords($title);
    }
}

// Extract all metadata fields
$art_meta = $artwork_meta[$filename] ?? [];
$medium = $art_meta['medium'] ?? '';
$dimensions = $art_meta['dimensions'] ?? '';
$price = $art_meta['price'] ?? '';
$description = $art_meta['description'] ?? '';
$tags = $art_meta['tags'] ?? [];

// Build meta content
$og_title = $title . ' by ' . $artist_name;
$og_description = 'Artwork by ' . $artist_name;
if ($site_name) {
    $og_description .= ' on ' . $site_name;
}
if ($artist_location) {
    $og_description .= ' - ' . $artist_location;
}

// Check for exhibit context
$exhibit_slug = isset($_GET['exhibit']) ? preg_replace('/[^a-z0-9-]/', '', strtolower($_GET['exhibit'])) : '';
$exhibit_title = '';
$in_exhibit = false;

// Get all artworks for navigation
$artworks = [];

if ($exhibit_slug) {
    // Exhibit-scoped navigation
    $exhibits_file = __DIR__ . '/exhibits.json';
    $exhibits = file_exists($exhibits_file) ? json_decode(file_get_contents($exhibits_file), true) : [];
    if (isset($exhibits[$exhibit_slug]) && $exhibits[$exhibit_slug]['status'] === 'published') {
        $in_exhibit = true;
        $exhibit_title = $exhibits[$exhibit_slug]['title'] ?? '';
        $exhibit_artworks = $exhibits[$exhibit_slug]['artworks'] ?? [];
        // Filter to only existing files
        foreach ($exhibit_artworks as $fname) {
            if (file_exists($uploads_dir . $fname)) {
                $artworks[] = $fname;
            }
        }
    }
}

// Fall back to all artworks if not in exhibit or exhibit not found
if (empty($artworks)) {
    $in_exhibit = false;
    $exhibit_slug = '';
    foreach (glob($uploads_dir . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) as $file) {
        $basename = basename($file);
        // Skip resized versions
        if (preg_match('/_(?:large|medium|small|social)\.[a-z]+$/i', $basename)) {
            continue;
        }
        $artworks[] = $basename;
    }
}

// Find current position and neighbors
$current_index = array_search($filename, $artworks);
$prev_artwork = ($current_index !== false && $current_index > 0) ? $artworks[$current_index - 1] : null;
$next_artwork = ($current_index !== false && $current_index < count($artworks) - 1) ? $artworks[$current_index + 1] : null;

// Build nav URL suffix for exhibit context
$nav_suffix = $in_exhibit ? '&exhibit=' . urlencode($exhibit_slug) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/analytics.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($og_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($og_description) ?>">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?= htmlspecialchars($artwork_url) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($og_title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($og_description) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($og_image) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:site_name" content="<?= htmlspecialchars($site_name) ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($og_title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($og_description) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($og_image) ?>">
    <meta name="twitter:image:alt" content="<?= htmlspecialchars($og_title) ?><?= $site_name ? ' - artwork on ' . htmlspecialchars($site_name) : '' ?>">
    <?php if ($is_authenticated): ?>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <?php endif; ?>

    <style>
        /* Reset and base - standalone page, don't inherit subdomain styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            /* Light mode (default) */
            --bg-primary: #fafafa;
            --bg-secondary: #f0f0f0;
            --bg-tertiary: #e5e5e5;
            --text-primary: #111;
            --text-secondary: #555;
            --text-muted: #888;
            --accent: #4a9eff;
            --border: #ddd;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg-primary: #111;
                --bg-secondary: #1a1a1a;
                --bg-tertiary: #222;
                --text-primary: #fafafa;
                --text-secondary: #ccc;
                --text-muted: #888;
                --accent: #4a9eff;
                --border: #333;
            }
        }

        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 16px;
            line-height: 1.6;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        .artwork-page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .artwork-hero {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #000;
            min-height: 50vh;
        }

        .artwork-hero img {
            max-width: 100%;
            max-height: 70vh;
            object-fit: contain;
            border-radius: 4px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }

        .artwork-info {
            padding: 24px 20px;
            background: var(--bg-secondary);
            text-align: center;
            border-top: 1px solid var(--border);
        }

        .artwork-info h1 {
            font-size: 1.4rem;
            font-weight: normal;
            margin: 0 0 8px 0;
            color: var(--text-primary);
        }

        .artwork-info .artist {
            font-size: 1rem;
            color: var(--text-muted);
            margin: 0 0 20px 0;
        }

        .artwork-info .artist a {
            color: var(--accent);
            text-decoration: none;
        }

        .artwork-info .artist a:hover {
            text-decoration: underline;
        }

        .artwork-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }

        .artwork-actions button,
        .artwork-actions a {
            padding: 10px 16px;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: transform 0.1s, opacity 0.1s, box-shadow 0.1s;
            font-weight: 500;
        }

        .artwork-actions button:hover,
        .artwork-actions a:hover {
            transform: scale(1.03);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .artwork-actions button:active,
        .artwork-actions a:active {
            transform: scale(0.98);
        }

        /* Status dot - positioned at far right like a gallery wall label */
        .status-indicator {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.8);
            box-shadow: 0 1px 4px rgba(0,0,0,0.3);
            position: relative;
            cursor: default;
            padding: 0;
            flex-shrink: 0;
            margin-left: 0;
        }
        .status-indicator::after {
            content: attr(data-label);
            position: absolute;
            left: 28px;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0,0,0,0.8);
            color: #fff;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s;
        }
        .status-indicator:hover::after {
            opacity: 1;
        }
        .status-available { background-color: #22c55e; }
        .status-sold { background-color: #ef4444; }
        .status-on_display { background-color: #f97316; }
        .status-pending { background-color: #f59e0b; }
        .status-not_for_sale { background-color: transparent; border-color: #ccc; }

        /* Brand-colored buttons with good contrast */
        .artwork-actions .btn-twitter {
            background: #000;
            color: #fff;
            border: 1px solid #333;
        }

        .artwork-actions .btn-bluesky {
            background: #0085ff;
            color: #fff;
        }

        .artwork-actions .btn-pinterest {
            background: #e60023;
            color: #fff;
        }

        .artwork-actions .btn-copy {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }

        .artwork-actions .btn-video {
            background: linear-gradient(135deg, #ff0050, #ff4d4d);
            color: #fff;
        }

        .artwork-actions .btn-zoom {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            text-decoration: none;
        }

        .artwork-actions .btn-zoom:hover {
            text-decoration: none;
        }

        .artwork-nav {
            display: flex;
            justify-content: space-between;
            padding: 16px 20px;
            background: var(--bg-tertiary);
            border-top: 1px solid var(--border);
        }

        .artwork-nav a {
            color: var(--accent);
            text-decoration: none;
            font-size: 0.9rem;
        }

        .artwork-nav a:hover {
            text-decoration: underline;
        }
        .artwork-nav .disabled {
            color: var(--text-muted);
            pointer-events: none;
        }
        .back-to-gallery {
            text-align: center;
            padding: 16px;
            background: var(--bg-tertiary);
            border-top: 1px solid var(--border);
        }
        .back-to-gallery a {
            color: var(--accent);
            text-decoration: none;
            font-size: 1rem;
        }
        .back-to-gallery a:hover {
            text-decoration: underline;
        }

        /* Discover more artists section */
        .discover-more {
            text-align: center;
            padding: 20px;
            background: var(--bg-secondary);
            border-top: 1px solid var(--border);
        }
        .discover-more p {
            color: var(--text-muted);
            margin: 0 0 12px 0;
            font-size: 0.9rem;
        }
        .discover-more a {
            display: inline-block;
            background: var(--accent);
            color: white;
            padding: 12px 24px;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 500;
            transition: background 0.2s, transform 0.1s;
        }
        .discover-more a:hover {
            background: #3a8eef;
            transform: scale(1.02);
        }

        /* Swipe hint */
        .swipe-hint {
            text-align: center;
            padding: 8px;
            background: var(--bg-tertiary);
            color: var(--text-muted);
            font-size: 0.8rem;
        }

        /* Swipe animation */
        .artwork-hero.swiping-left {
            animation: swipeLeft 0.3s ease-out;
        }
        .artwork-hero.swiping-right {
            animation: swipeRight 0.3s ease-out;
        }
        @keyframes swipeLeft {
            0% { transform: translateX(0); opacity: 1; }
            100% { transform: translateX(-100px); opacity: 0; }
        }
        @keyframes swipeRight {
            0% { transform: translateX(0); opacity: 1; }
            100% { transform: translateX(100px); opacity: 0; }
        }

        /* Toast notification */
        .toast {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--bg-tertiary);
            color: var(--text-primary);
            padding: 12px 24px;
            border-radius: 8px;
            border: 1px solid var(--border);
            opacity: 0;
            transition: transform 0.3s, opacity 0.3s;
            z-index: 1000;
        }
        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }

        /* SVG icons */
        .artwork-actions svg {
            width: 16px;
            height: 16px;
        }

        /* Metadata details */
        .artwork-details {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin: 0 0 12px 0;
        }
        .artwork-details .detail-sep::before {
            content: '\00b7';
            margin: 0 8px;
            color: var(--text-muted);
        }
        .artwork-description {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin: 0 0 16px 0;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.5;
        }
        .artwork-tags-display {
            margin: 0 0 16px 0;
        }
        .artwork-tags-display .tag-pill {
            display: inline-block;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            margin: 2px;
            text-decoration: none;
        }

        /* Editable fields */
        .editable-field {
            background: transparent;
            border: 1px dashed var(--border);
            border-radius: 4px;
            padding: 4px 8px;
            font-family: inherit;
            font-size: inherit;
            color: var(--text-primary);
            text-align: center;
            width: auto;
        }
        .editable-field:focus {
            border-color: var(--accent);
            outline: none;
            background: var(--bg-tertiary);
        }
        .editable-field::placeholder {
            color: var(--text-muted);
            font-style: italic;
        }
        input.editable-field {
            display: inline-block;
        }
        textarea.editable-field {
            display: block;
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            min-height: 60px;
            resize: vertical;
            text-align: left;
        }
        .edit-meta-row {
            margin: 8px 0;
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .edit-meta-row .detail-sep::before {
            content: '\00b7';
            margin: 0 4px;
            color: var(--text-muted);
        }
        .edit-status-row {
            margin: 8px 0 16px 0;
        }
        .edit-status-row select {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 4px 8px;
            font-family: inherit;
            font-size: 0.85rem;
        }
        .save-indicator {
            color: var(--text-muted);
            font-size: 0.75rem;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .save-indicator.show {
            opacity: 1;
        }

        /* Mobile responsive */
        @media (max-width: 480px) {
            .artwork-actions {
                gap: 8px;
            }
            .artwork-actions button {
                padding: 8px 12px;
                font-size: 0.8rem;
            }
            .artwork-actions svg {
                width: 14px;
                height: 14px;
            }
        }
    </style>
</head>
<body class="artwork-page">
    <div class="artwork-hero">
        <img src="<?= htmlspecialchars($image_url) ?>" alt="<?= htmlspecialchars($title) ?> by <?= htmlspecialchars($artist_name) ?>">
    </div>

    <div class="artwork-info">
        <?php if ($is_authenticated): ?>
        <!-- Editable view -->
        <h1><input type="text" class="editable-field" id="edit-title" value="<?= htmlspecialchars($title) ?>" placeholder="Title" data-field="title"></h1>
        <p class="artist">by <a href="/"><?= htmlspecialchars($artist_name) ?></a></p>
        <div class="edit-meta-row">
            <input type="text" class="editable-field" id="edit-medium" value="<?= htmlspecialchars($medium) ?>" placeholder="Medium (e.g. Oil on canvas)" data-field="medium" size="20">
            <span class="detail-sep"></span>
            <input type="text" class="editable-field" id="edit-dimensions" value="<?= htmlspecialchars($dimensions) ?>" placeholder="Dimensions (e.g. 24 x 36)" data-field="dimensions" size="16">
            <span class="detail-sep"></span>
            <input type="text" class="editable-field" id="edit-price" value="<?= htmlspecialchars($price) ?>" placeholder="Price" data-field="price" size="10">
        </div>
        <div style="margin:8px auto;max-width:600px;">
            <textarea class="editable-field" id="edit-description" placeholder="Description" data-field="description"><?= htmlspecialchars($description) ?></textarea>
        </div>
        <div class="edit-meta-row">
            <input type="text" class="editable-field" id="edit-tags" value="<?= htmlspecialchars(implode(', ', $tags)) ?>" placeholder="Tags (comma separated)" data-field="tags" size="30">
        </div>
        <div class="edit-status-row">
            <select id="edit-status" data-field="status">
                <option value="available"<?= $artwork_status === 'available' ? ' selected' : '' ?>>&#x1F7E2; Available</option>
                <option value="sold"<?= $artwork_status === 'sold' ? ' selected' : '' ?>>&#x1F534; Sold</option>
                <option value="on_display"<?= $artwork_status === 'on_display' ? ' selected' : '' ?>>&#x1F7E0; On Display</option>
                <option value="pending"<?= $artwork_status === 'pending' ? ' selected' : '' ?>>&#x1F7E1; Pending</option>
                <option value="not_for_sale"<?= $artwork_status === 'not_for_sale' ? ' selected' : '' ?>>&#x26AA; Not For Sale</option>
            </select>
            <span class="save-indicator" id="save-indicator">Saved</span>
        </div>
        <?php else: ?>
        <!-- Public view -->
        <h1><?= htmlspecialchars($title) ?></h1>
        <p class="artist">by <a href="/"><?= htmlspecialchars($artist_name) ?></a></p>
        <?php
        $details = [];
        if (!empty($medium)) $details[] = htmlspecialchars($medium);
        if (!empty($dimensions)) $details[] = htmlspecialchars($dimensions);
        if (!empty($price)) $details[] = '$' . htmlspecialchars($price);
        ?>
        <?php if (!empty($details)): ?>
        <p class="artwork-details"><?= implode('<span class="detail-sep"></span>', $details) ?></p>
        <?php endif; ?>
        <?php if (!empty($description)): ?>
        <p class="artwork-description"><?= nl2br(htmlspecialchars($description)) ?></p>
        <?php endif; ?>
        <?php if (!empty($tags)): ?>
        <div class="artwork-tags-display">
            <?php foreach ($tags as $tag): ?>
            <span class="tag-pill"><?= htmlspecialchars($tag) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <div class="artwork-actions">
            <button class="btn-bluesky" onclick="shareBluesky()">
                <svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.39 14.39c-.77.77-1.79 1.19-2.89 1.19s-2.12-.42-2.89-1.19c-.77-.77-1.19-1.79-1.19-2.89 0-.37.05-.73.14-1.08l-2.28-.76c-.11.59-.17 1.21-.17 1.84 0 1.66.65 3.22 1.83 4.39 1.17 1.18 2.73 1.83 4.39 1.83s3.22-.65 4.39-1.83c1.18-1.17 1.83-2.73 1.83-4.39 0-.63-.06-1.25-.17-1.84l-2.28.76c.09.35.14.71.14 1.08 0 1.1-.42 2.12-1.19 2.89z"/></svg>
                Bluesky
            </button>
            <button class="btn-twitter" onclick="shareTwitter()">
                <svg viewBox="0 0 24 24"><path fill="currentColor" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                Twitter
            </button>
            <button class="btn-pinterest" onclick="sharePinterest()">
                <svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 0C5.373 0 0 5.372 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738.098.119.112.224.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12 0-6.628-5.373-12-12-12z"/></svg>
                Pinterest
            </button>
            <button class="btn-copy" onclick="copyLink()">
                <svg viewBox="0 0 24 24"><path fill="currentColor" d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>
                Copy Link
            </button>
            <?php if ($video_tool_url): ?>
            <button class="btn-video" onclick="createVideo()">
                <svg viewBox="0 0 24 24"><path fill="currentColor" d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/></svg>
                Create Video
            </button>
            <?php endif; ?>
            <a href="/zoom.php?f=<?= urlencode($filename) ?>" class="btn-zoom">
                <svg viewBox="0 0 24 24"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/><path fill="currentColor" d="M12 10h-2v2H9v-2H7V9h2V7h1v2h2v1z"/></svg>
                Explore Details
            </a>
            <?php if (!$is_authenticated): ?>
            <span class="status-indicator status-<?= htmlspecialchars($artwork_status) ?>" data-label="<?= htmlspecialchars($status_label) ?>"></span>
            <?php endif; ?>
        </div>
    </div>

    <nav class="artwork-nav">
        <?php if ($prev_artwork): ?>
        <a href="/art.php?f=<?= urlencode($prev_artwork) ?><?= $nav_suffix ?>">&larr; Previous</a>
        <?php else: ?>
        <span class="disabled">&larr; Previous</span>
        <?php endif; ?>

        <?php if ($next_artwork): ?>
        <a href="/art.php?f=<?= urlencode($next_artwork) ?><?= $nav_suffix ?>">Next &rarr;</a>
        <?php else: ?>
        <span class="disabled">Next &rarr;</span>
        <?php endif; ?>
    </nav>

    <div class="swipe-hint">
        <?php if ($prev_artwork || $next_artwork): ?>
        swipe left/right to browse artwork
        <?php endif; ?>
    </div>

    <?php if ($in_exhibit): ?>
    <div class="back-to-gallery" style="border-bottom:1px solid var(--border);">
        <a href="/exhibit/<?= htmlspecialchars($exhibit_slug) ?>">&larr; Back to "<?= htmlspecialchars($exhibit_title) ?>"</a>
    </div>
    <?php endif; ?>

    <div class="back-to-gallery">
        <a href="/">View All Artwork by <?= htmlspecialchars($artist_name) ?></a>
    </div>

    <?php if ($site_url): ?>
    <div class="discover-more">
        <p>Discover more artists<?php if ($artist_location): ?> near <?= htmlspecialchars($artist_location) ?><?php endif; ?></p>
        <a href="<?= htmlspecialchars($site_url) ?>/?search=<?= urlencode($artist_location) ?>">Browse Artists on <?= htmlspecialchars($site_name) ?></a>
    </div>
    <?php endif; ?>

    <div class="toast" id="toast"></div>

    <script>
        var artistName = <?= json_encode($artist_name) ?>;
        var artworkTitle = <?= json_encode($title) ?>;
        var artworkUrl = <?= json_encode($artwork_url) ?>;
        var imageUrl = <?= json_encode($image_url) ?>;
        var filename = <?= json_encode($filename) ?>;
        var videoToolUrl = <?= json_encode($video_tool_url) ?>;

        function showToast(msg) {
            var toast = document.getElementById('toast');
            toast.textContent = msg;
            toast.classList.add('show');
            setTimeout(function() {
                toast.classList.remove('show');
            }, 2000);
        }

        function shareTwitter() {
            var text = '"' + artworkTitle + '" by ' + artistName;
            var url = 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(text) + '&url=' + encodeURIComponent(artworkUrl);
            window.open(url, '_blank', 'width=550,height=420');
        }

        function shareBluesky() {
            var text = '"' + artworkTitle + '" by ' + artistName + ' ' + artworkUrl;
            var url = 'https://bsky.app/intent/compose?text=' + encodeURIComponent(text);
            window.open(url, '_blank', 'width=550,height=420');
        }

        function sharePinterest() {
            var text = artworkTitle + ' by ' + artistName;
            var url = 'https://pinterest.com/pin/create/button/?url=' + encodeURIComponent(artworkUrl) + '&media=' + encodeURIComponent(imageUrl) + '&description=' + encodeURIComponent(text);
            window.open(url, '_blank', 'width=750,height=550');
        }

        function copyLink() {
            navigator.clipboard.writeText(artworkUrl).then(function() {
                showToast('Link copied!');
            });
        }

        function createVideo() {
            if (!videoToolUrl) return;
            window.location.href = videoToolUrl + '?image=' + encodeURIComponent(imageUrl) + '&title=' + encodeURIComponent(artworkTitle) + '&artist=' + encodeURIComponent(artistName);
        }

        // Swipe navigation
        var prevArtwork = <?= json_encode($prev_artwork) ?>;
        var nextArtwork = <?= json_encode($next_artwork) ?>;
        var navSuffix = <?= json_encode($nav_suffix) ?>;
        var heroEl = document.querySelector('.artwork-hero');
        var touchStartX = 0;
        var touchStartY = 0;
        var touchEndX = 0;
        var touchEndY = 0;
        var minSwipeDistance = 50;

        heroEl.addEventListener('touchstart', function(e) {
            touchStartX = e.changedTouches[0].screenX;
            touchStartY = e.changedTouches[0].screenY;
        }, { passive: true });

        heroEl.addEventListener('touchend', function(e) {
            touchEndX = e.changedTouches[0].screenX;
            touchEndY = e.changedTouches[0].screenY;
            handleSwipe();
        }, { passive: true });

        function handleSwipe() {
            var deltaX = touchEndX - touchStartX;
            var deltaY = touchEndY - touchStartY;

            // Only trigger if horizontal swipe is greater than vertical (not scrolling)
            if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > minSwipeDistance) {
                if (deltaX > 0 && prevArtwork) {
                    // Swipe right -> previous
                    heroEl.classList.add('swiping-right');
                    setTimeout(function() {
                        window.location.href = '/art.php?f=' + encodeURIComponent(prevArtwork) + navSuffix;
                    }, 200);
                } else if (deltaX < 0 && nextArtwork) {
                    // Swipe left -> next
                    heroEl.classList.add('swiping-left');
                    setTimeout(function() {
                        window.location.href = '/art.php?f=' + encodeURIComponent(nextArtwork) + navSuffix;
                    }, 200);
                }
            }
        }

        // Inline metadata editing
        <?php if ($is_authenticated): ?>
        (function() {
            var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            var saveIndicator = document.getElementById('save-indicator');

            function saveField(field, value) {
                saveIndicator.textContent = 'Saving...';
                saveIndicator.classList.add('show');
                fetch('/update_meta.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ filename: filename, field: field, value: value, csrf_token: csrfToken })
                }).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.success) {
                        saveIndicator.textContent = 'Saved';
                        setTimeout(function() { saveIndicator.classList.remove('show'); }, 1500);
                    } else {
                        saveIndicator.textContent = 'Error';
                        setTimeout(function() { saveIndicator.classList.remove('show'); }, 2000);
                    }
                }).catch(function() {
                    saveIndicator.textContent = 'Error';
                    setTimeout(function() { saveIndicator.classList.remove('show'); }, 2000);
                });
            }

            // Text inputs save on blur
            var fields = ['edit-title', 'edit-medium', 'edit-dimensions', 'edit-price', 'edit-tags'];
            fields.forEach(function(id) {
                var el = document.getElementById(id);
                if (!el) return;
                var orig = el.value;
                el.addEventListener('blur', function() {
                    if (el.value !== orig) {
                        saveField(el.dataset.field, el.value);
                        orig = el.value;
                        if (el.dataset.field === 'title') {
                            artworkTitle = el.value;
                        }
                    }
                });
                el.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') { e.preventDefault(); el.blur(); }
                    if (e.key === 'Escape') { el.value = orig; el.blur(); }
                });
            });

            // Textarea saves on blur
            var descEl = document.getElementById('edit-description');
            if (descEl) {
                var origDesc = descEl.value;
                descEl.addEventListener('blur', function() {
                    if (descEl.value !== origDesc) {
                        saveField('description', descEl.value);
                        origDesc = descEl.value;
                    }
                });
            }

            // Status dropdown saves on change
            var statusEl = document.getElementById('edit-status');
            if (statusEl) {
                statusEl.addEventListener('change', function() {
                    saveField('status', statusEl.value);
                });
            }
        })();
        <?php endif; ?>

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowLeft' && prevArtwork) {
                window.location.href = '/art.php?f=' + encodeURIComponent(prevArtwork) + navSuffix;
            } else if (e.key === 'ArrowRight' && nextArtwork) {
                window.location.href = '/art.php?f=' + encodeURIComponent(nextArtwork) + navSuffix;
            }
        });
    </script>
</body>
</html>
