<?php
/*
 * pending_notifications.php
 * Returns approved/rejected notifications not yet delivered.
 * Consumed by telegram_bridge.py (it marks them via mark_notifications.php).
 */

require 'db.php';

header('Content-Type: application/json');

$rows = [];
$result = $conn->query("SELECT id, hospital, chat_id, type
                        FROM hospital_notifications
                        WHERE delivered = 0
                        ORDER BY id ASC");

if ($result) {
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
}

echo json_encode($rows);
?>