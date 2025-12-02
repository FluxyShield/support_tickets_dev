<?php
/**
 * ===================================================================
 * API - Logique des Tickets (api/tickets.php)
 * ===================================================================
 * Contient toutes les fonctions liées à la gestion des tickets.
 * ===================================================================
 */

if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé');
}

// Vérifier que config.php est bien chargé
if (!function_exists('sendEmail')) {
    require_once ROOT_PATH . '/config.php';
}
function ticket_list() {
    if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
        requireAuth();
        return;
    }

    $db = Database::getInstance()->getConnection();
    $is_admin = isset($_SESSION['admin_id']);

    // --- Récupération et validation des paramètres ---
    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
    $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, ['options' => ['default' => 10, 'min_range' => 1, 'max_range' => 100]]);
    $offset = ($page - 1) * $limit;
    
    $status = $_GET['status'] ?? 'all';
    $priority = $_GET['priority'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $my_tickets = filter_var($_GET['my_tickets'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    $include_files = filter_var($_GET['include_files'] ?? 'true', FILTER_VALIDATE_BOOLEAN); // Par défaut on inclut tout maintenant

    // --- Construction de la requête principale (tickets) ---
    $whereClauses = [];
    $params = [];
    $types = '';

    if ($is_admin) {
        if ($status !== 'all') { $whereClauses[] = "t.status = ?"; $params[] = $status; $types .= 's'; }
        if ($priority !== 'all') { $whereClauses[] = "t.priority = ?"; $params[] = $priority; $types .= 's'; }
        if ($my_tickets) { $whereClauses[] = "t.assigned_to = ?"; $params[] = $_SESSION['admin_id']; $types .= 'i'; }
        if (!empty($search)) { $whereClauses[] = "t.id LIKE ?"; $params[] = "%$search%"; $types .= 's'; }
    } else {
        $whereClauses[] = "t.user_id = ?";
        $params[] = $_SESSION['user_id'];
        $types .= 'i';
    }
    
    $whereSql = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    // --- Requête pour le total (pagination) ---
    $totalQuery = "SELECT COUNT(t.id) as total FROM tickets t $whereSql";
    $stmt = $db->prepare($totalQuery);
    if (count($params) > 0) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $totalItems = $stmt->get_result()->fetch_assoc()['total'];
    $totalPages = ceil($totalItems / $limit);

    // --- Requête pour les tickets de la page ---
    $ticketsQuery = "SELECT t.* FROM tickets t $whereSql ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $db->prepare($ticketsQuery);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $ticket_results = $stmt->get_result();

    $tickets = [];
    $ticket_ids = [];
    while ($row = $ticket_results->fetch_assoc()) {
        $ticket_id = (int)$row['id'];
        $tickets[$ticket_id] = [
            'id' => $ticket_id,
            'user_id' => (int)$row['user_id'],
            'name' => decrypt($row['user_name_encrypted']),
            'email' => decrypt($row['user_email_encrypted']),
            'subject' => decrypt($row['subject_encrypted']),
            'description' => decrypt($row['description_encrypted']),
            'category' => decrypt($row['category_encrypted']),
            'priority' => $row['priority'],
            'status' => $row['status'],
            'date' => date('d/m/Y H:i', strtotime($row['created_at'])),
            'closed_at' => $row['closed_at'],
            'assigned_to' => isset($row['assigned_to']) ? (int)$row['assigned_to'] : null,
            'description_modified' => (int)$row['description_modified'],
            'review_id' => (int)$row['review_id'],
            'review_rating' => (int)$row['review_rating'],
            'messages' => [], // Initialisation
            'files' => []      // Initialisation
        ];
        $ticket_ids[] = $ticket_id;
    }

    // --- DEBUT DE LA CORRECTION N+1 ---
    if (!empty($ticket_ids)) {
        $ids_placeholder = implode(',', array_fill(0, count($ticket_ids), '?'));
        $ids_types = str_repeat('i', count($ticket_ids));

        // 2. Une seule requête pour tous les messages
        $messages = [];
        $msg_stmt = $db->prepare("SELECT * FROM messages WHERE ticket_id IN ($ids_placeholder) ORDER BY created_at ASC");
        $msg_stmt->bind_param($ids_types, ...$ticket_ids);
        $msg_stmt->execute();
        $msg_result = $msg_stmt->get_result();
        while ($msg = $msg_result->fetch_assoc()) {
            $messages[$msg['ticket_id']][] = [
                'id' => (int)$msg['id'],
                'author_name' => decrypt($msg['author_name_encrypted']),
                'author_role' => $msg['author_role'],
                'text' => decrypt($msg['message_encrypted']),
                'date' => $msg['created_at'],
                'is_read' => (int)$msg['is_read'],
            ];
        }

        // 3. Une seule requête pour tous les fichiers (si nécessaire)
        $files = [];
        if ($include_files) {
            $file_stmt = $db->prepare("SELECT * FROM ticket_files WHERE ticket_id IN ($ids_placeholder)");
            $file_stmt->bind_param($ids_types, ...$ticket_ids);
            $file_stmt->execute();
            $file_result = $file_stmt->get_result();
            while ($file = $file_result->fetch_assoc()) {
                $files[$file['ticket_id']][] = [
                    'id' => (int)$file['id'],
                    'name' => decrypt($file['original_filename_encrypted']),
                    'type' => $file['file_type'],
                    'size' => (int)$file['file_size'],
                    'date' => date('d/m/Y', strtotime($file['uploaded_at'])),
                    'uploaded_by' => decrypt($file['uploaded_by_encrypted'])
                ];
            }
        }

        // 4. On attache les messages et fichiers aux tickets correspondants
        foreach ($tickets as $ticket_id => &$ticket) {
            if (isset($messages[$ticket_id])) {
                $ticket['messages'] = $messages[$ticket_id];
            }
            if (isset($files[$ticket_id])) {
                $ticket['files'] = $files[$ticket_id];
            }
        }
    }
    // --- FIN DE LA CORRECTION N+1 ---

    $user_info = null;
    if (!$is_admin) {
        $user_info = [
            'firstname' => $_SESSION['firstname'],
            'lastname' => $_SESSION['lastname']
        ];
    }

    jsonResponse(true, 'Tickets récupérés', [
        'tickets' => array_values($tickets), // Réindexer le tableau pour la réponse JSON
        'user' => $user_info,
        'pagination' => [
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalItems' => (int)$totalItems
        ]
    ]);
}

function get_ticket_details() {
    requireAuth(); // 'user' ou 'admin'
    $ticket_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if (!$ticket_id) {
        jsonResponse(false, 'ID de ticket invalide.');
    }

    $db = Database::getInstance()->getConnection();
    
    // On récupère le ticket
    $stmt = $db->prepare("SELECT * FROM tickets WHERE id = ?");
    $stmt->bind_param("i", $ticket_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        jsonResponse(false, 'Ticket non trouvé.');
    }

    // ⭐ SÉCURITÉ RENFORCÉE : Vérifier les permissions AVANT de traiter les données.
    // On récupère d'abord uniquement les informations nécessaires à la vérification des droits.
    $permissions_check_row = $result->fetch_assoc();
    if (!isset($_SESSION['admin_id']) && $permissions_check_row['user_id'] != $_SESSION['user_id']) {
        jsonResponse(false, 'Accès non autorisé à ce ticket.');
    }

    // Les permissions sont valides, on peut maintenant utiliser les données.
    $row = $permissions_check_row;
    // On replace le pointeur du résultat au début pour les boucles suivantes.
    $result->data_seek(0);

    $ticket = [
        'id' => (int)$row['id'],
        'name' => decrypt($row['user_name_encrypted']),
        'email' => decrypt($row['user_email_encrypted']),
        'subject' => decrypt($row['subject_encrypted']),
        'description' => decrypt($row['description_encrypted']),
        'category' => decrypt($row['category_encrypted']),
        'priority' => $row['priority'],
        'status' => $row['status'],
        'date' => date('d/m/Y', strtotime($row['created_at'])),
    ];

    // Récupérer les fichiers associés (on peut réutiliser la logique de ticket_list)
    $files = [];
    $fileStmt = $db->prepare("SELECT * FROM ticket_files WHERE ticket_id = ?");
    $fileStmt->bind_param("i", $ticket_id);
    $fileStmt->execute();
    $filesResult = $fileStmt->get_result();
    while ($fileRow = $filesResult->fetch_assoc()) {
        $files[] = [
            'id' => $fileRow['id'],
            'name' => decrypt($fileRow['filename_encrypted']),
            'type' => decrypt($fileRow['file_type_encrypted']),
            'size' => $fileRow['file_size'],
            'date' => date('d/m/Y', strtotime($fileRow['uploaded_at'])),
            'uploaded_by' => decrypt($fileRow['uploaded_by_name_encrypted'])
        ];
    }
    $ticket['files'] = $files;

    jsonResponse(true, 'Détails du ticket récupérés.', ['ticket' => $ticket]);
}

function get_stats() {
    requireAuth('admin');
    $db = Database::getInstance()->getConnection();

    $query = "SELECT status, COUNT(*) as count FROM tickets GROUP BY status";
    $result = $db->query($query);

    if (!$result) {
        jsonResponse(false, 'Erreur lors de la récupération des statistiques.');
    }

    $stats = [
        'total' => 0,
        'Ouvert' => 0,
        'En cours' => 0,
        'Fermé' => 0
    ];

    while ($row = $result->fetch_assoc()) {
        $stats[$row['status']] = (int)$row['count'];
        $stats['total'] += (int)$row['count'];
    }

    jsonResponse(true, 'Statistiques récupérées', ['stats' => $stats]);
}

function ticket_create() {
    // ⭐ SÉCURITÉ : Limiter la création de tickets (10 par heure par utilisateur).
    checkRateLimit('ticket_create', 10, 3600);

    requireAuth('user');
    $input = getInput();
    $user_id = (int)$_SESSION['user_id'];

    $category = sanitizeInput($input['category'] ?? '');
    $priority = sanitizeInput($input['priority'] ?? '');
    $subject = sanitizeInput($input['subject'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    
    // ⭐ SÉCURITÉ RENFORCÉE : Validation stricte des entrées
    if (empty($category) || empty($priority) || empty($subject) || empty($description)) {
        jsonResponse(false, 'Tous les champs sont requis.');
    }

    // Valider que les valeurs de catégorie et priorité sont parmi celles autorisées
    $allowed_categories = ['Technique', 'Facturation', 'Compte', 'Autre'];
    if (!in_array($category, $allowed_categories)) {
        jsonResponse(false, 'Catégorie non valide.');
    }

    $allowed_priorities = ['Basse', 'Moyenne', 'Haute'];
    if (!in_array($priority, $allowed_priorities)) {
        jsonResponse(false, 'Priorité non valide.');
    }

    // ⭐ AMÉLIORATION SÉCURITÉ : Valider la longueur des champs texte (min et max)
    if (strlen($subject) < 5) {
        jsonResponse(false, 'Le sujet doit contenir au moins 5 caractères.');
    }
    if (strlen($subject) > 255) {
        jsonResponse(false, 'Le sujet ne peut pas dépasser 255 caractères.');
    }
    if (strlen($description) < 10) {
        jsonResponse(false, 'La description doit contenir au moins 10 caractères.');
    }
    if (strlen($description) > 10000) {
        jsonResponse(false, 'La description ne peut pas dépasser 10000 caractères.');
    }
    // --- Fin de la validation ---


    $db = Database::getInstance()->getConnection();
    
    $user_name_enc = encrypt($_SESSION['firstname'] . ' ' . $_SESSION['lastname']);

    // ⭐ SOLUTION : Remplacer la requête non sécurisée et fragile
    $stmt_email = $db->prepare("SELECT email_encrypted FROM users WHERE id = ?");
    $stmt_email->bind_param("i", $user_id);
    $stmt_email->execute();
    $result_email = $stmt_email->get_result();
    $user_data = $result_email->fetch_assoc();
    $user_email_enc = $user_data['email_encrypted'] ?? '';

    $category_enc = encrypt($category);
    $subject_enc = encrypt($subject);
    $description_enc = encrypt($description);

    $stmt = $db->prepare("INSERT INTO tickets (user_id, user_name_encrypted, user_email_encrypted, category_encrypted, priority, subject_encrypted, description_encrypted) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssss", $user_id, $user_name_enc, $user_email_enc, $category_enc, $priority, $subject_enc, $description_enc);

    if ($stmt->execute()) {
        // ⭐ SOLUTION : Récupérer l'ID du ticket qui vient d'être inséré
        $ticket_id = $stmt->insert_id;
        // --- NOUVEAU : Envoi de l'email de confirmation ---
        $user_email = decrypt($user_email_enc);
        $user_firstname = $_SESSION['firstname'];
        $ticket_link = APP_URL_BASE . '/ticket_details.php?id=' . $ticket_id;

        $email_subject = "[Ticket #{$ticket_id}] Votre demande a bien été reçue";
        $email_body = "
            <h2 style='color: #4A4A49;'>Confirmation de votre ticket</h2>
            <p>Bonjour " . htmlspecialchars($user_firstname) . ",</p>
            <p>Nous avons bien reçu votre demande de support et l'avons enregistrée sous le numéro <strong>#{$ticket_id}</strong>.</p>
            <p><strong>Sujet :</strong> " . htmlspecialchars($subject) . "</p>
            <p>Notre équipe va l'examiner dans les plus brefs délais. Vous pouvez suivre son avancement en cliquant sur le lien ci-dessous :</p>
            <p style='text-align: center; margin: 30px 0;'><a href='{$ticket_link}' style='background: #EF8000; color: white; padding: 12px 25px; text-decoration: none; border-radius: 8px; font-weight: bold;'>Voir mon ticket</a></p>
        ";

        // On envoie l'email (l'échec n'empêche pas la création du ticket)
        sendEmail($user_email, $email_subject, $email_body);
        
        // --- NOUVEAU : Notification aux administrateurs ---
        $admin_subject = "[Nouveau Ticket #{$ticket_id}] " . $subject;
        $admin_link = APP_URL_BASE . '/admin.php#ticket-' . $ticket_id;
        $admin_body = "
            <h2 style='color: #4A4A49;'>Nouveau ticket créé</h2>
            <p><strong>Utilisateur :</strong> " . htmlspecialchars($user_firstname . ' ' . $_SESSION['lastname']) . "</p>
            <p><strong>Sujet :</strong> " . htmlspecialchars($subject) . "</p>
            <p><strong>Priorité :</strong> " . htmlspecialchars($priority) . "</p>
            <p><strong>Catégorie :</strong> " . htmlspecialchars($category) . "</p>
            <hr>
            <p>" . nl2br(htmlspecialchars($description)) . "</p>
            <p style='text-align: center; margin: 30px 0;'><a href='{$admin_link}' style='background: #4A4A49; color: white; padding: 12px 25px; text-decoration: none; border-radius: 8px; font-weight: bold;'>Gérer le ticket</a></p>
        ";

        // Récupérer tous les emails des admins
        $stmt_admins = $db->prepare("SELECT email_encrypted FROM users WHERE role = 'admin'");
        $stmt_admins->execute();
        $res_admins = $stmt_admins->get_result();
        
        while ($admin = $res_admins->fetch_assoc()) {
            $admin_email = decrypt($admin['email_encrypted']);
            if ($admin_email) {
                sendEmail($admin_email, $admin_subject, $admin_body);
            }
        }
        // --- FIN DE L'AJOUT ---

        jsonResponse(true, 'Ticket créé avec succès.', ['ticket_id' => $ticket_id]);
    } else {
        jsonResponse(false, 'Erreur lors de la création du ticket.');
    }
}

function ticket_update() {
    requireAuth('admin');
    $input = getInput();
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $status = $input['status'] ?? '';

    // ⭐ SÉCURITÉ RENFORCÉE : Validation stricte du type et des valeurs autorisées.
    if (!$id) {
        jsonResponse(false, 'ID de ticket invalide.');
    }
    $allowed_statuses = ['Ouvert', 'En cours', 'Fermé'];
    if (!in_array($status, $allowed_statuses)) {
        jsonResponse(false, 'Statut non valide.');
    }

    $db = Database::getInstance()->getConnection();

    // ⭐ AUDIT : Récupérer l'ancien statut avant la mise à jour
    $old_status_stmt = $db->prepare("SELECT status FROM tickets WHERE id = ?");
    $old_status_stmt->bind_param("i", $id);
    $old_status_stmt->execute();
    $old_status_res = $old_status_stmt->get_result();
    if ($old_status_res->num_rows === 0) {
        jsonResponse(false, 'Ticket non trouvé pour l\'audit.');
    }
    $old_status = $old_status_res->fetch_assoc()['status'];
    
    $query = "UPDATE tickets SET status = ?";
    $types = "s";
    $params = [$status];

    if ($status === 'Fermé') {
        $query .= ", closed_at = NOW()";
    } else {
        $query .= ", closed_at = NULL";
    }
    $query .= " WHERE id = ?";
    $params[] = $id; // Ajouter l'ID au tableau des paramètres

    $stmt = $db->prepare($query);
    $stmt->bind_param($types . "i", ...$params);

    if (!$stmt->execute()) {
        jsonResponse(false, 'Erreur lors de la mise à jour.');
        return;
    }

    // ⭐ AUDIT : Enregistrer le changement de statut
    logAuditEvent('TICKET_STATUS_UPDATE', $id, [
        'old_status' => $old_status,
        'new_status' => $status
    ]);



    // ⭐ NOUVEAU : Logique d'envoi d'email de notification à l'utilisateur

    // 1. Ajouter un message système pour tracer le changement
    $admin_name = $_SESSION['admin_firstname'] ?? 'un admin';
    $system_message = "Le statut a été changé à '" . htmlspecialchars($status) . "' par " . htmlspecialchars($admin_name) . ".";
    $system_message_enc = encrypt($system_message);
    $author_name_enc = encrypt("Système");

    $msg_stmt = $db->prepare("INSERT INTO messages (ticket_id, author_name_encrypted, author_role, message_encrypted) VALUES (?, ?, 'system', ?)");
    $msg_stmt->bind_param("iss", $id, $author_name_enc, $system_message_enc);
    $msg_stmt->execute();


    // ==========================================================
    // === DÉBUT DE LA CORRECTION (On envoie AVANT de répondre) ===
    // ==========================================================

    // 2. Préparer et envoyer l'email D'ABORD
    $email_sent = false;
    
    // 3. Récupérer les infos pour l'email
    $info_stmt = $db->prepare("SELECT t.subject_encrypted, u.email_encrypted, u.firstname_encrypted FROM tickets t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
    $info_stmt->bind_param("i", $id);
    $info_stmt->execute();
    $ticket_info = $info_stmt->get_result()->fetch_assoc();

    if ($ticket_info) {
        $user_email = decrypt($ticket_info['email_encrypted']);
        $user_firstname = decrypt($ticket_info['firstname_encrypted']);
        $ticket_subject = decrypt($ticket_info['subject_encrypted']);
        $ticket_link = APP_URL_BASE . '/ticket_details.php?id=' . $id;

        $email_subject = "[Ticket #{$id}] Votre ticket est maintenant : {$status}";
        $email_body = "
            <h2 style='color: #4A4A49;'>Mise à jour de votre ticket</h2>
            <p>Bonjour " . htmlspecialchars($user_firstname) . ",</p>
            <p>Le statut de votre ticket <strong>#{$id} - " . htmlspecialchars($ticket_subject) . "</strong> a été mis à jour. Il est maintenant : <strong>{$status}</strong>.</p>
            <p style='text-align: center; margin: 30px 0;'><a href='{$ticket_link}' style='background: #EF8000; color: white; padding: 12px 25px; text-decoration: none; border-radius: 8px; font-weight: bold;'>Voir mon ticket</a></p>
        ";
        $email_sent = sendEmail($user_email, $email_subject, $email_body);
    }

    // 4. Répondre à l'admin (maintenant que l'email est parti)
    if ($email_sent) {
        jsonResponse(true, 'Statut du ticket mis à jour et email envoyé.');
    } else {
        jsonResponse(true, 'Statut mis à jour (mais l\'email de notification a échoué).');
    }
    
    // ==========================================================
    // === FIN DE LA CORRECTION ===
    // ==========================================================
}

function ticket_delete() {
    requireAuth('admin');
    $input = getInput();
    $id = (int)($input['id'] ?? 0);

    if (empty($id)) {
        jsonResponse(false, 'ID de ticket requis.');
    }

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("DELETE FROM tickets WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        // ⭐ AUDIT : Enregistrer la suppression du ticket
        logAuditEvent('TICKET_DELETE', $id);

        jsonResponse(true, 'Ticket supprimé.');
    } else {
        jsonResponse(false, 'Erreur lors de la suppression.');
    }
}

/**
 * ⭐ NOUVEAU : Récupère les infos d'un ticket via son jeton d'avis.
 */
function get_ticket_by_review_token() {
    $token = $_GET['token'] ?? '';
    if (empty($token)) {
        jsonResponse(false, 'Jeton manquant.');
    }

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT id, subject_encrypted, review_id, review_rating FROM tickets WHERE review_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        jsonResponse(false, 'Jeton d\'avis invalide ou expiré.');
    }

    $ticket = $result->fetch_assoc();
    $ticket['subject'] = decrypt($ticket['subject_encrypted']);

    jsonResponse(true, 'Ticket trouvé.', ['ticket' => $ticket]);
}

/**
 * ⭐ NOUVEAU : Soumet un avis via un jeton.
 */
function submit_review_by_token() {
    $input = getInput();
    
    // ⭐ SÉCURITÉ RENFORCÉE : Validation stricte côté serveur
    $token = trim($input['token'] ?? '');
    if (empty($token) || strlen($token) > 255) {
        jsonResponse(false, 'Jeton invalide.');
    }
    
    // ⭐ SÉCURITÉ : Valider et nettoyer la note
    $rating = filter_var($input['rating'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
    if ($rating === false || $rating < 1 || $rating > 5) {
        jsonResponse(false, 'La note doit être un nombre entier entre 1 et 5.');
    }
    
    // ⭐ SÉCURITÉ : Valider et nettoyer le commentaire
    $comment = trim($input['comment'] ?? '');
    $comment = sanitizeInput($comment);
    
    // ⭐ SÉCURITÉ : Valider la longueur du commentaire (max 2000 caractères)
    if (strlen($comment) > 2000) {
        jsonResponse(false, 'Le commentaire ne peut pas dépasser 2000 caractères.');
    }


    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT id, user_id, review_id FROM tickets WHERE review_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();

    if (!$ticket) {
        jsonResponse(false, 'Jeton d\'avis invalide.');
    }

    if ($ticket['review_id']) {
        jsonResponse(false, 'Un avis a déjà été soumis pour ce ticket.');
    }

    $comment_enc = encrypt($comment);
    $insert_stmt = $db->prepare("INSERT INTO ticket_reviews (ticket_id, user_id, rating, comment_encrypted) VALUES (?, ?, ?, ?)");
    $insert_stmt->bind_param("iiis", $ticket['id'], $ticket['user_id'], $rating, $comment_enc);
    
    if ($insert_stmt->execute()) {
        $review_id = $insert_stmt->insert_id;
        // ⭐ SÉCURITÉ : Utiliser une requête préparée pour éviter l'injection SQL
        $ticket_id = (int)$ticket['id'];
        $update_stmt = $db->prepare("UPDATE tickets SET review_id = ?, review_rating = ?, review_token = NULL WHERE id = ?");
        $update_stmt->bind_param("iii", $review_id, $rating, $ticket_id);
        $update_stmt->execute();
        jsonResponse(true, 'Avis enregistré avec succès.');
    } else {
        jsonResponse(false, 'Erreur lors de l\'enregistrement de l\'avis.');
    }
}

function ticket_update_description() {
    requireAuth('user');
    $input = getInput();
    $ticket_id = (int)($input['ticket_id'] ?? 0);
    $description = trim($input['description'] ?? '');
    $user_id = (int)$_SESSION['user_id'];

    if (empty($ticket_id) || empty($description)) {
        jsonResponse(false, 'Données invalides.');
    }

    // ⭐ AMÉLIORATION SÉCURITÉ : Valider la longueur de la description (min et max)
    if (strlen($description) < 10) {
        jsonResponse(false, 'La description doit contenir au moins 10 caractères.');
    }
    if (strlen($description) > 10000) {
        jsonResponse(false, 'La description ne peut pas dépasser 10000 caractères.');
    }
    
    // ⭐ AMÉLIORATION SÉCURITÉ : Nettoyer la description après validation de longueur
    $description = sanitizeInput($description);

    $db = Database::getInstance()->getConnection();
    $description_enc = encrypt($description);

    // On vérifie que l'utilisateur est le propriétaire et que la description n'a pas déjà été modifiée
    $stmt = $db->prepare("UPDATE tickets SET description_encrypted = ?, description_modified = 1 WHERE id = ? AND user_id = ? AND description_modified = 0");
    $stmt->bind_param("sii", $description_enc, $ticket_id, $user_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        jsonResponse(true, 'Description mise à jour.');
    } else {
        jsonResponse(false, 'Impossible de mettre à jour la description (déjà modifiée ou ticket non trouvé).');
    }
}

function ticket_reopen() {
    requireAuth('user');
    $input = getInput();
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $user_id = (int)$_SESSION['user_id'];

    // ⭐ SÉCURITÉ RENFORCÉE : Valider que l'ID est un entier positif.
    if (!$id) {
        jsonResponse(false, 'ID de ticket requis.');
    }

    $db = Database::getInstance()->getConnection();

    // ⭐ SÉCURITÉ RENFORCÉE : Vérifier que l'utilisateur est le propriétaire ET que le ticket est bien 'Fermé'.
    // ⭐ NOUVEAU : Ajouter une condition pour que la réouverture ne soit possible que dans les 24h suivant la fermeture.
    // Cela rend la manipulation côté client inutile.
    $stmt = $db->prepare("UPDATE tickets SET status = 'Ouvert', closed_at = NULL WHERE id = ? AND user_id = ? AND status = 'Fermé' AND closed_at >= NOW() - INTERVAL 24 HOUR");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();

    // Vérifier si la mise à jour a bien eu lieu
    if ($stmt->affected_rows > 0) {
        jsonResponse(true, 'Ticket ré-ouvert.');
    } else {
        jsonResponse(false, 'Impossible de ré-ouvrir le ticket (il est peut-être fermé depuis plus de 24h, déjà ouvert, ou vous n\'êtes pas le propriétaire).');
    }
}
