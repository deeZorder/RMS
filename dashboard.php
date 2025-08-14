<?php
// dashboard.php
// User interface to browse and select videos for playback.

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
// Support short param: d=0 → default, d=1 → dashboard1; otherwise ?dashboard=dashboardId; default → 'default'
$selectedDashboardId = 'default';
if (isset($_GET['d'])) {
    $n = (int)$_GET['d'];
    if ($n === 0) { $selectedDashboardId = 'default'; }
    elseif ($n >= 1) { $selectedDashboardId = 'dashboard' . $n; }
} elseif (isset($_GET['dashboard'])) {
    $selectedDashboardId = (string)$_GET['dashboard'];
}
$dashboards = [];
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
$allowedExt = ['mp4', 'webm', 'ogg', 'mov'];

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
        }
    }
}

// Fallback: scan directories if cache not used or unavailable
if (!$usedCacheForVideos) {
    foreach ($clipsDirs as $dirIndex => $clipsDir) {
        $resolved = isset($dirAbsPaths[$dirIndex]) ? $dirAbsPaths[$dirIndex] : (is_dir($clipsDir) ? $clipsDir : realpath(__DIR__ . '/' . $clipsDir));
        if ($resolved && is_dir($resolved)) {
            $items = @scandir($resolved) ?: [];
            foreach ($items as $file) {
                if ($file === '.' || $file === '..') { continue; }
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExt, true)) {
                    $videoFiles[] = ['name' => $file, 'dirIndex' => $dirIndex];
                }
            }
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
    $lastScanPath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId) . '/last_scan.txt';
    
    if (!file_exists($lastScanPath)) {
        return true; // No cache exists
    }
    
    $lastScanTime = (int)file_get_contents($lastScanPath);
    $maxDirModTime = 0;
    
    foreach ($dirAbsPaths as $dir) {
        if (is_dir($dir)) {
            $maxDirModTime = max($maxDirModTime, filemtime($dir));
        }
    }
    
    return $maxDirModTime > $lastScanTime;
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
    $currentVideoPath = $baseDir . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId) . '/current_video.txt';
    
    if (file_exists($currentVideoPath)) {
        $currentVideoData = json_decode(@file_get_contents($currentVideoPath), true);
        if (is_array($currentVideoData) && isset($currentVideoData['filename'])) {
            $currentFilename = $currentVideoData['filename'];
            $currentDirIndex = isset($currentVideoData['dirIndex']) ? (int)$currentVideoData['dirIndex'] : 0;
            
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
                @file_put_contents($currentVideoPath, json_encode($newCurrentVideo, JSON_UNESCAPED_SLASHES));
                
                // Log the auto-update for debugging
                error_log("Auto-updated current video for profile $profileId: " . $firstVideo['name']);
                
                return $newCurrentVideo;
            }
        }
    }
    
    return null;
}

// ============================================================================
// MAIN EXECUTION CODE - Now all functions are defined and can be called
// ============================================================================

// Apply saved order if available, with smart caching based on file modification times
$orderPath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $selectedDashboardId) . '/video_order.json';
$lastScanPath = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $selectedDashboardId) . '/last_scan.txt';

// Only scan if directories have changed since last scan
$needsScan = isCacheStale($selectedDashboardId, $dirAbsPaths);

if ($needsScan) {
    // Directories have changed, need to scan
    $orderKeys = autoUpdateVideoOrder(__DIR__, $selectedDashboardId, $dirAbsPaths, $videoFiles);
    // Update last scan time
    file_put_contents($lastScanPath, (string)time());
    
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

// Distribute videos across rows
$rows = max(1, (int)$config['rows']);
$clipsPerRow = max(1, (int)$config['clipsPerRow']);
$totalVideos = count($videoFiles);

// Handle case where no videos are found
if ($totalVideos === 0) {
    $rowsData = [];
} elseif ($rows > 0 && $totalVideos > 0) {
    // Fill each row with clipsPerRow videos before moving to next row
    $rowsData = [];
    $remainingVideos = $videoFiles;
    
    for ($rowIndex = 0; $rowIndex < $rows && !empty($remainingVideos); $rowIndex++) {
        $rowVideos = array_splice($remainingVideos, 0, $clipsPerRow);
        $rowsData[] = $rowVideos;
    }
    
    // If there are still remaining videos, add them to the last row
    if (!empty($remainingVideos)) {
        $rowsData[count($rowsData) - 1] = array_merge($rowsData[count($rowsData) - 1], $remainingVideos);
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
            <span>✅ Video list automatically updated! Current video set to: <?php echo htmlspecialchars($currentVideoUpdate['filename']); ?></span>
            <button onclick="document.getElementById('auto-update-notification').style.display='none'" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer;">×</button>
        </div>
        <?php endif; ?>
        <?php if (empty($videoFiles)): ?>
            <p>No videos found, please put videos in the video map or choose a different map on the admin page.</p>
        <?php else: ?>
            <?php 
            // Define a CSS custom property to calculate item width for each row
            $gap = 12; // reduced gap in pixels for better media fit
            // We'll set data attributes on the container for JS to use
            ?>
            <?php foreach ($rowsData as $rowIndex => $videos): ?>
                <div class="carousel-row" data-row-index="<?php echo $rowIndex; ?>">
                    <button class="arrow prev" aria-label="Scroll left">&#x2039;</button>
                    <div class="carousel-track" data-row="<?php echo $rowIndex; ?>">
                        <?php foreach ($videos as $video): 
                            // Calculate style for width per item with reduced gap
                            $calcWidth = "calc((100% - " . ($clipsPerRow - 1) . " * {$gap}px) / " . $clipsPerRow . ")";
                        ?>
                            <div class="carousel-item" data-filename="<?php echo htmlspecialchars($video['name'], ENT_QUOTES, 'UTF-8'); ?>" data-dir-index="<?php echo (int)$video['dirIndex']; ?>" style="flex: 0 0 <?php echo $calcWidth; ?>;">
                                <img data-src="thumb.php?file=<?php echo rawurlencode($video['name']); ?>&dirIndex=<?php echo (int)$video['dirIndex']; ?>" alt="thumbnail" class="lazy-thumb" loading="lazy" />
                                <video data-src="preview.php?file=<?php echo rawurlencode($video['name']); ?>&dirIndex=<?php echo (int)$video['dirIndex']; ?>" class="lazy-preview-video" muted loop preload="none" style="display: none;"></video>
                                <div class="video-placeholder">
                                    <div class="play-icon">▶</div>
                                </div>
                                <div class="title" data-filename="<?php echo htmlspecialchars($video['name'], ENT_QUOTES, 'UTF-8'); ?>" data-dir-index="<?php echo (int)$video['dirIndex']; ?>"><?php echo htmlspecialchars(pathinfo($video['name'], PATHINFO_FILENAME), ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="arrow next" aria-label="Scroll right">&#x203a;</button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Video Controls (persistent) -->
        <div class="video-controls" id="video-controls">
            <h3></h3>
            <div class="control-row">
                <div class="control-buttons">
                    <button type="button" id="play-btn" class="btn primary">▶ Play</button>
                    <button type="button" id="pause-btn" class="btn secondary">⏸ Pause</button>
                    <button type="button" id="stop-btn" class="btn secondary">⏹ Stop</button>
                </div>
                <div class="playback-controls">
                    <button type="button" id="loop-btn" class="btn secondary">🔁 Loop</button>
                    <button type="button" id="all-btn" class="btn secondary">📺 Play All</button>
                    <button type="button" id="external-audio-btn" class="btn secondary" title="Use phone or external device for audio" style="display: none;">🔈 External Audio</button>
                </div>
            </div>
            <div class="volume-control">
                <div class="volume-header">
                    <label for="volume-slider">Volume: <span id="volume-display">100%</span></label>
                    <button type="button" id="mute-btn" class="btn secondary">🔊</button>
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
        // Keep preview independent from polling by tracking active element
        let currentPreviewEl = null;
        function stopPreview() {
            if (!currentPreviewEl) return;
            const v = currentPreviewEl.querySelector('.lazy-preview-video');
            if (v) {
                try { v.pause(); } catch {}
                v.loop = false;
                // Release network/decoder to be safe
                try { v.removeAttribute('src'); v.load(); } catch {}
            }
            // Ensure tile UI returns to thumbnail state
            try { currentPreviewEl.classList.remove('previewing'); } catch {}
            currentPreviewEl = null;
        }

        function stopAllPreviews() {
            document.querySelectorAll('.lazy-preview-video').forEach(v => {
                try { v.pause(); } catch {}
                v.loop = false;
                try { v.removeAttribute('src'); v.load(); } catch {}
                const item = v.closest('.carousel-item');
                if (item) { item.classList.remove('previewing'); }
            });
            currentPreviewEl = null;
        }

        function startPreviewLoopForItem(item) {
            console.log('startPreviewLoopForItem called with:', item);
            if (!item) return;
            if (currentPreviewEl === item) return; // already previewing this tile
            stopAllPreviews(); // hard stop any other previews before starting a new one

            // Get the existing video element (should already exist from HTML generation)
            let v = item.querySelector('.lazy-preview-video');
            console.log('Existing preview video element:', v);
            
            if (!v) {
                console.log('Creating new preview video element (fallback)');
                v = document.createElement('video');
                v.className = 'lazy-preview-video';
                
                // Standard attributes for video playback
                v.setAttribute('muted', '');
                v.setAttribute('playsinline', '');
                v.setAttribute('webkit-playsinline', '');
                v.setAttribute('x5-playsinline', '');
                
                // Tablet-specific attributes for better compatibility
                v.setAttribute('x5-video-player-type', 'h5');
                v.setAttribute('x5-video-player-fullscreen', 'false');
                v.setAttribute('x5-video-orientation', 'portraint');
                v.setAttribute('x5-video-same-origin', 'true');
                
                // iOS Safari specific attributes
                v.setAttribute('webkit-playsinline', '');
                v.setAttribute('playsinline', '');
                
                // Android TV specific attributes
                v.setAttribute('android-keep-screen-on', 'true');
                v.setAttribute('android-focusable', 'true');
                v.setAttribute('android-focusable-in-touch-mode', 'true');
                
                // Force hardware acceleration and rendering
                v.style.transform = 'translateZ(0)';
                v.style.webkitTransform = 'translateZ(0)';
                v.style.backfaceVisibility = 'hidden';
                v.style.webkitBackfaceVisibility = 'hidden';
                
                v.preload = 'metadata';
                v.autoplay = false;
                v.loop = true; // Loop the preview video
                
                // Insert video before placeholder so it sits underneath the title bar but above the image
                const placeholder = item.querySelector('.video-placeholder');
                if (placeholder && placeholder.parentNode === item) {
                    item.insertBefore(v, placeholder);
                } else {
                    item.appendChild(v);
                }
                console.log('Preview video element inserted, placeholder:', placeholder);
            }
            
            // Set up event listeners if they haven't been set up yet
            if (!v.hasAttribute('data-listeners-setup')) {
                v.setAttribute('data-listeners-setup', 'true');
                
                // When video loads, mark tile as loaded to fade placeholder
                v.addEventListener('loadeddata', () => {
                    console.log('Preview video loaded, adding loaded and previewing classes');
                    item.classList.add('loaded');
                    item.classList.add('previewing'); // hide thumbnail overlay
                }, { once: true });
                
                // Add error event listener for debugging
                v.addEventListener('error', (e) => {
                    console.error('Preview video error event:', e);
                    console.error('Preview video error details:', v.error);
                });
                
                // Add loadstart event for debugging
                v.addEventListener('loadstart', () => {
                    console.log('Preview video loadstart event fired');
                });
                
                // Add canplay event for debugging
                v.addEventListener('canplay', () => {
                    console.log('Preview video canplay event fired');
                });
                
                // Add playing event for debugging
                v.addEventListener('playing', () => {
                    console.log('Preview video playing event fired - video should be visible now');
                    // Force a repaint on Android TV
                    v.style.display = 'none';
                    v.offsetHeight; // Force reflow
                    v.style.display = 'block';
                });
            }

            // Build preview video URL from tile data attributes
            const filename = item.getAttribute('data-filename') || '';
            const dirIndex = item.getAttribute('data-dir-index') || '0';
            
            // Use the preview.php endpoint for animated preview loops
            let url = 'preview.php?file=' + encodeURIComponent(filename) + '&dirIndex=' + encodeURIComponent(dirIndex);
            
            // Log the preview video URL being used
            console.log('Setting preview video src to:', url);
            console.log('Using preview.php endpoint for animated preview loops');
            
            if (v.src !== url) {
                v.src = url;
                try { v.load(); } catch (e) { console.error('Preview video load() error:', e); }
            }

            // Configure preview loop - the video will loop automatically
            v.muted = true;
            v.playsInline = true;
            v.loop = true;

            const kick = () => {
                console.log('Kicking preview video playback');
                
                // Try to play with comprehensive error handling
                const playPromise = v.play();
                if (playPromise !== undefined) {
                    playPromise.then(() => {
                        console.log('Preview video play() succeeded');
                        // Force visibility on Android TV after successful play
                        setTimeout(() => {
                            v.style.opacity = '1';
                            v.style.visibility = 'visible';
                            v.style.display = 'block';
                            console.log('Forced preview video visibility on Android TV');
                        }, 100);
                    }).catch((err) => {
                        console.error('Preview video play() failed:', err);
                        console.error('Error name:', err.name);
                        console.error('Error message:', err.message);
                        
                        // Try alternative approaches for tablet browsers
                        if (err.name === 'NotAllowedError') {
                            console.log('Autoplay blocked, trying user interaction workaround');
                            // Some tablets require user interaction before video can play
                            // We'll try to play again after a short delay
                            setTimeout(() => {
                                v.play().catch((retryErr) => {
                                    console.error('Retry preview video play() failed:', retryErr);
                                });
                            }, 100);
                        }
                    });
                }
            };
            
            if (v.readyState >= 1) { 
                console.log('Preview video ready, kicking immediately');
                kick(); 
            }
            else {
                console.log('Preview video not ready, waiting for loadedmetadata');
                v.addEventListener('loadedmetadata', () => { 
                    console.log('Preview video metadata loaded, kicking');
                    kick(); 
                }, { once: true });
                
                // Fallback timeout to force play attempt
                setTimeout(() => { 
                    if (v.readyState < 1) { 
                        console.log('Fallback timeout, attempting to play preview video');
                        v.play().catch((err) => {
                            console.error('Fallback preview video play failed:', err);
                        }); 
                    } 
                }, 150);
            }

            currentPreviewEl = item;
            item.classList.add('previewing');
            console.log('Preview started for item, classes:', item.className);
            console.log('Preview video element:', v);
            console.log('Preview video src:', v.src);
            console.log('Preview video readyState:', v.readyState);
            console.log('Preview video muted:', v.muted);
            console.log('Preview video playsInline:', v.playsInline);
            console.log('Preview video loop:', v.loop);
            console.log('Item DOM structure:', item.innerHTML);
            
            // Log user agent for debugging tablet issues
            console.log('User Agent:', navigator.userAgent);
            console.log('Platform:', navigator.platform);
            
            // Android TV specific logging
            if (navigator.userAgent.includes('Android') && navigator.userAgent.includes('Chrome')) {
                console.log('Android TV detected - applying special rendering fixes');
                // Force immediate visibility for Android TV
                v.style.opacity = '1';
                v.style.visibility = 'visible';
                v.style.display = 'block';
                v.style.zIndex = '999';
            }
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
        
        // Monitor config.json for changes and auto-refresh dashboard
        const configCheckInterval = 5000; // Check every 5 seconds
        
        function checkConfigForChanges() {
            fetch('api.php?action=check_config_changes&' + profileQuery + '&t=' + Date.now())
                .then(response => response.json())
                .then(data => {
                    if (data.needsRefresh) {
                        // auto-refresh on config change
                        location.reload();
                    }
                })
                .catch(() => {});
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
            track.addEventListener('touchstart', (e) => {
                isDragging = true;
                dragStartX = e.touches[0].clientX;
                dragStartScrollLeft = track.scrollLeft;
                track.style.scrollBehavior = 'auto';
                
                lastDragTime = Date.now();
                lastDragX = e.touches[0].clientX;
                dragVelocity = 0;
            });
            
            track.addEventListener('touchmove', (e) => {
                if (!isDragging) return;
                
                e.preventDefault();
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
            });
            
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
        const thumbnailsDisabled = totalVideos > 60; // Disable thumbnails for large collections to speed up load
        
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
                        // Ensure thumbnail overlay is visible unless the tile is explicitly previewing
                        if (!carouselItem.classList.contains('previewing')) {
                            // Remove any stray preview video element to prevent flicker
                            const stray = carouselItem.querySelector('video.lazy-preview-video');
                            if (stray) {
                                try { stray.pause(); } catch {}
                                try { stray.remove(); } catch {}
                            }
                            carouselItem.classList.remove('previewing');
                        }
                        loadedVideos++;
                        updateLoadingProgress();
                        cleanup();
                    };
                    
                    const onError = () => {
                        carouselItem.classList.remove('loading');
                        const playIcon = carouselItem.querySelector('.play-icon');
                        if (playIcon) playIcon.textContent = '❌';
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
        
        // Performance optimization: Limit initial preview video loading
        const maxInitialPreviews = 8; // Lower initial priority load to reduce startup cost
        let initialPreviewCount = 0;
        
        document.querySelectorAll('.carousel-item').forEach((item, index) => {
            const previewVideo = item.querySelector('.lazy-preview-video');
            if (previewVideo && initialPreviewCount < maxInitialPreviews) {
                // Priority load for first preview videos
                item.style.setProperty('--load-priority', 'high');
                initialPreviewCount++;
            } else {
                // Lower priority for later preview videos
                item.style.setProperty('--load-priority', 'low');
            }
        });
        
        // Load current playing video to highlight (only if there's actually a video selected)
        updateCurrentVideoDisplay();
        // Add click event to each item to select video
            document.querySelectorAll('.carousel-item').forEach(item => {
            item.addEventListener('click', () => {
                console.log('Carousel item clicked:', item);
                // If this item is already selected, do nothing to avoid stopping playback
                if (item.classList.contains('playing')) {
                    console.log('Item already playing, ignoring click');
                    return;
                }

                const filename = item.getAttribute('data-filename');
                const dirIndex = parseInt(item.getAttribute('data-dir-index') || '0', 10);
                console.log('Selected video:', filename, 'dirIndex:', dirIndex);
                // Highlight selected
                document.querySelectorAll('.carousel-item.playing').forEach(el => el.classList.remove('playing'));
                item.classList.add('playing');
                // If not playing, show preview loop; otherwise, keep preview off
                if (currentPlaybackState !== 'play') {
                    console.log('Starting preview loop, currentPlaybackState:', currentPlaybackState);
                    startPreviewLoopForItem(item);
                } else {
                    console.log('Video is playing, stopping preview');
                    stopPreview();
                }
                // Send update to server
                const formData = new FormData();
                formData.append('filename', filename);
                formData.append('dirIndex', String(dirIndex));
                fetch('api.php?action=set_current_video&' + profileQuery, {
                    method: 'POST',
                    body: formData
                }).then(res => res.json()).then(() => {
                    // Update current video name with custom title (controls are persistent)
                    updateVideoControlsTitle(filename, dirIndex);

                    // Set playback state to stop when a new video is selected
                    fetch('api.php?action=stop_video&' + profileQuery, { method: 'POST' })
                        .then(res => res.json())
                        .then(() => {
                            updateButtonStates('stop');
                        });
                });
            });
        });
        
        // Control button handlers
        document.getElementById('play-btn').addEventListener('click', () => {
            // Immediate visual feedback
            updateButtonStates('play');
            stopPreview();
            fetch('api.php?action=play_video&' + profileQuery, { method: 'POST' })
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
            fetch('api.php?action=pause_video&' + profileQuery, { method: 'POST' })
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
            fetch('api.php?action=stop_video&' + profileQuery, { method: 'POST' })
                .then(res => res.json())
                .then((data) => {
                    
                    // Then clear the current video selection
                    return fetch('api.php?action=clear_current_video&' + profileQuery, { method: 'POST' });
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
        function pollPlaybackState() {
            fetch('api.php?action=get_playback_state&' + profileQuery)
                .then(res => res.json())
                .then(data => {
                    updateButtonStates(data.state);
                    currentPlaybackState = data.state || 'stop';
                    if (currentPlaybackState === 'play') {
                        stopPreview();
                    } else {
                        const sel = document.querySelector('.carousel-item.playing');
                        if (sel) startPreviewLoopForItem(sel);
                    }
                })
                .catch(() => {});
        }
        
        // Function to update current video display
        function updateCurrentVideoDisplay() {
            fetch('api.php?action=get_current_video&' + profileQuery)
                .then(res => res.json())
                .then(data => {
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
                                        // Only preview when not actively playing
                                        if (currentPlaybackState !== 'play') {
                                            startPreviewLoopForItem(item);
                                        } else {
                                            stopPreview();
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
                .catch(() => {});
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
                else if (state === 'stop' && hasSelected) prefix = 'Selected';
                document.getElementById('current-video-name').textContent = prefix + (displayTitle ? ': ' + displayTitle : '');
            }).catch(() => {
                const fallback = filename ? filename.replace(/\.[^/.]+$/, '') : '';
                document.getElementById('current-video-name').textContent = (fallback ? ('Selected: ' + fallback) : 'No video selected');
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
            fetch('api.php?action=set_volume&' + profileQuery, {
                method: 'POST',
                body: formData
            }).then(res => res.json())
            .then(() => console.log('Volume updated to ' + volume + '%'))
            .catch(err => console.error('Volume update failed:', err));
        });
        
        // Mute button functionality (scoped to current profile)
        muteBtn.addEventListener('click', () => {
            fetch('api.php?action=toggle_mute&' + profileQuery, { method: 'POST' })
                .then(res => res.json())
                .then(data => {
                    updateMuteButton(data.muted);
                })
                .catch(err => console.error('Mute toggle failed:', err));
        });
        
        function updateMuteButton(muted) {
            muteBtn.textContent = muted ? '🔇' : '🔊';
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
        
        // Loop button functionality
        const loopBtn = document.getElementById('loop-btn');
        loopBtn.addEventListener('click', () => {
            fetch('api.php?action=get_loop_mode&' + profileQuery).then(res => res.json()).then(data => {
                const newLoopMode = data.loop === 'on' ? 'off' : 'on';
                const formData = new FormData();
                formData.append('loop', newLoopMode);
                return fetch('api.php?action=set_loop_mode&' + profileQuery, {
                    method: 'POST',
                    body: formData
                });
            }).then(res => res.json()).then(data => {
                updateLoopButton(data.loop);
            }).catch(err => console.error('Loop toggle failed:', err));
        });
        
        // Play All button functionality
        const allBtn = document.getElementById('all-btn');
        allBtn.addEventListener('click', () => {
            fetch('api.php?action=get_play_all_mode&' + profileQuery).then(res => res.json()).then(data => {
                const newPlayAllMode = data.play_all === 'on' ? 'off' : 'on';
                const formData = new FormData();
                formData.append('play_all', newPlayAllMode);
                return fetch('api.php?action=set_play_all_mode&' + profileQuery, {
                    method: 'POST',
                    body: formData
                });
            }).then(res => res.json()).then(data => {
                updatePlayAllButton(data.play_all);
            }).catch(err => console.error('Play All toggle failed:', err));
        });
        
        // Function to update loop button state
        function updateLoopButton(loopMode) {
            loopBtn.textContent = loopMode === 'on' ? '🔁 Loop On' : '🔁 Loop';
            loopBtn.className = loopMode === 'on' ? 'btn primary' : 'btn secondary';
        }
        
        // Function to update play all button state
        function updatePlayAllButton(playAllMode) {
            allBtn.textContent = playAllMode === 'on' ? '📺 Play All On' : '📺 Play All';
            allBtn.className = playAllMode === 'on' ? 'btn primary' : 'btn secondary';
        }
        
        // Load initial loop, play all, and external audio states
        Promise.all([
            fetch('api.php?action=get_loop_mode&' + profileQuery).then(res => res.json()),
            fetch('api.php?action=get_play_all_mode&' + profileQuery).then(res => res.json()),
            fetch('api.php?action=get_external_audio_mode&' + profileQuery).then(res => res.json())
        ]).then(([loopData, playAllData, externalData]) => {
            updateLoopButton(loopData.loop);
            updatePlayAllButton(playAllData.play_all);
            if (typeof setExternalAudioUI === 'function') {
                setExternalAudioUI(externalData.external === 'on');
            }
        }).catch(() => {});
        
        // External audio mode controls
        const externalAudioBtn = document.getElementById('external-audio-btn');
        const externalAudioBanner = document.getElementById('external-audio-banner');
        const externalHelpBtn = document.getElementById('external-help-btn');

        function setExternalAudioUI(isOn) {
            if (!externalAudioBtn) return;
            externalAudioBtn.textContent = isOn ? '🔈 External Audio On' : '🔈 External Audio';
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
                    return fetch('api.php?action=set_external_audio_mode&' + profileQuery, { method: 'POST', body: formData });
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
        // Poll every 1 second to keep buttons in sync
        setInterval(pollPlaybackState, 1000);
        // Poll every 2 seconds to keep current video display in sync
        setInterval(updateCurrentVideoDisplay, 2000);
        
        // Check for refresh signals from admin
        setInterval(checkForRefreshSignal, 3000);
        
        function checkForRefreshSignal() {
            fetch('api.php?action=check_refresh_signal&' + profileQuery).then(res => res.json()).then(data => {
                if (data.should_refresh) {
                    window.location.reload();
                }
            }).catch(() => {});
        }
        

    });
    </script>
</body>
</html>