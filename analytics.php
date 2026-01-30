<?php
// Google Analytics 4 — include in <head> of every page
$_ga_ids = ['G-TL29LHSMH8'];

// Artist's own GA4 ID (optional)
$_ac_file = __DIR__ . '/artist_config.php';
if (file_exists($_ac_file)) {
    $_ac = require $_ac_file;
    if (!empty($_ac['google_analytics_id'])) {
        $_ga_ids[] = $_ac['google_analytics_id'];
    }
}
$_ga_primary = $_ga_ids[0];
?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= $_ga_primary ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
<?php foreach ($_ga_ids as $_ga_id): ?>
gtag('config', '<?= htmlspecialchars($_ga_id, ENT_QUOTES) ?>');
<?php endforeach; ?>
</script>
