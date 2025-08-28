<?php
/**
 * API endpoint for admin actions
 */

// Include state manager for consistency
// Temporarily disabled to isolate the issue
// require_once __DIR__ . '/state_manager.php';

// Set error reporting to catch any issues (but don't display them)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set headers including CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Determine action and allowlist read-only endpoints for unauthenticated access
// Support legacy suffix style: e.g. action=browse_directories@admin.php
$rawAction = $_GET['action'] ?? '';
$actionContext = '';
if (is_string($rawAction) && strpos($rawAction, '@') !== false) {
    list($action, $actionContext) = explode('@', $rawAction, 2);
} else {
    $action = $rawAction;
}
$readOnlyActions = [
    'get_current_video',
    'get_playback_state',
    'get_volume',
    'get_mute_state',
    'get_loop_mode',
    'get_play_all_mode',
    'get_external_audio_mode',
    'get_video_titles',
    'get_all_videos',
    'check_config_changes',
    'check_refresh_signal',
    'test_connection',
    'simple_test',
    'debug_state',
    'log_event',
    'list_video_codecs',
    'encode_vp9_status',
    'browse_directories',
    // Allow dashboard control actions for kiosk devices
    'set_current_video',
    'clear_current_video',
    'play_video',
    'pause_video',
    'stop_video',
    'set_volume',
    'toggle_mute',
    'set_loop_mode',
    'set_play_all_mode',
    'set_external_audio_mode',
];
$enforceSecurity = !in_array($action, $readOnlyActions, true);

// Security check - ensure this is called from admin context
// Check multiple security measures to be more robust
$isSecure = false;

// Method 1: Check referrer (if available)
if (isset($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    if (strpos($ref, 'admin.php') !== false || strpos($ref, 'dashboard.php') !== false) {
        $isSecure = true;
    }
}

// Method 2: Check if we're in the same domain and session is active
if (!$isSecure && isset($_SERVER['HTTP_HOST']) && isset($_SERVER['SERVER_NAME'])) {
    if ($_SERVER['HTTP_HOST'] === $_SERVER['SERVER_NAME'] || $_SERVER['HTTP_HOST'] === 'localhost') {
        // Start session if not already started
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        // Check if we have any admin session data or if we're in admin/dashboard context
        if (isset($_SESSION['admin_authenticated']) || 
            (isset($_SERVER['REQUEST_URI']) && (
                strpos($_SERVER['REQUEST_URI'], 'admin') !== false ||
                strpos($_SERVER['REQUEST_URI'], 'dashboard') !== false
            ))) {
            $isSecure = true;
        }
    }
}

// Method 3: Check if we're accessing from the same directory structure
if (!$isSecure && isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], 'admin') !== false) {
    $isSecure = true;
}

// Method 4: Development mode - allow localhost access (remove in production)
if (!$isSecure && isset($_SERVER['HTTP_HOST']) && 
    (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || 
     strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false)) {
    $isSecure = true;
}

// Method 5: Action context hint provided via suffix (e.g., @admin.php or @dashboard.php)
if (!$isSecure && isset($actionContext) && $actionContext !== '') {
    if (strpos($actionContext, 'admin.php') !== false || strpos($actionContext, 'dashboard.php') !== false) {
        $isSecure = true;
    }
}

if ($enforceSecurity && !$isSecure) {
    http_response_code(403);
    echo json_encode([
        'success' => false, 
        'error' => 'Access denied - insufficient security context',
        'debug_info' => [
            'referer' => $_SERVER['HTTP_REFERER'] ?? 'none',
            'host' => $_SERVER['HTTP_HOST'] ?? 'none',
            'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'none',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'none'
        ]
    ]);
    exit;
}

// Note: $action already set above

switch ($action) {
    case 'stop_all_processes':
        stopAllProcesses();
        break;
        
    case 'check_ffmpeg':
        checkFFmpeg();
        break;
        
    case 'get_video_count':
        getVideoCount();
        break;
        
    case 'test_connection':
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'message' => 'API connection successful', 'timestamp' => time()]);
        break;

    case 'simple_test':
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'message' => 'Simple test successful', 'timestamp' => time()]);
        break;

    case 'list_video_codecs':
        listVideoCodecs();
        break;

    case 'encode_vp9_single':
        encodeVp9Single();
        break;

    case 'encode_vp9_status':
        encodeVp9Status();
        break;

    case 'log_event':
        logEvent();
        break;

    case 'debug_state':
        // Temporarily disable the complex debug to isolate the issue
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'message' => 'Debug endpoint working']);
        break;
        
    case 'get_volume':
        getVolume();
        break;
        
    case 'set_volume':
        setVolume();
        break;
        
    case 'get_mute_state':
        getMuteState();
        break;
        
    case 'toggle_mute':
        toggleMute();
        break;
        
    case 'play_video':
        playVideo();
        break;
        
    case 'pause_video':
        pauseVideo();
        break;
        
    case 'stop_video':
        stopVideo();
        break;
        
    case 'set_current_video':
        setCurrentVideo();
        break;
        
    case 'clear_current_video':
        clearCurrentVideo();
        break;
        
    case 'get_current_video':
        getCurrentVideo();
        break;
        
    case 'get_playback_state':
        getPlaybackState();
        break;
        
    case 'get_loop_mode':
        getLoopMode();
        break;
        
    case 'set_loop_mode':
        setLoopMode();
        break;
        
    case 'get_play_all_mode':
        getPlayAllMode();
        break;
        
    case 'set_play_all_mode':
        setPlayAllMode();
        break;
        
    case 'get_external_audio_mode':
        getExternalAudioMode();
        break;
        
    case 'set_external_audio_mode':
        setExternalAudioModeEndpoint();
        break;
        
    case 'get_video_titles':
        getVideoTitles();
        break;

    case 'set_video_title':
        setVideoTitleEndpoint();
        break;

    case 'get_all_videos':
        getAllVideos();
        break;

    case 'check_config_changes':
        checkConfigChanges();
        break;

    case 'move_video':
        moveVideoEndpoint();
        break;
        
    case 'check_refresh_signal':
        checkRefreshSignal();
        break;
    
    case 'trigger_dashboard_refresh':
        triggerDashboardRefreshEndpoint();
        break;
        
    case 'warm_thumbnails':
        warmThumbnails();
        break;
        
    case 'warm_previews':
        warmPreviews();
        break;

    case 'browse_directories':
        browseDirectories();
        break;
        
    default:
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        break;
}

function stopAllProcesses() {
    try {
        // Log the request for debugging
        error_log("API: stop_all_processes called from " . ($_SERVER['HTTP_REFERER'] ?? 'no referrer'));
        
        $stoppedCount = 0;
        $errors = [];
        
        // Stop FFmpeg processes on Windows
        if (stripos(PHP_OS, 'WIN') === 0) {
            // Find and stop FFmpeg processes
            $output = [];
            exec('tasklist /FI "IMAGENAME eq ffmpeg.exe" /FO CSV', $output);
            
            foreach ($output as $line) {
                if (strpos($line, 'ffmpeg.exe') !== false) {
                    $parts = str_getcsv($line);
                    if (isset($parts[1]) && is_numeric($parts[1])) {
                        $pid = (int)$parts[1];
                        // Try graceful termination first
                        exec("taskkill /PID $pid /F", $killOutput, $returnCode);
                        if ($returnCode === 0) {
                            $stoppedCount++;
                        } else {
                            $errors[] = "Failed to stop FFmpeg process $pid";
                        }
                    }
                }
            }
        } else {
            // Unix/Linux systems
            $output = [];
            exec('pgrep -f ffmpeg', $output);
            
            foreach ($output as $pid) {
                $pid = trim($pid);
                if (is_numeric($pid)) {
                    $intPid = (int)$pid;
                    if (function_exists('posix_kill')) {
                        if (!defined('SIGTERM')) { define('SIGTERM', 15); }
                        if (!defined('SIGKILL')) { define('SIGKILL', 9); }
                        // Try graceful termination first
                        @posix_kill($intPid, SIGTERM);
                        sleep(1);
                        // Check if process still exists
                        if (@posix_kill($intPid, 0)) {
                            // Force kill if still running
                            @posix_kill($intPid, SIGKILL);
                        }
                    } else {
                        // Fallback using shell kill
                        @exec('kill -TERM ' . $intPid . ' 2>/dev/null');
                        sleep(1);
                        @exec('kill -0 ' . $intPid . ' >/dev/null 2>&1 || true', $chkOut, $chkCode);
                        if ($chkCode === 0) {
                            @exec('kill -KILL ' . $intPid . ' 2>/dev/null');
                        }
                    }
                    $stoppedCount++;
                }
            }
        }
        
        // Also stop any PHP processes that might be running long operations
        $currentPid = getmypid();
        $output = [];
        
        if (stripos(PHP_OS, 'WIN') === 0) {
            exec('tasklist /FI "IMAGENAME eq php.exe" /FO CSV', $output);
        } else {
            exec('pgrep -f "php.*admin"', $output);
        }
        
        foreach ($output as $line) {
            if (stripos(PHP_OS, 'WIN') === 0) {
                $parts = str_getcsv($line);
                if (isset($parts[1]) && is_numeric($parts[1])) {
                    $pid = (int)$parts[1];
                    if ($pid !== $currentPid) {
                        // Don't kill ourselves
                        exec("taskkill /PID $pid /F", $killOutput, $returnCode);
                        if ($returnCode === 0) {
                            $stoppedCount++;
                        }
                    }
                }
            } else {
                $pid = trim($line);
                if (is_numeric($pid)) {
                    $intPid = (int)$pid;
                    if ($intPid !== $currentPid) {
                        if (function_exists('posix_kill')) {
                            if (!defined('SIGTERM')) { define('SIGTERM', 15); }
                            @posix_kill($intPid, SIGTERM);
                        } else {
                            @exec('kill -TERM ' . $intPid . ' 2>/dev/null');
                        }
                        $stoppedCount++;
                    }
                }
            }
        }
        
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode([
            'success' => true,
            'stopped_count' => $stoppedCount,
            'errors' => $errors
        ]);

    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function checkFFmpeg() {
    try {
        $output = [];
        $returnCode = 0;
        
        if (stripos(PHP_OS, 'WIN') === 0) {
            exec('ffmpeg -version 2>&1', $output, $returnCode);
        } else {
            exec('which ffmpeg 2>&1', $output, $returnCode);
        }
        
        if ($returnCode === 0) {
            header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
            echo json_encode([
                'success' => true,
                'available' => true,
                'version' => implode("\n", $output)
            ]);
        } else {
            header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
            echo json_encode([
                'success' => true,
                'available' => false,
                'error' => 'FFmpeg not found or not accessible'
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => true,
            'available' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function getVideoCount() {
    try {
        // Try to get count from admin cache first
        $adminCachePath = __DIR__ . '/data/admin_cache.json';
        $count = 0;
        
        if (file_exists($adminCachePath)) {
            $cache = json_decode(@file_get_contents($adminCachePath), true) ?: [];
            if (!empty($cache['total_videos']) && is_numeric($cache['total_videos'])) {
                $count = (int)$cache['total_videos'];
            } elseif (!empty($cache['all_video_files']) && is_array($cache['all_video_files'])) {
                $count = count($cache['all_video_files']);
            }
        }
        
        // Fallback to scanning videos directory if cache is empty
        if ($count === 0) {
            $videosDir = __DIR__ . '/videos';
            if (is_dir($videosDir)) {
                $files = scandir($videosDir);
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                        if (in_array($ext, ['mp4', 'avi', 'mov', 'mkv', 'webm', 'flv', 'wmv'])) {
                            $count++;
                        }
                    }
                }
            }
        }
        
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode([
            'success' => true,
            'count' => $count
        ]);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function getConfiguredDirectories() {
    $configPath = __DIR__ . '/config.json';
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true) ?: [];
        if (!empty($config['directories']) && is_array($config['directories'])) {
            return $config['directories'];
        } elseif (!empty($config['directory'])) {
            return [$config['directory']];
        }
    }
    return ['videos']; // Default fallback
}

function warmThumbnails() {
    try {
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $batch = max(1, min(50, (int)($_GET['batch'] ?? 10)));
        $adminCachePath = __DIR__ . '/data/admin_cache.json';
        $thumbDir = __DIR__ . '/data/thumbs';
        
        // Ensure thumbs directory exists
        if (!is_dir($thumbDir)) {
            @mkdir($thumbDir, 0777, true);
        }
        
        // Ensure we have the latest configured directories available for path reconstruction
        $all = [];
        if (file_exists($adminCachePath)) {
            $cache = json_decode(@file_get_contents($adminCachePath), true) ?: [];
            if (!empty($cache['all_video_files']) && is_array($cache['all_video_files'])) {
                $all = $cache['all_video_files'];
            }
        }
        
        $total = count($all);
        if ($total === 0) {
            echo json_encode([
                'status' => 'ok', 
                'total' => 0, 
                'processed' => 0, 
                'failed' => 0, 
                'remaining' => 0, 
                'nextOffset' => $offset
            ]);
            return;
        }
        
        // Build list of targets that actually need thumbnail generation (skip existing)
        $targets = [];
        foreach ($all as $entry) {
            $videoPath = '';
            if (!empty($entry['path']) && is_string($entry['path'])) {
                $videoPath = $entry['path'];
            } else {
                $dirs = getConfiguredDirectories();
                $di = (int)($entry['dirIndex'] ?? 0);
                if (isset($dirs[$di])) {
                    $sep = (strpos($dirs[$di], ':\\') !== false) ? '\\' : '/';
                    $videoPath = rtrim($dirs[$di], '\\/') . $sep . (string)($entry['name'] ?? '');
                }
            }
            if ($videoPath !== '' && is_file($videoPath)) {
                $mtime = @filemtime($videoPath) ?: 0;
                $np = str_replace(['\\','/'], DIRECTORY_SEPARATOR, $videoPath);
                if (stripos(PHP_OS, 'WIN') === 0) { $np = strtolower($np); }
                $hash = sha1($np . '|' . $mtime);
                $previewPathCheck = __DIR__ . '/data/previews/' . $hash . '.mp4';
                if (!is_file($previewPathCheck)) {
                    $targets[] = $entry;
                }
            }
        }

        $end = min(count($targets), $offset + $batch);
        $processed = 0;
        $failed = 0;

        for ($i = $offset; $i < $end; $i++) {
            $entry = $targets[$i];
            $videoPath = '';
            if (!empty($entry['path']) && is_string($entry['path'])) {
                $videoPath = $entry['path'];
            } else {
                // Reconstruct from name + dirIndex if needed
                $dirs = getConfiguredDirectories();
                $di = (int)(isset($entry['dirIndex']) ? $entry['dirIndex'] : 0);
                if (isset($dirs[$di])) {
                    $sep = (strpos($dirs[$di], ':\\') !== false) ? '\\' : '/';
                    $videoPath = rtrim($dirs[$di], '\\/') . $sep . (string)(isset($entry['name']) ? $entry['name'] : '');
                }
            }
            
            if ($videoPath !== '' && is_file($videoPath)) {
                // Try to generate thumbnail using the same logic as thumb.php
                $mtime = @filemtime($videoPath) ?: 0;
                $np = str_replace(['\\','/'], DIRECTORY_SEPARATOR, $videoPath);
                if (stripos(PHP_OS, 'WIN') === 0) { $np = strtolower($np); }
                $hash = sha1($np . '|' . $mtime);
                $thumbPath = rtrim($thumbDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.jpg';
                
                if (is_file($thumbPath)) {
                    $processed++; // Already exists
                    // Ensure admin cache has thumbnail metadata
                    try {
                        $acPath = __DIR__ . '/data/admin_cache.json';
                        if (is_file($acPath)) {
                            $cache = json_decode(@file_get_contents($acPath), true);
                            if (is_array($cache) && !empty($cache['all_video_files']) && is_array($cache['all_video_files'])) {
                                $name = isset($entry['name']) ? (string)$entry['name'] : '';
                                $di = (int)($entry['dirIndex'] ?? 0);
                                $p = isset($entry['path']) ? (string)$entry['path'] : '';
                                $updated = false;
                                foreach ($cache['all_video_files'] as &$item) {
                                    $in = isset($item['name']) ? (string)$item['name'] : '';
                                    $idi = (int)($item['dirIndex'] ?? 0);
                                    $ip = isset($item['path']) ? (string)$item['path'] : '';
                                    if ($in === $name && $idi === $di && ($p === '' || $ip === $p)) {
                                        $item['thumb_hash'] = $hash;
                                        $item['thumb_mtime'] = $mtime;
                                        $updated = true;
                                        break;
                                    }
                                }
                                unset($item);
                                if ($updated) {
                                    @file_put_contents($acPath, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                }
                            }
                        }
                    } catch (Exception $__) {}
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
                            // Update admin cache with thumbnail metadata
                            try {
                                $acPath = __DIR__ . '/data/admin_cache.json';
                                if (is_file($acPath)) {
                                    $cache = json_decode(@file_get_contents($acPath), true);
                                    if (is_array($cache) && !empty($cache['all_video_files']) && is_array($cache['all_video_files'])) {
                                        $name = isset($entry['name']) ? (string)$entry['name'] : '';
                                        $di = (int)($entry['dirIndex'] ?? 0);
                                        $p = isset($entry['path']) ? (string)$entry['path'] : '';
                                        $updated = false;
                                        foreach ($cache['all_video_files'] as &$item) {
                                            $in = isset($item['name']) ? (string)$item['name'] : '';
                                            $idi = (int)($item['dirIndex'] ?? 0);
                                            $ip = isset($item['path']) ? (string)$item['path'] : '';
                                            if ($in === $name && $idi === $di && ($p === '' || $ip === $p)) {
                                                $item['thumb_hash'] = $hash;
                                                $item['thumb_mtime'] = $mtime;
                                                $updated = true;
                                                break;
                                            }
                                        }
                                        unset($item);
                                        if ($updated) {
                                            @file_put_contents($acPath, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                        }
                                    }
                                }
                            } catch (Exception $__) {}
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
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'error' => $e->getMessage()
        ]);
    }
}

function warmPreviews() {
    try {
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $batch = max(1, min(10, (int)($_GET['batch'] ?? 2))); // Smaller default batch for previews
        $adminCachePath = __DIR__ . '/data/admin_cache.json';
        $previewDir = __DIR__ . '/data/previews';
        
        // Ensure previews directory exists
        if (!is_dir($previewDir)) {
            @mkdir($previewDir, 0777, true);
        }
        
        // Ensure we have the latest configured directories available for path reconstruction
        $all = [];
        if (file_exists($adminCachePath)) {
            $cache = json_decode(@file_get_contents($adminCachePath), true) ?: [];
            if (!empty($cache['all_video_files']) && is_array($cache['all_video_files'])) {
                $all = $cache['all_video_files'];
            }
        }
        
        $total = count($all);
        if ($total === 0) {
            echo json_encode([
                'status' => 'ok', 
                'total' => 0, 
                'processed' => 0, 
                'failed' => 0, 
                'remaining' => 0, 
                'nextOffset' => $offset
            ]);
            return;
        }
        
        // Build list of targets that actually need generation (skip existing)
        $targets = [];
        foreach ($all as $entry) {
            $videoPath = '';
            if (!empty($entry['path']) && is_string($entry['path'])) {
                $videoPath = $entry['path'];
            } else {
                // Reconstruct from name + dirIndex if needed
                $dirs = getConfiguredDirectories();
                $di = (int)(isset($entry['dirIndex']) ? $entry['dirIndex'] : 0);
                if (isset($dirs[$di])) {
                    $sep = (strpos($dirs[$di], ':\\') !== false) ? '\\' : '/';
                    $videoPath = rtrim($dirs[$di], '\\/') . $sep . (string)(isset($entry['name']) ? $entry['name'] : '');
                }
            }
            if ($videoPath !== '' && is_file($videoPath)) {
                $mtime = @filemtime($videoPath) ?: 0;
                $np = str_replace(['\\','/'], DIRECTORY_SEPARATOR, $videoPath);
                if (stripos(PHP_OS, 'WIN') === 0) { $np = strtolower($np); }
                $hash = sha1($np . '|' . $mtime);
                $previewPathCheck = rtrim($previewDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.mp4';
                if (!is_file($previewPathCheck)) {
                    $targets[] = $entry;
                }
            }
        }

        $end = min(count($targets), $offset + $batch);
        $processed = 0;
        $failed = 0;
        
        for ($i = $offset; $i < $end; $i++) {
            $entry = $targets[$i];
            $videoPath = '';
            if (!empty($entry['path']) && is_string($entry['path'])) {
                $videoPath = $entry['path'];
            } else {
                // Reconstruct from name + dirIndex if needed
                $dirs = getConfiguredDirectories();
                $di = (int)(isset($entry['dirIndex']) ? $entry['dirIndex'] : 0);
                if (isset($dirs[$di])) {
                    $sep = (strpos($dirs[$di], ':\\') !== false) ? '\\' : '/';
                    $videoPath = rtrim($dirs[$di], '\\/') . $sep . (string)(isset($entry['name']) ? $entry['name'] : '');
                }
            }
            
            if ($videoPath !== '' && is_file($videoPath)) {
                // Try to generate preview using FFmpeg
                $mtime = @filemtime($videoPath) ?: 0;
                $np = str_replace(['\\','/'], DIRECTORY_SEPARATOR, $videoPath);
                if (stripos(PHP_OS, 'WIN') === 0) { $np = strtolower($np); }
                $hash = sha1($np . '|' . $mtime);
                $previewPath = rtrim($previewDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.mp4';
                
                if (is_file($previewPath)) {
                    $processed++; // Already exists
                    // Ensure admin cache has preview metadata
                    try {
                        $acPath = __DIR__ . '/data/admin_cache.json';
                        if (is_file($acPath)) {
                            $cache = json_decode(@file_get_contents($acPath), true);
                            if (is_array($cache) && !empty($cache['all_video_files']) && is_array($cache['all_video_files'])) {
                                $name = isset($entry['name']) ? (string)$entry['name'] : '';
                                $di = (int)($entry['dirIndex'] ?? 0);
                                $p = isset($entry['path']) ? (string)$entry['path'] : '';
                                $updated = false;
                                foreach ($cache['all_video_files'] as &$item) {
                                    $in = isset($item['name']) ? (string)$item['name'] : '';
                                    $idi = (int)($item['dirIndex'] ?? 0);
                                    $ip = isset($item['path']) ? (string)$item['path'] : '';
                                    if ($in === $name && $idi === $di && ($p === '' || $ip === $p)) {
                                        $item['preview_hash'] = $hash;
                                        $item['preview_mtime'] = $mtime;
                                        $updated = true;
                                        break;
                                    }
                                }
                                unset($item);
                                if ($updated) {
                                    @file_put_contents($acPath, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                }
                            }
                        }
                    } catch (Exception $__) {}
                } else {
                    // Check ffmpeg availability
                    $which = (stripos(PHP_OS, 'WIN') === 0) ? 'where' : 'which';
                    $cmdCheck = $which . ' ffmpeg' . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
                    @exec($cmdCheck, $out, $code);
                    
                    if ($code === 0) {
                        $escapedIn = escapeshellarg($videoPath);
                        $escapedOut = escapeshellarg($previewPath);
                        
                        // Generate a 5-second preview without audio, scaled to 480p, faster encoding
                        $cmd = 'ffmpeg -ss 00:00:05 -i ' . $escapedIn . ' -t 5 -vf "scale=480:-2" -c:v libx264 -preset fast -crf 28 -an -y ' . $escapedOut . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
                        @exec($cmd, $o, $c);
                        if ($c === 0 && is_file($previewPath)) {
                            $processed++;
                            // Update admin cache with preview metadata
                            try {
                                $acPath = __DIR__ . '/data/admin_cache.json';
                                if (is_file($acPath)) {
                                    $cache = json_decode(@file_get_contents($acPath), true);
                                    if (is_array($cache) && !empty($cache['all_video_files']) && is_array($cache['all_video_files'])) {
                                        $name = isset($entry['name']) ? (string)$entry['name'] : '';
                                        $di = (int)($entry['dirIndex'] ?? 0);
                                        $p = isset($entry['path']) ? (string)$entry['path'] : '';
                                        $updated = false;
                                        foreach ($cache['all_video_files'] as &$item) {
                                            $in = isset($item['name']) ? (string)$item['name'] : '';
                                            $idi = (int)($item['dirIndex'] ?? 0);
                                            $ip = isset($item['path']) ? (string)$item['path'] : '';
                                            if ($in === $name && $idi === $di && ($p === '' || $ip === $p)) {
                                                $item['preview_hash'] = $hash;
                                                $item['preview_mtime'] = $mtime;
                                                $updated = true;
                                                break;
                                            }
                                        }
                                        unset($item);
                                        if ($updated) {
                                            @file_put_contents($acPath, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                        }
                                    }
                                }
                            } catch (Exception $__) {}
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
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'error' => $e->getMessage()
        ]);
    }
}

function getVolume() {
    try {
        $profileId = $_GET['profile'] ?? 'default';
        $statePath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId) . '/state.json';

        $volume = 50; // Default volume
        if (file_exists($statePath)) {
            $stateData = json_decode(file_get_contents($statePath), true);
            if (is_array($stateData) && isset($stateData['volume'])) {
                $volume = (int)$stateData['volume'];
            }
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'volume' => $volume]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function setVolume() {
    try {
        $profileId = $_POST['profile'] ?? 'default';
        $volume = (int)($_POST['volume'] ?? 100);

        if ($volume < 0 || $volume > 100) {
            throw new Exception('Volume must be between 0 and 100');
        }

        // Direct file update for reliability
        $statePath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId) . '/state.json';

        // Load existing state or create new
        $stateData = [];
        if (file_exists($statePath)) {
            $stateData = json_decode(file_get_contents($statePath), true) ?: [];
        }

        // Update volume and timestamp
        $stateData['volume'] = $volume;
        $stateData['lastControlChange'] = time();

        // Save updated state
        file_put_contents($statePath, json_encode($stateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'volume' => $volume]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getMuteState() {
    try {
        $profileId = $_GET['profile'] ?? 'default';
        $statePath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId) . '/state.json';

        $muted = false;
        if (file_exists($statePath)) {
            $stateData = json_decode(file_get_contents($statePath), true);
            if (is_array($stateData) && isset($stateData['muted'])) {
                $muted = (bool)$stateData['muted'];
            }
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'muted' => $muted]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function toggleMute() {
    try {
        $profileId = $_POST['profile'] ?? 'default';
        $stateDir = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $stateFile = $stateDir . '/state.json';
        if (!is_dir($stateDir)) {
            @mkdir($stateDir, 0777, true);
        }

        $state = [];
        if (file_exists($stateFile)) {
            $decoded = json_decode(@file_get_contents($stateFile), true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }

        $currentMuted = isset($state['muted']) ? (bool)$state['muted'] : false;
        $state['muted'] = !$currentMuted;
        $state['lastControlChange'] = time();

        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'muted' => $state['muted']]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// Video control functions for direct playback (no HLS)
function playVideo() {
    try {
        $profileId = $_POST['profile'] ?? 'default';

        // Simple fallback implementation without state manager
        $statePath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $stateFile = $statePath . '/state.json';

        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true);
            $state['playbackState'] = 'play';
            $state['lastControlChange'] = time();
            file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'state' => 'play']);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function pauseVideo() {
    try {
        $profileId = $_POST['profile'] ?? 'default';

        // Simple fallback implementation without state manager
        $statePath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $stateFile = $statePath . '/state.json';

        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true);
            $state['playbackState'] = 'pause';
            $state['lastControlChange'] = time();
            file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'state' => 'pause']);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function stopVideo() {
    try {
        $profileId = $_POST['profile'] ?? 'default';

        // Simple fallback implementation without state manager
        $statePath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $stateFile = $statePath . '/state.json';

        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true);
            $state['playbackState'] = 'stop';
            $state['lastControlChange'] = time();
            file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'state' => 'stop']);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function setCurrentVideo() {
    try {
        $profileId = $_POST['profile'] ?? 'default';
        $filename = $_POST['filename'] ?? '';
        $dirIndex = (int)($_POST['dirIndex'] ?? 0);

        if (empty($filename)) {
            throw new Exception('No filename provided');
        }

        // Simple fallback implementation without state manager
        $statePath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        if (!is_dir($statePath)) {
            mkdir($statePath, 0777, true);
        }

        $stateFile = $statePath . '/state.json';
        $state = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
        $state['currentVideo'] = ['filename' => $filename, 'dirIndex' => $dirIndex];
        $state['lastControlChange'] = time();

        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'currentVideo' => ['filename' => $filename, 'dirIndex' => $dirIndex]]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function clearCurrentVideo() {
    try {
        $profileId = $_POST['profile'] ?? 'default';

        // Simple fallback implementation without state manager
        $statePath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $stateFile = $statePath . '/state.json';

        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true);
            $state['currentVideo'] = ['filename' => '', 'dirIndex' => 0];
            $state['playbackState'] = 'stop';
            $state['lastControlChange'] = time();
            file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'currentVideo' => null]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getCurrentVideo() {
    try {
        $profileId = $_GET['profile'] ?? 'default';
        $statePath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId) . '/state.json';

        $currentVideo = ['filename' => '', 'dirIndex' => 0];
        if (file_exists($statePath)) {
            $stateData = json_decode(file_get_contents($statePath), true);
            if (is_array($stateData) && isset($stateData['currentVideo'])) {
                $currentVideo = $stateData['currentVideo'];
            }
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'currentVideo' => $currentVideo]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getPlaybackState() {
    try {
        $profileId = $_GET['profile'] ?? 'default';
        $statePath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId) . '/state.json';

        $state = 'stop';
        if (file_exists($statePath)) {
            $stateData = json_decode(file_get_contents($statePath), true);
            if (is_array($stateData) && isset($stateData['playbackState'])) {
                $state = $stateData['playbackState'];
            }
        }

        // Ensure we always return valid JSON
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'state' => $state]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function listVideoCodecs() {
    try {
        $adminCachePath = __DIR__ . '/data/admin_cache.json';
        $dirs = getConfiguredDirectories();
        $videos = [];
        if (is_file($adminCachePath)) {
            $cache = json_decode(@file_get_contents($adminCachePath), true) ?: [];
            if (!empty($cache['all_video_files']) && is_array($cache['all_video_files'])) {
                foreach ($cache['all_video_files'] as $e) {
                    $name = isset($e['name']) ? (string)$e['name'] : '';
                    $di = (int)($e['dirIndex'] ?? 0);
                    if ($name !== '' && isset($dirs[$di])) {
                        $sep = (strpos($dirs[$di], ':\\') !== false) ? '\\' : '/';
                        $videos[] = rtrim($dirs[$di], '\\/') . $sep . $name;
                    }
                }
            }
        }
        if (empty($videos)) {
            // Fallback: scan first directory only to limit overhead
            $root = $dirs[0] ?? (__DIR__ . '/videos');
            if (is_dir($root)) {
                $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
                foreach ($iter as $file) {
                    $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                    if (in_array($ext, ['mp4','mov','mkv','webm','avi','m4v'])) {
                        $videos[] = $file->getPathname();
                    }
                }
            }
        }

        $results = [];
        foreach ($videos as $path) {
            $cmd = (stripos(PHP_OS, 'WIN') === 0 ? 'ffprobe.exe' : 'ffprobe');
            $escaped = escapeshellarg($path);
            $probe = @shell_exec($cmd . ' -v error -select_streams v:0 -show_entries stream=codec_name,width,height -of csv=p=0:s=, -- ' . $escaped);
            $codec = null; $w = null; $h = null;
            if (is_string($probe) && trim($probe) !== '') {
                $parts = array_map('trim', explode(',', trim($probe)));
                if (count($parts) >= 3) {
                    $codec = $parts[0]; $w = (int)$parts[1]; $h = (int)$parts[2];
                }
            }
            $results[] = [ 'file' => $path, 'codec' => $codec, 'width' => $w, 'height' => $h ];
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'count' => count($results), 'videos' => $results]);
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function encodeVp9Single() {
    try {
        $file = $_GET['file'] ?? '';
        $dirIndex = isset($_GET['dirIndex']) ? (int)$_GET['dirIndex'] : 0;
        if ($file === '') { throw new Exception('Missing file'); }

        $dirs = getConfiguredDirectories();
        if (!isset($dirs[$dirIndex])) { throw new Exception('Invalid dirIndex'); }
        $sep = (strpos($dirs[$dirIndex], ':\\') !== false) ? '\\' : '/';
        $inPath = rtrim($dirs[$dirIndex], '\\/') . $sep . $file;
        if (!is_file($inPath)) { throw new Exception('File not found: ' . $inPath); }

        $outDir = __DIR__ . '/data/encoded/vp9';
        if (!is_dir($outDir)) { @mkdir($outDir, 0777, true); }
        $base = pathinfo($file, PATHINFO_FILENAME);
        $outPath = $outDir . '/' . $base . '_vp9_1080p.webm';

        $cmd = (stripos(PHP_OS, 'WIN') === 0 ? 'ffmpeg.exe' : 'ffmpeg');
        $args = ' -y -i ' . escapeshellarg($inPath)
              . ' -vf "scale=1920:1080:force_original_aspect_ratio=decrease"'
              . ' -c:v libvpx-vp9 -b:v 0 -crf 32 -row-mt 1 -threads 4'
              . ' -c:a libopus -b:a 128k '
              . escapeshellarg($outPath)
              . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');

        // Record status and start background process
        $statusFile = __DIR__ . '/data/encoded/vp9_status.json';
        @file_put_contents($statusFile, json_encode(['state' => 'running', 'started' => time(), 'input' => $inPath, 'output' => $outPath]));

        if (stripos(PHP_OS, 'WIN') === 0) {
            pclose(popen('start /B ' . $cmd . $args, 'r'));
        } else {
            exec($cmd . $args . ' > /dev/null 2>&1 &');
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode(['success' => true, 'output' => $outPath]);
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function encodeVp9Status() {
    try {
        $statusFile = __DIR__ . '/data/encoded/vp9_status.json';
        $state = 'idle';
        $payload = ['state' => $state];
        if (is_file($statusFile)) {
            $data = json_decode(@file_get_contents($statusFile), true) ?: [];
            $payload = array_merge(['state' => 'idle'], $data);
            // Consider job done if output file exists
            if (!empty($data['output']) && is_file($data['output'])) {
                $payload['state'] = 'done';
            }
        }
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode(['success' => true, 'state' => $payload['state'], 'progress' => $payload['progress'] ?? null, 'output' => $payload['output'] ?? null]);
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function logEvent() {
    try {
        $profileId = $_POST['profile'] ?? ($_GET['profile'] ?? 'default');
        $profileSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $dir = __DIR__ . '/data/profiles/' . $profileSafe;
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $logFile = $dir . '/dashboard.log';

        // Collect payload
        $event = $_POST['event'] ?? ($_GET['event'] ?? 'unknown');
        $detailsRaw = $_POST['details'] ?? ($_GET['details'] ?? '');
        $details = is_array($detailsRaw) ? $detailsRaw : @json_decode($detailsRaw, true);
        if (!is_array($details)) { $details = ['message' => (string)$detailsRaw]; }

        $row = [
            'ts' => time(),
            'iso' => date('c'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'event' => $event,
            'details' => $details,
        ];

        // Append JSON line
        @file_put_contents($logFile, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getLoopMode() {
    try {
        $profileId = $_GET['profile'] ?? 'default';
        $statePath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId) . '/state.json';

        $loop = 'off';
        if (file_exists($statePath)) {
            $stateData = json_decode(file_get_contents($statePath), true);
            if (is_array($stateData) && isset($stateData['loopMode'])) {
                $loop = $stateData['loopMode'];
            }
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'loop' => $loop]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function setLoopMode() {
    try {
        $profileId = $_POST['profile'] ?? 'default';
        $loop = $_POST['loop'] ?? 'off';
        
        if (!in_array($loop, ['on', 'off'])) {
            throw new Exception('Invalid loop mode');
        }
        
        $profileDir = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        if (!is_dir($profileDir)) {
            mkdir($profileDir, 0777, true);
        }
        
        $statePath = $profileDir . '/state.json';
        
        // Load existing state or create new
        $stateData = [];
        if (file_exists($statePath)) {
            $stateData = json_decode(file_get_contents($statePath), true) ?: [];
        }
        
        // Set loop mode
        $stateData['loopMode'] = $loop;
        $stateData['lastControlChange'] = time();
        
        // Save updated state
        file_put_contents($statePath, json_encode($stateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        echo json_encode(['success' => true, 'loop' => $loop]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getPlayAllMode() {
    try {
        $profileId = $_GET['profile'] ?? 'default';
        $statePath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId) . '/state.json';

        $playAll = 'off';
        if (file_exists($statePath)) {
            $stateData = json_decode(file_get_contents($statePath), true);
            if (is_array($stateData) && isset($stateData['playAllMode'])) {
                $playAll = $stateData['playAllMode'];
            }
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'play_all' => $playAll]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function setPlayAllMode() {
    try {
        $profileId = $_POST['profile'] ?? 'default';
        $playAll = $_POST['play_all'] ?? 'off';
        
        if (!in_array($playAll, ['on', 'off'])) {
            throw new Exception('Invalid play all mode');
        }
        
        $profileDir = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        if (!is_dir($profileDir)) {
            mkdir($profileDir, 0777, true);
        }
        
        $statePath = $profileDir . '/state.json';
        
        // Load existing state or create new
        $stateData = [];
        if (file_exists($statePath)) {
            $stateData = json_decode(file_get_contents($statePath), true) ?: [];
        }
        
        // Set play all mode
        $stateData['playAllMode'] = $playAll;
        $stateData['lastControlChange'] = time();
        
        // Save updated state
        file_put_contents($statePath, json_encode($stateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        echo json_encode(['success' => true, 'play_all' => $playAll]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getExternalAudioMode() {
    try {
        $profileId = $_GET['profile'] ?? 'default';
        $statePath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId) . '/state.json';

        $external = 'off';
        if (file_exists($statePath)) {
            $stateData = json_decode(file_get_contents($statePath), true);
            if (is_array($stateData) && isset($stateData['externalAudioMode'])) {
                $external = $stateData['externalAudioMode'];
            }
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => true, 'external' => $external]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function setExternalAudioModeEndpoint() {
    try {
        $profileId = $_POST['profile'] ?? 'default';
        $external = $_POST['external'] ?? 'off';

        if (!in_array($external, ['on', 'off'])) {
            throw new Exception('Invalid external audio mode');
        }

        // Use state_manager.php functions for consistency
        if (function_exists('setExternalAudioMode')) {
            $result = \setExternalAudioMode($profileId, $external);
            if ($result) {
                echo json_encode(['success' => true, 'external' => $external]);
            } else {
                throw new Exception('Failed to update external audio mode');
            }
        } else {
            throw new Exception('State manager functions not available');
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getVideoTitles() {
    try {
        $profileId = isset($_GET['profile']) ? (string)$_GET['profile'] : 'default';
        $profileSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $profileTitlesPath = __DIR__ . '/data/profiles/' . $profileSafe . '/titles.json';
        $globalTitlesPath = __DIR__ . '/data/video_titles.json';

        $titles = [];
        if (is_file($profileTitlesPath)) {
            $titles = json_decode(@file_get_contents($profileTitlesPath), true) ?: [];
        } elseif (is_file($globalTitlesPath)) {
            // Backward compatibility with handler/global storage
            $titles = json_decode(@file_get_contents($globalTitlesPath), true) ?: [];
        }

        // Ensure empty array serializes as object for JS compatibility (like handler)
        if (empty($titles)) {
            $titles = new stdClass();
        }

        echo json_encode(['success' => true, 'titles' => $titles]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function setVideoTitleEndpoint() {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        $filename = isset($_POST['filename']) ? (string)$_POST['filename'] : '';
        $title = isset($_POST['title']) ? (string)$_POST['title'] : '';
        $dirIndex = isset($_POST['dirIndex']) ? (int)$_POST['dirIndex'] : 0;
        $profileId = isset($_POST['profile']) ? (string)$_POST['profile'] : 'default';
        if ($filename === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing filename']);
            return;
        }
        // Normalize title: strip control chars and limit length
        $title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title);
        if (function_exists('mb_strlen') && mb_strlen($title) > 200) {
            $title = mb_substr($title, 0, 200);
        } else if (strlen($title) > 200) {
            $title = substr($title, 0, 200);
        }

        // Store titles globally like handler for compatibility
        $titlesPath = __DIR__ . '/data/video_titles.json';
        $titles = [];
        if (is_file($titlesPath)) {
            $decoded = json_decode(@file_get_contents($titlesPath), true);
            if (is_array($decoded)) { $titles = $decoded; }
        }
        $key = (string)$dirIndex . '|' . $filename;
        $titles[$key] = $title;
        @file_put_contents($titlesPath, json_encode($titles, JSON_PRETTY_PRINT));

        // Also save per-profile override for future
        $profileSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $profileDir = __DIR__ . '/data/profiles/' . $profileSafe;
        if (!is_dir($profileDir)) { @mkdir($profileDir, 0777, true); }
        $profileTitlesPath = $profileDir . '/titles.json';
        $pTitles = [];
        if (is_file($profileTitlesPath)) {
            $pDecoded = json_decode(@file_get_contents($profileTitlesPath), true);
            if (is_array($pDecoded)) { $pTitles = $pDecoded; }
        }
        $pTitles[$key] = $title;
        @file_put_contents($profileTitlesPath, json_encode($pTitles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        echo json_encode(['status' => 'ok', 'title' => $title]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getAllVideos() {
    try {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, (int)($_GET['limit'] ?? 20));
        $offset = ($page - 1) * $limit;
        $profileId = isset($_GET['profile']) ? (string)$_GET['profile'] : 'default';

        // Resolve configured directories (absolute if possible)
        $dirs = getConfiguredDirectories();
        $allowedExt = ['mp4', 'webm', 'ogg', 'mov'];

        // Prefer admin cache when available and fresh
        $allWithMeta = [];
        $adminCachePath = __DIR__ . '/data/admin_cache.json';
        $useCache = false;
        if (is_file($adminCachePath)) {
            $cache = json_decode(@file_get_contents($adminCachePath), true) ?: [];
            if (!empty($cache['all_video_files']) && is_array($cache['all_video_files'])) {
                $lastScan = (int)($cache['last_scan'] ?? 0);
                $maxDirMtime = 0;
                foreach ($dirs as $dir) { if (is_dir($dir)) { $maxDirMtime = max($maxDirMtime, @filemtime($dir) ?: 0); } }
                if ($lastScan >= $maxDirMtime) {
                    $useCache = true;
                    foreach ($cache['all_video_files'] as $v) {
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

        // Fallback: scan directories now
        if (!$useCache) {
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
                            $allWithMeta[] = [
                                'name' => $file,
                                'dirIndex' => $i,
                                'dirPath' => $dir,
                                'key' => $dir . '|' . $file,
                            ];
                        }
                    }
                }
            }
        }

        // Load saved order for profile
        $profileSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $orderPath = __DIR__ . '/data/profiles/' . $profileSafe . '/video_order.json';
        $savedOrder = [];
        if (is_file($orderPath)) {
            $decoded = json_decode(@file_get_contents($orderPath), true);
            if (is_array($decoded) && !empty($decoded['order']) && is_array($decoded['order'])) {
                $savedOrder = $decoded['order'];
            }
        }

        // Compose ordered keys
        $existingKeys = array_column($allWithMeta, 'key');
        $existingSet = array_flip($existingKeys);
        $orderedKeys = [];
        foreach ($savedOrder as $k) { if (isset($existingSet[$k])) { $orderedKeys[] = $k; } }
        $missing = array_values(array_filter($allWithMeta, function ($v) use ($orderedKeys) { return !in_array($v['key'], $orderedKeys, true); }));
        usort($missing, function ($a, $b) { $c = strcmp($a['name'], $b['name']); return $c !== 0 ? $c : ($a['dirIndex'] <=> $b['dirIndex']); });
        foreach ($missing as $m) { $orderedKeys[] = $m['key']; }

        // Map back to items
        $keyToItem = [];
        foreach ($allWithMeta as $v) { $keyToItem[$v['key']] = ['name' => $v['name'], 'dirIndex' => $v['dirIndex']]; }
        $orderedItems = [];
        foreach ($orderedKeys as $k) { if (isset($keyToItem[$k])) { $orderedItems[] = $keyToItem[$k]; } }

        $totalCount = count($orderedItems);
        $videos = array_slice($orderedItems, $offset, $limit);
        $totalPages = (int)ceil($totalCount / max(1, $limit));

        echo json_encode([
            'success' => true,
            'videos' => $videos,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalCount,
                'totalPages' => $totalPages,
                'hasNext' => $page < $totalPages,
                'hasPrev' => $page > 1
            ]
        ]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function checkConfigChanges() {
    try {
        // Simple check - always return false for now
        // In a real implementation, you'd check if config.json has changed
        echo json_encode(['success' => true, 'needsRefresh' => false]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function checkRefreshSignal() {
    try {
        $profileId = isset($_GET['profile']) ? (string)$_GET['profile'] : 'default';
        $profileSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $base = __DIR__;
        $profileDir = $base . '/data/profiles/' . $profileSafe;

        // Check multiple legacy and current refresh signal paths
        $candidates = [
            $profileDir . '/refresh.txt',                    // current simple path (this file used previously in api.php)
            $profileDir . '/dashboard_refresh.txt',          // legacy/admin handler path
            $base . '/data/dashboard_refresh.txt',           // older global path
            $base . '/dashboard_refresh.txt',                // very old global path in root
        ];

        $shouldRefresh = false;
        foreach ($candidates as $p) {
            if (is_file($p)) {
                $shouldRefresh = true;
                @unlink($p);
            }
        }

        echo json_encode(['success' => true, 'should_refresh' => $shouldRefresh]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function triggerDashboardRefreshEndpoint() {
    try {
        $profileId = isset($_POST['profile']) ? (string)$_POST['profile'] : (isset($_GET['profile']) ? (string)$_GET['profile'] : 'default');
        $profileSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $dir = __DIR__ . '/data/profiles/' . $profileSafe;
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $path = $dir . '/dashboard_refresh.txt';
        @file_put_contents($path, time());
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function moveVideoEndpoint() {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $filename = isset($_POST['filename']) ? trim((string)$_POST['filename']) : '';
        $dirIndex = isset($_POST['dirIndex']) ? (int)$_POST['dirIndex'] : 0;
        $direction = isset($_POST['direction']) ? (string)$_POST['direction'] : '';
        $profileId = isset($_POST['profile']) ? (string)$_POST['profile'] : 'default';

        if ($filename === '' || !in_array($direction, ['up','down'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid parameters']);
            return;
        }

        // Load configured directories
        $dirs = getConfiguredDirectories();
        if ($dirIndex < 0 || $dirIndex >= count($dirs)) { $dirIndex = 0; }

        // Build list of all videos (name, dirIndex, dirPath, key)
        $allowedExt = ['mp4','webm','ogg','mov'];
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

        // Load existing order for this profile
        $profileSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $orderDir = __DIR__ . '/data/profiles/' . $profileSafe;
        if (!is_dir($orderDir)) { @mkdir($orderDir, 0777, true); }
        $orderPath = $orderDir . '/video_order.json';
        $savedOrder = [];
        if (is_file($orderPath)) {
            $decoded = json_decode(@file_get_contents($orderPath), true);
            if (is_array($decoded) && !empty($decoded['order']) && is_array($decoded['order'])) {
                $savedOrder = $decoded['order'];
            }
        }

        // Normalize ordered keys
        $existingKeys = array_column($all, 'key');
        $existingSet = array_flip($existingKeys);
        $orderedKeys = [];
        foreach ($savedOrder as $k) { if (isset($existingSet[$k])) { $orderedKeys[] = $k; } }
        $missing = array_values(array_filter($all, function($v) use ($orderedKeys) { return !in_array($v['key'], $orderedKeys, true); }));
        usort($missing, function($a,$b){ $c = strcmp($a['name'],$b['name']); return $c !== 0 ? $c : ($a['dirIndex'] <=> $b['dirIndex']); });
        foreach ($missing as $m) { $orderedKeys[] = $m['key']; }

        $targetKey = (isset($dirs[$dirIndex]) ? $dirs[$dirIndex] : ($dirs[0] ?? '')) . '|' . $filename;
        $idx = array_search($targetKey, $orderedKeys, true);
        if ($idx === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Video not found']);
            return;
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

        @file_put_contents($orderPath, json_encode(['order' => array_values($orderedKeys)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo json_encode(['status' => 'ok']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}
function browseDirectories() {
    try {
        // Accept both POST and GET for compatibility
        $path = trim($_POST['path'] ?? ($_GET['path'] ?? ''));
        $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        if ($isWindows) {
            $path = str_replace('/', '\\', $path);
        }

        $isAllowed = false;
        if ($path === '') {
            $isAllowed = true;
        } else {
            if ($isWindows) {
                if (preg_match('/^[A-Za-z]:\\\\/', $path)) { $isAllowed = true; }
                if (preg_match('/^\\\\\\\\[^\\\\]+\\\\[^\\\\]+/', $path)) { $isAllowed = true; }
                if (preg_match('/^[A-Za-z]:/', $path)) { $isAllowed = true; }
            } else {
                if (strpos($path, '/') === 0) {
                    $deniedPaths = ['/etc/', '/proc/', '/sys/', '/dev/', '/boot/', '/root/'];
                    $isDenied = false;
                    foreach ($deniedPaths as $denied) {
                        if (strpos($path, $denied) === 0) { $isDenied = true; break; }
                    }
                    if (!$isDenied) { $isAllowed = true; }
                }
            }
        }

        if (!$isAllowed) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied to this path', 'path' => $path]);
            return;
        }

        if ($path === '') {
            if ($isWindows) {
                $directories = [];
                foreach (range('A', 'Z') as $drive) {
                    $drivePath = $drive . ':\\\\';
                    if (is_dir($drivePath)) { $directories[] = $drivePath; }
                }
                echo json_encode(['status' => 'ok', 'directories' => $directories, 'currentPath' => '']);
            } else {
                $directories = [];
                $rootDirs = ['/home', '/mnt', '/media', '/var', '/usr'];
                foreach ($rootDirs as $dir) { if (is_dir($dir)) { $directories[] = basename($dir); } }
                echo json_encode(['status' => 'ok', 'directories' => $directories, 'currentPath' => '/']);
            }
            return;
        }

        if (!is_dir($path)) {
            echo json_encode(['error' => 'Directory not found or not accessible', 'path' => $path]);
            return;
        }

        $directories = [];
        $videoFiles = [];
        $items = @scandir($path);
        if ($items === false) {
            echo json_encode(['error' => 'Directory not accessible', 'path' => $path]);
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $itemPath = $path . ($isWindows ? '\\' : '/') . $item;
            if (is_dir($itemPath)) {
                $directories[] = $item;
            } else {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, ['mp4','webm','ogg','mov','avi','mkv','wmv','flv'])) {
                    $videoFiles[] = $item;
                }
            }
        }
        sort($directories);
        sort($videoFiles);
        echo json_encode(['status' => 'ok', 'directories' => $directories, 'videoFiles' => $videoFiles, 'currentPath' => $path]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to browse directory: ' . $e->getMessage()]);
    }
}
?>
