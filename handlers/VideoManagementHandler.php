<?php

require_once __DIR__ . '/BaseHandler.php';

// Ensure BaseHandler class is available for static analysis
if (!class_exists('BaseHandler')) {
    /**
     * Fallback BaseHandler class definition for static analysis (PHP 7.3 compatible)
     * This should not be used in practice as BaseHandler.php is included above
     */
    abstract class BaseHandler {
        protected $baseDir;
        protected $profileId;
        protected $config;
        
        public function __construct(string $baseDir, string $profileId) {
            $this->baseDir = $baseDir;
            $this->profileId = $profileId;
        }
        
        protected function validatePostRequest() {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                return false;
            }
            return true;
        }
        
        protected function validateFilename($filename) {
            return !empty($filename) && 
                   strpos($filename, '..') === false && 
                   strpos($filename, '/') === false && 
                   strpos($filename, '\\') === false;
        }
        
        protected function getConfiguredDirectories() {
            $dirs = [];
            if (!empty($this->config['directories']) && is_array($this->config['directories'])) {
                $dirs = $this->config['directories'];
            } elseif (!empty($this->config['directory'])) {
                $dirs = [$this->config['directory']];
            } else {
                $dirs = ['videos'];
            }
            
            $normalized = [];
            foreach ($dirs as $dir) {
                if (is_dir($dir)) {
                    $normalized[] = $dir;
                } elseif (is_dir($this->baseDir . '/' . $dir)) {
                    $normalized[] = $this->baseDir . '/' . $dir;
                }
            }
            return $normalized;
        }
        
        abstract public function handle(string $action): void;
    }
}

class VideoManagementHandler extends BaseHandler {
    
    public function handle(string $action): void {
        switch ($action) {
            case 'get_current_video':
                $this->getCurrentVideo();
                break;
            case 'set_current_video':
                $this->setCurrentVideo();
                break;
            case 'clear_current_video':
                $this->clearCurrentVideo();
                break;
            case 'get_next_video':
                $this->getNextVideo();
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown video management action']);
        }
    }
    
    private function getCurrentVideo(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }
        
        try {
            $state = loadState($this->profileId);
            if (empty($state['currentVideo']['filename'])) {
                echo json_encode(['currentVideo' => null]);
            } else {
                echo json_encode(['currentVideo' => $state['currentVideo']]);
            }
        } catch (Exception $e) {
            error_log("getCurrentVideo error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get current video']);
        }
    }
    
    private function setCurrentVideo(): void {
        if (!$this->validatePostRequest()) return;
        
        $filename = trim($_POST['filename'] ?? '');
        $dirIndex = isset($_POST['dirIndex']) ? (int)$_POST['dirIndex'] : 0;
        
        if (!$this->validateFilename($filename)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid filename']);
            return;
        }
        
        // Validate dir index against configured directories
        $dirs = $this->getConfiguredDirectories();
        if ($dirIndex < 0 || $dirIndex >= count($dirs)) { 
            $dirIndex = 0; 
        }
        
        if (!function_exists('updateState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }
        
        updateState($this->profileId, [
            'currentVideo' => ['filename' => $filename, 'dirIndex' => $dirIndex]
        ]);
        
        echo json_encode([
            'status' => 'ok', 
            'currentVideo' => ['filename' => $filename, 'dirIndex' => $dirIndex]
        ]);
    }
    
    private function clearCurrentVideo(): void {
        if (!$this->validatePostRequest()) return;
        
        if (!function_exists('updateState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }
        
        updateState($this->profileId, [
            'currentVideo' => ['filename' => '', 'dirIndex' => 0]
        ]);
        
        echo json_encode(['status' => 'ok', 'currentVideo' => '']);
    }
    
    private function getNextVideo(): void {
        if (!function_exists('loadState')) {
            http_response_code(500);
            echo json_encode(['error' => 'State management not available']);
            return;
        }
        
        $state = loadState($this->profileId);
        $dirs = $this->getConfiguredDirectories();
        $allowedExt = ['mp4', 'webm', 'ogg', 'mov'];
        $allWithMeta = $this->buildAllVideosList($dirs, $allowedExt);
        $savedOrder = $this->loadVideoOrder();

        // Parse the saved order to extract filenames and dirIndexes
        $orderedItems = [];
        foreach ($savedOrder as $orderKey) {
            if (strpos($orderKey, '|') !== false) {
                [$dirPath, $filename] = explode('|', $orderKey, 2);
                // Find the dirIndex for this directory
                $dirIndex = array_search($dirPath, $dirs);
                if ($dirIndex !== false) {
                    $orderedItems[] = ['name' => $filename, 'dirIndex' => $dirIndex];
                }
            }
        }

        // Add any missing videos from the current directory structure
        $existingKeys = array_column($allWithMeta, 'key');
        $existingSet = array_flip($existingKeys);
        $orderedKeys = [];
        foreach ($savedOrder as $k) { 
            if (isset($existingSet[$k])) { 
                $orderedKeys[] = $k; 
            } 
        }
        $missing = array_values(array_filter($allWithMeta, function ($v) use ($orderedKeys) { 
            return !in_array($v['key'], $orderedKeys, true); 
        }));
        usort($missing, function ($a, $b) {
            $c = strcmp($a['name'], $b['name']);
            return $c !== 0 ? $c : ($a['dirIndex'] <=> $b['dirIndex']);
        });
        foreach ($missing as $m) { 
            $orderedItems[] = ['name' => $m['name'], 'dirIndex' => $m['dirIndex']]; 
        }

        $next = null;
        if (!empty($orderedItems)) {
            if (empty($state['currentVideo']['filename'])) {
                $next = $orderedItems[0];
            } else {
                $idx = -1;
                foreach ($orderedItems as $k => $v) {
                    if ($v['name'] === $state['currentVideo']['filename'] && 
                        $v['dirIndex'] === $state['currentVideo']['dirIndex']) { 
                        $idx = $k; 
                        break; 
                    }
                }
                $next = $idx === -1 ? $orderedItems[0] : $orderedItems[($idx + 1) % count($orderedItems)];
            }
        }
        
        echo json_encode(['nextVideo' => $next]);
    }
    
    private function buildAllVideosList(array $dirs, array $allowedExt): array {
        $all = [];
        foreach ($dirs as $i => $dir) {
            if (!is_dir($dir)) { continue; }
            $items = @scandir($dir);
            if ($items === false) { continue; }
            foreach ($items as $file) {
                if ($file === '.' || $file === '..') { continue; }
                $path = $dir . DIRECTORY_SEPARATOR . $file;
                if (is_file($path)) {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($ext, $allowedExt, true)) {
                        $all[] = [
                            'name' => $file,
                            'dirIndex' => $i,
                            'dirPath' => $dir,
                            'key' => $dir . '|' . $file,
                        ];
                    }
                }
            }
        }
        return $all;
    }
    
    private function loadVideoOrder(): array {
        $dir = $this->baseDir . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->profileId);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $path = $dir . '/video_order.json';
        
        if (!file_exists($path)) { return []; }
        $decoded = json_decode(@file_get_contents($path), true);
        if (is_array($decoded) && isset($decoded['order']) && is_array($decoded['order'])) {
            return $decoded['order'];
        }
        return [];
    }
}
