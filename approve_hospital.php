<?php
/*
 * approve_hospital.php
 * Approves or rejects a pending hospital (admin only).
 *
 * On APPROVE the admin supplies verified coordinates (lat, lng POST params) —
 * they are required when the hospital has none yet.
 * Queues a Telegram notification for the hospital's registered chat,
 * which telegram_bridge.py picks up and sends.
 *
 * POST params: id, action = approve | reject, lat (opt), lng (opt)
 */

require 'auth_check.php';
require 'db.php';

header('Content-Type: application/json');

function respond($ok, $message, $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => $ok ? 'ok' : 'error', 'message' => $message]);
    exit;
}

if (!is_admin($conn)) {
    respond(false, 'Admin access required', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Use POST', 405);
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$lat = isset($_POST['lat']) ? trim($_POST['lat']) : '';
$lng = isset($_POST['lng']) ? trim($_POST['lng']) : '';

if ($id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    respond(false, 'Invalid request', 400);
}

/* Grab hospital info before it changes. */
$stmt = $conn->prepare("SELECT name, chat_id, lat, lng FROM hospitals WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
if (!$row) {
    respond(false, 'Hospital not found', 404);
}

if ($action === 'approve') {
    $setLat = $row['lat'];
    $setLng = $row['lng'];

    if ($lat !== '' || $lng !== '') {
        if ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
            respond(false, 'Provide both latitude and longitude', 400);
        }
        if ((float)$lat < -90 || (float)$lat > 90 || (float)$lng < -180 || (float)$lng > 180) {
            respond(false, 'lat/lng out of range', 400);
        }
        $setLat = $lat;
        $setLng = $lng;
    }

    if ($setLat === null || $setLng === null || $setLat === '' || $setLng === '') {
        respond(false, 'Set the hospital location before approving', 400);
    }

    $stmt = $conn->prepare("UPDATE hospitals SET status = 'approved', lat = ?, lng = ? WHERE id = ?");
    $stmt->bind_param("ddi", $setLat, $setLng, $id);
} else {
    $stmt = $conn->prepare("UPDATE hospitals SET status = 'rejected' WHERE id = ?");
    $stmt->bind_param("i", $id);
}
$stmt->execute();

if ($stmt->affected_rows <= 0) {
    respond(false, 'No change', 409);
}

/* Queue the notification for the registrant's chat. */
if (!empty($row['chat_id'])) {
    $type = ($action === 'approve') ? 'approved' : 'rejected';
    $stmt2 = $conn->prepare("INSERT INTO hospital_notifications (hospital, chat_id, type) VALUES (?, ?, ?)");
    $stmt2->bind_param("sss", $row['name'], $row['chat_id'], $type);
    $stmt2->execute();
}

respond(true, $action === 'approve' ? 'Hospital approved' : 'Hospital rejected');
?>