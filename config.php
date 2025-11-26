<?php
/**
 * @file config.php
 * @brief Fichier de configuration principal de l'application.
 */

if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé.');
}

function initialize_session() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_samesite', 'Lax'); 
        ini_set('session.gc_maxlifetime', 1800); // 30 minutes
        session_start();
    }

    // Gestion de l'expiration de session
    $session_timeout = 1800; 
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    $_SESSION['last_activity'] = time();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'support_tickets');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', 'utf8mb4');

define('ENCRYPTION_KEY', $_ENV['ENCRYPTION_KEY'] ?? '');
define('APP_URL_BASE', $_ENV['APP_URL'] ?? 'http://localhost/support_tickets');

define('ATTACHMENT_LIFETIME_DAYS', 90);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_ATTEMPT_WINDOW_MINUTES', 15);
define('LOGIN_LOCKOUT_TIME_MINUTES', 30);

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

function loadAppSettings() {
    if (defined('APP_NAME')) return;

    try {
        $db_conn = Database::getInstance()->getConnection();
        $result = $db_conn->query("SELECT setting_key, setting_value FROM settings");
        $_SESSION['app_settings'] = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $_SESSION['app_settings'][$row['setting_key']] = $row['setting_value'];
            }
        }
        define('APP_NAME', $_SESSION['app_settings']['app_name'] ?? 'Support Descamps');
        define('APP_LOGO_URL', APP_URL_BASE . '/assets/' . ($_SESSION['app_settings']['app_logo_url'] ?? 'logo.png'));
    } catch (Exception $e) {
        define('APP_NAME', 'Support Descamps');
        define('APP_LOGO_URL', APP_URL_BASE . '/assets/logo.png');
    }
}

class Log {
    private static $logger;

    public static function getLogger() {
        if (!self::$logger) {
            // ⭐ CORRECTION : Création automatique du dossier logs s'il n'existe pas
            $logDir = __DIR__ . '/logs';
            if (!is_dir($logDir)) {
                if (!mkdir($logDir, 0755, true)) {
                    // Si on ne peut pas créer le dossier, on retourne un logger null silencieux ou on gère l'erreur
                    // Pour éviter le crash complet, on utilise error_log natif en fallback temporaire
                    error_log("Impossible de créer le dossier logs dans " . $logDir);
                }
            }

            self::$logger = new Logger('app');
            // Vérification que le dossier est inscriptible
            if (is_dir($logDir) && is_writable($logDir)) {
                self::$logger->pushHandler(new StreamHandler($logDir . '/critical.log', Logger::CRITICAL));
                self::$logger->pushHandler(new StreamHandler($logDir . '/app.log', Logger::INFO));
            }
        }
        return self::$logger;
    }
}

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
    header_remove('X-Powered-By');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    $csp = "default-src 'self'; ";
    $csp .= "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net/npm/apexcharts; "; // Ajout 'unsafe-inline' si nécessaire pour certains scripts inlines
    $csp .= "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; ";
    $csp .= "font-src 'self' https://fonts.gstatic.com; ";
    $csp .= "img-src 'self' data:; ";
    $csp .= "object-src 'none'; ";
    $csp .= "frame-ancestors 'none'; ";
    $csp .= "form-action 'self'; ";
    $csp .= "base-uri 'self'; ";

    header("Content-Security-Policy: " . $csp);
}

function setJsonHeaders() {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    setSecurityHeaders();
}

function sendEmail($to, $subject, $body, $altBody = '') {
    if (empty($_ENV['MAIL_HOST'])) {
        error_log("Email non envoyé : MAIL_HOST n'est pas configuré.");
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USERNAME'];
        $mail->Password   = $_ENV['MAIL_PASSWORD'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPDebug  = 0;
        $mail->Debugoutput = 'error_log';

        $mail->setFrom($_ENV['MAIL_FROM_EMAIL'] ?? $_ENV['MAIL_USERNAME'], $_ENV['MAIL_FROM_NAME'] ?? 'Support Descamps');
        $mail->addAddress($to);

        $logoPath = __DIR__ . '/assets/logo.png';
        if (file_exists($logoPath)) {
            $mail->addEmbeddedImage($logoPath, 'logoimg');
        }

        $fullBody = "
        <html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f9f9f9; padding: 20px;'>
            <div style='max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 20px; border: 1px solid #ddd; border-radius: 12px;'>
                
                <div style='text-align: center; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee;'>
                    <img src='cid:logoimg' alt='" . APP_NAME . " Logo' width='150' style='max-width: 150px; height: auto;'>
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

function checkRateLimit($action, $max_attempts, $window_seconds) {
    try {
        $ip_address = getIpAddress();
        if (!$ip_address) {
            $ip_address = '0.0.0.0';
        }
        
        $db = Database::getInstance()->getConnection();
        
        // Utilisation de DATE_SUB pour compatibilité SQL standard
        $cleanup_stmt = $db->prepare("DELETE FROM rate_limits WHERE action = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $cleanup_stmt->bind_param("si", $action, $window_seconds);
        $cleanup_stmt->execute();
        
        $check_stmt = $db->prepare("SELECT COUNT(*) as count FROM rate_limits WHERE action = ? AND ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $check_stmt->bind_param("ssi", $action, $ip_address, $window_seconds);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $count = $result->fetch_assoc()['count'];
        
        if ($count >= $max_attempts) {
            $remaining_seconds = $window_seconds;
            $remaining_minutes = ceil($remaining_seconds / 60);
            throw new Exception("Trop de tentatives. Veuillez réessayer dans {$remaining_minutes} minute(s).");
        }
        
        $insert_stmt = $db->prepare("INSERT INTO rate_limits (action, ip_address, created_at) VALUES (?, ?, NOW())");
        $insert_stmt->bind_param("ss", $action, $ip_address);
        $insert_stmt->execute();
        
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Trop de tentatives') !== false) {
            jsonResponse(false, $e->getMessage());
        }
        Log::getLogger()->error('Erreur rate limiting', ['action' => $action, 'error' => $e->getMessage()]);
    }
}

function logAuditEvent($action, $target_id = null, $details = []) {
    try {
        $admin_id = $_SESSION['admin_id'] ?? null;
        $ip_address = getIpAddress();
        
        $db = Database::getInstance()->getConnection();
        
        $action = substr(trim($action), 0, 255);
        if (empty($action)) {
            return;
        }
        
        if ($target_id !== null) {
            $target_id = filter_var($target_id, FILTER_VALIDATE_INT);
            if ($target_id === false) {
                $target_id = null;
            }
        }
        
        if ($admin_id !== null) {
            $admin_id = filter_var($admin_id, FILTER_VALIDATE_INT);
            if ($admin_id === false) {
                $admin_id = null;
            }
        }
        
        $details_json = !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;
        
        $stmt = $db->prepare("INSERT INTO audit_log (admin_id, action, target_id, details, ip_address, timestamp) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("isis", $admin_id, $action, $target_id, $details_json, $ip_address);
        $stmt->execute();
        
    } catch (Exception $e) {
        Log::getLogger()->error('Erreur audit log', ['action' => $action, 'error' => $e->getMessage()]);
    }
}
?>