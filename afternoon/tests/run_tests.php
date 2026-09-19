<?php
declare(strict_types=1);

require __DIR__ . "/../lib/validate.php";

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
    assert($r === ["location" => "หาดบางแสน หน้าโค้งวงเวียน", "waste_type" => "general",
                   "amount_kg" => 20, "detail" => ""], "output mismatch");
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

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
