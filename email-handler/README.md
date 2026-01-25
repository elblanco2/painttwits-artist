# Email-to-Gallery Handler

Let artists add artwork to their gallery by simply emailing images.

## How It Works

1. Artist sends email to `artwork@yourdomain.com` with image attachment
2. This script (running via cron) checks inbox every minute
3. If sender is an approved artist:
   - Image is processed (resized, DZI tiles generated)
   - Added to their gallery automatically
   - Confirmation email sent with links
4. If sender is unknown:
   - Friendly email sent with link to apply

## Requirements

- PHP 7.4+ with IMAP extension
- Imagick PHP extension
- Composer (for mail parser library)

## Installation

### 1. Install Dependencies

```bash
cd email-handler
composer require php-mime-mail-parser/php-mime-mail-parser
```

### 2. Configure

```bash
cp config.sample.php config.php
# Edit config.php with your settings
```

### 3. Set Up Cron Job

Run the handler every minute:

```bash
* * * * * php /path/to/email_artwork_handler.php >> /path/to/logs/cron.log 2>&1
```

### 4. Create Email Account

Set up the email address in your hosting control panel (cPanel, etc).

## Email Format for Artists

**Subject line** becomes the artwork title:
```
"Sunset over Miami" 24x36 oil on canvas
```

**Body** can contain structured metadata:
```
Title: Sunset over Miami
Size: 24x36
Medium: Oil on canvas
Price: $500
Status: available
Description: Painted during a beautiful evening...
```

Or just attach images with a simple subject line - both work!

## Supported Image Formats

- JPEG (.jpg, .jpeg)
- PNG (.png)
- GIF (.gif)
- WebP (.webp)
- HEIC (.heic) - converted to JPEG
- TIFF (.tiff) - converted to JPEG

## What Gets Generated

For each uploaded image:
- Original (full resolution)
- Large (1200px)
- Medium (800px)
- Small/thumbnail (400px)
- Social (1200x630 for link previews)
- DZI tiles (if image > 2000px, for deep zoom)

## Troubleshooting

**Logs location:** Check `LOG_PATH/email_artwork.log`

**Common issues:**

1. **"IMAP connection failed"**
   - Verify IMAP credentials
   - Check firewall allows port 993
   - Ensure IMAP is enabled on email account

2. **"Permission denied" on uploads**
   - Verify web server can write to artist upload directories
   - Check directory permissions (755 for dirs, 644 for files)

3. **Images not processing**
   - Verify Imagick extension is installed: `php -m | grep imagick`
   - Check PHP memory limit for large images

## Database Table

The handler logs all submissions. Create this table:

```sql
CREATE TABLE email_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_email VARCHAR(255) NOT NULL,
    sender_name VARCHAR(255),
    subject VARCHAR(500),
    body TEXT,
    attachment_count INT DEFAULT 0,
    sender_type ENUM('approved_artist', 'unknown_sender') NOT NULL,
    status ENUM('received', 'processed', 'responded', 'no_attachments', 'failed') DEFAULT 'received',
    admin_notes TEXT,
    processed_files JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    INDEX idx_sender_email (sender_email),
    INDEX idx_status (status)
);
```
