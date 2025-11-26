<?php
/**
 * ===================================================================
 * API - Logique d'Authentification (api/auth.php)
 * ===================================================================
 */

if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé');
}

if (!function_exists('sendEmail')) {
    require_once ROOT_PATH . '/config.php';
}

function validatePasswordPolicy($password) {
    if (strlen($password) < 8) return false;
    if (!preg_match('/[A-Z]/', $password)) return false;
    if (!preg_match('/[0-9]/', $password)) return false;
    return true;
}

function register() {
    checkRateLimit('register', 5, 3600);

    $input = getInput();
    $firstname = sanitizeInput(trim($input['firstname'] ?? ''));
    $lastname = sanitizeInput(trim($input['lastname'] ?? ''));
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $confirmPassword = $input['confirmPassword'] ?? '';

    if (empty($firstname) || empty($lastname) || empty($email) || empty($password)) {
        jsonResponse(false, 'Tous les champs marqués d\'un * sont requis.');
    }
    if (strlen($firstname) < 2 || strlen($lastname) < 2) {
        jsonResponse(false, 'Le prénom et le nom doivent contenir au moins 2 caractères.');
    }
    if (strlen($firstname) > 100 || strlen($lastname) > 100) {
        jsonResponse(false, 'Le prénom et le nom ne peuvent pas dépasser 100 caractères.');
    }
    if (strlen($email) > 255) {
        jsonResponse(false, 'L\'adresse email ne peut pas dépasser 255 caractères.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'L\'adresse email fournie n\'est pas valide.');
    }
    if (!validatePasswordPolicy($password)) {
        jsonResponse(false, 'Le mot de passe doit contenir au moins 8 caractères, une majuscule et un chiffre.');
    }
    if ($password !== $confirmPassword) {
        jsonResponse(false, 'Les mots de passe ne correspondent pas.');
    }

    $db = Database::getInstance()->getConnection();

    $email_hash = hashData($email);
    $stmt = $db->prepare("SELECT id FROM users WHERE email_hash = ?");
    $stmt->bind_param("s", $email_hash);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        jsonResponse(false, 'Un compte avec cette adresse email existe déjà.');
    }
    $stmt->close();

    $firstname_enc = encrypt($firstname);
    $lastname_enc = encrypt($lastname);
    $email_enc = encrypt($email);
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT INTO users (firstname_encrypted, lastname_encrypted, email_encrypted, password_hash, email_hash, role) VALUES (?, ?, ?, ?, ?, 'user')");
    $stmt->bind_param("sssss", $firstname_enc, $lastname_enc, $email_enc, $password_hash, $email_hash);

    if ($stmt->execute()) {
        $user_id = $stmt->insert_id;

        $_SESSION['user_id'] = $user_id;
        $_SESSION['firstname'] = $firstname;
        $_SESSION['lastname'] = $lastname;

        jsonResponse(true, 'Compte créé avec succès !', [
            'user' => [
                'id' => $user_id,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => $email
            ]
        ]);
    } else {
        jsonResponse(false, 'Erreur lors de la création du compte. Veuillez réessayer.');
    }
}

function login() {
    $input = getInput();
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        jsonResponse(false, 'Email et mot de passe requis.');
    }

    $db = Database::getInstance()->getConnection();

    $email_hash = hashData($email);
    $stmt = $db->prepare("SELECT id, password_hash, role, firstname_encrypted, lastname_encrypted, email_encrypted FROM users WHERE email_hash = ?");
    $stmt->bind_param("s", $email_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if ($user['role'] === 'admin') {
            jsonResponse(false, 'Identifiants incorrects ou compte non trouvé.');
        }

        if (password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);

            $firstname = decrypt($user['firstname_encrypted']);
            $lastname = decrypt($user['lastname_encrypted']);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['firstname'] = $firstname;
            $_SESSION['lastname'] = $lastname;
            $_SESSION['email_encrypted'] = $user['email_encrypted'];
            
            jsonResponse(true, 'Connexion réussie', [
                'user' => [
                    'id' => $user['id'],
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                ]
            ]);
        }
    }

    jsonResponse(false, 'Identifiants incorrects ou compte non trouvé.');
}

function admin_login() {
    $input = getInput();
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        jsonResponse(false, 'Email et mot de passe requis.');
    }

    $ip_address = getIpAddress();
    $db = Database::getInstance()->getConnection();

    // --- 1. Vérifier si l'IP ou l'email est actuellement verrouillé ---
    $lockout_check_stmt = $db->prepare("SELECT locked_until FROM login_attempts WHERE (ip_address = ? OR email_attempted = ?) AND locked_until > NOW() ORDER BY locked_until DESC LIMIT 1");
    $lockout_check_stmt->bind_param("ss", $ip_address, $email);
    $lockout_check_stmt->execute();
    $lockout_result = $lockout_check_stmt->get_result();

    if ($lockout_result->num_rows > 0) {
        $lockout_data = $lockout_result->fetch_assoc();
        $locked_until_timestamp = strtotime($lockout_data['locked_until']);
        $remaining_time = $locked_until_timestamp - time();
        $minutes_remaining = ceil($remaining_time / 60);
        jsonResponse(false, "Trop de tentatives de connexion. Veuillez réessayer dans {$minutes_remaining} minute(s).");
    }

    // --- 2. Nettoyer les anciennes tentatives (CORRECTIF SQL) ---
    // Utilisation de DATE_SUB pour compatibilité
    $cleanup_minutes = LOGIN_ATTEMPT_WINDOW_MINUTES + LOGIN_LOCKOUT_TIME_MINUTES;
    $cleanup_stmt = $db->prepare("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL ? MINUTE) AND locked_until IS NULL");
    $cleanup_stmt->bind_param("i", $cleanup_minutes);
    $cleanup_stmt->execute();

    // --- 3. Compter les tentatives échouées récentes (CORRECTIF SQL) ---
    $window_minutes = LOGIN_ATTEMPT_WINDOW_MINUTES;
    // Utilisation de DATE_SUB pour compatibilité
    $recent_attempts_stmt = $db->prepare("SELECT COUNT(id) as count FROM login_attempts WHERE (ip_address = ? OR email_attempted = ?) AND attempt_time > DATE_SUB(NOW(), INTERVAL ? MINUTE) AND locked_until IS NULL");
    $recent_attempts_stmt->bind_param("ssi", $ip_address, $email, $window_minutes);
    $recent_attempts_stmt->execute();
    $recent_attempts_count = $recent_attempts_stmt->get_result()->fetch_assoc()['count'];

    if ($recent_attempts_count >= MAX_LOGIN_ATTEMPTS) {
        $locked_until = date('Y-m-d H:i:s', time() + (LOGIN_LOCKOUT_TIME_MINUTES * 60));
        $lock_stmt = $db->prepare("INSERT INTO login_attempts (ip_address, email_attempted, locked_until) VALUES (?, ?, ?)");
        $lock_stmt->bind_param("sss", $ip_address, $email, $locked_until);
        $lock_stmt->execute();
        
        jsonResponse(false, "Trop de tentatives de connexion. Votre accès est temporairement bloqué pour " . LOGIN_LOCKOUT_TIME_MINUTES . " minutes.");
    }

    // --- 4. Procéder à la connexion normale ---

    $db = Database::getInstance()->getConnection();
    $email_hash = hashData($email);
    $stmt = $db->prepare("SELECT * FROM users WHERE email_hash = ? AND role = 'admin'");
    $stmt->bind_param("s", $email_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);

            // Connexion réussie : Effacer toutes les tentatives échouées
            $clear_attempts_stmt = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR email_attempted = ?");
            $clear_attempts_stmt->bind_param("ss", $ip_address, $email);
            $clear_attempts_stmt->execute();

            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_firstname'] = decrypt($user['firstname_encrypted']);
            $_SESSION['admin_lastname'] = decrypt($user['lastname_encrypted']);

            logAuditEvent('ADMIN_LOGIN_SUCCESS', $user['id']);
            Log::getLogger()->info('Connexion admin réussie', ['email' => $email, 'ip' => $ip_address]);

            jsonResponse(true, 'Connexion réussie', [
                'user' => [
                    'id' => $user['id'],
                    'firstname' => decrypt($user['firstname_encrypted']),
                    'lastname' => decrypt($user['lastname_encrypted']),
                    'email' => decrypt($user['email_encrypted'])
                ]
            ]);
        }
    }

    // --- 6. Connexion échouée : Enregistrer la tentative ---
    $record_attempt_stmt = $db->prepare("INSERT INTO login_attempts (ip_address, email_attempted) VALUES (?, ?)");
    $record_attempt_stmt->bind_param("ss", $ip_address, $email);
    $record_attempt_stmt->execute();

    Log::getLogger()->warning('Tentative de connexion admin échouée', ['email' => $email, 'ip' => $ip_address]);

    jsonResponse(false, 'Identifiants incorrects ou compte non trouvé.');
}

function admin_invite() {
    requireAuth('admin');
    $inviting_admin_id = $_SESSION['admin_id'];
    $input = getInput();
    $email = trim($input['email'] ?? '');

    if (empty($email)) {
        jsonResponse(false, 'L\'adresse email est requise.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Adresse email invalide.');
    }

    $db = Database::getInstance()->getConnection();
    $email_hash = hashData($email);

    $stmt = $db->prepare("SELECT id FROM users WHERE email_hash = ?");
    $stmt->bind_param("s", $email_hash);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        jsonResponse(false, 'Un compte avec cette adresse email existe déjà.');
    }
    $stmt->close();

    $stmt = $db->prepare("DELETE FROM admin_invitations WHERE email_hash = ?");
    $stmt->bind_param("s", $email_hash);
    $stmt->execute();

    $token = bin2hex(random_bytes(32));
    $token_hash = hashData($token);
    $expires_at = date('Y-m-d H:i:s', time() + 86400);

    $email_enc = encrypt($email);
    $stmt = $db->prepare("INSERT INTO admin_invitations (email_encrypted, email_hash, token_hash, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $email_enc, $email_hash, $token_hash, $expires_at);

    if ($stmt->execute()) {
        $invitationLink = APP_URL_BASE . '/admin_register.php?token=' . $token;
        
        $emailBody = "
            <h2 style='color: #4A4A49; border-bottom: 2px solid #eee; padding-bottom: 15px;'>👋 Invitation Administrateur</h2>
            <p>Bonjour,</p>
            <p>Vous avez été invité(e) à rejoindre l'équipe d'administration du Support Ticketing System.</p>
            <p>Pour finaliser votre inscription et créer votre compte, veuillez cliquer sur le bouton ci-dessous. Ce lien est valide pendant <strong>24 heures</strong>.</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$invitationLink}' style='background: #4A4A49; color: white; padding: 12px 25px; text-decoration: none; border-radius: 8px; font-weight: bold;'>Finaliser mon inscription</a>
            </p>
            <p style='font-size: 12px; color: #888;'>Si vous ne parvenez pas à cliquer sur le bouton, copiez et collez ce lien dans votre navigateur :<br><a href='{$invitationLink}' style='color: #EF8000;'>{$invitationLink}</a></p>
           <p style='margin-top: 20px;'>Si vous avez reçu cet email par erreur, veuillez l'ignorer et le supprimer.</p>";

        if (sendEmail($email, 'Invitation Administrateur', $emailBody)) {
            logAuditEvent('ADMIN_INVITE_SENT', $inviting_admin_id, ['invited_email' => $email]);
            jsonResponse(true, 'Invitation envoyée avec succès.');
        } else {
            jsonResponse(false, 'Erreur lors de l\'envoi de l\'invitation. Vérifiez les logs.');
        }
    } else {
        jsonResponse(false, 'Erreur lors de la création de l\'invitation.');
    }
}

function admin_register_complete() {
    $input = getInput();
    $token = $input['token'] ?? '';
    $firstname = sanitizeInput(trim($input['firstname'] ?? ''));
    $lastname = sanitizeInput(trim($input['lastname'] ?? ''));
    $password = $input['password'] ?? '';

    if (strlen($firstname) < 2 || strlen($lastname) < 2) {
        jsonResponse(false, 'Le prénom et le nom doivent contenir au moins 2 caractères.');
    }
    if (strlen($password) < 8) {
        jsonResponse(false, 'Le mot de passe doit contenir au moins 8 caractères.');
    }
    if (!validatePasswordPolicy($password)) {
        jsonResponse(false, 'Le mot de passe doit contenir au moins 8 caractères, une majuscule et un chiffre.');
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

    $invitation = $result->fetch_assoc();
    $email_hash = $invitation['email_hash'];
    $email_enc = $invitation['email_encrypted'];

    $firstname_enc = encrypt($firstname);
    $lastname_enc = encrypt($lastname);
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT INTO users (firstname_encrypted, lastname_encrypted, email_encrypted, password_hash, email_hash, role) VALUES (?, ?, ?, ?, ?, 'admin')");
    $stmt->bind_param("sssss", $firstname_enc, $lastname_enc, $email_enc, $password_hash, $email_hash);
    
    if ($stmt->execute()) {
        $deleteStmt = $db->prepare("DELETE FROM admin_invitations WHERE email_hash = ?");
        $deleteStmt->bind_param("s", $email_hash);
        $deleteStmt->execute();

        logAuditEvent('ADMIN_ACCOUNT_CREATED', $stmt->insert_id, ['email' => decrypt($email_enc)]);
        jsonResponse(true, 'Compte admin créé avec succès. Vous pouvez maintenant vous connecter.');
    } else {
        jsonResponse(false, 'Erreur lors de la création du compte administrateur.');
    }
}

function request_password_reset() {
    checkRateLimit('password_reset_request', 3, 3600);

    $input = getInput();
    $email = trim($input['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Adresse email invalide.');
    }

    $db = Database::getInstance()->getConnection();
    $email_hash = hashData($email);

    $stmt = $db->prepare("SELECT id FROM users WHERE email_hash = ?");
    $stmt->bind_param("s", $email_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $token = bin2hex(random_bytes(32));
        $token_hash = hashData($token);
        $expires_at = date('Y-m-d H:i:s', time() + 900);

        $insertStmt = $db->prepare("INSERT INTO password_resets (email_hash, token_hash, expires_at) VALUES (?, ?, ?)");
        $insertStmt->bind_param("sss", $email_hash, $token_hash, $expires_at);
        $insertStmt->execute();
 
        $resetLink = APP_URL_BASE . '/reset_password.php?token=' . $token;
        $emailBody = "
            <h2 style='color: #4A4A49;'>Réinitialisation de mot de passe</h2>
            <p>Bonjour, vous avez demandé une réinitialisation de votre mot de passe. Cliquez sur le lien ci-dessous pour continuer. Ce lien est valide pendant <strong>15 minutes</strong>.</p>
            <p style='text-align: center; margin: 30px 0;'><a href='{$resetLink}' style='background: #EF8000; color: white; padding: 12px 25px; text-decoration: none; border-radius: 8px; font-weight: bold;'>Réinitialiser mon mot de passe</a></p>
            <p>Si vous n'êtes pas à l'origine de cette demande, veuillez ignorer cet email.</p>";
        sendEmail($email, 'Réinitialisation de votre mot de passe', $emailBody);
    }

    jsonResponse(true, 'Si un compte est associé à cet email, un lien de réinitialisation a été envoyé.');
}

function perform_password_reset() {
    $input = getInput();
    $token = $input['token'] ?? '';
    $password = $input['password'] ?? '';

    if (empty($token) || empty($password)) {
        jsonResponse(false, 'Jeton et mot de passe requis.');
    }
    if (!validatePasswordPolicy($password)) {
        jsonResponse(false, 'Le mot de passe doit contenir au moins 8 caractères, une majuscule et un chiffre.');
    }

    $db = Database::getInstance()->getConnection();
    $token_hash = hashData($token);

    $stmt = $db->prepare("SELECT email_hash FROM password_resets WHERE token_hash = ? AND expires_at > NOW()");
    $stmt->bind_param("s", $token_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $reset_request = $result->fetch_assoc();
        $email_hash = $reset_request['email_hash'];

    $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $updateStmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email_hash = ?");
        $updateStmt->bind_param("ss", $password_hash, $email_hash);
        
        if ($updateStmt->execute()) {
            $deleteStmt = $db->prepare("DELETE FROM password_resets WHERE email_hash = ?");
            $deleteStmt->bind_param("s", $email_hash);
            $deleteStmt->execute();

            jsonResponse(true, 'Mot de passe réinitialisé avec succès. Vous pouvez maintenant vous connecter.', ['show_login_button' => true]);
        } else {
            jsonResponse(false, 'Erreur lors de la mise à jour du mot de passe.');
        }
    } else {
        jsonResponse(false, 'Jeton invalide ou expiré.');
    }
}

function logout() {
    session_destroy();
    jsonResponse(true, 'Déconnexion réussie.');
}
?>