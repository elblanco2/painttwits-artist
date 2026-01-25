<?php
/**
 * Artist Portfolio Configuration
 *
 * For self-hosted: Copy to artist_config.php and fill in your values.
 * For painttwits.com: This file is auto-generated when subdomain is created.
 */

return [
    // =========================================
    // SITE CONFIGURATION (customize for your deployment)
    // =========================================

    // Your site's branding - displayed in meta tags, footers, etc.
    'site_name' => 'My Gallery',              // e.g., 'painttwits' or 'My Art Platform'
    'site_domain' => 'example.com',           // e.g., 'painttwits.com' or 'myartsite.com'
    'site_url' => 'https://example.com',      // Full URL to main site

    // Optional: Central API for multi-artist platforms
    // Leave empty if running standalone (single artist, no central sync)
    'central_api' => '',                      // e.g., 'https://painttwits.com/api'
    'api_key' => '',                          // API key for central sync

    // Video intro tool URL (leave empty to hide "Create Video" button)
    'video_tool_url' => '',                   // e.g., 'https://painttwits.com/introduce.php'

    // =========================================
    // ARTIST INFORMATION
    // =========================================

    'artist_id' => 1,
    'name' => 'Your Name',
    'email' => 'artist@example.com',          // OAuth email must match this
    'location' => 'City, State',
    'bio' => '',
    'website' => '',

    // =========================================
    // AUTHENTICATION (Optional)
    // =========================================

    // Google OAuth for artist login (optional - needed to edit/upload)
    'oauth' => [
        'google_client_id' => '',
        'callback_url' => '',                 // Leave empty for self-hosted single-artist
    ],

    // Auth token signing secret
    // For self-hosted, generate with: bin2hex(random_bytes(32))
    'auth_signing_secret' => '',

    // =========================================
    // DISPLAY OPTIONS
    // =========================================

    'show_prices' => false,
    'contact_form' => true,
    'show_site_badge' => true,                // Show "powered by" badge in footer

    // =========================================
    // PAINTTWITS NETWORK (Optional)
    // =========================================
    // Register with painttwits.com for discovery in their artist directory

    'painttwits_network' => [
        'enabled' => false,                   // Set true to register for discovery
        'sample_artwork' => '',               // Filename in uploads/ to use as preview
    ],
];
