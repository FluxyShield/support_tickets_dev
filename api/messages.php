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

// Vérifier que config.php est bien chargé
if (!function_exists('sendEmail')) {
    require_once ROOT_PATH . '/config.php';
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

        // --- NOUVEAU : Notification à l'utilisateur lors d'une réponse admin ---
        if ($author_role === 'admin') {
            // 1. Récupérer les informations du ticket et de l'utilisateur
            $info_stmt = $db->prepare("
                SELECT 
                    t.subject_encrypted, 
                    u.email_encrypted, 
                    u.firstname_encrypted 
                FROM tickets t 
                JOIN users u ON t.user_id = u.id 
                WHERE t.id = ?
            ");
            $info_stmt->bind_param("i", $ticket_id);
            $info_stmt->execute();
            $ticket_info = $info_stmt->get_result()->fetch_assoc();

            if ($ticket_info) {
                $user_email = decrypt($ticket_info['email_encrypted']);
                $user_firstname = decrypt($ticket_info['firstname_encrypted']);
                $ticket_subject = decrypt($ticket_info['subject_encrypted']);
                $ticket_link = APP_URL_BASE . '/ticket_details.php?id=' . $ticket_id;

                $email_subject = "[Ticket #{$ticket_id}] Nouvelle réponse d'un administrateur";
                $email_body = "
                    <h2 style='color: #4A4A49;'>Nouvelle réponse sur votre ticket</h2>
                    <p>Bonjour " . htmlspecialchars($user_firstname) . ",</p>
                    <p>Un administrateur a répondu à votre ticket <strong>#{$ticket_id} - " . htmlspecialchars($ticket_subject) . "</strong>.</p>
                    <p><strong>Message de " . htmlspecialchars($author_name) . " :</strong></p>
                    <blockquote style='border-left: 4px solid #ccc; padding-left: 15px; margin-left: 0; font-style: italic;'>" . nl2br(htmlspecialchars($message)) . "</blockquote>
                    <p style='text-align: center; margin: 30px 0;'><a href='{$ticket_link}' style='background: #EF8000; color: white; padding: 12px 25px; text-decoration: none; border-radius: 8px; font-weight: bold;'>Voir la réponse</a></p>
                ";
                sendEmail($user_email, $email_subject, $email_body);
            }
        }

        // --- NOUVEAU : Notification à l'admin lors d'une réponse utilisateur ---
        if ($author_role === 'user') {
            $ticket_info_stmt = $db->prepare("SELECT subject_encrypted, assigned_to FROM tickets WHERE id = ?");
            $ticket_info_stmt->bind_param("i", $ticket_id);
            $ticket_info_stmt->execute();
            $ticket_info = $ticket_info_stmt->get_result()->fetch_assoc();

            if ($ticket_info) {
                $admin_to_notify_id = $ticket_info['assigned_to']; // Peut être NULL
                $ticket_subject = decrypt($ticket_info['subject_encrypted']);
                
                $admin_email_stmt = $db->prepare("SELECT email_encrypted FROM users WHERE id = ? AND role = 'admin'");
                $admin_email_stmt->bind_param("i", $admin_to_notify_id);
                $admin_email_stmt->execute();
                $admin_data = $admin_email_stmt->get_result()->fetch_assoc();

                $email_subject = "Nouvelle réponse sur le ticket #{$ticket_id}";
                $admin_ticket_link = APP_URL_BASE . '/admin.php#ticket-' . $ticket_id;
                $email_body = "
                    <p>L'utilisateur a répondu au ticket <strong>#{$ticket_id} - " . htmlspecialchars($ticket_subject) . "</strong>.</p>
                    <p><strong>Message :</strong></p>
                    <blockquote style='border-left: 4px solid #ccc; padding-left: 15px; margin-left: 0;'>" . nl2br(htmlspecialchars($message)) . "</blockquote>
                    <p style='text-align: center; margin: 30px 0;'><a href='{$admin_ticket_link}' style='background: #4A4A49; color: white; padding: 12px 25px; text-decoration: none; border-radius: 8px; font-weight: bold;'>Voir la réponse</a></p>
                ";

                if ($admin_data) {
                    // Cas 1 : Le ticket est assigné, on notifie l'admin concerné
                    $admin_email = decrypt($admin_data['email_encrypted']);
                    if ($admin_email) {
                        sendEmail($admin_email, $email_subject, $email_body);
                    }
                } else {
                    // Cas 2 : Le ticket n'est pas assigné, on notifie TOUS les admins
                    $all_admins_stmt = $db->prepare("SELECT email_encrypted FROM users WHERE role = 'admin'");
                    $all_admins_stmt->execute();
                    $admins_result = $all_admins_stmt->get_result();
                    
                    while ($admin = $admins_result->fetch_assoc()) {
                        $admin_email = decrypt($admin['email_encrypted']);
                        if ($admin_email) {
                            sendEmail($admin_email, $email_subject, $email_body);
                        }
                    }
                }
            }
        }
        // --- FIN DE LA NOTIFICATION ---

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