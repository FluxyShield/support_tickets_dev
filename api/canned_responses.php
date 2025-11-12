<?php
if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé');
}

/**
 * Récupère la liste de tous les modèles de réponse.
 */
function canned_list() {
    requireAuth('admin');
    $db = Database::getInstance()->getConnection();

    $result = $db->query("SELECT id, title_encrypted, content_encrypted FROM canned_responses ORDER BY created_at DESC");

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
    $title = sanitizeInput(trim($input['title'] ?? ''));
    $content = sanitizeInput(trim($input['content'] ?? ''));

    if (empty($title) || empty($content)) {
        jsonResponse(false, 'Le titre et le contenu sont requis.');
    }

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
    $id = (int)($input['id'] ?? 0);

    if (empty($id)) {
        jsonResponse(false, 'ID du modèle requis.');
    }

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("DELETE FROM canned_responses WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        jsonResponse(true, 'Modèle supprimé avec succès.');
    } else {
        jsonResponse(false, 'Erreur lors de la suppression du modèle.');
    }
}