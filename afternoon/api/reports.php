<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/validate.php';
require_once __DIR__ . '/../lib/pagination.php';

// GET /api/reports.php?page=1&per_page=10&waste_type=general&status=PENDING&record_origin=citizen&q=บางแสน
// คืน { reports: [...], pagination: { page, per_page, total, total_pages } }
// หมายเหตุ contract: เดิม endpoint นี้คืน JSON array เปล่า ๆ ตอนนี้ห่อด้วย envelope
// เพราะ pagination ต้องส่ง total/total_pages กลับไปด้วย
function get_reports(): void
{
    try {
        $paging = pagination_params($_GET);
        $waste_type = report_filter($_GET['waste_type'] ?? ($_GET['type'] ?? null), VALID_WASTE_TYPES, 'waste_type');
        $status = report_filter($_GET['status'] ?? null, VALID_REPORT_STATUSES, 'status');
        // แยกเรื่องแจ้งจริงออกจากแถวตัวอย่างสำหรับเดโม — ใช้ทั้งใน UI และ tools/clear_demo.php
        $origin = report_filter($_GET['record_origin'] ?? null, VALID_REPORT_ORIGINS, 'record_origin');
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 422);
    }

    $search = search_like_pattern($_GET['q'] ?? null);

    $where = ' WHERE 1=1';
    $params = [];
    if ($waste_type !== null) {
        $where .= ' AND waste_type = :waste_type';
        $params['waste_type'] = $waste_type;
    }
    if ($status !== null) {
        $where .= ' AND status = :status';
        $params['status'] = $status;
    }
    if ($origin !== null) {
        $where .= ' AND record_origin = :record_origin';
        $params['record_origin'] = $origin;
    }
    if ($search !== null) {
        $where .= ' AND location LIKE :search';
        $params['search'] = $search;
    }

    $pdo = db();
    $count = $pdo->prepare("SELECT COUNT(*) FROM reports$where");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    // LIMIT/OFFSET แทรกเป็นตัวเลขตรง ๆ ได้ เพราะ pagination_params ตรวจเป็น int > 0 มาแล้ว
    $limit = $paging['per_page'];
    $offset = $paging['offset'];
    $stmt = $pdo->prepare(
        "SELECT id, location, latitude, longitude, location_source, record_origin,
                waste_type, amount_kg, detail, image_path, status, created_at
         FROM reports$where
         ORDER BY created_at DESC, id DESC
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);
    $rows = array_map('cast_report_row', $stmt->fetchAll(PDO::FETCH_ASSOC));

    json_response([
        'reports' => $rows,
        'pagination' => pagination_meta($paging['page'], $paging['per_page'], $total),
    ]);
}

function report_filter(mixed $value, array $allowed, string $name): ?string
{
    if ($value === null || $value === '' || $value === 'all') {
        return null;
    }
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        throw new InvalidArgumentException("$name: ค่าไม่ถูกต้อง");
    }
    return $value;
}

// PDO คืน DECIMAL เป็น string — ส่ง JSON เป็น number/null ให้ frontend ใช้ตรง ๆ ได้
// amount_kg ต้องคง null ไว้ ห้าม cast เป็น 0: "ไม่รู้" กับ "ศูนย์กิโล" ไม่ใช่เรื่องเดียวกัน
// image_path ตรวจซ้ำตอนอ่าน — แถวที่ถูกแก้มือหรือไฟล์หาย (เช่น Railway redeploy) กลายเป็น null
function cast_report_row(array $row): array
{
    $row['id'] = (int)$row['id'];
    $row['amount_kg'] = $row['amount_kg'] !== null ? (int)$row['amount_kg'] : null;
    $row['latitude'] = $row['latitude'] !== null ? (float)$row['latitude'] : null;
    $row['longitude'] = $row['longitude'] !== null ? (float)$row['longitude'] : null;
    $row['image_path'] = report_photo_path_or_null($row['image_path'] ?? null);
    return $row;
}

// POST รับได้สองแบบ:
//   application/json       — ไม่มีรูป (worker/สคริปต์/ฟอร์มที่ผู้ใช้ไม่ได้แนบรูป)
//   multipart/form-data    — มีรูป, field ชื่อ photo
// ทั้งสองทางผ่าน validate_report() ตัวเดียวกัน จึงได้กฎเดียวกันเสมอ
function post_report(): void
{
    $saved_photo = null;
    $is_multipart = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');

    if ($is_multipart) {
        $data = $_POST;
        try {
            // บันทึกไฟล์ก่อน แล้วค่อยเอา path ที่ระบบตั้งชื่อเองเข้า validate
            // ถ้ารูปใช้ไม่ได้ ต้องตอบ 422 ไม่ใช่เงียบ ๆ บันทึกเรื่องแจ้งโดยไม่มีรูป
            if (isset($data['photo'])) {
                throw new InvalidArgumentException('photo: malformed upload');
            }
            $saved_photo = save_report_photo($_FILES['photo'] ?? null);
            $data['image_path'] = $saved_photo;
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 422);
        }
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            json_error("Invalid JSON", 400);
        }
        // Only this request's uploaded file may be attached to a new report.
        $data['image_path'] = null;
    }

    try {
        $clean = validate_report($data);
    } catch (InvalidArgumentException $e) {
        if ($saved_photo !== null) {
            @unlink(__DIR__ . '/../' . $saved_photo);
        }
        json_error($e->getMessage(), 422);
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            "INSERT INTO reports (location, waste_type, amount_kg, detail, image_path,
                                  latitude, longitude, location_source, record_origin)
             VALUES (:location, :waste_type, :amount_kg, :detail, :image_path,
                     :latitude, :longitude, :location_source, :record_origin)"
        );
        $stmt->execute($clean);
    } catch (Throwable $e) {
        if ($saved_photo !== null) {
            @unlink(__DIR__ . '/../' . $saved_photo);
        }
        throw $e;
    }
    json_response(["id" => (int)$pdo->lastInsertId()] + $clean, 201);
}

try {
    match ($_SERVER['REQUEST_METHOD']) {
        'GET' => get_reports(),
        'POST' => post_report(),
        default => json_error("Method Not Allowed", 405),
    };
} catch (PDOException $e) {
    error_log('reports API: ' . $e->getMessage());
    json_error("database error", 500);
}
