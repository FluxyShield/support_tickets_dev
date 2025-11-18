/**
 * @file file-viewer-system.js
 * @brief Classe pour gérer la visualisation de fichiers joints.
 * 
 * Cette classe fournit une interface modale complète pour visualiser les fichiers
 * attachés à un ticket. Elle gère :
 * - L'injection dynamique du HTML et des styles de la modale.
 * - L'affichage des images avec des contrôles de zoom.
 * - L'intégration d'un visualisateur pour les fichiers PDF.
 * - La navigation (précédent/suivant) entre les fichiers d'un même ticket.
 * - L'affichage d'une galerie de miniatures pour une sélection rapide.
 * - Le téléchargement des fichiers.
 */

class FileViewerSystem {
    constructor() {
        this.currentFiles = [];
        this.currentIndex = 0;
        this.init();
    }

    init() {
        this.injectStyles();
        this.injectHTML();
        this.attachEventListeners();
    }

    injectStyles() {
        const style = document.createElement('style');
        style.textContent = `
            /* Galerie de fichiers */
            .file-gallery {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                gap: 15px;
                margin: 20px 0;
            }

            .file-card {
                background: var(--gray-50);
                border: 2px solid var(--gray-200);
                border-radius: 12px;
                padding: 15px;
                cursor: pointer;
                transition: all 0.3s;
                position: relative;
                overflow: hidden;
            }

            .file-card:hover {
                border-color: var(--orange);
                transform: translateY(-5px);
                box-shadow: 0 8px 20px rgba(239, 128, 0, 0.2);
            }

            .file-card-preview {
                width: 100%;
                height: 120px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: white;
                border-radius: 8px;
                margin-bottom: 10px;
                overflow: hidden;
                position: relative;
            }

            .file-card-preview img {
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;
            }

            .file-card-preview .file-icon {
                font-size: 48px;
            }

            .file-card-badge {
                position: absolute;
                top: 5px;
                right: 5px;
                background: var(--orange);
                color: white;
                font-size: 10px;
                font-weight: 700;
                padding: 3px 8px;
                border-radius: 5px;
                text-transform: uppercase;
            }

            .file-card-info {
                text-align: center;
            }

            .file-card-name {
                font-weight: 600;
                font-size: 13px;
                color: var(--gray-900);
                margin-bottom: 5px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .file-card-meta {
                font-size: 11px;
                color: var(--gray-600);
            }

            .file-card-actions {
                display: flex;
                gap: 5px;
                margin-top: 10px;
            }

            .file-card-btn {
                flex: 1;
                padding: 6px;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 11px;
                font-weight: 600;
                transition: all 0.3s;
            }

            .file-card-btn.view {
                background: var(--orange);
                color: white;
            }

            .file-card-btn.view:hover {
                background: #D67200;
            }

            .file-card-btn.download {
                background: var(--gray-200);
                color: var(--gray-700);
            }

            .file-card-btn.download:hover {
                background: var(--gray-300);
            }

            /* Modal de visualisation */
            .file-viewer-modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.95);
                z-index: 10000;
                align-items: center;
                justify-content: center;
            }

            .file-viewer-modal.active {
                display: flex;
            }

            .file-viewer-container {
                width: 90%;
                height: 90%;
                display: flex;
                flex-direction: column;
                background: white;
                border-radius: 12px;
                overflow: hidden;
                animation: zoomIn 0.3s ease;
            }

            @keyframes zoomIn {
                from {
                    opacity: 0;
                    transform: scale(0.8);
                }
                to {
                    opacity: 1;
                    transform: scale(1);
                }
            }

            .file-viewer-header {
                background: var(--gray-900);
                color: white;
                padding: 15px 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .file-viewer-title {
                font-weight: 600;
                font-size: 16px;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .file-viewer-actions {
                display: flex;
                gap: 10px;
            }

            .file-viewer-btn {
                background: rgba(255, 255, 255, 0.1);
                border: none;
                color: white;
                padding: 8px 15px;
                border-radius: 6px;
                cursor: pointer;
                font-weight: 600;
                font-size: 14px;
                transition: all 0.3s;
                display: flex;
                align-items: center;
                gap: 5px;
            }

            .file-viewer-btn:hover {
                background: rgba(255, 255, 255, 0.2);
            }

            .file-viewer-btn.close {
                background: #ef4444;
            }

            .file-viewer-btn.close:hover {
                background: #dc2626;
            }

            .file-viewer-body {
                flex: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                background: var(--gray-100);
                position: relative;
                overflow: hidden;
            }

            .file-viewer-content {
                max-width: 100%;
                max-height: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .file-viewer-image {
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;
                transition: transform 0.3s;
                cursor: zoom-in;
            }

            .file-viewer-image.zoomed {
                cursor: zoom-out;
                transform: scale(2);
            }

            .file-viewer-pdf {
                width: 100%;
                height: 100%;
                border: none;
            }

            /* Navigation entre fichiers */
            .file-viewer-nav {
                position: absolute;
                top: 50%;
                transform: translateY(-50%);
                background: rgba(0, 0, 0, 0.7);
                color: white;
                border: none;
                width: 50px;
                height: 50px;
                border-radius: 50%;
                cursor: pointer;
                font-size: 24px;
                transition: all 0.3s;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .file-viewer-nav:hover {
                background: rgba(0, 0, 0, 0.9);
                transform: translateY(-50%) scale(1.1);
            }

            .file-viewer-nav.prev {
                left: 20px;
            }

            .file-viewer-nav.next {
                right: 20px;
            }

            .file-viewer-nav:disabled {
                opacity: 0.3;
                cursor: not-allowed;
            }

            /* Footer avec miniatures */
            .file-viewer-footer {
                background: var(--gray-900);
                padding: 15px;
                display: flex;
                gap: 10px;
                overflow-x: auto;
                max-height: 120px;
            }

            .file-viewer-thumb {
                width: 80px;
                height: 80px;
                border: 3px solid transparent;
                border-radius: 8px;
                cursor: pointer;
                flex-shrink: 0;
                overflow: hidden;
                transition: all 0.3s;
                background: white;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .file-viewer-thumb:hover {
                border-color: var(--orange);
            }

            .file-viewer-thumb.active {
                border-color: var(--orange);
                box-shadow: 0 0 20px rgba(239, 128, 0, 0.5);
            }

            .file-viewer-thumb img {
                max-width: 100%;
                max-height: 100%;
                object-fit: cover;
            }

            .file-viewer-thumb-icon {
                font-size: 32px;
            }

            /* Indicateur de chargement */
            .file-viewer-loading {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                text-align: center;
                color: var(--gray-600);
            }

            .file-viewer-loading-spinner {
                width: 50px;
                height: 50px;
                border: 5px solid var(--gray-300);
                border-top-color: var(--orange);
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin: 0 auto 15px;
            }

            @keyframes spin {
                to { transform: rotate(360deg); }
            }

            /* Zoom controls */
            .file-viewer-zoom-controls {
                position: absolute;
                bottom: 20px;
                right: 20px;
                display: flex;
                gap: 10px;
                background: rgba(0, 0, 0, 0.7);
                padding: 10px;
                border-radius: 8px;
            }

            .zoom-btn {
                background: white;
                border: none;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                cursor: pointer;
                font-size: 20px;
                transition: all 0.3s;
            }

            .zoom-btn:hover {
                background: var(--orange);
                color: white;
                transform: scale(1.1);
            }

            /* Info overlay */
            .file-viewer-info {
                position: absolute;
                top: 20px;
                left: 20px;
                background: rgba(0, 0, 0, 0.7);
                color: white;
                padding: 15px 20px;
                border-radius: 8px;
                font-size: 14px;
            }

            .file-viewer-info-item {
                margin: 5px 0;
            }

            /* Responsive */
            @media (max-width: 768px) {
                .file-gallery {
                    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                    gap: 10px;
                }

                .file-card-preview {
                    height: 100px;
                }

                .file-viewer-container {
                    width: 100%;
                    height: 100%;
                    border-radius: 0;
                }

                .file-viewer-nav {
                    width: 40px;
                    height: 40px;
                    font-size: 20px;
                }

                .file-viewer-nav.prev {
                    left: 10px;
                }

                .file-viewer-nav.next {
                    right: 10px;
                }

                .file-viewer-footer {
                    max-height: 100px;
                }

                .file-viewer-thumb {
                    width: 60px;
                    height: 60px;
                }

                .file-viewer-zoom-controls {
                    bottom: 10px;
                    right: 10px;
                }

                .file-viewer-info {
                    font-size: 12px;
                    padding: 10px 15px;
                }
            }

            /* Animations */
            @keyframes slideInUp {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .file-card {
                animation: slideInUp 0.3s ease forwards;
            }

            .file-card:nth-child(1) { animation-delay: 0.05s; }
            .file-card:nth-child(2) { animation-delay: 0.1s; }
            .file-card:nth-child(3) { animation-delay: 0.15s; }
            .file-card:nth-child(4) { animation-delay: 0.2s; }
            .file-card:nth-child(5) { animation-delay: 0.25s; }
            .file-card:nth-child(6) { animation-delay: 0.3s; }
        `;
        document.head.appendChild(style);
    }

    injectHTML() {
        const modal = document.createElement('div');
        modal.className = 'file-viewer-modal';
        modal.id = 'fileViewerModal';
        modal.innerHTML = `
            <div class="file-viewer-container">
                <div class="file-viewer-header">
                    <div class="file-viewer-title">
                        <span id="fileViewerIcon">📄</span>
                        <span id="fileViewerTitle">Fichier</span>
                    </div>
                    <div class="file-viewer-actions">
                        <button class="file-viewer-btn" id="fileViewerDownload">
                            ⬇️ Télécharger
                        </button>
                        <button class="file-viewer-btn close" id="fileViewerClose">
                            ✖ Fermer
                        </button>
                    </div>
                </div>
                <div class="file-viewer-body">
                    <button class="file-viewer-nav prev" id="fileViewerPrev">‹</button>
                    <button class="file-viewer-nav next" id="fileViewerNext">›</button>
                    
                    <div class="file-viewer-loading" id="fileViewerLoading" style="display:none;">
                        <div class="file-viewer-loading-spinner"></div>
                        <p>Chargement du fichier...</p>
                    </div>

                    <div class="file-viewer-info" id="fileViewerInfo" style="display:none;">
                        <div class="file-viewer-info-item">
                            <strong>Nom:</strong> <span id="infoFileName">-</span>
                        </div>
                        <div class="file-viewer-info-item">
                            <strong>Taille:</strong> <span id="infoFileSize">-</span>
                        </div>
                        <div class="file-viewer-info-item">
                            <strong>Type:</strong> <span id="infoFileType">-</span>
                        </div>
                        <div class="file-viewer-info-item">
                            <strong>Envoyé par:</strong> <span id="infoUploadedBy">-</span>
                        </div>
                    </div>
                    
                    <div class="file-viewer-content" id="fileViewerContent"></div>
                    
                    <div class="file-viewer-zoom-controls" id="fileViewerZoomControls" style="display:none;">
                        <button class="zoom-btn" id="zoomIn" title="Zoom avant">+</button>
                        <button class="zoom-btn" id="zoomOut" title="Zoom arrière">−</button>
                        <button class="zoom-btn" id="zoomReset" title="Réinitialiser">↺</button>
                    </div>
                </div>
                <div class="file-viewer-footer" id="fileViewerFooter"></div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    attachEventListeners() {
        // Fermer le modal
        const closeBtn = document.getElementById('fileViewerClose');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.closeViewer());
        }

        // Fermer avec Echap
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeViewer();
            }
        });

        // Navigation
        const prevBtn = document.getElementById('fileViewerPrev');
        const nextBtn = document.getElementById('fileViewerNext');
        
        if (prevBtn) prevBtn.addEventListener('click', () => this.showPrevious());
        if (nextBtn) nextBtn.addEventListener('click', () => this.showNext());

        // Navigation au clavier
        document.addEventListener('keydown', (e) => {
            const modal = document.getElementById('fileViewerModal');
            if (modal && modal.classList.contains('active')) {
                if (e.key === 'ArrowLeft') this.showPrevious();
                if (e.key === 'ArrowRight') this.showNext();
            }
        });

        // Télécharger
        const downloadBtn = document.getElementById('fileViewerDownload');
        if (downloadBtn) {
            downloadBtn.addEventListener('click', () => this.downloadCurrent());
        }

        // Zoom controls
        document.getElementById('zoomIn')?.addEventListener('click', () => this.zoomIn());
        document.getElementById('zoomOut')?.addEventListener('click', () => this.zoomOut());
        document.getElementById('zoomReset')?.addEventListener('click', () => this.zoomReset());
    }

    // Méthode pour sauvegarder les fichiers actuels (utilisation simple)
    setFiles(files) {
        this.currentFiles = files;
    }

    // Ouvrir un fichier par son ID (plus flexible)
    openFileById(fileId) {
        const index = this.currentFiles.findIndex(f => f.id === fileId);
        if (index !== -1) {
            this.openFile(index);
        }
    }

    // Méthode pour la galerie moderne (optionnelle)
    renderGallery(files, containerId = 'filesGallery') {
        const container = document.getElementById(containerId);
        if (!container || !files || files.length === 0) return;

        const html = files.map((file, index) => {
            const isImage = file.type.includes('image');
            const isPDF = file.type.includes('pdf');
            const icon = isPDF ? '📄' : isImage ? '🖼️' : '📎';
            const badge = isPDF ? 'PDF' : isImage ? 'IMG' : 'FILE';

            return `
                <div class="file-card" onclick="fileViewerSystem.openFile(${index})">
                    <div class="file-card-preview">
                        <span class="file-card-badge">${badge}</span>
                        ${isImage ? `<img src="api.php?action=ticket_download_file&file_id=${file.id}&preview=1" alt="${file.name}">` : `<div class="file-icon">${icon}</div>`}
                    </div>
                    <div class="file-card-info">
                        <div class="file-card-name" title="${file.name}">${file.name}</div>
                        <div class="file-card-meta">
                            ${(file.size / 1024).toFixed(2)} Ko
                        </div>
                        <div class="file-card-meta">
                            ${file.uploaded_by} • ${file.date}
                        </div>
                    </div>
                    <div class="file-card-actions">
                        <button class="file-card-btn view" onclick="event.stopPropagation(); fileViewerSystem.openFile(${index})">
                            👁️ Voir
                        </button>
                        <button class="file-card-btn download" onclick="event.stopPropagation(); fileViewerSystem.downloadFile(${file.id}, '${file.name}')">
                            ⬇️
                        </button>
                    </div>
                </div>
            `;
        }).join('');

        container.innerHTML = `<div class="file-gallery">${html}</div>`;
        this.currentFiles = files;
    }

    openFile(index) {
        if (!this.currentFiles || this.currentFiles.length === 0) return;

        this.currentIndex = index;
        const file = this.currentFiles[index];

        // Afficher le modal
        const modal = document.getElementById('fileViewerModal');
        modal.classList.add('active');

        // Afficher le loading
        this.showLoading(true);

        // Mettre à jour le header
        const isImage = file.type.includes('image');
        const isPDF = file.type.includes('pdf');
        const icon = isPDF ? '📄' : isImage ? '🖼️' : '📎';

        document.getElementById('fileViewerIcon').textContent = icon;
        document.getElementById('fileViewerTitle').textContent = file.name;

        // Mettre à jour les infos
        this.updateFileInfo(file);

        // Charger le contenu
        this.loadFileContent(file);

        // Mettre à jour les miniatures
        this.renderThumbnails();

        // Mettre à jour les boutons de navigation
        this.updateNavButtons();
    }

    loadFileContent(file) {
        const content = document.getElementById('fileViewerContent');
        const zoomControls = document.getElementById('fileViewerZoomControls');
        
        content.innerHTML = '';

        if (file.type.includes('image')) {
            // Image - ⭐ AJOUT du paramètre preview=1
            const img = document.createElement('img');
            img.className = 'file-viewer-image';
            img.src = `api.php?action=ticket_download_file&file_id=${file.id}&preview=1&t=${Date.now()}`;
            
            img.onload = () => {
                this.showLoading(false);
                zoomControls.style.display = 'flex';
            };

            img.onerror = (e) => {
                console.error('Erreur de chargement image:', e);
                this.showLoading(false);
                content.innerHTML = '<p style="color:white;">❌ Erreur de chargement de l\'image</p>';
            };

            // Toggle zoom au clic
            img.addEventListener('click', () => {
                img.classList.toggle('zoomed');
            });

            content.appendChild(img);

        } else if (file.type.includes('pdf')) {
            // PDF - ⭐ AJOUT du paramètre preview=1
            const iframe = document.createElement('iframe');
            iframe.className = 'file-viewer-pdf';
            iframe.src = `api.php?action=ticket_download_file&file_id=${file.id}&preview=1&t=${Date.now()}`;
            
            iframe.onload = () => {
                this.showLoading(false);
            };

            iframe.onerror = (e) => {
                console.error('Erreur de chargement PDF:', e);
                this.showLoading(false);
                content.innerHTML = '<p style="color:white;">❌ Erreur de chargement du PDF</p>';
            };

            content.appendChild(iframe);
            zoomControls.style.display = 'none';

        } else {
            // Autre type de fichier
            this.showLoading(false);
            content.innerHTML = '<p style="color:white;">⚠️ Prévisualisation non disponible pour ce type de fichier</p>';
            zoomControls.style.display = 'none';
        }
    }

    updateFileInfo(file) {
        document.getElementById('infoFileName').textContent = file.name;
        document.getElementById('infoFileSize').textContent = `${(file.size / 1024).toFixed(2)} Ko`;
        document.getElementById('infoFileType').textContent = file.type;
        document.getElementById('infoUploadedBy').textContent = file.uploaded_by;
        document.getElementById('fileViewerInfo').style.display = 'block';
    }

    renderThumbnails() {
        const footer = document.getElementById('fileViewerFooter');
        if (!footer) return;

        const html = this.currentFiles.map((file, index) => {
            const isImage = file.type.includes('image');
            const isPDF = file.type.includes('pdf');
            const icon = isPDF ? '📄' : '📎';
            const activeClass = index === this.currentIndex ? 'active' : '';

            return `
                <div class="file-viewer-thumb ${activeClass}" onclick="fileViewerSystem.openFile(${index})">
                    ${isImage ? 
                        `<img src="api.php?action=ticket_download_file&file_id=${file.id}&preview=1" alt="${file.name}">` :
                        `<div class="file-viewer-thumb-icon">${icon}</div>`
                    }
                </div>
            `;
        }).join('');

        footer.innerHTML = html;
    }

    updateNavButtons() {
        const prevBtn = document.getElementById('fileViewerPrev');
        const nextBtn = document.getElementById('fileViewerNext');

        if (prevBtn) prevBtn.disabled = this.currentIndex === 0;
        if (nextBtn) nextBtn.disabled = this.currentIndex === this.currentFiles.length - 1;
    }

    showPrevious() {
        if (this.currentIndex > 0) {
            this.openFile(this.currentIndex - 1);
        }
    }

    showNext() {
        if (this.currentIndex < this.currentFiles.length - 1) {
            this.openFile(this.currentIndex + 1);
        }
    }

    downloadCurrent() {
        if (!this.currentFiles || this.currentFiles.length === 0) return;
        const file = this.currentFiles[this.currentIndex];
        this.downloadFile(file.id, file.name);
    }

    downloadFile(fileId, fileName) {
        const link = document.createElement('a');
        link.href = `api.php?action=ticket_download_file&file_id=${fileId}`;
        link.download = fileName;
        link.click();
    }

    showLoading(show) {
        const loading = document.getElementById('fileViewerLoading');
        if (loading) {
            loading.style.display = show ? 'block' : 'none';
        }
    }

    zoomIn() {
        const img = document.querySelector('.file-viewer-image');
        if (img) {
            const currentScale = img.style.transform ? parseFloat(img.style.transform.replace(/[^\d.]/g, '')) : 1;
            img.style.transform = `scale(${Math.min(currentScale + 0.5, 5)})`;
        }
    }

    zoomOut() {
        const img = document.querySelector('.file-viewer-image');
        if (img) {
            const currentScale = img.style.transform ? parseFloat(img.style.transform.replace(/[^\d.]/g, '')) : 1;
            img.style.transform = `scale(${Math.max(currentScale - 0.5, 0.5)})`;
        }
    }

    zoomReset() {
        const img = document.querySelector('.file-viewer-image');
        if (img) {
            img.style.transform = 'scale(1)';
            img.classList.remove('zoomed');
        }
    }

    closeViewer() {
        const modal = document.getElementById('fileViewerModal');
        if (modal) {
            modal.classList.remove('active');
        }
        this.zoomReset();
    }
}

// Initialisation globale
const fileViewerSystem = new FileViewerSystem();

// Export pour utilisation dans d'autres scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = FileViewerSystem;
}