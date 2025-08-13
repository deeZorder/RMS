<?php
// file_check.php - Simple file accessibility test

echo "<h1>Video File Accessibility Test</h1>\n";
echo "<pre>\n";

// Test 1: Check if videos directory exists
echo "=== Directory Check ===\n";
$videoDir = 'videos';
if (is_dir($videoDir)) {
    echo "Videos directory exists: YES\n";
    echo "Path: " . realpath($videoDir) . "\n";
    echo "Permissions: " . substr(sprintf('%o', fileperms($videoDir)), -4) . "\n";
} else {
    echo "Videos directory exists: NO\n";
    echo "Current working directory: " . getcwd() . "\n";
    echo "Script location: " . __DIR__ . "\n";
}

// Test 2: List files in videos directory
echo "\n=== Files in Videos Directory ===\n";
if (is_dir($videoDir)) {
    $files = scandir($videoDir);
    echo "Total files found: " . count($files) . "\n";
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $filePath = $videoDir . '/' . $file;
            $fileSize = filesize($filePath);
            $isReadable = is_readable($filePath) ? 'YES' : 'NO';
            echo "  - $file ($fileSize bytes, readable: $isReadable)\n";
        }
    }
}

// Test 3: Check specific video file
echo "\n=== Specific Video File Check ===\n";
$videoFile = 'Magical Rainforest.mp4';
$videoPath = $videoDir . '/' . $videoFile;

if (file_exists($videoPath)) {
    echo "Video file exists: YES\n";
    echo "File size: " . filesize($videoPath) . " bytes\n";
    echo "File readable: " . (is_readable($videoPath) ? 'YES' : 'NO') . "\n";
    echo "File permissions: " . substr(sprintf('%o', fileperms($videoPath)), -4) . "\n";
    
    // Try to open the file
    $handle = @fopen($videoPath, 'rb');
    if ($handle) {
        echo "File can be opened: YES\n";
        
        // Read first few bytes
        $data = fread($handle, 16);
        echo "First 16 bytes: " . bin2hex($data) . "\n";
        
        // Check for MP4 signature
        if (strpos($data, 'ftyp') !== false) {
            echo "MP4 signature found: YES\n";
        } else {
            echo "MP4 signature found: NO\n";
        }
        
        fclose($handle);
    } else {
        echo "File can be opened: NO\n";
        $error = error_get_last();
        if ($error) {
            echo "Error: " . $error['message'] . "\n";
        }
    }
} else {
    echo "Video file exists: NO\n";
    echo "Looking for similar files...\n";
    
    if (is_dir($videoDir)) {
        $files = glob($videoDir . '/*.mp4');
        if (count($files) > 0) {
            echo "MP4 files found:\n";
            foreach ($files as $file) {
                echo "  - " . basename($file) . "\n";
            }
        } else {
            echo "No MP4 files found in videos directory\n";
        }
    }
}

// Test 4: Check web server user
echo "\n=== Web Server Information ===\n";
echo "PHP version: " . phpversion() . "\n";
echo "Server software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
echo "Document root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "\n";
echo "Script path: " . __FILE__ . "\n";

echo "\n=== Test Complete ===\n";
echo "</pre>\n";
?>
