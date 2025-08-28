<?php
// Minimal direct state endpoint for polling when api.php is blocked
// Returns JSON: { currentVideo: { filename, dirIndex }, playbackState, volume, muted }

// Ensure clean JSON output only
error_reporting(E_ALL);
ini_set('display_errors', '0');
if (function_exists('header_remove')) { header_remove(); }
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit();
}

try {
    $action = $_GET['action'] ?? 'get_state';
    if ($action !== 'get_state') {
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
        exit();
    }

    $profileId = $_GET['profile'] ?? 'default';
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
                if (isset($state['muted'])) {
                    $response['muted'] = (bool)$state['muted'];
                }
            }
        }
    }

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
