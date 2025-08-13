# Relax Media System (RMS)

A professional multi-page PHP/HTML/JS/CSS multimedia system designed for comfort rooms and digital signage. This system provides an intuitive interface for managing and playing video collections of any size, from small personal libraries to massive collections of 30,000+ videos.

## Core Components

### 🎮 Dashboard (dashboard.php)
- **Responsive carousel interface** with drag-and-drop navigation
- **Lazy-loaded thumbnails** for optimal performance with large collections
- **Smart loading queue** (3 videos at a time) to prevent browser overload
- **Touch-friendly controls** with momentum scrolling
- **Real-time video controls**: Play, Pause, Stop, Loop, Play All
- **Custom video titles** displayed instead of filenames
- **Visual progress indicator** for loading large collections (10+ videos)

### 📺 Screen (screen.php) 
- **Auto-fullscreen playback** optimized for TV browsers and digital signage
- **Aggressive fullscreen detection** with multiple browser API support
- **Automatic video cycling** with "Play All" mode
- **Cross-platform video serving** through secure PHP endpoint
- **Real-time state synchronization** with dashboard controls
- **Controls hidden by default** (no native UI). To show controls on a specific screen, open `screen.php?controls=1`. To force-hide, use `?nocontrols=1`.

### ⚙️ Admin (admin.php)
- **System-wide directory browsing** - select folders from any location/drive
- **Pagination system** handles massive video collections (30,000+ videos)
- **Custom video title management** with thumbnail previews
- **Responsive design** optimized for mobile, tablet, and desktop
- **Toggle controls** for video thumbnails and information density
- **Automatic dashboard refresh on save** for multi-device setups
- **System Actions**: "Refresh Dashboards" and "Clear Thumbnails & Titles" buttons
- **Thumbnail warm-up**: pre-generates thumbs in batches after scans/changes

## Advanced Features

### 📊 Performance Optimizations
- **Pagination**: Loads 50 videos per page instead of entire collection
- **Lazy Loading**: Videos load only when visible in viewport
- **Debounced Loading**: Prevents browser overload with large collections
- **Smart Caching**: Efficient memory usage regardless of collection size
- **Progress Tracking**: Visual indicators for loading status

### 🎛️ Enhanced Controls
- **Loop Mode**: Individual video repeat functionality
- **Play All Mode**: Automatic playlist cycling through entire collection
- **Smart Loop Logic**: When both modes enabled, cycles through all videos then loops
- **Drag Navigation**: Mouse and touch support for carousel scrolling
- **Momentum Scrolling**: Natural feel drag interactions

### 🌐 Cross-Platform Compatibility
- **Windows Path Support**: Handles backslashes and drive letters correctly
- **Flexible Directory Selection**: Browse any system location, not just project folders
- **Universal Video Serving**: Secure PHP endpoint serves videos from any directory
- **Mobile Responsive**: Touch-optimized interface for all device sizes

### 🔄 Real-Time Communication
- **Multi-Device Sync**: Dashboard auto-refreshes when you save changes in Admin
- **Live State Updates**: Real-time playback state synchronization
- **Persistent Settings**: All configurations saved and restored automatically

## 🚀 Quick Start

### Installation
1. **Extract** the `RMS` folder to your LAMP/XAMPP `htdocs` directory
2. **Start** your web server (Apache + PHP)
3. **Navigate** to the admin page to configure your video directory

### URLs
- **Dashboard**: `http://localhost/RMS/dashboard.php` - Video selection interface
- **Screen**: `http://localhost/RMS/screen.php` - Fullscreen player for TV/monitor (controls hidden by default)
- **Admin**: `http://localhost/RMS/admin.php` - Configuration and management

### Basic Setup
1. **Open Admin Page**: Configure your video directory location
2. **Browse Directories**: Select any folder on your system (local drives, network drives)
3. **Customize Titles**: Edit video display names and toggle thumbnail visibility
4. **Test Dashboard**: Verify videos load and playback controls work
5. **Setup Screen**: Open on secondary display for fullscreen playback

## 💡 Usage Tips

### For Large Collections (1000+ videos)
- **Pagination automatically activates** for collections over 50 videos
- **Use page navigation** or "Jump to page" for quick browsing
- **Toggle thumbnails off** in admin for faster loading when editing titles
- **Search functionality** works across all paginated results

### Multi-Device Setup
1. **Admin Device**: Laptop/tablet running `admin.php` for control
2. **Selection Device**: Touch display running `dashboard.php` for user interaction
3. **Playback Device**: TV/monitor running `screen.php` for fullscreen viewing
4. **Sync**: The dashboard updates automatically within ~3 seconds after you save changes in Admin

### Supported Video Formats
- **Primary**: MP4, WebM, OGG, MOV
- **Extended**: AVI, MKV (browser dependent)
- **Optimization**: H.264 MP4 recommended for best compatibility

## 📁 File Structure

```
RMS/
├── dashboard.php             # Video selection carousel interface
├── screen.php                # Fullscreen video player (controls hidden by default)
├── admin.php                 # Configuration and management panel
├── api.php                   # REST endpoints for inter-page communication
├── video.php                 # Secure video file serving endpoint
├── thumb.php                 # On-demand thumbnail generation/serving
├── header.php                # Shared navigation component
├── assets/
│   └── style.css             # Responsive UI styling
├── data/
│   ├── admin_cache.json      # Admin-side scan cache
│   ├── video_titles.json     # Custom video title mappings
│   ├── thumbs/               # Generated thumbnails cache
│   ├── profiles/
│   │   └── default/
│   │       ├── current_video.txt
│   │       ├── playback_state.txt
│   │       ├── volume.txt
│   │       ├── mute_state.txt
│   │       ├── loop_mode.txt
│   │       ├── play_all_mode.txt
│   │       ├── dashboard_refresh.txt
│   │       └── video_order.json
│   ├── screens.json          # Screens registry
│   └── dashboards.json       # Dashboard profiles
└── config.json               # Base configuration storage
```

## 🔧 Technical Features

### Performance Optimizations
- **Lazy Loading**: Videos load only when visible
- **Pagination**: Handles collections of any size (tested with 30,000+ videos)
- **Smart Caching**: Minimal memory footprint regardless of collection size
- **Debounced Loading**: Prevents browser overload with batch processing

### Browser Compatibility
- **Desktop**: Chrome, Firefox, Safari, Edge
- **Mobile**: iOS Safari, Chrome Mobile, Samsung Internet
- **TV Browsers**: Optimized for digital signage and embedded browsers
- **Fullscreen**: Multiple API support for maximum compatibility

### Security Features
- **Path Validation**: Prevents directory traversal attacks
- **File Type Filtering**: Only serves approved video formats
- **Secure Endpoints**: All file access through validated PHP endpoints
- **Input Sanitization**: All user inputs properly escaped and validated

### Headers & Encoding
- **JSON APIs** return `Content-Type: application/json; charset=utf-8` for correctness
- **Static assets** (CSS/JS/JSON) are served with UTF‑8 via `.htaccess`
- **CSS** declares `@charset "UTF-8"` as the first line

## 🆘 Troubleshooting

### Performance Issues
- **Slow Loading**: Enable pagination in admin (automatic for 50+ videos)
- **Memory Usage**: Toggle off thumbnails for large collections
- **Browser Freezing**: Reduce videos per page in API settings

### Playback Issues
- **No Fullscreen**: Check browser permissions and TV compatibility mode
- **Videos Not Playing**: Verify file formats and video.php endpoint accessibility
- **Sync Problems**: Ensure the server can write `dashboard_refresh.txt` and that `dashboard.php` is open (it polls every 3s)
 - **Thumbnails Not Generating**: Ensure `ffmpeg` is installed and available in PATH. Use Admin → System Actions → "Clear Thumbnails & Titles", then re-add or rescan directories to warm thumbnails.

### Directory Issues
- **Can't Browse**: Ensure PHP has read permissions for target directories
- **Path Errors**: Use the directory browser instead of manual path entry
- **Network Drives**: Map drives before selection or use UNC paths

## 🔄 Recent Updates

### v2.2 - System Actions & Screen Controls
- Default screen controls hidden; enable per screen with `?controls=1`
- Added System Actions: "Refresh Dashboards" and "Clear Thumbnails & Titles"
- Thumbnail warm-up improved to use latest config; admin cache invalidated on clear

### v2.1 - Admin & Headers
- **Auto dashboard refresh on save** (no manual button needed)
- **Session-based flash messages** next to Save buttons; removed `refreshed=1` query param
- **Externalized inline styles** into `assets/style.css`
- **UTF‑8 everywhere**: JSON APIs send `charset=utf-8`; CSS declares `@charset`; `.htaccess` sets UTF‑8 for static assets

### v2.0 - Performance & Scale
- **Pagination system** for massive video collections
- **Lazy loading** with visual progress indicators
- **Mobile-responsive admin interface**
- **Drag navigation** for carousel scrolling

### v1.5 - Enhanced Controls
- **Play All mode** with smart loop integration
- **Custom video titles** with thumbnail previews
- **Multi-device dashboard refresh**
- **Universal directory browsing**

### v1.0 - Core Features
- **Responsive carousel interface**
- **Automatic fullscreen playback**
- **Real-time control synchronization**
- **Cross-platform compatibility**

---

**System Requirements**: PHP 7.4+, Apache/Nginx, Modern web browser  
**Tested Collections**: Up to 30,000 videos  
**Performance**: Sub-2 second load times on standard hardware