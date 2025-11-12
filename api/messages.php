<?php
/**
 * ===================================================================
 * API - Logique des Messages (api/messages.php)
 * ===================================================================
 * Contient toutes les fonctions liées à la gestion des messages dans les tickets.
 * ===================================================================
 */

if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé');
}

/**
 * Crée un nouveau message dans un ticket.
 * Gère les permissions pour les utilisateurs et les administrateurs.
 */
function message_create() {
    // 1. Vérifier si un utilisateur OU un admin est connecté
    if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
        requireAuth(); // Déclenche une erreur d'authentification standard
    }

    $input = getInput();
    $ticket_id = (int)($input['ticket_id'] ?? 0);
    $message = sanitizeInput($input['message'] ?? '');

    if (empty($ticket_id) || empty($message)) {
        jsonResponse(false, 'ID de ticket et message requis.');
    }

    $db = Database::getInstance()->getConnection();

    // 2. Déterminer le rôle et l'identité de l'auteur
    $author_role = '';
    $author_name = '';
    $author_id = 0;

    if (isset($_SESSION['admin_id'])) {
        $author_role = 'admin';
        $author_name = $_SESSION['admin_firstname'] . ' ' . $_SESSION['admin_lastname'];
        $author_id = (int)$_SESSION['admin_id'];
    } else { // C'est un utilisateur
        $author_role = 'user';
        $author_name = $_SESSION['firstname'] . ' ' . $_SESSION['lastname'];
        $author_id = (int)$_SESSION['user_id'];

        // 3. SÉCURITÉ : L'utilisateur ne peut poster que sur ses propres tickets
        $stmt_check = $db->prepare("SELECT user_id FROM tickets WHERE id = ?");
        $stmt_check->bind_param("i", $ticket_id);
        $stmt_check->execute();
        $ticket_owner = $stmt_check->get_result()->fetch_assoc();

        if (!$ticket_owner || $ticket_owner['user_id'] != $author_id) {
            jsonResponse(false, 'Accès non autorisé à ce ticket.');
        }
    }

    // 4. Préparer et insérer le message
    $author_name_enc = encrypt($author_name);
    $message_enc = encrypt($message);

    $stmt = $db->prepare("INSERT INTO messages (ticket_id, user_id, author_name_encrypted, author_role, message_encrypted) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $ticket_id, $author_id, $author_name_enc, $author_role, $message_enc);

    if ($stmt->execute()) {
        // 5. Mettre à jour le statut du ticket si un utilisateur répond
        // Si un utilisateur répond à un ticket "Fermé", on le ré-ouvre automatiquement
        if ($author_role === 'user') {
            $status_stmt = $db->prepare("SELECT status FROM tickets WHERE id = ?");
            $status_stmt->bind_param("i", $ticket_id);
            $status_stmt->execute();
            $ticket_data = $status_stmt->get_result()->fetch_assoc();
            
            if ($ticket_data && $ticket_data['status'] === 'Fermé') {
                // ⭐ CORRECTION : Rouvrir directement le ticket sans appeler ticket_reopen()
                $reopen_stmt = $db->prepare("UPDATE tickets SET status = 'Ouvert', closed_at = NULL WHERE id = ?");
                $reopen_stmt->bind_param("i", $ticket_id);
                $reopen_stmt->execute();
                
                // Message système optionnel
                $system_message = "Le ticket a été automatiquement ré-ouvert suite à votre message.";
                $system_message_enc = encrypt($system_message);
                $system_author_enc = encrypt("Système");
                
                $system_stmt = $db->prepare("INSERT INTO messages (ticket_id, user_id, author_name_encrypted, author_role, message_encrypted) VALUES (?, ?, ?, 'system', ?)");
                $system_stmt->bind_param("iiss", $ticket_id, $author_id, $system_author_enc, $system_message_enc);
                $system_stmt->execute();
            }
        }

        jsonResponse(true, 'Message envoyé avec succès.');
    } else {
        jsonResponse(false, 'Erreur lors de l\'envoi du message.');
    }
}

/**
 * Marque les messages d'un ticket comme lus (pour les admins).
 */
function message_read() {
    requireAuth('admin');
    $input = getInput();
    $ticket_id = (int)($input['ticket_id'] ?? 0);

    if (empty($ticket_id)) {
        jsonResponse(false, 'ID de ticket requis.');
    }

    $db = Database::getInstance()->getConnection();
    // On ne marque comme lus que les messages des utilisateurs
    $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE ticket_id = ? AND author_role = 'user'");
    $stmt->bind_param("i", $ticket_id);
    $stmt->execute();

    jsonResponse(true, 'Messages marqués comme lus.');
}

/**
 * Marque les messages d'un ticket comme lus (pour les utilisateurs).
 * C'est l'équivalent de message_read() mais pour le côté client.
 */
function message_read_by_user() {
    requireAuth('user'); // Seul un utilisateur connecté peut faire ça
    $input = getInput();
    $ticket_id = (int)($input['ticket_id'] ?? 0);
    $user_id = (int)$_SESSION['user_id'];

    if (empty($ticket_id)) {
        jsonResponse(false, 'ID de ticket requis.');
    }

    $db = Database::getInstance()->getConnection();

    // SÉCURITÉ : Vérifier que l'utilisateur est bien le propriétaire du ticket
    $stmt_check = $db->prepare("SELECT id FROM tickets WHERE id = ? AND user_id = ?");
    $stmt_check->bind_param("ii", $ticket_id, $user_id);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows === 0) {
        jsonResponse(false, 'Accès non autorisé à ce ticket.');
    }

    // On ne marque comme lus que les messages des administrateurs
    $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE ticket_id = ? AND author_role = 'admin'");
    $stmt->bind_param("i", $ticket_id);
    $stmt->execute();

    jsonResponse(true, 'Messages marqués comme lus par l\'utilisateur.');
}