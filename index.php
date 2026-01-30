<?php
/**
 * Artist Portfolio Template
 * White-label gallery with optional network integration
 */

session_start();
require_once __DIR__ . '/security_helpers.php';

// Load artist config if exists
$config_file = __DIR__ . '/artist_config.php';
$config = file_exists($config_file) ? require $config_file : [];

$artist_name = $config['name'] ?? '{{ARTIST_NAME}}';
$artist_location = $config['location'] ?? '{{ARTIST_LOCATION}}';
$artist_bio = $config['bio'] ?? '';
$artist_email = $config['email'] ?? '{{ARTIST_EMAIL}}';
$artist_website = $config['website'] ?? '';

// Site branding from config (white-label support)
$site_name = $config['site_name'] ?? '';
$site_domain = $config['site_domain'] ?? '';
$site_url = $config['site_url'] ?? '';
$video_tool_url = $config['video_tool_url'] ?? '';
$show_site_badge = $config['show_site_badge'] ?? false;

// Check authentication status
$is_authenticated = isset($_SESSION['artist_authenticated']) && $_SESSION['artist_authenticated'];
$has_google_oauth = !empty($config['oauth']['google_client_id'] ?? '');
$logged_in_name = $_SESSION['artist_name'] ?? '';
$logged_in_picture = $_SESSION['artist_picture'] ?? '';

// Check for login errors
$login_error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';

// Get artworks from local uploads or API
// Load artwork metadata (titles, tags, etc.)
$metadata_file = __DIR__ . '/artwork_meta.json';
$artwork_meta = file_exists($metadata_file) ? json_decode(file_get_contents($metadata_file), true) : [];
if (!is_array($artwork_meta)) $artwork_meta = [];

// Collect all unique tags for the filter
$all_tags = [];

// Only show original files, not the resized versions (_large, _medium, _small)
$artworks = [];
$uploads_dir = __DIR__ . '/uploads';

if (is_dir($uploads_dir)) {
    $files = glob($uploads_dir . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
    foreach ($files as $file) {
        $basename = basename($file);
        // Skip resized versions (contain _large, _medium, _small, or _social before extension)
        if (preg_match('/_(large|medium|small|social|map)\.[^.]+$/', $basename)) {
            continue;
        }
        // Use medium size for display if available, fall back to original
        $name = pathinfo($basename, PATHINFO_FILENAME);
        $ext = pathinfo($basename, PATHINFO_EXTENSION);
        $display_file = $basename;
        if (file_exists($uploads_dir . '/' . $name . '_medium.' . $ext)) {
            $display_file = $name . '_medium.' . $ext;
        }

        // Get title and tags from metadata
        $meta = $artwork_meta[$basename] ?? [];
        $title = $meta['title'] ?? preg_replace('/^art_[a-f0-9.]+$/', 'Untitled', $name);
        $tags = $meta['tags'] ?? [];
        $medium = $meta['medium'] ?? '';
        $dimensions = $meta['dimensions'] ?? '';

        // Collect tags for filter
        foreach ($tags as $tag) {
            if (!in_array($tag, $all_tags)) {
                $all_tags[] = $tag;
            }
        }

        $artworks[] = [
            'filename' => $display_file,
            'original' => $basename,
            'title' => $title,
            'tags' => $tags,
            'status' => $meta['status'] ?? 'available',
            'medium' => $medium,
            'dimensions' => $dimensions
        ];
    }
}

// Sort tags alphabetically
sort($all_tags);

// Check for tag filter
$active_tag = isset($_GET['tag']) ? strtolower(trim($_GET['tag'])) : '';

// Build current page URL for sharing
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$current_url = $protocol . '://' . $_SERVER['HTTP_HOST'];

// Load exhibits
$exhibits_file = __DIR__ . '/exhibits.json';
$all_exhibits = file_exists($exhibits_file) ? json_decode(file_get_contents($exhibits_file), true) : [];
if (!is_array($all_exhibits)) $all_exhibits = [];

// Separate exhibits by time state (only published, unless authenticated)
$current_exhibits = [];
$permanent_exhibits = [];
$past_exhibits = [];
$upcoming_exhibits = [];
$now = time();

foreach ($all_exhibits as $slug => $ex) {
    if ($ex['status'] !== 'published' && !$is_authenticated) continue;
    $dur = $ex['duration'] ?? 'temporary';
    if ($dur === 'permanent') {
        $permanent_exhibits[$slug] = $ex;
    } else {
        $start_ts = !empty($ex['start_date']) ? strtotime($ex['start_date']) : null;
        $end_ts = !empty($ex['end_date']) ? strtotime($ex['end_date']) : null;
        if ($start_ts && $start_ts > $now) {
            $upcoming_exhibits[$slug] = $ex;
        } elseif ($end_ts && $end_ts < $now) {
            $past_exhibits[$slug] = $ex;
        } else {
            $current_exhibits[$slug] = $ex;
        }
    }
}
$has_exhibits = !empty($current_exhibits) || !empty($permanent_exhibits) || !empty($past_exhibits) || !empty($upcoming_exhibits);

// Check if specific artwork is being shared (for OG tags)
$shared_artwork = isset($_GET['art']) ? $_GET['art'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/analytics.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($_SESSION['artist_authenticated'])): ?>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <?php endif; ?>
    <meta name="description" content="<?= htmlspecialchars($artist_name) ?> - Artist Portfolio<?= $site_name ? ' on ' . htmlspecialchars($site_name) : '' ?>">
    <title><?= htmlspecialchars($artist_name) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/themes.css">

    <?php
    // Get artwork for OG image - specific artwork if ?art= param, otherwise first
    $og_image = '';
    $og_title = $artist_name . ' - Artist Portfolio';
    $og_description = 'View artwork by ' . $artist_name . ' on ' . ($site_name ?: 'this gallery') . '';
    $og_url = $current_url;

    // Helper to get social-optimized image URL (1200x630)
    function getSocialImageUrl($original_filename, $base_url) {
        $pathinfo = pathinfo($original_filename);
        $social_filename = $pathinfo['filename'] . '_social.' . $pathinfo['extension'];
        $social_path = __DIR__ . '/uploads/' . $social_filename;

        // Use _social version if it exists, otherwise fall back to original
        if (file_exists($social_path)) {
            return $base_url . '/uploads/' . $social_filename;
        }
        return $base_url . '/uploads/' . $original_filename;
    }

    if ($shared_artwork && !empty($artworks)) {
        // Find the specific artwork being shared
        foreach ($artworks as $art) {
            if ($art['original'] === $shared_artwork) {
                $og_image = getSocialImageUrl($art['original'], $current_url);
                $og_title = $art['title'] . ' by ' . $artist_name;
                $og_description = 'Artwork by ' . $artist_name . ' on ' . ($site_name ?: 'this gallery') . '';
                $og_url = $current_url . '?art=' . urlencode($art['original']);
                break;
            }
        }
    }

    // Fall back to first artwork if no specific one found
    if (empty($og_image) && !empty($artworks)) {
        $first_art = $artworks[0];
        $og_image = getSocialImageUrl($first_art['original'], $current_url);
        $og_description = 'Discover ' . count($artworks) . ' artwork' . (count($artworks) > 1 ? 's' : '') . ' by ' . $artist_name . ' on ' . ($site_name ?: 'this gallery') . '';
    }
    ?>

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars($og_url) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($og_title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($og_description) ?>">
    <?php if ($og_image): ?>
    <meta property="og:image" content="<?= htmlspecialchars($og_image) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="675">
    <?php endif; ?>
    <meta property="og:site_name" content="painttwits">

    <!-- Twitter/X (2025 standards) -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@painttwits">
    <meta name="twitter:title" content="<?= htmlspecialchars($og_title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($og_description) ?>">
    <?php if ($og_image): ?>
    <meta name="twitter:image" content="<?= htmlspecialchars($og_image) ?>">
    <meta name="twitter:image:alt" content="<?= htmlspecialchars($og_title) ?><?= $site_name ? ' - artwork by ' . htmlspecialchars($artist_name) : '' ?>">
    <?php endif; ?>
</head>
<body>
    <header>
        <h1><?= htmlspecialchars($artist_name) ?></h1>
        <?php if ($artist_location): ?>
        <p class="location"><?= htmlspecialchars($artist_location) ?></p>
        <?php endif; ?>

        <?php if ($is_authenticated): ?>
        <div class="auth-status">
            <?php if ($logged_in_picture): ?>
            <img src="<?= htmlspecialchars($logged_in_picture) ?>" alt="" class="auth-avatar">
            <?php endif; ?>
            <span>logged in as <?= htmlspecialchars($logged_in_name) ?></span>
            <a href="settings.php" class="btn-settings">settings</a>
            <a href="auth.php?action=logout" class="btn-logout">logout</a>
        </div>
        <?php else: ?>
        <div class="auth-status">
            <a href="auth.php?action=magic" class="btn-login-small">artist login</a>
        </div>
        <?php endif; ?>
    </header>

    <?php if ($login_error): ?>
    <div class="error-message"><?= $login_error ?><br><small style="opacity:0.8;">Make sure you're using the email address associated with this gallery.</small></div>
    <?php endif; ?>

    <nav>
        <ul>
            <li><a href="#work">work</a></li>
            <?php if ($has_exhibits): ?><li><a href="#exhibits">exhibits</a></li><?php endif; ?>
            <li><a href="#about">about</a></li>
            <li><a href="#contact">contact</a></li>
            <?php if ($site_url): ?><li><a href="<?= htmlspecialchars($site_url) ?>" target="_blank"><?= htmlspecialchars($site_name ?: 'home') ?></a></li><?php endif; ?>
        </ul>
    </nav>

    <main>
        <section id="work" class="section">
            <h2>work</h2>

            <?php if (!empty($all_tags)): ?>
            <div class="tag-filter">
                <a href="?" class="tag-pill <?= empty($active_tag) ? 'active' : '' ?>">all</a>
                <?php foreach ($all_tags as $tag): ?>
                <a href="?tag=<?= urlencode($tag) ?>" class="tag-pill <?= $active_tag === $tag ? 'active' : '' ?>"><?= htmlspecialchars($tag) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (empty($artworks)): ?>
            <p class="empty">no work uploaded yet.</p>

            <?php if ($is_authenticated): ?>
            <p class="hint">drag images here or use the upload form below.</p>
            <div class="dropzone" id="dropzone">
                drop images here
                <span class="hint">or click to select</span>
                <input type="file" id="file-input" multiple accept="image/*" style="display:none;">
            </div>
            <?php else: ?>
            <div class="login-prompt">
                <p>artist login required to upload work</p>
                <div class="login-buttons">
                    <a href="auth.php?action=magic" class="btn-login btn-magic">
                        Sign in with email
                    </a>
                    <?php if ($has_google_oauth): ?>
                    <a href="auth.php?provider=google&action=login" class="btn-login btn-google">
                        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="currentColor" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="currentColor" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="currentColor" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                        Sign in with Google
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="artwork-grid">
                <?php foreach ($artworks as $art):
                    // Filter by tag if active
                    if ($active_tag && !in_array($active_tag, $art['tags'])) {
                        continue;
                    }
                    $art_url = $current_url . '/uploads/' . $art['original'];
                    $share_text = urlencode($art['title'] . ' by ' . $artist_name . ' - found on ' . ($site_name ?: 'this gallery') . '');
                    $share_url = urlencode($current_url);
                    $art_tags = $art['tags'] ?? [];
                ?>
                <div class="artwork" data-filename="<?= htmlspecialchars($art['original']) ?>" data-tags="<?= htmlspecialchars(implode(',', $art_tags)) ?>">
                    <a href="/art.php?f=<?= urlencode($art['original']) ?>" class="artwork-link">
                        <img src="uploads/<?= htmlspecialchars($art['filename']) ?>"
                             alt="<?= htmlspecialchars($art['title']) ?>"
                             loading="lazy">
                    </a>
                    <?php
                    $subtitle_parts = [];
                    if (!empty($art['medium'])) $subtitle_parts[] = htmlspecialchars($art['medium']);
                    if (!empty($art['dimensions'])) $subtitle_parts[] = htmlspecialchars($art['dimensions']);
                    $subtitle = implode(' &middot; ', $subtitle_parts);
                    ?>
                    <div class="artwork-info">
                        <?php if ($is_authenticated): ?>
                        <input type="text" class="title editable-title" value="<?= htmlspecialchars($art['title']) ?>" data-filename="<?= htmlspecialchars($art['original']) ?>" placeholder="Untitled">
                        <?php if ($subtitle): ?><span class="artwork-subtitle"><?= $subtitle ?></span><?php endif; ?>
                        <input type="text" class="editable-tags" value="<?= htmlspecialchars(implode(', ', $art_tags)) ?>" data-filename="<?= htmlspecialchars($art['original']) ?>" placeholder="tags (comma separated)">
                        <select class="status-select" data-filename="<?= htmlspecialchars($art['original']) ?>" onchange="updateStatus(this)">
                            <option value="available"<?= $art['status'] === 'available' ? ' selected' : '' ?>>&#x1F7E2; Available</option>
                            <option value="sold"<?= $art['status'] === 'sold' ? ' selected' : '' ?>>&#x1F534; Sold</option>
                            <option value="on_display"<?= $art['status'] === 'on_display' ? ' selected' : '' ?>>&#x1F7E0; On Display</option>
                            <option value="pending"<?= $art['status'] === 'pending' ? ' selected' : '' ?>>&#x1F7E1; Pending</option>
                            <option value="not_for_sale"<?= $art['status'] === 'not_for_sale' ? ' selected' : '' ?>>&#x26AA; Not For Sale</option>
                        </select>
                        <?php else: ?>
                        <span class="title"><?= htmlspecialchars($art['title']) ?></span>
                        <?php if ($subtitle): ?><span class="artwork-subtitle"><?= $subtitle ?></span><?php endif; ?>
                        <?php if (!empty($art_tags)): ?>
                        <div class="artwork-tags">
                            <?php foreach ($art_tags as $tag): ?>
                            <a href="?tag=<?= urlencode($tag) ?>" class="tag-pill small"><?= htmlspecialchars($tag) ?></a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                        <div class="artwork-actions">
                            <?php if ($is_authenticated): ?>
                            <button class="btn-delete" onclick="deleteArtwork('<?= htmlspecialchars($art['original']) ?>')" title="Delete artwork">×</button>
                            <?php endif; ?>
                            <button class="btn-share" onclick="toggleShareMenu(this)" title="Share">
                                <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92-1.31-2.92-2.92-2.92z"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="share-inline" data-filename="<?= htmlspecialchars($art['original']) ?>" data-title="<?= htmlspecialchars($art['title']) ?>">
                        <button type="button" onclick="shareBluesky(this)" title="Bluesky"><svg viewBox="0 0 600 530" width="18" height="18"><path fill="currentColor" d="m135.72 44.03c66.496 49.921 138.02 151.14 164.28 205.46 26.262-54.316 97.782-155.54 164.28-205.46 47.98-36.021 125.72-63.892 125.72 24.795 0 17.712-10.155 148.79-16.111 170.07-20.703 73.984-96.144 92.854-163.25 81.433 117.3 19.964 147.14 86.092 82.697 152.22-122.39 125.59-175.91-31.511-189.63-71.766-2.514-7.3797-3.6904-10.832-3.7077-7.8964-0.0174-2.9357-1.1937 0.51669-3.7077 7.8964-13.714 40.255-67.233 197.36-189.63 71.766-64.444-66.128-34.605-132.26 82.697-152.22-67.108 11.421-142.55-7.4491-163.25-81.433-5.9562-21.282-16.111-152.36-16.111-170.07 0-88.687 77.742-60.816 125.72-24.795z"/></svg></button>
                        <button type="button" onclick="shareTwitter(this)" title="Twitter/X"><svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg></button>
                        <button type="button" onclick="sharePinterest(this)" title="Pinterest"><svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 0C5.373 0 0 5.372 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738.098.119.112.224.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12 0-6.628-5.373-12-12-12z"/></svg></button>
                        <button type="button" onclick="copyLink(this)" title="Copy link"><svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg></button>
                        <button type="button" onclick="createVideo(this)" title="Create Video"><svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/></svg></button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($is_authenticated): ?>
            <div class="dropzone" id="dropzone">
                add more work
                <span class="hint">drag or click</span>
                <input type="file" id="file-input" multiple accept="image/*" style="display:none;">
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ($has_exhibits): ?>
        <section id="exhibits" class="section">
            <h2>exhibits</h2>
            <?php
            function renderExhibitCards($exhibits, $uploads_dir) {
                foreach ($exhibits as $slug => $ex) {
                    $cover = $ex['cover'] ?? ($ex['artworks'][0] ?? '');
                    $count = count($ex['artworks'] ?? []);
                    $pi = $cover ? pathinfo($cover) : null;
                    $thumb = '';
                    if ($pi && file_exists($uploads_dir . '/' . $pi['filename'] . '_small.' . $pi['extension'])) {
                        $thumb = $pi['filename'] . '_small.' . $pi['extension'];
                    } elseif ($cover && file_exists($uploads_dir . '/' . $cover)) {
                        $thumb = $cover;
                    }
                    // Date badge
                    $badge = '';
                    $now = time();
                    if (($ex['duration'] ?? '') === 'permanent') {
                        $badge = 'Permanent';
                    } elseif (!empty($ex['start_date']) && strtotime($ex['start_date']) > $now) {
                        $badge = 'Opens ' . date('M j', strtotime($ex['start_date']));
                    } elseif (!empty($ex['end_date']) && strtotime($ex['end_date']) < $now) {
                        $badge = 'Closed';
                    } elseif (!empty($ex['end_date'])) {
                        $badge = 'Through ' . date('M j', strtotime($ex['end_date']));
                    }
                    if ($ex['status'] !== 'published') {
                        $badge = 'Draft';
                    }
                    ?>
                    <a href="/exhibit/<?= htmlspecialchars($slug) ?>" class="exhibit-card">
                        <?php if ($thumb): ?>
                        <img src="/uploads/<?= htmlspecialchars($thumb) ?>" alt="<?= htmlspecialchars($ex['title'] ?? '') ?>" loading="lazy">
                        <?php else: ?>
                        <div class="exhibit-card-empty"></div>
                        <?php endif; ?>
                        <div class="exhibit-card-info">
                            <span class="exhibit-card-title"><?= htmlspecialchars($ex['title'] ?? 'Untitled') ?></span>
                            <span class="exhibit-card-meta"><?= $count ?> work<?= $count !== 1 ? 's' : '' ?><?= $badge ? ' · ' . htmlspecialchars($badge) : '' ?></span>
                        </div>
                    </a>
                    <?php
                }
            }
            ?>

            <?php if (!empty($upcoming_exhibits)): ?>
            <h3 class="exhibit-group-label">upcoming</h3>
            <div class="exhibits-grid">
                <?php renderExhibitCards($upcoming_exhibits, $uploads_dir); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($current_exhibits)): ?>
            <h3 class="exhibit-group-label">current</h3>
            <div class="exhibits-grid">
                <?php renderExhibitCards($current_exhibits, $uploads_dir); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($permanent_exhibits)): ?>
            <h3 class="exhibit-group-label">permanent collection</h3>
            <div class="exhibits-grid">
                <?php renderExhibitCards($permanent_exhibits, $uploads_dir); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($past_exhibits)): ?>
            <h3 class="exhibit-group-label">past</h3>
            <div class="exhibits-grid">
                <?php renderExhibitCards($past_exhibits, $uploads_dir); ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section id="about" class="section">
            <h2>about</h2>
            <?php if ($is_authenticated): ?>
            <textarea id="bio-editor" class="editable-bio" placeholder="Write something about yourself and your work..."><?= htmlspecialchars($artist_bio) ?></textarea>
            <button id="save-bio-btn" class="btn-save-bio" style="display:none;">save bio</button>
            <?php elseif ($artist_bio): ?>
            <p><?= nl2br(htmlspecialchars($artist_bio)) ?></p>
            <?php else: ?>
            <p class="empty">no bio yet.</p>
            <?php endif; ?>
        </section>

        <section id="contact" class="section">
            <h2>contact</h2>
            <?php if ($artist_email): ?>
            <p><a href="mailto:<?= htmlspecialchars($artist_email) ?>"><?= htmlspecialchars($artist_email) ?></a></p>
            <?php endif; ?>
            <?php if ($artist_website): ?>
            <p><a href="<?= htmlspecialchars($artist_website) ?>" target="_blank"><?= htmlspecialchars($artist_website) ?></a></p>
            <?php endif; ?>
        </section>
    </main>

    <footer>
        <p>
            <?php if ($site_url && $show_site_badge): ?>
            <a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($artist_name) ?>.<?= htmlspecialchars($site_domain) ?></a>
            <?php else: ?>
            &copy; <?= date('Y') ?> <?= htmlspecialchars($artist_name) ?>
            <?php endif; ?>
            <button class="theme-cycle-btn theme-btn" title="Toggle theme"></button>
        </p>
    </footer>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/upload.js"></script>
    <script>
        // Inline share functionality
        var artistName = '<?= addslashes($artist_name) ?>';
        var baseUrl = '<?= $current_url ?>';

        function toggleShareMenu(btn) {
            var artwork = btn.closest('.artwork');
            var shareInline = artwork.querySelector('.share-inline');

            // Close any other open menus
            document.querySelectorAll('.share-inline.active').forEach(function(el) {
                if (el !== shareInline) el.classList.remove('active');
            });

            // Toggle this menu
            shareInline.classList.toggle('active');
        }

        function getShareData(el) {
            var shareInline = el.closest('.share-inline');
            return {
                filename: shareInline.getAttribute('data-filename'),
                title: shareInline.getAttribute('data-title')
            };
        }

        function shareTwitter(el) {
            var data = getShareData(el);
            var artUrl = baseUrl + '?art=' + encodeURIComponent(data.filename);
            var text = data.title + ' by ' + artistName;
            var url = 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(text) + '&url=' + encodeURIComponent(artUrl);
            window.open(url, '_blank', 'width=550,height=420');
        }

        function shareBluesky(el) {
            var data = getShareData(el);
            var artUrl = baseUrl + '?art=' + encodeURIComponent(data.filename);
            var text = data.title + ' by ' + artistName + ' ' + artUrl;
            var url = 'https://bsky.app/intent/compose?text=' + encodeURIComponent(text);
            window.open(url, '_blank', 'width=550,height=420');
        }

        function sharePinterest(el) {
            var data = getShareData(el);
            var artUrl = baseUrl + '?art=' + encodeURIComponent(data.filename);
            var imageUrl = baseUrl + '/uploads/' + data.filename;
            var text = data.title + ' by ' + artistName;
            var url = 'https://pinterest.com/pin/create/button/?url=' + encodeURIComponent(artUrl) + '&media=' + encodeURIComponent(imageUrl) + '&description=' + encodeURIComponent(text);
            window.open(url, '_blank', 'width=750,height=550');
        }

        function copyLink(el) {
            var data = getShareData(el);
            var artUrl = baseUrl + '?art=' + encodeURIComponent(data.filename);
            navigator.clipboard.writeText(artUrl).then(function() {
                el.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
                setTimeout(function() {
                    el.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>';
                }, 2000);
            });
        }

        function createVideo(el) {
            var data = getShareData(el);
            // Build gallery of all artworks for swiping during recording
            var gallery = [];
            document.querySelectorAll('.artwork-item').forEach(function(item) {
                var itemData = getShareData(item.querySelector('.btn-share'));
                gallery.push({
                    image: baseUrl + '/uploads/' + itemData.filename,
                    title: itemData.title
                });
            });
            // Find index of clicked artwork
            var startIndex = gallery.findIndex(function(g) { return g.image.includes(data.filename); });
            if (startIndex < 0) startIndex = 0;

            // Redirect with gallery data
            var params = new URLSearchParams({
                gallery: JSON.stringify(gallery),
                start: startIndex,
                artist: artistName
            });
            var videoUrl = <?= json_encode($video_tool_url) ?>;
            if (!videoUrl) return;
            window.location.href = videoUrl + '?' + params.toString();
        }

        function updateStatus(select) {
            var filename = select.getAttribute('data-filename');
            var value = select.value;
            var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            fetch('/update_meta.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'filename=' + encodeURIComponent(filename) + '&field=status&value=' + encodeURIComponent(value) + '&csrf_token=' + encodeURIComponent(csrfToken)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    select.style.outline = '2px solid #22c55e';
                    setTimeout(function() { select.style.outline = ''; }, 1000);
                }
            });
        }

        // Close menus when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.share-inline') && !e.target.closest('.btn-share')) {
                document.querySelectorAll('.share-inline.active').forEach(function(el) {
                    el.classList.remove('active');
                });
            }
        });

        // Editable artwork titles
        document.querySelectorAll('.editable-title').forEach(function(input) {
            var originalValue = input.value;

            input.addEventListener('focus', function() {
                originalValue = this.value;
            });

            input.addEventListener('blur', function() {
                var newValue = this.value.trim();
                if (newValue !== originalValue) {
                    saveArtworkTitle(this.dataset.filename, newValue, this);
                }
            });

            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.blur();
                }
                if (e.key === 'Escape') {
                    this.value = originalValue;
                    this.blur();
                }
            });
        });

        function saveArtworkTitle(filename, title, input) {
            input.style.opacity = '0.5';
            var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            fetch('update_meta.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ filename: filename, field: 'title', value: title, csrf_token: csrfToken })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                input.style.opacity = '1';
                if (data.success) {
                    input.style.borderColor = '#4a4';
                    setTimeout(function() { input.style.borderColor = ''; }, 1000);
                    // Update share data attribute
                    var artwork = input.closest('.artwork');
                    var shareInline = artwork.querySelector('.share-inline');
                    if (shareInline) shareInline.setAttribute('data-title', title);
                } else {
                    alert('Failed to save: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(function(err) {
                input.style.opacity = '1';
                alert('Error saving title');
            });
        }

        // Editable bio
        var bioEditor = document.getElementById('bio-editor');
        var saveBioBtn = document.getElementById('save-bio-btn');
        var originalBio = bioEditor ? bioEditor.value : '';

        if (bioEditor) {
            bioEditor.addEventListener('input', function() {
                if (this.value !== originalBio) {
                    saveBioBtn.style.display = 'inline-block';
                } else {
                    saveBioBtn.style.display = 'none';
                }
            });

            saveBioBtn.addEventListener('click', function() {
                saveBio(bioEditor.value);
            });
        }

        function saveBio(bio) {
            saveBioBtn.textContent = 'saving...';
            saveBioBtn.disabled = true;

            var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            fetch('update_profile.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ bio: bio, csrf_token: csrfToken })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    originalBio = bio;
                    saveBioBtn.textContent = 'saved!';
                    setTimeout(function() {
                        saveBioBtn.style.display = 'none';
                        saveBioBtn.textContent = 'save bio';
                        saveBioBtn.disabled = false;
                    }, 1500);
                } else {
                    alert('Failed to save: ' + (data.error || 'Unknown error'));
                    saveBioBtn.textContent = 'save bio';
                    saveBioBtn.disabled = false;
                }
            })
            .catch(function(err) {
                alert('Error saving bio');
                saveBioBtn.textContent = 'save bio';
                saveBioBtn.disabled = false;
            });
        }
    </script>
</body>
</html>
