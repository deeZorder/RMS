<?php

require_once __DIR__ . '/BaseHandler.php';

class AdminHandler extends BaseHandler {
    
    public function handle(string $action): void {
        switch ($action) {
            case 'warm_thumbnails':
                $this->warmThumbnails();
                break;
            case 'warm_previews':
                $this->warmPreviews();
                break;
            case 'check_ffmpeg':
                $this->checkFfmpeg();
                break;
            case 'browse_directories':
                $this->browseDirectories();
                break;
            case 'upload_files':
                $this->uploadFiles();
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown admin action']);
        }
    }
    
    private function warmThumbnails(): void {
        $offset = max(0, (int)(isset($_GET['offset']) ? $_GET['offset'] : 0));
        $batch = max(1, min(50, (int)(isset($_GET['batch']) ? $_GET['batch'] : 10)));
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
        
        // Build list of targets that actually need generation (skip existing)
        $targets = [];
        foreach ($all as $entry) {
            $videoPath = '';
            if (!empty($entry['path']) && is_string($entry['path'])) {
                $videoPath = $entry['path'];
            } else {
                $dirs = $this->getConfiguredDirectories();
                $di = (int)(isset($entry['dirIndex']) ? $entry['dirIndex'] : 0);
                if (isset($dirs[$di])) {
                    $sep = (strpos($dirs[$di], ':\\') !== false) ? '\\' : '/';
                    $videoPath = rtrim($dirs[$di], '\\/') . $sep . (string)(isset($entry['name']) ? $entry['name'] : '');
                }
            }

            if ($videoPath !== '' && is_file($videoPath)) {
                $mtime = @filemtime($videoPath) ?: 0;
                $hash = sha1($videoPath . '|' . $mtime);
                $thumbPath = rtrim($thumbDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.jpg';
                if (!is_file($thumbPath)) {
                    $targets[] = $entry; // needs generation
                }
            }
        }

        $total = count($targets);
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

        // Cache ffmpeg availability once per request
        $which = (stripos(PHP_OS, 'WIN') === 0) ? 'where' : 'which';
        $cmdCheck = $which . ' ffmpeg' . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
        @exec($cmdCheck, $__out, $__code);
        $ffmpegAvailable = ($__code === 0);

        
        for ($i = $offset; $i < $end; $i++) {
            $entry = $targets[$i];
            $videoPath = '';
            if (!empty($entry['path']) && is_string($entry['path'])) {
                $videoPath = $entry['path'];
            } else {
                // Reconstruct from name + dirIndex if needed
                $dirs = $this->getConfiguredDirectories();
                $di = (int)(isset($entry['dirIndex']) ? $entry['dirIndex'] : 0);
                if (isset($dirs[$di])) {
                    $sep = (strpos($dirs[$di], ':\\') !== false) ? '\\' : '/';
                    $videoPath = rtrim($dirs[$di], '\\/') . $sep . (string)(isset($entry['name']) ? $entry['name'] : '');
                }
            }
            
            if ($videoPath !== '' && is_file($videoPath)) {
                // Try to generate thumbnail using the same logic as thumb.php
                $mtime = @filemtime($videoPath) ?: 0;
                $hash = sha1($videoPath . '|' . $mtime);
                $thumbPath = rtrim($thumbDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.jpg';
                
                if (!is_file($thumbPath)) {
                    // Use cached ffmpeg availability
                    if () {
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
                } else {
                    // Should not happen since we filtered, but count as processed
                    $processed++;
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
    
    private function warmPreviews(): void {
        $offset = max(0, (int)(isset($_GET['offset']) ? $_GET['offset'] : 0));
        $batch = max(1, min(10, (int)(isset($_GET['batch']) ? $_GET['batch'] : 2))); // Smaller default batch for previews
        $adminCachePath = $this->baseDir . '/data/admin_cache.json';
        $previewDir = $this->baseDir . '/data/previews';
        
        // Ensure previews directory exists
        if (!is_dir($previewDir)) {
            @mkdir($previewDir, 0777, true);
        }
        
        // Ensure we have the latest configured directories available for path reconstruction
        $all = [];
        if (file_exists($adminCachePath)) {
            $cache = json_decode(@file_get_contents($adminCachePath), true) ?: [];
            if (!empty($cache['all_video_files']) && is_array($cache['all_video_files'])) {
                $all = $cache['all_video_files'];
            }
        }
        
        // Build list of targets that actually need preview generation (skip existing)
        $targets = [];
        foreach ($all as $entry) {
            $videoPath = '';
            if (!empty($entry['path']) && is_string($entry['path'])) {
                $videoPath = $entry['path'];
            } else {
                $dirs = $this->getConfiguredDirectories();
                $di = (int)($entry['dirIndex'] ?? 0);
                if (isset($dirs[$di])) {
                    $sep = (strpos($dirs[$di], ':\\') !== false) ? '\\' : '/';
                    $videoPath = rtrim($dirs[$di], '\\/') . $sep . (string)($entry['name'] ?? '');
                }
            }
            if ($videoPath !== '' && is_file($videoPath)) {
                $mtime = @filemtime($videoPath) ?: 0;
                $hash = sha1($videoPath . '|' . $mtime);
                $path = rtrim($previewDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.mp4';
                if (!is_file($path)) {
                    $targets[] = $entry;
                }
            }
        }

        $total = count($targets);
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

        // Cache ffmpeg availability once per request
        $which = (stripos(PHP_OS, 'WIN') === 0) ? 'where' : 'which';
        $cmdCheck = $which . ' ffmpeg' . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
        @exec($cmdCheck, $__out2, $__code2);
        $ffmpegAvailable2 = ($__code2 === 0);

        
        for ($i = $offset; $i < $end; $i++) {
            $entry = $targets[$i];
            $videoPath = '';
            if (!empty($entry['path']) && is_string($entry['path'])) {
                $videoPath = $entry['path'];
            } else {
                // Reconstruct from name + dirIndex if needed
                $dirs = $this->getConfiguredDirectories();
                $di = (int)(isset($entry['dirIndex']) ? $entry['dirIndex'] : 0);
                if (isset($dirs[$di])) {
                    $sep = (strpos($dirs[$di], ':\\') !== false) ? '\\' : '/';
                    $videoPath = rtrim($dirs[$di], '\\/') . $sep . (string)(isset($entry['name']) ? $entry['name'] : '');
                }
            }
            
            if ($videoPath !== '' && is_file($videoPath)) {
                // Try to generate preview using FFmpeg
                $mtime = @filemtime($videoPath) ?: 0;
                $hash = sha1($videoPath . '|' . $mtime);
                $previewPath = rtrim($previewDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.mp4';
                
                if (is_file($previewPath)) {
                    $processed++; // Already exists
                    // Ensure admin cache has preview metadata
                    $this->updateAdminCachePreviewMetadata($all[$i], $hash, $mtime);
                } else {
                    // Use cached ffmpeg availability
                    if () {
                        $escapedIn = escapeshellarg($videoPath);
                        $escapedOut = escapeshellarg($previewPath);
                        
                        // Generate a 5-second preview without audio, scaled to 480p, faster encoding
                        $cmd = 'ffmpeg -ss 00:00:05 -i ' . $escapedIn . ' -t 5 -vf "scale=480:-2" -c:v libx264 -preset fast -crf 28 -an -y ' . $escapedOut . (stripos(PHP_OS, 'WIN') === 0 ? ' 2> NUL' : ' 2> /dev/null');
                        @exec($cmd, $o, $c);
                        if ($c === 0 && is_file($previewPath)) {
                            $processed++;
                            // Update admin cache with preview metadata
                            $this->updateAdminCachePreviewMetadata($all[$i], $hash, $mtime);
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

    /**
     * Update admin_cache.json with preview metadata for a specific video entry
     */
    private function updateAdminCachePreviewMetadata(array $entry, string $hash, int $mtime): void {
        $adminCachePath = $this->baseDir . '/data/admin_cache.json';
        if (!is_file($adminCachePath)) {
            return;
        }
        $cache = json_decode(@file_get_contents($adminCachePath), true);
        if (!is_array($cache) || empty($cache['all_video_files']) || !is_array($cache['all_video_files'])) {
            return;
        }

        $name = isset($entry['name']) ? (string)$entry['name'] : '';
        $dirIndex = (int)($entry['dirIndex'] ?? 0);
        $path = isset($entry['path']) ? (string)$entry['path'] : '';

        $updated = false;
        foreach ($cache['all_video_files'] as &$item) {
            $itemName = isset($item['name']) ? (string)$item['name'] : '';
            $itemDirIndex = (int)($item['dirIndex'] ?? 0);
            $itemPath = isset($item['path']) ? (string)$item['path'] : '';

            if ($itemName === $name && $itemDirIndex === $dirIndex && ($path === '' || $itemPath === $path)) {
                $item['preview_hash'] = $hash;
                $item['preview_mtime'] = $mtime;
                $updated = true;
                break;
            }
        }
        unset($item);

        if ($updated) {
            @file_put_contents($adminCachePath, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
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
        
        $path = trim(isset($_POST['path']) ? $_POST['path'] : '');
        
        // Clean up path separators for Windows
        $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        if ($isWindows) {
            $path = str_replace('/', '\\', $path);
        }
        
        // Enhanced security check - more flexible but still secure
        $isAllowed = false;
        
        if ($path === '') {
            $isAllowed = true; // allow listing roots/drives
        } else {
            // For Windows paths
            if ($isWindows) {
                // Allow drive letters (A-Z):
                if (preg_match('/^[A-Za-z]:\\\\/', $path)) {
                    $isAllowed = true;
                }
                // Allow UNC paths \\server\share
                if (preg_match('/^\\\\\\\\[^\\\\]+\\\\[^\\\\]+/', $path)) {
                    $isAllowed = true;
                }
                // Allow mapped network drives and common Windows paths
                if (preg_match('/^[A-Za-z]:/', $path)) {
                    $isAllowed = true;
                }
            } else {
                // For Unix/Linux paths
                if (strpos($path, '/') === 0) {
                    // Deny access to sensitive system directories
                    $deniedPaths = ['/etc/', '/proc/', '/sys/', '/dev/', '/boot/', '/root/'];
                    $isDenied = false;
                    foreach ($deniedPaths as $denied) {
                        if (strpos($path, $denied) === 0) {
                            $isDenied = true;
                            break;
                        }
                    }
                    if (!$isDenied) {
                        $isAllowed = true;
                    }
                }
            }
        }
        
        // Additional check: verify the path exists and is accessible
        if ($isAllowed && $path !== '' && !is_dir($path)) {
            // Path doesn't exist, but we'll let the error handling below deal with it
            // Don't fail here as the user might be typing a valid path
        }
        
        if (!$isAllowed) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Access denied to this path', 
                'path' => $path,
                'hint' => 'Please use absolute paths (e.g., C:\\Videos on Windows or /home/user/videos on Linux)'
            ]);
            return;
        }
        
        try {
            if (empty($path)) {
                // Show available drives on Windows or root on Linux
                if ($isWindows) {
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
                    echo json_encode([
                        'error' => 'Directory not found or not accessible',
                        'path' => $path,
                        'details' => 'The specified path does not exist or is not a directory'
                    ]);
                    return;
                }
                
                $directories = [];
                $videoFiles = [];
                $items = @scandir($path);
                if ($items === false) {
                    echo json_encode([
                        'error' => 'Directory not accessible',
                        'path' => $path,
                        'details' => 'Permission denied or path is not readable'
                    ]);
                    return;
                }
                foreach ($items as $item) {
                    if ($item !== '.' && $item !== '..') {
                        $itemPath = $path . ($isWindows ? '\\' : '/') . $item;
                        if (is_dir($itemPath)) {
                            $directories[] = $item;
                        } else {
                            // Check if it's a video file
                            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                            if (in_array($ext, ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv', 'wmv', 'flv'])) {
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
    
    private function uploadFiles(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        try {
            // Get the folder name from POST data
            $folderName = trim(isset($_POST['folderName']) ? $_POST['folderName'] : '');
            if (empty($folderName)) {
                echo json_encode(['error' => 'Folder name is required']);
                return;
            }

            // Sanitize folder name (remove unsafe characters)
            $folderName = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $folderName);
            $folderName = trim($folderName);
            
            if (empty($folderName)) {
                echo json_encode(['error' => 'Invalid folder name']);
                return;
            }

            // Create target directory in the videos folder
            $baseVideoDir = $this->baseDir . '/videos';
            if (!is_dir($baseVideoDir)) {
                @mkdir($baseVideoDir, 0777, true);
            }
            
            $targetDir = $baseVideoDir . '/' . $folderName;
            if (!is_dir($targetDir)) {
                if (!@mkdir($targetDir, 0777, true)) {
                    echo json_encode(['error' => 'Failed to create target directory']);
                    return;
                }
            }

            // Check if files were uploaded
            if (!isset($_FILES['files']) || !is_array($_FILES['files']['name'])) {
                echo json_encode(['error' => 'No files uploaded']);
                return;
            }

            $uploadedFiles = [];
            $errors = [];
            $totalSize = 0;
            
            // Process each uploaded file
            $fileCount = count($_FILES['files']['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                $fileName = $_FILES['files']['name'][$i];
                $tempPath = $_FILES['files']['tmp_name'][$i];
                $fileSize = $_FILES['files']['size'][$i];
                $fileError = $_FILES['files']['error'][$i];
                
                if ($fileError !== UPLOAD_ERR_OK) {
                    $errors[] = "Failed to upload: $fileName (Error code: $fileError)";
                    continue;
                }
                
                // Validate file extension
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowedExtensions = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv', 'wmv', 'flv'];
                if (!in_array($ext, $allowedExtensions)) {
                    $errors[] = "Skipped non-video file: $fileName";
                    continue;
                }
                
                // Sanitize filename
                $safeFileName = preg_replace('/[^a-zA-Z0-9_\-\.\s]/', '', $fileName);
                $targetPath = $targetDir . '/' . $safeFileName;
                
                // Check if file already exists
                if (file_exists($targetPath)) {
                    // Add timestamp to make unique
                    $name = pathinfo($safeFileName, PATHINFO_FILENAME);
                    $extension = pathinfo($safeFileName, PATHINFO_EXTENSION);
                    $safeFileName = $name . '_' . time() . '.' . $extension;
                    $targetPath = $targetDir . '/' . $safeFileName;
                }
                
                // Move uploaded file to target directory
                if (move_uploaded_file($tempPath, $targetPath)) {
                    $uploadedFiles[] = [
                        'original' => $fileName,
                        'saved' => $safeFileName,
                        'size' => $fileSize
                    ];
                    $totalSize += $fileSize;
                } else {
                    $errors[] = "Failed to save: $fileName";
                }
            }
            
            // Return results
            echo json_encode([
                'status' => 'success',
                'folderName' => $folderName,
                'folderPath' => $targetDir,
                'uploadedFiles' => $uploadedFiles,
                'totalFiles' => count($uploadedFiles),
                'totalSize' => $totalSize,
                'errors' => $errors
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Upload failed: ' . $e->getMessage()]);
        }
    }
}