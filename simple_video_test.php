<?php
// simple_video_test.php - Very simple video streaming test

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

// Set basic headers
header('Content-Type: video/mp4');
header('Content-Length: ' . $fileSize);
header('Accept-Ranges: bytes');

// Simple file serving - no buffering, no delays
readfile($videoPath);
?>
