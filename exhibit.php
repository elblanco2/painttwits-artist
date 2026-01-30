<?php
/**
 * Public Exhibit Page — Carousel viewer for curated artwork groups
 * URL: /exhibit/slug-name (via .htaccess rewrite)
 */

session_start();
require_once __DIR__ . '/security_helpers.php';

$is_authenticated = isset($_SESSION['artist_authenticated']) && $_SESSION['artist_authenticated'];

// Load config
$config_file = __DIR__ . '/artist_config.php';
$config_json = __DIR__ . '/config.json';

if (file_exists($config_file)) {
    $config = require $config_file;
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

$site_name = $config['site_name'] ?? 'Gallery';
$site_url = $config['site_url'] ?? '';

// Get slug from query param (rewritten from /exhibit/slug)
$slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9-]/', '', strtolower($_GET['slug'])) : '';

if (!$slug) {
    header('Location: /#exhibits');
    exit;
}

// Load exhibits
$exhibits_file = __DIR__ . '/exhibits.json';
$exhibits = file_exists($exhibits_file) ? json_decode(file_get_contents($exhibits_file), true) : [];

if (!isset($exhibits[$slug])) {
    http_response_code(404);
    die('Exhibit not found');
}

$exhibit = $exhibits[$slug];

// Only show published exhibits to non-authenticated users
if ($exhibit['status'] !== 'published' && !$is_authenticated) {
    http_response_code(404);
    die('Exhibit not found');
}

$title = $exhibit['title'] ?? 'Untitled Exhibit';
$description = $exhibit['description'] ?? '';
$artworks = $exhibit['artworks'] ?? [];
$cover = $exhibit['cover'] ?? ($artworks[0] ?? '');
$duration = $exhibit['duration'] ?? 'temporary';
$start_date = $exhibit['start_date'] ?? null;
$end_date = $exhibit['end_date'] ?? null;
$opening_reception = $exhibit['opening_reception'] ?? null;
$venue = $exhibit['venue'] ?? '';
$press_release = $exhibit['press_release'] ?? '';

// Filter to only existing files
$uploads_dir = __DIR__ . '/uploads/';
$valid_artworks = [];
foreach ($artworks as $fname) {
    if (file_exists($uploads_dir . $fname)) {
        $valid_artworks[] = $fname;
    }
}
$artworks = $valid_artworks;
$total = count($artworks);

// Date display logic
$date_display = '';
$status_badge = '';
$now = time();

if ($duration === 'permanent') {
    $status_badge = 'Permanent Collection';
} else {
    $start_ts = $start_date ? strtotime($start_date) : null;
    $end_ts = $end_date ? strtotime($end_date) : null;

    if ($start_ts && $start_ts > $now) {
        $status_badge = 'Opens ' . date('M j', $start_ts);
        $date_display = date('M j, Y', $start_ts);
    } elseif ($end_ts && $end_ts < $now) {
        $status_badge = 'Closed';
        if ($start_ts) $date_display = date('M j', $start_ts) . ' – ' . date('M j, Y', $end_ts);
    } elseif ($start_ts && $end_ts) {
        $status_badge = 'Now through ' . date('M j', $end_ts);
        $date_display = date('M j', $start_ts) . ' – ' . date('M j, Y', $end_ts);
    } elseif ($start_ts) {
        $status_badge = 'Since ' . date('M j, Y', $start_ts);
        $date_display = 'Since ' . date('M j, Y', $start_ts);
    }
}

$reception_display = '';
if ($opening_reception) {
    $r_ts = strtotime($opening_reception);
    if ($r_ts) {
        $reception_display = 'Opening Reception: ' . date('M j, g:i A', $r_ts);
    }
}

// Build URLs
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'];
$exhibit_url = $base_url . '/exhibit/' . $slug;

// OG image: use cover artwork
$og_image = '';
if ($cover) {
    $pi = pathinfo($cover);
    $social_file = $pi['filename'] . '_social.' . $pi['extension'];
    $medium_file = $pi['filename'] . '_medium.' . $pi['extension'];
    if (file_exists($uploads_dir . $social_file)) {
        $og_image = $base_url . '/uploads/' . $social_file;
    } elseif (file_exists($uploads_dir . $medium_file)) {
        $og_image = $base_url . '/uploads/' . $medium_file;
    } elseif (file_exists($uploads_dir . $cover)) {
        $og_image = $base_url . '/uploads/' . $cover;
    }
}

$og_title = $title . ' by ' . $artist_name;
$og_desc_parts = [];
if ($date_display) $og_desc_parts[] = $date_display;
if ($venue) $og_desc_parts[] = $venue;
$og_desc_parts[] = $total . ' works';
$og_description = implode(' · ', $og_desc_parts);

// Load artwork metadata for titles
$meta_file = __DIR__ . '/artwork_meta.json';
$artwork_meta = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/analytics.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($og_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($og_description) ?>">

    <!-- Open Graph -->
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?= htmlspecialchars($exhibit_url) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($og_title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($og_description) ?>">
    <?php if ($og_image): ?>
    <meta property="og:image" content="<?= htmlspecialchars($og_image) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <?php endif; ?>
    <meta property="og:site_name" content="<?= htmlspecialchars($site_name) ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($og_title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($og_description) ?>">
    <?php if ($og_image): ?>
    <meta name="twitter:image" content="<?= htmlspecialchars($og_image) ?>">
    <?php endif; ?>

    <!-- JSON-LD Structured Data -->
    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ExhibitionEvent',
        'name' => $title,
        'description' => $description ?: $og_description,
        'image' => $og_image ?: null,
        'url' => $exhibit_url,
        'organizer' => ['@type' => 'Person', 'name' => $artist_name],
        'startDate' => $start_date ?: null,
        'endDate' => $end_date ?: null,
        'location' => $venue ? ['@type' => 'Place', 'name' => $venue] : null,
        'numberOfItems' => $total
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
    </script>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg: #fafafa;
            --bg2: #f0f0f0;
            --bg3: #e5e5e5;
            --text: #111;
            --text2: #555;
            --muted: #888;
            --accent: #4a9eff;
            --border: #ddd;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #111;
                --bg2: #1a1a1a;
                --bg3: #222;
                --text: #fafafa;
                --text2: #ccc;
                --muted: #888;
                --border: #333;
            }
        }

        body {
            font-family: 'Courier New', Courier, monospace;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
        }

        /* Header */
        .exhibit-header {
            text-align: center;
            padding: 2rem 1rem 1rem;
            border-bottom: 1px solid var(--border);
        }
        .exhibit-header h1 {
            font-size: 1.8rem;
            font-weight: normal;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }
        .exhibit-header .artist {
            color: var(--muted);
            margin-bottom: 0.5rem;
        }
        .exhibit-header .artist a {
            color: var(--accent);
            text-decoration: none;
        }
        .exhibit-header .artist a:hover { text-decoration: underline; }
        .exhibit-date-bar {
            color: var(--text2);
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        .exhibit-badge {
            display: inline-block;
            background: var(--bg3);
            color: var(--text2);
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            margin-top: 0.5rem;
        }
        .exhibit-badge.upcoming { background: #dbeafe; color: #1e40af; }
        .exhibit-badge.current { background: #dcfce7; color: #166534; }
        .exhibit-badge.closed { background: #fef2f2; color: #991b1b; }
        .exhibit-badge.permanent { background: #f3e8ff; color: #6b21a8; }
        @media (prefers-color-scheme: dark) {
            .exhibit-badge.upcoming { background: #1e3a5f; color: #93c5fd; }
            .exhibit-badge.current { background: #14532d; color: #86efac; }
            .exhibit-badge.closed { background: #450a0a; color: #fca5a5; }
            .exhibit-badge.permanent { background: #3b0764; color: #d8b4fe; }
        }
        .exhibit-venue {
            color: var(--muted);
            font-size: 0.85rem;
        }
        .exhibit-reception {
            color: var(--text2);
            font-size: 0.85rem;
            font-style: italic;
            margin-top: 0.25rem;
        }
        .exhibit-description {
            max-width: 700px;
            margin: 1rem auto 0;
            color: var(--text2);
            font-size: 0.95rem;
        }

        /* Carousel */
        .carousel-wrap {
            position: relative;
            background: #000;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 50vh;
            max-height: 75vh;
            overflow: hidden;
        }
        .carousel-wrap img {
            max-width: 90%;
            max-height: 70vh;
            object-fit: contain;
            cursor: pointer;
            transition: opacity 0.3s;
        }
        .carousel-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255,255,255,0.15);
            color: #fff;
            border: none;
            font-size: 2rem;
            width: 50px;
            height: 80px;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .carousel-nav:hover { background: rgba(255,255,255,0.3); }
        .carousel-nav.prev { left: 0; border-radius: 0 8px 8px 0; }
        .carousel-nav.next { right: 0; border-radius: 8px 0 0 8px; }
        .carousel-counter {
            position: absolute;
            bottom: 12px;
            right: 16px;
            color: rgba(255,255,255,0.7);
            font-size: 0.85rem;
            background: rgba(0,0,0,0.5);
            padding: 2px 10px;
            border-radius: 10px;
        }

        /* Thumbnail strip */
        .thumb-strip {
            display: flex;
            gap: 4px;
            padding: 8px;
            overflow-x: auto;
            background: var(--bg2);
            border-bottom: 1px solid var(--border);
            scrollbar-width: thin;
        }
        .thumb-strip img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            cursor: pointer;
            opacity: 0.5;
            transition: opacity 0.2s;
            border: 2px solid transparent;
            flex-shrink: 0;
        }
        .thumb-strip img:hover { opacity: 0.8; }
        .thumb-strip img.active {
            opacity: 1;
            border-color: var(--accent);
        }

        /* Artwork info below carousel */
        .artwork-caption {
            text-align: center;
            padding: 1rem;
            background: var(--bg2);
        }
        .artwork-caption .caption-title {
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }
        .artwork-caption .caption-link {
            font-size: 0.8rem;
        }
        .artwork-caption .caption-link a {
            color: var(--accent);
            text-decoration: none;
        }
        .artwork-caption .caption-link a:hover { text-decoration: underline; }

        /* Press release */
        .press-release {
            max-width: 700px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .press-release summary {
            cursor: pointer;
            color: var(--text2);
            font-size: 0.9rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border);
        }
        .press-release .pr-text {
            padding: 1rem 0;
            color: var(--text2);
            font-size: 0.9rem;
            white-space: pre-wrap;
            line-height: 1.7;
        }

        /* Share + nav */
        .exhibit-footer {
            text-align: center;
            padding: 1.5rem 1rem;
            border-top: 1px solid var(--border);
        }
        .share-row {
            display: flex;
            gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .share-row a {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            text-decoration: none;
            color: #fff;
            transition: opacity 0.2s;
        }
        .share-row a:hover { opacity: 0.85; }
        .share-row .s-bsky { background: #0085ff; }
        .share-row .s-twitter { background: #000; border: 1px solid #333; }
        .share-row .s-pinterest { background: #e60023; }
        .share-row .s-copy { background: var(--bg3); color: var(--text); border: 1px solid var(--border); }
        .back-gallery {
            margin-top: 1rem;
        }
        .back-gallery a {
            color: var(--accent);
            text-decoration: none;
            font-size: 0.95rem;
        }
        .back-gallery a:hover { text-decoration: underline; }

        /* Toast */
        .toast {
            position: fixed; bottom: 20px; left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--bg3); color: var(--text);
            padding: 12px 24px; border-radius: 8px;
            border: 1px solid var(--border);
            opacity: 0; transition: transform 0.3s, opacity 0.3s;
            z-index: 1000;
        }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }

        @media (max-width: 600px) {
            .exhibit-header h1 { font-size: 1.3rem; }
            .carousel-nav { width: 36px; height: 60px; font-size: 1.5rem; }
            .thumb-strip img { width: 48px; height: 48px; }
        }
    </style>
</head>
<body>
    <div class="exhibit-header">
        <h1><?= htmlspecialchars($title) ?></h1>
        <p class="artist">by <a href="/"><?= htmlspecialchars($artist_name) ?></a></p>
        <?php if ($date_display): ?>
        <p class="exhibit-date-bar"><?= htmlspecialchars($date_display) ?></p>
        <?php endif; ?>
        <?php if ($venue): ?>
        <p class="exhibit-venue"><?= htmlspecialchars($venue) ?></p>
        <?php endif; ?>
        <?php if ($reception_display): ?>
        <p class="exhibit-reception"><?= htmlspecialchars($reception_display) ?></p>
        <?php endif; ?>
        <?php
        $badge_class = '';
        if ($duration === 'permanent') $badge_class = 'permanent';
        elseif ($start_date && strtotime($start_date) > $now) $badge_class = 'upcoming';
        elseif ($end_date && strtotime($end_date) < $now) $badge_class = 'closed';
        elseif ($status_badge) $badge_class = 'current';
        ?>
        <?php if ($status_badge): ?>
        <span class="exhibit-badge <?= $badge_class ?>"><?= htmlspecialchars($status_badge) ?></span>
        <?php endif; ?>
        <?php if ($description): ?>
        <p class="exhibit-description"><?= nl2br(htmlspecialchars($description)) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($total > 0): ?>
    <div class="carousel-wrap" id="carousel">
        <?php if ($total > 1): ?>
        <button class="carousel-nav prev" onclick="carousel(-1)">&lsaquo;</button>
        <button class="carousel-nav next" onclick="carousel(1)">&rsaquo;</button>
        <?php endif; ?>
        <img id="carousel-img" src="/uploads/<?= htmlspecialchars($artworks[0]) ?>" alt="<?= htmlspecialchars($title) ?>">
        <div class="carousel-counter"><span id="counter-current">1</span> of <?= $total ?></div>
    </div>

    <div class="thumb-strip" id="thumbs">
        <?php foreach ($artworks as $i => $fname):
            $pi = pathinfo($fname);
            $thumb = $pi['filename'] . '_small.' . $pi['extension'];
            $src = file_exists($uploads_dir . $thumb) ? $thumb : $fname;
        ?>
        <img src="/uploads/<?= htmlspecialchars($src) ?>"
             data-full="/uploads/<?= htmlspecialchars($fname) ?>"
             data-index="<?= $i ?>"
             class="<?= $i === 0 ? 'active' : '' ?>"
             onclick="goTo(<?= $i ?>)"
             alt="Thumbnail <?= $i + 1 ?>">
        <?php endforeach; ?>
    </div>

    <div class="artwork-caption" id="caption">
        <?php
        $first_meta = $artwork_meta[$artworks[0]] ?? [];
        $first_title = $first_meta['title'] ?? pathinfo($artworks[0], PATHINFO_FILENAME);
        ?>
        <p class="caption-title" id="caption-title"><?= htmlspecialchars($first_title) ?></p>
        <p class="caption-link"><a id="caption-link" href="/art.php?f=<?= urlencode($artworks[0]) ?>&exhibit=<?= urlencode($slug) ?>">View full detail</a></p>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:3rem;color:var(--muted);">No artworks in this exhibit yet.</div>
    <?php endif; ?>

    <?php if ($press_release): ?>
    <details class="press-release">
        <summary>Press Release</summary>
        <div class="pr-text"><?= htmlspecialchars($press_release) ?></div>
    </details>
    <?php endif; ?>

    <div class="exhibit-footer">
        <div class="share-row">
            <?php
            $share_text = '"' . $title . '" by ' . $artist_name;
            if ($date_display) $share_text .= "\n" . $date_display;
            if ($venue) $share_text .= ' · ' . $venue;
            ?>
            <a href="#" class="s-bsky" onclick="shareBsky();return false;">Bluesky</a>
            <a href="#" class="s-twitter" onclick="shareTwitter();return false;">Twitter</a>
            <a href="#" class="s-pinterest" onclick="sharePinterest();return false;">Pinterest</a>
            <a href="#" class="s-copy" onclick="copyLink();return false;">Copy Link</a>
        </div>
        <div class="back-gallery">
            <a href="/#exhibits">&larr; Back to gallery</a>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
    var artworks = <?= json_encode($artworks) ?>;
    var artworkTitles = <?= json_encode(array_map(function($f) use ($artwork_meta) {
        $m = $artwork_meta[$f] ?? [];
        return $m['title'] ?? pathinfo($f, PATHINFO_FILENAME);
    }, $artworks)) ?>;
    var slug = <?= json_encode($slug) ?>;
    var currentIndex = 0;
    var total = artworks.length;
    var exhibitUrl = <?= json_encode($exhibit_url) ?>;
    var shareText = <?= json_encode($share_text) ?>;

    function goTo(idx) {
        if (idx < 0) idx = total - 1;
        if (idx >= total) idx = 0;
        currentIndex = idx;

        var img = document.getElementById('carousel-img');
        img.style.opacity = '0';
        setTimeout(function() {
            img.src = '/uploads/' + artworks[idx];
            img.onload = function() { img.style.opacity = '1'; };
        }, 150);

        document.getElementById('counter-current').textContent = idx + 1;
        document.getElementById('caption-title').textContent = artworkTitles[idx];
        document.getElementById('caption-link').href = '/art.php?f=' + encodeURIComponent(artworks[idx]) + '&exhibit=' + encodeURIComponent(slug);

        // Update thumbs
        var thumbs = document.querySelectorAll('#thumbs img');
        thumbs.forEach(function(t) { t.classList.remove('active'); });
        if (thumbs[idx]) {
            thumbs[idx].classList.add('active');
            thumbs[idx].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
    }

    function carousel(dir) { goTo(currentIndex + dir); }

    // Keyboard nav
    document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowLeft') carousel(-1);
        else if (e.key === 'ArrowRight') carousel(1);
    });

    // Swipe
    var touchStartX = 0;
    var carouselEl = document.getElementById('carousel');
    if (carouselEl) {
        carouselEl.addEventListener('touchstart', function(e) {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });
        carouselEl.addEventListener('touchend', function(e) {
            var dx = e.changedTouches[0].screenX - touchStartX;
            if (Math.abs(dx) > 50) carousel(dx > 0 ? -1 : 1);
        }, { passive: true });
    }

    // Click image to view detail
    var mainImg = document.getElementById('carousel-img');
    if (mainImg) {
        mainImg.addEventListener('click', function() {
            window.location.href = '/art.php?f=' + encodeURIComponent(artworks[currentIndex]) + '&exhibit=' + encodeURIComponent(slug);
        });
    }

    // Share functions
    function showToast(msg) {
        var t = document.getElementById('toast');
        t.textContent = msg;
        t.classList.add('show');
        setTimeout(function() { t.classList.remove('show'); }, 2000);
    }
    function shareBsky() {
        window.open('https://bsky.app/intent/compose?text=' + encodeURIComponent(shareText + '\n' + exhibitUrl), '_blank', 'width=550,height=420');
    }
    function shareTwitter() {
        window.open('https://twitter.com/intent/tweet?text=' + encodeURIComponent(shareText) + '&url=' + encodeURIComponent(exhibitUrl), '_blank', 'width=550,height=420');
    }
    function sharePinterest() {
        var img = <?= json_encode($og_image) ?>;
        window.open('https://pinterest.com/pin/create/button/?url=' + encodeURIComponent(exhibitUrl) + '&media=' + encodeURIComponent(img) + '&description=' + encodeURIComponent(shareText), '_blank', 'width=750,height=550');
    }
    function copyLink() {
        navigator.clipboard.writeText(exhibitUrl).then(function() { showToast('Link copied!'); });
    }
    </script>
</body>
</html>
