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
    
    // ⭐ SÉCURITÉ : Utiliser une requête préparée même pour les requêtes statiques
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings");
    $stmt->execute();
    $result = $stmt->get_result();
    
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

    // ⭐ SÉCURITÉ RENFORCÉE : Validation stricte côté serveur
    $input = getInput();
    
    // --- Mise à jour des valeurs textuelles ---
    $app_name = trim($input['app_name'] ?? $_POST['app_name'] ?? '');
    $app_primary_color = trim($input['app_primary_color'] ?? $_POST['app_primary_color'] ?? '#EF8000');

    // ⭐ AMÉLIORATION SÉCURITÉ : Valider le nom de l'application (min et max)
    if (!empty($app_name)) {
        if (strlen($app_name) < 2) {
            jsonResponse(false, 'Le nom de l\'application doit contenir au moins 2 caractères.');
        }
        if (strlen($app_name) > 100) {
            jsonResponse(false, 'Le nom de l\'application ne peut pas dépasser 100 caractères.');
        }
        $app_name = sanitizeInput($app_name);
        updateSetting($db, 'app_name', $app_name);
    }
    
    // ⭐ SÉCURITÉ : Valider la couleur (format hexadécimal)
    if (!preg_match('/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/', $app_primary_color)) {
        jsonResponse(false, 'Format de couleur invalide. Utilisez un code hexadécimal (ex: #EF8000).');
    }
    $app_primary_color = sanitizeInput($app_primary_color);
    updateSetting($db, 'app_primary_color', $app_primary_color);

    // --- Gestion du téléversement du logo ---
    if (isset($_FILES['app_logo']) && $_FILES['app_logo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['app_logo'];
        
        // ⭐ SÉCURITÉ RENFORCÉE : Validation stricte du fichier uploadé
        if (!is_uploaded_file($file['tmp_name'])) {
            jsonResponse(false, 'Fichier non valide : tentative d\'upload falsifiée.');
        }
        
        $allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml'];
        $maxSize = 5 * 1024 * 1024; // 5 MB
        
        // ⭐ SÉCURITÉ : Vérifier le type MIME réel du fichier
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            jsonResponse(false, 'Erreur lors de la vérification du type de fichier.');
        }
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $allowedTypes)) {
            jsonResponse(false, 'Type de fichier non autorisé pour le logo (PNG, JPG, GIF, SVG autorisés).');
        }
        
        if ($file['size'] > $maxSize || $file['size'] <= 0) {
            jsonResponse(false, 'Le fichier du logo est trop volumineux (max 5MB) ou invalide.');
        }
        
        // ⭐ SÉCURITÉ : Valider l'extension
        $allowed_extensions = ['png', 'jpg', 'jpeg', 'gif', 'svg'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed_extensions)) {
            jsonResponse(false, 'Extension de fichier non autorisée pour le logo.');
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