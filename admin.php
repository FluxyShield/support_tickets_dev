<?php
/**
 * @file admin.php
 * @brief Panneau d'administration principal du système de support.
 *
 * Cette page est le point d'entrée sécurisé pour les administrateurs.
 * Elle implémente une garde d'authentification stricte : si aucun administrateur
 * n'est connecté (vérifié via la session 'admin_session'), l'utilisateur est
 * immédiatement redirigé vers la page de connexion.
 *
 * La page sert de conteneur pour les différentes sections de l'administration
 * (Tickets, Statistiques, Paramètres), qui sont chargées dynamiquement en JavaScript.
 */
define('ROOT_PATH', __DIR__);
require_once 'config.php';
session_name('admin_session');
initialize_session();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}
$csrf_token = $_SESSION['csrf_token'] ?? '';
$admin_firstname = $_SESSION['admin_firstname'] ?? 'Admin';
setSecurityHeaders();
session_write_close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <title>Tableau de bord Admin - Support</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="css/admin-style.css">
    <link rel="stylesheet" href="css/file-viewer.css">
</head>
<body>
    <div class="admin-container">
        <div class="admin-sidebar">
            <div class="sidebar-header">
                <div class="logo">🎫 Support Admin</div>
            </div>
            <div class="sidebar-nav">
                <button class="admin-tab active" onclick="switchTab('tickets')">🎟️ Tickets</button>
                <button class="admin-tab" onclick="switchTab('stats')">📊 Statistiques</button>
                <button class="admin-tab" onclick="switchTab('settings')">⚙️ Paramètres</button>
            </div>
            <div class="sidebar-footer">
                <div id="adminName" class="admin-user">Bonjour <?php echo htmlspecialchars($admin_firstname); ?></div>
                <button class="btn btn-danger" onclick="logout()">Déconnexion</button>
            </div>
        </div>

        <div class="admin-main-content">
            <div id="ticketsTab" class="tab-content active">
                <div class="content-header">
                    <h1>Gestion des Tickets</h1>
                    <div class="header-actions">
                        <button id="myTicketsBtn" class="btn btn-secondary" onclick="toggleMyTickets()">Mes tickets</button>
                    </div>
                </div>

                <div class="stats-kpis">
                    <div class="kpi-card">
                        <div class="kpi-value" id="totalTickets">...</div>
                        <div class="kpi-label">Total</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value" id="openTickets">...</div>
                        <div class="kpi-label">Ouverts</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value" id="inProgressTickets">...</div>
                        <div class="kpi-label">En cours</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value" id="closedTickets">...</div>
                        <div class="kpi-label">Fermés</div>
                    </div>
                </div>

                <div class="filters">
                    <input type="text" id="adminSearchInput" onkeyup="handleSearch(event)" placeholder="Rechercher par ID...">
                    <select id="filterStatus" onchange="filterTickets()">
                        <option value="all">Tous les statuts</option>
                        <option value="Ouvert">Ouvert</option>
                        <option value="En cours">En cours</option>
                        <option value="Fermé">Fermé</option>
                    </select>
                    <select id="filterPriority" onchange="filterTickets()">
                        <option value="all">Toutes les priorités</option>
                        <option value="Haute">Haute</option>
                        <option value="Moyenne">Moyenne</option>
                        <option value="Basse">Basse</option>
                    </select>
                    <button class="btn btn-primary" onclick="triggerSearch()">Rechercher</button>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Utilisateur</th>
                                <th>Sujet</th>
                                <th>Catégorie</th>
                                <th>Priorité</th>
                                <th>Assigné à</th>
                                <th>Statut</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="ticketsTable">
                            <!-- Les tickets seront injectés ici par JS -->
                        </tbody>
                    </table>
                </div>
                <div id="paginationControls" class="pagination"></div>
            </div>

            <div id="statsTab" class="tab-content">
                <!-- Le contenu du dashboard sera injecté ici par JS -->
            </div>

            <div id="settingsTab" class="tab-content">
                <!-- Le contenu des paramètres sera injecté ici par JS -->
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div id="overlay" class="overlay"></div>

    <div id="viewTicketModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3>Détails du Ticket</h3>
                <button class="close-modal" onclick="closeViewModal()">&times;</button>
            </div>
            <div id="ticketDetails"></div>
        </div>
    </div>

    <div id="deleteAllModal" class="modal">
        <div class="modal-content" style="max-width: 450px; text-align: center;">
            <div class="modal-header">
                <h3>Confirmation</h3>
            </div>
            <p style="margin: 20px 0;">Êtes-vous sûr de vouloir supprimer TOUS les tickets ? Cette action est irréversible.</p>
            <div style="display:flex; gap:15px; justify-content:center;">
                <button class="btn btn-secondary" onclick="closeDeleteAllModal()">Annuler</button>
                <button class="btn btn-danger" onclick="deleteAllTickets()">Confirmer la suppression</button>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="js/drag-drop-upload.js"></script>
    <script src="js/file-viewer-system.js"></script>
    <script src="js/admin-script.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script src="js/admin-stats.js"></script>

    <script>
        // Initialisation de la page
        document.addEventListener('DOMContentLoaded', () => {
            window.appBasePath = "<?php echo rtrim(APP_URL_BASE, '/'); ?>";
            localStorage.setItem('admin_firstname', '<?php echo addslashes($admin_firstname); ?>');
            localStorage.setItem('admin_id', '<?php echo (int)($_SESSION['admin_id'] ?? 0); ?>');
            loadInitialData();
        });
    </script>
</body>
</html>