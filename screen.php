<?php
// screen.php
// Large display screen where the selected video is played.

// Include shared state management
require_once __DIR__ . '/state_manager.php';

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
    <!-- External CSS file with all screen-specific styles -->
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
    <!-- Controls are disabled - styles handled by external CSS -->
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
        
        // Screen timeout variables
        let screenTimeoutId = null;
        let isScreenOff = false;

        // Function to start screen timeout when no video is playing
        function startScreenTimeout() {
            // Clear any existing timeouts
            if (screenTimeoutId) clearTimeout(screenTimeoutId);
            
            // Show placeholder
            showPlaceholder();
            
            // Turn off screen after 30 seconds total
            screenTimeoutId = setTimeout(() => {
                if (!currentVideo && !isScreenOff) {
                    turnOffScreen();
                }
            }, 30000);
        }
        
        // Function to turn off screen
        function turnOffScreen() {
            isScreenOff = true;
            
            // Add CSS classes for black screen - CSS handles all the styling
            document.body.classList.add('screen-off');
            document.documentElement.classList.add('screen-off');
            
            // Clear any remaining timeouts
            if (screenTimeoutId) clearTimeout(screenTimeoutId);
            
            console.log('🖥️ Screen should now be completely black');
        }
        
        // Function to reset screen timeout
        function resetScreenTimeout() {
            if (screenTimeoutId) clearTimeout(screenTimeoutId);
            isScreenOff = false;
            
            // Remove CSS classes - CSS handles all the styling restoration
            document.body.classList.remove('screen-off');
            document.documentElement.classList.remove('screen-off');
        }
        
        // Function to update video element with new source
        function loadVideo(filename, shouldPlay = false, dirIndex = 0) {
            if (!filename) {
                videoEl.style.display = 'none';
                showPlaceholder(); // Use the new function
                videoEl.pause();
                currentVideo = '';
                // Start screen timeout when no video is loaded
                startScreenTimeout();
                return;
            }
            // If same video is already loaded and playing state hasn't changed, do nothing
            if (filename === currentVideo && !shouldPlay) {
                return;
            }
            const newSrc = 'video.php?file=' + encodeURIComponent(filename) + '&dirIndex=' + dirIndex;
            currentVideo = filename;
            sourceEl.src = newSrc;
            // For compatibility, set video src directly as well
            videoEl.src = newSrc;
            
            // Ensure video is visible - CSS classes handle the styling
            hidePlaceholderCompletely(); // Hide placeholder completely
            videoEl.style.display = 'block';
            videoEl.style.visibility = 'visible';
            videoEl.style.opacity = '1';
            
            videoEl.load();
            
            // Only reset screen timeout if this is a new video or if we're explicitly playing
            if (shouldPlay) {
                resetScreenTimeout();
                // Ensure placeholder is completely hidden when video is playing
                hidePlaceholderCompletely();
            } else {
                // If video is loaded but not playing, start screen timeout
                startScreenTimeout();
            }
            
            // Ensure loop is disabled when loading new video (will be set by poll function)
            videoEl.loop = false;
            
            // Only play if explicitly requested
            if (shouldPlay) {
                const playVideo = () => {
                    videoEl.play().catch((err) => {
                        // Try again after a short delay
                        setTimeout(() => {
                            videoEl.play().catch((err2) => {
                                // Try one more time with user interaction simulation
                                videoEl.muted = true;
                                videoEl.play().catch((err3) => {});
                            });
                        }, 100);
                    });
                };
                
                // Try to play immediately
                playVideo();
                
                // Also try when video is loaded
                videoEl.addEventListener('loadeddata', () => {
                    playVideo();
                }, { once: true });
            }
        }
        
        // Function to completely hide the placeholder and counter
        function hidePlaceholderCompletely() {
            if (placeholder) {
                // Apply CSS class for hiding - CSS handles all the styling
                placeholder.classList.add('video-playing-hidden');
                
                // Force a reflow to ensure styles are applied
                placeholder.offsetHeight;
            }
        }
        
        // Function to show the placeholder when needed
        function showPlaceholder() {
            if (placeholder) {
                // Remove the hiding CSS class - CSS handles all the styling restoration
                placeholder.classList.remove('video-playing-hidden');
                
                // Force a reflow to ensure styles are applied
                placeholder.offsetHeight;
            }
        }

        // Determine profile from URL: ?d=1 → dashboard1, ?dashboard=... or ?profile=...
        function getProfileQuery() {
            const params = new URLSearchParams(location.search);
            let profileQuery = 'profile=default';
            
            if (params.has('profile')) {
                profileQuery = 'profile=' + encodeURIComponent(params.get('profile'));
            } else if (params.has('d')) {
                const n = parseInt(params.get('d') || '0', 10);
                if (n === 0) {
                    profileQuery = 'profile=default';
                } else if (n >= 1) {
                    profileQuery = 'profile=dashboard' + String(n);
                }
            } else if (params.has('dashboard')) {
                profileQuery = 'profile=' + encodeURIComponent(params.get('dashboard'));
            }
            
            console.log('🔍 Profile detection:', {
                url: location.search,
                profileQuery: profileQuery,
                params: Object.fromEntries(params.entries())
            });
            
            return profileQuery;
        }

        // Start initial screen timeout if no video is loaded
        if (!currentVideo) {
            startScreenTimeout();
        }
        
        // SIMPLIFIED POLLING SYSTEM
        // Single efficient polling system
        let isPolling = false;
        let pollInterval = null;
        let lastKnownState = '';
        let pollTimeoutId = null;
        
        // Single efficient polling function that gets everything at once
        async function efficientPoll() {
            if (isPolling) return;
            isPolling = true;
            
            try {
                const profileQuery = getProfileQuery();
                
                // Get all state in ONE API call instead of multiple calls
                const changeRes = await fetch('api.php?action=check_changes&' + profileQuery, {
                    signal: AbortSignal.timeout(3000) // Reduced timeout to 3 seconds
                });
                
                if (!changeRes.ok) {
                    throw new Error(`API failed: ${changeRes.status}`);
                }
                
                const changeData = await changeRes.json();
                const currentStateHash = changeData.currentState.stateHash;
                
                // Only update if something actually changed
                if (currentStateHash !== lastKnownState) {
                    console.log('🔄 State changed, updating video and controls');
                    lastKnownState = currentStateHash;
                    
                    // Apply all changes at once
                    const state = changeData.currentState;
                    
                    // Update video if needed (including clearing video when empty)
                    if (state.video !== currentVideo) {
                        if (state.video) {
                            console.log('📺 Loading new video:', state.video);
                            loadVideo(state.video, state.playbackState === 'play', state.dirIndex);
                        } else {
                            console.log('📺 Clearing video (stop pressed)');
                            loadVideo('', false, 0); // This will show black screen
                        }
                    }
                    
                    // Apply playback controls
                    if (state.video && currentVideo === state.video) {
                        applyPlaybackControls(state.playbackState);
                    }
                    
                    // Apply volume and mute
                    if (state.volume !== undefined) {
                        videoEl.volume = state.volume / 100;
                    }
                    if (state.muted !== undefined) {
                        videoEl.muted = state.muted;
                    }
                }
                
            } catch (err) {
                console.log('⚠️ Poll error:', err.message);
                // Don't retry immediately on error
            } finally {
                isPolling = false;
            }
        }
        
        // Function to apply playback controls
        function applyPlaybackControls(playbackState) {
            switch (playbackState) {
                case 'play':
                    if (videoEl.paused) {
                        console.log('▶️ Starting video playback');
                        videoEl.play().catch(err => console.log('Play failed:', err));
                        resetScreenTimeout();
                        if (!window.fullscreenAttempted) {
                            setTimeout(attemptAutoFullscreen, 500);
                            window.fullscreenAttempted = true;
                        }
                    }
                    break;
                case 'pause':
                    if (!videoEl.paused) {
                        console.log('⏸️ Pausing video');
                        videoEl.pause();
                        startScreenTimeout();
                    }
                    break;
                case 'stop':
                    if (!videoEl.paused || videoEl.currentTime !== 0) {
                        console.log('⏹️ Stopping video');
                        videoEl.pause();
                        videoEl.currentTime = 0;
                        startScreenTimeout();
                    }
                    break;
            }
        }
        
        // Start efficient polling - every 2 seconds for responsive control
        pollInterval = setInterval(efficientPoll, 2000);
        
        // Initial poll after 1 second
        setTimeout(efficientPoll, 1000);
        
        // Try immediate fullscreen for TV/monitor browsers (Philips monitor)
        if (!window.fullscreenAttempted) {
            setTimeout(() => {
                attemptAutoFullscreen();
                window.fullscreenAttempted = true;
            }, 1000);
        }
        
        // Check initial playback state and attempt automatic fullscreen
        setTimeout(() => {
            // Check if there's a video playing
            const profileQuery = getProfileQuery();
            fetch('api.php?action=get_playback_state&' + profileQuery).then(res => res.json()).then(data => {
                if (data.state === 'play' && currentVideo && !window.fullscreenAttempted) {
                    // Try automatic fullscreen for Philips monitor
                    setTimeout(attemptAutoFullscreen, 500);
                    window.fullscreenAttempted = true;
                }
            }).catch(err => {});
        }, 2000); // Wait 2 seconds after page load
        
        // Try to play video when window gains focus (helps with second monitor)
        window.addEventListener('focus', () => {
            if (currentVideo && videoEl.paused) {
                videoEl.play().catch(err => {});
            }
            
            // Try fullscreen when window gains focus (sometimes works on TV browsers)
            if (currentVideo && !document.fullscreenElement && !window.fullscreenAttempted) {
                setTimeout(attemptAutoFullscreen, 1000);
                window.fullscreenAttempted = true;
            }
        });
        
        // Also try when the page becomes visible
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && currentVideo && videoEl.paused) {
                videoEl.play().catch(err => {});
            }
            
            // Try fullscreen when page becomes visible (sometimes works on TV browsers)
            if (!document.hidden && currentVideo && !document.fullscreenElement && !window.fullscreenAttempted) {
                setTimeout(attemptAutoFullscreen, 1000);
                window.fullscreenAttempted = true;
            }
        });
        
        // Clean up all intervals when page is unloaded to prevent memory leaks
        window.addEventListener('beforeunload', () => {
            cleanup();
        });
        
        // Handle video end event for play all mode
        videoEl.addEventListener('ended', () => {

            
            // Get profile query for this event handler
            const profileQuery = getProfileQuery();
            
            // Check both play all mode and loop mode
            Promise.all([
                fetch('api.php?action=get_play_all_mode&' + profileQuery).then(res => res.json()),
                fetch('api.php?action=get_loop_mode&' + profileQuery).then(res => res.json())
            ]).then(([playAllData, loopData]) => {
                if (playAllData.play_all === 'on') {
                    // Get the next video in the playlist
                    fetch('api.php?action=get_next_video&' + profileQuery).then(res => res.json()).then(nextData => {
                        // Handle both string and object formats for backward compatibility
                        const nextVideoData = nextData.nextVideo;
                        const nextVideoName = typeof nextVideoData === 'string' ? nextVideoData : (nextVideoData?.name || '');
                        const nextVideoDirIndex = typeof nextVideoData === 'string' ? 0 : (nextVideoData?.dirIndex || 0);
                        
                        if (nextVideoName && nextVideoName !== currentVideo) {
                            // Set the next video as current
                            const formData = new FormData();
                            formData.append('filename', nextVideoName);
                            formData.append('dirIndex', nextVideoDirIndex);
                            fetch('api.php?action=set_current_video&' + profileQuery, {
                                method: 'POST',
                                body: formData
                            }).then(() => {
                                // Load and play the next video
                                loadVideo(nextVideoName, true, nextVideoDirIndex);
                            });
                        } else {
                            // Reached end of playlist, stop playback
                            fetch('api.php?action=stop_video&' + profileQuery, { method: 'POST' })
                                .then(res => res.json())
                                .then(() => {
                                    videoEl.pause();
                                    videoEl.currentTime = 0;
                                })
                                .catch(err => {});
                        }
                    }).catch(err => {});
                } else if (loopData.loop === 'on') {
                    // Just restart the current video (loop is handled by video element)
                    videoEl.currentTime = 0;
                    videoEl.play().catch(err => {});
                } else {
                    // Ensure global playback state reflects stop to avoid accidental restarts
                    fetch('api.php?action=stop_video&' + profileQuery, { method: 'POST' })
                        .then(res => res.json())
                        .then(() => {
                            // Reset the player UI locally as well
                            videoEl.pause();
                            videoEl.currentTime = 0;
                        })
                        .catch(err => {});
                }
            }).catch(err => {});
        });
        
        // Function to attempt automatic fullscreen without user interaction (for Philips monitor)
        function attemptAutoFullscreen() {
            if (document.fullscreenElement) {
                console.log('✅ Already in fullscreen mode');
                return;
            }
            
            console.log('🎬 Attempting automatic fullscreen for Philips monitor');
            
            // Reset fullscreen attempt flag to allow retries
            window.fullscreenAttempted = false;
            
            // Method 1: Try video element fullscreen first
            if (videoEl.requestFullscreen) {
                console.log('🎬 Trying standard video fullscreen');
                videoEl.requestFullscreen().then(() => {
                    console.log('✅ Video fullscreen successful');
                    window.fullscreenAttempted = true;
                }).catch(err => {
                    console.log('❌ Video fullscreen failed, trying document fullscreen:', err);
                    attemptDocumentFullscreen();
                });
            } else if (videoEl.webkitRequestFullscreen) {
                console.log('🎬 Trying webkit video fullscreen');
                videoEl.webkitRequestFullscreen().then(() => {
                    console.log('✅ Webkit video fullscreen successful');
                    window.fullscreenAttempted = true;
                }).catch(err => {
                    console.log('❌ Webkit video fullscreen failed, trying document fullscreen:', err);
                    attemptDocumentFullscreen();
                });
            } else if (videoEl.webkitEnterFullscreen) {
                console.log('🎬 Trying webkit enter fullscreen');
                videoEl.webkitEnterFullscreen().then(() => {
                    console.log('✅ Webkit enter fullscreen successful');
                    window.fullscreenAttempted = true;
                }).catch(err => {
                    console.log('❌ Webkit enter fullscreen failed, trying document fullscreen:', err);
                    attemptDocumentFullscreen();
                });
            } else if (videoEl.mozRequestFullScreen) {
                console.log('🎬 Trying mozilla video fullscreen');
                videoEl.mozRequestFullScreen().then(() => {
                    console.log('✅ Mozilla video fullscreen successful');
                    window.fullscreenAttempted = true;
                }).catch(err => {
                    console.log('❌ Mozilla video fullscreen failed, trying document fullscreen:', err);
                    attemptDocumentFullscreen();
                });
            } else if (videoEl.msRequestFullscreen) {
                console.log('🎬 Trying MS video fullscreen');
                videoEl.msRequestFullscreen().then(() => {
                    console.log('✅ MS video fullscreen successful');
                    window.fullscreenAttempted = true;
                }).catch(err => {
                    console.log('❌ MS video fullscreen failed, trying document fullscreen:', err);
                    attemptDocumentFullscreen();
                });
            } else {
                console.log('🎬 Video fullscreen not supported, trying document fullscreen');
                attemptDocumentFullscreen();
            }
        }
        
        // Function to attempt document fullscreen as fallback
        function attemptDocumentFullscreen() {
            if (document.fullscreenElement) {
                console.log('✅ Already in document fullscreen mode');
                return;
            }
            
            console.log('🎬 Attempting document fullscreen as fallback');
            
            if (document.documentElement.requestFullscreen) {
                console.log('🎬 Trying standard document fullscreen');
                document.documentElement.requestFullscreen().then(() => {
                    console.log('✅ Document fullscreen successful');
                    window.fullscreenAttempted = true;
                }).catch(err => {
                    console.log('❌ Document fullscreen failed:', err);
                    attemptLegacyFullscreen();
                });
            } else if (document.documentElement.webkitRequestFullscreen) {
                console.log('🎬 Trying webkit document fullscreen');
                document.documentElement.webkitRequestFullscreen().then(() => {
                    console.log('✅ Webkit document fullscreen successful');
                    window.fullscreenAttempted = true;
                }).catch(err => {
                    console.log('❌ Webkit document fullscreen failed:', err);
                    attemptLegacyFullscreen();
                });
            } else {
                console.log('🎬 Document fullscreen not supported, trying legacy methods');
                attemptLegacyFullscreen();
            }
        }
        
        // Function to attempt legacy fullscreen methods
        function attemptLegacyFullscreen() {
            if (document.fullscreenElement) {
                return; // Already in fullscreen
            }
            
            console.log('Attempting legacy fullscreen methods');
            
            // Try legacy methods that sometimes work on TV browsers
            try {
                if (document.documentElement.webkitEnterFullscreen) {
                    document.documentElement.webkitEnterFullscreen();
                } else if (document.documentElement.mozRequestFullScreen) {
                    document.documentElement.mozRequestFullScreen();
                } else if (document.documentElement.msRequestFullscreen) {
                    document.documentElement.msRequestFullscreen();
                } else {
                    console.log('All fullscreen methods failed');
                }
            } catch (err) {
                console.log('Legacy fullscreen failed:', err);
            }
        }
        
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
                    // Use a small delay to ensure the keydown event is fully processed
                    setTimeout(enterFullscreen, 50);
                }
            }
        });
        
        // Try to enter fullscreen when video starts playing (for TV/monitor browsers)
        videoEl.addEventListener('play', () => {
            console.log('🎬 Video started playing, keeping polling active for dashboard controls');
            // Keep polling active to respond to dashboard controls (play/pause/stop)
            // Only pause the main video loading poll, keep control polling active
            
            // Only try fullscreen if video is actually playing and not muted
            if (!videoEl.paused && !videoEl.muted && !window.fullscreenAttempted) {
                console.log('Video started playing, attempting automatic fullscreen for Philips monitor');
                // Try automatic fullscreen for Philips monitor
                setTimeout(attemptAutoFullscreen, 500);
                window.fullscreenAttempted = true;
            }
        });
        
        // Resume full polling when video pauses or stops
        videoEl.addEventListener('pause', () => {
            console.log('⏸️ Video paused, resuming full polling');
            if (!pollInterval) {
                pollInterval = setInterval(efficientPoll, 2000);
            }
        });
        
        videoEl.addEventListener('ended', () => {
            console.log('⏹️ Video ended, resuming full polling');
            if (!pollInterval) {
                pollInterval = setInterval(efficientPoll, 2000);
            }
        });
        
        // Try to enter fullscreen when video is loaded and ready (for TV/monitor browsers)
        videoEl.addEventListener('loadeddata', () => {
            // Check if video should be playing
            const profileQuery = getProfileQuery();
            fetch('api.php?action=get_playback_state&' + profileQuery).then(res => res.json()).then(data => {
                if (data.state === 'play' && !videoEl.paused && !videoEl.muted && !window.fullscreenAttempted) {
                    console.log('Video loaded and should be playing, attempting automatic fullscreen for Philips monitor');
                    // Try automatic fullscreen for Philips monitor
                    setTimeout(attemptAutoFullscreen, 500);
                    window.fullscreenAttempted = true;
                }
            }).catch(err => console.log('Failed to check playback state on video load:', err));
        });
        
        // Click to toggle fullscreen
        videoEl.addEventListener('click', () => {
            if (document.fullscreenElement) {
                exitFullscreen();
            } else {
                // Use a small delay to ensure the click event is fully processed
                setTimeout(enterFullscreen, 50);
            }
        });
        
        // Also allow clicking anywhere on the page to enter fullscreen (for initial load)
        document.addEventListener('click', () => {
            // Only try to enter fullscreen if video is playing and not already in fullscreen
            if (currentVideo && !videoEl.paused && !document.fullscreenElement) {
                console.log('Page clicked, entering fullscreen');
                // Use a small delay to ensure the click event is fully processed
                setTimeout(enterFullscreen, 100);
            }
        }, { once: true }); // Only trigger once per page load
        
        // Cleanup function to prevent memory leaks
        function cleanup() {
            // Clear all intervals
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
            
            // Clear all timeouts
            if (pollTimeoutId) {
                clearTimeout(pollTimeoutId);
                pollTimeoutId = null;
            }
            
            // Reset all flags
            isPolling = false;
            window.fullscreenAttempted = false;
            
            // Force garbage collection if available
            if (window.gc) {
                window.gc();
            }
        }
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', cleanup);
        
        // Cleanup on page visibility change (when tab becomes hidden)
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // Pause polling when page is hidden to save resources
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
                if (pollTimeoutId) {
                    clearTimeout(pollTimeoutId);
                    pollTimeoutId = null;
                }
            } else {
                // Resume polling when page becomes visible
                if (!pollInterval) {
                    pollInterval = setInterval(efficientPoll, 2000);
                }
                if (!pollTimeoutId && !isPolling) efficientPoll(); // Resume polling if it was paused
            }
        });
        
        // Simplified periodic cleanup and health monitoring (combined to avoid redundancy)
        setInterval(async () => {
            try {
                // Reset fullscreen flag periodically
                if (document.fullscreenElement) {
                    window.fullscreenAttempted = true;
                } else {
                    // Reset flag every 30 seconds if not in fullscreen
                    window.fullscreenAttempted = false;
                }
                
                // Force cleanup of any stuck polling states
                if (isPolling && !pollTimeoutId) {
                    isPolling = false;
                }
                
                // Health check (combined with cleanup to avoid redundant 30s intervals)
                try {
                    const response = await fetch('api.php?action=health');
                    await response.json();
                } catch (error) {
                    console.error('💔 Health check failed:', error);
                }
                
            } catch (error) {
                // Force cleanup on error
                cleanup();
            }
        }, 30000); // Run every 30 seconds to reduce overhead
        
        // Page unload cleanup handled by cleanup() function
    });
    </script>
</body>
</html>