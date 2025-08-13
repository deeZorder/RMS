<?php
// screen.php
// Large display screen where the selected video is played.

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

// Load configuration to know video directory
$configPath = __DIR__ . '/config.json';
if (file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true);
} else {
$config = [
    'directory' => 'videos'
];
}

// Controls visibility (allow enabling via query param)
$showControls = false; // default: hide controls
if (isset($_GET['controls'])) {
    $val = strtolower((string)$_GET['controls']);
    $showControls = in_array($val, ['1','true','on','yes'], true);
}
if (isset($_GET['nocontrols']) || isset($_GET['hide_controls'])) {
    $showControls = false;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Screen - Relax Media System</title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo filemtime(__DIR__ . '/assets/style.css'); ?>">
</head>
<body>
    <main class="screen-page">
        <video id="playback" class="<?php echo $showControls ? '' : 'video-no-controls'; ?>" <?php echo $showControls ? 'controls' : ''; ?> autoplay playsinline <?php echo $showControls ? '' : 'disablepictureinpicture controlsList="nodownload noplaybackrate noremoteplayback"'; ?>>
            <source id="videoSource" src="" type="video/mp4">
            Your browser does not support the video tag.
        </video>
        <div id="placeholder" class="placeholder" style="display:none;">Select a video from the dashboard to play.</div>
    </main>
    <?php if (!$showControls): ?>
    <style>
      /* Aggressively hide native controls across browsers when controls are disabled */
      video.video-no-controls::-webkit-media-controls { display: none !important; }
      video.video-no-controls::-webkit-media-controls-enclosure { display: none !important; }
      video.video-no-controls::-moz-media-controls { display: none !important; }
    </style>
    <?php endif; ?>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        // Suppress verbose console output in production, but keep warnings and errors
        (function(){
            try {
                const noop = function(){};
                ['log','debug','info','table'].forEach(k => { if (typeof console[k] === 'function') console[k] = noop; });
            } catch(_) {}
        })();
        const videoEl = document.getElementById('playback');
        // Enforce controls visibility based on server-side flag
        const SHOW_CONTROLS = <?php echo $showControls ? 'true' : 'false'; ?>;
        videoEl.controls = SHOW_CONTROLS;
        if (!SHOW_CONTROLS) {
            try {
                // Remove attribute and prevent re-adding
                videoEl.removeAttribute('controls');
                videoEl.setAttribute('disablepictureinpicture', '');
                videoEl.setAttribute('controlsList', 'nodownload noplaybackrate noremoteplayback');
                // Block right-click menu on video
                videoEl.addEventListener('contextmenu', e => e.preventDefault());
                // Block common playback keyboard shortcuts
                const blockedKeys = new Set([' ', 'k','K','j','J','l','L','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','m','M']);
                document.addEventListener('keydown', (e) => {
                    if (blockedKeys.has(e.key)) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                }, true);
                // Prevent default click from toggling playback
                videoEl.addEventListener('click', (e) => { e.preventDefault(); }, true);
                // Mutation observer to ensure controls stay disabled
                const observer = new MutationObserver(mutations => {
                    for (const m of mutations) {
                        if (m.type === 'attributes' && m.attributeName === 'controls') {
                            if (videoEl.hasAttribute('controls')) videoEl.removeAttribute('controls');
                            if (videoEl.controls) videoEl.controls = false;
                        }
                    }
                });
                observer.observe(videoEl, { attributes: true, attributeFilter: ['controls'] });
            } catch (_) {}
        }
        const sourceEl = document.getElementById('videoSource');
        const placeholder = document.getElementById('placeholder');
        let currentVideo = '';

        // Function to update video element with new source
        function loadVideo(filename, shouldPlay = false, dirIndex = 0) {
            if (!filename) {
                videoEl.style.display = 'none';
                placeholder.style.display = 'block';
                videoEl.pause();
                currentVideo = '';
                return;
            }
            // If same video is already playing, do nothing
            if (filename === currentVideo) return;
            const newSrc = 'video.php?file=' + encodeURIComponent(filename) + '&dirIndex=' + dirIndex;
            currentVideo = filename;
            sourceEl.src = newSrc;
            // For compatibility, set video src directly as well
            videoEl.src = newSrc;
            placeholder.style.display = 'none';
            videoEl.style.display = 'block';
            videoEl.load();
            
                    // Ensure loop is disabled when loading new video (will be set by poll function)
        videoEl.loop = false;
            
            // Only play if explicitly requested
            if (shouldPlay) {
                const playVideo = () => {
                    videoEl.play().catch(() => {
                        // Try again after a short delay
                        setTimeout(() => {
                            videoEl.play().catch(() => {
                                // Try one more time with user interaction simulation
                                videoEl.muted = true;
                                videoEl.play().catch(() => {});
                            });
                        }, 100);
                    });
                };
                
                // Try to play immediately
                playVideo();
                
                // Also try when video is loaded
                videoEl.addEventListener('loadeddata', playVideo, { once: true });
            }
            

        }

        // Determine profile from URL: ?d=1 → dashboard1, ?dashboard=... or ?profile=...
        function getProfileQuery() {
            const params = new URLSearchParams(location.search);
            if (params.has('profile')) {
                return 'profile=' + encodeURIComponent(params.get('profile'));
            }
            if (params.has('d')) {
                const n = parseInt(params.get('d') || '0', 10);
                if (n === 0) return 'profile=default';
                if (n >= 1) return 'd=' + String(n);
            }
            if (params.has('dashboard')) {
                return 'profile=' + encodeURIComponent(params.get('dashboard'));
            }
            return 'profile=default';
        }

        // Poll server to check for updates
        let isPolling = false; // Prevent overlapping polls
        let pollTimeoutId = null; // Track timeout ID for cleanup
        let consecutiveErrors = 0; // Track consecutive errors for recovery
        let lastSuccessfulPoll = Date.now(); // Track last successful poll
        
        async function poll() {
            if (isPolling) {
                console.log('Poll already in progress, skipping...');
                return;
            }
            
            isPolling = true;
            const profileQuery = getProfileQuery();
            
            // Clear any existing timeout before setting new one
            if (pollTimeoutId) {
                clearTimeout(pollTimeoutId);
                pollTimeoutId = null;
            }
            
            // Add timeout to prevent hanging requests
            pollTimeoutId = setTimeout(() => {
                isPolling = false;
                pollTimeoutId = null;
                console.log('Poll timeout, resetting...');
                consecutiveErrors++;
                attemptRecovery();
            }, 10000); // 10 second timeout
            
            try {
                // Fetch all data sequentially to avoid overwhelming the server
                const videoRes = await fetch('api.php?action=get_current_video&' + profileQuery);
                const videoData = await videoRes.json();
                
                const stateRes = await fetch('api.php?action=get_playback_state&' + profileQuery);
                const stateData = await stateRes.json();
                
                const volumeRes = await fetch('api.php?action=get_volume&' + profileQuery);
                const volumeData = await volumeRes.json();
                
                const muteRes = await fetch('api.php?action=get_mute_state&' + profileQuery);
                const muteData = await muteRes.json();
                
                const loopRes = await fetch('api.php?action=get_loop_mode&' + profileQuery);
                const loopData = await loopRes.json();
                
                const playAllRes = await fetch('api.php?action=get_play_all_mode&' + profileQuery);
                const playAllData = await playAllRes.json();
                
                const externalRes = await fetch('api.php?action=get_external_audio_mode&' + profileQuery);
                const externalData = await externalRes.json();
                
                // Handle both string and object formats for backward compatibility
                const currentVideoData = videoData.currentVideo;
                const filename = typeof currentVideoData === 'string' ? currentVideoData : (currentVideoData?.filename || '');
                const dirIndex = typeof currentVideoData === 'string' ? 0 : (currentVideoData?.dirIndex || 0);
                const playbackState = stateData.state;
                
                // Only load video if there's a filename, and only play if state is 'play'
                if (filename) {
                    loadVideo(filename, playbackState === 'play', dirIndex);
                    // Ensure video is paused when first loaded (unless explicitly playing)
                    if (playbackState !== 'play') {
                        videoEl.pause();
                    }
                } else {
                    loadVideo(''); // Clear video
                }
                
                // Handle playback controls for already loaded video
                if (filename && videoEl.src && currentVideo === filename) {
                    switch (playbackState) {
                        case 'play':
                            videoEl.play().catch(err => console.log('Play failed:', err));
                            // Enter fullscreen when play is requested (only once)
                            if (!window.fullscreenAttempted) {
                                // Use a single timeout instead of multiple
                                const fullscreenTimeout = setTimeout(() => {
                                    enterFullscreen();
                                    window.fullscreenAttempted = true;
                                }, 50);
                                // Store timeout ID for cleanup
                                window.fullscreenTimeoutId = fullscreenTimeout;
                            }
                            break;
                        case 'pause':
                            videoEl.pause();
                            break;
                        case 'stop':
                            videoEl.pause();
                            videoEl.currentTime = 0;
                            break;
                    }
                } else if (filename && playbackState === 'play') {
                    // If this is a new video that should be playing, enter fullscreen after loading
                    if (!window.fullscreenAttempted) {
                        const fullscreenTimeout = setTimeout(() => {
                            if (!videoEl.paused && !videoEl.muted) {
                                enterFullscreen();
                                window.fullscreenAttempted = true;
                            }
                        }, 1000);
                        // Store timeout ID for cleanup
                        window.fullscreenTimeoutId = fullscreenTimeout;
                    }
                }
                
                // Apply volume setting
                if (volumeData.volume !== undefined) {
                    videoEl.volume = volumeData.volume / 100;
                }
                
                // Apply mute setting (force mute if external audio mode is on)
                if (muteData.muted !== undefined) {
                    const forceMute = externalData && externalData.external === 'on';
                    videoEl.muted = forceMute ? true : muteData.muted;
                }
                
                // Apply loop setting (disable video loop if play all is enabled)
                if (loopData.loop !== undefined && playAllData.play_all !== undefined) {
                    // Only enable video loop if play all is disabled
                    const shouldLoop = (loopData.loop === 'on' && playAllData.play_all === 'off');
                    console.log('🎯 Loop setting - loop mode:', loopData.loop, 'play all mode:', playAllData.play_all, 'should loop:', shouldLoop);
                    console.log('🎯 Setting videoEl.loop to:', shouldLoop);
                    videoEl.loop = shouldLoop;
                    console.log('🎯 videoEl.loop is now:', videoEl.loop);
                }
                
                // Reset error counter on successful poll
                consecutiveErrors = 0;
                lastSuccessfulPoll = Date.now();
                
                // Clear timeout and reset polling flag on success
                if (pollTimeoutId) {
                    clearTimeout(pollTimeoutId);
                    pollTimeoutId = null;
                }
                isPolling = false;
                
            } catch (err) { 
                console.error('Poll error:', err);
                consecutiveErrors++;
                
                // Clear timeout and reset polling flag on error
                if (pollTimeoutId) {
                    clearTimeout(pollTimeoutId);
                    pollTimeoutId = null;
                }
                isPolling = false;
                
                // Add error recovery - reset fullscreen flag on errors
                window.fullscreenAttempted = false;
                
                // Attempt recovery if we have multiple consecutive errors
                if (consecutiveErrors >= 3) {
                    attemptRecovery();
                }
            }
        }
        
        // Function to attempt recovery when API calls are failing
        function attemptRecovery() {
            console.log('🔄 Attempting API recovery...');
            
            // Test health endpoint first
            fetch('api.php?action=health')
                .then(res => res.json())
                .then(health => {
                    console.log('✅ Health check successful:', health);
                    consecutiveErrors = 0;
                    
                    // If health is good but we still have issues, try a fresh poll
                    if (Date.now() - lastSuccessfulPoll > 30000) { // 30 seconds
                        console.log('🔄 Attempting fresh poll after recovery...');
                        setTimeout(poll, 1000);
                    }
                })
                .catch(err => {
                    console.error('❌ Health check failed:', err);
                    // If health check fails, the server might be down
                    // Wait longer before retrying
                    setTimeout(() => {
                        console.log('🔄 Retrying after health check failure...');
                        consecutiveErrors = Math.max(0, consecutiveErrors - 1);
                    }, 30000); // Wait 30 seconds
                });
        }
        
        // Function to detect and fix memory leaks
        function detectMemoryLeaks() {
            // Check for stuck intervals
            if (isVolumePolling && !volumePollInterval) {
                console.warn('🚨 Memory leak detected: volume polling stuck');
                isVolumePolling = false;
            }
            if (isMutePolling && !mutePollInterval) {
                console.warn('🚨 Memory leak detected: mute polling stuck');
                isMutePolling = false;
            }
            if (isPolling && !pollTimeoutId) {
                console.warn('🚨 Memory leak detected: main polling stuck');
                isPolling = false;
            }
            
            // Check for stuck fullscreen timeouts
            if (window.fullscreenTimeoutId && !window.fullscreenAttempted) {
                console.warn('🚨 Memory leak detected: fullscreen timeout stuck');
                clearTimeout(window.fullscreenTimeoutId);
                window.fullscreenTimeoutId = null;
            }
            
            // Enhanced memory monitoring with fallback
            let memoryInfo = null;
            
            // Try multiple memory monitoring approaches
            if (console.memory) {
                try {
                    const mem = console.memory;
                    const usedBytes = mem.usedJSHeapSize || 0;
                    const totalBytes = mem.totalJSHeapSize || 0;
                    const limitBytes = mem.jsHeapSizeLimit || 0;
                    
                    // Only use if values seem reasonable (not 0 or extremely small)
                    if (usedBytes > 1024 && totalBytes > 1024 && limitBytes > 1024) {
                        memoryInfo = {
                            used: Math.round(usedBytes / 1024 / 1024),
                            total: Math.round(totalBytes / 1024 / 1024),
                            limit: Math.round(limitBytes / 1024 / 1024),
                            source: 'console.memory'
                        };
                    }
                } catch (e) {
                    console.log('console.memory error:', e);
                }
            }
            
            // Fallback: estimate memory usage based on performance
            if (!memoryInfo) {
                try {
                    const startTime = performance.now();
                    // Create a small object to measure memory pressure
                    const testArray = new Array(1000).fill('test');
                    const endTime = performance.now();
                    const timeDiff = endTime - startTime;
                    
                    // Rough estimate based on performance (not accurate but gives relative sense)
                    memoryInfo = {
                        used: Math.round(timeDiff * 10), // Rough estimate
                        total: 100, // Placeholder
                        limit: 100, // Placeholder
                        source: 'performance.estimate',
                        note: 'Rough estimate - actual values may vary'
                    };
                    
                    // Clean up test array
                    testArray.length = 0;
                } catch (e) {
                    console.log('Performance estimate error:', e);
                }
            }
            
            // Log memory status
            if (memoryInfo) {
                const usagePercent = memoryInfo.limit > 0 ? Math.round((memoryInfo.used / memoryInfo.limit) * 100) : 0;
                
                console.log('📊 Memory Status:', {
                    used: memoryInfo.used + 'MB',
                    total: memoryInfo.total + 'MB', 
                    limit: memoryInfo.limit + 'MB',
                    usage: usagePercent + '%',
                    source: memoryInfo.source,
                    timestamp: new Date().toISOString()
                });
                
                // Check for critical memory usage
                if (memoryInfo.used > 100 && memoryInfo.source === 'console.memory') {
                    console.error('🚨 Critical memory usage:', memoryInfo.used + 'MB used');
                    
                    // Force cleanup and restart polling
                    cleanup();
                    setTimeout(() => {
                        startVolumePolling();
                        startMutePolling();
                        poll();
                    }, 1000);
                }
            } else {
                console.log('📊 Memory monitoring not available');
            }
            
            // Check for excessive DOM nodes or event listeners
            const videoCount = document.querySelectorAll('video').length;
            const audioCount = document.querySelectorAll('audio').length;
            const iframeCount = document.querySelectorAll('iframe').length;
            
            if (videoCount > 1 || audioCount > 1 || iframeCount > 1) {
                console.warn('🚨 Multiple media elements detected:', {
                    videos: videoCount,
                    audios: audioCount,
                    iframes: iframeCount
                });
            }
            
            // Check for stuck network requests
            const now = Date.now();
            const timeSinceLastPoll = now - lastSuccessfulPoll;
            if (timeSinceLastPoll > 60000) { // More than 1 minute
                console.warn('🚨 No successful polls in', Math.round(timeSinceLastPoll / 1000), 'seconds');
                
                // Force a fresh poll
                if (!isPolling) {
                    console.log('🔄 Forcing fresh poll due to inactivity...');
                    poll();
                }
            }
        }
        
        // Function to test for memory leaks
        function testMemoryLeaks() {
            console.log('🧪 Running memory leak test...');
            
            // Test 1: Check if intervals are properly managed
            console.log('📊 Interval Status:', {
                volumePollInterval: !!volumePollInterval,
                mutePollInterval: !!mutePollInterval,
                pollInterval: !!pollInterval,
                isVolumePolling,
                isMutePolling,
                isPolling
            });
            
            // Test 2: Check if timeouts are properly managed
            console.log('📊 Timeout Status:', {
                pollTimeoutId: !!pollTimeoutId,
                fullscreenTimeoutId: !!window.fullscreenTimeoutId,
                fullscreenAttempted: window.fullscreenAttempted
            });
            
            // Test 3: Check memory usage
            if (console.memory) {
                const mem = console.memory;
                console.log('📊 Memory Status:', {
                    used: Math.round(mem.usedJSHeapSize / 1024 / 1024) + 'MB',
                    total: Math.round(mem.totalJSHeapSize / 1024 / 1024) + 'MB',
                    limit: Math.round(mem.jsHeapSizeLimit / 1024 / 1024) + 'MB'
                });
            }
            
            // Test 4: Check DOM elements
            const videoCount = document.querySelectorAll('video').length;
            const audioCount = document.querySelectorAll('audio').length;
            console.log('📊 DOM Elements:', { videos: videoCount, audios: audioCount });
            
            // Test 5: Check if any fetch requests are hanging
            console.log('📊 Fetch Status:', {
                activeRequests: window.activeRequests || 0,
                lastPollTime: new Date(lastSuccessfulPoll).toISOString(),
                consecutiveErrors
            });
        }
        
        // Expose test function globally for debugging
        window.testMemoryLeaks = testMemoryLeaks;
        window.forceCleanup = cleanup;
        
        // Initial poll
        poll();
        // Poll every 3 seconds instead of 2 to reduce load
        const pollInterval = setInterval(poll, 3000);

        // Fast volume update path: long-poll for volume signal and apply immediately
        let volumePollInterval = null;
        let isVolumePolling = false;
        
        function startVolumePolling() {
            if (volumePollInterval) {
                clearInterval(volumePollInterval);
                volumePollInterval = null;
            }
            
            if (isVolumePolling) {
                return; // Already polling
            }
            
            isVolumePolling = true;
            const profileQuery = getProfileQuery();
            
            // Use setInterval instead of recursive setTimeout to prevent memory leaks
            volumePollInterval = setInterval(async () => {
                try {
                    const res = await fetch('api.php?action=check_volume_signal&' + profileQuery);
                    const sig = await res.json();
                    
                    // When there's a (new) signal timestamp, fetch latest volume immediately
                    if (sig && sig.volume_signal) {
                        try {
                            const volRes = await fetch('api.php?action=get_volume&' + profileQuery);
                            const volData = await volRes.json();
                            if (volData && typeof volData.volume === 'number') {
                                videoEl.volume = volData.volume / 100;
                            }
                        } catch (volErr) {
                            console.log('Volume fetch error:', volErr);
                        }
                    }
                } catch (err) {
                    console.log('Volume signal check error:', err);
                    // Stop polling on error to prevent infinite error loops
                    stopVolumePolling();
                }
            }, 400); // ~2.5x per second
        }
        
        function stopVolumePolling() {
            if (volumePollInterval) {
                clearInterval(volumePollInterval);
                volumePollInterval = null;
            }
            isVolumePolling = false;
        }
        
        // Start volume polling
        startVolumePolling();

        // Fast mute update path
        let mutePollInterval = null;
        let isMutePolling = false;
        
        function startMutePolling() {
            if (mutePollInterval) {
                clearInterval(mutePollInterval);
                mutePollInterval = null;
            }
            
            if (isMutePolling) {
                return; // Already polling
            }
            
            isMutePolling = true;
            const profileQuery = getProfileQuery();
            
            // Use setInterval instead of recursive setTimeout to prevent memory leaks
            mutePollInterval = setInterval(async () => {
                try {
                    const res = await fetch('api.php?action=check_mute_signal&' + profileQuery);
                    const sig = await res.json();
                    
                    if (sig && sig.mute_signal) {
                        try {
                            const [muteRes, externalRes] = await Promise.all([
                                fetch('api.php?action=get_mute_state&' + profileQuery),
                                fetch('api.php?action=get_external_audio_mode&' + profileQuery)
                            ]);
                            
                            const muteData = await muteRes.json();
                            const externalData = await externalRes.json();
                            
                            const forceMute = externalData && externalData.external === 'on';
                            if (muteData && typeof muteData.muted !== 'undefined') {
                                videoEl.muted = forceMute ? true : !!muteData.muted;
                            }
                        } catch (muteErr) {
                            console.log('Mute fetch error:', muteErr);
                        }
                    }
                } catch (err) {
                    console.log('Mute signal check error:', err);
                    // Stop polling on error to prevent infinite error loops
                    stopMutePolling();
                }
            }, 400);
        }
        
        function stopMutePolling() {
            if (mutePollInterval) {
                clearInterval(mutePollInterval);
                mutePollInterval = null;
            }
            isMutePolling = false;
        }
        
        // Start mute polling
        startMutePolling();
        
        // Try immediate fullscreen for TV/monitor browsers (only once)
        if (!window.fullscreenAttempted) {
            setTimeout(() => {
                console.log('Attempting immediate fullscreen for TV/monitor');
                enterFullscreen();
                window.fullscreenAttempted = true;
            }, 1000);
        }
        
        // Try to enter fullscreen on page load if video is playing (for TV/monitor browsers)
        setTimeout(() => {
            // Check if there's a video playing and try to enter fullscreen
            fetch('api.php?action=get_playback_state&' + profileQuery).then(res => res.json()).then(data => {
                if (data.state === 'play' && currentVideo && !window.fullscreenAttempted) {
                    console.log('Video is playing, attempting fullscreen for TV/monitor');
                    // Only try once to prevent multiple attempts
                    setTimeout(enterFullscreen, 1000);
                    window.fullscreenAttempted = true;
                }
            }).catch(err => console.log('Failed to check initial playback state:', err));
        }, 2000); // Wait 2 seconds after page load
        
        // Try to play video when window gains focus (helps with second monitor)
        window.addEventListener('focus', () => {
            if (currentVideo && videoEl.paused) {
                videoEl.play().catch(err => console.log('Focus autoplay failed:', err));
            }
        });
        
        // Also try when the page becomes visible
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && currentVideo && videoEl.paused) {
                videoEl.play().catch(err => console.log('Visibility autoplay failed:', err));
            }
        });
        
        // Handle video end event for play all mode
        videoEl.addEventListener('ended', () => {
            console.log('🎬 VIDEO ENDED EVENT FIRED');
            console.log('Current video element loop property:', videoEl.loop);
            console.log('Current video filename:', currentVideo);
            console.log('Video element src:', videoEl.src);
            console.log('Video element currentTime:', videoEl.currentTime);
            console.log('Video element duration:', videoEl.duration);
            
            // Get profile query for this event handler
            const profileQuery = getProfileQuery();
            console.log('🎯 Profile query for ended event:', profileQuery);
            
            // Check both play all mode and loop mode
            Promise.all([
                fetch('api.php?action=get_play_all_mode&' + profileQuery).then(res => res.json()),
                fetch('api.php?action=get_loop_mode&' + profileQuery).then(res => res.json())
            ]).then(([playAllData, loopData]) => {
                console.log('Play all mode:', playAllData.play_all, 'Loop mode:', loopData.loop);
                console.log('Video element loop property after check:', videoEl.loop);
                
                if (playAllData.play_all === 'on') {
                    console.log('🚀 Play all mode enabled, checking if we should move to next video');
                    // Get the next video in the playlist
                    fetch('api.php?action=get_next_video&' + profileQuery).then(res => res.json()).then(nextData => {
                        console.log('📺 Next video API response:', nextData);
                        // Handle both string and object formats for backward compatibility
                        const nextVideoData = nextData.nextVideo;
                        const nextVideoName = typeof nextVideoData === 'string' ? nextVideoData : (nextVideoData?.name || '');
                        const nextVideoDirIndex = typeof nextVideoData === 'string' ? 0 : (nextVideoData?.dirIndex || 0);
                        
                        console.log('🔄 Next video:', nextVideoName, 'Current video:', currentVideo);
                        if (nextVideoName && nextVideoName !== currentVideo) {
                            console.log('✅ Moving to next video:', nextVideoName);
                            // Set the next video as current
                            const formData = new FormData();
                            formData.append('filename', nextVideoName);
                            formData.append('dirIndex', nextVideoDirIndex);
                            fetch('api.php?action=set_current_video&' + profileQuery, {
                                method: 'POST',
                                body: formData
                            }).then(() => {
                                console.log('✅ Current video updated, loading next video');
                                // Load and play the next video
                                loadVideo(nextVideoName, true, nextVideoDirIndex);
                            });
                        } else {
                            console.log('⏹️ No next video available or reached end of playlist');
                            // Reached end of playlist, stop playback
                            fetch('api.php?action=stop_video&' + profileQuery, { method: 'POST' })
                                .then(res => res.json())
                                .then(() => {
                                    console.log('⏹️ Playback stopped at end of playlist');
                                    videoEl.pause();
                                    videoEl.currentTime = 0;
                                })
                                .catch(err => console.log('❌ Failed to set stop state on end:', err));
                        }
                    }).catch(err => console.log('❌ Failed to get next video:', err));
                } else if (loopData.loop === 'on') {
                    console.log('🔄 Loop mode enabled, restarting current video');
                    // Just restart the current video (loop is handled by video element)
                    videoEl.currentTime = 0;
                    videoEl.play().catch(err => console.log('❌ Loop restart failed:', err));
                } else {
                    console.log('⏹️ No loop or play all enabled, video will stop');
                    // Ensure global playback state reflects stop to avoid accidental restarts
                    fetch('api.php?action=stop_video&' + profileQuery, { method: 'POST' })
                        .then(res => res.json())
                        .then(() => {
                            console.log('⏹️ Playback stopped (no loop/play all)');
                            // Reset the player UI locally as well
                            videoEl.pause();
                            videoEl.currentTime = 0;
                        })
                        .catch(err => console.log('❌ Failed to set stop state on end:', err));
                }
            }).catch(err => console.log('Failed to check play all/loop mode:', err));
        });
        
        // Function to show fullscreen prompt
        function showFullscreenPrompt() {
            if (document.fullscreenElement) {
                return; // Already in fullscreen
            }
            
            // Create fullscreen prompt overlay
            const prompt = document.createElement('div');
            prompt.id = 'fullscreen-prompt';
            prompt.innerHTML = `
                <div class="fullscreen-prompt-content">
                    <h3>🎬 Video Ready</h3>
                    <p>Click anywhere to enter fullscreen mode</p>
                    <button class="btn primary" onclick="enterFullscreenFromPrompt()">Enter Fullscreen</button>
                    <button class="btn secondary" onclick="dismissFullscreenPrompt()">Skip</button>
                </div>
            `;
            document.body.appendChild(prompt);
            
            // Auto-hide after 10 seconds
            setTimeout(() => {
                if (document.getElementById('fullscreen-prompt')) {
                    dismissFullscreenPrompt();
                }
            }, 10000);
        }
        
        // Function to dismiss fullscreen prompt
        function dismissFullscreenPrompt() {
            const prompt = document.getElementById('fullscreen-prompt');
            if (prompt) {
                prompt.remove();
            }
        }
        
        // Function to enter fullscreen from prompt (global function)
        window.enterFullscreenFromPrompt = function() {
            dismissFullscreenPrompt();
            enterFullscreen();
        };
        
        // Function to enter fullscreen (optimized for TV/monitor browsers)
        function enterFullscreen() {
            if (document.fullscreenElement) {
                return; // Already in fullscreen
            }
            
            console.log('Attempting fullscreen for TV/monitor browser');
            
            // Try different fullscreen methods for browser compatibility
            if (videoEl.requestFullscreen) {
                videoEl.requestFullscreen().catch(err => console.log('Standard fullscreen failed:', err));
            } else if (videoEl.webkitRequestFullscreen) {
                videoEl.webkitRequestFullscreen().catch(err => console.log('Webkit fullscreen failed:', err));
            } else if (videoEl.webkitEnterFullscreen) {
                videoEl.webkitEnterFullscreen().catch(err => console.log('Webkit enter fullscreen failed:', err));
            } else if (videoEl.mozRequestFullScreen) {
                videoEl.mozRequestFullScreen().catch(err => console.log('Mozilla fullscreen failed:', err));
            } else if (videoEl.msRequestFullscreen) {
                videoEl.msRequestFullscreen().catch(err => console.log('MS fullscreen failed:', err));
            } else {
                console.log('Fullscreen not supported, trying document fullscreen');
                // Try document fullscreen as fallback
                if (document.documentElement.requestFullscreen) {
                    document.documentElement.requestFullscreen().catch(err => console.log('Document fullscreen failed:', err));
                } else if (document.documentElement.webkitRequestFullscreen) {
                    document.documentElement.webkitRequestFullscreen().catch(err => console.log('Document webkit fullscreen failed:', err));
                }
            }
        }
        
        // Function to exit fullscreen
        function exitFullscreen() {
            if (document.exitFullscreen) {
                document.exitFullscreen().catch(err => console.log('Exit fullscreen failed:', err));
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen().catch(err => console.log('Exit webkit fullscreen failed:', err));
            } else if (document.mozCancelFullScreen) {
                document.mozCancelFullScreen().catch(err => console.log('Exit mozilla fullscreen failed:', err));
            } else if (document.msExitFullscreen) {
                document.msExitFullscreen().catch(err => console.log('Exit MS fullscreen failed:', err));
            }
        }
        
        // Keyboard shortcut for fullscreen (F11 or F key)
        document.addEventListener('keydown', (e) => {
            if (e.key === 'F11' || e.key === 'f' || e.key === 'F') {
                e.preventDefault();
                if (document.fullscreenElement) {
                    exitFullscreen();
                } else {
                    enterFullscreen();
                }
            }
        });
        
        // Try to enter fullscreen when video starts playing (for TV/monitor browsers)
        videoEl.addEventListener('play', () => {
            // Only try fullscreen if video is actually playing and not muted
            if (!videoEl.paused && !videoEl.muted && !window.fullscreenAttempted) {
                // Only try once to prevent multiple attempts
                setTimeout(enterFullscreen, 500);
                window.fullscreenAttempted = true;
            }
        });
        
        // Try to enter fullscreen when video is loaded and ready (for TV/monitor browsers)
        videoEl.addEventListener('loadeddata', () => {
            // Check if video should be playing
            fetch('api.php?action=get_playback_state&' + profileQuery).then(res => res.json()).then(data => {
                if (data.state === 'play' && !videoEl.paused && !videoEl.muted && !window.fullscreenAttempted) {
                    console.log('Video loaded and should be playing, attempting fullscreen for TV/monitor');
                    setTimeout(enterFullscreen, 300);
                    window.fullscreenAttempted = true;
                }
            }).catch(err => console.log('Failed to check playback state on video load:', err));
        });
        
        // Click to toggle fullscreen
        videoEl.addEventListener('click', () => {
            if (document.fullscreenElement) {
                exitFullscreen();
            } else {
                enterFullscreen();
            }
        });
        
        // Also allow clicking anywhere on the page to enter fullscreen (for initial load)
        document.addEventListener('click', () => {
            // Only try to enter fullscreen if video is playing and not already in fullscreen
            if (currentVideo && !videoEl.paused && !document.fullscreenElement) {
                console.log('Page clicked, entering fullscreen');
                setTimeout(enterFullscreen, 100);
            }
        }, { once: true }); // Only trigger once per page load
        
        // Cleanup function to prevent memory leaks
        function cleanup() {
            // Clear all intervals
            if (volumePollInterval) {
                clearInterval(volumePollInterval);
                volumePollInterval = null;
            }
            if (mutePollInterval) {
                clearInterval(mutePollInterval);
                mutePollInterval = null;
            }
            if (pollInterval) {
                clearInterval(pollInterval);
            }
            
            // Clear all timeouts
            if (pollTimeoutId) {
                clearTimeout(pollTimeoutId);
                pollTimeoutId = null;
            }
            if (window.fullscreenTimeoutId) {
                clearTimeout(window.fullscreenTimeoutId);
                window.fullscreenTimeoutId = null;
            }
            
            // Reset all flags
            isPolling = false;
            isVolumePolling = false;
            isMutePolling = false;
            window.fullscreenAttempted = false;
            
            // Force garbage collection if available
            if (window.gc) {
                window.gc();
                console.log('🧹 Garbage collection triggered');
            }
            
            console.log('🧹 Cleanup completed');
        }
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', cleanup);
        
        // Cleanup on page visibility change (when tab becomes hidden)
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // Pause polling when page is hidden to save resources
                if (volumePollInterval) {
                    clearInterval(volumePollInterval);
                    volumePollInterval = null;
                }
                if (mutePollInterval) {
                    clearInterval(mutePollInterval);
                    mutePollInterval = null;
                }
                if (pollTimeoutId) {
                    clearTimeout(pollTimeoutId);
                    pollTimeoutId = null;
                }
            } else {
                // Resume polling when page becomes visible
                if (!volumePollInterval) startVolumePolling();
                if (!mutePollInterval) startMutePolling();
                if (!pollTimeoutId && !isPolling) poll(); // Resume polling if it was paused
            }
        });
        
        // Periodic cleanup to prevent timeout accumulation
        setInterval(() => {
            try {
                // Reset fullscreen flag periodically
                if (document.fullscreenElement) {
                    window.fullscreenAttempted = true;
                } else {
                    // Reset flag every 15 seconds if not in fullscreen
                    window.fullscreenAttempted = false;
                }
                
                // Force cleanup of any stuck polling states
                if (isPolling && !pollTimeoutId) {
                    console.log('Detected stuck polling state, resetting...');
                    isPolling = false;
                }
                if (isVolumePolling && !volumePollInterval) {
                    console.log('Detected stuck volume polling state, resetting...');
                    isVolumePolling = false;
                }
                if (isMutePolling && !mutePollInterval) {
                    console.log('Detected stuck mute polling state, resetting...');
                    isMutePolling = false;
                }
                
                // Run memory leak detection
                detectMemoryLeaks();
                
                // Memory monitoring (only in development)
                if (console.memory) {
                    const mem = console.memory;
                    if (mem.usedJSHeapSize > 50 * 1024 * 1024) { // 50MB threshold
                        console.warn('High memory usage detected:', 
                            Math.round(mem.usedJSHeapSize / 1024 / 1024) + 'MB used,',
                            Math.round(mem.totalJSHeapSize / 1024 / 1024) + 'MB total');
                        
                        // Force garbage collection if available
                        if (window.gc) {
                            window.gc();
                            console.log('Garbage collection triggered');
                        }
                    }
                }
            } catch (error) {
                console.error('Error in periodic cleanup:', error);
                // Force cleanup on error
                cleanup();
            }
        }, 15000); // Run every 15 seconds instead of 30
        
        // Additional memory leak prevention: clear intervals on page unload
        window.addEventListener('beforeunload', () => {
            // Force cleanup of all intervals and timeouts
            if (volumePollInterval) {
                clearInterval(volumePollInterval);
                volumePollInterval = null;
            }
            if (mutePollInterval) {
                clearInterval(mutePollInterval);
                mutePollInterval = null;
            }
            if (pollInterval) {
                clearInterval(pollInterval);
            }
            if (pollTimeoutId) {
                clearTimeout(pollTimeoutId);
                pollTimeoutId = null;
            }
            
            // Reset all flags
            isPolling = false;
            isVolumePolling = false;
            isMutePolling = false;
            window.fullscreenAttempted = false;
            
            console.log('Page unload cleanup completed');
        });

        // Network request tracking
        let activeRequests = 0;
        let requestStartTimes = new Map();
        
        // Override fetch to track requests
        const originalFetch = window.fetch;
        window.fetch = function(...args) {
            const requestId = Math.random().toString(36).substr(2, 9);
            const startTime = Date.now();
            
            activeRequests++;
            requestStartTimes.set(requestId, startTime);
            
            console.log(`🌐 Fetch request started: ${args[0]} (${activeRequests} active)`);
            
            return originalFetch.apply(this, args)
                .then(response => {
                    activeRequests--;
                    requestStartTimes.delete(requestId);
                    const duration = Date.now() - startTime;
                    console.log(`✅ Fetch completed: ${args[0]} in ${duration}ms (${activeRequests} active)`);
                    return response;
                })
                .catch(error => {
                    activeRequests--;
                    requestStartTimes.delete(requestId);
                    const duration = Date.now() - startTime;
                    console.error(`❌ Fetch failed: ${args[0]} after ${duration}ms (${activeRequests} active)`, error);
                    throw error;
                });
        };
        
        // Check for hanging requests
        setInterval(() => {
            if (activeRequests > 0) {
                const now = Date.now();
                const hangingRequests = [];
                
                requestStartTimes.forEach((startTime, requestId) => {
                    const duration = now - startTime;
                    if (duration > 30000) { // 30 seconds
                        hangingRequests.push({ requestId, duration });
                    }
                });
                
                if (hangingRequests.length > 0) {
                    console.warn('🚨 Hanging requests detected:', hangingRequests);
                    
                    // If we have hanging requests for too long, force cleanup
                    if (hangingRequests.some(req => req.duration > 60000)) { // 1 minute
                        console.error('🚨 Critical: Requests hanging for over 1 minute, forcing cleanup');
                        cleanup();
                        
                        // Reset all request tracking
                        activeRequests = 0;
                        requestStartTimes.clear();
                    }
                }
            }
        }, 10000); // Check every 10 seconds

        // Health check monitoring
        let lastHealthCheck = Date.now();
        let healthCheckInterval = null;
        
        function startHealthMonitoring() {
            if (healthCheckInterval) {
                clearInterval(healthCheckInterval);
            }
            
            healthCheckInterval = setInterval(async () => {
                try {
                    const startTime = Date.now();
                    const response = await fetch('api.php?action=health');
                    const health = await response.json();
                    const duration = Date.now() - startTime;
                    
                    lastHealthCheck = Date.now();
                    console.log(`💚 Health check OK: ${duration}ms`, health);
                    
                    // If health check takes too long, it might indicate server issues
                    if (duration > 5000) { // 5 seconds
                        console.warn('⚠️ Health check slow:', duration + 'ms');
                    }
                    
                } catch (error) {
                    console.error('💔 Health check failed:', error);
                    
                    // If health check fails multiple times, force recovery
                    const timeSinceLastHealth = Date.now() - lastHealthCheck;
                    if (timeSinceLastHealth > 30000) { // 30 seconds
                        console.error('🚨 Health check failed for too long, forcing recovery');
                        attemptRecovery();
                    }
                }
            }, 20000); // Check every 20 seconds
        }
        
        // Start health monitoring
        startHealthMonitoring();
    });
    </script>
</body>
</html>