/**
 * DRAG & DROP UPLOAD SYSTEM
 * ⭐ MIS À JOUR : Ajout du SVG final (style vidéo / code de Claude)
 */

class DragDropUpload {
    constructor(options = {}) {
        this.dropZoneId = options.dropZoneId || 'dropZone';
        this.fileInputId = options.fileInputId || 'fileInput';
        this.previewContainerId = options.previewContainerId || 'filePreview';
        this.maxFileSize = options.maxFileSize || 20 * 1024 * 1024; // 20 Mo
        this.allowedTypes = options.allowedTypes || ['image/png', 'image/jpeg', 'image/jpg', 'application/pdf', 'image/gif', 'image/webp'];
        this.maxFiles = options.maxFiles || 5;
        this.files = [];
        
        this.init();
    }

    init() {
        // On garde le style des Previews, mais le style principal est dans style.css
        this.injectPreviewStyles();
        this.setupDropZone();
        this.attachEventListeners();
    }

    setupDropZone() {
        const dropZone = document.getElementById(this.dropZoneId);
        if (!dropZone) return;

        // ⭐ NOUVEAU HTML (de Claude, le bon SVG)
        dropZone.innerHTML = `
            <div class="custom-drop-zone-icon">
                <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 100%; height: 100%;">
                    <path d="M90 55C90 50.8 88.8 46.9 86.7 43.6C84.2 31.8 73.6 23 61 23C51.8 23 43.6 28.1 39.4 35.8C37.3 35.3 35.2 35 33 35C21.4 35 12 44.4 12 56C12 67.6 21.4 77 33 77H87C98.6 77 108 67.6 108 56C108 45.2 100.1 36.1 90 55Z" fill="currentColor"/>
                    <path d="M60 45L60 85M60 45L50 55M60 45L70 55" stroke="white" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/>
                <!-- ⭐ CORRECTION : Remplacement de l'icône SVG par une version plus claire -->
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 100%; height: 100%;">
                    <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4C9.11 4 6.6 5.64 5.35 8.04C2.34 8.36 0 10.91 0 14C0 17.31 2.69 20 6 20H19C21.76 20 24 17.76 24 15C24 12.36 21.95 10.22 19.35 10.04Z" fill="currentColor"/>
                    <path d="M12 11L12 17M12 11L10 13M12 11L14 13" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <p class="custom-drop-zone-text"><strong>Glissez-déposez ou Cliquez ici</strong> pour uploader</p>
            <small class="custom-drop-zone-subtext">Max ${this.maxFiles} fichiers • ${(this.maxFileSize / 1024 / 1024).toFixed(0)} Mo par fichier</small>
            <div class="drop-zone-error" id="dropZoneError"></div>
        `;
    }
    
    injectPreviewStyles() {
        // On garde les styles pour les previews, car ils sont dynamiques
        if (document.getElementById('dragDropPreviewStyles')) return;
        
        // On supprime aussi l'ancien style "dragDropStyles" au cas où
        const oldStyle = document.getElementById('dragDropStyles');
        if (oldStyle) oldStyle.remove();
        
        const style = document.createElement('style');
        style.id = 'dragDropPreviewStyles';
        style.textContent = `
            .file-preview-container {
                margin-top: 20px;
            }
            .file-preview-item {
                display: flex; align-items: center; gap: 15px;
                padding: 15px; background: white;
                border: 2px solid var(--gray-200);
                border-radius: 12px; margin-bottom: 10px;
                transition: all 0.3s; animation: slideInUp 0.3s ease;
            }
            @keyframes slideInUp {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .file-preview-thumbnail {
                width: 60px; height: 60px; border-radius: 8px;
                background: var(--gray-100); display: flex;
                align-items: center; justify-content: center;
                flex-shrink: 0; overflow: hidden;
            }
            .file-preview-thumbnail img {
                width: 100%; height: 100%; object-fit: cover;
            }
            .file-preview-thumbnail-icon { font-size: 32px; }
            .file-preview-info { flex: 1; min-width: 0; }
            .file-preview-name {
                font-weight: 600; color: var(--gray-900);
                margin-bottom: 5px; white-space: nowrap;
                overflow: hidden; text-overflow: ellipsis;
            }
            .file-preview-size { font-size: 13px; color: var(--gray-600); }
            .file-preview-remove {
                background: var(--danger); color: white; border: none;
                width: 32px; height: 32px; border-radius: 50%;
                cursor: pointer; font-size: 18px;
                display: flex; align-items: center; justify-content: center;
                transition: all 0.3s; flex-shrink: 0;
            }
            .file-preview-remove:hover {
                background: #dc2626; transform: rotate(90deg) scale(1.1);
            }
            .file-status {
                display: inline-flex; align-items: center; gap: 5px;
                padding: 4px 10px; border-radius: 12px;
                font-size: 12px; font-weight: 600;
            }
            .file-status.ready { background: #d1fae5; color: #065f46; }
            .file-status.uploading { background: #dbeafe; color: #1e40af; }
            .file-status.error { background: #fee2e2; color: #991b1b; }
            .drop-zone-error {
                background: #fee2e2; color: #991b1b;
                padding: 12px; border-radius: 8px;
                margin-top: 15px; font-size: 14px;
                display: none; animation: shake 0.5s;
            }
            @keyframes shake {
                0%, 100% { transform: translateX(0); }
                25% { transform: translateX(-10px); }
                75% { transform: translateX(10px); }
            }
            .drop-zone-error.active { display: block; }
            .file-input-hidden { display: none; }
        `;
        document.head.appendChild(style);
    }

    attachEventListeners() {
        const dropZone = document.getElementById(this.dropZoneId);
        const fileInput = document.getElementById(this.fileInputId);
        if (!dropZone || !fileInput) return;
        dropZone.addEventListener('click', () => {
            fileInput.click();
        });
        fileInput.addEventListener('change', (e) => {
            this.handleFiles(e.target.files);
        });
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
        });
        dropZone.addEventListener('dragenter', () => {
            dropZone.classList.add('drag-over');
        });
        dropZone.addEventListener('dragleave', (e) => {
            dropZone.classList.remove('drag-over');
        });
        dropZone.addEventListener('drop', (e) => {
            dropZone.classList.remove('drag-over');
            const files = e.dataTransfer.files;
            this.handleFiles(files);
        });
    }

    handleFiles(fileList) {
        const files = Array.from(fileList);
        if (this.files.length + files.length > this.maxFiles) {
            this.showError(`Vous ne pouvez ajouter que ${this.maxFiles} fichiers maximum`);
            return;
        }
        files.forEach(file => {
            if (this.validateFile(file)) {
                this.addFile(file);
            }
        });
        this.renderPreview();
    }

    validateFile(file) {
        if (!this.allowedTypes.includes(file.type)) {
            this.showError(`Type de fichier non autorisé : ${file.name}`);
            return false;
        }
        if (file.size > this.maxFileSize) {
            this.showError(`Fichier trop volumineux : ${file.name} (max ${(this.maxFileSize / 1024 / 1024).toFixed(0)} Mo)`);
            return false;
        }
        return true;
    }

    addFile(file) {
        const fileObj = {
            id: Date.now() + Math.random(),
            file: file,
            name: file.name,
            size: file.size,
            type: file.type,
            preview: null,
            status: 'ready'
        };
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => {
                fileObj.preview = e.target.result;
                this.renderPreview();
            };
            reader.readAsDataURL(file);
        }
        this.files.push(fileObj);
    }

    removeFile(fileId) {
        this.files = this.files.filter(f => f.id !== fileId);
        this.renderPreview();
        const fileInput = document.getElementById(this.fileInputId);
        if (fileInput) {
            fileInput.value = '';
        }
    }

    renderPreview() {
        const container = document.getElementById(this.previewContainerId);
        if (!container) return;
        if (this.files.length === 0) {
            container.innerHTML = '';
            return;
        }
        container.innerHTML = `
            <div class="file-preview-container">
                ${this.files.map(file => this.renderFileItem(file)).join('')}
            </div>
        `;
        this.files.forEach(file => {
            const removeBtn = document.getElementById(`remove-${file.id}`);
            if (removeBtn) {
                removeBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.removeFile(file.id);
                });
            }
        });
    }

    renderFileItem(file) {
        const sizeInKB = (file.size / 1024).toFixed(2);
        const icon = file.type.includes('pdf') ? '📄' : file.type.includes('image') ? '🖼️' : '📎';
        
        return `
            <div class="file-preview-item">
                <div class="file-preview-thumbnail">
                    ${file.preview ? 
                        `<img src="${file.preview}" alt="${file.name}">` : 
                        `<div class="file-preview-thumbnail-icon">${icon}</div>`
                    }
                </div>
                <div class="file-preview-info">
                    <div class="file-preview-name">${file.name}</div>
                    <div class="file-preview-size">${sizeInKB} Ko</div>
                    <div class="file-status ${file.status}">
                        ${file.status === 'ready' ? '✓ Prêt' : 
                          file.status === 'uploading' ? '⏳ Upload...' : 
                          '❌ Erreur'}
                    </div>
                </div>
                <button class="file-preview-remove" id="remove-${file.id}" title="Supprimer">×</button>
            </div>
        `;
    }

    showError(message) {
        const errorDiv = document.getElementById('dropZoneError');
        if (errorDiv) {
            errorDiv.textContent = message;
            errorDiv.classList.add('active');
            
            setTimeout(() => {
                errorDiv.classList.remove('active');
            }, 5000);
        }
    }

    getFiles() {
        return this.files.map(f => f.file);
    }

    clear() {
        this.files = [];
        this.renderPreview();
        const fileInput = document.getElementById(this.fileInputId);
        if (fileInput) {
            fileInput.value = '';
        }
    }

    async uploadFiles(ticketId) {
        let allUploadsSuccess = true;
        for (let i = 0; i < this.files.length; i++) {
            const fileObj = this.files[i];
            fileObj.status = 'uploading';
            this.renderPreview();
            
            const singleFileFormData = new FormData();
            singleFileFormData.append('ticket_id', ticketId);
            singleFileFormData.append('file', fileObj.file);

            try {
                const res = await fetch('api.php?action=ticket_upload_file', {
                    method: 'POST',
                    body: singleFileFormData
                });
                const data = await res.json();
                if (data.success) {
                    fileObj.status = 'ready';
                } else {
                    fileObj.status = 'error';
                    allUploadsSuccess = false;
                }
            } catch (error) {
                console.error('Erreur upload:', error);
                fileObj.status = 'error';
                allUploadsSuccess = false;
            }
            this.renderPreview();
        }
        return allUploadsSuccess;
    }
}

let dragDropUpload;

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('dropZone')) {
        dragDropUpload = new DragDropUpload({
            dropZoneId: 'dropZone',
            fileInputId: 'ticketFile', 
            previewContainerId: 'filePreview',
            maxFileSize: 20 * 1024 * 1024,
            allowedTypes: ['image/png', 'image/jpeg', 'image/jpg', 'application/pdf', 'image/gif', 'image/webp'],
            maxFiles: 5
        });
    }
    
    // On cherche aussi la dropzone de l'admin au cas où
    // Note: l'init de celle-ci est gérée dans `viewTicket` dans admin-script.js
});