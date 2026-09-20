<?php
declare(strict_types=1);

require __DIR__ . "/../lib/cli_only.php";

// Integration test: pagination / filter / search ของ endpoint ที่คืนรายการ
//   GET api/reports.php
//   GET api/vision.php
//   GET api/vision-observations.php
//
// ต้องให้ Apache + MySQL ของ XAMPP รันอยู่
// รัน: C:\xampp\php\php.exe afternoon\tests\list_api_test.php
//
// ข้อมูลทดสอบใช้ prefix เฉพาะรอบ แล้วลบทิ้งใน finally
// ไม่ลบข้อมูล demo/ผู้ใช้จริง

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/vision_config.php';

$base = 'http://localhost/bangsaen/api';
$prefix = 'TESTLIST-' . date('His');

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "PASS  $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL  $name\n      {$e->getMessage()}\n";
    }
}

function request_json(string $method, string $url, ?array $payload = null): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("curl failed: $error");
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException("non-json response HTTP $status: $body");
    }
    return [$status, $json];
}

function assert_that(bool $condition, string $message): void
{
    if (!$condition) {
        throw new Exception($message);
    }
}

function expect_422(string $url): void
{
    [$status, $json] = request_json('GET', $url);
    assert_that($status === 422, "expected 422, got $status for $url");
    assert_that(isset($json['error']), "422 ควรมี error message: " . json_encode($json));
}

// ---------- seed ----------
// 23 reports = 3 หน้าไม่ลงตัว (10 + 10 + 3) ทดสอบ last page ได้จริง
const REPORT_TOTAL = 23;
const WATER_INCIDENTS = 4;
const INCIDENT_TOTAL = 12;

// ต้องตรงกับ VALID_WASTE_TYPES ใน lib/validate.php
$report_types = ['general', 'recyclable', 'hazardous'];

try {
    for ($i = 1; $i <= REPORT_TOTAL; $i++) {
        $payload = [
            'location' => sprintf('%s จุดที่ %02d', $prefix, $i),
            'waste_type' => $report_types[$i % 3],
            'amount_kg' => $i,
            'detail' => '',
        ];
        // 5 รายการแรกมีพิกัด GPS เพื่อทดสอบ round-trip
        if ($i <= 5) {
            $payload['latitude'] = 13.2800000 + $i / 10000;
            $payload['longitude'] = 100.9200000 + $i / 10000;
            $payload['location_source'] = 'gps';
        }
        [$status] = request_json('POST', "$base/reports.php", $payload);
        if ($status !== 201) {
            throw new RuntimeException("seed report $i failed with HTTP $status");
        }
    }

    for ($i = 1; $i <= INCIDENT_TOTAL; $i++) {
        [$status] = request_json('POST', "$base/vision.php", [
            'camera_name' => sprintf('%s-CAM-%02d', $prefix, $i),
            'location' => sprintf('%s โซน %02d', $prefix, $i),
            'area_type' => $i <= WATER_INCIDENTS ? 'water' : 'land',
            'detected_count' => $i,
            'max_confidence' => 0.5,
            'source_mode' => 'replay',
        ]);
        if ($status !== 201) {
            throw new RuntimeException("seed incident $i failed with HTTP $status");
        }
    }

    // ---------- reports: pagination ----------

    check('reports page 1 returns 10 of 23', function () use ($base, $prefix) {
        [$status, $json] = request_json('GET', "$base/reports.php?q=$prefix&page=1");
        assert_that($status === 200, "expected 200, got $status");
        assert_that(count($json['reports']) === 10, 'expected 10 rows, got ' . count($json['reports']));
        assert_that($json['pagination'] === [
            'page' => 1, 'per_page' => 10, 'total' => REPORT_TOTAL, 'total_pages' => 3,
        ], 'pagination mismatch: ' . json_encode($json['pagination']));
    });

    check('reports default page is 1', function () use ($base, $prefix) {
        [, $withParam] = request_json('GET', "$base/reports.php?q=$prefix&page=1");
        [, $noParam] = request_json('GET', "$base/reports.php?q=$prefix");
        assert_that($noParam['pagination'] === $withParam['pagination'], 'default page ไม่เท่ากับ page=1');
        assert_that(
            array_column($noParam['reports'], 'id') === array_column($withParam['reports'], 'id'),
            'default page คืนแถวไม่เหมือน page=1'
        );
    });

    check('reports page 2 has different rows and same total', function () use ($base, $prefix) {
        [, $p1] = request_json('GET', "$base/reports.php?q=$prefix&page=1");
        [$status, $p2] = request_json('GET', "$base/reports.php?q=$prefix&page=2");
        assert_that($status === 200, "expected 200, got $status");
        assert_that(count($p2['reports']) === 10, 'expected 10 rows on page 2');
        assert_that($p2['pagination']['page'] === 2, 'page echo ผิด');
        assert_that($p2['pagination']['total'] === REPORT_TOTAL, 'total ต้องไม่เปลี่ยนตามหน้า');
        $overlap = array_intersect(array_column($p1['reports'], 'id'), array_column($p2['reports'], 'id'));
        assert_that($overlap === [], 'page 1 และ page 2 มีแถวซ้ำกัน: ' . json_encode($overlap));
    });

    check('reports last page returns the remainder', function () use ($base, $prefix) {
        [$status, $json] = request_json('GET', "$base/reports.php?q=$prefix&page=3");
        assert_that($status === 200, "expected 200, got $status");
        assert_that(count($json['reports']) === 3, 'expected 3 rows on last page, got ' . count($json['reports']));
        assert_that($json['pagination']['total_pages'] === 3, 'total_pages mismatch');
    });

    check('reports page past the end is empty but keeps totals', function () use ($base, $prefix) {
        // ตั้งใจให้เป็น 200 + list ว่าง (ไม่ใช่ 404) เพื่อให้ UI clamp กลับไปหน้าสุดท้ายได้
        [$status, $json] = request_json('GET', "$base/reports.php?q=$prefix&page=99");
        assert_that($status === 200, "expected 200, got $status");
        assert_that($json['reports'] === [], 'ควรได้ list ว่าง');
        assert_that($json['pagination']['total'] === REPORT_TOTAL, 'total ต้องยังถูกต้อง');
        assert_that($json['pagination']['total_pages'] === 3, 'total_pages ต้องยังถูกต้อง');
    });

    check('reports total count equals rows collected across all pages', function () use ($base, $prefix) {
        $ids = [];
        for ($page = 1; $page <= 3; $page++) {
            [, $json] = request_json('GET', "$base/reports.php?q=$prefix&page=$page");
            $ids = array_merge($ids, array_column($json['reports'], 'id'));
        }
        assert_that(count($ids) === REPORT_TOTAL, 'รวมทุกหน้าได้ ' . count($ids) . ' แถว');
        assert_that(count(array_unique($ids)) === REPORT_TOTAL, 'มี id ซ้ำระหว่างหน้า');
    });

    check('reports per_page is honoured', function () use ($base, $prefix) {
        [$status, $json] = request_json('GET', "$base/reports.php?q=$prefix&per_page=5&page=2");
        assert_that($status === 200, "expected 200, got $status");
        assert_that(count($json['reports']) === 5, 'expected 5 rows');
        assert_that($json['pagination']['total_pages'] === 5, 'total_pages ควรเป็น 5 เมื่อ per_page=5');
    });

    check('reports rejects invalid pagination input', function () use ($base, $prefix) {
        foreach (['page=0', 'page=-1', 'page=abc', 'page=1.5', 'per_page=0', 'per_page=101'] as $bad) {
            expect_422("$base/reports.php?q=$prefix&$bad");
        }
    });

    // ---------- reports: filter / search ----------

    check('reports filter by waste_type', function () use ($base, $prefix) {
        [$status, $json] = request_json('GET', "$base/reports.php?q=$prefix&waste_type=recyclable&per_page=100");
        assert_that($status === 200, "expected 200, got $status");
        $expected = 0;
        for ($i = 1; $i <= REPORT_TOTAL; $i++) {
            if ($i % 3 === 1) $expected++;
        }
        assert_that($json['pagination']['total'] === $expected,
            "expected $expected recyclable, got {$json['pagination']['total']}");
        foreach ($json['reports'] as $row) {
            assert_that($row['waste_type'] === 'recyclable', 'filter รั่ว: ' . $row['waste_type']);
        }
    });

    check('reports filter by status', function () use ($base, $prefix) {
        // ยังไม่มี endpoint เปลี่ยน status ของ report — ทุกแถวใหม่จึงเป็น PENDING
        [, $pending] = request_json('GET', "$base/reports.php?q=$prefix&status=PENDING");
        assert_that($pending['pagination']['total'] === REPORT_TOTAL, 'PENDING total mismatch');
        [, $resolved] = request_json('GET', "$base/reports.php?q=$prefix&status=RESOLVED");
        assert_that($resolved['pagination']['total'] === 0, 'RESOLVED ควรเป็น 0');
        assert_that($resolved['reports'] === [], 'RESOLVED ควรได้ list ว่าง');
    });

    check('reports filter=all means no filter', function () use ($base, $prefix) {
        [$status, $json] = request_json('GET', "$base/reports.php?q=$prefix&status=all&waste_type=all");
        assert_that($status === 200, "expected 200, got $status");
        assert_that($json['pagination']['total'] === REPORT_TOTAL, 'all ไม่ควรกรองอะไรออก');
    });

    check('reports rejects unknown filter values', function () use ($base, $prefix) {
        expect_422("$base/reports.php?q=$prefix&status=DELETED");
        expect_422("$base/reports.php?q=$prefix&waste_type=nuclear");
    });

    check('reports filter combines with pagination', function () use ($base, $prefix) {
        [, $all] = request_json('GET', "$base/reports.php?q=$prefix&waste_type=general&per_page=100");
        $total = $all['pagination']['total'];
        [, $paged] = request_json('GET', "$base/reports.php?q=$prefix&waste_type=general&per_page=3&page=2");
        assert_that($paged['pagination']['total'] === $total, 'total ต้องเท่ากันเมื่อเปลี่ยนหน้า');
        assert_that(count($paged['reports']) === 3, 'expected 3 rows');
        assert_that($paged['pagination']['total_pages'] === (int)ceil($total / 3), 'total_pages mismatch');
    });

    check('reports search matches location only', function () use ($base, $prefix) {
        [$status, $json] = request_json('GET', "$base/reports.php?q=" . urlencode("$prefix จุดที่ 07"));
        assert_that($status === 200, "expected 200, got $status");
        assert_that($json['pagination']['total'] === 1, 'ควรเจอ 1 แถว, ได้ ' . $json['pagination']['total']);
        assert_that(str_contains($json['reports'][0]['location'], 'จุดที่ 07'), 'เจอผิดแถว');
    });

    check('reports search treats % as a literal', function () use ($base, $prefix) {
        // ถ้าไม่ escape, "%" จะกลายเป็น wildcard และคืนทุกแถว
        [$status, $json] = request_json('GET', "$base/reports.php?q=" . urlencode('%'));
        assert_that($status === 200, "expected 200, got $status");
        assert_that($json['pagination']['total'] === 0,
            'ค้นหา % ต้องไม่กลายเป็น wildcard, ได้ ' . $json['pagination']['total']);
    });

    check('reports keep gps fields through POST then GET', function () use ($base, $prefix) {
        [$status, $json] = request_json('GET', "$base/reports.php?q=" . urlencode("$prefix จุดที่ 03"));
        assert_that($status === 200, "expected 200, got $status");
        $row = $json['reports'][0];
        assert_that($row['location_source'] === 'gps', 'location_source mismatch: ' . $row['location_source']);
        assert_that(abs($row['latitude'] - 13.2803) < 0.00001, 'latitude mismatch: ' . var_export($row['latitude'], true));
        assert_that(abs($row['longitude'] - 100.9203) < 0.00001, 'longitude mismatch');
        assert_that(is_float($row['latitude']), 'latitude ควรเป็น number ไม่ใช่ string');
    });

    check('reports without gps keep null coordinates', function () use ($base, $prefix) {
        [, $json] = request_json('GET', "$base/reports.php?q=" . urlencode("$prefix จุดที่ 20"));
        $row = $json['reports'][0];
        assert_that($row['latitude'] === null && $row['longitude'] === null, 'ควรเป็น null');
        assert_that($row['location_source'] === 'manual', 'ควร default เป็น manual');
    });

    // ---------- reports: แยกข้อมูลจริงออกจากข้อมูลตัวอย่าง ----------
    // กรรมการ/ผู้ใช้ต้องแยกออกได้ว่าแถวไหนเป็นเรื่องแจ้งจริง แถวไหนเป็นตัวอย่างสำหรับเดโม

    check('report defaults to record_origin citizen', function () use ($base, $prefix) {
        [, $json] = request_json('GET', "$base/reports.php?q=" . urlencode("$prefix จุดที่ 20"));
        assert_that(($json['reports'][0]['record_origin'] ?? null) === 'citizen',
            'แถวที่คนแจ้งต้องเป็น citizen, ได้ ' . var_export($json['reports'][0]['record_origin'] ?? null, true));
    });

    check('report can be posted as demo_seed and filtered', function () use ($base, $prefix) {
        [$status, $created] = request_json('POST', "$base/reports.php", [
            'location' => "$prefix ตัวอย่างเดโม",
            'waste_type' => 'general',
            'amount_kg' => 7,
            'record_origin' => 'demo_seed',
        ]);
        assert_that($status === 201, "expected 201, got $status");
        assert_that($created['record_origin'] === 'demo_seed', 'response ต้องบอกว่าเป็น demo_seed');

        [, $demo] = request_json('GET', "$base/reports.php?record_origin=demo_seed&per_page=100&q=$prefix");
        assert_that($demo['pagination']['total'] === 1, 'ควรมีแถวตัวอย่าง 1 แถว, ได้ ' . $demo['pagination']['total']);
        assert_that($demo['reports'][0]['location'] === "$prefix ตัวอย่างเดโม", 'ได้แถวผิด');

        [, $real] = request_json('GET', "$base/reports.php?record_origin=citizen&per_page=100&q=$prefix");
        assert_that($real['pagination']['total'] === REPORT_TOTAL, 'filter citizen ต้องไม่รวมแถวตัวอย่าง');
        [, $all] = request_json('GET', "$base/reports.php?record_origin=all&per_page=100&q=$prefix");
        assert_that($all['pagination']['total'] === REPORT_TOTAL + 1, 'all ต้องรวมทั้งสองแบบ');
    });

    check('reports reject unknown record_origin', function () use ($base, $prefix) {
        expect_422("$base/reports.php?q=$prefix&record_origin=detector_run");
        [$status] = request_json('POST', "$base/reports.php", [
            'location' => "$prefix ค่าผิด",
            'waste_type' => 'general',
            'amount_kg' => 1,
            'record_origin' => 'detector_run',
        ]);
        assert_that($status === 422, "POST ค่าผิดต้องได้ 422, ได้ $status");
    });

    // ---------- incidents: pagination / filter / search ----------

    check('incidents paginate with search', function () use ($base, $prefix) {
        [$status, $p1] = request_json('GET', "$base/vision.php?q=$prefix&page=1");
        assert_that($status === 200, "expected 200, got $status");
        assert_that($p1['pagination']['total'] === INCIDENT_TOTAL,
            'total mismatch: ' . $p1['pagination']['total']);
        assert_that($p1['pagination']['total_pages'] === 2, 'total_pages ควรเป็น 2');
        assert_that(count($p1['incidents']) === 10, 'expected 10 rows, got ' . count($p1['incidents']));

        [, $p2] = request_json('GET', "$base/vision.php?q=$prefix&page=2");
        assert_that(count($p2['incidents']) === 2, 'expected 2 rows on page 2');
        $overlap = array_intersect(array_column($p1['incidents'], 'id'), array_column($p2['incidents'], 'id'));
        assert_that($overlap === [], 'incident ซ้ำระหว่างหน้า');
    });

    check('incidents search matches camera_name', function () use ($base, $prefix) {
        [$status, $json] = request_json('GET', "$base/vision.php?q=" . urlencode("$prefix-CAM-05"));
        assert_that($status === 200, "expected 200, got $status");
        assert_that($json['pagination']['total'] === 1, 'ควรเจอ 1 incident, ได้ ' . $json['pagination']['total']);
        assert_that($json['incidents'][0]['camera_name'] === "$prefix-CAM-05", 'เจอผิดตัว');
    });

    check('incidents filter by area_type', function () use ($base, $prefix) {
        [$status, $water] = request_json('GET', "$base/vision.php?q=$prefix&area_type=water&per_page=100");
        assert_that($status === 200, "expected 200, got $status");
        assert_that($water['pagination']['total'] === WATER_INCIDENTS,
            'water total mismatch: ' . $water['pagination']['total']);
        foreach ($water['incidents'] as $row) {
            assert_that($row['area_type'] === 'water', 'area filter รั่ว');
        }
        [, $land] = request_json('GET', "$base/vision.php?q=$prefix&area_type=land&per_page=100");
        assert_that($land['pagination']['total'] === INCIDENT_TOTAL - WATER_INCIDENTS, 'land total mismatch');
    });

    check('incidents filter by review_status', function () use ($base, $prefix) {
        [, $pending] = request_json('GET', "$base/vision.php?q=$prefix&review_status=pending&per_page=100");
        assert_that($pending['pagination']['total'] === INCIDENT_TOTAL, 'incident ใหม่ต้องเป็น pending ทั้งหมด');
        [, $rejected] = request_json('GET', "$base/vision.php?q=$prefix&review_status=rejected");
        assert_that($rejected['pagination']['total'] === 0, 'ยังไม่มี rejected');
    });

    check('incidents combine filter + search + pagination', function () use ($base, $prefix) {
        [$status, $json] = request_json('GET', "$base/vision.php?q=$prefix&area_type=water&per_page=3&page=2");
        assert_that($status === 200, "expected 200, got $status");
        assert_that($json['pagination']['total'] === WATER_INCIDENTS, 'total ต้องนับตาม filter+search');
        assert_that($json['pagination']['total_pages'] === 2, 'total_pages mismatch');
        assert_that(count($json['incidents']) === 1, 'หน้า 2 ของ 4 รายการที่ per_page=3 ต้องมี 1 แถว');
    });

    check('incidents summary stays global, not filtered', function () use ($base, $prefix) {
        // summary คือภาพรวมทั้งระบบ ไม่ใช่ผลลัพธ์ที่ถูก filter — คงพฤติกรรมเดิมไว้
        [, $json] = request_json('GET', "$base/vision.php?q=$prefix&per_page=1");
        assert_that(isset($json['summary']), 'ต้องยังมี summary');
        foreach (['total', 'pending_review', 'confirmed', 'rejected', 'needs_check', 'resolved'] as $key) {
            assert_that(isset($json['summary'][$key]), "summary ขาด key $key (contract เดิม)");
        }
        assert_that($json['summary']['total'] >= INCIDENT_TOTAL,
            'summary ควรนับทั้งระบบ ไม่ใช่แค่ผลค้นหา, ได้ ' . $json['summary']['total']);
        assert_that(count($json['incidents']) === 1, 'per_page=1 ต้องคืน 1 แถว');
    });

    check('incidents reject invalid pagination and filters', function () use ($base, $prefix) {
        foreach (['page=0', 'page=xyz', 'per_page=0', 'per_page=500'] as $bad) {
            expect_422("$base/vision.php?q=$prefix&$bad");
        }
        expect_422("$base/vision.php?area_type=sky");
        expect_422("$base/vision.php?review_status=maybe");
    });

    check('incidents list numbers are typed for the UI', function () use ($base, $prefix) {
        [, $json] = request_json('GET', "$base/vision.php?q=" . urlencode("$prefix-CAM-07"));
        $row = $json['incidents'][0];
        assert_that(is_int($row['id']), 'id ควรเป็น int');
        assert_that(is_int($row['observation_count']), 'observation_count ควรเป็น int');
        assert_that(is_float($row['max_confidence']), 'max_confidence ควรเป็น float');
    });

    // ---------- incident detail + observation drill-down ----------

    check('incident detail endpoint returns one incident', function () use ($base, $prefix) {
        [, $list] = request_json('GET', "$base/vision.php?q=" . urlencode("$prefix-CAM-09"));
        $id = $list['incidents'][0]['id'];
        [$status, $json] = request_json('GET', "$base/vision-incident.php?id=$id");
        assert_that($status === 200, "expected 200, got $status");
        assert_that($json['incident']['id'] === $id, 'คืน incident ผิดตัว');
        assert_that($json['aggregation']['window_minutes'] === INCIDENT_AGGREGATION_WINDOW_MINUTES,
            'ควรบอก aggregation window ให้หน้ารายละเอียดอธิบายได้');
    });

    check('incident detail rejects bad id', function () use ($base) {
        expect_422("$base/vision-incident.php?id=abc");
        expect_422("$base/vision-incident.php?id=0");
        [$status] = request_json('GET', "$base/vision-incident.php?id=999999999");
        assert_that($status === 404, "id ที่ไม่มีจริงควรเป็น 404, ได้ $status");
    });

    check('observation drill-down paginates', function () use ($base, $prefix) {
        // ยิง observation เพิ่มให้กล้องเดิมภายใน window → รวมเป็น incident เดิม
        $camera = "$prefix-CAM-01";
        for ($i = 0; $i < 4; $i++) {
            [$status, $json] = request_json('POST', "$base/vision.php", [
                'camera_name' => $camera,
                'location' => "$prefix โซน 01",
                'area_type' => 'water',
                'detected_count' => 5 + $i,
                'max_confidence' => 0.6,
                'source_mode' => 'replay',
            ]);
            assert_that($status === 201, "seed observation failed: $status");
            $id = (int)$json['incident']['id'];
        }
        [$status, $page1] = request_json('GET', "$base/vision-observations.php?incident_id=$id&per_page=2&page=1");
        assert_that($status === 200, "expected 200, got $status");
        assert_that($page1['pagination']['total'] === 5, 'ควรมี 5 observations, ได้ ' . $page1['pagination']['total']);
        assert_that(count($page1['observations']) === 2, 'expected 2 rows');
        [, $page3] = request_json('GET', "$base/vision-observations.php?incident_id=$id&per_page=2&page=3");
        assert_that(count($page3['observations']) === 1, 'หน้าสุดท้ายควรมี 1 แถว');
        $overlap = array_intersect(
            array_column($page1['observations'], 'id'),
            array_column($page3['observations'], 'id')
        );
        assert_that($overlap === [], 'observation ซ้ำระหว่างหน้า');
    });

    check('observation drill-down default per_page is 50', function () use ($base, $prefix) {
        [, $list] = request_json('GET', "$base/vision.php?q=" . urlencode("$prefix-CAM-02"));
        $id = $list['incidents'][0]['id'];
        [$status, $json] = request_json('GET', "$base/vision-observations.php?incident_id=$id");
        assert_that($status === 200, "expected 200, got $status");
        assert_that($json['pagination']['per_page'] === 50, 'default per_page ของ timeline ควรเป็น 50');
    });
    // ---------- activity: ไทม์ไลน์ย้อนหลัง (derived จาก reports + incidents) ----------
    // ระบบไม่มีตาราง log แยก — endpoint นี้อ่านจากคอลัมน์เวลาที่มีอยู่จริงเท่านั้น
    // $activity_total = reports ทั้งหมด (+1 แถวตัวอย่างจากเทสต์ก่อนหน้า) + incident ที่ถูกสร้าง
    $activity_total = REPORT_TOTAL + 1 + INCIDENT_TOTAL;

    check('activity defaults to 90 days and per_page 20', function () use ($base, $prefix, $activity_total) {
        [$status, $json] = request_json('GET', "$base/activity.php?q=$prefix");
        assert_that($status === 200, "expected 200, got $status");
        assert_that(isset($json['activities']), 'ต้องมี key activities');
        assert_that($json['pagination']['per_page'] === 20, 'default per_page ของไทม์ไลน์ควรเป็น 20');
        assert_that($json['days'] === 90, 'default days ควรเป็น 90, ได้ ' . var_export($json['days'] ?? null, true));
        assert_that($json['pagination']['total'] === $activity_total,
            'total mismatch: ได้ ' . $json['pagination']['total'] . ' คาดว่า ' . $activity_total);
    });

    check('activity rows carry the fields the log page shows', function () use ($base, $prefix) {
        [, $json] = request_json('GET', "$base/activity.php?q=$prefix&per_page=100");
        foreach (['kind', 'occurred_at', 'ref_id', 'location', 'record_origin'] as $key) {
            assert_that(array_key_exists($key, $json['activities'][0]), "activity ขาด key $key");
        }
        assert_that(is_int($json['activities'][0]['ref_id']), 'ref_id ควรเป็น number');
        // เรียงใหม่ไปเก่า
        $times = array_column($json['activities'], 'occurred_at');
        $sorted = $times;
        rsort($sorted);
        assert_that($times === $sorted, 'ไทม์ไลน์ต้องเรียงจากใหม่ไปเก่า');
    });

    check('activity filters by kind', function () use ($base, $prefix) {
        [, $reports] = request_json('GET', "$base/activity.php?q=$prefix&kind=report&per_page=100");
        assert_that($reports['pagination']['total'] === REPORT_TOTAL + 1,
            'report kind total mismatch: ' . $reports['pagination']['total']);
        foreach ($reports['activities'] as $row) {
            assert_that($row['kind'] === 'report', 'kind รั่ว: ' . $row['kind']);
        }
        [, $detect] = request_json('GET', "$base/activity.php?q=$prefix&kind=detect&per_page=100");
        assert_that($detect['pagination']['total'] === INCIDENT_TOTAL, 'detect kind total mismatch');
        // ยังไม่มีการตรวจ/ปิดงานกับข้อมูลชุดทดสอบนี้
        [, $review] = request_json('GET', "$base/activity.php?q=$prefix&kind=review&per_page=100");
        assert_that($review['pagination']['total'] === 0, 'review ควรเป็น 0');
        assert_that($review['activities'] === [], 'review ควรได้ list ว่าง');
    });

    check('activity kind=all means every kind', function () use ($base, $prefix, $activity_total) {
        [, $all] = request_json('GET', "$base/activity.php?q=$prefix&kind=all&per_page=100");
        assert_that($all['pagination']['total'] === $activity_total, 'all ไม่ควรกรองอะไรออก');
    });

    check('activity day window narrows the result', function () use ($base, $prefix, $activity_total) {
        // ข้อมูลชุดทดสอบเพิ่งถูกสร้าง — 1 วันย้อนหลังต้องยังเห็นครบ
        [$status, $json] = request_json('GET', "$base/activity.php?q=$prefix&days=1&per_page=100");
        assert_that($status === 200, "expected 200, got $status");
        assert_that($json['days'] === 1, 'days ต้องสะท้อนค่าที่ขอ');
        assert_that($json['pagination']['total'] === $activity_total, 'ข้อมูลของวันนี้ต้องยังอยู่ในหน้าต่าง 1 วัน');
    });

    check('activity paginates without overlap', function () use ($base, $prefix) {
        [, $p1] = request_json('GET', "$base/activity.php?q=$prefix&per_page=5&page=1");
        [, $p2] = request_json('GET', "$base/activity.php?q=$prefix&per_page=5&page=2");
        assert_that(count($p1['activities']) === 5, 'expected 5 rows on page 1');
        assert_that(count($p2['activities']) === 5, 'expected 5 rows on page 2');
        $key = fn(array $r) => $r['kind'] . '-' . $r['ref_id'];
        $overlap = array_intersect(array_map($key, $p1['activities']), array_map($key, $p2['activities']));
        assert_that($overlap === [], 'หน้า 1 และ 2 ซ้ำกัน: ' . json_encode($overlap));
    });

    check('activity rejects bad days and kind', function () use ($base, $prefix) {
        foreach (['days=0', 'days=91', 'days=abc', 'days=-1', 'kind=observation', 'kind=REPORT'] as $bad) {
            expect_422("$base/activity.php?q=$prefix&$bad");
        }
        expect_422("$base/activity.php?q=$prefix&per_page=101");
    });

} finally {
    // ลบเฉพาะข้อมูลของรอบทดสอบนี้
    $pdo = db();
    $stmt = $pdo->prepare("DELETE FROM reports WHERE location LIKE :prefix");
    $stmt->execute(['prefix' => $prefix . '%']);
    $deleted_reports = $stmt->rowCount();
    $stmt = $pdo->prepare("DELETE FROM vision_observations WHERE camera_name LIKE :prefix");
    $stmt->execute(['prefix' => $prefix . '%']);
    $stmt = $pdo->prepare("DELETE FROM vision_incidents WHERE camera_name LIKE :prefix");
    $stmt->execute(['prefix' => $prefix . '%']);
    echo "\ncleanup: ลบ test reports $deleted_reports แถว, incidents/observations prefix $prefix\n";
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
