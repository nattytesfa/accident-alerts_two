<?php
/*
 * delete_hospital.php
 * Lets the admin permanently delete a hospital (approved, pending or rejected).
 *
 * POST params: id
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
if ($id <= 0) {
    respond(false, 'Invalid request', 400);
}

$stmt = $conn->prepare("SELECT name, chat_id FROM hospitals WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    respond(false, 'Hospital not found', 404);
}

/* Drop any queued approval/rejection notices for this hospital too. */
$stmt2 = $conn->prepare("DELETE FROM hospital_notifications WHERE hospital = ?");
$stmt2->bind_param("s", $row['name']);
$stmt2->execute();

/* Tell the registrant's chat that it was deleted and they can re-register. */
if (!empty($row['chat_id'])) {
    $stmtN = $conn->prepare("INSERT INTO hospital_notifications (hospital, chat_id, type) VALUES (?, ?, 'deleted')");
    $stmtN->bind_param("ss", $row['name'], $row['chat_id']);
    $stmtN->execute();
}

$stmt3 = $conn->prepare("DELETE FROM hospitals WHERE id = ?");
$stmt3->bind_param("i", $id);
$stmt3->execute();

respond(true, 'Hospital deleted');
?>