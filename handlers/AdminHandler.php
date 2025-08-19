<?php

require_once __DIR__ . '/BaseHandler.php';

class AdminHandler extends BaseHandler {
    
    public function handle(string $action): void {
        switch ($action) {
            case 'warm_thumbnails':
                $this->warmThumbnails();
                break;
            case 'check_ffmpeg':
                $this->checkFfmpeg();
                break;
            case 'browse_directories':
                $this->browseDirectories();
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown admin action']);
        }
    }
    
    private function warmThumbnails(): void {
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $batch = max(1, min(50, (int)($_GET['batch'] ?? 10)));
        $adminCachePath = $this->baseDir . '/data/admin_cache.json';
        $thumbDir = $this->baseDir . '/data/thumbs';
        
        // Ensure thumbs directory exists
        if (!is_dir($thumbDir)) {
            @mkdir($thumbDir, 0777, true);
        }
        
        // Ensure we have the latest configured directories available for path reconstruction
        $all = [];
        if (file_exists($adminCachePath)) {
            $cache = json_decode(@file_get_contents($adminCachePath), true) ?: [];
            if (!empty($cache['all_video_files']) && is_array($cache['all_video_files'])) {
                $all = $cache['all_video_files'];
            }
        }
        
        $total = count($all);
        if ($total === 0) {
            echo json_encode([
                'status' => 'ok', 
                'total' => 0, 
                'processed' => 0, 
                'failed' => 0, 
                'remaining' => 0, 
                'nextOffset' => $offset
            ]);
            return;
        }
        
        $end = min($total, $offset + $batch);
        $processed = 0;
        $failed = 0;
        
        for ($i = $offset; $i < $end; $i++) {
            $entry = $all[$i];
            $videoPath = '';
            if (!empty($entry['path']) && is_string($entry['path'])) {
                $videoPath = $entry['path'];
            } else {
                // Reconstruct from name + dirIndex if needed
                $dirs = $this->getConfiguredDirectories();
                $di = (int)($entry['dirIndex'] ?? 0);
                if (isset($dirs[$di])) {
                    $sep = (strpos($dirs[$di], ':\\') !== false) ? '\\' : '/';
                    $videoPath = rtrim($dirs[$di], '\\/') . $sep . (string)($entry['name'] ?? '');
                }
            }
            
            if ($videoPath !== '' && is_file($videoPath)) {
                // Try to generate thumbnail using the same logic as thumb.php
                $mtime = @filemtime($videoPath) ?: 0;
                $hash = sha1($videoPath . '|' . $mtime);
                $thumbPath = rtrim($thumbDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.jpg';
                
                if (is_file($thumbPath)) {
                    $processed++; // Already exists
                } else {
                    // Check ffmpeg availability
                    $which = (stripos(PHP_OS, 'WIN') === 0) ? 'where' : 'which';
                    $cmdCheck = $which . ' ffmpeg' . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
                    @exec($cmdCheck, $out, $code);
                    
                    if ($code === 0) {
                        $escapedIn = escapeshellarg($videoPath);
                        $escapedOut = escapeshellarg($thumbPath);
                        $cmd = 'ffmpeg -ss 1 -i ' . $escapedIn . ' -frames:v 1 -vf "scale=480:-1" -q:v 5 -y ' . $escapedOut . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
                        @exec($cmd, $o, $c);
                        if ($c === 0 && is_file($thumbPath)) {
                            $processed++;
                        } else {
                            $failed++;
                        }
                    } else {
                        $failed++;
                    }
                }
            } else {
                $failed++;
            }
        }
        
        $remaining = max(0, $total - $end);
        echo json_encode([
            'status' => 'ok',
            'total' => $total, 
            'processed' => $processed, 
            'failed' => $failed,
            'remaining' => $remaining, 
            'nextOffset' => $end
        ]);
    }
    
    private function checkFfmpeg(): void {
        $which = (stripos(PHP_OS, 'WIN') === 0) ? 'where' : 'which';
        $cmdCheck = $which . ' ffmpeg' . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
        @exec($cmdCheck, $out, $code);
        
        if ($code === 0) {
            // Test FFmpeg with a simple command
            $testCmd = 'ffmpeg -version' . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
            @exec($testCmd, $versionOut, $versionCode);
            
            if ($versionCode === 0) {
                echo json_encode([
                    'available' => true, 
                    'version' => trim($versionOut[0] ?? 'Unknown')
                ]);
            } else {
                echo json_encode([
                    'available' => false, 
                    'error' => 'FFmpeg found but failed to execute version command'
                ]);
            }
        } else {
            echo json_encode([
                'available' => false, 
                'error' => 'FFmpeg not found in system PATH'
            ]);
        }
    }
    
    private function browseDirectories(): void {
        if (!$this->validatePostRequest()) return;
        
        $path = trim($_POST['path'] ?? '');
        
        // Clean up path separators for Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $path = str_replace('/', '\\', $path);
        }
        
        // Security: Allow common roots and UNC paths on Windows
        $allowedRoots = ['C:', 'D:', 'E:', 'F:', 'G:', 'H:', '/', '/mnt', '/media'];
        $isAllowed = false;
        
        if ($path === '') {
            $isAllowed = true; // allow listing roots
        }
        if (!$isAllowed) {
            foreach ($allowedRoots as $root) {
                if (strpos($path, $root) === 0) {
                    $isAllowed = true;
                    break;
                }
            }
        }
        if (PHP_OS_FAMILY === 'Windows' && !$isAllowed) {
            // Allow paths starting with \\SERVER\Share or \\?\UNC\SERVER\Share
            if (strpos($path, '\\') === 0) {
                $isAllowed = true;
            }
        }
        
        if (!$isAllowed) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied to this path']);
            return;
        }
        
        try {
            if (empty($path)) {
                // Show available drives on Windows or root on Linux
                if (PHP_OS_FAMILY === 'Windows') {
                    $directories = [];
                    foreach (range('A', 'Z') as $drive) {
                        $drivePath = $drive . ':\\';
                        if (is_dir($drivePath)) {
                            $directories[] = $drivePath;
                        }
                    }
                    echo json_encode([
                        'status' => 'ok', 
                        'directories' => $directories, 
                        'currentPath' => ''
                    ]);
                } else {
                    $directories = [];
                    $rootDirs = ['/home', '/mnt', '/media', '/var', '/usr'];
                    foreach ($rootDirs as $dir) {
                        if (is_dir($dir)) {
                            $directories[] = basename($dir);
                        }
                    }
                    echo json_encode([
                        'status' => 'ok', 
                        'directories' => $directories, 
                        'currentPath' => '/'
                    ]);
                }
            } else {
                if (!is_dir($path)) {
                    echo json_encode(['error' => 'Directory not found or not accessible']);
                    return;
                }
                
                $directories = [];
                $videoFiles = [];
                $items = @scandir($path);
                if ($items === false) {
                    echo json_encode(['error' => 'Directory not accessible']);
                    return;
                }
                foreach ($items as $item) {
                    if ($item !== '.' && $item !== '..') {
                        $itemPath = $path . (PHP_OS_FAMILY === 'Windows' ? '\\' : '/') . $item;
                        if (is_dir($itemPath)) {
                            $directories[] = $item;
                        } else {
                            // Check if it's a video file
                            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                            if (in_array($ext, ['mp4', 'webm', 'ogg', 'mov'])) {
                                $videoFiles[] = $item;
                            }
                        }
                    }
                }
                
                sort($directories);
                sort($videoFiles);
                echo json_encode([
                    'status' => 'ok', 
                    'directories' => $directories, 
                    'videoFiles' => $videoFiles,
                    'currentPath' => $path
                ]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to browse directory: ' . $e->getMessage()]);
        }
    }
}
