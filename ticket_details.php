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
    'priority' => $ticket_raw['priority'], // Priority is not encrypted
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
    <title>Ticket #<?php echo $ticket['id']; ?> - Support Descamps</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            background: linear-gradient(135deg, #EDEDED 0%, #D1D1D1 100%);
            min-height: 100vh;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        .page-container {
            max-width: 900px;
            margin: 0 auto;
        }

        /* Header with gradient */
        .page-header {
            background: linear-gradient(135deg, #7C7C7B 0%, #4A4A49 100%);
            color: white;
            padding: 30px 40px;
            border-radius: 16px 16px 0 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .back-button {
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 20px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .back-button:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(-3px);
        }

        .header-title h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }

        .header-title p {
            margin: 5px 0 0 0;
            opacity: 0.9;
            font-size: 14px;
        }

        .ticket-id-badge {
            background: linear-gradient(135deg, #EF8000 0%, #D97000 100%);
            padding: 12px 24px;
            border-radius: 12px;
            font-family: monospace;
            font-size: 18px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(239, 128, 0, 0.3);
        }

        /* Main content card */
        .content-card {
            background: white;
            border-radius: 0 0 16px 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 40px;
        }

        /* Info cards grid */
        .info-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-card {
            background: linear-gradient(135deg, #F9FAFB 0%, #F3F4F6 100%);
            padding: 20px;
            border-radius: 12px;
            border-left: 4px solid #EF8000;
            transition: all 0.3s;
        }

        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .info-card-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6B7280;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .info-card-value {
            font-size: 16px;
            color: #111827;
            font-weight: 600;
        }

        /* Status badges */
        .status-badges {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .badge-icon {
            font-size: 16px;
        }

        /* Description section */
        .description-section {
            background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
            border-left: 5px solid #EF8000;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(239, 128, 0, 0.1);
        }

        .description-section h3 {
            margin: 0 0 15px 0;
            font-size: 18px;
            color: #78350F;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .description-content {
            color: #92400E;
            line-height: 1.6;
            font-size: 15px;
            white-space: pre-wrap;
            word-break: break-word;
        }

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            flex: 1;
            min-width: 200px;
            padding: 14px 28px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #7C7C7B 0%, #4A4A49 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(74, 74, 73, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(74, 74, 73, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #EF8000 0%, #D97000 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(239, 128, 0, 0.3);
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(239, 128, 0, 0.4);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }

            .header-left {
                flex-direction: column;
            }

            .info-cards {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                min-width: 100%;
            }
        }

        /* Animation */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .page-container {
            animation: slideIn 0.5s ease;
        }
    </style>
</head>
<body>
    <div class="page-container">
        <!-- Header -->
        <div class="page-header">
            <div class="header-left">
                <button class="back-button" onclick="location.href='index.php'" title="Retour">
                    ←
                </button>
                <div class="header-title">
                    <h1>Détails du Ticket</h1>
                    <p>Consultez les informations de votre demande</p>
                </div>
            </div>
            <div class="ticket-id-badge">
                #<?php echo $ticket['id']; ?>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content-card">
            <!-- Info Cards -->
            <div class="info-cards">
                <div class="info-card">
                    <div class="info-card-label">📋 Sujet</div>
                    <div class="info-card-value"><?php echo htmlspecialchars($ticket['subject']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-card-label">📅 Date de création</div>
                    <div class="info-card-value"><?php echo htmlspecialchars($ticket['date']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-card-label">📁 Catégorie</div>
                    <div class="info-card-value"><?php echo htmlspecialchars($ticket['category']); ?></div>
                </div>
            </div>

            <!-- Status Badges -->
            <div class="status-badges">
                <span class="badge" style="background-color: <?php echo $priorityColors[$ticket['priority']]; ?>20; color: <?php echo $priorityColors[$ticket['priority']]; ?>;">
                    <span class="badge-icon">⚡</span>
                    Priorité : <?php echo htmlspecialchars($ticket['priority']); ?>
                </span>
                <span class="badge" style="background-color: <?php echo $statusColors[$ticket['status']]; ?>20; color: <?php echo $statusColors[$ticket['status']]; ?>;">
                    <span class="badge-icon">🔔</span>
                    Statut : <?php echo htmlspecialchars($ticket['status']); ?>
                </span>
            </div>

            <!-- Description -->
            <div class="description-section">
                <h3>
                    <span>📝</span>
                    Description de la demande
                </h3>
                <div class="description-content">
                    <?php echo nl2br(htmlspecialchars($ticket['description'])); ?>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <button class="btn btn-primary" onclick="location.href='index.php'">
                    <span>←</span>
                    Retour à la liste
                </button>
                <button class="btn btn-secondary" onclick="location.href='index.php'">
                    <span>+</span>
                    Nouveau ticket
                </button>
            </div>
        </div>
    </div>
</body>
</html>