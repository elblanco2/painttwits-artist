<?php
/**
 * Email-to-Gallery Handler Configuration
 *
 * Copy this file to config.php and fill in your values.
 * This allows artists to email artwork directly to their gallery.
 */

// IMAP Settings - Connect to your email inbox
define('IMAP_HOST', 'mail.example.com');      // Your mail server
define('IMAP_PORT', 993);                      // Usually 993 for SSL
define('IMAP_USER', 'artwork@example.com');    // Email address to receive artwork
define('IMAP_PASS', 'your-password-here');     // Email password
define('IMAP_FLAGS', '/imap/ssl');             // Connection flags
define('IMAP_MAILBOX', 'INBOX');               // Mailbox to check

// Database Settings - Same as your main site
// NOTE: Use '127.0.0.1' instead of 'localhost' to avoid Unix socket permission issues
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'your_database');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Paths - Adjust for your server
define('LOG_PATH', '/path/to/logs');                    // Where to write logs
define('SUBDOMAINS_PATH', '/path/to/public_html');      // Where artist sites are hosted

// Email Settings - For sending replies
define('EMAIL_FROM', 'artwork@example.com');    // Reply-from address
define('EMAIL_FROM_NAME', 'Your Site Name');    // Display name

// Site Settings
define('SITE_URL', 'https://example.com');      // Your main site URL
define('SITE_NAME', 'Your Site Name');          // Site name for emails
define('APPLY_URL', 'https://example.com/#join'); // Where unknown senders can apply
