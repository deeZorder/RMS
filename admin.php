<?php
/**
 * admin.php
 * Administrative interface for the Relax Media system.
 * Refactored for better maintainability and PHP 7.3 compatibility
 */

// Include shared state management
require_once __DIR__ . '/state_manager.php';

// Include the refactored admin system
require_once __DIR__ . '/admin/AdminBootstrap.php';

// Ensure the Admin class is loaded (fallback if bootstrap fails)
if (!class_exists('Admin')) {
    require_once __DIR__ . '/admin/Admin.php';
}

// Ensure triggerDashboardRefresh function is available
if (!function_exists('triggerDashboardRefresh')) {
    /**
     * Fallback triggerDashboardRefresh function if not loaded from state_manager.php
     * @param string $profileId The dashboard profile ID to refresh
     * @return bool Success status
     */
    function triggerDashboardRefresh($profileId) {
        // Modern approach: Update state with a refresh timestamp
        // Dashboards can poll for this change to trigger refreshes
        if (function_exists('updateState')) {
            $updates = array(
                'lastRefreshTrigger' => time(),
                'refreshRequested' => true
            );
            return updateState($profileId, $updates);
        }

        // Fallback: Write directly to a refresh signal file if updateState is not available
        $baseDir = __DIR__;
        $dataDir = $baseDir . '/data';
        $profilesDir = $dataDir . '/profiles';
        if (!is_dir($profilesDir)) { @mkdir($profilesDir, 0777, true); }
        $profileDir = $profilesDir . '/' . $profileId;
        if (!is_dir($profileDir)) { @mkdir($profileDir, 0777, true); }
        $refreshFile = $profileDir . '/dashboard_refresh.txt';
        return file_put_contents($refreshFile, time()) !== false;
    }
}

// Initialize and run the refactored admin system
try {
    // Verify Admin class exists before instantiation
    if (!class_exists('Admin')) {
        throw new Exception('Admin class could not be loaded. Please check the admin system files.');
    }

    $admin = new Admin(__DIR__);
    $admin->run();
} catch (Exception $e) {
    // Show error instead of falling back to old system
    echo '<!DOCTYPE html><html><head><title>Admin System Error</title></head><body>';
    echo '<h1>Admin System Error</h1>';
    echo '<p>The admin system encountered an error. Please check the configuration and try again.</p>';
    echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><a href="dashboard.php">Return to Dashboard</a></p>';
    echo '</body></html>';
    exit;
}