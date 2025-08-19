<?php
// Start output buffering to prevent header issues
ob_start();

// Suppress PHP notices/warnings from leaking into JSON responses
@ini_set('display_errors', '0');
@error_reporting(0);

// Set reasonable timeout for API calls
@set_time_limit(30);
@ini_set('max_execution_time', 30);

// api.php
// AJAX endpoints for the Relax Media system.

// Include shared state management and router
require_once __DIR__ . '/state_manager.php';

// Check if APIRouter.php exists and load it
$routerPath = __DIR__ . '/APIRouter.php';
if (!file_exists($routerPath)) {
    error_log("RMS API Error: APIRouter.php not found at: " . $routerPath);
    http_response_code(500);
    echo json_encode(['error' => 'APIRouter.php not found']);
    exit;
}

require_once $routerPath;

// Verify APIRouter class is defined
if (!class_exists('APIRouter')) {
    error_log("RMS API Error: APIRouter class not defined after including " . $routerPath);
    http_response_code(500);
    echo json_encode(['error' => 'APIRouter class not defined']);
    exit;
}

// Function definitions
if (!function_exists('sanitize_profile_id')) {
    function sanitize_profile_id(string $raw): string {
        $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $raw);
        return $id !== '' ? $id : 'default';
    }
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

// CORS headers for distributed setup (tablet/monitor access)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Determine action from query string
$action = $_GET['action'] ?? '';

$profileId = 'default';
if (isset($_GET['profile'])) {
    $profileId = sanitize_profile_id((string)$_GET['profile']);
} elseif (isset($_POST['profile'])) {
    $profileId = sanitize_profile_id((string)$_POST['profile']);
} elseif (isset($_GET['d'])) {
    $n = (int)$_GET['d'];
    if ($n === 0) { $profileId = 'default'; }
    elseif ($n >= 1) { $profileId = 'dashboard' . $n; }
} elseif (isset($_POST['d'])) {
    $n = (int)$_POST['d'];
    if ($n === 0) { $profileId = 'default'; }
    elseif ($n >= 1) { $profileId = 'dashboard' . $n; }
}

// Initialize router and handle request
$baseDir = __DIR__;

try {
    $router = new APIRouter($baseDir, $profileId);
    $router->route($action);
    
    // Get the output buffer content and send it
    $buffer = ob_get_clean();
    if (!empty($buffer)) {
        // Send the JSON response
        echo $buffer;
    }
    
} catch (Exception $e) {
    // Clean up output buffer on error
    $buffer = ob_get_clean();
    if (!empty($buffer)) {
        error_log("RMS API Buffer Content on Exception: " . $buffer);
    }
    
    // Log the error for debugging with more details
    error_log("RMS API Error: " . $e->getMessage() . " | Action: " . $action . " | Profile: " . $profileId . " | File: " . $e->getFile() . ":" . $e->getLine() . " | Trace: " . $e->getTraceAsString());
    
    // Return proper JSON error response
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'action' => $action,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ]);
} catch (Error $e) {
    // Clean up output buffer on error
    $buffer = ob_get_clean();
    if (!empty($buffer)) {
        error_log("RMS API Buffer Content on Fatal Error: " . $buffer);
    }
    
    // Log fatal errors with more details
    error_log("RMS API Fatal Error: " . $e->getMessage() . " | Action: " . $action . " | Profile: " . $profileId . " | File: " . $e->getFile() . ":" . $e->getLine() . " | Trace: " . $e->getTraceAsString());
    
    // Return proper JSON error response
    http_response_code(500);
    echo json_encode([
        'error' => 'Fatal error occurred',
        'action' => $action,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
