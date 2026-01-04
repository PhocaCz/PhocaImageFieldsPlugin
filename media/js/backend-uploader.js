/* @package Joomla
 * @copyright Copyright (C) Open Source Matters. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * @extension Phoca Extension
 * @copyright Copyright (C) Jan Pavelka www.phoca.cz
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */
document.addEventListener('DOMContentLoaded', () => {
    const wrappers = document.querySelectorAll('.phocaimage-wrapper');

    wrappers.forEach(wrapper => {
        new PhocaImageUploader(wrapper);
    });
});

class PhocaImageUploader {
    constructor(wrapper) {

        this.wrapper = wrapper;
        this.dataInput = wrapper.querySelector('.phocaimage-data');
        this.dropzone = wrapper.querySelector('.phocaimage-dropzone');
        this.fileInput = wrapper.querySelector('.phocaimage-file-input');
        this.selectBtn = wrapper.querySelector('.phocaimage-select-btn');
        this.deleteAllBtn = wrapper.querySelector('.phocaimage-delete-all-btn');
        this.gallery = wrapper.querySelector('.phocaimage-gallery');
        this.progressBar = wrapper.querySelector('.phocaimage-progress-bar');
        this.errorContainer = wrapper.querySelector('.phocaimage-error');

        // Internal State
        this.images = [];
        try {
            const rawValue = this.dataInput.value;
            if (rawValue && rawValue !== 'null') {
                this.images = JSON.parse(rawValue) || [];
            }
        } catch (e) {
            console.error('Failed to parse initial value', e);
            this.images = [];
        }

        // Config
        this.config = {
            uploadUrl: wrapper.dataset.uploadUrl,
            deleteUrl: wrapper.dataset.deleteUrl,
            uploadPath: wrapper.dataset.uploadPath,
            articleId: wrapper.dataset.articleId,
            fieldId: wrapper.dataset.fieldId,
            csrfToken: wrapper.dataset.csrfToken,
            enableCaption: parseInt(wrapper.dataset.enableCaption) === 1,
            enableDeleteAll: parseInt(wrapper.dataset.enableDeleteAll) === 1
        };

        this.init();
    }

    init() {
        this.initDragAndDrop();
        this.initSelectButton();
        this.initSortable();
        this.initDeleteButtons();
        this.initCaptionInputs();
        this.initDeleteAllButton();
    }

    initDragAndDrop() {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            this.dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
            }, false);
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            this.dropzone.addEventListener(eventName, () => {
                this.dropzone.classList.add('highlight');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            this.dropzone.addEventListener(eventName, () => {
                this.dropzone.classList.remove('highlight');
            }, false);
        });

        this.dropzone.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            this.handleFiles(files);
        }, false);
    }

    initSelectButton() {
        this.selectBtn.addEventListener('click', () => {
            this.fileInput.click();
        });

        this.fileInput.addEventListener('change', () => {
            this.handleFiles(this.fileInput.files);
        });
    }

    initSortable() {
        // Ensure Sortable is available
        if (typeof Sortable === 'undefined') {
            console.error('SortableJS is not loaded. Sorting will be disabled.');
            return;
        }

        try {
            new Sortable(this.gallery, {
                animation: 150,
                onEnd: () => {
                    this.updateStateFromDOM();
                }
            });
        } catch (e) {
            console.error('Error initializing Sortable:', e);
        }
    }

    initDeleteButtons() {
        this.gallery.addEventListener('click', (e) => {
            if (e.target.closest('.phocaimage-delete-btn')) {
                const item = e.target.closest('.phocaimage-item');
                this.deleteImage(item);
            }
        });
    }

    initCaptionInputs() {
        this.gallery.addEventListener('input', (e) => {
            if (e.target.classList.contains('phocaimage-caption-input')) {
                this.updateStateFromDOM();
            }
        });
    }

    initDeleteAllButton() {
        if (!this.deleteAllBtn) return;
        this.deleteAllBtn.addEventListener('click', () => {
            this.deleteAllImages();
        });
    }

    handleFiles(files) {
        if (!files.length) return;

        const formData = new FormData();
        formData.append(this.config.csrfToken, 1);
        formData.append('article_id', this.config.articleId);
        formData.append('field_id', this.config.fieldId);

        for (let i = 0; i < files.length; i++) {
            formData.append('phocaimage_files[]', files[i]);
        }

        this.uploadFiles(formData);
    }

    uploadFiles(formData) {
        this.progressBar.style.display = 'block';
        this.progressBar.style.width = '0%';
        this.hideError();

        const xhr = new XMLHttpRequest();
        xhr.open('POST', this.config.uploadUrl, true);

        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) {
                const percentComplete = (e.loaded / e.total) * 100;
                this.progressBar.style.width = percentComplete + '%';
            }
        };

        xhr.onload = () => {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);

                    // Joomla com_ajax returns plugin results as an array in 'data'
                    let pluginResult = null;
                    if (Array.isArray(response.data)) {
                        if (response.data.length > 0) {
                            pluginResult = response.data[0];
                        } else {
                            console.error('Plugin event did not return a value or set the result argument.');
                        }
                    } else if (response.data && typeof response.data === 'object') {
                        pluginResult = response.data;
                    }

                    if (pluginResult && pluginResult.success) {
                        this.handleUploadSuccess(pluginResult);
                    } else {
                        let errorMsg = Joomla.Text._('PLG_FIELDS_PHOCAIMAGE_ERROR_UPLOAD_FAILED');
                        if (pluginResult && pluginResult.message) {
                            errorMsg = pluginResult.message;
                        } else if (response.message) {
                            errorMsg = response.message;
                        }

                        this.showError(errorMsg + ' (' + Joomla.Text._('PLG_FIELDS_PHOCAIMAGE_ERROR_CHECK_CONSOLE_FOR_DETAILS') + ')');
                    }
                } catch (e) {
                    this.showError(Joomla.Text._('PLG_FIELDS_PHOCAIMAGE_ERROR_INVALID_SERVER_RESPONSE'));
                }
            } else {
                this.showError(Joomla.Text._('PLG_FIELDS_PHOCAIMAGE_ERROR_UPLOAD_FAILED_WITH_STATUS') + ' ' + xhr.status);
            }
            this.progressBar.style.display = 'none';
        };

        xhr.onerror = (e) => {
            console.error('Network Error:', e);
            this.showError(Joomla.Text._('PLG_FIELDS_PHOCAIMAGE_NETWORK_ERROR_OCCURED'));
            this.progressBar.style.display = 'none';
        };

        xhr.send(formData);
    }

    handleUploadSuccess(response) {

        if (response.files) {
            // 1. Update State FIRST (Persistence)
            response.files.forEach(file => {
                // Ensure we have a valid object
                if (file && file.filename) {
                    this.images.push({
                        filename: file.filename,
                        order: this.images.length + 1
                    });
                }
            });

            // 2. Commit to Input
            this.commitState();

            // 3. Update UI (Display)
            response.files.forEach(file => {
                try {
                    this.addImageToGallery(file);
                } catch (e) {
                    console.error('Error rendering image to gallery:', e, file);
                    // Do NOT stop persistence if rendering fails
                }
            });
        }
    }

    addImageToGallery(file) {
        const item = document.createElement('div');
        item.className = 'phocaimage-item';
        item.dataset.filename = file.filename;

        // Fallback checks for missing thumbnails
        let thumbFilename = file.filename;
        if (file.thumbnails && file.thumbnails.medium) {
            thumbFilename = file.thumbnails.medium;
        } else {
            console.warn('Thumbnail missing for', file.filename, '- falling back to original.');
        }

        if (!thumbFilename) {
            console.error('No filename available for display', file);
            return;
        }

        const thumbUrl = this.config.uploadPath + '/' + thumbFilename;

        let captionHtml = '';
        if (this.config.enableCaption) {
            captionHtml = `
                <div class="phocaimage-caption-container mb-2">
                    <input type="text" 
                           class="form-control form-control-sm phocaimage-caption-input" 
                           placeholder="${Joomla.Text._('PLG_FIELDS_PHOCAIMAGE_CAPTION')}"
                           title="${Joomla.Text._('PLG_FIELDS_PHOCAIMAGE_CAPTION_DESC')}"
                           value="${file.caption || ''}">
                </div>
            `;
        }

        item.innerHTML = `
            <div class="phocaimage-thumb">
                <img src="${thumbUrl}" alt="${file.filename}" loading="lazy">
            </div>
            <div class="phocaimage-info">
                ${captionHtml}
                <span class="phocaimage-filename">${file.filename}</span>
            </div>
            <div class="phocaimage-actions">
                 <button type="button" class="btn btn-danger btn-sm phocaimage-delete-btn" title="${Joomla.Text._('PLG_FIELDS_PHOCAIMAGE_DELETE')}">
                    <span class="icon-trash" aria-hidden="true"></span>
                </button>
            </div>
        `;

        this.gallery.appendChild(item);
    }

    deleteImage(item) {
        if (!confirm(Joomla.Text._('PLG_FIELDS_PHOCAIMAGE_ARE_YOU_SURE_DELETE_IMAGE'))) return;

        const filename = item.dataset.filename;
        const formData = new FormData();
        formData.append(this.config.csrfToken, 1);
        formData.append('filename', filename);
        formData.append('article_id', this.config.articleId);
        formData.append('field_id', this.config.fieldId);
        formData.append('action', 'delete'); // Ensure action is sent

        fetch(this.config.deleteUrl, {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                const result = data.data || data;

                if (result.success) {
                    // Update State
                    this.images = this.images.filter(img => img.filename !== filename);
                    this.commitState();

                    // Update UI
                    item.remove();
                } else {
                    alert(result.message || Joomla.Text._('PLG_FIELDS_PHOCAIMAGE_ERROR_FAILED_DELETE_IMAGE'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert(Joomla.Text._('PLG_FIELDS_PHOCAIMAGE_ERROR_WHILE_DELETING'));
            });
    }

    async deleteAllImages() {
        if (!confirm(Joomla.Text._('PLG_FIELDS_PHOCAIMAGE_CONFIRM_DELETE_ALL'))) return;

        const items = Array.from(this.gallery.querySelectorAll('.phocaimage-item'));
        if (items.length === 0) return;

        // Sequential delete to not overwhelm the server and reuse existing logic
        for (const item of items) {
            const filename = item.dataset.filename;
            const formData = new FormData();
            formData.append(this.config.csrfToken, 1);
            formData.append('filename', filename);
            formData.append('article_id', this.config.articleId);
            formData.append('field_id', this.config.fieldId);
            formData.append('action', 'delete');

            try {
                const response = await fetch(this.config.deleteUrl, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                const result = data.data || data;

                if (result.success) {
                    this.images = this.images.filter(img => img.filename !== filename);
                    item.remove();
                } else {
                    console.error('Failed to delete ' + filename, result.message);
                }
            } catch (error) {
                console.error('Error deleting ' + filename, error);
            }
        }

        this.commitState();
    }

    updateStateFromDOM() {
        // Called by Sortable onEnd
        const newImages = [];
        const items = this.gallery.querySelectorAll('.phocaimage-item');

        items.forEach((item, index) => {
            const captionInput = item.querySelector('.phocaimage-caption-input');
            const caption = captionInput ? captionInput.value : '';

            newImages.push({
                filename: item.dataset.filename,
                order: index + 1,
                caption: caption
            });
        });

        this.images = newImages;
        this.commitState();
    }

    commitState() {
        // Serialize internal state to hidden input
        // This is what Joomla saves to database
        const json = JSON.stringify(this.images);
        this.dataInput.value = json;
    }

    showError(msg) {
        this.errorContainer.textContent = msg;
        this.errorContainer.style.display = 'block';
        setTimeout(() => {
            this.errorContainer.style.display = 'none';
        }, 8000);
    }

    hideError() {
        this.errorContainer.style.display = 'none';
    }
}
