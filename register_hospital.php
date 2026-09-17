<?php
/*
 * register_hospital.php
 * Stores a hospital registration request made via the Telegram bot.
 *
 * Coordinates are NOT provided here — the admin verifies and enters them
 * on the dashboard (approve_hospital.php) before approving.
 *
 * POST params: name, chat_id (optional), reference (optional)
 * Upserts on unique `name`.
 *
 * SECURITY NOTE: `/hospitals` lists approved hospital names publicly to
 * anyone on Telegram, and this endpoint has no authentication (it's meant
 * to be called by anyone requesting registration). Without the status
 * guard below, a stranger could re-send an already-approved hospital's
 * exact name and silently knock it back to 'pending', removing it from
 * live alert routing until an admin notices and re-approves it. The
 * IF(status = 'approved', status, 'pending') guard keeps an approved
 * hospital approved regardless of how many times its name is re-submitted,
 * while still letting a genuinely new or still-pending name go through.
 */

require 'db.php';

header('Content-Type: application/json');

function respond($ok, $message, $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => $ok ? 'ok' : 'error', 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Use POST', 405);
}

$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$chat_id = isset($_POST['chat_id']) ? trim($_POST['chat_id']) : '';
$reference = isset($_POST['reference']) ? trim($_POST['reference']) : '';

if ($name === '') {
    respond(false, 'Missing required field: name', 400);
}

$name = substr($name, 0, 100);
$chat_id = substr($chat_id, 0, 50);
$reference = substr($reference, 0, 300);

$stmt = $conn->prepare("INSERT INTO hospitals (name, chat_id, reference, status)
                        VALUES (?, ?, ?, 'pending')
                        ON DUPLICATE KEY UPDATE
                            chat_id = VALUES(chat_id),
                            reference = VALUES(reference),
                            status = IF(status = 'approved', status, 'pending')");
$stmt->bind_param("sss", $name, $chat_id, $reference);

if ($stmt->execute()) {
    respond(true, 'Request saved — awaiting admin approval', 200);
} else {
    respond(false, 'Database error', 500);
}
?>
