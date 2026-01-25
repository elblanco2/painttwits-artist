#!/usr/bin/php -q
<?php
/**
 * Painttwits Email-to-Gallery Handler (IMAP Polling Version)
 *
 * Polls newart@painttwits.com via IMAP for new artwork submissions
 * Run via cron every 1-2 minutes
 *
 * - Approved artists: artwork added to their subdomain
 * - Unknown emails: get a friendly response with apply link
 * - All submissions logged to database for admin review
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpMimeMailParser\Parser;

// --- Error Logging Setup ---
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', LOG_PATH . '/php_error.log');

// --- Logging Functions ---
function log_message($message) {
    $logfile = LOG_PATH . '/email_artwork.log';
    @file_put_contents($logfile, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

function log_error($message) {
    $logfile = LOG_PATH . '/email_artwork_error.log';
    @file_put_contents($logfile, date('Y-m-d H:i:s') . ' - ERROR: ' . $message . "\n", FILE_APPEND);
}

log_message("=== Starting IMAP Check ===");

// --- Database Connection ---
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    log_message("Database connected");
} catch (PDOException $e) {
    log_error("Database connection failed: " . $e->getMessage());
    exit(1);
}

// --- Connect to IMAP ---
$mailbox = '{' . IMAP_HOST . ':' . IMAP_PORT . IMAP_FLAGS . '}' . IMAP_MAILBOX;
log_message("Connecting to: " . IMAP_HOST);

$imap = @imap_open($mailbox, IMAP_USER, IMAP_PASS);
if (!$imap) {
    log_error("IMAP connection failed: " . imap_last_error());
    exit(1);
}

log_message("IMAP connected successfully");

// --- Check for new emails ---
$emails = imap_search($imap, 'UNSEEN');

if (!$emails) {
    log_message("No new emails found");
    imap_close($imap);
    exit(0);
}

log_message("Found " . count($emails) . " new email(s)");

foreach ($emails as $email_number) {
    log_message("--- Processing email #$email_number ---");

    try {
        // Get email headers
        $header = imap_headerinfo($imap, $email_number);
        $from_address = $header->from[0]->mailbox . '@' . $header->from[0]->host;
        $from_name = isset($header->from[0]->personal) ? imap_utf8($header->from[0]->personal) : '';
        $subject = isset($header->subject) ? imap_utf8($header->subject) : 'Untitled';

        $from_address = strtolower(trim($from_address));
        $from_name = trim($from_name);

        log_message("From: $from_name <$from_address>");
        log_message("Subject: $subject");

        // Get email body
        $body = getEmailBody($imap, $email_number);

        // Get attachments
        $attachments = getAttachments($imap, $email_number);
        log_message("Attachments: " . count($attachments));

        // Parse [subdomain] from subject line if present
        $target_subdomain = null;
        $clean_subject = $subject;
        if (preg_match('/^\[([a-z0-9-]+)\]\s*/i', $subject, $matches)) {
            $target_subdomain = strtolower($matches[1]);
            $clean_subject = trim(substr($subject, strlen($matches[0])));
            log_message("Target subdomain from subject: $target_subdomain");
        }

        // Get all subdomains for this email
        $stmt = $pdo->prepare("SELECT * FROM artists WHERE LOWER(email) = ? AND subdomain IS NOT NULL AND subdomain != '' ORDER BY is_primary DESC, id ASC");
        $stmt->execute([$from_address]);
        $all_subdomains = $stmt->fetchAll();

        $artist = null;
        $is_approved = !empty($all_subdomains);

        if ($is_approved) {
            if ($target_subdomain) {
                // Look for the specific subdomain requested
                foreach ($all_subdomains as $sub) {
                    if (strtolower($sub['subdomain']) === $target_subdomain) {
                        $artist = $sub;
                        log_message("Matched requested subdomain: {$artist['subdomain']}");
                        break;
                    }
                }
                if (!$artist) {
                    // Subdomain specified but not found - use primary/first and warn
                    $artist = $all_subdomains[0];
                    log_message("WARNING: Requested subdomain '$target_subdomain' not found for this email. Using default: {$artist['subdomain']}");
                }
            } else {
                // No subdomain specified - use primary (is_primary=1) or first one
                $artist = $all_subdomains[0];
                if (count($all_subdomains) > 1) {
                    log_message("Multiple subdomains found (" . count($all_subdomains) . "). Using default: {$artist['subdomain']}. Tip: Use [subdomain] in subject to target specific gallery.");
                }
            }
            // Use clean subject (without [subdomain] prefix) for metadata parsing
            $subject = $clean_subject;
        }

        // Log submission to database
        $submission_id = logSubmission($pdo, $from_address, $from_name, $subject, $body, count($attachments), $is_approved ? 'approved_artist' : 'unknown_sender');

        if (!$is_approved) {
            // Unknown sender - save up to 3 pending artworks and send apply link
            log_message("Unknown sender, checking if we can save pending artwork");

            // Check how many pending artworks this email already has
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM pending_artworks WHERE sender_email = ? AND status = 'pending'");
            $stmt->execute([$from_address]);
            $pending_count = (int)$stmt->fetch()['count'];

            $saved_pending = 0;
            if ($pending_count < 3 && !empty($attachments)) {
                log_message("Saving pending artwork for unknown sender ($pending_count already pending)");

                // Save up to (3 - pending_count) artworks
                $remaining_slots = 3 - $pending_count;
                foreach ($attachments as $index => $attachment) {
                    if ($saved_pending >= $remaining_slots) break;

                    $extension = strtolower(pathinfo($attachment['filename'], PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                    if (!in_array($extension, $allowed)) continue;

                    // Generate safe filename
                    $safe_filename = bin2hex(random_bytes(16)) . '.' . $extension;
                    $pending_dir = SITE_URL ? '/home/ua896588/public_html/painttwits.com/uploads/pending' : __DIR__ . '/../uploads/pending';
                    $file_path = $pending_dir . '/' . $safe_filename;

                    // Save the file
                    if (file_put_contents($file_path, $attachment['data'])) {
                        // Insert into pending_artworks
                        $stmt = $pdo->prepare("INSERT INTO pending_artworks (email_submission_id, sender_email, sender_name, filename, original_filename, file_path, file_size, mime_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $submission_id,
                            $from_address,
                            $from_name,
                            $safe_filename,
                            $attachment['filename'],
                            'uploads/pending/' . $safe_filename,
                            strlen($attachment['data']),
                            'image/' . $extension
                        ]);
                        $saved_pending++;
                        log_message("Saved pending artwork: $safe_filename");
                    }
                }
            }

            $notes = $saved_pending > 0
                ? "Saved $saved_pending pending artwork(s). Sent apply link."
                : "Sent apply link - not an approved artist";

            updateSubmissionStatus($pdo, $submission_id, 'responded', $notes);
            sendNotApprovedEmail($from_address, $from_name, $saved_pending);

            // Mark as read
            imap_setflag_full($imap, $email_number, "\\Seen");
            continue;
        }

        // --- Process attachments for approved artist ---
        log_message("Approved artist: {$artist['name']} ({$artist['subdomain']})");

        if (empty($attachments)) {
            log_message("No attachments found");
            updateSubmissionStatus($pdo, $submission_id, 'no_attachments', 'Email had no image attachments');
            sendNoAttachmentsEmail($from_address, $artist['name']);
            imap_setflag_full($imap, $email_number, "\\Seen");
            continue;
        }

        // Parse metadata from subject/body
        $metadata = parseArtworkMetadata($subject, $body);
        log_message("Parsed metadata: " . json_encode($metadata));

        $processed_count = 0;
        $artwork_urls = [];
        $processed_files = [];

        foreach ($attachments as $index => $attachment) {
            $extension = strtolower(pathinfo($attachment['filename'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'tiff'];

            if (!in_array($extension, $allowed)) {
                log_message("Skipping {$attachment['filename']}: not allowed extension");
                continue;
            }

            log_message("Processing: {$attachment['filename']}");

            try {
                $result = processArtwork(
                    $attachment['data'],
                    $extension,
                    $artist,
                    $metadata,
                    $index
                );

                if ($result['success']) {
                    $processed_count++;
                    $artwork_urls[] = $result;
                    $processed_files[] = $result['filename'];
                    log_message("Successfully processed: {$result['filename']}");

                    // Sync to central database
                    syncArtworkToCenter($pdo, $artist, $result);
                }
            } catch (Exception $e) {
                log_error("Failed to process {$attachment['filename']}: " . $e->getMessage());
            }
        }

        // Update submission record
        if ($processed_count > 0) {
            updateSubmissionStatus($pdo, $submission_id, 'processed', "Processed {$processed_count} artwork(s)", json_encode($processed_files));
            sendSuccessEmail($from_address, $artist, $artwork_urls);
            log_message("Success email sent");
        } else {
            updateSubmissionStatus($pdo, $submission_id, 'failed', 'No valid images could be processed');
            sendFailureEmail($from_address, $artist['name']);
        }

        // Mark as read
        imap_setflag_full($imap, $email_number, "\\Seen");

    } catch (Exception $e) {
        log_error("Error processing email #$email_number: " . $e->getMessage());
    }
}

imap_close($imap);
log_message("=== IMAP Check Complete ===\n");
exit(0);

// ============================================
// IMAP HELPER FUNCTIONS
// ============================================

function getEmailBody($imap, $email_number) {
    $body = '';
    $structure = imap_fetchstructure($imap, $email_number);

    if (!isset($structure->parts)) {
        // Simple message
        $body = imap_body($imap, $email_number);
        if ($structure->encoding == 3) {
            $body = base64_decode($body);
        } elseif ($structure->encoding == 4) {
            $body = quoted_printable_decode($body);
        }
    } else {
        // Multipart message - get plain text part
        foreach ($structure->parts as $partNum => $part) {
            if ($part->subtype == 'PLAIN') {
                $body = imap_fetchbody($imap, $email_number, $partNum + 1);
                if ($part->encoding == 3) {
                    $body = base64_decode($body);
                } elseif ($part->encoding == 4) {
                    $body = quoted_printable_decode($body);
                }
                break;
            }
        }
    }

    return $body;
}

function getAttachments($imap, $email_number) {
    $attachments = [];
    $structure = imap_fetchstructure($imap, $email_number);

    if (!isset($structure->parts)) {
        return $attachments;
    }

    foreach ($structure->parts as $partNum => $part) {
        $attachment = null;

        // Check for attachment
        if ($part->ifdparameters) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute) == 'filename') {
                    $attachment = ['filename' => $param->value];
                    break;
                }
            }
        }

        if (!$attachment && $part->ifparameters) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) == 'name') {
                    $attachment = ['filename' => $param->value];
                    break;
                }
            }
        }

        if ($attachment) {
            $data = imap_fetchbody($imap, $email_number, $partNum + 1);

            // Decode based on encoding
            if ($part->encoding == 3) { // BASE64
                $data = base64_decode($data);
            } elseif ($part->encoding == 4) { // QUOTED-PRINTABLE
                $data = quoted_printable_decode($data);
            }

            $attachment['data'] = $data;
            $attachments[] = $attachment;
        }

        // Check for nested parts (e.g., in multipart/mixed)
        if (isset($part->parts)) {
            foreach ($part->parts as $subPartNum => $subPart) {
                $subAttachment = null;

                if ($subPart->ifdparameters) {
                    foreach ($subPart->dparameters as $param) {
                        if (strtolower($param->attribute) == 'filename') {
                            $subAttachment = ['filename' => $param->value];
                            break;
                        }
                    }
                }

                if (!$subAttachment && $subPart->ifparameters) {
                    foreach ($subPart->parameters as $param) {
                        if (strtolower($param->attribute) == 'name') {
                            $subAttachment = ['filename' => $param->value];
                            break;
                        }
                    }
                }

                if ($subAttachment) {
                    $data = imap_fetchbody($imap, $email_number, ($partNum + 1) . '.' . ($subPartNum + 1));

                    if ($subPart->encoding == 3) {
                        $data = base64_decode($data);
                    } elseif ($subPart->encoding == 4) {
                        $data = quoted_printable_decode($data);
                    }

                    $subAttachment['data'] = $data;
                    $attachments[] = $subAttachment;
                }
            }
        }
    }

    return $attachments;
}

// ============================================
// DATABASE FUNCTIONS
// ============================================

function logSubmission($pdo, $email, $name, $subject, $body, $attachment_count, $sender_type) {
    $stmt = $pdo->prepare("
        INSERT INTO email_submissions
        (sender_email, sender_name, subject, body, attachment_count, sender_type, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'received', NOW())
    ");
    $stmt->execute([$email, $name, $subject, substr($body, 0, 5000), $attachment_count, $sender_type]);
    return $pdo->lastInsertId();
}

function updateSubmissionStatus($pdo, $id, $status, $notes = null, $processed_files = null) {
    $stmt = $pdo->prepare("
        UPDATE email_submissions
        SET status = ?, admin_notes = ?, processed_files = ?, processed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$status, $notes, $processed_files, $id]);
}

// ============================================
// ARTWORK PROCESSING FUNCTIONS
// ============================================

function parseArtworkMetadata($subject, $body) {
    $metadata = [
        'title' => 'Untitled',
        'dimensions' => '',
        'medium' => '',
        'price' => '',
        'status' => 'available',
        'description' => ''
    ];

    // Try to parse structured body first
    $lines = explode("\n", $body);
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/^title:\s*(.+)/i', $line, $m)) {
            $metadata['title'] = trim($m[1]);
        } elseif (preg_match('/^size:\s*(.+)/i', $line, $m)) {
            $metadata['dimensions'] = trim($m[1]);
        } elseif (preg_match('/^dimensions?:\s*(.+)/i', $line, $m)) {
            $metadata['dimensions'] = trim($m[1]);
        } elseif (preg_match('/^medium:\s*(.+)/i', $line, $m)) {
            $metadata['medium'] = trim($m[1]);
        } elseif (preg_match('/^price:\s*(.+)/i', $line, $m)) {
            $metadata['price'] = trim($m[1]);
        } elseif (preg_match('/^status:\s*(.+)/i', $line, $m)) {
            $status = strtolower(trim($m[1]));
            if (in_array($status, ['available', 'sold', 'other'])) {
                $metadata['status'] = $status;
            }
        } elseif (preg_match('/^description:\s*(.+)/i', $line, $m)) {
            $metadata['description'] = trim($m[1]);
        }
    }

    // If no structured title found, try to parse subject line
    if ($metadata['title'] === 'Untitled' && !empty($subject)) {
        if (preg_match('/^["\'](.+?)["\']/', $subject, $m)) {
            $metadata['title'] = trim($m[1]);
            $remainder = trim(substr($subject, strlen($m[0])));

            if (preg_match('/^(\d+\s*x\s*\d+)/i', $remainder, $dm)) {
                $metadata['dimensions'] = $dm[1];
                $remainder = trim(substr($remainder, strlen($dm[0])));
            }
            if (!empty($remainder)) {
                $metadata['medium'] = $remainder;
            }
        } else {
            $metadata['title'] = $subject;
        }
    }

    return $metadata;
}

function processArtwork($imageData, $extension, $artist, $metadata, $index) {
    $subdomain = $artist['subdomain'];
    $subdomain_dir = SUBDOMAINS_PATH . "/{$subdomain}.painttwits.com";
    $uploads_dir = $subdomain_dir . '/uploads';
    $dzi_dir = $uploads_dir . '/dzi';

    if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);
    if (!is_dir($dzi_dir)) mkdir($dzi_dir, 0755, true);

    $timestamp = time();
    $rand = substr(md5(uniqid()), 0, 8);
    $base_name = "art_{$timestamp}_{$rand}";
    $original_filename = "{$base_name}.jpg";

    $temp_file = "/tmp/{$base_name}_temp.{$extension}";
    file_put_contents($temp_file, $imageData);

    $image = new Imagick($temp_file);
    $image->autoOrient();

    if (in_array($extension, ['heic', 'tiff'])) {
        $image->setImageFormat('jpeg');
    }

    $orig_width = $image->getImageWidth();
    $orig_height = $image->getImageHeight();

    $image->setImageCompressionQuality(92);
    $image->writeImage("{$uploads_dir}/{$original_filename}");

    $sizes = ['large' => 1200, 'medium' => 800, 'small' => 400];
    foreach ($sizes as $suffix => $max_dim) {
        $resized = clone $image;
        $resized->thumbnailImage($max_dim, $max_dim, true);
        $resized->setImageCompressionQuality(85);
        $resized->writeImage("{$uploads_dir}/{$base_name}_{$suffix}.jpg");
        $resized->destroy();
    }

    // Social image
    $social = new Imagick();
    $social->newImage(1200, 630, new ImagickPixel('#f5f5f5'));
    $social->setImageFormat('jpeg');
    $thumb = clone $image;
    $thumb->thumbnailImage(1200, 630, true);
    $x = (1200 - $thumb->getImageWidth()) / 2;
    $y = (630 - $thumb->getImageHeight()) / 2;
    $social->compositeImage($thumb, Imagick::COMPOSITE_OVER, $x, $y);
    $social->setImageCompressionQuality(85);
    $social->writeImage("{$uploads_dir}/{$base_name}_social.jpg");
    $social->destroy();
    $thumb->destroy();

    // DZI tiles for large images
    $dzi_generated = false;
    if ($orig_width > 2000 || $orig_height > 2000) {
        $dzi_generated = generateDZI($image, $base_name, $dzi_dir);
    }

    $image->destroy();
    unlink($temp_file);

    // Update metadata
    $meta_file = $subdomain_dir . '/artwork_meta.json';
    $meta = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : [];
    if (!is_array($meta)) $meta = [];

    $title = $metadata['title'];
    if ($index > 0 && $title !== 'Untitled') {
        $title .= ' (' . ($index + 1) . ')';
    }

    $meta[$original_filename] = [
        'title' => $title,
        'dimensions' => $metadata['dimensions'],
        'medium' => $metadata['medium'],
        'price' => $metadata['price'],
        'status' => $metadata['status'],
        'description' => $metadata['description'],
        'uploaded_via' => 'email',
        'uploaded_at' => date('Y-m-d H:i:s')
    ];

    file_put_contents($meta_file, json_encode($meta, JSON_PRETTY_PRINT));

    return [
        'success' => true,
        'filename' => $original_filename,
        'title' => $title,
        'width' => $orig_width,
        'height' => $orig_height,
        'dzi' => $dzi_generated,
        'gallery_url' => "https://{$subdomain}.painttwits.com",
        'artwork_url' => "https://{$subdomain}.painttwits.com/art.php?f=" . urlencode($original_filename),
        'zoom_url' => $dzi_generated ? "https://{$subdomain}.painttwits.com/zoom.php?f=" . urlencode($original_filename) : null
    ];
}

function generateDZI($image, $base_name, $dzi_dir) {
    $tile_size = 256;
    $overlap = 1;
    $format = 'jpg';
    $quality = 85;

    $width = $image->getImageWidth();
    $height = $image->getImageHeight();
    $max_dimension = max($width, $height);
    $max_level = (int)ceil(log($max_dimension, 2));

    $tiles_dir = "{$dzi_dir}/{$base_name}_files";
    if (!is_dir($tiles_dir)) mkdir($tiles_dir, 0755, true);

    for ($level = $max_level; $level >= 0; $level--) {
        $scale = pow(2, $max_level - $level);
        $level_width = (int)ceil($width / $scale);
        $level_height = (int)ceil($height / $scale);

        $level_dir = "{$tiles_dir}/{$level}";
        if (!is_dir($level_dir)) mkdir($level_dir, 0755, true);

        $level_image = clone $image;
        $level_image->scaleImage($level_width, $level_height);

        $cols = (int)ceil($level_width / $tile_size);
        $rows = (int)ceil($level_height / $tile_size);

        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $x = $col * $tile_size - ($col > 0 ? $overlap : 0);
                $y = $row * $tile_size - ($row > 0 ? $overlap : 0);

                $tile_width = $tile_size + ($col > 0 ? $overlap : 0) + ($col < $cols - 1 ? $overlap : 0);
                $tile_height = $tile_size + ($row > 0 ? $overlap : 0) + ($row < $rows - 1 ? $overlap : 0);

                $x = max(0, $x);
                $y = max(0, $y);
                $tile_width = min($tile_width, $level_width - $x);
                $tile_height = min($tile_height, $level_height - $y);

                if ($tile_width > 0 && $tile_height > 0) {
                    $tile = clone $level_image;
                    $tile->cropImage($tile_width, $tile_height, $x, $y);
                    $tile->setImageCompressionQuality($quality);
                    $tile->writeImage("{$level_dir}/{$col}_{$row}.{$format}");
                    $tile->destroy();
                }
            }
        }
        $level_image->destroy();
    }

    $dzi_content = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $dzi_content .= '<Image xmlns="http://schemas.microsoft.com/deepzoom/2008" Format="' . $format . '" Overlap="' . $overlap . '" TileSize="' . $tile_size . '">' . "\n";
    $dzi_content .= '  <Size Height="' . $height . '" Width="' . $width . '"/>' . "\n";
    $dzi_content .= '</Image>';

    file_put_contents("{$dzi_dir}/{$base_name}.dzi", $dzi_content);

    return true;
}

function syncArtworkToCenter($pdo, $artist, $artwork) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO artworks (artist_id, filename, title, uploaded_at, uploaded_via)
            VALUES (?, ?, ?, NOW(), 'email')
        ");
        $stmt->execute([$artist['id'], $artwork['filename'], $artwork['title']]);
        log_message("Synced to central database: {$artwork['filename']}");
    } catch (Exception $e) {
        log_error("Failed to sync to central: " . $e->getMessage());
    }
}

// ============================================
// EMAIL SENDING FUNCTIONS
// ============================================

function sendEmail($to, $subject, $body) {
    $from_email = EMAIL_FROM;
    $from_name = EMAIL_FROM_NAME;

    $headers = [
        'From' => "{$from_name} <{$from_email}>",
        'Reply-To' => $from_email,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/html; charset=UTF-8',
        'X-Mailer' => 'Painttwits/1.0'
    ];

    $header_string = '';
    foreach ($headers as $key => $value) {
        $header_string .= "{$key}: {$value}\r\n";
    }

    $html_body = getEmailTemplate($body);
    return @mail($to, $subject, $html_body, $header_string);
}

function getEmailTemplate($content) {
    return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 20px;">
        <tr><td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;">
                <tr><td style="background:#111;padding:24px;text-align:center;">
                    <h1 style="margin:0;color:#fff;font-size:24px;font-weight:normal;letter-spacing:2px;">painttwits</h1>
                </td></tr>
                <tr><td style="padding:32px 24px;">' . $content . '</td></tr>
                <tr><td style="background:#f9f9f9;padding:20px 24px;text-align:center;border-top:1px solid #eee;">
                    <p style="margin:0;color:#888;font-size:13px;"><a href="https://painttwits.com" style="color:#888;text-decoration:none;">painttwits.com</a></p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>';
}

function sendNotApprovedEmail($email, $name, $saved_count = 0) {
    $name = $name ?: 'there';
    $subject = "Thanks for your interest in painttwits!";

    $saved_message = '';
    if ($saved_count > 0) {
        $saved_message = '<div style="margin:0 0 16px;padding:16px;background:#e8f5e9;border-radius:6px;border-left:4px solid #4caf50;">
            <p style="margin:0;color:#2e7d32;font-size:15px;line-height:1.6;"><strong>Good news!</strong> We\'ve saved ' . $saved_count . ' of your artwork' . ($saved_count > 1 ? 's' : '') . '. Once approved, ' . ($saved_count > 1 ? 'they\'ll' : 'it\'ll') . ' be automatically added to your new gallery.</p>
        </div>';
    }

    $body = '<h2 style="margin:0 0 16px;color:#111;font-size:20px;font-weight:normal;">Hey ' . htmlspecialchars($name) . ',</h2>
        <p style="margin:0 0 16px;color:#333;font-size:15px;line-height:1.6;">Thanks for emailing your artwork to painttwits! We\'d love to feature your work.</p>
        ' . $saved_message . '
        <p style="margin:0 0 16px;color:#333;font-size:15px;line-height:1.6;">To get started, apply for your own artist page. Once approved, you\'ll get your own subdomain at <strong>yourname.painttwits.com</strong>.</p>
        <div style="margin:24px 0;text-align:center;">
            <a href="https://painttwits.com/apply.php?email=' . urlencode($email) . '" style="display:inline-block;background:#111;color:#fff;padding:14px 28px;text-decoration:none;border-radius:6px;font-size:16px;">Apply Now</a>
        </div>
        <p style="margin:0 0 16px;color:#333;font-size:15px;line-height:1.6;">Once approved, just email your artwork to <strong>newart@painttwits.com</strong> and it\'ll be added automatically.</p>
        <p style="margin:0;color:#888;font-size:14px;">— The painttwits team</p>';
    sendEmail($email, $subject, $body);
}

function sendNoAttachmentsEmail($email, $name) {
    $subject = "No images found in your email";
    $body = '<h2 style="margin:0 0 16px;color:#111;font-size:20px;font-weight:normal;">Hey ' . htmlspecialchars($name) . ',</h2>
        <p style="margin:0 0 16px;color:#333;font-size:15px;line-height:1.6;">We received your email but couldn\'t find any image attachments.</p>
        <p style="margin:0 0 16px;color:#333;font-size:15px;line-height:1.6;"><strong>To add artwork via email:</strong></p>
        <ol style="margin:0 0 24px;padding-left:20px;color:#333;font-size:15px;line-height:1.8;">
            <li>Attach your image (JPG, PNG, or HEIC)</li>
            <li>Use the subject line for the title, e.g. <em>"Sunset" 24x36 oil on canvas</em></li>
            <li>Send to <strong>newart@painttwits.com</strong></li>
        </ol>
        <p style="margin:0;color:#888;font-size:14px;">— The painttwits team</p>';
    sendEmail($email, $subject, $body);
}

function sendSuccessEmail($email, $artist, $artworks) {
    $count = count($artworks);
    $subject = $count === 1 ? "Your artwork is live!" : "{$count} artworks added to your gallery!";

    $artwork_list = '';
    foreach ($artworks as $art) {
        $artwork_list .= '<div style="margin:16px 0;padding:16px;background:#f9f9f9;border-radius:6px;">
            <p style="margin:0 0 8px;font-weight:600;color:#111;">' . htmlspecialchars($art['title']) . '</p>
            <p style="margin:0 0 8px;color:#666;font-size:14px;">' . $art['width'] . ' x ' . $art['height'] . 'px</p>
            <p style="margin:0;"><a href="' . $art['artwork_url'] . '" style="color:#4a9eff;text-decoration:none;">View artwork</a>';
        if ($art['zoom_url']) {
            $artwork_list .= ' &nbsp;|&nbsp; <a href="' . $art['zoom_url'] . '" style="color:#4a9eff;text-decoration:none;">Deep zoom</a>';
        }
        $artwork_list .= '</p></div>';
    }

    $body = '<h2 style="margin:0 0 16px;color:#111;font-size:20px;font-weight:normal;">Hey ' . htmlspecialchars($artist['name']) . ',</h2>
        <p style="margin:0 0 16px;color:#333;font-size:15px;line-height:1.6;">' . ($count === 1 ? 'Your artwork has' : 'Your artworks have') . ' been added to your gallery!</p>
        ' . $artwork_list . '
        <div style="margin:24px 0;text-align:center;">
            <a href="https://' . $artist['subdomain'] . '.painttwits.com" style="display:inline-block;background:#111;color:#fff;padding:14px 28px;text-decoration:none;border-radius:6px;font-size:16px;">View Your Gallery</a>
        </div>
        <p style="margin:0;color:#888;font-size:14px;">— The painttwits team</p>';
    sendEmail($email, $subject, $body);
}

function sendFailureEmail($email, $name) {
    $subject = "There was a problem with your upload";
    $body = '<h2 style="margin:0 0 16px;color:#111;font-size:20px;font-weight:normal;">Hey ' . htmlspecialchars($name) . ',</h2>
        <p style="margin:0 0 16px;color:#333;font-size:15px;line-height:1.6;">We received your email but had trouble processing the images.</p>
        <p style="margin:0 0 16px;color:#333;font-size:15px;line-height:1.6;"><strong>Tips for successful uploads:</strong></p>
        <ul style="margin:0 0 24px;padding-left:20px;color:#333;font-size:15px;line-height:1.8;">
            <li>Use JPG, PNG, or HEIC format</li>
            <li>Keep images under 10MB each</li>
            <li>Ideal resolution: 3000-5000px on the long edge</li>
        </ul>
        <p style="margin:0;color:#888;font-size:14px;">— The painttwits team</p>';
    sendEmail($email, $subject, $body);
}
