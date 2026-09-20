<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/vision_config.php';
require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../lib/vision_evidence.php';

const VALID_AREA_TYPES = ["land", "water"];
const VALID_SOURCE_MODES = ["replay", "camera", "cctv"];
const VALID_RECORD_ORIGINS = ["demo_seed", "detector_run"];
const VALID_REVIEW_STATUSES = ["pending", "confirmed", "rejected"];
const VALID_ACTION_STATUSES = ["none", "needs_check", "resolved"];

// กลุ่มงานของเจ้าหน้าที่ — แบ่งคิวตาม "ต้องทำอะไรต่อ" ไม่ใช่ตามสถานะดิบ
// ใช้คอลัมน์เดิมทั้งหมด ไม่ได้เพิ่มคอลัมน์หรือเปลี่ยน flow การตรวจ
// (idx_incident_queue (review_status, action_status, last_seen) รองรับเงื่อนไขเหล่านี้อยู่แล้ว)
const VIEW_CONDITIONS = [
    // ต้องตรวจ — ยังรอคนกด ยืนยัน/ปฏิเสธ
    "review" => "review_status = 'pending'",
    // ต้องดำเนินการ — ยืนยันแล้วแต่ยังไม่ปิดงาน
    "action" => "review_status = 'confirmed' AND action_status <> 'resolved'",
    // ประวัติ — ปิดงานแล้ว หรือถูกปฏิเสธ (ปฏิเสธเป็นปลายทาง)
    "history" => "(action_status = 'resolved' OR review_status = 'rejected')",
];

function validate_vision_observation(array $data): array
{
    $camera_name = $data["camera_name"] ?? null;
    if (!is_string($camera_name)) {
        throw new InvalidArgumentException("camera_name: ต้องเป็น string");
    }
    $camera_name = trim($camera_name);
    if (mb_strlen($camera_name) < 1 || mb_strlen($camera_name) > 100) {
        throw new InvalidArgumentException("camera_name: ยาวต้อง 1-100 ตัวอักษร");
    }

    $location = $data["location"] ?? null;
    if (!is_string($location)) {
        throw new InvalidArgumentException("location: ต้องเป็น string");
    }
    $location = trim($location);
    if (mb_strlen($location) < 1 || mb_strlen($location) > 100) {
        throw new InvalidArgumentException("location: ยาวต้อง 1-100 ตัวอักษร");
    }

    $area_type = $data["area_type"] ?? null;
    if (!is_string($area_type) || !in_array($area_type, VALID_AREA_TYPES, true)) {
        throw new InvalidArgumentException("area_type: ต้องเป็นหนึ่งใน " . implode(", ", VALID_AREA_TYPES));
    }

    $detected_count = $data["detected_count"] ?? null;
    if (is_bool($detected_count) || !is_int($detected_count) || $detected_count < 0) {
        throw new InvalidArgumentException("detected_count: ต้องเป็นจำนวนเต็ม >= 0");
    }

    $max_confidence = $data["max_confidence"] ?? null;
    if ($max_confidence !== null) {
        if (is_bool($max_confidence) || !is_numeric($max_confidence)) {
            throw new InvalidArgumentException("max_confidence: ต้องเป็นตัวเลข");
        }
        $max_confidence = (float)$max_confidence;
        if ($max_confidence < 0 || $max_confidence > 1) {
            throw new InvalidArgumentException("max_confidence: ต้องอยู่ช่วง 0-1");
        }
    }

    $source_mode = $data["source_mode"] ?? "replay";
    if (!is_string($source_mode) || !in_array($source_mode, VALID_SOURCE_MODES, true)) {
        throw new InvalidArgumentException("source_mode: ต้องเป็นหนึ่งใน " . implode(", ", VALID_SOURCE_MODES));
    }

    // record_origin แยกจาก source_mode: replay อาจเป็นทั้ง seed demo หรือผล inference จริง
    $record_origin = $data["record_origin"] ?? "demo_seed";
    if (!is_string($record_origin) || !in_array($record_origin, VALID_RECORD_ORIGINS, true)) {
        throw new InvalidArgumentException("record_origin: ต้องเป็นหนึ่งใน " . implode(", ", VALID_RECORD_ORIGINS));
    }

    // ภาพหลักฐานของการตรวจครั้งนี้ — optional
    // validate_evidence_image_path() บังคับให้เหลือแค่ไฟล์รูปในโฟลเดอร์ที่อนุญาต
    $image_path = validate_evidence_image_path($data["image_path"] ?? null);

    return [
        "camera_name" => $camera_name,
        "location" => $location,
        "area_type" => $area_type,
        "detected_count" => $detected_count,
        "max_confidence" => $max_confidence,
        "source_mode" => $source_mode,
        "record_origin" => $record_origin,
        "image_path" => $image_path,
    ];
}

function validate_filter(?string $value, array $allowed, string $name): ?string
{
    if ($value === null) {
        return null;
    }
    if (!in_array($value, $allowed, true)) {
        throw new InvalidArgumentException("$name: ค่าไม่ถูกต้อง");
    }
    return $value;
}

function get_vision(): void
{
    $pdo = db();

    try {
        $review_status = validate_filter($_GET['review_status'] ?? null, VALID_REVIEW_STATUSES, 'review_status');
        $action_status = validate_filter($_GET['action_status'] ?? null, VALID_ACTION_STATUSES, 'action_status');
        $area_type = validate_filter($_GET['area_type'] ?? null, VALID_AREA_TYPES, 'area_type');
        $view = validate_filter($_GET['view'] ?? null, array_keys(VIEW_CONDITIONS), 'view');
        $paging = pagination_params($_GET);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 422);
    }

    // ค้นหาจากสถานที่ หรือชื่อกล้อง/จุดตรวจ
    $search = search_like_pattern($_GET['q'] ?? null);

    $where = " WHERE 1=1";
    $params = [];

    if ($search !== null) {
        $where .= " AND (location LIKE :search_location OR camera_name LIKE :search_camera)";
        $params['search_location'] = $search;
        $params['search_camera'] = $search;
    }

    if ($review_status !== null) {
        $where .= " AND review_status = :review_status";
        $params['review_status'] = $review_status;
    }
    if ($action_status !== null) {
        $where .= " AND action_status = :action_status";
        $params['action_status'] = $action_status;
    }
    if ($area_type !== null) {
        $where .= " AND area_type = :area_type";
        $params['area_type'] = $area_type;
    }
    if ($view !== null) {
        // ค่าคงที่จาก VIEW_CONDITIONS เท่านั้น ($view ผ่าน validate_filter มาแล้ว) ไม่มี input ของผู้ใช้ต่อเข้า SQL
        $where .= " AND " . VIEW_CONDITIONS[$view];
    }

    // total นับด้วย filter/search ชุดเดียวกับที่ใช้ดึงรายการ เพื่อให้ pagination ตรงกัน
    $count = $pdo->prepare("SELECT COUNT(*) FROM vision_incidents$where");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $sql = "SELECT * FROM vision_incidents$where";

    // Priority Queue แบบอธิบายได้:
    // pending ก่อน จากนั้น observation_count -> peak_detected_count -> recency
    // ไม่มี weighted score และไม่มีการ claim ว่า calibrated/validated.
    $sql .= " ORDER BY
        CASE
            WHEN review_status = 'pending' THEN 0
            WHEN review_status = 'confirmed' AND action_status = 'needs_check' THEN 1
            ELSE 2
        END,
        CASE WHEN review_status = 'pending' THEN observation_count END DESC,
        CASE WHEN review_status = 'pending' THEN peak_detected_count END DESC,
        last_seen DESC,
        id DESC";

    // LIMIT/OFFSET แทรกเป็นตัวเลขตรง ๆ ได้ เพราะ pagination_params ตรวจเป็น int > 0 มาแล้ว
    $limit = $paging['per_page'];
    $offset = $paging['offset'];
    $sql .= " LIMIT $limit OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $incidents = array_map('cast_incident_row', $stmt->fetchAll(PDO::FETCH_ASSOC));

    $summary = $pdo->query(
        "SELECT
            COUNT(*) AS total,
            SUM(review_status = 'pending') AS pending_review,
            SUM(review_status = 'confirmed') AS confirmed,
            SUM(review_status = 'rejected') AS rejected,
            SUM(action_status = 'needs_check') AS needs_check,
            SUM(action_status = 'resolved') AS resolved,
            SUM(" . VIEW_CONDITIONS['review'] . ") AS to_review,
            SUM(" . VIEW_CONDITIONS['action'] . ") AS to_act,
            SUM(" . VIEW_CONDITIONS['history'] . ") AS history
         FROM vision_incidents"
    )->fetch(PDO::FETCH_ASSOC);

    foreach ($summary as $k => $v) {
        $summary[$k] = (int)$v;
    }

    json_response([
        'aggregation' => [
            'window_minutes' => INCIDENT_AGGREGATION_WINDOW_MINUTES,
            'status' => 'PROTOTYPE / UNCALIBRATED',
            'rule' => 'same camera/location/area/source/origin + pending incident within time window',
        ],
        'priority_rule' => 'pending first; observation_count desc; peak_detected_count desc; last_seen desc',
        'summary' => $summary,
        'view' => $view,
        'incidents' => $incidents,
        'pagination' => pagination_meta($paging['page'], $paging['per_page'], $total),
    ]);
}

// PDO คืนตัวเลขทั้งหมดเป็น string — cast ให้ frontend ไม่ต้องเดาชนิดข้อมูล
function cast_incident_row(array $row): array
{
    foreach (['id', 'observation_count', 'first_detected_count', 'latest_detected_count', 'peak_detected_count'] as $field) {
        $row[$field] = (int)$row[$field];
    }
    $row['max_confidence'] = $row['max_confidence'] !== null ? (float)$row['max_confidence'] : null;
    return $row;
}

function post_vision(): void
{
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        json_error("Invalid JSON", 400);
    }

    try {
        $clean = validate_vision_observation($data);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 422);
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $incident_id = null;
        $aggregated = false;

        // detected_count = 0 เก็บเป็น raw observation ได้ แต่ไม่สร้าง Possible Waste Incident
        if ($clean['detected_count'] > 0) {
            $window = (int)INCIDENT_AGGREGATION_WINDOW_MINUTES;
            $find = $pdo->prepare(
                "SELECT id
                 FROM vision_incidents
                 WHERE camera_name = :camera_name
                   AND location = :location
                   AND area_type = :area_type
                   AND source_mode = :source_mode
                   AND record_origin = :record_origin
                   AND review_status = 'pending'
                   AND last_seen >= DATE_SUB(NOW(), INTERVAL $window MINUTE)
                 ORDER BY last_seen DESC, id DESC
                 LIMIT 1
                 FOR UPDATE"
            );
            $find->execute([
                'camera_name' => $clean['camera_name'],
                'location' => $clean['location'],
                'area_type' => $clean['area_type'],
                'source_mode' => $clean['source_mode'],
                'record_origin' => $clean['record_origin'],
            ]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $incident_id = (int)$existing['id'];
                $update = $pdo->prepare(
                    "UPDATE vision_incidents
                     SET last_seen = NOW(),
                         observation_count = observation_count + 1,
                         latest_detected_count = :detected_count,
                         peak_detected_count = GREATEST(peak_detected_count, :detected_count_peak),
                         max_confidence = CASE
                             WHEN :max_confidence_check IS NULL THEN max_confidence
                             WHEN max_confidence IS NULL THEN :max_confidence_new
                             ELSE GREATEST(max_confidence, :max_confidence_peak)
                         END
                     WHERE id = :id"
                );
                $update->execute([
                    'detected_count' => $clean['detected_count'],
                    'detected_count_peak' => $clean['detected_count'],
                    'max_confidence_check' => $clean['max_confidence'],
                    'max_confidence_new' => $clean['max_confidence'],
                    'max_confidence_peak' => $clean['max_confidence'],
                    'id' => $incident_id,
                ]);
                $aggregated = true;
            } else {
                $create = $pdo->prepare(
                    "INSERT INTO vision_incidents
                        (camera_name, location, area_type, source_mode, record_origin,
                         first_seen, last_seen, observation_count,
                         first_detected_count, latest_detected_count, peak_detected_count,
                         max_confidence)
                     VALUES
                        (:camera_name, :location, :area_type, :source_mode, :record_origin,
                         NOW(), NOW(), 1,
                         :first_detected_count, :latest_detected_count, :peak_detected_count,
                         :max_confidence)"
                );
                $create->execute([
                    'camera_name' => $clean['camera_name'],
                    'location' => $clean['location'],
                    'area_type' => $clean['area_type'],
                    'source_mode' => $clean['source_mode'],
                    'record_origin' => $clean['record_origin'],
                    'first_detected_count' => $clean['detected_count'],
                    'latest_detected_count' => $clean['detected_count'],
                    'peak_detected_count' => $clean['detected_count'],
                    'max_confidence' => $clean['max_confidence'],
                ]);
                $incident_id = (int)$pdo->lastInsertId();
            }
        }

        $observation = $pdo->prepare(
            "INSERT INTO vision_observations
                (incident_id, camera_name, location, area_type, detected_count, max_confidence, source_mode, record_origin, image_path)
             VALUES
                (:incident_id, :camera_name, :location, :area_type, :detected_count, :max_confidence, :source_mode, :record_origin, :image_path)"
        );
        $observation->execute(['incident_id' => $incident_id] + $clean);
        $observation_id = (int)$pdo->lastInsertId();

        $obs_stmt = $pdo->prepare("SELECT * FROM vision_observations WHERE id = :id");
        $obs_stmt->execute(['id' => $observation_id]);
        $observation_row = $obs_stmt->fetch(PDO::FETCH_ASSOC);

        $incident_row = null;
        if ($incident_id !== null) {
            $incident_stmt = $pdo->prepare("SELECT * FROM vision_incidents WHERE id = :id");
            $incident_stmt->execute(['id' => $incident_id]);
            $incident_row = $incident_stmt->fetch(PDO::FETCH_ASSOC);
        }

        $pdo->commit();

        json_response([
            'observation' => $observation_row,
            'incident' => $incident_row,
            'aggregated_into_existing_incident' => $aggregated,
        ], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

try {
    match ($_SERVER['REQUEST_METHOD']) {
        'GET' => get_vision(),
        'POST' => post_vision(),
        default => json_error("Method Not Allowed", 405),
    };
} catch (PDOException $e) {
    json_error("database error", 500);
}
