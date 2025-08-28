<?php

// Load BaseHandler first (required by all other handlers)
$baseHandlerPath = __DIR__ . '/handlers/BaseHandler.php';
if (!file_exists($baseHandlerPath)) {
    error_log("RMS APIRouter Error: BaseHandler file not found: " . $baseHandlerPath);
    throw new Exception("BaseHandler file not found: BaseHandler.php");
}
require_once $baseHandlerPath;

// Load all other handler classes with error checking
$handlerFiles = [
    'VideoControlHandler.php',
    'VideoManagementHandler.php', 
    'VideoLibraryHandler.php',
    'SystemHandler.php',
    'AdminHandler.php',
    'BatchHandler.php',
    'DebugHandler.php'
];

foreach ($handlerFiles as $handlerFile) {
    $handlerPath = __DIR__ . '/handlers/' . $handlerFile;
    if (!file_exists($handlerPath)) {
        error_log("RMS APIRouter Error: Handler file not found: " . $handlerPath);
        throw new Exception("Handler file not found: " . $handlerFile);
    }
    require_once $handlerPath;
}

class APIRouter {
    private $baseDir;
    private $profileId;
    
    private $routes = [
        // Video Control
        'play_video' => 'VideoControlHandler',
        'pause_video' => 'VideoControlHandler',
        'stop_video' => 'VideoControlHandler',
        'get_playback_state' => 'VideoControlHandler',
        'set_volume' => 'VideoControlHandler',
        'get_volume' => 'VideoControlHandler',
        'toggle_mute' => 'VideoControlHandler',
        'get_mute_state' => 'VideoControlHandler',
        'set_loop_mode' => 'VideoControlHandler',
        'get_loop_mode' => 'VideoControlHandler',
        'set_play_all_mode' => 'VideoControlHandler',
        'get_play_all_mode' => 'VideoControlHandler',
        'set_external_audio_mode' => 'VideoControlHandler',
        'get_external_audio_mode' => 'VideoControlHandler',
        
        // Video Management
        'get_current_video' => 'VideoManagementHandler',
        'set_current_video' => 'VideoManagementHandler',
        'clear_current_video' => 'VideoManagementHandler',
        'get_next_video' => 'VideoManagementHandler',
        
        // Video Library
        'get_all_videos' => 'VideoLibraryHandler',
        'get_video_count' => 'VideoLibraryHandler',
        'move_video' => 'VideoLibraryHandler',
        'get_video_titles' => 'VideoLibraryHandler',
        'set_video_title' => 'VideoLibraryHandler',
        
        // System
        'health' => 'SystemHandler',
        'check_config_changes' => 'SystemHandler',
        'check_changes' => 'SystemHandler',
        'get_config' => 'SystemHandler',
        'migrate_state' => 'SystemHandler',
        'get_state' => 'SystemHandler',
        
        // Legacy signal endpoints (missing from refactor)
        'check_mute_signal' => 'VideoControlHandler',
        'check_volume_signal' => 'VideoControlHandler', 
        'check_refresh_signal' => 'SystemHandler',
        'trigger_dashboard_refresh' => 'SystemHandler',
        'force_refresh_videos' => 'VideoLibraryHandler',
        
        // Admin
        'warm_thumbnails' => 'AdminHandler',
        'warm_previews' => 'AdminHandler',
        'check_ffmpeg' => 'AdminHandler',
        'browse_directories' => 'AdminHandler',
        'upload_files' => 'AdminHandler',
        
        // Batch operations
        'get_dashboard_state' => 'BatchHandler',
        'get_control_state' => 'BatchHandler',
        
        // Debug operations
        'debug_api' => 'DebugHandler',
        'clear_cache' => 'DebugHandler',
    ];
    
    public function __construct(string $baseDir, string $profileId) {
        $this->baseDir = $baseDir;
        $this->profileId = $profileId;
    }
    
    public function route(string $action): void {
        if (!isset($this->routes[$action])) {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
            return;
        }
        
        $handlerClass = $this->routes[$action];
        
        // Check if handler class exists
        if (!class_exists($handlerClass)) {
            error_log("RMS APIRouter Error: Handler class not found: " . $handlerClass);
            http_response_code(500);
            echo json_encode(['error' => 'Handler class not found: ' . $handlerClass]);
            return;
        }
        
        try {
            $handler = new $handlerClass($this->baseDir, $this->profileId);
            $handler->handle($action);
        } catch (Exception $e) {
            error_log("RMS APIRouter Error creating handler: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create handler: ' . $e->getMessage()]);
        } catch (Error $e) {
            error_log("RMS APIRouter Fatal Error creating handler: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Fatal error creating handler: ' . $e->getMessage()]);
        }
    }
}
