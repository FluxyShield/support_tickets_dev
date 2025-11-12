<?php
if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé');
}

function notifications() {
    // Pour l'instant, on renvoie une liste vide pour éviter l'erreur 500.
    // La logique complète sera ajoutée plus tard.
    if (!isset($_SESSION['admin_id'])) {
         jsonResponse(true, 'Notifications récupérées', ['notifications' => []]);
    }
    
    $db = Database::getInstance()->getConnection();
    $admin_id = (int)$_SESSION['admin_id'];

    // Exemple de logique : récupérer les messages non lus dans les tickets assignés à l'admin
    $query = "SELECT m.ticket_id, t.subject_encrypted, m.message_encrypted as preview, m.created_at 
              FROM messages m
              JOIN tickets t ON m.ticket_id = t.id
              WHERE m.is_read = 0 AND m.author_role = 'user'
              AND (t.assigned_to = ? OR t.assigned_to IS NULL)
              ORDER BY m.created_at DESC LIMIT 10";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Cette partie doit être complétée pour formater les données
    jsonResponse(true, 'Notifications récupérées', ['notifications' => []]);
}

function notifications_read_all() {
    jsonResponse(true, 'Toutes les notifications ont été marquées comme lues.');
}