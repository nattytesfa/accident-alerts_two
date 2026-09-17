<?php
/*
 * add_hospital.php
 * Lets the admin add an approved hospital manually (no bot request needed).
 *
 * POST params: name, lat, lng, chat_id (optional)
 * Inserts straight into the approved list so the bridge can route to it.
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

$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$lat = isset($_POST['lat']) ? trim($_POST['lat']) : '';
$lng = isset($_POST['lng']) ? trim($_POST['lng']) : '';
$chat_id = isset($_POST['chat_id']) ? trim($_POST['chat_id']) : '';

if ($name === '' || $lat === '' || $lng === '') {
    respond(false, 'Name, latitude and longitude are required', 400);
}
if (!is_numeric($lat) || !is_numeric($lng)) {
    respond(false, 'Coordinates must be numeric', 400);
}
if ((float)$lat < -90 || (float)$lat > 90 || (float)$lng < -180 || (float)$lng > 180) {
    respond(false, 'lat/lng out of range', 400);
}

$check = $conn->prepare("SELECT id FROM hospitals WHERE name = ?");
$check->bind_param("s", $name);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    respond(false, 'A hospital with that name already exists', 409);
}

$stmt = $conn->prepare("INSERT INTO hospitals (name, lat, lng, chat_id, status) VALUES (?, ?, ?, ?, 'approved')");
$stmt->bind_param("sdds", $name, $lat, $lng, $chat_id);
if ($stmt->execute()) {
    respond(true, 'Hospital added');
}
respond(false, 'Could not add the hospital — try again', 500);
?>