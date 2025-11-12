<?php
/**
 * ===================================================================
 * Script de Rapport Hebdomadaire Automatique
 * ===================================================================
 * Ce script est destiné à être exécuté via une tâche CRON.
 * Il collecte les statistiques de la semaine écoulée (7 derniers jours)
 * et envoie un rapport par email à tous les administrateurs.
 *
 * USAGE (via une tâche CRON) :
 * 1 0 * * 1 php /chemin/vers/votre/projet/support_tickets/weekly_report.php
 * (Exemple pour tous les lundis à 00h01)
 * ===================================================================
 */

// Sécurité : S'assurer que le script est exécuté depuis la ligne de commande (CLI)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Accès interdit. Ce script doit être exécuté en ligne de commande.");
}

// ⭐ CORRECTION SÉCURITÉ : Définir ROOT_PATH avant d'inclure config.php
define('ROOT_PATH', dirname(__DIR__)); // On remonte d'un niveau car on est dans /api
require_once ROOT_PATH . '/config.php';

echo "===================================================\n";
echo "===== DÉBUT DE LA GÉNÉRATION DU RAPPORT HEBDO =====\n";
echo "===================================================\n";
echo "Date : " . date('Y-m-d H:i:s') . "\n";

$db = Database::getInstance()->getConnection();

// --- 1. Collecte des statistiques sur les 7 derniers jours ---

$seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));

// Nouveaux tickets
$new_tickets_stmt = $db->prepare("SELECT COUNT(id) as count FROM tickets WHERE created_at >= ?");
$new_tickets_stmt->bind_param("s", $seven_days_ago);
$new_tickets_stmt->execute();
$new_tickets_count = $new_tickets_stmt->get_result()->fetch_assoc()['count'];

// Tickets fermés
$closed_tickets_stmt = $db->prepare("SELECT COUNT(id) as count FROM tickets WHERE status = 'Fermé' AND closed_at >= ?");
$closed_tickets_stmt->bind_param("s", $seven_days_ago);
$closed_tickets_stmt->execute();
$closed_tickets_count = $closed_tickets_stmt->get_result()->fetch_assoc()['count'];

// Temps de résolution moyen (pour les tickets fermés cette semaine)
$avg_resolution_stmt = $db->prepare("SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, closed_at)) as avg_sec FROM tickets WHERE status = 'Fermé' AND closed_at >= ?");
$avg_resolution_stmt->bind_param("s", $seven_days_ago);
$avg_resolution_stmt->execute();
$avg_resolution_seconds = $avg_resolution_stmt->get_result()->fetch_assoc()['avg_sec'];

// Tickets actuellement ouverts
$open_tickets_count = $db->query("SELECT COUNT(id) as count FROM tickets WHERE status != 'Fermé'")->fetch_assoc()['count'];

// --- 2. Formatage des données pour l'email ---

function format_duration($seconds) {
    if ($seconds === null || $seconds <= 0) return "N/A";
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return sprintf('%dh %02dm', $h, $m);
}

$avg_resolution_formatted = format_duration($avg_resolution_seconds);
$start_date = date('d/m/Y', strtotime('-7 days'));
$end_date = date('d/m/Y');

// --- 3. Construction de l'email HTML ---

$subject = "📊 Rapport Hebdomadaire du Support - Semaine du " . $start_date;

$emailBody = "
    <h2 style='color: #4A4A49;'>Rapport Hebdomadaire du Support</h2>
    <p>Voici le résumé de l'activité sur la plateforme de support pour la période du <strong>{$start_date}</strong> au <strong>{$end_date}</strong>.</p>
    
    <table style='width: 100%; border-collapse: collapse; margin: 25px 0;'>
        <tr style='border-bottom: 1px solid #eee;'>
            <td style='padding: 12px; font-size: 18px; color: #EF8000;'>🎟️</td>
            <td style='padding: 12px; font-weight: bold;'>Nouveaux tickets créés</td>
            <td style='padding: 12px; text-align: right; font-weight: bold; font-size: 18px;'>{$new_tickets_count}</td>
        </tr>
        <tr style='border-bottom: 1px solid #eee;'>
            <td style='padding: 12px; font-size: 18px; color: #10b981;'>✅</td>
            <td style='padding: 12px; font-weight: bold;'>Tickets résolus</td>
            <td style='padding: 12px; text-align: right; font-weight: bold; font-size: 18px;'>{$closed_tickets_count}</td>
        </tr>
        <tr style='border-bottom: 1px solid #eee;'>
            <td style='padding: 12px; font-size: 18px; color: #3b82f6;'>⏱️</td>
            <td style='padding: 12px; font-weight: bold;'>Temps de résolution moyen</td>
            <td style='padding: 12px; text-align: right; font-weight: bold; font-size: 18px;'>{$avg_resolution_formatted}</td>
        </tr>
        <tr style='border-bottom: 1px solid #eee;'>
            <td style='padding: 12px; font-size: 18px; color: #f59e0b;'>📂</td>
            <td style='padding: 12px; font-weight: bold;'>Tickets actuellement en attente</td>
            <td style='padding: 12px; text-align: right; font-weight: bold; font-size: 18px;'>{$open_tickets_count}</td>
        </tr>
    </table>

    <p style='text-align: center; margin-top: 30px;'>
        <a href='" . APP_URL_BASE . "/admin.php' style='background: #EF8000; color: white; padding: 12px 25px; text-decoration: none; border-radius: 8px; font-weight: bold;'>Accéder au tableau de bord</a>
    </p>
";

// --- 4. Envoi de l'email à tous les administrateurs ---

echo "\nEnvoi du rapport par email aux administrateurs...\n";

$adminStmt = $db->prepare("SELECT email_encrypted FROM users WHERE role = 'admin'");
$adminStmt->execute();
$adminsResult = $adminStmt->get_result();

if ($adminsResult->num_rows === 0) {
    echo "Aucun administrateur trouvé. Fin du script.\n";
    exit;
}

$sentCount = 0;
while ($admin = $adminsResult->fetch_assoc()) {
    $adminEmail = decrypt($admin['email_encrypted']);
    if ($adminEmail) {
        if (sendEmail($adminEmail, $subject, $emailBody)) {
            echo "[OK] Email de rapport envoyé à " . $adminEmail . "\n";
            $sentCount++;
        } else {
            echo "[ERREUR] Impossible d'envoyer l'email à " . $adminEmail . "\n";
        }
    }
}

echo "\nRapport envoyé à " . $sentCount . " administrateur(s).\n";
echo "\n===================================================\n";
echo "===== FIN DE LA GÉNÉRATION DU RAPPORT HEBDO =====\n";
echo "===================================================\n";

?>
```

<!--### Étape 2 : Configurer la tâche CRON

La tâche CRON est une fonctionnalité de votre serveur (généralement sur les hébergements Linux) qui permet de planifier l'exécution de commandes ou de scripts à des moments précis.
Si vous êtes sur un serveur Windows, l'équivalent est le "Planificateur de tâches".
Si vous êtes en local avec XAMPP, la configuration d'une tâche CRON n'est pas simple, mais voici comment vous le feriez sur un vrai serveur de production :
1.  Connectez-vous à votre serveur en SSH ou via le panneau de contrôle de votre hébergeur (cPanel, Plesk, etc.).
2.  Cherchez une section nommée "Tâches CRON" ou "CRON Jobs".
3.  Créez une nouvelle tâche CRON avec les paramètres suivants :
   *   **Minute :** `1`
   *   **Heure :** `0`
   *   **Jour du mois :** `*` (tous)
   *   **Mois :** `*` (tous)
   *   **Jour de la semaine :** `1` (pour Lundi)
   *   **Commande à exécuter :**
       ```bash
       /usr/bin/php /chemin/absolu/vers/votre/projet/support_tickets/weekly_report.php
       ```
       *   `/usr/bin/php` est le chemin courant vers l'exécutable PHP. Il peut varier.
       *   `/chemin/absolu/vers/votre/projet/` doit être remplacé par le chemin réel sur votre serveur (par exemple `/home/votrenom/public_html/support_tickets/`).

Cette configuration exécutera le script `weekly_report.php` **tous les lundis à 00h01**, qui calculera les statistiques des 7 jours précédents et enverra le rapport à tous vos administrateurs.

Le script que je vous ai fourni est un excellent point de départ. Vous pourriez l'enrichir davantage en ajoutant des statistiques sur le "top" des catégories de tickets, ou l'administrateur ayant résolu le plus de tickets cette semaine !


[PROMPT_SUGGESTION]Comment puis-je ajouter le nom de l'administrateur qui a résolu le plus de tickets cette semaine dans le rapport hebdomadaire ?[/PROMPT_SUGGESTION]
[PROMPT_SUGGESTION]Est-il possible de générer ce rapport manuellement depuis l'interface d'administration ?[/PROMPT_SUGGESTION]
