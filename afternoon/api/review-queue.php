<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../lib/report_photo.php';

const REVIEW_QUEUE_VIEWS = ['review', 'action', 'history'];
const REVIEW_QUEUE_SOURCES = ['citizen', 'ai'];
const REVIEW_QUEUE_AREAS = ['land', 'water'];

function queue_filter(mixed $value, array $allowed, string $name): ?string
{
    if ($value === null || $value === '' || $value === 'all') {
        return null;
    }
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        throw new InvalidArgumentException("$name: ค่าไม่ถูกต้อง");
    }
    return $value;
}

function queue_union_sql(): string
{
    return "SELECT 'citizen' AS source, id, location, NULL AS area_type, record_origin,
            CASE
                WHEN status = 'PENDING' THEN 'pending'
                WHEN status = 'REJECTED' THEN 'rejected'
                ELSE 'confirmed'
            END AS review_status,
            CASE
                WHEN status = 'NEEDS_CHECK' THEN 'needs_check'
                WHEN status = 'RESOLVED' THEN 'resolved'
                ELSE 'none'
            END AS action_status,
            created_at AS first_seen, created_at AS last_seen, reviewed_at, resolved_at,
            1 AS observation_count, NULL AS camera_name, detail, image_path, waste_type, amount_kg
        FROM reports
        UNION ALL
        SELECT 'ai' AS source, id, location, area_type, record_origin,
            review_status, action_status, first_seen, last_seen, reviewed_at, resolved_at,
            observation_count, camera_name, NULL AS detail, NULL AS image_path,
            NULL AS waste_type, NULL AS amount_kg
        FROM vision_incidents";
}

function queue_view_condition(string $view): string
{
    return match ($view) {
        'review' => "review_status = 'pending'",
        'action' => "review_status = 'confirmed' AND action_status = 'needs_check'",
        'history' => "review_status = 'rejected' OR action_status = 'resolved'",
    };
}

function get_review_queue(): void
{
    try {
        $paging = pagination_params($_GET);
        $view = queue_filter($_GET['view'] ?? 'review', REVIEW_QUEUE_VIEWS, 'view') ?? 'review';
        $source = queue_filter($_GET['source'] ?? null, REVIEW_QUEUE_SOURCES, 'source');
        $area = queue_filter($_GET['area_type'] ?? null, REVIEW_QUEUE_AREAS, 'area_type');
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 422);
    }

    $where = ['(' . queue_view_condition($view) . ')'];
    $params = [];
    if ($source !== null) {
        $where[] = 'source = :source';
        $params['source'] = $source;
    }
    if ($area !== null) {
        $where[] = 'area_type = :area_type';
        $params['area_type'] = $area;
    }
    $search = search_like_pattern($_GET['q'] ?? null);
    if ($search !== null) {
        $where[] = '(location LIKE :search_location OR camera_name LIKE :search_camera OR detail LIKE :search_detail)';
        $params['search_location'] = $search;
        $params['search_camera'] = $search;
        $params['search_detail'] = $search;
    }

    $union = queue_union_sql();
    $whereSql = ' WHERE ' . implode(' AND ', $where);
    $pdo = db();

    $count = $pdo->prepare("SELECT COUNT(*) FROM ($union) AS queue_items$whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $limit = $paging['per_page'];
    $offset = $paging['offset'];
    $stmt = $pdo->prepare(
        "SELECT * FROM ($union) AS queue_items$whereSql
         ORDER BY last_seen DESC, observation_count DESC, source, id DESC
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);
    $items = array_map(function (array $row): array {
        $row['id'] = (int)$row['id'];
        $row['observation_count'] = (int)$row['observation_count'];
        $row['amount_kg'] = $row['amount_kg'] !== null ? (int)$row['amount_kg'] : null;
        if ($row['source'] === 'citizen') {
            $row['image_path'] = report_photo_path_or_null($row['image_path']);
        }
        return $row;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $summary = $pdo->query(
        "SELECT COUNT(*) AS total,
            SUM(review_status = 'pending') AS to_review,
            SUM(review_status = 'confirmed' AND action_status = 'needs_check') AS to_act,
            SUM(review_status = 'rejected' OR action_status = 'resolved') AS history
         FROM ($union) AS queue_summary"
    )->fetch(PDO::FETCH_ASSOC);
    foreach ($summary as $key => $value) {
        $summary[$key] = (int)$value;
    }

    json_response([
        'view' => $view,
        'summary' => $summary,
        'items' => $items,
        'pagination' => pagination_meta($paging['page'], $paging['per_page'], $total),
    ]);
}

try {
    match ($_SERVER['REQUEST_METHOD']) {
        'GET' => get_review_queue(),
        default => json_error('Method Not Allowed', 405),
    };
} catch (PDOException $e) {
    json_error('database error', 500);
}
