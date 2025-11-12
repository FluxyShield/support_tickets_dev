<?php
// ⭐ CORRECTION SÉCURITÉ : Définir ROOT_PATH avant d'inclure config.php
// pour éviter l'erreur "Accès direct non autorisé".
define('ROOT_PATH', __DIR__);

require_once 'config.php'; // Maintenant, l'inclusion est sécurisée.

/**
 * ===================================================================
 * API PRINCIPALE (api.php)
 * ===================================================================
 * ⭐ MIS À JOUR :
 * - Suppression de la fonction 'ticket_delete_all'.
 * - Correction du bug des filtres (les tickets "Ouvert" n'apparaissaient pas)
 * en remplaçant bind_param(...) par call_user_func_array.
 * - Correction faille de sécurité (Injection SQL sur notifications)
 * - ⭐ AJOUT DE LA FONCTION adminMessageCreate()
 * ===================================================================
 */

if (defined('DEBUG_MODE') && DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/error.log'); // Crée un fichier error.log
}

// ⭐ SOLUTION : Démarrer la bonne session en fonction de l'action
$action_temp = $_GET['action'] ?? $_POST['action'] ?? '';

// Actions qui nécessitent la session admin
$admin_session_actions = [
    'admin_login', 'admin_invite', 'admin_register_complete', 'get_stats', 'get_ticket_details',
    'message_read', 'canned_list', 'canned_create', 'canned_delete', 'get_admins',
    'assign_ticket', 'unassign_ticket', 'get_app_settings', 'update_app_settings', 'get_advanced_stats', 'ticket_list',
    'ticket_update', 'ticket_delete'
];

if (in_array($action_temp, $admin_session_actions) || (isset($_SESSION['admin_id']) && !isset($_SESSION['user_id']))) {
    session_name('admin_session');
} else {
    session_name('user_session');
}

// Maintenant que le nom est défini, on peut démarrer la session.
initialize_session();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ⭐ CORRECTION SÉCURITÉ : Logique de vérification CSRF clarifiée et corrigée.
    // Actions POST publiques qui n'ont PAS besoin de vérification CSRF car elles sont anonymes.
    $public_post_actions = ['login', 'admin_login', 'register', 'admin_register_complete', 'request_password_reset', 'perform_password_reset'];

    // On vérifie le jeton pour TOUTES les requêtes POST, SAUF celles listées ci-dessus et l'upload de fichiers.
    if (!in_array($action, $public_post_actions) && $action !== 'ticket_upload_file') {
        checkCsrfToken();
    }
}

setJsonHeaders();

// ⭐ CORRECTION ROUTAGE : Ajout des actions de profil manquantes.
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

// ==========================================
// ROUTER PRINCIPAL
// ==========================================

// ⭐ AMÉLIORATION ORGANISATION : Routeur intelligent
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

// Si l'action est valide et que le fichier a été inclus, la fonction existe.
if (function_exists($action)) {
    $action(); // Appelle dynamiquement la fonction (ex: login())
} else {
    jsonResponse(false, 'Action non reconnue : ' . htmlspecialchars($action));
}

// ==========================================
// FONCTIONS UTILITAIRES
// ==========================================
function jsonResponse($success, $message, $data = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));
    exit;
}
function requireAuth($role = null) {
    if ($role === 'admin') {
        if (!isset($_SESSION['admin_id'])) {
            jsonResponse(false, 'Authentification admin requise');
        }
    } else {
        if (!isset($_SESSION['user_id'])) {
            jsonResponse(false, 'Authentification requise');
        }
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
        return; // Le jeton est valide
    }

    // Si on arrive ici, le jeton est manquant ou invalide
    jsonResponse(false, 'Jeton de sécurité invalide ou expiré. Veuillez rafraîchir la page.');
}

?>