<?php
/**
 * @file api.php
 * @brief Routeur API sécurisé avec gestion globale des erreurs.
 */
define('ROOT_PATH', __DIR__);
require_once 'config.php';

// Désactiver l'affichage des erreurs HTML pour ne pas casser le JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Démarrage session & Headers
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$admin_actions = [
    'admin_login', 'admin_invite', 'admin_register_complete', 'get_stats',
    'message_read', 'canned_list', 'canned_create', 'canned_delete',
    'get_admins', 'assign_ticket', 'unassign_ticket',
    'get_app_settings', 'update_app_settings', 'get_advanced_stats', 'ticket_list',
    'ticket_update', 'ticket_delete', 'message_create'
];

if (in_array($action, $admin_actions)) {
    session_name('admin_session');
} else {
    session_name('user_session');
}
initialize_session();
setJsonHeaders();

// Fonction de réponse standard
function jsonResponse($success, $message, $data = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));
    exit;
}

// Fonction d'auth simplifiée pour l'API
function requireAuth($role = null) {
    $isAdmin = isset($_SESSION['admin_id']);
    $isUser = isset($_SESSION['user_id']);

    if ($role === 'admin' && !$isAdmin) jsonResponse(false, 'Authentification admin requise');
    if ($role === 'user' && !$isUser) jsonResponse(false, 'Authentification requise');
    if (!$role && !$isAdmin && !$isUser) jsonResponse(false, 'Authentification requise');
}

function getInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    return $input ?: $_POST;
}

// Chargement des fichiers API
require_once ROOT_PATH . '/api/auth.php';
require_once ROOT_PATH . '/api/tickets.php';
require_once ROOT_PATH . '/api/messages.php';
require_once ROOT_PATH . '/api/files.php';
require_once ROOT_PATH . '/api/reviews.php';
require_once ROOT_PATH . '/api/notifications.php';
require_once ROOT_PATH . '/api/canned_responses.php';
require_once ROOT_PATH . '/api/admins.php';
require_once ROOT_PATH . '/api/settings.php';
require_once ROOT_PATH . '/api/stats.php';

// EXÉCUTION SÉCURISÉE
try {
    if (function_exists($action)) {
        $action();
    } else {
        jsonResponse(false, 'Action inconnue: ' . htmlspecialchars($action));
    }
} catch (Throwable $e) {
    // C'est ICI que la magie opère. On capture TOUT (erreurs fatales, exceptions DB, etc.)
    // On log l'erreur réelle pour vous (coté serveur)
    error_log("API CRITICAL ERROR [$action]: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    
    // On renvoie un JSON propre au client pour qu'il ne plante pas
    jsonResponse(false, 'Erreur serveur interne : ' . $e->getMessage());
}
?>