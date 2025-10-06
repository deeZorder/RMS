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
// Cache-control for dynamic screen (always fresh)
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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

// Resolve screen profile from query params (server-side to match client)
$screenProfile = 'default';
if (isset($_GET['profile']) && $_GET['profile'] !== '') {
    $screenProfile = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_GET['profile']);
} elseif (isset($_GET['d'])) {
    $n = (int)($_GET['d'] ?? 0);
    $screenProfile = ($n === 0) ? 'default' : ('dashboard' . $n);
} elseif (isset($_GET['dashboard']) && $_GET['dashboard'] !== '') {
    $screenProfile = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_GET['dashboard']);
}

// Get current video from state (with fallback if helper is unavailable to the analyzer)
if (!function_exists('getCurrentVideoForProfile')) {
    $currentVideo = ['filename' => '', 'dirIndex' => 0];
    $statePathTmp = __DIR__ . '/data/profiles/' . $screenProfile . '/state.json';
    if (file_exists($statePathTmp)) {
        $content = @file_get_contents($statePathTmp);
        if ($content !== false) {
            $st = json_decode($content, true);
            if (is_array($st) && isset($st['currentVideo']) && is_array($st['currentVideo'])) {
                $currentVideo = [
                    'filename' => (string)($st['currentVideo']['filename'] ?? ''),
                    'dirIndex' => (int)($st['currentVideo']['dirIndex'] ?? 0),
                ];
            }
        }
    }
} else {
    $currentVideo = getCurrentVideoForProfile($screenProfile);
}
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
$statePath = __DIR__ . '/data/profiles/' . $screenProfile . '/state.json';
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
    <style>
        html, body { margin:0; padding:0; height:100%; overflow:hidden; }
    </style>
    
    <!-- Video.js removed -->
</head>
<body>
    <div id="blackout" style="position:fixed; inset:0; background:#000; z-index:9999; display:<?php echo ($initialPlaybackState !== 'stop') ? 'none' : 'block'; ?>;"></div>
    <div class="screen-container">
        <div class="video-container">
            <?php if ($videoFile && file_exists($videoPath)): ?>
        <video 
            id="playback" 
            class="<?php echo $showControls ? '' : 'video-no-controls'; ?>" 
            <?php echo $showControls ? 'controls' : ''; ?> 
            autoplay 
            <?php 
                // Start unmuted by default on all devices
            ?>
            playsinline 
            preload="auto"
                >
<?php $renderUrl = $videoUrl; ?>
                    <source src="<?php echo htmlspecialchars($renderUrl); ?>">
                    <p>JavaScript required for video playback.</p>
        </video>
            <?php else: ?>
                <!-- Keep screen black when no video is selected or file is missing -->
            <?php endif; ?>
        </div>
    </div>

    <!-- Video.js removed -->

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const DEBUG = false;
        if (!DEBUG) { try { console.log = function(){}; } catch(_) {} }

        // Early maximize attempts
        try {
            if (window.screen && window.screen.width && window.screen.height) {
                try { window.resizeTo(window.screen.width, window.screen.height); } catch(_) {}
                try { window.moveTo(0, 0); } catch(_) {}
            }
            if (typeof window.maximize === 'function') { try { window.maximize(); } catch(_) {} }
            try { window.focus(); } catch(_) {}
        } catch (_) {}

        const videoEl = document.getElementById('playback');
            const blackoutEl = document.getElementById('blackout');

            function isOn(value) {
                try {
                    if (typeof value === 'string') return ['on','true','1','yes'].includes(value.toLowerCase());
                    if (typeof value === 'boolean') return value;
                    if (typeof value === 'number') return value !== 0;
                } catch(_) {}
                return false;
            }
            function normalizeOnOff(value, fallback) {
                return isOn(value) ? 'on' : (isOn(fallback) ? 'on' : 'off');
            }
            function buildVideoUrl(name, dirIndex) {
                const ts = Date.now();
                return 'video.php?file=' + encodeURIComponent(name) + '&dirIndex=' + String(dirIndex || 0) + '&t=' + ts;
            }

        // Profile and environment
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
        const isWebOS = /webos|web0s|lgwebos/i.test((navigator.userAgent || ''));
        const allowAudio = audioParam ? ['1','true','on','yes'].includes((audioParam||'').toLowerCase()) : (isWebOS ? true : false);

        let desiredMuted = false;
        let desiredVolume = 50;
        let hasUserInteraction = false;
        let delayedStartTimer = null;

        let lastVideo = '<?php echo $videoFile; ?>';
        let lastPlaybackState = '<?php echo $initialPlaybackState; ?>';
        let loopMode = 'off';
        let playAllMode = 'off';

        function showBlackout() { try { if (blackoutEl) blackoutEl.style.display = 'block'; } catch (_) {} }
        function hideBlackout() { try { if (blackoutEl) blackoutEl.style.display = 'none'; } catch (_) {} }
        function stopAndBlackout() {
            showBlackout();
            try {
                const el = document.getElementById('playback');
                if (el) {
                    try { el.pause(); } catch (_) {}
                    try { el.removeAttribute('src'); } catch (_) {}
                    try { while (el.firstChild) el.removeChild(el.firstChild); } catch (_) {}
                    try { el.load(); } catch (_) {}
                }
            } catch (_) {}
        }

            function nextVideoFlow() {
                const bases = ['api.php', 'state_direct.php'];
                function tryBase(i) {
                    if (i >= bases.length) { return Promise.resolve(); }
                    const base = bases[i];
                    return fetch(base + '?action=get_next_video&' + screenProfileQuery)
                        .then(r => r.json())
                        .then(data => {
                            if (!data || !data.nextVideo || !data.nextVideo.name) { throw new Error('no_next'); }
                            const nv = data.nextVideo;
                            const fd = new FormData();
                            fd.append('filename', nv.name);
                            fd.append('dirIndex', String(nv.dirIndex || 0));
                            fd.append('profile', screenProfile);
                            return fetch(base + '?action=set_current_video', { method: 'POST', body: fd })
                                .then(() => {
                                    const playFd = new FormData();
                                    playFd.append('profile', screenProfile);
                                    return fetch(base + '?action=play_video', { method: 'POST', body: playFd });
                                });
                        })
                        .catch(() => tryBase(i + 1));
                }
                return tryBase(0).catch(() => {});
            }

        function applyAudioSettings(volumePercent, isMuted) {
            try {
                const vol = Math.max(0, Math.min(100, Number(volumePercent || 0)));
                desiredVolume = vol;
                desiredMuted = !!isMuted;
                const effectiveMuted = !!isMuted;
                const tag = document.getElementById('playback');
                if (tag) {
                    try { tag.volume = (vol / 100); } catch (_) {}
                    try { tag.muted = effectiveMuted; } catch (_) {}
                    try { if (!effectiveMuted) { tag.removeAttribute('muted'); } } catch (_) {}
                }
                } catch (_) {}
        }

        function attemptAutoPlay(tag) {
            if (!tag) return;
            // Try unmuted first; fallback to muted if blocked
            try { tag.muted = false; tag.removeAttribute('muted'); } catch(_) {}
            try { if (typeof desiredVolume === 'number') { tag.volume = Math.max(0, Math.min(1, (desiredVolume || 0) / 100)); } } catch(_) {}
            try {
                tag.play().then(function(){ try { hideBlackout(); } catch(_) {} maximizeVideo(tag); })
                .catch(function(){
                    try { tag.muted = true; } catch(_) {}
                    try { tag.play().then(function(){ try { hideBlackout(); } catch(_) {} maximizeVideo(tag); }).catch(function(){}); } catch(_) {}
                });
            } catch(_) {}
        }
        function handlePlaybackEnded(tag) {
            if (window.__rmsEndedHandled) { return; }
            window.__rmsEndedHandled = true;
            if (isOn(loopMode)) {
                try { tag.currentTime = 0; } catch(_) {}
                attemptAutoPlay(tag);
                                        return;
                                    }
            if (isOn(playAllMode)) { nextVideoFlow(); }
        }
        function attachNativeEventHandlers(tag) {
            if (!tag) return;
            try { tag.addEventListener('error', function(){}); } catch(_) {}
            const hide = function(){ try { hideBlackout(); } catch(_) {} };
            try { tag.addEventListener('canplay', hide); } catch(_) {}
            try { tag.addEventListener('loadeddata', hide); } catch(_) {}
            try { tag.addEventListener('play', hide); } catch(_) {}
            try {
                tag.addEventListener('playing', function(){
                    try { hideBlackout(); } catch(_) {}
                    try {
                        if (isWebOS && allowAudio === true) {
                            tag.muted = false;
                            tag.removeAttribute('muted');
                            tag.play().catch(()=>{});
                            }
                        } catch(_) {}
                        try {
                            if (window.screen && window.screen.width && window.screen.height) {
                                try { window.resizeTo(window.screen.width, window.screen.height); } catch(_) {}
                                try { window.moveTo(0, 0); } catch(_) {}
                            }
                        } catch(_) {}
                        try { if (typeof window.maximize === 'function') { window.maximize(); } } catch(_) {}
                        try { window.focus(); } catch(_) {}
                    });
                } catch(_) {}
            try {
                tag.addEventListener('timeupdate', function(){
                    try { if (tag.currentTime > 0.2) { hideBlackout(); } } catch(_) {}
                    try {
                        const d = tag.duration || 0;
                        const t = tag.currentTime || 0;
                        if (d && t) {
                            if ((d - t) < 0.25) {
                                if (isOn(loopMode) && !window.__rmsLoopHandled) {
                                    window.__rmsLoopHandled = true;
                                    try { tag.currentTime = 0; } catch(_) {}
                                    try { attemptAutoPlay(tag); } catch(_) {}
                                    return;
                                }
                                if (!isOn(loopMode) && isOn(playAllMode) && !window.__rmsNearEndHandled) {
                                    window.__rmsNearEndHandled = true;
                                    nextVideoFlow();
                                }
                    } else {
                                window.__rmsLoopHandled = false;
                                window.__rmsNearEndHandled = false;
                                window.__rmsEndedHandled = false;
                            }
                        }
                    } catch(_) {}
                });
            } catch(_) {}
            try { tag.addEventListener('ended', function(){ handlePlaybackEnded(tag); }, { passive: true }); } catch(_) {}
        }
        function maximizeVideo(tag) {
            if (!tag) return;
            try {
                if (window.screen && window.screen.width && window.screen.height) {
                    try { window.resizeTo(window.screen.width, window.screen.height); } catch(_) {}
                    try { window.moveTo(0, 0); } catch(_) {}
                }
                if (typeof window.maximize === 'function') { try { window.maximize(); } catch(_) {} }
                try { window.focus(); } catch(_) {}
            } catch(_) {}
            try {
                const container = tag.parentElement || document.querySelector('.video-container');
                if (container) {
                    container.style.position = 'fixed';
                    container.style.top = '0';
                    container.style.left = '0';
                    container.style.width = '100vw';
                    container.style.height = '100vh';
                    container.style.zIndex = '9999';
                    container.style.background = 'black';
                    container.style.margin = '0';
                    container.style.padding = '0';
                    document.body.style.overflow = 'hidden';
                    document.documentElement.style.overflow = 'hidden';
                    document.body.style.margin = '0';
                    document.body.style.padding = '0';
                    document.documentElement.style.margin = '0';
                    document.documentElement.style.padding = '0';
                }
            } catch(_) {}
        }
        function bootPlayerWithCurrentVideo(filename, dirIndex) {
            try {
                const container = document.querySelector('.video-container');
                if (!container) return;
                const video = document.createElement('video');
                video.id = 'playback';
                video.className = <?php echo $showControls ? '""' : '"video-no-controls"'; ?>;
                if (<?php echo $showControls ? 'true' : 'false'; ?>) { video.setAttribute('controls', ''); }
                video.setAttribute('playsinline', '');
                video.setAttribute('preload', 'auto');
                // Do not force muted on boot; attempt unmuted autoplay first
                const src = document.createElement('source');
                src.src = 'video.php?file=' + encodeURIComponent(filename) + '&dirIndex=' + (dirIndex || 0) + '&t=' + Date.now();
                video.appendChild(src);
                container.innerHTML = '';
                container.appendChild(video);
                attachNativeEventHandlers(video);
                try { video.addEventListener('canplay', function once(){ video.removeEventListener('canplay', once); attemptAutoPlay(video); }); } catch(_) {}
            } catch (e) {
                console.log('Failed to boot player dynamically:', e.message);
            }
        }
        function fetchAndApplyAudio() {
            fetch('state_direct.php?action=get_state&' + screenProfileQuery + '&t=' + Date.now())
                .then(r => r.ok ? r.json() : { volume: 50, muted: false })
                .then(data => {
                    const volume = (typeof data.volume === 'number') ? data.volume : 50;
                    const muted = !!data.muted;
                    applyAudioSettings(volume, muted);
                })
                .catch(() => {});
        }
        fetchAndApplyAudio();
        if (lastPlaybackState === 'stop') { showBlackout(); } else { hideBlackout(); }
        if (videoEl) {
            const initialSrcEl = videoEl.querySelector('source');
            if (initialSrcEl && initialSrcEl.getAttribute('src')) {
                attachNativeEventHandlers(videoEl);
                if (lastPlaybackState === 'play') {
                    if (isWebOS) { attemptAutoPlay(videoEl); } else { scheduleDelayedStartIfNeeded(); }
                }
            }
        }
        function scheduleDelayedStartIfNeeded() {
            try { if (delayedStartTimer) { clearTimeout(delayedStartTimer); } } catch(_) {}
            delayedStartTimer = setTimeout(function(){
                try { const tag = document.getElementById('playback'); if (tag) attemptAutoPlay(tag); } catch(_) {}
                delayedStartTimer = null;
            }, 3000);
        }
        function grantAudioPermissionOnce() {
            try { hasUserInteraction = true; applyAudioSettings(desiredVolume, desiredMuted); } catch(_) {}
        }
        try { document.addEventListener('pointerdown', grantAudioPermissionOnce, { once: true }); } catch(_) {}
        try { document.addEventListener('keydown', grantAudioPermissionOnce, { once: true }); } catch(_) {}
        try { document.addEventListener('click', grantAudioPermissionOnce, { once: true }); } catch(_) {}
        let isChecking = false;
        function checkForVideoChanges() {
    if (isChecking) return;
    isChecking = true;
    fetch('state_direct.php?action=get_state&' + screenProfileQuery + '&t=' + Date.now())
        .then(r => {
            const ct = (r.headers && r.headers.get) ? (r.headers.get('content-type') || '') : '';
                    if (!r.ok || ct.indexOf('application/json') === -1) { throw new Error('non-json'); }
            return r.json();
        })
        .then(data => {
            let shouldReload = false;
            try {
                const nextVol = (typeof data.volume === 'number') ? data.volume : desiredVolume;
                const nextMuted = (typeof data.muted === 'boolean') ? data.muted : desiredMuted;
                applyAudioSettings(nextVol, nextMuted);
            } catch (_) {}
            loopMode = normalizeOnOff(data.loopMode, loopMode);
            playAllMode = normalizeOnOff(data.playAllMode, playAllMode);
            if (data.currentVideo && data.currentVideo.filename !== lastVideo) {
                const newName = data.currentVideo.filename || '';
                if (!newName) {
                            try { stopAndBlackout(); } catch(_) {}
                    lastVideo = '';
                } else {
                    console.log('Video changed from', lastVideo, 'to', newName, '- refreshing page');
                    shouldReload = true;
                }
            }
                    const ps = String(data.playbackState || '').toLowerCase();
                    if (ps && ps !== lastPlaybackState) {
                        console.log('Playback state changed from', lastPlaybackState, 'to', ps);
                        lastPlaybackState = ps;
                        const tag = document.getElementById('playback');
                        if (tag) {
                            if (ps === 'play') { try { attemptAutoPlay(tag); } catch(_) {} }
                            else if (ps === 'pause') { try { tag.pause(); } catch(_) {} }
                            else if (ps === 'stop') { stopAndBlackout(); }
                        }
                    }
            try {
                const trig = parseInt(data.lastRefreshTrigger || 0, 10) || 0;
                const flag = !!data.refreshRequested;
                const recent = (Date.now()/1000 - trig) < 30;
                        if (flag && recent) { window.location.reload(); }
                    } catch(_) {}
                    if (shouldReload) { window.location.reload(); }
                })
                .catch(() => {})
                .then(() => { isChecking = false; });
        }
        try { checkForVideoChanges(); setInterval(checkForVideoChanges, 3000); } catch(_) {}
    });
    </script>
</body>
</html>
