<?php
/**
 * ===================================================================
 * Script de Nettoyage Automatique des Pièces Jointes
 * ===================================================================
 * Ce script est destiné à être exécuté via une tâche CRON (ou une tâche planifiée Windows).
 * Il supprime les pièces jointes des tickets qui sont fermés depuis plus de
 * ATTACHMENT_LIFETIME_DAYS jours (défini dans config.php).
 *
 * USAGE (en ligne de commande) :
 * php c:\xampp\htdocs\support_tickets\cleanup.php
 * ===================================================================
 */

// Sécurité : S'assurer que le script est exécuté depuis la ligne de commande (CLI)
// et non via un navigateur web.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Accès interdit. Ce script doit être exécuté en ligne de commande.");
}

// ⭐ CORRECTION SÉCURITÉ : Définir ROOT_PATH avant d'inclure config.php
define('ROOT_PATH', dirname(__DIR__)); // On remonte d'un niveau car on est dans /api
require_once ROOT_PATH . '/config.php';

echo "===================================================\n";
echo "===== DÉBUT DU NETTOYAGE DES PIÈCES JOINTES =====\n";
echo "===================================================\n";
echo "Date : " . date('Y-m-d H:i:s') . "\n";
echo "Rétention des fichiers : " . ATTACHMENT_LIFETIME_DAYS . " jours\n\n";

$db = Database::getInstance()->getConnection();
$totalFilesDeleted = 0;
$totalSizeDeleted = 0;
$errorsOccurred = false;

// 1. Sélectionner tous les fichiers des tickets fermés depuis plus de X jours
$sql = "
    SELECT 
        tf.id as file_id, 
        tf.filename_encrypted,
        tf.file_size,
        t.id as ticket_id
    FROM ticket_files tf
    JOIN tickets t ON tf.ticket_id = t.id
    WHERE 
        t.status = 'Fermé' 
        AND t.closed_at IS NOT NULL
        AND t.closed_at < NOW() - INTERVAL ? DAY
";

$stmt = $db->prepare($sql);
$lifetime = ATTACHMENT_LIFETIME_DAYS;
$stmt->bind_param("i", $lifetime);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "Aucun fichier à supprimer. Terminé.\n";
    exit;
}

echo "Trouvé " . $result->num_rows . " fichier(s) à supprimer...\n\n";

// 2. Parcourir et supprimer chaque fichier
while ($file = $result->fetch_assoc()) {
    $decryptedFilename = decrypt($file['filename_encrypted']);
    $filepath = dirname(__DIR__, 3) . '/secure_uploads/' . $decryptedFilename; // Utiliser le chemin sécurisé

    // Supprimer le fichier physique
    if (file_exists($filepath)) {
        if (unlink($filepath)) {
            // Supprimer l'entrée dans la base de données
            $deleteStmt = $db->prepare("DELETE FROM ticket_files WHERE id = ?");
            $deleteStmt->bind_param("i", $file['file_id']);
            $deleteStmt->execute();

            echo "[OK] Fichier supprimé : " . $decryptedFilename . " (Ticket #" . $file['ticket_id'] . ")\n";
            $totalFilesDeleted++;
            $totalSizeDeleted += (int)$file['file_size'];
        } else {
            echo "[ERREUR] Impossible de supprimer le fichier physique : " . $filepath . "\n";
            $errorsOccurred = true;
        }
    } else {
        echo "[AVERTISSEMENT] Fichier non trouvé sur le disque, suppression de la référence DB : " . $decryptedFilename . "\n";
        $deleteStmt = $db->prepare("DELETE FROM ticket_files WHERE id = ?");
        $deleteStmt->bind_param("i", $file['file_id']);
        $deleteStmt->execute();
    }
}

$spaceFreedMb = round($totalSizeDeleted / (1024 * 1024), 2);


// ⭐ NOUVEAU : Envoi du rapport par email aux administrateurs
echo "\nEnvoi du rapport par email aux administrateurs...\n";

$adminStmt = $db->prepare("SELECT email_encrypted FROM users WHERE role = 'admin'");
$adminStmt->execute();
$adminsResult = $adminStmt->get_result();

$subject = $errorsOccurred 
    ? "⚠️ Rapport de Nettoyage avec Erreurs - " . APP_NAME
    : "✅ Rapport de Nettoyage Automatique - " . APP_NAME;

$statusMessage = $errorsOccurred
    ? "<p style='color:#c05621;font-weight:bold;'>Le script s'est terminé mais a rencontré des erreurs. Veuillez consulter les logs du serveur pour plus de détails.</p>"
    : "<p style='color:#065f46;font-weight:bold;'>Le script de nettoyage s'est déroulé avec succès, sans aucune erreur.</p>";

$emailBody = "
    <h2 style='color: #4A4A49; border-bottom: 2px solid #eee; padding-bottom: 15px;'>Rapport de Nettoyage</h2>
    <p>Ceci est un rapport automatique du script de nettoyage des pièces jointes.</p>
    
    <div style='background: #f9f9f9; padding: 15px; border-radius: 8px; margin: 20px 0;'>
        <p style='margin: 5px 0;'><strong>Date d'exécution :</strong> " . date('d/m/Y à H:i:s') . "</p>
        <p style='margin: 5px 0;'><strong>Période de rétention :</strong> " . ATTACHMENT_LIFETIME_DAYS . " jours</p>
        <hr style='border: 0; border-top: 1px solid #eee; margin: 15px 0;'>
        <p style='margin: 5px 0;'><strong>Fichiers supprimés :</strong> " . $totalFilesDeleted . "</p>
        <p style='margin: 5px 0;'><strong>Espace disque libéré :</strong> " . $spaceFreedMb . " Mo</p>
    </div>

    " . $statusMessage . "
    <p style='font-size: 12px; color: #888; margin-top: 20px;'>Ceci est un message automatique envoyé à tous les administrateurs.</p>";

$sentCount = 0;
while ($admin = $adminsResult->fetch_assoc()) {
    $adminEmail = decrypt($admin['email_encrypted']);
    if (sendEmail($adminEmail, $subject, $emailBody)) {
        echo "[OK] Email de rapport envoyé à " . $adminEmail . "\n";
        $sentCount++;
    } else {
        echo "[ERREUR] Impossible d'envoyer l'email à " . $adminEmail . "\n";
    }
}

echo "Rapport envoyé à " . $sentCount . " administrateur(s).\n";
echo "\n===================================================\n";
echo "Nettoyage terminé.\n";
echo "Fichiers supprimés : " . $totalFilesDeleted . "\n";
echo "Espace libéré : " . round($totalSizeDeleted / (1024 * 1024), 2) . " Mo\n";
echo "===================================================\n";