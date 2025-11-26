<?php
define('ROOT_PATH', __DIR__);
require_once 'config.php';

try {
    $db = Database::getInstance()->getConnection();
    echo "Connected successfully via SSL!\n";
    echo "Cipher: " . $db->get_ssl_cipher() . "\n";
} catch (Exception $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
}
