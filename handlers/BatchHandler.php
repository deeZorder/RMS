<?php

require_once __DIR__ . '/BaseHandler.php';

class BatchHandler extends BaseHandler {
    
    public function handle(string $action): void {
        switch ($action) {
            case 'get_dashboard_state':
                $this->getDashboardState();
                break;
            case 'get_control_state':
                $this->getControlState();
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown batch action']);
        }
    }
    
    /**
     * Get all state needed for dashboard initialization in one call
     */
    private function getDashboardState(): void {
        $state = loadState($this->profileId);
        
        $response = [
            'current_video' => $state['currentVideo'],
            'playback_state' => $state['playbackState'],
            'volume' => $state['volume'],
            'muted' => $state['muted'],
            'loop_mode' => $state['loopMode'],
            'play_all_mode' => $state['playAllMode'],
            'external_audio_mode' => $state['externalAudioMode'],
            'timestamp' => time()
        ];
        
        echo json_encode($response);
    }
    
    /**
     * Get all control states needed for UI updates in one call
     */
    private function getControlState(): void {
        $state = loadState($this->profileId);
        
        // Get video titles
        $titles = [];
        $videoTitlesPath = $this->baseDir . '/data/video_titles.json';
        if (file_exists($videoTitlesPath)) {
            $titles = json_decode(file_get_contents($videoTitlesPath), true) ?: [];
        }
        
        // Build state hash for change detection
        $stateHash = md5(
            $state['currentVideo']['filename'] . 
            $state['currentVideo']['dirIndex'] . 
            $state['playbackState'] . 
            $state['volume'] . 
            $state['muted'] . 
            $state['loopMode'] . 
            $state['playAllMode'] . 
            $state['externalAudioMode']
        );
        
        $response = [
            'current_video' => $state['currentVideo'],
            'playback_state' => $state['playbackState'],
            'volume' => $state['volume'],
            'muted' => $state['muted'],
            'loop_mode' => $state['loopMode'],
            'play_all_mode' => $state['playAllMode'],
            'external_audio_mode' => $state['externalAudioMode'],
            'video_titles' => $titles,
            'state_hash' => $stateHash,
            'last_changes' => [
                'scan' => $state['lastScan'],
                'config' => $state['lastConfigCheck'],
                'controls' => $state['lastControlChange']
            ],
            'timestamp' => time()
        ];
        
        echo json_encode($response);
    }
}
