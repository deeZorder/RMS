<?php
// admin.php
// Administrative page used to configure the media system.

// Security headers (must be sent before any output)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

// Start session for flash messages
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Path to the configuration file relative to this script
$configPath = __DIR__ . '/config.json';
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) { @mkdir($dataDir, 0777, true); }
$dashboardsPath = $dataDir . '/dashboards.json';
$screensPath = $dataDir . '/screens.json';

// Cache management for admin page performance
$adminCachePath = $dataDir . '/admin_cache.json';
$adminCache = [];

// Load existing configuration values early so we can evaluate cache freshness correctly
if (!isset($config) || !is_array($config)) {
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true);
        if (!is_array($config)) {
            $config = [ 'directory' => 'videos', 'rows' => 2, 'clipsPerRow' => 4, 'dashboardBackground' => '' ];
        }
    } else {
        $config = [ 'directory' => 'videos', 'rows' => 2, 'clipsPerRow' => 4, 'dashboardBackground' => '' ];
    }
}

// Check if we need to refresh admin cache
$needsAdminScan = true;
if (file_exists($adminCachePath)) {
    $adminCache = json_decode(file_get_contents($adminCachePath), true) ?: [];
    $lastScanTime = $adminCache['last_scan'] ?? 0;

    // Check if any configured directories have changed (only those directories, not project root)
    $maxDirModTime = 0;
    $configuredDirs = [];
    if (!empty($config['directories']) && is_array($config['directories'])) {
        $configuredDirs = $config['directories'];
    } else {
        $configuredDirs = [$config['directory'] ?? 'videos'];
    }

    foreach ($configuredDirs as $dir) {
        if (is_dir($dir)) {
            $mt = @filemtime($dir) ?: 0;
            if ($mt > $maxDirModTime) { $maxDirModTime = $mt; }
        } else {
            // Try relative path
            $relativePath = realpath(__DIR__ . '/' . $dir);
            if ($relativePath && is_dir($relativePath)) {
                $mt = @filemtime($relativePath) ?: 0;
                if ($mt > $maxDirModTime) { $maxDirModTime = $mt; }
            }
        }
    }

    // If we couldn't resolve any configured directory, fall back to the default videos folder
    if ($maxDirModTime === 0) {
        $videosPath = __DIR__ . '/videos';
        if (is_dir($videosPath)) {
            $maxDirModTime = @filemtime($videosPath) ?: 0;
        }
    }

    if ($maxDirModTime <= $lastScanTime) {
        $needsAdminScan = false;
    }
}

// Load existing configuration values (already loaded above for cache check; ensure it's an array)
if (!isset($config) || !is_array($config)) {
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true);
    }
    if (!is_array($config)) {
        $config = [ 'directory' => 'videos', 'rows' => 2, 'clipsPerRow' => 4, 'dashboardBackground' => '' ];
    }
}

// Ensure missing config keys have sane defaults without overriding existing values
if (!isset($config['directory']) || !is_string($config['directory']) || $config['directory'] === '') {
    $config['directory'] = 'videos';
}
if (!isset($config['rows']) || (int)$config['rows'] < 1) {
    $config['rows'] = 2;
}
if (!isset($config['clipsPerRow']) || (int)$config['clipsPerRow'] < 1) {
    $config['clipsPerRow'] = 4;
}

// Load dashboards (multiple dashboard profiles)
$dashboards = [];
if (file_exists($dashboardsPath)) {
    $decodedDash = json_decode(@file_get_contents($dashboardsPath), true);
    if (is_array($decodedDash)) { $dashboards = $decodedDash; }
}
// Load screens registry
$screens = [];
if (file_exists($screensPath)) {
    $decodedScreens = json_decode(@file_get_contents($screensPath), true);
    if (is_array($decodedScreens)) { $screens = $decodedScreens; }
}
if (!isset($screens['screens']) || !is_array($screens['screens'])) {
    $screens['screens'] = [];
}
// Ensure a default profile exists
if (empty($dashboards) || !isset($dashboards['default'])) {
    $dashboards['default'] = [
        'id' => 'default',
        'name' => 'Default',
        'rows' => (int)($config['rows'] ?? 2),
        'clipsPerRow' => (int)($config['clipsPerRow'] ?? 4),
        'dashboardBackground' => (string)($config['dashboardBackground'] ?? ''),
    ];
    file_put_contents($dashboardsPath, json_encode($dashboards, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
// Normalize default profile name to "Default" if needed
if (isset($dashboards['default'])) {
    if (($dashboards['default']['name'] ?? '') !== 'Default') {
        $dashboards['default']['name'] = 'Default';
        file_put_contents($dashboardsPath, json_encode($dashboards, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

// Handle form submission and flash messages
$message = '';
$flash = $_SESSION['flash'] ?? null; // capture and clear flash for one-time display
if (isset($_SESSION['flash'])) {
    unset($_SESSION['flash']);
}
$hasError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Removed verbose POST debugging logs

    // Determine which form was submitted based on current_section
    $currentSection = $_POST['current_section'] ?? 'directory-config';
    
    // Only process dashboard settings if the dashboard-settings form was submitted
    if ($currentSection === 'dashboard-settings') {
        $dashboardId = trim((string)($_POST['dashboard_id'] ?? 'default')) ?: 'default';
        $dashboardName = trim((string)($_POST['dashboard_name'] ?? '')) ?: ($dashboardId === 'default' ? 'Default' : ucfirst($dashboardId));
        $rows = (int)($_POST['rows'] ?? 2);
        $clipsPerRow = (int)($_POST['clipsPerRow'] ?? 5);
        $dashboardBackground = isset($_POST['dashboardBackground']) ? trim((string)$_POST['dashboardBackground']) : '';
        if ($rows < 1) { $rows = 1; }
        if ($clipsPerRow < 1) { $clipsPerRow = 1; }
        
        // Update selected dashboard profile
        if (!isset($dashboards[$dashboardId])) {
            // Create new profile if not exists
            $dashboards[$dashboardId] = [
                'id' => $dashboardId,
                'name' => $dashboardName,
                'rows' => $rows,
                'clipsPerRow' => $clipsPerRow,
                'dashboardBackground' => ($dashboardBackground === 'none') ? '' : $dashboardBackground,
            ];
        } else {
            $dashboards[$dashboardId]['name'] = $dashboardName;
            $dashboards[$dashboardId]['rows'] = $rows;
            $dashboards[$dashboardId]['clipsPerRow'] = $clipsPerRow;
            $dashboards[$dashboardId]['dashboardBackground'] = ($dashboardBackground === 'none') ? '' : $dashboardBackground;
        }
        file_put_contents($dashboardsPath, json_encode($dashboards, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Also update base config defaults for backward compatibility
        $config['rows'] = $rows;
        $config['clipsPerRow'] = $clipsPerRow;
        $config['dashboardBackground'] = ($dashboardBackground === 'none') ? '' : $dashboardBackground;
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // Set success flash and trigger dashboard refresh
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Configuration saved successfully'];
        
        // Invalidate admin cache since dashboard settings changed
        if (file_exists($adminCachePath)) {
            @unlink($adminCachePath);
        }
        
        // Trigger refresh for the selected dashboard profile (per-profile refresh file)
        $sanitizedId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $dashboardId);
        $profileDir = __DIR__ . '/data/profiles/' . ($sanitizedId !== '' ? $sanitizedId : 'default');
        if (!is_dir($profileDir)) { @mkdir($profileDir, 0777, true); }
        @file_put_contents($profileDir . '/dashboard_refresh.txt', time());
        // Also write legacy/global locations for older dashboards still polling globally
        @file_put_contents(__DIR__ . '/data/dashboard_refresh.txt', time());
        @file_put_contents(__DIR__ . '/dashboard_refresh.txt', time());
        // Redirect without query param
        header('Location: admin.php?admin-panel=' . urlencode($currentSection) . '&dashboard=' . urlencode($dashboardId));
        exit;
    }
    
    // Delete dashboard profile
    if (($currentSection === 'delete-dashboard') && !empty($_POST['dashboard_id'])) {
        $id = trim((string)$_POST['dashboard_id']);
        if ($id && $id !== 'default') {
            $dash = [];
            if (file_exists($dashboardsPath)) {
                $dash = json_decode(@file_get_contents($dashboardsPath), true) ?: [];
            }
            if (isset($dash[$id])) {
                unset($dash[$id]);
                file_put_contents($dashboardsPath, json_encode($dash, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }
        // Trigger refresh and redirect back
        @file_put_contents(__DIR__ . '/data/dashboard_refresh.txt', time());
        header('Location: admin.php?admin-panel=dashboard-settings&dashboard=default');
        exit;
    }

    // System action: clear thumbnails and titles
    if ($currentSection === 'clear-thumbs-titles') {
        $baseDir = __DIR__;
        $thumbDir = $baseDir . '/data/thumbs';
        $titlesFile = $baseDir . '/data/video_titles.json';
        $adminCachePath = $baseDir . '/data/admin_cache.json';

        // Remove all thumbnails
        if (is_dir($thumbDir)) {
            $items = @scandir($thumbDir) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') { continue; }
                $path = $thumbDir . DIRECTORY_SEPARATOR . $item;
                if (is_file($path)) { @unlink($path); }
            }
        }

        // Remove titles file
        if (file_exists($titlesFile)) { @unlink($titlesFile); }

        // Invalidate admin cache so warm_thumbnails uses fresh file list
        if (file_exists($adminCachePath)) { @unlink($adminCachePath); }

        // Touch refresh so dashboards/screens update
        @file_put_contents($baseDir . '/data/dashboard_refresh.txt', time());

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Cleared thumbnails and titles'];
        header('Location: admin.php?admin-panel=system-status');
        exit;
    }

    // System action: trigger dashboard refresh signal
    if ($currentSection === 'system-refresh') {
        // Touch per-profile default and legacy/global refresh files
        $defaultProfileDir = __DIR__ . '/data/profiles/default';
        if (!is_dir($defaultProfileDir)) { @mkdir($defaultProfileDir, 0777, true); }
        @file_put_contents($defaultProfileDir . '/dashboard_refresh.txt', time());
        @file_put_contents(__DIR__ . '/data/dashboard_refresh.txt', time());
        @file_put_contents(__DIR__ . '/dashboard_refresh.txt', time());

        // Optional flash message
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Dashboard refresh signal sent'];

        // Redirect back to System Status
        header('Location: admin.php?admin-panel=system-status');
        exit;
    }

    // System action: generate thumbnails for all videos
    if ($currentSection === 'generate-thumbnails') {
        // Force a fresh scan by invalidating admin cache
        if (file_exists($adminCachePath)) {
            @unlink($adminCachePath);
        }
        
        // Ensure thumbs directory exists
        $thumbDir = __DIR__ . '/data/thumbs';
        if (!is_dir($thumbDir)) {
            @mkdir($thumbDir, 0777, true);
        }
        
        // Get configured directories and scan for videos
        $configuredDirs = [];
        if (!empty($config['directories']) && is_array($config['directories'])) {
            $configuredDirs = $config['directories'];
        } else {
            $configuredDirs = [$config['directory'] ?? 'videos'];
        }
        
        $allowedExt = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'];
        $totalVideos = 0;
        $processedVideos = 0;
        $failedVideos = 0;
        
        // Scan each configured directory
        foreach ($configuredDirs as $dirIndex => $dir) {
            $fullPath = $dir;
            if (!is_dir($fullPath)) {
                // Try relative path if absolute doesn't exist
                $relativePath = realpath(__DIR__ . '/' . $dir);
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
        $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
        
        // Redirect back to System Status
        header('Location: admin.php?admin-panel=system-status');
        exit;
    }

    // System reset: revert to defaults (config + dashboards)
    if ($currentSection === 'system-reset') {
        // Default base config
        $defaultConfig = [
            'directory' => 'videos',
            'rows' => 2,
            'clipsPerRow' => 4,
            'dashboardBackground' => '',
            'directories' => [ 'videos' ]
        ];
        file_put_contents($configPath, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
        file_put_contents($dashboardsPath, json_encode($defaultDashboards, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Optionally clear screens associations but keep file structure
        $screensReset = [ 'screens' => [] ];
        file_put_contents($screensPath, json_encode($screensReset, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Touch refresh signal
        @file_put_contents(__DIR__ . '/data/dashboard_refresh.txt', time());

        // Redirect back to System Status
        header('Location: admin.php?admin-panel=system-status');
        exit;
    }
    // Create/Update Dashboard from Screen Management
    if ($currentSection === 'screen-management' && isset($_POST['sm_action'])) {
        $smAction = (string)$_POST['sm_action'];
        if ($smAction === 'add-dashboard') {
            $newId = trim((string)($_POST['new_dashboard_id'] ?? ''));
            $newName = trim((string)($_POST['new_dashboard_name'] ?? ''));
            if ($newId === '') {
                // auto-generate id like dashboardN
                $i = 1; $candidate = 'dashboard' . $i;
                while (isset($dashboards[$candidate])) { $i++; $candidate = 'dashboard' . $i; }
                $newId = $candidate;
                if ($newName === '') { $newName = 'Dashboard ' . $i; }
            }
            if (!isset($dashboards[$newId])) {
                $dashboards[$newId] = [
                    'id' => $newId,
                    'name' => $newName !== '' ? $newName : ucfirst($newId),
                    'rows' => (int)($config['rows'] ?? 2),
                    'clipsPerRow' => (int)($config['clipsPerRow'] ?? 5),
                    'dashboardBackground' => (string)($config['dashboardBackground'] ?? ''),
                ];
                file_put_contents($dashboardsPath, json_encode($dashboards, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
            @file_put_contents(__DIR__ . '/data/dashboard_refresh.txt', time());
            header('Location: admin.php?admin-panel=screen-management#screen-management');
            exit;
        } elseif ($smAction === 'add-screen') {
            $toDashboard = trim((string)($_POST['to_dashboard'] ?? 'default'));
            $screenName = trim((string)($_POST['screen_name'] ?? '')); // optional label
            // Create a screen record with an ID and linked profile
            $screenId = uniqid('screen_', true);
            $screens['screens'][] = [
                'id' => $screenId,
                'name' => $screenName !== '' ? $screenName : 'Screen',
                'profile' => $toDashboard,
                'createdAt' => time(),
            ];
            file_put_contents($screensPath, json_encode($screens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            header('Location: admin.php?admin-panel=screen-management#screen-management');
            exit;
        } elseif ($smAction === 'delete-screen') {
            $sid = (string)($_POST['screen_id'] ?? '');
            $screens['screens'] = array_values(array_filter($screens['screens'], function ($s) use ($sid) { return ($s['id'] ?? '') !== $sid; }));
            file_put_contents($screensPath, json_encode($screens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            header('Location: admin.php?admin-panel=screen-management#screen-management');
            exit;
        } elseif ($smAction === 'delete-dashboard') {
            $delId = trim((string)($_POST['dashboard_id'] ?? ''));
            if ($delId && $delId !== 'default') {
                // Remove dashboard profile
                if (isset($dashboards[$delId])) {
                    unset($dashboards[$delId]);
                    file_put_contents($dashboardsPath, json_encode($dashboards, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
                // Remove screens linked to this profile
                if (isset($screens['screens']) && is_array($screens['screens'])) {
                    $screens['screens'] = array_values(array_filter($screens['screens'], function ($s) use ($delId) {
                        return ($s['profile'] ?? '') !== $delId;
                    }));
                    file_put_contents($screensPath, json_encode($screens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
                // Remove profile state directory data/profiles/{id}
                $profileDir = __DIR__ . '/data/profiles/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $delId);
                if (is_dir($profileDir)) {
                    $rii = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($profileDir, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($rii as $file) {
                        try {
                            if ($file->isDir()) { @rmdir($file->getPathname()); }
                            else { @unlink($file->getPathname()); }
                        } catch (Throwable $e) { /* ignore */ }
                    }
                    @rmdir($profileDir);
                }
            }
            @file_put_contents(__DIR__ . '/data/dashboard_refresh.txt', time());
            header('Location: admin.php?admin-panel=screen-management#screen-management');
            exit;
        }
    }

    // Process directory configuration (for directory-config section)
    // Gather directories from multiple sources: directories[], directories_json, or legacy 'directory'
    $postedDirs = [];
    if (isset($_POST['directories']) && is_array($_POST['directories'])) {
        $postedDirs = array_values(array_filter(array_map('trim', $_POST['directories'])));
    } elseif (!empty($_POST['directories_json'])) {
        $decoded = json_decode($_POST['directories_json'], true);
        if (is_array($decoded)) { $postedDirs = array_values(array_filter(array_map('trim', $decoded))); }
    } else {
        $legacy = trim($_POST['directory'] ?? '');
        if ($legacy !== '') { $postedDirs[] = $legacy; }
    }

    // Validate each directory
    $validDirs = [];
    foreach ($postedDirs as $dir) {
        if (is_dir($dir)) {
            $validDirs[] = $dir;
        } elseif (is_dir(__DIR__ . '/' . $dir)) {
            $validDirs[] = __DIR__ . '/' . $dir;
        }
    }
    $validDirs = array_values(array_unique($validDirs));

    if (count($validDirs) === 0) {
        // If no directories were provided in the form, preserve existing directory configuration
        if (!empty($config['directories']) && is_array($config['directories'])) {
            $validDirs = $config['directories'];
        } elseif (!empty($config['directory'])) {
            $validDirs = [$config['directory']];
        } else {
            // Fall back to default videos directory if no existing configuration
            $defaultDir = __DIR__ . '/videos';
            if (is_dir($defaultDir)) {
                $validDirs = [$defaultDir];
            } else {
                $hasError = true;
                $message = 'Error: No valid directories provided and default videos directory not found.';
            }
        }
    }
    
    if (!$hasError) {
        // Save multi-directories and keep legacy first directory for compatibility
        $config['directories'] = $validDirs;
        $config['directory'] = $validDirs[0];
        // Note: We don't update rows/clipsPerRow here to preserve dashboard settings
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // Preserve the current section in the redirect
        $currentSection = $_POST['current_section'] ?? 'directory-config';
        // Set success flash and trigger dashboard refresh
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Configuration saved successfully'];
        
        // Invalidate admin cache since directories changed
        if (file_exists($adminCachePath)) {
            @unlink($adminCachePath);
        }
        
        @file_put_contents(__DIR__ . '/data/dashboard_refresh.txt', time());
        @file_put_contents(__DIR__ . '/dashboard_refresh.txt', time());
        // Redirect without query param
        header('Location: admin.php?admin-panel=' . urlencode($currentSection));
        exit;
    }
}

// Get available directories for selection (include subdirectories)
$availableDirectories = [];
// Use cached data or perform fresh scan
if ($needsAdminScan) {
    // Fresh scan needed - cache is stale
    $startTime = microtime(true);
    
    $projectRoot = __DIR__;

    // Add root level directories
    foreach (scandir($projectRoot) as $item) {
        $itemPath = $projectRoot . '/' . $item;
        if (is_dir($itemPath) && $item !== '.' && $item !== '..' && $item !== 'assets') {
            $availableDirectories[] = $item;
        }
    }

    // Add subdirectories from videos folder
    $videosPath = $projectRoot . '/videos';
    if (is_dir($videosPath)) {
        foreach (scandir($videosPath) as $item) {
            $itemPath = $videosPath . '/' . $item;
            if (is_dir($itemPath) && $item !== '.' && $item !== '..') {
                $availableDirectories[] = 'videos/' . $item;
            }
        }
    }

    // Get all configured directories
    $configuredDirs = [];
    if (!empty($config['directories']) && is_array($config['directories'])) {
        $configuredDirs = $config['directories'];
    } else {
        $configuredDirs = [$config['directory'] ?? 'videos'];
    }

    // Handle pagination for large video collections
    $page = max(1, (int)($_GET['page'] ?? 1));
    $videosPerPage = 20; // Show 20 videos per page
    $offset = ($page - 1) * $videosPerPage;

    // Count total videos across all configured directories
    $totalVideos = 0;
    $allVideoFiles = [];
    $paginationInfo = ['total' => 0, 'pages' => 0, 'current' => $page];

    $allowedExt = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'];

    // Scan all configured directories
    foreach ($configuredDirs as $dirIndex => $dir) {
        if (!is_dir($dir)) {
            // Try relative path if absolute doesn't exist
            $relativePath = realpath(__DIR__ . '/' . $dir);
            if ($relativePath && is_dir($relativePath)) {
                $dir = $relativePath;
            } else {
                continue; // Skip invalid directories
            }
        }
        
        $allFiles = scandir($dir);
        if ($allFiles === false) continue;
        
        foreach ($allFiles as $file) {
            if ($file !== '.' && $file !== '..' && is_file($dir . DIRECTORY_SEPARATOR . $file)) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExt)) {
                    $allVideoFiles[] = [
                        'name' => $file,
                        'dirIndex' => $dirIndex,
                        'path' => $dir . DIRECTORY_SEPARATOR . $file
                    ];
                }
            }
        }
    }

    // Sort files for consistent pagination
    usort($allVideoFiles, function ($a, $b) {
        $c = strcmp($a['name'], $b['name']);
        return $c !== 0 ? $c : ($a['dirIndex'] <=> $b['dirIndex']);
    });

    $totalVideos = count($allVideoFiles);
    $totalPages = max(1, ceil($totalVideos / $videosPerPage));

    // Get only the files for current page
    $videoFiles = array_slice($allVideoFiles, $offset, $videosPerPage);

    $paginationInfo = [
        'total' => $totalVideos,
        'pages' => $totalPages,
        'current' => $page,
        'per_page' => $videosPerPage,
        'start' => $offset + 1,
        'end' => min($offset + $videosPerPage, $totalVideos)
    ];
    
    // Cache the results
    $adminCache = [
        'last_scan' => time(),
        'available_directories' => $availableDirectories,
        'total_videos' => $totalVideos,
        'all_video_files' => $allVideoFiles,
        'pagination_info' => $paginationInfo
    ];
    file_put_contents($adminCachePath, json_encode($adminCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    
    // Scan completed
    
} else {
    // Use cached data - much faster
    $startTime = microtime(true);
    
    $availableDirectories = $adminCache['available_directories'] ?? [];
    $totalVideos = $adminCache['total_videos'] ?? 0;
    $allVideoFiles = $adminCache['all_video_files'] ?? [];
    
    // Handle pagination for current page
    $page = max(1, (int)($_GET['page'] ?? 1));
    $videosPerPage = 20;
    $offset = ($page - 1) * $videosPerPage;
    
    $totalPages = max(1, ceil($totalVideos / $videosPerPage));
    $videoFiles = array_slice($allVideoFiles, $offset, $videosPerPage);
    
    $paginationInfo = [
        'total' => $totalVideos,
        'pages' => $totalPages,
        'current' => $page,
        'per_page' => $videosPerPage,
        'start' => $offset + 1,
        'end' => min($offset + $videosPerPage, $totalVideos)
    ];
    
    // Cache loaded
}

$activeSection = isset($_GET['section']) ? (string)$_GET['section'] : '';

// Discover available dashboard backgrounds (use cache if available)
$backgroundDir = __DIR__ . '/assets/backgrounds';
$availableBackgrounds = [];

if ($needsAdminScan || !isset($adminCache['available_backgrounds'])) {
    // Fresh scan needed
    if (is_dir($backgroundDir)) {
        $files = @scandir($backgroundDir) ?: [];
        foreach ($files as $bgFile) {
            if ($bgFile === '.' || $bgFile === '..') { continue; }
            $ext = strtolower(pathinfo($bgFile, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $availableBackgrounds[] = 'assets/backgrounds/' . $bgFile;
            }
        }
    }
    
    // Update cache with backgrounds
    if ($needsAdminScan) {
        $adminCache['available_backgrounds'] = $availableBackgrounds;
        file_put_contents($adminCachePath, json_encode($adminCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
} else {
    // Use cached backgrounds
    $availableBackgrounds = $adminCache['available_backgrounds'] ?? [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Relax Media System</title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo filemtime(__DIR__ . '/assets/style.css'); ?>">
    <style>
        /* Importing Modal Styles */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background-color: #2a2a2a;
            border: 1px solid #444;
            border-radius: 8px;
            padding: 0;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
            animation: modalSlideIn 0.3s ease-out;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            background: linear-gradient(135deg, #4ecdc4, #44a08d);
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
            text-align: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.4em;
        }
        
        .modal-body {
            padding: 30px;
            text-align: center;
        }
        
        .importing-animation {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }
        
        .spinner {
            width: 60px;
            height: 60px;
            border: 4px solid #333;
            border-top: 4px solid #4ecdc4;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .importing-details {
            color: #888;
            font-size: 0.9em;
            margin-top: 10px;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background-color: #333;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 20px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4ecdc4, #44a08d);
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 4px;
        }
        
        /* Thumbnail Modal Styles */
        .thumbnail-animation {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }
        
        .thumbnail-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            width: 100%;
            margin-top: 10px;
        }
        
        .stat-item {
            text-align: center;
            padding: 10px;
            background: #1a1a1a;
            border-radius: 6px;
            border: 1px solid #333;
        }
        
        .stat-label {
            display: block;
            font-size: 0.8em;
            color: #888;
            margin-bottom: 5px;
        }
        
        .stat-value {
            display: block;
            font-size: 1.2em;
            font-weight: bold;
            color: #4ecdc4;
        }
        
        .console-output {
            width: 100%;
            max-height: 200px;
            background: #0a0a0a;
            border: 1px solid #333;
            border-radius: 6px;
            overflow: hidden;
        }
        
        .console-header {
            background: #333;
            color: #fff;
            padding: 8px 12px;
            font-size: 0.9em;
            font-weight: bold;
            border-bottom: 1px solid #444;
        }
        
        .console-content {
            padding: 12px;
            max-height: 150px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.85em;
            line-height: 1.4;
            color: #ccc;
        }
        
        .console-content .log-entry {
            margin-bottom: 5px;
            padding: 3px 0;
        }
        
        .console-content .log-info { color: #4ecdc4; }
        .console-content .log-success { color: #4ecdc4; }
        .console-content .log-warning { color: #ffa500; }
        .console-content .log-error { color: #ff6b6b; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="admin-page">
        <div class="admin-layout">
            <!-- Left Sidebar Navigation -->
            <nav class="admin-sidebar">
                <div class="sidebar-header">
                    <h2>Admin Panel</h2>
                </div>
                <ul class="sidebar-nav">
                    <li><a href="#directory-config" class="nav-link active" data-section="directory-config">
                        <span class="nav-icon">📁</span>
                        <span class="nav-text">Directory Configuration</span>
                    </a></li>
                    <li><a href="#video-management" class="nav-link" data-section="video-management">
                        <span class="nav-icon">🎬</span>
                        <span class="nav-text">Video Management</span>
                    </a></li>
                    <li><a href="#dashboard-settings" class="nav-link" data-section="dashboard-settings">
                        <span class="nav-icon">⚙️</span>
                        <span class="nav-text">Dashboard Settings</span>
                    </a></li>
                     <li><a href="#screen-management" class="nav-link" data-section="screen-management">
                         <span class="nav-icon">🖥️</span>
                         <span class="nav-text">Screen Management</span>
                     </a></li>
                    <li><a href="#system-status" class="nav-link" data-section="system-status">
                        <span class="nav-icon">📊</span>
                        <span class="nav-text">System Status</span>
                    </a></li>
                </ul>
            </nav>

            <!-- Main Content Area -->
            <div class="admin-content">
                
                <!-- Directory Configuration Section -->
                <section id="directory-config" class="admin-section active">
                                    <div class="section-header">
                    <h3>📁 Directory Configuration</h3>
                    <p>Configure video directories and manage file locations</p>
                    <div style="font-size: 11px; color: #666; font-style: italic; margin-top: 5px;">
                        <?php if ($needsAdminScan): ?>
                            🔄 Scanning directories for changes...
                        <?php else: ?>
                            ✅ Using cached data (<?php echo round((time() - ($adminCache['last_scan'] ?? 0)) / 60, 1); ?>m old)
                        <?php endif; ?>
                    </div>
                </div>
                    
                    <form class="admin-form" method="post" action="admin.php">
                        <div class="form-group">
                            <label for="directory">Clips directories</label>
                            <div class="form-input-row">
                                <input type="text" id="directory" name="directory" value="" placeholder="Add a directory and press +">
                                <button type="button" id="browse-directory-btn" class="btn secondary">📁 Browse</button>
                                <button type="button" id="add-directory-btn" class="btn primary">＋ Add</button>
                            </div>
                            <small class="hint">Add one or more folders (Windows or UNIX paths). Use Browse to pick, then click ＋ Add. Drag to reorder.</small>
                            <input type="hidden" id="directories_json" name="directories_json" value="<?php echo htmlspecialchars(json_encode($config['directories'] ?? [$config['directory'] ?? 'videos'])); ?>">
                            <input type="hidden" id="current_section" name="current_section" value="directory-config">
                            <div id="directories-chips" class="chips"></div>
                            
                            <!-- Directory Browser -->
                            <div id="directory-browser">
                                <h4>Browse Directories</h4>
                                <div class="directory-browser-controls">
                                    <div class="directory-browser-path">
                                        <input type="text" id="browse-path" placeholder="Enter path to browse">
                                        <button type="button" id="browse-go-btn" class="btn primary">Go</button>
                                    </div>
                                    <div class="directory-browser-buttons">
                                        <button type="button" id="browse-home-btn" class="btn secondary">Home</button>
                                    </div>
                                </div>
                                <div id="directory-list">
                                    <!-- Directory list will be loaded here -->
                                </div>
                                <div class="directory-browser-buttons">
                                    <button type="button" id="select-directory-btn" class="btn primary" disabled>Select Directory</button>
                                    <button type="button" id="cancel-browse-btn" class="btn secondary">Cancel</button>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn primary btn-with-icon">💾 Save Configuration</button>
                                <?php if (!empty($flash) && ($activeSection === 'directory-config' || $activeSection === '')): ?>
                                    <span id="global-flash-alert" class="alert success" style="margin:0; padding:10px 14px; line-height:1; display:inline-flex; align-items:center; gap:8px;">✅ <?php echo htmlspecialchars($flash['message'] ?? ''); ?></span>
                                <?php endif; ?>
                                <small class="hint hint-right">Save your directory configuration to apply changes</small>
                            </div>
                        </div>
                    </form>
                </section>
                
                <!-- Importing Modal -->
                <div id="importing-modal" class="modal" style="display: none;">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>🔄 Importing File Data</h3>
                        </div>
                        <div class="modal-body">
                            <div class="importing-animation">
                                <div class="spinner"></div>
                                <p>Scanning directories and importing video files...</p>
                                <p class="importing-details">This may take a few moments depending on the number of files.</p>
                                <div class="progress-bar" style="display: none;">
                                    <div class="progress-fill"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Thumbnail Generation Modal -->
                <div id="thumbnail-modal" class="modal" style="display: none;">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>🖼️ Generating Thumbnails</h3>
                        </div>
                        <div class="modal-body">
                            <div class="thumbnail-animation">
                                <div class="spinner"></div>
                                <p id="thumbnail-status">Initializing thumbnail generation...</p>
                                <p class="thumbnail-details">This may take several minutes depending on the number of videos.</p>
                                <div class="progress-bar">
                                    <div class="progress-fill"></div>
                                </div>
                                <div class="thumbnail-stats">
                                    <div class="stat-item">
                                        <span class="stat-label">Total Videos:</span>
                                        <span class="stat-value" id="total-videos">0</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-label">Processed:</span>
                                        <span class="stat-value" id="processed-videos">0</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-label">Failed:</span>
                                        <span class="stat-value" id="failed-videos">0</span>
                                    </div>
                                </div>
                                <div class="console-output" id="console-output">
                                    <div class="console-header">Console Output:</div>
                                    <div class="console-content" id="console-content"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Dashboard Settings Section -->
                <section id="dashboard-settings" class="admin-section">
                    <div class="section-header">
                        <h3>⚙️ Dashboard Settings</h3>
                        <p>Choose a dashboard profile to customize its layout and background</p>
                    </div>
                    
                    <form class="admin-form" method="post" action="admin.php">
                        <input type="hidden" name="current_section" value="dashboard-settings">
                        <div class="form-group">
                            <label for="dashboard_id">Dashboard profile</label>
                            <div class="form-input-row">
                                <select id="dashboard_id" name="dashboard_id" style="max-width: 240px;">
                                    <?php 
                                    $selectedDashboard = $_GET['dashboard'] ?? 'default';
                                    foreach ($dashboards as $id => $d) {
                                        $sel = ($id === $selectedDashboard) ? 'selected' : '';
                                        $label = ($id === 'default') ? 'Default' : ($d['name'] ?? $id);
                                        echo '<option value="' . htmlspecialchars($id) . '" ' . $sel . '>' . htmlspecialchars($label) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <small class="hint">Select which dashboard to edit. To add or remove dashboards, use Screen Management.</small>
                        </div>
                        <div class="form-group">
                            <label for="rows">Number of rows on dashboard</label>
                            <input type="number" id="rows" name="rows" value="<?php echo (int)($dashboards[$selectedDashboard]['rows'] ?? $config['rows']); ?>" min="1" max="4" required>
                            <small class="hint">Maximum 4 rows allowed</small>
                        </div>
                        <div class="form-group">
                            <label for="clipsPerRow">Clips per row (max visible at once)</label>
                            <input type="number" id="clipsPerRow" name="clipsPerRow" value="<?php echo (int)($dashboards[$selectedDashboard]['clipsPerRow'] ?? $config['clipsPerRow']); ?>" min="1" max="8" required>
                            <small class="hint">Maximum 8 videos per row allowed</small>
                        </div>
                        <div class="form-group">
                            <label for="dashboardBackground">Dashboard background image</label>
                            <select id="dashboardBackground" name="dashboardBackground">
                                <option value="none">None (use animated gradient)</option>
                                <?php foreach ($availableBackgrounds as $bg): ?>
                                    <option value="<?php echo htmlspecialchars($bg); ?>" <?php echo (!empty($dashboards[$selectedDashboard]['dashboardBackground']) && $dashboards[$selectedDashboard]['dashboardBackground'] === $bg) ? 'selected' : ''; ?>><?php echo htmlspecialchars(basename($bg)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="hint">Place images in <code>assets/backgrounds</code>. Supports JPG, PNG, WEBP, GIF.</small>
                            <?php if (!empty($dashboards[$selectedDashboard]['dashboardBackground'])): ?>
                                <div style="margin-top:10px;">
                                    <img src="<?php echo htmlspecialchars($dashboards[$selectedDashboard]['dashboardBackground']); ?>" alt="Background preview" style="max-width: 100%; height: auto; border:1px solid #333; border-radius:4px;" />
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn secondary btn-with-icon">Save Configuration</button>
                            <?php if (!empty($flash) && $activeSection === 'dashboard-settings'): ?>
                                <span id="global-flash-alert" class="alert success" style="margin:0; padding:10px 14px; line-height:1; display:inline-flex; align-items:center; gap:8px;">✅ <?php echo htmlspecialchars($flash['message'] ?? ''); ?></span>
                            <?php endif; ?>
                            <small class="hint hint-right">Changes save automatically and refresh the dashboard</small>
                        </div>
                    </form>
                </section>
                    
                                    <!-- Video Management Section -->
                <section id="video-management" class="admin-section">
                        <div class="section-header">
                            <h3>🎬 Video Management</h3>
                            <p>Manage video titles, thumbnails, and metadata</p>
                        </div>
                        
                        <div class="video-titles">
                            <div class="video-titles-header">
                                <div class="header-content">
                                    <h3>Video Management</h3>
                                    <?php if ($totalVideos > 0): ?>
                                        <p>Showing <?php echo $paginationInfo['start']; ?>-<?php echo $paginationInfo['end']; ?> of <?php echo number_format($totalVideos); ?> videos</p>
                                    <?php else: ?>
                                        <p>No videos found in the selected directory.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if ($totalVideos > $videosPerPage): ?>
                            <div class="pagination-container">
                                <div class="pagination-info">
                                    <span>Page <?php echo $page; ?> of <?php echo $paginationInfo['pages']; ?></span>
                                </div>
                                <div class="pagination-controls">
                                    <?php if ($page > 1): ?>
                                        <a href="?admin-panel=video-management&page=1" class="btn secondary">« First</a>
                                        <a href="?admin-panel=video-management&page=<?php echo $page - 1; ?>" class="btn secondary">‹ Prev</a>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // Show page numbers (5 pages around current)
                                    $startPage = max(1, $page - 2);
                                    $endPage = min($paginationInfo['pages'], $page + 2);
                                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <?php if ($i == $page): ?>
                                            <span class="btn primary current-page"><?php echo $i; ?></span>
                                        <?php else: ?>
                                            <a href="?admin-panel=video-management&page=<?php echo $i; ?>" class="btn secondary"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $paginationInfo['pages']): ?>
                                        <a href="?admin-panel=video-management&page=<?php echo $page + 1; ?>" class="btn secondary">Next ›</a>
                                        <a href="?admin-panel=video-management&page=<?php echo $paginationInfo['pages']; ?>" class="btn secondary">Last »</a>
                                    <?php endif; ?>
                                </div>
                                <div class="pagination-jump">
                                    <form method="get" style="display: inline-flex; gap: 5px; align-items: center;">
                                        <input type="hidden" name="admin-panel" value="video-management">
                                        <label for="page-jump" style="font-size: 14px; color: #ccc;">Go to page:</label>
                                        <input type="number" id="page-jump" name="page" min="1" max="<?php echo $paginationInfo['pages']; ?>" 
                                               value="<?php echo $page; ?>" style="width: 60px; padding: 4px;">
                                        <button type="submit" class="btn secondary" style="padding: 4px 8px;">Go</button>
                                    </form>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div id="video-titles-container">
                                <!-- Video titles will be loaded here -->
                            </div>
                        </div>
                    </section>
                    
                    <!-- System Status Section -->
                    <section id="system-status" class="admin-section">
                        <div class="section-header">
                            <h3>📊 System Status</h3>
                            <p>Monitor system status and manage dashboard refresh</p>
                        </div>
                        
                        <div class="status-cards">
                            <div class="status-card">
                                <h4>📁 Directory Status</h4>
                                <p>Configured directories: <strong><?php echo count($config['directories'] ?? [$config['directory'] ?? 'videos']); ?></strong></p>
                                <p>Total videos found: <strong><?php echo number_format($totalVideos); ?></strong></p>
                                <?php 
                                $thumbDir = __DIR__ . '/data/thumbs';
                                $thumbCount = 0;
                                if (is_dir($thumbDir)) {
                                    $thumbFiles = @scandir($thumbDir) ?: [];
                                    $thumbCount = count(array_filter($thumbFiles, function($f) { return $f !== '.' && $f !== '..' && pathinfo($f, PATHINFO_EXTENSION) === 'jpg'; }));
                                }
                                ?>
                                <p>Generated thumbnails: <strong><?php echo number_format($thumbCount); ?></strong></p>
                                <?php if ($totalVideos > 0): ?>
                                    <p>Thumbnail coverage: <strong><?php echo round(($thumbCount / $totalVideos) * 100); ?>%</strong></p>
                                <?php endif; ?>
                            </div>
                            
                            <div class="status-card">
                                <h4>🎛️ Dashboard Video Controls</h4>
                                <div id="dashboard-video-controls">
                                    <p>Loading dashboards…</p>
                                </div>
                            </div>
                            
                            <div class="status-card">
                                <h4>⚙️ System Actions</h4>
                                <div class="action-buttons">
                                     <form method="post" action="admin.php" onsubmit="return confirm('Trigger a refresh signal for all dashboards?');" style="display:inline;">
                                         <input type="hidden" name="current_section" value="system-refresh" data-fixed>
                                         <button type="submit" class="btn secondary">🔁 Refresh Dashboards</button>
                                     </form>
                                     <button type="button" class="btn primary" id="generate-thumbs-btn">🖼️ Generate Thumbnails</button>
                                       <form method="post" action="admin.php" onsubmit="return confirm('Delete ALL generated thumbnails and custom titles? This cannot be undone.');" style="display:inline;">
                                          <input type="hidden" name="current_section" value="clear-thumbs-titles" data-fixed>
                                          <button type="submit" class="btn secondary">🧹 Clear Thumbnails & Titles</button>
                                      </form>
                                     <form method="post" action="admin.php" onsubmit="return confirm('Reset configuration and dashboards to defaults?');" style="display:inline;">
                                        <input type="hidden" name="current_section" value="system-reset" data-fixed>
                                        <button type="submit" class="btn secondary">🔄 Reset to Default</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Screen Management Section -->
                    <section id="screen-management" class="admin-section">
                        <div class="section-header">
                            <h3>🖥️ Screen Management</h3>
                            <p>Manage dashboards and pair screens to them</p>
                        </div>
                        <div class="admin-form">
                            <h4 style="margin-bottom:10px; color:#4ecdc4;">Add Dashboard</h4>
                            <form method="post" action="admin.php" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                                <input type="hidden" name="current_section" value="screen-management">
                                <input type="hidden" name="sm_action" value="add-dashboard">
                                <div style="flex:1; min-width:220px;">
                                    <label for="new_dashboard_id">Dashboard ID (optional)</label>
                                    <input type="text" id="new_dashboard_id" name="new_dashboard_id" placeholder="e.g., dashboard3">
                                </div>
                                <div style="flex:1; min-width:220px;">
                                    <label for="new_dashboard_name">Dashboard Name (optional)</label>
                                    <input type="text" id="new_dashboard_name" name="new_dashboard_name" placeholder="e.g., Dashboard 3">
                                </div>
                                <button type="submit" class="btn secondary">＋ Add Dashboard</button>
                            </form>
                        </div>

                        <div class="status-cards">
                            <?php foreach ($dashboards as $id => $dash): 
                                $name = $dash['name'] ?? $id;
                                $isDefault = ($id === 'default');
                                $n = null;
                                if (preg_match('/^dashboard(\d+)$/', $id, $m)) { $n = (int)$m[1]; }
                                $dashUrl = $isDefault ? 'dashboard.php?d=0' : ($n ? ('dashboard.php?d=' . $n) : ('dashboard.php?dashboard=' . rawurlencode($id)));
                                $screenUrl = $isDefault ? 'screen.php?d=0' : ($n ? ('screen.php?d=' . $n) : ('screen.php?profile=' . rawurlencode($id)));
                            ?>
                            <div class="status-card">
                                <h4><?php echo htmlspecialchars($name); ?></h4>
                                <p><strong>ID:</strong> <?php echo htmlspecialchars($id); ?></p>
                                <?php if ($n): ?>
                                    <p><strong>Short param:</strong> d=<?php echo (int)$n; ?></p>
                                <?php endif; ?>
                                <div class="action-buttons">
                                    <a href="<?php echo htmlspecialchars($dashUrl); ?>" class="btn secondary open-dashboard-link" data-url="<?php echo htmlspecialchars($dashUrl); ?>">Open Dashboard</a>
                                    <a href="<?php echo htmlspecialchars($screenUrl); ?>" class="btn secondary open-screen-link" data-url="<?php echo htmlspecialchars($screenUrl); ?>">Open Screen</a>
                                    <?php if (!$isDefault): ?>
                                    <form method="post" action="admin.php" onsubmit="return confirm('Delete this dashboard and its linked screens?');" style="display:inline;">
                                        <input type="hidden" name="current_section" value="screen-management">
                                        <input type="hidden" name="sm_action" value="delete-dashboard">
                                        <input type="hidden" name="dashboard_id" value="<?php echo htmlspecialchars($id); ?>">
                                        <button type="submit" class="btn secondary">🗑 Delete</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top:12px; display:grid; gap:8px;">
                                    <div style="display:flex; gap:8px; align-items:center;">
                                        <input type="text" readonly value="<?php echo htmlspecialchars($dashUrl); ?>" style="flex:1; padding:8px; background:#2a2a2a; border:1px solid #444; color:#fff; border-radius:4px;">
                                        <button type="button" class="btn secondary copy-btn" data-copy="<?php echo htmlspecialchars($dashUrl); ?>">Copy</button>
                                    </div>
                                    <div style="display:flex; gap:8px; align-items:center;">
                                        <input type="text" readonly value="<?php echo htmlspecialchars($screenUrl); ?>" style="flex:1; padding:8px; background:#2a2a2a; border:1px solid #444; color:#fff; border-radius:4px;">
                                        <button type="button" class="btn secondary copy-btn" data-copy="<?php echo htmlspecialchars($screenUrl); ?>">Copy</button>
                                    </div>
                                </div>

                                <div style="margin-top:16px;">
                                    <h5 style="margin:0 0 8px 0; color:#4ecdc4;">Screens linked to this dashboard</h5>
                                    <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:6px;">
                                        <?php $linked = array_values(array_filter($screens['screens'], function($s) use($id){return ($s['profile'] ?? '') === $id;})); ?>
                                        <?php if (empty($linked)): ?>
                                            <li style="color:#888;">No screens linked yet.</li>
                                        <?php else: foreach ($linked as $s): ?>
                                            <li style="display:flex; gap:8px; align-items:center;">
                                                <span style="flex:1; color:#ccc;">📺 <?php echo htmlspecialchars($s['name'] ?? $s['id']); ?> <small style="color:#777;">(<?php echo htmlspecialchars($s['id']); ?>)</small></span>
                                                <form method="post" action="admin.php" onsubmit="return confirm('Remove this screen?');">
                                                    <input type="hidden" name="current_section" value="screen-management">
                                                    <input type="hidden" name="sm_action" value="delete-screen">
                                                    <input type="hidden" name="screen_id" value="<?php echo htmlspecialchars($s['id']); ?>">
                                                    <button type="submit" class="btn secondary">Remove</button>
                                                </form>
                                            </li>
                                        <?php endforeach; endif; ?>
                                    </ul>
                                    <form method="post" action="admin.php" style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
                                        <input type="hidden" name="current_section" value="screen-management">
                                        <input type="hidden" name="sm_action" value="add-screen">
                                        <input type="hidden" name="to_dashboard" value="<?php echo htmlspecialchars($id); ?>">
                                        <div style="flex:1; min-width:220px;">
                                            <label>Screen Name (optional)</label>
                                            <input type="text" name="screen_name" placeholder="e.g., TV Left">
                                        </div>
                                        <button type="submit" class="btn secondary">＋ Add Screen</button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            </div>
            
            <!-- Bottom Messages removed; flash now inline near submit buttons -->
        </main>
    
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        // Suppress non-critical console output
        (function(){
            try { ['log','debug','info','table'].forEach(k => { if (typeof console[k] === 'function') console[k] = function(){}; }); } catch(_) {}
        })();
        
        // Navigation functionality
        const navLinks = document.querySelectorAll('.nav-link');
        const adminSections = document.querySelectorAll('.admin-section');
        
        function showSection(sectionId) {
            console.log('Showing section:', sectionId);
            
            // Hide all sections
            adminSections.forEach(section => {
                section.classList.remove('active');
            });
            
            // Remove active class from all nav links
            navLinks.forEach(link => {
                link.classList.remove('active');
            });
            
            // Show selected section
            const targetSection = document.getElementById(sectionId);
            if (targetSection) {
                targetSection.classList.add('active');
                console.log('Section activated:', sectionId);
            } else {
                console.error('Section not found:', sectionId);
            }
            
            // Add active class to clicked nav link
            const activeLink = document.querySelector(`[data-section="${sectionId}"]`);
            if (activeLink) {
                activeLink.classList.add('active');
                console.log('Nav link activated:', sectionId);
            } else {
                console.error('Nav link not found:', sectionId);
            }
            
            // Update the current section hidden field
            const currentSectionInput = document.getElementById('current_section');
            if (currentSectionInput) {
                currentSectionInput.value = sectionId;
                console.log('Updated current section to:', sectionId);
            }
            
            // Also update any other current_section hidden fields in other forms
            document.querySelectorAll('input[name="current_section"]').forEach(input => {
                if (!input.hasAttribute('data-fixed')) {
                    input.value = sectionId;
                }
            });

        }
    
        // Dismiss inline flash helper
        function dismissInlineFlash() {
            const inlineFlash = document.getElementById('global-flash-alert');
            if (inlineFlash) inlineFlash.remove();
        }
        
        // Add click event listeners to nav links
        navLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const sectionId = link.getAttribute('data-section');
                showSection(sectionId);
                
                // Update URL hash without scrolling
                history.pushState(null, null, `#${sectionId}`);
                
                // Dismiss inline flash when navigating to a different section
                dismissInlineFlash();
            });
        });

        // Intercept header nav clicks for Dashboard and Screen to open in a new window
        const headerWindowLinks = document.querySelectorAll('header.site-header a[href="dashboard.php"], header.site-header a[href="screen.php"]');
        headerWindowLinks.forEach(anchor => {
            anchor.addEventListener('click', (event) => {
                event.preventDefault();
                const url = anchor.getAttribute('href');
                const windowName = url.includes('dashboard.php') ? 'DashboardWindow' : 'ScreenWindow';
                const width = Math.min(window.screen.availWidth || 1280, 1280);
                const height = Math.min(window.screen.availHeight || 900, 900);
                const left = Math.max(0, Math.floor(((window.screen.availWidth || width) - width) / 2));
                const top = Math.max(0, Math.floor(((window.screen.availHeight || height) - height) / 2));
                const features = `popup=yes,width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes,toolbar=no,menubar=no,location=no,status=no`;
                const win = window.open(url, windowName, features);
                if (win && typeof win.focus === 'function') {
                    win.focus();
                }
            });
        });
        
        // Handle browser back/forward buttons
        window.addEventListener('popstate', () => {
            const hash = window.location.hash.slice(1);
            if (hash) {
                showSection(hash);
            } else {
                showSection('directory-config');
            }
            // Dismiss inline flash on browser navigation
            dismissInlineFlash();
        });
        
        // Initialize with hash, URL parameter, or default to directory configuration
        const urlParams = new URLSearchParams(window.location.search);
        const sectionParam = urlParams.get('admin-panel') || urlParams.get('section');
        const initialSection = window.location.hash.slice(1) || sectionParam || 'directory-config';
        showSection(initialSection);
        // Dashboard settings: only selection and redirect to chosen profile
        const dashboardIdSelect = document.getElementById('dashboard_id');

        if (dashboardIdSelect) {
            dashboardIdSelect.addEventListener('change', () => {
                const id = dashboardIdSelect.value || 'default';
                const search = new URLSearchParams(window.location.search);
                search.set('admin-panel', 'dashboard-settings');
                search.set('dashboard', id);
                window.location.search = search.toString();
            });
        }
        
        // Removed manual refresh dashboard UI; saves trigger refresh automatically
        
        // Add form submission debugging and ensure directories are saved (directory-config form only)
        const directoryForm = document.querySelector('#directory-config form.admin-form');
        if (directoryForm) {
            directoryForm.addEventListener('submit', (e) => {
                console.log('Form submitting with directory:', directoryInput.value);
                console.log('Selected directories before submit:', selectedDirectories);
                
                // If no directories are selected, add the default videos directory
                if (selectedDirectories.length === 0) {
                    console.log('No directories selected, adding default videos directory');
                    selectedDirectories = ['videos'];
                    updateDirsState();
                }
                
                // Ensure directories are saved to hidden field
                updateDirsState();
                console.log('Directories JSON after update:', dirsJsonInput.value);
                
                // Show importing modal before form submission
                showImportingModal('Processing directory changes and updating system...');
                
                console.log('Form data:', new FormData(directoryForm));
            });
        }
        
        // Directory browser functionality
        const browseDirBtn = document.getElementById('browse-directory-btn');
        const addDirectoryBtn = document.getElementById('add-directory-btn');
        const directoryBrowser = document.getElementById('directory-browser');
        const browsePath = document.getElementById('browse-path');
        const browseGoBtn = document.getElementById('browse-go-btn');
        const browseHomeBtn = document.getElementById('browse-home-btn');
        const directoryList = document.getElementById('directory-list');
        const selectDirectoryBtn = document.getElementById('select-directory-btn');
        const cancelBrowseBtn = document.getElementById('cancel-browse-btn');
        const directoryInput = document.getElementById('directory');
        const chipsEl = document.getElementById('directories-chips');
        const dirsJsonInput = document.getElementById('directories_json');
        let selectedDirectories = Array.isArray(<?php echo json_encode($config['directories'] ?? [$config['directory'] ?? 'videos']); ?>) ? <?php echo json_encode($config['directories'] ?? [$config['directory'] ?? 'videos']); ?> : [];

        function renderChips() {
            console.log('Rendering chips for directories:', selectedDirectories);
            chipsEl.innerHTML = '';
            
            if (selectedDirectories.length === 0) {
                chipsEl.innerHTML = '<div style="color: #666; font-style: italic; text-align: center; padding: 10px;">No directories added yet</div>';
                return;
            }
            
            selectedDirectories.forEach((dir, index) => {
                const chip = document.createElement('div');
                chip.className = 'chip';
                chip.draggable = true;
                chip.innerHTML = '<span>' + escapeHtml(dir) + '</span><span class="remove" title="Remove">✕</span>';
                
                // Add remove functionality
                const removeBtn = chip.querySelector('.remove');
                removeBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    console.log('Removing directory:', dir);
                    selectedDirectories.splice(index, 1);
                    updateDirsState();
                    renderChips();
                });
                
                // drag reorder
                chip.addEventListener('dragstart', (e) => {
                    e.dataTransfer.setData('text/plain', String(index));
                });
                chip.addEventListener('dragover', (e) => { e.preventDefault(); });
                chip.addEventListener('drop', (e) => {
                    e.preventDefault();
                    const from = parseInt(e.dataTransfer.getData('text/plain'), 10);
                    const to = index;
                    if (!isNaN(from) && from !== to) {
                        const [moved] = selectedDirectories.splice(from, 1);
                        selectedDirectories.splice(to, 0, moved);
                        updateDirsState();
                        renderChips();
                    }
                });
                chipsEl.appendChild(chip);
            });
            updateDirsState();
        }

        function updateDirsState() {
            dirsJsonInput.value = JSON.stringify(selectedDirectories);
            renderChips.defer && clearTimeout(renderChips.defer);
        }

        function showInlineMessage(text, variant = 'success') {
            const msg = document.createElement('div');
            msg.className = `alert ${variant}`;
            msg.style.marginTop = '10px';
            msg.textContent = text;
            directoryInput.parentNode.appendChild(msg);
            setTimeout(() => msg.remove(), 2500);
        }
        
        function showImportingModal(message = 'Scanning directories and importing video files...') {
            const modal = document.getElementById('importing-modal');
            if (modal) {
                // Update the message if provided
                const messageElement = modal.querySelector('.importing-animation p');
                if (messageElement) {
                    messageElement.textContent = message;
                }
                
                modal.style.display = 'flex';
                // Auto-hide after a reasonable time (in case something goes wrong)
                setTimeout(() => {
                    if (modal.style.display === 'flex') {
                        modal.style.display = 'none';
                    }
                }, 30000); // 30 seconds max
            }
        }
        
        function hideImportingModal() {
            const modal = document.getElementById('importing-modal');
            if (modal) {
                modal.style.display = 'none';
            }
        }
        
        function showImportingProgress(percent, message = null) {
            const modal = document.getElementById('importing-modal');
            if (modal) {
                const progressBar = modal.querySelector('.progress-bar');
                const progressFill = modal.querySelector('.progress-fill');
                const messageElement = modal.querySelector('.importing-animation p');
                
                if (progressBar && progressFill) {
                    progressBar.style.display = 'block';
                    progressFill.style.width = percent + '%';
                }
                
                if (message && messageElement) {
                    messageElement.textContent = message;
                }
            }
        }

        // Warm up thumbnails by calling the API in batches and updating the modal progress
        function startThumbnailWarmup(doneCb) {
            const modal = document.getElementById('importing-modal');
            const progressBar = modal ? modal.querySelector('.progress-bar') : null;
            if (progressBar) progressBar.style.display = 'block';
            let offset = 0; const batch = 15; let total = 0; let processedCumulative = 0;
            function step() {
                fetch(`api.php?action=warm_thumbnails&offset=${offset}&batch=${batch}`)
                    .then(r => r.json())
                    .then(data => {
                        total = data.total || total;
                        processedCumulative += data.processed || 0;
                        offset = data.nextOffset || offset;
                        if (total > 0) {
                            const pct = Math.min(100, Math.round((processedCumulative / total) * 100));
                            showImportingProgress(pct, `Generating thumbnails (${processedCumulative}/${total})...`);
                        }
                        if ((data.remaining || 0) > 0) {
                            setTimeout(step, 50);
                        } else {
                            showImportingProgress(100, 'Thumbnails ready');
                            if (typeof doneCb === 'function') setTimeout(doneCb, 500);
                            else setTimeout(hideImportingModal, 500);
                        }
                    })
                    .catch(() => {
                        // On error, hide modal so UI isn't blocked
                        if (typeof doneCb === 'function') doneCb(); else hideImportingModal();
                    });
            }
            step();
        }

        function openBrowsePanel() {
            directoryBrowser.style.display = 'block';
            browsePath.value = '';
            loadDirectoryList('');
            browsePath.focus();
        }

        addDirectoryBtn.addEventListener('click', () => {
            const val = directoryInput.value.trim();
            if (!val) {
                openBrowsePanel();
                showInlineMessage('Browse opened. Pick a folder to add.', 'success');
                return;
            }
            if (selectedDirectories.includes(val)) {
                showInlineMessage('That folder is already added.', 'error');
                return;
            }
            
            // Show importing modal for new directory
            showImportingModal();
            
            selectedDirectories.push(val);
            renderChips();
            directoryInput.value = '';
            showInlineMessage('✅ Added: ' + val, 'success');
            // Pulse the newest chip
            const lastChip = chipsEl.lastElementChild;
            if (lastChip) {
                lastChip.classList.add('pulse');
                setTimeout(() => lastChip.classList.remove('pulse'), 1200);
            }
            
            // Begin warming thumbnails for added directory
            startThumbnailWarmup();
        });

        // Press Enter in the input to add quickly
        directoryInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                addDirectoryBtn.click();
            }
        });

        // Initialize chips from config
        console.log('Initializing chips with directories:', selectedDirectories);
        renderChips();
        
        // Show importing modal on page load if fresh scan is needed
        <?php if ($needsAdminScan): ?>
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                showImportingModal('Initializing and scanning directories...');
                // Kick off thumbnail warmup (uses cached list)
                startThumbnailWarmup(() => {
                    hideImportingModal();
                });
            }, 500);
        });
        <?php endif; ?>
        
        // Debug layout
        console.log('Admin layout elements:', {
            sidebar: document.querySelector('.admin-sidebar'),
            content: document.querySelector('.admin-content'),
            layout: document.querySelector('.admin-layout')
        });
        
        // Force layout check
        const layout = document.querySelector('.admin-layout');
        const sidebar = document.querySelector('.admin-sidebar');
        const content = document.querySelector('.admin-content');
        
        if (layout) {
            console.log('Layout found, applying styles...');
            layout.style.display = 'flex';
            layout.style.width = '100%';
            layout.style.maxWidth = 'none';
            layout.style.margin = '0';
            layout.style.padding = '0';
            layout.style.position = 'relative';
            console.log('Layout styles applied');
        }
        
        if (sidebar) {
            console.log('Sidebar found, width:', sidebar.offsetWidth);
            sidebar.style.width = '280px';
            sidebar.style.flexShrink = '0';
            sidebar.style.minWidth = '280px';
            sidebar.style.maxWidth = '280px';
            sidebar.style.position = 'relative';
            sidebar.style.zIndex = '10';
            console.log('Sidebar styles applied, new width:', sidebar.offsetWidth);
        }
        
        if (content) {
            console.log('Content found, flex:', content.style.flex);
            content.style.flex = '1';
            content.style.width = 'calc(100% - 280px)';
            content.style.maxWidth = 'calc(100% - 280px)';
            content.style.minWidth = '0';
            console.log('Content styles applied');
        }
        
        // Force a reflow
        setTimeout(() => {
            if (layout) {
                layout.style.display = 'none';
                layout.offsetHeight; // Force reflow
                layout.style.display = 'flex';
                console.log('Layout reflow forced');
            }
        }, 100);
        
        console.log('Elements found:', {
            browseDirBtn: !!browseDirBtn,
            directoryBrowser: !!directoryBrowser,
            browsePath: !!browsePath,
            browseGoBtn: !!browseGoBtn,
            browseHomeBtn: !!browseHomeBtn,
            directoryList: !!directoryList,
            selectDirectoryBtn: !!selectDirectoryBtn,
            cancelBrowseBtn: !!cancelBrowseBtn,
            directoryInput: !!directoryInput
        });
        
        // HTML escape helpers
        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
        function escapeAttr(str) { return escapeHtml(str); }

        // Define loadVideoTitles function before using it
        function loadVideoTitles() {
            const container = document.getElementById('video-titles-container');
            console.log('Loading video titles, container:', container);
            
            if (!container) {
                console.error('Video titles container not found!');
                return;
            }
            
            // Show loading indicator
            container.innerHTML = '<div style="text-align: center; padding: 20px; color: #4ecdc4;">📁 Loading videos...</div>';
            
            // Get current page from URL
            const urlParams = new URLSearchParams(window.location.search);
            const currentPage = urlParams.get('page') || 1;
            
            // Get paginated videos and their titles
            console.log('Fetching videos for page:', currentPage);
            Promise.all([
                fetch(`api.php?action=get_all_videos&page=${currentPage}&limit=20`).then(res => res.json()),
                fetch('api.php?action=get_video_titles').then(res => res.json())
            ]).then(([videosData, titlesData]) => {
                console.log('Videos data:', videosData);
                console.log('Titles data:', titlesData);
                const videos = videosData.videos || [];
                const titles = titlesData.titles || {};
                
                if (videos.length === 0) {
                    container.innerHTML = '<p>No videos found.</p>';
                    return;
                }
                
                let html = '<div class="video-titles-list">';
                videos.forEach(video => {
                    // Handle both string and object formats for backward compatibility
                    const videoName = typeof video === 'string' ? video : video.name;
                    const videoDirIndex = typeof video === 'string' ? 0 : video.dirIndex;
                    const titleKey = videoDirIndex + '|' + videoName;
                    const currentTitle = titles[titleKey] || videoName.replace(/\.[^/.]+$/, '');
                    const directory = <?php echo json_encode($config['directory']); ?>;
                    const separator = directory.includes(':\\') ? '\\' : '/';
                    const videoPath = directory + separator + videoName;
                    const safeVideoPath = videoPath.split('\\').join('\\\\');
                    const videoId = videoName.replace(/[^a-zA-Z0-9]/g, '_');
                    html += '<div class="video-title-item">';
                    // Reorder controls
                    html += '<div class="video-reorder" style="display:flex; flex-direction:column; gap:6px; margin-right:10px; align-items:center;">';
                    html += '<button type="button" class="btn secondary move-btn" data-filename="' + escapeAttr(videoName) + '" data-dir-index="' + videoDirIndex + '" data-direction="up" title="Move up">▲</button>';
                    html += '<button type="button" class="btn secondary move-btn" data-filename="' + escapeAttr(videoName) + '" data-dir-index="' + videoDirIndex + '" data-direction="down" title="Move down">▼</button>';
                    html += '</div>';
                    html += '<div class="video-thumbnail">';
                    html += '<img src="thumb.php?file=' + encodeURIComponent(videoName) + '&dirIndex=' + videoDirIndex + '" alt="thumbnail" loading="lazy" />';
                    html += '</div>';
                    html += '<div class="video-info">';
                    html += '<label for="title-' + videoId + '">' + escapeHtml(videoName) + '</label>';
                    html += '<input type="text" id="title-' + videoId + '" value="' + escapeAttr(currentTitle) + '" placeholder="Enter custom title" maxlength="26">';
                    html += '<button type="button" class="btn secondary save-title-btn" data-filename="' + escapeAttr(videoName) + '" data-dir-index="' + videoDirIndex + '">Save</button>';
                    html += '</div>';
                    html += '</div>';
                });
                html += '</div>';
                container.innerHTML = html;
                
                // Always show all video items
                document.querySelectorAll('.video-title-item').forEach(item => {
                    item.style.display = 'flex';
                });

                // Images load natively; optionally handle error state
                const thumbImgs = container.querySelectorAll('.video-thumbnail img');
                thumbImgs.forEach(img => {
                    img.addEventListener('error', () => { img.alt = 'thumbnail unavailable'; });
                });
                
                // Add event listeners for save buttons
                document.querySelectorAll('.save-title-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const filename = this.getAttribute('data-filename');
                        const dirIndex = this.getAttribute('data-dir-index') || '0';
                        const titleId = filename.replace(/[^a-zA-Z0-9]/g, '_');
                        const input = document.getElementById('title-' + titleId);
                        const title = input.value.trim();
                        
                        if (title) {
                            const formData = new FormData();
                            formData.append('filename', filename);
                            formData.append('title', title);
                            formData.append('dirIndex', dirIndex);
                            
                            fetch('api.php?action=set_video_title', {
                                method: 'POST',
                                body: formData
                            }).then(res => res.json()).then(data => {
                                if (data.status === 'ok') {
                                    console.log('Title saved for', filename);
                                    // Show success feedback
                                    this.textContent = 'Saved!';
                                    this.className = 'btn primary save-title-btn';
                                    
                                    // Automatically refresh the dashboard
                                    console.log('Triggering dashboard refresh after title save');
                                    fetch('api.php?action=trigger_dashboard_refresh', { method: 'POST' })
                                        .then(res => res.json())
                                        .then(refreshData => {
                                            console.log('Dashboard refresh triggered:', refreshData);
                                        })
                                        .catch(err => console.error('Failed to trigger dashboard refresh:', err));
                                    
                                    setTimeout(() => {
                                        this.textContent = 'Save';
                                        this.className = 'btn secondary save-title-btn';
                                    }, 2000);
                                }
                            }).catch(err => console.error('Failed to save title:', err));
                        }
                    });
                });

                // Add event listeners for move buttons
                document.querySelectorAll('.move-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const filename = this.getAttribute('data-filename');
                        const dirIndex = this.getAttribute('data-dir-index') || '0';
                        const direction = this.getAttribute('data-direction');

                        const formData = new FormData();
                        formData.append('filename', filename);
                        formData.append('dirIndex', dirIndex);
                        formData.append('direction', direction);

                        // Visual feedback
                        this.textContent = direction === 'up' ? '⏫' : '⏬';
                        this.disabled = true;

                        fetch('api.php?action=move_video', { method: 'POST', body: formData })
                            .then(async res => { const t = await res.text(); try { return JSON.parse(t); } catch(e){ console.error('Move video raw:', t); throw e; } })
                            .then(data => {
                                if (data.status === 'ok') {
                                    // Reload current page list
                                    loadVideoTitles();
                                    // Trigger dashboard refresh so order updates there too
                                    fetch('api.php?action=trigger_dashboard_refresh', { method: 'POST' }).catch(() => {});
                                } else {
                                    console.error('Move failed:', data);
                                }
                            })
                            .catch(err => console.error('Failed to move video:', err))
                            .finally(() => {
                                setTimeout(() => { this.disabled = false; this.textContent = direction === 'up' ? '▲' : '▼'; }, 500);
                            });
                    });
                });
            }).catch(err => console.error('Failed to load video titles:', err));
        }
        
        // Load and display video titles
        loadVideoTitles();
        

        
        let currentBrowsePath = '';
        let selectedDirectory = '';
        
        // Remove test code - no longer needed
        
        browseDirBtn.addEventListener('click', () => {
            console.log('Browse button clicked');
            directoryBrowser.style.display = 'block';
            browsePath.value = '';
            loadDirectoryList('');
        });
        
        cancelBrowseBtn.addEventListener('click', () => {
            directoryBrowser.style.display = 'none';
            selectedDirectory = '';
            selectDirectoryBtn.disabled = true;
        });
        
        browseGoBtn.addEventListener('click', () => {
            const path = browsePath.value.trim();
            console.log('Browse Go clicked, path:', path);
            if (path) {
                loadDirectoryList(path);
            } else {
                console.log('No path entered');
            }
        });
        
        browseHomeBtn.addEventListener('click', () => {
            loadDirectoryList('');
        });
        
        browsePath.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                browseGoBtn.click();
            }
        });
        
        // Ensure the event listener is properly attached
        if (selectDirectoryBtn) {
            console.log('Attaching event listener to select directory button');
            selectDirectoryBtn.addEventListener('click', () => {
                console.log('Select directory button clicked!');
                console.log('Select directory clicked, selectedDirectory:', selectedDirectory);
                console.log('Directory input element:', directoryInput);
                console.log('Button disabled state:', selectDirectoryBtn.disabled);
                if (selectedDirectory) {
                    console.log('Adding directory to list:', selectedDirectory);
                    const selectedPath = selectedDirectory; // Store the path before clearing
                    if (!selectedDirectories.includes(selectedPath)) {
                        // Show importing modal for new directory
                        showImportingModal();
                        
                        selectedDirectories.push(selectedPath);
                        renderChips();
                        const lastChip = chipsEl.lastElementChild;
                        if (lastChip) {
                            lastChip.classList.add('pulse');
                            setTimeout(() => lastChip.classList.remove('pulse'), 1200);
                        }
                        
                        // Hide modal after a short delay
                        setTimeout(() => {
                            hideImportingModal();
                        }, 2000);
                    }
                    directoryInput.value = selectedDirectory;
                    directoryBrowser.style.display = 'none';
                    selectedDirectory = '';
                    selectDirectoryBtn.disabled = true;
                    console.log('Directory input value is now:', directoryInput.value);
                    
                    // Also trigger a change event to ensure the form knows the value changed
                    directoryInput.dispatchEvent(new Event('input', { bubbles: true }));
                    directoryInput.dispatchEvent(new Event('change', { bubbles: true }));
                    
                    // Visual feedback
                    directoryInput.style.backgroundColor = '#4ecdc4';
                    directoryInput.style.color = '#000';
                    setTimeout(() => {
                        directoryInput.style.backgroundColor = '';
                        directoryInput.style.color = '';
                    }, 2000);
                    
                    // Show success message
                    showInlineMessage('✅ Directory added: ' + selectedPath, 'success');
                } else {
                    console.log('No directory selected');
                }
            });
        }
        
        function loadDirectoryList(path) {
            console.log('Loading directory list for path:', path);
            currentBrowsePath = path;
            browsePath.value = path;
            
            const formData = new FormData();
            formData.append('path', path);
            
            console.log('Sending request to api.php?action=browse_directories');
            fetch('api.php?action=browse_directories', {
                method: 'POST',
                body: formData
            })
            .then(res => {
                console.log('Response status:', res.status);
                return res.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.status === 'ok') {
                    displayDirectoryList(data.directories, data.currentPath, data.videoFiles || []);
                } else {
                    directoryList.innerHTML = '<p style="color: #ff6b6b;">Error: ' + data.error + '</p>';
                }
            })
            .catch(err => {
                console.error('Failed to load directories:', err);
                directoryList.innerHTML = '<p style="color: #ff6b6b;">Failed to load directories</p>';
            });
        }
        
        function getParentPath(path) {
            if (path.includes(':\\')) {
                // Windows path
                const parts = path.split('\\').filter(part => part !== '');
                parts.pop();
                return parts.length > 0 ? parts.join('\\') + '\\' : path.split('\\')[0] + '\\';
            } else {
                // Unix path
                const parts = path.split('/').filter(part => part !== '');
                parts.pop();
                return parts.length > 0 ? '/' + parts.join('/') : '/';
            }
        }
        
        function displayDirectoryList(directories, currentPath, videoFiles = []) {
            let html = '';
            
            if (currentPath && currentPath !== '/') {
                const parentPath = getParentPath(currentPath);
                const escapedParentPath = parentPath.split('\\').join('\\\\');
                html += '<div class="directory-item" data-path="' + escapedParentPath + '">';
                html += '<span style="color: #4ecdc4;">📁 ..</span> (Parent directory)';
                html += '</div>';
            }
            
            // Always show option to select current directory
            if (currentPath && currentPath !== '') {
                html += '<div class="select-current-directory" style="color: #4ecdc4; padding: 15px; text-align: center; border: 2px dashed #4ecdc4; border-radius: 8px; cursor: pointer; background-color: #0a3a3a; margin-bottom: 15px;">';
                html += '<p style="margin: 0 0 10px 0; font-weight: bold;">🎯 Select Current Directory</p>';
                html += '<p style="font-size: 14px; margin: 0; color: #fff;">👆 Click here to use this directory as your video folder</p>';
                html += '<p style="font-size: 12px; margin: 5px 0 0 0; color: #888; word-break: break-all;">' + currentPath + '</p>';
                html += '</div>';
            }
            
            // Show video files info if any (always show, regardless of subdirectories)
            if (videoFiles && videoFiles.length > 0) {
                html += '<div style="margin-bottom: 15px; padding: 10px; background-color: #1a1a1a; border-radius: 4px;">';
                html += '<p style="color: #4ecdc4; margin-bottom: 10px;">🎬 Video files in this directory (' + videoFiles.length + '):</p>';
                html += '<div style="max-height: 150px; overflow-y: auto;">';
                videoFiles.slice(0, 10).forEach(file => {
                    html += '<div style="color: #ccc; font-size: 12px; padding: 2px 0;">📹 ' + file + '</div>';
                });
                if (videoFiles.length > 10) {
                    html += '<div style="color: #888; font-size: 11px; padding: 2px 0;">... and ' + (videoFiles.length - 10) + ' more</div>';
                }
                html += '</div></div>';
            }
            
            if (directories.length === 0) {
                html += '<div style="color: #888; padding: 10px; text-align: center; font-style: italic;">';
                html += '<p style="margin: 0;">📁 No subdirectories found</p>';
                html += '</div>';
            } else {
                directories.forEach(dir => {
                    let fullPath;
                    if (currentPath) {
                        // Handle Windows paths properly
                        if (currentPath.includes(':\\')) {
                            // Windows path
                            fullPath = currentPath.endsWith('\\') ? currentPath + dir : currentPath + '\\' + dir;
                        } else {
                            // Unix path
                            fullPath = currentPath.endsWith('/') ? currentPath + dir : currentPath + '/' + dir;
                        }
                    } else {
                        fullPath = dir;
                    }
                    
                    const escapedPath = fullPath.split('\\').join('\\\\');
                    html += '<div class="directory-item" data-path="' + escapedPath + '">';
                    html += '<span style="color: #4ecdc4;">📁 ' + dir + '</span>';
                    html += '</div>';
                });
            }
            
            directoryList.innerHTML = html;
            
            // Add click handlers
            directoryList.querySelectorAll('.directory-item').forEach(item => {
                item.addEventListener('click', () => {
                    const rawPath = item.getAttribute('data-path');
                    const path = rawPath.split('\\\\').join('\\');
                    if (path === '..') {
                        const parentPath = getParentPath(currentPath);
                        loadDirectoryList(parentPath);
                    } else {
                        loadDirectoryList(path);
                    }
                });
                
                item.addEventListener('dblclick', () => {
                    const rawPath = item.getAttribute('data-path');
                    const path = rawPath.split('\\\\').join('\\');
                    console.log('Double-clicked directory:', path);
                    if (path !== '..') {
                        selectedDirectory = path;
                        selectDirectoryBtn.disabled = false;
                        const displayPath = path.split('\\').join('\\\\');
                        selectDirectoryBtn.textContent = 'Select: ' + displayPath;
                        console.log('Selected directory set to:', selectedDirectory);
                        console.log('Button enabled:', selectDirectoryBtn.disabled);
                        
                        // Highlight selected item
                        directoryList.querySelectorAll('.directory-item').forEach(el => {
                            el.style.backgroundColor = '';
                        });
                        item.style.backgroundColor = '#007acc';
                    }
                });
            });
            
            // Add click handler for current directory selection (always available when there's a current path)
            const selectCurrentDir = directoryList.querySelector('.select-current-directory');
            if (selectCurrentDir) {
                selectCurrentDir.addEventListener('click', () => {
                    console.log('Clicked current directory selector, selecting path:', currentPath);
                    selectedDirectory = currentPath;
                    selectDirectoryBtn.disabled = false;
                    const displayCurrentPath = currentPath.split('\\').join('\\\\');
                    selectDirectoryBtn.textContent = 'Select: ' + displayCurrentPath;
                    console.log('Selected directory set to:', selectedDirectory);
                    console.log('Button enabled:', selectDirectoryBtn.disabled);
                    
                    // Highlight the selection
                    selectCurrentDir.style.backgroundColor = '#007acc';
                    selectCurrentDir.style.borderColor = '#007acc';
                    selectCurrentDir.style.color = '#fff';
                    
                    // Remove highlight from other directory items
                    directoryList.querySelectorAll('.directory-item').forEach(el => {
                        el.style.backgroundColor = '';
                    });
                });
            }
        }
        
        // Video management functionality - always show video info
        // Thumbnails can be toggled if needed in the future
        
        // Removed system refresh dashboard button logic
        
        // System status functionality
        const currentVideoStatus = document.getElementById('current-video-status');
        
        // Removed global Clear Video button; per-dashboard controls now handle this
        
        // Update current video status
        function updateCurrentVideoStatus() {
            if (!currentVideoStatus) { return; }
            fetch('api.php?action=get_current_video&profile=default')
                .then(res => res.json())
                .then(data => {
                    const currentVideo = data.currentVideo;
                    if (currentVideo && currentVideo.filename) {
                        currentVideoStatus.innerHTML = `
                            <p><span class="current-video-name">${escapeHtml(currentVideo.filename)}</span></p>
                            <p class="current-video-status">🎬 Currently playing</p>
                        `;
                    } else {
                        currentVideoStatus.innerHTML = `
                            <p>No video selected</p>
                            <p class="current-video-status">Select a video from the dashboard</p>
                        `;
                    }
                })
                .catch(err => {
                    console.error('Failed to get current video status:', err);
                    currentVideoStatus.innerHTML = `
                        <p>Status unavailable</p>
                        <p class="current-video-status">Check console for details</p>
                    `;
                });
        }
        
        // Load and display video titles
        loadVideoTitles();
        
        // Update current video status on page load
        updateCurrentVideoStatus();
        
        // Update status every 30 seconds (only if card exists)
        if (currentVideoStatus) {
            setInterval(updateCurrentVideoStatus, 30000);
        }

        // Dashboard video controls: per-profile Clear Video
        function renderDashboardVideoControls() {
            const container = document.getElementById('dashboard-video-controls');
            if (!container) return;
            const profiles = <?php echo json_encode($dashboards); ?>;
            let html = '';
            html += '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px;">';
            Object.keys(profiles).forEach((id) => {
                const d = profiles[id] || {};
                const name = id === 'default' ? 'Default' : (d.name || id);
                let query = 'profile=' + encodeURIComponent(id);
                const m = id.match(/^dashboard(\d+)$/);
                if (id === 'default') {
                    query = 'd=0';
                } else if (m) {
                    query = 'd=' + m[1];
                }
                html += '<div style="border:1px solid #333; background:#1f1f1f; border-radius:8px; padding:12px;">';
                html += '<div style="color:#4ecdc4; font-weight:600; margin-bottom:6px;">' + escapeHtml(name) + '</div>';
                html += '<div style="display:flex; gap:8px; flex-wrap:wrap;">'
                      + '<button class="btn secondary dc-clear" data-q="' + query + '">Clear Video</button>'
                      + '<button class="btn secondary dc-stop" data-q="' + query + '">Stop</button>'
                      + '<button class="btn secondary dc-pause" data-q="' + query + '">Pause</button>'
                      + '<button class="btn secondary dc-play" data-q="' + query + '">Play</button>'
                      + '</div>';
                html += '<div class="now-playing" data-profile="' + escapeHtml(id) + '" data-q="' + query + '" style="margin-top:8px; color:#ccc; font-size:0.95rem;">Now playing: <span class="np-title">None</span></div>';
                html += '</div>';
            });
            html += '</div>';
            container.innerHTML = html;

            function bind(btnClass, action, method = 'POST') {
                container.querySelectorAll('.' + btnClass).forEach(btn => {
                    btn.addEventListener('click', () => {
                        const q = btn.getAttribute('data-q') || '';
                        fetch('api.php?action=' + action + '&' + q, { method })
                            .then(res => res.json())
                            .then(() => {
                                btn.textContent = 'Done';
                                setTimeout(() => { btn.textContent = btnClass.split('-')[1].replace(/^./, c=>c.toUpperCase()); }, 800);
                            })
                            .catch(() => {});
                    });
                });
            }
            bind('dc-clear', 'clear_current_video');
            bind('dc-stop', 'stop_video');
            bind('dc-pause', 'pause_video');
            bind('dc-play', 'play_video');

            // Populate Now Playing titles per dashboard
            function updateNowPlaying() {
                const npEls = Array.from(container.querySelectorAll('.now-playing'));
                if (npEls.length === 0) return;
                // Fetch titles once
                fetch('api.php?action=get_video_titles&profile=default')
                    .then(res => res.json())
                    .then(titlesData => {
                        const titles = titlesData && titlesData.titles ? titlesData.titles : {};
                        npEls.forEach(el => {
                            const q = el.getAttribute('data-q') || '';
                            fetch('api.php?action=get_current_video&' + q)
                                .then(r => r.json())
                                .then(data => {
                                    const holder = el.querySelector('.np-title');
                                    if (!holder) return;
                                    const cv = data && data.currentVideo;
                                    if (!cv) { holder.textContent = 'None'; return; }
                                    let filename = '';
                                    let dirIndex = 0;
                                    if (typeof cv === 'object') {
                                        filename = cv.filename || '';
                                        dirIndex = (cv.dirIndex != null) ? cv.dirIndex : 0;
                                    } else {
                                        filename = String(cv || '');
                                    }
                                    if (!filename) { holder.textContent = 'None'; return; }
                                    const key = String(dirIndex) + '|' + filename;
                                    const custom = titles[key];
                                    const display = custom || filename.replace(/\.[^/.]+$/, '');
                                    holder.textContent = display;
                                })
                                .catch(() => {
                                    const holder = el.querySelector('.np-title');
                                    if (holder) holder.textContent = 'None';
                                });
                        });
                    })
                    .catch(() => {
                        npEls.forEach(el => { const h = el.querySelector('.np-title'); if (h) h.textContent = 'None'; });
                    });
            }
            updateNowPlaying();
            setInterval(updateNowPlaying, 8000);
        }
        renderDashboardVideoControls();

        // Screen Management buttons: open links in a popup and copy URLs
        document.querySelectorAll('.open-dashboard-link, .open-screen-link').forEach(anchor => {
            anchor.addEventListener('click', (event) => {
                event.preventDefault();
                const url = anchor.getAttribute('data-url') || anchor.getAttribute('href');
                const windowName = anchor.classList.contains('open-dashboard-link') ? 'DashboardWindow' : 'ScreenWindow';
                const width = Math.min(window.screen.availWidth || 1280, 1280);
                const height = Math.min(window.screen.availHeight || 900, 900);
                const left = Math.max(0, Math.floor(((window.screen.availWidth || width) - width) / 2));
                const top = Math.max(0, Math.floor(((window.screen.availHeight || height) - height) / 2));
                const features = `popup=yes,width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes,toolbar=no,menubar=no,location=no,status=no`;
                const win = window.open(url, windowName, features);
                if (win && typeof win.focus === 'function') {
                    win.focus();
                }
            });
        });

        document.querySelectorAll('.copy-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(btn.getAttribute('data-copy') || '');
                    btn.textContent = 'Copied!';
                    setTimeout(() => btn.textContent = 'Copy', 1200);
                } catch (e) {
                    const parent = btn.parentElement;
                    const input = parent && parent.querySelector('input[type="text"]');
                    if (input) {
                        input.select();
                        document.execCommand('copy');
                        btn.textContent = 'Copied!';
                        setTimeout(() => btn.textContent = 'Copy', 1200);
                    }
                }
            });
        });

        // Thumbnail generation with modal and progress tracking
        const generateThumbsBtn = document.getElementById('generate-thumbs-btn');
        const thumbnailModal = document.getElementById('thumbnail-modal');
        
        if (generateThumbsBtn && thumbnailModal) {
            // Console logging functions
            function logToConsole(message, type = 'info') {
                const consoleContent = document.getElementById('console-content');
                if (consoleContent) {
                    const logEntry = document.createElement('div');
                    logEntry.className = `log-entry log-${type}`;
                    logEntry.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
                    consoleContent.appendChild(logEntry);
                    consoleContent.scrollTop = consoleContent.scrollHeight;
                }
                // Also log to browser console
                console.log(`[Thumbnail Generation] ${message}`);
            }
            
            // Update progress and stats
            function updateProgress(processed, total, failed = 0) {
                const progressFill = thumbnailModal.querySelector('.progress-fill');
                const processedEl = document.getElementById('processed-videos');
                const totalEl = document.getElementById('total-videos');
                const failedEl = document.getElementById('failed-videos');
                
                if (progressFill && total > 0) {
                    const percent = Math.round((processed / total) * 100);
                    progressFill.style.width = percent + '%';
                }
                
                if (processedEl) processedEl.textContent = processed;
                if (totalEl) totalEl.textContent = total;
                if (failedEl) failedEl.textContent = failed;
            }
            
            // Show thumbnail modal
            function showThumbnailModal() {
                thumbnailModal.style.display = 'flex';
                // Reset stats
                updateProgress(0, 0, 0);
                // Clear console
                const consoleContent = document.getElementById('console-content');
                if (consoleContent) consoleContent.innerHTML = '';
                // Reset status
                const statusEl = document.getElementById('thumbnail-status');
                if (statusEl) statusEl.textContent = 'Initializing thumbnail generation...';
            }
            
            // Hide thumbnail modal
            function hideThumbnailModal() {
                thumbnailModal.style.display = 'none';
            }
            
            // Check FFmpeg availability
            async function checkFFmpeg() {
                try {
                    const response = await fetch('api.php?action=check_ffmpeg');
                    const data = await response.json();
                    if (data.available) {
                        logToConsole('FFmpeg is available and working', 'success');
                        return true;
                    } else {
                        logToConsole('FFmpeg is not available or not working', 'error');
                        logToConsole('Error: ' + (data.error || 'Unknown error'), 'error');
                        return false;
                    }
                } catch (error) {
                    logToConsole('Failed to check FFmpeg availability: ' + error.message, 'error');
                    return false;
                }
            }
            
            // Start thumbnail generation
            async function startThumbnailGeneration() {
                showThumbnailModal();
                logToConsole('Starting thumbnail generation process...', 'info');
                
                // Check FFmpeg first
                const ffmpegAvailable = await checkFFmpeg();
                if (!ffmpegAvailable) {
                    logToConsole('Cannot proceed without FFmpeg. Please install FFmpeg and ensure it\'s in your system PATH.', 'error');
                    updateProgress(0, 0, 0);
                    return;
                }
                
                // Get video count first
                try {
                    const response = await fetch('api.php?action=get_video_count');
                    const data = await response.json();
                    const totalVideos = data.count || 0;
                    
                    if (totalVideos === 0) {
                        logToConsole('No videos found to process', 'warning');
                        updateProgress(0, 0, 0);
                        return;
                    }
                    
                    logToConsole(`Found ${totalVideos} videos to process`, 'info');
                    updateProgress(0, totalVideos, 0);
                    
                    // Start processing in batches
                    let processed = 0;
                    let failed = 0;
                    const batchSize = 5;
                    let offset = 0;
                    
                    const statusEl = document.getElementById('thumbnail-status');
                    if (statusEl) statusEl.textContent = `Processing videos (${processed}/${totalVideos})...`;
                    
                    while (processed + failed < totalVideos) {
                        try {
                            const batchResponse = await fetch(`api.php?action=warm_thumbnails&offset=${offset}&batch=${batchSize}`);
                            const batchData = await batchResponse.json();
                            
                            if (batchData.status === 'ok') {
                                const batchProcessed = batchData.processed || 0;
                                const batchFailed = batchData.failed || 0;
                                
                                processed += batchProcessed;
                                failed += batchFailed;
                                offset = batchData.nextOffset || (offset + batchSize);
                                
                                logToConsole(`Batch processed: ${batchProcessed} success, ${batchFailed} failed`, 'info');
                                updateProgress(processed, totalVideos, failed);
                                
                                if (statusEl) statusEl.textContent = `Processing videos (${processed}/${totalVideos})...`;
                                
                                // Small delay between batches
                                await new Promise(resolve => setTimeout(resolve, 100));
                            } else {
                                logToConsole(`Batch failed: ${batchData.error || 'Unknown error'}`, 'error');
                                failed += batchSize;
                                offset += batchSize;
                            }
                        } catch (error) {
                            logToConsole(`Batch error: ${error.message}`, 'error');
                            failed += batchSize;
                            offset += batchSize;
                        }
                    }
                    
                    // Final update
                    updateProgress(processed, totalVideos, failed);
                    if (statusEl) statusEl.textContent = `Completed: ${processed} generated, ${failed} failed`;
                    
                    if (failed === 0) {
                        logToConsole('All thumbnails generated successfully!', 'success');
                    } else {
                        logToConsole(`Thumbnail generation completed with ${failed} failures`, 'warning');
                    }
                    
                    // Auto-hide modal after 5 seconds
                    setTimeout(() => {
                        hideThumbnailModal();
                        // Refresh the page to show updated thumbnail counts
                        location.reload();
                    }, 5000);
                    
                } catch (error) {
                    logToConsole(`Fatal error: ${error.message}`, 'error');
                    if (statusEl) statusEl.textContent = 'Error occurred during processing';
                }
            }
            
            // Button click handler
            generateThumbsBtn.addEventListener('click', function() {
                if (confirm('Generate thumbnails for all videos? This may take several minutes.')) {
                    startThumbnailGeneration();
                }
            });
        }
    });
    </script>
</body>
</html>