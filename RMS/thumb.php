<?php
// thumb.php - Generate or serve cached thumbnails for videos

@ini_set('display_errors', '0');
@error_reporting(0);

$baseDir = __DIR__;
$configPath = $baseDir . '/config.json';
$config = file_exists($configPath) ? (json_decode(@file_get_contents($configPath), true) ?: []) : [];

// Resolve video directories similar to video.php
$videoDirs = [];
if (!empty($config['directories']) && is_array($config['directories'])) {
    $videoDirs = $config['directories'];
} else {
    $videoDirs = [ $config['directory'] ?? 'videos' ];
}

$file = $_GET['file'] ?? '';
$dirIndex = isset($_GET['dirIndex']) ? (int)$_GET['dirIndex'] : 0;

// Basic validation
if ($file === '' || strpos($file, '..') !== false || strpos($file, '/') !== false || strpos($file, '\\') !== false) {
    http_response_code(400);
    exit;
}

// Resolve directory
$selectedDir = $videoDirs[0] ?? 'videos';
if (isset($videoDirs[$dirIndex])) { $selectedDir = $videoDirs[$dirIndex]; }
if (!is_dir($selectedDir)) {
    $fallback = !empty($config['directory']) ? $config['directory'] : 'videos';
    $selectedDir = realpath($baseDir . '/' . $fallback);
}

$videoPath = $selectedDir . (strpos($selectedDir, ':\\') !== false ? '\\' : '/') . $file;
if (!is_file($videoPath)) {
    http_response_code(404);
    exit;
}

// Thumbnail cache path
$thumbDir = $baseDir . '/data/thumbs';
if (!is_dir($thumbDir)) { @mkdir($thumbDir, 0777, true); }

// Build a stable cache key by path + mtime
$mtime = @filemtime($videoPath) ?: 0;
$hash = sha1($videoPath . '|' . $mtime);
$thumbPath = $thumbDir . '/' . $hash . '.jpg';

// Serve cached thumbnail if exists
if (is_file($thumbPath)) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=604800, immutable');
    readfile($thumbPath);
    exit;
}

// Generate thumbnail
// Try ffmpeg if available
function hasFfmpeg() {
    $which = (stripos(PHP_OS, 'WIN') === 0) ? 'where' : 'which';
    $cmd = $which . ' ffmpeg 2> ' . (stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null');
    @exec($cmd, $out, $code);
    return $code === 0;
}

$generated = false;
if (hasFfmpeg()) {
    $escapedIn = escapeshellarg($videoPath);
    $escapedOut = escapeshellarg($thumbPath);
    // Grab a frame at 1s, scale to width 480 preserving aspect
    $cmd = 'ffmpeg -ss 1 -i ' . $escapedIn . ' -frames:v 1 -vf "scale=480:-1" -q:v 5 -y ' . $escapedOut . ' 2> ' . (stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null');
    @exec($cmd, $o, $code);
    if ($code === 0 && is_file($thumbPath)) {
        $generated = true;
    }
}

if (!$generated) {
    // Fallback: serve a 1x1 transparent PNG to avoid broken images
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=300');
    // 1x1 transparent PNG
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAoMBg0nQx6QAAAAASUVORK5CYII=');
    exit;
}

// Serve the generated thumbnail
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=604800, immutable');
readfile($thumbPath);
?>


