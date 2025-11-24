<?php
/**
 * @file api.php
 * @brief Point d'entrée principal et routeur pour toutes les requêtes API.
 *
 * Ce script agit comme un contrôleur frontal pour l'API de l'application.
 * Il est responsable de :
 * - L'initialisation de la configuration et de l'environnement.
 * - La gestion des erreurs et du logging en fonction du mode (debug/production).
 * - La sélection du type de session (utilisateur ou admin) en fonction de l'action demandée.
 * - La validation des jetons CSRF pour les requêtes POST sécurisées.
 * - Le routage de la requête vers le fichier de traitement approprié (auth, tickets, messages, etc.)
 *   en se basant sur le paramètre 'action'.
 * - La fourniture de fonctions utilitaires pour la réponse JSON et la gestion de l'authentification.
 */
define('ROOT_PATH', __DIR__);
require_once 'config.php';

if (defined('DEBUG_MODE') && DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/error.log');
}

$action_temp = $_GET['action'] ?? $_POST['action'] ?? '';

$admin_actions_list = [
    'admin_login', 'admin_invite', 'admin_register_complete', 'get_stats',
    'message_read', 'canned_list', 'canned_create', 'canned_delete',
    'get_admins', 'assign_ticket', 'unassign_ticket',
    'get_app_settings', 'update_app_settings', 'get_advanced_stats', 'ticket_list',
    'ticket_update', 'ticket_delete', 'message_create'
];

if (in_array($action_temp, $admin_actions_list)) {
    session_name('admin_session');
} else {
    session_name('user_session');
}
initialize_session();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $public_post_actions = ['login', 'admin_login', 'register', 'admin_register_complete', 'request_password_reset', 'perform_password_reset'];

    // ⭐ SÉCURITÉ : Vérifier CSRF pour toutes les actions POST sauf les authentifications publiques
    // Les uploads de fichiers nécessitent aussi une protection CSRF
    if (!in_array($action, $public_post_actions)) {
        checkCsrfToken();
    }
}

setJsonHeaders();

$auth_actions = ['register', 'login', 'logout', 'admin_invite', 'admin_register_complete', 'admin_login', 'request_password_reset', 'perform_password_reset'];
$ticket_actions = ['ticket_create', 'ticket_list', 'ticket_update', 'ticket_delete', 'ticket_update_description', 'ticket_reopen', 'get_stats', 'get_ticket_details'];
$message_actions = ['message_create', 'message_read', 'message_read_by_user'];
$file_actions = ['ticket_upload_file', 'ticket_download_file', 'ticket_delete_file'];
$review_actions = ['ticket_add_review'];
$notification_actions = ['notifications', 'notifications_read_all'];
$canned_actions = ['canned_list', 'canned_create', 'canned_delete'];
$admin_actions = ['get_admins', 'assign_ticket', 'unassign_ticket'];
$settings_actions = ['get_app_settings', 'update_app_settings'];
$stats_actions = ['get_advanced_stats'];

// Routeur principal
if (in_array($action, $auth_actions)) {
    require_once ROOT_PATH . '/api/auth.php';
} elseif (in_array($action, $ticket_actions)) {
    require_once ROOT_PATH . '/api/tickets.php';
} elseif (in_array($action, $message_actions)) {
    require_once ROOT_PATH . '/api/messages.php';
} elseif (in_array($action, $file_actions)) {
    require_once ROOT_PATH . '/api/files.php';
} elseif (in_array($action, $review_actions)) {
    require_once ROOT_PATH . '/api/reviews.php';
} elseif (in_array($action, $notification_actions)) {
    require_once ROOT_PATH . '/api/notifications.php';
} elseif (in_array($action, $canned_actions)) {
    require_once ROOT_PATH . '/api/canned_responses.php';
} elseif (in_array($action, $admin_actions)) {
    require_once ROOT_PATH . '/api/admins.php';
} elseif (in_array($action, $settings_actions)) {
    require_once ROOT_PATH . '/api/settings.php';
} elseif (in_array($action, $stats_actions)) {
    require_once ROOT_PATH . '/api/stats.php';
}

if (function_exists($action)) {
    $action();
} else {
    jsonResponse(false, 'Action non reconnue : ' . htmlspecialchars($action));
}

function jsonResponse($success, $message, $data = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));
    exit;
}
function requireAuth($role = null) {
    // Si un rôle spécifique est requis
    if ($role === 'admin') {
        if (!isset($_SESSION['admin_id'])) {
            jsonResponse(false, 'Authentification administrateur requise.');
        }
        return; // Succès
    }

    if ($role === 'user') {
        if (!isset($_SESSION['user_id'])) {
            jsonResponse(false, 'Authentification utilisateur requise.');
        }
        return; // Succès
    }

    // Si aucun rôle n'est spécifié, on vérifie si un utilisateur OU un admin est connecté
    if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
        jsonResponse(false, 'Authentification requise.');
    }
}
function getInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    return $input ?: $_POST;
}
function checkCsrfToken() {
    $headers = getallheaders();
    $tokenFromHeader = $headers['X-CSRF-TOKEN'] ?? null;
    $tokenFromInput = getInput()['csrf_token'] ?? null;

    if (
        !empty($_SESSION['csrf_token']) &&
        ($tokenFromHeader === $_SESSION['csrf_token'] || $tokenFromInput === $_SESSION['csrf_token'])
    ) {
        return;
    }
    jsonResponse(false, 'Jeton de sécurité invalide ou expiré. Veuillez rafraîchir la page.');
}

?>