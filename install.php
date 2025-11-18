<?php
/**
 * @file install.php
 * @brief Script d'installation de la base de données pour le système de tickets de support.
 *
 * Ce script gère l'installation complète de l'application. Il se connecte à la base de données,
 * supprime les tables existantes pour garantir une installation propre, puis crée l'ensemble du
 * schéma de base de données (tables, index, clés étrangères). Il insère également les données
 * initiales nécessaires, comme le compte super-administrateur et les paramètres par défaut.
 */
define('ROOT_PATH', __DIR__);
define('INSTALLING', true);

require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - Support Descamps</title>
    <style>        
        @import url('https://fonts.googleapis.com/css2?family=Source+Sans+Pro:wght@300;400;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, var(--gray-medium) 0%, var(--gray-dark) 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { background: white; border-radius: 20px; padding: 40px; max-width: 600px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        h1 { color: var(--gray-dark); text-align: center; margin-bottom: 30px; font-size: 32px; }
        .step { padding: 15px; margin: 10px 0; border-radius: 8px; background: #f3f4f6; }
        .step.success { background: #d1fae5; color: #065f46; }
        .step.error { background: #fee2e2; color: #991b1b; }
        .step.info { background: var(--gray-100); color: var(--gray-700); }
        .btn { display: block; width: 100%; padding: 15px; background: linear-gradient(135deg, var(--gray-medium) 0%, var(--gray-dark) 100%); color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; margin-top: 20px; text-decoration: none; text-align: center; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(74, 74, 73, 0.4); }        
        :root {
            --gray-50: #F9F9F9;
            --gray-600: #7C7C7B;
            --gray-700: #4A4A49;
            --orange: #EF8000;
        }
        ol { margin-left:20px;color:var(--gray-700); }
        ol li { margin:10px 0; }
        a { color: var(--orange); text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎫 Installation du système</h1>
        
        <?php
        try {
            $db = Database::getInstance()->getConnection();
            
            $db->query("SET FOREIGN_KEY_CHECKS = 0");
            
            echo '<div class="step info">🗑️ Nettoyage des anciennes tables...</div>';
            
            $db->query("DROP TABLE IF EXISTS settings");
            $db->query("DROP TABLE IF EXISTS password_resets");
            $db->query("DROP TABLE IF EXISTS admin_invitations");
            $db->query("DROP TABLE IF EXISTS audit_log");
            $db->query("DROP TABLE IF EXISTS canned_responses");
            $db->query("DROP TABLE IF EXISTS login_attempts");
            $db->query("DROP TABLE IF EXISTS ticket_reviews");
            $db->query("DROP TABLE IF EXISTS ticket_files");
            $db->query("DROP TABLE IF EXISTS email_logs");
            $db->query("DROP TABLE IF EXISTS ticket_assignments");
            $db->query("DROP TABLE IF EXISTS messages");
            $db->query("DROP TABLE IF EXISTS tickets");
            $db->query("DROP TABLE IF EXISTS users");
            
            echo '<div class="step success">✅ Anciennes tables supprimées</div>';
            echo '<div class="step info">📦 Création des nouvelles tables...</div>';
            
            $sql_users = "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                firstname_encrypted VARCHAR(512) NOT NULL,
                lastname_encrypted VARCHAR(512) NOT NULL,
                email_encrypted VARCHAR(512) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                email_hash VARCHAR(64) NOT NULL UNIQUE,
                role ENUM('admin', 'user') DEFAULT 'user',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_hash (email_hash),
                INDEX idx_role (role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $db->query($sql_users);
            echo '<div class="step success">✅ Table "users" créée</div>';
            
            $sql_tickets = "CREATE TABLE IF NOT EXISTS tickets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                user_name_encrypted VARCHAR(512) NOT NULL,
                user_email_encrypted VARCHAR(512) NOT NULL,
                category_encrypted VARCHAR(512) NOT NULL,
                priority_encrypted VARCHAR(512) NOT NULL,
                subject_encrypted VARCHAR(512) NOT NULL,
                description_encrypted TEXT NOT NULL,
                status ENUM('Ouvert', 'En cours', 'Fermé') DEFAULT 'Ouvert',
                assigned_to INT NULL,
                assigned_at TIMESTAMP NULL,
                description_modified TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                closed_at TIMESTAMP NULL DEFAULT NULL, 
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at),
                INDEX idx_assigned_to (assigned_to)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $db->query($sql_tickets);
            echo '<div class="step success">✅ Table "tickets" (avec closed_at) créée</div>';
            
            $sql_messages = "CREATE TABLE IF NOT EXISTS messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                user_id INT NULL, 
                author_name_encrypted VARCHAR(512) NOT NULL,
                author_role ENUM('user', 'admin', 'system') NOT NULL, 
                message_encrypted TEXT NOT NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL, 
                INDEX idx_ticket_id (ticket_id),
                INDEX idx_is_read (is_read),
                INDEX idx_author_role (author_role) 
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            if ($db->query($sql_messages)) {
                echo '<div class="step success">✅ Table "messages" (chiffrée + rôle system) créée</div>';
            }
            
            $sql_files = "CREATE TABLE IF NOT EXISTS ticket_files (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                filename_encrypted VARCHAR(512) NOT NULL,
                original_filename_encrypted VARCHAR(512) NOT NULL,
                file_size INT NOT NULL,
                file_type VARCHAR(50) NOT NULL,
                uploaded_by_encrypted VARCHAR(512) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                INDEX idx_ticket_id (ticket_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $db->query($sql_files);
            echo '<div class="step success">✅ Table "ticket_files" (chiffrée) créée</div>';
            
            $sql_emails = "CREATE TABLE IF NOT EXISTS email_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                recipient_email_encrypted VARCHAR(512) NOT NULL,
                email_type ENUM('ticket_created', 'message_sent', 'ticket_closed', 'review_request') NOT NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                status ENUM('sent', 'failed') DEFAULT 'sent',
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                INDEX idx_ticket_id (ticket_id),
                INDEX idx_sent_at (sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $db->query($sql_emails);
            echo '<div class="step success">✅ Table "email_logs" créée</div>';
            
            $sql_reviews = "CREATE TABLE IF NOT EXISTS ticket_reviews (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL UNIQUE,
                rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
                comment_encrypted TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                INDEX idx_ticket_id (ticket_id),
                INDEX idx_rating (rating)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $db->query($sql_reviews);
            echo '<div class="step success">✅ Table "ticket_reviews" créée</div>';
            
            $sql_canned = "CREATE TABLE IF NOT EXISTS canned_responses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title_encrypted VARCHAR(512) NOT NULL,
                content_encrypted TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $db->query($sql_canned);
            echo '<div class="step success">✅ Table "canned_responses" créée</div>';
            
            $sql_invites = "CREATE TABLE IF NOT EXISTS admin_invitations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email_encrypted VARCHAR(512) NOT NULL,
                email_hash VARCHAR(64) NOT NULL UNIQUE,
                token_hash VARCHAR(64) NOT NULL UNIQUE,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_hash (email_hash),
                INDEX idx_token_hash (token_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $db->query($sql_invites);
            echo '<div class="step success">✅ Table "admin_invitations" créée</div>';
            
            $sql_resets = "CREATE TABLE IF NOT EXISTS password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email_hash VARCHAR(255) NOT NULL,
                token_hash VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_hash (email_hash),
                INDEX idx_token_hash (token_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $db->query($sql_resets);
            echo '<div class="step success">✅ Table "password_resets" créée</div>';

            $sql_login_attempts = "CREATE TABLE `login_attempts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `ip_address` VARCHAR(45) NOT NULL,
                `email_attempted` VARCHAR(255) NOT NULL,
                `attempt_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `locked_until` DATETIME NULL,
                INDEX `idx_ip_address` (`ip_address`),
                INDEX `idx_email_attempted` (`email_attempted`),
                INDEX `idx_attempt_time` (`attempt_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $db->query($sql_login_attempts);
            echo '<div class="step success">✅ Table "login_attempts" créée</div>';

            $sql_settings = "CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
                setting_value TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $db->query($sql_settings);
            echo '<div class="step success">✅ Table "settings" créée</div>';

            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('app_name', 'Support Descamps')");
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('app_primary_color', '#EF8000')");
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('app_logo_url', 'logo.png')");
            echo '<div class="step info">🔧 Paramètres par défaut insérés</div>';

            $sql_audit = "CREATE TABLE IF NOT EXISTS `audit_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `admin_id` int(11) DEFAULT NULL,
                `action` varchar(255) NOT NULL,
                `target_id` int(11) DEFAULT NULL,
                `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
                `ip_address` varchar(45) DEFAULT NULL,
                `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `admin_id` (`admin_id`),
                KEY `action` (`action`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            $db->query($sql_audit);
            echo '<div class="step success">✅ Table "audit_log" créée</div>';

            $sql_assign = "CREATE TABLE ticket_assignments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                assigned_by INT NOT NULL,
                assigned_to INT NOT NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                note TEXT,
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_ticket_id (ticket_id),
                INDEX idx_assigned_to (assigned_to)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $db->query($sql_assign);
            echo '<div class="step success">✅ Table "ticket_assignments" créée</div>';

            echo '<div class="step info">🔧 Création des index pour optimiser les performances...</div>';
            try {
                $db->query("
                    CREATE INDEX idx_tickets_user_status 
                    ON tickets(user_id, status, created_at DESC)
                ");
                echo '<div class="step success">✅ Index tickets (user + status) créé</div>';
                
                $db->query("
                    CREATE INDEX idx_tickets_assigned_status 
                    ON tickets(assigned_to, status, created_at DESC)
                ");
                echo '<div class="step success">✅ Index tickets (assignation) créé</div>';
                
                $db->query("
                    CREATE INDEX idx_messages_ticket_created 
                    ON messages(ticket_id, created_at ASC)
                ");
                echo '<div class="step success">✅ Index messages créé</div>';
                
                $db->query("
                    CREATE INDEX idx_messages_ticket_unread 
                    ON messages(ticket_id, is_read, author_role)
                ");
                echo '<div class="step success">✅ Index messages non-lus créé</div>';
                
                $db->query("
                    CREATE INDEX idx_files_ticket 
                    ON ticket_files(ticket_id, created_at DESC)
                ");
                echo '<div class="step success">✅ Index fichiers créé</div>';
                
                $db->query("
                    CREATE INDEX idx_reviews_ticket 
                    ON ticket_reviews(ticket_id)
                ");
                echo '<div class="step success">✅ Index avis créé</div>';
                
                echo '<div class="step info">📊 Analyse des tables...</div>';
                $db->query("ANALYZE TABLE tickets");
                $db->query("ANALYZE TABLE messages");
                $db->query("ANALYZE TABLE ticket_files");
                $db->query("ANALYZE TABLE ticket_reviews");
                    
                echo '<div class="step success">✅ Optimisation terminée - Base de données prête</div>';
                
                $result = $db->query("
                    SELECT 
                        COUNT(*) as index_count,
                        SUM(CASE WHEN NON_UNIQUE = 0 THEN 1 ELSE 0 END) as unique_indexes
                    FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA = '" . DB_NAME . "'
                ");
                    
                if ($result) {
                    $stats = $result->fetch_assoc();
                    echo '<div class="step info">📈 ' . $stats['index_count'] . ' index créés (' . $stats['unique_indexes'] . ' uniques)</div>';
                }
            } catch (Exception $e) {
                echo '<div class="step error">⚠️ Avertissement : Certains index n\'ont pas pu être créés</div>';
                echo '<div class="step info">Raison : ' . htmlspecialchars($e->getMessage()) . '</div>';
                echo '<div class="step info">💡 L\'application fonctionnera mais les performances seront réduites</div>';
            }

            $db->query("SET FOREIGN_KEY_CHECKS = 1");
            
            echo '<div class="step info">👤 Création du Super Admin...</div>';
            $admin_firstname = 'Super';
            $admin_lastname = 'Admin';
            $admin_email = 'support-it@descamps-bois.fr';
            $admin_password = '@Desc@mps2025!'; 
            $email_hash = hashData($admin_email);
            $firstname_enc = encrypt($admin_firstname);
            $lastname_enc = encrypt($admin_lastname);
            $email_enc = encrypt($admin_email);
            $password_hash = password_hash($admin_password, PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (firstname_encrypted, lastname_encrypted, email_encrypted, password_hash, email_hash, role) VALUES (?, ?, ?, ?, ?, 'admin')");
            $stmt->bind_param("sssss", $firstname_enc, $lastname_enc, $email_enc, $password_hash, $email_hash);
            if ($stmt->execute()) {
                echo '<div class="step success">✅ Super Admin créé avec succès !</div>';
                echo '<div class="step info"><strong>Email:</strong> ' . htmlspecialchars($admin_email) . '</div>';
                echo '<div class="step info"><strong>Pass:</strong> ' . htmlspecialchars($admin_password) . '</div>';
            } else {
                echo '<div class="step error">❌ Erreur lors de la création du Super Admin.</div>';
            }
            
            $upload_dir = __DIR__ . '/uploads';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
                file_put_contents($upload_dir . '/.htaccess', 'Deny from all');
                echo '<div class="step success">✅ Dossier "uploads" créé et sécurisé</div>';
            }
            
            echo '<div class="step success"><strong>🎉 Installation terminée avec succès !</strong></div>';
            echo '<a href="index.php" class="btn">Accéder au système 🚀</a>';
            
        } catch (Exception $e) {
            echo '<div class="step error">❌ Erreur : ' . htmlspecialchars($e->getMessage()) . '</div>';
            echo '<div class="step info">💡 Vérifie que la base de données "support_tickets" existe</div>';
        }
        ?>
</body>
</html>