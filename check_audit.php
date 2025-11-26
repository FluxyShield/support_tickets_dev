<?php
define('ROOT_PATH', __DIR__);
require_once 'config.php';

$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 5");

echo "--- Audit Log Entries ---\n";
while ($row = $stmt->fetch_assoc()) {
    echo "[{$row['created_at']}] Action: {$row['action']}, Admin ID: {$row['admin_id']}, IP: {$row['ip_address']}\n";
}
echo "-------------------------\n";
