<?php
/**
 * @file admin.php
 * @brief Panneau d'administration et page de connexion pour les administrateurs.
 */

define('ROOT_PATH', __DIR__);
require_once 'config.php';
session_name('admin_session');
initialize_session();

$isAdminLoggedIn = isset($_SESSION['admin_id']);
$csrf_token = $_SESSION['csrf_token'] ?? '';
$admin_name = $_SESSION['admin_firstname'] ?? 'Admin';

session_write_close(); 

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
        /* ============================================
           STYLES POUR LA PAGE DE CONNEXION ADMIN
           ============================================ */
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #7C7C7B 0%, #4A4A49 100%);
            margin: 0;
            padding: 20px;
        }

        .login-body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
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
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            background: linear-gradient(135deg, #7C7C7B 0%, #4A4A49 100%);
            color: white;
            padding: 40px;
            text-align: center;
            border-bottom: 4px solid #EF8000;
        }

        .login-header h1 {
            font-size: 28px;
            margin-bottom: 5px;
            color: white;
            font-weight: 700;
        }

        .login-header p {
            margin: 0;
            font-size: 16px;
            opacity: 0.9;
        }

        .login-content {
            padding: 40px;
        }

        .login-content .form-group {
            margin-bottom: 20px;
        }

        .login-content .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-dark);
        }

        .login-content .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
            font-family: 'Source Sans Pro', sans-serif;
        }

        .login-content .form-group input:focus {
            outline: none;
            border-color: #EF8000;
            box-shadow: 0 0 0 3px rgba(239, 128, 0, 0.1);
        }

        .login-content .btn-primary {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #7C7C7B 0%, #4A4A49 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .login-content .btn-primary:hover {
            background: linear-gradient(135deg, #4A4A49 0%, #4A4A49 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(74, 74, 73, 0.4);
        }

        .error-message {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: #991b1b;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .login-content > div:last-child {
            text-align: center;
            margin-top: 20px;
        }

        .login-content > div:last-child a {
            color: #EF8000;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }

        .login-content > div:last-child a:hover {
            color: #D67200;
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-container {
                margin: 10px;
            }

            .login-header {
                padding: 30px 20px;
            }

            .login-header h1 {
                font-size: 24px;
            }

            .login-content {
                padding: 30px 20px;
            }
        }
    </style>
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
                <button type="submit" class="btn btn-primary">Se connecter</button>
            </form>
            <div>
                <a href="forgot_password.php?role=admin">Mot de passe oublié ?</a>
            </div>
        </div>
    </div>
</body>
</html>
<?php
    exit();
}

// ============================================
// TABLEAU DE BORD ADMIN (Code existant)
// ============================================
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