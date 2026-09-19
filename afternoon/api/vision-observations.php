<?php
declare(strict_types=1);

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/response.php';

// Drill-down endpoint: raw observations are evidence/source records.
// Human review happens at incident level via /api/vision-review.php.
function get_observations(): void
{
    $incident_id = $_GET['incident_id'] ?? null;
    if (!is_string($incident_id) && !is_int($incident_id)) {
        json_error("incident_id: ต้องระบุ", 422);
    }
    if (is_string($incident_id) && !ctype_digit($incident_id)) {
        json_error("incident_id: ต้องเป็นจำนวนเต็ม", 422);
    }
    $incident_id = (int)$incident_id;
    if ($incident_id < 1) {
        json_error("incident_id: ต้องมากกว่า 0", 422);
    }

    $stmt = db()->prepare(
        "SELECT id, incident_id, camera_name, location, area_type,
                detected_count, max_confidence, source_mode, captured_at
         FROM vision_observations
         WHERE incident_id = :incident_id
         ORDER BY captured_at ASC, id ASC
         LIMIT 200"
    );
    $stmt->execute(['incident_id' => $incident_id]);

    json_response([
        'incident_id' => $incident_id,
        'observations' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

try {
    match ($_SERVER['REQUEST_METHOD']) {
        'GET' => get_observations(),
        default => json_error("Method Not Allowed", 405),
    };
} catch (PDOException $e) {
    json_error("database error", 500);
}
