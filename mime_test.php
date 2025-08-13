<?php
// mime_test.php - Test Apache MIME type handling for video files

echo "<h1>Apache MIME Type Test</h1>\n";
echo "<pre>\n";

// Test 1: Check if Apache is serving MP4 files with correct MIME type
echo "=== Apache MIME Type Test ===\n";
echo "Testing if Apache can serve MP4 files correctly...\n\n";

// Test 2: Check current MIME type detection
$ext = 'mp4';
$mimeType = 'video/mp4';
echo "File extension: $ext\n";
echo "Expected MIME type: $mimeType\n";

// Test 3: Check if we can set headers
echo "\n=== Header Test ===\n";
if (!headers_sent()) {
    echo "Headers can be set: YES\n";
    header("Content-Type: $mimeType");
    echo "MIME type header set successfully\n";
} else {
    echo "Headers can be set: NO (headers already sent)\n";
}

// Test 4: Check Apache configuration
echo "\n=== Apache Configuration Check ===\n";
$apacheConf = 'C:/xampp/apache/conf/mime.types';
if (file_exists($apacheConf)) {
    echo "Apache mime.types file exists: YES\n";
    $mimeContent = file_get_contents($apacheConf);
    if (strpos($mimeContent, 'video/mp4') !== false) {
        echo "MP4 MIME type configured in Apache: YES\n";
    } else {
        echo "MP4 MIME type configured in Apache: NO\n";
        echo "This might be the issue!\n";
    }
} else {
    echo "Apache mime.types file exists: NO\n";
    echo "Looking for alternative locations...\n";
    
    // Check common XAMPP locations
    $alternativePaths = [
        'C:/xampp/apache/conf/httpd.conf',
        'C:/xampp/apache/conf/extra/httpd-mime.conf',
        'C:/xampp/apache/conf/extra/mime.types'
    ];
    
    foreach ($alternativePaths as $path) {
        if (file_exists($path)) {
            echo "Found: $path\n";
            $content = file_get_contents($path);
            if (strpos($content, 'video/mp4') !== false) {
                echo "MP4 MIME type found in: $path\n";
            }
        }
    }
}

// Test 5: Check if video file exists and is accessible
echo "\n=== Video File Test ===\n";
$videoFile = 'videos/Magical Rainforest.mp4';
if (file_exists($videoFile)) {
    echo "Video file exists: YES\n";
    echo "File size: " . filesize($videoFile) . " bytes\n";
    echo "File readable: " . (is_readable($videoFile) ? 'YES' : 'NO') . "\n";
    
    // Test file opening
    $handle = @fopen($videoFile, 'rb');
    if ($handle) {
        echo "File can be opened: YES\n";
        $data = fread($handle, 1024);
        echo "First 1KB readable: " . (strlen($data) > 0 ? 'YES' : 'NO') . "\n";
        fclose($handle);
    } else {
        echo "File can be opened: NO\n";
    }
} else {
    echo "Video file exists: NO\n";
    echo "Looking for video files in videos directory...\n";
    
    if (is_dir('videos')) {
        $files = scandir('videos');
        echo "Files in videos directory:\n";
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                echo "  - $file\n";
            }
        }
    } else {
        echo "Videos directory does not exist\n";
    }
}

echo "\n=== Test Complete ===\n";
echo "</pre>\n";

// Test 6: Try to serve a small portion of video
echo "<h2>Video Serving Test</h2>\n";
if (file_exists($videoFile) && is_readable($videoFile)) {
    echo "<p>Attempting to serve first 1KB of video file...</p>\n";
    
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers for video
    header('Content-Type: video/mp4');
    header('Content-Length: 1024');
    header('Accept-Ranges: bytes');
    
    // Serve first 1KB
    $handle = fopen($videoFile, 'rb');
    if ($handle) {
        $data = fread($handle, 1024);
        echo $data;
        fclose($handle);
        echo "\n<p>Video serving test completed. Check if you see binary data above.</p>\n";
    }
} else {
    echo "<p>Cannot test video serving - file not accessible.</p>\n";
}
?>
