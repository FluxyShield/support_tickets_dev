<?php
if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé');
}

// Vérifier que config.php est bien chargé
if (!function_exists('sendEmail')) {
    require_once ROOT_PATH . '/config.php';
}

/**
 * Récupère la liste de tous les modèles de réponse.
 */
function canned_list() {
    requireAuth('admin');
    $db = Database::getInstance()->getConnection();

    // ⭐ SÉCURITÉ : Utiliser une requête préparée même pour les requêtes statiques
    $stmt = $db->prepare("SELECT id, title_encrypted, content_encrypted FROM canned_responses ORDER BY created_at DESC");
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        jsonResponse(false, 'Erreur lors de la récupération des modèles.');
    }

    $responses = [];
    while ($row = $result->fetch_assoc()) {
        $responses[] = [
            'id' => (int)$row['id'],
            'title' => decrypt($row['title_encrypted']),
            'content' => decrypt($row['content_encrypted'])
        ];
    }

    jsonResponse(true, 'Modèles récupérés', ['responses' => $responses]);
}

/**
 * Crée un nouveau modèle de réponse.
 */
function canned_create() {
    requireAuth('admin');
    $input = getInput();
    
    // ⭐ SÉCURITÉ RENFORCÉE : Validation stricte côté serveur
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    
    if (empty($title) || empty($content)) {
        jsonResponse(false, 'Le titre et le contenu sont requis.');
    }
    
    // ⭐ AMÉLIORATION SÉCURITÉ : Valider la longueur des champs (min et max) - déjà fait mais on s'assure que c'est complet
    if (strlen($title) < 3) {
        jsonResponse(false, 'Le titre doit contenir au moins 3 caractères.');
    }
    if (strlen($title) > 255) {
        jsonResponse(false, 'Le titre ne peut pas dépasser 255 caractères.');
    }
    
    if (strlen($content) < 10) {
        jsonResponse(false, 'Le contenu doit contenir au moins 10 caractères.');
    }
    if (strlen($content) > 10000) {
        jsonResponse(false, 'Le contenu ne peut pas dépasser 10000 caractères.');
    }
    
    // ⭐ SÉCURITÉ : Nettoyer les entrées
    $title = sanitizeInput($title);
    $content = sanitizeInput($content);

    $db = Database::getInstance()->getConnection();

    $title_enc = encrypt($title);
    $content_enc = encrypt($content);

    $stmt = $db->prepare("INSERT INTO canned_responses (title_encrypted, content_encrypted) VALUES (?, ?)");
    $stmt->bind_param("ss", $title_enc, $content_enc);

    if ($stmt->execute()) {
        jsonResponse(true, 'Modèle créé avec succès.');
    } else {
        jsonResponse(false, 'Erreur lors de la création du modèle.');
    }
}

/**
 * Supprime un modèle de réponse existant.
 */
function canned_delete() {
    requireAuth('admin');
    $input = getInput();
    
    // ⭐ SÉCURITÉ RENFORCÉE : Validation stricte côté serveur
    $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (!$id) {
        jsonResponse(false, 'ID du modèle invalide.');
    }
    
    // ⭐ SÉCURITÉ : Vérifier que le modèle existe
    $db = Database::getInstance()->getConnection();
    $check_stmt = $db->prepare("SELECT id FROM canned_responses WHERE id = ?");
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows === 0) {
        jsonResponse(false, 'Modèle non trouvé.');
    }

    // La connexion DB est déjà établie ci-dessus
    $stmt = $db->prepare("DELETE FROM canned_responses WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        jsonResponse(true, 'Modèle supprimé avec succès.');
    } else {
        jsonResponse(false, 'Erreur lors de la suppression du modèle.');
    }
}