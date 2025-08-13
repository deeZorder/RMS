<?php
// video_info.php - Analyze video file format and codec information

// Get the video filename
$videoFile = $_GET['file'] ?? 'Magical Rainforest.mp4';

// Simple path construction
$videoPath = 'videos/' . $videoFile;

// Check if file exists
if (!file_exists($videoPath)) {
    http_response_code(404);
    echo "Video file not found: $videoPath";
    exit;
}

// Get file info
$fileSize = filesize($videoPath);
$fileTime = filemtime($videoPath);

echo "<h1>Video File Analysis</h1>\n";
echo "<pre>\n";

echo "=== File Information ===\n";
echo "Filename: $videoFile\n";
echo "File size: " . number_format($fileSize) . " bytes (" . round($fileSize / 1024 / 1024, 2) . " MB)\n";
echo "Last modified: " . date('Y-m-d H:i:s', $fileTime) . "\n";
echo "File path: $videoPath\n\n";

// Check if ffprobe is available (part of FFmpeg)
$ffprobePath = 'C:/xampp/ffmpeg/bin/ffprobe.exe';
if (file_exists($ffprobePath)) {
    echo "=== FFprobe Analysis ===\n";
    
    // Get video information using ffprobe
    $command = "\"$ffprobePath\" -v quiet -print_format json -show_format -show_streams \"$videoPath\"";
    $output = shell_exec($command);
    
    if ($output) {
        $info = json_decode($output, true);
        
        if ($info && isset($info['format'])) {
            echo "Format: " . ($info['format']['format_name'] ?? 'Unknown') . "\n";
            echo "Duration: " . ($info['format']['duration'] ?? 'Unknown') . " seconds\n";
            echo "Bitrate: " . ($info['format']['bit_rate'] ?? 'Unknown') . " bps\n";
            
            if (isset($info['streams'])) {
                echo "\n=== Video Streams ===\n";
                foreach ($info['streams'] as $i => $stream) {
                    if ($stream['codec_type'] === 'video') {
                        echo "Video Stream $i:\n";
                        echo "  Codec: " . ($stream['codec_name'] ?? 'Unknown') . "\n";
                        echo "  Codec Long: " . ($stream['codec_long_name'] ?? 'Unknown') . "\n";
                        echo "  Resolution: " . ($stream['width'] ?? 'Unknown') . "x" . ($stream['height'] ?? 'Unknown') . "\n";
                        echo "  Frame Rate: " . ($stream['r_frame_rate'] ?? 'Unknown') . "\n";
                        echo "  Bitrate: " . ($stream['bit_rate'] ?? 'Unknown') . " bps\n";
                        echo "  Profile: " . ($stream['profile'] ?? 'Unknown') . "\n";
                        echo "  Level: " . ($stream['level'] ?? 'Unknown') . "\n";
                    } elseif ($stream['codec_type'] === 'audio') {
                        echo "Audio Stream $i:\n";
                        echo "  Codec: " . ($stream['codec_name'] ?? 'Unknown') . "\n";
                        echo "  Sample Rate: " . ($stream['sample_rate'] ?? 'Unknown') . " Hz\n";
                        echo "  Channels: " . ($stream['channels'] ?? 'Unknown') . "\n";
                    }
                }
            }
        } else {
            echo "Could not parse ffprobe output\n";
        }
    } else {
        echo "FFprobe command failed\n";
    }
} else {
    echo "=== FFprobe Not Available ===\n";
    echo "FFprobe not found at: $ffprobePath\n";
    echo "This tool can provide detailed video codec information\n\n";
    
    // Fallback: try to get basic info from file header
    echo "=== Basic File Header Analysis ===\n";
    $handle = fopen($videoPath, 'rb');
    if ($handle) {
        // Read first 1024 bytes to analyze header
        $header = fread($handle, 1024);
        fclose($handle);
        
        // Look for MP4 signature
        if (strpos($header, 'ftyp') !== false) {
            echo "MP4 signature found: YES\n";
            
            // Look for codec hints in header
            if (strpos($header, 'avc1') !== false) {
                echo "H.264/AVC codec detected: YES\n";
            }
            if (strpos($header, 'mp4a') !== false) {
                echo "AAC audio codec detected: YES\n";
            }
            if (strpos($header, 'hev1') !== false || strpos($header, 'hevc') !== false) {
                echo "H.265/HEVC codec detected: YES\n";
            }
        } else {
            echo "MP4 signature found: NO\n";
        }
    }
}

echo "\n=== Android TV Compatibility Notes ===\n";
echo "Philips BDL5051T (Android TV) typically supports:\n";
echo "- H.264/AVC video codec (most compatible)\n";
echo "- H.265/HEVC video codec (may not be supported)\n";
echo "- AAC audio codec\n";
echo "- MP4 container format\n";
echo "- Resolution up to 4K (depending on model)\n";
echo "- Frame rates: 24, 25, 30, 50, 60 fps\n\n";

echo "=== Recommendations ===\n";
echo "1. If video uses H.265/HEVC: Convert to H.264/AVC\n";
echo "2. If resolution is very high: Try 1080p or 720p\n";
echo "3. If bitrate is very high: Try reducing bitrate\n";
echo "4. Test with a simple H.264 MP4 file\n\n";

echo "=== Test Complete ===\n";
echo "</pre>\n";

// Try to serve a small portion of video for testing
echo "<h2>Video Header Test</h2>\n";
echo "<p>Attempting to serve first 1KB of video file to test streaming...</p>\n";

// Clear any output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Set headers for video
header('Content-Type: video/mp4');
header('Content-Length: 1024');
header('Accept-Ranges: bytes');

// Serve first 1KB
$handle = fopen($videoPath, 'rb');
if ($handle) {
    $data = fread($handle, 1024);
    echo $data;
    fclose($handle);
    echo "\n<p>Video header test completed. Check if you see binary data above.</p>\n";
} else {
    echo "<p>Cannot read video file</p>\n";
}
?>
