<?php
/**
 * AdminConfig.php
 * Handles admin configuration loading, validation, and management
 */

// Load dependencies
// AdminConfig is the base class and has no external dependencies

class AdminConfig {
    private $config = [];
    private $dashboards = [];
    private $screens = [];
    private $adminCache = [];
    private $configPath;
    private $dataDir;
    private $dashboardsPath;
    private $screensPath;
    private $adminCachePath;
    private $needsAdminScan = true;

    public function __construct($baseDir = null) {
        if ($baseDir === null) {
            $baseDir = dirname(__DIR__);
        }
        
        $this->configPath = $baseDir . '/config.json';
        $this->dataDir = $baseDir . '/data';
        $this->dashboardsPath = $this->dataDir . '/dashboards.json';
        $this->screensPath = $this->dataDir . '/screens.json';
        $this->adminCachePath = $this->dataDir . '/admin_cache.json';
        
        $this->ensureDataDirectory();
        $this->loadConfiguration();
        $this->loadDashboards();
        $this->loadScreens();
        $this->checkCacheFreshness();
    }

    private function ensureDataDirectory() {
        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0777, true);
        }
    }

    private function loadConfiguration() {
        $defaultConfig = array(
            'directory' => 'videos',
            'rows' => 2,
            'clipsPerRow' => 4,
            'dashboardBackground' => ''
        );

        if (file_exists($this->configPath)) {
            $config = json_decode(file_get_contents($this->configPath), true);
            if (is_array($config)) {
                $this->config = array_merge($defaultConfig, $config);
            } else {
                $this->config = $defaultConfig;
            }
        } else {
            $this->config = $defaultConfig;
        }

        // Ensure missing config keys have sane defaults
        if (!isset($this->config['directory']) || !is_string($this->config['directory']) || $this->config['directory'] === '') {
            $this->config['directory'] = 'videos';
        }
        if (!isset($this->config['rows']) || (int)$this->config['rows'] < 1) {
            $this->config['rows'] = 2;
        }
        if (!isset($this->config['clipsPerRow']) || (int)$this->config['clipsPerRow'] < 1) {
            $this->config['clipsPerRow'] = 4;
        }
    }

    private function loadDashboards() {
        $this->dashboards = [];
        if (file_exists($this->dashboardsPath)) {
            $decodedDash = json_decode(@file_get_contents($this->dashboardsPath), true);
            if (is_array($decodedDash)) {
                $this->dashboards = $decodedDash;
            }
        }

        // Ensure a default profile exists
        if (empty($this->dashboards) || !isset($this->dashboards['default'])) {
            $this->dashboards['default'] = array(
                'id' => 'default',
                'name' => 'Default',
                'rows' => (int)($this->config['rows'] ?? 2),
                'clipsPerRow' => (int)($this->config['clipsPerRow'] ?? 4),
                'dashboardBackground' => (string)($this->config['dashboardBackground'] ?? ''),
            );
            $this->saveDashboards();
        }

        // Normalize default profile name to "Default" if needed
        if (isset($this->dashboards['default'])) {
            if (($this->dashboards['default']['name'] ?? '') !== 'Default') {
                $this->dashboards['default']['name'] = 'Default';
                $this->saveDashboards();
            }
        }
    }

    private function loadScreens() {
        $this->screens = [];
        if (file_exists($this->screensPath)) {
            $decodedScreens = json_decode(@file_get_contents($this->screensPath), true);
            if (is_array($decodedScreens)) {
                $this->screens = $decodedScreens;
            }
        }
        if (!isset($this->screens['screens']) || !is_array($this->screens['screens'])) {
            $this->screens['screens'] = array();
        }
    }

    private function checkCacheFreshness() {
        $this->needsAdminScan = true;
        if (file_exists($this->adminCachePath)) {
            $this->adminCache = json_decode(file_get_contents($this->adminCachePath), true);
            if (!is_array($this->adminCache)) {
                $this->adminCache = array();
            }
            $lastScanTime = $this->adminCache['last_scan'] ?? 0;

            // Check if any configured directories have changed
            $maxDirModTime = 0;
            $configuredDirs = $this->getConfiguredDirectories();

            foreach ($configuredDirs as $dir) {
                if (is_dir($dir)) {
                    $mt = @filemtime($dir) ?: 0;
                    if ($mt > $maxDirModTime) {
                        $maxDirModTime = $mt;
                    }
                } else {
                    // Try relative path
                    $relativePath = realpath(dirname($this->configPath) . '/' . $dir);
                    if ($relativePath && is_dir($relativePath)) {
                        $mt = @filemtime($relativePath) ?: 0;
                        if ($mt > $maxDirModTime) {
                            $maxDirModTime = $mt;
                        }
                    }
                }
            }

            // If we couldn't resolve any configured directory, fall back to the default videos folder
            if ($maxDirModTime === 0) {
                $videosPath = dirname($this->configPath) . '/videos';
                if (is_dir($videosPath)) {
                    $maxDirModTime = @filemtime($videosPath) ?: 0;
                }
            }

            if ($maxDirModTime <= $lastScanTime) {
                $this->needsAdminScan = false;
            }
        }
    }

    public function getConfiguredDirectories() {
        // Use null coalescing to avoid undefined index notices and help linters
        $dirs = $this->config['directories'] ?? null;
        if (is_array($dirs) && !empty($dirs)) {
            return $dirs;
        }
        return [ $this->config['directory'] ?? 'videos' ];
    }

    public function saveConfiguration() {
        return file_put_contents($this->configPath, json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function saveDashboards() {
        return file_put_contents($this->dashboardsPath, json_encode($this->dashboards, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function saveScreens() {
        return file_put_contents($this->screensPath, json_encode($this->screens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function invalidateCache() {
        if (file_exists($this->adminCachePath)) {
            @unlink($this->adminCachePath);
        }
        $this->needsAdminScan = true;
    }

    public function updateCache($data) {
        $this->adminCache = array_merge($this->adminCache, $data);
        file_put_contents($this->adminCachePath, json_encode($this->adminCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    // Getters
    public function getConfig() { return $this->config; }
    public function getDashboards() { return $this->dashboards; }
    public function getScreens() { return $this->screens; }
    public function getAdminCache() { return $this->adminCache; }
    public function needsAdminScan() { return $this->needsAdminScan; }
    public function getConfigPath() { return $this->configPath; }
    public function getDataDir() { return $this->dataDir; }
    public function getDashboardsPath() { return $this->dashboardsPath; }
    public function getScreensPath() { return $this->screensPath; }

    // Setters
    public function setConfig($key, $value) {
        $this->config[$key] = $value;
    }

    public function setDashboard($id, $dashboard) {
        $this->dashboards[$id] = $dashboard;
    }

    public function removeDashboard($id) {
        if (isset($this->dashboards[$id])) {
            unset($this->dashboards[$id]);
        }
    }

    public function addScreen($screen) {
        $this->screens['screens'][] = $screen;
    }

    public function removeScreen($screenId) {
        $this->screens['screens'] = array_values(array_filter(
            $this->screens['screens'],
            function($s) use ($screenId) {
                return (($s['id'] ?? '') !== $screenId);
            }
        ));
    }
}