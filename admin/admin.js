/**
 * admin.js
 * JavaScript functionality for the admin interface
 * Compatible with PHP 7.3 environments
 */

document.addEventListener('DOMContentLoaded', function() {
    // Suppress non-critical console output
    (function(){
        try { 
            ['log','debug','info','table'].forEach(function(k) { 
                if (typeof console[k] === 'function') console[k] = function(){}; 
            }); 
        } catch(_) {}
    })();
    
    // Initialize admin functionality
    initializeNavigation();
    initializeDirectoryManagement();
    initializeVideoManagement();
    initializeDashboardControls();
    initializeModals();
    initializeProcessStopping();
    
    // Initialize with URL parameters or default section
    var urlParams = new URLSearchParams(window.location.search);
    var sectionParam = urlParams.get('admin-panel') || urlParams.get('section');
    var initialSection = window.location.hash.slice(1) || sectionParam || 'directory-config';
    showSection(initialSection);
});

// Navigation functionality
function initializeNavigation() {
    var navLinks = document.querySelectorAll('.nav-link');
    var adminSections = document.querySelectorAll('.admin-section');
    
    // Add click event listeners to nav links
    navLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var sectionId = link.getAttribute('data-section');
            showSection(sectionId);
            
            // Update URL hash without scrolling
            history.pushState(null, null, '#' + sectionId);
            
            // Dismiss inline flash when navigating to a different section
            dismissInlineFlash();
        });
    });

    // Handle browser back/forward buttons
    window.addEventListener('popstate', function() {
        var hash = window.location.hash.slice(1);
        if (hash) {
            showSection(hash);
        } else {
            showSection('directory-config');
        }
        dismissInlineFlash();
    });
}

function showSection(sectionId) {
    var navLinks = document.querySelectorAll('.nav-link');
    var adminSections = document.querySelectorAll('.admin-section');
    
    // Hide all sections
    adminSections.forEach(function(section) {
        section.classList.remove('active');
    });
    
    // Remove active class from all nav links
    navLinks.forEach(function(link) {
        link.classList.remove('active');
    });
    
    // Show selected section
    var targetSection = document.getElementById(sectionId);
    if (targetSection) {
        targetSection.classList.add('active');
    }
    
    // Add active class to clicked nav link
    var activeLink = document.querySelector('[data-section="' + sectionId + '"]');
    if (activeLink) {
        activeLink.classList.add('active');
    }
    
    // Update the current section hidden field
    var currentSectionInput = document.getElementById('current_section');
    if (currentSectionInput) {
        currentSectionInput.value = sectionId;
    }
    
    // Also update any other current_section hidden fields in other forms
    document.querySelectorAll('input[name="current_section"]').forEach(function(input) {
        if (!input.hasAttribute('data-fixed')) {
            input.value = sectionId;
        }
    });
}

// Directory Management functionality
function initializeDirectoryManagement() {
    var directoryInput = document.getElementById('directory');
    var addDirectoryBtn = document.getElementById('add-directory-btn');
    var chipsEl = document.getElementById('directories-chips');
    var dirsJsonInput = document.getElementById('directories_json');
    
    if (!directoryInput || !addDirectoryBtn || !chipsEl || !dirsJsonInput) {
        return; // Elements not found, skip initialization
    }
    
    // Parse initial directories from the hidden field
    var selectedDirectories = [];
    try {
        var initialDirs = JSON.parse(dirsJsonInput.value || '[]');
        selectedDirectories = Array.isArray(initialDirs) ? initialDirs : [];
    } catch (e) {
        selectedDirectories = [];
    }

    function renderChips() {
        chipsEl.innerHTML = '';
        
        if (selectedDirectories.length === 0) {
            chipsEl.innerHTML = '<div style="color: #666; font-style: italic; text-align: center; padding: 10px;">No directories added yet</div>';
            return;
        }
        
        selectedDirectories.forEach(function(dir, index) {
            var chip = document.createElement('div');
            chip.className = 'chip';
            chip.draggable = true;
            chip.innerHTML = '<span>' + escapeHtml(dir) + '</span><span class="remove" title="Remove">✕</span>';
            
            // Add remove functionality
            var removeBtn = chip.querySelector('.remove');
            removeBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                selectedDirectories.splice(index, 1);
                updateDirsState();
                renderChips();
            });
            
            // drag reorder
            chip.addEventListener('dragstart', function(e) {
                e.dataTransfer.setData('text/plain', String(index));
            });
            chip.addEventListener('dragover', function(e) { e.preventDefault(); });
            chip.addEventListener('drop', function(e) {
                e.preventDefault();
                var from = parseInt(e.dataTransfer.getData('text/plain'), 10);
                var to = index;
                if (!isNaN(from) && from !== to) {
                    var moved = selectedDirectories.splice(from, 1)[0];
                    selectedDirectories.splice(to, 0, moved);
                    updateDirsState();
                    renderChips();
                }
            });
            chipsEl.appendChild(chip);
        });
        updateDirsState();
    }

    function updateDirsState() {
        dirsJsonInput.value = JSON.stringify(selectedDirectories);
    }

    function showInlineMessage(text, variant) {
        variant = variant || 'success';
        var msg = document.createElement('div');
        msg.className = 'alert ' + variant;
        msg.style.marginTop = '10px';
        msg.textContent = text;
        directoryInput.parentNode.appendChild(msg);
        setTimeout(function() { msg.remove(); }, 2500);
    }

    addDirectoryBtn.addEventListener('click', function() {
        var val = directoryInput.value.trim();
        if (!val) {
            openBrowsePanel();
            showInlineMessage('Browse opened. Pick a folder to add.', 'success');
            return;
        }
        if (selectedDirectories.indexOf(val) !== -1) {
            showInlineMessage('That folder is already added.', 'error');
            return;
        }
        
        selectedDirectories.push(val);
        renderChips();
        directoryInput.value = '';
        showInlineMessage('✅ Added: ' + val, 'success');
        
        // Pulse the newest chip
        var lastChip = chipsEl.lastElementChild;
        if (lastChip) {
            lastChip.classList.add('pulse');
            setTimeout(function() { lastChip.classList.remove('pulse'); }, 1200);
        }
    });

    // Press Enter in the input to add quickly
    directoryInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addDirectoryBtn.click();
        }
    });

    // Initialize chips from config
    renderChips();
    
    // Initialize directory browser if elements exist
    initializeDirectoryBrowser();
}

// Directory Browser functionality
function initializeDirectoryBrowser() {
    var directoryBrowser = document.getElementById('directory-browser');
    if (!directoryBrowser) return;
    
    var browsePath = document.getElementById('browse-path');
    var browseGoBtn = document.getElementById('browse-go-btn');
    var browseHomeBtn = document.getElementById('browse-home-btn');
    var directoryList = document.getElementById('directory-list');
    var selectDirectoryBtn = document.getElementById('select-directory-btn');
    var cancelBrowseBtn = document.getElementById('cancel-browse-btn');
    var clientRadio = document.getElementById('client-browser-radio');
    var clientWarning = document.getElementById('client-browser-warning');
    var serverControls = document.getElementById('server-browser-controls');
    var clientControls = document.getElementById('client-browser-controls');
    var browseClientBtn = document.getElementById('browse-client-btn');
    var clientFileInput = document.getElementById('client-file-input');
    var clientSelectedInfo = document.getElementById('client-selected-info');
    var clientSelectedPath = document.getElementById('client-selected-path');
    
    if (!browsePath || !browseGoBtn || !browseHomeBtn || !directoryList || !selectDirectoryBtn || !cancelBrowseBtn) {
        return; // Not all elements found
    }
    
    var currentBrowsePath = '';
    var selectedDirectory = '';
    var currentMode = 'server';
    
    function openBrowsePanel() {
        directoryBrowser.style.display = 'block';
        if (currentMode === 'server') {
            browsePath.value = '';
            browsePath.focus();
            loadDirectoryList('');
        } else {
            // Client mode: show controls and warning
            if (clientWarning) clientWarning.style.display = 'block';
            if (clientControls) clientControls.style.display = '';
            if (serverControls) serverControls.style.display = 'none';
            if (directoryList) directoryList.innerHTML = '<div style="color:#ccc; padding:12px;">Click "Browse Local Folders" to pick a folder from this device.</div>';
        }
    }
    
    // Make openBrowsePanel globally accessible
    window.openBrowsePanel = openBrowsePanel;
    
    cancelBrowseBtn.addEventListener('click', function() {
        directoryBrowser.style.display = 'none';
        selectedDirectory = '';
        selectDirectoryBtn.disabled = true;
    });
    
    browseGoBtn.addEventListener('click', function() {
        var path = browsePath.value.trim();
        if (path) {
            loadDirectoryList(path);
        }
    });
    
    browseHomeBtn.addEventListener('click', function() {
        loadDirectoryList('');
    });
    
    browsePath.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            browseGoBtn.click();
        }
    });
    
    selectDirectoryBtn.addEventListener('click', function() {
        if (selectedDirectory) {
            var directoryInput = document.getElementById('directory');
            if (directoryInput) {
                directoryInput.value = selectedDirectory;
                directoryInput.dispatchEvent(new Event('input', { bubbles: true }));
                directoryInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
            directoryBrowser.style.display = 'none';
            selectedDirectory = '';
            selectDirectoryBtn.disabled = true;
        }
    });

    // Toggle between server and client modes
    function setMode(mode) {
        currentMode = mode === 'client' ? 'client' : 'server';
        if (serverControls) serverControls.style.display = (currentMode === 'server') ? '' : 'none';
        if (clientControls) clientControls.style.display = (currentMode === 'client') ? '' : 'none';
        if (clientWarning) clientWarning.style.display = (currentMode === 'client') ? '' : 'none';
        if (directoryList) {
            directoryList.innerHTML = '';
        }
        // Reset selection state
        selectedDirectory = '';
        selectDirectoryBtn.disabled = true;
        // Load initial content for the selected mode
        if (currentMode === 'server') {
            loadDirectoryList('');
        } else {
            if (directoryList) directoryList.innerHTML = '<div style="color:#ccc; padding:12px;">Click "Browse Local Folders" to pick a folder from this device.</div>';
        }
    }

    // Bind radio change
    document.querySelectorAll('input[name="browser-type"]').forEach(function(r) {
        r.addEventListener('change', function() {
            setMode(r.value);
        });
    });

    // Client browsing support
    function supportsFileSystemAccessAPI() {
        return !!(window.showDirectoryPicker);
    }

    function handleClientSelectionDisplay(pathText) {
        if (clientSelectedInfo) clientSelectedInfo.style.display = '';
        if (clientSelectedPath) clientSelectedPath.textContent = pathText || '(folder selected)';
    }

    // Primary path: File System Access API
    if (browseClientBtn) {
        browseClientBtn.addEventListener('click', function() {
            if (supportsFileSystemAccessAPI()) {
                // Use modern directory picker
                window.showDirectoryPicker({}).then(function(dirHandle) {
                    try {
                        // We cannot read an absolute path for privacy; show name as hint
                        var display = 'Selected local folder: ' + (dirHandle.name || '(folder)');
                        handleClientSelectionDisplay(display);
                        // We cannot transmit local path to server; user must map/share
                        selectedDirectory = dirHandle.name || '';
                        selectDirectoryBtn.disabled = selectedDirectory ? false : true;
                    } catch (e) {
                        console.error(e);
                    }
                }).catch(function(err) {
                    // User canceled or not allowed
                });
            } else if (clientFileInput) {
                // Fallback: webkitdirectory
                clientFileInput.click();
            }
        });
    }

    // Fallback input change handler
    if (clientFileInput) {
        clientFileInput.addEventListener('change', function() {
            if (clientFileInput.files && clientFileInput.files.length > 0) {
                // Infer a pseudo path from first file
                var first = clientFileInput.files[0];
                var webkitPath = (first && first.webkitRelativePath) ? first.webkitRelativePath : '';
                var folder = '';
                if (webkitPath && webkitPath.indexOf('/') !== -1) {
                    folder = webkitPath.split('/')[0];
                }
                var display = folder ? ('Selected local folder: ' + folder) : 'Selected local files: ' + clientFileInput.files.length;
                handleClientSelectionDisplay(display);
                selectedDirectory = folder || '';
                selectDirectoryBtn.disabled = selectedDirectory ? false : true;
            }
        });
    }

    // Default initial mode
    setMode('server');
    
    function loadDirectoryList(path) {
        currentBrowsePath = path;
        browsePath.value = path;
        
        var formData = new FormData();
        formData.append('path', path);
        
        fetch('api.php?action=browse_directories', {
            method: 'POST',
            body: formData
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(data) {
            if (data.status === 'ok') {
                displayDirectoryList(data.directories, data.currentPath, data.videoFiles || []);
            } else {
                var errorMsg = data.error || 'Unknown error';
                if (data.hint) {
                    errorMsg += '<br><small style="color: #ffa500;">' + data.hint + '</small>';
                }
                if (data.path) {
                    errorMsg += '<br><small style="color: #888;">Path: ' + escapeHtml(data.path) + '</small>';
                }
                directoryList.innerHTML = '<div style="color: #ff6b6b; padding: 15px; background: #2a0000; border: 1px solid #660000; border-radius: 4px;">' + errorMsg + '</div>';
            }
        })
        .catch(function(err) {
            directoryList.innerHTML = '<div style="color: #ff6b6b; padding: 15px; background: #2a0000; border: 1px solid #660000; border-radius: 4px;">Failed to load directories: ' + err.message + '</div>';
        });
    }
    
    function displayDirectoryList(directories, currentPath, videoFiles) {
        var html = '';
        
        if (currentPath && currentPath !== '/') {
            var parentPath = getParentPath(currentPath);
            var escapedParentPath = parentPath.split('\\').join('\\\\');
            html += '<div class="directory-item" data-path="' + escapedParentPath + '">';
            html += '<span style="color: #4ecdc4;">📁 ..</span> (Parent directory)';
            html += '</div>';
        }
        
        // Always show option to select current directory
        if (currentPath && currentPath !== '') {
            html += '<div class="select-current-directory" style="color: #4ecdc4; padding: 15px; text-align: center; border: 2px dashed #4ecdc4; border-radius: 8px; cursor: pointer; background-color: #0a3a3a; margin-bottom: 15px;">';
            html += '<p style="margin: 0 0 10px 0; font-weight: bold;">🎯 Select Current Directory</p>';
            html += '<p style="font-size: 14px; margin: 0; color: #fff;">👆 Click here to use this directory as your video folder</p>';
            html += '<p style="font-size: 12px; margin: 5px 0 0 0; color: #888; word-break: break-all;">' + currentPath + '</p>';
            html += '</div>';
        }
        
        // Show video files info if any
        if (videoFiles && videoFiles.length > 0) {
            html += '<div style="margin-bottom: 15px; padding: 10px; background-color: #1a1a1a; border-radius: 4px;">';
            html += '<p style="color: #4ecdc4; margin-bottom: 10px;">🎬 Video files in this directory (' + videoFiles.length + '):</p>';
            html += '<div style="max-height: 150px; overflow-y: auto;">';
            videoFiles.slice(0, 10).forEach(function(file) {
                html += '<div style="color: #ccc; font-size: 12px; padding: 2px 0;">📹 ' + file + '</div>';
            });
            if (videoFiles.length > 10) {
                html += '<div style="color: #888; font-size: 11px; padding: 2px 0;">... and ' + (videoFiles.length - 10) + ' more</div>';
            }
            html += '</div></div>';
        }
        
        if (directories.length === 0) {
            html += '<div style="color: #888; padding: 10px; text-align: center; font-style: italic;">';
            html += '<p style="margin: 0;">📁 No subdirectories found</p>';
            html += '</div>';
        } else {
            directories.forEach(function(dir) {
                var fullPath;
                if (currentPath) {
                    if (currentPath.indexOf(':\\') !== -1) {
                        // Windows path
                        fullPath = currentPath.endsWith('\\') ? currentPath + dir : currentPath + '\\' + dir;
                    } else {
                        // Unix path
                        fullPath = currentPath.endsWith('/') ? currentPath + dir : currentPath + '/' + dir;
                    }
                } else {
                    fullPath = dir;
                }
                
                var escapedPath = fullPath.split('\\').join('\\\\');
                html += '<div class="directory-item" data-path="' + escapedPath + '">';
                html += '<span style="color: #4ecdc4;">📁 ' + dir + '</span>';
                html += '</div>';
            });
        }
        
        directoryList.innerHTML = html;
        
        // Add click handlers
        directoryList.querySelectorAll('.directory-item').forEach(function(item) {
            item.addEventListener('click', function() {
                var rawPath = item.getAttribute('data-path');
                var path = rawPath.split('\\\\').join('\\');
                if (path === '..') {
                    var parentPath = getParentPath(currentPath);
                    loadDirectoryList(parentPath);
                } else {
                    loadDirectoryList(path);
                }
            });
            
            item.addEventListener('dblclick', function() {
                var rawPath = item.getAttribute('data-path');
                var path = rawPath.split('\\\\').join('\\');
                if (path !== '..') {
                    selectedDirectory = path;
                    selectDirectoryBtn.disabled = false;
                    var displayPath = path.split('\\').join('\\\\');
                    selectDirectoryBtn.textContent = 'Select: ' + displayPath;
                    
                    // Highlight selected item
                    directoryList.querySelectorAll('.directory-item').forEach(function(el) {
                        el.style.backgroundColor = '';
                    });
                    item.style.backgroundColor = '#007acc';
                }
            });
        });
        
        // Add click handler for current directory selection
        var selectCurrentDir = directoryList.querySelector('.select-current-directory');
        if (selectCurrentDir) {
            selectCurrentDir.addEventListener('click', function() {
                selectedDirectory = currentPath;
                selectDirectoryBtn.disabled = false;
                var displayCurrentPath = currentPath.split('\\').join('\\\\');
                selectDirectoryBtn.textContent = 'Select: ' + displayCurrentPath;
                
                // Highlight the selection
                selectCurrentDir.style.backgroundColor = '#007acc';
                selectCurrentDir.style.borderColor = '#007acc';
                selectCurrentDir.style.color = '#fff';
                
                // Remove highlight from other directory items
                directoryList.querySelectorAll('.directory-item').forEach(function(el) {
                    el.style.backgroundColor = '';
                });
            });
        }
    }
    
    function getParentPath(path) {
        if (path.indexOf(':\\') !== -1) {
            // Windows path
            var parts = path.split('\\').filter(function(part) { return part !== ''; });
            parts.pop();
            return parts.length > 0 ? parts.join('\\') + '\\' : path.split('\\')[0] + '\\';
        } else {
            // Unix path
            var parts = path.split('/').filter(function(part) { return part !== ''; });
            parts.pop();
            return parts.length > 0 ? '/' + parts.join('/') : '/';
        }
    }
}

// Video Management functionality
function initializeVideoManagement() {
    loadVideoTitles();
    // Also wire the Refresh Dashboards button in this section to trigger a refresh signal
    var refreshForms = document.querySelectorAll('#video-management .header-actions form');
    refreshForms.forEach(function(f){
        f.addEventListener('submit', function(){
            // Trigger dashboard refresh signal for the selected profile
            var profile = (new URLSearchParams(window.location.search)).get('dashboard') || 'default';
            var fd = new FormData();
            fd.append('profile', profile);
            fetch('api.php?action=trigger_dashboard_refresh', { method: 'POST', body: fd })
                .catch(function(){ /* ignore */ });
        });
    });
}

function loadVideoTitles() {
    var container = document.getElementById('video-titles-container');
    if (!container) return;
    
    // Show loading indicator
    container.innerHTML = '<div style="text-align: center; padding: 20px; color: #4ecdc4;">📁 Loading videos...</div>';
    
    // Get current page from URL
    var urlParams = new URLSearchParams(window.location.search);
    var currentPage = urlParams.get('page') || 1;
    
    // Get paginated videos and their titles
    // Determine current profile from URL/query
    const urlParams2 = new URLSearchParams(window.location.search);
    const selectedProfile = urlParams2.get('dashboard') || 'default';

    Promise.all([
        fetch('api.php?action=get_all_videos&page=' + currentPage + '&limit=20&profile=' + encodeURIComponent(selectedProfile))
            .then(function(res) {
                if (!res.ok) throw new Error('HTTP ' + res.status + ': ' + res.statusText);
                return res.text();
            })
            .then(function(text) {
                if (!text.trim()) throw new Error('Empty response from get_all_videos');
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('Invalid JSON response from get_all_videos');
                }
            }),
        fetch('api.php?action=get_video_titles')
            .then(function(res) {
                if (!res.ok) throw new Error('HTTP ' + res.status + ': ' + res.statusText);
                return res.text();
            })
            .then(function(text) {
                if (!text.trim()) {
                    return { titles: {} };
                }
                try {
                    return JSON.parse(text);
                } catch (e) {
                    return { titles: {} };
                }
            })
    ]).then(function(results) {
        var videosData = results[0];
        var titlesData = results[1];
        var videos = videosData.videos || [];
        var titles = titlesData.titles || {};
        
        if (videos.length === 0) {
            container.innerHTML = '<p>No videos found.</p>';
            return;
        }
        
        renderCarouselView(videos, titles, container);
    }).catch(function(err) {
        container.innerHTML = '<div style="text-align: center; padding: 20px; color: #ff6b6b;">❌ Error loading videos: ' + err.message + '</div>';
    });
}

// Dashboard Controls functionality
function initializeDashboardControls() {
    var dashboardIdSelect = document.getElementById('dashboard_id');
    if (dashboardIdSelect) {
        dashboardIdSelect.addEventListener('change', function() {
            var id = dashboardIdSelect.value || 'default';
            var search = new URLSearchParams(window.location.search);
            search.set('admin-panel', 'dashboard-settings');
            search.set('dashboard', id);
            window.location.search = search.toString();
        });
    }
    
    // Render dashboard video controls
    renderDashboardVideoControls();
}

// Modal functionality
function initializeModals() {
    // Initialize thumbnail generation modal
    var generateThumbsBtn = document.getElementById('generate-thumbs-btn');
    var thumbnailModal = document.getElementById('thumbnail-modal');
    
    if (generateThumbsBtn && thumbnailModal) {
        // Console logging functions
        function logToConsole(message, type = 'info') {
            var consoleContent = document.getElementById('console-content');
            if (consoleContent) {
                var logEntry = document.createElement('div');
                logEntry.className = 'log-entry log-' + type;
                logEntry.textContent = '[' + new Date().toLocaleTimeString() + '] ' + message;
                consoleContent.appendChild(logEntry);
                consoleContent.scrollTop = consoleContent.scrollHeight;
            }
            // Also log to browser console
            console.log('[Thumbnail Generation] ' + message);
        }
        
        // Update progress and stats
        function updateProgress(processed, total, failed = 0) {
            var progressFill = thumbnailModal.querySelector('.progress-fill');
            var processedEl = document.getElementById('processed-videos');
            var totalEl = document.getElementById('total-videos');
            var failedEl = document.getElementById('failed-videos');
            
            if (progressFill && total > 0) {
                var percent = Math.round((processed / total) * 100);
                progressFill.style.width = percent + '%';
            }
            
            if (processedEl) processedEl.textContent = processed;
            if (totalEl) totalEl.textContent = total;
            if (failedEl) failedEl.textContent = failed;
        }
        
        // Show thumbnail modal
        function showThumbnailModal() {
            thumbnailModal.style.display = 'flex';
            // Reset stats
            updateProgress(0, 0, 0);
            // Clear console
            var consoleContent = document.getElementById('console-content');
            if (consoleContent) consoleContent.innerHTML = '';
            // Reset status
            var statusEl = document.getElementById('thumbnail-status');
            if (statusEl) statusEl.textContent = 'Initializing thumbnail generation...';
        }
        
        // Hide thumbnail modal
        function hideThumbnailModal() {
            thumbnailModal.style.display = 'none';
        }
        
        // Check FFmpeg availability
        function checkFFmpeg() {
            return fetch('api.php?action=check_ffmpeg')
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.available) {
                        logToConsole('FFmpeg is available and working', 'success');
                        return true;
                    } else {
                        logToConsole('FFmpeg is not available or not working', 'error');
                        logToConsole('Error: ' + (data.error || 'Unknown error'), 'error');
                        return false;
                    }
                })
                .catch(function(error) {
                    logToConsole('Failed to check FFmpeg availability: ' + error.message, 'error');
                    return false;
                });
        }
        
        // Start thumbnail generation
        function startThumbnailGeneration() {
            showThumbnailModal();
            logToConsole('Starting thumbnail generation process...', 'info');
            
            // Check FFmpeg first
            checkFFmpeg().then(function(ffmpegAvailable) {
                if (!ffmpegAvailable) {
                    logToConsole('Cannot proceed without FFmpeg. Please install FFmpeg and ensure it\'s in your system PATH.', 'error');
                    updateProgress(0, 0, 0);
                    return;
                }
                
                // Get video count first
                fetch('api.php?action=get_video_count')
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        var totalVideos = data.count || 0;
                        
                        if (totalVideos === 0) {
                            logToConsole('No videos found to process', 'warning');
                            updateProgress(0, 0, 0);
                            return;
                        }
                        
                        logToConsole('Found ' + totalVideos + ' videos to process', 'info');
                        updateProgress(0, totalVideos, 0);
                        
                        // Start processing in batches
                        var processed = 0;
                        var failed = 0;
                        var batchSize = 5;
                        var offset = 0;
                        
                        var statusEl = document.getElementById('thumbnail-status');
                        if (statusEl) statusEl.textContent = 'Processing videos (' + processed + '/' + totalVideos + ')...';
                        
                        function processBatch() {
                            if (processed + failed >= totalVideos) {
                                // Completed
                                updateProgress(processed, totalVideos, failed);
                                if (statusEl) statusEl.textContent = 'Completed: ' + processed + ' generated, ' + failed + ' failed';
                                
                                if (failed === 0) {
                                    logToConsole('All thumbnails generated successfully!', 'success');
                                } else {
                                    logToConsole('Thumbnail generation completed with ' + failed + ' failures', 'warning');
                                }
                                
                                // Auto-hide modal after 5 seconds
                                setTimeout(function() {
                                    hideThumbnailModal();
                                    // Refresh the page to show updated thumbnail counts
                                    location.reload();
                                }, 5000);
                                return;
                            }
                            
                            fetch('api.php?action=warm_thumbnails&offset=' + offset + '&batch=' + batchSize)
                                .then(function(response) { return response.json(); })
                                .then(function(batchData) {
                                    if (batchData.status === 'ok') {
                                        // Align total to server-side filtered count to avoid infinite loop
                                        if (typeof batchData.total === 'number') {
                                            totalVideos = batchData.total;
                                        }
                                        // If nothing remains, finish
                                        if (typeof batchData.remaining === 'number' && batchData.remaining <= 0) {
                                            updateProgress(processed, totalVideos, failed);
                                            if (statusEl) statusEl.textContent = 'Completed: ' + processed + ' generated, ' + failed + ' failed';
                                            setTimeout(function() { hideThumbnailModal(); location.reload(); }, 2000);
                                            return;
                                        }
                                        var batchProcessed = batchData.processed || 0;
                                        var batchFailed = batchData.failed || 0;
                                        
                                        processed += batchProcessed;
                                        failed += batchFailed;
                                        offset = batchData.nextOffset || (offset + batchSize);

                                        // Guard: if server returns same nextOffset repeatedly, advance to avoid loop
                                        if (offset <= processed + failed) {
                                            offset = processed + failed;
                                        }
                                        
                                        logToConsole('Batch processed: ' + batchProcessed + ' success, ' + batchFailed + ' failed', 'info');
                                        updateProgress(processed, totalVideos, failed);
                                        
                                        if (statusEl) statusEl.textContent = 'Processing videos (' + processed + '/' + totalVideos + ')...';
                                        
                                        // Process next batch after small delay
                                        setTimeout(processBatch, 100);
                                    } else {
                                        logToConsole('Batch failed: ' + (batchData.error || 'Unknown error'), 'error');
                                        failed += batchSize;
                                        offset += batchSize;
                                        setTimeout(processBatch, 100);
                                    }
                                })
                                .catch(function(error) {
                                    logToConsole('Batch error: ' + error.message, 'error');
                                    failed += batchSize;
                                    offset += batchSize;
                                    setTimeout(processBatch, 100);
                                });
                        }
                        
                        processBatch();
                    })
                    .catch(function(error) {
                        logToConsole('Fatal error: ' + error.message, 'error');
                        var statusEl = document.getElementById('thumbnail-status');
                        if (statusEl) statusEl.textContent = 'Error occurred during processing';
                    });
            });
        }
        
        // Button click handler
        generateThumbsBtn.addEventListener('click', function() {
            if (confirm('Generate thumbnails for all videos? This may take several minutes.')) {
                startThumbnailGeneration();
            }
        });
    }
    
    // Initialize preview generation modal
    var generatePreviewsBtn = document.getElementById('generate-previews-btn');
    var previewModal = document.getElementById('preview-modal');
    
    if (generatePreviewsBtn && previewModal) {
        // Console logging functions for previews
        function logToPreviewConsole(message, type = 'info') {
            var consoleContent = document.getElementById('preview-console-content');
            if (consoleContent) {
                var logEntry = document.createElement('div');
                logEntry.className = 'log-entry log-' + type;
                logEntry.textContent = '[' + new Date().toLocaleTimeString() + '] ' + message;
                consoleContent.appendChild(logEntry);
                consoleContent.scrollTop = consoleContent.scrollHeight;
            }
            // Also log to browser console
            console.log('[Preview Generation] ' + message);
        }
        
        // Update preview progress and stats
        function updatePreviewProgress(processed, total, failed = 0) {
            var progressFill = previewModal.querySelector('.progress-fill');
            var processedEl = document.getElementById('preview-processed-videos');
            var totalEl = document.getElementById('preview-total-videos');
            var failedEl = document.getElementById('preview-failed-videos');
            
            if (progressFill && total > 0) {
                var percent = Math.round((processed / total) * 100);
                progressFill.style.width = percent + '%';
            }
            
            if (processedEl) processedEl.textContent = processed;
            if (totalEl) totalEl.textContent = total;
            if (failedEl) failedEl.textContent = failed;
        }
        
        // Show preview modal
        function showPreviewModal() {
            previewModal.style.display = 'flex';
            // Reset stats
            updatePreviewProgress(0, 0, 0);
            // Clear console
            var consoleContent = document.getElementById('preview-console-content');
            if (consoleContent) consoleContent.innerHTML = '';
            // Reset status
            var statusEl = document.getElementById('preview-status');
            if (statusEl) statusEl.textContent = 'Initializing preview generation...';
        }
        
        // Hide preview modal
        function hidePreviewModal() {
            previewModal.style.display = 'none';
        }
        
        // Check FFmpeg availability (reuse the same function logic)
        function checkFFmpegForPreviews() {
            return fetch('api.php?action=check_ffmpeg')
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.available) {
                        logToPreviewConsole('FFmpeg is available and working', 'success');
                        return true;
                    } else {
                        logToPreviewConsole('FFmpeg is not available or not working', 'error');
                        logToPreviewConsole('Error: ' + (data.error || 'Unknown error'), 'error');
                        return false;
                    }
                })
                .catch(function(error) {
                    logToPreviewConsole('Failed to check FFmpeg availability: ' + error.message, 'error');
                    return false;
                });
        }
        
        // Start preview generation
        function startPreviewGeneration() {
            showPreviewModal();
            logToPreviewConsole('Starting preview generation process...', 'info');
            
            // Check FFmpeg first
            checkFFmpegForPreviews().then(function(ffmpegAvailable) {
                if (!ffmpegAvailable) {
                    logToPreviewConsole('Cannot proceed without FFmpeg. Please install FFmpeg and ensure it\'s in your system PATH.', 'error');
                    updatePreviewProgress(0, 0, 0);
                    return;
                }
                
                // Get video count first
                fetch('api.php?action=get_video_count')
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        var totalVideos = data.count || 0;
                        
                        if (totalVideos === 0) {
                            logToPreviewConsole('No videos found to process', 'warning');
                            updatePreviewProgress(0, 0, 0);
                            return;
                        }
                        
                        logToPreviewConsole('Found ' + totalVideos + ' videos to process', 'info');
                        updatePreviewProgress(0, totalVideos, 0);
                        
                        // Start processing in batches (smaller batches for previews since they take longer)
                        var processed = 0;
                        var failed = 0;
                        var batchSize = 2; // Smaller batch size for previews
                        var offset = 0;
                        
                        var statusEl = document.getElementById('preview-status');
                        if (statusEl) statusEl.textContent = 'Processing video previews (' + processed + '/' + totalVideos + ')...';
                        
                        function processBatch() {
                            if (processed + failed >= totalVideos) {
                                // Completed
                                updatePreviewProgress(processed, totalVideos, failed);
                                if (statusEl) statusEl.textContent = 'Completed: ' + processed + ' generated, ' + failed + ' failed';
                                
                                if (failed === 0) {
                                    logToPreviewConsole('All previews generated successfully!', 'success');
                                } else {
                                    logToPreviewConsole('Preview generation completed with ' + failed + ' failures', 'warning');
                                }
                                
                                // Auto-hide modal after 5 seconds
                                setTimeout(function() {
                                    hidePreviewModal();
                                    // Refresh the page to show updated preview counts
                                    location.reload();
                                }, 5000);
                                return;
                            }
                            
                            fetch('api.php?action=warm_previews&offset=' + offset + '&batch=' + batchSize)
                                .then(function(response) { return response.json(); })
                                .then(function(batchData) {
                                    if (batchData.status === 'ok') {
                                        if (typeof batchData.total === 'number') {
                                            totalVideos = batchData.total;
                                        }
                                        if (typeof batchData.remaining === 'number' && batchData.remaining <= 0) {
                                            updatePreviewProgress(processed, totalVideos, failed);
                                            if (statusEl) statusEl.textContent = 'Completed: ' + processed + ' generated, ' + failed + ' failed';
                                            setTimeout(function(){ hidePreviewModal(); location.reload(); }, 2000);
                                            return;
                                        }
                                        var batchProcessed = batchData.processed || 0;
                                        var batchFailed = batchData.failed || 0;
                                        
                                        processed += batchProcessed;
                                        failed += batchFailed;
                                        offset = batchData.nextOffset || (offset + batchSize);
                                        if (offset <= processed + failed) {
                                            offset = processed + failed;
                                        }
                                        
                                        logToPreviewConsole('Batch processed: ' + batchProcessed + ' success, ' + batchFailed + ' failed', 'info');
                                        updatePreviewProgress(processed, totalVideos, failed);
                                        
                                        if (statusEl) statusEl.textContent = 'Processing video previews (' + processed + '/' + totalVideos + ')...';
                                        
                                        // Process next batch after longer delay (previews take more time)
                                        setTimeout(processBatch, 500);
                                    } else {
                                        logToPreviewConsole('Batch failed: ' + (batchData.error || 'Unknown error'), 'error');
                                        failed += batchSize;
                                        offset += batchSize;
                                        setTimeout(processBatch, 500);
                                    }
                                })
                                .catch(function(error) {
                                    logToPreviewConsole('Batch error: ' + error.message, 'error');
                                    failed += batchSize;
                                    offset += batchSize;
                                    setTimeout(processBatch, 500);
                                });
                        }
                        
                        processBatch();
                    })
                    .catch(function(error) {
                        logToPreviewConsole('Fatal error: ' + error.message, 'error');
                        var statusEl = document.getElementById('preview-status');
                        if (statusEl) statusEl.textContent = 'Error occurred during processing';
                    });
            });
        }
        
        // Button click handler
        generatePreviewsBtn.addEventListener('click', function() {
            if (confirm('Generate video previews for all videos? This may take a very long time (much longer than thumbnails).')) {
                startPreviewGeneration();
            }
        });
    }

    // Initialize single VP9 encode modal
    (function(){
        var encodeBtn = document.getElementById('encode-vp9-btn');
        if (!encodeBtn) return; // button removed/disabled

        // Create a dedicated VP9 modal so it doesn't auto-close
        var vp9Modal = document.createElement('div');
        vp9Modal.id = 'vp9-modal';
        vp9Modal.className = 'modal';
        vp9Modal.style.display = 'none';
        vp9Modal.innerHTML = '<div class="modal-content">\
            <div class="modal-header"><h3>🎞️ Encoding to VP9 (single file)</h3></div>\
            <div class="modal-body">\
                <div class="preview-animation"><div class="spinner"></div></div>\
                <div class="vp9-status">Starting…</div>\
                <div style="margin-top:10px;"><button id="vp9-close" class="btn secondary">Close</button></div>\
            </div></div>';
        document.body.appendChild(vp9Modal);
        function show(){ vp9Modal.style.display = 'flex'; }
        function hide(){ vp9Modal.style.display = 'none'; }
        function setStatus(msg){ var s = vp9Modal.querySelector('.vp9-status'); if (s) s.textContent = msg; }
        vp9Modal.querySelector('#vp9-close').addEventListener('click', hide);

        encodeBtn.addEventListener('click', function(){
            setStatus('Starting…');
            show();
            fetch('api.php?action=encode_vp9_single&file=' + encodeURIComponent('New Zealand Tour.mp4') + '&dirIndex=0')
                .then(function(r){ return r.ok ? r.json() : { success:false }; })
                .then(function(d){
                    if (!d || !d.success) { setStatus('Failed to start: ' + (d && d.error ? d.error : 'Unknown error')); return; }
                    setStatus('Encoding started…');
                    var poll = setInterval(function(){
                        fetch('api.php?action=encode_vp9_status')
                            .then(function(r){ return r.ok ? r.json() : { success:false }; })
                            .then(function(s){
                                if (!s || !s.success) return;
                                if (s.state === 'running') {
                                    setStatus('Progress: ' + (s.progress || 'working…'));
                                } else if (s.state === 'done') {
                                    setStatus('Done: ' + (s.output || ''));
                                    clearInterval(poll);
                                } else if (s.state === 'idle' || s.state === 'error') {
                                    setStatus(s.message || 'Idle');
                                    clearInterval(poll);
                                }
                            })
                            .catch(function(){ /* ignore */ });
                    }, 1000);
                })
                .catch(function(){ setStatus('Failed to start'); });
        });
    })();
}

// Utility functions
function escapeAttr(str) { 
    return escapeHtml(str); 
}

function getCurrentDashboardSettings() {
    // Use the dashboard data passed from PHP
    const dashboards = window.adminDashboards || {};
    
    // Try to get the selected dashboard from the URL or default
    const urlParams = new URLSearchParams(window.location.search);
    const selectedDashboard = urlParams.get('dashboard') || 'default';
    
    if (dashboards[selectedDashboard]) {
        return dashboards[selectedDashboard];
    }
    
    // Fallback to default or basic settings
    return {
        rows: 2,
        clipsPerRow: 4
    };
}

function renderCarouselView(videos, titles, container) {
    // Get dashboard configuration for rows and clipsPerRow
    const dashboardSettings = getCurrentDashboardSettings();
    const rows = dashboardSettings.rows || 2;
    const clipsPerRow = dashboardSettings.clipsPerRow || 4;
    
    // Distribute videos across rows with balanced algorithm
    const rowsData = [];
    const totalVideos = videos.length;
    
    if (totalVideos === 0) {
        // No videos to distribute
    } else if (rows === 1) {
        // Single row gets all videos
        rowsData.push([...videos]);
    } else {
        // Multi-row balanced distribution
        const videosPerRowBase = Math.floor(totalVideos / rows);
        const extraVideos = totalVideos % rows;
        
        let videoIndex = 0;
        for (let rowIndex = 0; rowIndex < rows; rowIndex++) {
            // Some rows get one extra video to distribute the remainder
            const videosForThisRow = videosPerRowBase + (rowIndex < extraVideos ? 1 : 0);
            const rowVideos = videos.slice(videoIndex, videoIndex + videosForThisRow);
            rowsData.push(rowVideos);
            videoIndex += videosForThisRow;
            
            // Stop if we've distributed all videos
            if (videoIndex >= totalVideos) break;
        }
    }
    
    let html = '<div class="admin-carousel-preview">';
    html += '<h4>Dashboard Preview Layout (' + rows + ' rows, balanced distribution)</h4>';
    
    if (rowsData.length === 0) {
        html += '<div class="admin-carousel-empty">No videos available</div>';
    } else {
        rowsData.forEach((rowVideos, rowIndex) => {
            html += '<div class="admin-carousel-row">';
            html += '<div class="admin-carousel-row-header">Row ' + (rowIndex + 1) + ' (' + rowVideos.length + ' videos)</div>';
            html += '<div class="admin-carousel-track">';
            
            rowVideos.forEach((video, videoIndex) => {
                const videoName = typeof video === 'string' ? video : video.name;
                const videoDirIndex = typeof video === 'string' ? 0 : video.dirIndex;
                const titleKey = videoDirIndex + '|' + videoName;
                const currentTitle = titles[titleKey] || videoName.replace(/\.[^/.]+$/, '');
                const videoId = videoName.replace(/[^a-zA-Z0-9]/g, '_');
                const position = rowIndex * clipsPerRow + videoIndex + 1;
                
                html += '<div class="admin-carousel-item" data-filename="' + escapeAttr(videoName) + '" data-dir-index="' + videoDirIndex + '" data-position="' + position + '">';
                html += '<img src="thumb.php?file=' + encodeURIComponent(videoName) + '&dirIndex=' + videoDirIndex + '" alt="thumbnail" loading="lazy" />';
                html += '<div class="admin-title" title="' + escapeAttr(currentTitle) + '">' + escapeHtml(currentTitle) + '</div>';
                html += '<button type="button" class="move-btn reorder-left" data-filename="' + escapeAttr(videoName) + '" data-dir-index="' + videoDirIndex + '" data-direction="up" title="Move left">◀️</button>';
                html += '<button type="button" class="move-btn reorder-right" data-filename="' + escapeAttr(videoName) + '" data-dir-index="' + videoDirIndex + '" data-direction="down" title="Move right">▶️</button>';
                html += '<div class="admin-controls">';
                html += '<button type="button" class="edit-title-btn" data-filename="' + escapeAttr(videoName) + '" data-dir-index="' + videoDirIndex + '" data-video-id="' + videoId + '">Edit</button>';
                html += '</div>';
                html += '</div>';
            });
            
            html += '</div></div>';
        });
    }
    
    html += '</div>';
    container.innerHTML = html;
    
    setupCarouselEventListeners();
}

function setupCarouselEventListeners() {
    // Images load natively; optionally handle error state
    const thumbImgs = document.querySelectorAll('.admin-carousel-item img');
    thumbImgs.forEach(img => {
        img.addEventListener('error', () => { img.alt = 'thumbnail unavailable'; });
    });
    
    // Add event listeners for edit title buttons
    document.querySelectorAll('.edit-title-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const filename = this.getAttribute('data-filename');
            const dirIndex = this.getAttribute('data-dir-index') || '0';
            const videoId = this.getAttribute('data-video-id');
            
            // Get current title
            const titleElement = this.closest('.admin-carousel-item').querySelector('.admin-title');
            const currentTitle = titleElement.textContent;
            
            // Prompt for new title
            const newTitle = prompt('Enter new title for: ' + filename, currentTitle);
            if (newTitle !== null && newTitle.trim() !== '') {
                saveVideoTitle(filename, dirIndex, newTitle.trim(), this, () => {
                    // Update the title display immediately
                    titleElement.textContent = newTitle.trim();
                    titleElement.title = newTitle.trim();
                });
            }
        });
    });

    // Add event listeners for move buttons
    document.querySelectorAll('.move-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const filename = this.getAttribute('data-filename');
            const dirIndex = this.getAttribute('data-dir-index') || '0';
            const direction = this.getAttribute('data-direction');
            
            moveVideo(filename, dirIndex, direction, this);
        });
    });
}

function saveVideoTitle(filename, dirIndex, title, buttonElement, successCallback) {
    const formData = new FormData();
    formData.append('filename', filename);
    formData.append('title', title);
    formData.append('dirIndex', dirIndex);
    // Include profile so titles can be stored per-profile and globally
    const profileFromUrl = (new URLSearchParams(window.location.search)).get('dashboard') || 'default';
    formData.append('profile', profileFromUrl);
    
    const originalText = buttonElement.textContent;
    buttonElement.textContent = 'Saving...';
    buttonElement.disabled = true;
    
    fetch('api.php?action=set_video_title', {
        method: 'POST',
        body: formData
    }).then(res => res.json()).then(data => {
        if (data.status === 'ok') {
            console.log('Title saved for', filename);
            if (successCallback) successCallback();
            // Update the displayed title in the Dashboard Preview Layout immediately
            const containers = document.querySelectorAll('.admin-carousel-item');
            containers.forEach(function(item){
                const img = item.querySelector('img');
                // We don't have data attributes here, so rely on existing dataset in renderCarouselView
            });
            // Simpler: reload the preview list so ordering and titles re-render
            loadVideoTitles();
        } else {
            console.error('Failed to save title:', data);
        }
    }).catch(err => {
        console.error('Error saving title:', err);
    }).finally(() => {
        setTimeout(() => {
            buttonElement.textContent = originalText;
            buttonElement.disabled = false;
        }, 800);
    });
}

function moveVideo(filename, dirIndex, direction, buttonElement) {
    const formData = new FormData();
    formData.append('filename', filename);
    formData.append('dirIndex', dirIndex);
    formData.append('direction', direction);
    // Include profile so order is saved per dashboard
    const profileFromUrl = (new URLSearchParams(window.location.search)).get('dashboard') || 'default';
    formData.append('profile', profileFromUrl);

    // Visual feedback
    const originalText = buttonElement.textContent;
    buttonElement.textContent = direction === 'up' ? '⏫' : '⏬';
    buttonElement.disabled = true;

    fetch('api.php?action=move_video', { method: 'POST', body: formData })
        .then(async res => { 
            const t = await res.text(); 
            try { 
                return JSON.parse(t); 
            } catch(e) { 
                console.error('Move video raw:', t); 
                throw e; 
            } 
        })
        .then(data => {
            if (data.status === 'ok') {
                // Reload current page list and re-render preview in place without full page reload
                loadVideoTitles();
            } else {
                console.error('Move failed:', data);
            }
        })
        .catch(err => console.error('Failed to move video:', err))
        .finally(() => {
            setTimeout(() => { 
                buttonElement.disabled = false; 
                buttonElement.textContent = originalText;
            }, 500);
        });
}

function renderDashboardVideoControls() {
    const container = document.getElementById('dashboard-video-controls');
    if (!container) return;
    
    // Use the dashboard data passed from PHP
    const profiles = window.adminDashboards || {};
    let html = '';
    html += '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px;">';
    
    Object.keys(profiles).forEach((id) => {
        const d = profiles[id] || {};
        const name = id === 'default' ? 'Default' : (d.name || id);
        let query = 'profile=' + encodeURIComponent(id);
        const m = id.match(/^dashboard(\d+)$/);
        if (id === 'default') {
            query = 'd=0';
        } else if (m) {
            query = 'd=' + m[1];
        }
        html += '<div style="border:1px solid #333; background:#1f1f1f; border-radius:8px; padding:12px;">';
        html += '<div style="color:#4ecdc4; font-weight:600; margin-bottom:6px;">' + escapeHtml(name) + '</div>';
        html += '<div style="display:flex; gap:8px; flex-wrap:wrap;">'
              + '<button class="btn secondary dc-clear" data-q="' + query + '">Clear Video</button>'
              + '<button class="btn secondary dc-stop" data-q="' + query + '">Stop</button>'
              + '<button class="btn secondary dc-pause" data-q="' + query + '">Pause</button>'
              + '<button class="btn secondary dc-play" data-q="' + query + '">Play</button>'
              + '</div>';
        html += '<div class="now-playing" data-profile="' + escapeHtml(id) + '" data-q="' + query + '" style="margin-top:8px; color:#ccc; font-size:0.95rem;"><span class="np-label">Now playing:</span><span class="np-title">Loading status...</span><span class="np-status" style="font-size:0.8em; color:#666; cursor:help;" title="🔄 Updating | ▶️ Playing (shows label) | ⏸️ Paused | ⏹️ Stopped | ❌ Error">🔄</span></div>';
        html += '</div>';
    });
    html += '</div>';
    container.innerHTML = html;

    function bind(btnClass, action, method = 'POST') {
        container.querySelectorAll('.' + btnClass).forEach(btn => {
            btn.addEventListener('click', () => {
                const q = btn.getAttribute('data-q') || '';
                fetch('api.php?action=' + action + '&' + q, { method })
                    .then(res => res.json())
                    .then(() => {
                        btn.textContent = 'Done';
                        setTimeout(() => { btn.textContent = btnClass.split('-')[1].replace(/^./, c=>c.toUpperCase()); }, 800);
                        
                        // Refresh status immediately after action
                        setTimeout(() => updateNowPlaying(), 500);
                    })
                    .catch(() => {});
            });
        });
    }
    bind('dc-clear', 'clear_current_video');
    bind('dc-stop', 'stop_video');
    bind('dc-pause', 'pause_video');
    bind('dc-play', 'play_video');

    // Populate Now Playing titles per dashboard
    function updateNowPlaying() {
        const npEls = Array.from(container.querySelectorAll('.now-playing'));
        if (npEls.length === 0) return;
        
        console.log('Updating now playing status for', npEls.length, 'dashboards');
        
        // Show updating status
        npEls.forEach(el => {
            const statusEl = el.querySelector('.np-status');
            const labelEl = el.querySelector('.np-label');
            if (statusEl) {
                statusEl.textContent = '🔄';
                statusEl.style.color = '#4ecdc4';
            }
            // Hide label during loading since we don't know if there's a video yet
            if (labelEl) labelEl.style.display = 'none';
        });
        
        // Update each dashboard's now playing status
        npEls.forEach(el => {
            const profileId = el.getAttribute('data-profile');
            const q = el.getAttribute('data-q') || '';
            
            console.log(`Fetching status for profile ${profileId} with query: ${q}`);
            
            // Get both current video AND playback state (like dashboard.php does)
            Promise.all([
                fetch(`api.php?action=get_current_video&${q}`).then(r => r.json()).catch(() => ({ currentVideo: null })),
                fetch(`api.php?action=get_playback_state&${q}`).then(r => r.json()).catch(() => ({ state: 'stop' })),
                fetch(`api.php?action=get_video_titles&profile=${encodeURIComponent(profileId)}`).then(r => r.json()).catch(() => ({ titles: {} }))
            ]).then(([videoData, stateData, titlesData]) => {
                const holder = el.querySelector('.np-title');
                const statusEl = el.querySelector('.np-status');
                const labelEl = el.querySelector('.np-label');
                if (!holder) return;
                
                console.log(`Profile ${profileId} data:`, { video: videoData, state: stateData, titles: titlesData });
                
                const cv = videoData && videoData.currentVideo;
                const state = stateData && stateData.state;
                const titles = titlesData && titlesData.titles ? titlesData.titles : {};
                
                if (!cv || (typeof cv === 'object' && !cv.filename) || (typeof cv === 'string' && cv.trim() === '')) { 
                    // No video selected
                    const labelEl = el.querySelector('.np-label');
                    if (labelEl) labelEl.style.display = 'none';
                    holder.textContent = 'No video selected'; 
                    if (statusEl) {
                        statusEl.textContent = '⏸️';
                        statusEl.style.color = '#888';
                    }
                    console.log(`Profile ${profileId}: No current video`);
                    return; 
                }
                
                let filename = '';
                let dirIndex = 0;
                if (typeof cv === 'object') {
                    filename = cv.filename || '';
                    dirIndex = (cv.dirIndex != null) ? cv.dirIndex : 0;
                } else {
                    filename = String(cv || '');
                }
                
                if (!filename) { 
                    // Empty filename
                    const labelEl = el.querySelector('.np-label');
                    if (labelEl) labelEl.style.display = 'none';
                    holder.textContent = 'No video selected'; 
                    if (statusEl) {
                        statusEl.textContent = '⏸️';
                        statusEl.style.color = '#888';
                    }
                    console.log(`Profile ${profileId}: Empty filename`);
                    return; 
                }
                
                // Look up custom title for this profile
                const key = String(dirIndex) + '|' + filename;
                const custom = titles[key];
                const display = custom || filename.replace(/\.[^/.]+$/, '');
                
                console.log(`Profile ${profileId}: Video "${filename}" (dir: ${dirIndex}) -> Display: "${display}" (custom: ${custom ? 'yes' : 'no'})`);
                
                // Determine status based on playback state (like dashboard.php)
                let statusText = display;
                let statusIcon = '▶️';
                let statusColor = '#4ecdc4';
                let showLabel = false;
                
                if (state === 'play') {
                    statusText = display;
                    statusIcon = '▶️';
                    statusColor = '#4ecdc4';
                    showLabel = true; // Only show "Now playing:" when actually playing
                } else if (state === 'pause') {
                    statusText = display;
                    statusIcon = '⏸️';
                    statusColor = '#ffa500';
                    showLabel = false; // Hide label when paused
                } else if (state === 'stop') {
                    statusText = display;
                    statusIcon = '⏹️';
                    statusColor = '#888';
                    showLabel = false; // Hide label when stopped
                } else {
                    statusText = display;
                    statusIcon = '⏸️';
                    statusColor = '#888';
                    showLabel = false; // Hide label for unknown states
                }
                
                // Show/hide label based on playback state
                if (labelEl) {
                    labelEl.style.display = showLabel ? 'inline' : 'none';
                }
                
                holder.textContent = statusText;
                if (statusEl) {
                    statusEl.textContent = statusIcon;
                    statusEl.style.color = statusColor;
                }
                
                console.log(`Profile ${profileId}: Final status - "${statusText}" with icon ${statusIcon}, label visible: ${showLabel}`);
                
            }).catch((err) => {
                console.error(`Failed to get status for profile ${profileId}:`, err);
                const holder = el.querySelector('.np-title');
                const statusEl = el.querySelector('.np-status');
                const labelEl = el.querySelector('.np-label');
                if (holder) holder.textContent = 'Error loading status';
                if (labelEl) labelEl.style.display = 'none';
                if (statusEl) {
                    statusEl.textContent = '❌';
                    statusEl.style.color = '#ff6b6b';
                }
            });
        });
    }
    updateNowPlaying();
    // Update less frequently to reduce server load
    setInterval(updateNowPlaying, 8000);
    
    // Add manual refresh button for debugging
    const refreshBtn = document.createElement('button');
    refreshBtn.className = 'btn secondary';
    refreshBtn.textContent = '🔄 Refresh Status';
    refreshBtn.style.marginTop = '10px';
    refreshBtn.addEventListener('click', () => {
        refreshBtn.textContent = '🔄 Updating...';
        refreshBtn.disabled = true;
        updateNowPlaying();
        setTimeout(() => {
            refreshBtn.textContent = '🔄 Refresh Status';
            refreshBtn.disabled = false;
        }, 1000);
    });
    container.appendChild(refreshBtn);
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function dismissInlineFlash() {
    var inlineFlash = document.getElementById('global-flash-alert');
    if (inlineFlash) inlineFlash.remove();
}

// Additional functionality will be added as needed...
// This file is structured to be PHP 7.3 compatible (no modern JS features)



function initializeHLSGeneration() {
    const generateHLSBtn = document.getElementById('generate-hls-btn');
    const hlsModal = document.getElementById('hls-modal');
    
    if (generateHLSBtn && hlsModal) {
        // Console logging functions
        function logToConsole(message, type = 'info') {
            var consoleContent = document.getElementById('hls-console-content');
            if (consoleContent) {
                var logEntry = document.createElement('div');
                logEntry.className = 'log-entry log-' + type;
                logEntry.textContent = '[' + new Date().toLocaleTimeString() + '] ' + message;
                consoleContent.appendChild(logEntry);
                consoleContent.scrollTop = consoleContent.scrollHeight;
            }
            // Also log to browser console
            console.log('[HLS Generation] ' + message);
        }
        
        // Update progress and stats
        function updateProgress(processed, total, failed = 0) {
            var progressFill = hlsModal.querySelector('.progress-fill');
            var processedEl = document.getElementById('hls-processed-videos');
            var totalEl = document.getElementById('hls-total-videos');
            var failedEl = document.getElementById('hls-failed-videos');
            
            if (progressFill && total > 0) {
                var percent = Math.round((processed / total) * 100);
                progressFill.style.width = percent + '%';
            }
            
            if (processedEl) processedEl.textContent = processed;
            if (totalEl) totalEl.textContent = total;
            if (failedEl) failedEl.textContent = failed;
        }
        
        // Show HLS modal
        function showHLSModal() {
            hlsModal.style.display = 'flex';
            // Reset stats
            updateProgress(0, 0, 0);
            // Clear console
            var consoleContent = document.getElementById('hls-console-content');
            if (consoleContent) consoleContent.innerHTML = '';
            // Reset status
            var statusEl = document.getElementById('hls-status');
            if (statusEl) statusEl.textContent = 'Initializing HLS generation...';
        }
        
        // Hide HLS modal
        function hideHLSModal() {
            hlsModal.style.display = 'none';
        }
        
        // Start HLS generation
        function startHLSGeneration() {
            showHLSModal();
            logToConsole('Starting HLS generation process...', 'info');
            
            // For HLS generation, we'll process all videos in the videos directory
            var statusEl = document.getElementById('hls-status');
            if (statusEl) statusEl.textContent = 'Scanning videos directory...';
            
            // Send AJAX request to generate HLS for all videos
            fetch('hls_streamer.php?action=batch_generate_hls', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'videos=all'
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    var totalVideos = data.results.length;
                    var successCount = 0;
                    var errorCount = 0;
                    
                    data.results.forEach(function(result) {
                        if (result.success) {
                            successCount++;
                        } else {
                            errorCount++;
                        }
                    });
                    
                    logToConsole('HLS generation completed! Success: ' + successCount + ', Errors: ' + errorCount, 'success');
                    updateProgress(successCount, totalVideos, errorCount);
                    
                    if (statusEl) {
                        statusEl.textContent = 'Completed: ' + successCount + ' generated, ' + errorCount + ' failed';
                    }
                    
                    // Auto-hide modal after 5 seconds
                    setTimeout(function() {
                        hideHLSModal();
                        // Refresh the page to show updated video counts
                        location.reload();
                    }, 5000);
                    
                } else {
                    logToConsole('Error: ' + (data.error || 'Unknown error occurred'), 'error');
                    if (statusEl) statusEl.textContent = 'Error: ' + (data.error || 'Unknown error occurred');
                }
            })
            .catch(function(error) {
                logToConsole('Error: ' + error.message, 'error');
                if (statusEl) statusEl.textContent = 'Error: ' + error.message;
            });
        }
        
        // Button click handler
        generateHLSBtn.addEventListener('click', function() {
            if (confirm('Generate HLS streams for all videos? This will create adaptive streaming versions and may take a very long time depending on the number and size of videos.')) {
                startHLSGeneration();
            }
        });
    }
}

function initializeProcessStopping() {
    const stopProcessesBtn = document.getElementById('stop-processes-btn');
    if (!stopProcessesBtn) return;

    // Check for running processes on page load
    checkRunningProcesses();
    
    // Check every 10 seconds for running processes
    setInterval(checkRunningProcesses, 10000);

    stopProcessesBtn.addEventListener('click', function() {
        if (!confirm('Are you sure you want to stop all running processes? This will terminate any ongoing thumbnail generation or preview generation.')) {
            return;
        }

        stopProcessesBtn.textContent = '⏹️ Stopping...';
        stopProcessesBtn.disabled = true;

        fetch('api.php?action=stop_all_processes')
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const message = `Stopped ${data.stopped_count} processes.`;
                    if (data.errors && data.errors.length > 0) {
                        alert(message + '\n\nErrors:\n' + data.errors.join('\n'));
                    } else {
                        alert(message);
                    }
                    location.reload(); // Refresh to show updated status
                } else {
                    alert('Failed to stop processes: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Process stop error:', error);
                alert('Error stopping processes: ' + error.message);
            })
            .finally(() => {
                setTimeout(() => {
                    stopProcessesBtn.textContent = '⏹️ Stop All Processes';
                    stopProcessesBtn.disabled = false;
                }, 1000);
            });
    });
}

function checkRunningProcesses() {
    const stopProcessesBtn = document.getElementById('stop-processes-btn');
    if (!stopProcessesBtn) return;

    // Check if any modals are visible (indicating processes are running)
    const thumbnailModal = document.getElementById('thumbnail-modal');
    const previewModal = document.getElementById('preview-modal');
    
    const isAnyProcessRunning = (
        (thumbnailModal && thumbnailModal.style.display === 'flex') ||
        (previewModal && previewModal.style.display === 'flex')
    );

    if (isAnyProcessRunning) {
        stopProcessesBtn.textContent = '⏹️ Stop Running Processes';
        stopProcessesBtn.disabled = false;
        stopProcessesBtn.className = 'btn btn-danger';
    } else {
        stopProcessesBtn.textContent = '⏹️ Stop All Processes';
        stopProcessesBtn.disabled = false;
        stopProcessesBtn.className = 'btn btn-secondary';
    }
}
