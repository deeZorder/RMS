# RMS Distributed Setup - Issue Diagnosis & Fix

## Current Setup Analysis
- **Windows Server (grksv641)**: Running admin.php via XAMPP ✅
- **Tablet**: Accessing dashboard.php via network ❓
- **Philips Monitor**: Accessing screen.php via network ❓

## Identified Issues

### 1. **Mixed Path Separators**
Your system uses mixed Windows (`\`) and Unix (`/`) path separators:
- Current paths: `\\GRKSV641\C$\xampp\htdocs\RMS/videos`
- This can cause issues on different platforms

### 2. **Network Access URLs**
Devices should access:
- Dashboard: `http://grksv641/RMS/dashboard.php`
- Screen: `http://grksv641/RMS/screen.php`
- API: `http://grksv641/RMS/api.php`

### 3. **Video Serving**
Videos are served via: `http://grksv641/RMS/video.php?file=filename.mp4`

## Testing Steps for Each Device

### Test on Tablet (dashboard.php)
1. Open browser and go to: `http://grksv641/RMS/dashboard.php`
2. Check browser console for errors (F12)
3. Verify API calls are working

### Test on Philips Monitor (screen.php)  
1. Open browser and go to: `http://grksv641/RMS/screen.php`
2. Check if videos load and play
3. Verify API connectivity

### Test API Connectivity
Try these URLs from each device:
- `http://grksv641/RMS/api.php?action=health`
- `http://grksv641/RMS/api.php?action=get_all_videos&page=1&limit=5`

## Potential Fixes Needed

### If Network Access Fails:
1. **Firewall Issues**: Windows firewall might block HTTP requests
2. **Apache Configuration**: Virtual hosts or .htaccess issues
3. **DNS Resolution**: Devices can't resolve 'grksv641' hostname

### If Video Playback Fails:
1. **MIME Types**: Apache might not serve video files correctly
2. **File Permissions**: Videos folder permissions
3. **Path Resolution**: Cross-platform path issues

## Recommended Configuration Updates

### Update config.json for better cross-platform support:
```json
{
    "directory": "videos",
    "directories": ["videos"],
    "rows": 2,
    "clipsPerRow": 4,
    "dashboardBackground": "assets/backgrounds/silver.jpg",
    "serverUrl": "http://grksv641/RMS",
    "enableCORS": true
}
```

### Add CORS headers for cross-device access in api.php:
```php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
```

## Next Steps
1. Test each device individually with the URLs above
2. Check browser console errors on tablet/monitor
3. Verify network connectivity between devices
4. Check Windows firewall settings on server
5. Verify Apache is serving files correctly

## Quick Network Test
From tablet/monitor browser, try:
- `http://grksv641/RMS/` (should show index page)
- `http://grksv641/RMS/api.php?action=health` (should return JSON)
