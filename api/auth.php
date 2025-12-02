<?php
/**
 * ===================================================================
 * API - Logique d'Authentification (api/auth.php)
 * ===================================================================
 * Contient toutes les fonctions liées à la connexion, inscription, etc.
 * ===================================================================
 */

if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé');
}

// Vérifier que config.php est bien chargé
if (!function_exists('sendEmail')) {
    require_once ROOT_PATH . '/config.php';
}

/**
 * Valide la politique de mot de passe.
 * @param string $password Le mot de passe à vérifier.
 * @return bool True si le mot de passe est valide, false sinon.
 */
function validatePasswordPolicy($password) {
    // Au moins 8 caractères
    if (strlen($password) < 8) return false;
    // Au moins une lettre majuscule
    if (!preg_match('/[A-Z]/', $password)) return false;
    // Au moins un chiffre
    if (!preg_match('/[0-9]/', $password)) return false;
    
    return true;
}

/**
 * Connexion Administrateur (utilisée par login.php)
 */
function admin_login() {
    $input = getInput();
    $email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        jsonResponse(false, 'Email et mot de passe requis.');
    }

    $ip_address = getIpAddress();
    $db = Database::getInstance()->getConnection();

    // 1. Vérifier les tentatives de connexion (Brute Force)
    checkLoginAttempts($db, $ip_address, $email);

    // 2. Vérification des identifiants
    $email_hash = hashData($email);
    $stmt = $db->prepare("SELECT * FROM users WHERE email_hash = ? AND role = 'admin'");
    $stmt->bind_param("s", $email_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password_hash'])) {
            // Succès : Regénération de session et initialisation
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_firstname'] = decrypt($user['firstname_encrypted']);
            $_SESSION['admin_lastname'] = decrypt($user['lastname_encrypted']);
            $_SESSION['admin_email'] = decrypt($user['email_encrypted']);
            
            // Nettoyer les tentatives échouées
            $db->query("DELETE FROM login_attempts WHERE ip_address = '$ip_address'");
            
            logAuditEvent('ADMIN_LOGIN_SUCCESS', $user['id']);
            
            jsonResponse(true, 'Connexion réussie', ['user' => [
                'id' => $user['id'],
                'firstname' => $_SESSION['admin_firstname'],
                'lastname' => $_SESSION['admin_lastname'],
                'email' => $_SESSION['admin_email']
            ]]);
        }
    }

    // Échec : Enregistrer la tentative
    recordFailedLogin($db, $ip_address, $email);
    jsonResponse(false, 'Identifiants incorrects ou accès non autorisé.');
}

/**
 * Connexion Utilisateur (utilisée par index.php)
 */
function login() {
    $input = getInput();
    $email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        jsonResponse(false, 'Email et mot de passe requis.');
    }

    $ip_address = getIpAddress();
    $db = Database::getInstance()->getConnection();

    // 1. Vérifier les tentatives
    checkLoginAttempts($db, $ip_address, $email);

    // 2. Vérification des identifiants (tout rôle autorisé à se connecter ici, ou filtrez si besoin)
    $email_hash = hashData($email);
    $stmt = $db->prepare("SELECT * FROM users WHERE email_hash = ?"); 
    $stmt->bind_param("s", $email_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['firstname'] = decrypt($user['firstname_encrypted']);
            $_SESSION['lastname'] = decrypt($user['lastname_encrypted']);
            $_SESSION['email'] = decrypt($user['email_encrypted']);
            
            // Si c'est aussi un admin, on set les variables admin pour faciliter l'accès mixte
            if ($user['role'] === 'admin') {
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_firstname'] = $_SESSION['firstname'];
                $_SESSION['admin_lastname'] = $_SESSION['lastname'];
            }

            $db->query("DELETE FROM login_attempts WHERE ip_address = '$ip_address'");
            
            jsonResponse(true, 'Connexion réussie', ['user' => [
                'id' => $user['id'],
                'firstname' => $_SESSION['firstname'],
                'lastname' => $_SESSION['lastname'],
                'email' => $_SESSION['email'],
                'role' => $user['role']
            ]]);
        }
    }

    recordFailedLogin($db, $ip_address, $email);
    jsonResponse(false, 'Identifiants incorrects.');
}

/**
 * Inscription Utilisateur (utilisée par register.php)
 */
function register() {
    $input = getInput();
    $firstname = sanitizeInput(trim($input['firstname'] ?? ''));
    $lastname = sanitizeInput(trim($input['lastname'] ?? ''));
    $email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $input['password'] ?? '';
    $confirmPassword = $input['confirmPassword'] ?? '';

    if (empty($firstname) || empty($lastname) || empty($email) || empty($password)) {
        jsonResponse(false, 'Tous les champs sont requis.');
    }

    if ($password !== $confirmPassword) {
        jsonResponse(false, 'Les mots de passe ne correspondent pas.');
    }

    if (!validatePasswordPolicy($password)) {
        jsonResponse(false, 'Le mot de passe doit contenir au moins 8 caractères, une majuscule et un chiffre.');
    }

    $db = Database::getInstance()->getConnection();
    $email_hash = hashData($email);

    // Vérifier si l'email existe déjà
    $stmt = $db->prepare("SELECT id FROM users WHERE email_hash = ?");
    $stmt->bind_param("s", $email_hash);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        jsonResponse(false, 'Cet email est déjà utilisé.');
    }

    // Création du compte
    $firstname_enc = encrypt($firstname);
    $lastname_enc = encrypt($lastname);
    $email_enc = encrypt($email);
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT INTO users (firstname_encrypted, lastname_encrypted, email_encrypted, password_hash, email_hash, role) VALUES (?, ?, ?, ?, ?, 'user')");
    $stmt->bind_param("sssss", $firstname_enc, $lastname_enc, $email_enc, $password_hash, $email_hash);

    if ($stmt->execute()) {
        $user_id = $stmt->insert_id;
        
        // Auto-login après inscription
        $_SESSION['user_id'] = $user_id;
        $_SESSION['firstname'] = $firstname;
        $_SESSION['lastname'] = $lastname;
        $_SESSION['email'] = $email;
        
        jsonResponse(true, 'Compte créé avec succès !');
    } else {
        jsonResponse(false, 'Erreur lors de la création du compte.');
    }
}

/**
 * Helpers pour la sécurité des logins (limitation tentatives)
 */
function checkLoginAttempts($db, $ip_address, $email) {
    // Vérifier verrouillage
    $lockout_check = $db->prepare("SELECT locked_until FROM login_attempts WHERE (ip_address = ? OR email_attempted = ?) AND locked_until > NOW() LIMIT 1");
    $lockout_check->bind_param("ss", $ip_address, $email);
    $lockout_check->execute();
    
    if ($lockout_check->get_result()->num_rows > 0) {
        jsonResponse(false, "Trop de tentatives. Accès temporairement bloqué.");
    }

    // Nettoyer vieux logs
    $db->query("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL " . LOGIN_ATTEMPT_WINDOW_MINUTES . " MINUTE) AND locked_until IS NULL");

    // Compter tentatives récentes
    $count_stmt = $db->prepare("SELECT COUNT(*) as count FROM login_attempts WHERE (ip_address = ? OR email_attempted = ?) AND attempt_time > DATE_SUB(NOW(), INTERVAL " . LOGIN_ATTEMPT_WINDOW_MINUTES . " MINUTE)");
    $count_stmt->bind_param("ss", $ip_address, $email);
    $count_stmt->execute();
    $attempts = $count_stmt->get_result()->fetch_assoc()['count'];

    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $lock_stmt = $db->prepare("INSERT INTO login_attempts (ip_address, email_attempted, locked_until) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . LOGIN_LOCKOUT_TIME_MINUTES . " MINUTE))");
        $lock_stmt->bind_param("ss", $ip_address, $email);
        $lock_stmt->execute();
        jsonResponse(false, "Trop de tentatives. Compte bloqué pour " . LOGIN_LOCKOUT_TIME_MINUTES . " minutes.");
    }
}

function recordFailedLogin($db, $ip, $email) {
    $stmt = $db->prepare("INSERT INTO login_attempts (ip_address, email_attempted) VALUES (?, ?)");
    $stmt->bind_param("ss", $ip, $email);
    $stmt->execute();
}

/**
 * Invitation d'un administrateur (API Admin)
 */
function admin_invite() {
    requireAuth('admin');
    $inviting_admin_id = $_SESSION['admin_id'];
    $input = getInput();
    $email = trim($input['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Adresse email invalide.');
    }

    $db = Database::getInstance()->getConnection();
    $email_hash = hashData($email);

    // Vérifier existence compte
    $stmt = $db->prepare("SELECT id FROM users WHERE email_hash = ?");
    $stmt->bind_param("s", $email_hash);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        jsonResponse(false, 'Un compte existe déjà avec cet email.');
    }

    // Supprimer anciennes invitations
    $db->query("DELETE FROM admin_invitations WHERE email_hash = '$email_hash'");

    $token = bin2hex(random_bytes(32));
    $token_hash = hashData($token);
    $expires_at = date('Y-m-d H:i:s', time() + 86400); // 24h

    $email_enc = encrypt($email);
    $stmt = $db->prepare("INSERT INTO admin_invitations (email_encrypted, email_hash, token_hash, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $email_enc, $email_hash, $token_hash, $expires_at);

    if ($stmt->execute()) {
        $invitationLink = APP_URL_BASE . '/admin_register.php?token=' . $token;
        $body = "<p>Bonjour,</p><p>Vous avez été invité à devenir administrateur.</p><p><a href='$invitationLink'>Cliquez ici pour finaliser votre inscription</a></p>";
        
        if (sendEmail($email, 'Invitation Administrateur', $body)) {
            logAuditEvent('ADMIN_INVITE_SENT', $inviting_admin_id, ['email' => $email]);
            jsonResponse(true, 'Invitation envoyée.');
        } else {
            jsonResponse(false, "Erreur lors de l'envoi de l'email.");
        }
    } else {
        jsonResponse(false, "Erreur base de données.");
    }
}

/**
 * Finalisation inscription Admin
 */
function admin_register_complete() {
    $input = getInput();
    $token = $input['token'] ?? '';
    $firstname = sanitizeInput(trim($input['firstname'] ?? ''));
    $lastname = sanitizeInput(trim($input['lastname'] ?? ''));
    $password = $input['password'] ?? '';

    if (strlen($firstname) < 2 || strlen($lastname) < 2) {
        jsonResponse(false, 'Prénom et nom requis.');
    }
    if (!validatePasswordPolicy($password)) {
        jsonResponse(false, 'Mot de passe trop faible.');
    }

    $db = Database::getInstance()->getConnection();
    $token_hash = hashData($token);

    $stmt = $db->prepare("SELECT * FROM admin_invitations WHERE token_hash = ? AND expires_at > NOW()");
    $stmt->bind_param("s", $token_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        jsonResponse(false, 'Jeton invalide ou expiré.');
    }

    $invite = $result->fetch_assoc();
    $email_hash = $invite['email_hash'];
    $email_enc = $invite['email_encrypted'];

    $firstname_enc = encrypt($firstname);
    $lastname_enc = encrypt($lastname);
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT INTO users (firstname_encrypted, lastname_encrypted, email_encrypted, password_hash, email_hash, role) VALUES (?, ?, ?, ?, ?, 'admin')");
    $stmt->bind_param("sssss", $firstname_enc, $lastname_enc, $email_enc, $password_hash, $email_hash);

    if ($stmt->execute()) {
        $db->query("DELETE FROM admin_invitations WHERE email_hash = '$email_hash'");
        logAuditEvent('ADMIN_ACCOUNT_CREATED', $stmt->insert_id);
        jsonResponse(true, 'Compte admin créé.');
    } else {
        jsonResponse(false, 'Erreur lors de la création.');
    }
}

/**
 * Demande réinitialisation mot de passe
 */
function request_password_reset() {
    $input = getInput();
    $email = trim($input['email'] ?? '');
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Email invalide.');
    }

    $db = Database::getInstance()->getConnection();
    $email_hash = hashData($email);

    // Vérifier si l'utilisateur existe
    $stmt = $db->prepare("SELECT id FROM users WHERE email_hash = ?");
    $stmt->bind_param("s", $email_hash);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows > 0) {
        $token = bin2hex(random_bytes(32));
        $token_hash = hashData($token);
        $expires = date('Y-m-d H:i:s', time() + 900); // 15 min

        $stmt = $db->prepare("INSERT INTO password_resets (email_hash, token_hash, expires_at) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $email_hash, $token_hash, $expires);
        $stmt->execute();

        $link = APP_URL_BASE . '/reset_password.php?token=' . $token;
        sendEmail($email, 'Réinitialisation mot de passe', "<p>Cliquez ici : <a href='$link'>Réinitialiser</a></p>");
    }

    // Toujours dire succès pour sécurité
    jsonResponse(true, 'Si le compte existe, un email a été envoyé.');
}

/**
 * Exécution réinitialisation mot de passe
 */
function perform_password_reset() {
    $input = getInput();
    $token = $input['token'] ?? '';
    $password = $input['password'] ?? '';

    if (!validatePasswordPolicy($password)) {
        jsonResponse(false, 'Mot de passe invalide.');
    }

    $db = Database::getInstance()->getConnection();
    $token_hash = hashData($token);

    $stmt = $db->prepare("SELECT email_hash FROM password_resets WHERE token_hash = ? AND expires_at > NOW()");
    $stmt->bind_param("s", $token_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        jsonResponse(false, 'Lien invalide ou expiré.');
    }

    $email_hash = $result->fetch_assoc()['email_hash'];
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email_hash = ?");
    $stmt->bind_param("ss", $password_hash, $email_hash);

    if ($stmt->execute()) {
        $db->query("DELETE FROM password_resets WHERE email_hash = '$email_hash'");
        jsonResponse(true, 'Mot de passe modifié avec succès.');
    } else {
        jsonResponse(false, 'Erreur serveur.');
    }
}

/**
 * Déconnexion
 */
function logout() {
    session_destroy();
    jsonResponse(true, 'Déconnexion réussie.');
}
?>