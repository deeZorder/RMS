<?php
// video_test.php - Simple video serving test for Windows Server debugging

// Set unlimited execution time
@set_time_limit(0);
@ini_set('max_execution_time', 0);
@ini_set('output_buffering', 'Off');
@ini_set('implicit_flush', 'On');

// Get the video filename
$videoFile = $_GET['file'] ?? 'Magical Rainforest.mp4';
$dirIndex = isset($_GET['dirIndex']) ? (int)$_GET['dirIndex'] : 0;

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

// Construct full path to video file
$videoPath = $selectedDir . (strpos($selectedDir, ':\\') !== false ? '\\' : '/') . $videoFile;

// Check if file exists
if (!file_exists($videoPath) || !is_file($videoPath)) {
    http_response_code(404);
    echo "Video file not found: $videoPath";
    exit;
}

// Get file info
$fileSize = filesize($videoPath);
$fileTime = filemtime($videoPath);
$ext = strtolower(pathinfo($videoFile, PATHINFO_EXTENSION));

// Set MIME type
$mimeType = 'video/mp4'; // Default for testing
switch ($ext) {
    case 'mp4': $mimeType = 'video/mp4'; break;
    case 'webm': $mimeType = 'video/webm'; break;
    case 'ogg': $mimeType = 'video/ogg'; break;
    case 'mov': $mimeType = 'video/quicktime'; break;
}

// Set headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Accept-Ranges: bytes');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');

// Handle range requests
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
            $buffer = 1024 * 32; // 32KB buffer for testing
            
            while (!feof($handle)) {
                $pos = ftell($handle);
                if ($pos === false || $pos > $end) break;
                
                $bytesToRead = min($buffer, $end - $pos + 1);
                $data = fread($handle, $bytesToRead);
                
                if ($data === false) break;
                
                echo $data;
                flush();
                usleep(100); // Small delay
            }
            fclose($handle);
        }
        exit;
    }
}

// Serve full file
$handle = fopen($videoPath, 'rb');
if ($handle) {
    $buffer = 1024 * 32; // 32KB buffer
    
    while (!feof($handle)) {
        $data = fread($handle, $buffer);
        if ($data === false) break;
        
        echo $data;
        flush();
        usleep(100); // Small delay
    }
    fclose($handle);
} else {
    readfile($videoPath);
}
?>
