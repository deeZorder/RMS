<?php

require_once __DIR__ . '/BaseHandler.php';

class SecurityMiddleware extends BaseHandler {

    private $readOnlyActions = [
        'get_current_video',
        'get_playback_state',
        'get_volume',
        'get_mute_state',
        'get_loop_mode',
        'get_play_all_mode',
        'get_external_audio_mode',
        'get_video_titles',
        'get_next_video',
        'check_config_changes',
        'check_refresh_signal',
        'test_connection',
        'simple_test',
        'debug_state',
        'log_event',
        'list_video_codecs',
        'encode_vp9_status',
        'browse_directories',
        // Allow dashboard control actions for kiosk devices
        'set_current_video',
        'clear_current_video',
        'play_video',
        'pause_video',
        'stop_video',
        'set_volume',
        'toggle_mute',
        'set_loop_mode',
        'set_play_all_mode',
        'set_external_audio_mode',
    ];

    public function validateRequest(string $action): bool {
        $enforceSecurity = !in_array($action, $this->readOnlyActions, true);
        $isSecure = false;

        // Method 1: Check referrer (if available)
        if (isset($_SERVER['HTTP_REFERER'])) {
            $ref = $_SERVER['HTTP_REFERER'];
            if (strpos($ref, 'admin.php') !== false || strpos($ref, 'dashboard.php') !== false) {
                $isSecure = true;
            }
        }

        // Method 2: Check if we're in the same domain and session is active
        if (!$isSecure && isset($_SERVER['HTTP_HOST']) && isset($_SERVER['SERVER_NAME'])) {
            if ($_SERVER['HTTP_HOST'] === $_SERVER['SERVER_NAME'] || $_SERVER['HTTP_HOST'] === 'localhost') {
                // Start session if not already started
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    session_start();
                }

                // Check if we have any admin session data or if we're in admin/dashboard context
                if (isset($_SESSION['admin_authenticated']) ||
                    (isset($_SERVER['REQUEST_URI']) && (
                        strpos($_SERVER['REQUEST_URI'], 'admin') !== false ||
                        strpos($_SERVER['REQUEST_URI'], 'dashboard') !== false
                    ))) {
                    $isSecure = true;
                }
            }
        }

        // Method 3: Check if we're accessing from the same directory structure
        if (!$isSecure && isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], 'admin') !== false) {
            $isSecure = true;
        }

        // Method 4: Development mode - allow localhost access (remove in production)
        if (!$isSecure && isset($_SERVER['HTTP_HOST']) &&
            (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
             strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false)) {
            $isSecure = true;
        }

        // Method 5: Action context hint provided via suffix (e.g., @admin.php or @dashboard.php)
        if (!$isSecure && isset($GLOBALS['actionContext']) && $GLOBALS['actionContext'] !== '') {
            if (strpos($GLOBALS['actionContext'], 'admin.php') !== false || strpos($GLOBALS['actionContext'], 'dashboard.php') !== false) {
                $isSecure = true;
            }
        }

        if ($enforceSecurity && !$isSecure) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Access denied - insufficient security context',
                'debug_info' => [
                    'referer' => $_SERVER['HTTP_REFERER'] ?? 'none',
                    'host' => $_SERVER['HTTP_HOST'] ?? 'none',
                    'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'none',
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'none'
                ]
            ]);
            return false;
        }

        return true;
    }

    public function __construct() {
        // Override parent constructor - we don't need baseDir/profileId for security middleware
    }
}
