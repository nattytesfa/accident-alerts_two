<?php
/*
 * hospitals_sync.php
 * Returns the APPROVED hospitals as JSON.
 * Consumed by telegram_bridge.py to route alerts to the nearest hospital.
 */

require 'db.php';

header('Content-Type: application/json');

$rows = [];
$result = $conn->query("SELECT id, name, lat, lng, chat_id
                        FROM hospitals
                        WHERE status = 'approved' AND lat IS NOT NULL AND lng IS NOT NULL
                        ORDER BY name ASC");

if ($result) {
    while ($r = $result->fetch_assoc()) {
        $rows[] = [
            'id'     => (int)$r['id'],
            'name'   => $r['name'],
            'lat'    => (float)$r['lat'],
            'lng'    => (float)$r['lng'],
            'chat_id'=> $r['chat_id'],
        ];
    }
}

echo json_encode($rows);
?>