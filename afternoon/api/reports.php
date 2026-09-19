<?php
declare(strict_types=1);

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/response.php';
require __DIR__ . '/../lib/validate.php';
require __DIR__ . '/../lib/pagination.php';

// GET /api/reports.php?page=1&per_page=10&waste_type=general&status=PENDING&q=บางแสน
// คืน { reports: [...], pagination: { page, per_page, total, total_pages } }
// หมายเหตุ contract: เดิม endpoint นี้คืน JSON array เปล่า ๆ ตอนนี้ห่อด้วย envelope
// เพราะ pagination ต้องส่ง total/total_pages กลับไปด้วย
function get_reports(): void
{
    try {
        $paging = pagination_params($_GET);
        $waste_type = report_filter($_GET['waste_type'] ?? ($_GET['type'] ?? null), VALID_WASTE_TYPES, 'waste_type');
        $status = report_filter($_GET['status'] ?? null, VALID_REPORT_STATUSES, 'status');
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
        "SELECT id, location, latitude, longitude, location_source,
                waste_type, amount_kg, detail, status, created_at
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
function cast_report_row(array $row): array
{
    $row['id'] = (int)$row['id'];
    $row['amount_kg'] = (int)$row['amount_kg'];
    $row['latitude'] = $row['latitude'] !== null ? (float)$row['latitude'] : null;
    $row['longitude'] = $row['longitude'] !== null ? (float)$row['longitude'] : null;
    return $row;
}

function post_report(): void
{
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        json_error("Invalid JSON", 400);
    }

    try {
        $clean = validate_report($data);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 422);
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "INSERT INTO reports (location, waste_type, amount_kg, detail, latitude, longitude, location_source)
         VALUES (:location, :waste_type, :amount_kg, :detail, :latitude, :longitude, :location_source)"
    );
    $stmt->execute($clean);
    json_response(["id" => (int)$pdo->lastInsertId()] + $clean, 201);
}

try {
    match ($_SERVER['REQUEST_METHOD']) {
        'GET' => get_reports(),
        'POST' => post_report(),
        default => json_error("Method Not Allowed", 405),
    };
} catch (PDOException $e) {
    json_error("database error", 500);
}
