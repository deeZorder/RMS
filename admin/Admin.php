<?php
/**
 * Admin.php
 * Main admin class that orchestrates the admin interface
 * Compatible with PHP 7.3
 */

// Load dependencies in correct order
require_once __DIR__ . '/AdminConfig.php';
require_once __DIR__ . '/AdminHandlers.php';
require_once __DIR__ . '/AdminTemplate.php';

// Static analysis stubs to help IDEs without conflicting at runtime
if (defined('IDE_HELPERS') && IDE_HELPERS) {
    class AdminConfig {
        public function __construct($baseDir = null) {}
        public function getConfig() { return []; }
        public function getDashboards() { return []; }
        public function getScreens() { return ['screens' => []]; }
        public function getAdminCache() { return []; }
        public function needsAdminScan() { return true; }
        public function getConfiguredDirectories() { return []; }
        public function invalidateCache() {}
        public function updateCache($data) {}
    }
    class AdminHandlers {
        public function __construct($config, $baseDir = null) {}
        public function handleRequest() { return false; }
    }
    class AdminTemplate {
        public function __construct($config, $baseDir = null) {}
        public function renderHeader($title = '') {}
        public function renderNavigation() {}
        public function renderModals() {}
        public function renderFooter() {}
        public function renderDashboardSettings($selectedDashboard, $flash = null, $activeSection = '') {}
        public function renderDirectoryConfigSection($flash = null, $activeSection = '') {}
    }
}

class Admin {
    /** @var AdminConfig */
private $config;
/** @var AdminHandlers */
private $handlers;
/** @var AdminTemplate */
private $template;
private $baseDir;
    private $flash;
    private $hasError;

    public function __construct($baseDir = null) {
        if ($baseDir === null) {
            $baseDir = dirname(__DIR__);
        }
        $this->baseDir = $baseDir;
        
        // Set security headers (must be sent before any output)
        $this->setSecurityHeaders();
        
        // Start session for flash messages
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        // Set admin authentication flag for API security
        $_SESSION['admin_authenticated'] = true;
        
        // Initialize components
        $this->config = new AdminConfig($baseDir);
        $this->handlers = new AdminHandlers($this->config, $baseDir);
        $this->template = new AdminTemplate($this->config, $baseDir);
        
        // Handle flash messages
        $this->flash = isset($_SESSION['flash']) ? $_SESSION['flash'] : null;
        if (isset($_SESSION['flash'])) {
            unset($_SESSION['flash']);
        }
        $this->hasError = false;
    }

    private function setSecurityHeaders() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        // Temporarily comment out strict referrer policy to allow API calls
        // header('Referrer-Policy: no-referrer');
        header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    }

    public function run() {
        // Handle POST requests
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $handled = $this->handlers->handleRequest();
            if ($handled) {
                return; // Handler redirected, so we're done
            }

            // If not handled by our refactored handlers, fall back to original logic
            // This allows for gradual migration
            $this->handleLegacyPosts();
        }
        
        // Render the admin interface
        $this->render();
    }

    private function handleLegacyPosts() {
        // Handle the cases that haven't been fully refactored yet
        $currentSection = isset($_POST['current_section']) ? $_POST['current_section'] : 'directory-config';
        
        if ($currentSection === 'generate-thumbnails') {
            // Route to the new AdminHandlers system
            $this->handlers->handleRequest();
            return; // handleRequest will exit if successful
        } elseif ($currentSection === 'generate-previews') {
            // Route to the new AdminHandlers system
            $this->handlers->handleRequest();
            return; // handleRequest will exit if successful
        }
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



    public function render() {
        // Get current state
        $activeSection = isset($_GET['section']) ? (string)$_GET['section'] : '';
        $selectedDashboard = isset($_GET['dashboard']) ? $_GET['dashboard'] : 'default';
        
        // Discover available directories and scan for videos
        $this->scanAndCacheData();
        
        // Start rendering
        $this->template->renderHeader();
        
        echo '<main class="admin-page">';
        echo '<div class="admin-layout">';
        
        // Render navigation
        $this->template->renderNavigation();
        
        echo '<div class="admin-content">';
        
        // Render sections
        $this->renderAllSections($selectedDashboard, $activeSection);
        
        echo '</div></div>';
        
        // Render modals
        $this->template->renderModals();
        
        echo '</main>';
        
        // Pass dashboard data to JavaScript
        $dashboards = $this->config->getDashboards();
        echo '<script>';
        echo 'window.adminDashboards = ' . json_encode($dashboards) . ';';
        echo '</script>';
        
        // Include the JavaScript file
        echo '<script src="admin/admin.js?v=' . filemtime(__DIR__ . '/admin.js') . '"></script>';
        
        $this->template->renderFooter();
    }

    private function renderAllSections($selectedDashboard, $activeSection) {
        // Directory Configuration Section
        $this->template->renderDirectoryConfigSection($this->flash, $activeSection);
        
        // Video Management Section
        $this->renderVideoManagementSection();
        
        // Dashboard Settings Section
        $this->template->renderDashboardSettings($selectedDashboard, $this->flash, $activeSection);
        
        // Screen Management Section
        $this->renderScreenManagementSection();
        
        // System Status Section
        $this->renderSystemStatusSection();
    }

    private function renderVideoManagementSection() {
        // Get pagination info
        $page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
        $videosPerPage = 20;

        $adminCache = $this->config->getAdminCache();
        $totalVideos = isset($adminCache['total_videos']) ? $adminCache['total_videos'] : 0;
        $totalPages = max(1, ceil($totalVideos / $videosPerPage));
        
        $paginationInfo = array(
            'total' => $totalVideos,
            'pages' => $totalPages,
            'current' => $page,
            'per_page' => $videosPerPage,
            'start' => (($page - 1) * $videosPerPage) + 1,
            'end' => min(($page - 1) * $videosPerPage + $videosPerPage, $totalVideos)
        );
        
        ?>
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
                    <div class="header-actions">
                        <form method="post" style="display: inline-block;">
                            <input type="hidden" name="current_section" value="refresh-dashboards">
                            <button type="submit" class="btn btn-secondary" title="Push current video order to all dashboards">
                                🔄 Refresh Dashboards
                            </button>
                        </form>
                    </div>
                </div>
                
                <?php $this->renderPagination($paginationInfo, $page); ?>
                
                <div id="video-titles-container">
                    <!-- Video carousel preview will be loaded here via JavaScript -->
                </div>
            </div>
        </section>
        <?php
    }

    private function renderPagination($paginationInfo, $page) {
        if ($paginationInfo['total'] <= $paginationInfo['per_page']) {
            return; // No pagination needed
        }
        ?>
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
        </div>
        <?php
    }

    private function renderScreenManagementSection() {
        $dashboards = $this->config->getDashboards();
        $screens = $this->config->getScreens();
        ?>
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
                    $name = isset($dash['name']) ? $dash['name'] : $id;
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
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    private function renderSystemStatusSection() {
        $config = $this->config->getConfig();
        $adminCache = $this->config->getAdminCache();
        $totalVideos = isset($adminCache['total_videos']) ? $adminCache['total_videos'] : 0;
        
        // Count thumbnails and previews
        $thumbDir = $this->baseDir . '/data/thumbs';
        $previewDir = $this->baseDir . '/data/previews';
        
        $thumbCount = 0;
        if (is_dir($thumbDir)) {
            $thumbFiles = @scandir($thumbDir) ?: array();
            $thumbCount = count(array_filter($thumbFiles, function($f) { 
                return $f !== '.' && $f !== '..' && pathinfo($f, PATHINFO_EXTENSION) === 'jpg'; 
            }));
        }
        
        $previewCount = 0;
        if (is_dir($previewDir)) {
            $previewFiles = @scandir($previewDir) ?: array();
            $previewCount = count(array_filter($previewFiles, function($f) { 
                return $f !== '.' && $f !== '..' && pathinfo($f, PATHINFO_EXTENSION) === 'mp4'; 
            }));
        }
        ?>
        <section id="system-status" class="admin-section">
            <div class="section-header">
                <h3>📊 System Status</h3>
                <p>Monitor system status and manage dashboard refresh</p>
            </div>
            
            <div class="status-cards">
                <div class="status-card">
                    <h4>📁 Directory Status</h4>
                    <p>Configured directories: <strong><?php echo count($config['directories'] ?? array($config['directory'] ?? 'videos')); ?></strong></p>
                    <p>Total videos found: <strong><?php echo number_format($totalVideos); ?></strong></p>
                    <p>Generated thumbnails: <strong><?php echo number_format($thumbCount); ?></strong></p>
                    <?php if ($totalVideos > 0): ?>
                        <p>Thumbnail coverage: <strong><?php echo round(($thumbCount / $totalVideos) * 100); ?>%</strong></p>
                    <?php endif; ?>
                    <p>Generated previews: <strong><?php echo number_format($previewCount); ?></strong></p>
                    <?php if ($totalVideos > 0): ?>
                        <p>Preview coverage: <strong><?php echo round(($previewCount / $totalVideos) * 100); ?>%</strong></p>
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
                        <button type="button" id="generate-thumbs-btn" class="btn btn-primary">🖼️ Generate Thumbnails</button>
                        <button type="button" id="generate-previews-btn" class="btn btn-primary">🎬 Generate Previews</button>
                        <button type="button" id="stop-processes-btn" class="btn btn-danger">⏹️ Stop All Processes</button>
                        <button type="button" id="reindex-previews-btn" class="btn secondary">↻ Reindex Previews</button>
                        <form method="post" action="admin.php" onsubmit="return confirm('Delete ALL generated thumbnails and custom titles? This cannot be undone.');" style="display:inline;">
                            <input type="hidden" name="current_section" value="clear-thumbs-titles" data-fixed>
                            <button type="submit" class="btn secondary">🧹 Clear Titles, Thumbnails & Previews</button>
                        </form>
                        <form method="post" action="admin.php" onsubmit="return confirm('Reset configuration and dashboards to defaults?');" style="display:inline;">
                            <input type="hidden" name="current_section" value="system-reset" data-fixed>
                            <button type="submit" class="btn secondary">🔄 Reset to Default</button>
                        </form>
                        <!-- <button type="button" id="encode-vp9-btn" class="btn btn-primary">🧪 Encode “New Zealand Tour.mp4” (VP9 1080p)</button> -->
                    </div>
                </div>
            </div>
        </section>
        <?php
    }

    private function scanAndCacheData() {
        if (!$this->config->needsAdminScan()) {
            return; // Use cached data
        }
        
        $startTime = microtime(true);
        $projectRoot = $this->baseDir;
        
        // Get available directories for selection
        $availableDirectories = array();
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
        
        // Count total videos across all configured directories
        $configuredDirs = $this->config->getConfiguredDirectories();
        $totalVideos = 0;
        $allVideoFiles = array();
        $allowedExt = array('mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv');
        
        foreach ($configuredDirs as $dirIndex => $dir) {
            if (!is_dir($dir)) {
                $relativePath = realpath($this->baseDir . '/' . $dir);
                if ($relativePath && is_dir($relativePath)) {
                    $dir = $relativePath;
                } else {
                    continue;
                }
            }
            
            $allFiles = scandir($dir);
            if ($allFiles === false) continue;
            
            foreach ($allFiles as $file) {
                if ($file !== '.' && $file !== '..' && is_file($dir . DIRECTORY_SEPARATOR . $file)) {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($ext, $allowedExt)) {
                        $allVideoFiles[] = array(
                            'name' => $file,
                            'dirIndex' => $dirIndex,
                            'path' => $dir . DIRECTORY_SEPARATOR . $file
                        );
                    }
                }
            }
        }
        
        // Sort files for consistent pagination
        usort($allVideoFiles, function ($a, $b) {
            $c = strcmp($a['name'], $b['name']);
            return $c !== 0 ? $c : ($a['dirIndex'] - $b['dirIndex']);
        });
        
        $totalVideos = count($allVideoFiles);
        
        // Cache the results
        $this->config->updateCache(array(
            'last_scan' => time(),
            'available_directories' => $availableDirectories,
            'total_videos' => $totalVideos,
            'all_video_files' => $allVideoFiles
        ));
    }
}
