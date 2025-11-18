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
    if (!isset($_FILES['file']) || !isset($_POST['ticket_id'])) {
        jsonResponse(false, 'Fichier ou ID de ticket manquant');
    }
    $ticket_id = (int)$_POST['ticket_id'];
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(false, 'Erreur lors de l\'upload du fichier');
    }
    $max_size = 20 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        jsonResponse(false, 'Le fichier est trop volumineux (max 20 Mo)');
    }
    $allowed_types = ['image/png', 'image/jpeg', 'image/jpg', 'application/pdf', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime_type, $allowed_types)) {
        jsonResponse(false, 'Type de fichier non autorisé (PNG, JPG, JPEG, GIF, WebP, PDF uniquement)');
    }
    $allowed_extensions = ['png', 'jpg', 'jpeg', 'pdf', 'gif', 'webp'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_extension, $allowed_extensions)) {
        jsonResponse(false, 'Extension de fichier non autorisée');
    }
    $db = Database::getInstance()->getConnection();
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