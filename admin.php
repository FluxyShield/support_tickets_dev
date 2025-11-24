<?php
/**
 * @file admin.php
 * @brief Panneau d'administration et page de connexion pour les administrateurs.
 *
 * Ce fichier a un double rôle :
 * 1. Si l'administrateur n'est pas connecté, il affiche un formulaire de connexion sécurisé.
 * 2. Si l'administrateur est connecté, il affiche le tableau de bord complet de l'application,
 *    qui est une interface dynamique (SPA) gérée par `js/admin-script.js`.
 */

define('ROOT_PATH', __DIR__);
require_once 'config.php';
session_name('admin_session');
initialize_session();

$isAdminLoggedIn = isset($_SESSION['admin_id']);
$csrf_token = $_SESSION['csrf_token'] ?? '';
$admin_name = $_SESSION['admin_firstname'] ?? 'Admin';

// La session est libérée après avoir récupéré les infos nécessaires pour le rendu initial
session_write_close(); 

// Si l'admin n'est pas connecté, on affiche la page de connexion.
if (!$isAdminLoggedIn) {
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <link rel="stylesheet" href="admin-styles.css">
    <title>Connexion Admin - Support Ticketing</title>
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-header">
            <h1>🎫 Support Ticketing</h1>
            <p>Connexion Administrateur</p>
        </div>
        <div class="login-content">
            <div id="errorMsg" class="error-message" style="display:none;"></div>
            <form id="loginForm" onsubmit="loginAdmin(event)">
                <div class="form-group">
                    <label>Adresse email</label>
                    <input type="email" id="email" required>
                </div>
                <div class="form-group">
                    <label>Mot de passe</label>
                    <input type="password" id="password" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Se connecter</button>
            </form>
            <div style="text-align:center;margin-top:20px;">
                <a href="forgot_password.php?role=admin" style="color:var(--primary);text-decoration:none;">Mot de passe oublié ?</a>
            </div>
        </div>
    </div>
    <script src="js/login-script.js"></script>
</body>
</html>
<?php
    exit(); // On arrête le script ici.
}

// Si on arrive ici, c'est que l'admin est connecté. On affiche le tableau de bord.
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <title>Tableau de bord - Support</title>
    <link rel="stylesheet" href="admin-styles.css">
</head>
<body>
    <div class="admin-grid-container">
        <!-- Header -->
        <header class="admin-header">
            <div class="header-left">
                <div class="logo">🎫 Support Ticketing</div>
            </div>
            <div class="header-right">
                <div class="admin-profile">
                    <span>Bonjour, <strong><?php echo htmlspecialchars($admin_name); ?></strong></span>
                    <button id="logoutButton" class="btn btn-danger">Déconnexion</button>
                </div>
            </div>
        </header>

        <!-- Navigation principale -->
        <nav class="admin-nav">
            <button class="admin-tab active" onclick="switchTab('tickets')">Tickets</button>
            <button class="admin-tab" onclick="switchTab('stats')">Statistiques</button>
            <button class="admin-tab" onclick="switchTab('settings')">Paramètres</button>
        </nav>

        <!-- Contenu principal -->
        <main class="admin-main">
            <!-- Onglet Tickets -->
            <div id="ticketsTab" class="tab-content active">
                <div class="kpi-grid">
                    <div class="kpi-card"><h4>Total</h4><p id="totalTickets">...</p></div>
                    <div class="kpi-card"><h4>Ouverts</h4><p id="openTickets">...</p></div>
                    <div class="kpi-card"><h4>En cours</h4><p id="inProgressTickets">...</p></div>
                    <div class="kpi-card"><h4>Fermés</h4><p id="closedTickets">...</p></div>
                </div>

                <div class="filters-bar">
                    <input type="text" id="adminSearchInput" onkeyup="handleSearch(event)" placeholder="Rechercher par ID ticket, nom, email...">
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
                    <button id="myTicketsBtn" class="btn btn-secondary" onclick="toggleMyTickets()">Mes tickets</button>
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
                            <!-- Les tickets seront insérés ici par JS -->
                        </tbody>
                    </table>
                </div>

                <div id="paginationControls" class="pagination"></div>
            </div>

            <!-- Onglet Statistiques -->
            <div id="statsTab" class="tab-content">
                <!-- Le contenu des statistiques sera chargé ici -->
            </div>

            <!-- Onglet Paramètres -->
            <div id="settingsTab" class="tab-content">
                <!-- Le contenu des paramètres sera chargé ici -->
            </div>
        </main>
    </div>

    <!-- Modals -->
    <div id="viewTicketModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Détails du Ticket</h3>
                <button class="close-modal" onclick="closeViewModal()">&times;</button>
            </div>
            <div id="ticketDetails" class="modal-body"></div>
        </div>
    </div>
    
    <div id="inactivityModal" class="modal"></div>

    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script src="js/admin-stats.js"></script>
    <script src="js/file-viewer-system.js"></script>
    <script src="js/admin-script.js"></script>
</body>
</html>