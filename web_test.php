<?php
// Enable error display for debugging
@ini_set('display_errors', '1');
@error_reporting(E_ALL);

// Set headers first
header('Content-Type: text/plain; charset=utf-8');

echo "=== Web Test Debug ===\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Current directory: " . __DIR__ . "\n";
echo "Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "\n";

// Test file existence
$files = [
    'state_manager.php',
    'APIRouter.php',
    'handlers/BaseHandler.php',
    'handlers/VideoManagementHandler.php'
];

foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    echo "File $file: " . (file_exists($path) ? 'EXISTS' : 'MISSING') . "\n";
}

// Test basic inclusion
echo "\n=== Testing Inclusions ===\n";
try {
    require_once __DIR__ . '/state_manager.php';
    echo "state_manager.php: OK\n";
} catch (Exception $e) {
    echo "state_manager.php: ERROR - " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/APIRouter.php';
    echo "APIRouter.php: OK\n";
} catch (Exception $e) {
    echo "APIRouter.php: ERROR - " . $e->getMessage() . "\n";
}

// Test class instantiation
echo "\n=== Testing Class Instantiation ===\n";
try {
    $router = new APIRouter(__DIR__, 'default');
    echo "APIRouter instantiation: OK\n";
} catch (Exception $e) {
    echo "APIRouter instantiation: ERROR - " . $e->getMessage() . "\n";
}

// Test a simple endpoint
echo "\n=== Testing Endpoint ===\n";
try {
    $_GET['action'] = 'get_current_video';
    $_GET['profile'] = 'default';
    
    ob_start();
    $router = new APIRouter(__DIR__, 'default');
    $router->route('get_current_video');
    $output = ob_get_clean();
    
    echo "get_current_video output: " . $output . "\n";
} catch (Exception $e) {
    echo "get_current_video: ERROR - " . $e->getMessage() . "\n";
} catch (Error $e) {
    echo "get_current_video: FATAL - " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";
?>
