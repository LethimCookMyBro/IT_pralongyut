<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/vision_config.php';
require_once __DIR__ . '/../lib/vision_evidence.php';

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

    // ภาพหลักฐาน: เอา observation ล่าสุดที่มีภาพ (ใหม่ก่อน) ไม่เกิน 5 รายการ
    // อ่านอย่างเดียว ไม่เปลี่ยน contract เดิมของ key incident/aggregation
    $ev = db()->prepare(
        "SELECT id, image_path, captured_at, detected_count, max_confidence
         FROM vision_observations
         WHERE incident_id = :id AND image_path IS NOT NULL
         ORDER BY captured_at DESC, id DESC
         LIMIT 5"
    );
    $ev->execute(['id' => $id]);

    $evidence = [];
    foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $safe = evidence_path_or_null($item['image_path']);
        if ($safe === null) {
            continue;
        }
        $evidence[] = [
            'observation_id' => (int)$item['id'],
            'image_path' => $safe,
            'captured_at' => $item['captured_at'],
            'detected_count' => (int)$item['detected_count'],
            'max_confidence' => $item['max_confidence'] !== null ? (float)$item['max_confidence'] : null,
        ];
    }

    json_response([
        'incident' => $row,
        'evidence' => $evidence,
        'aggregation' => [
            'window_minutes' => INCIDENT_AGGREGATION_WINDOW_MINUTES,
            'status' => 'PROTOTYPE / UNCALIBRATED',
            'rule' => 'same camera/location/area/source/origin + pending incident within time window',
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
