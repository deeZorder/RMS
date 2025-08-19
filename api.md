# RMS API Documentation

## Overview

The Relax Media System (RMS) API has been refactored from a monolithic 890-line file into a clean, modular architecture. This document describes the new structure and available endpoints.

## Architecture

### Before Refactoring
- **Original**: Single `api.php` file with 890 lines
- **Problem**: PHP6613 error due to type inference overload
- **Issues**: Difficult to maintain, poor separation of concerns

### After Refactoring
The API is now organized into focused, specialized components:

```
RMS/
├── api.php                 # Main entry point (45 lines)
├── APIRouter.php          # Request routing logic
├── state_manager.php      # Shared state management
└── handlers/              # Specialized handler classes
    ├── BaseHandler.php           # Common functionality
    ├── VideoControlHandler.php   # Playback controls
    ├── VideoManagementHandler.php # Video selection/management
    ├── VideoLibraryHandler.php   # Video library operations
    ├── SystemHandler.php         # System utilities
    └── AdminHandler.php          # Admin/maintenance tools
```

## Handler Classes

### BaseHandler
**File**: `handlers/BaseHandler.php`
**Purpose**: Abstract base class providing common functionality for all handlers.

**Features**:
- Profile ID management
- Configuration loading
- Common validation methods
- Directory configuration utilities

### VideoControlHandler
**File**: `handlers/VideoControlHandler.php`
**Purpose**: Handles video playback control operations.

**Endpoints**:
- `play_video` - Start video playback
- `pause_video` - Pause current video
- `stop_video` - Stop video playback
- `get_playback_state` - Get current playback state
- `set_volume` - Set volume level (0-100)
- `get_volume` - Get current volume
- `toggle_mute` - Toggle mute state
- `get_mute_state` - Get current mute state
- `set_loop_mode` - Enable/disable loop mode
- `get_loop_mode` - Get loop mode status
- `set_play_all_mode` - Enable/disable play all mode
- `get_play_all_mode` - Get play all mode status
- `set_external_audio_mode` - Enable/disable external audio
- `get_external_audio_mode` - Get external audio mode status

### VideoManagementHandler
**File**: `handlers/VideoManagementHandler.php`
**Purpose**: Manages current video selection and navigation.

**Endpoints**:
- `get_current_video` - Get currently selected video
- `set_current_video` - Set the current video
- `clear_current_video` - Clear current video selection
- `get_next_video` - Get next video in playlist order

### VideoLibraryHandler
**File**: `handlers/VideoLibraryHandler.php`
**Purpose**: Handles video library operations and metadata.

**Endpoints**:
- `get_all_videos` - Get paginated list of all videos
- `get_video_count` - Get total number of videos
- `move_video` - Move video up/down in playlist order
- `get_video_titles` - Get custom video titles
- `set_video_title` - Set custom title for a video

**Enhanced Features**:
- **Balanced Distribution**: Videos are distributed evenly across dashboard rows rather than sequentially
- **Interactive Management**: Admin panel provides carousel preview for direct title editing and reordering
- **Error Resilience**: Improved error handling for empty or malformed API responses

### SystemHandler
**File**: `handlers/SystemHandler.php`
**Purpose**: System utilities and configuration management.

**Endpoints**:
- `health` - API health check
- `check_config_changes` - Check for configuration file changes
- `check_changes` - Check for system state changes
- `get_config` - Get current configuration
- `migrate_state` - Migrate old state files
- `get_state` - Get current system state

### AdminHandler
**File**: `handlers/AdminHandler.php`
**Purpose**: Administrative and maintenance operations.

**Endpoints**:
- `warm_thumbnails` - Generate video thumbnails in batches
- `check_ffmpeg` - Check FFmpeg availability and version
- `browse_directories` - Browse filesystem directories

**Note**: The admin panel (`admin.php`) includes a visual carousel preview that uses the same balanced distribution algorithm as the dashboard, providing accurate layout representation for video management.

### BatchHandler
**File**: `handlers/BatchHandler.php`
**Purpose**: Batch operations to reduce API call overhead.

**Endpoints**:
- `get_dashboard_state` - Get all dashboard initialization state in one call
- `get_control_state` - Get all control states and titles in one call

### DebugHandler
**File**: `handlers/DebugHandler.php`
**Purpose**: Debugging and maintenance utilities.

**Endpoints**:
- `debug_api` - Get system diagnostics and API status
- `clear_cache` - Clear all cached state files (POST only)

## API Usage

### Basic Request Format
```
GET/POST /RMS/api.php?action={endpoint_name}[&profile={profile_id}]
```

### Profile Support
The API supports multiple dashboard profiles:
- Default profile: `default`
- Numbered profiles: `dashboard1`, `dashboard2`, etc.
- Profile can be specified via:
  - `?profile=profile_name`
  - `?d=0` (default) or `?d=1` (dashboard1), etc.

### Example Requests

#### Health Check
```bash
curl "http://localhost/RMS/api.php?action=health"
```

#### Play Video
```bash
curl -X POST "http://localhost/RMS/api.php?action=play_video&profile=default"
```

#### Set Current Video
```bash
curl -X POST \
  "http://localhost/RMS/api.php?action=set_current_video" \
  -d "filename=video.mp4&dirIndex=0&profile=default"
```

#### Get All Videos (Paginated)
```bash
curl "http://localhost/RMS/api.php?action=get_all_videos&page=1&limit=50&profile=default"
```

#### Get Dashboard State (Batch)
```bash
curl "http://localhost/RMS/api.php?action=get_dashboard_state&profile=default"
```

#### Get Control State (Batch)
```bash
curl "http://localhost/RMS/api.php?action=get_control_state&profile=default"
```

#### Debug API Status
```bash
curl "http://localhost/RMS/api.php?action=debug_api&profile=default"
```

#### Clear Cache (POST)
```bash
curl -X POST "http://localhost/RMS/api.php?action=clear_cache&profile=default"
```

## Response Format

All API responses are in JSON format:

### Success Response
```json
{
  "status": "ok",
  "data": "...",
  "timestamp": 1234567890
}
```

### Error Response
```json
{
  "error": "Error message",
  "code": 400
}
```

## Security Features

- Input validation and sanitization
- Path traversal protection
- Method validation (GET/POST restrictions)
- File type restrictions for video operations
- Directory access controls

## Configuration

The API reads configuration from `config.json`:

```json
{
  "directories": [
    "/path/to/videos1",
    "/path/to/videos2"
  ],
  "other_settings": "..."
}
```

## State Management

Each profile maintains its own state including:
- Current video selection
- Playback state (play/pause/stop)
- Volume and mute settings
- Loop and play-all modes
- Video ordering preferences

State is persisted in `data/profiles/{profile_id}/` directory.

## Benefits of New Architecture

1. **Resolved PHP6613 Error**: Modular structure prevents type inference overload
2. **Improved Maintainability**: Clear separation of concerns
3. **Better Testing**: Each handler can be tested independently
4. **Scalability**: Easy to add new endpoints or modify existing ones
5. **Code Reusability**: Common functionality in BaseHandler
6. **Type Safety**: Proper PHP class structure with inheritance
7. **Performance Optimization**: Batch endpoints reduce API call overhead
8. **Enhanced User Experience**: Visual admin interface with accurate layout preview
9. **Balanced Distribution**: Improved video layout algorithm for better visual presentation
10. **Error Resilience**: Robust error handling prevents UI failures from API issues

## Performance Optimizations

### Batch API Endpoints

To address dashboard performance issues caused by multiple API calls, batch endpoints have been introduced:

**`get_dashboard_state`** - Returns all initial state needed by dashboard:
```json
{
  "current_video": {"filename": "video.mp4", "dirIndex": 0},
  "playback_state": "play",
  "volume": 75,
  "muted": false,
  "loop_mode": "off",
  "play_all_mode": "on",
  "external_audio_mode": "off",
  "timestamp": 1234567890
}
```

**`get_control_state`** - Returns complete control state with titles:
```json
{
  "current_video": {"filename": "video.mp4", "dirIndex": 0},
  "playback_state": "play",
  "volume": 75,
  "muted": false,
  "loop_mode": "off", 
  "play_all_mode": "on",
  "external_audio_mode": "off",
  "video_titles": {"0|video.mp4": "Custom Title"},
  "state_hash": "abc123...",
  "last_changes": {
    "scan": 1234567890,
    "config": 1234567890,
    "controls": 1234567890
  },
  "timestamp": 1234567890
}
```

### Usage Recommendations

- Use `get_dashboard_state` for initial page load
- Use `get_control_state` for periodic updates (includes change detection via state_hash)
- Individual endpoints remain available for specific actions
- Batch endpoints significantly reduce HTTP request overhead

## Development Guidelines

### Adding New Endpoints

1. Identify the appropriate handler class
2. Add the endpoint to the handler's `handle()` method
3. Create a private method for the endpoint logic
4. Update the router's `$routes` array in `APIRouter.php`
5. Update this documentation

### Creating New Handlers

1. Extend `BaseHandler`
2. Implement the `handle(string $action): void` method
3. Add handler to the router
4. Include in autoloading

### Best Practices

- Keep handler methods focused and single-purpose
- Use the base class validation methods
- Follow existing error handling patterns
- Maintain backward compatibility
- Document new endpoints in this file
