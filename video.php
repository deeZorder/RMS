<?php
// video.php
// Serves video files from any configured directory with Windows Server compatibility

// Set unlimited execution time for video streaming
@set_time_limit(0);
@ini_set('max_execution_time', 0);

// Increase memory limit for large video files
@ini_set('memory_limit', '512M');

// Windows Server specific settings
@ini_set('output_buffering', 'Off');
@ini_set('implicit_flush', 'On');

// Load configuration
$configPath = __DIR__ . '/config.json';
if (file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true);
} else {
    $config = ['directory' => 'videos'];
}

// Function to check if connection is still alive
function isConnectionAlive() {
    return !connection_aborted() && !connection_status();
}

// Function to safely output video data on Windows Server
function safeOutput($data) {
    if (ob_get_level()) {
        ob_end_clean(); // Clear any output buffers
    }
    echo $data;
    flush();
    
    // Windows Server specific flush
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

// Get the video filename and optional directory index from the URL
$videoFile = $_GET['file'] ?? '';
$dirIndex = isset($_GET['dirIndex']) ? (int)$_GET['dirIndex'] : 0;

if (empty($videoFile)) {
    http_response_code(400);
    echo 'No video file specified';
    exit;
}

// Security: Allow common filename characters including parentheses, spaces, etc.
// Block only dangerous characters that could be used for directory traversal
if (strpos($videoFile, '..') !== false || strpos($videoFile, '/') !== false || strpos($videoFile, '\\') !== false) {
    http_response_code(400);
    echo 'Invalid filename - path traversal not allowed';
    exit;
}

// Determine video directories
$videoDirs = [];
if (!empty($config['directories']) && is_array($config['directories'])) {
    $videoDirs = $config['directories'];
} else {
    $videoDirs = [ $config['directory'] ];
}
// Pick selected directory
$selectedDir = $videoDirs[0] ?? 'videos';
if (isset($videoDirs[$dirIndex])) {
    $selectedDir = $videoDirs[$dirIndex];
}
if (!is_dir($selectedDir)) {
    // Fallback to relative path if absolute path doesn't exist
    $fallback = !empty($config['directory']) ? $config['directory'] : 'videos';
    $selectedDir = realpath(__DIR__ . '/' . $fallback);
}

// Construct full path to video file
$videoPath = $selectedDir . (strpos($selectedDir, ':\\') !== false ? '\\' : '/') . $videoFile;

// Security: Check if file exists and is within allowed directory
if (!file_exists($videoPath) || !is_file($videoPath)) {
    http_response_code(404);
    echo 'Video file not found. Path: ' . $videoPath . ' | File: ' . $videoFile;
    error_log('Video file not found: ' . $videoPath);
    exit;
}

// Get file info
$fileSize = filesize($videoPath);
$fileTime = filemtime($videoPath);

// Enhanced MIME type detection for Windows Server
$mimeType = 'application/octet-stream'; // Safe default
$ext = strtolower(pathinfo($videoFile, PATHINFO_EXTENSION));
switch ($ext) {
    case 'webm':
        $mimeType = 'video/webm';
        break;
    case 'ogg':
        $mimeType = 'video/ogg';
        break;
    case 'mov':
        $mimeType = 'video/quicktime';
        break;
    case 'mp4':
        $mimeType = 'video/mp4';
        break;
    case 'avi':
        $mimeType = 'video/x-msvideo';
        break;
    case 'wmv':
        $mimeType = 'video/x-ms-wmv';
        break;
    case 'flv':
        $mimeType = 'video/x-flv';
        break;
    case 'mkv':
        $mimeType = 'video/x-matroska';
        break;
}

// Windows Server specific headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $fileTime) . ' GMT');
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');

// Windows Server specific caching headers
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Additional headers for better compatibility
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, If-Range, If-Modified-Since, If-None-Match');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Handle range requests for video streaming
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range) {
    $ranges = array_map('trim', explode('=', $range));
    if ($ranges[0] === 'bytes') {
        $ranges = array_map('trim', explode('-', $ranges[1]));
        $start = (int)$ranges[0];
        $end = isset($ranges[1]) && $ranges[1] !== '' ? (int)$ranges[1] : $fileSize - 1;
        
        // Validate range
        if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
            http_response_code(416); // Range Not Satisfiable
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
        header('Content-Length: ' . ($end - $start + 1));
        
        $handle = fopen($videoPath, 'rb');
        if (!$handle) {
            http_response_code(500);
            exit;
        }
        
        fseek($handle, $start);
        $buffer = 1024 * 64; // Smaller buffer for Windows Server (64KB)
        $bytesSent = 0;
        
        while (!feof($handle) && isConnectionAlive()) {
            $pos = ftell($handle);
            if ($pos === false || $pos > $end) { break; }
            
            $bytesToRead = min($buffer, $end - $pos + 1);
            $data = fread($handle, $bytesToRead);
            
            if ($data === false) { break; }
            
            safeOutput($data);
            $bytesSent += strlen($data);
            
            // Windows Server specific delay
            usleep(500); // 0.5ms delay for Windows Server
        }
        fclose($handle);
        exit;
    }
}

// Serve the entire file
// Stream in chunks optimized for Windows Server
$handle = fopen($videoPath, 'rb');
if ($handle) {
    $buffer = 1024 * 64; // Smaller buffer for Windows Server (64KB)
    $bytesSent = 0;
    
    while (!feof($handle) && isConnectionAlive()) {
        $data = fread($handle, $buffer);
        
        if ($data === false) { break; }
        
        safeOutput($data);
        $bytesSent += strlen($data);
        
        // Windows Server specific delay
        usleep(500); // 0.5ms delay for Windows Server
    }
    fclose($handle);
} else {
    // Fallback to readfile if fopen fails
    if (ob_get_level()) {
        ob_end_clean();
    }
    readfile($videoPath);
    flush();
}
?> 