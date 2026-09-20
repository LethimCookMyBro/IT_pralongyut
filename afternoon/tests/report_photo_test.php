<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/cli_only.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/report_photo.php';

// Actual Apache multipart requests; removes only this run's rows and files.
$base = 'http://localhost/bangsaen';
$prefix = 'PHOTO-' . bin2hex(random_bytes(8));
$temp = [];
$saved = [];
$passed = 0;
$failed = 0;
$fixtures = [
    'jpg' => file_get_contents(__DIR__ . '/../assets/vision/sample-road.jpg'),
    'png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aav8AAAAASUVORK5CYII='),
    'webp' => base64_decode('UklGRjgAAABXRUJQVlA4ICwAAADQAQCdASoCAAIAAUAmJaACdLoB+AADsAD+8NcD/yU1/jblq/+Uq8FjdmwAAA=='),
];
function upload_request(array|string $body, array $headers = []): array {
    global $base;
    $ch = curl_init($base . '/api/reports.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) { throw new RuntimeException(curl_error($ch)); }
    curl_close($ch);
    return [$status, json_decode($body, true)];
}
function photo_check(string $name, callable $fn): void {
    global $passed, $failed;
    try { $fn(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n"; }
}
function need(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function fixture(string $bytes, string $mime, string $name): CURLFile {
    global $temp;
    $path = tempnam(sys_get_temp_dir(), 'bw-photo-');
    $temp[] = $path;
    file_put_contents($path, $bytes);
    return new CURLFile($path, $mime, $name);
}
function submit(array $fields, int $expected): array {
    global $prefix, $saved;
    [$status, $data] = upload_request($fields + ['location' => $prefix]);
    if (!empty($data['image_path'])) { $saved[] = $data['image_path']; }
    need($status === $expected, "HTTP $status, expected $expected");
    need(is_array($data), 'JSON response');
    return $data;
}
try {
    photo_check('location only', fn() => need(submit([], 201)['image_path'] === null, 'optional photo'));
    photo_check('location and note', fn() => need(submit(['detail' => 'smoke note'], 201)['detail'] === 'smoke note', 'note preserved'));
    foreach (['manual', 'preset', 'gps'] as $source) {
        photo_check("location source $source", fn() => submit(['location_source' => $source,
            'latitude' => '13.283', 'longitude' => '100.924'], 201));
    }
    foreach ($fixtures as $ext => $bytes) {
        photo_check("valid $ext and static delivery", function() use ($ext, $bytes) {
            global $base;
            $data = submit(['photo' => fixture($bytes, 'application/octet-stream', 'not-trusted.php')], 201);
            need((bool)preg_match('~^uploads/reports/[a-f0-9]{32}\\.' . $ext . '$~', $data['image_path']), 'random relative filename');
            $ch = curl_init($base . '/' . $data['image_path']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $actual = curl_exec($ch);
            need(curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && $actual === $bytes, 'static file round trip');
            curl_close($ch);
        });
    }
    photo_check('oversized', fn() => submit(['photo' => fixture(str_repeat('x', REPORT_PHOTO_MAX_BYTES + 1), 'image/png', 'large.png')], 422));
    photo_check('fake MIME', fn() => submit(['photo' => fixture('not an image', 'image/jpeg', 'fake.jpg')], 422));
    photo_check('PHP disguised as image', fn() => submit(['photo' => fixture('<?php echo "EXECUTED";', 'image/png', 'shell.png')], 422));
    photo_check('traversal filename ignored', function() use ($fixtures) {
        $data = submit(['photo' => fixture($fixtures['png'], 'image/png', '../../escape.php')], 201);
        need((bool)preg_match('~^uploads/reports/[a-f0-9]{32}\\.png$~', $data['image_path']), 'safe generated path');
    });
    photo_check('malformed multipart', function() {
        [$status] = upload_request('broken multipart', ['Content-Type: multipart/form-data; boundary=missing']);
        need($status === 422, "HTTP $status");
    });
    photo_check('array photo rejected', fn() => submit(['photo[]' => fixture($fixtures['png'], 'image/png', 'photo.png')], 422));
    photo_check('text photo rejected', fn() => submit(['photo' => 'fake'], 422));
    photo_check('validation failure removes upload', function() use ($fixtures) {
        $root = 'C:/xampp/htdocs/bangsaen/uploads/reports';
        $before = glob($root . '/*');
        submit(['location' => 'x', 'photo' => fixture($fixtures['png'], 'image/png', 'photo.png')], 422);
        need(glob($root . '/*') === $before, 'orphan upload');
    });
    photo_check('missing photo path is null', fn() => need(report_photo_path_or_null('uploads/reports/' . str_repeat('a', 32) . '.jpg') === null, 'missing file'));
    photo_check('image with PHP bytes is served without execution', function() use ($fixtures) {
        global $base;
        $bytes = $fixtures['png'] . '<?php echo "SHOULD_NOT_EXECUTE"; ?>';
        $data = submit(['photo' => fixture($bytes, 'image/png', 'polyglot.php.png')], 201);
        need(file_get_contents($base . '/' . $data['image_path']) === $bytes, 'script execution or altered content');
    });
    photo_check('DB failure removes upload and hides internal error', function() use ($fixtures, $prefix) {
        global $base, $temp;
        // Isolated PHP process with a nonexistent DB; never changes the real schema.
        $port = random_int(20000, 40000);
        $log = tempnam(sys_get_temp_dir(), 'bw-photo-log-');
        $temp[] = $log;
        $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__)],
            [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']], $pipes,
            null, array_merge(getenv(), ['MYSQLDATABASE' => $prefix]));
        need(is_resource($process), 'could not start isolated PHP');
        $original = $base;
        try {
            fclose($pipes[0]);
            $ready = false;
            for ($i = 0; $i < 30; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
                if ($socket) { fclose($socket); $ready = true; break; }
                usleep(50000);
            }
            need($ready, 'isolated PHP not ready');
            $base = "http://127.0.0.1:$port";
            $before = glob(report_photo_root() . '/*');
            $data = submit(['photo' => fixture($fixtures['png'], 'image/png', 'photo.png')], 500);
            need($data === ['error' => 'database error'], 'DB internals leaked');
            need(glob(report_photo_root() . '/*') === $before, 'DB failure orphan');
        } finally {
            $base = $original;
            proc_terminate($process);
            proc_close($process);
        }
    });
} finally {
    $stmt = db()->prepare('DELETE FROM reports WHERE location = ?');
    $stmt->execute([$prefix]);
    foreach ($saved as $path) {
        if (preg_match('~^uploads/reports/[a-f0-9]{32}\\.(jpg|png|webp)$~', $path)) {
            @unlink('C:/xampp/htdocs/bangsaen/' . $path);
        }
    }
    foreach ($temp as $path) { @unlink($path); }
}
echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
