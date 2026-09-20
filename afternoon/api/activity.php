<?php
declare(strict_types=1);

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/response.php';
require __DIR__ . '/../lib/pagination.php';
require __DIR__ . '/../lib/activity.php';

// GET /api/activity.php?days=90&kind=report&q=บางแสน&page=1&per_page=20
// คืน { activities: [...], days, kind, pagination: { page, per_page, total, total_pages } }
//
// ไม่มีตาราง log แยก — ไทม์ไลน์นี้ derive จาก reports + vision_incidents (ดู lib/activity.php)
// จึงเห็นได้เฉพาะเหตุการณ์ที่มีคอลัมน์เวลาเก็บไว้จริง ไม่มีการเติมประวัติย้อนหลังเทียม
function get_activity(): void
{
    try {
        $paging = pagination_params($_GET, ACTIVITY_DEFAULT_PER_PAGE);
        $days = activity_days($_GET['days'] ?? null);
        $kind = activity_kind($_GET['kind'] ?? null);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 422);
    }

    $search = search_like_pattern($_GET['q'] ?? null);
    $where = $search !== null ? ' WHERE location LIKE :search' : '';
    $params = $search !== null ? ['search' => $search] : [];

    // activity_sql() ฝัง $days ที่ตรวจเป็น int แล้ว ไม่มีค่าจากผู้ใช้ต่อเข้า SQL ตรง ๆ
    $timeline = activity_sql($kind, $days);

    $pdo = db();
    $count = $pdo->prepare("SELECT COUNT(*) FROM ($timeline) AS activity$where");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $limit = $paging['per_page'];
    $offset = $paging['offset'];
    $stmt = $pdo->prepare(
        "SELECT * FROM ($timeline) AS activity$where
         ORDER BY occurred_at DESC, ref_id DESC
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);

    json_response([
        'activities' => array_map('activity_cast_row', $stmt->fetchAll(PDO::FETCH_ASSOC)),
        'days' => $days,
        'kind' => $kind ?? 'all',
        'pagination' => pagination_meta($paging['page'], $paging['per_page'], $total),
    ]);
}

try {
    match ($_SERVER['REQUEST_METHOD']) {
        'GET' => get_activity(),
        default => json_error('Method Not Allowed', 405),
    };
} catch (PDOException $e) {
    json_error('database error', 500);
}
