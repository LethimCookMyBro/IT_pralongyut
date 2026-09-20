<?php
declare(strict_types=1);

require_once __DIR__ . "/../lib/cli_only.php";

require_once __DIR__ . '/../lib/db.php';

$base = 'http://localhost/bangsaen/api';
$prefix = 'TEST-AGG-' . date('His') . '-';
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

function post_observation(string $base, string $camera, int $count, string $recordOrigin = 'demo_seed'): array
{
    return request_json('POST', "$base/vision.php", [
        'camera_name' => $camera,
        'location' => 'Test Zone',
        'area_type' => 'land',
        'detected_count' => $count,
        'max_confidence' => 0.5,
        'source_mode' => 'replay',
        'record_origin' => $recordOrigin,
    ]);
}

$incidentA = null;
$incidentB = null;
$incidentC = null;
$incidentIMG = null;
$incidentC2 = null;

try {
    check('four observations aggregate into one incident', function () use ($base, $prefix, &$incidentA) {
        $camera = $prefix . 'A';
        $expectedCounts = [12, 18, 25, 31];
        foreach ($expectedCounts as $i => $count) {
            [$status, $json] = post_observation($base, $camera, $count);
            if ($status !== 201) {
                throw new Exception("expected 201, got $status");
            }
            if (!isset($json['incident']['id'])) {
                throw new Exception('missing incident');
            }
            if (($json['incident']['record_origin'] ?? null) !== 'demo_seed' ||
                ($json['observation']['record_origin'] ?? null) !== 'demo_seed') {
                throw new Exception('default record_origin should be demo_seed');
            }
            $id = (int)$json['incident']['id'];
            if ($incidentA === null) {
                $incidentA = $id;
            } elseif ($id !== $incidentA) {
                throw new Exception("observation created a new incident: $id != $incidentA");
            }
            $expectedAggregated = $i > 0;
            if ((bool)$json['aggregated_into_existing_incident'] !== $expectedAggregated) {
                throw new Exception('aggregated flag mismatch');
            }
        }

        [$status, $json] = request_json('GET', "$base/vision.php?review_status=pending");
        if ($status !== 200) {
            throw new Exception("GET queue expected 200, got $status");
        }
        $row = null;
        foreach ($json['incidents'] as $incident) {
            if ((int)$incident['id'] === $incidentA) {
                $row = $incident;
                break;
            }
        }
        if (!$row) {
            throw new Exception('incident not found in queue');
        }
        if ((int)$row['observation_count'] !== 4 || (int)$row['peak_detected_count'] !== 31) {
            throw new Exception('aggregation counters incorrect');
        }
    });

    check('raw observation drill-down returns all four records', function () use ($base, &$incidentA) {
        [$status, $json] = request_json('GET', "$base/vision-observations.php?incident_id=$incidentA");
        if ($status !== 200 || count($json['observations']) !== 4) {
            throw new Exception('expected 4 raw observations');
        }
    });

    check('different camera creates a different incident', function () use ($base, $prefix, &$incidentA, &$incidentB) {
        [$status, $json] = post_observation($base, $prefix . 'B', 9);
        if ($status !== 201) {
            throw new Exception("expected 201, got $status");
        }
        $incidentB = (int)$json['incident']['id'];
        if ($incidentB === $incidentA) {
            throw new Exception('different camera reused same incident');
        }
    });

    check('record_origin is persisted and prevents cross-origin aggregation', function () use ($base, $prefix) {
        $camera = $prefix . 'ORIGIN';
        [$statusDemo, $demo] = post_observation($base, $camera, 2, 'demo_seed');
        [$statusReal, $real] = post_observation($base, $camera, 3, 'detector_run');

        if ($statusDemo !== 201 || $statusReal !== 201) {
            throw new Exception("expected 201 for both origins");
        }
        if (($demo['incident']['record_origin'] ?? null) !== 'demo_seed') {
            throw new Exception('demo incident origin mismatch');
        }
        if (($real['incident']['record_origin'] ?? null) !== 'detector_run' ||
            ($real['observation']['record_origin'] ?? null) !== 'detector_run') {
            throw new Exception('detector_run origin was not persisted');
        }
        if ((int)$demo['incident']['id'] === (int)$real['incident']['id']) {
            throw new Exception('different record_origin values must not aggregate into one incident');
        }

        [$detailStatus, $detail] = request_json('GET', "$base/vision-incident.php?id=" . (int)$real['incident']['id']);
        if ($detailStatus !== 200 || ($detail['incident']['record_origin'] ?? null) !== 'detector_run') {
            throw new Exception('incident detail did not expose detector_run origin');
        }
    });

    check('invalid record_origin is rejected with 422', function () use ($base, $prefix) {
        [$status, $json] = request_json('POST', "$base/vision.php", [
            'camera_name' => $prefix . 'BAD-ORIGIN',
            'location' => 'Test Zone',
            'area_type' => 'land',
            'detected_count' => 1,
            'max_confidence' => 0.5,
            'source_mode' => 'replay',
            'record_origin' => 'fake',
        ]);
        if ($status !== 422 || !str_starts_with((string)($json['error'] ?? ''), 'record_origin')) {
            throw new Exception('invalid record_origin should return 422 naming record_origin');
        }
    });

    check('priority queue keeps higher-observation pending incident ahead', function () use ($base, &$incidentA, &$incidentB) {
        [$status, $json] = request_json('GET', "$base/vision.php?review_status=pending");
        if ($status !== 200) {
            throw new Exception("expected 200, got $status");
        }
        $posA = null;
        $posB = null;
        foreach ($json['incidents'] as $index => $incident) {
            $id = (int)$incident['id'];
            if ($id === $incidentA) $posA = $index;
            if ($id === $incidentB) $posB = $index;
        }
        if ($posA === null || $posB === null || $posA >= $posB) {
            throw new Exception('priority ordering does not place 4-observation incident ahead of 1-observation incident');
        }
    });

    check('zero detection is raw observation only', function () use ($base, $prefix) {
        [$status, $json] = post_observation($base, $prefix . 'ZERO', 0);
        if ($status !== 201) {
            throw new Exception("expected 201, got $status");
        }
        if ($json['incident'] !== null || $json['observation']['incident_id'] !== null) {
            throw new Exception('zero detection should not create/link incident');
        }
    });

    check('observation accepts a known evidence image', function () use ($base, $prefix, &$incidentIMG) {
        [$status, $json] = request_json('POST', "$base/vision.php", [
            'camera_name' => $prefix . 'IMG',
            'location' => 'Test Zone',
            'area_type' => 'land',
            'detected_count' => 5,
            'max_confidence' => 0.5,
            'source_mode' => 'replay',
            'image_path' => 'assets/vision/street-replay-01.jpg',
        ]);
        if ($status !== 201) {
            throw new Exception("expected 201, got $status");
        }
        if ($json['observation']['image_path'] !== 'assets/vision/street-replay-01.jpg') {
            throw new Exception('image_path not stored: ' . var_export($json['observation']['image_path'], true));
        }
        $incidentIMG = (int)$json['incident']['id'];
    });

    check('incident detail exposes evidence newest-first', function () use ($base, &$incidentIMG) {
        [$status, $json] = request_json('GET', "$base/vision-incident.php?id=$incidentIMG");
        if ($status !== 200) {
            throw new Exception("expected 200, got $status");
        }
        if (!isset($json['evidence']) || !is_array($json['evidence'])) {
            throw new Exception('missing evidence array');
        }
        if (count($json['evidence']) !== 1) {
            throw new Exception('expected exactly 1 evidence item, got ' . count($json['evidence']));
        }
        if ($json['evidence'][0]['image_path'] !== 'assets/vision/street-replay-01.jpg') {
            throw new Exception('evidence image_path mismatch');
        }
    });

    check('observation drill-down returns image_path', function () use ($base, &$incidentIMG) {
        [$status, $json] = request_json('GET', "$base/vision-observations.php?incident_id=$incidentIMG");
        if ($status !== 200) {
            throw new Exception("expected 200, got $status");
        }
        if ($json['observations'][0]['image_path'] !== 'assets/vision/street-replay-01.jpg') {
            throw new Exception('drill-down image_path mismatch');
        }
    });

    check('incident without image returns empty evidence', function () use ($base, &$incidentC2) {
        [$status, $json] = request_json('POST', "$base/vision.php", [
            'camera_name' => 'NOIMG-' . date('His'),
            'location' => 'Test Zone',
            'area_type' => 'land',
            'detected_count' => 2,
            'max_confidence' => 0.4,
            'source_mode' => 'replay',
        ]);
        if ($status !== 201) {
            throw new Exception("expected 201, got $status");
        }
        if ($json['observation']['image_path'] !== null) {
            throw new Exception('image_path should be null when omitted');
        }
        $incidentC2 = (int)$json['incident']['id'];

        [$status, $json] = request_json('GET', "$base/vision-incident.php?id=$incidentC2");
        if ($status !== 200 || $json['evidence'] !== []) {
            throw new Exception('expected empty evidence array');
        }
    });

    check('dangerous image_path values are rejected with 422', function () use ($base, $prefix) {
        $bad = [
            'assets/vision/../../lib/db.php',
            '../assets/vision/street-replay-01.jpg',
            'javascript:alert(1)',
            'http://evil.example/x.jpg',
            '//evil.example/x.jpg',
            'data:image/png;base64,AAAA',
            'C:\\xampp\\htdocs\\bangsaen\\index.html',
            'assets/vision/shell.php',
            'assets/vision/does-not-exist.jpg',
            'assets/other/street-replay-01.jpg',
        ];
        foreach ($bad as $value) {
            [$status, $json] = request_json('POST', "$base/vision.php", [
                'camera_name' => $prefix . 'BAD',
                'location' => 'Test Zone',
                'area_type' => 'land',
                'detected_count' => 1,
                'max_confidence' => 0.5,
                'source_mode' => 'replay',
                'image_path' => $value,
            ]);
            if ($status !== 422) {
                throw new Exception("expected 422 for " . var_export($value, true) . ", got $status");
            }
            if (!str_starts_with((string)($json['error'] ?? ''), 'image_path')) {
                throw new Exception('error should name image_path, got: ' . var_export($json['error'] ?? null, true));
            }
        }
    });

    check('confirm incident then resolve', function () use ($base, &$incidentA) {
        [$status, $json] = request_json('POST', "$base/vision-review.php", ['id' => $incidentA, 'action' => 'confirm']);
        if ($status !== 200 || $json['review_status'] !== 'confirmed' || $json['action_status'] !== 'needs_check') {
            throw new Exception('confirm state mismatch');
        }

        [$status] = request_json('POST', "$base/vision-review.php", ['id' => $incidentA, 'action' => 'confirm']);
        if ($status !== 409) {
            throw new Exception("duplicate confirm should be 409, got $status");
        }

        [$status, $json] = request_json('POST', "$base/vision-review.php", ['id' => $incidentA, 'action' => 'resolve']);
        if ($status !== 200 || $json['action_status'] !== 'resolved') {
            throw new Exception('resolve state mismatch');
        }
    });

    check('reject incident closes review path', function () use ($base, &$incidentB) {
        [$status, $json] = request_json('POST', "$base/vision-review.php", ['id' => $incidentB, 'action' => 'reject']);
        if ($status !== 200 || $json['review_status'] !== 'rejected') {
            throw new Exception('reject state mismatch');
        }
    });

    check('resolve before confirm is rejected', function () use ($base, $prefix, &$incidentC) {
        [$status, $json] = post_observation($base, $prefix . 'C', 3);
        if ($status !== 201) {
            throw new Exception("create expected 201, got $status");
        }
        $incidentC = (int)$json['incident']['id'];
        [$status] = request_json('POST', "$base/vision-review.php", ['id' => $incidentC, 'action' => 'resolve']);
        if ($status !== 409) {
            throw new Exception("resolve-before-confirm should be 409, got $status");
        }
    });
} finally {
    // ลบเฉพาะข้อมูล test prefix ของสคริปต์นี้ ไม่แตะข้อมูล demo/user
    $pdo = db();
    foreach ([$prefix . '%', 'NOIMG-%'] as $pattern) {
        $stmt = $pdo->prepare("DELETE FROM vision_observations WHERE camera_name LIKE :prefix");
        $stmt->execute(['prefix' => $pattern]);
        $stmt = $pdo->prepare("DELETE FROM vision_incidents WHERE camera_name LIKE :prefix");
        $stmt->execute(['prefix' => $pattern]);
    }
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
