<?php
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__);
}

// Chargement de la configuration
require_once ROOT_PATH . '/config.php';

// Désactiver l'affichage des erreurs HTML pour ne pas casser le JSON
// On le fait APRÈS config.php car config.php peut le réactiver
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Initialisation de la session
initialize_session();

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
require_once ROOT_PATH . '/api/users.php';

// Récupération de l'action
$action = $_GET['action'] ?? '';

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