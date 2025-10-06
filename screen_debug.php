<?php
// screen_native_wrapper.php - Minimal player page to test native wrapper behavior
// Loads current (or provided) video via video.php and offers explicit user-gesture controls

require_once __DIR__ . '/state_manager.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Resolve profile and current video
$screenProfile = 'default';
if (isset($_GET['profile']) && $_GET['profile'] !== '') {
    $screenProfile = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_GET['profile']);
} elseif (isset($_GET['d'])) {
    $n = (int)($_GET['d'] ?? 0);
    $screenProfile = ($n === 0) ? 'default' : ('dashboard' . $n);
} elseif (isset($_GET['dashboard']) && $_GET['dashboard'] !== '') {
    $screenProfile = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_GET['dashboard']);
}

$fileParam = isset($_GET['file']) ? (string)$_GET['file'] : '';
$dirIndexParam = isset($_GET['dirIndex']) ? (int)$_GET['dirIndex'] : null;

if ($fileParam === '') {
    $currentVideo = getCurrentVideoForProfile($screenProfile);
    $videoFile = $currentVideo['filename'] ?? '';
    $dirIndex = $currentVideo['dirIndex'] ?? 0;
} else {
    $videoFile = $fileParam;
    $dirIndex = $dirIndexParam !== null ? $dirIndexParam : 0;
}

// Build source URL via video.php
$videoUrl = 'video.php?file=' . rawurlencode($videoFile) . '&dirIndex=' . $dirIndex;
$ext = strtolower(pathinfo($videoFile, PATHINFO_EXTENSION));
if ($ext === 'mkv') {
    $videoUrl .= '&forcemime=mp4';
}

$pageTitle = $videoFile ? pathinfo($videoFile, PATHINFO_FILENAME) : 'Native Wrapper Test';
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
    <title><?php echo htmlspecialchars($pageTitle); ?> - Native Wrapper</title>
    <style>
        html, body { height: 100%; margin: 0; background: #000; color: #ddd; font-family: Arial, sans-serif; }
        .wrap { display: grid; grid-template-rows: auto 1fr auto; height: 100%; }
        .controls { padding: 8px; background: #111; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .controls button { background: #2a2a2a; color: #fff; border: 1px solid #444; padding: 8px 10px; cursor: pointer; }
        .controls input[type=range] { width: 140px; }
        .stage { position: relative; background: #000; }
        video { width: 100%; height: 100%; object-fit: contain; background: #000; }
        .log { height: 160px; overflow: auto; background: #0b0b0b; font-size: 12px; padding: 8px; white-space: pre-wrap; }
        .pill { padding: 2px 6px; border-radius: 10px; border: 1px solid #555; background:#1a1a1a; margin-left: 8px; font-size: 11px; }
        .badge { color: #8ad; }
        .overlay { position: absolute; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.6); z-index: 10; }
        .overlay .card { background: #111; border: 1px solid #333; padding: 16px 20px; border-radius: 8px; text-align: center; }
        .overlay .card button { background: #3a6; border: none; padding: 10px 16px; color: #fff; cursor: pointer; font-size: 14px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="controls">
            <div>
                <strong>Source:</strong>
                <span class="badge"><?php echo htmlspecialchars($videoFile ?: '(none)'); ?></span>
                <span class="pill" id="ua-pill">UA: -</span>
                <span class="pill" id="act-pill">Activation: -</span>
            </div>
            <div style="flex:1"></div>
            <button id="btn-play-muted">Play (muted)</button>
            <button id="btn-play-audio">Play with sound</button>
            <button id="btn-unmute">Unmute</button>
            <button id="btn-pause">Pause</button>
            <button id="btn-fs">Fullscreen</button>
            <label style="margin-left:8px;">Volume <input id="vol" type="range" min="0" max="100" value="50"></label>
        </div>
        <div class="stage">
            <video id="v" autoplay playsinline preload="auto" <?php echo isset($_GET['controls']) ? 'controls' : ''; ?>>
                <source id="src" src="<?php echo htmlspecialchars($videoUrl); ?>">
                Your browser does not support HTML5 video.
            </video>
            <div id="audioOverlay" class="overlay"><div class="card">
                <div>Audio is blocked by the browser. Click to enable sound.</div>
                <div style="margin-top:10px;"><button id="btn-enable-audio">Enable Audio</button></div>
            </div></div>
        </div>
        <div class="log" id="log"></div>
    </div>

    <script>
    (function(){
        const video = document.getElementById('v');
        const logEl = document.getElementById('log');
        const vol = document.getElementById('vol');
        const audioOverlay = document.getElementById('audioOverlay');
        const btnEnableAudio = document.getElementById('btn-enable-audio');

        function log(msg) {
            try {
                const line = '[' + new Date().toISOString() + '] ' + msg + '\n';
                logEl.textContent += line;
                logEl.scrollTop = logEl.scrollHeight;
                console.log(msg);
            } catch (_) {}
        }

        function updateUA() {
            try { document.getElementById('ua-pill').textContent = 'UA: ' + (navigator.userAgent || '-'); } catch(_) {}
            try {
                const act = (navigator.userActivation && (navigator.userActivation.isActive ? 'active' : 'inactive')) || 'n/a';
                document.getElementById('act-pill').textContent = 'Activation: ' + act;
            } catch(_) {}
        }
        updateUA();

        function applyVolume() {
            const v = Math.max(0, Math.min(100, Number(vol.value || 0)));
            try { video.volume = v / 100; } catch(_) {}
            log('Volume set: ' + v);
        }
        vol.addEventListener('input', applyVolume);

        document.getElementById('btn-play-muted').addEventListener('click', function(){
            try { video.muted = true; } catch(_) {}
            try { video.play().then(()=> log('Play muted OK')).catch(e=> log('Play muted failed: ' + (e && e.name))); } catch(e){ log('Play muted threw: ' + (e && e.name)); }
        });
        document.getElementById('btn-play-audio').addEventListener('click', function(){
            try { video.muted = false; video.removeAttribute('muted'); } catch(_) {}
            applyVolume();
            try { video.play().then(()=> log('Play with audio OK')).catch(e=> log('Play with audio failed: ' + (e && e.name))); } catch(e){ log('Play audio threw: ' + (e && e.name)); }
        });
        btnEnableAudio.addEventListener('click', function(){
            try { video.muted = false; video.removeAttribute('muted'); } catch(_) {}
            applyVolume();
            try {
                video.play().then(()=>{ log('User enabled audio'); audioOverlay.style.display = 'none'; })
                .catch(e=>{ log('Enable audio failed: ' + (e && e.name)); });
            } catch(e){ log('Enable audio threw: ' + (e && e.name)); }
        });
        document.getElementById('btn-unmute').addEventListener('click', function(){
            try { video.muted = false; video.removeAttribute('muted'); log('Unmuted'); } catch(_) {}
        });
        document.getElementById('btn-pause').addEventListener('click', function(){
            try { video.pause(); log('Paused'); } catch(_) {}
        });
        document.getElementById('btn-fs').addEventListener('click', function(){
            try {
                if (video.requestFullscreen) return video.requestFullscreen();
                if (video.webkitRequestFullscreen) return video.webkitRequestFullscreen();
                if (video.msRequestFullscreen) return video.msRequestFullscreen();
            } catch(e) { log('Fullscreen failed: ' + e.message); }
        });

        // Events for diagnostics
        ['loadedmetadata','loadeddata','canplay','playing','pause','ended','error','volumechange','waiting','stalled','suspend','ratechange'].forEach(ev => {
            try { video.addEventListener(ev, () => log('Event: ' + ev)); } catch(_) {}
        });
        try { video.addEventListener('error', () => { try { log('Video error object: ' + (video.error && (video.error.message || video.error.code))); } catch(_) {} }); } catch(_) {}

        // Attempt initial UNMUTED autoplay (intended for native wrapper granting permission)
        try { video.volume = 0.5; } catch(_) {}
        try { video.muted = false; video.removeAttribute('muted'); } catch(_) {}
        try {
            video.play().then(()=>{
                log('Initial autoplay (with audio) OK');
            }).catch(e => {
                log('Initial unmuted autoplay failed: ' + (e && e.name) + ' — attempting muted fallback');
                try { video.muted = true; } catch(_) {}
                try {
                    video.play().then(()=> { log('Muted fallback OK'); audioOverlay.style.display = 'flex'; })
                    .catch(e2=> { log('Muted fallback failed: ' + (e2 && e2.name)); audioOverlay.style.display = 'flex'; });
                } catch(e2){ log('Muted fallback threw: ' + (e2 && e2.name)); audioOverlay.style.display = 'flex'; }
            });
        } catch (e) {
            log('Initial autoplay threw: ' + (e && e.name));
        }

        setInterval(updateUA, 2000);

        // === State polling (to mirror screen.php behavior) ===
        const screenProfile = <?php echo json_encode($screenProfile); ?>;
        const screenProfileQuery = 'profile=' + encodeURIComponent(screenProfile);
        let lastVideoName = <?php echo json_encode($videoFile); ?>;
        let lastDirIndex = <?php echo (int)$dirIndex; ?>;
        let lastPlaybackState = 'unknown';

        function buildVideoUrlJS(name, dirIndex) {
            const base = 'video.php?file=' + encodeURIComponent(name) + '&dirIndex=' + String(dirIndex || 0);
            const ext = String(name || '').split('.').pop().toLowerCase();
            if (ext === 'mkv') return base + '&forcemime=mp4';
            return base;
        }

        function applyAudioFromState(volPercent, muted) {
            try {
                const v = Math.max(0, Math.min(100, Number(volPercent)));
                video.volume = isFinite(v) ? (v / 100) : video.volume;
            } catch(_) {}
            try {
                if (muted === true) { video.muted = true; }
                else if (muted === false) { video.muted = false; video.removeAttribute('muted'); }
            } catch(_) {}
        }

        function checkState() {
            fetch('state_direct.php?action=get_state&' + screenProfileQuery + '&t=' + Date.now())
                .then(r => r.ok ? r.json() : null)
                .then(data => {
                    if (!data) return;

                    // Volume/mute sync
                    applyAudioFromState(data.volume, data.muted);

                    // Video change
                    const nv = data.currentVideo && data.currentVideo.filename ? data.currentVideo.filename : '';
                    const nd = data.currentVideo && typeof data.currentVideo.dirIndex !== 'undefined' ? (data.currentVideo.dirIndex || 0) : 0;
                    if (nv && (nv !== lastVideoName || nd !== lastDirIndex)) {
                        const url = buildVideoUrlJS(nv, nd);
                        try {
                            const srcEl = document.getElementById('src');
                            if (srcEl) srcEl.src = url;
                            video.pause();
                            video.load();
                        } catch(_) {}
                        lastVideoName = nv;
                        lastDirIndex = nd;
                    }

                    // Playback state
                    const ps = (data.playbackState || '').toLowerCase();
                    if (ps !== lastPlaybackState) {
                        lastPlaybackState = ps;
                    }
                    if (ps === 'play') {
                        // Try unmuted if wrapper allows
                        try { video.muted = false; video.removeAttribute('muted'); } catch(_) {}
                        try { video.play().catch(()=>{}); } catch(_) {}
                    } else if (ps === 'pause') {
                        try { video.pause(); } catch(_) {}
                    } else if (ps === 'stop') {
                        try { video.pause(); } catch(_) {}
                    }
                })
                .catch(() => {});
        }

        // Start polling
        try { checkState(); setInterval(checkState, 3000); } catch(_) {}
    })();
    </script>
</body>
</html>


