/**
 * Logique de gestion des utilisateurs pour le panneau d'administration
 */

let currentUsersPage = 1;
let usersSearchQuery = '';

// Initialisation au chargement
document.addEventListener('DOMContentLoaded', () => {
    // Si l'onglet actif est 'users', charger les données
    if (document.querySelector('.admin-tab.active')?.getAttribute('onclick')?.includes('users')) {
        loadUsers();
    }
});

// Surcharge de la fonction switchTab globale pour gérer l'onglet users
const originalSwitchTab = window.switchTab;
window.switchTab = function (tabName) {
    // Appel de la fonction originale
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));

    document.getElementById(tabName + 'Tab').classList.add('active');
    document.querySelector(`.admin-tab[onclick="switchTab('${tabName}')"]`).classList.add('active');

    // Logique spécifique
    if (tabName === 'users') {
        loadUsers();
    } else if (typeof originalSwitchTab === 'function') {
        // Pour les autres onglets, on laisse la logique existante (si elle existe ailleurs)
        // Note: admin-script.js gère déjà le switch UI, on ajoute juste le chargement de données ici.
    }
};

async function loadUsers(page = 1) {
    currentUsersPage = page;
    const tbody = document.getElementById('usersTable');
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">Chargement...</td></tr>';

    try {
        const res = await fetch(`api.php?action=get_users&page=${page}&limit=10&search=${encodeURIComponent(usersSearchQuery)}`, {
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        });
        const data = await res.json();

        if (data.success) {
            renderUsers(data.users);
            renderUsersPagination(data.pagination);
        } else {
            tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:red;">${data.message}</td></tr>`;
        }
    } catch (error) {
        console.error('Erreur:', error);
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:red;">Erreur de chargement</td></tr>';
    }
}

function renderUsers(users) {
    const tbody = document.getElementById('usersTable');
    if (users.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">Aucun utilisateur trouvé</td></tr>';
        return;
    }

    tbody.innerHTML = users.map(u => `
        <tr>
            <td>#${u.id}</td>
            <td>${escapeHtml(u.firstname)} ${escapeHtml(u.lastname)}</td>
            <td>${escapeHtml(u.email)}</td>
            <td>
                <span class="badge badge-${u.role === 'admin' ? 'high' : 'low'}">
                    ${u.role === 'admin' ? 'Administrateur' : 'Utilisateur'}
                </span>
            </td>
            <td>${u.created_at}</td>
            <td>
                ${u.role === 'user'
            ? `<button class="btn btn-success btn-small" onclick="promoteUser(${u.id}, '${escapeHtml(u.firstname)} ${escapeHtml(u.lastname)}')">Promouvoir Admin</button>`
            : `<button class="btn btn-danger btn-small" onclick="demoteUser(${u.id}, '${escapeHtml(u.firstname)} ${escapeHtml(u.lastname)}')">Rétrograder User</button>`
        }
            </td>
        </tr>
    `).join('');
}

function renderUsersPagination(pagination) {
    const container = document.getElementById('usersPagination');
    let html = '';

    if (pagination.totalPages > 1) {
        if (pagination.currentPage > 1) {
            html += `<button onclick="loadUsers(${pagination.currentPage - 1})">&laquo; Précédent</button>`;
        }

        html += `<span>Page ${pagination.currentPage} sur ${pagination.totalPages}</span>`;

        if (pagination.currentPage < pagination.totalPages) {
            html += `<button onclick="loadUsers(${pagination.currentPage + 1})">Suivant &raquo;</button>`;
        }
    }

    container.innerHTML = html;
}

let searchTimeout;
function handleUserSearch(e) {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        usersSearchQuery = e.target.value;
        loadUsers(1);
    }, 300);
}

async function promoteUser(userId, userName) {
    if (!confirm(`Êtes-vous sûr de vouloir promouvoir "${userName}" au rang d'Administrateur ?\n\nIl aura accès à TOUS les tickets et au panneau d'administration.`)) {
        return;
    }

    await updateUserRole(userId, 'admin');
}

async function demoteUser(userId, userName) {
    if (!confirm(`Êtes-vous sûr de vouloir rétrograder "${userName}" au rang d'Utilisateur ?\n\nIl perdra son accès au panneau d'administration.`)) {
        return;
    }

    await updateUserRole(userId, 'user');
}

async function updateUserRole(userId, newRole) {
    try {
        const res = await fetch('api.php?action=update_user_role', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({ user_id: userId, role: newRole })
        });
        const data = await res.json();

        if (data.success) {
            alert('✅ ' + data.message);
            loadUsers(currentUsersPage); // Recharger la liste
        } else {
            alert('❌ ' + data.message);
        }
    } catch (error) {
        console.error('Erreur:', error);
        alert('❌ Erreur de communication avec le serveur');
    }
}

// Utilitaire pour échapper le HTML (si pas déjà présent dans admin-script.js)
function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
