<?php
// Serve cached preview video loops for dashboard videos
// This endpoint generates a short preview loop from the middle of each video

// Suppress error output to prevent corruption of video stream
@ini_set('display_errors', 0);
@error_reporting(0);

$baseDir = __DIR__;
$configPath = $baseDir . '/config.json';

if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration not found']);
    exit;
}

$config = json_decode(file_get_contents($configPath), true);
if (!$config) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid configuration']);
    exit;
}

// Get video file and directory index from query parameters
$file = $_GET['file'] ?? '';
$dirIndex = (int)($_GET['dirIndex'] ?? 0);

if (empty($file)) {
    http_response_code(400);
    echo json_encode(['error' => 'No file specified']);
    exit;
}

// Get video directories from config
$clipsDirs = [];
if (!empty($config['directories']) && is_array($config['directories'])) {
    $clipsDirs = $config['directories'];
} else {
    $clipsDirs = [$config['directory'] ?? 'videos'];
}

if (!isset($clipsDirs[$dirIndex])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid directory index']);
    exit;
}

// Try to find preview using admin cache first (most efficient)
$previewPath = null;
$adminCachePath = $baseDir . '/data/admin_cache.json';

if (file_exists($adminCachePath)) {
    $adminCache = json_decode(file_get_contents($adminCachePath), true);
    if (is_array($adminCache) && isset($adminCache['all_video_files']) && is_array($adminCache['all_video_files'])) {
        // Look for the video in admin cache
        foreach ($adminCache['all_video_files'] as $videoEntry) {
            if (isset($videoEntry['name']) && $videoEntry['name'] === $file && 
                isset($videoEntry['dirIndex']) && $videoEntry['dirIndex'] === $dirIndex &&
                isset($videoEntry['preview_hash']) && !empty($videoEntry['preview_hash'])) {
                
                // Found the video with preview hash
                $previewDir = $baseDir . '/data/previews';
                $previewPath = $previewDir . '/' . $videoEntry['preview_hash'] . '.mp4';
                
                // Verify the preview file still exists and is newer than the video
                if (file_exists($previewPath)) {
                    // Check if video file still exists and has same mtime
                    $videoDir = $clipsDirs[$dirIndex] ?? $clipsDirs[0];
                    $fullVideoDir = $baseDir . DIRECTORY_SEPARATOR . $videoDir;
                    $videoPath = $fullVideoDir . DIRECTORY_SEPARATOR . $file;
                    
                    if (is_file($videoPath) && filemtime($videoPath) === $videoEntry['preview_mtime']) {
                        // Cache entry is still valid, use this preview
                        break;
                    } else {
                        // Video file changed or doesn't exist, cache entry is stale
                        $previewPath = null;
                    }
                } else {
                    // Preview file doesn't exist, cache entry is stale
                    $previewPath = null;
                }
                break;
            }
        }
    }
}

// Fallback: try preview_mapping.json if admin cache doesn't have preview hashes
if (!$previewPath) {
    $previewMappingFile = $baseDir . '/data/preview_mapping.json';
    if (file_exists($previewMappingFile)) {
        $mappingData = json_decode(file_get_contents($previewMappingFile), true);
        if (is_array($mappingData) && isset($mappingData['mappings']) && is_array($mappingData['mappings'])) {
            $mappingKey = $dirIndex . '|' . $file;
            if (isset($mappingData['mappings'][$mappingKey])) {
                $mapping = $mappingData['mappings'][$mappingKey];
                $previewDir = $baseDir . '/data/previews';
                $previewPath = $previewDir . '/' . $mapping['preview_file'];
                
                // Verify the preview file still exists and is newer than the video
                if (file_exists($previewPath)) {
                    // Check if video file still exists and has same mtime
                    $videoDir = $clipsDirs[$dirIndex] ?? $clipsDirs[0];
                    $fullVideoDir = $baseDir . DIRECTORY_SEPARATOR . $videoDir;
                    $videoPath = $fullVideoDir . DIRECTORY_SEPARATOR . $file;
                    
                    if (is_file($videoPath) && filemtime($videoPath) === $mapping['mtime']) {
                        // Mapping is still valid, use this preview
                        // Continue to use this preview path
                    } else {
                        // Video file changed or doesn't exist, mapping is stale
                        $previewPath = null;
                    }
                } else {
                    // Preview file doesn't exist, mapping is stale
                    $previewPath = null;
                }
            }
        }
    }
}

// Final fallback: if no cache entry or mapping, try to generate hash manually
if (!$previewPath) {
    // Resolve directory (same logic as admin system which generates the previews)
    $videoDir = $clipsDirs[$dirIndex] ?? $clipsDirs[0];
    $fullVideoDir = $baseDir . DIRECTORY_SEPARATOR . $videoDir;
    $videoPath = $fullVideoDir . DIRECTORY_SEPARATOR . $file;
    
    if (!is_file($videoPath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Video file not found', 'message' => 'Generate previews in admin panel first']);
        exit;
    }
    
    // Build possible cache keys by path + mtime across hashing variants
    $mtime = @filemtime($videoPath) ?: 0;
    $candidates = [];
    // Consider both absolute path (as preview.php resolves) and relative path as used by api.php warmers
    $absPath = $videoPath;
    $relPath = rtrim((string)($clipsDirs[$dirIndex] ?? $clipsDirs[0]), '\\/') . DIRECTORY_SEPARATOR . $file;
    // Normalize relPath to not include baseDir
    if (strpos($relPath, $baseDir . DIRECTORY_SEPARATOR) === 0) {
        $relPath = substr($relPath, strlen($baseDir . DIRECTORY_SEPARATOR));
    }
    foreach ([$absPath, $relPath] as $p) {
        // Variant A: OS separator, original case
        $np_os = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $p);
        $candidates[] = sha1($np_os . '|' . $mtime);
        $candidates[] = sha1($np_os . '|' . $mtime . '|preview');
        // Variant B: OS separator, lowercase on Windows
        $np_os_lower = (stripos(PHP_OS, 'WIN') === 0) ? strtolower($np_os) : $np_os;
        $candidates[] = sha1($np_os_lower . '|' . $mtime);
        $candidates[] = sha1($np_os_lower . '|' . $mtime . '|preview');
        // Variant C: Forward slashes, original case
        $np_fwd = str_replace(['\\', '/'], '/', $p);
        $candidates[] = sha1($np_fwd . '|' . $mtime);
        $candidates[] = sha1($np_fwd . '|' . $mtime . '|preview');
        // Variant D: Forward slashes, lowercase on Windows
        $np_fwd_lower = (stripos(PHP_OS, 'WIN') === 0) ? strtolower($np_fwd) : $np_fwd;
        $candidates[] = sha1($np_fwd_lower . '|' . $mtime);
        $candidates[] = sha1($np_fwd_lower . '|' . $mtime . '|preview');
    }

    $previewPath = null;
    foreach ($candidates as $h) {
        $p = $baseDir . '/data/previews/' . $h . '.mp4';
        if (file_exists($p)) { $previewPath = $p; break; }
    }
    if (!$previewPath) {
        // Default to first candidate path (helps return a consistent path in error logs)
        $previewPath = $baseDir . '/data/previews/' . ($candidates[0] ?? '') . '.mp4';
    }
}

// Check if preview file exists
if (!file_exists($previewPath)) {
    http_response_code(404);
    echo json_encode(['error' => 'Preview not found', 'message' => 'Generate previews in admin panel first']);
    exit;
}

// Set proper headers for video streaming
header('Content-Type: video/mp4');
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000));

// Get file size for range requests
$fileSize = filesize($previewPath);
header('Content-Length: ' . $fileSize);

// Handle range requests for better streaming
if (isset($_SERVER['HTTP_RANGE'])) {
    $range = $_SERVER['HTTP_RANGE'];
    if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
        $start = (int)$matches[1];
        $end = !empty($matches[2]) ? (int)$matches[2] : $fileSize - 1;
        
        if ($start < $fileSize && $end < $fileSize && $start <= $end) {
            header('HTTP/1.1 206 Partial Content');
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
            header('Content-Length: ' . ($end - $start + 1));
            
            // Output the requested range
            $handle = fopen($previewPath, 'rb');
            fseek($handle, $start);
            $buffer = 1024 * 8; // 8KB chunks
            while (!feof($handle) && ftell($handle) <= $end) {
                $remaining = $end - ftell($handle) + 1;
                $chunkSize = min($buffer, $remaining);
                echo fread($handle, $chunkSize);
                flush();
            }
            fclose($handle);
            exit;
        }
    }
}

// No range request or invalid range, serve the entire file
readfile($previewPath);
?>
