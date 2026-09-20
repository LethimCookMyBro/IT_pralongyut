<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/cli_only.php';
require_once __DIR__ . '/../lib/db.php';

// Read-only check: connection timezone affects presentation, never the stored instant.
$row = db()->query("SELECT @@session.time_zone AS zone,
    TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS offset_minutes,
    UNIX_TIMESTAMP(NOW()) AS epoch")->fetch();
$checks = [
    'connection uses Bangkok offset' => $row['zone'] === '+07:00',
    'SQL naive time is seven hours ahead of UTC' => (int)$row['offset_minutes'] === 420,
    'SQL current instant matches PHP epoch' => abs((int)$row['epoch'] - time()) <= 5,
];
$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) { $failed++; }
}
echo (count($checks) - $failed) . " passed, $failed failed\n";
exit($failed ? 1 : 0);
