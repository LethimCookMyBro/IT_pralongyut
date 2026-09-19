<?php
declare(strict_types=1);

require __DIR__ . "/../lib/validate.php";
require __DIR__ . "/../lib/pagination.php";

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
                   "location_source" => "manual"], "output mismatch");
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

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
