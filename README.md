# Relax Media System (RMS)

RMS is a lightweight PHP/HTML/JS system for curated video playback in comfort rooms and digital signage. It provides a tablet‑friendly dashboard for selecting content, a TV/browser “screen” for playback, and an admin panel for configuration and bulk tasks.

## Overview

- Dashboard: browsable carousel with play/pause/stop, Loop, and Play‑All controls
- Screen: fullscreen player that syncs with dashboard state and advances on Play‑All
- Admin: directory selection, titles, previews/thumbnails, refresh actions, and profiles

## Core Apps

### Dashboard (`dashboard.php`)
- Responsive, touch‑friendly carousel with lazy thumbnails and balanced rows
- Playback controls: Play, Pause, Stop, Loop, Play All
- Auto refresh when admin makes changes (via refresh signal)

### Screen (`screen.php`)
- Fullscreen playback for TV/monitor browsers (controls hidden by default)
- Honors Loop and Play‑All on end; uses next‑video ordering from saved profile
- Resilient autoplay logic and ARIA fixes for built‑in video menus

### Admin (`admin.php`)
- Configure directories and profiles; manage titles and order
- System Actions: Refresh Dashboards, Refresh Screens, generate thumbnails/previews
- Per‑profile “Refresh Screen” buttons in Screen Management

## Architecture

- State: `data/profiles/<profile>/state.json` (current video, playback, modes, refresh flags)
- Order: `data/profiles/<profile>/video_order.json` (dirPath|filename list)
- API: `api.php` provides a unified set of endpoints. Router class `APIRouter.php` and `handlers/` support a modular path as well.
- Refresh: Admin sets `refreshRequested=true` with a recent `lastRefreshTrigger`. Dashboard/screen poll `api.php?action=check_refresh_signal` and auto‑reload when set. Legacy `dashboard_refresh.txt` files are still supported.

## 📁 File Structure

```text
RMS/
├── admin/                          # Admin UI (PHP + JS)
│   ├── Admin.php                   # Admin page renderer and sections
│   ├── AdminBootstrap.php          # Bootstraps the admin system
│   ├── AdminConfig.php             # Reads/writes config, dashboards, screens
│   ├── AdminHandlers.php           # Processes admin POST actions
│   ├── AdminTemplate.php           # Shared admin HTML/CSS template helpers
│   └── admin.js                    # Admin client-side logic (navigation, modals)
│
├── handlers/                       # API handler classes (modular routing)
│   ├── BaseHandler.php             # Base class shared by handlers
│   ├── SystemHandler.php           # Health, state, refresh-signal checks
│   ├── VideoControlHandler.php     # Play/Pause/Stop, volume, loop/play-all
│   ├── VideoManagementHandler.php  # Current/next video selection
│   ├── VideoLibraryHandler.php     # Listing, titles, moves
│   ├── AdminHandler.php            # Admin batch ops (warming, browse)
│   └── BatchHandler.php            # Batched state queries for UI
│
├── assets/
│   └── style.css                   # Shared styling for dashboard/admin
│
├── data/                           # Persistent app data and caches
│   ├── admin_cache.json            # Admin scan cache (directories/videos)
│   ├── dashboards.json             # Dashboard profiles (rows, clipsPerRow, bg)
│   ├── screens.json                # Registered screens with profile mapping
│   ├── profiles/
│   │   └── <profile>/              # Per-profile state and order
│   │       ├── state.json          # Live state (currentVideo, playback, flags)
│   │       ├── video_order.json    # Saved order (dirPath|filename array)
│   │       ├── titles.json         # Optional per-profile custom titles
│   │       └── dashboard.log       # Optional diagnostics
│   ├── thumbs/                     # Generated thumbnail JPEGs (hashed)
│   └── previews/                   # Generated preview MP4s (hashed)
│
├── videos/                         # Your video directories (configurable)
│
├── .htaccess                       # Cache headers (static vs dynamic), gzip
├── APIRouter.php                   # Routes api.php actions to handlers
├── admin.php                       # Admin entry point (loads Admin class)
├── api.php                         # Unified API (legacy + new endpoints)
├── dashboard.php                   # Video selection carousel interface
├── header.php                      # Shared header included by admin
├── index.php                       # Optional redirect/landing
├── preview.php                     # Preview serving (generated clips)
├── screen.php                      # Fullscreen player (TV/monitor)
├── state_direct.php                # Direct state JSON endpoint (fallback)
├── state_manager.php               # State helpers (load/save/update)
├── thumb.php                       # On-demand thumbnail generation/serve
├── video.php                       # Secure video file serving endpoint
└── config.json                     # Base configuration
```

## Installation

1. Copy `RMS/` into your web root (e.g., `htdocs`) and start Apache/PHP
2. Open Admin and select your video directory/directories
3. Optionally generate thumbnails/previews via System Actions

URLs:
- Admin: `http://localhost/RMS/admin.php`
- Dashboard: `http://localhost/RMS/dashboard.php`
- Screen: `http://localhost/RMS/screen.php` (use `?controls=1` to show controls)

## Usage

- From the dashboard, select and play videos; toggle Loop/Play‑All as needed
- On the screen, videos loop or advance automatically based on modes
- Use Admin → System Actions to “Refresh Dashboards” or “Refresh Screens” after changes
- Use Admin → Screen Management → “Refresh Screen” to target a single profile

## API (quick reference)

- Read: `get_current_video`, `get_playback_state`, `get_loop_mode`, `get_play_all_mode`, `get_next_video`
- Write: `set_current_video`, `play_video`, `pause_video`, `stop_video`, `set_loop_mode`, `set_play_all_mode`
- System: `check_refresh_signal`, `check_config_changes`

## Caching & Headers

- Dynamic pages: `Cache-Control: no-store, no-cache, must-revalidate` (avoid stale UI)
- Static assets: long‑lived cache via `.htaccess`
- Consider self‑hosting third‑party libraries if you need local cache headers

## Accessibility

- Video.js menus receive ARIA roles at runtime on `screen.php` to satisfy validators
- Screen controls are hidden by default for signage; enable per device with `?controls=1`

## Troubleshooting

- JSON parse errors in console: ensure endpoints return clean JSON (no BOM, no stray `?>`); visit the endpoint URL directly to verify
- “Next video” not advancing: confirm Play‑All is on and `get_next_video` returns a result; verify `video_order.json` exists for the profile
- Thumbnails not showing: ensure FFmpeg is installed and accessible; re‑generate via Admin
- Icons appear garbled: server/editor encoding—Admin normalizes key labels at runtime; ensure UTF‑8 everywhere

## Requirements

- PHP 7.3+ (7.4+ recommended), Apache 2.4+
- File read permission for video directories; write permission for `data/`
- Optional FFmpeg for thumbnails and previews

## Recent Updates (highlights)

- Unified refresh via state flags; legacy file fallback
- Per‑profile refresh buttons and global screen refresh
- Loop/Play‑All respected by `screen.php` on video end
- Added `get_next_video`; hardened `check_refresh_signal`
- Fixed JSON issues (removed BOM/stray tag); added cache headers and `.htaccess`

