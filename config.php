<?php
/**
 * Configuration du Support Ticket System
 * ⭐ Version corrigée : compatibilité complète Office 365 / PHPMailer
 */

if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé.');
}
function initialize_session() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_samesite', 'Strict');
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Charger Composer
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Charger les variables d'environnement
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Configuration BDD
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'support_tickets');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', 'utf8mb4');

if (empty($_ENV['ENCRYPTION_KEY'])) {
    throw new Exception("ENCRYPTION_KEY manquante dans le fichier .env !");
}
define('ENCRYPTION_KEY', $_ENV['ENCRYPTION_KEY']);

define('APP_URL_BASE', $_ENV['APP_URL'] ?? 'http://localhost/support_tickets');

// Chargement dynamique des paramètres appli
if (!defined('INSTALLING')) {
    try {
        $db_conn = Database::getInstance()->getConnection();
        $result = $db_conn->query("SELECT setting_key, setting_value FROM settings");
        $app_settings = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $app_settings[$row['setting_key']] = $row['setting_value'];
            }
        }

        define('APP_NAME', $app_settings['app_name'] ?? 'Support Descamps');
        define('APP_PRIMARY_COLOR', $app_settings['app_primary_color'] ?? '#EF8000');
        $logo_filename = $app_settings['app_logo_url'] ?? 'logo.png';
        define('APP_LOGO_URL', APP_URL_BASE . '/assets/' . $logo_filename);
    } catch (Exception $e) {
        define('APP_NAME', 'Support Descamps');
        define('APP_LOGO_URL', APP_URL_BASE . '/assets/logo.png');
    }
} else {
    define('APP_NAME', 'Support Descamps');
    define('APP_LOGO_URL', APP_URL_BASE . '/assets/logo.png');
}

// Paramètres généraux
define('ATTACHMENT_LIFETIME_DAYS', 90);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_ATTEMPT_WINDOW_MINUTES', 15);
define('LOGIN_LOCKOUT_TIME_MINUTES', 30);

// ====================
// CLASSE DATABASE
// ====================
class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $this->connection = mysqli_init();
            if (!$this->connection) {
                throw new Exception("mysqli_init a échoué");
            }

            if (!empty($_ENV['DB_SSL']) && $_ENV['DB_SSL'] === 'true') {
                $ssl_key = $_ENV['DB_SSL_KEY'] ?? '';
                $ssl_cert = $_ENV['DB_SSL_CERT'] ?? '';
                $ssl_ca = $_ENV['DB_SSL_CA'] ?? '';

                if (!file_exists($ssl_key) || !file_exists($ssl_cert) || !file_exists($ssl_ca)) {
                    throw new Exception("Certificats SSL manquants ou invalides");
                }

                $this->connection->ssl_set($ssl_key, $ssl_cert, $ssl_ca, NULL, NULL);
                $flags = MYSQLI_CLIENT_SSL;
                if (isset($_ENV['DB_SSL_VERIFY_SERVER_CERT']) && $_ENV['DB_SSL_VERIFY_SERVER_CERT'] === 'false') {
                    $flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
                }

                $connected = $this->connection->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, 3306, NULL, $flags);
            } else {
                $connected = $this->connection->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            }

            if (!$connected || $this->connection->connect_error) {
                throw new Exception("Erreur MySQL : " . $this->connection->connect_error);
            }

            $this->connection->set_charset(DB_CHARSET);

        } catch (Exception $e) {
            throw new Exception("Erreur de connexion BDD : " . $e->getMessage());
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

    private function __clone() {}
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

// ====================
// FONCTIONS GÉNÉRALES
// ====================
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
        $cipher = "aes-256-cbc";
        $c = base64_decode($data);
        $ivlen = openssl_cipher_iv_length($cipher);
        $iv = substr($c, 0, $ivlen);
        $hmac = substr($c, $ivlen, 32);
        $ciphertext = substr($c, $ivlen + 32);
        $plaintext = openssl_decrypt($ciphertext, $cipher, hex2bin(ENCRYPTION_KEY), OPENSSL_RAW_DATA, $iv);
        $calcmac = hash_hmac('sha256', $ciphertext, hex2bin(ENCRYPTION_KEY), true);
        return hash_equals($hmac, $calcmac) ? $plaintext : false;
    } catch (Exception $e) {
        return false;
    }
}

function hashData($data) {
    return hash('sha256', $data);
}

function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function setJsonHeaders() {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    setSecurityHeaders();
}

// ====================
// ✅ NOUVELLE FONCTION D'ENVOI D'EMAIL (Office 365 compatible)
// ====================
function sendEmail($to, $subject, $body, $altBody = '') {
    if (empty($_ENV['MAIL_HOST'])) {
        error_log("Email non envoyé : MAIL_HOST n'est pas configuré.");
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // --- Configuration Office 365 ---
        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST'];            // smtp.office365.com
        $mail->SMTPAuth   = true;                          // ✅ Auth obligatoire
        $mail->Username   = $_ENV['MAIL_USERNAME'];        // ex: mail@descamps-bois.fr
        $mail->Password   = $_ENV['MAIL_PASSWORD'];        // mot de passe ou app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;// ✅ TLS
        $mail->Port       = 587;                           // ✅ Port standard
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPDebug  = 0; // passe à 2 pour debug détaillé
        $mail->Debugoutput = 'error_log';

        // --- Expéditeur & Destinataires ---
        $mail->setFrom($_ENV['MAIL_FROM_EMAIL'] ?? $_ENV['MAIL_USERNAME'], $_ENV['MAIL_FROM_NAME'] ?? 'Support Descamps');
        $mail->addAddress($to);

        // --- Contenu HTML ---
        $fullBody = "
        <html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f9f9f9; padding: 20px;'>
            <div style='max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 20px; border: 1px solid #ddd; border-radius: 12px;'>
                <div style='text-align: center; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee;'>
                    <img src='" . APP_LOGO_URL . "' alt='" . APP_NAME . " Logo' style='max-width: 150px; height: auto;'>
                </div>
                " . $body . "
                <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: center; font-size: 12px; color: #888;'>
                    <p>Ceci est un message automatique, merci de ne pas y répondre.</p>
                </div>
            </div>
        </body></html>";

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $fullBody;
        $mail->AltBody = $altBody ?: strip_tags($fullBody);

        $mail->send();
        error_log("✅ Email envoyé avec succès à $to via Office365");
        return true;

    } catch (Exception $e) {
        error_log("❌ Erreur PHPMailer : " . $mail->ErrorInfo);
        return false;
    }
}

function sanitizeInput($data) {
    return htmlspecialchars(stripslashes(trim($data)), ENT_QUOTES, 'UTF-8');
}

function getIpAddress() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) $ip = $_SERVER['HTTP_CLIENT_IP'];
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    else $ip = $_SERVER['REMOTE_ADDR'];
    return filter_var($ip, FILTER_VALIDATE_IP);
}
?>