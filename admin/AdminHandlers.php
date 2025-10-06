<?php
/**
 * AdminHandlers.php
 * Handles all admin form processing and POST requests
 */

// Load dependencies
require_once __DIR__ . '/AdminConfig.php';

class AdminHandlers {
    private $config;
    private $baseDir;

    public function __construct(AdminConfig $config, $baseDir = null) {
        $this->config = $config;
        $this->baseDir = $baseDir ?: dirname(__DIR__);
    }

    public function handleRequest() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }

$currentSection = $_POST['current_section'] ?? 'directory-config';

        switch ($currentSection) {
            case 'dashboard-settings':
                return $this->handleDashboardSettings();
            case 'delete-dashboard':
                return $this->handleDeleteDashboard();
            case 'refresh-profile':
                return $this->handleRefreshProfile();
            case 'clear-thumbs-titles':
                return $this->handleClearThumbsTitles();
            case 'system-refresh':
                return $this->handleSystemRefresh();
            case 'system-refresh-screens':
                return $this->handleSystemRefreshScreens();
            case 'refresh-dashboards':
                return $this->handleRefreshDashboards();
            case 'generate-thumbnails':
                return $this->handleGenerateThumbnails();
            case 'generate-previews':
                return $this->handleGeneratePreviews();
            case 'system-reset':
                return $this->handleSystemReset();
            case 'screen-management':
                return $this->handleScreenManagement();
            case 'directory-config':
            default:
                return $this->handleDirectoryConfig();
        }
    }

    private function handleDashboardSettings() {
        $dashboardId = trim((string)($_POST['dashboard_id'] ?? 'default')) ?: 'default';
        $dashboardName = trim((string)($_POST['dashboard_name'] ?? '')) ?: ($dashboardId === 'default' ? 'Default' : ucfirst($dashboardId));
        $rows = (int)($_POST['rows'] ?? 2);
        $clipsPerRow = (int)($_POST['clipsPerRow'] ?? 5);
        $dashboardBackground = isset($_POST['dashboardBackground']) ? trim((string)$_POST['dashboardBackground']) : '';
        
        if ($rows < 1) $rows = 1;
        if ($clipsPerRow < 1) $clipsPerRow = 1;
        
        $dashboards = $this->config->getDashboards();
        
        if (!isset($dashboards[$dashboardId])) {
            $dashboards[$dashboardId] = array(
                'id' => $dashboardId,
                'name' => $dashboardName,
                'rows' => $rows,
                'clipsPerRow' => $clipsPerRow,
                'dashboardBackground' => ($dashboardBackground === 'none') ? '' : $dashboardBackground,
            );
        } else {
            $dashboards[$dashboardId]['name'] = $dashboardName;
            $dashboards[$dashboardId]['rows'] = $rows;
            $dashboards[$dashboardId]['clipsPerRow'] = $clipsPerRow;
            $dashboards[$dashboardId]['dashboardBackground'] = ($dashboardBackground === 'none') ? '' : $dashboardBackground;
        }
        
        $this->config->setDashboard($dashboardId, $dashboards[$dashboardId]);
        $this->config->saveDashboards();

        // Also update base config defaults for backward compatibility
        $this->config->setConfig('rows', $rows);
        $this->config->setConfig('clipsPerRow', $clipsPerRow);
        $this->config->setConfig('dashboardBackground', ($dashboardBackground === 'none') ? '' : $dashboardBackground);
        $this->config->saveConfiguration();
        
        $_SESSION['flash'] = array('type' => 'success', 'message' => 'Configuration saved successfully');
        $this->config->invalidateCache();
        
        // Trigger refresh for the selected dashboard profile
        $sanitizedId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $dashboardId);
        $profileId = ($sanitizedId !== '' ? $sanitizedId : 'default');
        $this->triggerDashboardRefresh($profileId);
        
        header('Location: admin.php?admin-panel=' . urlencode('dashboard-settings') . '&dashboard=' . urlencode($dashboardId));
        exit;
    }

    private function handleDeleteDashboard() {
        $id = trim((string)($_POST['dashboard_id'] ?? ''));
        if ($id && $id !== 'default') {
            $this->config->removeDashboard($id);
            $this->config->saveDashboards();
        }
        $this->triggerDashboardRefresh('default');
        header('Location: admin.php?admin-panel=dashboard-settings&dashboard=default');
        exit;
    }

    private function handleClearThumbsTitles() {
        $thumbDir = $this->baseDir . '/data/thumbs';
        $previewDir = $this->baseDir . '/data/previews';
        $titlesFile = $this->baseDir . '/data/video_titles.json';

        // Remove all thumbnails
        if (is_dir($thumbDir)) {
            $items = @scandir($thumbDir) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $path = $thumbDir . DIRECTORY_SEPARATOR . $item;
                if (is_file($path)) @unlink($path);
            }
        }

        // Remove all previews
        if (is_dir($previewDir)) {
            $items = @scandir($previewDir) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $path = $previewDir . DIRECTORY_SEPARATOR . $item;
                if (is_file($path)) @unlink($path);
            }
        }

        // Remove titles file
        if (file_exists($titlesFile)) @unlink($titlesFile);

        $this->config->invalidateCache();
        $this->triggerDashboardRefresh('default');

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Cleared thumbnails, previews and titles'];
        header('Location: admin.php?admin-panel=system-status');
        exit;
    }

    private function handleSystemRefresh() {
        $refreshCount = 0;
        
        // Always refresh default profile
        if ($this->triggerDashboardRefresh('default')) {
            $refreshCount++;
        }
        
        // Refresh all other dashboard profiles
        $dashboards = $this->config->getDashboards();
        if (!empty($dashboards) && is_array($dashboards)) {
            foreach (array_keys($dashboards) as $profileId) {
                if ($profileId !== 'default') {
                    if ($this->triggerDashboardRefresh($profileId)) {
                        $refreshCount++;
                    }
                }
            }
        }

        $this->config->invalidateCache();
        $_SESSION['flash'] = ['type' => 'success', 'message' => "Dashboard refresh signal sent to {$refreshCount} dashboard profile(s)"];
        header('Location: admin.php?admin-panel=system-status');
        exit;
    }

    private function handleSystemRefreshScreens() {
        $profilesRefreshed = [];
        $screens = $this->config->getScreens();
        if (isset($screens['screens']) && is_array($screens['screens'])) {
            foreach ($screens['screens'] as $screen) {
                $p = isset($screen['profile']) ? (string)$screen['profile'] : 'default';
                $p = preg_replace('/[^a-zA-Z0-9_\-]/', '', $p);
                if ($p === '') { $p = 'default'; }
                if (!isset($profilesRefreshed[$p])) {
                    if ($this->triggerDashboardRefresh($p)) {
                        $profilesRefreshed[$p] = true;
                    } else {
                        $profilesRefreshed[$p] = false;
                    }
                }
            }
        }

        // Always include default in case no screens are registered
        if (!isset($profilesRefreshed['default'])) {
            $this->triggerDashboardRefresh('default');
            $profilesRefreshed['default'] = true;
        }

        $count = count($profilesRefreshed);
        $_SESSION['flash'] = ['type' => 'success', 'message' => "Screen refresh signal sent to {$count} profile(s)"];
        header('Location: admin.php?admin-panel=system-status');
        exit;
    }

    private function handleRefreshProfile() {
        $dashboardId = trim((string)($_POST['dashboard_id'] ?? 'default')) ?: 'default';
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '', $dashboardId);
        $profileId = $sanitized !== '' ? $sanitized : 'default';

        $ok = $this->triggerDashboardRefresh($profileId);
        $_SESSION['flash'] = [
            'type' => $ok ? 'success' : 'error',
            'message' => ($ok ? 'Refresh signal sent for profile: ' : 'Failed to send refresh for profile: ') . htmlspecialchars($profileId)
        ];
        header('Location: admin.php?admin-panel=screen-management#screen-management');
        exit;
    }

    private function handleRefreshDashboards() {
        $refreshCount = 0;
        
        // Always refresh default profile
        if ($this->triggerDashboardRefresh('default')) {
            $refreshCount++;
        }
        
        // Refresh all other dashboard profiles
        $dashboards = $this->config->getDashboards();
        if (!empty($dashboards) && is_array($dashboards)) {
            foreach (array_keys($dashboards) as $profileId) {
                if ($profileId !== 'default') {
                    if ($this->triggerDashboardRefresh($profileId)) {
                        $refreshCount++;
                    }
                }
            }
        }

        $this->config->invalidateCache();
        $_SESSION['flash'] = ['type' => 'success', 'message' => "Dashboard refresh signal sent to {$refreshCount} dashboard profile(s). Video order pushed to all dashboards."];
        header('Location: admin.php?admin-panel=video-management');
        exit;
    }

    private function handleGenerateThumbnails() {
        // Force a fresh scan by invalidating admin cache
        $this->config->invalidateCache();

        // Ensure thumbs directory exists
        $thumbDir = $this->baseDir . '/data/thumbs';
        if (!is_dir($thumbDir)) {
            @mkdir($thumbDir, 0777, true);
        }

        // Get configured directories and scan for videos
        $configuredDirs = $this->config->getConfiguredDirectories();
        $allowedExt = array('mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv');
        $totalVideos = 0;
        $processedVideos = 0;
        $failedVideos = 0;

        // Scan each configured directory
        foreach ($configuredDirs as $dirIndex => $dir) {
            $fullPath = $dir;
            if (!is_dir($fullPath)) {
                // Try relative path if absolute doesn't exist
                $relativePath = realpath($this->baseDir . '/' . $dir);
                if ($relativePath && is_dir($relativePath)) {
                    $fullPath = $relativePath;
                } else {
                    continue; // Skip invalid directories
                }
            }

            $allFiles = @scandir($fullPath);
            if ($allFiles === false) continue;

            foreach ($allFiles as $file) {
                if ($file !== '.' && $file !== '..' && is_file($fullPath . DIRECTORY_SEPARATOR . $file)) {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($ext, $allowedExt)) {
                        $totalVideos++;
                        $videoPath = $fullPath . DIRECTORY_SEPARATOR . $file;

                        // Try to generate thumbnail using the same logic as thumb.php
                        $mtime = @filemtime($videoPath) ?: 0;
                        $hash = sha1($videoPath . '|' . $mtime);
                        $thumbPath = rtrim($thumbDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.jpg';

                        if (is_file($thumbPath)) {
                            $processedVideos++; // Already exists
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
                                    $processedVideos++;
                                } else {
                                    $failedVideos++;
                                }
                            } else {
                                $failedVideos++;
                            }
                        }
                    }
                }
            }
        }

        // Set success flash message with results
        $message = "Thumbnail generation completed: {$processedVideos} generated, {$failedVideos} failed out of {$totalVideos} total videos.";
        if ($failedVideos > 0) {
            $message .= " Failed videos may need FFmpeg to be installed or may have permission issues.";
        }
        $_SESSION['flash'] = array('type' => 'success', 'message' => $message);

        // Redirect back to System Status
        header('Location: admin.php?admin-panel=system-status');
        exit;
    }

    private function handleGeneratePreviews() {
        // Generate video previews using FFmpeg
        $baseDir = $this->baseDir;
        $config = $this->config->getConfig();
        
        // Get video directories
        $clipsDirs = [];
        if (!empty($config['directories']) && is_array($config['directories'])) {
            $clipsDirs = $config['directories'];
        } else {
            $clipsDirs = [ $config['directory'] ?? 'videos' ];
        }
        
        // Get all video files using the same logic as VideoManagementHandler
        $videoFiles = [];
        $allowedExt = ['mp4', 'webm', 'ogg', 'mov'];
        
        // Build the complete video list from directories
        $allWithMeta = [];
        foreach ($clipsDirs as $dirIndex => $clipsDir) {
            $fullVideoDir = $baseDir . DIRECTORY_SEPARATOR . $clipsDir;
            if (is_dir($fullVideoDir)) {
                $items = @scandir($fullVideoDir) ?: [];
                foreach ($items as $file) {
                    if ($file === '.' || $file === '..') continue;
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($ext, $allowedExt, true)) {
                        $allWithMeta[] = [
                            'name' => $file,
                            'dirIndex' => $dirIndex,
                            'dirPath' => $clipsDir,
                            'key' => $clipsDir . '|' . $file,
                        ];
                    }
                }
            }
        }
        
        // Load the saved video order to maintain the same order as dashboard
        $videoOrderFile = $baseDir . '/data/profiles/default/video_order.json';
        $savedOrder = [];
        if (file_exists($videoOrderFile)) {
            $decoded = json_decode(file_get_contents($videoOrderFile), true);
            if (is_array($decoded) && isset($decoded['order']) && is_array($decoded['order'])) {
                $savedOrder = $decoded['order'];
            }
        }
        
        // Parse the saved order to extract filenames and dirIndexes (same as VideoManagementHandler)
        $orderedItems = [];
        foreach ($savedOrder as $orderKey) {
            if (strpos($orderKey, '|') !== false) {
                [$dirPath, $filename] = explode('|', $orderKey, 2);
                // Find the dirIndex for this directory
                $dirIndex = array_search($dirPath, $clipsDirs);
                if ($dirIndex !== false) {
                    $orderedItems[] = ['name' => $filename, 'dirIndex' => $dirIndex];
                }
            }
        }
        
        // Add any missing videos from the current directory structure (same as VideoManagementHandler)
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
        
        // Use the properly ordered video list
        $videoFiles = $orderedItems;
        
        if (empty($videoFiles)) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'No video files found to generate previews for.'];
            header('Location: admin.php?admin-panel=system-status');
            exit;
        }
        
        // Create previews directory if it doesn't exist
        $previewDir = $baseDir . '/data/previews';
        if (!is_dir($previewDir)) { @mkdir($previewDir, 0777, true); }
        
        $processedVideos = 0;
        $failedVideos = 0;
        $totalVideos = count($videoFiles);
        
        // Load existing admin cache to update with preview hashes
        $adminCachePath = $baseDir . '/data/admin_cache.json';
        $adminCache = [];
        if (file_exists($adminCachePath)) {
            $adminCache = json_decode(file_get_contents($adminCachePath), true) ?: [];
        }
        
        // Initialize admin cache structure if it doesn't exist
        if (empty($adminCache)) {
            $adminCache = [
                'last_scan' => time(),
                'available_directories' => [],
                'total_videos' => 0,
                'all_video_files' => []
            ];
        }
        
        // Update admin cache with preview hashes
        $adminCache['last_scan'] = time();
        $adminCache['total_videos'] = $totalVideos;
        
        // Process videos and build enhanced admin cache
        $enhancedVideoFiles = [];
        foreach ($videoFiles as $video) {
            $videoDir = $clipsDirs[$video['dirIndex']] ?? $clipsDirs[0];
            $fullVideoDir = $baseDir . DIRECTORY_SEPARATOR . $videoDir;
            $videoPath = $fullVideoDir . DIRECTORY_SEPARATOR . $video['name'];
            
            if (!is_file($videoPath)) { continue; }
            
            // Generate hash for preview filename (same as preview.php)
            $normalizedPath = str_replace(['\\', '/'], '/', $videoPath);
            $mtime = @filemtime($videoPath) ?: 0;
            $hash = sha1($normalizedPath . '|' . $mtime . '|preview');
            $previewPath = $previewDir . DIRECTORY_SEPARATOR . $hash . '.mp4';
            
            // Build enhanced video entry with preview hash
            $enhancedEntry = [
                'name' => $video['name'],
                'dirIndex' => $video['dirIndex'],
                'path' => $videoDir . DIRECTORY_SEPARATOR . $video['name'],
                'preview_hash' => $hash,
                'preview_mtime' => $mtime
            ];
            $enhancedVideoFiles[] = $enhancedEntry;
            
            // Skip if preview already exists and is newer than video
            if (file_exists($previewPath) && filemtime($previewPath) >= filemtime($videoPath)) {
                $processedVideos++;
                continue;
            }
            
            // Generate preview using FFmpeg
            $durationCmd = 'ffprobe -v quiet -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($videoPath) . ' 2> ' . (stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null');
            $duration = @exec($durationCmd);
            $duration = floatval($duration) ?: 0;
            
            // Extract a 6-second segment from middle of video
            $seekTime = $duration > 0 ? max(1, ($duration / 2) - 3) : 2.0;
            $segmentDuration = min(6.0, $duration > 0 ? $duration : 6.0);
            
            // Generate preview video loop
            $ffmpegCmd = "ffmpeg -ss " . $seekTime . " -i " . escapeshellarg($videoPath) . " -t " . $segmentDuration . " -c:v libx264 -preset fast -crf 28 -c:a aac -b:a 64k -vf \"scale=480:-1\" -movflags +faststart -y " . escapeshellarg($previewPath) . " 2>&1";
            $output = [];
            $returnCode = 0;
            @exec($ffmpegCmd, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($previewPath)) {
                $processedVideos++;
            } else {
                $failedVideos++;
                // Clean up failed preview
                if (file_exists($previewPath)) {
                    @unlink($previewPath);
                }
                // Remove preview hash from failed video
                $enhancedEntry['preview_hash'] = null;
                $enhancedEntry['preview_mtime'] = null;
            }
        }
        
        // Update admin cache with enhanced video files
        $adminCache['all_video_files'] = $enhancedVideoFiles;
        
        // Save updated admin cache
        if (!empty($enhancedVideoFiles)) {
            file_put_contents($adminCachePath, json_encode($adminCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        
        // Set success flash message with results
        $message = "Preview generation completed: {$processedVideos} generated, {$failedVideos} failed out of {$totalVideos} total videos.";
        if ($failedVideos > 0) {
            $message .= " Failed videos may need FFmpeg to be installed or may have permission issues.";
        }
        $message .= " Preview hashes added to admin cache for efficient lookup.";
        
        $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
        header('Location: admin.php?admin-panel=system-status');
        exit;
    }

    private function handleSystemReset() {
        // Default base config
        $defaultConfig = [
            'directory' => 'videos',
            'rows' => 2,
            'clipsPerRow' => 4,
            'dashboardBackground' => '',
            'directories' => ['videos']
        ];
        
        // Reset config
        foreach ($defaultConfig as $key => $value) {
            $this->config->setConfig($key, $value);
        }
        $this->config->saveConfiguration();

        // Default dashboards: only 'default'
        $defaultDashboards = [
            'default' => [
                'id' => 'default',
                'name' => 'Default',
                'rows' => 2,
                'clipsPerRow' => 4,
                'dashboardBackground' => ''
            ]
        ];
        $this->config->setDashboard('default', $defaultDashboards['default']);
        $this->config->saveDashboards();

        // Reset screens
        $this->config->getScreens()['screens'] = [];
        $this->config->saveScreens();

        $this->triggerDashboardRefresh('default');
        header('Location: admin.php?admin-panel=system-status');
        exit;
    }

    private function handleScreenManagement() {
        $smAction = (string)($_POST['sm_action'] ?? '');
        
        switch ($smAction) {
            case 'add-dashboard':
                return $this->handleAddDashboard();
            case 'add-screen':
                return $this->handleAddScreen();
            case 'delete-screen':
                return $this->handleDeleteScreen();
            case 'delete-dashboard':
                return $this->handleDeleteDashboardFromScreens();
        }
        
        return false;
    }

    private function handleAddDashboard() {
        $newId = trim((string)($_POST['new_dashboard_id'] ?? ''));
        $newName = trim((string)($_POST['new_dashboard_name'] ?? ''));
        
        if ($newId === '') {
            $dashboards = $this->config->getDashboards();
            $i = 1;
            $candidate = 'dashboard' . $i;
            while (isset($dashboards[$candidate])) {
                $i++;
                $candidate = 'dashboard' . $i;
            }
            $newId = $candidate;
            if ($newName === '') $newName = 'Dashboard ' . $i;
        }
        
        $dashboards = $this->config->getDashboards();
        if (!isset($dashboards[$newId])) {
            $config = $this->config->getConfig();
            $this->config->setDashboard($newId, [
                'id' => $newId,
                'name' => $newName !== '' ? $newName : ucfirst($newId),
                'rows' => (int)($config['rows'] ?? 2),
                'clipsPerRow' => (int)($config['clipsPerRow'] ?? 5),
                'dashboardBackground' => (string)($config['dashboardBackground'] ?? ''),
            ]);
            $this->config->saveDashboards();
        }
        
        $this->triggerDashboardRefresh('default');
        header('Location: admin.php?admin-panel=screen-management#screen-management');
        exit;
    }

    private function handleAddScreen() {
        $toDashboard = trim((string)($_POST['to_dashboard'] ?? 'default'));
        $screenName = trim((string)($_POST['screen_name'] ?? ''));
        
        $screenId = uniqid('screen_', true);
        $this->config->addScreen([
            'id' => $screenId,
            'name' => $screenName !== '' ? $screenName : 'Screen',
            'profile' => $toDashboard,
            'createdAt' => time(),
        ]);
        $this->config->saveScreens();
        
        header('Location: admin.php?admin-panel=screen-management#screen-management');
        exit;
    }

    private function handleDeleteScreen() {
        $sid = (string)($_POST['screen_id'] ?? '');
        $this->config->removeScreen($sid);
        $this->config->saveScreens();
        
        header('Location: admin.php?admin-panel=screen-management#screen-management');
        exit;
    }

    private function handleDeleteDashboardFromScreens() {
        $delId = trim((string)($_POST['dashboard_id'] ?? ''));
        if ($delId && $delId !== 'default') {
            // Remove dashboard profile
            $this->config->removeDashboard($delId);
            $this->config->saveDashboards();
            
            // Remove screens linked to this profile
            $screens = $this->config->getScreens();
            if (isset($screens['screens']) && is_array($screens['screens'])) {
                $screens['screens'] = array_values(array_filter($screens['screens'], function($s) use ($delId) {
                    return ($s['profile'] ?? '') !== $delId;
                }));
                $this->config->saveScreens();
            }
            
            // Remove profile state directory data/profiles/{id}
            $profileDir = $this->baseDir . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $delId);
            if (is_dir($profileDir)) {
                $this->removeDirectory($profileDir);
            }
        }
        
        $this->triggerDashboardRefresh('default');
        header('Location: admin.php?admin-panel=screen-management#screen-management');
        exit;
    }

    private function handleDirectoryConfig() {
        // Gather directories from multiple sources
        $postedDirs = [];
        if (isset($_POST['directories']) && is_array($_POST['directories'])) {
            $postedDirs = array_values(array_filter(array_map('trim', $_POST['directories'])));
        } elseif (!empty($_POST['directories_json'])) {
            $decoded = json_decode($_POST['directories_json'], true);
            if (is_array($decoded)) {
                $postedDirs = array_values(array_filter(array_map('trim', $decoded)));
            }
        } else {
            $legacy = trim($_POST['directory'] ?? '');
            if ($legacy !== '') $postedDirs[] = $legacy;
        }

        // Validate each directory
        $validDirs = [];
        foreach ($postedDirs as $dir) {
            if (is_dir($dir)) {
                $validDirs[] = $dir;
            } elseif (is_dir($this->baseDir . '/' . $dir)) {
                $validDirs[] = $this->baseDir . '/' . $dir;
            }
        }
        $validDirs = array_values(array_unique($validDirs));

        if (count($validDirs) === 0) {
            $config = $this->config->getConfig();
            if (!empty($config['directories']) && is_array($config['directories'])) {
                $validDirs = $config['directories'];
            } elseif (!empty($config['directory'])) {
                $validDirs = [$config['directory']];
            } else {
                $defaultDir = $this->baseDir . '/videos';
                if (is_dir($defaultDir)) {
                    $validDirs = [$defaultDir];
                } else {
                    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Error: No valid directories provided and default videos directory not found.'];
                    header('Location: admin.php?admin-panel=directory-config');
                    exit;
                }
            }
        }
        
        // Save multi-directories and keep legacy first directory for compatibility
        $this->config->setConfig('directories', $validDirs);
        $this->config->setConfig('directory', $validDirs[0]);
        $this->config->saveConfiguration();
        
        $_SESSION['flash'] = array('type' => 'success', 'message' => 'Configuration saved successfully');
        $this->config->invalidateCache();
        $this->triggerDashboardRefresh('default');
        
        header('Location: admin.php?admin-panel=directory-config');
        exit;
    }

    private function triggerDashboardRefresh($profileId) {
        if (function_exists('triggerDashboardRefresh')) {
            return triggerDashboardRefresh($profileId);
        }
        
        // Fallback implementation
        $baseDir = $this->baseDir;
        $dataDir = $baseDir . '/data';
        $profilesDir = $dataDir . '/profiles';
        if (!is_dir($profilesDir)) @mkdir($profilesDir, 0777, true);
        $profileDir = $profilesDir . '/' . $profileId;
        if (!is_dir($profileDir)) @mkdir($profileDir, 0777, true);
        $refreshFile = $profileDir . '/dashboard_refresh.txt';
        return file_put_contents($refreshFile, time()) !== false;
    }

    private function removeDirectory($dir) {
        if (!is_dir($dir)) return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
