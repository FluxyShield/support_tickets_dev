<?php
/**
 * @file index.php
 * @brief Page d'accueil principale pour les utilisateurs du système de support.
 *
 * Cette page est le point d'entrée pour les utilisateurs. Elle gère deux états :
 * 1. Vue "invité" : Affiche les options pour se connecter ou s'inscrire.
 * 2. Vue "connecté" : Affiche la liste des tickets de l'utilisateur, permet la création
 *    de nouveaux tickets et l'interaction avec les tickets existants (voir détails,
 *    ajouter des messages, etc.).
 *
 * La page est construite comme une Single Page Application (SPA) partielle, où la plupart
 * des interactions (connexion, affichage des tickets, envoi de messages) sont gérées
 * de manière asynchrone via des appels API en JavaScript (fetch) sans rechargement complet.
 */
define('ROOT_PATH', __DIR__);
require_once 'config.php';
// session_name('user_session'); // REMOVED: Unified in config.php
initialize_session();
setSecurityHeaders();
$isUserLoggedIn = isset($_SESSION['user_id']);
session_write_close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">

    <link rel="stylesheet" href="style.css">
    <title>Support Descamps - Accueil</title>
    <style>
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message-sent-animation {
            position: fixed; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            background: white; padding: 40px; border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            z-index: 10000; text-align: center;
            animation: slideIn 0.3s ease;
        }
        
        .animation-icon {
            width: 80px; height: 80px; border-radius: 50%;
            background: #10b981; display: flex;
            align-items: center; justify-content: center;
            margin: 0 auto 20px;
            color: white; font-size: 50px; font-weight: bold;
        }
        
        .animation-icon.loading {
            background: var(--gray-200);
            border: 8px solid var(--gray-200);
            border-top-color: var(--orange);
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .overlay {
            position: fixed; top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999; display: none;
        }

        .overlay.active { display: block; }
        
        .rating-stars {
            display: flex; flex-direction: row-reverse; 
            justify-content: center; gap: 10px; margin-bottom: 20px;
        }
        .rating-stars input { display: none; }
        .rating-stars label {
            font-size: 40px; color: var(--gray-200);
            cursor: pointer; transition: color 0.2s;
        }
        .rating-stars input:checked ~ label,
        .rating-stars label:hover,
        .rating-stars label:hover ~ label { color: #f59e0b; }
        
        .user-rating-display {
            font-weight: 600; color: var(--gray-700);
            background: #fef3c7; padding: 8px 12px;
            border-radius: 8px; display: inline-block;
        }
        
        .btn-review {
            background: #f59e0b; color: white; border: none;
            padding: 6px 12px; font-size: 12px; border-radius: 8px;
            font-weight: 600; cursor: pointer; transition: all 0.3s;
        }
        .btn-review:hover { background: #d97706; transform: translateY(-2px); }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🎫 Support Descamps</div>
            <div class="nav-buttons">
                <button id="logoutBtn" class="btn btn-danger" onclick="logout()" style="display:none;">Déconnexion</button>
            </div>
        </div>
        <div class="content">
            <div id="guestSection" style="text-align:center; <?php if ($isUserLoggedIn) echo 'display:none;'; ?>">
                <h2 style="color:var(--gray-900);margin-bottom:20px;">Bienvenue sur le Support Descamps</h2>
                <p style="color:var(--gray-600);margin-bottom:30px;">Connectez-vous pour créer et gérer vos tickets</p>
                <div style="display:flex;gap:15px;justify-content:center;">
                    <button class="btn btn-primary" onclick="location.href='register.php'">S'inscrire</button>
                    <button class="btn btn-secondary" onclick="showLoginModal()">Se connecter</button>
                </div>
            </div>
            <div id="userSection" style="<?php if (!$isUserLoggedIn) echo 'display:none;'; ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:30px;">
                    <h2 id="welcomeMsg" style="color:var(--gray-900);"></h2>
                    <button class="btn btn-success" onclick="showCreateTicketModal()">+ Nouveau Ticket</button>
                </div>
                <div id="ticketsList"></div>
            </div>
        </div>
    </div>

    <div id="overlay" class="overlay"></div>

    <div id="loginModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Connexion</h3>
                <button class="close-modal" onclick="closeLoginModal()">&times;</button>
            </div>
            <div id="loginErrorMsg" class="error-message" style="display:none; margin-bottom: 15px;"></div>

            <form onsubmit="login(event)">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="loginEmail" required>
                </div>
                <div class="form-group">
                    <label>Mot de passe</label>
                    <input type="password" id="loginPassword" autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Se connecter</button>
            </form>
            <div style="text-align:center;margin-top:15px;">
                <a href="register.php" style="color:var(--primary);">Pas de compte ? S'inscrire</a> | 
                <a href="forgot_password.php" style="color:var(--primary);">Mot de passe oublié ?</a>
            </div>
        </div>
    </div>

    <div id="createTicketModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Nouveau Ticket</h3>
                <button class="close-modal" onclick="closeCreateTicketModal()">&times;</button>
            </div>
            <form onsubmit="createTicket(event)">
                <div class="form-group">
                    <label>Sujet *</label>
                    <input type="text" id="ticketSubject" required>
                </div>
                <div class="form-group">
                    <label>Catégorie *</label>
                    <select id="ticketCategory" required>
                        <option value="">Sélectionnez</option>
                        <option value="Technique">Technique</option>
                        <option value="Facturation">Facturation</option>
                        <option value="Compte">Compte</option>
                        <option value="Autre">Autre</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Priorité *</label>
                    <select id="ticketPriority" required>
                        <option value="">Sélectionnez</option>
                        <option value="Basse">Basse</option>
                        <option value="Moyenne">Moyenne</option>
                        <option value="Haute">Haute</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Description *</label>
                    <textarea id="ticketDescription" required></textarea>
                </div>
                <div class="form-group">
                    <label>Fichiers joints (optionnel)</label>
                    <input type="file" id="ticketFile" class="file-input-hidden" multiple accept=".png,.jpg,.jpeg,.pdf,.gif,.webp">
                    <div id="dropZone" class="custom-drop-zone"></div>
                    <div id="filePreview"></div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Créer</button>
            </form>
        </div>
    </div>

    <div id="viewTicketModal" class="modal">
        <div class="modal-content" style="max-width:800px;">
            <div class="modal-header">
                <h3>Détails du Ticket</h3>
                <button class="close-modal" onclick="closeViewTicketModal()">&times;</button>
            </div>
            <div id="ticketDetails"></div>
        </div>
    </div>

    <div id="editDescriptionModal" class="modal">
        <div class="modal-content" style="max-width:500px;">
            <div class="modal-header">
                <h3>Modifier la description</h3>
                <button class="close-modal" onclick="closeEditDescriptionModal()">&times;</button>
            </div>
            <div style="background:#fef3c7;padding:15px;border-radius:8px;margin-bottom:20px;">
                <p style="color:#92400e;font-weight:600;">⚠️ Attention</p>
                <p style="color:#92400e;margin-top:5px;">Vous ne pouvez modifier la description qu'une seule fois !</p>
            </div>
            <form onsubmit="updateDescription(event)">
                <input type="hidden" id="editTicketId">
                <div class="form-group">
                    <label>Nouvelle description *</label>
                    <textarea id="editDescription" required minlength="10"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Confirmer</button>
            </form>
        </div>
    </div>
    
    <div id="reviewModal" class="modal">
        <div class="modal-content" style="max-width:500px;">
            <div class="modal-header">
                <h3>Donner votre avis</h3>
                <button class="close-modal" onclick="closeReviewModal()">&times;</button>
            </div>
            <form onsubmit="submitReview(event)">
                <input type="hidden" id="reviewTicketId">
                <div class="form-group">
                    <label>Votre note *</label>
                    <div class="rating-stars">
                        <input type="radio" id="star5" name="rating" value="5" required><label for="star5">★</label>
                        <input type="radio" id="star4" name="rating" value="4"><label for="star4">★</label>
                        <input type="radio" id="star3" name="rating" value="3"><label for="star3">★</label>
                        <input type="radio" id="star2" name="rating" value="2"><label for="star2">★</label>
                        <input type="radio" id="star1" name="rating" value="1"><label for="star1">★</label>
                    </div>
                </div>
                <div class="form-group">
                    <label>Votre commentaire (optionnel)</label>
                    <textarea id="reviewComment" placeholder="Qu'avez-vous pensé de ce support ?"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Envoyer mon avis</button>
            </form>
        </div>
    </div>

    <div id="reopenTicketModal" class="modal">
        <div class="modal-content" style="max-width:450px; text-align:center;">
            <div class="modal-header">
                <h3>Réouvrir ce ticket ?</h3>
                <button class="close-modal" onclick="closeReopenTicketModal()">&times;</button>
            </div>
            <p style="margin: 20px 0; font-size: 16px;">Êtes-vous sûr de vouloir réouvrir ce ticket ? Un message sera ajouté pour notifier l'équipe de support.</p>
            <div style="display:flex; gap:15px; justify-content:center; margin-top:20px;">
                <button class="btn btn-secondary" onclick="closeReopenTicketModal()">Annuler</button>
                <button id="confirmReopenBtn" class="btn btn-success" onclick="confirmReopenTicket()">Confirmer</button> 
            </div>
        </div>
    </div>

    <script src="js/drag-drop-upload.js"></script>
    <script src="js/file-viewer-system.js"></script>
    <script>
        let tickets = [];
        let currentUser = null;

        function escapeHTML(str) {
            if (str === null || str === undefined) return '';
            return str.toString()
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        async function apiFetch(url, options = {}) {
            options.headers = options.headers || {};
            options.headers['X-CSRF-TOKEN'] = csrfToken;

            if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(options.body);
            }

            if (options.method && options.method.toUpperCase() === 'GET') {
                delete options.body;
            }

            const response = await fetch(url, options);

            if (response.headers.get("content-type")?.includes("application/json")) {
                const data = await response.json();
                if (!data.success && (data.message === 'Authentification requise' || data.message === 'Authentification admin requise')) {
                    console.warn('Session invalide ou expirée détectée via API. Rechargement de la page...');
                    window.location.reload(); // Force un rechargement complet de la page
                    throw new Error('Session expirée, rechargement de la page.');
                }
                return { ...response, json: () => Promise.resolve(data) }; // Retourne une réponse compatible
            }
            return response;
        }
        
        checkSession();

        /**
         * Vérifie si un timestamp (string) est dans les dernières 24 heures
         * @param {string} closedAt - Le timestamp de fermeture (ex: "2023-10-27 14:30:00")
         */
        function isReopenable(closedAt) {
            if (!closedAt) return false;
            const closedDate = new Date(closedAt.replace(' ', 'T')); 
            const now = new Date();
            const diffInMs = now - closedDate;
            const hours24 = 24 * 60 * 60 * 1000;
            return diffInMs <= hours24;
        }
        
        function checkSession() {
            const isUserLoggedIn = <?php echo json_encode($isUserLoggedIn); ?>;
            if (isUserLoggedIn) {
                document.getElementById('logoutBtn').style.display = 'block';
                loadTickets();
            }
        }
        
        async function login(e) {
            e.preventDefault();
            const errorDiv = document.getElementById('loginErrorMsg');
            errorDiv.style.display = 'none';

            const email = document.getElementById('loginEmail').value.trim();
            const password = document.getElementById('loginPassword').value;

            const res = await apiFetch('api.php?action=login', { method: 'POST', body: { email, password } });
            const data = await res.json();

            if (data.success) {
                location.reload();
            } else {
                errorDiv.textContent = '❌ ' + data.message;
                errorDiv.style.display = 'block';
            }
        }
        
        function logout() {
            apiFetch('api.php?action=logout', { method: 'POST' })
                .finally(() => {
                    location.reload();
                });
        }
        
        async function loadTickets() {
            const res = await apiFetch('api.php?action=ticket_list');
            const data = await res.json();
            if (data.success) {
                tickets = data.tickets;
                if (data.user) {
                    document.getElementById('welcomeMsg').textContent = `Bonjour ${escapeHTML(data.user.firstname)} ${escapeHTML(data.user.lastname)} !`;
                }
                renderTickets();
                updateModalIfOpen();
            }
        }
        
        /**
         * Met à jour le modal s'il est ouvert, sans causer de boucle.
         */
        function updateModalIfOpen() {
            const modal = document.getElementById('viewTicketModal');
            if (modal && modal.classList.contains('active')) {
                const openTicketId = document.getElementById('ticketDetails').dataset.ticketId;
                if (openTicketId) {
                    const updatedTicket = tickets.find(t => t.id == openTicketId);
                    if (updatedTicket) {
                        refreshModalContent(updatedTicket);
                    } else {
                        closeViewTicketModal();
                    }
                }
            }
        }

        /**
         * Rafraîchit uniquement le contenu du modal.
         * @param {object} ticket L'objet ticket avec les données à jour.
         */
        function refreshModalContent(ticket) {
            console.log(`🔄 Rafraîchissement du modal utilisateur pour le ticket #${ticket.id}`);
            
            const messagesContainer = document.querySelector('#viewTicketModal #messagesContainer');
            if (messagesContainer) {
                messagesContainer.innerHTML = ticket.messages.length === 0 ? '<p style="color:var(--gray-600);">Aucun message</p>' : 
                    ticket.messages.map(m => `
                        <div class="message ${m.author_role === 'admin' ? 'message-admin' : 'message-user'}">
                            <strong>${escapeHTML(m.author_name)}</strong> - ${new Date(m.date).toLocaleString('fr-FR')}
                            <p style="margin-top:5px;">${escapeHTML(m.text)}</p>
                        </div>
                    `).join('');
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        }

        function renderTickets() {
            const container = document.getElementById('ticketsList');
            if (tickets.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-state-icon">🔭</div><p>Aucun ticket</p></div>';
                return;
            }
            container.innerHTML = tickets.map(t => {
                const unread = t.messages.filter(m => m.is_read === 0 && m.author_role === 'admin').length;
                const canEdit = t.description_modified === 0 && t.status !== 'Fermé';
                let closedActionsHtml = '';
                if (t.status === 'Fermé') {
                    if (isReopenable(t.closed_at)) {
                        closedActionsHtml = `<button class="btn btn-success btn-small" onclick="reopenTicket(event, ${t.id})">🔄 Rouvrir le ticket</button>`;
                    } else {
                        if (t.review_id) {
                            closedActionsHtml = `<div class="user-rating-display">Votre note : ${'★'.repeat(t.review_rating)}${'☆'.repeat(5 - t.review_rating)}</div>`;
                        }
                    }
                }
                
                return `
                    <div class="ticket-card ${unread > 0 ? 'has-unread' : ''}">
                        <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
                            <div>
                                <strong>#${t.id} - ${t.subject}</strong>
                                ${unread > 0 ? `<span class="badge badge-high">${unread} nouveau(x)</span>` : ''}
                            </div>
                            <span class="badge badge-${t.status === 'Ouvert' ? 'open' : t.status === 'En cours' ? 'in-progress' : 'closed'}">
                                ${t.status}
                            </span>
                        </div>
                        <p style="color:var(--gray-600);margin-bottom:10px;">${escapeHTML(t.description)}</p>
                        <div style="display:flex;gap:10px;font-size:12px;color:var(--gray-600);flex-wrap:wrap;align-items:center;">
                            <span>📁 ${t.category}</span>
                            <span class="badge badge-${t.priority === 'Haute' ? 'high' : t.priority === 'Moyenne' ? 'medium' : 'low'}">${t.priority}</span>
                            <span>📅 ${t.date}</span>
                            ${t.files && t.files.length > 0 ? `<span>📎 ${t.files.length} fichier(s)</span>` : ''}
                        </div>
                        <div style="display:flex;gap:10px;margin-top:15px;justify-content:space-between;align-items:center;">
                            <div>
                                <button class="btn btn-primary btn-small" onclick="viewTicket(${t.id})">Voir le ticket</button>
                                ${canEdit ? `<button class="edit-description-btn" onclick="showEditDescription(${t.id})">Modifier</button>` : ''}
                            </div>
                            <div>
                                ${closedActionsHtml} </div>
                        </div>
                    </div>
                `;
            }).join('');
        }
        
        async function viewTicket(id) {
            const ticket = tickets.find(t => t.id === id);
            if (!ticket) return;

            const hasUnreadAdminMessages = ticket.messages.some(m => m.is_read === 0 && m.author_role === 'admin');
            if (hasUnreadAdminMessages) {
                console.log(`Marquage des messages admin du ticket #${id} comme lus...`);
                await apiFetch('api.php?action=message_read_by_user', {
                    method: 'POST',
                    body: { ticket_id: id }
                });
                await loadTickets();
            }

            fileViewerSystem.setFiles(ticket.files || []);
            let closedActionsHtml = '';
            if (ticket.status === 'Fermé') {
                if (isReopenable(ticket.closed_at)) {
                    closedActionsHtml = `<button class="btn btn-success" style="margin-top:15px;" onclick="reopenTicket(event, ${ticket.id})">🔄 Rouvrir le ticket</button>`;
                } else {
                    if (ticket.review_id) {
                        closedActionsHtml = `<div class="user-rating-display" style="margin-top:15px;">Votre note : ${'★'.repeat(ticket.review_rating)}${'☆'.repeat(5 - ticket.review_rating)}</div>`;
                    }
                }
            }
            
            const details = document.getElementById('ticketDetails');
            details.dataset.ticketId = id; 
            details.innerHTML = `
                <div style="margin-bottom:20px;">
                    <div style="display:flex;gap:10px;margin-bottom:15px;align-items:center;justify-content:space-between;">
                        <div>
                            <span class="badge badge-${ticket.status === 'Ouvert' ? 'open' : ticket.status === 'En cours' ? 'in-progress' : 'closed'}">${ticket.status}</span>
                            <span class="badge badge-${ticket.priority === 'Haute' ? 'high' : ticket.priority === 'Moyenne' ? 'medium' : 'low'}">${ticket.priority}</span>
                        </div>
                        <div>
                            ${closedActionsHtml} </div>
                    </div>
                    <h4>${escapeHTML(ticket.subject)}</h4>
                    <p style="color:var(--gray-600);margin:10px 0;">${escapeHTML(ticket.description)}</p>
                </div>
                ${ticket.files && ticket.files.length > 0 ? `
                    <h4 style="margin-bottom:15px;">📎 Fichiers joints (${ticket.files.length})</h4>
                    <div class="file-gallery">
                        ${ticket.files.map((f, index) => {
                            const isImage = f.type.includes('image');
                            const isPDF = f.type.includes('pdf');
                            const icon = isPDF ? '📄' : '🖼️';
                            const badge = isPDF ? 'PDF' : isImage ? 'IMG' : 'FILE';
                            return `
                            <div class="file-card">
                                <div class="file-card-preview" onclick="fileViewerSystem.openFile(${index})">
                                    <span class="file-card-badge">${badge}</span>
                                    ${isImage ? `<img src="api.php?action=ticket_download_file&file_id=${f.id}&preview=1" alt="${f.name}">` : `<div class="file-icon">${icon}</div>`}
                                </div>
                                <div class="file-card-info">
                                    <div class="file-card-name" title="${f.name}">${f.name}</div>
                                    <div class="file-card-meta">
                                        ${(f.size / 1024).toFixed(2)} Ko
                                    </div>
                                    <div class="file-card-meta">
                                        ${f.uploaded_by} • ${f.date}
                                    </div>
                                </div>
                                <div class="file-card-actions">
                                    <button class="file-card-btn view" onclick="fileViewerSystem.openFile(${index})">
                                        👁️ Voir
                                    </button>
                                    <a href="api.php?action=ticket_download_file&file_id=${f.id}" class="file-card-btn download" download>
                                        ⬇️
                                    </a>
                                </div>
                            </div>
                        `;
                        }).join('')}
                    </div>
                ` : ''}
                <h4 style="margin-bottom:15px;">Messages</h4>
                <div id="messagesContainer">
                    ${ticket.messages.length === 0 ? '<p style="color:var(--gray-600);">Aucun message</p>' : 
                        ticket.messages.map(m => `
                            <div class="message ${m.author_role === 'admin' ? 'message-admin' : 'message-user'}">
                                <strong>${escapeHTML(m.author_name)}</strong> - ${new Date(m.date).toLocaleString('fr-FR')}
                                <p style="margin-top:5px;">${escapeHTML(m.text)}</p>
                            </div>
                        `).join('')
                    }
                </div>
                ${ticket.status !== 'Fermé' ? `
                    <form onsubmit="sendMessage(event, ${ticket.id})" style="margin-top:20px;">
                        <div class="form-group">
                            <textarea id="newMessage" placeholder="Votre message..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Envoyer</button>
                    </form>
                ` : `<p style="color:var(--gray-600);margin-top:20px;text-align:center;padding:15px;background:var(--gray-50);border-radius:8px;">Ce ticket est fermé. ${isReopenable(ticket.closed_at) ? 'Vous pouvez le rouvrir.' : 'Vous pouvez le noter.'}</p>`}
            `;
            document.getElementById('viewTicketModal').classList.add('active');
            const messagesContainer = document.getElementById('messagesContainer');
            if(messagesContainer) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        }
        
        async function createTicket(e) {
            e.preventDefault();
            const formData = new FormData();
            formData.append('name', `${currentUser.firstname} ${currentUser.lastname}`);
            formData.append('email', currentUser.email);
            formData.append('subject', document.getElementById('ticketSubject').value);
            formData.append('category', document.getElementById('ticketCategory').value);
            formData.append('priority', document.getElementById('ticketPriority').value);
            formData.append('description', document.getElementById('ticketDescription').value);

            formData.append('csrf_token', csrfToken);
            
            closeCreateTicketModal();
            showLoadingAnimation("Ticket en cours de création...");

            try {
                const res = await apiFetch('api.php?action=ticket_create', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    const ticketId = data.ticket_id;
                    if (dragDropUpload && dragDropUpload.files.length > 0) {
                        await dragDropUpload.uploadFiles(ticketId); 
                        dragDropUpload.clear();
                    }
                    document.dispatchEvent(new CustomEvent('ticketsUpdated'));
                    window.location.href = `ticket_details.php?id=${ticketId}`;
                } else {
                    hideLoadingAnimation();
                    alert('❌ ' + data.message);
                }
            } catch (error) {
                hideLoadingAnimation();
                alert('❌ ' + error.message);
            }
        }
        
        async function sendMessage(e, ticketId) {
            e.preventDefault();
            const messageInput = document.getElementById('newMessage');
            const message = messageInput.value;
            messageInput.value = '';
            
            try {
                const res = await apiFetch('api.php?action=message_create', { method: 'POST', body: { ticket_id: ticketId, message: message } });
                const data = await res.json();
                if (data.success) {
                    await loadTickets(); 
                } else {
                    messageInput.value = message;
                    alert('❌ ' + data.message);
                }
            } catch (error) {
                messageInput.value = message;
                alert('❌ ' + error.message);
            }
        }
        
        function showEditDescription(ticketId) {
            const ticket = tickets.find(t => t.id === ticketId);
            if (!ticket) return;
            document.getElementById('editTicketId').value = ticketId;
            document.getElementById('editDescription').value = ticket.description;
            document.getElementById('editDescriptionModal').classList.add('active');
        }
        
        async function updateDescription(e) {
            e.preventDefault();
            const ticketId = document.getElementById('editTicketId').value;
            const newDescription = document.getElementById('editDescription').value;
            const res = await apiFetch('api.php?action=ticket_update_description', { method: 'POST', body: { ticket_id: ticketId, description: newDescription } });
            const data = await res.json();
            if (data.success) {
                closeEditDescriptionModal();
                showSuccessAnimation('Description mise à jour !');
                setTimeout(() => loadTickets(), 1500);
            } else {
                alert('❌ ' + data.message);
            }
        }
        
        function showReviewModal(ticketId) {
            document.getElementById('reviewTicketId').value = ticketId;
            document.querySelectorAll('input[name="rating"]').forEach(r => r.checked = false);
            document.getElementById('reviewComment').value = '';
            document.getElementById('reviewModal').classList.add('active');
        }
        
        function closeReviewModal() {
            document.getElementById('reviewModal').classList.remove('active');
        }
        
        async function submitReview(e) {
            e.preventDefault();
            const ticketId = document.getElementById('reviewTicketId').value;
            const rating = document.querySelector('input[name="rating"]:checked');
            const comment = document.getElementById('reviewComment').value;
            if (!rating) {
                alert('Veuillez sélectionner une note');
                return;
            }
            const res = await apiFetch('api.php?action=ticket_add_review', {
                method: 'POST',
                body: JSON.stringify({
                    ticket_id: parseInt(ticketId),
                    rating: parseInt(rating.value),
                    comment: comment
                })
            });
            const data = await res.json();
            if (data.success) {
                closeReviewModal();
                showSuccessAnimation('Avis envoyé, merci !');
                setTimeout(() => loadTickets(), 1500); 
            } else {
                alert('❌ ' + data.message);
            }
        }
        
        async function reopenTicket(e, ticketId) {
            e.stopPropagation();
            if (!confirm('Voulez-vous vraiment rouvrir ce ticket ?')) {
                return;
            }
            
            showLoadingAnimation("Réouverture du ticket...");
            
            try {
                const res = await apiFetch('api.php?action=ticket_reopen', { method: 'POST', body: { ticket_id: ticketId } });
                const data = await res.json();
                
                if (data.success) {
                    hideLoadingAnimation();
                    showSuccessAnimation('Ticket rouvert !');
                    if (document.getElementById('viewTicketModal').classList.contains('active')) {
                        closeViewTicketModal();
                    }
                    setTimeout(loadTickets, 1500);
                } else {
                    hideLoadingAnimation();
                    alert('❌ ' + data.message);
                }
            } catch (error) {
                hideLoadingAnimation();
                alert('❌ ' + error.message);
            }
        }
        
        function showSuccessAnimation(message = 'Action réussie !') {
            showAnimation(message, '✓', false);
            setTimeout(hideLoadingAnimation, 1500);
        }

        function showLoadingAnimation(message = 'Chargement...') {
            showAnimation(message, '', true);
        }
        
        function showAnimation(message, icon, isLoading) {
            hideLoadingAnimation(); 
            const overlay = document.getElementById('overlay');
            overlay.classList.add('active');
            const animation = document.createElement('div');
            animation.id = 'loadingAnimation'; 
            animation.className = 'message-sent-animation';
            let iconHtml = '';
            if (isLoading) {
                iconHtml = `<div class="animation-icon loading"></div>`; 
            } else {
                iconHtml = `<div class="animation-icon">${icon}</div>`;
            }
            animation.innerHTML = `
                ${iconHtml}
                <h3 style="color:var(--gray-900);margin-bottom:10px;">${message}</h3>
            `;
            document.body.appendChild(animation);
        }
        
        function hideLoadingAnimation() {
            const overlay = document.getElementById('overlay');
            const animation = document.getElementById('loadingAnimation');
            if (overlay) overlay.classList.remove('active');
            if (animation) animation.remove();
        }

        function showLoginModal() { document.getElementById('loginModal').classList.add('active'); }
        function closeLoginModal() { document.getElementById('loginModal').classList.remove('active'); }
        function showCreateTicketModal() { document.getElementById('createTicketModal').classList.add('active'); }
        function closeCreateTicketModal() { document.getElementById('createTicketModal').classList.remove('active'); }
        function closeViewTicketModal() { 
            document.getElementById('viewTicketModal').classList.remove('active'); 
            document.getElementById('ticketDetails').dataset.ticketId = '';
            
        }
        function closeEditDescriptionModal() { document.getElementById('editDescriptionModal').classList.remove('active'); }
        function closeReviewModal() { document.getElementById('reviewModal').classList.remove('active'); }
        
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', e => {
                if (e.target === modal) {
                    if (modal.id === 'viewTicketModal') closeViewTicketModal();
                    if (modal.id === 'loginModal') closeLoginModal();
                    if (modal.id === 'createTicketModal') closeCreateTicketModal();
                    if (modal.id === 'editDescriptionModal') closeEditDescriptionModal();
                    if (modal.id === 'reviewModal') closeReviewModal();
                    if (modal.id === 'reopenTicketModal') closeReopenTicketModal();
                }
            });
        });
        
        document.addEventListener('ticketsUpdated', () => {
            console.log('Notification reçue, rafraîchissement des tickets (user)...');
            if (currentUser) {
                loadTickets();
            }
        });

        setInterval(() => {
            if (currentUser && !document.querySelector('.modal.active')) {
                console.log('🔄 Rafraîchissement automatique des tickets utilisateur...');
                loadTickets();
            }
        }, 300000);

    </script>

    <script src="js/notification-system.js"></script>
</body>
</html>