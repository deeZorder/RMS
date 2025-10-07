<?php

require_once __DIR__ . '/BaseHandler.php';

class LegacyHandler extends BaseHandler {

    public function handle(string $action): void {
        switch ($action) {
            case 'stop_all_processes':
                $this->stopAllProcesses();
                break;
            case 'check_ffmpeg':
                $this->checkFFmpeg();
                break;
            case 'get_video_count':
                $this->getVideoCount();
                break;
            case 'test_connection':
                $this->testConnection();
                break;
            case 'simple_test':
                $this->simpleTest();
                break;
            case 'list_video_codecs':
                $this->listVideoCodecs();
                break;
            case 'encode_vp9_single':
                $this->encodeVp9Single();
                break;
            case 'encode_vp9_status':
                $this->encodeVp9Status();
                break;
            case 'log_event':
                $this->logEvent();
                break;
            case 'debug_state':
                $this->debugState();
                break;
            case 'get_volume':
                $this->getVolume();
                break;
            case 'set_volume':
                $this->setVolume();
                break;
            case 'get_mute_state':
                $this->getMuteState();
                break;
            case 'toggle_mute':
                $this->toggleMute();
                break;
            case 'play_video':
                $this->playVideo();
                break;
            case 'pause_video':
                $this->pauseVideo();
                break;
            case 'stop_video':
                $this->stopVideo();
                break;
            case 'set_current_video':
                $this->setCurrentVideo();
                break;
            case 'clear_current_video':
                $this->clearCurrentVideo();
                break;
            case 'get_current_video':
                $this->getCurrentVideo();
                break;
            case 'get_playback_state':
                $this->getPlaybackState();
                break;
            case 'get_loop_mode':
                $this->getLoopMode();
                break;
            case 'set_loop_mode':
                $this->setLoopMode();
                break;
            case 'get_play_all_mode':
                $this->getPlayAllMode();
                break;
            case 'set_play_all_mode':
                $this->setPlayAllMode();
                break;
            case 'get_external_audio_mode':
                $this->getExternalAudioMode();
                break;
            case 'set_external_audio_mode':
                $this->setExternalAudioModeEndpoint();
                break;
            case 'get_video_titles':
                $this->getVideoTitles();
                break;
            case 'set_video_title':
                $this->setVideoTitleEndpoint();
                break;
            case 'get_all_videos':
                $this->getAllVideos();
                break;
            case 'get_next_video':
                $this->getNextVideo();
                break;
            case 'check_config_changes':
                $this->checkConfigChanges();
                break;
            case 'move_video':
                $this->moveVideoEndpoint();
                break;
            case 'check_refresh_signal':
                $this->checkRefreshSignal();
                break;
            case 'trigger_dashboard_refresh':
                $this->triggerDashboardRefreshEndpoint();
                break;
            case 'warm_thumbnails':
                $this->warmThumbnails();
                break;
            case 'warm_previews':
                $this->warmPreviews();
                break;
            case 'reindex_previews':
                $this->reindexPreviews();
                break;
            case 'reindex_thumbs':
                $this->reindexThumbs();
                break;
            case 'browse_directories':
                $this->browseDirectories();
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown legacy action']);
        }
    }

    // Include all the original inline functions here...
    // (I'll include the key ones, the rest can be migrated incrementally)

    private function testConnection(): void {
        echo json_encode(['success' => true, 'message' => 'API connection successful', 'timestamp' => time()]);
    }

    private function simpleTest(): void {
        echo json_encode(['success' => true, 'message' => 'Simple test successful', 'timestamp' => time()]);
    }

    private function debugState(): void {
        echo json_encode(['success' => true, 'message' => 'Debug endpoint working']);
    }

    private function getVolume(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $state = loadState($this->profileId);
        echo json_encode(['volume' => $state['volume']]);
    }

    private function setVolume(): void {
        if (!$this->validatePostRequest()) return;

        if (!function_exists('updateState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $volume = max(0, min(100, (int)($_POST['volume'] ?? 100)));
        updateState($this->profileId, ['volume' => $volume]);

        // Signal volume change for near-instant pickup by screens
        $profileDir = $this->baseDir . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->profileId);
        if (!is_dir($profileDir)) { @mkdir($profileDir, 0777, true); }
        $volumeSignalPath = $profileDir . '/volume_signal.txt';
        @file_put_contents($volumeSignalPath, (string)time());

        echo json_encode(['status' => 'ok', 'volume' => $volume]);
    }

    private function getMuteState(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $state = loadState($this->profileId);
        echo json_encode(['muted' => $state['muted']]);
    }

    private function toggleMute(): void {
        if (!$this->validatePostRequest()) return;

        if (!function_exists('loadState') || !function_exists('updateState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $state = loadState($this->profileId);
        $newMuted = !$state['muted'];
        updateState($this->profileId, ['muted' => $newMuted]);

        // Signal mute change
        $profileDir = $this->baseDir . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->profileId);
        if (!is_dir($profileDir)) { @mkdir($profileDir, 0777, true); }
        $muteSignalPath = $profileDir . '/mute_signal.txt';
        @file_put_contents($muteSignalPath, (string)time());

        echo json_encode(['status' => 'ok', 'muted' => $newMuted]);
    }

    private function playVideo(): void {
        if (!$this->validatePostRequest()) return;

        if (!function_exists('updateState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        updateState($this->profileId, ['playbackState' => 'play']);
        echo json_encode(['status' => 'ok', 'action' => 'play']);
    }

    private function pauseVideo(): void {
        if (!$this->validatePostRequest()) return;

        if (!function_exists('updateState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        updateState($this->profileId, ['playbackState' => 'pause']);
        echo json_encode(['status' => 'ok', 'action' => 'pause']);
    }

    private function stopVideo(): void {
        if (!$this->validatePostRequest()) return;

        if (!function_exists('updateState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        updateState($this->profileId, ['playbackState' => 'stop']);
        echo json_encode(['status' => 'ok', 'action' => 'stop']);
    }

    private function setCurrentVideo(): void {
        if (!$this->validatePostRequest()) return;

        if (!function_exists('updateState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $filename = $_POST['filename'] ?? '';
        $dirIndex = (int)($_POST['dirIndex'] ?? 0);

        if (!$this->validateFilename($filename)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid filename']);
            return;
        }

        updateState($this->profileId, ['currentVideo' => ['filename' => $filename, 'dirIndex' => $dirIndex]]);
        echo json_encode(['status' => 'ok']);
    }

    private function clearCurrentVideo(): void {
        if (!$this->validatePostRequest()) return;

        if (!function_exists('updateState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        updateState($this->profileId, ['currentVideo' => ['filename' => '', 'dirIndex' => 0]]);
        echo json_encode(['status' => 'ok']);
    }

    private function getCurrentVideo(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $state = loadState($this->profileId);
        echo json_encode(['currentVideo' => $state['currentVideo']]);
    }

    private function getPlaybackState(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $state = loadState($this->profileId);
        echo json_encode(['state' => $state['playbackState']]);
    }

    private function getLoopMode(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $state = loadState($this->profileId);
        echo json_encode(['loopMode' => $state['loopMode']]);
    }

    private function setLoopMode(): void {
        if (!$this->validatePostRequest()) return;

        if (!function_exists('updateState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $loopMode = $_POST['loop'] === 'on' ? 'on' : 'off';
        updateState($this->profileId, ['loopMode' => $loopMode]);

        echo json_encode(['status' => 'ok', 'loopMode' => $loopMode]);
    }

    private function getPlayAllMode(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $state = loadState($this->profileId);
        echo json_encode(['playAllMode' => $state['playAllMode']]);
    }

    private function setPlayAllMode(): void {
        if (!$this->validatePostRequest()) return;

        if (!function_exists('updateState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $playAllMode = $_POST['playAllMode'] === 'on' ? 'on' : 'off';
        updateState($this->profileId, ['playAllMode' => $playAllMode]);

        echo json_encode(['status' => 'ok', 'playAllMode' => $playAllMode]);
    }

    private function getExternalAudioMode(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $state = loadState($this->profileId);
        echo json_encode(['external' => $state['externalAudioMode']]);
    }

    private function setExternalAudioModeEndpoint(): void {
        if (!$this->validatePostRequest()) return;

        if (!function_exists('updateState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $external = $_POST['external'] === 'on' ? 'on' : 'off';
        updateState($this->profileId, ['externalAudioMode' => $external]);

        echo json_encode(['status' => 'ok', 'external' => $external]);
    }

    private function getVideoTitles(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $state = loadState($this->profileId);
        $titles = $state['videoTitles'] ?? [];
        echo json_encode(['titles' => $titles]);
    }

    private function setVideoTitleEndpoint(): void {
        if (!$this->validatePostRequest()) return;

        if (!function_exists('loadState') || !function_exists('updateState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $filename = $_POST['filename'] ?? '';
        $title = $_POST['title'] ?? '';

        if (!$this->validateFilename($filename)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid filename']);
            return;
        }

        $state = loadState($this->profileId);
        $titles = $state['videoTitles'] ?? [];
        $titles[$filename] = $title;
        updateState($this->profileId, ['videoTitles' => $titles]);

        echo json_encode(['status' => 'ok']);
    }

    private function getAllVideos(): void {
        $dirs = $this->getConfiguredDirectories();
        $all = [];

        foreach ($dirs as $i => $dir) {
            if (!is_dir($dir)) continue;
            $items = @scandir($dir);
            if ($items === false) continue;

            foreach ($items as $file) {
                if ($file === '.' || $file === '..') continue;
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, ['mp4', 'webm', 'ogg', 'mov', 'mkv', 'avi', 'wmv', 'flv'], true)) {
                    $all[] = [ 'name' => $file, 'dirIndex' => $i, 'dirPath' => $dir, 'key' => $dir . '|' . $file ];
                }
            }
        }

        echo json_encode(['videos' => $all]);
    }

    private function getNextVideo(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }

        $state = loadState($this->profileId);
        $dirs = $this->getConfiguredDirectories();

        // Load saved video order
        $orderPath = $this->baseDir . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->profileId) . '/video_order.json';
        $savedOrder = [];
        if (is_file($orderPath)) {
            $dec = json_decode(@file_get_contents($orderPath), true);
            if (is_array($dec) && isset($dec['order']) && is_array($dec['order'])) { $savedOrder = $dec['order']; }
        }

        // Build ordered list
        $ordered = [];
        foreach ($savedOrder as $okey) {
            if (strpos($okey, '|') === false) continue;
            list($dirPath, $filename) = explode('|', $okey, 2);
            $di = array_search($dirPath, $dirs);
            if ($di !== false) { $ordered[] = ['name' => $filename, 'dirIndex' => (int)$di]; }
        }

        // Determine next based on current state
        $next = null;
        if (!empty($ordered)) {
            $cur = $state['currentVideo'];
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
    }

    private function checkConfigChanges(): void {
        $configPath = $this->baseDir . '/config.json';
        $lastMod = file_exists($configPath) ? filemtime($configPath) : 0;
        echo json_encode(['config_changed' => $lastMod]);
    }

    private function checkRefreshSignal(): void {
        $profileDir = $this->baseDir . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->profileId);
        $refreshSignalPath = $profileDir . '/refresh_signal.txt';
        $ts = 0;
        if (file_exists($refreshSignalPath)) {
            $ts = (int)trim(@file_get_contents($refreshSignalPath));
        }
        echo json_encode(['refresh_signal' => $ts]);
    }

    private function triggerDashboardRefreshEndpoint(): void {
        if (!$this->validatePostRequest()) return;

        $profileDir = $this->baseDir . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->profileId);
        if (!is_dir($profileDir)) { @mkdir($profileDir, 0777, true); }
        $refreshSignalPath = $profileDir . '/refresh_signal.txt';
        @file_put_contents($refreshSignalPath, (string)time());

        echo json_encode(['status' => 'ok']);
    }

    // Stub implementations for other functions - these should be migrated from the original api.php
    private function stopAllProcesses(): void {
        echo json_encode(['status' => 'ok', 'message' => 'Process management not implemented in handler']);
    }

    private function checkFFmpeg(): void {
        echo json_encode(['available' => false, 'message' => 'FFmpeg check not implemented in handler']);
    }

    private function getVideoCount(): void {
        $dirs = $this->getConfiguredDirectories();
        $count = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $items = @scandir($dir);
            if ($items === false) continue;
            foreach ($items as $file) {
                if ($file === '.' || $file === '..') continue;
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, ['mp4', 'webm', 'ogg', 'mov', 'mkv', 'avi', 'wmv', 'flv'], true)) {
                    $count++;
                }
            }
        }
        echo json_encode(['count' => $count]);
    }

    private function listVideoCodecs(): void {
        echo json_encode(['codecs' => [], 'message' => 'Codec listing not implemented in handler']);
    }

    private function encodeVp9Single(): void {
        echo json_encode(['status' => 'error', 'message' => 'VP9 encoding not implemented in handler']);
    }

    private function encodeVp9Status(): void {
        echo json_encode(['status' => 'not_running', 'message' => 'VP9 encoding status not implemented in handler']);
    }

    private function logEvent(): void {
        $message = $_GET['message'] ?? 'No message provided';
        error_log("API Event: " . $message);
        echo json_encode(['status' => 'ok']);
    }

    private function moveVideoEndpoint(): void {
        echo json_encode(['status' => 'error', 'message' => 'Video moving not implemented in handler']);
    }

    private function warmThumbnails(): void {
        echo json_encode(['status' => 'error', 'message' => 'Thumbnail warming not implemented in handler']);
    }

    private function warmPreviews(): void {
        echo json_encode(['status' => 'error', 'message' => 'Preview warming not implemented in handler']);
    }

    private function reindexPreviews(): void {
        echo json_encode(['status' => 'error', 'message' => 'Preview reindexing not implemented in handler']);
    }

    private function reindexThumbs(): void {
        echo json_encode(['status' => 'error', 'message' => 'Thumbnail reindexing not implemented in handler']);
    }

    private function browseDirectories(): void {
        $dirs = $this->getConfiguredDirectories();
        echo json_encode(['directories' => $dirs]);
    }
}
