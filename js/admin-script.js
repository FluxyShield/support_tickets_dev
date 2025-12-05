/**
 * ADMIN SCRIPT - Support Ticketing System
 * Version Sécurisée (Session PHP) + Fonctionnalités Complètes
 */

// ==========================================
// 1. UTILITAIRES DE BASE
// ==========================================

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

// ==========================================
// 2. VARIABLES GLOBALES
// ==========================================

let tickets = []; // Contiendra uniquement les tickets de la page actuelle
let currentTab = 'tickets';
let adminsList = [];
let cannedResponses = [];

let currentPage = 1;
let itemsPerPage = 10;
let currentPaginationData = {};

// ❌ ANCIEN CODE SUPPRIMÉ : On ne vérifie plus le localStorage ici.
// La sécurité est gérée par le serveur et checkAdminSession().

const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

// ==========================================
// 3. SÉCURITÉ ET API (MODIFIÉ)
// ==========================================

/**
 * Vérifie si la session admin est active via le serveur au démarrage.
 */
async function checkAdminSession() {
    try {
        // On appelle une action légère pour tester la session
        // get_app_settings est parfait car il renvoie les infos sans être lourd
        const response = await apiFetch('api.php?action=get_app_settings');
        const data = await response.json();

        if (data.success) {
            // La session est valide, on peut charger l'interface
            loadInitialData();

            // Si l'API renvoyait le nom de l'admin, on pourrait l'afficher ici
            // Sinon, le serveur gère l'affichage via PHP ou une autre requête
            // document.getElementById('adminName').textContent = ...
        }
    } catch (error) {
        console.error("Erreur lors de la vérification de session:", error);
    }
}

/**
 * Wrapper API Fetch sécurisé
 * - Ajoute le CSRF Token
 * - Gère le Content-Type (JSON vs FormData)
 * - Intercepte les erreurs 401/Session Expirée
 */
async function apiFetch(url, options = {}) {
    // Prépare les headers
    options.headers = options.headers || {};
    options.headers['X-CSRF-TOKEN'] = csrfToken;
    options.headers['X-Requested-With'] = 'XMLHttpRequest';

    // Gestion intelligente du Content-Type
    // Si body est un objet mais PAS un FormData, on le stringify en JSON
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(options.body);
    }

    // Si c'est une requête GET, on ne met pas de body
    if (options.method && options.method.toUpperCase() === 'GET') {
        delete options.body;
    }

    try {
        const response = await fetch(url, options);

        // Vérification si la réponse est du JSON pour l'interception d'erreur
        const contentType = response.headers.get("content-type");
        if (contentType && contentType.indexOf("application/json") !== -1) {
            // On clone la réponse pour pouvoir la lire ici ET la renvoyer
            const clone = response.clone();
            const data = await clone.json();

            // 🔒 INTERCEPTEUR DE SÉCURITÉ
            if (data.success === false && (data.message === 'Authentification admin requise' || data.message === 'Authentification requise')) {
                console.warn('Session expirée détectée. Redirection...');
                alert('Votre session a expiré. Veuillez vous reconnecter.');
                window.location.href = 'login.php';
                // On bloque la suite
                return new Promise(() => { });
            }

            // Pour garder la compatibilité avec votre code existant qui fait "await res.json()"
            // On renvoie un objet qui a une méthode .json() qui retourne déjà la data
            return {
                ok: response.ok,
                status: response.status,
                headers: response.headers,
                json: () => Promise.resolve(data)
            };
        }

        // Si ce n'est pas du JSON (ex: téléchargement fichier), on renvoie la réponse brute
        return response;

    } catch (error) {
        console.error('Erreur apiFetch:', error);
        throw error;
    }
}

// ==========================================
// 4. CHARGEMENT ET NAVIGATION
// ==========================================

// Point d'entrée du script
document.addEventListener('DOMContentLoaded', () => {
    checkAdminSession();

    // Gestionnaire déconnexion
    const logoutBtn = document.getElementById('logoutButton');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            await apiFetch('api.php?action=logout', { method: 'POST' });
            window.location.href = 'login.php';
        });
    }
});

// Navigation par onglets
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
        // Si vous avez une fonction loadAdvancedStats, elle serait appelée ici
        if (typeof loadAdvancedStats === 'function') loadAdvancedStats('30');

    } else if (tab === 'settings') {
        document.querySelectorAll('.admin-tab')[2].classList.add('active');
        document.getElementById('settingsTab').classList.add('active');
        // Load admins list when switching to settings
        renderAdminsList();
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

// New function for sidebar-based settings navigation
function switchSettingsSection(section) {
    // Remove active class from all sidebar buttons
    document.querySelectorAll('.sidebar-btn').forEach(btn => btn.classList.remove('active'));

    // Hide all settings panels
    document.querySelectorAll('.settings-panel').forEach(panel => panel.style.display = 'none');

    // Show selected section
    if (section === 'admin') {
        document.querySelector('.sidebar-btn[onclick*="admin"]').classList.add('active');
        document.getElementById('adminSection').style.display = 'block';
        renderAdminsList();
    } else if (section === 'responses') {
        document.querySelector('.sidebar-btn[onclick*="responses"]').classList.add('active');
        document.getElementById('responsesSection').style.display = 'block';
    } else if (section === 'general') {
        document.querySelector('.sidebar-btn[onclick*="general"]').classList.add('active');
        document.getElementById('generalSection').style.display = 'block';
    }
}

// Function to render admins list
function renderAdminsList() {
    const adminsList = document.getElementById('adminsList');
    if (!adminsList || !window.adminsList || window.adminsList.length === 0) {
        return;
    }

    adminsList.innerHTML = window.adminsList.map(admin => `
        <div class="admin-list-item">
            <strong>${escapeHTML(admin.firstname)} ${escapeHTML(admin.lastname)}</strong>
            ${admin.is_current ? '<span class="badge-you">Vous</span>' : ''}
            ${!admin.is_current ? `<button class="btn btn-danger btn-small" onclick="deleteAdmin(${admin.id})">🗑️ Supprimer</button>` : ''}
        </div>
    `).join('');
}

// Function to send admin invite
async function sendAdminInvite() {
    const emailInput = document.getElementById('inviteAdminEmail');
    const email = emailInput.value.trim();

    if (!email) {
        alert('Veuillez entrer une adresse email');
        return;
    }

    try {
        const res = await apiFetch('api.php?action=invite_admin', {
            method: 'POST',
            body: { email }
        });
        const data = await res.json();

        if (data.success) {
            alert('Invitation envoyée avec succès !');
            emailInput.value = '';
            await loadAdmins();
            renderAdminsList();
        } else {
            alert('Erreur : ' + data.message);
        }
    } catch (error) {
        console.error('Erreur lors de l\'envoi de l\'invitation:', error);
        alert('Erreur de connexion au serveur');
    }
}

// Function to delete admin
async function deleteAdmin(adminId) {
    if (!confirm('Êtes-vous sûr de vouloir supprimer cet administrateur ?')) {
        return;
    }

    try {
        const res = await apiFetch('api.php?action=delete_admin', {
            method: 'POST',
            body: { admin_id: adminId }
        });
        const data = await res.json();

        if (data.success) {
            alert('Administrateur supprimé avec succès');
            await loadAdmins();
            renderAdminsList();
        } else {
            alert('Erreur : ' + data.message);
        }
    } catch (error) {
        console.error('Erreur lors de la suppression:', error);
        alert('Erreur de connexion au serveur');
    }
}


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
        ['totalTickets', 'openTickets', 'inProgressTickets', 'closedTickets'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = '...';
        });
    }
}

async function loadAdmins() {
    try {
        const res = await apiFetch('api.php?action=get_admins');
        const data = await res.json();
        if (data.success) {
            window.adminsList = data.admins;
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

// ==========================================
// 5. GESTION DES TICKETS (CACHE & FETCH)
// ==========================================

// Variable globale pour annuler les requêtes
let abortController = null;

// Cache simple
const ticketsCache = new Map();
const CACHE_DURATION = 30000;

function getCacheKey() {
    const statusFilter = document.getElementById('filterStatus').value;
    const priorityFilter = document.getElementById('filterPriority').value;
    const searchTerm = document.getElementById('adminSearchInput').value;
    const filterMyTickets = window.filterMyTickets || false;
    return `${currentPage}-${itemsPerPage}-${statusFilter}-${priorityFilter}-${searchTerm}-${filterMyTickets}`;
}

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

function saveToCache(key, data) {
    ticketsCache.set(key, {
        data: data,
        timestamp: Date.now()
    });
    if (ticketsCache.size > 20) {
        const firstKey = ticketsCache.keys().next().value;
        ticketsCache.delete(firstKey);
    }
}

function showLoadingIndicator() {
    const tbody = document.getElementById('ticketsTable');
    if (tbody) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align:center;padding:40px;">
                    <div style="display:inline-block;width:40px;height:40px;border:4px solid var(--gray-200);border-top-color:var(--orange);border-radius:50%;animation:spin 1s linear infinite;"></div>
                    <p style="margin-top:15px;color:var(--gray-600);">Chargement des tickets...</p>
                </td>
            </tr>
        `;
    }
}

async function loadTickets() {
    if (abortController) {
        abortController.abort();
    }
    abortController = new AbortController();

    const statusFilter = document.getElementById('filterStatus').value;
    const priorityFilter = document.getElementById('filterPriority').value;
    const searchTerm = document.getElementById('adminSearchInput').value;
    const filterMyTickets = window.filterMyTickets || false;

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

    showLoadingIndicator();

    try {
        const url = `api.php?action=ticket_list&page=${currentPage}&limit=${itemsPerPage}&status=${statusFilter}&priority=${priorityFilter}&search=${encodeURIComponent(searchTerm)}&my_tickets=${filterMyTickets}&include_files=false`;

        const startTime = performance.now();
        const res = await apiFetch(url, { signal: abortController.signal });
        const data = await res.json();
        const loadTime = (performance.now() - startTime).toFixed(0);

        if (data.success) {
            tickets = data.tickets;
            currentPaginationData = data.pagination;

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

function updateModalIfOpen() {
    const modal = document.getElementById('viewTicketModal');
    if (modal && modal.classList.contains('active')) {
        const openTicketId = document.getElementById('ticketDetails').dataset.ticketId;
        if (openTicketId) {
            const updatedTicket = tickets.find(t => t.id == openTicketId);
            if (updatedTicket) {
                refreshModalContent(updatedTicket);
            } else {
                closeViewModal();
            }
        }
    }
}

function refreshModalContent(ticket) {
    console.log(`🔄 Rafraîchissement du modal pour le ticket #${ticket.id}`);

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

    const assignmentUI = document.getElementById('assignmentUI');
    if (assignmentUI) assignmentUI.innerHTML = renderAssignmentUI(ticket);
}

async function loadTicketFiles(ticketId) {
    window.loadedTicketFiles = [];
    try {
        const res = await apiFetch(`api.php?action=ticket_list&limit=1&search=${ticketId}&include_files=true`);
        const data = await res.json();

        if (data.success && data.tickets.length > 0) {
            const ticket = tickets.find(t => t.id === ticketId);
            if (ticket) {
                ticket.files = data.tickets[0].files;
                window.loadedTicketFiles = data.tickets[0].files;
            }
        }
    } catch (error) {
        console.error('Erreur chargement fichiers:', error);
    }
}

function showErrorMessage(message) {
    const tbody = document.getElementById('ticketsTable');
    if (tbody) {
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
}

// Recherche et filtres
// let searchTimeout = null; // REMOVED: Duplicate declaration
function debouncedSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        currentPage = 1;
        loadTickets();
    }, 500);
}

function handleSearch(event) {
    if (event.key === 'Enter') {
        clearTimeout(searchTimeout);
        currentPage = 1;
        loadTickets();
    } else {
        debouncedSearch();
    }
}

function triggerSearch() {
    clearTimeout(searchTimeout);
    currentPage = 1;
    loadTickets();
}

function clearTicketsCache() {
    ticketsCache.clear();
    console.log('🗑️ Cache vidé');
}

// Pagination et préchargement
function preloadNextPage() {
    if (!currentPaginationData || currentPage >= currentPaginationData.totalPages) return;

    const nextPage = currentPage + 1;
    const statusFilter = document.getElementById('filterStatus').value;
    const priorityFilter = document.getElementById('filterPriority').value;
    const searchTerm = document.getElementById('adminSearchInput').value;
    const filterMyTickets = window.filterMyTickets || false;

    const url = `api.php?action=ticket_list&page=${nextPage}&limit=${itemsPerPage}&status=${statusFilter}&priority=${priorityFilter}&search=${encodeURIComponent(searchTerm)}&my_tickets=${filterMyTickets}&include_files=false`;

    apiFetch(url).then(res => res.json()).then(data => {
        if (data.success) {
            const cacheKey = `${nextPage}-${itemsPerPage}-${statusFilter}-${priorityFilter}-${searchTerm}-${filterMyTickets}`;
            saveToCache(cacheKey, {
                tickets: data.tickets,
                pagination: data.pagination
            });
            console.log('📥 Page suivante préchargée');
        }
    }).catch(() => { });
}

function goToPage(page) {
    if (page < 1 || (currentPaginationData.totalPages && page > currentPaginationData.totalPages) || page === currentPage) {
        return;
    }
    currentPage = page;
    loadTickets();
    setTimeout(preloadNextPage, 500);
}

window.clearTicketsCache = clearTicketsCache;

// Filtres supplémentaires
function filterTickets() {
    currentPage = 1;
    loadTickets();
}

window.filterMyTickets = false;

function toggleMyTickets() {
    window.filterMyTickets = !window.filterMyTickets;
    const btn = document.getElementById('myTicketsBtn');
    btn.classList.toggle('active', window.filterMyTickets);
    currentPage = 1;
    loadTickets();
}

// ==========================================
// 6. RENDU DES TICKETS ET INTERFACE
// ==========================================

function renderTickets() {
    const tbody = document.getElementById('ticketsTable');
    if (!tbody) return;

    if (tickets.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:40px;color:var(--gray-600);">Aucun ticket trouvé pour ces filtres</td></tr>';
        return;
    }

    tbody.innerHTML = tickets.map(t => {
        const unread = t.messages.filter(m => m.is_read === 0 && m.author_role === 'user').length;

        let assignedAdmin = null;
        if (t.assigned_to && adminsList) {
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
        if (currentPage > 3) html += `<span class="pagination-dots">...</span>`;

        let startPage = Math.max(2, currentPage - 1);
        let endPage = Math.min(totalPages - 1, currentPage + 1);

        if (currentPage <= 2) endPage = 3;
        if (currentPage >= totalPages - 1) startPage = totalPages - 2;

        for (let i = startPage; i <= endPage; i++) {
            html += `<button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
        }

        if (currentPage < totalPages - 2) html += `<span class="pagination-dots">...</span>`;
        html += `<button class="pagination-btn ${totalPages === currentPage ? 'active' : ''}" onclick="goToPage(${totalPages})">${totalPages}</button>`;
    }

    html += `<button class="pagination-btn" onclick="goToPage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>Suivant</button>`;
    container.innerHTML = html;
}

// ==========================================
// 7. MODAL ET DÉTAILS TICKET
// ==========================================
async function viewTicket(id) {
    let ticket = tickets.find(t => t.id === id);

    const hasUnreadUserMessages = ticket.messages.some(m => m.is_read === 0 && m.author_role === 'user');
    if (hasUnreadUserMessages) {
        clearTicketsCache();
        await apiFetch('api.php?action=message_read', {
            method: 'POST',
            body: { ticket_id: id }
        });
        await loadTickets();
    }

    if (!ticket || !ticket.messages || !ticket.files) {
        await loadTicketFiles(id);
        ticket = tickets.find(t => t.id === id);

        if (!ticket || !ticket.messages) {
            const res = await apiFetch(`api.php?action=ticket_list&limit=1&search=${id}&include_files=true`);
            const data = await res.json();
            if (data.success && data.tickets.length > 0) {
                ticket = data.tickets[0];
                const index = tickets.findIndex(t => t.id === id);
                if (index !== -1) tickets[index] = ticket;
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
    if (messagesContainer) {
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
// 8. PARAMÈTRES & ADMINS
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
                    </div>
            </div>
        </div>
    `;

    switchSettingsTab(defaultTab, true);
}

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
            <button class="btn btn-danger btn-small" onclick="deleteAdmin(${admin.id}, '${escapeHTML(admin.fullname)}')" title="Supprimer cet administrateur">
                🗑️ Supprimer
            </button>
        </div>
    `).join('');
}

async function inviteAdmin(e) {
    e.preventDefault();

    const errorDiv = document.getElementById('adminInviteError');
    const successDiv = document.getElementById('adminInviteSuccess');
    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';

    const inviteForm = e.target;
    const submitButton = inviteForm.querySelector('button[type="submit"]');

    submitButton.disabled = true;
    submitButton.textContent = 'Envoi en cours...';

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
            emailInput.value = '';
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

async function deleteAdmin(adminId, adminName) {
    if (!confirm(`Êtes-vous sûr de vouloir supprimer l'administrateur "${adminName}" ?\n\nCette action est irréversible.`)) {
        return;
    }

    try {
        const res = await apiFetch('api.php?action=delete_admin', {
            method: 'POST',
            body: { admin_id: adminId }
        });
        const data = await res.json();

        if (data.success) {
            showSuccessAnimation('Administrateur supprimé');
            loadAdmins().then(() => {
                renderSettingsTab('admins');
            });
        } else {
            alert('❌ ' + data.message);
        }
    } catch (error) {
        console.error('Erreur suppression admin:', error);
        alert('❌ Erreur de connexion au serveur');
    }
}

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
        const res = await apiFetch('api.php?action=canned_response_create', {
            method: 'POST',
            body: { title, content }
        });
        const data = await res.json();
        if (data.success) {
            showSuccessAnimation('Modèle créé');
            document.getElementById('cannedTitle').value = '';
            document.getElementById('cannedContent').value = '';
            loadCannedResponses().then(() => {
                document.getElementById('cannedListContainer').innerHTML = renderCannedList();
            });
        } else {
            alert('❌ ' + data.message);
        }
    } catch (error) {
        console.error('Erreur création modèle:', error);
    }
}

async function deleteCannedResponse(id) {
    if (!confirm('Supprimer ce modèle ?')) return;

    try {
        const res = await apiFetch('api.php?action=canned_response_delete', {
            method: 'POST',
            body: { id }
        });
        const data = await res.json();
        if (data.success) {
            loadCannedResponses().then(() => {
                document.getElementById('cannedListContainer').innerHTML = renderCannedList();
            });
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
    }
}

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
            setTimeout(() => window.location.reload(), 1500);
        } else {
            messagesDiv.innerHTML = `<div class="error-message" style="margin-bottom:15px;">${data.message}</div>`;
        }
    } catch (error) {
        messagesDiv.innerHTML = `<div class="error-message" style="margin-bottom:15px;">Erreur de connexion au serveur.</div>`;
    }
}

// ==========================================
// 10. ACTIONS (ASSIGNATION, MESSAGE, STATUS)
// ==========================================

function renderAssignmentUI(ticket) {
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
                note: `Assigné par un administrateur`
            }
        });
        const data = await res.json();
        if (data.success) {
            showSuccessAnimation('Ticket assigné !');
            clearTicketsCache();
            await loadTickets();
            const ticket = tickets.find(t => t.id === ticketId);
            if (ticket && document.getElementById('assignmentUI')) {
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
    if (!confirm('Voulez-vous vraiment désassigner ce ticket ?')) return;

    try {
        const res = await apiFetch('api.php?action=assign_ticket', {
            method: 'POST',
            body: {
                ticket_id: ticketId,
                admin_id: null,
                note: `Désassigné par un administrateur`
            }
        });
        const data = await res.json();
        if (data.success) {
            showSuccessAnimation('Ticket désassigné');
            clearTicketsCache();
            await loadTickets();
            const ticket = tickets.find(t => t.id === ticketId);
            if (ticket && document.getElementById('assignmentUI')) {
                document.getElementById('assignmentUI').innerHTML = renderAssignmentUI(ticket);
            }
        } else {
            alert('❌ ' + data.message);
        }
    } catch (error) {
        console.error('Erreur désassignation:', error);
    }
}

async function sendMessage(e, ticketId) {
    e.preventDefault();
    const messageInput = document.getElementById('adminMessage');
    const message = messageInput.value.trim();
    const fileInput = document.getElementById('adminFile');

    if (!message && fileInput.files.length === 0) return;

    const submitBtn = e.target.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Envoi...';

    const formData = new FormData();
    formData.append('ticket_id', ticketId);
    formData.append('message', message);

    for (let i = 0; i < fileInput.files.length; i++) {
        formData.append('files[]', fileInput.files[i]);
    }

    try {
        const res = await apiFetch('api.php?action=ticket_reply', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            messageInput.value = '';
            fileInput.value = '';
            document.getElementById('adminFilePreview').innerHTML = '';
            showSuccessAnimation('Message envoyé');

            // Recharger le ticket pour voir le nouveau message
            clearTicketsCache();
            await loadTickets();

            // Mettre à jour le modal si ouvert
            const ticket = tickets.find(t => t.id === ticketId);
            if (ticket) refreshModalContent(ticket);

        } else {
            alert('❌ ' + data.message);
        }
    } catch (error) {
        console.error('Erreur envoi message:', error);
        alert('❌ Erreur lors de l\'envoi');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Envoyer';
    }
}

async function deleteFile(fileId, ticketId) {
    if (!confirm('Supprimer ce fichier ?')) return;

    try {
        const res = await apiFetch('api.php?action=ticket_delete_file', {
            method: 'POST',
            body: { file_id: fileId, ticket_id: ticketId }
        });
        const data = await res.json();
        if (data.success) {
            showSuccessAnimation('Fichier supprimé');
            await loadTicketFiles(ticketId);
            const ticket = tickets.find(t => t.id === ticketId);
            if (ticket) refreshModalContent(ticket);
        } else {
            alert('❌ ' + data.message);
        }
    } catch (error) {
        console.error('Erreur suppression fichier:', error);
    }
}

async function changeStatus(id, newStatus) {
    try {
        const res = await apiFetch('api.php?action=ticket_update_status', {
            method: 'POST',
            body: { ticket_id: id, status: newStatus }
        });
        const data = await res.json();

        if (data.success) {
            showSuccessAnimation(`Statut changé en "${newStatus}"`);
            // Mise à jour locale optimiste
            const ticket = tickets.find(t => t.id === id);
            if (ticket) {
                ticket.status = newStatus;
                renderTickets();
                // Si le modal est ouvert, le mettre à jour
                const modal = document.getElementById('viewTicketModal');
                if (modal && modal.classList.contains('active') && document.getElementById('ticketDetails').dataset.ticketId == id) {
                    refreshModalContent(ticket);
                }
            }
            // Rafraîchir les stats KOP
            loadKOPStats();
        } else {
            alert('❌ ' + data.message);
        }
    } catch (error) {
        console.error('Erreur changement statut:', error);
    }
}

async function deleteTicket(id) {
    if (!confirm('Êtes-vous sûr de vouloir supprimer ce ticket ? Cette action est irréversible.')) return;

    try {
        const res = await apiFetch('api.php?action=ticket_delete', {
            method: 'POST',
            body: { ticket_id: id }
        });
        const data = await res.json();

        if (data.success) {
            showSuccessAnimation('Ticket supprimé');
            // Suppression locale
            tickets = tickets.filter(t => t.id !== id);
            renderTickets();
            loadKOPStats();

            // Si le modal était ouvert sur ce ticket, le fermer
            const modal = document.getElementById('viewTicketModal');
            if (modal && modal.classList.contains('active') && document.getElementById('ticketDetails').dataset.ticketId == id) {
                closeViewModal();
            }
        } else {
            alert('❌ ' + data.message);
        }
    } catch (error) {
        console.error('Erreur suppression ticket:', error);
    }
}

function confirmDeleteAll() {
    document.getElementById('deleteAllModal').classList.add('active');
}

async function deleteAllTickets() {
    const password = document.getElementById('deletePassword').value;
    if (!password) {
        alert('Veuillez entrer votre mot de passe');
        return;
    }

    if (!confirm('⚠️ ATTENTION : Vous allez supprimer TOUS les tickets fermés.\nCette action est irréversible.\n\nConfirmer ?')) {
        return;
    }

    try {
        const res = await apiFetch('api.php?action=ticket_delete_all_closed', {
            method: 'POST',
            body: { password }
        });
        const data = await res.json();

        if (data.success) {
            showSuccessAnimation(`${data.count} tickets supprimés`);
            closeDeleteAllModal();
            document.getElementById('deletePassword').value = '';
            currentPage = 1;
            loadTickets();
            loadKOPStats();
        } else {
            alert('❌ ' + data.message);
        }
    } catch (error) {
        console.error('Erreur suppression massive:', error);
        alert('❌ Erreur serveur');
    }
}

// ==========================================
// 11. ANIMATIONS ET UI
// ==========================================

function showSuccessAnimation(message) {
    showAnimation('✅', message, 'var(--success)');
}

function showLoadingAnimation(message = 'Chargement...') {
    const div = document.createElement('div');
    div.id = 'globalLoading';
    div.style.cssText = `position:fixed;top:20px;right:20px;background:white;padding:15px 25px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);display:flex;align-items:center;gap:10px;z-index:9999;border-left:4px solid var(--primary);`;
    div.innerHTML = `<div style="width:20px;height:20px;border:2px solid var(--gray-200);border-top-color:var(--primary);border-radius:50%;animation:spin 1s linear infinite;"></div><span style="font-weight:500;color:#333;">${message}</span>`;
    document.body.appendChild(div);
}

function showAnimation(icon, message, color) {
    const div = document.createElement('div');
    div.style.cssText = `position:fixed;top:20px;right:20px;background:white;padding:15px 25px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);display:flex;align-items:center;gap:10px;z-index:9999;animation:slideIn 0.3s ease-out;border-left:4px solid ${color};`;
    div.innerHTML = `<span style="font-size:20px;">${icon}</span><span style="font-weight:500;color:#333;">${message}</span>`;
    document.body.appendChild(div);
    setTimeout(() => {
        div.style.animation = 'slideOut 0.3s ease-in forwards';
        setTimeout(() => div.remove(), 300);
    }, 3000);
}

function hideLoadingAnimation() {
    const el = document.getElementById('globalLoading');
    if (el) el.remove();
}

function closeViewModal() {
    document.getElementById('viewTicketModal').classList.remove('active');
    // Nettoyer l'URL si nécessaire ou l'état
}

function closeDeleteAllModal() {
    document.getElementById('deleteAllModal').classList.remove('active');
}

async function logout() {
    try {
        await apiFetch('api.php?action=logout', { method: 'POST' });
        window.location.href = 'login.php';
    } catch (e) {
        window.location.href = 'login.php';
    }
}

// ==========================================
// 12. GESTION INACTIVITÉ
// ==========================================

class InactivityManager {
    constructor(timeoutMinutes = 15, warningMinutes = 2) {
        this.timeout = timeoutMinutes * 60 * 1000;
        this.warning = warningMinutes * 60 * 1000;
        this.timer = null;
        this.warningTimer = null;
        this.lastActivity = Date.now();

        this.init();
    }

    init() {
        // Événements à surveiller
        ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(evt => {
            document.addEventListener(evt, () => this.resetTimer(), true);
        });

        // Vérification périodique
        setInterval(() => this.checkInactivity(), 1000);

        this.injectModalHTML();

        // Gestionnaire du bouton "Rester connecté"
        document.addEventListener('click', (e) => {
            if (e.target && e.target.id === 'stayConnectedBtn') {
                this.stay();
            }
        });
    }

    resetTimer() {
        this.lastActivity = Date.now();
        const modal = document.getElementById('inactivityModal');
        if (modal && modal.style.display === 'flex') {
            // Si le modal est affiché, on ne reset pas automatiquement, l'utilisateur doit cliquer
        }
    }

    checkInactivity() {
        const now = Date.now();
        const inactiveTime = now - this.lastActivity;
        const timeUntilTimeout = this.timeout - inactiveTime;

        if (timeUntilTimeout <= 0) {
            this.finalLogout();
        } else if (timeUntilTimeout <= this.warning) {
            this.showWarning(Math.ceil(timeUntilTimeout / 1000));
        }
    }

    showWarning(secondsRemaining) {
        const modal = document.getElementById('inactivityModal');
        const timerSpan = document.getElementById('inactivityTimer');
        if (modal) {
            modal.style.display = 'flex';
            if (timerSpan) timerSpan.textContent = secondsRemaining;
        }
    }

    stay() {
        this.lastActivity = Date.now();
        const modal = document.getElementById('inactivityModal');
        if (modal) modal.style.display = 'none';

        // Ping le serveur pour garder la session PHP active
        apiFetch('api.php?action=get_app_settings').catch(() => { });
    }

    finalLogout() {
        window.location.href = 'login.php?timeout=1';
    }

    injectModalHTML() {
        if (document.getElementById('inactivityModal')) return;
        const modalHTML = `
            <div id="inactivityModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;">
                <div style="background:white;padding:30px;border-radius:12px;text-align:center;max-width:400px;box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <div style="font-size:48px;margin-bottom:15px;">⏳</div>
                    <h3 style="margin-bottom:10px;">Inactivité détectée</h3>
                    <p style="color:var(--gray-600);margin-bottom:20px;">Vous allez être déconnecté dans <span id="inactivityTimer" style="font-weight:bold;color:var(--danger);">60</span> secondes.</p>
                    <button id="stayConnectedBtn" class="btn btn-primary" style="width:100%;">Rester connecté</button>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);
    }
}
const inactivityManager = new InactivityManager(15, 2);
let adminDragDrop;