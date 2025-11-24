<?php
// ========================================================================
// == Correction Sécurité: Logique de chargement et de validation côté serveur
// ========================================================================
define('ROOT_PATH', __DIR__);
require_once 'config.php';
initialize_session();

// 1. Vérifier si un utilisateur est connecté
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit();
}

// 2. Valider l'ID du ticket
$ticket_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$ticket_id) {
    header('Location: index.php');
    exit();
}

// 3. Récupérer les données du ticket depuis la BDD
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT * FROM tickets WHERE id = ?");
$stmt->bind_param("i", $ticket_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Ticket non trouvé
    header('Location: 404.html');
    exit();
}

$ticket_raw = $result->fetch_assoc();

// 4. ⭐ Point de contrôle de sécurité IDOR
// Vérifier si l'utilisateur a le droit de voir ce ticket.
// L'accès est refusé si l'utilisateur n'est PAS un admin ET si son user_id ne correspond PAS à celui du ticket.
if (!isset($_SESSION['admin_id']) && $ticket_raw['user_id'] != $_SESSION['user_id']) {
    // Redirection silencieuse vers la page d'accueil. L'utilisateur ne doit pas savoir que ce ticket existe.
    header('Location: index.php');
    exit();
}

// 5. Décrypter les données pour l'affichage (uniquement si l'accès est autorisé)
$ticket = [
    'id' => (int)$ticket_raw['id'],
    'subject' => decrypt($ticket_raw['subject_encrypted']),
    'description' => decrypt($ticket_raw['description_encrypted']),
    'category' => decrypt($ticket_raw['category_encrypted']),
    'priority' => decrypt($ticket_raw['priority_encrypted']),
    'status' => $ticket_raw['status'],
    'date' => date('d/m/Y', strtotime($ticket_raw['created_at'])),
];

// Logique pour les couleurs des badges
$priorityColors = [
    'Haute' => '#ef4444',
    'Moyenne' => '#f59e0b',
    'Basse' => '#6b7280'
];
$statusColors = [
    'Ouvert' => '#3b82f6',
    'En cours' => '#f59e0b',
    'Fermé' => '#10b981'
];
// ========================================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails du Ticket #<?php echo $ticket['id']; ?> - Support Descamps</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            background: linear-gradient(135deg, #7C7C7B 0%, #4A4A49 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .details-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 800px;
            width: 100%;
            overflow: hidden;
            animation: slideUp 0.5s ease;
        }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .details-header {
            background: var(--gray-800);
            color: white;
            padding: 30px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .details-header h1 {
            font-size: 24px;
            margin: 0;
            font-weight: 600;
        }
        .ticket-id-header {
            font-family: monospace;
            font-size: 16px;
            background: rgba(255,255,255,0.1);
            padding: 5px 10px;
            border-radius: 6px;
        }
        .details-body { padding: 40px; }
        .info-grid {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 15px 20px;
            align-items: start;
        }
        .info-label { font-weight: 600; color: var(--gray-700); }
        .info-value { color: var(--gray-900); white-space: pre-wrap; word-break: break-word; }
        .badge { display: inline-block; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; }
        .description-card {
            background: var(--gray-50);
            border-left: 4px solid var(--orange);
            padding: 25px;
            border-radius: 12px;
            margin-top: 30px;
        }
        .description-card h3 {
            margin-top: 0; margin-bottom: 15px; font-size: 18px; color: var(--gray-800);
        }
        .action-buttons { margin-top: 40px; display: flex; gap: 15px; }
        .btn { flex: 1; justify-content: center; }
        .page-title { margin-bottom: 20px; font-weight: 700; color: var(--gray-800); }
    </style>
</head>
<body>
    <div class="details-container">
        <div class="details-header">
            <h1>Détails du Ticket</h1>
            <span class="ticket-id-header">#<?php echo $ticket['id']; ?></span>
        </div>

        <div class="details-body">
            
            <div class="info-grid">
                <div class="info-label">Sujet :</div>
                <div class="info-value"><strong><?php echo htmlspecialchars($ticket['subject']); ?></strong></div>

                <div class="info-label">Date de création :</div>
                <div class="info-value"><?php echo htmlspecialchars($ticket['date']); ?></div>
                
                <div class="info-label">Catégorie :</div>
                <div class="info-value"><?php echo htmlspecialchars($ticket['category']); ?></div>

                <div class="info-label">Priorité :</div>
                <div class="info-value">
                    <span class="badge" style="background-color: <?php echo $priorityColors[$ticket['priority']]; ?>20; color: <?php echo $priorityColors[$ticket['priority']]; ?>;">
                        <?php echo htmlspecialchars($ticket['priority']); ?>
                    </span>
                </div>

                <div class="info-label">Statut :</div>
                <div class="info-value">
                    <span class="badge" style="background-color: <?php echo $statusColors[$ticket['status']]; ?>20; color: <?php echo $statusColors[$ticket['status']]; ?>;">
                        <?php echo htmlspecialchars($ticket['status']); ?>
                    </span>
                </div>
            </div>

            <div class="description-card">
                <h3>Description de la demande</h3>
                <div class="info-value">
                    <?php echo nl2br(htmlspecialchars($ticket['description'])); ?>
                </div>
            </div>

            <div class="action-buttons">
                <button class="btn btn-primary" onclick="location.href='index.php'">
                    Retour à la liste des tickets
                </button>
                <button class="btn btn-secondary" onclick="location.href='index.php#new-ticket-form'">
                    Créer un nouveau ticket
                </button>
            </div>
        </div>
    </div>
    <!-- Le script JS a été supprimé car la logique est maintenant gérée côté serveur -->
</body>
</html>