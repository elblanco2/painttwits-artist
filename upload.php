<?php
/**
 * Painttwits Artist Portfolio - Upload Handler
 * Handles artwork uploads with responsive image generation
 * Syncs to painttwits.com central database
 * REQUIRES OAuth authentication
 */

session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['artist_authenticated']) || !$_SESSION['artist_authenticated']) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required. Please login first.']);
    exit;
}

// Check session timeout (24 hours)
$timeout = 86400;
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $timeout) {
    $_SESSION = [];
    session_destroy();
    http_response_code(401);
    echo json_encode(['error' => 'Session expired. Please login again.']);
    exit;
}

// Load config
$config_file = __DIR__ . '/artist_config.php';
$config = file_exists($config_file) ? require $config_file : [];

$artist_id = $config['artist_id'] ?? null;
$painttwits_api = $config['painttwits_api'] ?? 'https://painttwits.com/api';
$api_key = $config['api_key'] ?? null;

// Image sizes to generate
$sizes = [
    'large' => 1200,
    'medium' => 800,
    'small' => 400
];

// Social media image dimensions (1200x630 for optimal OG/Twitter cards)
$social_width = 1200;
$social_height = 630;

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Check for file
if (!isset($_FILES['artwork']) || $_FILES['artwork']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['artwork'];

// Validate file type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff', 'image/heic'];

if (!in_array($mimeType, $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Allowed: JPEG, PNG, GIF, WebP, TIFF, HEIC']);
    exit;
}

// Validate file size (20MB max)
$maxSize = 50 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large. Maximum: 50MB']);
    exit;
}

// Create upload directory if needed
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique base filename
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$baseName = uniqid('art_', true);
$filename = $baseName . '.' . $ext;
$destination = $uploadDir . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $destination)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file']);
    exit;
}

// Extract GPS coordinates from EXIF if available (before any conversions)
$exifGps = ['latitude' => null, 'longitude' => null];
if (function_exists('exif_read_data') && in_array($mimeType, ['image/jpeg', 'image/tiff'])) {
    try {
        $exif = @exif_read_data($destination, 'GPS', true);
        if ($exif && isset($exif['GPS'])) {
            $gps = $exif['GPS'];
            if (isset($gps['GPSLatitude'], $gps['GPSLatitudeRef'], $gps['GPSLongitude'], $gps['GPSLongitudeRef'])) {
                $lat = exifGpsToDecimal($gps['GPSLatitude'], $gps['GPSLatitudeRef']);
                $lng = exifGpsToDecimal($gps['GPSLongitude'], $gps['GPSLongitudeRef']);
                if ($lat !== null && $lng !== null && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                    $exifGps['latitude'] = round($lat, 8);
                    $exifGps['longitude'] = round($lng, 8);
                }
            }
        }
    } catch (Exception $e) {
        // GPS extraction failed, continue without it
    }
}

/**
 * Convert EXIF GPS to decimal degrees
 */
function exifGpsToDecimal($coord, $ref) {
    if (!is_array($coord) || count($coord) < 3) return null;
    $deg = parseFrac($coord[0]);
    $min = parseFrac($coord[1]);
    $sec = parseFrac($coord[2]);
    if ($deg === null || $min === null || $sec === null) return null;
    $decimal = $deg + ($min / 60) + ($sec / 3600);
    if ($ref === 'S' || $ref === 'W') $decimal *= -1;
    return $decimal;
}

function parseFrac($v) {
    if (is_numeric($v)) return (float)$v;
    if (is_string($v) && strpos($v, '/') !== false) {
        $p = explode('/', $v);
        if (count($p) === 2 && is_numeric($p[0]) && is_numeric($p[1]) && $p[1] != 0) {
            return (float)$p[0] / (float)$p[1];
        }
    }
    return null;
}

// For TIFF/HEIC, convert to JPEG first using Imagick
if (in_array($mimeType, ["image/tiff", "image/heic"])) {
    if (class_exists("Imagick")) {
        try {
            $img = new Imagick($destination);
            $img->setImageFormat("jpeg");
            $img->setImageCompressionQuality(92);
            $img->autoOrient();
            
            // Update filename to .jpg
            $newFilename = $baseName . ".jpg";
            $newDestination = $uploadDir . $newFilename;
            $img->writeImage($newDestination);
            $img->destroy();
            
            // Remove original TIFF/HEIC
            unlink($destination);
            
            // Update variables
            $destination = $newDestination;
            $filename = $newFilename;
            $ext = "jpg";
            $mimeType = "image/jpeg";
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to convert image: " . $e->getMessage()]);
            exit;
        }
    } else {
        http_response_code(500);
        echo json_encode(["error" => "TIFF/HEIC support requires Imagick extension"]);
        exit;
    }
}


// Generate resized versions
$generatedSizes = ['original' => $filename];

if (function_exists('imagecreatefromjpeg')) {
    $imageInfo = getimagesize($destination);
    if ($imageInfo) {
        $origWidth = $imageInfo[0];
        $origHeight = $imageInfo[1];

        $sourceImage = null;
        switch ($mimeType) {
            case 'image/jpeg':
                $sourceImage = imagecreatefromjpeg($destination);
                // Fix EXIF orientation for JPEGs
                // imagerotate() rotates counter-clockwise
                if (function_exists('exif_read_data')) {
                    $exif = @exif_read_data($destination);
                    if ($exif && isset($exif['Orientation'])) {
                        switch ($exif['Orientation']) {
                            case 3: // 180 degrees
                                $sourceImage = imagerotate($sourceImage, 180, 0);
                                break;
                            case 6: // 90 CW -> rotate 270 CCW
                                $sourceImage = imagerotate($sourceImage, 270, 0);
                                // Swap dimensions after rotation
                                $temp = $origWidth;
                                $origWidth = $origHeight;
                                $origHeight = $temp;
                                break;
                            case 8: // 90 CCW -> rotate 90 CCW
                                $sourceImage = imagerotate($sourceImage, 90, 0);
                                // Swap dimensions after rotation
                                $temp = $origWidth;
                                $origWidth = $origHeight;
                                $origHeight = $temp;
                                break;
                        }
                    }
                }
                break;
            case 'image/png':
                $sourceImage = imagecreatefrompng($destination);
                break;
            case 'image/gif':
                $sourceImage = imagecreatefromgif($destination);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $sourceImage = imagecreatefromwebp($destination);
                }
                break;
        }

        if ($sourceImage) {
            foreach ($sizes as $sizeName => $maxDimension) {
                if ($origWidth > $maxDimension || $origHeight > $maxDimension) {
                    if ($origWidth > $origHeight) {
                        $newWidth = $maxDimension;
                        $newHeight = intval($origHeight * ($maxDimension / $origWidth));
                    } else {
                        $newHeight = $maxDimension;
                        $newWidth = intval($origWidth * ($maxDimension / $origHeight));
                    }

                    $resized = imagecreatetruecolor($newWidth, $newHeight);

                    if ($mimeType === 'image/png') {
                        imagealphablending($resized, false);
                        imagesavealpha($resized, true);
                        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                        imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
                    }

                    imagecopyresampled(
                        $resized, $sourceImage,
                        0, 0, 0, 0,
                        $newWidth, $newHeight,
                        $origWidth, $origHeight
                    );

                    $resizedFilename = $baseName . '_' . $sizeName . '.' . $ext;
                    $resizedPath = $uploadDir . $resizedFilename;

                    switch ($mimeType) {
                        case 'image/jpeg':
                            imagejpeg($resized, $resizedPath, 85);
                            break;
                        case 'image/png':
                            imagepng($resized, $resizedPath, 8);
                            break;
                        case 'image/gif':
                            imagegif($resized, $resizedPath);
                            break;
                        case 'image/webp':
                            if (function_exists('imagewebp')) {
                                imagewebp($resized, $resizedPath, 85);
                            }
                            break;
                    }

                    imagedestroy($resized);
                    $generatedSizes[$sizeName] = $resizedFilename;
                } else {
                    $generatedSizes[$sizeName] = $filename;
                }
            }

            // Generate square map thumbnail (80x80, center cropped)
            $mapSize = 80;
            $mapThumb = imagecreatetruecolor($mapSize, $mapSize);

            // Calculate crop area (center square)
            $cropSize = min($origWidth, $origHeight);
            $cropX = intval(($origWidth - $cropSize) / 2);
            $cropY = intval(($origHeight - $cropSize) / 2);

            imagecopyresampled(
                $mapThumb, $sourceImage,
                0, 0, $cropX, $cropY,
                $mapSize, $mapSize,
                $cropSize, $cropSize
            );

            $mapFilename = $baseName . '_map.' . $ext;
            $mapPath = $uploadDir . $mapFilename;

            switch ($mimeType) {
                case 'image/jpeg':
                    imagejpeg($mapThumb, $mapPath, 80);
                    break;
                case 'image/png':
                    imagepng($mapThumb, $mapPath, 8);
                    break;
                case 'image/gif':
                    imagegif($mapThumb, $mapPath);
                    break;
                case 'image/webp':
                    if (function_exists('imagewebp')) {
                        imagewebp($mapThumb, $mapPath, 80);
                    }
                    break;
            }
            imagedestroy($mapThumb);
            $generatedSizes['map'] = $mapFilename;

            // Generate social media optimized image (1200x630)
            $socialImage = imagecreatetruecolor($social_width, $social_height);

            // Fill with light background
            $bg = imagecolorallocate($socialImage, 250, 250, 250);
            imagefill($socialImage, 0, 0, $bg);

            // Calculate scaling to fit artwork centered with padding
            $padding = 40;
            $available_w = $social_width - ($padding * 2);
            $available_h = $social_height - ($padding * 2);

            $scale = min($available_w / $origWidth, $available_h / $origHeight);
            $dst_w = intval($origWidth * $scale);
            $dst_h = intval($origHeight * $scale);
            $dst_x = intval(($social_width - $dst_w) / 2);
            $dst_y = intval(($social_height - $dst_h) / 2);

            // Reload source for social image (may have been destroyed)
            $socialSource = null;
            switch ($mimeType) {
                case 'image/jpeg':
                    $socialSource = imagecreatefromjpeg($destination);
                    // Apply same EXIF rotation
                    if (function_exists('exif_read_data')) {
                        $exif = @exif_read_data($destination);
                        if ($exif && isset($exif['Orientation'])) {
                            switch ($exif['Orientation']) {
                                case 3:
                                    $socialSource = imagerotate($socialSource, 180, 0);
                                    break;
                                case 6:
                                    $socialSource = imagerotate($socialSource, 270, 0);
                                    break;
                                case 8:
                                    $socialSource = imagerotate($socialSource, 90, 0);
                                    break;
                            }
                        }
                    }
                    break;
                case 'image/png':
                    $socialSource = imagecreatefrompng($destination);
                    break;
                case 'image/gif':
                    $socialSource = imagecreatefromgif($destination);
                    break;
                case 'image/webp':
                    if (function_exists('imagecreatefromwebp')) {
                        $socialSource = imagecreatefromwebp($destination);
                    }
                    break;
            }

            if ($socialSource) {
                imagecopyresampled(
                    $socialImage, $socialSource,
                    $dst_x, $dst_y, 0, 0,
                    $dst_w, $dst_h,
                    $origWidth, $origHeight
                );
                imagedestroy($socialSource);
            }

            // Add subtle watermark
            $watermark_color = imagecolorallocatealpha($socialImage, 17, 17, 17, 100);
            imagestring($socialImage, 2, $social_width - 90, $social_height - 15, 'painttwits.com', $watermark_color);

            // Save social image
            $socialFilename = $baseName . '_social.jpg';
            $socialPath = $uploadDir . $socialFilename;
            imagejpeg($socialImage, $socialPath, 90);
            imagedestroy($socialImage);
            $generatedSizes['social'] = $socialFilename;

            imagedestroy($sourceImage);

            // Generate DZI tiles for high-res images (>3000px)
            if ($origWidth >= 3000 || $origHeight >= 3000) {
                require_once __DIR__ . '/generate_dzi.php';
                $dziResult = generateDZI($filename, $uploadDir);
                if ($dziResult['success']) {
                    $generatedSizes['dzi'] = 'ready';
                } else {
                    $generatedSizes['dzi'] = 'failed';
                    // Log but don't fail upload - DZI can be generated on-demand later
                    error_log("DZI generation failed for {$filename}: " . ($dziResult['error'] ?? 'unknown'));
                }
            }
        }
    }
}

// Sync to painttwits.com if configured
$synced = false;
$syncError = null;
$syncDebug = [];

if ($artist_id && $api_key) {
    $syncData = [
        'artist_id' => $artist_id,
        'api_key' => $api_key,
        'title' => pathinfo($file['name'], PATHINFO_FILENAME),
        'filename' => $filename,
        'files' => $generatedSizes,
        'original_name' => $file['name'],
        'size' => $file['size'],
        'mime_type' => $mimeType,
        'exif_latitude' => $exifGps['latitude'],
        'exif_longitude' => $exifGps['longitude']
    ];

    $syncDebug['request_url'] = $painttwits_api . '/sync_artwork.php';
    $syncDebug['request_data'] = $syncData;

    $ch = curl_init($painttwits_api . '/sync_artwork.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($syncData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $syncResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $syncDebug['http_code'] = $httpCode;
    $syncDebug['response'] = $syncResponse;
    $syncDebug['curl_error'] = $curlError;

    $syncResult = json_decode($syncResponse, true);
    $synced = isset($syncResult['success']) && $syncResult['success'];

    if (!$synced) {
        $syncError = $syncResult['error'] ?? $curlError ?: 'Unknown sync error';
    }

    // Log sync attempt
    $logFile = __DIR__ . '/logs/sync.log';
    if (!is_dir(__DIR__ . '/logs')) {
        mkdir(__DIR__ . '/logs', 0755, true);
    }
    $logEntry = date('Y-m-d H:i:s') . " | artist_id={$artist_id} | file={$filename} | synced=" . ($synced ? 'YES' : 'NO') . " | http={$httpCode} | response={$syncResponse}\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
} else {
    $syncDebug['skipped'] = 'No artist_id or api_key configured';
    $syncDebug['artist_id'] = $artist_id;
    $syncDebug['api_key_set'] = !empty($api_key);
}

echo json_encode([
    'success' => true,
    'filename' => $filename,
    'files' => $generatedSizes,
    'synced_to_painttwits' => $synced,
    'sync_error' => $syncError,
    'sync_debug' => $syncDebug
]);
