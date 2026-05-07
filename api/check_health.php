<?php
/**
 * Federation health probe.
 *
 * Lightweight endpoint that lets painttwits.com confirm a self-hoster's URL
 * actually serves a painttwits-artist gallery before approving federation.
 *
 * Called by central during register.php initiate (one-time pre-flight) and
 * later by a heartbeat cron (Phase 3, future).
 *
 * No auth required — this is a "is anyone home?" probe with no sensitive
 * data exposed. Does NOT confirm artist identity, only that the install is
 * a painttwits-artist instance.
 *
 * Response shape (always 200 OK if reachable):
 *   { painttwits_artist: true, version: "1.0.x" }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *'); // central probe, public-readable

// Best-effort version read
$version = '1.0.0';
$version_file = __DIR__ . '/../version.php';
if (file_exists($version_file)) {
    $contents = (string) @file_get_contents($version_file);
    if (preg_match("/define\\(\\s*'PAINTTWITS_ARTIST_VERSION'\\s*,\\s*'([^']+)'/", $contents, $m)) {
        $version = $m[1];
    }
}

echo json_encode([
    'painttwits_artist' => true,
    'version' => $version,
]);
