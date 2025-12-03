<?php
/**
 * @file admin.php
 * @brief Panneau d'administration et page de connexion pour les administrateurs.
 */

define('ROOT_PATH', __DIR__);
require_once 'config.php';

// Configuration de la session admin
// Configuration de la session admin
// session_name('admin_session'); // REMOVED: Unified in config.php
initialize_session();

$isAdminLoggedIn = isset($_SESSION['admin_id']);
$csrf_token = $_SESSION['csrf_token'] ?? '';
$admin_name = $_SESSION['admin_firstname'] ?? 'Admin';

// On ferme l'écriture de session pour ne pas bloquer les requêtes parallèles
session_write_close(); 

// ==============================================================================================
// CAS 1 : ADMINISTRATEUR NON CONNECTÉ -> AFFICHER LE FORMULAIRE DE CONNEXION
// ==============================================================================================
if (!$isAdminLoggedIn) {
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <link rel="stylesheet" href="style.css">
    <title>Connexion Admin - Support Ticketing</title>
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #7C7C7B 0%, #4A4A49 100%);
            margin: 0;
            padding: 20px;
        }
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            max-width: 450px;
            width: 100%;
            animation: fadeInUp 0.5s ease;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .login-header {
            background: linear-gradient(135deg, #7C7C7B 0%, #4A4A49 100%);
            color: white;
            padding: 40px;
            text-align: center;
            border-bottom: 4px solid #EF8000;
        }
        .login-header h1 { font-size: 28px; margin-bottom: 5px; color: white; font-weight: 700; }
        .login-header p { margin: 0; font-size: 16px; opacity: 0.9; }
        .login-content { padding: 40px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--gray-dark); }
        .form-group input { width: 100%; padding: 12px; border: 2px solid #D4D4D4; border-radius: 8px; font-size: 14px; transition: border-color 0.3s; }
        .form-group input:focus { outline: none; border-color: #EF8000; box-shadow: 0 0 0 3px rgba(239, 128, 0, 0.1); }
        .btn-primary { width: 100%; padding: 12px; background: linear-gradient(135deg, #7C7C7B 0%, #4A4A49 100%); color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-primary:hover { background: linear-gradient(135deg, #4A4A49 0%, #4A4A49 100%); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(74, 74, 73, 0.4); }
        .error-message { background: #fee2e2; border-left: 4px solid #ef4444; padding: 15px; border-radius: 8px; margin-bottom: 20px; color: #991b1b; display: none; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { opacity: 0; transform: translateX(-20px); } to { opacity: 1; transform: translateX(0); } }
        .forgot-link { text-align: center; margin-top: 20px; }
        .forgot-link a { color: #EF8000; text-decoration: none; font-weight: 600; }
        .forgot-link a:hover { color: #D67200; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>🎫 Support Ticketing</h1>
            <p>Connexion Administrateur</p>
        </div>
        <div class="login-content">
            <div id="errorMsg" class="error-message"></div>
            
            <form id="loginForm" onsubmit="loginAdmin(event)">
                <div class="form-group">
                    <label>Adresse email</label>
                    <input type="email" id="email" required autocomplete="email">
                </div>
                <div class="form-group">
                    <label>Mot de passe</label>
                    <input type="password" id="password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary" id="loginBtn">Se connecter</button>
            </form>
            
            <div class="forgot-link">
                <a href="forgot_password.php?role=admin">Mot de passe oublié ?</a>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        async function loginAdmin(e) {
            e.preventDefault(); // Empêche le rechargement de la page
            
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const errorDiv = document.getElementById('errorMsg');
            const btn = document.getElementById('loginBtn');

            // Reset UI
            errorDiv.style.display = 'none';
            btn.disabled = true;
            btn.textContent = 'Connexion en cours...';

            try {
                const res = await fetch('api.php?action=admin_login', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ email, password })
                });

                // Vérification robuste du type de contenu
                const contentType = res.headers.get("content-type");
                if (!contentType || !contentType.includes("application/json")) {
                    const text = await res.text();
                    console.error("Réponse non-JSON reçue:", text);
                    throw new Error("Erreur serveur (500 ou format invalide). Consultez la console.");
                }

                const data = await res.json();

                if (data.success) {
                    // Rechargement pour afficher le tableau de bord
                    window.location.reload();
                } else {
                    showError(data.message || 'Erreur inconnue.');
                }
            } catch (error) {
                console.error('Erreur JS:', error);
                showError('Erreur de connexion au serveur. Vérifiez les logs PHP (config.php).');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Se connecter';
            }
        }

        function showError(msg) {
            const errorDiv = document.getElementById('errorMsg');
            errorDiv.textContent = '❌ ' + msg;
            errorDiv.style.display = 'block';
        }
    </script>
</body>
</html>
<?php
    // IMPORTANT : Arrêter le script ici pour ne pas afficher le tableau de bord en dessous
    exit();
}

// ==============================================================================================
// CAS 2 : ADMINISTRATEUR CONNECTÉ -> AFFICHER LE TABLEAU DE BORD
// ==============================================================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <title>Tableau de bord - Support</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin-styles.css">
</head>
<body>
    <div class="admin-grid-container">
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

        <nav class="admin-nav">
            <button class="admin-tab active" onclick="switchTab('tickets')">Tickets</button>
            <button class="admin-tab" onclick="switchTab('stats')">Statistiques</button>
            <button class="admin-tab" onclick="switchTab('settings')">Paramètres</button>
        </nav>

        <main class="admin-main">
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
                    <table class="tickets-table">
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
                            </tbody>
                    </table>
                </div>

                <div id="paginationControls" class="pagination"></div>
            </div>

            <div id="statsTab" class="tab-content">
                </div>

            <div id="settingsTab" class="tab-content">
                <div class="settings-container">
                    <!-- Section Gestion des Utilisateurs -->
                    <div class="settings-section">
                        <h3>👥 Gestion des Utilisateurs</h3>
                        <div class="filters-bar">
                            <input type="text" id="userSearchInput" onkeyup="handleUserSearch(event)" placeholder="Rechercher par ID...">
                        </div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nom</th>
                                        <th>Email</th>
                                        <th>Rôle</th>
                                        <th>Inscrit le</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="usersTable">
                                </tbody>
                            </table>
                        </div>
                        <div id="usersPagination" class="pagination"></div>
                    </div>

                    <!-- Autres paramètres (Placeholders) -->
                    <div class="settings-section">
                        <h3>⚙️ Configuration Générale</h3>
                        <div class="settings-grid">
                            <div class="settings-card">
                                <h4>Notifications</h4>
                                <p style="color: var(--text-muted); font-size: 14px;">Gérer les préférences de notification par email.</p>
                                <button class="btn btn-secondary btn-small" style="margin-top: 10px;">Configurer</button>
                            </div>
                            <div class="settings-card">
                                <h4>Sécurité</h4>
                                <p style="color: var(--text-muted); font-size: 14px;">Modifier le mot de passe administrateur et les accès.</p>
                                <button class="btn btn-secondary btn-small" style="margin-top: 10px;">Gérer</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="viewTicketModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Détails du Ticket</h3>
                <button class="close-modal" onclick="closeViewModal()">&times;</button>
            </div>
            <div id="ticketDetails" class="modal-body"></div>
        </div>
    </div>
    
    <div id="deleteAllModal" class="modal">
        <div class="modal-content" style="max-width: 400px; text-align: center;">
            <div class="modal-header">
                <h3>Supprimer tous les tickets ?</h3>
                <button class="close-modal" onclick="closeDeleteAllModal()">&times;</button>
            </div>
            <p style="color: var(--danger); font-weight: bold; margin: 20px 0;">⚠️ Attention : Cette action est irréversible !</p>
            <p>Voulez-vous vraiment supprimer <strong>tous</strong> les tickets de la base de données ?</p>
            <div style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
                <button class="btn btn-secondary" onclick="closeDeleteAllModal()">Annuler</button>
                <button class="btn btn-danger" onclick="deleteAllTickets()">Oui, tout supprimer</button>
            </div>
        </div>
    </div>
    
    <div id="inactivityModal" class="modal">
        <div class="modal-content" style="max-width: 450px; text-align: center;">
            <div class="modal-header">
                <h3>Êtes-vous toujours là ?</h3>
            </div>
            <p style="margin: 20px 0; font-size: 16px;">Vous allez être déconnecté pour inactivité dans <strong id="inactivityCountdown">120</strong> secondes.</p>
            <button class="btn btn-primary" onclick="inactivityManager.stay()" style="width: 100%;">Je suis toujours là</button>
        </div>
    </div>
    
    <div id="overlay" class="overlay"></div>

    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script src="js/drag-drop-upload.js"></script>
    <script src="js/admin-stats.js"></script>
    <script src="js/file-viewer-system.js"></script>
    <script src="js/admin-users.js"></script>
    <script src="js/admin-script.js"></script>
</body>
</html>