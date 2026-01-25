<?php
/**
 * DZI (Deep Zoom Image) Tile Generator for Painttwits
 *
 * Generates tile pyramid for OpenSeaDragon viewing.
 * Adapted from snappy_private/email_parser.php
 *
 * Usage:
 *   As function: generateDZI('art_abc123.jpg', '/path/to/uploads/')
 *   As endpoint: generate_dzi.php?f=art_abc123.jpg (requires auth)
 */

// Configuration
define('DZI_TILE_SIZE', 256);
define('DZI_OVERLAP', 1);
define('DZI_FORMAT', 'jpg');
define('DZI_QUALITY', 85);
define('DZI_MIN_DIMENSION', 3000); // Only auto-generate for images larger than this

/**
 * Generate DZI tiles for an image
 *
 * @param string $filename The image filename (e.g., 'art_abc123.jpg')
 * @param string $uploadsDir The uploads directory path
 * @return array ['success' => bool, 'error' => string|null, 'tiles' => int|null]
 */
function generateDZI($filename, $uploadsDir) {
    $uploadsDir = rtrim($uploadsDir, '/');
    $originalPath = $uploadsDir . '/' . $filename;

    // Validate file exists
    if (!file_exists($originalPath)) {
        return ['success' => false, 'error' => 'Original file not found: ' . $filename];
    }

    // Get filename parts
    $pathInfo = pathinfo($filename);
    $baseName = $pathInfo['filename'];

    // DZI output paths
    $dziDir = $uploadsDir . '/dzi';
    $dziFilesDir = $dziDir . '/' . $baseName . '_files';
    $dziXmlPath = $dziDir . '/' . $baseName . '.dzi';

    // Check if already generated
    if (file_exists($dziXmlPath) && is_dir($dziFilesDir)) {
        return ['success' => true, 'error' => null, 'message' => 'DZI already exists', 'skipped' => true];
    }

    // Create directories
    if (!is_dir($dziDir)) {
        if (!@mkdir($dziDir, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create dzi directory'];
        }
    }

    if (!is_dir($dziFilesDir)) {
        if (!@mkdir($dziFilesDir, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create tiles directory'];
        }
    }

    try {
        // Load image with Imagick
        $image = new Imagick($originalPath);

        // Auto-orient based on EXIF
        $image->autoOrient();

        // Get dimensions
        $originalWidth = $image->getImageWidth();
        $originalHeight = $image->getImageHeight();

        // Clear the image - we'll reload for each level
        $image->clear();

        // Calculate zoom levels
        $maxDimension = max($originalWidth, $originalHeight);
        $maxZoomLevel = (int)ceil(log($maxDimension, 2));

        $totalTiles = 0;

        // Generate tiles for each zoom level
        for ($level = $maxZoomLevel; $level >= 0; $level--) {
            $levelDir = $dziFilesDir . '/' . $level;
            if (!@mkdir($levelDir, 0755, true)) {
                // Clean up on failure
                rrmdir($dziFilesDir);
                return ['success' => false, 'error' => 'Failed to create level directory: ' . $level];
            }

            // Calculate dimensions at this level
            $scale = pow(2, $maxZoomLevel - $level);
            $currentWidth = (int)ceil($originalWidth / $scale);
            $currentHeight = (int)ceil($originalHeight / $scale);

            // Skip if too small
            if ($currentWidth < 1 || $currentHeight < 1) {
                continue;
            }

            // Load and resize image for this level
            $levelImage = new Imagick($originalPath);
            $levelImage->autoOrient();
            $levelImage->resizeImage($currentWidth, $currentHeight, Imagick::FILTER_LANCZOS, 1);

            // Calculate number of tiles
            $cols = (int)ceil($currentWidth / DZI_TILE_SIZE);
            $rows = (int)ceil($currentHeight / DZI_TILE_SIZE);

            // Generate tiles
            for ($row = 0; $row < $rows; $row++) {
                for ($col = 0; $col < $cols; $col++) {
                    $x = $col * DZI_TILE_SIZE;
                    $y = $row * DZI_TILE_SIZE;

                    // Calculate crop region with overlap
                    $cropX = max(0, $x - DZI_OVERLAP);
                    $cropY = max(0, $y - DZI_OVERLAP);

                    // Calculate crop size
                    $cropWidth = min(DZI_TILE_SIZE + DZI_OVERLAP * 2, $currentWidth - $cropX);
                    $cropHeight = min(DZI_TILE_SIZE + DZI_OVERLAP * 2, $currentHeight - $cropY);

                    // Adjust for edge tiles
                    if ($x == 0) $cropWidth = min(DZI_TILE_SIZE + DZI_OVERLAP, $currentWidth);
                    if ($y == 0) $cropHeight = min(DZI_TILE_SIZE + DZI_OVERLAP, $currentHeight);

                    // Clone and crop
                    $tile = clone $levelImage;
                    $tile->cropImage($cropWidth, $cropHeight, $cropX, $cropY);
                    $tile->setImageFormat(DZI_FORMAT);
                    $tile->setImageCompressionQuality(DZI_QUALITY);

                    // Save tile
                    $tilePath = $levelDir . '/' . $col . '_' . $row . '.' . DZI_FORMAT;
                    $tile->writeImage($tilePath);
                    $tile->clear();

                    $totalTiles++;
                }
            }

            $levelImage->clear();
        }

        // Create DZI XML descriptor
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<Image TileSize="' . DZI_TILE_SIZE . '" Overlap="' . DZI_OVERLAP . '" Format="' . DZI_FORMAT . '" xmlns="http://schemas.microsoft.com/deepzoom/2008">' . "\n";
        $xml .= '  <Size Width="' . $originalWidth . '" Height="' . $originalHeight . '"/>' . "\n";
        $xml .= '</Image>';

        if (!file_put_contents($dziXmlPath, $xml)) {
            rrmdir($dziFilesDir);
            return ['success' => false, 'error' => 'Failed to write DZI descriptor'];
        }

        return [
            'success' => true,
            'error' => null,
            'tiles' => $totalTiles,
            'levels' => $maxZoomLevel + 1,
            'dimensions' => ['width' => $originalWidth, 'height' => $originalHeight],
            'dzi_path' => $dziXmlPath
        ];

    } catch (ImagickException $e) {
        // Clean up on failure
        if (is_dir($dziFilesDir)) {
            rrmdir($dziFilesDir);
        }
        return ['success' => false, 'error' => 'Imagick error: ' . $e->getMessage()];
    }
}

/**
 * Check if DZI exists for a file
 */
function dziExists($filename, $uploadsDir) {
    $uploadsDir = rtrim($uploadsDir, '/');
    $baseName = pathinfo($filename, PATHINFO_FILENAME);
    $dziXmlPath = $uploadsDir . '/dzi/' . $baseName . '.dzi';
    return file_exists($dziXmlPath);
}

/**
 * Get DZI path for a file
 */
function getDZIPath($filename, $uploadsDir) {
    $baseName = pathinfo($filename, PATHINFO_FILENAME);
    return '/uploads/dzi/' . $baseName . '.dzi';
}

/**
 * Check if image is large enough to warrant DZI
 */
function shouldGenerateDZI($filename, $uploadsDir) {
    $uploadsDir = rtrim($uploadsDir, '/');
    $originalPath = $uploadsDir . '/' . $filename;

    if (!file_exists($originalPath)) {
        return false;
    }

    $imageInfo = @getimagesize($originalPath);
    if (!$imageInfo) {
        return false;
    }

    return ($imageInfo[0] >= DZI_MIN_DIMENSION || $imageInfo[1] >= DZI_MIN_DIMENSION);
}

/**
 * Recursively remove directory
 */
function rrmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object)) {
                    rrmdir($dir . "/" . $object);
                } else {
                    unlink($dir . "/" . $object);
                }
            }
        }
        rmdir($dir);
    }
}

// === HTTP Endpoint Mode ===
// If called directly via HTTP (not included by another file), handle the request

if (php_sapi_name() !== 'cli' && isset($_GET['f']) && basename($_SERVER['SCRIPT_FILENAME']) === 'generate_dzi.php') {
    session_start();
    header('Content-Type: application/json');

    // Require authentication for direct calls
    if (!isset($_SESSION['artist_authenticated']) || !$_SESSION['artist_authenticated']) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    $filename = basename($_GET['f']);
    $uploadsDir = __DIR__ . '/uploads';

    if (empty($filename)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Filename required']);
        exit;
    }

    $result = generateDZI($filename, $uploadsDir);

    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(500);
        echo json_encode($result);
    }
    exit;
}
