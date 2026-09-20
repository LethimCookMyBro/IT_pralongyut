<?php
declare(strict_types=1);

require_once __DIR__ . "/../lib/cli_only.php";

/**
 * seed_demo.php — ใส่ "ข้อมูลตัวอย่างสำหรับเดโม" ผ่าน API จริง
 *
 * ทำไมต้องมี:
 *   ระบบยังเป็นต้นแบบ/replay ฐานข้อมูลจริงมีข้อมูลน้อยมาก (ไม่กี่แถว)
 *   จึงไม่เห็นว่า pagination / filter / search ทำงานจริงหรือไม่
 *
 * ข้อมูลที่สร้างคือข้อมูลสมมติ ไม่ใช่ข้อมูลจากกล้อง CCTV จริงและไม่ใช่เรื่องร้องเรียนจริง
 *
 * ความปลอดภัยของข้อมูล:
 *   - สคริปต์นี้ INSERT ผ่าน API เท่านั้น (POST api/reports.php, POST api/vision.php,
 *     POST api/vision-review.php)
 *   - ไม่มี DELETE / TRUNCATE / DROP / ALTER และไม่แก้ข้อมูลเดิม
 *   - รันซ้ำได้ แต่จะได้ข้อมูลเพิ่มขึ้นเรื่อย ๆ
 *     (ถ้ารันซ้ำภายในช่วง INCIDENT_AGGREGATION_WINDOW_MINUTES การตรวจพบใหม่
 *      จะถูกรวมเข้าเหตุที่ยัง pending ของกล้องเดิม ตามกติกา Event Aggregation)
 *
 * วิธีใช้ (ต้องเปิด Apache/MySQL ของ XAMPP ก่อน):
 *   C:\xampp\php\php.exe afternoon\tools\seed_demo.php
 *   C:\xampp\php\php.exe afternoon\tools\seed_demo.php http://localhost/bangsaen/api
 */

$base = rtrim($argv[1] ?? 'http://localhost/bangsaen/api', '/');

function post_json(string $url, array $payload): array
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        fwrite(STDERR, "เชื่อมต่อ $url ไม่ได้ — เปิด Apache ใน XAMPP แล้วหรือยัง\n");
        exit(1);
    }
    $status = (int)substr($http_response_header[0] ?? 'HTTP/1.1 000', 9, 3);
    $json = json_decode($body, true);
    if ($status >= 400) {
        fwrite(STDERR, "HTTP $status จาก $url: $body\n");
        exit(1);
    }
    return is_array($json) ? $json : [];
}

/* ---------- 1) เรื่องแจ้งจากประชาชน ---------- */
// พิกัดตัวอย่างอยู่แถวบางแสน (13.28–13.30, 100.91–100.93) เป็นค่าสมมติสำหรับเดโม
// ไม่ใช่พิกัดที่สำรวจจริง — จึงใส่เฉพาะรายการที่ตั้งใจให้ location_source = gps
$reports = [
    ['หาดบางแสน — โค้งวงเวียน', 'general', 18, 'ถุงพลาสติกและกล่องอาหารกองใต้ร่ม', 'preset', null, null],
    ['หาดบางแสน — ฝั่งเหนือ', 'general', 25, 'ขยะจากนักท่องเที่ยวช่วงวันหยุด', 'preset', null, null],
    ['หาดบางแสน — ฝั่งใต้', 'recyclable', 12, 'ขวดน้ำพลาสติกจำนวนมาก', 'preset', null, null],
    ['หาดวอนนภา', 'general', 30, 'ถังขยะล้น ไม่ได้เก็บสองวัน', 'preset', null, null],
    ['แหลมแท่น', 'recyclable', 8, 'กระป๋องอลูมิเนียมกระจายบนลานหิน', 'preset', null, null],
    ['ถนนเลียบหาดบางแสน', 'general', 22, 'ถุงขยะวางนอกจุดพักขยะ', 'preset', null, null],
    ['ตลาดหนองมน', 'organic', 45, 'เศษอาหารและเศษผักจากแผงค้า', 'preset', null, null],
    ['ย่านหอพักบางแสน', 'general', 16, 'ขยะรวมหน้าหอพักช่วงสิ้นเดือน', 'preset', null, null],
    ['ตลาดสดเทศบาลเมืองแสนสุข', 'organic', 38, 'น้ำเสียและเศษอาหารใต้แผง', 'preset', null, null],
    ['สวนสาธารณะเทศบาลเมืองแสนสุข', 'recyclable', 6, 'ขวดแก้วใกล้สนามเด็กเล่น', 'preset', null, null],
    ['เขาสามมุก', 'general', 10, 'ขยะริมทางขึ้นจุดชมวิว', 'preset', null, null],
    ['มหาวิทยาลัยบูรพา — พื้นที่รอบนอก', 'general', 14, 'ขยะรอบป้ายรถประจำทาง', 'preset', null, null],
    ['คลองบางแสน', 'general', 28, 'ขยะลอยติดตะแกรงระบายน้ำ', 'preset', null, null],
    ['คลองระบายน้ำเลียบชายหาด', 'hazardous', 4, 'กระป๋องสเปรย์และถ่านไฟฉายในคลอง', 'preset', null, null],
    ['ริมหาดใกล้ร้านอาหาร (พิกัดจากอุปกรณ์)', 'general', 20, 'กองขยะหลังแนวร้านอาหาร', 'gps', 13.2846231, 100.9187450],
    ['จุดจอดรถริมหาด (พิกัดจากอุปกรณ์)', 'recyclable', 9, 'ขวดพลาสติกในช่องจอด', 'gps', 13.2871902, 100.9201338],
    ['ทางเดินเลียบชายหาด (พิกัดจากอุปกรณ์)', 'general', 13, 'ถุงขยะวางทิ้งข้างทางเดิน', 'gps', 13.2902554, 100.9215007],
    ['ซอยข้างตลาด (พิมพ์เอง)', 'hazardous', 3, 'หลอดไฟแตกและกระป๋องสี', 'manual', null, null],
    ['ลานกิจกรรมชายหาด (พิมพ์เอง)', 'general', 17, 'ขยะหลังงานดนตรีกลางแจ้ง', 'manual', null, null],
];

$created_reports = 0;
foreach ($reports as [$location, $type, $kg, $detail, $source, $lat, $lng]) {
    post_json("$base/reports.php", [
        'location' => $location,
        'waste_type' => $type,
        'amount_kg' => $kg,
        'detail' => $detail,
        'location_source' => $source,
        'latitude' => $lat,
        'longitude' => $lng,
        // ติดป้ายที่ตัวข้อมูล ไม่ใช่แค่ข้อความบนหน้าเว็บ — หน้า reports.html แสดงป้าย "ตัวอย่าง"
        // และ tools/clear_demo.php ลบได้เฉพาะแถวนี้
        'record_origin' => 'demo_seed',
    ]);
    $created_reports++;
}

/* ---------- 2) การตรวจพบของโมเดล → เหตุ (ผ่าน Event Aggregation จริง) ---------- */
// [กล้อง, สถานที่, ประเภทพื้นที่, จำนวนที่ตรวจพบแต่ละครั้ง, ความมั่นใจของโมเดล]
// การตรวจพบหลายครั้งของกล้องเดียวกันจะถูกรวมเป็นเหตุเดียวโดย api/vision.php
$incidents = [
    ['Replay-Beach-01', 'หาดบางแสน — โค้งวงเวียน', 'land', [4, 6, 9], 0.88],
    ['Replay-Beach-02', 'หาดบางแสน — ฝั่งเหนือ', 'land', [7, 5], 0.81],
    ['Replay-Beach-03', 'หาดบางแสน — ฝั่งใต้', 'land', [3], 0.74],
    ['Replay-Beach-04', 'หาดวอนนภา', 'land', [11, 12, 12, 15], 0.92],
    ['Replay-Cape-01', 'แหลมแท่น', 'land', [2, 2], 0.69],
    ['Replay-Street-02', 'ถนนเลียบหาดบางแสน', 'land', [5, 8], 0.85],
    ['Replay-Market-01', 'ตลาดหนองมน', 'land', [9, 7, 6], 0.79],
    ['Replay-Dorm-01', 'ย่านหอพักบางแสน', 'land', [6], 0.72],
    ['Replay-Market-02', 'ตลาดสดเทศบาลเมืองแสนสุข', 'land', [13, 10], 0.9],
    ['Replay-Park-01', 'สวนสาธารณะเทศบาลเมืองแสนสุข', 'land', [2], 0.66],
    ['Replay-Hill-01', 'เขาสามมุก', 'land', [4, 3], 0.77],
    ['Replay-Campus-01', 'มหาวิทยาลัยบูรพา — พื้นที่รอบนอก', 'land', [5], 0.7],
    ['Replay-Canal-01', 'คลองบางแสน', 'water', [8, 10, 14], 0.87],
    ['Replay-Canal-02', 'คลองระบายน้ำเลียบชายหาด', 'water', [3, 4], 0.75],
    ['Replay-Canal-03', 'คลองบางแสน — ปลายคลอง', 'water', [6], 0.71],
];

$incident_ids = [];
$created_observations = 0;
foreach ($incidents as [$camera, $location, $area, $counts, $confidence]) {
    foreach ($counts as $i => $count) {
        $res = post_json("$base/vision.php", [
            'camera_name' => $camera,
            'location' => $location,
            'area_type' => $area,
            'detected_count' => $count,
            // ความมั่นใจของโมเดล ไม่ใช่ค่าความแม่นยำของระบบ
            'max_confidence' => round($confidence - 0.03 * $i, 2),
            'source_mode' => 'replay',
            'record_origin' => 'demo_seed',
        ]);
        $created_observations++;
        if ($i === 0 && isset($res['incident']['id'])) {
            $incident_ids[] = (int)$res['incident']['id'];
        }
    }
}

/* ---------- 3) ให้บางเหตุมีสถานะหลากหลาย (ผ่าน flow ตรวจจริง) ---------- */
// เหตุที่เหลือคงสถานะ pending ไว้ให้ทดลองตรวจในหน้าเว็บ
$reviews = [];
if (count($incident_ids) >= 10) {
    // ยืนยัน → ต้องดำเนินการ
    $reviews[] = [$incident_ids[1], 'confirm'];
    $reviews[] = [$incident_ids[6], 'confirm'];
    // ยืนยันแล้วปิดงาน
    $reviews[] = [$incident_ids[3], 'confirm'];
    $reviews[] = [$incident_ids[3], 'resolve'];
    $reviews[] = [$incident_ids[8], 'confirm'];
    $reviews[] = [$incident_ids[8], 'resolve'];
    // ปฏิเสธ (สถานะสุดท้าย)
    $reviews[] = [$incident_ids[9], 'reject'];
    $reviews[] = [$incident_ids[4], 'reject'];
}

foreach ($reviews as [$id, $action]) {
    post_json("$base/vision-review.php", ['id' => $id, 'action' => $action]);
}

echo "เพิ่มข้อมูลตัวอย่างแล้ว (ไม่ลบข้อมูลเดิม)\n";
echo "  reports            +$created_reports\n";
echo "  vision observation +$created_observations\n";
echo "  incidents ใหม่      " . count($incident_ids) . "\n";
echo "  ตรวจสถานะให้แล้ว    " . count($reviews) . " ครั้ง\n";
