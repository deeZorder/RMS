<?php

require_once __DIR__ . '/../state_manager.php';

abstract class BaseHandler {
    protected $baseDir;
    protected $profileId;
    protected $config;
    
    protected function loadConfig(): void {
        $configPath = $this->baseDir . '/config.json';
        $this->config = file_exists($configPath) ? 
            (json_decode(file_get_contents($configPath), true) ?: []) : [];
    }
    
    public function __construct(string $baseDir, string $profileId) {
        $this->baseDir = $baseDir;
        $this->profileId = $profileId;
        $this->loadConfig();
    }
    
    protected function validatePostRequest(): bool {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return false;
        }
        return true;
    }
    
    protected function validateFilename(string $filename): bool {
        return !empty($filename) && 
               strpos($filename, '..') === false && 
               strpos($filename, '/') === false && 
               strpos($filename, '\\') === false;
    }
    
    protected function getConfiguredDirectories(): array {
        $dirs = [];
        if (!empty($this->config['directories']) && is_array($this->config['directories'])) {
            $dirs = $this->config['directories'];
        } elseif (!empty($this->config['directory'])) {
            $dirs = [$this->config['directory']];
        } else {
            $dirs = ['videos'];
        }
        
        $normalized = [];
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $normalized[] = $dir;
            } elseif (is_dir($this->baseDir . '/' . $dir)) {
                $normalized[] = $this->baseDir . '/' . $dir;
            }
        }
        return $normalized;
    }
    
    abstract public function handle(string $action): void;
}
