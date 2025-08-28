<?php

require_once __DIR__ . '/BaseHandler.php';

class SystemHandler extends BaseHandler {
    
    public function handle(string $action): void {
        switch ($action) {
            case 'health':
                $this->health();
                break;
            case 'check_config_changes':
                $this->checkConfigChanges();
                break;
            case 'check_changes':
                $this->checkChanges();
                break;
            case 'get_config':
                $this->getConfig();
                break;
            case 'migrate_state':
                $this->migrateState();
                break;
            case 'get_state':
                $this->getState();
                break;
            case 'check_refresh_signal':
                $this->checkRefreshSignal();
                break;
            case 'trigger_dashboard_refresh':
                $this->triggerDashboardRefresh();
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown system action']);
        }
    }
    
    private function health(): void {
        echo json_encode([
            'status' => 'ok',
            'timestamp' => time(),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ]);
    }
    
    private function checkConfigChanges(): void {
        $configPath = $this->baseDir . '/config.json';
        $configModTime = file_exists($configPath) ? filemtime($configPath) : 0;
        $state = loadState($this->profileId);
        $lastCheckTime = $state['lastConfigCheck'];
        
        $needsRefresh = false;
        if ($configModTime > $lastCheckTime) {
            $needsRefresh = true;
            updateState($this->profileId, ['lastConfigCheck' => $configModTime]);
        }
        
        echo json_encode([
            'needsRefresh' => $needsRefresh, 
            'lastCheck' => $lastCheckTime, 
            'configModTime' => $configModTime
        ]);
    }
    
    private function checkChanges(): void {
        $state = loadState($this->profileId);
        $currentStateHash = md5(
            $state['currentVideo']['filename'] . 
            $state['currentVideo']['dirIndex'] . 
            $state['playbackState'] . 
            $state['volume'] . 
            $state['muted']
        );
        
        echo json_encode([
            'lastChanges' => [
                'scan' => $state['lastScan'],
                'config' => $state['lastConfigCheck'],
                'controls' => $state['lastControlChange']
            ],
            'currentState' => [
                'video' => $state['currentVideo']['filename'],
                'dirIndex' => $state['currentVideo']['dirIndex'],
                'playbackState' => $state['playbackState'],
                'volume' => $state['volume'],
                'muted' => $state['muted'],
                'stateHash' => $currentStateHash
            ],
            'timestamp' => time()
        ]);
    }
    
    private function getConfig(): void {
        echo json_encode($this->config);
    }
    
    private function migrateState(): void {
        if (!$this->validatePostRequest()) return;
        
        $migrateResult = migrateOldStateFiles($this->profileId);
        if ($migrateResult) {
            echo json_encode([
                'status' => 'ok', 
                'message' => 'State files migrated successfully for profile: ' . $this->profileId
            ]);
        } else {
            echo json_encode([
                'status' => 'error', 
                'message' => 'No old state files found for profile: ' . $this->profileId
            ]);
        }
    }
    
    private function getState(): void {
        $state = loadState($this->profileId);
        echo json_encode(['status' => 'ok', 'state' => $state]);
    }
    
    private function checkRefreshSignal(): void {
        $shouldRefresh = false;
        
        // Check state.json for refresh request
        $state = loadState($this->profileId);
        
        if (isset($state['refreshRequested']) && $state['refreshRequested'] === true) {
            $lastRefreshTrigger = isset($state['lastRefreshTrigger']) ? (int)$state['lastRefreshTrigger'] : 0;
            $currentTime = time();
            
            // If refresh was triggered within the last 30 seconds, trigger refresh
            if ($currentTime - $lastRefreshTrigger < 30) {
                $shouldRefresh = true;
                
                // Clear the refresh request flag to prevent repeated refreshes
                updateState($this->profileId, ['refreshRequested' => false]);
            }
        }
        
        // Fallback: Check legacy dashboard_refresh.txt files for backwards compatibility
        if (!$shouldRefresh) {
            $profileDir = $this->baseDir . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->profileId);
            $dashboardRefreshPath = $profileDir . '/dashboard_refresh.txt';
            
            $candidates = [ 
                $dashboardRefreshPath, 
                $this->baseDir . '/data/dashboard_refresh.txt', 
                $this->baseDir . '/dashboard_refresh.txt' 
            ];
            
            foreach ($candidates as $refreshFile) {
                if (file_exists($refreshFile)) {
                    $lastRefresh = (int)@file_get_contents($refreshFile);
                    $currentTime = time();
                    if ($currentTime - $lastRefresh < 10) {
                        $shouldRefresh = true;
                        @unlink($refreshFile);
                        break;
                    }
                }
            }
        }
        
        echo json_encode(['should_refresh' => $shouldRefresh]);
    }
    
    private function triggerDashboardRefresh(): void {
        if (!$this->validatePostRequest()) return;
        
        // Use state-based refresh mechanism
        $updates = [
            'lastRefreshTrigger' => time(),
            'refreshRequested' => true
        ];
        
        $success = updateState($this->profileId, $updates);
        
        if ($success) {
            echo json_encode(['status' => 'ok', 'message' => 'Refresh signal sent']);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to trigger refresh signal']);
        }
    }
}
