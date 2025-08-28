<?php
// state_manager.php
// Shared state management functions for all RMS PHP files

// State management functions
function getStateFilePath($profileId) {
    $baseDir = __DIR__;
    $dataDir = $baseDir . '/data';
    $profilesDir = $dataDir . '/profiles';
    if (!is_dir($profilesDir)) { @mkdir($profilesDir, 0777, true); }
    $profileDir = $profilesDir . '/' . $profileId;
    if (!is_dir($profileDir)) { @mkdir($profileDir, 0777, true); }
    return $profileDir . '/state.json';
}

function loadState($profileId) {
    $statePath = getStateFilePath($profileId);
    if (!file_exists($statePath)) {
        // Try to migrate old state files first
        if (migrateOldStateFiles($profileId)) {
            // Migration successful, now load the new state
            $content = @file_get_contents($statePath);
            if ($content !== false) {
                $state = json_decode($content, true);
                if (is_array($state)) {
                    return $state;
                }
            }
        }
        
        // Return default state if migration failed or no old files exist
        return [
            'currentVideo' => ['filename' => '', 'dirIndex' => 0],
            'playbackState' => 'stop',
            'volume' => 50,
            'muted' => false,
            'loopMode' => 'off',
            'playAllMode' => 'off',
            'externalAudioMode' => 'off',
            'lastControlChange' => time(),
            'lastConfigCheck' => 0,
            'lastScan' => 0
        ];
    }
    
    $content = @file_get_contents($statePath);
    if ($content === false) {
        // Return default state instead of recursive call
        return [
            'currentVideo' => ['filename' => '', 'dirIndex' => 0],
            'playbackState' => 'stop',
            'volume' => 50,
            'muted' => false,
            'loopMode' => 'off',
            'playAllMode' => 'off',
            'externalAudioMode' => 'off',
            'lastControlChange' => time(),
            'lastConfigCheck' => 0,
            'lastScan' => 0
        ];
    }
    
    $state = json_decode($content, true);
    if (!is_array($state)) {
        // Return default state instead of recursive call
        return [
            'currentVideo' => ['filename' => '', 'dirIndex' => 0],
            'playbackState' => 'stop',
            'volume' => 50,
            'muted' => false,
            'loopMode' => 'off',
            'playAllMode' => 'off',
            'externalAudioMode' => 'off',
            'lastControlChange' => time(),
            'lastConfigCheck' => 0,
            'lastScan' => 0
        ];
    }
    
    // Ensure all required fields exist
    $defaults = [
        'currentVideo' => ['filename' => '', 'dirIndex' => 0],
        'playbackState' => 'stop',
        'volume' => 50,
        'muted' => false,
        'loopMode' => 'off',
        'playAllMode' => 'off',
        'externalAudioMode' => 'off',
        'lastControlChange' => time(),
        'lastConfigCheck' => 0,
        'lastScan' => 0
    ];

    $state = array_merge($defaults, $state);

    // Validate state consistency
    $state = validateStateConsistency($state);

    return $state;
}

function saveState($profileId, $state) {
    $statePath = getStateFilePath($profileId);
    // Removed automatic lastControlChange update - not needed
    return safeFileWrite($statePath, json_encode($state, JSON_PRETTY_PRINT));
}

function updateState($profileId, $updates) {
    error_log("StateManager: updateState called with: $profileId, updates: " . json_encode($updates));
    $state = loadState($profileId);
    error_log("StateManager: current state before update: " . json_encode($state));
    $state = array_merge($state, $updates);
    error_log("StateManager: state after merge: " . json_encode($state));
    $result = saveState($profileId, $state);
    error_log("StateManager: updateState result: " . ($result ? 'success' : 'failed'));
    return $result;
}

function triggerDashboardRefresh($profileId) {
    // Modern approach: Update state with a refresh timestamp
    // Dashboards can poll for this change to trigger refreshes
    $updates = [
        'lastRefreshTrigger' => time(),
        'refreshRequested' => true
    ];
    return updateState($profileId, $updates);
}

function safeFileWrite($path, $content) {
    try {
        error_log("StateManager: safeFileWrite called with path: $path");
        $tempPath = $path . '.tmp';
        error_log("StateManager: writing to temp path: $tempPath");
        if (file_put_contents($tempPath, $content) === false) {
            error_log("StateManager: failed to write to temp file");
            return false;
        }
        error_log("StateManager: renaming temp file to final path");
        $result = rename($tempPath, $path);
        error_log("StateManager: rename result: " . ($result ? 'success' : 'failed'));
        return $result;
    } catch (Exception $e) {
        error_log('File write error: ' . $e->getMessage());
        return false;
    }
}

function migrateOldStateFiles($profileId) {
    $baseDir = __DIR__;
    $dataDir = $baseDir . '/data';
    $profilesDir = $dataDir . '/profiles';
    $profileDir = $profilesDir . '/' . $profileId;
    
    if (!is_dir($profileDir)) {
        return false;
    }
    
    $state = [
        'currentVideo' => ['filename' => '', 'dirIndex' => 0],
        'playbackState' => 'stop',
        'volume' => 50,
        'muted' => false,
        'loopMode' => 'off',
        'playAllMode' => 'off',
        'externalAudioMode' => 'off',
        'lastControlChange' => time(),
        'lastConfigCheck' => 0,
        'lastScan' => 0
    ];
    
    // Migrate current video
    $currentVideoPath = $profileDir . '/current_video.txt';
    if (file_exists($currentVideoPath)) {
        $content = trim(file_get_contents($currentVideoPath));
        if ($content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded) && isset($decoded['filename'])) {
                $state['currentVideo'] = $decoded;
            } else {
                $state['currentVideo'] = ['filename' => $content, 'dirIndex' => 0];
            }
        }
    }
    
    // Migrate playback state
    $playbackStatePath = $profileDir . '/playback_state.txt';
    if (file_exists($playbackStatePath)) {
        $state['playbackState'] = trim(file_get_contents($playbackStatePath)) ?: 'stop';
    }
    
    // Migrate volume
    $volumePath = $profileDir . '/volume.txt';
    if (file_exists($volumePath)) {
        $state['volume'] = (int)file_get_contents($volumePath) ?: 50;
    }
    
    // Migrate mute state
    $muteStatePath = $profileDir . '/mute_state.txt';
    if (file_exists($muteStatePath)) {
        $state['muted'] = (trim(file_get_contents($muteStatePath)) === 'muted');
    }
    
    // Migrate loop mode
    $loopModePath = $profileDir . '/loop_mode.txt';
    if (file_exists($loopModePath)) {
        $state['loopMode'] = trim(file_get_contents($loopModePath)) ?: 'off';
    }
    
    // Migrate play all mode
    $playAllModePath = $profileDir . '/play_all_mode.txt';
    if (file_exists($playAllModePath)) {
        $state['playAllMode'] = trim(file_get_contents($playAllModePath)) ?: 'off';
    }
    
    // Migrate external audio mode
    $externalAudioModePath = $profileDir . '/external_audio_mode.txt';
    if (file_exists($externalAudioModePath)) {
        $state['externalAudioMode'] = trim(file_get_contents($externalAudioModePath)) ?: 'off';
    }
    
    // Migrate timestamps
    $lastControlChangePath = $profileDir . '/last_control_change.txt';
    if (file_exists($lastControlChangePath)) {
        $state['lastControlChange'] = (int)file_get_contents($lastControlChangePath) ?: time();
    }
    
    $lastConfigCheckPath = $profileDir . '/last_config_check.txt';
    if (file_exists($lastConfigCheckPath)) {
        $state['lastConfigCheck'] = (int)file_get_contents($lastConfigCheckPath) ?: 0;
    }
    
    $lastScanPath = $profileDir . '/last_scan.txt';
    if (file_exists($lastScanPath)) {
        $state['lastScan'] = (int)file_get_contents($lastScanPath) ?: 0;
    }
    
    // Save the migrated state
    return saveState($profileId, $state);
}

// Helper function to get current video data
function getCurrentVideoForProfile($profileId) {
    $state = loadState($profileId);
    return $state['currentVideo'];
}

// Helper function to set current video
function setCurrentVideoForProfile($profileId, $filename, $dirIndex) {
    error_log("StateManager: setCurrentVideoForProfile called with: $profileId, $filename, $dirIndex");

    $updates = [
        'currentVideo' => ['filename' => $filename, 'dirIndex' => $dirIndex]
    ];

    // If clearing video (empty filename), also stop playback
    if (empty($filename)) {
        $updates['playbackState'] = 'stop';
        error_log("StateManager: Clearing video, setting playback to stop");
    }

    $result = updateState($profileId, $updates);
    error_log("StateManager: setCurrentVideoForProfile result: " . ($result ? 'success' : 'failed'));
    return $result;
}

// Helper function to clear current video
function clearCurrentVideo($profileId) {
    $updates = [
        'currentVideo' => ['filename' => '', 'dirIndex' => 0],
        'playbackState' => 'stop'
    ];

    error_log("StateManager: Clearing current video and setting playback to stop");
    return updateState($profileId, $updates);
}

// Helper function to get playback state
function getPlaybackState($profileId) {
    $state = loadState($profileId);
    return $state['playbackState'];
}

// Helper function to set playback state
function setPlaybackState($profileId, $state) {
    // Validate state consistency - if no video is selected, can't be playing
    $currentVideo = getCurrentVideoForProfile($profileId);
    if (empty($currentVideo['filename']) && $state === 'play') {
        error_log("StateManager: Preventing play state with no video selected, setting to stop");
        $state = 'stop';
    }
    return updateState($profileId, ['playbackState' => $state]);
}

// Helper function to get volume
function getVolume($profileId) {
    $state = loadState($profileId);
    return $state['volume'];
}

// Helper function to set volume
function setVolume($profileId, $volume) {
    return updateState($profileId, ['volume' => $volume]);
}

// Helper function to get mute state
function getMuteState($profileId) {
    $state = loadState($profileId);
    return $state['muted'];
}

// Helper function to set mute state
function setMuteState($profileId, $muted) {
    return updateState($profileId, ['muted' => $muted]);
}

// Helper function to get loop mode
function getLoopMode($profileId) {
    $state = loadState($profileId);
    return $state['loopMode'];
}

// Helper function to set loop mode
function setLoopMode($profileId, $mode) {
    return updateState($profileId, ['loopMode' => $mode]);
}

// Helper function to get play all mode
function getPlayAllMode($profileId) {
    $state = loadState($profileId);
    return $state['playAllMode'];
}

// Helper function to set play all mode
function setPlayAllMode($profileId, $mode) {
    return updateState($profileId, ['playAllMode' => $mode]);
}

// Helper function to get external audio mode
function getExternalAudioMode($profileId) {
    $state = loadState($profileId);
    return $state['externalAudioMode'];
}

// Helper function to set external audio mode
function setExternalAudioMode($profileId, $mode) {
    return updateState($profileId, ['externalAudioMode' => $mode]);
}

// Helper function to update last scan time
function updateLastScan($profileId) {
    return updateState($profileId, ['lastScan' => time()]);
}

// Helper function to get last scan time
function getLastScan($profileId) {
    $state = loadState($profileId);
    return isset($state['lastScan']) ? $state['lastScan'] : 0;
}

// Helper function to update last config check time
function updateLastConfigCheck($profileId) {
    return updateState($profileId, ['lastConfigCheck' => time()]);
}

// Helper function to get last config check time
function getLastConfigCheck($profileId) {
    $state = loadState($profileId);
    return $state['lastConfigCheck'];
}

// Function to validate and fix state consistency
function validateStateConsistency($state) {
    // If no video is selected, playback state should not be 'play'
    if (empty($state['currentVideo']['filename']) && $state['playbackState'] === 'play') {
        error_log("StateManager: Fixing inconsistent state - no video selected but playbackState is 'play', setting to 'stop'");
        $state['playbackState'] = 'stop';
    }

    // Handle duplicate/conflicting fields (legacy support)
    if (isset($state['loop']) && isset($state['loopMode'])) {
        if ($state['loop'] !== $state['loopMode']) {
            error_log("StateManager: Conflicting loop values detected, using loopMode: " . $state['loopMode']);
        }
        unset($state['loop']); // Remove duplicate field
    } elseif (isset($state['loop']) && !isset($state['loopMode'])) {
        $state['loopMode'] = $state['loop'];
        unset($state['loop']);
    }

    if (isset($state['play_all']) && isset($state['playAllMode'])) {
        if ($state['play_all'] !== $state['playAllMode']) {
            error_log("StateManager: Conflicting play_all values detected, using playAllMode: " . $state['playAllMode']);
        }
        unset($state['play_all']); // Remove duplicate field
    } elseif (isset($state['play_all']) && !isset($state['playAllMode'])) {
        $state['playAllMode'] = $state['play_all'];
        unset($state['play_all']);
    }

    return $state;
}

// Removed refresh helper functions - not needed
?>
