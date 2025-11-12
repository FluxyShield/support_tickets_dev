<?php
/**
 * ===================================================================
 * API - Logique des Paramètres (api/settings.php)
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
 * Récupère tous les paramètres de l'application.
 */
function get_app_settings() {
    requireAuth('admin');
    $db = Database::getInstance()->getConnection();
    $result = $db->query("SELECT setting_key, setting_value FROM settings");
    
    $settings = [];
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    jsonResponse(true, 'Paramètres récupérés', ['settings' => $settings]);
}

/**
 * Met à jour les paramètres de l'application.
 */function update_app_settings() {
    requireAuth('admin');
    $db = Database::getInstance()->getConnection();

    // --- Mise à jour des valeurs textuelles ---
    $app_name = sanitizeInput($_POST['app_name'] ?? '');
    $app_primary_color = sanitizeInput($_POST['app_primary_color'] ?? '#EF8000');

    if (!empty($app_name)) {
        updateSetting($db, 'app_name', $app_name);
    }
    if (preg_match('/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/', $app_primary_color)) {
        updateSetting($db, 'app_primary_color', $app_primary_color);
    }

    // --- Gestion du téléversement du logo ---
    if (isset($_FILES['app_logo']) && $_FILES['app_logo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['app_logo'];
        $allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml'];
        $maxSize = 5 * 1024 * 1024; // 5 MB

        if (!in_array($file['type'], $allowedTypes)) {
            jsonResponse(false, 'Type de fichier non autorisé pour le logo (PNG, JPG, GIF, SVG autorisés).');
        }
        if ($file['size'] > $maxSize) {
            jsonResponse(false, 'Le fichier du logo est trop volumineux (max 5MB).');
        }

        // Utiliser une extension de fichier sécurisée
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (!in_array(strtolower($extension), ['png', 'jpg', 'jpeg', 'gif', 'svg'])) {
            $extension = 'png'; // fallback
        }
        $newFilename = 'logo.' . $extension;
        $destination = ROOT_PATH . '/assets/' . $newFilename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            // Mettre à jour l'URL dans la base de données avec un timestamp pour forcer le rafraîchissement du cache
            $logoUrl = $newFilename . '?v=' . time();
            updateSetting($db, 'app_logo_url', $logoUrl);
        } else {
            jsonResponse(false, 'Erreur lors du déplacement du fichier logo téléversé.');
        }
    }

    jsonResponse(true, 'Paramètres mis à jour avec succès.');
}

/**
 * Fonction utilitaire pour mettre à jour un paramètre dans la BDD.
 */
function updateSetting($db, $key, $value) {
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("sss", $key, $value, $value);
    $stmt->execute();
}

?>