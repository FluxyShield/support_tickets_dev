/**
 * ⭐ AMÉLIORATION SÉCURITÉ : Fonction pour échapper le HTML
 * Empêche les attaques XSS en convertissant les caractères spéciaux en entités HTML.
 * @param {string} str La chaîne à échapper.
 * @returns {string} La chaîne échappée.
 */
function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    return str.toString()
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
/**
 * ADMIN SCRIPT - Support Ticketing System
 * ⭐ MIS À JOUR :
 * - Bug 1 (Invitation) : Remplacement de alert() par des messages inline.
 * - Bug 2 (Stats) : Ajout d'un message d'erreur si le chargement des stats échoue (évite la page blanche).
 */

let tickets = []; // Contiendra uniquement les tickets de la page actuelle
let currentTab = 'tickets';
let adminsList = [];
let cannedResponses = [];

let currentPage = 1;
let itemsPerPage = 10; 
let currentPaginationData = {}; 

const adminFirstname = localStorage.getItem('admin_firstname');
const adminId = localStorage.getItem('admin_id'); 
if (!adminFirstname || !adminId) {
    window.location.href = 'login.php';
}

// ==========================================
// ⭐ AMÉLIORATION SÉCURITÉ : FETCH AVEC CSRF
// ==========================================

const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

document.getElementById('adminName').textContent = `Bonjour ${adminFirstname}`;

loadInitialData(); 

/**
 * Wrapper pour l'API fetch qui ajoute automatiquement le jeton CSRF.
 * @param {string} url - L'URL de l'API.
 * @param {object} options - Les options de fetch (method, body, etc.).
 * @returns {Promise<Response>}
 */
async function apiFetch(url, options = {}) {
    // Prépare les headers
    options.headers = options.headers || {};
    options.headers['X-CSRF-TOKEN'] = csrfToken;

    // Si le body est un objet JSON, on s'assure que le header Content-Type est correct
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(options.body);
    }

    // Si c'est une requête GET, on ne met pas de body
    if (options.method && options.method.toUpperCase() === 'GET') {
        delete options.body;
    }

    const response = await fetch(url, options);

    // ⭐ NOUVEAU : Détection de session invalide/expirée
    // On ne peut pas lire le JSON si la réponse est vide (ex: téléchargement de fichier)
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

// ==========================================
// NAVIGATION PAR ONGLETS
// ==========================================

function switchTab(tab) {
    currentTab = tab;
    
    document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    
    if (tab === 'tickets') {
        document.querySelectorAll('.admin-tab')[0].classList.add('active');
        document.getElementById('ticketsTab').classList.add('active');
    } else if (tab === 'stats') {
        document.querySelectorAll('.admin-tab')[1].classList.add('active');
        document.getElementById('statsTab').classList.add('active');
        loadAdvancedStats('30'); // ⭐ Charge le nouveau dashboard

    } else if (tab === 'settings') { 
        document.querySelectorAll('.admin-tab')[2].classList.add('active');
        document.getElementById('settingsTab').classList.add('active');
        renderSettingsTab('admins');
    }
}

function switchSettingsTab(subTab) {
    document.querySelectorAll('.settings-sub-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.settings-sub-content').forEach(c => c.style.display = 'none');
    
    if (subTab === 'admins') {
        document.getElementById('settingsAdminsTab').classList.add('active');
        document.getElementById('settingsAdminsContent').style.display = 'block';
    } else if (subTab === 'canned') {
        document.getElementById('settingsCannedTab').classList.add('active');
        document.getElementById('settingsCannedContent').style.display = 'block';
    } else if (subTab === 'general') {
        document.getElementById('settingsGeneralTab').classList.add('active');
        const contentDiv = document.getElementById('settingsGeneralContent');
        contentDiv.style.display = 'block';
        renderGeneralSettings();
    }
}

// ==========================================
// CHARGEMENT INITIAL
// ==========================================

async function loadInitialData() {
    await loadAdmins(); 
    await loadCannedResponses(); 
    await loadKOPStats(); 
    await loadTickets(); 
}

async function loadKOPStats() {
    try {
        const res = await apiFetch('api.php?action=get_stats');
        const data = await res.json();
        if (data.success) {
            document.getElementById('totalTickets').textContent = data.stats.total;
            document.getElementById('openTickets').textContent = data.stats.Ouvert;
            document.getElementById('inProgressTickets').textContent = data.stats['En cours']; 
            document.getElementById('closedTickets').textContent = data.stats.Fermé;
        }
    } catch (error) {
        console.error('Erreur chargement KOP Stats:', error);
        document.getElementById('totalTickets').textContent = '...';
        document.getElementById('openTickets').textContent = '...';
        document.getElementById('inProgressTickets').textContent = '...';
        document.getElementById('closedTickets').textContent = '...';
    }
}


async function loadAdmins() {
    try {
        const res = await apiFetch('api.php?action=get_admins');
        const data = await res.json();
        if (data.success) {
            adminsList = data.admins;
        } else {
            console.error('Erreur chargement admins:', data.message);
        }
    } catch (error) {
        console.error('Erreur de chargement des admins:', error);
    }
}

async function loadCannedResponses() {
    try {
        const res = await apiFetch('api.php?action=canned_list');
        const data = await res.json();
        if (data.success) {
            cannedResponses = data.responses;
        } else {
            console.error('Erreur chargement modèles:', data.message);
        }
    } catch (error) {
        console.error('Erreur de chargement des modèles:', error);
    }
}

// Variable globale pour annuler les requêtes
let abortController = null;

// Cache simple pour éviter les requêtes identiques
const ticketsCache = new Map();
const CACHE_DURATION = 30000; // 30 secondes

/**
 * Génère une clé de cache basée sur les paramètres
 */
function getCacheKey() {
    const statusFilter = document.getElementById('filterStatus').value;
    const priorityFilter = document.getElementById('filterPriority').value;
    const searchTerm = document.getElementById('adminSearchInput').value;
    // Note: filterMyTickets n'est pas défini dans le script original,
    // mais je le laisse car il faisait partie de votre code fourni.
    // S'il n'est pas utilisé, il sera 'undefined' et constant.
    const filterMyTickets = window.filterMyTickets || false; 
    
    return `${currentPage}-${itemsPerPage}-${statusFilter}-${priorityFilter}-${searchTerm}-${filterMyTickets}`;
}

/**
 * Récupère depuis le cache si valide
 */
function getFromCache(key) {
    const cached = ticketsCache.get(key);
    if (!cached) return null;
    
    const now = Date.now();
    if (now - cached.timestamp > CACHE_DURATION) {
        ticketsCache.delete(key);
        return null;
    }
    
    return cached.data;
}

/**
 * Sauvegarde dans le cache
 */
function saveToCache(key, data) {
    ticketsCache.set(key, {
        data: data,
        timestamp: Date.now()
    });
    
    // Nettoyage automatique : garder max 20 entrées
    if (ticketsCache.size > 20) {
        const firstKey = ticketsCache.keys().next().value;
        ticketsCache.delete(firstKey);
    }
}

/**
 * Affiche l'indicateur de chargement
 */
function showLoadingIndicator() {
    const tbody = document.getElementById('ticketsTable');
    tbody.innerHTML = `
        <tr>
            <td colspan="9" style="text-align:center;padding:40px;">
                <div style="display:inline-block;width:40px;height:40px;border:4px solid var(--gray-200);border-top-color:var(--orange);border-radius:50%;animation:spin 1s linear infinite;"></div>
                <p style="margin-top:15px;color:var(--gray-600);">Chargement des tickets...</p>
            </td>
        </tr>
    `;
}

/**
 * Charge les tickets avec optimisations
 */
async function loadTickets() {
    // Annuler la requête précédente si elle existe
    if (abortController) {
        abortController.abort();
    }
    
    // Créer un nouveau contrôleur d'annulation
    abortController = new AbortController();
    
    const statusFilter = document.getElementById('filterStatus').value;
    const priorityFilter = document.getElementById('filterPriority').value;
    const searchTerm = document.getElementById('adminSearchInput').value;
    const filterMyTickets = window.filterMyTickets || false; // Assure la définition
    
    // Vérifier le cache
    const cacheKey = getCacheKey();
    const cachedData = getFromCache(cacheKey);
    
    if (cachedData) {
        console.log('📦 Chargement depuis le cache');
        tickets = cachedData.tickets;
        currentPaginationData = cachedData.pagination;
        renderTickets();
        renderPaginationControls();
        updateModalIfOpen();
        return;
    }
    
    // Afficher l'indicateur de chargement
    showLoadingIndicator();

    try {
        // J'ai corrigé l'URL pour qu'elle corresponde aux paramètres de votre code
        const url = `api.php?action=ticket_list&page=${currentPage}&limit=${itemsPerPage}&status=${statusFilter}&priority=${priorityFilter}&search=${encodeURIComponent(searchTerm)}&my_tickets=${filterMyTickets}&include_files=false`;
        
        const startTime = performance.now();
        
        const res = await apiFetch(url, {
            signal: abortController.signal
        });
        
        const data = await res.json();
        
        const loadTime = (performance.now() - startTime).toFixed(0);

        if (data.success) {
            tickets = data.tickets;
            currentPaginationData = data.pagination;
            
            // Sauvegarder dans le cache
            saveToCache(cacheKey, {
                tickets: data.tickets,
                pagination: data.pagination
            });
            
            console.log(`⚡ Tickets chargés en ${loadTime}ms (${data.pagination.totalItems} tickets)`);
            
            renderTickets();
            renderPaginationControls();
            updateModalIfOpen();
            
        } else {
            console.error('Erreur:', data.message);
            showErrorMessage(data.message);
        }
    } catch (error) {
        if (error.name === 'AbortError') {
            console.log('🚫 Requête annulée');
        } else {
            console.error('Erreur de chargement:', error);
            showErrorMessage('Erreur de connexion au serveur');
        }
    } finally {
        abortController = null;
    }
}

/**
 * Mise à jour du modal si ouvert
 */
function updateModalIfOpen() {
    const modal = document.getElementById('viewTicketModal');
    // ⭐ SOLUTION : Logique de mise à jour du modal sans boucle
    if (modal && modal.classList.contains('active')) {
        const openTicketId = document.getElementById('ticketDetails').dataset.ticketId;
        if (openTicketId) {
            const updatedTicket = tickets.find(t => t.id == openTicketId);
            if (updatedTicket) {
                refreshModalContent(updatedTicket); // On rafraîchit le contenu au lieu de tout recréer
            } else {
                closeViewModal();
            }
        }
    }
}

/**
 * ⭐ NOUVEAU : Rafraîchit le contenu du modal sans le recréer entièrement.
 * C'est la clé pour éviter les boucles de rechargement.
 * @param {object} ticket - L'objet ticket avec les données à jour.
 */
function refreshModalContent(ticket) {
    console.log(`🔄 Rafraîchissement du modal pour le ticket #${ticket.id}`);

    // Mettre à jour la section des messages
    const messagesContainer = document.getElementById('messagesContainer');
    if (messagesContainer) {
        messagesContainer.innerHTML = ticket.messages.length === 0 ? '<p style="color:var(--gray-600);">Aucun message</p>' : 
            ticket.messages.map(m => `
                <div class="message ${m.author_role === 'admin' ? 'message-admin' : (m.author_role === 'user' ? 'message-user' : 'message-system')}">
                    <strong>${escapeHTML(m.author_name)}</strong> - ${new Date(m.date).toLocaleString('fr-FR')}
                    <p style="margin-top:5px;">${m.text}</p>
                </div>
            `).join('');
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // Mettre à jour la section d'assignation (si elle existe)
    const assignmentUI = document.getElementById('assignmentUI');
    if (assignmentUI) assignmentUI.innerHTML = renderAssignmentUI(ticket);
}
/**
 * Charge les fichiers d'un ticket spécifique (lazy loading)
 */
async function loadTicketFiles(ticketId) {
    window.loadedTicketFiles = []; // Stockage temporaire
    try {
        const res = await apiFetch(`api.php?action=ticket_list&limit=1&search=${ticketId}&include_files=true`);
        const data = await res.json();
        
        if (data.success && data.tickets.length > 0) {
            const ticket = tickets.find(t => t.id === ticketId);
            if (ticket) {
                ticket.files = data.tickets[0].files;
                window.loadedTicketFiles = data.tickets[0].files; // Stocker pour updateModal
            }
        }
    } catch (error) {
        console.error('Erreur chargement fichiers:', error);
    }
}

/**
 * Affiche un message d'erreur
 */
function showErrorMessage(message) {
    const tbody = document.getElementById('ticketsTable');
    tbody.innerHTML = `
        <tr>
            <td colspan="9" style="text-align:center;padding:40px;">
                <div style="color:var(--danger);font-size:48px;margin-bottom:15px;">⚠️</div>
                <p style="color:var(--danger);font-weight:600;">${message}</p>
                <button class="btn btn-secondary" onclick="loadTickets()" style="margin-top:15px;">Réessayer</button>
            </td>
        </tr>
    `;
}

/**
 * Debounce pour la recherche
 */
let searchTimeout = null;
function debouncedSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        currentPage = 1;
        loadTickets();
    }, 500);
}

/**
 * Handler de recherche avec debounce
 */
function handleSearch(event) {
    if (event.key === 'Enter') {
        clearTimeout(searchTimeout);
        currentPage = 1;
        loadTickets();
    } else {
        debouncedSearch();
    }
}

/**
 * Trigger de recherche immédiat
 */
function triggerSearch() {
    clearTimeout(searchTimeout);
    currentPage = 1;
    loadTickets();
}

/**
 * Vider le cache (utile après une action CRUD)
 */
function clearTicketsCache() {
    ticketsCache.clear();
    console.log('🗑️ Cache vidé');
}

/**
 * Préchargement de la page suivante (optionnel - améliore UX)
 */
function preloadNextPage() {
    // Correction : "hasNext" n'est pas dans la pagination, 
    // utiliser "totalPages" et "currentPage"
    if (!currentPaginationData || currentPage >= currentPaginationData.totalPages) return;
    
    const nextPage = currentPage + 1;
    const statusFilter = document.getElementById('filterStatus').value;
    const priorityFilter = document.getElementById('filterPriority').value;
    const searchTerm = document.getElementById('adminSearchInput').value;
    const filterMyTickets = window.filterMyTickets || false;
    
    const url = `api.php?action=ticket_list&page=${nextPage}&limit=${itemsPerPage}&status=${statusFilter}&priority=${priorityFilter}&search=${encodeURIComponent(searchTerm)}&my_tickets=${filterMyTickets}&include_files=false`;
    
    // Préchargement silencieux
    apiFetch(url).then(res => res.json()).then(data => {
        if (data.success) {
            const cacheKey = `${nextPage}-${itemsPerPage}-${statusFilter}-${priorityFilter}-${searchTerm}-${filterMyTickets}`;
            saveToCache(cacheKey, {
                tickets: data.tickets,
                pagination: data.pagination
            });
            console.log('📥 Page suivante préchargée');
        }
    }).catch(() => {
        // Échec silencieux
    });
}

/**
 * Navigation pagination avec préchargement
 */
function goToPage(page) {
    if (page < 1 || (currentPaginationData.totalPages && page > currentPaginationData.totalPages) || page === currentPage) {
        return;
    }
    currentPage = page;
    loadTickets();
    
    // Précharger la page suivante
    setTimeout(preloadNextPage, 500);
}

// Exporter pour réutilisation
window.clearTicketsCache = clearTicketsCache;


// ==========================================
// FILTRAGE ET RECHERCHE (Anciennes fonctions remplacées ci-dessus)
// ==========================================

function filterTickets() {
    currentPage = 1;
    loadTickets();
}

/**
 * ⭐ NOUVEAU : Gère le filtre "Mes tickets".
 * Active ou désactive le filtre pour n'afficher que les tickets assignés à l'admin connecté.
 */
window.filterMyTickets = false; // Variable globale pour l'état du filtre

function toggleMyTickets() {
    window.filterMyTickets = !window.filterMyTickets;

    const btn = document.getElementById('myTicketsBtn');
    btn.classList.toggle('active', window.filterMyTickets);

    // Revenir à la première page et recharger les tickets
    currentPage = 1;
    loadTickets();
}


// handleSearch() est maintenant défini dans le bloc optimisé
// triggerSearch() est maintenant défini dans le bloc optimisé


// ==========================================
// AFFICHAGE DES TICKETS
// ==========================================
function renderTickets() {
    const tbody = document.getElementById('ticketsTable');

    if (tickets.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:40px;color:var(--gray-600);">Aucun ticket trouvé pour ces filtres</td></tr>';
        return;
    }

    tbody.innerHTML = tickets.map(t => { 
        const unread = t.messages.filter(m => m.is_read === 0 && m.author_role === 'user').length;
        
        let assignedAdmin = null; // Correction: initialisation
        if (t.assigned_to && adminsList) { // Correction: s'assurer que adminsList est chargé
            assignedAdmin = adminsList.find(a => a.id === t.assigned_to);
        }
        
        return `
            <tr class="${unread > 0 ? 'ticket-row-unread' : ''}" style="${unread > 0 ? 'background:rgba(239,128,0,0.05);' : ''}">
                <td><strong>#${escapeHTML(t.id)}</strong></td>
                <td>${escapeHTML(t.name)}<br><small style="color:var(--gray-600);">${escapeHTML(t.email)}</small></td>
                <td>${escapeHTML(t.subject)} ${unread > 0 ? `<span class="badge badge-high">${escapeHTML(unread)}</span>` : ''}</td>
                <td>${escapeHTML(t.category)}</td>
                <td><span class="badge badge-${t.priority === 'Haute' ? 'high' : t.priority === 'Moyenne' ? 'medium' : 'low'}">${escapeHTML(t.priority)}</span></td>
                
                <td>
                    ${assignedAdmin ? 
                        `<span class="badge badge-assigned" style="background:var(--gray-200);color:var(--gray-700);">${escapeHTML(assignedAdmin.firstname)}</span>` : 
                        `<span style="color:var(--gray-400);">Non assigné</span>`
                    }
                </td>
                
                <td><span class="badge badge-${t.status === 'Ouvert' ? 'open' : t.status === 'En cours' ? 'in-progress' : 'closed'}">${escapeHTML(t.status)}</span></td>
                <td>${escapeHTML(t.date)}</td>
                
                <td>
                    <button class="btn btn-primary btn-small" onclick="viewTicket(${t.id})">Voir</button>
                    <button class="btn btn-success btn-small" onclick="changeStatus(${t.id}, 'En cours')">En cours</button>
                    <button class="btn btn-secondary btn-small" onclick="changeStatus(${t.id}, 'Fermé')">Fermer</button>
                    <button class="btn btn-danger btn-small" onclick="deleteTicket(${t.id})">Supprimer</button>
                </td>
            </tr>
        `;
    }).join('');
}


// ==========================================
// FONCTIONS DE PAGINATION
// ==========================================
function renderPaginationControls() {
    const { currentPage, totalPages } = currentPaginationData;
    const container = document.getElementById('paginationControls');
    
    if (!totalPages || totalPages <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '';

    html += `<button class="pagination-btn" onclick="goToPage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>Précédent</button>`;

    const maxPagesToShow = 5; 
    
    if (totalPages <= maxPagesToShow + 2) {
        for (let i = 1; i <= totalPages; i++) {
            html += `<button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
        }
    } else {
        html += `<button class="pagination-btn ${1 === currentPage ? 'active' : ''}" onclick="goToPage(1)">1</button>`;

        if (currentPage > 3) {
            html += `<span class="pagination-dots">...</span>`;
        }

        let startPage = Math.max(2, currentPage - 1);
        let endPage = Math.min(totalPages - 1, currentPage + 1);

        if (currentPage <= 2) { endPage = 3; }
        if (currentPage >= totalPages - 1) { startPage = totalPages - 2; }

        for (let i = startPage; i <= endPage; i++) {
            html += `<button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
        }

        if (currentPage < totalPages - 2) {
            html += `<span class="pagination-dots">...</span>`;
        }
        
        html += `<button class="pagination-btn ${totalPages === currentPage ? 'active' : ''}" onclick="goToPage(${totalPages})">${totalPages}</button>`;
    }

    html += `<button class="pagination-btn" onclick="goToPage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>Suivant</button>`;

    container.innerHTML = html;
}

// goToPage() est maintenant défini dans le bloc optimisé


// ==========================================
// VISUALISATION D'UN TICKET
// ==========================================

async function viewTicket(id) {
    // Utiliser le ticket chargé par loadTickets()
    let ticket = tickets.find(t => t.id === id);

    // ⭐ CORRECTION BUG NOTIFICATION : Marquer les messages comme lus par l'admin
    // On vérifie s'il y a des messages non lus de l'utilisateur avant de faire l'appel API
    const hasUnreadUserMessages = ticket.messages.some(m => m.is_read === 0 && m.author_role === 'user');
    if (hasUnreadUserMessages) {
        console.log(`Marquage des messages utilisateur du ticket #${id} comme lus...`);
        // ⭐ CORRECTION BUG NOTIFICATION : Vider le cache pour forcer le rechargement des tickets
        clearTicketsCache();

        await apiFetch('api.php?action=message_read', {
            method: 'POST',
            body: { ticket_id: id }
        });
        // Recharger les données pour que le badge disparaisse de la liste principale
        await loadTickets();
    }

    // Si les fichiers/messages ne sont pas chargés (à cause de l'optimisation),
    // les charger maintenant.
    if (!ticket || !ticket.messages || !ticket.files) {
        await loadTicketFiles(id); // Assure que les fichiers sont chargés
        // Re-chercher le ticket au cas où il a été mis à jour par loadTicketFiles
        ticket = tickets.find(t => t.id === id); 
        
        // Si les messages manquent toujours (cas peu probable), re-fetch complet
        if (!ticket || !ticket.messages) {
             const res = await apiFetch(`api.php?action=ticket_list&limit=1&search=${id}&include_files=true`);
             const data = await res.json();
             if (data.success && data.tickets.length > 0) {
                ticket = data.tickets[0];
                // Mettre à jour notre liste locale
                const index = tickets.findIndex(t => t.id === id);
                if (index !== -1) {
                    tickets[index] = ticket;
                }
             } else {
                 alert(`Erreur : impossible de charger les détails du ticket ${id}.`);
                 return;
             }
        }
    }


    if (typeof fileViewerSystem !== 'undefined') {
        fileViewerSystem.setFiles(ticket.files || []);
    }

    const details = document.getElementById('ticketDetails');
    details.dataset.ticketId = id; 
    
    details.innerHTML = `
        <div id="assignmentUI" class="assignment-section">
            ${renderAssignmentUI(ticket)}
        </div>
    
        <div style="margin-bottom:20px;margin-top:20px;border-top:1px solid var(--gray-200);padding-top:20px;">
            <div style="display:flex;gap:10px;margin-bottom:15px;align-items:center;justify-content:space-between;">
                <div>
                    <span class="badge badge-${ticket.status === 'Ouvert' ? 'open' : ticket.status === 'En cours' ? 'in-progress' : 'closed'}">${ticket.status}</span>
                    <span class="badge badge-${ticket.priority === 'Haute' ? 'high' : ticket.priority === 'Moyenne' ? 'medium' : 'low'}">${ticket.priority}</span>
                </div>
                ${ticket.review_id ? `<div class="user-rating-display" style="font-size:14px;">Note : ${'★'.repeat(ticket.review_rating)}${'☆'.repeat(5 - ticket.review_rating)}</div>` : ''}
            </div>
            <h4>${escapeHTML(ticket.subject)}</h4>
            <p style="color:var(--gray-600);margin:10px 0;"><strong>De:</strong> ${escapeHTML(ticket.name)} (${escapeHTML(ticket.email)})</p>
            <p style="background:var(--gray-50);padding:15px;border-radius:8px;">${escapeHTML(ticket.description)}</p>
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
                            <div class="file-card-name" title="${escapeHTML(f.name)}">${escapeHTML(f.name)}</div>
                            <div class="file-card-meta">
                                ${(f.size / 1024).toFixed(2)} Ko
                            </div>
                            <div class="file-card-meta">
                                ${escapeHTML(f.uploaded_by)} • ${escapeHTML(f.date)}
                            </div>
                        </div>
                        <div class="file-card-actions">
                            <button class="file-card-btn view" onclick="fileViewerSystem.openFile(${index})">
                                👁️ Voir
                            </button>
                            <a href="api.php?action=ticket_download_file&file_id=${f.id}" class="file-card-btn download" download>
                                ⬇️
                            </a>
                            <button class="file-card-btn" style="background:var(--danger);color:white;" onclick="deleteFile(${f.id}, ${ticket.id})">
                                🗑️
                            </button>
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
                    <div class="message ${m.author_role === 'admin' ? 'message-admin' : (m.author_role === 'user' ? 'message-user' : 'message-system')}">
                        <strong>${escapeHTML(m.author_name)}</strong> - ${new Date(m.date).toLocaleString('fr-FR')}
                        <p style="margin-top:5px;">${escapeHTML(m.text)}</p>
                    </div>
                `).join('')
            }
        </div>

        <form onsubmit="sendMessage(event, ${ticket.id})" style="margin-top:20px;">
            <div class="form-group">
                <label for="cannedResponseSelect">Insérer un modèle de réponse :</label>
                <select id="cannedResponseSelect" onchange="applyCannedResponse()" style="width:100%;padding:10px;border-radius:8px;border:2px solid var(--gray-200);font-size:14px;background:white;">
                    <option value="">-- Choisir un modèle --</option>
                    ${cannedResponses.map(r => `<option value="${r.id}">${r.title}</option>`).join('')}
                </select>
            </div>
            
            <div class="form-group">
                <textarea id="adminMessage" placeholder="Répondre au ticket..." required></textarea>
            </div>
            
            <div class="form-group">
                <label>Joindre un fichier (optionnel)</label>
                <input type="file" id="adminFile" accept=".png,.jpg,.jpeg,.pdf,.gif,.webp" class="file-input-hidden" multiple>
                <div id="adminDropZone" class="custom-drop-zone"></div>
                <div id="adminFilePreview"></div>
            </div>
            <button type="submit" class="btn btn-primary">Envoyer</button>
        </form>
    `;

    document.getElementById('viewTicketModal').classList.add('active');
    
    const messagesContainer = document.getElementById('messagesContainer');
    if(messagesContainer) {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    if (typeof DragDropUpload !== 'undefined') {
         adminDragDrop = new DragDropUpload({
            dropZoneId: 'adminDropZone',
            fileInputId: 'adminFile',
            previewContainerId: 'adminFilePreview',
            maxFileSize: 20 * 1024 * 1024,
            allowedTypes: ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'application/pdf'],
            maxFiles: 5
        });
    }
}

// ==========================================
// GESTION DE L'ONGLET PARAMÈTRES
// ==========================================

function renderSettingsTab(defaultTab = 'admins') {
    const container = document.getElementById('settingsTab');
    
    container.innerHTML = `
        <h2 style="color:var(--gray-900);margin-bottom:20px;">⚙️ Paramètres</h2>
        <div class="settings-layout">
            <div class="settings-menu">
                <button id="settingsAdminsTab" class="settings-sub-tab" onclick="switchSettingsTab('admins')">
                    👥 Gestion des Admins
                </button>
                <button id="settingsCannedTab" class="settings-sub-tab" onclick="switchSettingsTab('canned')">
                    💬 Modèles de réponse
                </button>
                <button id="settingsGeneralTab" class="settings-sub-tab" onclick="switchSettingsTab('general')">
                    🌐 Général
                </button>
            </div>
            <div class="settings-content">
                <div id="settingsAdminsContent" class="settings-sub-content">
                    ${renderAdminsSubTab()}
                </div>
                <div id="settingsCannedContent" class="settings-sub-content" style="display:none;">
                    ${renderCannedSubTab()}
                </div>
                <div id="settingsGeneralContent" class="settings-sub-content" style="display:none;">
                    <!-- Le contenu sera injecté ici -->
                </div>
            </div>
        </div>
    `;
    
    switchSettingsTab(defaultTab, true);
}

// ==========================================
// GESTION DES ADMINS
// ==========================================

function renderAdminsSubTab() {
    return `
        <h3 style="color:var(--gray-dark);font-size:20px;margin-bottom:20px;">Gestion des Administrateurs</h3>
        <div class="canned-layout">
            <div class="canned-form">
                <h3>Inviter un nouvel admin</h3>
                <p style="color:var(--gray-600);font-size:14px;margin-bottom:20px;">
                    L'utilisateur recevra un email pour finaliser la création de son compte.
                </p>
                
                <div id="adminInviteError" class="error-message" style="display:none; margin-bottom: 15px;"></div>
                <div id="adminInviteSuccess" class="success-message" style="display:none; margin-bottom: 15px;"></div>

                <form onsubmit="inviteAdmin(event)">
                    <div class="form-group">
                        <label>Adresse email *</label>
                        <input type="email" id="adminEmail" required placeholder="nouvel.admin@email.com">
                    </div>
                    <button type="submit" class="btn btn-success" style="width:100%;">Envoyer l'invitation</button>
                </form>
            </div>
            <div class="canned-list">
                <h3>Admins existants</h3>
                <div id="adminListContainer">
                    ${renderAdminList()}
                </div>
            </div>
        </div>
    `;
}

function renderAdminList() {
    if (adminsList.length === 0) {
        return '<p style="color:var(--gray-600);padding:20px 0;">Aucun admin trouvé.</p>';
    }
    
    return adminsList.map(admin => `
        <div class="canned-item">
            <div class="canned-item-info">
                <strong>${escapeHTML(admin.fullname)}</strong>
            </div>
            ${admin.id == adminId ? 
                '<button class="btn btn-secondary btn-small" disabled>Vous</button>' : 
                '<button class="btn btn-danger btn-small" disabled>Supprimer (Bientôt)</button>'
            }
        </div>
    `).join('');
}

async function inviteAdmin(e) {
    e.preventDefault();
    
    // Cacher les anciens messages
    const errorDiv = document.getElementById('adminInviteError');
    const successDiv = document.getElementById('adminInviteSuccess');
    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';

    // --- DÉBUT DE LA CORRECTION ---
    // 1. Cible le bouton
    const inviteForm = e.target;
    const submitButton = inviteForm.querySelector('button[type="submit"]');

    // 2. Désactive le bouton et affiche "Envoi..."
    submitButton.disabled = true;
    submitButton.textContent = 'Envoi en cours...';
    // --- FIN DE LA CORRECTION ---

    const emailInput = document.getElementById('adminEmail');
    const email = emailInput.value;

    try {
        const res = await apiFetch('api.php?action=admin_invite', {
            method: 'POST',
            body: { email }
        });
        const data = await res.json();
        
        if (data.success) {
            successDiv.textContent = '✅ ' + data.message;
            successDiv.style.display = 'block';
            emailInput.value = ''; // Vider le champ
        } else {
            errorDiv.textContent = '❌ ' + data.message;
            errorDiv.style.display = 'block';
        }
    } catch (error) {
        console.error('Erreur invitation admin:', error);
        errorDiv.textContent = '❌ Erreur: ' + error.message;
        errorDiv.style.display = 'block';
    } finally {
        submitButton.disabled = false;
        submitButton.textContent = "Envoyer l'invitation";
    }
}

// ==========================================
// GESTION DES MODÈLES
// ==========================================

function renderCannedSubTab() {
    return `
        <h3 style="color:var(--gray-dark);font-size:20px;margin-bottom:20px;">Gestion des Modèles de Réponse</h3>
        <div class="canned-layout">
            <div class="canned-form">
                <h3>Nouveau Modèle</h3>
                <form onsubmit="createCannedResponse(event)">
                    <div class="form-group">
                        <label>Titre (ex: "Salutation") *</label>
                        <input type="text" id="cannedTitle" required>
                    </div>
                    <div class="form-group">
                        <label>Contenu de la réponse *</label>
                        <textarea id="cannedContent" rows="8" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-success" style="width:100%;">Enregistrer le modèle</button>
                </form>
            </div>
            <div class="canned-list">
                <h3>Modèles Enregistrés</h3>
                <div id="cannedListContainer">
                    ${renderCannedList()}
                </div>
            </div>
        </div>
    `;
}

function renderCannedList() {
    if (cannedResponses.length === 0) {
        return '<p style="color:var(--gray-600);padding:20px 0;">Aucun modèle enregistré.</p>';
    }
    
    return cannedResponses.map(r => `
        <div class="canned-item">
            <div class="canned-item-info">
                <strong>${escapeHTML(r.title)}</strong>
                <p>${escapeHTML(r.content.substring(0, 100))}...</p>
            </div>
            <button class="btn btn-danger btn-small" onclick="deleteCannedResponse(${r.id})">Supprimer</button>
        </div>
    `).join('');
}

async function createCannedResponse(e) {
    e.preventDefault();
    const title = document.getElementById('cannedTitle').value;
    const content = document.getElementById('cannedContent').value;
    
    try {
        const res = await apiFetch('api.php?action=canned_create', {
            method: 'POST',
            body: { title, content }
        });
        const data = await res.json();
        
        if (data.success) {
            showSuccessAnimation('Modèle créé !');
            await loadCannedResponses(); 
            renderSettingsTab('canned'); 
        } else {
            alert('❌ ' + data.message);
        }
    } catch (error) {
        console.error('Erreur création modèle:', error);
    }
}

async function deleteCannedResponse(id) {
    if (!confirm('Voulez-vous vraiment supprimer ce modèle ?')) return;
    
    try {
        const res = await apiFetch('api.php?action=canned_delete', {
            method: 'POST',
            body: { id }
        });
        const data = await res.json();
        
        if (data.success) {
            showSuccessAnimation('Modèle supprimé !');
            await loadCannedResponses(); 
            renderSettingsTab('canned'); 
        } else {
            alert('❌ ' + data.message);
        }
    } catch (error) {
        console.error('Erreur suppression modèle:', error);
    }
}

function applyCannedResponse() {
    const select = document.getElementById('cannedResponseSelect');
    const responseId = select.value;
    if (!responseId) return; 

    const response = cannedResponses.find(r => r.id == responseId);
    if (response) {
        const messageBox = document.getElementById('adminMessage');
        messageBox.value = response.content;
        messageBox.focus(); 
    }
    
    select.value = ""; 
}

// ==========================================
// ⭐ NOUVEAU : GESTION DES PARAMÈTRES GÉNÉRAUX
// ==========================================

async function renderGeneralSettings() {
    const container = document.getElementById('settingsGeneralContent');
    container.innerHTML = `<p>Chargement des paramètres...</p>`;

    try {
        const res = await apiFetch('api.php?action=get_app_settings');
        const data = await res.json();

        if (data.success) {
            const settings = data.settings;
            container.innerHTML = `
                <h3 style="color:var(--gray-dark);font-size:20px;margin-bottom:20px;">Paramètres Généraux</h3>
                <form onsubmit="saveGeneralSettings(event)">
                    <div id="settingsMessages"></div>
                    <div class="form-group">
                        <label for="appName">Nom de l'application</label>
                        <input type="text" id="appName" value="${escapeHTML(settings.app_name) || ''}" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="appPrimaryColor">Couleur principale</label>
                        <input type="color" id="appPrimaryColor" value="${escapeHTML(settings.app_primary_color) || '#EF8000'}" class="form-control" style="padding: 5px; height: 48px;">
                    </div>
                    <div class="form-group">
                        <label>Logo de l'application</label>
                        <div style="display:flex; align-items:center; gap:20px;">
                            <img src="${window.appBasePath || ''}/assets/${settings.app_logo_url || 'logo.png'}" alt="Logo actuel" style="height:50px; background:var(--gray-100); padding:5px; border-radius:8px;">
                            <input type="file" id="appLogo" class="form-control" accept="image/png, image/jpeg, image/gif, image/svg+xml">
                        </div>
                        <small>Laissez vide pour ne pas changer. Taille max: 5MB.</small>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Enregistrer les modifications</button>
                </form>
            `;
        } else {
            container.innerHTML = `<div class="error-message">${data.message}</div>`;
        }
    } catch (error) {
        container.innerHTML = `<div class="error-message">Erreur de chargement des paramètres.</div>`;
    }
}

async function saveGeneralSettings(e) {
    e.preventDefault();
    const messagesDiv = document.getElementById('settingsMessages');
    messagesDiv.innerHTML = '';

    const formData = new FormData();
    formData.append('app_name', document.getElementById('appName').value);
    formData.append('app_primary_color', document.getElementById('appPrimaryColor').value);
    
    const logoInput = document.getElementById('appLogo');
    if (logoInput.files.length > 0) {
        formData.append('app_logo', logoInput.files[0]);
    }

    try {
        const res = await apiFetch('api.php?action=update_app_settings', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            messagesDiv.innerHTML = `<div class="success-message" style="margin-bottom:15px;">${data.message}</div>`;
            showSuccessAnimation('Paramètres sauvegardés !');
            setTimeout(() => window.location.reload(), 1500); // Recharger pour voir les changements
        } else {
            messagesDiv.innerHTML = `<div class="error-message" style="margin-bottom:15px;">${data.message}</div>`;
        }
    } catch (error) {
        messagesDiv.innerHTML = `<div class="error-message" style="margin-bottom:15px;">Erreur de connexion au serveur.</div>`;
    }
}
// ==========================================
// UI D'ASSIGNATION
// ==========================================

function renderAssignmentUI(ticket) {
    // ⭐ CORRECTION : S'assurer que la liste des admins est chargée avant de continuer.
    // Si elle n'est pas prête, on retourne une chaîne vide pour éviter une erreur.
    if (!adminsList || adminsList.length === 0) {
        return '<p>Chargement des assignations...</p>';
    }

    let assignedAdmin = adminsList.find(a => a.id === ticket.assigned_to);
    let html = `
        <h4 style="margin-bottom:15px;">👤 Assignation</h4>
        <div class="assignment-body">
            <select id="adminAssignSelect" class="form-control" style="width:100%;padding:10px;border-radius:8px;border:2px solid var(--gray-200);">
                <option value="0">-- Non assigné --</option>
                ${adminsList.map(admin => `
                    <option value="${admin.id}" ${assignedAdmin && assignedAdmin.id === admin.id ? 'selected' : ''}>
                        ${admin.fullname}
                    </option>
                `).join('')}
            </select>
            <div class="assignment-actions">
                <button class="btn btn-success" onclick="assignTicket(${ticket.id})">Assigner</button>
                ${assignedAdmin ? `<button class="btn btn-secondary" onclick="unassignTicket(${ticket.id})">Désassigner</button>` : ''}
            </div>
        </div>
        ${assignedAdmin ? `<small style="color:var(--gray-600);margin-top:10px;display:block;">Assigné à ${escapeHTML(assignedAdmin.fullname)} ${ticket.assigned_at ? 'le ' + new Date(ticket.assigned_at).toLocaleDateString('fr-FR') : ''}</small>` : ''}
    `;
    return html;
}

// ==========================================
// ACTIONS D'ASSIGNATION
// ==========================================

async function assignTicket(ticketId) {
    const adminId = document.getElementById('adminAssignSelect').value;
    if (adminId === "0") {
        return unassignTicket(ticketId);
    }
    try {
        const res = await apiFetch('api.php?action=assign_ticket', {
            method: 'POST',
            body: {
                ticket_id: ticketId,
                admin_id: parseInt(adminId),
                note: `Assigné par ${adminFirstname}`
            }
        });
        const data = await res.json();
        if (data.success) {
            showSuccessAnimation('Ticket assigné !');
            clearTicketsCache(); // Vider le cache
            await loadTickets(); 
            const ticket = tickets.find(t => t.id === ticketId);
            if(ticket && document.getElementById('assignmentUI')) { 
                 document.getElementById('assignmentUI').innerHTML = renderAssignmentUI(ticket);
            }
        } else {
            alert('❌ ' + data.message);
        }
    } catch (error) {
        console.error('Erreur assignation:', error);
    }
}

async function unassignTicket(ticketId) {
    try {
        const res = await apiFetch('api.php?action=unassign_ticket', {
            method: 'POST',
            body: { ticket_id: ticketId }
        });
        const data = await res.json();
        if (data.success) {
            showSuccessAnimation('Ticket désassigné !');
            clearTicketsCache(); // Vider le cache
            await loadTickets(); 
            const ticket = tickets.find(t => t.id === ticketId);
             if(ticket && document.getElementById('assignmentUI')) { 
                 document.getElementById('assignmentUI').innerHTML = renderAssignmentUI(ticket);
            }
        } else {
            alert('❌ ' + data.message);
        }
    } catch (error) {
        console.error('Erreur désassignation:', error);
    }
}

// ==========================================
// ENVOI D'UN MESSAGE
// ==========================================

async function sendMessage(e, ticketId) {
    e.preventDefault();
    const messageInput = document.getElementById('adminMessage');
    const message = messageInput.value;
    
    messageInput.value = '';
    if (adminDragDrop) adminDragDrop.clear();
    
    try {
        // ⭐ SOLUTION : Utiliser la nouvelle action unifiée 'message_create'
        const res = await apiFetch('api.php?action=message_create', {
            method: 'POST',
            body: {
                ticket_id: ticketId,
                message: message
            }
        });
        const data = await res.json();
        if (data.success) {
            if (adminDragDrop && adminDragDrop.files.length > 0) {
                await adminDragDrop.uploadFiles(ticketId); 
                adminDragDrop.clear();
            }
            // ⭐ SOLUTION : Mettre à jour le modal sans le fermer
            clearTicketsCache(); // Vider le cache
            await loadTickets(); // Rafraîchit la liste des tickets
            await loadKOPStats(); // Rafraîchit les KPIs
            // Le modal reste ouvert et se met à jour grâce à la logique dans loadTickets()
        } else {
            alert('❌ ' + data.message);
            messageInput.value = message;
        }
    } catch (error) {
        console.error('Erreur:', error);
        alert('❌ Erreur lors de l\'envoi du message');
        messageInput.value = message;
    }
}

// ==========================================
// SUPPRESSION DE FICHIER
// ==========================================

async function deleteFile(fileId, ticketId) {
    if (!confirm('Supprimer ce fichier ?')) return;
    const res = await apiFetch('api.php?action=ticket_delete_file', {
        method: 'POST',
        // ⭐ SÉCURITÉ : Envoyer l'ID du ticket avec l'ID du fichier
        // pour que le serveur puisse valider que le fichier appartient bien au ticket.
        body: { file_id: fileId, ticket_id: ticketId }
    });
    const data = await res.json();
    if (data.success) {
        clearTicketsCache(); // Vider le cache
        await loadTickets();
    } else {
        alert('❌ ' + data.message);
    }
}

// ==========================================
// CHANGEMENT DE STATUT
// ==========================================

async function changeStatus(id, status) {
    const res = await apiFetch('api.php?action=ticket_update', {
        method: 'POST',
        body: { id, status }
    });
    const data = await res.json();
    if (data.success) {
        clearTicketsCache(); // Vider le cache
        await loadTickets(); 
        await loadKOPStats(); 
        document.dispatchEvent(new CustomEvent('ticketsUpdated')); 
    }
}

// ==========================================
// SUPPRESSION DE TICKET
// ==========================================

async function deleteTicket(id) {
    if (!confirm('Supprimer ce ticket ?')) return;
    const res = await apiFetch('api.php?action=ticket_delete', {
        method: 'POST',
        body: { id }
    });
    const data = await res.json();
    if (data.success) {
        clearTicketsCache(); // Vider le cache
        loadTickets(); 
        loadKOPStats(); 
    }
}

// ==========================================
// SUPPRESSION DE TOUS LES TICKETS
// ==========================================

function confirmDeleteAll() {
    document.getElementById('deleteAllModal').classList.add('active');
}

async function deleteAllTickets() {
    const res = await apiFetch('api.php?action=ticket_delete_all', {
        method: 'POST'
    });
    const data = await res.json();
    if (data.success) {
        closeDeleteAllModal();
        currentPage = 1; 
        clearTicketsCache(); // Vider le cache
        loadTickets();
        loadKOPStats(); 
        alert(`✅ ${data.deleted_count} tickets supprimés`);
    }
}

// ==========================================
// ANIMATIONS ET UTILITAIRES
// ==========================================

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
    if(overlay) overlay.classList.add('active');
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

// ==========================================
// MODALS
// ==========================================

function closeViewModal() {
    document.getElementById('viewTicketModal').classList.remove('active');
    document.getElementById('ticketDetails').dataset.ticketId = '';
    if (adminDragDrop) {
        adminDragDrop.clear();
    }
}
function closeDeleteAllModal() {
    document.getElementById('deleteAllModal').classList.remove('active');
}
function logout() {
    localStorage.clear();
    window.location.href = 'login.php';
}

// ==========================================
// EVENT LISTENERS
// ==========================================

document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', e => {
        if (e.target === modal) {
            if (modal.id === 'viewTicketModal') {
                closeViewModal();
            } else {
                modal.classList.remove('active');
            }
        }
    });
});

document.addEventListener('ticketsUpdated', () => {
    console.log('Notification reçue, rafraîchissement des tickets (admin)...');
    clearTicketsCache(); // Vider le cache
    loadTickets();
    loadKOPStats();
});

// ⭐ AMÉLIORATION UX : Polling pour les mises à jour
setInterval(() => {
    // Si un modal est ouvert, on ne fait rien pour ne pas perturber l'action en cours.
    if (document.querySelector('.modal.active')) {
        return;
    }

    // ⭐ CORRECTION : On vérifie toujours les statistiques en arrière-plan, peu importe l'onglet.
    // Cela permet de garder les KPIs à jour et de déclencher les notifications sonores.
    console.log('🔄 Vérification des mises à jour en arrière-plan...');
    loadKOPStats();

    // Si on est sur l'onglet des tickets, on rafraîchit aussi le tableau.
    if (currentTab === 'tickets') {
        console.log('🔄 Rafraîchissement du tableau des tickets...');
        loadTickets();
    }

}, 30000); // Toutes les 30 secondes

// ==========================================
// ⭐ NOUVEAU : SYSTÈME DE DÉCONNEXION AUTOMATIQUE
// ==========================================

class InactivityManager {
    constructor(timeoutMinutes = 15, warningMinutes = 2) {
        this.timeout = timeoutMinutes * 60 * 1000; // en millisecondes
        this.warningTime = warningMinutes * 60 * 1000;
        this.logoutTimer = null;
        this.warningTimer = null;
        this.warningModalVisible = false;

        this.events = ['mousemove', 'keydown', 'click', 'scroll'];
        this.resetTimer = this.resetTimer.bind(this); // Assure que 'this' est correct
        this.showWarning = this.showWarning.bind(this);
        this.finalLogout = this.finalLogout.bind(this);

        this.init();
    }

    init() {
        this.events.forEach(event => {
            window.addEventListener(event, this.resetTimer);
        });
        this.startTimers();
        this.injectModalHTML();
    }

    startTimers() {
        if (this.warningTimer) clearTimeout(this.warningTimer);
        if (this.logoutTimer) clearTimeout(this.logoutTimer);

        // Timer pour afficher l'avertissement
        this.warningTimer = setTimeout(this.showWarning, this.timeout - this.warningTime);

        // Timer pour la déconnexion finale
        this.logoutTimer = setTimeout(this.finalLogout, this.timeout);
        
        // console.log(`[Inactivity] Timers réinitialisés. Déconnexion dans ${this.timeout / 60000} minutes.`);
    }

    resetTimer() {
        // Si le modal d'avertissement est visible, le fait de bouger la souris suffit à le fermer.
        if (this.warningModalVisible) {
            this.stay();
            return;
        }
        this.startTimers();
    }

    showWarning() {
        this.warningModalVisible = true;
        const modal = document.getElementById('inactivityModal');
        const countdownElement = document.getElementById('inactivityCountdown');
        if (!modal || !countdownElement) return;

        modal.classList.add('active');
        
        let countdown = this.warningTime / 1000;
        countdownElement.textContent = countdown;

        this.countdownInterval = setInterval(() => {
            countdown--;
            countdownElement.textContent = countdown;
            if (countdown <= 0) {
                clearInterval(this.countdownInterval);
            }
        }, 1000);
    }

    stay() {
        const modal = document.getElementById('inactivityModal');
        if (modal) modal.classList.remove('active');
        
        if (this.countdownInterval) clearInterval(this.countdownInterval);
        this.warningModalVisible = false;
        this.resetTimer();
    }

    finalLogout() {
        // Appelle la fonction de déconnexion globale déjà existante
        logout();
    }

    injectModalHTML() {
        const modalHTML = `
            <div id="inactivityModal" class="modal">
                <div class="modal-content" style="max-width: 450px; text-align: center;">
                    <div class="modal-header">
                        <h3>Êtes-vous toujours là ?</h3>
                    </div>
                    <p style="margin: 20px 0; font-size: 16px;">Vous allez être déconnecté pour inactivité dans <strong id="inactivityCountdown">120</strong> secondes.</p>
                    <button class="btn btn-primary" onclick="inactivityManager.stay()" style="width: 100%;">Je suis toujours là</button>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);
    }

    destroy() {
        this.events.forEach(event => {
            window.removeEventListener(event, this.resetTimer);
        });
        clearTimeout(this.warningTimer);
        clearTimeout(this.logoutTimer);
    }
}

// Lancement du gestionnaire d'inactivité
const inactivityManager = new InactivityManager(15, 2); // 15 min timeout, 2 min warning



let adminDragDrop;