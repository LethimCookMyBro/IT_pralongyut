<?php
declare(strict_types=1);

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/response.php';
require __DIR__ . '/../lib/validate.php';
require __DIR__ . '/../lib/pagination.php';
require __DIR__ . '/../lib/report_config.php';

// GET /api/reports-similar.php?waste_type=general&location=...&latitude=..&longitude=..
//
// เตือนก่อนส่งรายการใหม่ว่า "บริเวณนี้อาจมีรายการแจ้งอยู่แล้ว"
// endpoint นี้ไม่บล็อกอะไร ไม่เขียนอะไร และไม่ใช่การตัดสินว่าซ้ำจริง
// ผู้ใช้ยังส่งรายการใหม่ได้เสมอ — POST /api/reports.php ไม่เรียกฟังก์ชันนี้
//
// เกณฑ์: PROTOTYPE / UNCALIBRATED (ดู lib/report_config.php)
// - มีพิกัด  → waste_type เดียวกัน + อยู่ในรัศมี REPORT_DUPLICATE_RADIUS_METERS
// - ไม่มีพิกัด → waste_type เดียวกัน + ชื่อสถานที่ใกล้เคียงกัน (LIKE)
// ทั้งสองแบบนับเฉพาะรายการที่ยังไม่ RESOLVED และอยู่ในช่วง window

function haversine_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earth_radius_m = 6371000.0;
    $d_lat = deg2rad($lat2 - $lat1);
    $d_lng = deg2rad($lng2 - $lng1);
    $a = sin($d_lat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($d_lng / 2) ** 2;
    return $earth_radius_m * 2 * asin(min(1.0, sqrt($a)));
}

function get_similar_reports(): void
{
    try {
        $waste_type = $_GET['waste_type'] ?? null;
        if (!is_string($waste_type) || !in_array($waste_type, VALID_WASTE_TYPES, true)) {
            throw new InvalidArgumentException('waste_type: ต้องเป็นหนึ่งใน ' . implode(', ', VALID_WASTE_TYPES));
        }
        $latitude = validate_coordinate($_GET['latitude'] ?? null, 90.0, 'latitude');
        $longitude = validate_coordinate($_GET['longitude'] ?? null, 180.0, 'longitude');
        if (($latitude === null) !== ($longitude === null)) {
            throw new InvalidArgumentException('latitude: ต้องระบุ latitude และ longitude คู่กัน');
        }
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 422);
    }

    $window_days = REPORT_DUPLICATE_WINDOW_DAYS;
    $sql = "SELECT id, location, latitude, longitude, location_source,
                   waste_type, amount_kg, detail, status, created_at
            FROM reports
            WHERE waste_type = :waste_type
              AND status <> 'RESOLVED'
              AND created_at >= DATE_SUB(NOW(), INTERVAL $window_days DAY)";
    $params = ['waste_type' => $waste_type];
    $rule = '';

    if ($latitude !== null && $longitude !== null) {
        // กรองด้วย bounding box ใน SQL ก่อน แล้วค่อยวัดระยะจริงด้วย haversine ใน PHP
        // (ทำให้ index ใช้ได้ และเลี่ยงการ bind placeholder ซ้ำชื่อซึ่ง PDO แบบ
        //  ATTR_EMULATE_PREPARES=false ไม่รองรับ)
        $radius = REPORT_DUPLICATE_RADIUS_METERS;
        $lat_delta = $radius / 111320.0;
        $cos_lat = max(0.000001, cos(deg2rad($latitude)));
        $lng_delta = $radius / (111320.0 * $cos_lat);

        $sql .= " AND latitude BETWEEN :min_lat AND :max_lat
                  AND longitude BETWEEN :min_lng AND :max_lng";
        $params['min_lat'] = $latitude - $lat_delta;
        $params['max_lat'] = $latitude + $lat_delta;
        $params['min_lng'] = $longitude - $lng_delta;
        $params['max_lng'] = $longitude + $lng_delta;
        $rule = 'same waste_type within ' . (int)$radius . 'm radius (uncalibrated)';
    } else {
        $pattern = search_like_pattern($_GET['location'] ?? null);
        if ($pattern === null) {
            // ไม่มีทั้งพิกัดและชื่อสถานที่ = ไม่มีอะไรให้เทียบ ไม่ใช่ error
            json_response([
                'similar' => [],
                'match_rule' => 'no location or coordinates supplied',
                'calibration' => 'PROTOTYPE / UNCALIBRATED',
            ]);
        }
        $sql .= " AND location LIKE :location";
        $params['location'] = $pattern;
        $rule = 'same waste_type and similar location text';
    }

    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 50';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $similar = [];
    foreach ($rows as $row) {
        $row['id'] = (int)$row['id'];
        $row['amount_kg'] = (int)$row['amount_kg'];
        $row['latitude'] = $row['latitude'] !== null ? (float)$row['latitude'] : null;
        $row['longitude'] = $row['longitude'] !== null ? (float)$row['longitude'] : null;
        $row['distance_m'] = null;

        if ($latitude !== null && $longitude !== null) {
            if ($row['latitude'] === null || $row['longitude'] === null) {
                continue;
            }
            $distance = haversine_meters($latitude, $longitude, $row['latitude'], $row['longitude']);
            if ($distance > REPORT_DUPLICATE_RADIUS_METERS) {
                continue;
            }
            $row['distance_m'] = round($distance, 1);
        }
        $similar[] = $row;
    }

    if ($latitude !== null) {
        usort($similar, fn(array $a, array $b) => $a['distance_m'] <=> $b['distance_m']);
    }

    json_response([
        'similar' => array_slice($similar, 0, REPORT_DUPLICATE_MAX_RESULTS),
        'match_rule' => $rule,
        'radius_meters' => $latitude !== null ? REPORT_DUPLICATE_RADIUS_METERS : null,
        'window_days' => REPORT_DUPLICATE_WINDOW_DAYS,
        'calibration' => 'PROTOTYPE / UNCALIBRATED',
    ]);
}

try {
    match ($_SERVER['REQUEST_METHOD']) {
        'GET' => get_similar_reports(),
        default => json_error("Method Not Allowed", 405),
    };
} catch (PDOException $e) {
    json_error("database error", 500);
}
