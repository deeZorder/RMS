<?php
// video.php
// Serves video files from any configured directory

// Set unlimited execution time for video streaming
@set_time_limit(0);
@ini_set('max_execution_time', 0);

// Increase memory limit for large video files
@ini_set('memory_limit', '512M');

// Load configuration
$configPath = __DIR__ . '/config.json';
if (file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true);
} else {
    $config = ['directory' => 'videos'];
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

// Set appropriate headers for video streaming
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
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $fileTime) . ' GMT');
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=604800, immutable');

// Function to check if connection is still alive
function isConnectionAlive() {
    return !connection_aborted() && !connection_status();
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
        $buffer = 1024 * 256; // 256KB chunks for better throughput
        $bytesSent = 0;
        
        while (!feof($handle) && isConnectionAlive()) {
            $pos = ftell($handle);
            if ($pos === false || $pos > $end) { break; }
            
            $bytesToRead = min($buffer, $end - $pos + 1);
            $data = fread($handle, $bytesToRead);
            
            if ($data === false) { break; }
            
            echo $data;
            $bytesSent += strlen($data);
            
            // Flush output buffer to prevent memory buildup
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            
            // Small delay to prevent overwhelming the connection
            usleep(1000); // 1ms
        }
        fclose($handle);
        exit;
    }
}

// Serve the entire file
// Stream in chunks to reduce memory usage
$handle = fopen($videoPath, 'rb');
if ($handle) {
    $buffer = 1024 * 256; // 256KB chunks
    $bytesSent = 0;
    
    while (!feof($handle) && isConnectionAlive()) {
        $data = fread($handle, $buffer);
        
        if ($data === false) { break; }
        
        echo $data;
        $bytesSent += strlen($data);
        
        // Flush output buffer to prevent memory buildup
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
        
        // Small delay to prevent overwhelming the connection
        usleep(1000); // 1ms
    }
    fclose($handle);
} else {
    // Fallback to readfile if fopen fails
    readfile($videoPath);
}
?> 