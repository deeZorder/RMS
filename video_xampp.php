<?php
// video_xampp.php - XAMPP/Apache optimized video streaming
// Optimized for Apache instead of IIS

// Prevent any output before headers
ob_start();

// Set unlimited execution time
@set_time_limit(0);
@ini_set('max_execution_time', 0);
@ini_set('output_buffering', 'Off');
@ini_set('implicit_flush', 'On');

// Get parameters
$videoFile = $_GET['file'] ?? '';
$dirIndex = isset($_GET['dirIndex']) ? (int)$_GET['dirIndex'] : 0;

// Validate input
if (empty($videoFile)) {
    ob_end_clean();
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'No video file specified';
    exit;
}

// Security check
if (strpos($videoFile, '..') !== false || strpos($videoFile, '/') !== false || strpos($videoFile, '\\') !== false) {
    ob_end_clean();
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid filename';
    exit;
}

// Load configuration
$configPath = __DIR__ . '/config.json';
if (file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true);
} else {
    $config = ['directory' => 'videos'];
}

// Determine video directory
$videoDirs = [];
if (!empty($config['directories']) && is_array($config['directories'])) {
    $videoDirs = $config['directories'];
} else {
    $videoDirs = [ $config['directory'] ];
}

$selectedDir = $videoDirs[0] ?? 'videos';
if (isset($videoDirs[$dirIndex])) {
    $selectedDir = $videoDirs[$dirIndex];
}

if (!is_dir($selectedDir)) {
    $fallback = !empty($config['directory']) ? $config['directory'] : 'videos';
    $selectedDir = realpath(__DIR__ . '/' . $fallback);
}

// Construct full path
$videoPath = $selectedDir . (strpos($selectedDir, ':\\') !== false ? '\\' : '/') . $videoFile;

// Check if file exists
if (!file_exists($videoPath) || !is_file($videoPath)) {
    ob_end_clean();
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Video file not found: ' . $videoPath;
    exit;
}

// Get file info
$fileSize = filesize($videoPath);
$fileTime = filemtime($videoPath);
$ext = strtolower(pathinfo($videoFile, PATHINFO_EXTENSION));

// Set MIME type
$mimeType = 'video/mp4'; // Default
switch ($ext) {
    case 'mp4': $mimeType = 'video/mp4'; break;
    case 'webm': $mimeType = 'video/webm'; break;
    case 'ogg': $mimeType = 'video/ogg'; break;
    case 'mov': $mimeType = 'video/quicktime'; break;
    case 'avi': $mimeType = 'video/x-msvideo'; break;
    case 'wmv': $mimeType = 'video/x-ms-wmv'; break;
}

// CRITICAL: Clear any output buffers before setting headers
while (ob_get_level()) {
    ob_end_clean();
}

// Set video streaming headers optimized for Apache
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=3600'); // Apache-friendly caching
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $fileTime) . ' GMT');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, If-Range');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Handle range requests (Apache handles these well)
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range) {
    $ranges = array_map('trim', explode('=', $range));
    if ($ranges[0] === 'bytes') {
        $ranges = array_map('trim', explode('-', $ranges[1]));
        $start = (int)$ranges[0];
        $end = isset($ranges[1]) && $ranges[1] !== '' ? (int)$ranges[1] : $fileSize - 1;
        
        if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
        header('Content-Length: ' . ($end - $start + 1));
        
        $handle = fopen($videoPath, 'rb');
        if ($handle) {
            fseek($handle, $start);
            $buffer = 1024 * 64; // Larger buffer for Apache (64KB)
            $bytesSent = 0;
            
            while (!feof($handle)) {
                $pos = ftell($handle);
                if ($pos === false || $pos > $end) break;
                
                $bytesToRead = min($buffer, $end - $pos + 1);
                $data = fread($handle, $bytesToRead);
                
                if ($data === false) break;
                
                echo $data;
                $bytesSent += strlen($data);
                
                // Apache-specific flush
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                
                // No delay for Apache - it handles streaming better
            }
            fclose($handle);
        }
        exit;
    }
}

// Serve full file - Apache optimized
$handle = fopen($videoPath, 'rb');
if ($handle) {
    $buffer = 1024 * 64; // Larger buffer for Apache (64KB)
    $bytesSent = 0;
    
    while (!feof($handle)) {
        $data = fread($handle, $buffer);
        if ($data === false) break;
        
        echo $data;
        $bytesSent += strlen($data);
        
        // Apache-specific flush
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
        
        // No delay for Apache - it handles streaming better
    }
    fclose($handle);
} else {
    // Fallback - Apache handles this well
    readfile($videoPath);
}
?>
