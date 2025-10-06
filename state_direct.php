<?php
// Minimal direct state endpoint for polling when api.php is blocked
// Returns JSON with canonical keys: currentVideo, playbackState, volume, muted, loopMode, playAllMode

error_reporting(E_ALL);
ini_set('display_errors', '0');
if (function_exists('header_remove')) { header_remove(); }
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit();
}

try {
    $action = isset($_GET['action']) ? (string)$_GET['action'] : 'get_state';

    // Helpers
    $allowedVideoExts = ['mp4','webm','ogg','mov','mkv','avi','wmv','flv'];
    $resolveDirs = function() {
        $configPath = __DIR__ . '/config.json';
        $dirs = [];
        if (is_file($configPath)) {
            $cfg = json_decode(@file_get_contents($configPath), true);
            if (is_array($cfg)) {
                if (!empty($cfg['directories']) && is_array($cfg['directories'])) {
                    $dirs = $cfg['directories'];
                } elseif (!empty($cfg['directory'])) {
                    $dirs = [$cfg['directory']];
                }
            }
        }
        if (empty($dirs)) { $dirs = ['videos']; }
        $abs = [];
        foreach ($dirs as $d) {
            $isWinAbs = (strpos($d, ':\\') !== false);
            $isUnixAbs = (strlen($d) > 0 && $d[0] === '/');
            $p = $isWinAbs || $isUnixAbs ? $d : (__DIR__ . '/' . $d);
            $rp = realpath($p);
            $abs[] = $rp !== false ? $rp : $p;
        }
        return $abs;
    };

    // Write helpers
    $readJson = function($path) {
        if (!is_file($path)) { return null; }
        $raw = @file_get_contents($path);
        if ($raw === false) { return null; }
        $dec = json_decode($raw, true);
        return is_array($dec) ? $dec : null;
    };
    $writeJson = function($path, $data) {
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        return @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    };

    // Extra actions beyond get_state
    if ($action === 'get_next_video') {
        $profileId = isset($_GET['profile']) ? (string)$_GET['profile'] : 'default';
        $safeProfile = preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $statePath = __DIR__ . '/data/profiles/' . $safeProfile . '/state.json';
        $state = [ 'currentVideo' => ['filename' => '', 'dirIndex' => 0], 'loopMode' => 'off' ];
        $existing = $readJson($statePath);
        if (is_array($existing)) { $state = array_merge($state, $existing); }

        // Loop mode: return current again
        if (($state['loopMode'] ?? 'off') === 'on') {
            $cur = $state['currentVideo'] ?? ['filename' => '', 'dirIndex' => 0];
            if (!empty($cur['filename'])) {
                echo json_encode(['success' => true, 'nextVideo' => ['name' => (string)$cur['filename'], 'dirIndex' => (int)($cur['dirIndex'] ?? 0)]]);
                exit();
            }
        }

        $dirs = $resolveDirs();

        // Build library of all videos
        $all = [];
        foreach ($dirs as $i => $dir) {
            if (!is_dir($dir)) { continue; }
            $items = @scandir($dir);
            if ($items === false) { continue; }
            foreach ($items as $file) {
                if ($file === '.' || $file === '..') { continue; }
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $allowedVideoExts, true)) {
                    $all[] = [ 'name' => $file, 'dirIndex' => $i, 'dirPath' => $dir, 'key' => $dir . '|' . $file ];
                }
            }
        }

        // Load saved order if present
        $orderPath = __DIR__ . '/data/profiles/' . $safeProfile . '/video_order.json';
        $savedOrder = [];
        $orderData = $readJson($orderPath);
        if (is_array($orderData) && isset($orderData['order']) && is_array($orderData['order'])) { $savedOrder = $orderData['order']; }

        // Build ordered list from saved order
        $ordered = [];
        foreach ($savedOrder as $okey) {
            if (strpos($okey, '|') === false) continue;
            list($dirPath, $filename) = explode('|', $okey, 2);
            $di = array_search($dirPath, $dirs);
            if ($di !== false) { $ordered[] = ['name' => $filename, 'dirIndex' => (int)$di]; }
        }
        // Append any missing items (sorted for stability)
        $orderedKeys = [];
        foreach ($ordered as $o) { $orderedKeys[] = (isset($dirs[$o['dirIndex']]) ? $dirs[$o['dirIndex']] : '') . '|' . $o['name']; }
        $missing = array_values(array_filter($all, function($v) use ($orderedKeys){ return !in_array($v['key'], $orderedKeys, true); }));
        usort($missing, function($a,$b){ $c = strcmp($a['name'],$b['name']); return $c !== 0 ? $c : ($a['dirIndex'] <=> $b['dirIndex']); });
        foreach ($missing as $m) { $ordered[] = ['name'=>$m['name'], 'dirIndex'=>$m['dirIndex']]; }

        // Determine next
        $next = null;
        if (!empty($ordered)) {
            $cur = $state['currentVideo'] ?? ['filename' => '', 'dirIndex' => 0];
            if (empty($cur['filename'])) {
                $next = $ordered[0];
            } else {
                $idx = -1;
                foreach ($ordered as $k => $v) {
                    if ($v['name'] === $cur['filename'] && (int)$v['dirIndex'] === (int)$cur['dirIndex']) { $idx = $k; break; }
                }
                $next = $idx === -1 ? $ordered[0] : $ordered[($idx + 1) % count($ordered)];
            }
        }

        echo json_encode(['success' => true, 'nextVideo' => $next]);
        exit();
    }

    if ($action === 'set_current_video' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $profileId = $_POST['profile'] ?? 'default';
        $safeProfile = preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $baseDir = __DIR__ . '/data/profiles/' . $safeProfile;
        $statePath = $baseDir . '/state.json';
        $name = (string)($_POST['filename'] ?? '');
        $di = (int)($_POST['dirIndex'] ?? 0);
        $state = $readJson($statePath) ?: [];
        $state['currentVideo'] = ['filename' => $name, 'dirIndex' => $di];
        $state['lastControlChange'] = time();
        $ok = $writeJson($statePath, $state);
        echo json_encode(['success' => (bool)$ok]);
        exit();
    }

    if (in_array($action, ['play_video','pause_video','stop_video'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $profileId = $_POST['profile'] ?? 'default';
        $safeProfile = preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
        $baseDir = __DIR__ . '/data/profiles/' . $safeProfile;
        $statePath = $baseDir . '/state.json';
        $state = $readJson($statePath) ?: [];
        $map = ['play_video' => 'play', 'pause_video' => 'pause', 'stop_video' => 'stop'];
        $state['playbackState'] = $map[$action];
        $state['lastControlChange'] = time();
        $ok = $writeJson($statePath, $state);
        echo json_encode(['success' => (bool)$ok, 'playbackState' => $state['playbackState']]);
        exit();
    }

    if ($action !== 'get_state') {
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
        exit();
    }

    $profileId = isset($_GET['profile']) ? (string)$_GET['profile'] : 'default';
    $safeProfile = preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId);
    $baseDir = __DIR__ . '/data/profiles/' . $safeProfile;
    $statePath = $baseDir . '/state.json';
    $volumePath = $baseDir . '/volume.json';
    $mutePath = $baseDir . '/mute.json';

    $response = [
        'currentVideo' => ['filename' => '', 'dirIndex' => 0],
        'playbackState' => 'stop',
        'volume' => 50,
        'muted' => false,
        'loopMode' => 'off',
        'playAllMode' => 'off',
        'lastRefreshTrigger' => 0,
        'refreshRequested' => false,
    ];

    if (is_file($statePath)) {
        $stateRaw = @file_get_contents($statePath);
        if ($stateRaw !== false) {
            $state = json_decode($stateRaw, true);
            if (is_array($state)) {
                if (isset($state['currentVideo']) && is_array($state['currentVideo'])) {
                    $response['currentVideo'] = [
                        'filename' => (string)($state['currentVideo']['filename'] ?? ''),
                        'dirIndex' => (int)($state['currentVideo']['dirIndex'] ?? 0),
                    ];
                }
                if (isset($state['playbackState'])) {
                    $response['playbackState'] = (string)$state['playbackState'];
                }
                if (isset($state['volume']) && is_numeric($state['volume'])) {
                    $v = (int)$state['volume'];
                    if ($v >= 0 && $v <= 100) { $response['volume'] = $v; }
                }
                if (array_key_exists('muted', $state)) {
                    $response['muted'] = (bool)$state['muted'];
                }
                if (isset($state['loopMode']) && ($state['loopMode'] === 'on' || $state['loopMode'] === 'off')) {
                    $response['loopMode'] = $state['loopMode'];
                }
                if (isset($state['playAllMode']) && ($state['playAllMode'] === 'on' || $state['playAllMode'] === 'off')) {
                    $response['playAllMode'] = $state['playAllMode'];
                }
                if (isset($state['lastRefreshTrigger'])) {
                    $response['lastRefreshTrigger'] = (int)$state['lastRefreshTrigger'];
                }
                if (isset($state['refreshRequested'])) {
                    $response['refreshRequested'] = (bool)$state['refreshRequested'];
                }
            }
        }
    }

    // Optional overrides from separate files if present
    if (is_file($volumePath)) {
        $vol = json_decode(@file_get_contents($volumePath), true);
        if (is_array($vol) && isset($vol['volume'])) {
            $v = (int)$vol['volume'];
            if ($v >= 0 && $v <= 100) { $response['volume'] = $v; }
        }
    }
    if (is_file($mutePath)) {
        $mute = json_decode(@file_get_contents($mutePath), true);
        if (is_array($mute) && array_key_exists('muted', $mute)) {
            $response['muted'] = (bool)$mute['muted'];
        }
    }

    echo json_encode($response);
    exit();
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['error' => 'state_direct_failed', 'message' => $e->getMessage()]);
    exit();
}