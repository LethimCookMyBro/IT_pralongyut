<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/cli_only.php';
require_once __DIR__ . '/../lib/db.php';

$base = rtrim($argv[1] ?? 'http://localhost/bangsaen/api', '/');
$prefix = 'TESTREVIEW-' . date('Ymd-His') . '-' . getmypid() . '-' . bin2hex(random_bytes(2));
$passed = 0;
$failed = 0;

function request_review_json(string $method, string $url, ?array $payload = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException("non-json HTTP $status: $body");
    }
    return [$status, $json];
}

function review_check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "PASS $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL $name\n  {$e->getMessage()}\n";
    }
}

function review_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    [$status, $citizen] = request_review_json('POST', "$base/reports.php", [
        'location' => "$prefix citizen pending",
        'detail' => 'citizen queue smoke',
    ]);
    review_assert($status === 201, "citizen seed HTTP $status");

    [, $rejectedCitizen] = request_review_json('POST', "$base/reports.php", [
        'location' => "$prefix citizen rejected",
        'record_origin' => 'demo_seed',
    ]);

    [$status, $ai] = request_review_json('POST', "$base/vision.php", [
        'camera_name' => "$prefix AI camera",
        'location' => "$prefix AI incident",
        'area_type' => 'land',
        'detected_count' => 2,
        'max_confidence' => 0.75,
        'source_mode' => 'replay',
        'record_origin' => 'detector_run',
    ]);
    review_assert($status === 201, "AI seed HTTP $status");

    review_check('combined queue contains citizen and AI with server pagination', function () use ($base, $prefix) {
        [$status, $json] = request_review_json('GET', "$base/review-queue.php?q=$prefix&view=review&per_page=1&page=1");
        review_assert($status === 200, "expected 200, got $status");
        review_assert($json['pagination']['total'] === 3, 'combined pending total must be 3');
        review_assert(count($json['items']) === 1, 'per_page=1 must return one row');
        review_assert($json['pagination']['total_pages'] === 3, 'total_pages must be 3');
    });

    review_check('combined queue source filters stay server-side', function () use ($base, $prefix) {
        [, $citizenRows] = request_review_json('GET', "$base/review-queue.php?q=$prefix&source=citizen&per_page=100");
        review_assert($citizenRows['pagination']['total'] === 2, 'citizen filter must return two reports');
        foreach ($citizenRows['items'] as $row) {
            review_assert($row['source'] === 'citizen', 'citizen filter leaked another source');
        }

        [, $aiRows] = request_review_json('GET', "$base/review-queue.php?q=$prefix&source=ai&per_page=100");
        review_assert($aiRows['pagination']['total'] === 1, 'AI filter must return one incident');
        review_assert($aiRows['items'][0]['source'] === 'ai', 'AI row source mismatch');
    });

    review_check('citizen accept resolve stores real lifecycle timestamps', function () use ($base, $citizen) {
        [$status, $accepted] = request_review_json('POST', "$base/report-review.php", [
            'id' => $citizen['id'],
            'action' => 'accept',
        ]);
        review_assert($status === 200, "accept HTTP $status");
        review_assert($accepted['status'] === 'NEEDS_CHECK', 'accept must move to NEEDS_CHECK');
        review_assert($accepted['reviewed_at'] !== null, 'accept must set reviewed_at');
        review_assert($accepted['resolved_at'] === null, 'accept must not set resolved_at');

        [, $actionQueue] = request_review_json('GET', "$base/review-queue.php?view=action&source=citizen&per_page=100");
        review_assert(in_array((int)$accepted['id'], array_column($actionQueue['items'], 'id'), true), 'accepted citizen must enter action queue');

        [$status] = request_review_json('POST', "$base/report-review.php", [
            'id' => $citizen['id'],
            'action' => 'accept',
        ]);
        review_assert($status === 409, "repeat accept must be 409, got $status");

        [$status, $resolved] = request_review_json('POST', "$base/report-review.php", [
            'id' => $citizen['id'],
            'action' => 'resolve',
        ]);
        review_assert($status === 200, "resolve HTTP $status");
        review_assert($resolved['status'] === 'RESOLVED', 'resolve must move to RESOLVED');
        review_assert($resolved['resolved_at'] !== null, 'resolve must set resolved_at');

        [, $history] = request_review_json('GET', "$base/review-queue.php?view=history&source=citizen&per_page=100");
        review_assert(in_array((int)$resolved['id'], array_column($history['items'], 'id'), true), 'resolved citizen must enter history');
    });

    review_check('citizen reject is terminal and appears in Activity', function () use ($base, $prefix, $rejectedCitizen) {
        [$status, $rejected] = request_review_json('POST', "$base/report-review.php", [
            'id' => $rejectedCitizen['id'],
            'action' => 'reject',
        ]);
        review_assert($status === 200, "reject HTTP $status");
        review_assert($rejected['status'] === 'REJECTED', 'reject must move to REJECTED');
        review_assert($rejected['reviewed_at'] !== null, 'reject must set reviewed_at');
        review_assert($rejected['resolved_at'] === null, 'reject must not fabricate resolved_at');

        [$status] = request_review_json('POST', "$base/report-review.php", [
            'id' => $rejectedCitizen['id'],
            'action' => 'resolve',
        ]);
        review_assert($status === 409, "resolve rejected must be 409, got $status");

        [, $similar] = request_review_json(
            'GET',
            $base . '/reports-similar.php?location=' . urlencode($rejected['location'] ?? "$prefix citizen rejected")
        );
        review_assert($similar['similar'] === [], 'rejected report must not trigger duplicate warning');

        [, $activity] = request_review_json('GET', "$base/activity.php?q=$prefix&kind=review&per_page=100");
        $matches = array_values(array_filter(
            $activity['activities'],
            fn(array $row): bool => $row['source'] === 'citizen' && $row['state'] === 'rejected'
        ));
        review_assert(count($matches) === 1, 'Activity must contain one citizen reject event');
    });

    review_check('AI lifecycle remains in the same combined queue', function () use ($base, $prefix, $ai) {
        $id = $ai['incident']['id'];
        [$status] = request_review_json('POST', "$base/vision-review.php", ['id' => $id, 'action' => 'confirm']);
        review_assert($status === 200, "AI accept HTTP $status");

        [, $action] = request_review_json('GET', "$base/review-queue.php?q=$prefix&view=action&source=ai&per_page=100");
        review_assert($action['pagination']['total'] === 1, 'accepted AI incident must be in action queue');

        [$status] = request_review_json('POST', "$base/vision-review.php", ['id' => $id, 'action' => 'resolve']);
        review_assert($status === 200, "AI resolve HTTP $status");

        [, $history] = request_review_json('GET', "$base/review-queue.php?q=$prefix&view=history&source=ai&per_page=100");
        review_assert($history['pagination']['total'] === 1, 'resolved AI incident must be in history');

        [, $activity] = request_review_json('GET', "$base/activity.php?q=$prefix&per_page=100");
        $aiKinds = array_column(array_filter(
            $activity['activities'],
            fn(array $row): bool => $row['source'] === 'ai'
        ), 'kind');
        foreach (['detect', 'review', 'resolve'] as $kind) {
            review_assert(in_array($kind, $aiKinds, true), "AI Activity missing $kind");
        }
    });

    review_check('citizen Activity contains submit accept close lifecycle', function () use ($base, $prefix) {
        [, $activity] = request_review_json('GET', "$base/activity.php?q=$prefix&per_page=100");
        $citizenRows = array_values(array_filter(
            $activity['activities'],
            fn(array $row): bool => $row['source'] === 'citizen'
        ));
        $kinds = array_column($citizenRows, 'kind');
        foreach (['report', 'review', 'resolve'] as $kind) {
            review_assert(in_array($kind, $kinds, true), "citizen Activity missing $kind");
        }
        review_assert(in_array('accepted', array_column($citizenRows, 'state'), true), 'citizen Activity missing accept result');
    });
} finally {
    $pdo = db();
    $like = "$prefix%";
    $stmt = $pdo->prepare('DELETE FROM vision_observations WHERE camera_name LIKE :prefix');
    $stmt->execute(['prefix' => $like]);
    $stmt = $pdo->prepare('DELETE FROM vision_incidents WHERE camera_name LIKE :prefix');
    $stmt->execute(['prefix' => $like]);
    $stmt = $pdo->prepare('DELETE FROM reports WHERE location LIKE :prefix');
    $stmt->execute(['prefix' => $like]);
}

echo "$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
