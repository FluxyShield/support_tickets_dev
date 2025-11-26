<?php
/**
 * @file config.php
 * @brief Fichier de configuration principal.
 */

if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé.');
}

// Gestion globale des erreurs fatales (pour éviter les 500 silencieuses)
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        // Si une erreur fatale survient, on renvoie du JSON propre
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'message' => 'Erreur critique serveur : ' . $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
        exit;
    }
});

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

function logAuditEvent($action, $target_id = null, $details = null) {
    try {
        $db = Database::getInstance()->getConnection();
        $admin_id = $_SESSION['admin_id'] ?? null;
        $ip_address = getIpAddress();
        $details_json = $details ? json_encode($details) : null;

        $stmt = $db->prepare("INSERT INTO audit_log (admin_id, action, target_id, details, ip_address) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            // "isiss" : integer, string, integer, string, string
            $stmt->bind_param("isiss", $admin_id, $action, $target_id, $details_json, $ip_address);
            $stmt->execute();
        } else {
            // Si la table n'existe pas ou erreur SQL, on log dans le fichier mais on ne plante pas
            Log::getLogger()->error("Impossible de préparer la requête d'audit", ['error' => $db->error]);
        }
    } catch (Throwable $e) {
        // Fail-safe complet pour l'audit
        error_log("Audit Log Error: " . $e->getMessage());
    }
}

function checkRateLimit($action, $limit, $window_seconds) {
    $db = Database::getInstance()->getConnection();
    $ip_address = getIpAddress();
    $window_seconds = (int)$window_seconds;
    
    // Nettoyage des anciennes entrées (pourrait être fait via un cron, mais ici on le fait à la volée)
    $db->query("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL $window_seconds SECOND)");
    
    // Vérification du nombre de tentatives
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM rate_limits WHERE action = ? AND ip_address = ?");
    $stmt->bind_param("ss", $action, $ip_address);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['count'];
    
    if ($count >= $limit) {
        jsonResponse(false, "Trop de tentatives. Veuillez réessayer plus tard.");
    }
    
    // Enregistrement de la tentative
    $stmt = $db->prepare("INSERT INTO rate_limits (action, ip_address) VALUES (?, ?)");
    $stmt->bind_param("ss", $action, $ip_address);
    $stmt->execute();
}

function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        // Configuration SMTP
        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST'] ?? 'smtp.example.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USERNAME'] ?? 'user@example.com';
        $mail->Password   = $_ENV['MAIL_PASSWORD'] ?? 'secret';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        // Destinataires
        $mail->setFrom($_ENV['MAIL_FROM_EMAIL'] ?? 'noreply@example.com', $_ENV['APP_NAME'] ?? 'Support');
        $mail->addAddress($to);

        // Contenu
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        Log::getLogger()->error("Erreur envoi email: {$mail->ErrorInfo}");
        return false;
    }
}

function setJsonHeaders() {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200); // Force 200 OK par défaut
}
?>