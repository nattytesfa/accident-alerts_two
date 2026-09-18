<?php
/*
 * check_hospital.php
 * Reports whether a hospital name and/or Telegram chat_id is already
 * registered. Used by telegram_bridge.py to stop duplicate registrations
 * before the bot starts collecting details.
 *
 * GET params: name (optional), chat_id (optional)
 * Returns: { "name_exists": bool, "chat_exists": bool, "status": string|null }
 */

require 'db.php';

header('Content-Type: application/json');

$name = isset($_GET['name']) ? trim($_GET['name']) : '';
$chat_id = isset($_GET['chat_id']) ? trim($_GET['chat_id']) : '';

$result = ['name_exists' => false, 'chat_exists' => false, 'status' => null];

if ($name !== '') {
    $stmt = $conn->prepare("SELECT status FROM hospitals WHERE name = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $result['name_exists'] = true;
        $result['status'] = $row['status'];
    }
}

if ($chat_id !== '') {
    $stmt = $conn->prepare("SELECT id FROM hospitals WHERE chat_id = ? LIMIT 1");
    $stmt->bind_param("s", $chat_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $result['chat_exists'] = true;
    }
}

echo json_encode($result);
?>
