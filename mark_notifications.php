<?php
/*
 * mark_notifications.php
 * Marks notifications as delivered after the bridge sends them.
 * POST param: ids = comma-separated list of notification ids.
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

$raw = isset($_POST['ids']) ? trim($_POST['ids']) : '';
$ids = array_filter(array_map('intval', explode(',', $raw)));

if (!$ids) {
    respond(true, 'No ids', 200);
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

$stmt = $conn->prepare("UPDATE hospital_notifications SET delivered = 1 WHERE id IN ($placeholders)");
$stmt->bind_param($types, ...$ids);
$stmt->execute();

respond(true, 'Marked ' . $stmt->affected_rows . ' as delivered');
?>