<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php
        // ⭐ CORRECTION SÉCURITÉ : Définir ROOT_PATH avant d'inclure config.php
        define('ROOT_PATH', __DIR__);
    ?>
    <?php
        require_once 'config.php';
        session_name('admin_session'); // ⭐ SOLUTION : Nom de session unique pour l'admin
        initialize_session();
    ?>
    <!-- Jeton CSRF généré de manière fiable -->
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
    
    <title>Administration - Support Descamps</title>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🎫 Support Descamps - Admin</div>
            <div class="nav-buttons">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span id="adminName" style="color:var(--gray-700);font-weight:600;"></span>
                    <button class="btn btn-danger" onclick="logout()">Déconnexion</button>
                </div>
            </div>
        </div>

        <div class="admin-tabs">
            <button class="admin-tab active" onclick="switchTab('tickets')">📋 Tickets</button>
            <button class="admin-tab" onclick="switchTab('stats')">📊 Statistiques</button>
            <button class="admin-tab" onclick="switchTab('settings')">⚙️ Paramètres</button>
        </div>

        <div class="content">
            <div id="ticketsTab" class="tab-content active">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:30px;flex-wrap:wrap;gap:15px;">
                    <h2 style="color:var(--gray-900);">Gestion des Tickets</h2>
                    
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    
                        <div style="display: flex; border: 2px solid var(--gray-200); border-radius: 8px; overflow: hidden; background: white;">
                            <input type="text" id="adminSearchInput" onkeyup="handleSearch(event)" placeholder="Rechercher par ID, nom, email..." style="padding: 10px; border: none; outline: none; font-size: 14px; min-width: 250px;">
                            <button class="btn btn-secondary" onclick="triggerSearch()" style="border: none; border-radius: 0; border-left: 2px solid var(--gray-200); margin: 0; transform: none; box-shadow: none; padding: 0 12px;">&#128269;</button>
                        </div>
                        
                        <button id="myTicketsBtn" class="btn btn-secondary" onclick="toggleMyTickets()" style="padding: 10px; height: 44px;">👤 Mes tickets</button>
                        
                        <select id="filterStatus" onchange="filterTickets()" style="padding:10px;border-radius:8px;border:2px solid var(--gray-200);">
                            <option value="all">Tous les statuts</option>
                            <option value="Ouvert">Ouvert</option>
                            <option value="En cours">En cours</option>
                            <option value="Fermé">Fermé</option>
                        </select>
                        
                        <select id="filterPriority" onchange="filterTickets()" style="padding:10px;border-radius:8px;border:2px solid var(--gray-200);">
                            <option value="all">Toutes priorités</option>
                            <option value="Haute">Haute</option>
                            <option value="Moyenne">Moyenne</option>
                            <option value="Basse">Basse</option>
                        </select>
                        
                        </div>
                </div> <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value" id="totalTickets">0</div>
                        <div class="stat-label">Total Tickets</div>
                    </div>
                    <div class="stat-card" style="background:linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                        <div class="stat-value" id="openTickets">0</div>
                        <div class="stat-label">Ouverts</div>
                    </div>
                    <div class="stat-card" style="background:linear-gradient(135deg, #EF8000 0%, #D67200 100%);">
                        <div class="stat-value" id="inProgressTickets">0</div>
                        <div class="stat-label">En Cours</div>
                    </div>
                    <div class="stat-card" style="background:linear-gradient(135deg, #10b981 0%, #059669 100%);">
                        <div class="stat-value" id="closedTickets">0</div>
                        <div class="stat-label">Fermés</div>
                    </div>
                </div>
                <div style="overflow-x:auto;">
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
                        <tbody id="ticketsTable"></tbody>
                    </table>
                </div>
                
                <div id="paginationControls" class="pagination-controls"></div>
                
            </div> <div id="statsTab" class="tab-content">
                </div>

            <div id="settingsTab" class="tab-content">
                </div>
            
        </div>
    
    </div> <div id="viewTicketModal" class="modal">
        <div class="modal-content" style="max-width:900px;">
            <div class="modal-header">
                <h3>Détails du Ticket</h3>
                <button class="close-modal" onclick="closeViewModal()">&times;</button>
            </div>
            <div id="ticketDetails"></div>
        </div>
    </div>

    <!-- ⭐ CORRECTION : Passer le chemin de base de l'application au JavaScript -->
    <script>
        const appBasePath = '<?php echo rtrim(parse_url(APP_URL_BASE, PHP_URL_PATH), '/'); ?>';
    </script>

    <!-- ApexCharts pour les graphiques -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <script src="js/drag-drop-upload.js"></script>
    <script src="js/file-viewer-system.js"></script>
    <script src="js/admin-script.js"></script>
    <script src="js/admin-stats.js"></script> <!-- ⭐ NOUVEAU -->
    <script src="js/notification-system.js"></script>

    <?php session_write_close(); // Libère la session pour les autres requêtes ?>

    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin-styles.css">
</body>
</html>