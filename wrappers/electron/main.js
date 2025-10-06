const { app, BrowserWindow, globalShortcut } = require('electron');

// Ensure autoplay with audio without user gesture
app.commandLine.appendSwitch('autoplay-policy', 'no-user-gesture-required');
// Avoid media session hijacking and key handling quirks
app.commandLine.appendSwitch('disable-features', 'HardwareMediaKeyHandling,MediaSessionService');

function createWindow() {
  const targetUrl = process.env.RMS_URL || 'http://localhost/RMS/screen_native_wrapper.php?profile=default&audio=1';

  const win = new BrowserWindow({
    width: 1920,
    height: 1080,
    backgroundColor: '#000000',
    fullscreen: true,
    autoHideMenuBar: true,
    webPreferences: {
      nodeIntegration: false,
      contextIsolation: true,
      autoplayPolicy: 'no-user-gesture-required',
      backgroundThrottling: false
    }
  });

  win.webContents.on('did-finish-load', () => {
    try { win.webContents.setAudioMuted(false); } catch (_) {}
    // Best-effort unmute + play inside the page
    win.webContents.executeJavaScript(`
      try {
        const video = document.querySelector('video');
        if (video) {
          video.muted = false;
          try { video.removeAttribute('muted'); } catch(_) {}
          try { video.volume = 0.5; } catch(_) {}
          video.play().catch(()=>{});
        }
      } catch(_) {}
    `).catch(() => {});
  });

  win.loadURL(targetUrl);

  // Convenience shortcuts
  globalShortcut.register('Ctrl+R', () => win.reload());
  globalShortcut.register('Ctrl+Shift+I', () => win.webContents.openDevTools({ mode: 'detach' }));
}

app.whenReady().then(createWindow);
app.on('window-all-closed', () => app.quit());


