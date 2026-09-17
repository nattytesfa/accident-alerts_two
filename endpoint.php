<?php
/*
 * endpoint.php
 * Receives alert data (from the Arduino/Telegram bridge or any HTTP client)
 * and stores it in the `alerts` table.
 *
 * Example request:
 *   POST /accident-alerts/endpoint.php
 *   Content-Type: application/x-www-form-urlencoded
 *
 *   lat=38.7617&lng=-9.1394&hospital=Central+General+Hospital
 *
 * Responds with JSON so the Arduino can confirm delivery.
 */

require 'db.php';

header('Content-Type: application/json');

function respond($ok, $message, $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => $ok ? 'ok' : 'error', 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Use POST for this endpoint', 405);
}

$lat = isset($_POST['lat']) ? trim($_POST['lat']) : '';
$lng = isset($_POST['lng']) ? trim($_POST['lng']) : '';
$hospital = isset($_POST['hospital']) ? trim($_POST['hospital']) : '';

if ($lat === '' || $lng === '' || $hospital === '') {
    respond(false, 'Missing required fields: lat, lng, hospital', 400);
}

if (!is_numeric($lat) || !is_numeric($lng)) {
    respond(false, 'lat and lng must be numeric', 400);
}

// Validate ranges
if ((float)$lat < -90 || (float)$lat > 90 || (float)$lng < -180 || (float)$lng > 180) {
    respond(false, 'lat/lng out of range', 400);
}

if (strlen($hospital) > 100) {
    $hospital = substr($hospital, 0, 100);
}

$stmt = $conn->prepare("INSERT INTO alerts (lat, lng, hospital) VALUES (?, ?, ?)");
$stmt->bind_param("dds", $lat, $lng, $hospital);

if ($stmt->execute()) {
    respond(true, 'Alert stored', 200);
} else {
    respond(false, 'Database error', 500);
}
?>
