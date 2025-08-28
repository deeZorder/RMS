<?php
/**
 * AdminTemplate.php
 * Handles HTML template rendering for the admin interface
 */

// Load dependencies
require_once __DIR__ . '/AdminConfig.php';

// Static analysis stub to help IDEs without conflicting at runtime
if (defined('IDE_HELPERS') && IDE_HELPERS) {
    class AdminConfig {
        public function __construct($baseDir = null) {}
        public function getConfig() { return []; }
        public function getDashboards() { return []; }
        public function getAdminCache() { return []; }
        public function needsAdminScan() { return true; }
        public function updateCache($data) {}
    }
}

class AdminTemplate {
    private $config;
    private $baseDir;

    public function __construct(AdminConfig $config, $baseDir = null) {
        $this->config = $config;
        $this->baseDir = $baseDir ?: dirname(__DIR__);
    }

    public function renderHeader($title = 'Admin - Relax Media System') {
        $cssVersion = filemtime($this->baseDir . '/assets/style.css');
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo htmlspecialchars($title); ?></title>
            <link rel="stylesheet" href="assets/style.css?v=<?php echo $cssVersion; ?>">
            <?php $this->renderCSS(); ?>
        </head>
        <body>
            <?php include $this->baseDir . '/header.php'; ?>
        <?php
    }

    public function renderCSS() {
        ?>
        <style>
            /* Admin-specific CSS styles */
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
            
            /* Directory Browser Styles */
            .browser-type-selector {
                margin-bottom: 15px;
                padding: 15px;
                background: #1a1a1a;
                border: 1px solid #333;
                border-radius: 6px;
            }
            
            .radio-group {
                display: flex;
                gap: 20px;
                flex-wrap: wrap;
            }
            
            .radio-option {
                display: flex;
                align-items: center;
                gap: 8px;
                cursor: pointer;
                padding: 8px 12px;
                border-radius: 4px;
                transition: background-color 0.2s;
            }
            
            .radio-option:hover {
                background-color: #2a2a2a;
            }
            
            .radio-option input[type="radio"] {
                margin: 0;
                accent-color: #4ecdc4;
            }
            
            .radio-option span {
                color: #ccc;
                font-size: 14px;
                font-weight: 500;
            }
            
            .radio-option input[type="radio"]:checked + span {
                color: #4ecdc4;
            }
            
            #directory-browser {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.8);
                z-index: 1000;
                padding: 20px;
                box-sizing: border-box;
                overflow-y: auto;
            }
            
            #directory-browser .browser-content {
                max-width: 800px;
                margin: 0 auto;
                background: #2a2a2a;
                border: 1px solid #444;
                border-radius: 8px;
                padding: 20px;
                max-height: calc(100vh - 40px);
                overflow-y: auto;
            }
            
            .directory-item {
                padding: 8px 12px;
                border-radius: 4px;
                cursor: pointer;
                transition: background-color 0.2s;
                margin-bottom: 2px;
            }
            
            .directory-item:hover {
                background-color: #333;
            }
            
            /* Modal Console and Progress Styles */
            .thumbnail-animation, .preview-animation {
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
            
            .progress-bar {
                width: 100%;
                height: 8px;
                background-color: #333;
                border-radius: 4px;
                overflow: hidden;
                margin: 10px 0;
            }
            
            .progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #4ecdc4, #44a08d);
                width: 0%;
                transition: width 0.3s ease;
                border-radius: 4px;
            }
            
            .thumbnail-stats, .preview-stats {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 20px;
                width: 100%;
                margin: 20px 0;
            }
            
            .stat-item {
                text-align: center;
                padding: 10px;
                background: rgba(78, 205, 196, 0.1);
                border-radius: 4px;
                border: 1px solid rgba(78, 205, 196, 0.3);
            }
            
            .stat-label {
                display: block;
                font-size: 12px;
                color: #888;
                margin-bottom: 5px;
            }
            
            .stat-value {
                display: block;
                font-size: 18px;
                font-weight: bold;
                color: #4ecdc4;
            }
            
            .console-output {
                width: 100%;
                margin-top: 20px;
            }
            
            .console-header {
                background: #1a1a1a;
                color: #4ecdc4;
                padding: 8px 12px;
                font-size: 12px;
                font-weight: bold;
                border-radius: 4px 4px 0 0;
                border: 1px solid #333;
                border-bottom: none;
            }
            
            .console-content {
                background: #000;
                color: #ccc;
                padding: 10px;
                font-family: 'Courier New', monospace;
                font-size: 11px;
                line-height: 1.4;
                max-height: 200px;
                overflow-y: auto;
                border: 1px solid #333;
                border-radius: 0 0 4px 4px;
                white-space: pre-wrap;
            }
            
            .log-entry {
                margin-bottom: 2px;
                padding: 2px 0;
            }
            
            .log-success {
                color: #4ecdc4;
            }
            
            .log-error {
                color: #ff6b6b;
            }
            
            .log-warning {
                color: #ffa500;
            }
            
            .log-info {
                color: #ccc;
            }

        </style>
        <?php
    }

    public function renderNavigation() {
        ?>
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
        <?php
    }

    public function renderDirectoryConfigSection($flash = null, $activeSection = '') {
        $config = $this->config->getConfig();
        $needsAdminScan = $this->config->needsAdminScan();
        $adminCache = $this->config->getAdminCache();
        ?>
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
                        <input type="text" id="directory" name="directory" value="" placeholder="Enter full path (e.g., C:\Videos or /home/user/videos) or browse local folders below">
                        <button type="button" id="add-directory-btn" class="btn primary">＋ Add</button>
                    </div>
                    <small class="hint">
                        Add full directory paths and click ＋ Add. Use "Browse Local Folders" below for easy upload, or type server paths manually.<br>
                        <strong>Examples:</strong> <code>C:\Users\YourName\Videos</code> (Windows) • <code>/home/username/videos</code> (Linux) • <code>\\server\share\videos</code> (Network)
                    </small>
                    <input type="hidden" id="directories_json" name="directories_json" value="<?php echo htmlspecialchars(json_encode($config['directories'] ?? [$config['directory'] ?? 'videos'])); ?>">
                    <input type="hidden" id="current_section" name="current_section" value="directory-config">
                    <div id="directories-chips" class="chips"></div>
                    
                    <?php $this->renderDirectoryBrowser(); ?>
                    
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
        <?php
    }

    private function renderDirectoryBrowser() {
        ?>
        <!-- Directory Browser -->
        <div id="directory-browser">
            <div class="browser-content">
                <h4>Browse Directories</h4>
                
                <!-- Browser Type Selection -->
                <div class="browser-type-selector">
                    <div class="radio-group">
                        <label class="radio-option">
                            <input type="radio" name="browser-type" value="server" checked>
                            <span>🖥️ Server Directories</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="browser-type" value="client" id="client-browser-radio">
                            <span>💻 Local Client Directories</span>
                        </label>
                    </div>
                    <div id="client-browser-warning" style="display: none; margin-top: 8px; padding: 8px; background: #2a1a00; border: 1px solid #664400; border-radius: 4px; color: #ffaa00; font-size: 12px;">
                        ⚠️ Client directory browsing requires a modern browser with File System Access API support (Chrome/Edge 86+). Selected directories will need to be accessible to the server.
                    </div>
                </div>
                
                <!-- Server Browser Controls -->
                <div id="server-browser-controls" class="directory-browser-controls">
                    <div class="directory-browser-path">
                        <input type="text" id="browse-path" placeholder="Enter server path to browse">
                        <button type="button" id="browse-go-btn" class="btn primary">Go</button>
                    </div>
                    <div class="directory-browser-buttons">
                        <button type="button" id="browse-home-btn" class="btn secondary">Home</button>
                    </div>
                </div>
                
                <!-- Client Browser Controls -->
                <div id="client-browser-controls" class="directory-browser-controls" style="display: none;">
                    <div class="client-browser-info">
                        <p style="margin: 10px 0; color: #ccc; font-size: 14px;">
                            Click "Browse Local Folders" to select a folder from your computer using your browser's file picker.
                        </p>
                        <button type="button" id="browse-client-btn" class="btn primary">📁 Browse Local Folders</button>
                        <input type="file" id="client-file-input" webkitdirectory directory multiple style="display: none;">
                    </div>
                    <div id="client-selected-info" style="display: none; margin-top: 15px; padding: 10px; background: #1a2a1a; border: 1px solid #4ecdc4; border-radius: 4px;">
                        <p style="margin: 0 0 8px 0; color: #4ecdc4; font-weight: bold;">Selected Local Directory:</p>
                        <p id="client-selected-path" style="margin: 0; color: #fff; word-break: break-all;"></p>
                        <p style="margin: 8px 0 0 0; color: #888; font-size: 12px;">
                            Note: Make sure this directory is accessible to your server via network share, mapped drive, or shared folder.
                        </p>
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
        </div>
        <?php
    }

    public function renderModals() {
        ?>
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

        <!-- Preview Generation Modal -->
        <div id="preview-modal" class="modal" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>🎬 Generating Previews</h3>
                </div>
                <div class="modal-body">
                    <div class="preview-animation">
                        <div class="spinner"></div>
                        <p id="preview-status">Initializing preview generation...</p>
                        <p class="preview-details">This may take a very long time depending on the number of videos.</p>
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                        <div class="preview-stats">
                            <div class="stat-item">
                                <span class="stat-label">Total Videos:</span>
                                <span class="stat-value" id="preview-total-videos">0</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Processed:</span>
                                <span class="stat-value" id="preview-processed-videos">0</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Failed:</span>
                                <span class="stat-value" id="preview-failed-videos">0</span>
                            </div>
                        </div>
                        <div class="console-output" id="preview-console-output">
                            <div class="console-header">Console Output:</div>
                            <div class="console-content" id="preview-console-content"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- HLS Generation Modal -->
        <div id="hls-modal" class="modal" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>📺 Generating HLS Streams</h3>
                </div>
                <div class="modal-body">
                    <div class="hls-animation">
                        <div class="spinner"></div>
                        <p id="hls-status">Initializing HLS generation...</p>
                        <p class="hls-details">This will generate adaptive streaming for all videos. This may take a very long time depending on the number and size of videos.</p>
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                        <div class="hls-stats">
                            <div class="stat-item">
                                <span class="stat-label">Total Videos:</span>
                                <span class="stat-value" id="hls-total-videos">0</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Processed:</span>
                                <span class="stat-value" id="hls-processed-videos">0</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Failed:</span>
                                <span class="stat-value" id="hls-failed-videos">0</span>
                            </div>
                        </div>
                        <div class="console-output" id="hls-console-output">
                            <div class="console-header">Console Output:</div>
                            <div class="console-content" id="hls-console-content"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function renderFooter() {
        ?>
        </body>
        </html>
        <?php
    }

    public function renderDashboardSettings($selectedDashboard, $flash = null, $activeSection = '') {
        $dashboards = $this->config->getDashboards();
        $config = $this->config->getConfig();
        
        // Get available backgrounds
        $availableBackgrounds = $this->getAvailableBackgrounds();
        ?>
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
                            <option value="<?php echo htmlspecialchars($bg); ?>" <?php echo ((($dashboards[$selectedDashboard]['dashboardBackground'] ?? '') === $bg) ? 'selected' : ''); ?>><?php echo htmlspecialchars(basename($bg)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="hint">Place images in <code>assets/backgrounds</code>. Supports JPG, PNG, WEBP, GIF.</small>
                    <?php if (!empty(($dashboards[$selectedDashboard]['dashboardBackground'] ?? ''))): ?>
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
        <?php
    }

    private function getAvailableBackgrounds() {
        $backgroundDir = $this->baseDir . '/assets/backgrounds';
        $availableBackgrounds = [];
        
        $adminCache = $this->config->getAdminCache();
        $needsAdminScan = $this->config->needsAdminScan();
        
        if ($needsAdminScan || !isset($adminCache['available_backgrounds'])) {
            if (is_dir($backgroundDir)) {
                $files = @scandir($backgroundDir) ?: [];
                foreach ($files as $bgFile) {
                    if ($bgFile === '.' || $bgFile === '..') continue;
                    $ext = strtolower(pathinfo($bgFile, PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                        $availableBackgrounds[] = 'assets/backgrounds/' . $bgFile;
                    }
                }
            }
            
            if ($needsAdminScan) {
                $this->config->updateCache(['available_backgrounds' => $availableBackgrounds]);
            }
        } else {
            $availableBackgrounds = $adminCache['available_backgrounds'] ?? [];
        }
        
        return $availableBackgrounds;
    }
}
