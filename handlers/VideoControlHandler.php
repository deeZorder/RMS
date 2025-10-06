<?php

require_once __DIR__ . '/BaseHandler.php';

class VideoControlHandler extends BaseHandler {
    
    public function handle(string $action): void {
        switch ($action) {
            case 'play_video':
                $this->playVideo();
                break;
            case 'pause_video':
                $this->pauseVideo();
                break;
            case 'stop_video':
                $this->stopVideo();
                break;
            case 'get_playback_state':
                $this->getPlaybackState();
                break;
            case 'set_volume':
                $this->setVolume();
                break;
            case 'get_volume':
                $this->getVolume();
                break;
            case 'toggle_mute':
                $this->toggleMute();
                break;
            case 'get_mute_state':
                $this->getMuteState();
                break;
            case 'set_loop_mode':
                $this->setLoopMode();
                break;
            case 'get_loop_mode':
                $this->getLoopMode();
                break;
            case 'set_play_all_mode':
                $this->setPlayAllMode();
                break;
            case 'get_play_all_mode':
                $this->getPlayAllMode();
                break;
            case 'set_external_audio_mode':
                $this->setExternalAudioMode();
                break;
            case 'get_external_audio_mode':
                $this->getExternalAudioMode();
                break;
            case 'check_mute_signal':
                $this->checkMuteSignal();
                break;
            case 'check_volume_signal':
                $this->checkVolumeSignal();
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown video control action']);
        }
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
    
    private function getPlaybackState(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }
        
        $state = loadState($this->profileId);
        echo json_encode(['state' => $state['playbackState']]);
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
    
    private function getVolume(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }
        
        $state = loadState($this->profileId);
        echo json_encode(['volume' => $state['volume']]);
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
    
    private function getMuteState(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }
        
        $state = loadState($this->profileId);
        echo json_encode(['muted' => $state['muted']]);
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
    
    private function getLoopMode(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }
        
        $state = loadState($this->profileId);
        echo json_encode(['loopMode' => $state['loopMode']]);
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
    
    private function getPlayAllMode(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }
        
        $state = loadState($this->profileId);
        echo json_encode(['playAllMode' => $state['playAllMode']]);
    }
    
    private function setExternalAudioMode(): void {
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
    
    private function getExternalAudioMode(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }
        
        $state = loadState($this->profileId);
        echo json_encode(['external' => $state['externalAudioMode']]);
    }
    
    private function checkMuteSignal(): void {
        $profileDir = $this->baseDir . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->profileId);
        $muteSignalPath = $profileDir . '/mute_signal.txt';
        $ts = 0;
        if (file_exists($muteSignalPath)) {
            $ts = (int)trim(@file_get_contents($muteSignalPath));
        }
        echo json_encode(['mute_signal' => $ts]);
    }
    
    private function checkVolumeSignal(): void {
        $profileDir = $this->baseDir . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->profileId);
        $volumeSignalPath = $profileDir . '/volume_signal.txt';
        $ts = 0;
        if (file_exists($volumeSignalPath)) {
            $ts = (int)trim(@file_get_contents($volumeSignalPath));
        }
        echo json_encode(['volume_signal' => $ts]);
    }
}
