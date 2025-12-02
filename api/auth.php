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
 * ⭐ AMÉLIORATION SÉCURITÉ : Valide la politique de mot de passe.
 * @param string $password Le mot de passe à vérifier.
 * @return bool True si le mot de passe est valide, false sinon.
 */
function validatePasswordPolicy($password) {
    // Au moins 8 caractères
    if (strlen($password) < 8) return false;
    // Au moins une lettre majuscule
    if (!preg_match('/[A-Z]/', $password)) return false;
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

    // --- 2. Nettoyer les anciennes tentatives pour éviter l'encombrement ---
    // ⭐ CORRECTION SQL : Utilisation de DATE_SUB au lieu de la syntaxe - INTERVAL
    $cleanup_minutes = LOGIN_ATTEMPT_WINDOW_MINUTES + LOGIN_LOCKOUT_TIME_MINUTES;
    $cleanup_stmt = $db->prepare("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL ? MINUTE) AND locked_until IS NULL");
    $cleanup_stmt->bind_param("i", $cleanup_minutes);
    $cleanup_stmt->execute();

    // --- 3. Compter les tentatives échouées récentes pour cette IP/email ---
    // ⭐ CORRECTION SQL : Utilisation de DATE_SUB
    $window_minutes = LOGIN_ATTEMPT_WINDOW_MINUTES;
    $recent_attempts_stmt = $db->prepare("SELECT COUNT(id) as count FROM login_attempts WHERE (ip_address = ? OR email_attempted = ?) AND attempt_time > DATE_SUB(NOW(), INTERVAL ? MINUTE) AND locked_until IS NULL");
    $recent_attempts_stmt->bind_param("ssi", $ip_address, $email, $window_minutes);
    $recent_attempts_stmt->execute();
    $recent_attempts_count = $recent_attempts_stmt->get_result()->fetch_assoc()['count'];

    if ($recent_attempts_count >= MAX_LOGIN_ATTEMPTS) {
        // Verrouiller l'accès
        // ⭐ CORRECTION SQL : Utilisation de DATE_ADD pour plus de sûreté
        $lock_stmt = $db->prepare("INSERT INTO login_attempts (ip_address, email_attempted, locked_until) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))");
        $lockout_minutes = LOGIN_LOCKOUT_TIME_MINUTES;
        $lock_stmt->bind_param("ssi", $ip_address, $email, $lockout_minutes);
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
            // ⭐ SÉCURITÉ : Régénérer l'ID de session pour prévenir la fixation de session.
            session_regenerate_id(true);

            // --- 5. Connexion réussie : Effacer toutes les tentatives échouées pour cette IP/email ---
            $clear_attempts_stmt = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR email_attempted = ?");
            $clear_attempts_stmt->bind_param("ss", $ip_address, $email);
            $clear_attempts_stmt->execute();

            // Le mot de passe est correct
            // ⭐ CORRECTION : Initialiser la session pour l'administrateur
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_firstname'] = decrypt($user['firstname_encrypted']);
            $_SESSION['admin_lastname'] = decrypt($user['lastname_encrypted']);
            // ⭐ FIN CORRECTION

            // ⭐ AUDIT : Enregistrer la connexion réussie de l'admin
            logAuditEvent('ADMIN_LOGIN_SUCCESS', $user['id']);

            // ⭐ SÉCURITÉ : Journaliser la tentative de connexion réussie
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

    // Si on arrive ici, l'email ou le mot de passe est incorrect
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

    // ⭐ SÉCURITÉ : Valider que l'email n'est pas vide avant de le traiter
    if (empty($email)) {
        jsonResponse(false, 'L\'adresse email est requise.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Adresse email invalide.');
    }

    $db = Database::getInstance()->getConnection();
    $email_hash = hashData($email);

    // Vérifier si un utilisateur ou une invitation existe déjà
    $stmt = $db->prepare("SELECT id FROM users WHERE email_hash = ?");
    $stmt->bind_param("s", $email_hash);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        jsonResponse(false, 'Un compte avec cette adresse email existe déjà.');
    }
    $stmt->close();

    // ⭐ CORRECTION : Supprimer les anciennes invitations pour cet email
    $stmt = $db->prepare("DELETE FROM admin_invitations WHERE email_hash = ?");
    $stmt->bind_param("s", $email_hash);
    $stmt->execute();

    // Générer un token sécurisé
    $token = bin2hex(random_bytes(32));
    $token_hash = hashData($token);
    $expires_at = date('Y-m-d H:i:s', time() + 86400); // 24 heures

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

        // Envoi de l'email
        if (sendEmail($email, 'Invitation Administrateur', $emailBody)) {
            // ⭐ AUDIT : Enregistrer l'invitation
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

    // ⭐ SÉCURITÉ : Valider la longueur minimale du prénom et du nom côté serveur
    if (strlen($firstname) < 2 || strlen($lastname) < 2) {
        jsonResponse(false, 'Le prénom et le nom doivent contenir au moins 2 caractères.');
    }

    // ⭐ SÉCURITÉ RENFORCÉE : Valider la longueur du mot de passe avant la politique complexe
    if (strlen($password) < 8) {
        jsonResponse(false, 'Le mot de passe doit contenir au moins 8 caractères.');
    }

    // ⭐ SÉCURITÉ : La politique de mot de passe est maintenant validée côté serveur.
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
    // ⭐ AMÉLIORATION SÉCURITÉ : Utiliser PASSWORD_DEFAULT
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT INTO users (firstname_encrypted, lastname_encrypted, email_encrypted, password_hash, email_hash, role) VALUES (?, ?, ?, ?, ?, 'admin')");
    $stmt->bind_param("sssss", $firstname_enc, $lastname_enc, $email_enc, $password_hash, $email_hash);
    
    if ($stmt->execute()) {
        $deleteStmt = $db->prepare("DELETE FROM admin_invitations WHERE email_hash = ?");
        $deleteStmt->bind_param("s", $email_hash);
        $deleteStmt->execute();

        // ⭐ AUDIT : Enregistrer la création du nouveau compte admin
        logAuditEvent('ADMIN_ACCOUNT_CREATED', $stmt->insert_id, ['email' => decrypt($email_enc)]);
        jsonResponse(true, 'Compte admin créé avec succès. Vous pouvez maintenant vous connecter.');
    } else {
        jsonResponse(false, 'Erreur lors de la création du compte administrateur.');
    }
}

function request_password_reset() {
    // ⭐ SÉCURITÉ : Limiter les demandes de réinitialisation (3 par heure par IP).
    checkRateLimit('password_reset_request', 3, 3600);

    $input = getInput();
    $email = trim($input['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Adresse email invalide.');
    }

    $db = Database::getInstance()->getConnection();
    $email_hash = hashData($email);

    // On vérifie si un utilisateur (admin ou user) existe avec cet email
    $stmt = $db->prepare("SELECT id FROM users WHERE email_hash = ?");
    $stmt->bind_param("s", $email_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        // Générer un token sécurisé
        $token = bin2hex(random_bytes(32));
        $token_hash = hashData($token);
        $expires_at = date('Y-m-d H:i:s', time() + 900); // Valide 15 minutes

        // ⭐ CORRECTION : Insérer le jeton dans la table `password_resets`
        $insertStmt = $db->prepare("INSERT INTO password_resets (email_hash, token_hash, expires_at) VALUES (?, ?, ?)");
        $insertStmt->bind_param("sss", $email_hash, $token_hash, $expires_at);
        $insertStmt->execute();
 
        // Envoyer l'email avec le token en clair
        $resetLink = APP_URL_BASE . '/reset_password.php?token=' . $token;
        $emailBody = "
            <h2 style='color: #4A4A49;'>Réinitialisation de mot de passe</h2>
            <p>Bonjour, vous avez demandé une réinitialisation de votre mot de passe. Cliquez sur le lien ci-dessous pour continuer. Ce lien est valide pendant <strong>15 minutes</strong>.</p>
            <p style='text-align: center; margin: 30px 0;'><a href='{$resetLink}' style='background: #EF8000; color: white; padding: 12px 25px; text-decoration: none; border-radius: 8px; font-weight: bold;'>Réinitialiser mon mot de passe</a></p>
            <p>Si vous n'êtes pas à l'origine de cette demande, veuillez ignorer cet email.</p>";
        sendEmail($email, 'Réinitialisation de votre mot de passe', $emailBody);
    }

    // Pour des raisons de sécurité, on envoie toujours une réponse positive
    jsonResponse(true, 'Si un compte est associé à cet email, un lien de réinitialisation a été envoyé.');
}

function perform_password_reset() {
    $input = getInput();
    $token = $input['token'] ?? '';
    $password = $input['password'] ?? '';

    if (empty($token) || empty($password)) {
        jsonResponse(false, 'Jeton et mot de passe requis.');
    }
    // ⭐ AMÉLIORATION SÉCURITÉ : Utilisation de la nouvelle politique de mot de passe
    if (!validatePasswordPolicy($password)) {
        jsonResponse(false, 'Le mot de passe doit contenir au moins 8 caractères, une majuscule et un chiffre.');
    }

    $db = Database::getInstance()->getConnection();
    $token_hash = hashData($token);

    // ⭐ CORRECTION : Vérifier le jeton dans la table `password_resets`
    $stmt = $db->prepare("SELECT email_hash FROM password_resets WHERE token_hash = ? AND expires_at > NOW()");
    $stmt->bind_param("s", $token_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $reset_request = $result->fetch_assoc();
        $email_hash = $reset_request['email_hash'];

        // Mettre à jour le mot de passe
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $updateStmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email_hash = ?");
        $updateStmt->bind_param("ss", $password_hash, $email_hash);
        
        if ($updateStmt->execute()) {
            // Le mot de passe est mis à jour, on peut supprimer le jeton
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