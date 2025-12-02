<?php
/**
 * ===================================================================
 * API - Gestion des Utilisateurs (api/users.php)
 * ===================================================================
 * Contient les fonctions pour lister les utilisateurs et modifier leurs rôles.
 * ===================================================================
 */

if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé');
}

function get_users() {
    requireAuth('admin');
    $db = Database::getInstance()->getConnection();

    // Récupération des paramètres de pagination et recherche
    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
    $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, ['options' => ['default' => 10, 'min_range' => 1, 'max_range' => 100]]);
    $search = $_GET['search'] ?? '';
    $offset = ($page - 1) * $limit;

    $whereClauses = [];
    $params = [];
    $types = '';

    if (!empty($search)) {
        // Note: Comme les noms/emails sont chiffrés, la recherche est limitée.
        // On peut rechercher par ID ou par rôle.
        // Pour une recherche sur les champs chiffrés, il faudrait déchiffrer tous les users (très lourd)
        // ou utiliser un mécanisme de hash aveugle (non implémenté ici).
        // On se limite ici à la recherche par ID pour l'instant.
        if (is_numeric($search)) {
            $whereClauses[] = "id = ?";
            $params[] = $search;
            $types .= 'i';
        }
    }

    $whereSql = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    // Compter le total
    $countQuery = "SELECT COUNT(*) as total FROM users $whereSql";
    $stmt = $db->prepare($countQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $totalItems = $stmt->get_result()->fetch_assoc()['total'];
    $totalPages = ceil($totalItems / $limit);

    // Récupérer les utilisateurs
    $query = "SELECT id, firstname_encrypted, lastname_encrypted, email_encrypted, role, created_at FROM users $whereSql ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $db->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'id' => (int)$row['id'],
            'firstname' => decrypt($row['firstname_encrypted']),
            'lastname' => decrypt($row['lastname_encrypted']),
            'email' => decrypt($row['email_encrypted']),
            'role' => $row['role'],
            'created_at' => date('d/m/Y H:i', strtotime($row['created_at']))
        ];
    }

    jsonResponse(true, 'Utilisateurs récupérés', [
        'users' => $users,
        'pagination' => [
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalItems' => (int)$totalItems
        ]
    ]);
}

function update_user_role() {
    requireAuth('admin');
    $input = getInput();
    $user_id = filter_var($input['user_id'] ?? 0, FILTER_VALIDATE_INT);
    $new_role = $input['role'] ?? '';

    if (!$user_id || !in_array($new_role, ['admin', 'user'])) {
        jsonResponse(false, 'Données invalides.');
    }

    // Sécurité : Empêcher de modifier son propre rôle
    if ($user_id === $_SESSION['admin_id']) {
        jsonResponse(false, 'Vous ne pouvez pas modifier votre propre rôle.');
    }

    $db = Database::getInstance()->getConnection();
    
    // Vérifier si l'utilisateur existe
    $checkStmt = $db->prepare("SELECT id FROM users WHERE id = ?");
    $checkStmt->bind_param("i", $user_id);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows === 0) {
        jsonResponse(false, 'Utilisateur introuvable.');
    }

    // Mise à jour du rôle
    $stmt = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
    $stmt->bind_param("si", $new_role, $user_id);

    if ($stmt->execute()) {
        // Audit
        logAuditEvent('USER_ROLE_UPDATE', $user_id, [
            'new_role' => $new_role,
            'updated_by' => $_SESSION['admin_id']
        ]);
        
        jsonResponse(true, "Rôle mis à jour avec succès : $new_role");
    } else {
        jsonResponse(false, "Erreur lors de la mise à jour du rôle.");
    }
}
?>
