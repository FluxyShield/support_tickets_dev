<?php
/**
 * @file config.php
 * @brief Fichier de configuration principal.
 */

if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé.');
}

// Gestion de session optimisée
function initialize_session() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_samesite', 'Lax'); 
        ini_set('session.gc_maxlifetime', 3600); // 1 heure
        session_start();
    }

    // Régénération périodique pour éviter le vol de session
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 1800) { // 30 min
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Chargement de l'autoloader si présent
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Chargement .env
use Dotenv\Dotenv;
if (class_exists('Dotenv\Dotenv') && file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Constantes BDD
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'support_tickets');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', 'utf8mb4');

define('ENCRYPTION_KEY', $_ENV['ENCRYPTION_KEY'] ?? 'CHANGE_ME_IN_PROD_32_CHARS_MIN!!!');
define('APP_URL_BASE', $_ENV['APP_URL'] ?? 'http://localhost/support_tickets');

define('ATTACHMENT_LIFETIME_DAYS', 90);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_ATTEMPT_WINDOW_MINUTES', 15);
define('LOGIN_LOCKOUT_TIME_MINUTES', 30);

// Classe Database (Singleton)
class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // Active les exceptions MySQL
            $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $this->connection->set_charset(DB_CHARSET);
        } catch (Exception $e) {
            // On ne plante pas ici, on laisse l'appelant gérer l'exception pour renvoyer du JSON
            throw new Exception("Erreur connexion BDD : " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }
}

// Classe Log (Fail-safe)
class Log {
    private static $logger;

    public static function getLogger() {
        if (!self::$logger) {
            try {
                // Tentative de création du dossier logs
                $logDir = __DIR__ . '/logs';
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0755, true);
                }

                // Si Monolog est dispo et dossier inscriptible
                if (class_exists('Monolog\Logger') && is_dir($logDir) && is_writable($logDir)) {
                    self::$logger = new Logger('app');
                    self::$logger->pushHandler(new StreamHandler($logDir . '/app.log', Logger::INFO));
                } else {
                    throw new Exception("Monolog non dispo ou dossier logs non inscriptible");
                }
            } catch (Exception $e) {
                // Fallback : Classe anonyme qui redirige vers error_log natif PHP
                // C'est ce qui empêche le crash 500 si les logs foirent
                self::$logger = new class {
                    public function __call($name, $arguments) {
                        $msg = "[SupportApp Log] " . strtoupper($name) . ": " . ($arguments[0] ?? '');
                        if (isset($arguments[1])) $msg .= ' ' . json_encode($arguments[1]);
                        error_log($msg);
                    }
                };
            }
        }
        return self::$logger;
    }
}

// Fonctions Crypto & Utilitaires
function encrypt($data) {
    if (empty($data)) return '';
    $cipher = "aes-256-cbc";
    $ivlen = openssl_cipher_iv_length($cipher);
    $iv = openssl_random_pseudo_bytes($ivlen);
    $ciphertext = openssl_encrypt($data, $cipher, hex2bin(ENCRYPTION_KEY), OPENSSL_RAW_DATA, $iv);
    $hmac = hash_hmac('sha256', $ciphertext, hex2bin(ENCRYPTION_KEY), true);
    return base64_encode($iv . $hmac . $ciphertext);
}

function decrypt($data) {
    if (empty($data)) return '';
    try {
        $c = base64_decode($data);
        $cipher = "aes-256-cbc";
        $ivlen = openssl_cipher_iv_length($cipher);
        if (strlen($c) < $ivlen + 32) return false;
        $iv = substr($c, 0, $ivlen);
        $hmac = substr($c, $ivlen, 32);
        $ciphertext = substr($c, $ivlen + 32);
        $calcmac = hash_hmac('sha256', $ciphertext, hex2bin(ENCRYPTION_KEY), true);
        if (!hash_equals($hmac, $calcmac)) return false;
        return openssl_decrypt($ciphertext, $cipher, hex2bin(ENCRYPTION_KEY), OPENSSL_RAW_DATA, $iv);
    } catch (Exception $e) {
        return false;
    }
}

function hashData($data) {
    return hash('sha256', $data);
}

function sanitizeInput($data) {
    return htmlspecialchars(stripslashes(trim($data)), ENT_QUOTES, 'UTF-8');
}

function getIpAddress() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function setJsonHeaders() {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200); // Force 200 OK par défaut
}
?>