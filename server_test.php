<?php
// server_test.php - Windows Server diagnostic script

echo "<h1>Windows Server Video Streaming Diagnostic</h1>\n";
echo "<pre>\n";

// Test 1: Basic PHP Info
echo "=== PHP Configuration ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
echo "Operating System: " . PHP_OS . "\n";
echo "SAPI: " . php_sapi_name() . "\n\n";

// Test 2: PHP Settings
echo "=== PHP Settings ===\n";
echo "output_buffering: " . ini_get('output_buffering') . "\n";
echo "implicit_flush: " . ini_get('implicit_flush') . "\n";
echo "max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n\n";

// Test 3: File System Access
echo "=== File System Access ===\n";
$configPath = __DIR__ . '/config.json';
if (file_exists($configPath)) {
    echo "Config file exists: YES\n";
    $config = json_decode(file_get_contents($configPath), true);
    echo "Config content: " . json_encode($config, JSON_PRETTY_PRINT) . "\n";
} else {
    echo "Config file exists: NO\n";
}

// Test video directory
$videoDir = 'videos';
if (is_dir($videoDir)) {
    echo "Video directory exists: YES\n";
    echo "Video directory path: " . realpath($videoDir) . "\n";
    echo "Video directory permissions: " . substr(sprintf('%o', fileperms($videoDir)), -4) . "\n";
    
    // List video files
    $videoFiles = glob($videoDir . '/*.mp4');
    echo "MP4 files found: " . count($videoFiles) . "\n";
    foreach ($videoFiles as $file) {
        echo "  - " . basename($file) . " (" . filesize($file) . " bytes)\n";
    }
} else {
    echo "Video directory exists: NO\n";
}

// Test 4: Video File Access
echo "\n=== Video File Access Test ===\n";
$testVideo = $videoDir . '/Magical Rainforest.mp4';
if (file_exists($testVideo)) {
    echo "Test video exists: YES\n";
    echo "File size: " . filesize($testVideo) . " bytes\n";
    echo "File permissions: " . substr(sprintf('%o', fileperms($testVideo)), -4) . "\n";
    echo "File readable: " . (is_readable($testVideo) ? 'YES' : 'NO') . "\n";
    
    // Test file opening
    $handle = @fopen($testVideo, 'rb');
    if ($handle) {
        echo "File can be opened: YES\n";
        $data = fread($handle, 1024);
        echo "First 1KB readable: " . (strlen($data) > 0 ? 'YES' : 'NO') . "\n";
        fclose($handle);
    } else {
        echo "File can be opened: NO\n";
        echo "Error: " . error_get_last()['message'] ?? 'Unknown error' . "\n";
    }
} else {
    echo "Test video exists: NO\n";
}

// Test 5: HTTP Headers
echo "\n=== HTTP Headers Test ===\n";
echo "Content-Type header can be set: ";
if (headers_sent()) {
    echo "NO (headers already sent)\n";
} else {
    echo "YES\n";
    // Test setting a header
    header('X-Test-Header: TestValue');
    echo "Test header set successfully\n";
}

// Test 6: Output Buffering
echo "\n=== Output Buffering Test ===\n";
echo "Current output buffer level: " . ob_get_level() . "\n";
if (ob_get_level() > 0) {
    echo "Output buffer content length: " . ob_get_length() . "\n";
    echo "Clearing output buffer...\n";
    ob_end_clean();
    echo "Output buffer cleared\n";
}

// Test 7: Network/Connection
echo "\n=== Network Test ===\n";
echo "Connection status: " . connection_status() . "\n";
echo "Connection aborted: " . (connection_aborted() ? 'YES' : 'NO') . "\n";

// Test 8: MIME Type Detection
echo "\n=== MIME Type Test ===\n";
$ext = 'mp4';
$mimeType = 'video/mp4';
echo "Extension: $ext\n";
echo "MIME Type: $mimeType\n";
echo "MIME type can be set: ";
if (!headers_sent()) {
    header("Content-Type: $mimeType");
    echo "YES\n";
} else {
    echo "NO (headers already sent)\n";
}

echo "\n=== Diagnostic Complete ===\n";
echo "</pre>\n";

// Test 9: Simple Video Stream Test
echo "<h2>Video Stream Test</h2>\n";
if (file_exists($testVideo) && is_readable($testVideo)) {
    echo "<p>Attempting to stream first 1KB of video file...</p>\n";
    
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers for video
    header('Content-Type: video/mp4');
    header('Content-Length: 1024');
    header('Accept-Ranges: bytes');
    header('Cache-Control: no-cache');
    
    // Stream first 1KB
    $handle = fopen($testVideo, 'rb');
    if ($handle) {
        $data = fread($handle, 1024);
        echo $data;
        fclose($handle);
        echo "\n<p>Video stream test completed. Check if you see binary data above.</p>\n";
    }
} else {
    echo "<p>Cannot test video streaming - file not accessible.</p>\n";
}
?>
