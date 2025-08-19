<?php

require_once __DIR__ . '/BaseHandler.php';

class DebugHandler extends BaseHandler {
    
    public function handle(string $action): void {
        switch ($action) {
            case 'debug_api':
                $this->debugApi();
                break;
            case 'clear_cache':
                $this->clearCache();
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown debug action']);
        }
    }
    
    /**
     * Debug API status and return diagnostic information
     */
    private function debugApi(): void {
        $diagnostics = [
            'status' => 'ok',
            'timestamp' => time(),
            'profile' => $this->profileId,
            'baseDir' => $this->baseDir,
            'phpVersion' => PHP_VERSION,
            'memoryUsage' => memory_get_usage(true),
            'memoryPeak' => memory_get_peak_usage(true),
        ];
        
        // Test state loading
        try {
            $state = loadState($this->profileId);
            $diagnostics['stateLoaded'] = true;
            $diagnostics['currentState'] = $state;
        } catch (Exception $e) {
            $diagnostics['stateLoaded'] = false;
            $diagnostics['stateError'] = $e->getMessage();
        }
        
        // Check directories
        $dataDir = $this->baseDir . '/data';
        $profilesDir = $dataDir . '/profiles';
        $profileDir = $profilesDir . '/' . $this->profileId;
        
        $diagnostics['directories'] = [
            'data' => is_dir($dataDir) ? 'exists' : 'missing',
            'profiles' => is_dir($profilesDir) ? 'exists' : 'missing',
            'profile' => is_dir($profileDir) ? 'exists' : 'missing'
        ];
        
        // Check file permissions
        $diagnostics['permissions'] = [
            'data_writable' => is_writable($dataDir),
            'profiles_writable' => is_dir($profilesDir) ? is_writable($profilesDir) : false,
            'profile_writable' => is_dir($profileDir) ? is_writable($profileDir) : false
        ];
        
        echo json_encode($diagnostics);
    }
    
    /**
     * Clear all cached state files to resolve corruption issues
     */
    private function clearCache(): void {
        if (!$this->validatePostRequest()) return;
        
        $cleared = [];
        $errors = [];
        
        // Clear profile state files
        $profilesDir = $this->baseDir . '/data/profiles';
        if (is_dir($profilesDir)) {
            $profiles = scandir($profilesDir);
            foreach ($profiles as $profile) {
                if ($profile === '.' || $profile === '..') continue;
                
                $profileDir = $profilesDir . '/' . $profile;
                if (is_dir($profileDir)) {
                    $stateFile = $profileDir . '/state.json';
                    if (file_exists($stateFile)) {
                        if (@unlink($stateFile)) {
                            $cleared[] = "Profile: $profile state.json";
                        } else {
                            $errors[] = "Failed to delete: $profile state.json";
                        }
                    }
                }
            }
        }
        
        // Clear admin cache
        $adminCache = $this->baseDir . '/data/admin_cache.json';
        if (file_exists($adminCache)) {
            if (@unlink($adminCache)) {
                $cleared[] = 'admin_cache.json';
            } else {
                $errors[] = 'Failed to delete: admin_cache.json';
            }
        }
        
        echo json_encode([
            'status' => 'ok',
            'cleared' => $cleared,
            'errors' => $errors,
            'message' => count($cleared) . ' files cleared, ' . count($errors) . ' errors'
        ]);
    }
}
