<?php
declare(strict_types=1);

require __DIR__ . '/../lib/db.php';

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

function post_observation(string $base, string $camera, int $count): array
{
    return request_json('POST', "$base/vision.php", [
        'camera_name' => $camera,
        'location' => 'Test Zone',
        'area_type' => 'land',
        'detected_count' => $count,
        'max_confidence' => 0.5,
        'source_mode' => 'replay',
    ]);
}

$incidentA = null;
$incidentB = null;
$incidentC = null;

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
    $stmt = $pdo->prepare("DELETE FROM vision_observations WHERE camera_name LIKE :prefix");
    $stmt->execute(['prefix' => $prefix . '%']);
    $stmt = $pdo->prepare("DELETE FROM vision_incidents WHERE camera_name LIKE :prefix");
    $stmt->execute(['prefix' => $prefix . '%']);
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
