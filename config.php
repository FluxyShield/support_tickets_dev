<?php
/**
 * Configuration du Support Ticket System
 * ⭐ MIS À JOUR : Logique de session et CSRF centralisée
 */

// ⭐ AMÉLIORATION SÉCURITÉ : Empêcher l'accès direct au fichier de configuration.
if (!defined('ROOT_PATH')) {
    // Si ROOT_PATH n'est pas défini (par api.php, index.php, etc.), on arrête tout.
    die('Accès direct non autorisé.');
}

// Imports de PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Gère la session et le jeton CSRF de manière centralisée.
 * Doit être appelée au tout début de chaque point d'entrée (index.html, admin.html, api.php).
 */
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

// Charger l'autoload de Composer
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Charger le fichier .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Configuration de la base de données
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'support_tickets');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', 'utf8mb4');

// Clé de chiffrement
if (empty($_ENV['ENCRYPTION_KEY'])) {
    throw new Exception("ENCRYPTION_KEY manquante dans le fichier .env !");
}
define('ENCRYPTION_KEY', $_ENV['ENCRYPTION_KEY']);

// Configuration Application
define('APP_URL_BASE', $_ENV['APP_URL'] ?? 'http://localhost/support_tickets');

// ⭐ SOLUTION : Déplacer la définition des constantes avant les fonctions qui les utilisent.
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

        // Définir les constantes dynamiquement avec des valeurs par défaut
        define('APP_NAME', $app_settings['app_name'] ?? 'Support Descamps');
        define('APP_PRIMARY_COLOR', $app_settings['app_primary_color'] ?? '#EF8000');
        
        // Construire l'URL complète du logo
        $logo_filename = $app_settings['app_logo_url'] ?? 'logo.png';
        define('APP_LOGO_URL', APP_URL_BASE . '/assets/' . $logo_filename);

    } catch (Exception $e) {
        // Fallback si la base de données n'est pas prête
        define('APP_NAME', 'Support Descamps');
        define('APP_LOGO_URL', APP_URL_BASE . '/assets/logo.png'); // Définir un fallback
    }
} else {
    // Mode installation : utiliser des valeurs par défaut sans toucher à la BDD
    define('APP_NAME', 'Support Descamps');
    // ⭐ CORRECTION : Définir une URL de logo par défaut pendant l'installation
    define('APP_LOGO_URL', APP_URL_BASE . '/assets/logo.png');
}

// ⭐ NOUVEAU : Configuration de la rétention des fichiers
// Durée en jours après laquelle les pièces jointes des tickets FERMÉS sont supprimées.
define('ATTACHMENT_LIFETIME_DAYS', 90); // 90 jours

// ⭐ NOUVEAU : Configuration de la limitation des tentatives de connexion
define('MAX_LOGIN_ATTEMPTS', 5); // Nombre max de tentatives échouées avant verrouillage
define('LOGIN_ATTEMPT_WINDOW_MINUTES', 15); // Fenêtre de temps pour compter les tentatives (en minutes)
define('LOGIN_LOCKOUT_TIME_MINUTES', 30); // Durée du verrouillage après dépassement (en minutes)

// Classe de connexion à la base de données
class Database {
    private static $instance = null;
    private $connection;
    private function __construct() {
        try {
            // ⭐ AMÉLIORATION SÉCURITÉ : Activer SSL/TLS pour la connexion à la base de données
            $this->connection = mysqli_init();
            if (!$this->connection) {
                throw new Exception("mysqli_init a échoué");
            }

            // Si les variables d'environnement pour SSL sont définies, on configure la connexion sécurisée.
            // C'est crucial pour un environnement de production avec une base de données distante.
            if (!empty($_ENV['DB_SSL_KEY']) && !empty($_ENV['DB_SSL_CERT']) && !empty($_ENV['DB_SSL_CA'])) {
                $this->connection->ssl_set($_ENV['DB_SSL_KEY'], $_ENV['DB_SSL_CERT'], $_ENV['DB_SSL_CA'], NULL, NULL);
                $this->connection->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, null, null, MYSQLI_CLIENT_SSL);
            } else {
                // Connexion standard (pour le développement local)
                $this->connection->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            }

            if ($this->connection->connect_error) {
                throw new Exception("Erreur de connexion : " . $this->connection->connect_error);
            }
            $this->connection->set_charset(DB_CHARSET);
        } catch (Exception $e) {
            throw new Exception("Erreur de connexion à la base de données : " . $e->getMessage());
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

// Fonctions de chiffrement AES-256-CBC
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
        if (hash_equals($hmac, $calcmac)) {
            return $plaintext;
        }
        return false;
    } catch (Exception $e) {
        return false;
    }
}
function hashData($data) {
    return hash('sha256', $data);
}

// Headers de sécurité
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

// ⭐ NOUVELLE FONCTION D'ENVOI D'EMAIL (LA VRAIE)
function sendEmail($to, $subject, $body, $altBody = '') {
    // Vérifier si les infos sont dans le .env
    if (empty($_ENV['MAIL_HOST'])) {
        error_log("Email non envoyé : MAIL_HOST n'est pas configuré dans .env");
        return false;
    }

    $mail = new PHPMailer(true);
    
    try {
        // Paramètres du serveur
        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST'];
        $mail->SMTPAuth   = false;
        $mail->Username   = $_ENV['MAIL_USERNAME'];
        $mail->Password   = $_ENV['MAIL_PASSWORD'];
        $mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'] ?? PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['MAIL_PORT'] ?? 587;
        $mail->CharSet    = 'UTF-8';

        // Destinataires
        $mail->setFrom($_ENV['MAIL_FROM_EMAIL'] ?? 'noreply@localhost.com', $_ENV['MAIL_FROM_NAME'] ?? 'Support Descamps');
        $mail->addAddress($to);

        // ⭐ NOUVEAU : Template d'email centralisé
        $fullBody = "
        <html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f9f9f9; padding: 20px;'>
            <div style='max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 20px; border: 1px solid #ddd; border-radius: 12px;'>
                
                <!-- En-tête avec logo -->
                <div style='text-align: center; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee;'>
                    <img src='" . APP_LOGO_URL . "' alt='" . APP_NAME . " Logo' style='max-width: 150px; height: auto;'>
                </div>

                <!-- Contenu principal de l'email -->
                " . $body . "

                <!-- Pied de page automatique -->
                <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: center; font-size: 12px; color: #888;'>
                    <p>Ceci est un message automatique, merci de ne pas y répondre.</p>
                </div>

            </div>
        </body></html>";

        // Contenu
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $fullBody;
        $mail->AltBody = $altBody ?: strip_tags($fullBody);

        $mail->send();
        error_log("Email envoyé avec succès à $to (via Mailtrap)");
        return true;
    } catch (Exception $e) {
        error_log("Erreur PHPMailer : " . $mail->ErrorInfo);
        return false;
    }
}

// Fonction de validation
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// ⭐ NOUVELLE FONCTION UTILITAIRE : Récupérer l'adresse IP du client
function getIpAddress() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    // Nettoyer l'IP pour éviter les injections ou les formats invalides
    return filter_var($ip, FILTER_VALIDATE_IP);
}

?>