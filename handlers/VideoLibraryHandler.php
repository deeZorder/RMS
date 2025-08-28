<?php

require_once __DIR__ . '/BaseHandler.php';
require_once __DIR__ . '/../state_manager.php';

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

// Ensure state_manager functions are available for static analysis
if (!function_exists('loadState')) {
    /**
     * Fallback loadState function if not loaded from state_manager.php
     * @param string $profileId The profile ID
     * @return array The state data
     */
    function loadState($profileId) {
        return [];
    }
}

if (!function_exists('saveState')) {
    /**
     * Fallback saveState function if not loaded from state_manager.php
     * @param string $profileId The profile ID
     * @param array $state The state data
     * @return bool Success status
     */
    function saveState($profileId, $state) {
        return false;
    }
}

if (!function_exists('updateState')) {
    /**
     * Fallback updateState function if not loaded from state_manager.php
     * @param string $profileId The profile ID
     * @param array $updates The state updates
     * @return bool Success status
     */
    function updateState($profileId, $updates) {
        // This should not be called in practice as state_manager.php is included via BaseHandler
        if (function_exists('loadState') && function_exists('saveState')) {
            // If we get here, the state_manager.php functions should be available
            $state = loadState($profileId);
            $state = array_merge($state, $updates);
            return saveState($profileId, $state);
        }
        return false;
    }
}

class VideoLibraryHandler extends BaseHandler {
    
    public function handle(string $action): void {
        switch ($action) {
            case 'get_all_videos':
                $this->getAllVideos();
                break;
            case 'get_video_count':
                $this->getVideoCount();
                break;
            case 'move_video':
                $this->moveVideo();
                break;
            case 'get_video_titles':
                $this->getVideoTitles();
                break;
            case 'set_video_title':
                $this->setVideoTitle();
                break;
            case 'force_refresh_videos':
                $this->forceRefreshVideos();
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown video library action']);
        }
    }
    
    private function getAllVideos(): void {
        $dirs = $this->getConfiguredDirectories();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        $allowedExt = ['mp4', 'webm', 'ogg', 'mov'];
        
        // Prefer using admin cache to avoid rescanning large directories on every request
        $allWithMeta = [];
        $adminCachePath = $this->baseDir . '/data/admin_cache.json';
        $useCache = false;
        
        if (file_exists($adminCachePath)) {
            $adminCache = json_decode(@file_get_contents($adminCachePath), true) ?: [];
            if (!empty($adminCache['all_video_files']) && is_array($adminCache['all_video_files'])) {
                // Determine if cache is still fresh by comparing directory mtimes
                $lastScan = (int)($adminCache['last_scan'] ?? 0);
                $maxDirModTime = 0;
                foreach ($dirs as $dir) {
                    if (is_dir($dir)) {
                        $maxDirModTime = max($maxDirModTime, @filemtime($dir) ?: 0);
                    }
                }
                if ($lastScan >= $maxDirModTime) {
                    $useCache = true;
                    foreach ($adminCache['all_video_files'] as $v) {
                        $di = (int)($v['dirIndex'] ?? 0);
                        $name = (string)($v['name'] ?? '');
                        if ($name === '' || !isset($dirs[$di])) { continue; }
                        $dirPath = $dirs[$di];
                        $allWithMeta[] = [
                            'name' => $name,
                            'dirIndex' => $di,
                            'dirPath' => $dirPath,
                            'key' => $dirPath . '|' . $name,
                        ];
                    }
                }
            }
        }
        
        // Fallback to a fresh scan if cache is not available or stale
        if (!$useCache) {
            $allWithMeta = $this->buildAllVideosList($dirs, $allowedExt);
        }
        
        $savedOrder = $this->loadVideoOrder();

        // Build current ordered keys: first saved order entries that still exist, then remaining by name+dir
        $existingKeys = array_column($allWithMeta, 'key');
        $existingSet = array_flip($existingKeys);
        $orderedKeys = [];
        foreach ($savedOrder as $k) { 
            if (isset($existingSet[$k])) { 
                $orderedKeys[] = $k; 
            } 
        }
        
        // Add missing (new) keys sorted by name then dirIndex
        $missing = array_values(array_filter($allWithMeta, function ($v) use ($orderedKeys) { 
            return !in_array($v['key'], $orderedKeys, true); 
        }));
        usort($missing, function ($a, $b) {
            $c = strcmp($a['name'], $b['name']);
            return $c !== 0 ? $c : ($a['dirIndex'] <=> $b['dirIndex']);
        });
        foreach ($missing as $m) { 
            $orderedKeys[] = $m['key']; 
        }

        // Map keys back to minimal items
        $keyToItem = [];
        foreach ($allWithMeta as $v) { 
            $keyToItem[$v['key']] = ['name' => $v['name'], 'dirIndex' => $v['dirIndex']]; 
        }
        $orderedItems = [];
        foreach ($orderedKeys as $k) { 
            if (isset($keyToItem[$k])) { 
                $orderedItems[] = $keyToItem[$k]; 
            } 
        }

        $totalCount = count($orderedItems);
        $pageItems = array_slice($orderedItems, $offset, $limit);
        
        echo json_encode([
            'videos' => $pageItems, 
            'pagination' => [
                'total' => $totalCount, 
                'page' => $page, 
                'limit' => $limit, 
                'pages' => ceil($totalCount / $limit)
            ]
        ]);
    }
    
    private function getVideoCount(): void {
        $dirs = $this->getConfiguredDirectories();
        $allowedExt = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'];
        $totalCount = 0;
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                // Try relative path if absolute doesn't exist
                $relativePath = realpath($this->baseDir . '/' . $dir);
                if ($relativePath && is_dir($relativePath)) {
                    $dir = $relativePath;
                } else {
                    continue; // Skip invalid directories
                }
            }
            
            $allFiles = @scandir($dir);
            if ($allFiles === false) continue;
            
            foreach ($allFiles as $file) {
                if ($file !== '.' && $file !== '..' && is_file($dir . DIRECTORY_SEPARATOR . $file)) {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($ext, $allowedExt)) {
                        $totalCount++;
                    }
                }
                // Limit to prevent infinite loops on very large directories
                if ($totalCount > 10000) break 2;
            }
        }
        
        echo json_encode(['count' => $totalCount]);
    }
    
    private function moveVideo(): void {
        if (!$this->validatePostRequest()) return;
        
        $filename = trim($_POST['filename'] ?? '');
        $dirIndex = isset($_POST['dirIndex']) ? (int)$_POST['dirIndex'] : 0;
        $direction = $_POST['direction'] ?? '';
        
        if ($filename === '' || !in_array($direction, ['up', 'down'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid parameters']);
            return;
        }
        
        if (!$this->validateFilename($filename)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid filename']);
            return;
        }
        
        $dirs = $this->getConfiguredDirectories();
        if ($dirIndex < 0 || $dirIndex >= count($dirs)) { 
            $dirIndex = 0; 
        }
        $allowedExt = ['mp4', 'webm', 'ogg', 'mov'];
        $allWithMeta = $this->buildAllVideosList($dirs, $allowedExt);

        // Build ordered list (per profile)
        $savedOrder = $this->loadVideoOrder();
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
            $orderedKeys[] = $m['key']; 
        }

        $targetKey = $dirs[$dirIndex] . '|' . $filename;
        $idx = array_search($targetKey, $orderedKeys, true);
        if ($idx === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Video not found']);
            return;
        }
        
        if ($direction === 'up' && $idx > 0) {
            $tmp = $orderedKeys[$idx - 1];
            $orderedKeys[$idx - 1] = $orderedKeys[$idx];
            $orderedKeys[$idx] = $tmp;
        } elseif ($direction === 'down' && $idx < count($orderedKeys) - 1) {
            $tmp = $orderedKeys[$idx + 1];
            $orderedKeys[$idx + 1] = $orderedKeys[$idx];
            $orderedKeys[$idx] = $tmp;
        }

        $this->saveVideoOrder($orderedKeys);
        echo json_encode(['status' => 'ok']);
    }
    
    private function getVideoTitles(): void {
        $titles = []; // Will be converted to object if empty
        $videoTitlesPath = $this->baseDir . '/data/video_titles.json';
        if (file_exists($videoTitlesPath)) {
            $titles = json_decode(file_get_contents($videoTitlesPath), true) ?: [];
        }
        
        // Ensure empty array becomes empty object in JSON for JavaScript compatibility
        if (empty($titles)) {
            $titles = new stdClass();
        }
        
        echo json_encode(['titles' => $titles]);
    }
    
    private function setVideoTitle(): void {
        if (!$this->validatePostRequest()) return;
        
        $filename = trim($_POST['filename'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $dirIndex = isset($_POST['dirIndex']) ? (int)$_POST['dirIndex'] : 0;
        
        if (!$this->validateFilename($filename)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid filename']);
            return;
        }
        
        if ($dirIndex < 0) { $dirIndex = 0; }
        
        // Normalize title: strip control chars and limit length
        $title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title);
        if (mb_strlen($title) > 200) {
            $title = mb_substr($title, 0, 200);
        }
        
        $videoTitlesPath = $this->baseDir . '/data/video_titles.json';
        $titles = [];
        if (file_exists($videoTitlesPath)) {
            $titles = json_decode(file_get_contents($videoTitlesPath), true) ?: [];
        }
        
        $titles[$dirIndex . '|' . $filename] = $title;
        file_put_contents($videoTitlesPath, json_encode($titles, JSON_PRETTY_PRINT));
        
        echo json_encode(['status' => 'ok', 'title' => $title]);
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
    
    private function saveVideoOrder(array $order): void {
        $dir = $this->baseDir . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->profileId);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $path = $dir . '/video_order.json';
        @file_put_contents($path, json_encode(['order' => array_values($order)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    private function forceRefreshVideos(): void {
        $dirs = $this->getConfiguredDirectories();
        $allowedExt = ['mp4', 'webm', 'ogg', 'mov'];
        $allVideos = $this->buildAllVideosList($dirs, $allowedExt);
        
        // Generate new order
        $newOrder = [];
        foreach ($allVideos as $video) {
            $videoKey = (isset($dirs[$video['dirIndex']]) ? $dirs[$video['dirIndex']] : '') . '|' . $video['name'];
            $newOrder[] = $videoKey;
        }
        
        // Save new order
        $this->saveVideoOrder($newOrder);
        
        // Update current video if needed
        if (!empty($newOrder)) {
            $firstVideo = $allVideos[0];
            \updateState($this->profileId, [
                'currentVideo' => [
                    'filename' => $firstVideo['name'], 
                    'dirIndex' => $firstVideo['dirIndex']
                ]
            ]);
        }
        
        echo json_encode([
            'status' => 'ok', 
            'message' => 'Video list refreshed', 
            'videoCount' => count($newOrder)
        ]);
    }
}
