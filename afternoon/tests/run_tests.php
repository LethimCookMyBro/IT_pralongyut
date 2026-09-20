<?php
declare(strict_types=1);

require __DIR__ . "/../lib/cli_only.php";

require __DIR__ . "/../lib/validate.php";
require __DIR__ . "/../lib/pagination.php";
require __DIR__ . "/../lib/vision_evidence.php";
require __DIR__ . "/../lib/live_detection.php";

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
        echo "FAIL  $name\n      " . $e->getMessage() . "\n";
    }
}

function expect_error(string $field, array $data): void
{
    try {
        validate_report($data);
    } catch (InvalidArgumentException $e) {
        if (!str_starts_with($e->getMessage(), $field)) {
            throw new Exception("error should start with '$field' got: " . $e->getMessage());
        }
        return;
    }
    throw new Exception("expected InvalidArgumentException for $field");
}

$good = ["location" => " หาดบางแสน หน้าโค้งวงเวียน ", "waste_type" => "general",
         "amount_kg" => "20", "detail" => ""];

check("clean output", function () use ($good) {
    $r = validate_report($good);
    // === เทียบทั้ง key และลำดับ — ถ้าเพิ่ม/ย้ายฟิลด์ใน validate_report() ต้องอัปเดตที่นี่ด้วย
    assert($r === ["location" => "หาดบางแสน หน้าโค้งวงเวียน", "waste_type" => "general",
                   "amount_kg" => 20, "detail" => "",
                   "latitude" => null, "longitude" => null,
                   "location_source" => "manual",
                   "record_origin" => "citizen"], "output mismatch");
});

check("amount boundaries", function () use ($good) {
    assert(validate_report([...$good, "amount_kg" => 1])["amount_kg"] === 1);
    assert(validate_report([...$good, "amount_kg" => 1000])["amount_kg"] === 1000);
});

check("thai length uses mb_strlen", function () use ($good) {
    $r = validate_report([...$good, "location" => "กขค"]);
    assert($r["location"] === "กขค");
});

foreach (["", "  ", "ab", "กข", str_repeat("x", 101)] as $v) {
    check("bad location '$v'", fn() => expect_error("location", [...$good, "location" => $v]));
}
foreach (["plastic", "", null, "General", true] as $v) {
    check("bad waste_type", fn() => expect_error("waste_type", [...$good, "waste_type" => $v]));
}
foreach ([0, 1001, -5, "20.5", "abc", true, 20.5, null] as $v) {
    check("bad amount_kg " . var_export($v, true),
        fn() => expect_error("amount_kg", [...$good, "amount_kg" => $v]));
}
check("detail too long", fn() => expect_error("detail", [...$good, "detail" => str_repeat("x", 501)]));

// ---------- PHASE B: latitude / longitude / location_source ----------

check("gps coordinates pass through as float", function () use ($good) {
    $r = validate_report([...$good, "latitude" => "13.2846231", "longitude" => "100.9123456",
                          "location_source" => "gps"]);
    assert($r["latitude"] === 13.2846231, "latitude mismatch: " . var_export($r["latitude"], true));
    assert($r["longitude"] === 100.9123456, "longitude mismatch");
    assert($r["location_source"] === "gps", "location_source mismatch");
});

check("coordinates round to DECIMAL(10,7)", function () use ($good) {
    // ปัดให้ตรง column ก่อนเก็บ ไม่ปล่อยให้ MySQL ปัดเงียบ ๆ
    $r = validate_report([...$good, "latitude" => 13.123456789, "longitude" => -100.987654321]);
    assert($r["latitude"] === 13.1234568, "latitude not rounded: " . var_export($r["latitude"], true));
    assert($r["longitude"] === -100.9876543, "longitude not rounded");
});

check("preset location source allowed", function () use ($good) {
    $r = validate_report([...$good, "location_source" => "preset"]);
    assert($r["location_source"] === "preset");
    assert($r["latitude"] === null && $r["longitude"] === null, "preset ไม่บังคับพิกัด");
});

check("empty coordinate strings become null", function () use ($good) {
    // form ที่ผู้ใช้ไม่กด GPS จะส่ง "" มา ไม่ใช่ absent
    $r = validate_report([...$good, "latitude" => "", "longitude" => ""]);
    assert($r["latitude"] === null && $r["longitude"] === null, "empty string should be null");
});

foreach ([90.1, -90.1, "abc", true, "13,28", INF, NAN] as $v) {
    check("bad latitude " . var_export($v, true),
        fn() => expect_error("latitude", [...$good, "latitude" => $v, "longitude" => 100.9]));
}
foreach ([180.1, -180.1, "abc", true] as $v) {
    check("bad longitude " . var_export($v, true),
        fn() => expect_error("longitude", [...$good, "latitude" => 13.2, "longitude" => $v]));
}

check("latitude without longitude is rejected",
    fn() => expect_error("latitude", [...$good, "latitude" => 13.2]));
check("longitude without latitude is rejected",
    fn() => expect_error("latitude", [...$good, "longitude" => 100.9]));
check("location_source=gps without coordinates is rejected",
    fn() => expect_error("latitude", [...$good, "location_source" => "gps"]));

foreach (["GPS", "device", "preset ", 5, true, []] as $v) {
    check("bad location_source " . var_export($v, true),
        fn() => expect_error("location_source", [...$good, "location_source" => $v]));
}

check("missing/empty location_source defaults to manual", function () use ($good) {
    // form ที่ผู้ใช้พิมพ์สถานที่เองอาจไม่ส่ง field นี้ หรือส่ง "" — ทั้งสองคือ manual
    assert(validate_report($good)["location_source"] === "manual");
    assert(validate_report([...$good, "location_source" => ""])["location_source"] === "manual");
    assert(validate_report([...$good, "location_source" => null])["location_source"] === "manual");
});

// ---------- record_origin ของ reports: ข้อมูลจริงจากคน vs ข้อมูลตัวอย่างสำหรับเดโม ----------
// ต้องแยกให้ได้ในระดับข้อมูล ไม่ใช่แค่ข้อความบนหน้าเว็บ กรรมการต้องไม่อ่าน seed เป็นเรื่องจริง

check("report record_origin defaults to citizen", function () use ($good) {
    assert(validate_report($good)["record_origin"] === "citizen");
    assert(validate_report([...$good, "record_origin" => ""])["record_origin"] === "citizen");
    assert(validate_report([...$good, "record_origin" => null])["record_origin"] === "citizen");
});

check("report record_origin demo_seed is kept", function () use ($good) {
    assert(validate_report([...$good, "record_origin" => "demo_seed"])["record_origin"] === "demo_seed");
});

foreach (["DEMO_SEED", "seed", "detector_run", "demo", 1, true, []] as $v) {
    check("bad report record_origin " . var_export($v, true),
        fn() => expect_error("record_origin", [...$good, "record_origin" => $v]));
}

// ---------- PHASE A: pagination contract ----------

check("pagination defaults to page 1 / 10 per page", function () {
    $p = pagination_params([]);
    assert($p === ["page" => 1, "per_page" => 10, "offset" => 0], "default mismatch: " . json_encode($p));
});

check("pagination offset follows page", function () {
    assert(pagination_params(["page" => "1"])["offset"] === 0);
    assert(pagination_params(["page" => "2"])["offset"] === 10);
    assert(pagination_params(["page" => "4", "per_page" => "25"])["offset"] === 75);
});

check("pagination accepts custom default per_page", function () {
    // vision-observations.php ใช้ 50 เป็น default สำหรับ timeline
    assert(pagination_params([], 50)["per_page"] === 50);
    assert(pagination_params(["per_page" => "10"], 50)["per_page"] === 10);
});

function expect_pagination_error(string $name, array $query): void
{
    try {
        pagination_params($query);
    } catch (InvalidArgumentException $e) {
        if (!str_starts_with($e->getMessage(), $name)) {
            throw new Exception("error should start with '$name' got: " . $e->getMessage());
        }
        return;
    }
    throw new Exception("expected InvalidArgumentException for $name: " . json_encode($query));
}

foreach (["0", "-1", "abc", "1.5", "2e3", " 3", "", "๓"] as $v) {
    // "" ถือว่าไม่ส่งมา → ใช้ default, ที่เหลือต้อง error
    if ($v === "") continue;
    check("invalid page " . var_export($v, true), fn() => expect_pagination_error("page", ["page" => $v]));
}
check("empty page string falls back to default",
    fn() => assert(pagination_params(["page" => ""])["page"] === 1));
check("invalid per_page rejected", fn() => expect_pagination_error("per_page", ["per_page" => "0"]));
check("per_page above max rejected",
    fn() => expect_pagination_error("per_page", ["per_page" => (string)(PAGINATION_MAX_PER_PAGE + 1)]));
check("per_page at max allowed",
    fn() => assert(pagination_params(["per_page" => (string)PAGINATION_MAX_PER_PAGE])["per_page"] === PAGINATION_MAX_PER_PAGE));

check("pagination_meta computes total_pages", function () {
    assert(pagination_meta(1, 10, 37) === ["page" => 1, "per_page" => 10, "total" => 37, "total_pages" => 4]);
    assert(pagination_meta(1, 10, 30)["total_pages"] === 3, "exact multiple");
    assert(pagination_meta(1, 10, 1)["total_pages"] === 1);
    assert(pagination_meta(1, 10, 0)["total_pages"] === 0, "ว่างต้องเป็น 0 หน้า ไม่ใช่ 1");
});

check("search term escapes LIKE wildcards", function () {
    // ผู้ใช้พิมพ์ % หรือ _ ต้องถูก match แบบตัวอักษรจริง ไม่ใช่ wildcard
    assert(search_like_pattern("บางแสน") === "%บางแสน%");
    assert(search_like_pattern("  บางแสน  ") === "%บางแสน%", "ต้อง trim");
    assert(search_like_pattern("100%") === "%100\\%%", "escape %: " . search_like_pattern("100%"));
    assert(search_like_pattern("a_b") === "%a\\_b%", "escape _");
    assert(search_like_pattern("c\\d") === "%c\\\\d%", "escape backslash");
    assert(search_like_pattern("") === null, "ว่างต้องเป็น null (ไม่ filter)");
    assert(search_like_pattern("   ") === null);
    assert(search_like_pattern(null) === null);
    assert(search_like_pattern(123) === null, "non-string ต้องไม่ทำให้ error");
});

check("search term is length-capped", function () {
    $pattern = search_like_pattern(str_repeat("ก", 500));
    assert(mb_strlen($pattern) === 102, "ควรตัดที่ 100 ตัว + %% ได้ " . mb_strlen($pattern));
});

// ---------- PHASE F: evidence image path ----------

function expect_image_error($value): void
{
    try {
        validate_evidence_image_path($value);
    } catch (InvalidArgumentException $e) {
        if (!str_starts_with($e->getMessage(), "image_path")) {
            throw new Exception("error should start with 'image_path' got: " . $e->getMessage());
        }
        return;
    }
    throw new Exception("expected InvalidArgumentException for " . var_export($value, true));
}

check("absent image_path is null", function () {
    assert(validate_evidence_image_path(null) === null);
    assert(validate_evidence_image_path("") === null);
    assert(validate_evidence_image_path("   ") === null);
});

check("known evidence file is accepted", function () {
    $ok = validate_evidence_image_path("assets/vision/street-replay-01.jpg");
    assert($ok === "assets/vision/street-replay-01.jpg", var_export($ok, true));
});

check("path traversal is rejected", function () {
    foreach ([
        "assets/vision/../../lib/db.php",
        "assets/vision/..%2F..%2Fdb.php",
        "../assets/vision/street-replay-01.jpg",
        "assets/vision/sub/street-replay-01.jpg",
        "assets/vision/.hidden.jpg",
    ] as $bad) {
        expect_image_error($bad);
    }
});

check("absolute and windows paths are rejected", function () {
    foreach ([
        "/etc/passwd",
        "C:\\xampp\\htdocs\\bangsaen\\index.html",
        "assets\\vision\\street-replay-01.jpg",
        "//host/share/x.jpg",
    ] as $bad) {
        expect_image_error($bad);
    }
});

check("urls and javascript: are rejected", function () {
    foreach ([
        "javascript:alert(1)",
        "JavaScript:alert(1)",
        "data:image/png;base64,AAAA",
        "http://evil.example/x.jpg",
        "https://evil.example/assets/vision/x.jpg",
        "//evil.example/x.jpg",
    ] as $bad) {
        expect_image_error($bad);
    }
});

check("wrong folder or extension is rejected", function () {
    foreach ([
        "assets/other/street-replay-01.jpg",
        "street-replay-01.jpg",
        "assets/vision/shell.php",
        "assets/vision/street-replay-01.jpg.php",
        "assets/vision/street-replay-01.svg",
    ] as $bad) {
        expect_image_error($bad);
    }
});

check("missing file is rejected", fn() => expect_image_error("assets/vision/does-not-exist.jpg"));

check("null byte and non-string are rejected", function () {
    expect_image_error("assets/vision/street-replay-01.jpg\0.php");
    expect_image_error(123);
    expect_image_error(true);
    expect_image_error([]);
});

check("evidence_path_or_null never throws", function () {
    assert(evidence_path_or_null("assets/vision/../../lib/db.php") === null);
    assert(evidence_path_or_null("javascript:alert(1)") === null);
    assert(evidence_path_or_null(null) === null);
    assert(evidence_path_or_null("assets/vision/street-replay-01.jpg") === "assets/vision/street-replay-01.jpg");
});

// ---------- PHASE D: live detector runtime contract ----------

function live_test_runtime(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bangsaen-live-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('create temp runtime failed');
    }
    return $dir;
}

function live_test_snapshot(int $epoch = 1000): array
{
    return [
        'schema_version' => 1,
        'generated_at' => '2026-09-19T16:00:00Z',
        'generated_at_epoch' => $epoch,
        'source_id' => 'road',
        'source_label' => 'ขยะริมถนน',
        'camera_name' => 'REPLAY-ROAD-01',
        'location' => 'วิดีโออ้างอิง — ริมถนน',
        'area_type' => 'land',
        'model' => 'pLitterStreet',
        'detected_count' => 2,
        'max_confidence' => 0.75,
        'classes' => ['Plastic' => 2],
        'inference_ms' => 135.67,
        'last_post_status' => 201,
        'last_post_at' => '2026-09-19T16:00:00Z',
        'last_post_error' => null,
    ];
}

function remove_live_test_runtime(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_file($path)) @unlink($path);
    }
    @rmdir($dir);
}

check("live detector offline when runtime is absent", function () {
    $dir = live_test_runtime();
    try {
        $r = live_detection_read($dir, 1000);
        assert($r['available'] === false);
        assert($r['status'] === 'offline');
        assert($r['snapshot'] === null);
    } finally {
        remove_live_test_runtime($dir);
    }
});

check("fresh live detector snapshot is exposed with bounded fields", function () {
    $dir = live_test_runtime();
    try {
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'latest.json', json_encode(live_test_snapshot(), JSON_UNESCAPED_UNICODE));
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'latest.jpg', 'fake-jpeg-for-contract-test');
        $r = live_detection_read($dir, 1003);
        assert($r['available'] === true);
        assert($r['status'] === 'live');
        assert($r['age_seconds'] === 3);
        assert($r['snapshot']['detected_count'] === 2);
        assert($r['snapshot']['max_confidence'] === 0.75);
        assert($r['snapshot']['classes'] === ['Plastic' => 2]);
        assert(str_starts_with((string)$r['frame_url'], 'runtime/vision/latest.jpg?v='));
    } finally {
        remove_live_test_runtime($dir);
    }
});

check("old live detector snapshot becomes stale instead of pretending live", function () {
    $dir = live_test_runtime();
    try {
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'latest.json', json_encode(live_test_snapshot(), JSON_UNESCAPED_UNICODE));
        $r = live_detection_read($dir, 1010);
        assert($r['available'] === true);
        assert($r['status'] === 'stale');
        assert($r['age_seconds'] === 10);
    } finally {
        remove_live_test_runtime($dir);
    }
});

check("invalid live detector json fails closed", function () {
    $dir = live_test_runtime();
    try {
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'latest.json', '{not-json');
        $r = live_detection_read($dir, 1000);
        assert($r['available'] === false);
        assert($r['status'] === 'invalid');
        assert($r['snapshot'] === null);
    } finally {
        remove_live_test_runtime($dir);
    }
});

// ---------- ประวัติกิจกรรม 90 วัน (derived activity timeline) ----------
// ไม่มีตาราง log แยก — ไทม์ไลน์นี้อ่านจาก reports + vision_incidents ที่มีอยู่แล้ว

require __DIR__ . "/../lib/activity.php";

function expect_activity_error(string $field, callable $fn): void
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        if (!str_starts_with($e->getMessage(), $field)) {
            throw new Exception("error should start with '$field' got: " . $e->getMessage());
        }
        return;
    }
    throw new Exception("expected InvalidArgumentException for $field");
}

check("activity days defaults to 90", function () {
    assert(activity_days(null) === 90);
    assert(activity_days("") === 90);
});

check("activity days accepts 1..90", function () {
    assert(activity_days("1") === 1);
    assert(activity_days("7") === 7);
    assert(activity_days(30) === 30);
    assert(activity_days("90") === 90);
});

foreach (["0", "91", "365", "abc", "1.5", " 7", "-1", true] as $v) {
    check("bad activity days " . var_export($v, true),
        fn() => expect_activity_error("days", fn() => activity_days($v)));
}

check("activity kind null/empty/all means every kind", function () {
    assert(activity_kind(null) === null);
    assert(activity_kind("") === null);
    assert(activity_kind("all") === null);
});

check("activity kind keeps a known kind", function () {
    foreach (ACTIVITY_KINDS as $kind) {
        assert(activity_kind($kind) === $kind);
    }
});

foreach (["REPORT", "observation", "detector", 5, true] as $v) {
    check("bad activity kind " . var_export($v, true),
        fn() => expect_activity_error("kind", fn() => activity_kind($v)));
}

check("activity sql covers every kind when unfiltered", function () {
    $sql = activity_sql(null, 90);
    foreach (ACTIVITY_KINDS as $kind) {
        assert(str_contains($sql, "'$kind' AS kind"), "missing kind $kind");
    }
});

check("activity sql with a kind filter excludes the other kinds", function () {
    $sql = activity_sql("review", 90);
    assert(str_contains($sql, "'review' AS kind"), "review branch missing");
    foreach (["report", "detect", "resolve"] as $kind) {
        assert(!str_contains($sql, "'$kind' AS kind"), "kind $kind should be filtered out");
    }
    assert(!str_contains($sql, "UNION"), "single kind should not need UNION");
});

check("every activity branch is bounded by the day window", function () {
    $sql = activity_sql(null, 7);
    // หนึ่ง window ต่อหนึ่ง branch — ห้ามมี branch ไหนดึงทั้งตาราง
    assert(substr_count($sql, "INTERVAL 7 DAY") === count(ACTIVITY_KINDS), "window count mismatch: $sql");
});

// --- การตั้งค่าฐานข้อมูล: env ของ deployment ต้องมาก่อน ค่า local ต้องยังใช้ได้ ---

// ทดสอบเฉพาะการอ่านค่า ไม่ได้ต่อฐานข้อมูลจริง
require __DIR__ . "/../lib/db.php";

function with_db_env(array $env, callable $fn): void
{
    $keys = ["MYSQLHOST", "MYSQLPORT", "MYSQLUSER", "MYSQLPASSWORD", "MYSQLDATABASE"];
    $saved = [];
    foreach ($keys as $k) {
        $saved[$k] = getenv($k);
        putenv($k);
    }
    foreach ($env as $k => $v) {
        putenv("$k=$v");
    }
    try {
        $fn();
    } finally {
        foreach ($keys as $k) {
            $saved[$k] === false ? putenv($k) : putenv("$k={$saved[$k]}");
        }
    }
}

check("ไม่มี env -> ใช้ค่า XAMPP เดิม", function () {
    with_db_env([], function () {
        $c = db_config();
        assert($c["host"] === "127.0.0.1");
        assert($c["port"] === "3306");
        assert($c["user"] === "root");
        assert($c["pass"] === "");
        assert($c["name"] === "bangsaen_waste");
    });
});

check("มี env ของ Railway -> ใช้ค่าจาก env ทั้งหมด", function () {
    with_db_env([
        "MYSQLHOST" => "mysql.railway.internal",
        "MYSQLPORT" => "3306",
        "MYSQLUSER" => "railway_user",
        "MYSQLPASSWORD" => "s3cret-from-env",
        "MYSQLDATABASE" => "railway",
    ], function () {
        $c = db_config();
        assert($c["host"] === "mysql.railway.internal");
        assert($c["user"] === "railway_user");
        assert($c["pass"] === "s3cret-from-env");
        assert($c["name"] === "railway");
    });
});

check("ตั้ง env บางตัว -> ตัวที่ไม่ได้ตั้งถอยไปใช้ค่า local", function () {
    with_db_env(["MYSQLHOST" => "db.example.com"], function () {
        $c = db_config();
        assert($c["host"] === "db.example.com");
        assert($c["port"] === "3306");
        assert($c["name"] === "bangsaen_waste");
    });
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
