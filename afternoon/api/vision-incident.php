<?php
declare(strict_types=1);

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/response.php';
require __DIR__ . '/../lib/vision_config.php';

// GET /api/vision-incident.php?id=<incident_id>
// อ่าน incident เดียวสำหรับหน้ารายละเอียด (incident.html)
// read-only — การเปลี่ยนสถานะยังอยู่ที่ POST /api/vision-review.php เท่านั้น
function get_incident(): void
{
    $id = $_GET['id'] ?? null;
    if (!is_string($id) && !is_int($id)) {
        json_error("id: ต้องระบุ", 422);
    }
    if (is_string($id) && !ctype_digit($id)) {
        json_error("id: ต้องเป็นจำนวนเต็ม", 422);
    }
    $id = (int)$id;
    if ($id < 1) {
        json_error("id: ต้องมากกว่า 0", 422);
    }

    $stmt = db()->prepare("SELECT * FROM vision_incidents WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_error("ไม่พบ incident นี้", 404);
    }

    foreach (['id', 'observation_count', 'first_detected_count', 'latest_detected_count', 'peak_detected_count'] as $field) {
        $row[$field] = (int)$row[$field];
    }
    $row['max_confidence'] = $row['max_confidence'] !== null ? (float)$row['max_confidence'] : null;

    json_response([
        'incident' => $row,
        'aggregation' => [
            'window_minutes' => INCIDENT_AGGREGATION_WINDOW_MINUTES,
            'status' => 'PROTOTYPE / UNCALIBRATED',
            'rule' => 'same camera/location/area/source + pending incident within time window',
        ],
    ]);
}

try {
    match ($_SERVER['REQUEST_METHOD']) {
        'GET' => get_incident(),
        default => json_error("Method Not Allowed", 405),
    };
} catch (PDOException $e) {
    json_error("database error", 500);
}
