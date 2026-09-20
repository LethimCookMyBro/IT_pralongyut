<?php
declare(strict_types=1);

// Derived timeline: every event uses the timestamp stored for that lifecycle step.
const ACTIVITY_MAX_DAYS = 90;
const ACTIVITY_DEFAULT_PER_PAGE = 20;
const ACTIVITY_DEFAULT_DAYS = 90;
const ACTIVITY_KINDS = ['report', 'detect', 'review', 'resolve'];

function activity_days(mixed $value): int
{
    if ($value === null || $value === '') {
        return ACTIVITY_DEFAULT_DAYS;
    }
    if (is_int($value) && !is_bool($value)) {
        $days = $value;
    } elseif (is_string($value) && ctype_digit($value)) {
        $days = (int)$value;
    } else {
        throw new InvalidArgumentException('days: ต้องเป็นจำนวนเต็ม 1-' . ACTIVITY_MAX_DAYS);
    }
    if ($days < 1 || $days > ACTIVITY_MAX_DAYS) {
        throw new InvalidArgumentException('days: ต้องเป็นจำนวนเต็ม 1-' . ACTIVITY_MAX_DAYS);
    }
    return $days;
}

function activity_kind(mixed $value): ?string
{
    if ($value === null || $value === '' || $value === 'all') {
        return null;
    }
    if (!is_string($value) || !in_array($value, ACTIVITY_KINDS, true)) {
        throw new InvalidArgumentException('kind: ต้องเป็นหนึ่งใน ' . implode(', ', ACTIVITY_KINDS) . ', all');
    }
    return $value;
}

/** Every branch returns kind, occurred_at, ref_id, location, source,
 * record_origin, state, metric, and extra. */
function activity_branch_sql(string $kind, int $days): string
{
    $window = "INTERVAL $days DAY";
    return match ($kind) {
        'report' => "SELECT 'report' AS kind, created_at AS occurred_at, id AS ref_id,
            location, 'citizen' AS source, record_origin, 'pending' AS state,
            amount_kg AS metric, waste_type AS extra
            FROM reports WHERE created_at >= DATE_SUB(NOW(), $window)",
        'detect' => "SELECT 'detect' AS kind, first_seen AS occurred_at, id AS ref_id,
            location, 'ai' AS source, record_origin, 'pending' AS state,
            observation_count AS metric, camera_name AS extra
            FROM vision_incidents WHERE first_seen >= DATE_SUB(NOW(), $window)",
        'review' => "SELECT * FROM (
                SELECT 'review' AS kind, reviewed_at AS occurred_at, id AS ref_id,
                    location, 'citizen' AS source, record_origin,
                    CASE WHEN status = 'REJECTED' THEN 'rejected' ELSE 'accepted' END AS state,
                    amount_kg AS metric, waste_type AS extra
                FROM reports WHERE reviewed_at IS NOT NULL
                UNION ALL
                SELECT 'review' AS kind, reviewed_at AS occurred_at, id AS ref_id,
                    location, 'ai' AS source, record_origin,
                    CASE WHEN review_status = 'rejected' THEN 'rejected' ELSE 'accepted' END AS state,
                    observation_count AS metric, camera_name AS extra
                FROM vision_incidents WHERE reviewed_at IS NOT NULL
            ) AS review_events WHERE occurred_at >= DATE_SUB(NOW(), $window)",
        'resolve' => "SELECT * FROM (
                SELECT 'resolve' AS kind, resolved_at AS occurred_at, id AS ref_id,
                    location, 'citizen' AS source, record_origin, 'resolved' AS state,
                    amount_kg AS metric, waste_type AS extra
                FROM reports WHERE resolved_at IS NOT NULL
                UNION ALL
                SELECT 'resolve' AS kind, resolved_at AS occurred_at, id AS ref_id,
                    location, 'ai' AS source, record_origin, 'resolved' AS state,
                    observation_count AS metric, camera_name AS extra
                FROM vision_incidents WHERE resolved_at IS NOT NULL
            ) AS resolve_events WHERE occurred_at >= DATE_SUB(NOW(), $window)",
        default => throw new InvalidArgumentException('kind: ค่าไม่ถูกต้อง'),
    };
}

function activity_sql(?string $kind, int $days): string
{
    $kinds = $kind === null ? ACTIVITY_KINDS : [$kind];
    return implode("\nUNION ALL\n", array_map(
        fn(string $item): string => activity_branch_sql($item, $days),
        $kinds
    ));
}

function activity_cast_row(array $row): array
{
    $row['ref_id'] = (int)$row['ref_id'];
    $row['metric'] = $row['metric'] !== null ? (int)$row['metric'] : null;
    return $row;
}
