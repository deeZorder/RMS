<?php
/**
 * AdminBootstrap.php
 * Bootstrap file to properly load all admin classes in the correct order
 * This eliminates circular dependency issues and ensures all classes are available
 */

// Prevent multiple inclusions
if (defined('ADMIN_BOOTSTRAP_LOADED')) {
    return;
}
define('ADMIN_BOOTSTRAP_LOADED', true);

// Load classes in strict dependency order to ensure all classes are available
if (!class_exists('AdminConfig')) {
    require_once __DIR__ . '/AdminConfig.php';
}
if (!class_exists('AdminHandlers')) {
    require_once __DIR__ . '/AdminHandlers.php';
}
if (!class_exists('AdminTemplate')) {
    require_once __DIR__ . '/AdminTemplate.php';
}
if (!class_exists('Admin')) {
    require_once __DIR__ . '/Admin.php';
}
