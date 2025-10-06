﻿﻿<?php
// dashboard.php
// User interface to browse and select videos for playback.

// Include shared state management
require_once __DIR__ . '/state_manager.php';

// Security headers (must be sent before any output)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

// Load configuration
$configPath = __DIR__ . '/config.json';
$dashboardsPath = __DIR__ . '/data/dashboards.json';
if (file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true);
} else {
    $config = [
        'directory' => 'videos',
        'rows' => 2,
        'clipsPerRow' => 4
    ];
}

// Optional: load dashboard profile overrides
// Support short param: d=0 â†’ default, d=1 â†’ dashboard1; otherwise ?dashboard=dashboardId; default â†’ 'default'
$selectedDashboardId = 'default';
$d = isset($_GET['d']) ? $_GET['d'] : null;
if (isset($_GET['d'])) {
    $n = (int)$_GET['d'];
    if ($n === 0) { $selectedDashboardId = 'default'; }
    elseif ($n >= 1) { $selectedDashboardId = 'dashboard' . $n; }
} elseif (isset($_GET['dashboard'])) {
    $selectedDashboardId = (string)$_GET['dashboard'];
}
$dashboards = [];
$profile = null;
if (file_exists($dashboardsPath)) {
    $decodedDash = json_decode(@file_get_contents($dashboardsPath), true);
    if (is_array($decodedDash)) { $dashboards = $decodedDash; }
}
if (isset($dashboards[$selectedDashboardId]) && is_array($dashboards[$selectedDashboardId])) {
    $profile = $dashboards[$selectedDashboardId];
    if (isset($profile['rows'])) { $config['rows'] = (int)$profile['rows']; }
    if (isset($profile['clipsPerRow'])) { $config['clipsPerRow'] = (int)$profile['clipsPerRow']; }
    if (isset($profile['dashboardBackground'])) { $config['dashboardBackground'] = (string)$profile['dashboardBackground']; }
}

// Determine clips directories and gather video files
$clipsDirs = [];
if (!empty($config['directories']) && is_array($config['directories'])) {
    $clipsDirs = $config['directories'];
} else {
    $clipsDirs = [ $config['directory'] ];
}

// Normalize directory absolute paths similar to API
$dirAbsPaths = [];
foreach ($clipsDirs as $dirIndex => $clipsDir) {
    $resolved = is_dir($clipsDir) ? $clipsDir : realpath(__DIR__ . '/' . $clipsDir);
    if ($resolved && is_dir($resolved)) {
        $dirAbsPaths[$dirIndex] = $resolved;
    } else {
        $dirAbsPaths[$dirIndex] = $clipsDir; // fallback
    }
}

$videoFiles = [];
$allowedExt = ['mp4', 'webm', 'ogg', 'mov', 'mkv', 'avi', 'wmv', 'flv'];

// Prefer using admin cache to avoid rescanning directories on every dashboard load
$adminCachePath = __DIR__ . '/data/admin_cache.json';
$usedCacheForVideos = false;
if (file_exists($adminCachePath)) {
    $adminCache = json_decode(@file_get_contents($adminCachePath), true) ?: [];
    if (!empty($adminCache['all_video_files']) && is_array($adminCache['all_video_files'])) {
        $lastScan = (int)($adminCache['last_scan'] ?? 0);
        // Determine if cache is still fresh by comparing directory mtimes
        $maxDirModTime = 0;
        foreach ($dirAbsPaths as $absDir) {
            if ($absDir && is_dir($absDir)) {
                $mt = @filemtime($absDir) ?: 0;
                if ($mt > $maxDirModTime) { $maxDirModTime = $mt; }
            }
        }
        if ($lastScan >= $maxDirModTime) {
            // Rebuild videoFiles array from cache, remapping dirIndex by absolute path when possible
            foreach ($adminCache['all_video_files'] as $entry) {
                $name = isset($entry['name']) ? (string)$entry['name'] : '';
                if ($name === '') { continue; }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) { continue; }
                $entryPath = isset($entry['path']) ? (string)$entry['path'] : '';
                $mappedIndex = null;
                if ($entryPath !== '') {
                    $normEntryPath = str_replace(['/', '\\'], [DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], $entryPath);
                    foreach ($dirAbsPaths as $idx => $absDir) {
                        $prefix = rtrim((string)$absDir, '/\\') . DIRECTORY_SEPARATOR;
                        if (strpos($normEntryPath, $prefix) === 0) { $mappedIndex = (int)$idx; break; }
                    }
                }
                if ($mappedIndex === null) {
                    $di = (int)($entry['dirIndex'] ?? 0);
                    if (isset($dirAbsPaths[$di])) { $mappedIndex = $di; }
                }
                if ($mappedIndex === null) { continue; }
                $videoFiles[] = ['name' => $name, 'dirIndex' => $mappedIndex];
            }
            $usedCacheForVideos = true;
            error_log("Dashboard: Using cache with " . count($videoFiles) . " videos");
        } else {
            error_log("Dashboard: Cache is stale, will scan directories");
        }
    } else {
        error_log("Dashboard: Admin cache not found or invalid");
    }
}

// Fallback: scan directories if cache not used or unavailable
if (!$usedCacheForVideos) {
    foreach ($clipsDirs as $dirIndex => $clipsDir) {
        $resolved = isset($dirAbsPaths[$dirIndex]) ? $dirAbsPaths[$dirIndex] : (is_dir($clipsDir) ? $clipsDir : realpath(__DIR__ . '/' . $clipsDir));
        if ($resolved && is_dir($resolved)) {
            error_log("Dashboard: Scanning directory $clipsDir ($resolved)");
            $items = @scandir($resolved) ?: [];
            $foundVideos = 0;
            foreach ($items as $file) {
                if ($file === '.' || $file === '..') { continue; }
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExt, true)) {
                    $videoFiles[] = ['name' => $file, 'dirIndex' => $dirIndex];
                    $foundVideos++;
                }
            }
            error_log("Dashboard: Found $foundVideos videos in $clipsDir");
        } else {
            error_log("Dashboard: Directory $clipsDir not found or not accessible");
        }
    }
}

// ============================================================================
// FUNCTION DEFINITIONS - All functions must be defined before they're called
// ============================================================================

// Helper function to load cached video order efficiently
function loadCachedVideoOrder($profileId) {
    $orderPath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId) . '/video_order.json';
    if (file_exists($orderPath)) {
        $orderJson = json_decode(@file_get_contents($orderPath), true);
        if (is_array($orderJson) && isset($orderJson['order']) && is_array($orderJson['order'])) {
            return $orderJson['order'];
        }
    }
    return [];
}

// Helper function to check if cache is stale
function isCacheStale($profileId, $dirAbsPaths) {
    // Ensure getLastScan function is available from state_manager.php
    if (!function_exists('getLastScan')) {
        error_log('getLastScan function not available - state_manager.php may not be properly included');
        return true; // Force rescan if function not available
    }
    
    try {
        $lastScanTime = getLastScan($profileId);
        
        if ($lastScanTime === 0) {
            return true; // No cache exists
        }
        
        $maxDirModTime = 0;
        
        foreach ($dirAbsPaths as $dir) {
            if (is_dir($dir)) {
                $modTime = @filemtime($dir);
                if ($modTime !== false) {
                    $maxDirModTime = max($maxDirModTime, $modTime);
                }
            }
        }
        
        return $maxDirModTime > $lastScanTime;
    } catch (Exception $e) {
        error_log('Error in isCacheStale: ' . $e->getMessage());
        return true; // Force rescan on any error
    }
}

// Auto-update video order list when directory changes
function autoUpdateVideoOrder($baseDir, $profileId, $dirAbsPaths, $videoFiles) {
    $orderPath = $baseDir . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId) . '/video_order.json';
    
    // Check if order file exists and is up to date
    $needsUpdate = false;
    if (file_exists($orderPath)) {
        $orderJson = json_decode(@file_get_contents($orderPath), true);
        if (is_array($orderJson) && isset($orderJson['order']) && is_array($orderJson['order'])) {
            $existingOrder = $orderJson['order'];
            
            // Check if any videos in the current directory are missing from the order
            foreach ($videoFiles as $video) {
                $videoKey = (isset($dirAbsPaths[$video['dirIndex']]) ? $dirAbsPaths[$video['dirIndex']] : '') . '|' . $video['name'];
                if (!in_array($videoKey, $existingOrder)) {
                    $needsUpdate = true;
                    break;
                }
            }
            
            // Check if any videos in the order no longer exist
            foreach ($existingOrder as $orderKey) {
                if (strpos($orderKey, '|') !== false) {
                    [$dirPath, $filename] = explode('|', $orderKey, 2);
                    $found = false;
                    foreach ($videoFiles as $video) {
                        if ($video['name'] === $filename && 
                            (isset($dirAbsPaths[$video['dirIndex']]) ? $dirAbsPaths[$video['dirIndex']] : '') === $dirPath) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $needsUpdate = true;
                        break;
                    }
                }
            }
        } else {
            $needsUpdate = true;
        }
    } else {
        $needsUpdate = true;
    }
    
    // If update is needed, regenerate the order list
    if ($needsUpdate) {
        $newOrder = [];
        foreach ($videoFiles as $video) {
            $videoKey = (isset($dirAbsPaths[$video['dirIndex']]) ? $dirAbsPaths[$video['dirIndex']] : '') . '|' . $video['name'];
            $newOrder[] = $videoKey;
        }
        
        // Create profile directory if it doesn't exist
        $profileDir = dirname($orderPath);
        if (!is_dir($profileDir)) {
            @mkdir($profileDir, 0777, true);
        }
        
        // Save the new order
        $orderData = ['order' => $newOrder];
        @file_put_contents($orderPath, json_encode($orderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // Optional: keep silent in production
        
        return $newOrder;
    }
    
    // Return existing order if no update needed
    if (file_exists($orderPath)) {
        $orderJson = json_decode(@file_get_contents($orderPath), true);
        if (is_array($orderJson) && isset($orderJson['order']) && is_array($orderJson['order'])) {
            return $orderJson['order'];
        }
    }
    
    return [];
}

// Auto-update current video if it no longer exists
function autoUpdateCurrentVideo($baseDir, $profileId, $videoFiles, $orderKeys) {
    if (!function_exists('getCurrentVideoForProfile')) {
        return null; // Cannot update if function not available
    }

    $currentVideo = getCurrentVideoForProfile($profileId);
    
    if (!empty($currentVideo['filename'])) {
        $currentFilename = $currentVideo['filename'];
        $currentDirIndex = $currentVideo['dirIndex'];
        
        // Check if current video still exists
        $videoExists = false;
        foreach ($videoFiles as $video) {
            if ($video['name'] === $currentFilename && $video['dirIndex'] === $currentDirIndex) {
                $videoExists = true;
                break;
            }
        }
        
        // If current video doesn't exist, set it to the first available video
        if (!$videoExists && !empty($videoFiles)) {
            $firstVideo = $videoFiles[0];
            $newCurrentVideo = [
                'filename' => $firstVideo['name'],
                'dirIndex' => $firstVideo['dirIndex']
            ];
            if (function_exists('setCurrentVideoForProfile')) {
                setCurrentVideoForProfile($profileId, $firstVideo['name'], $firstVideo['dirIndex']);
            }
            
            // Log the auto-update for debugging
            error_log("Auto-updated current video for profile $profileId: " . $firstVideo['name']);
            
            return $newCurrentVideo;
        }
    }
    
    return null;
}


// Apply saved order if available, with smart caching based on file modification times
$orderPath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $selectedDashboardId) . '/video_order.json';

// Only scan if directories have changed since last scan
// Determine if a rescan is needed without relying on a helper (silences static analysis warning)
$needsScan = true;
if (function_exists('getLastScan')) {
    try {
        $lastScanTime = getLastScan($selectedDashboardId);
        if ($lastScanTime !== 0) {
            $maxDirModTime = 0;
            foreach ($dirAbsPaths as $dir) {
                if (is_dir($dir)) {
                    $modTime = @filemtime($dir);
                    if ($modTime !== false) {
                        $maxDirModTime = max($maxDirModTime, $modTime);
                    }
                }
            }
            $needsScan = $maxDirModTime > $lastScanTime;
        } else {
            $needsScan = true;
        }
    } catch (Exception $e) {
        error_log('Error while checking cache staleness: ' . $e->getMessage());
        $needsScan = true;
    }
} else {
    error_log('getLastScan function not available - state_manager.php may not be properly included');
    $needsScan = true;
}

if ($needsScan) {
    // Directories have changed, need to scan
    $orderKeys = autoUpdateVideoOrder(__DIR__, $selectedDashboardId, $dirAbsPaths, $videoFiles);
    // Update last scan time
    if (function_exists('updateLastScan')) {
        updateLastScan($selectedDashboardId);
    }
    
    // Silent in production
} else {
    // Use cached data - much faster
    $orderKeys = loadCachedVideoOrder($selectedDashboardId);
    
    // Silent in production
}

// Update current video if needed
$currentVideoUpdate = autoUpdateCurrentVideo(__DIR__, $selectedDashboardId, $videoFiles, $orderKeys);

if (!empty($orderKeys)) {
    $pos = array_flip($orderKeys);
    usort($videoFiles, function($a, $b) use ($pos, $dirAbsPaths) {
        $aKey = (isset($dirAbsPaths[$a['dirIndex']]) ? $dirAbsPaths[$a['dirIndex']] : '') . '|' . $a['name'];
        $bKey = (isset($dirAbsPaths[$b['dirIndex']]) ? $dirAbsPaths[$b['dirIndex']] : '') . '|' . $b['name'];
        $ai = isset($pos[$aKey]) ? $pos[$aKey] : PHP_INT_MAX;
        $bi = isset($pos[$bKey]) ? $pos[$bKey] : PHP_INT_MAX;
        if ($ai !== $bi) { return $ai <=> $bi; }
        $c = strcmp($a['name'], $b['name']);
        return $c !== 0 ? $c : ($a['dirIndex'] <=> $b['dirIndex']);
    });
} else {
    usort($videoFiles, function($a, $b){ $c=strcmp($a['name'],$b['name']); return $c!==0?$c:($a['dirIndex']<=>$b['dirIndex']); });
}

error_log("Dashboard: Final video count: " . count($videoFiles));

// Distribute videos across rows
$rows = max(1, (int)$config['rows']);
$clipsPerRow = max(1, (int)$config['clipsPerRow']);
$totalVideos = count($videoFiles);

// Handle case where no videos are found
if ($totalVideos === 0) {
    $rowsData = [];
} elseif ($rows === 1) {
    // Single row gets all videos
    $rowsData = [$videoFiles];
} elseif ($rows > 0 && $totalVideos > 0) {
    // Balanced distribution across multiple rows
    $rowsData = [];
    $videosPerRowBase = floor($totalVideos / $rows);
    $extraVideos = $totalVideos % $rows;
    
    $videoIndex = 0;
    for ($rowIndex = 0; $rowIndex < $rows; $rowIndex++) {
        // Some rows get one extra video to distribute the remainder
        $videosForThisRow = $videosPerRowBase + ($rowIndex < $extraVideos ? 1 : 0);
        $rowVideos = array_slice($videoFiles, $videoIndex, $videosForThisRow);
        $rowsData[] = $rowVideos;
        $videoIndex += $videosForThisRow;
        
        // Stop if we've distributed all videos
        if ($videoIndex >= $totalVideos) break;
    }
} else {
    $rowsData = [$videoFiles];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Relax Media System</title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo filemtime(__DIR__ . '/assets/style.css'); ?>">
</head>
<?php
$dashboardBg = isset($config['dashboardBackground']) ? trim((string)$config['dashboardBackground']) : '';
$bodyStyle = '';
if ($dashboardBg !== '') {
    $bodyStyle = " style=\"background-image: url('" . htmlspecialchars($dashboardBg, ENT_QUOTES, 'UTF-8') . "'); background-size: cover; background-position: center; background-attachment: fixed;\"";
}
?>
<body class="dashboard-bg"<?php echo $bodyStyle; ?>>
    <main class="dashboard-page container" style="padding-bottom: 160px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2></h2>
            <div id="loading-indicator" style="display: none; font-size: 14px; color: #4ecdc4;">
                <span id="loading-text">Loading videos...</span>
                <span id="loading-progress"></span>
            </div>
        </div>
        
        <?php if ($currentVideoUpdate): ?>
        <div id="auto-update-notification" style="background: #4ecdc4; color: white; padding: 10px; border-radius: 5px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <span>Video list automatically updated! Current video set to: <?php echo htmlspecialchars($currentVideoUpdate['filename']); ?></span>
            <button onclick="document.getElementById('auto-update-notification').style.display='none'" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer;"><img class="icon" src="assets/svgs/close.svg" alt="Close" /></button>
        </div>
        <?php endif; ?>
        <?php if (empty($videoFiles)): ?>
            <p>No videos found, please put videos in the video map or choose a different map on the admin page.</p>
            <!-- Debug info -->
            <div style="background: #f0f0f0; padding: 10px; margin: 10px 0; border: 1px solid #ccc; font-family: monospace;">
                <strong>Debug Info:</strong><br>
                Cache used: <?php echo $usedCacheForVideos ? 'Yes' : 'No'; ?><br>
                Cache exists: <?php echo file_exists($adminCachePath) ? 'Yes' : 'No'; ?><br>
                Videos directory: <?php echo is_dir('videos') ? 'Exists' : 'Not found'; ?><br>
                Video count in cache: <?php echo $usedCacheForVideos ? count($videoFiles) : 'N/A'; ?><br>
                Clips directories: <?php echo implode(', ', $clipsDirs); ?>
            </div>
        <?php else: ?>
            <?php 
            // Define a CSS custom property to calculate item width for each row
            $gap = 12; // reduced gap in pixels for better media fit
            // We'll set data attributes on the container for JS to use
            ?>
            <?php foreach ($rowsData as $rowIndex => $videos): ?>
                <div class="carousel-row" data-row-index="<?php echo $rowIndex; ?>">
                    <button class="arrow prev" aria-label="Scroll left"><img class="icon" src="assets/svgs/chevron-left.svg" alt="" aria-hidden="true"></button>
                    <div class="carousel-track" data-row="<?php echo $rowIndex; ?>">
                        <?php foreach ($videos as $video): 
                            // Calculate style for width per item with reduced gap
                            $calcWidth = "calc((100% - " . ($clipsPerRow - 1) . " * {$gap}px) / " . $clipsPerRow . ")";
                        ?>
                            <?php 
                                $thumbSrc = 'thumb.php?file=' . rawurlencode($video['name']) . '&dirIndex=' . (int)$video['dirIndex'] . '&noGen=1';
                                if (!empty($adminCache['all_video_files']) && is_array($adminCache['all_video_files'])) {
                                    foreach ($adminCache['all_video_files'] as $entry) {
                                        $en = isset($entry['name']) ? (string)$entry['name'] : '';
                                        $edi = (int)($entry['dirIndex'] ?? 0);
                                        if ($en === $video['name'] && $edi === (int)$video['dirIndex']) {
                                            if (!empty($entry['thumb_hash'])) {
                                                $th = (string)$entry['thumb_hash'];
                                                $tp = __DIR__ . '/data/thumbs/' . $th . '.jpg';
                                                if (is_file($tp)) {
                                                    $tm = isset($entry['thumb_mtime']) ? (int)$entry['thumb_mtime'] : 0;
                                                    $thumbSrc = 'data/thumbs/' . $th . '.jpg' . ($tm ? ('?v=' . $tm) : '');
                                                }
                                            }
                                            break;
                                        }
                                    }
                                }
                            ?>
                            <div class="carousel-item" data-filename="<?php echo htmlspecialchars($video['name'], ENT_QUOTES, 'UTF-8'); ?>" data-dir-index="<?php echo (int)$video['dirIndex']; ?>" style="flex: 0 0 <?php echo $calcWidth; ?>;">
                                <img data-src="<?php echo htmlspecialchars($thumbSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="thumbnail" class="lazy-thumb" loading="lazy" />
                                <div class="video-placeholder">
                                    <div class="play-icon"><img class="overlay-play-icon" src="assets/svgs/play.svg" alt=""></div>
                                </div>
                                <div class="title" data-filename="<?php echo htmlspecialchars($video['name'], ENT_QUOTES, 'UTF-8'); ?>" data-dir-index="<?php echo (int)$video['dirIndex']; ?>"><?php echo htmlspecialchars(pathinfo($video['name'], PATHINFO_FILENAME), ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="arrow next" aria-label="Scroll right"><img class="icon" src="assets/svgs/chevron-right.svg" alt="" aria-hidden="true"></button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Video Controls (persistent) -->
        <div class="video-controls" id="video-controls">
            <h3></h3>
            <div class="control-row">
                <div class="control-buttons">
                    <button type="button" id="play-btn" class="btn primary"><img class="icon" src="assets/svgs/play.svg" alt="" aria-hidden="true"><span class="label">Play</span></button>
                    <button type="button" id="pause-btn" class="btn secondary"><img class="icon" src="assets/svgs/pause.svg" alt="" aria-hidden="true"><span class="label">Pause</span></button>
                    <button type="button" id="stop-btn" class="btn secondary"><img class="icon" src="assets/svgs/stop.svg" alt="" aria-hidden="true"><span class="label">Stop</span></button>
                </div>
                <div class="playback-controls">
                    <button type="button" id="loop-btn" class="btn secondary"><img class="icon" src="assets/svgs/loop.svg" alt="" aria-hidden="true"><span class="label">Loop</span></button>
                    <button type="button" id="all-btn" class="btn secondary"><img class="icon" src="assets/svgs/play-all.svg" alt="" aria-hidden="true"><span class="label">Play All</span></button>
                    <button type="button" id="external-audio-btn" class="btn secondary" title="Use phone or external device for audio" style="display: none;"><img class="icon" src="assets/svgs/external-audio.svg" alt="" aria-hidden="true"><span class="label">External Audio</span></button>
                </div>
            </div>
            <div class="volume-control">
                <div class="volume-header">
                    <label for="volume-slider">Volume: <span id="volume-display">100%</span></label>
                    <button type="button" id="mute-btn" class="btn secondary"><img class="icon" src="assets/svgs/volume-on.svg" alt="" aria-hidden="true"><span class="label">Mute</span></button>
                </div>
                <input type="range" id="volume-slider" min="0" max="100" value="100" step="5">
            </div>
            <div id="external-audio-banner" class="external-audio" style="display:none;">
                <span>External audio mode: Pair your phone to the room speaker via Bluetooth and play your music.</span>
                <button type="button" id="external-help-btn" class="btn secondary">Help</button>
            </div>
            <div class="current-video-info">
                <span id="current-video-name">No video selected</span>
            </div>
        </div>
    </main>
    <script>
    // Dashboard page script
    document.addEventListener('DOMContentLoaded', () => {
        // Suppress non-critical console output
        (function(){
            try { ['log','debug','info','table'].forEach(k => { if (typeof console[k] === 'function') console[k] = function(){}; }); } catch(_) {}
        })();
        // --- Selected tile preview loop helpers ---
        // Preview functionality disabled - only show thumbnails to prevent 404 errors
        let currentPreviewEl = null;
        let previewRequestId = 0; // Incrementing token to invalidate stale preview loads
        // Simple client logging helper (robust GET-based, works in kiosk/webviews)
        function logDash(event, details = {}) {
            try {
                const url = 'api.php?action=log_event&' + profileQuery
                    + '&event=' + encodeURIComponent(String(event))
                    + '&details=' + encodeURIComponent(JSON.stringify(details))
                    + '&t=' + Date.now();
                // Try fetch keepalive GET first
                if (window.fetch) {
                    fetch(url, { method: 'GET', keepalive: true }).catch(() => {
                        const img = new Image();
                        img.src = url;
                    });
                } else {
                    const img = new Image();
                    img.src = url;
                }
            } catch(_) {}
        }
        
        function stopPreview() {
            if (currentPreviewEl) {
                const v = currentPreviewEl.querySelector('video.preview');
                if (v) v.remove();
                const img = currentPreviewEl.querySelector('.lazy-thumb');
                if (img) img.style.display = 'block';
                currentPreviewEl.classList.remove('selected');
            }
            currentPreviewEl = null;
        }

        function stopAllPreviews() {
            document.querySelectorAll('.carousel-item video.preview').forEach(v => v.remove());
            document.querySelectorAll('.carousel-item .lazy-thumb').forEach(img => { img.style.display = 'block'; });
            document.querySelectorAll('.carousel-item.selected').forEach(el => el.classList.remove('selected'));
            currentPreviewEl = null;
        }

        function startPreviewLoopForItem(item) {
            if (!item) return;
            // If this item is already previewing, ensure it's playing and skip restart
            if (currentPreviewEl === item) {
                const existingPreview = item.querySelector('video.preview');
                if (existingPreview) {
                    try { existingPreview.play().catch(() => {}); } catch(_) {}
                    return;
                }
            }
            // Ensure no other previews are active before starting a new one
            stopAllPreviews();
            item.classList.add('selected');
            currentPreviewEl = item;
            const requestId = ++previewRequestId;

            const filename = item.getAttribute('data-filename');
            const dirIndex = item.getAttribute('data-dir-index') || '0';

            // Try preview via admin cache mapping first
            fetch('data/admin_cache.json?t=' + Date.now())
                .then(r => r.json())
                .then(cache => {
                    try {
                        const files = Array.isArray(cache.all_video_files) ? cache.all_video_files : [];
                        const match = files.find(v => v && v.name === filename && String(v.dirIndex) === String(dirIndex));
                        if (requestId !== previewRequestId || currentPreviewEl !== item) {
                            // Stale response; ignore
                            return;
                        }
                        if (match && match.preview_hash) {
                            // Build preview URL and attach a small muted autoplaying loop
                            const url = 'data/previews/' + match.preview_hash + '.mp4';
                            insertPreviewVideo(item, url);
                        } else {
                            // No preview mapped for this video: show thumbnail only (no request)
                            showThumbOnly(item);
                        }
                    } catch (e) {
                        showThumbOnly(item);
                    }
                })
                .catch(() => {
                    if (requestId !== previewRequestId || currentPreviewEl !== item) return;
                    // On error, keep thumbnail only
                    showThumbOnly(item);
                });
        }

        function insertPreviewVideo(item, src) {
            if (item !== currentPreviewEl) return; // Do not insert into stale item
            // Hide thumb while preview plays
            const img = item.querySelector('.lazy-thumb');
            if (img) img.style.display = 'none';
            const existing = item.querySelector('video.preview');
            if (existing) existing.remove();
            const v = document.createElement('video');
            v.className = 'preview';
            v.muted = true;
            v.loop = true;
            v.autoplay = true;
            v.playsInline = true;
            v.preload = 'auto';
            v.src = src;
            // Strongly enforce looping playback in case autoplay/loop is flaky
            v.addEventListener('ended', () => {
                try { v.currentTime = 0; v.play().catch(() => {}); } catch(_) {}
            });
            v.addEventListener('loadeddata', () => {
                try { v.play().catch(() => {}); } catch(_) {}
            }, { once: true });
            v.onerror = function() {
                // On error fallback to thumbnail
                v.remove();
                showThumbOnly(item);
            };
            const placeholder = item.querySelector('.video-placeholder');
            if (placeholder) placeholder.before(v);
            else item.appendChild(v);
            // Kick off playback immediately
            try { v.play().catch(() => {}); } catch(_) {}
        }

        function showThumbOnly(item) {
            const img = item.querySelector('.lazy-thumb');
            if (img) img.style.display = 'block';
            const existing = item.querySelector('video.preview');
            if (existing) existing.remove();
        }
        // Build profile query for API calls
        const params = new URLSearchParams(location.search);
        let profileQuery = 'profile=default';
        if (params.has('profile')) {
            profileQuery = 'profile=' + encodeURIComponent(params.get('profile'));
        } else if (params.has('d')) {
            const n = parseInt(params.get('d') || '0', 10);
            if (n === 0) profileQuery = 'profile=default';
            else if (n >= 1) profileQuery = 'profile=dashboard' + n;
        } else if (params.has('dashboard')) {
            profileQuery = 'profile=' + encodeURIComponent(params.get('dashboard'));
        }
        
        // Also build the profile parameter for POST requests
        let profileParam = 'default';
        if (params.has('profile')) {
            profileParam = params.get('profile');
        } else if (params.has('d')) {
            const n = parseInt(params.get('d') || '0', 10);
            if (n === 0) profileParam = 'default';
            else if (n >= 1) profileParam = 'dashboard' + n;
        } else if (params.has('dashboard')) {
            profileParam = params.get('dashboard');
        }
        
        // Monitor config.json for changes and auto-refresh dashboard
        const configCheckInterval = 5000; // Check every 5 seconds
        
        let configCheckErrorCount = 0;
        function checkConfigForChanges() {
            fetch('api.php?action=check_config_changes&' + profileQuery + '&t=' + Date.now(), {
                signal: (window.AbortSignal && AbortSignal.timeout ? AbortSignal.timeout(5000) : undefined) // 5 second timeout
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    configCheckErrorCount = 0; // Reset error count on success
                    if (data.needsRefresh) {
                        // auto-refresh on config change
                        location.reload();
                    }
                })
                .catch((error) => {
                    configCheckErrorCount++;
                    console.error('Config check error:', error.message);
                    
                    // If we get too many errors, stop checking temporarily
                    if (configCheckErrorCount > 3) {
                        console.warn('Too many config check errors, pausing checks for 60 seconds');
                        setTimeout(() => {
                            configCheckErrorCount = 0; // Reset error count
                            checkConfigForChanges(); // Try again
                        }, 60000);
                    }
                });
        }
        
        // Start config monitoring
        setInterval(checkConfigForChanges, configCheckInterval);
        

        
        const clipsPerRow = <?php echo (int)$clipsPerRow; ?>;
        // Arrow click handlers and drag functionality
        document.querySelectorAll('.carousel-row').forEach(rowEl => {
            const track = rowEl.querySelector('.carousel-track');
            const items = track.querySelectorAll('.carousel-item');
            const prevBtn = rowEl.querySelector('.arrow.prev');
            const nextBtn = rowEl.querySelector('.arrow.next');
            const gap = 16; // gap defined in CSS
            
            // Arrow click handlers
            prevBtn.addEventListener('click', () => {
                const itemWidth = items[0].offsetWidth + gap;
                track.scrollBy({ left: -itemWidth, behavior: 'smooth' });
            });
            nextBtn.addEventListener('click', () => {
                const itemWidth = items[0].offsetWidth + gap;
                track.scrollBy({ left: itemWidth, behavior: 'smooth' });
            });
            
            // Drag functionality
            let isDragging = false;
            let dragStartX = 0;
            let dragStartScrollLeft = 0;
            let dragVelocity = 0;
            let lastDragTime = 0;
            let lastDragX = 0;
            
            // Mouse events
            track.addEventListener('mousedown', (e) => {
                isDragging = true;
                dragStartX = e.clientX;
                dragStartScrollLeft = track.scrollLeft;
                track.style.cursor = 'grabbing';
                track.style.scrollBehavior = 'auto'; // Disable smooth scrolling during drag
                
                // Prevent text selection
                e.preventDefault();
                
                // Track velocity for momentum
                lastDragTime = Date.now();
                lastDragX = e.clientX;
                dragVelocity = 0;
            });
            
            track.addEventListener('mousemove', (e) => {
                if (!isDragging) return;
                
                e.preventDefault();
                const dragDistance = e.clientX - dragStartX;
                track.scrollLeft = dragStartScrollLeft - dragDistance;
                
                // Calculate velocity for momentum
                const currentTime = Date.now();
                const timeDiff = currentTime - lastDragTime;
                if (timeDiff > 0) {
                    dragVelocity = (e.clientX - lastDragX) / timeDiff;
                }
                lastDragTime = currentTime;
                lastDragX = e.clientX;
            });
            
            track.addEventListener('mouseup', () => {
                if (isDragging) {
                    isDragging = false;
                    track.style.cursor = 'grab';
                    track.style.scrollBehavior = 'smooth'; // Re-enable smooth scrolling
                    
                    // Apply momentum scrolling
                    if (Math.abs(dragVelocity) > 0.1) {
                        const momentum = dragVelocity * 200; // Adjust momentum factor
                        track.scrollBy({ left: -momentum, behavior: 'smooth' });
                    }
                }
            });
            
            track.addEventListener('mouseleave', () => {
                if (isDragging) {
                    isDragging = false;
                    track.style.cursor = 'grab';
                    track.style.scrollBehavior = 'smooth';
                }
            });
            
            // Touch events for mobile
            track.style.touchAction = 'pan-y';
            track.addEventListener('touchstart', (e) => {
                isDragging = true;
                dragStartX = e.touches[0].clientX;
                dragStartScrollLeft = track.scrollLeft;
                track.style.scrollBehavior = 'auto';
                
                lastDragTime = Date.now();
                lastDragX = e.touches[0].clientX;
                dragVelocity = 0;
            }, { passive: true });
            
            track.addEventListener('touchmove', (e) => {
                if (!isDragging) return;
                
                const dragDistance = e.touches[0].clientX - dragStartX;
                track.scrollLeft = dragStartScrollLeft - dragDistance;
                
                // Calculate velocity
                const currentTime = Date.now();
                const timeDiff = currentTime - lastDragTime;
                if (timeDiff > 0) {
                    dragVelocity = (e.touches[0].clientX - lastDragX) / timeDiff;
                }
                lastDragTime = currentTime;
                lastDragX = e.touches[0].clientX;
            }, { passive: true });
            
            track.addEventListener('touchend', () => {
                if (isDragging) {
                    isDragging = false;
                    track.style.scrollBehavior = 'smooth';
                    
                    // Apply momentum
                    if (Math.abs(dragVelocity) > 0.1) {
                        const momentum = dragVelocity * 200;
                        track.scrollBy({ left: -momentum, behavior: 'smooth' });
                    }
                }
            });
            
            // Set initial cursor style
            track.style.cursor = 'grab';
            
            // Prevent click events on carousel items when dragging
            items.forEach(item => {
                let clickStartX = 0;
                let clickStartTime = 0;

                item.addEventListener('mousedown', (e) => {
                    clickStartX = e.clientX;
                    clickStartTime = Date.now();
                });

                // Track touch start as well so synthetic click after touch can be suppressed
                item.addEventListener('touchstart', (e) => {
                    const touch = e.touches && e.touches[0];
                    if (touch) {
                        clickStartX = touch.clientX;
                        clickStartTime = Date.now();
                    }
                }, { passive: true });

                item.addEventListener('click', (e) => {
                    const clickDistance = Math.abs(e.clientX - clickStartX);
                    const clickDuration = Date.now() - clickStartTime;

                    // If the pointer moved too much or if a drag occurred, cancel selection
                    if (clickDistance > 8 || isDragging) {
                        e.preventDefault();
                        // Prevent other click handlers on the same element from firing
                        if (typeof e.stopImmediatePropagation === 'function') {
                            e.stopImmediatePropagation();
                        } else {
                            e.stopPropagation();
                        }
                        return false;
                    }
                });
            });
        });
        
        // Lazy loading for image thumbnails with performance optimization
        const observerOptions = {
            root: null,
            rootMargin: '100px', // Start loading when 100px away from viewport
            threshold: 0.1
        };
        
        // Debounce video loading to prevent overwhelming the browser
        let loadingQueue = [];
        let isProcessingQueue = false;
        let totalVideos = document.querySelectorAll('.carousel-item').length;
        let loadedVideos = 0;
		const thumbnailsDisabled = false; // Always enable thumbnails
        
        const updateLoadingProgress = () => {
            const indicator = document.getElementById('loading-indicator');
            const progressText = document.getElementById('loading-progress');
            
            if (totalVideos > 10) { // Only show progress for large collections
                indicator.style.display = 'block';
                progressText.textContent = `(${loadedVideos}/${totalVideos})`;
                
                if (loadedVideos >= totalVideos || loadingQueue.length === 0) {
                    setTimeout(() => {
                        indicator.style.display = 'none';
                    }, 2000);
                }
            }
        };
        
        const processLoadingQueue = async () => {
            if (isProcessingQueue || loadingQueue.length === 0) return;
            
            isProcessingQueue = true;
            const batch = loadingQueue.splice(0, 6); // Can load more images concurrently than videos
            
            const loadPromises = batch.map(({ carouselItem, img }) => {
                return new Promise((resolve) => {
                    carouselItem.classList.add('loading');
                    const lazySrc = img.getAttribute('data-src');
                    if (!lazySrc) {
                        carouselItem.classList.remove('loading');
                        resolve();
                        return;
                    }
                    img.src = lazySrc;
                    
                    const cleanup = () => {
                        img.removeEventListener('load', onLoaded);
                        img.removeEventListener('error', onError);
                        img.removeAttribute('data-src');
                        resolve();
                    };
                    
                    const onLoaded = () => {
                        carouselItem.classList.remove('loading');
                        carouselItem.classList.add('loaded');
                        // Preview functionality disabled - only show thumbnails
                        loadedVideos++;
                        updateLoadingProgress();
                        cleanup();
                    };
                    
                    const onError = () => {
                        carouselItem.classList.remove('loading');
                        const playIcon = carouselItem.querySelector('.play-icon');
                        if (playIcon) playIcon.textContent = 'âŒ';
                        loadedVideos++;
                        updateLoadingProgress();
                        cleanup();
                    };
                    
                    img.addEventListener('load', onLoaded);
                    img.addEventListener('error', onError);
                    
                    // Timeout fallback
                    setTimeout(cleanup, 5000);
                });
            });
            
            await Promise.all(loadPromises);
            isProcessingQueue = false;
            
            // Process next batch if queue has more items
            if (loadingQueue.length > 0) {
                setTimeout(processLoadingQueue, 100);
            }
        };
        
        if (!thumbnailsDisabled) {
            if (!window.IntersectionObserver) {
                // Fallback: eagerly load thumbnails when IntersectionObserver is unavailable
                document.querySelectorAll('.carousel-item .lazy-thumb[data-src]').forEach(img => {
                    img.src = img.getAttribute('data-src');
                    img.removeAttribute('data-src');
                });
            } else {
            const videoObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const carouselItem = entry.target;
                        const img = carouselItem.querySelector('.lazy-thumb');
                        if (img && img.getAttribute('data-src')) {
                            loadingQueue.push({ carouselItem, img });
                            videoObserver.unobserve(carouselItem);
                            
                            // Start processing queue
                            processLoadingQueue();
                        }
                    }
                });
            }, observerOptions);
            // Start observing all carousel items
            document.querySelectorAll('.carousel-item').forEach(item => {
                videoObserver.observe(item);
            });
            }
        } else {
            // Indicate thumbnails are disabled to the user when collections are big
            const indicator = document.getElementById('loading-indicator');
            const text = document.getElementById('loading-text');
            if (indicator && text) {
                text.textContent = 'Thumbnails disabled for performance';
                indicator.style.display = 'block';
                setTimeout(() => { indicator.style.display = 'none'; }, 3000);
            }
        }
        
        // Performance optimization: Thumbnails only - previews disabled
        // Load current playing video to highlight (only if there's actually a video selected)
        updateCurrentVideoDisplay();
        // Debug: Check if videos are loaded
        console.log('Dashboard: Found', document.querySelectorAll('.carousel-item').length, 'video items');

        // Add click event to each item to select video
            document.querySelectorAll('.carousel-item').forEach(item => {
            item.addEventListener('click', () => {
                console.log('Carousel item clicked:', item);
                logDash('tile_click', { filename: item.getAttribute('data-filename'), dirIndex: item.getAttribute('data-dir-index') });
                // Record user selection timestamp to prevent polling interference
                lastUserSelection = Date.now();
                console.log('User selection recorded at timestamp:', lastUserSelection);
                // If this item is already selected, do nothing to avoid stopping playback
                if (item.classList.contains('playing')) {
                    console.log('Item already playing, ignoring click');
                    logDash('tile_click_ignored_already_playing');
                    return;
                }

                const filename = item.getAttribute('data-filename');
                const dirIndex = parseInt(item.getAttribute('data-dir-index') || '0', 10);
                console.log('Selected video:', filename, 'dirIndex:', dirIndex);
                // Highlight selected
                document.querySelectorAll('.carousel-item.playing').forEach(el => el.classList.remove('playing'));
                item.classList.add('playing');
                // Always start preview loop for the selected item
                console.log('Starting preview loop for selected item, currentPlaybackState:', currentPlaybackState);
                startPreviewLoopForItem(item);
                // Send update to server
                const formData = new FormData();
                formData.append('filename', filename);
                formData.append('dirIndex', String(dirIndex));
                formData.append('profile', profileParam);
                console.log('Setting current video:', filename, 'at dirIndex:', dirIndex);
                fetch('api.php?action=set_current_video&' + profileQuery, {
                    method: 'POST',
                    body: formData
                }).then(res => res.json()).then((data) => {
                    logDash('set_current_video_response', { ok: true, data });
                    console.log('Set current video response:', data);
                    // Update current video name with custom title (controls are persistent)
                    updateVideoControlsTitle(filename, dirIndex);

                    // Start playing the selected video automatically
                     const playForm = new FormData();
                     playForm.append('profile', profileParam);
                     fetch('api.php?action=play_video&' + profileQuery, { method: 'POST', body: playForm })
                         .then(res => res.json())
                         .then(() => { updateButtonStates('play'); })
                }).catch((error) => {
                    logDash('set_current_video_error', { message: String(error) });
                    console.error('Set current video error:', error);
                });
            });
        });
        
        // Control button handlers
        document.getElementById('play-btn').addEventListener('click', () => {
            // Record user action timestamp to prevent polling interference
            lastUserSelection = Date.now();
            // Immediate visual feedback
            updateButtonStates('play');
            stopPreview();
            const playForm = new FormData();
            playForm.append('profile', profileParam);
            fetch('api.php?action=play_video&' + profileQuery, { method: 'POST', body: playForm })
                .then(res => res.json())
                .then(() => {})
                .catch(err => {
                    
                    // Revert button state on error
                    pollPlaybackState();
                });
        });
        
        document.getElementById('pause-btn').addEventListener('click', () => {
            // Immediate visual feedback
            updateButtonStates('pause');
            const pauseForm = new FormData();
            pauseForm.append('profile', profileParam);
            fetch('api.php?action=pause_video&' + profileQuery, { method: 'POST', body: pauseForm })
                .then(res => res.json())
                .then(() => {
                        const sel = document.querySelector('.carousel-item.playing');
                        if (sel) startPreviewLoopForItem(sel);
                })
                .catch(err => {
                    
                    // Revert button state on error
                    pollPlaybackState();
                });
        });
        
        document.getElementById('stop-btn').addEventListener('click', () => {
            
            // Immediate visual feedback
            updateButtonStates('stop');
            
            // First stop the video
            const stopForm = new FormData();
            stopForm.append('profile', profileParam);
            fetch('api.php?action=stop_video&' + profileQuery, { method: 'POST', body: stopForm })
                .then(res => res.json())
                .then((data) => {
                    
                    // Then clear the current video selection
                    const clearForm = new FormData();
                    clearForm.append('profile', profileParam);
                    return fetch('api.php?action=clear_current_video&' + profileQuery, { method: 'POST', body: clearForm });
                })
                .then(res => res.json())
                .then((data) => {
                    // Update UI to show "select a video" state
                    updateCurrentVideoDisplay();
                    stopPreview();
                })
                .catch(err => {
                    
                    // Revert button state on error
                    pollPlaybackState();
                });
        });
        
        // Function to update button states
        function updateButtonStates(state) {
            const playBtn = document.getElementById('play-btn');
            const pauseBtn = document.getElementById('pause-btn');
            const stopBtn = document.getElementById('stop-btn');
            
            // Reset all buttons
            playBtn.className = 'btn secondary';
            pauseBtn.className = 'btn secondary';
            stopBtn.className = 'btn secondary';
            
            // Set active button
            switch(state) {
                case 'play':
                    playBtn.className = 'btn primary';
                    break;
                case 'pause':
                    pauseBtn.className = 'btn primary';
                    break;
                case 'stop':
                    stopBtn.className = 'btn primary';
                    break;
            }
        }
        
        // Poll for playback state to keep buttons in sync
        let currentPlaybackState = 'stop';
        let pollErrorCount = 0;
        function pollPlaybackState() {
            fetch('api.php?action=get_playback_state&' + profileQuery, {
                signal: (window.AbortSignal && AbortSignal.timeout ? AbortSignal.timeout(5000) : undefined) // 5 second timeout
            })
                .then(res => {
                    if (!res.ok) {
                        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                    }
                    return res.json();
                })
                .then(data => {
                    pollErrorCount = 0; // Reset error count on success
                    updateButtonStates(data.state);
                    currentPlaybackState = data.state || 'stop';
                    // Update current video info when state changes
                    fetch('api.php?action=get_current_video&' + profileQuery)
                        .then(r => r.json())
                        .then(cd => {
                            const cv = cd && cd.currentVideo;
                            const nm = cv && typeof cv === 'object' ? (cv.filename || '') : '';
                            const di = cv && typeof cv === 'object' ? (cv.dirIndex || 0) : 0;
                            updateVideoControlsTitle(nm, di);
                        }).catch(() => {});
                    if (Date.now() - lastUserSelection < 4000 && currentPlaybackState === 'stop') {
                        logDash('unexpected_stop_after_selection', { sinceSelectionMs: Date.now() - lastUserSelection });
                    }
                    const sel = document.querySelector('.carousel-item.playing');
                    if (sel && (currentPreviewEl !== sel || !sel.querySelector('video.preview'))) {
                        startPreviewLoopForItem(sel);
                    }
                })
                .catch((error) => {
                    pollErrorCount++;
                    logDash('poll_playback_error', { message: String(error), count: pollErrorCount });
                    console.error('Poll error:', error.message);
                    
                    // If we get too many errors, stop polling temporarily
                    if (pollErrorCount > 5) {
                        console.warn('Too many poll errors, pausing polling for 30 seconds');
                        setTimeout(() => {
                            pollErrorCount = 0; // Reset error count
                            pollPlaybackState(); // Try again
                        }, 30000);
                    }
                });
        }
        
        // Function to update current video display
        let updateErrorCount = 0;
        function updateCurrentVideoDisplay() {
            fetch('api.php?action=get_current_video&' + profileQuery, {
                signal: (window.AbortSignal && AbortSignal.timeout ? AbortSignal.timeout(5000) : undefined) // 5 second timeout
            })
                .then(res => {
                    if (!res.ok) {
                        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                    }
                    return res.json();
                })
                .then(data => {
                    updateErrorCount = 0; // Reset error count on success
                    const current = data.currentVideo;
                    if (current && (typeof current === 'object' || String(current).trim() !== '')) {
                        // Clear all playing highlights
                        document.querySelectorAll('.carousel-item.playing').forEach(el => el.classList.remove('playing'));
                        
                        // Highlight current video
                        document.querySelectorAll('.carousel-item').forEach(item => {
                            try {
                                const j = current;
                                if (typeof j === 'object' && j) {
                                    const nm = j.filename;
                                    const di = String(j.dirIndex ?? 0);
                                    if (item.getAttribute('data-filename') === nm && item.getAttribute('data-dir-index') === di) {
                                        item.classList.add('playing');
                                        // Only start preview if not already previewing this item
                                        if (currentPreviewEl !== item || !item.querySelector('video.preview')) {
                                            startPreviewLoopForItem(item);
                                        }
                                    }
                                }
                            } catch {}
                        });
                        
                        // Update name with custom title (controls are persistent)
                        const nm = typeof current === 'object' && current ? current.filename : String(current || '');
                        const di = typeof current === 'object' && current ? (current.dirIndex ?? 0) : 0;
                        updateVideoControlsTitle(nm, di);
                    } else {
                        // No video selected
                        document.querySelectorAll('.carousel-item.playing').forEach(el => el.classList.remove('playing'));
                        document.getElementById('current-video-name').textContent = 'No video selected';
                        stopPreview();
                    }
                })
                .catch((error) => {
                    updateErrorCount++;
                    console.error('Update display error:', error.message);
                    
                    // If we get too many errors, stop updating temporarily
                    if (updateErrorCount > 5) {
                        console.warn('Too many update errors, pausing updates for 30 seconds');
                        setTimeout(() => {
                            updateErrorCount = 0; // Reset error count
                            updateCurrentVideoDisplay(); // Try again
                        }, 30000);
                    }
                });
        }
        
        // Function to update video controls title with custom title and state-aware prefix
        function updateVideoControlsTitle(filename, dirIndex = 0) {
            Promise.all([
                fetch('api.php?action=get_video_titles&' + profileQuery).then(res => res.json()).catch(() => ({ titles: {} })),
                fetch('api.php?action=get_playback_state&' + profileQuery).then(res => res.json()).catch(() => ({ state: 'stop' })),
                fetch('api.php?action=get_current_video&' + profileQuery).then(res => res.json()).catch(() => ({ currentVideo: null }))
            ]).then(([titlesData, stateData, currentData]) => {
                const titles = titlesData.titles || {};
                const titleKey = dirIndex + '|' + filename;
                const customTitle = titles[titleKey];
                const displayTitle = (customTitle || (filename ? filename.replace(/\.[^/.]+$/, '') : ''));
                const state = (stateData && typeof stateData.state === 'string') ? stateData.state : 'stop';
                const cv = currentData && currentData.currentVideo;
                const hasSelected = !!(cv && ((typeof cv === 'object' && cv.filename) || (typeof cv === 'string' && cv.trim() !== '')));
                let prefix = 'Stopped';
                if (state === 'play') prefix = 'Playing';
                else if (state === 'pause') prefix = 'Paused';
                else if (state === 'stop' && hasSelected) prefix = 'Stopped';
                document.getElementById('current-video-name').textContent = prefix + (displayTitle ? ': ' + displayTitle : '');
            }).catch(() => {
                const fallback = filename ? filename.replace(/\.[^/.]+$/, '') : '';
                document.getElementById('current-video-name').textContent = (fallback ? ('Stopped: ' + fallback) : 'No video selected');
            });
        }
        
        // Volume control
        const volumeSlider = document.getElementById('volume-slider');
        const volumeDisplay = document.getElementById('volume-display');
        const muteBtn = document.getElementById('mute-btn');
        const volumeControl = document.querySelector('.volume-control');

        function setVolumeSliderProgress() {
            if (!volumeSlider) return;
            const min = parseFloat(volumeSlider.min || '0');
            const max = parseFloat(volumeSlider.max || '100');
            const val = parseFloat(volumeSlider.value || '0');
            const pct = Math.max(0, Math.min(100, ((val - min) / (max - min)) * 100));
            volumeSlider.style.setProperty('--range-progress', pct + '%');
        }
        
        volumeSlider.addEventListener('input', () => {
            const volume = volumeSlider.value;
            volumeDisplay.textContent = volume + '%';
            setVolumeSliderProgress();
            
            // Send volume update to server (scoped to current profile)
            const formData = new FormData();
            formData.append('volume', volume);
            formData.append('profile', profileParam);
            fetch('api.php?action=set_volume', {
                method: 'POST',
                body: formData
            }).then(res => res.json())
            .then(() => console.log('Volume updated to ' + volume + '%'))
            .catch(err => console.error('Volume update failed:', err));
        });
        
        // Mute button functionality (scoped to current profile)
        muteBtn.addEventListener('click', () => {
            const formData = new FormData();
            formData.append('profile', profileParam);
            fetch('api.php?action=toggle_mute', { 
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    updateMuteButton(data.muted);
                })
                .catch(err => console.error('Mute toggle failed:', err));
        });
        
        function updateMuteButton(muted) {
            const label = muteBtn.querySelector('.label');
            const icon = muteBtn.querySelector('img.icon');
            if (label) label.textContent = muted ? 'Unmute' : 'Mute';
            if (icon) icon.src = muted ? 'assets/svgs/volume-off.svg' : 'assets/svgs/volume-on.svg';
            muteBtn.className = muted ? 'btn primary' : 'btn secondary';
            if (volumeControl) {
                if (muted) {
                    volumeControl.classList.add('muted');
                } else {
                    volumeControl.classList.remove('muted');
                }
            }
        }
        
        // Load initial volume and mute state
        Promise.all([
            fetch('api.php?action=get_volume&' + profileQuery).then(res => res.json()),
            fetch('api.php?action=get_mute_state&' + profileQuery).then(res => res.json())
            ]).then(([volumeData, muteData]) => {
            volumeSlider.value = volumeData.volume;
            volumeDisplay.textContent = volumeData.volume + '%';
            setVolumeSliderProgress();
            updateMuteButton(muteData.muted);
        }).catch(() => {});

        // Initialize progress on first paint
        setVolumeSliderProgress();
        
                        // UI helpers for loop and play-all buttons (canonical keys)
        function updateLoopButton(loopMode) {
            try {
                var btn = document.getElementById('loop-btn');
                if (!btn) return;
                var label = btn.querySelector('.label');
                if (label) label.textContent = (loopMode === 'on' ? 'Loop On' : 'Loop');
                btn.className = (loopMode === 'on' ? 'btn primary' : 'btn secondary');
            } catch (e) { /* no-op */ }
        }

        function updatePlayAllButton(playAllMode) {
            try {
                var btn = document.getElementById('all-btn');
                if (!btn) return;
                var label = btn.querySelector('.label');
                if (label) label.textContent = (playAllMode === 'on' ? 'Play All On' : 'Play All');
                btn.className = (playAllMode === 'on' ? 'btn primary' : 'btn secondary');
            } catch (e) { /* no-op */ }
        }
// Loop button functionality
        const loopBtn = document.getElementById('loop-btn');
        if (loopBtn) {
            loopBtn.addEventListener('click', () => {
                const currLabel = (loopBtn.querySelector('.label') || {}).textContent || 'Loop';
                const optimisticMode = currLabel.indexOf('On') !== -1 ? 'off' : 'on';
                updateLoopButton(optimisticMode);
                loopBtn.disabled = true;

                fetch('api.php?action=get_loop_mode&' + profileQuery)
                    .then(res => res.json())
                    .then(data => {
                        const currentMode = (typeof data.loopMode === 'string') ? data.loopMode : (data.loop || 'off');
                        const newLoopMode = currentMode === 'on' ? 'off' : 'on';
                        const formData = new FormData();
                        formData.append('loopMode', newLoopMode);
                        formData.append('profile', profileParam);
                        return fetch('api.php?action=set_loop_mode', { method: 'POST', body: formData });
                    })
                    .then(res => res.json())
                    .then(data => {
                        const mode = (typeof data.loopMode === 'string') ? data.loopMode : (data.loop || 'off');
                        updateLoopButton(mode);
                    })
                    .catch(err => {
                        console.error('Loop toggle failed:', err);
                        fetch('api.php?action=get_loop_mode&' + profileQuery)
                            .then(r => r.json())
                            .then(d => {
                                const mode = (typeof d.loopMode === 'string') ? d.loopMode : (d.loop || 'off');
                                updateLoopButton(mode);
                            })
                            .catch(() => {});
                    })
                    .finally(() => { loopBtn.disabled = false; });
            });
        }

        // Play All button functionality
        const allBtn = document.getElementById('all-btn');
        if (allBtn) {
            allBtn.addEventListener('click', () => {
                const currLabel = (allBtn.querySelector('.label') || {}).textContent || 'Play All';
                const optimisticMode = currLabel.indexOf('On') !== -1 ? 'off' : 'on';
                updatePlayAllButton(optimisticMode);
                allBtn.disabled = true;

                fetch('api.php?action=get_play_all_mode&' + profileQuery)
                    .then(res => res.json())
                    .then(data => {
                        const currentMode = (typeof data.playAllMode === 'string') ? data.playAllMode : (data.play_all || 'off');
                        const newPlayAllMode = currentMode === 'on' ? 'off' : 'on';
                        const formData = new FormData();
                        formData.append('playAllMode', newPlayAllMode);
                        formData.append('profile', profileParam);
                        return fetch('api.php?action=set_play_all_mode', { method: 'POST', body: formData });
                    })
                    .then(res => res.json())
                    .then(data => {
                        const mode = (typeof data.playAllMode === 'string') ? data.playAllMode : (data.play_all || 'off');
                        updatePlayAllButton(mode);
                    })
                    .catch(err => {
                        console.error('Play All toggle failed:', err);
                        fetch('api.php?action=get_play_all_mode&' + profileQuery)
                            .then(r => r.json())
                            .then(d => {
                                const mode = (typeof d.playAllMode === 'string') ? d.playAllMode : (d.play_all || 'off');
                                updatePlayAllButton(mode);
                            })
                            .catch(() => {});
                    })
                    .finally(() => { allBtn.disabled = false; });
            });
        }
        // Load initial loop, play all, and external audio states
        Promise.all([
            fetch('api.php?action=get_loop_mode&' + profileQuery).then(res => res.json()),
            fetch('api.php?action=get_play_all_mode&' + profileQuery).then(res => res.json()),
            fetch('api.php?action=get_external_audio_mode&' + profileQuery).then(res => res.json())
        ]).then(([loopData, playAllData, externalData]) => {
            const loopInit = (typeof loopData.loopMode === 'string') ? loopData.loopMode : (loopData.loop || 'off');
            const playAllInit = (typeof playAllData.playAllMode === 'string') ? playAllData.playAllMode : (playAllData.play_all || 'off');
            updateLoopButton(loopInit);
            updatePlayAllButton(playAllInit);
            if (typeof setExternalAudioUI === 'function') {
                setExternalAudioUI(externalData.external === "on");
            }
        }).catch(() => {});
        const externalAudioBtn = document.getElementById('external-audio-btn');
        const externalAudioBanner = document.getElementById('external-audio-banner');
        const externalHelpBtn = document.getElementById('external-help-btn');

        function setExternalAudioUI(isOn) {
            if (!externalAudioBtn) return;
            const label = externalAudioBtn.querySelector('.label');
            if (label) label.textContent = isOn ? 'External Audio On' : 'External Audio';
            externalAudioBtn.className = isOn ? 'btn primary' : 'btn secondary';
            if (externalAudioBanner) externalAudioBanner.style.display = isOn ? 'flex' : 'none';
            if (isOn) {
                updateMuteButton(true);
            }
        }

        if (externalAudioBtn) {
            externalAudioBtn.addEventListener('click', () => {
                fetch('api.php?action=get_external_audio_mode&' + profileQuery).then(res => res.json()).then(data => {
                    const newMode = data.external === 'on' ? 'off' : 'on';
                    const formData = new FormData();
                    formData.append('external', newMode);
                    formData.append('profile', profileParam);
                    return fetch('api.php?action=set_external_audio_mode', { method: 'POST', body: formData });
                }).then(res => res.json()).then(data => {
                    setExternalAudioUI(data.external === 'on');
            }).catch(() => {});
            });
        }

        if (externalHelpBtn) {
            externalHelpBtn.addEventListener('click', () => {
                showExternalHelpOverlay();
            });
        }

        function showExternalHelpOverlay() {
            const overlay = document.createElement('div');
            overlay.style.position = 'fixed';
            overlay.style.inset = '0';
            overlay.style.background = 'rgba(0,0,0,0.8)';
            overlay.style.zIndex = '1000';
            overlay.style.display = 'flex';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.innerHTML = `
                <div style="background:#1f1f1f; border:1px solid #333; border-radius:8px; padding:20px; max-width:600px; width:90%; color:#fff;">
                    <h3 style=\"margin:0 0 10px 0; color:#4ecdc4;\">External Audio Help</h3>
                    <ol style=\"margin-left:18px; color:#ccc;\">
                        <li>Enable Bluetooth pairing on the room speaker/receiver.</li>
                        <li>On your smartphone, pair with the room speaker and ensure it is set for media audio.</li>
                        <li>Open your music app (Spotify, YouTube Music, etc.) and start playback.</li>
                        <li>Ensure this dashboard shows \"External Audio On\". Video audio will remain muted.</li>
                    </ol>
                    <div style=\"margin-top:12px; text-align:right;\">
                        <button id=\"external-help-close\" class=\"btn secondary\">Close</button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
            overlay.querySelector('#external-help-close').addEventListener('click', () => overlay.remove());
            overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
        }

        // Load custom video titles
        loadCustomTitles();
        
        function loadCustomTitles() {
            fetch('api.php?action=get_video_titles&' + profileQuery).then(res => res.json()).then(data => {
                const titles = data.titles || {};
                document.querySelectorAll('.carousel-item .title').forEach(titleEl => {
                    const filename = titleEl.getAttribute('data-filename');
                    const dirIndex = titleEl.getAttribute('data-dir-index') || '0';
                    const titleKey = dirIndex + '|' + filename;
                    if (titles[titleKey]) {
                        titleEl.textContent = titles[titleKey];
                    }
                });
            }).catch(() => {});
        }
        
        // Initial state check
        pollPlaybackState();
        updateCurrentVideoDisplay();
        // Poll every 3 seconds to keep buttons in sync (reduced from 1 second)
        setInterval(pollPlaybackState, 3000);
        // Poll every 5 seconds to keep current video display in sync (reduced from 2 seconds)
        // But don't interfere with recent user selections
        let lastUserSelection = 0;
        setInterval(() => {
            const timeSinceSelection = Date.now() - lastUserSelection;
            console.log('Polling check - Time since last selection:', timeSinceSelection + 'ms');
            // Only update if no recent user selection (within last 2 seconds)
            if (timeSinceSelection > 2000) {
                console.log('Updating current video display via polling');
                updateCurrentVideoDisplay();
            } else {
                console.log('Skipping polling update due to recent user selection');
            }
        }, 5000);
        
        // Check for refresh signals from admin panel every 5 seconds
        function checkRefreshSignal() {
            fetch('api.php?action=check_refresh_signal&' + profileQuery)
                .then(response => response.json())
                .then(data => {
                    if (data.should_refresh) {
                        console.log('Dashboard refresh signal received, reloading...');
                        window.location.reload();
                    }
                })
                .catch(error => {
                    // Silently ignore errors to avoid console spam
                    console.debug('Refresh signal check failed:', error);
                });
        }
        
        // Check for refresh signals every 5 seconds
        setInterval(checkRefreshSignal, 5000);
        
        // Global error handler for video-related errors
        window.addEventListener('error', (e) => {
            // Only handle video-related errors
            if (e.target && e.target.tagName === 'VIDEO') {
                console.warn('Global video error caught:', e.error);
                
                // Preview functionality disabled - no preview video errors to handle
                
                // Prevent the error from affecting other functionality
                e.preventDefault();
                return false;
            }
        }, true);
        
        // Also catch unhandled promise rejections from video operations
        window.addEventListener('unhandledrejection', (e) => {
            if (e.reason && typeof e.reason === 'object' && e.reason.name) {
                // Check if this is a video-related error
                if (e.reason.name === 'NotSupportedError' || 
                    e.reason.name === 'NotAllowedError' ||
                    e.reason.message.includes('no supported sources')) {
                    console.warn('Unhandled video promise rejection caught:', e.reason);
                    e.preventDefault();
                    return false;
                }
            }
        });
        

    });
    </script>
</body>
</html>

