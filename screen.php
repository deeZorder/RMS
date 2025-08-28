<?php
/**
 * screen.php - Simplified Video Player
 * Clean, reliable video playback based on original implementation
 */

// Include shared state management
require_once __DIR__ . '/state_manager.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

// Load configuration
$configPath = __DIR__ . '/config.json';
if (file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true);
} else {
    $config = ['directory' => 'videos'];
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

// Get current video from state
$currentVideo = getCurrentVideoForProfile('default');
$videoFile = $currentVideo['filename'] ?? '';
$dirIndex = $currentVideo['dirIndex'] ?? 0;

// Build video path and URL
$videoDirs = [];
if (!empty($config['directories']) && is_array($config['directories'])) {
    $videoDirs = $config['directories'];
} else {
    $videoDirs = [ $config['directory'] ];
}

$selectedDir = $videoDirs[$dirIndex] ?? 'videos';
if (!is_dir($selectedDir)) {
    $fallback = !empty($config['directory']) ? $config['directory'] : 'videos';
    $selectedDir = realpath(__DIR__ . '/' . $fallback);
}

$videoPath = $selectedDir . (strpos($selectedDir, ':\\') !== false ? '\\' : '/') . $videoFile;
$videoUrl = 'video.php?file=' . rawurlencode($videoFile) . '&dirIndex=' . $dirIndex;

// Page title
$pageTitle = $videoFile ? pathinfo($videoFile, PATHINFO_FILENAME) : 'No video selected';

// Compute initial playback state without depending on helper functions
$initialPlaybackState = 'stop';
$statePath = __DIR__ . '/data/profiles/default/state.json';
if (file_exists($statePath)) {
    $content = @file_get_contents($statePath);
    if ($content !== false) {
        $state = json_decode($content, true);
        if (is_array($state) && isset($state['playbackState'])) {
            $initialPlaybackState = $state['playbackState'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#000000">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Relax Media System</title>
    
    <!-- External stylesheet -->
    <link href="assets/style.css" rel="stylesheet">
    
    <!-- Video.js CSS -->
    <link href="https://vjs.zencdn.net/7.20.3/video-js.css" rel="stylesheet">
</head>
<body>
    <div id="blackout" style="position:fixed; inset:0; background:#000; z-index:9999; display:<?php echo ($videoFile && file_exists($videoPath) && $initialPlaybackState !== 'stop') ? 'none' : 'block'; ?>;"></div>
    <div class="screen-container">
        <div class="video-container">
            <?php if ($videoFile && file_exists($videoPath)): ?>
        <video 
            id="playback" 
            class="video-js vjs-default-skin <?php echo $showControls ? '' : 'video-no-controls'; ?>" 
            <?php echo $showControls ? 'controls' : ''; ?> 
            autoplay 
            playsinline 
            preload="auto"
                >
                    <source src="<?php echo htmlspecialchars($videoUrl); ?>" type="video/mp4">
                    <p class="vjs-no-js">JavaScript required for video playback.</p>
        </video>
            <?php else: ?>
                <!-- Keep screen black when no video is selected or file is missing -->
            <?php endif; ?>
        </div>
    </div>

    <script src="https://vjs.zencdn.net/7.20.3/video.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // First attempt to maximize browser window early for Philips monitor
        try {
            console.log('Early browser window maximization attempt...');
            
            if (window.screen && window.screen.width && window.screen.height) {
                try {
                    window.resizeTo(window.screen.width, window.screen.height);
                    window.moveTo(0, 0);
                    console.log(`Browser window resized to ${window.screen.width}x${window.screen.height}`);
                } catch (resizeError) {
                    console.log('Window resize failed:', resizeError.message);
                }
            }
            
            if (typeof window.maximize === 'function') {
                try {
                    window.maximize();
                    console.log('Early window.maximize() called');
                } catch (maximizeError) {
                    console.log('Window maximize failed:', maximizeError.message);
                }
            }
            
            try {
                window.focus();
                console.log('Window focused');
            } catch (focusError) {
                console.log('Window focus failed:', focusError.message);
            }
            
        } catch (e) {
            console.log('Early browser maximization failed:', e.message);
        }

            const videoElement = document.getElementById('playback');
            const blackoutEl = document.getElementById('blackout');
            let player = null;
            if (!videoElement) {
                console.log('No video element yet; polling will run and reload when a video is selected');
            } else {

        console.log('Initializing Video.js player...');
    
    // Initialize Video.js player
        player = videojs('playback', {
                controls: <?php echo $showControls ? 'true' : 'false'; ?>,
            autoplay: true,
                preload: 'auto',
            fluid: true
        });

        // Simple error handling
        player.on('error', function() {
            console.error('Video error:', player.error());
        });

        // Video events
        player.on('play', function() {
            console.log('Video started playing');
            try { if (blackoutEl) blackoutEl.style.display = 'none'; } catch (_) {}
        });

        // Track if video was playing before unmute attempts
        let wasPlayingBeforeUnmute = false;

        player.on('pause', function() {
            console.log('Video paused');
            
            // For Philips monitor - only resume if we know it was playing and this might be an unmute-related pause
            if (wasPlayingBeforeUnmute) {
                console.log('Video was playing before unmute - this might be an unmute-related pause');
                console.log('Waiting to see if video auto-recovers...');
                
                // Wait a bit longer to see if the video recovers naturally
                setTimeout(() => {
                    if (player.paused()) {
                        console.log('Video still paused - keeping it paused to avoid autoplay restrictions');
                        console.log('Will try alternative unmute strategy instead');
                        wasPlayingBeforeUnmute = false;
                        
                        // Try a different approach - force muted playback to resume
                        if (!player.muted()) {
                            console.log('Re-muting and trying to resume...');
                            player.muted(true);
                            setTimeout(() => {
                                player.play().then(() => {
                                    console.log('✅ Resumed with muted playback');
                                }).catch(e => console.log('Muted resume also failed:', e.message));
                            }, 500);
                        }
                    }
                }, 2000); // Wait 2 seconds to see if it recovers
            }
        });

        player.on('ended', function() {
            console.log('Video ended');
        });

        // Add event listener for when video is ready to play
        player.on('canplay', function() {
            console.log('Video can play - ready for audio operations');
            try { if (blackoutEl) blackoutEl.style.display = 'none'; } catch (_) {}
        });

        // Add event listener for when video starts playing
        // Show an unmute UI and wait for a real user gesture to unmute
        let hasShownMutedPrompt = false;
        player.on('playing', function() {
            console.log('Video is now playing on embedded display (muted)');
        });

        player.ready(function() {
            console.log('Video.js player ready');

            // If video is selected and playback state is 'play', try to start playing immediately
            <?php
            if (!empty($videoFile) && $initialPlaybackState === 'play') {
                echo "console.log('Attempting auto-start video playback');";
                echo "// Small delay to ensure page is fully loaded";
                echo "setTimeout(() => attemptAutoPlay(player), 100);";
            }
            ?>

            // Additional check: Always try to auto-play if there's a video and we're supposed to be playing
            setTimeout(function() {
                <?php
                $currentState = loadState('default');
                $shouldPlay = !empty($currentState['currentVideo']['filename']) && $currentState['playbackState'] === 'play';
                if ($shouldPlay) {
                    echo "console.log('Double-checking playback state and attempting to play');";
                    echo "if (player && (player.paused() || player.currentTime() === 0)) {";
                    echo "    attemptAutoPlay(player);";
                    echo "}";
                }
                ?>
            }, 1000);
        });
            }

        // Profile resolution for API calls (default to 'default')
        const qs = new URLSearchParams(window.location.search);
        let screenProfile = 'default';
        if (qs.has('profile')) {
            screenProfile = qs.get('profile') || 'default';
        } else if (qs.has('d')) {
            const n = parseInt(qs.get('d') || '0', 10);
            screenProfile = (n === 0) ? 'default' : ('dashboard' + n);
        } else if (qs.has('dashboard')) {
            screenProfile = qs.get('dashboard') || 'default';
        }
        const screenProfileQuery = 'profile=' + encodeURIComponent(screenProfile);
        const audioParam = qs.get('audio');
        const allowAudio = audioParam ? ['1','true','on','yes'].includes(audioParam.toLowerCase()) : true;

        function showBlackout() { try { if (blackoutEl) blackoutEl.style.display = 'block'; } catch (_) {} }
        function hideBlackout() { try { if (blackoutEl) blackoutEl.style.display = 'none'; } catch (_) {} }
        function stopAndBlackout() {
            showBlackout();
            try {
                const el = (player && player.el && player.el()) ? player.el().querySelector('video') : document.getElementById('playback');
                if (el) {
                    try { el.pause(); } catch (_) {}
                    try { el.removeAttribute('src'); } catch (_) {}
                    try { while (el.firstChild) el.removeChild(el.firstChild); } catch (_) {}
                    try { el.load(); } catch (_) {}
                }
            } catch (_) {}
        }

        // Apply audio settings to player
        function applyAudioSettings(volumePercent, isMuted) {
            try {
                const vol = Math.max(0, Math.min(100, Number(volumePercent || 0)));
                if (player && typeof player.volume === 'function') {
                    player.volume(vol / 100);
                }
                if (player && typeof player.muted === 'function') {
                    player.muted(allowAudio ? !!isMuted : true);
                }
            } catch (e) {
                console.log('Failed to apply audio settings:', e.message);
            }
        }

        // Dynamically create player when a video becomes available
        function bootPlayerWithCurrentVideo(filename, dirIndex) {
            try {
                const container = document.querySelector('.video-container');
                if (!container) return;

                // Create video element
                const video = document.createElement('video');
                video.id = 'playback';
                video.className = 'video-js vjs-default-skin ' + (<?php echo $showControls ? 'true' : 'false'; ?> ? '' : 'video-no-controls');
                if (<?php echo $showControls ? 'true' : 'false'; ?>) {
                    video.setAttribute('controls', '');
                }
                video.setAttribute('autoplay', '');
                video.setAttribute('playsinline', '');
                video.setAttribute('preload', 'auto');

                const src = document.createElement('source');
                src.type = 'video/mp4';
                src.src = 'video.php?file=' + encodeURIComponent(filename) + '&dirIndex=' + (dirIndex || 0);
                video.appendChild(src);

                // Clear container and insert
                container.innerHTML = '';
                container.appendChild(video);

                // Initialize Video.js
                player = videojs('playback', {
                    controls: <?php echo $showControls ? 'true' : 'false'; ?>,
                    autoplay: true,
                    preload: 'auto',
                    fluid: true
                });
            } catch (e) {
                console.log('Failed to boot player dynamically:', e.message);
            }
        }

        // Fetch and apply current volume/mute
        function fetchAndApplyAudio() {
            Promise.all([
                fetch('api.php?action=get_volume&' + screenProfileQuery + '&t=' + Date.now()).then(r => r.json()).catch(() => ({ volume: 50 })),
                fetch('api.php?action=get_mute_state&' + screenProfileQuery + '&t=' + Date.now()).then(r => r.json()).catch(() => ({ muted: false }))
            ]).then(([volData, muteData]) => {
                const volume = (typeof volData.volume === 'number') ? volData.volume : 50;
                const muted = !!muteData.muted;
                applyAudioSettings(volume, muted);
            }).catch(e => {
                console.log('Audio poll failed:', e && e.message ? e.message : e);
            });
        }

        // Initial audio sync and polling
        fetchAndApplyAudio();
        setInterval(fetchAndApplyAudio, 3000);

        // Function to attempt autoplay with proper error handling
        function attemptAutoPlay(player) {
            // Check if already playing to avoid unnecessary attempts
            try {
                const isPlaying = player && !player.paused() && player.currentTime() > 0;
                if (isPlaying) {
                    console.log('Video is already playing, skipping autoplay attempt');
                    return;
                }
            } catch (error) {
                console.log('Error checking player state:', error.message);
            }

            if (!allowAudio) {
                console.log('Attempting muted autoplay for embedded display...');
                try { player.muted(true); } catch (e) {}
                player.play().then(function() {
                    console.log('✅ Muted autoplay successful');
                    maximizeVideo(player);
                }).catch(function(error) {
                    console.log('Muted autoplay failed:', error && error.message ? error.message : error);
                    showManualPlayButton(player);
                });
                return;
            }

            console.log('Attempting autoplay with audio (best-effort)...');
            try { player.muted(false); } catch (e) {}
            player.play().then(function() {
                console.log('✅ Autoplay successful');
                maximizeVideo(player);
                setTimeout(() => {
                    try {
                        player.muted(false);
                        if (player.paused()) {
                            console.log('Unmuted caused pause; reverting to muted playback');
                            player.muted(true);
                            player.play().catch(() => {});
                        } else {
                            console.log('✅ Audio enabled');
                        }
                    } catch (_) {}
                }, 500);
            }).catch(function(error) {
                if (error && error.name === 'NotAllowedError') {
                    console.log('Autoplay blocked; trying controls-enabled then muted fallback...');
                    player.controls(true);
                    setTimeout(() => {
                        player.play().then(() => {
                            console.log('✅ Autoplay with controls successful');
                            maximizeVideo(player);
                            player.controls(false);
                            setTimeout(() => {
                                try {
                                    player.muted(false);
                                    if (player.paused()) {
                                        console.log('Unmute blocked; keeping muted');
                                        player.muted(true);
                                        player.play().catch(() => {});
                                    } else {
                                        console.log('✅ Audio enabled after controls');
                                    }
                                } catch (_) {}
                            }, 500);
                        }).catch(() => {
                            console.log('Controls method failed, trying muted autoplay...');
                            try { player.muted(true); } catch (e) {}
                            player.play().then(() => {
                                console.log('✅ Muted autoplay successful');
                                maximizeVideo(player);
                            }).catch(() => {
                                console.log('All autoplay methods failed');
                                showManualPlayButton(player);
                            });
                        });
                    }, 100);
                } else {
                    console.log('Autoplay error:', error && error.message ? error.message : error);
                    showManualPlayButton(player);
                }
            });
        }

        // Function to force autoplay for display monitor (no user interaction needed)
        function forceAutoplayForDisplay(player) {
            console.log('Forcing autoplay for display monitor...');

            // Method 1: Direct play attempt
            player.play().then(() => {
                 console.log('✅ Direct play successful for Philips monitor');
                maximizeVideo(player);
                 // Try to unmute Philips monitor after successful play
                 console.log('Video playing on Philips monitor - attempting to unmute...');
                 
                 setTimeout(() => {
                     console.log('Attempting to unmute Philips monitor with direct play...');
                     player.muted(false);
                     
                     // Always try to resume if paused
                     setTimeout(() => {
                         if (player.paused()) {
                             console.log('Video paused after direct unmute - resuming...');
                             player.play().catch(e => console.log('Direct resume failed:', e.message));
                         }
                         
                         if (player.muted() === false) {
                             console.log('✅ Philips monitor unmute successful with direct play!');
                         } else {
                             console.log('⚠️ Philips monitor unmute failed with direct play');
                         }
                     }, 500);
                 }, 1500);
            }).catch(() => {
                // Method 2: Try with controls temporarily enabled
                console.log('Direct play failed, trying with controls...');
                player.controls(true);
                setTimeout(() => {
                    player.play().then(() => {
                        console.log('✅ Play with controls successful');
                        maximizeVideo(player);
                        player.controls(false); // Hide controls
                        setTimeout(() => player.muted(false), 100);
                    }).catch(() => {
                        // Method 3: Muted approach
                        console.log('Controls method failed, trying muted...');
                        player.muted(true);
                        player.play().then(() => {
                            console.log('✅ Muted play successful');
                            maximizeVideo(player);
                            // Try to unmute after a delay
                            setTimeout(() => {
                                player.muted(false);
                                console.log('Attempted to unmute');
                            }, 500);
                        }).catch(() => {
                            console.log('All autoplay methods failed for display');
                        });
                    });
                }, 100);
            });
        }

        // Keep old function name for compatibility but use new logic
        function simulateUserInteraction(player) {
            try {
            forceAutoplayForDisplay(player);
                
                // Method 2: Try multiple event types
                setTimeout(() => {
                    console.log('Method 2: Trying multiple event types...');

                    const events = [
                        new MouseEvent('click', {
                            view: window,
                            bubbles: true,
                            cancelable: true,
                            clientX: videoElement.clientWidth / 2,
                            clientY: videoElement.clientHeight / 2
                        }),
                        new MouseEvent('mousedown', {
                            view: window,
                            bubbles: true,
                            cancelable: true,
                            clientX: videoElement.clientWidth / 2,
                            clientY: videoElement.clientHeight / 2
                        }),
                        new MouseEvent('mouseup', {
                            view: window,
                            bubbles: true,
                            cancelable: true,
                            clientX: videoElement.clientWidth / 2,
                            clientY: videoElement.clientHeight / 2
                        }),
                        new KeyboardEvent('keydown', {
                            key: ' ',
                            code: 'Space',
                            bubbles: true,
                            cancelable: true
                        })
                    ];

                    events.forEach((event, index) => {
                        try {
                            videoElement.dispatchEvent(event);
                            console.log(`Event ${index + 1} (${event.type}) dispatched successfully`);
                        } catch (eventError) {
                            console.log(`Event ${index + 1} (${event.type}) failed:`, eventError.message);
                        }
                    });

                    // Try using video element's native methods
                    try {
                        videoElement.click();
                        console.log('Native video.click() called');
                    } catch (clickError) {
                        console.log('Native video.click() failed:', clickError.message);
                    }
                }, 100);

                // Method 3: Alternative interaction - temporary controls enable
                setTimeout(() => {
                    console.log('Method 3: Alternative interaction simulation...');

                    // Try to programmatically pause and play to simulate interaction
                    const wasPaused = videoElement.paused;
                    const wasMuted = videoElement.muted;

                    // For embedded browser, skip fullscreen attempts and use container maximization
                    const maximizeContainerDuringInteraction = () => {
                                const container = document.querySelector('.video-js') || videoElement.parentElement;
                                if (container) {
                                    container.style.position = 'fixed';
                                    container.style.top = '0';
                                    container.style.left = '0';
                                    container.style.width = '100vw';
                                    container.style.height = '100vh';
                                    container.style.zIndex = '9999';
                                    container.style.background = 'black';
                            console.log('✅ Container maximized during interaction simulation');
                        }
                    };

                    try {
                        // Quick pause/play cycle to simulate user interaction
                        videoElement.pause();
                        setTimeout(() => {
                            try {
                                // Try container maximization first
                                maximizeContainerDuringInteraction();

                                // Then try play
                                setTimeout(() => {
                                    videoElement.play().then(() => {
                                        console.log('Pause/play cycle completed for interaction simulation');
                                    }).catch((playError) => {
                                        console.log('Play cycle failed (expected):', playError.message);
                                    });
                                }, 100);
                            } catch (syncError) {
                                console.log('Synchronous play failed (expected):', syncError.message);
                            }
                        }, 50);
                    } catch (interactionError) {
                        console.log('Interaction simulation failed:', interactionError.message);
                    }

                    console.log('=== USER INTERACTION SIMULATION COMPLETE ===');
                }, 300);

            } catch (error) {
                console.log('User interaction simulation failed:', error.message);
            }
        }

        // Function to attempt unmute with interaction simulation
        function attemptUnmuteWithInteraction(player, onSuccess, onFailure) {
            if (!player || player.paused()) {
                console.log('Cannot unmute - player is null or paused');
                if (onFailure) onFailure();
            return;
        }
        
            console.log('=== STARTING UNMUTE ATTEMPT ===');
            console.log('Current muted state:', player.muted());
            console.log('Video readyState:', player.readyState());

            // Try direct unmute first
            try {
                console.log('Attempt 1: Direct unmute...');
                player.muted(false);
                console.log('Direct unmute attempted, new muted state:', player.muted());

                // Verify it worked with multiple checks
                setTimeout(() => {
                    const isActuallyUnmuted = player.muted() === false;
                    console.log('Verification check - muted state:', player.muted(), 'considered unmuted:', isActuallyUnmuted);

                    if (isActuallyUnmuted) {
                        console.log('✅ Direct unmute successful!');

                        // Ensure video is still playing after unmuting
                        if (player.paused()) {
                            console.log('Video paused after direct unmuting, attempting to resume...');
                            player.play().then(() => {
                                console.log('✅ Video resumed after direct unmuting');
                                if (onSuccess) onSuccess();
                            }).catch((resumeError) => {
                                console.log('Failed to resume after direct unmuting:', resumeError.message);
                                if (onFailure) onFailure();
                            });
                        } else {
                            if (onSuccess) onSuccess();
                        }
                } else {
                        console.log('❌ Direct unmute failed, trying interaction simulation...');
                        simulateUserInteraction(player);

                        // Try multiple unmute attempts after interaction
                        let attemptCount = 0;
                        const maxAttempts = 3;

                        const tryUnmuteAfterInteraction = () => {
                            attemptCount++;
                            console.log(`Attempt ${attemptCount}: Trying unmute after interaction...`);

                            try {
                                player.muted(false);
                                console.log(`Attempt ${attemptCount} result - muted:`, player.muted());

                                if (player.muted() === false) {
                                    console.log(`✅ Unmute successful on attempt ${attemptCount}!`);

                                    // Ensure video is still playing after unmuting
                                    if (player.paused()) {
                                        console.log('Video paused after unmuting, attempting to resume...');
                                        player.play().then(() => {
                                            console.log('✅ Video resumed after unmuting');
                                            if (onSuccess) onSuccess();
                                        }).catch((resumeError) => {
                                            console.log('Failed to resume after unmuting:', resumeError.message);
                                            if (onFailure) onFailure();
                                        });
                                    } else {
                                        if (onSuccess) onSuccess();
                                    }
            return;
        }
        
                                if (attemptCount < maxAttempts) {
                                    setTimeout(tryUnmuteAfterInteraction, 200);
            } else {
                                    console.log('❌ All unmute attempts failed');
                                    // Last resort: show a manual play button
                                    showManualPlayButton(player);
                                    if (onFailure) onFailure();
                                }
                            } catch (unmuteError) {
                                console.log(`Attempt ${attemptCount} failed:`, unmuteError.message);
                                if (attemptCount < maxAttempts) {
                                    setTimeout(tryUnmuteAfterInteraction, 200);
                                } else {
                                    if (onFailure) onFailure();
                                }
                            }
                        };

                        setTimeout(tryUnmuteAfterInteraction, 400);
                    }
                }, 100);
            } catch (error) {
                console.log('❌ Direct unmute failed with error:', error.message);

                // Try with interaction simulation as fallback
                console.log('Trying interaction simulation as fallback...');
                simulateUserInteraction(player);

                setTimeout(() => {
                    try {
                        console.log('Final unmute attempt after interaction...');
                        player.muted(false);
                        console.log('Final attempt result - muted:', player.muted());

                        if (player.muted() === false) {
                            console.log('✅ Final unmute successful!');
                            if (onSuccess) onSuccess();
                        } else {
                            console.log('❌ Final unmute failed');
                            if (onFailure) onFailure();
                        }
                    } catch (finalError) {
                        console.log('❌ Final unmute attempt failed:', finalError.message);
                        if (onFailure) onFailure();
                    }
                }, 600);
            }
        }

        // Function to show muted indicator
        function showMutedIndicator() {
            // Remove any existing indicator
            hideMutedIndicator();

            const indicator = document.createElement('div');
            indicator.id = 'muted-indicator';
            indicator.innerHTML = `
                <div class="muted-indicator-content">
                    <span class="muted-icon">🔇</span>
                    <span class="muted-text">Video started muted - click anywhere to unmute</span>
                </div>
            `;
            indicator.className = 'muted-indicator';

            document.body.appendChild(indicator);

            // Auto-hide after 3 seconds if not interacted with
            setTimeout(function() {
                const existingIndicator = document.getElementById('muted-indicator');
                if (existingIndicator) {
                    existingIndicator.style.opacity = '0';
                    setTimeout(() => existingIndicator.remove(), 300);
                }
            }, 3000);
        }

        // Function to hide muted indicator
        function hideMutedIndicator() {
            const indicator = document.getElementById('muted-indicator');
            if (indicator) {
                indicator.remove();
            }
        }

        // Function to show muted indicator with manual unmute button
        function showMutedWithUnmuteButton(player) {
            // Remove any existing indicators
            hideMutedIndicator();
            const existingUnmute = document.getElementById('muted-unmute-indicator');
            if (existingUnmute) existingUnmute.remove();

            const indicator = document.createElement('div');
            indicator.id = 'muted-unmute-indicator';
            indicator.innerHTML = `
                <div class="muted-unmute-content">
                    <div class="muted-info">
                        <span class="muted-icon">🔇</span>
                        <span class="muted-text">Video is playing muted</span>
                    </div>
                    <button class="unmute-button" onclick="manualUnmuteVideo()">
                        🔊 Unmute Video
                    </button>
                </div>
            `;
            indicator.className = 'muted-unmute-indicator';

            document.body.appendChild(indicator);

            // Make player globally accessible for unmute function
            window.currentPlayer = player;
        }

        // Global function for manual unmute
        function manualUnmuteVideo() {
            const player = window.currentPlayer;
            if (!player) return;

            // Remove the indicator
            const indicator = document.getElementById('muted-unmute-indicator');
            if (indicator) indicator.remove();

            // Try to unmute with user interaction
            player.muted(false);

            // Verify it worked
            setTimeout(() => {
                if (player.muted() === false) {
                    console.log('✅ Manual unmute successful');
                } else {
                    console.log('❌ Manual unmute failed - browser restrictions');
                    // Show a message about browser restrictions
                    showBrowserRestrictionMessage();
                }
            }, 100);
        }

        // Function to show browser restriction message
        function showBrowserRestrictionMessage() {
            const message = document.createElement('div');
            message.id = 'browser-restriction-message';
            message.innerHTML = `
                <div class="restriction-content">
                    <h3>Browser Audio Restrictions</h3>
                    <p>Your browser requires direct user interaction to enable audio.</p>
                    <p>Please click the video controls or press a key to enable sound.</p>
                    <button onclick="this.parentElement.parentElement.remove()">Close</button>
                </div>
            `;
            message.className = 'browser-restriction-message';

            document.body.appendChild(message);
        }

        // Function to show manual play button as last resort
        function showManualPlayButton(player) {
            // Remove any existing indicators
            hideMutedIndicator();

            const playButton = document.createElement('div');
            playButton.id = 'manual-play-overlay';
            playButton.innerHTML = `
                <div class="manual-play-content">
                    <button class="manual-play-btn" onclick="manualPlayVideo()">
                        <div class="play-icon">▶</div>
                        <div class="play-text">Click to Play Video</div>
                    </button>
                    <div class="manual-play-hint">Browser requires user interaction</div>
                </div>
            `;
            playButton.className = 'manual-play-overlay';

            document.body.appendChild(playButton);

            // Make the player available globally for the manual play function
            window.videoPlayer = player;
        }

        // Global function for manual play button
        function manualPlayVideo() {
            const player = window.videoPlayer;
            if (player) {
                // Remove the overlay
                const overlay = document.getElementById('manual-play-overlay');
                if (overlay) overlay.remove();

                // Unmute and try to play
                player.muted(false);
                player.play().then(() => {
                    console.log('✅ Manual play successful!');
                    // Maximize after manual play
                    maximizeVideo(player);
                }).catch(error => {
                    console.log('Manual play failed:', error.message);
                });
            }
        }

        // Function to maximize video for Philips monitor with embedded browser
        function maximizeVideo(player) {
            if (!player) return;

            console.log('Maximizing video for Philips monitor embedded browser...');

            // First try to maximize the browser window itself
            maximizeBrowserWindow();

            // Get the video element
            const videoElement = player.el().querySelector('video');
            if (!videoElement) {
                console.log('No video element found for maximization');
                return;
            }

            // For embedded browsers, skip fullscreen API attempts and go straight to container maximization
            // This avoids the "user gesture required" error
            maximizeContainer();

            function maximizeBrowserWindow() {
                try {
                    console.log('Attempting to maximize browser window...');
                    
                    // Try different methods to maximize the browser window
                    if (window.screen && window.screen.width && window.screen.height) {
                        // Method 1: Try to resize to screen dimensions
                        try {
                            window.resizeTo(window.screen.width, window.screen.height);
                            window.moveTo(0, 0);
                            console.log('Browser window resized to screen dimensions');
                        } catch (resizeError) {
                            console.log('Window resize failed:', resizeError.message);
                        }
                    }
                    
                    // Method 2: Try to maximize using window.maximize (some embedded browsers support this)
                    if (typeof window.maximize === 'function') {
                        try {
                            window.maximize();
                            console.log('Browser window maximized using window.maximize()');
                        } catch (maximizeError) {
                            console.log('Window maximize failed:', maximizeError.message);
                        }
                    }
                    
                    // Method 3: Try outerWidth/outerHeight manipulation
                    if (window.outerWidth && window.outerHeight) {
                        try {
                            const currentOuter = { width: window.outerWidth, height: window.outerHeight };
                            window.outerWidth = window.screen.width;
                            window.outerHeight = window.screen.height;
                            console.log(`Browser outer dimensions changed from ${currentOuter.width}x${currentOuter.height} to ${window.screen.width}x${window.screen.height}`);
                        } catch (outerError) {
                            console.log('Outer dimensions manipulation failed:', outerError.message);
                        }
                    }
                    
                    // Method 4: Focus the window (might help with embedded browsers)
                    try {
                        window.focus();
                        console.log('Window focused');
                    } catch (focusError) {
                        console.log('Window focus failed:', focusError.message);
                    }
                    
                } catch (e) {
                    console.log('Browser window maximization failed:', e.message);
                    console.log('This is normal for embedded browsers with restricted APIs');
                }
            }

            function maximizeContainer() {
                const container = document.querySelector('.video-js') || videoElement.parentElement;
                if (container) {
                    // Force fullscreen-like appearance for embedded browser
                    container.style.position = 'fixed';
                    container.style.top = '0';
                    container.style.left = '0';
                    container.style.width = '100vw';
                    container.style.height = '100vh';
                    container.style.zIndex = '9999';
                    container.style.background = 'black';
                    container.style.margin = '0';
                    container.style.padding = '0';

                    // Hide any scrollbars and browser UI
                    document.body.style.overflow = 'hidden';
                    document.documentElement.style.overflow = 'hidden';
                    document.body.style.margin = '0';
                    document.body.style.padding = '0';
                    document.documentElement.style.margin = '0';
                    document.documentElement.style.padding = '0';

                    console.log('✅ Container maximized for Philips monitor embedded browser');

                    // Hide video controls for cleaner display
                    player.controls(false);
                    
                    // Also try to hide any browser UI elements that might be visible
                    try {
                        // Some embedded browsers support hiding UI elements
                        if (typeof window.navigator.mediaSession !== 'undefined') {
                            window.navigator.mediaSession.setActionHandler('play', () => {});
                            window.navigator.mediaSession.setActionHandler('pause', () => {});
                        }
                        
                        // Note: Pointer lock removed to avoid WrongDocumentError on embedded browsers
                        
                    } catch (e) {
                        console.log('Advanced browser features not available:', e.message);
                    }
                }
            }
        }

        // Poll for video changes and playback state every 3 seconds
        let lastVideo = '<?php echo $videoFile; ?>';
        let lastPlaybackState = '<?php echo $initialPlaybackState; ?>';

        console.log('Screen initialized with video:', lastVideo, 'playback state:', lastPlaybackState);
        let isChecking = false;

        function checkForVideoChanges() {
            if (isChecking) return;
            isChecking = true;

            // Check both current video and playback state with error handling
            // Try API first, fall back to direct access if needed
            Promise.all([
                fetch('api.php?action=get_current_video&' + screenProfileQuery + '&t=' + Date.now())
                    .then(r => {
                        if (!r.ok) {
                            throw new Error(`API HTTP ${r.status}: ${r.statusText}`);
                        }
                        return r.json();
                    })
                    .catch(() => {
                        // Fallback to direct state access
                        console.log('API failed, trying direct state access for video...');
                        return fetch('state_direct.php?action=get_state&' + screenProfileQuery + '&t=' + Date.now())
                            .then(r => {
                                if (!r.ok) {
                                    throw new Error(`Direct HTTP ${r.status}: ${r.statusText}`);
                                }
                                return r.json();
                            })
                            .then(data => {
                                // Transform direct response to match API format
                                return {
                                    success: true,
                                    currentVideo: data.currentVideo
                                };
                            });
                    }),
                fetch('api.php?action=get_playback_state&' + screenProfileQuery + '&t=' + Date.now())
                    .then(r => {
                        if (!r.ok) {
                            throw new Error(`API HTTP ${r.status}: ${r.statusText}`);
                        }
                        return r.json();
                    })
                    .catch(() => {
                        // Fallback to direct state access
                        console.log('API failed, trying direct state access for playback...');
                        return fetch('state_direct.php?action=get_state&' + screenProfileQuery + '&t=' + Date.now())
                            .then(r => {
                                if (!r.ok) {
                                    throw new Error(`Direct HTTP ${r.status}: ${r.statusText}`);
                                }
                                return r.json();
                            })
                            .then(data => {
                                // Transform direct response to match API format
                                return {
                                    success: true,
                                    state: data.playbackState
                                };
                            });
                    })
            ])
            .then(([videoData, playbackData]) => {
                let shouldReload = false;

                // Check for video changes
                if (videoData.success && videoData.currentVideo) {
                    const newVideo = videoData.currentVideo.filename;
                    if (newVideo && newVideo !== lastVideo) {
                        console.log('Video changed from', lastVideo, 'to', newVideo);
                        // If no player yet, build and try to autoplay without full reload
                        if (!player) {
                            bootPlayerWithCurrentVideo(newVideo, videoData.currentVideo.dirIndex || 0);
                            // Give player a moment to init, then try autoplay
                            setTimeout(() => {
                                try { attemptAutoPlay(player); } catch (e) {}
                            }, 200);
                        } else {
                            shouldReload = true;
                        }
                    }
                    lastVideo = newVideo;
                }

                // Check for playback state changes
                if (playbackData.success && playbackData.state !== lastPlaybackState) {
                    console.log('Playback state changed from', lastPlaybackState, 'to', playbackData.state);
                    lastPlaybackState = playbackData.state;

                    // Handle playback state changes
                    if (playbackData.state === 'play' && player) {
                        console.log('Playback state is play, attempting to start video');
                        attemptAutoPlay(player);
                        hideBlackout();
                    } else if (playbackData.state === 'pause' && player) {
                        console.log('Pausing video');
                        player.pause();
                        // Keep the last frame but do not force blackout here
                    } else if (playbackData.state === 'stop') {
                        console.log('Stopping video');
                        if (player) {
                            try { player.pause(); } catch(_) {}
                            try { player.currentTime(0); } catch(_) {}
                        }
                        stopAndBlackout();
                    }
                }

                // Reload page if video changed
                if (shouldReload) {
                    window.location.reload();
                }
            })
            .catch(error => {
                console.warn('API check failed:', error.message);
                console.warn('This might be due to server restrictions (403 Forbidden)');
                console.warn('Trying alternative approach...');

                // Fallback: try direct file access approach
                tryDirectStateAccess();
            })
            .finally(() => {
                isChecking = false;
            });
        }

        // Fallback function for direct state access when API is blocked
        function tryDirectStateAccess() {
            console.log('Attempting direct state access...');

            // Try using a simple direct endpoint that bypasses API restrictions
            fetch('state_direct.php?action=get_state&' + screenProfileQuery + '&t=' + Date.now())
                .then(r => {
                    if (!r.ok) {
                        throw new Error(`HTTP ${r.status}: ${r.statusText}`);
                    }
                    return r.json();
                })
                .then(data => {
                    console.log('Direct state access successful:', data);

                    let shouldReload = false;

                    // Check for video changes
                    if (data.currentVideo && data.currentVideo.filename !== lastVideo) {
                        console.log('Video changed from', lastVideo, 'to', data.currentVideo.filename);
                        shouldReload = true;
                    }
                    lastVideo = data.currentVideo ? data.currentVideo.filename : lastVideo;

                    // Check for playback state changes
                    if (data.playbackState !== lastPlaybackState) {
                        console.log('Playback state changed from', lastPlaybackState, 'to', data.playbackState);
                        lastPlaybackState = data.playbackState;

                        // Handle playback state changes
                        if (data.playbackState === 'play' && player) {
                            console.log('Playback state is play via direct access, attempting to start video');
                            attemptAutoPlay(player);
                            hideBlackout();
                        } else if (data.playbackState === 'pause' && player) {
                            console.log('Pausing video via direct access');
                            player.pause();
                        } else if (data.playbackState === 'stop') {
                            console.log('Stopping video via direct access');
                            if (player) {
                                try { player.pause(); } catch(_) {}
                                try { player.currentTime(0); } catch(_) {}
                            }
                            stopAndBlackout();
                        }
                    }

                    if (shouldReload) {
                        window.location.reload();
                    }
                })
                .catch(error => {
                    console.warn('Direct state access failed:', error.message);
                    console.warn('All methods failed - screen will not auto-update');
                    console.warn('Consider these solutions:');
                    console.warn('1. Start XAMPP Apache server');
                    console.warn('2. Check server configuration for 403 restrictions');
                    console.warn('3. Remove or fix .htaccess file');
                    console.warn('4. Check file permissions');
                });
        }

        // Alternative polling with different approach
        function alternativePolling() {
            // Try using different URL patterns to avoid 403
            const alternativeUrls = [
                'api.php?action=get_current_video&' + screenProfileQuery,
                'api.php?action=get_playback_state&' + screenProfileQuery
            ];

            // This would need more complex implementation
            console.log('Alternative polling not implemented yet');
        }

        // Start polling for video changes and playback state
        // Initial check followed by interval polling every 3 seconds
        try {
            checkForVideoChanges();
            setInterval(checkForVideoChanges, 3000);
            console.log('Polling enabled: checking for updates every 3 seconds');

            // Also poll for a refresh signal to catch config/profile directory changes
            const checkRefreshSignal = () => {
                fetch('api.php?action=check_refresh_signal&' + screenProfileQuery + '&t=' + Date.now())
                    .then(r => r.ok ? r.json() : { success: false })
                    .then(data => {
                        if (data && data.success && data.should_refresh) {
                            console.log('Refresh signal detected - reloading screen');
                            window.location.reload();
                        }
                    })
                    .catch(() => {});
            };
            checkRefreshSignal();
            setInterval(checkRefreshSignal, 3000);
        } catch (e) {
            console.log('Failed to start polling:', e.message);
        }

        // All unmute attempts now handled by single playing event handler

        console.log('Screen initialization complete');
    });
    </script>
</body>
</html>
