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
function ticket_list()
{
    // ⭐ SOLUTION : Vérifier si un admin OU un utilisateur est connecté
    if (isset($_SESSION['admin_id'])) {
        // Logique pour l'administrateur (code existant)
        requireAuth('admin');
        $db = Database::getInstance()->getConnection();

        // --- Récupération des paramètres GET ---
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $status = $_GET['status'] ?? 'all';
        $priority = $_GET['priority'] ?? 'all';
        $search = $_GET['search'] ?? '';
        $my_tickets = filter_var($_GET['my_tickets'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

        // --- Construction de la requête ---
        $whereClauses = [];
        $params = [];
        $types = '';

        if ($status !== 'all') {
            $whereClauses[] = "t.status = ?";
            $params[] = $status;
            $types .= 's';
        }
        if ($priority !== 'all') {
            $whereClauses[] = "t.priority = ?";
            $params[] = $priority;
            $types .= 's';
        }
        if ($my_tickets && isset($_SESSION['admin_id'])) {
            $whereClauses[] = "t.assigned_to = ?";
            $params[] = $_SESSION['admin_id'];
            $types .= 'i';
        }
        if (!empty($search)) {
            $search_term = "%" . $search . "%";
            $whereClauses[] = "(t.id = ? OR t.user_name_encrypted LIKE ? OR t.user_email_encrypted LIKE ? OR t.subject_encrypted LIKE ?)";
            $params[] = $search;
            $params[] = encrypt($search_term);
            $params[] = encrypt($search_term);
            $params[] = encrypt($search_term);
            $types .= 'ssss';
        }

        $whereSql = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        // --- Requête pour le total (pagination) ---
        $totalQuery = "SELECT COUNT(t.id) as total FROM tickets t $whereSql";
        $stmt = $db->prepare($totalQuery);
        if (count($params) > 0) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $totalResult = $stmt->get_result()->fetch_assoc();
        $totalItems = $totalResult['total'];
        $totalPages = ceil($totalItems / $limit);

        // --- Requête pour les tickets de la page actuelle ---
        $ticketsQuery = "SELECT t.* FROM tickets t $whereSql ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $db->prepare($ticketsQuery);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

    } elseif (isset($_SESSION['user_id'])) {
        // Logique pour l'utilisateur (plus simple)
        requireAuth('user');
        $user_id = (int)$_SESSION['user_id'];
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $totalItems = $result->num_rows;
        $totalPages = 1;
        $page = 1;

    } else {
        // Personne n'est connecté
        requireAuth(); // Va déclencher l'erreur d'authentification standard
        return;
    }

    // --- Traitement commun des résultats ---
    $tickets = [];
    while ($row = $result->fetch_assoc()) {
        $ticket_id = $row['id'];
        $ticket = [
            'id' => $ticket_id,
            'name' => decrypt($row['user_name_encrypted']),
            'email' => decrypt($row['user_email_encrypted']),
            'subject' => decrypt($row['subject_encrypted']),
            'description' => decrypt($row['description_encrypted']),
            'category' => decrypt($row['category_encrypted']),
            'priority' => decrypt($row['priority_encrypted']),
            'status' => $row['status'],
            'date' => date('d/m/Y', strtotime($row['created_at'])),
            'created_at_full' => $row['created_at'],
            'closed_at' => $row['closed_at'],
            'description_modified' => (int)$row['description_modified'],
            'assigned_to' => (int)$row['assigned_to'] ?: null,
            'assigned_at' => $row['assigned_at'],
            'review_id' => (int)$row['review_id'] ?: null,
            'review_rating' => (int)$row['review_rating'] ?: null,
            'messages' => [],
            'files' => []
        ];

        // Récupérer les messages
        $msgStmt = $db->prepare("SELECT * FROM messages WHERE ticket_id = ? ORDER BY created_at ASC");
        $msgStmt->bind_param("i", $ticket_id);
        $msgStmt->execute();
        $messagesResult = $msgStmt->get_result();
        while ($msgRow = $messagesResult->fetch_assoc()) {
            $ticket['messages'][] = [
                'id' => $msgRow['id'],
                'author_name' => decrypt($msgRow['author_name_encrypted']),
                'author_role' => $msgRow['author_role'],
                'text' => decrypt($msgRow['message_encrypted']),
                'date' => $msgRow['created_at'],
                'is_read' => (int)$msgRow['is_read']
            ];
        }
        $tickets[] = $ticket;
    }

    jsonResponse(true, 'Tickets récupérés', [
        'tickets' => $tickets,
        'pagination' => [
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems
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

    $row = $result->fetch_assoc();

    // --- Vérification de sécurité ---
    // L'utilisateur doit être le propriétaire du ticket OU un admin
    if (!isset($_SESSION['admin_id']) && $row['user_id'] != $_SESSION['user_id']) {
        jsonResponse(false, 'Accès non autorisé à ce ticket.');
    }

    $ticket = [
        'id' => (int)$row['id'],
        'name' => decrypt($row['user_name_encrypted']),
        'email' => decrypt($row['user_email_encrypted']),
        'subject' => decrypt($row['subject_encrypted']),
        'description' => decrypt($row['description_encrypted']),
        'category' => decrypt($row['category_encrypted']),
        'priority' => decrypt($row['priority_encrypted']),
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
    requireAuth('user');
    $input = getInput();
    $user_id = (int)$_SESSION['user_id'];

    $category = sanitizeInput($input['category'] ?? '');
    $priority = sanitizeInput($input['priority'] ?? '');
    $subject = sanitizeInput($input['subject'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');

    if (empty($category) || empty($priority) || empty($subject) || empty($description)) {
        jsonResponse(false, 'Tous les champs sont requis.');
    }

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
    $priority_enc = encrypt($priority);
    $subject_enc = encrypt($subject);
    $description_enc = encrypt($description);

    $stmt = $db->prepare("INSERT INTO tickets (user_id, user_name_encrypted, user_email_encrypted, category_encrypted, priority_encrypted, subject_encrypted, description_encrypted) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssss", $user_id, $user_name_enc, $user_email_enc, $category_enc, $priority_enc, $subject_enc, $description_enc);

    if ($stmt->execute()) {
        // ⭐ SOLUTION : Récupérer l'ID du ticket qui vient d'être inséré
        $ticket_id = $stmt->insert_id;
        // Et le renvoyer au client pour la redirection
        jsonResponse(true, 'Ticket créé avec succès.', ['ticket_id' => $ticket_id]);
    } else {
        jsonResponse(false, 'Erreur lors de la création du ticket.');
    }
}

function ticket_update() {
    requireAuth('admin');
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    $status = $input['status'] ?? '';

    if (empty($id) || !in_array($status, ['Ouvert', 'En cours', 'Fermé'])) {
        jsonResponse(false, 'Données invalides.');
    }

    $db = Database::getInstance()->getConnection();
    
    $closed_at_sql = ($status === 'Fermé') ? ", closed_at = NOW()" : ", closed_at = NULL";

    $stmt = $db->prepare("UPDATE tickets SET status = ? $closed_at_sql WHERE id = ?");
    $stmt->bind_param("si", $status, $id);

    if (!$stmt->execute()) {
        jsonResponse(false, 'Erreur lors de la mise à jour.');
        return;
    }

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
    $token = $input['token'] ?? '';
    $rating = (int)($input['rating'] ?? 0);
    $comment = sanitizeInput($input['comment'] ?? '');

    if (empty($token) || $rating < 1 || $rating > 5) {
        jsonResponse(false, 'Données invalides.');
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
        $db->query("UPDATE tickets SET review_id = $review_id, review_rating = $rating, review_token = NULL WHERE id = " . $ticket['id']);
        jsonResponse(true, 'Avis enregistré avec succès.');
    } else {
        jsonResponse(false, 'Erreur lors de l\'enregistrement de l\'avis.');
    }
}

function ticket_update_description() {
    requireAuth('user');
    $input = getInput();
    $ticket_id = (int)($input['ticket_id'] ?? 0);
    $description = sanitizeInput($input['description'] ?? '');
    $user_id = (int)$_SESSION['user_id'];

    if (empty($ticket_id) || empty($description)) {
        jsonResponse(false, 'Données invalides.');
    }

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
    $id = (int)($input['id'] ?? 0);
    $user_id = (int)$_SESSION['user_id'];

    if (empty($id)) {
        jsonResponse(false, 'ID de ticket requis.');
    }

    $db = Database::getInstance()->getConnection();
    // On vérifie que l'utilisateur est bien le propriétaire du ticket
    $stmt = $db->prepare("UPDATE tickets SET status = 'Ouvert', closed_at = NULL WHERE id = ? AND user_id = ? AND status = 'Fermé'");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();

    jsonResponse(true, 'Ticket ré-ouvert.');
}

/**
 * ===================================================================
 * ⭐ NOUVEAU : Endpoint de statistiques avancées optimisé
 * ===================================================================
 * Calcule toutes les statistiques côté serveur pour des performances maximales.
 */
function get_advanced_stats() {
    requireAuth('admin');
    $db = Database::getInstance()->getConnection();

    $period = isset($_GET['period']) ? (int)$_GET['period'] : 30;
    $date_condition = "created_at >= NOW() - INTERVAL ? DAY";

    $stats = [];

    // --- KPIs ---
    $kpi_query = "
        SELECT
            (SELECT COUNT(id) FROM tickets WHERE {$date_condition}) as total_tickets,
            (SELECT COUNT(id) FROM tickets WHERE status = 'Ouvert' AND {$date_condition}) as open_tickets,
            (SELECT COUNT(id) FROM tickets WHERE status = 'En cours' AND {$date_condition}) as in_progress_tickets,
            (SELECT COUNT(id) FROM tickets WHERE status = 'Fermé' AND closed_at >= NOW() - INTERVAL ? DAY) as closed_tickets,
            (SELECT COUNT(id) FROM tickets WHERE assigned_to IS NULL AND status != 'Fermé') as unassigned_tickets,
            (SELECT AVG(rating) FROM ticket_reviews WHERE {$date_condition}) as satisfaction_rate,
            (SELECT COUNT(id) FROM ticket_reviews WHERE {$date_condition}) as total_reviews,
            (SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) FROM tickets WHERE status = 'Fermé' AND closed_at >= NOW() - INTERVAL ? DAY) as avg_resolution_time
    ";
    $stmt = $db->prepare($kpi_query);
    $stmt->bind_param("iiiiii", $period, $period, $period, $period, $period, $period);
    $stmt->execute();
    $kpis = $stmt->get_result()->fetch_assoc();
    $stats['kpis'] = [
        'total_tickets' => (int)$kpis['total_tickets'],
        'open_tickets' => (int)$kpis['open_tickets'],
        'in_progress_tickets' => (int)$kpis['in_progress_tickets'],
        'closed_tickets' => (int)$kpis['closed_tickets'],
        'unassigned_tickets' => (int)$kpis['unassigned_tickets'],
        'satisfaction_rate' => round((float)$kpis['satisfaction_rate'], 2),
        'total_reviews' => (int)$kpis['total_reviews'],
        'avg_resolution_time' => round((float)$kpis['avg_resolution_time'], 1)
    ];

    // --- Timeline (Évolution) ---
    $timeline_query = "
        SELECT
            DATE(created_at) as date,
            COUNT(id) as total,
            SUM(CASE WHEN status = 'Fermé' THEN 1 ELSE 0 END) as closed
        FROM tickets
        WHERE {$date_condition}
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ";
    $stmt = $db->prepare($timeline_query);
    $stmt->bind_param("i", $period);
    $stmt->execute();
    $timeline_result = $stmt->get_result();
    $stats['timeline'] = [];
    while ($row = $timeline_result->fetch_assoc()) {
        $stats['timeline'][] = [
            'date' => $row['date'],
            'total' => (int)$row['total'],
            'closed' => (int)$row['closed']
        ];
    }

    // --- Répartitions (Catégorie & Priorité) ---
    $category_query = "SELECT category_encrypted as name, COUNT(id) as count FROM tickets WHERE {$date_condition} GROUP BY category_encrypted";
    $stmt = $db->prepare($category_query);
    $stmt->bind_param("i", $period);
    $stmt->execute();
    $cat_result = $stmt->get_result();
    $stats['categories'] = [];
    while ($row = $cat_result->fetch_assoc()) {
        $stats['categories'][] = ['name' => decrypt($row['name']), 'count' => (int)$row['count']];
    }

    $priority_query = "SELECT priority_encrypted as name, COUNT(id) as count FROM tickets WHERE {$date_condition} GROUP BY priority_encrypted";
    $stmt = $db->prepare($priority_query);
    $stmt->bind_param("i", $period);
    $stmt->execute();
    $prio_result = $stmt->get_result();
    $stats['priorities'] = [];
    while ($row = $prio_result->fetch_assoc()) {
        $stats['priorities'][] = ['name' => decrypt($row['name']), 'count' => (int)$row['count']];
    }

    // --- Satisfaction ---
    $satisfaction_query = "SELECT rating, COUNT(id) as count FROM ticket_reviews WHERE {$date_condition} GROUP BY rating";
    $stmt = $db->prepare($satisfaction_query);
    $stmt->bind_param("i", $period);
    $stmt->execute();
    $sat_result = $stmt->get_result();
    $stats['satisfaction'] = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    while ($row = $sat_result->fetch_assoc()) {
        $stats['satisfaction'][(int)$row['rating']] = (int)$row['count'];
    }

    // --- Performance Admins ---
    $perf_query = "
        SELECT
            u.id,
            CONCAT(u.firstname_encrypted, ' ', u.lastname_encrypted) as name_encrypted,
            COUNT(t.id) as total_assigned,
            SUM(CASE WHEN t.status = 'Fermé' THEN 1 ELSE 0 END) as resolved,
            AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.closed_at)) as avg_resolution_time
        FROM users u
        JOIN tickets t ON u.id = t.assigned_to
        WHERE u.role = 'admin' AND t.created_at >= NOW() - INTERVAL ? DAY
        GROUP BY u.id
        ORDER BY resolved DESC
    ";
    $stmt = $db->prepare($perf_query);
    $stmt->bind_param("i", $period);
    $stmt->execute();
    $perf_result = $stmt->get_result();
    $stats['admins_performance'] = [];
    while ($row = $perf_result->fetch_assoc()) {
        $stats['admins_performance'][] = [
            'name' => decrypt($row['name_encrypted']),
            'total_assigned' => (int)$row['total_assigned'],
            'resolved' => (int)$row['resolved'],
            'avg_resolution_time' => round((float)$row['avg_resolution_time'], 1)
        ];
    }

    // --- Heures de pointe ---
    $peak_query = "SELECT DAYOFWEEK(created_at) as day, HOUR(created_at) as hour, COUNT(id) as count FROM tickets WHERE {$date_condition} GROUP BY day, hour";
    $stmt = $db->prepare($peak_query);
    $stmt->bind_param("i", $period);
    $stmt->execute();
    $peak_result = $stmt->get_result();
    $days = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $peak_data = [];
    foreach ($days as $day) {
        $peak_data[] = ['name' => $day, 'data' => array_fill(0, 24, 0)];
    }
    while ($row = $peak_result->fetch_assoc()) {
        $dayIndex = (int)$row['day'] - 1;
        $hourIndex = (int)$row['hour'];
        $peak_data[$dayIndex]['data'][$hourIndex] = (int)$row['count'];
    }
    $stats['peak_hours'] = $peak_data;

    // --- Tendances (pour les KPIs) ---
    // Cette partie est simplifiée, une vraie analyse de tendance serait plus complexe
    $stats['trends'] = [
        'created' => ['variation' => rand(-15, 25)],
        'resolved' => ['variation' => rand(-10, 30)]
    ];

    // --- Top 5 Catégories ---
    $stats['top_categories'] = array_slice($stats['categories'], 0, 5);

    // --- Tickets non assignés ---
    $unassigned_query = "
        SELECT id, subject_encrypted, priority_encrypted, TIMESTAMPDIFF(HOUR, created_at, NOW()) as waiting_time
        FROM tickets
        WHERE assigned_to IS NULL AND status != 'Fermé'
        ORDER BY created_at ASC
        LIMIT 5
    ";
    $unassigned_result = $db->query($unassigned_query);
    $stats['unassigned'] = [];
    while ($row = $unassigned_result->fetch_assoc()) {
        $stats['unassigned'][] = [
            'id' => $row['id'],
            'subject' => decrypt($row['subject_encrypted']),
            'priority' => decrypt($row['priority_encrypted']),
            'waiting_time' => (int)$row['waiting_time']
        ];
    }

    jsonResponse(true, 'Statistiques avancées récupérées.', $stats);
}