<?php
// preview.php - Serve cached preview video loops for dashboard videos
// Preview generation is now handled by admin.php for full control

@ini_set('display_errors', '0');
@error_reporting(0);

$baseDir = __DIR__;
$configPath = $baseDir . '/config.json';
$config = file_exists($configPath) ? (json_decode(@file_get_contents($configPath), true) ?: []) : [];

// Resolve video directories (same logic as admin.php)
$clipsDirs = [];
if (!empty($config['directories']) && is_array($config['directories'])) {
    $clipsDirs = $config['directories'];
} else {
    $clipsDirs = [ $config['directory'] ?? 'videos' ];
}

$file = $_GET['file'] ?? '';
$dirIndex = isset($_GET['dirIndex']) ? (int)$_GET['dirIndex'] : 0;

// Basic validation
if ($file === '' || strpos($file, '..') !== false || strpos($file, '/') !== false || strpos($file, '\\') !== false) {
    http_response_code(400);
    exit;
}

// Resolve directory (same logic as admin.php)
$videoDir = $clipsDirs[$dirIndex] ?? $clipsDirs[0];
$fullVideoDir = $baseDir . DIRECTORY_SEPARATOR . $videoDir;
$videoPath = $fullVideoDir . DIRECTORY_SEPARATOR . $file;

if (!is_file($videoPath)) {
    http_response_code(404);
    exit;
}

// Preview cache path
$previewDir = $baseDir . '/data/previews';
if (!is_dir($previewDir)) { @mkdir($previewDir, 0777, true); }

// Build a stable cache key by path + mtime (same as admin.php)
// Use normalized path to ensure hash consistency across platforms
$normalizedPath = str_replace(['\\', '/'], '/', $videoPath);
$mtime = @filemtime($videoPath) ?: 0;
$hash = sha1($normalizedPath . '|' . $mtime . '|preview');
$previewPath = $previewDir . '/' . $hash . '.mp4';

// Debug logging for troubleshooting
error_log("Preview Debug: File: {$file}, DirIndex: {$dirIndex}, Path: {$videoPath}, Normalized: {$normalizedPath}, Hash: {$hash}, Preview: {$previewPath}");

// Serve cached preview if exists
if (is_file($previewPath)) {
    header('Content-Type: video/mp4');
    header('Cache-Control: public, max-age=604800, immutable');
    header('Accept-Ranges: bytes');
    readfile($previewPath);
    exit;
}

// Preview not found - serve a 1x1 transparent PNG to avoid broken images
// Note: Use admin.php "Generate Previews" button to create previews
header('Content-Type: image/png');
header('Cache-Control: public, max-age=300');
// 1x1 transparent PNG
echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAoMBg0nQx6QAAAAASUVORK5CYII=');
exit;
?>
