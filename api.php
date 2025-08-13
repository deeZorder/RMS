<?php
// Suppress PHP notices/warnings from leaking into JSON responses
@ini_set('display_errors', '0');
@error_reporting(0);

// Set reasonable timeout for API calls
@set_time_limit(30);
@ini_set('max_execution_time', 30);

// api.php
// AJAX endpoints for the Relax Media system.

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

// Function to safely read file contents with timeout protection
function safeFileRead($path, $default = '') {
    if (!file_exists($path)) {
        return $default;
    }
    
    // Use file_get_contents with timeout protection
    $context = stream_context_create([
        'http' => [
            'timeout' => 5
        ]
    ]);
    
    $content = @file_get_contents($path, false, $context);
    return $content !== false ? trim($content) : $default;
}

// Function to safely write file contents
function safeFileWrite($path, $content) {
    try {
        $tempPath = $path . '.tmp';
        if (file_put_contents($tempPath, $content) === false) {
            return false;
        }
        return rename($tempPath, $path);
    } catch (Exception $e) {
        error_log('File write error: ' . $e->getMessage());
        return false;
    }
}

// Determine action from query string
$action = $_GET['action'] ?? '';

// Health check endpoint
if ($action === 'health') {
    echo json_encode([
        'status' => 'ok',
        'timestamp' => time(),
        'memory_usage' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true),
        'profile_id' => $profileId
    ]);
    exit;
}

// Determine dashboard profile namespace
function sanitize_profile_id(string $raw): string {
    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $raw);
    return $id !== '' ? $id : 'default';
}

$profileId = 'default';
if (isset($_GET['profile'])) {
    $profileId = sanitize_profile_id((string)$_GET['profile']);
} elseif (isset($_POST['profile'])) {
    $profileId = sanitize_profile_id((string)$_POST['profile']);
} elseif (isset($_GET['d'])) {
    $n = (int)$_GET['d'];
    if ($n === 0) { $profileId = 'default'; }
    elseif ($n >= 1) { $profileId = 'dashboard' . $n; }
} elseif (isset($_POST['d'])) {
    $n = (int)$_POST['d'];
    if ($n === 0) { $profileId = 'default'; }
    elseif ($n >= 1) { $profileId = 'dashboard' . $n; }
}

// Paths to data files
$baseDir = __DIR__;
$configPath = $baseDir . '/config.json';

// Centralized data directory for state files
$dataDir = $baseDir . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
}

// Define state file paths (within profile directory)
$profilesDir = $dataDir . '/profiles';
if (!is_dir($profilesDir)) { @mkdir($profilesDir, 0777, true); }
$profileDir = $profilesDir . '/' . $profileId;
if (!is_dir($profileDir)) { @mkdir($profileDir, 0777, true); }

$currentVideoPath = $profileDir . '/current_video.txt';
$playbackStatePath = $profileDir . '/playback_state.txt';
$volumePath = $profileDir . '/volume.txt';
$muteStatePath = $profileDir . '/mute_state.txt';
$loopModePath = $profileDir . '/loop_mode.txt';
$playAllModePath = $profileDir . '/play_all_mode.txt';
$dashboardRefreshPath = $profileDir . '/dashboard_refresh.txt';
$videoTitlesPath = $dataDir . '/video_titles.json';
$videoOrderPath = $profileDir . '/video_order.json';
$externalAudioModePath = $profileDir . '/external_audio_mode.txt';

// Migrate legacy flat files into data directory if present
$legacyToNew = [
    // Legacy root files → default profile
    $baseDir . '/current_video.txt' => $dataDir . '/profiles/default/current_video.txt',
    $baseDir . '/playback_state.txt' => $dataDir . '/profiles/default/playback_state.txt',
    $baseDir . '/volume.txt' => $dataDir . '/profiles/default/volume.txt',
    $baseDir . '/mute_state.txt' => $dataDir . '/profiles/default/mute_state.txt',
    $baseDir . '/loop_mode.txt' => $dataDir . '/profiles/default/loop_mode.txt',
    $baseDir . '/play_all_mode.txt' => $dataDir . '/profiles/default/play_all_mode.txt',
    $baseDir . '/dashboard_refresh.txt' => $dataDir . '/profiles/default/dashboard_refresh.txt',
    $baseDir . '/video_order.json' => $dataDir . '/profiles/default/video_order.json',
    $baseDir . '/external_audio_mode.txt' => $dataDir . '/profiles/default/external_audio_mode.txt',
    // Pre-profile data files (in data/) → default profile
    $dataDir . '/current_video.txt' => $dataDir . '/profiles/default/current_video.txt',
    $dataDir . '/playback_state.txt' => $dataDir . '/profiles/default/playback_state.txt',
    $dataDir . '/volume.txt' => $dataDir . '/profiles/default/volume.txt',
    $dataDir . '/mute_state.txt' => $dataDir . '/profiles/default/mute_state.txt',
    $dataDir . '/loop_mode.txt' => $dataDir . '/profiles/default/loop_mode.txt',
    $dataDir . '/play_all_mode.txt' => $dataDir . '/profiles/default/play_all_mode.txt',
    $dataDir . '/dashboard_refresh.txt' => $dataDir . '/profiles/default/dashboard_refresh.txt',
    $dataDir . '/video_order.json' => $dataDir . '/profiles/default/video_order.json',
    $dataDir . '/external_audio_mode.txt' => $dataDir . '/profiles/default/external_audio_mode.txt',
];
foreach ($legacyToNew as $old => $new) {
    if (file_exists($old) && !file_exists($new)) {
        @rename($old, $new);
    }
}

// Helper functions for multi-directory support
function getConfiguredDirectories(array $config, string $baseDir): array {
    $dirs = [];
    if (!empty($config['directories']) && is_array($config['directories'])) {
        $dirs = $config['directories'];
    } elseif (!empty($config['directory'])) {
        $dirs = [$config['directory']];
    } else {
        $dirs = ['videos'];
    }
    $normalized = [];
    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            $normalized[] = $dir;
        } elseif (is_dir($baseDir . '/' . $dir)) {
            $normalized[] = $baseDir . '/' . $dir;
        }
    }
    return $normalized;
}

function readCurrentVideo(string $path): array {
    if (!file_exists($path)) { return [null, 0]; }
    $raw = trim(@file_get_contents($path));
    if ($raw === '') { return [null, 0]; }
    $decoded = json_decode($raw, true);
    if (is_array($decoded) && isset($decoded['filename'])) {
        $name = (string)$decoded['filename'];
        $idx = isset($decoded['dirIndex']) ? (int)$decoded['dirIndex'] : 0;
        return [$name, $idx];
    }
    // Legacy plain filename
    return [$raw, 0];
}

function writeCurrentVideo(string $path, string $filename, int $dirIndex): void {
    @file_put_contents($path, json_encode(['filename' => $filename, 'dirIndex' => $dirIndex], JSON_UNESCAPED_SLASHES));
}

// -------- Ordering helpers --------
function getVideoOrderFilePath(string $baseDir, string $profileId): string {
    $dir = $baseDir . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    return $dir . '/video_order.json';
}

function loadVideoOrder(string $baseDir, string $profileId): array {
    $path = getVideoOrderFilePath($baseDir, $profileId);
    if (!file_exists($path)) { return []; }
    $decoded = json_decode(@file_get_contents($path), true);
    if (is_array($decoded) && isset($decoded['order']) && is_array($decoded['order'])) {
        return $decoded['order'];
    }
    return [];
}

function saveVideoOrder(string $baseDir, array $order, string $profileId): void {
    $path = getVideoOrderFilePath($baseDir, $profileId);
    @file_put_contents($path, json_encode(['order' => array_values($order)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function buildAllVideosList(array $dirs, array $allowedExt): array {
    $all = [];
    foreach ($dirs as $i => $dir) {
        if (!is_dir($dir)) { continue; }
        $items = @scandir($dir);
        if ($items === false) { continue; }
        foreach ($items as $file) {
            if ($file === '.' || $file === '..') { continue; }
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExt, true)) {
                    $all[] = [
                        'name' => $file,
                        'dirIndex' => $i,
                        'dirPath' => $dir,
                        'key' => $dir . '|' . $file,
                    ];
                }
            }
        }
    }
    return $all;
}

function getOrderPositions(array $orderKeys): array {
    $pos = [];
    foreach ($orderKeys as $idx => $k) { $pos[$k] = $idx; }
    return $pos;
}

// Internal: ensure a thumbnail exists for a given video; returns true on success
function ensureVideoThumbnail(string $videoPath, string $thumbDir): bool {
    if (!is_file($videoPath)) { return false; }
    if (!is_dir($thumbDir)) { @mkdir($thumbDir, 0777, true); }
    $mtime = @filemtime($videoPath) ?: 0;
    $hash = sha1($videoPath . '|' . $mtime);
    $thumbPath = rtrim($thumbDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.jpg';
    if (is_file($thumbPath)) { return true; }
    // Check ffmpeg availability
    $which = (stripos(PHP_OS, 'WIN') === 0) ? 'where' : 'which';
    $cmdCheck = $which . ' ffmpeg' . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
    @exec($cmdCheck, $out, $code);
    if ($code !== 0) { return false; }
    $escapedIn = escapeshellarg($videoPath);
    $escapedOut = escapeshellarg($thumbPath);
    $cmd = 'ffmpeg -ss 1 -i ' . $escapedIn . ' -frames:v 1 -vf "scale=480:-1" -q:v 5 -y ' . $escapedOut . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
    @exec($cmd, $o, $c);
    return $c === 0 && is_file($thumbPath);
}

switch ($action) {
    case 'check_config_changes':
        // Check if config.json has been modified since last check
        $configPath = $baseDir . '/config.json';
        $configModTime = file_exists($configPath) ? filemtime($configPath) : 0;
        
        // Store last check time in profile directory
        $lastCheckPath = $profileDir . '/last_config_check.txt';
        $lastCheckTime = file_exists($lastCheckPath) ? (int)file_get_contents($lastCheckPath) : 0;
        
        $needsRefresh = false;
        if ($configModTime > $lastCheckTime) {
            $needsRefresh = true;
            // Update last check time
            file_put_contents($lastCheckPath, (string)$configModTime);
        }
        
        echo json_encode(['needsRefresh' => $needsRefresh, 'lastCheck' => $lastCheckTime, 'configModTime' => $configModTime]);
        break;
    case 'force_refresh_videos':
        // Force refresh the video list by regenerating video_order.json
        $config = file_exists($configPath) ? (json_decode(file_get_contents($configPath), true) ?: []) : [];
        $dirs = getConfiguredDirectories($config, $baseDir);
        $allowedExt = ['mp4', 'webm', 'ogg', 'mov'];
        $allVideos = buildAllVideosList($dirs, $allowedExt);
        
        // Generate new order
        $newOrder = [];
        foreach ($allVideos as $video) {
            $videoKey = (isset($dirs[$video['dirIndex']]) ? $dirs[$video['dirIndex']] : '') . '|' . $video['name'];
            $newOrder[] = $videoKey;
        }
        
        // Save new order
        $orderData = ['order' => $newOrder];
        file_put_contents($videoOrderPath, json_encode($orderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // Update current video if needed
        if (!empty($newOrder)) {
            $firstVideo = $allVideos[0];
            writeCurrentVideo($currentVideoPath, $firstVideo['name'], $firstVideo['dirIndex']);
        }
        
        echo json_encode(['status' => 'ok', 'message' => 'Video list refreshed', 'videoCount' => count($newOrder)]);
        break;
    case 'get_current_video':
        [$name, $idx] = readCurrentVideo($currentVideoPath);
        if ($name === null) {
            echo json_encode(['currentVideo' => null]);
        } else {
            echo json_encode(['currentVideo' => ['filename' => $name, 'dirIndex' => $idx]]);
        }
        break;
    case 'set_current_video':
        // Accept POST only
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $filename = $_POST['filename'] ?? '';
        $filename = trim($filename);
        $dirIndex = isset($_POST['dirIndex']) ? (int)$_POST['dirIndex'] : 0;
        // Prevent traversal characters
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid filename']);
            break;
        }
        // Validate dir index against configured directories
        $config = file_exists($configPath) ? (json_decode(file_get_contents($configPath), true) ?: []) : [];
        $dirs = getConfiguredDirectories($config, $baseDir);
        if ($dirIndex < 0 || $dirIndex >= count($dirs)) { $dirIndex = 0; }
        writeCurrentVideo($currentVideoPath, $filename, $dirIndex);
        echo json_encode(['status' => 'ok', 'currentVideo' => ['filename' => $filename, 'dirIndex' => $dirIndex]]);
        break;
    case 'clear_current_video':
        // Accept POST only
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        // Clear the current video file
        try {
            file_put_contents($currentVideoPath, '');
            echo json_encode(['status' => 'ok', 'currentVideo' => '']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to clear current video: ' . $e->getMessage()]);
        }
        break;
    case 'play_video':
        // Accept POST only
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        if (!safeFileWrite($playbackStatePath, 'play')) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to set playback state']);
            break;
        }
        echo json_encode(['status' => 'ok', 'action' => 'play']);
        break;
    case 'pause_video':
        // Accept POST only
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        if (!safeFileWrite($playbackStatePath, 'pause')) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to set playback state']);
            break;
        }
        echo json_encode(['status' => 'ok', 'action' => 'pause']);
        break;
    case 'stop_video':
        // Accept POST only
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        if (!safeFileWrite($playbackStatePath, 'stop')) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to set playback state']);
            break;
        }
        echo json_encode(['status' => 'ok', 'action' => 'stop']);
        break;
    case 'get_playback_state':
        $state = 'play';
        if (file_exists($playbackStatePath)) {
            $state = safeFileRead($playbackStatePath, 'play');
        }
        echo json_encode(['state' => $state]);
        break;
    case 'set_volume':
        // Accept POST only
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $volume = (int)($_POST['volume'] ?? 100);
        $volume = max(0, min(100, $volume)); // Clamp between 0-100
        
        if (!safeFileWrite($volumePath, $volume)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to write volume setting']);
            break;
        }
        
        // Signal volume change for near-instant pickup by screens
        $volumeSignalPath = $profileDir . '/volume_signal.txt';
        @safeFileWrite($volumeSignalPath, (string)time());
        echo json_encode(['status' => 'ok', 'volume' => $volume]);
        break;
    case 'get_volume':
        $volume = 100;
        if (file_exists($volumePath)) {
            $volume = (int)safeFileRead($volumePath, '100');
        }
        echo json_encode(['volume' => $volume]);
        break;
    case 'check_volume_signal':
        $volumeSignalPath = $profileDir . '/volume_signal.txt';
        $ts = 0;
        if (file_exists($volumeSignalPath)) {
            $ts = (int)safeFileRead($volumeSignalPath, '0');
        }
        echo json_encode(['volume_signal' => $ts]);
        break;
    case 'toggle_mute':
        // Accept POST only
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $muteState = 'unmuted';
        if (file_exists($muteStatePath)) {
            $muteState = trim(file_get_contents($muteStatePath));
        }
        // Toggle mute state
        $newMuteState = ($muteState === 'muted') ? 'unmuted' : 'muted';
        file_put_contents($muteStatePath, $newMuteState);
        // Signal mute change
        $muteSignalPath = $profileDir . '/mute_signal.txt';
        @file_put_contents($muteSignalPath, (string)time());
        echo json_encode(['status' => 'ok', 'muted' => ($newMuteState === 'muted')]);
        break;
    case 'get_mute_state':
        $muteState = 'unmuted';
        if (file_exists($muteStatePath)) {
            $muteState = trim(file_get_contents($muteStatePath));
        }
        echo json_encode(['muted' => ($muteState === 'muted')]);
        break;
    case 'check_mute_signal':
        $muteSignalPath = $profileDir . '/mute_signal.txt';
        $ts = 0;
        if (file_exists($muteSignalPath)) {
            $ts = (int)trim(@file_get_contents($muteSignalPath));
        }
        echo json_encode(['mute_signal' => $ts]);
        break;
    case 'set_loop_mode':
        // Accept POST only
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $loopMode = $_POST['loop'] ?? 'off';
        $loopMode = $loopMode === 'on' ? 'on' : 'off';
        file_put_contents($loopModePath, $loopMode);
        echo json_encode(['status' => 'ok', 'loop' => $loopMode]);
        break;
    case 'get_loop_mode':
        $loopMode = 'off'; // Default to loop disabled
        if (file_exists($loopModePath)) {
            $loopMode = trim(file_get_contents($loopModePath));
        }
        echo json_encode(['loop' => $loopMode]);
        break;
    case 'set_play_all_mode':
        // Accept POST only
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $playAllMode = $_POST['play_all'] ?? 'off';
        $playAllMode = $playAllMode === 'on' ? 'on' : 'off';
        file_put_contents($playAllModePath, $playAllMode);
        echo json_encode(['status' => 'ok', 'play_all' => $playAllMode]);
        break;
    case 'get_play_all_mode':
        $playAllMode = 'off';
        if (file_exists($playAllModePath)) {
            $playAllMode = trim(file_get_contents($playAllModePath));
        }
        echo json_encode(['play_all' => $playAllMode]);
        break;
    case 'set_external_audio_mode':
        // Accept POST only
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $external = $_POST['external'] ?? 'off';
        $external = $external === 'on' ? 'on' : 'off';
        file_put_contents($externalAudioModePath, $external);
        echo json_encode(['status' => 'ok', 'external' => $external]);
        break;
    case 'get_external_audio_mode':
        $external = 'off';
        if (file_exists($externalAudioModePath)) {
            $external = trim(file_get_contents($externalAudioModePath));
        }
        echo json_encode(['external' => $external]);
        break;
    case 'trigger_dashboard_refresh':
        // Accept POST only
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        // Create a refresh trigger file
        file_put_contents($dashboardRefreshPath, time());
        echo json_encode(['status' => 'ok', 'message' => 'Refresh signal sent']);
        break;
    case 'check_refresh_signal':
        // Check profile-specific refresh first, then global legacy
        $shouldRefresh = false;
        $candidates = [ $dashboardRefreshPath, $baseDir . '/data/dashboard_refresh.txt', $baseDir . '/dashboard_refresh.txt' ];
        foreach ($candidates as $refreshFile) {
            if (file_exists($refreshFile)) {
                $lastRefresh = (int)@file_get_contents($refreshFile);
                $currentTime = time();
                if ($currentTime - $lastRefresh < 10) {
                    $shouldRefresh = true;
                    @unlink($refreshFile);
                    break;
                }
            }
        }
        echo json_encode(['should_refresh' => $shouldRefresh]);
        break;
    case 'get_video_titles':
        // Get all video titles from titles file
        $titlesPath = $videoTitlesPath;
        $titles = [];
        if (file_exists($titlesPath)) {
            $titles = json_decode(file_get_contents($titlesPath), true) ?: [];
        }
        echo json_encode(['titles' => $titles]);
        break;
    case 'set_video_title':
        // Accept POST only
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $filename = $_POST['filename'] ?? '';
        $title = $_POST['title'] ?? '';
        $dirIndex = isset($_POST['dirIndex']) ? (int)$_POST['dirIndex'] : 0;
        $filename = trim($filename);
        $title = trim($title);
        // Basic validation
        if ($filename === '' || strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid filename']);
            break;
        }
        if ($dirIndex < 0) { $dirIndex = 0; }
        // Normalize title: strip control chars and limit length
        $title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title);
        if (mb_strlen($title) > 200) {
            $title = mb_substr($title, 0, 200);
        }
        
        $titlesPath = $videoTitlesPath;
        $titles = [];
        if (file_exists($titlesPath)) {
            $titles = json_decode(file_get_contents($titlesPath), true) ?: [];
        }
        
        $titles[$dirIndex . '|' . $filename] = $title;
        file_put_contents($titlesPath, json_encode($titles, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'ok', 'title' => $title]);
        break;
    case 'get_all_videos':
        // Return paginated videos across all configured directories, respecting saved order
        $config = file_exists($configPath) ? (json_decode(file_get_contents($configPath), true) ?: []) : [];
        $dirs = getConfiguredDirectories($config, $baseDir);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        $allowedExt = ['mp4', 'webm', 'ogg', 'mov'];
        // Prefer using admin cache to avoid rescanning large directories on every request
        $allWithMeta = [];
        $adminCachePath = $baseDir . '/data/admin_cache.json';
        $useCache = false;
        if (file_exists($adminCachePath)) {
            $adminCache = json_decode(@file_get_contents($adminCachePath), true) ?: [];
            if (!empty($adminCache['all_video_files']) && is_array($adminCache['all_video_files'])) {
                // Determine if cache is still fresh by comparing directory mtimes
                $lastScan = (int)($adminCache['last_scan'] ?? 0);
                $maxDirModTime = 0;
                foreach ($dirs as $dir) {
                    if (is_dir($dir)) {
                        $maxDirModTime = max($maxDirModTime, @filemtime($dir) ?: 0);
                    }
                }
                if ($lastScan >= $maxDirModTime) {
                    $useCache = true;
                    foreach ($adminCache['all_video_files'] as $v) {
                        $di = (int)($v['dirIndex'] ?? 0);
                        $name = (string)($v['name'] ?? '');
                        if ($name === '' || !isset($dirs[$di])) { continue; }
                        $dirPath = $dirs[$di];
                        $allWithMeta[] = [
                            'name' => $name,
                            'dirIndex' => $di,
                            'dirPath' => $dirPath,
                            'key' => $dirPath . '|' . $name,
                        ];
                    }
                }
            }
        }
        // Fallback to a fresh scan if cache is not available or stale
        if (!$useCache) {
            $allWithMeta = buildAllVideosList($dirs, $allowedExt);
        }
        $savedOrder = loadVideoOrder($baseDir, $profileId);

        // Build current ordered keys: first saved order entries that still exist, then remaining by name+dir
        $existingKeys = array_column($allWithMeta, 'key');
        $existingSet = array_flip($existingKeys);
        $orderedKeys = [];
        foreach ($savedOrder as $k) { if (isset($existingSet[$k])) { $orderedKeys[] = $k; } }
        // Add missing (new) keys sorted by name then dirIndex
        $missing = array_values(array_filter($allWithMeta, function ($v) use ($orderedKeys) { return !in_array($v['key'], $orderedKeys, true); }));
        usort($missing, function ($a, $b) {
            $c = strcmp($a['name'], $b['name']);
            return $c !== 0 ? $c : ($a['dirIndex'] <=> $b['dirIndex']);
        });
        foreach ($missing as $m) { $orderedKeys[] = $m['key']; }

        // Map keys back to minimal items
        $keyToItem = [];
        foreach ($allWithMeta as $v) { $keyToItem[$v['key']] = ['name' => $v['name'], 'dirIndex' => $v['dirIndex']]; }
        $orderedItems = [];
        foreach ($orderedKeys as $k) { if (isset($keyToItem[$k])) { $orderedItems[] = $keyToItem[$k]; } }

        $totalCount = count($orderedItems);
        $pageItems = array_slice($orderedItems, $offset, $limit);
        echo json_encode(['videos' => $pageItems, 'pagination' => ['total' => $totalCount, 'page' => $page, 'limit' => $limit, 'pages' => ceil($totalCount / $limit)]]);
        break;
    case 'warm_thumbnails':
        // Pre-generate thumbnails in batches using the admin cache list
        // Query params: offset (default 0), batch (default 10)
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $batch = max(1, min(50, (int)($_GET['batch'] ?? 10)));
        $adminCachePath = $baseDir . '/data/admin_cache.json';
        $thumbDir = $baseDir . '/data/thumbs';
        
        // Ensure thumbs directory exists
        if (!is_dir($thumbDir)) {
            @mkdir($thumbDir, 0777, true);
        }
        
        // Ensure we have the latest configured directories available for path reconstruction
        $config = file_exists($configPath) ? (json_decode(@file_get_contents($configPath), true) ?: []) : [];
        $all = [];
        if (file_exists($adminCachePath)) {
            $cache = json_decode(@file_get_contents($adminCachePath), true) ?: [];
            if (!empty($cache['all_video_files']) && is_array($cache['all_video_files'])) {
                $all = $cache['all_video_files'];
            }
        }
        
        $total = count($all);
        if ($total === 0) {
            echo json_encode(['status' => 'ok', 'total' => 0, 'processed' => 0, 'failed' => 0, 'remaining' => 0, 'nextOffset' => $offset]);
            break;
        }
        
        $end = min($total, $offset + $batch);
        $processed = 0;
        $failed = 0;
        
        for ($i = $offset; $i < $end; $i++) {
            $entry = $all[$i];
            $videoPath = '';
            if (!empty($entry['path']) && is_string($entry['path'])) {
                $videoPath = $entry['path'];
            } else {
                // Reconstruct from name + dirIndex if needed
                $dirs = getConfiguredDirectories($config ?? [], $baseDir);
                $di = (int)($entry['dirIndex'] ?? 0);
                if (isset($dirs[$di])) {
                    $sep = (strpos($dirs[$di], ':\\') !== false) ? '\\' : '/';
                    $videoPath = rtrim($dirs[$di], '\\/') . $sep . (string)($entry['name'] ?? '');
                }
            }
            
            if ($videoPath !== '' && is_file($videoPath)) {
                // Try to generate thumbnail using the same logic as thumb.php
                $mtime = @filemtime($videoPath) ?: 0;
                $hash = sha1($videoPath . '|' . $mtime);
                $thumbPath = rtrim($thumbDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.jpg';
                
                if (is_file($thumbPath)) {
                    $processed++; // Already exists
                } else {
                    // Check ffmpeg availability
                    $which = (stripos(PHP_OS, 'WIN') === 0) ? 'where' : 'which';
                    $cmdCheck = $which . ' ffmpeg' . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
                    @exec($cmdCheck, $out, $code);
                    
                    if ($code === 0) {
                        $escapedIn = escapeshellarg($videoPath);
                        $escapedOut = escapeshellarg($thumbPath);
                        $cmd = 'ffmpeg -ss 1 -i ' . $escapedIn . ' -frames:v 1 -vf "scale=480:-1" -q:v 5 -y ' . $escapedOut . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
                        @exec($cmd, $o, $c);
                        if ($c === 0 && is_file($thumbPath)) {
                            $processed++;
                        } else {
                            $failed++;
                        }
                    } else {
                        $failed++;
                    }
                }
            } else {
                $failed++;
            }
        }
        
        $remaining = max(0, $total - $end);
        echo json_encode([
            'status' => 'ok',
            'total' => $total, 
            'processed' => $processed, 
            'failed' => $failed,
            'remaining' => $remaining, 
            'nextOffset' => $end
        ]);
        break;
    case 'check_ffmpeg':
        // Check if FFmpeg is available and working
        $which = (stripos(PHP_OS, 'WIN') === 0) ? 'where' : 'which';
        $cmdCheck = $which . ' ffmpeg' . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
        @exec($cmdCheck, $out, $code);
        
        if ($code === 0) {
            // Test FFmpeg with a simple command
            $testCmd = 'ffmpeg -version' . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
            @exec($testCmd, $versionOut, $versionCode);
            
            if ($versionCode === 0) {
                echo json_encode(['available' => true, 'version' => trim($versionOut[0] ?? 'Unknown')]);
            } else {
                echo json_encode(['available' => false, 'error' => 'FFmpeg found but failed to execute version command']);
            }
        } else {
            echo json_encode(['available' => false, 'error' => 'FFmpeg not found in system PATH']);
        }
        break;
    case 'get_video_count':
        // Get total count of videos across all configured directories
        $config = file_exists($configPath) ? (json_decode(@file_get_contents($configPath), true) ?: []) : [];
        $dirs = getConfiguredDirectories($config, $baseDir);
        $allowedExt = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'];
        $totalCount = 0;
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                // Try relative path if absolute doesn't exist
                $relativePath = realpath($baseDir . '/' . $dir);
                if ($relativePath && is_dir($relativePath)) {
                    $dir = $relativePath;
                } else {
                    continue; // Skip invalid directories
                }
            }
            
            $allFiles = @scandir($dir);
            if ($allFiles === false) continue;
            
            foreach ($allFiles as $file) {
                if ($file !== '.' && $file !== '..' && is_file($dir . DIRECTORY_SEPARATOR . $file)) {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($ext, $allowedExt)) {
                        $totalCount++;
                    }
                }
                // Limit to prevent infinite loops on very large directories
                if ($totalCount > 10000) break 2;
            }
        }
        
        echo json_encode(['count' => $totalCount]);
        break;
    case 'get_next_video':
        // Get the next video across all configured dirs, respecting saved order
        [$currentName, $currentDir] = readCurrentVideo($currentVideoPath);
        $config = file_exists($configPath) ? (json_decode(file_get_contents($configPath), true) ?: []) : [];
        $dirs = getConfiguredDirectories($config, $baseDir);
        $allowedExt = ['mp4', 'webm', 'ogg', 'mov'];
        $allWithMeta = buildAllVideosList($dirs, $allowedExt);
        $savedOrder = loadVideoOrder($baseDir, $profileId);

        // Parse the saved order to extract filenames and dirIndexes
        $orderedItems = [];
        foreach ($savedOrder as $orderKey) {
            if (strpos($orderKey, '|') !== false) {
                [$dirPath, $filename] = explode('|', $orderKey, 2);
                // Find the dirIndex for this directory
                $dirIndex = array_search($dirPath, $dirs);
                if ($dirIndex !== false) {
                    $orderedItems[] = ['name' => $filename, 'dirIndex' => $dirIndex];
                }
            }
        }

        // Add any missing videos from the current directory structure
        $existingKeys = array_column($allWithMeta, 'key');
        $existingSet = array_flip($existingKeys);
        $orderedKeys = [];
        foreach ($savedOrder as $k) { if (isset($existingSet[$k])) { $orderedKeys[] = $k; } }
        $missing = array_values(array_filter($allWithMeta, function ($v) use ($orderedKeys) { return !in_array($v['key'], $orderedKeys, true); }));
        usort($missing, function ($a, $b) {
            $c = strcmp($a['name'], $b['name']);
            return $c !== 0 ? $c : ($a['dirIndex'] <=> $b['dirIndex']);
        });
        foreach ($missing as $m) { 
            $orderedItems[] = ['name' => $m['name'], 'dirIndex' => $m['dirIndex']]; 
        }

        $next = null;
        if (!empty($orderedItems)) {
            if (!$currentName) {
                $next = $orderedItems[0];
            } else {
                $idx = -1;
                foreach ($orderedItems as $k => $v) {
                    if ($v['name'] === $currentName && $v['dirIndex'] === $currentDir) { $idx = $k; break; }
                }
                $next = $idx === -1 ? $orderedItems[0] : $orderedItems[($idx + 1) % count($orderedItems)];
            }
        }
        
        echo json_encode(['nextVideo' => $next]);
        break;

    case 'move_video':
        // Move a video up or down in the saved order
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        $filename = trim($_POST['filename'] ?? '');
        $dirIndex = isset($_POST['dirIndex']) ? (int)$_POST['dirIndex'] : 0;
        $direction = $_POST['direction'] ?? '';
        if ($filename === '' || !in_array($direction, ['up', 'down'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid parameters']);
            break;
        }
        // Validate filename
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid filename']);
            break;
        }
        $config = file_exists($configPath) ? (json_decode(@file_get_contents($configPath), true) ?: []) : [];
        $dirs = getConfiguredDirectories($config, $baseDir);
        if ($dirIndex < 0 || $dirIndex >= count($dirs)) { $dirIndex = 0; }
        $allowedExt = ['mp4', 'webm', 'ogg', 'mov'];
        $allWithMeta = buildAllVideosList($dirs, $allowedExt);

        // Build ordered list (per profile)
        $savedOrder = loadVideoOrder($baseDir, $profileId);
        $existingKeys = array_column($allWithMeta, 'key');
        $existingSet = array_flip($existingKeys);
        $orderedKeys = [];
        foreach ($savedOrder as $k) { if (isset($existingSet[$k])) { $orderedKeys[] = $k; } }
        $missing = array_values(array_filter($allWithMeta, function ($v) use ($orderedKeys) { return !in_array($v['key'], $orderedKeys, true); }));
        usort($missing, function ($a, $b) {
            $c = strcmp($a['name'], $b['name']);
            return $c !== 0 ? $c : ($a['dirIndex'] <=> $b['dirIndex']);
        });
        foreach ($missing as $m) { $orderedKeys[] = $m['key']; }

        $targetKey = $dirs[$dirIndex] . '|' . $filename;
        $idx = array_search($targetKey, $orderedKeys, true);
        if ($idx === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Video not found']);
            break;
        }
        if ($direction === 'up' && $idx > 0) {
            $tmp = $orderedKeys[$idx - 1];
            $orderedKeys[$idx - 1] = $orderedKeys[$idx];
            $orderedKeys[$idx] = $tmp;
        } elseif ($direction === 'down' && $idx < count($orderedKeys) - 1) {
            $tmp = $orderedKeys[$idx + 1];
            $orderedKeys[$idx + 1] = $orderedKeys[$idx];
            $orderedKeys[$idx] = $tmp;
        }

        saveVideoOrder($baseDir, $orderedKeys, $profileId);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok']);
        break;
    case 'browse_directories':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                break;
            }
            
            $path = trim($_POST['path'] ?? '');
            
            // Clean up path separators for Windows
            if (PHP_OS_FAMILY === 'Windows') {
                $path = str_replace('/', '\\', $path);
            }
            
            // Security: Allow common roots and UNC paths on Windows
            $allowedRoots = ['C:', 'D:', 'E:', 'F:', 'G:', 'H:', '/', '/mnt', '/media'];
            $isAllowed = false;
            
            if ($path === '') {
                $isAllowed = true; // allow listing roots
            }
            if (!$isAllowed) {
                foreach ($allowedRoots as $root) {
                    if (strpos($path, $root) === 0) {
                        $isAllowed = true;
                        break;
                    }
                }
            }
            if (PHP_OS_FAMILY === 'Windows' && !$isAllowed) {
                // Allow paths starting with \\SERVER\Share or \\?\UNC\SERVER\Share
                if (strpos($path, '\\') === 0) {
                    $isAllowed = true;
                }
            }
            
            if (!$isAllowed) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied to this path']);
                break;
            }
            
            try {
                if (empty($path)) {
                    // Show available drives on Windows or root on Linux
                    if (PHP_OS_FAMILY === 'Windows') {
                        $directories = [];
                        foreach (range('A', 'Z') as $drive) {
                            $drivePath = $drive . ':\\';
                            if (is_dir($drivePath)) {
                                $directories[] = $drivePath;
                            }
                        }
                        echo json_encode(['status' => 'ok', 'directories' => $directories, 'currentPath' => '']);
                    } else {
                        $directories = [];
                        $rootDirs = ['/home', '/mnt', '/media', '/var', '/usr'];
                        foreach ($rootDirs as $dir) {
                            if (is_dir($dir)) {
                                $directories[] = basename($dir);
                            }
                        }
                        echo json_encode(['status' => 'ok', 'directories' => $directories, 'currentPath' => '/']);
                    }
                } else {
                    if (!is_dir($path)) {
                        echo json_encode(['error' => 'Directory not found or not accessible']);
                        break;
                    }
                    
                    $directories = [];
                    $videoFiles = [];
                    $items = @scandir($path);
                    if ($items === false) {
                        echo json_encode(['error' => 'Directory not accessible']);
                        break;
                    }
                    foreach ($items as $item) {
                        if ($item !== '.' && $item !== '..') {
                            $itemPath = $path . (PHP_OS_FAMILY === 'Windows' ? '\\' : '/') . $item;
                            if (is_dir($itemPath)) {
                                $directories[] = $item;
                            } else {
                                // Check if it's a video file
                                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                                if (in_array($ext, ['mp4', 'webm', 'ogg', 'mov'])) {
                                    $videoFiles[] = $item;
                                }
                            }
                        }
                    }
                    
                    sort($directories);
                    sort($videoFiles);
                    echo json_encode([
                        'status' => 'ok', 
                        'directories' => $directories, 
                        'videoFiles' => $videoFiles,
                        'currentPath' => $path
                    ]);
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to browse directory: ' . $e->getMessage()]);
            }
            break;
    case 'get_config':
        $config = [];
        if (file_exists($configPath)) {
            $config = json_decode(file_get_contents($configPath), true);
        }
        echo json_encode($config);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}
