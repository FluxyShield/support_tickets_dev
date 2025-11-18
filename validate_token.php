<?php
/**
 * ===================================================================
 * Script de Validation de Jeton (validate_token.php)
 * ===================================================================
 * Ce script vérifie la validité d'un jeton d'invitation admin.
 * Il est appelé par la page admin_register.html au chargement.
 * ===================================================================
 */

require_once 'config.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';

if (empty($token)) {
    echo json_encode(['valid' => false]);
    exit;
}

// ⭐ SÉCURITÉ RENFORCÉE : Valider le format du jeton avant de requêter la BDD.
// Nos jetons font 64 caractères hexadécimaux.
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    echo json_encode(['valid' => false]);
    exit;
}

$db = Database::getInstance()->getConnection();

$token_hash = hash('sha256', $token);

$stmt = $db->prepare("SELECT id FROM admin_invitations WHERE token_hash = ? AND expires_at > NOW()");
$stmt->bind_param("s", $token_hash);
$stmt->execute();
$result = $stmt->get_result();

$isValid = $result->num_rows > 0;

echo json_encode(['valid' => $isValid]);