<?php
/**
 * @file config.php
 * @brief Fichier de configuration principal.
 */

// ACTIVATION DU DEBUGGING (A SUPPRIMER EN PROD)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die("Erreur critique : Le dossier 'vendor' est manquant. Veuillez exécuter 'composer install'.");
}

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Chargement des variables d'environnement
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Définition des constantes
define('DB_HOST', $_ENV['DB_HOST']);
define('DB_USER', $_ENV['DB_USER']);
define('DB_PASS', $_ENV['DB_PASS']);
define('DB_NAME', $_ENV['DB_NAME']);
define('ENCRYPTION_KEY', $_ENV['ENCRYPTION_KEY']);
define('APP_URL_BASE', $_ENV['APP_URL_BASE'] ?? 'http://localhost/support_tickets');

// Constantes de sécurité
define('LOGIN_ATTEMPT_WINDOW_MINUTES', 15);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME_MINUTES', 15);
define('SESSION_NAME', 'support_ticket_session');

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        $this->connection = mysqli_init();
        
        // Configuration SSL si les certificats sont définis
        if (isset($_ENV['DB_SSL_KEY']) && !empty($_ENV['DB_SSL_KEY'])) {
            $this->connection->ssl_set(
                $_ENV['DB_SSL_KEY'],
                $_ENV['DB_SSL_CERT'],
                $_ENV['DB_SSL_CA'],
                null,
                null
            );
        }

        if (!$this->connection->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME)) {
            die("Erreur de connexion : " . $this->connection->connect_error);
        }
        
        $this->connection->set_charset("utf8mb4");
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }
}

class Log {
    private static $logger;

    public static function getLogger() {
        if (!self::$logger) {
            self::$logger = new Monolog\Logger('support');
            self::$logger->pushHandler(new Monolog\Handler\StreamHandler(__DIR__ . '/app.log', Monolog\Logger::WARNING));
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
            $stmt->bind_param("isiss", $admin_id, $action, $target_id, $details_json, $ip_address);
            $stmt->execute();
        } else {
            Log::getLogger()->error("Impossible de préparer la requête d'audit", ['error' => $db->error]);
        }
    } catch (Throwable $e) {
        error_log("Audit Log Error: " . $e->getMessage());
    }
}

function checkRateLimit($action, $limit, $window_seconds) {
    $db = Database::getInstance()->getConnection();
    $ip_address = getIpAddress();
    $window_seconds = (int)$window_seconds;
    
    // Nettoyage des anciennes entrées
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

function setSecurityHeaders() {
    header("X-XSS-Protection: 1; mode=block");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self';");
}

function getInput() {
    $contentType = $_SERVER["CONTENT_TYPE"] ?? '';
    if (strpos($contentType, "application/json") !== false) {
        return json_decode(file_get_contents("php://input"), true) ?? [];
    }
    return $_POST;
}

function jsonResponse($success, $message, $data = []) {
    setJsonHeaders();
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

function requireAuth($role = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
        jsonResponse(false, 'Authentification requise');
    }

    if ($role === 'admin' && !isset($_SESSION['admin_id'])) {
        jsonResponse(false, 'Authentification admin requise');
    }
    
    if ($role === 'user' && !isset($_SESSION['user_id'])) {
        jsonResponse(false, 'Authentification utilisateur requise');
    }
}

function initialize_session() {
    if (session_status() === PHP_SESSION_NONE) {
        // Paramètres de sécurité de session
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
        
        session_name(SESSION_NAME);
        session_start();
    }
    
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}