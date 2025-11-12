<?php
/**
 * ===================================================================
 * API - Logique des Avis (api/reviews.php)
 * ===================================================================
 * Contient toutes les fonctions liées à la gestion des avis sur les tickets.
 * ===================================================================
 */

if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé');
}

// Vérifier que config.php est bien chargé
if (!function_exists('sendEmail')) {
    require_once ROOT_PATH . '/config.php';
}

// ==========================================
// GESTION DES AVIS
// ==========================================
function ticketAddReview() {
    requireAuth('user');
    $input = getInput();
    $ticket_id = (int)($input['ticket_id'] ?? 0);
    $rating = (int)($input['rating'] ?? 0);
    $comment = sanitizeInput(trim($input['comment'] ?? ''));
    $user_id = $_SESSION['user_id'];
    if (empty($ticket_id)) {
        jsonResponse(false, 'ID de ticket manquant');
    }
    if (empty($rating) || $rating < 1 || $rating > 5) {
        jsonResponse(false, 'La note doit être entre 1 et 5');
    }
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT id FROM tickets WHERE id = ? AND user_id = ? AND status = 'Fermé'");
    $stmt->bind_param("ii", $ticket_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        jsonResponse(false, 'Ticket non trouvé ou non fermé');
    }
    $stmt = $db->prepare("SELECT id FROM ticket_reviews WHERE ticket_id = ?");
    $stmt->bind_param("i", $ticket_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        jsonResponse(false, 'Vous avez déjà noté ce ticket');
    }
    $comment_enc = encrypt($comment);
    $stmt = $db->prepare("INSERT INTO ticket_reviews (ticket_id, rating, comment_encrypted) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $ticket_id, $rating, $comment_enc);
    if ($stmt->execute()) {
        jsonResponse(true, 'Avis enregistré avec succès');
    }
    jsonResponse(false, 'Erreur lors de l\'enregistrement de l\'avis');
}