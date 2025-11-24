<?php
/**
 * ===================================================================
 * API - Logique des Fichiers (api/files.php)
 * ===================================================================
 * Contient toutes les fonctions liées à la gestion des fichiers
 * uploadés pour les tickets.
 * ===================================================================
 */

if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé');
}

// Vérifier que config.php est bien chargé
if (!function_exists('sendEmail')) {
    require_once ROOT_PATH . '/config.php';
}

// ==========================================
// GESTION DES FICHIERS
// ==========================================
function ticketUploadFile() {
    requireAuth();
    
    // ⭐ SÉCURITÉ RENFORCÉE : Validation stricte côté serveur
    if (!isset($_FILES['file']) || !isset($_POST['ticket_id'])) {
        jsonResponse(false, 'Fichier ou ID de ticket manquant');
    }
    
    // ⭐ SÉCURITÉ : Valider et nettoyer l'ID du ticket
    $ticket_id = filter_var($_POST['ticket_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$ticket_id) {
        jsonResponse(false, 'ID de ticket invalide');
    }
    
    // ⭐ SÉCURITÉ : Vérifier le nombre maximum de fichiers par ticket (5 fichiers max)
    $db = Database::getInstance()->getConnection();
    $max_files_per_ticket = 5;
    $count_stmt = $db->prepare("SELECT COUNT(id) as file_count FROM ticket_files WHERE ticket_id = ?");
    $count_stmt->bind_param("i", $ticket_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $current_file_count = $count_result->fetch_assoc()['file_count'];
    
    if ($current_file_count >= $max_files_per_ticket) {
        jsonResponse(false, "Nombre maximum de fichiers atteint pour ce ticket (maximum {$max_files_per_ticket} fichiers).");
    }
    
    $file = $_FILES['file'];
    
    // ⭐ SÉCURITÉ : Vérifier les erreurs d'upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'Le fichier dépasse la taille maximale autorisée par le serveur',
            UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille maximale autorisée',
            UPLOAD_ERR_PARTIAL => 'Le fichier n\'a été que partiellement téléchargé',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier n\'a été téléchargé',
            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant',
            UPLOAD_ERR_CANT_WRITE => 'Échec de l\'écriture du fichier sur le disque',
            UPLOAD_ERR_EXTENSION => 'Une extension PHP a arrêté le téléchargement'
        ];
        $error_msg = $error_messages[$file['error']] ?? 'Erreur lors de l\'upload du fichier';
        jsonResponse(false, $error_msg);
    }
    
    // ⭐ SÉCURITÉ : Vérifier que le fichier a bien été uploadé (protection contre les uploads falsifiés)
    if (!is_uploaded_file($file['tmp_name'])) {
        jsonResponse(false, 'Fichier non valide : tentative d\'upload falsifiée');
    }
    
    // ⭐ SÉCURITÉ : Valider la taille du fichier
    $max_size = 20 * 1024 * 1024; // 20 Mo
    if ($file['size'] > $max_size || $file['size'] <= 0) {
        jsonResponse(false, 'Le fichier est trop volumineux (max 20 Mo) ou invalide');
    }
    
    // ⭐ SÉCURITÉ : Valider le type MIME réel du fichier
    $allowed_types = ['image/png', 'image/jpeg', 'image/jpg', 'application/pdf', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        jsonResponse(false, 'Erreur lors de la vérification du type de fichier');
    }
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        jsonResponse(false, 'Type de fichier non autorisé (PNG, JPG, JPEG, GIF, WebP, PDF uniquement)');
    }
    
    // ⭐ SÉCURITÉ : Valider l'extension du fichier
    $allowed_extensions = ['png', 'jpg', 'jpeg', 'pdf', 'gif', 'webp'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // ⭐ SÉCURITÉ : Vérifier que l'extension correspond au type MIME
    $extension_mime_map = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'pdf' => 'application/pdf',
        'gif' => 'image/gif',
        'webp' => 'image/webp'
    ];
    
    if (!in_array($file_extension, $allowed_extensions)) {
        jsonResponse(false, 'Extension de fichier non autorisée');
    }
    
    // ⭐ SÉCURITÉ : Vérifier la cohérence entre extension et type MIME
    if (!isset($extension_mime_map[$file_extension]) || $extension_mime_map[$file_extension] !== $mime_type) {
        jsonResponse(false, 'Incohérence entre l\'extension et le type de fichier détecté');
    }
    
    // ⭐ SÉCURITÉ : Valider le nom du fichier (prévenir les noms de fichiers malveillants)
    $original_filename = basename($file['name']);
    if (strlen($original_filename) > 255 || preg_match('/[<>:"|?*\x00-\x1F]/', $original_filename)) {
        jsonResponse(false, 'Nom de fichier invalide');
    }
    
    // La connexion DB est déjà établie ci-dessus
    $user_id = $_SESSION['user_id'] ?? null;
    $is_admin = isset($_SESSION['admin_id']);
    if (!$is_admin && $user_id) {
        $stmt = $db->prepare("SELECT id FROM tickets WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $ticket_id, $user_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            jsonResponse(false, 'Ticket non trouvé');
        }
    } elseif (!$is_admin && !$user_id) {
         jsonResponse(false, 'Authentification requise');
    }
    // ⭐ SÉCURITÉ : Le dossier d'upload est maintenant HORS du webroot.
    // On remonte de 3 niveaux depuis /api/ pour sortir de /htdocs/support_tickets/
    // et on entre dans un dossier 'secure_uploads' (ex: c:/xampp/secure_uploads).
    $upload_dir = dirname(__DIR__, 3) . '/secure_uploads';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $unique_filename = uniqid('file_', true) . '.' . $file_extension;
    $destination = $upload_dir . '/' . $unique_filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        jsonResponse(false, 'Erreur lors de la sauvegarde du fichier');
    }
    $author_name = '';
    if($is_admin) {
        $author_name = $_SESSION['admin_firstname'];
    } else {
        $author_name = $_SESSION['firstname'] . ' ' . $_SESSION['lastname'];
    }
    $original_filename_enc = encrypt($file['name']);
    $filename_enc = encrypt($unique_filename);
    $author_name_enc = encrypt($author_name);
    
    $stmt = $db->prepare("INSERT INTO ticket_files (ticket_id, filename_encrypted, original_filename_encrypted, file_size, file_type, uploaded_by_encrypted) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ississ", $ticket_id, $filename_enc, $original_filename_enc, $file['size'], $mime_type, $author_name_enc);
    
    if ($stmt->execute()) {
        $file_id = $stmt->insert_id;
        // ⭐ AUDIT : Enregistrer le téléversement du fichier
        logAuditEvent('FILE_UPLOAD', $ticket_id, [
            'file_id' => $file_id,
            'filename' => $file['name'],
            'uploader_id' => $is_admin ? $_SESSION['admin_id'] : $_SESSION['user_id'],
            'uploader_role' => $is_admin ? 'admin' : 'user'
        ]);
        
        $message = "[Message Système] <strong>" . htmlspecialchars($author_name) . "</strong> a joint un fichier : " . htmlspecialchars($file['name']);
        $message_enc = encrypt($message);
        $system_author_enc = encrypt("Système");
        $author_role = 'system';
        $user_id_system = $is_admin ? $_SESSION['admin_id'] : $_SESSION['user_id']; 

        $msgStmt = $db->prepare("INSERT INTO messages (ticket_id, user_id, author_name_encrypted, author_role, message_encrypted, is_read) VALUES (?, ?, ?, ?, ?, 0)");
        $msgStmt->bind_param("iisss", $ticket_id, $user_id_system, $system_author_enc, $author_role, $message_enc);
        $msgStmt->execute();
        
        jsonResponse(true, 'Fichier uploadé avec succès', [
            'file_id' => $file_id,
            'original_name' => $file['name'],
            'size' => $file['size']
        ]);
    }
    jsonResponse(false, 'Erreur lors de l\'enregistrement du fichier');
}
function ticketDownloadFile() {
    $file_id = (int)($_GET['file_id'] ?? 0);
    $preview = isset($_GET['preview']);
    if (!$file_id) {
        http_response_code(400); die('ID de fichier manquant');
    }
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $is_admin = isset($_SESSION['admin_id']);
    $user_id = $_SESSION['user_id'] ?? null;
    if (!$is_admin && !$user_id) {
        http_response_code(401); die('Authentification requise');
    }
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT tf.*, t.user_id FROM ticket_files tf JOIN tickets t ON tf.ticket_id = t.id WHERE tf.id = ?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        http_response_code(404); die('Fichier non trouvé');
    }
    $file = $result->fetch_assoc();
    if (!$is_admin && $file['user_id'] != $user_id) {
        http_response_code(403); die('Accès refusé');
    }
    $filename = decrypt($file['filename_encrypted']);
    $original_filename = decrypt($file['original_filename_encrypted']);
    // ⭐ SÉCURITÉ : Utiliser le nouveau chemin sécurisé pour le téléchargement.
    $filepath = dirname(__DIR__, 3) . '/secure_uploads/' . $filename;
    if (!file_exists($filepath)) {
        http_response_code(404); die('Fichier physique non trouvé');
    }
    while (ob_get_level()) { ob_end_clean(); }
    if ($preview) {
        header('Content-Type: ' . $file['file_type']);
        header('Content-Disposition: inline; filename="' . addslashes($original_filename) . '"');
    } else {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addslashes($original_filename) . '"');
    }
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: public, max-age=3600');
    header('Accept-Ranges: bytes');
    header('X-Frame-Options: SAMEORIGIN'); // Toujours appliquer pour prévenir le clickjacking
    readfile($filepath);
    exit;
}
function ticketDeleteFile() {
    requireAuth('admin'); 
    $input = getInput();
    $file_id = filter_var($input['file_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $ticket_id = filter_var($input['ticket_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (!$file_id || !$ticket_id) {
        jsonResponse(false, 'ID de fichier ou de ticket invalide.');
    }

    $db = Database::getInstance()->getConnection();

    // ⭐ SÉCURITÉ (Anti-IDOR) : Vérifier que le fichier appartient bien au ticket spécifié.
    $stmt = $db->prepare("SELECT tf.filename_encrypted FROM ticket_files tf WHERE tf.id = ? AND tf.ticket_id = ?");
    $stmt->bind_param("ii", $file_id, $ticket_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        jsonResponse(false, 'Accès non autorisé ou fichier non trouvé pour ce ticket.');
    }
    $file = $result->fetch_assoc();
    $filename = decrypt($file['filename_encrypted']);
    // ⭐ SÉCURITÉ : Utiliser le nouveau chemin sécurisé pour la suppression.
    $filepath = dirname(__DIR__, 3) . '/secure_uploads/' . $filename;
    if (file_exists($filepath)) { unlink($filepath); }
    $deleteStmt = $db->prepare("DELETE FROM ticket_files WHERE id = ?");
    $deleteStmt->bind_param("i", $file_id);
    if ($deleteStmt->execute()) {
        // ⭐ AUDIT : Enregistrer la suppression du fichier
        logAuditEvent('FILE_DELETE', $ticket_id, [
            'file_id' => $file_id,
            'filename' => $filename
        ]);

        jsonResponse(true, 'Fichier supprimé');
    }
    jsonResponse(false, 'Erreur lors de la suppression');
}
?>