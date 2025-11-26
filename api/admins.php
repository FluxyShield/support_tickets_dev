<?php
/**
 * ===================================================================
 * API - Logique des Administrateurs (api/admins.php)
 * ===================================================================
 * Contient toutes les fonctions liées à la gestion des admins et assignations.
 * ===================================================================
 */

if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé');
}

// Vérifier que config.php est bien chargé
if (!function_exists('sendEmail')) {
    require_once ROOT_PATH . '/config.php';
}

function get_admins() {
    requireAuth('admin');
    $db = Database::getInstance()->getConnection();
    
    // ⭐ SÉCURITÉ : Utiliser une requête préparée même pour les requêtes statiques
    $role = 'admin';
    $stmt = $db->prepare("SELECT id, firstname_encrypted, lastname_encrypted FROM users WHERE role = ?");
    $stmt->bind_param("s", $role);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $admins = [];
    while ($row = $result->fetch_assoc()) {
        $admins[] = [
            'id' => (int)$row['id'],
            'firstname' => decrypt($row['firstname_encrypted']),
            'lastname' => decrypt($row['lastname_encrypted']),
            'fullname' => decrypt($row['firstname_encrypted']) . ' ' . decrypt($row['lastname_encrypted'])
        ];
    }
    
    jsonResponse(true, 'Admins récupérés', ['admins' => $admins]);
}

function assign_ticket() {
    requireAuth('admin');
    $input = getInput();
    
    // ⭐ SÉCURITÉ RENFORCÉE : Validation stricte côté serveur
    $ticket_id = filter_var($input['ticket_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $admin_id = filter_var($input['admin_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (!$ticket_id || !$admin_id) {
        jsonResponse(false, 'ID de ticket et ID d\'admin invalides.');
    }
    
    // ⭐ SÉCURITÉ : Vérifier que l'admin existe et est bien un admin
    $db = Database::getInstance()->getConnection();
    $check_admin_stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin'");
    $check_admin_stmt->bind_param("i", $admin_id);
    $check_admin_stmt->execute();
    if ($check_admin_stmt->get_result()->num_rows === 0) {
        jsonResponse(false, 'Admin non trouvé ou invalide.');
    }
    
    // ⭐ SÉCURITÉ : Vérifier que le ticket existe
    $check_ticket_stmt = $db->prepare("SELECT id FROM tickets WHERE id = ?");
    $check_ticket_stmt->bind_param("i", $ticket_id);
    $check_ticket_stmt->execute();
    if ($check_ticket_stmt->get_result()->num_rows === 0) {
        jsonResponse(false, 'Ticket non trouvé.');
    }

    // La connexion DB est déjà établie ci-dessus
    $stmt = $db->prepare("UPDATE tickets SET assigned_to = ?, assigned_at = NOW() WHERE id = ?");
    $stmt->bind_param("ii", $admin_id, $ticket_id);

    if ($stmt->execute()) {
        // ⭐ AUDIT : Enregistrer l'assignation
        logAuditEvent('TICKET_ASSIGN', $ticket_id, [
            'assigned_to_admin_id' => $admin_id,
            'assigned_by_admin_id' => $_SESSION['admin_id']
        ]);

        // Optionnel : ajouter un message système dans le ticket
        $target_admin_stmt = $db->prepare("SELECT firstname_encrypted, lastname_encrypted, email_encrypted FROM users WHERE id = ?");
        $target_admin_stmt->bind_param("i", $admin_id);
        $target_admin_stmt->execute();
        $target_admin_res = $target_admin_stmt->get_result()->fetch_assoc();
        $target_admin_firstname = decrypt($target_admin_res['firstname_encrypted']);
        $target_admin_lastname = decrypt($target_admin_res['lastname_encrypted']);

        $message = "Ticket assigné à " . htmlspecialchars($target_admin_firstname . ' ' . $target_admin_lastname) . ".";
        $message_enc = encrypt($message);
        $author_name_enc = encrypt("Système");

        $msg_stmt = $db->prepare("INSERT INTO messages (ticket_id, author_name_encrypted, author_role, message_encrypted) VALUES (?, ?, 'system', ?)");
        $msg_stmt->bind_param("iss", $ticket_id, $author_name_enc, $message_enc);
        $msg_stmt->execute();

        // ⭐ NOUVEAU : Envoi d'un email de notification à l'admin assigné
        $target_admin_email = decrypt($target_admin_res['email_encrypted']);

        // ⭐ CORRECTIF SÉCURITÉ : Ne pas se fier à la session qui peut être fermée.
        // Récupérer le nom de l'admin qui assigne depuis la BDD pour être sûr.
        $assigning_admin_id = $_SESSION['admin_id'];
        $assigning_admin_stmt = $db->prepare("SELECT firstname_encrypted, lastname_encrypted FROM users WHERE id = ?");
        $assigning_admin_stmt->bind_param("i", $assigning_admin_id);
        $assigning_admin_stmt->execute();
        $assigning_admin_res = $assigning_admin_stmt->get_result()->fetch_assoc();
        $assigning_admin_name = decrypt($assigning_admin_res['firstname_encrypted']) . ' ' . decrypt($assigning_admin_res['lastname_encrypted']);

        // Récupérer les détails du ticket pour l'email
        $ticket_stmt = $db->prepare("SELECT subject_encrypted FROM tickets WHERE id = ?");
        $ticket_stmt->bind_param("i", $ticket_id);
        $ticket_stmt->execute();
        $ticket_res = $ticket_stmt->get_result()->fetch_assoc();
        $ticket_subject = decrypt($ticket_res['subject_encrypted']);
 
        $email_subject = "[Ticket #" . $ticket_id . "] Vous a été assigné : " . $ticket_subject;
        $ticket_link = APP_URL_BASE . '/admin.php'; // Lien vers le panneau admin

        $email_body = "
            <h2 style='color: #4A4A49;'> Nouveau Ticket Assigné </h2>
            <p>Bonjour " . htmlspecialchars($target_admin_firstname) . ",</p>
            <p>Le ticket <strong>#" . $ticket_id . " - " . htmlspecialchars($ticket_subject) . "</strong> vient de vous être assigné par <strong>" . htmlspecialchars($assigning_admin_name) . "</strong>.</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='" . $ticket_link . "' style='background: #EF8000; color: white; padding: 12px 25px; text-decoration: none; border-radius: 8px; font-weight: bold;'>Voir le ticket</a>
            </p>";

        // ⭐ AMÉLIORATION PERFORMANCE : Rendre l'envoi d'email "asynchrone"
        // 1. On envoie la réponse au client immédiatement pour une UI réactive.
        jsonResponse(true, 'Ticket assigné avec succès.');

        // 2. On s'assure que le script continue de s'exécuter même si le client se déconnecte.
        ignore_user_abort(true);

        // 3. On libère la session pour éviter de bloquer d'autres requêtes.
        session_write_close();

        // 4. On envoie l'email en "arrière-plan". L'utilisateur n'attend plus cette étape.
        sendEmail($target_admin_email, $email_subject, $email_body);

        // La réponse a déjà été envoyée, on ne fait plus rien ici.
    } else {
        jsonResponse(false, 'Erreur lors de l\'assignation du ticket.');
    }
}

function unassign_ticket() {
    requireAuth('admin');
    $input = getInput();
    
    // ⭐ SÉCURITÉ RENFORCÉE : Validation stricte côté serveur
    $ticket_id = filter_var($input['ticket_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (!$ticket_id) {
        jsonResponse(false, 'ID de ticket invalide.');
    }
    
    // ⭐ SÉCURITÉ : Vérifier que le ticket existe
    $db = Database::getInstance()->getConnection();
    $check_ticket_stmt = $db->prepare("SELECT id FROM tickets WHERE id = ?");
    $check_ticket_stmt->bind_param("i", $ticket_id);
    $check_ticket_stmt->execute();
    if ($check_ticket_stmt->get_result()->num_rows === 0) {
        jsonResponse(false, 'Ticket non trouvé.');
    }

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("UPDATE tickets SET assigned_to = NULL, assigned_at = NULL WHERE id = ?");
    $stmt->bind_param("i", $ticket_id);

    if ($stmt->execute()) {
        // ⭐ AUDIT : Enregistrer la désassignation
        logAuditEvent('TICKET_UNASSIGN', $ticket_id);

        jsonResponse(true, 'Ticket désassigné avec succès.');
    } else {
        jsonResponse(false, 'Erreur lors de la désassignation.');
    }
}