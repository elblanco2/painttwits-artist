/**
 * Painttwits Artist Portfolio - Upload Handler
 * Drag-and-drop + click-to-upload with painttwits sync
 */

(function() {
    'use strict';

    var dropzone = document.getElementById('dropzone');
    var fileInput = document.getElementById('file-input');

    if (!dropzone || !fileInput) return;

    // Click to upload
    dropzone.addEventListener('click', function() {
        fileInput.click();
    });

    fileInput.addEventListener('change', function() {
        handleFiles(fileInput.files);
    });

    // Drag and drop
    dropzone.addEventListener('dragover', function(e) {
        e.preventDefault();
        dropzone.classList.add('dragover');
    });

    dropzone.addEventListener('dragleave', function() {
        dropzone.classList.remove('dragover');
    });

    dropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        dropzone.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });

    function handleFiles(files) {
        if (!files.length) return;

        // Create progress container
        var progressContainer = document.querySelector('.upload-progress');
        if (!progressContainer) {
            progressContainer = document.createElement('div');
            progressContainer.className = 'upload-progress';
            dropzone.parentNode.insertBefore(progressContainer, dropzone.nextSibling);
        }

        Array.from(files).forEach(function(file) {
            // Check if HEIC/HEIF file (check extension first, then MIME type)
            var lowerName = file.name.toLowerCase();
            var isHeic = lowerName.endsWith('.heic') ||
                         lowerName.endsWith('.heif') ||
                         file.type === 'image/heic' ||
                         file.type === 'image/heif';

            console.log('File:', file.name, 'Type:', file.type, 'isHeic:', isHeic);

            if (!file.type.startsWith('image/') && !isHeic) {
                return;
            }

            var item = document.createElement('div');
            item.className = 'upload-item';
            item.innerHTML = '<span class="name">' + escapeHtml(file.name) + '</span><span class="status">' + (isHeic ? 'converting...' : 'uploading...') + '</span>';
            progressContainer.appendChild(item);

            if (isHeic) {
                // Convert HEIC to JPEG before uploading
                convertHeicAndUpload(file, item);
            } else {
                uploadFile(file, item);
            }
        });
    }

    function convertHeicAndUpload(file, item) {
        // Check if heic2any is available
        if (typeof heic2any === 'undefined') {
            item.classList.add('error');
            item.querySelector('.status').textContent = 'HEIC conversion not available';
            return;
        }

        console.log('Starting HEIC conversion for:', file.name);

        heic2any({
            blob: file,
            toType: 'image/jpeg',
            quality: 0.92
        })
        .then(function(convertedBlob) {
            // Create a new File object with .jpg extension
            var newName = file.name.replace(/\.heic$/i, '.jpg').replace(/\.heif$/i, '.jpg');
            console.log('Converted! New filename:', newName, 'Blob type:', convertedBlob.type, 'Size:', convertedBlob.size);

            var convertedFile = new File([convertedBlob], newName, { type: 'image/jpeg' });
            console.log('Created File object:', convertedFile.name, convertedFile.type);

            item.querySelector('.status').textContent = 'uploading...';
            uploadFile(convertedFile, item);
        })
        .catch(function(err) {
            console.error('HEIC conversion error:', err);
            item.classList.add('error');
            item.querySelector('.status').textContent = 'conversion failed: ' + (err.message || err);
        });
    }

    function uploadFile(file, item) {
        var formData = new FormData();
        formData.append('artwork', file);

        fetch('upload.php', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            console.log('Upload response:', res);
            if (res.success) {
                item.classList.add('success');

                // Show sync status
                if (res.synced_to_painttwits) {
                    item.querySelector('.status').textContent = 'done + synced';
                    console.log('Sync SUCCESS:', res.sync_debug);
                } else {
                    item.querySelector('.status').textContent = 'done (not synced)';
                    console.warn('Sync FAILED:', res.sync_error, res.sync_debug);
                }

                // Reload after short delay to show new artwork
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                item.classList.add('error');
                item.querySelector('.status').textContent = res.error || 'failed';
                console.error('Upload failed:', res);
            }
        })
        .catch(function() {
            item.classList.add('error');
            item.querySelector('.status').textContent = 'failed';
        });
    }

    function escapeHtml(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // Delete artwork function (global)
    window.deleteArtwork = function(filename) {
        if (!confirm('Delete this artwork? This will also remove it from the main painttwits.com feed.')) return;

        fetch('delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ filename: filename })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            console.log('Delete response:', res);
            if (res.success) {
                // Remove from DOM
                var artwork = document.querySelector('.artwork[data-filename="' + filename + '"]');
                if (artwork) {
                    artwork.style.opacity = '0';
                    setTimeout(function() { artwork.remove(); }, 300);
                }

                // Log sync status
                if (res.synced_to_painttwits) {
                    console.log('Delete synced to painttwits.com');
                } else if (res.sync_error) {
                    console.warn('Delete sync failed:', res.sync_error);
                }
            } else {
                alert(res.error || 'Delete failed');
            }
        })
        .catch(function(err) {
            console.error('Delete error:', err);
            alert('Delete failed');
        });
    };

    // Update artwork status (global)
    window.updateStatus = function(filename, status) {
        fetch('update_meta.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ filename: filename, field: 'status', value: status })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                // Update status dot
                var artwork = document.querySelector('.artwork[data-filename="' + filename + '"]');
                if (artwork) {
                    artwork.setAttribute('data-status', status);
                    var dot = artwork.querySelector('.status-dot');
                    if (dot) {
                        dot.className = 'status-dot status-' + status;
                        dot.title = status.charAt(0).toUpperCase() + status.slice(1);
                    }
                }
            } else {
                alert(res.error || 'Update failed');
            }
        })
        .catch(function() {
            alert('Update failed');
        });
    };

    // Lightbox for viewing artwork
    var artworks = document.querySelectorAll('.artwork img');
    if (artworks.length > 0) {
        var lightbox = document.createElement('div');
        lightbox.className = 'lightbox';
        lightbox.innerHTML = '<span class="lightbox-close">&times;</span><img src="" alt="">';
        document.body.appendChild(lightbox);

        var lightboxImg = lightbox.querySelector('img');

        artworks.forEach(function(img) {
            img.addEventListener('click', function() {
                lightboxImg.src = img.src;
                lightbox.classList.add('active');
            });
        });

        lightbox.addEventListener('click', function() {
            lightbox.classList.remove('active');
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                lightbox.classList.remove('active');
            }
        });
    }

    // Editable titles - save on blur
    document.querySelectorAll('.editable-title').forEach(function(input) {
        var originalValue = input.value;
        input.addEventListener('focus', function() {
            originalValue = input.value;
        });
        input.addEventListener('blur', function() {
            var newValue = input.value.trim();
            if (newValue !== originalValue) {
                var filename = input.getAttribute('data-filename');
                fetch('update_meta.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ filename: filename, field: 'title', value: newValue })
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) {
                        alert(res.error || 'Failed to save title');
                        input.value = originalValue;
                    }
                })
                .catch(function() {
                    alert('Failed to save title');
                    input.value = originalValue;
                });
            }
        });
    });

    // Editable tags - save on blur
    document.querySelectorAll('.editable-tags').forEach(function(input) {
        var originalValue = input.value;
        input.addEventListener('focus', function() {
            originalValue = input.value;
        });
        input.addEventListener('blur', function() {
            var newValue = input.value.trim();
            if (newValue !== originalValue) {
                var filename = input.getAttribute('data-filename');
                fetch('update_meta.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ filename: filename, field: 'tags', value: newValue })
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) {
                        alert(res.error || 'Failed to save tags');
                        input.value = originalValue;
                    } else {
                        // Update the artwork data-tags attribute
                        var artwork = input.closest('.artwork');
                        if (artwork && res.value) {
                            artwork.setAttribute('data-tags', res.value.join(','));
                        }
                    }
                })
                .catch(function() {
                    alert('Failed to save tags');
                    input.value = originalValue;
                });
            }
        });
    });
})();
