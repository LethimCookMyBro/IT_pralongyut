<?php
declare(strict_types=1);

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/response.php';
require __DIR__ . '/../lib/pagination.php';

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

    try {
        // timeline ของหน้ารายละเอียดอ่านทีเดียวได้มากกว่าตาราง list ปกติ
        $paging = pagination_params($_GET, 50);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 422);
    }

    $pdo = db();
    $count = $pdo->prepare("SELECT COUNT(*) FROM vision_observations WHERE incident_id = :incident_id");
    $count->execute(['incident_id' => $incident_id]);
    $total = (int)$count->fetchColumn();

    // LIMIT/OFFSET แทรกเป็นตัวเลขตรง ๆ ได้ เพราะ pagination_params ตรวจเป็น int > 0 มาแล้ว
    $limit = $paging['per_page'];
    $offset = $paging['offset'];
    $stmt = $pdo->prepare(
        "SELECT id, incident_id, camera_name, location, area_type,
                detected_count, max_confidence, source_mode, captured_at
         FROM vision_observations
         WHERE incident_id = :incident_id
         ORDER BY captured_at ASC, id ASC
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute(['incident_id' => $incident_id]);

    $observations = array_map(static function (array $row): array {
        $row['id'] = (int)$row['id'];
        $row['incident_id'] = $row['incident_id'] !== null ? (int)$row['incident_id'] : null;
        $row['detected_count'] = (int)$row['detected_count'];
        $row['max_confidence'] = $row['max_confidence'] !== null ? (float)$row['max_confidence'] : null;
        return $row;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    json_response([
        'incident_id' => $incident_id,
        'observations' => $observations,
        'pagination' => pagination_meta($paging['page'], $paging['per_page'], $total),
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
