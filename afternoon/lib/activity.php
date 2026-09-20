<?php
declare(strict_types=1);

// ไทม์ไลน์กิจกรรมย้อนหลัง (derived activity timeline)
//
// ระบบยังไม่มีตาราง log แยก — ไทม์ไลน์นี้ "อนุมาน" จากคอลัมน์เวลาที่มีอยู่แล้วใน
// reports และ vision_incidents จึงไม่ต้องเพิ่มตาราง ไม่ต้องเขียน log ซ้ำ
// และไม่มีทางที่ log จะไม่ตรงกับข้อมูลจริง เพราะมันคือข้อมูลจริงชุดเดียวกัน
//
// ผลที่ตามมาที่ต้องรู้: เห็นได้แค่เหตุการณ์ที่มีคอลัมน์เวลาเก็บไว้
//   - แจ้งเรื่อง (reports.created_at)
//   - AI ตรวจพบเหตุใหม่ (vision_incidents.first_seen)
//   - เจ้าหน้าที่ตรวจ (reviewed_at) / ปิดงาน (resolved_at)
// สิ่งที่ยังเห็นไม่ได้: การตรวจครั้งที่ 2..n ของเหตุเดิม (ดูได้ในหน้ารายละเอียดเหตุ)
// และเวลาที่ "แก้" ข้อมูล เพราะไม่มี updated_at
//
// ponytail: ทุก branch มี LIMIT จาก pagination ชั้นนอก แต่ derived table ยัง
// materialize ทั้งช่วงเวลาก่อน sort — พอสำหรับต้นแบบ (หลักร้อย–หลักพันแถว)
// ถ้าข้อมูลโตจริงให้เพิ่มตาราง activity_log ที่เขียนตอน write แทน

const ACTIVITY_MAX_DAYS = 90;
// หน้า log อ่านง่ายกว่าถ้าเห็นหลายแถวต่อหน้า — ยังมี LIMIT เสมอ
const ACTIVITY_DEFAULT_PER_PAGE = 20;
const ACTIVITY_DEFAULT_DAYS = 90;

// เรียงตามลำดับที่ผู้ใช้เห็นในตัวกรอง
const ACTIVITY_KINDS = ['report', 'detect', 'review', 'resolve'];

// days: จำนวนวันย้อนหลัง — 1..90 เท่านั้น ห้ามให้ client ขอย้อนหลังไม่จำกัด
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

// kind: null / "" / "all" = ทุกประเภท
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

/**
 * ทุก branch คืนคอลัมน์ชุดเดียวกัน เพื่อ UNION ALL ได้:
 *   kind, occurred_at, ref_id, location, record_origin, state, metric, extra
 *
 * $days ถูก activity_days() ตรวจเป็น int 1-90 มาแล้ว จึงต่อเข้า SQL ตรงได้
 * (INTERVAL ? DAY bind เป็น placeholder ไม่ได้ใน MySQL — เหมือนที่ LIMIT/OFFSET ทำอยู่)
 */
function activity_branch_sql(string $kind, int $days): string
{
    $window = "INTERVAL $days DAY";

    return match ($kind) {
        // ประชาชน/เจ้าหน้าที่แจ้งจุดขยะ
        'report' => "SELECT 'report' AS kind, created_at AS occurred_at, id AS ref_id,
                            location, record_origin, status AS state,
                            amount_kg AS metric, waste_type AS extra
                     FROM reports
                     WHERE created_at >= DATE_SUB(NOW(), $window)",
        // เหตุใหม่ที่เกิดจากการรวมการตรวจพบของโมเดล
        'detect' => "SELECT 'detect' AS kind, first_seen AS occurred_at, id AS ref_id,
                            location, record_origin, review_status AS state,
                            observation_count AS metric, camera_name AS extra
                     FROM vision_incidents
                     WHERE first_seen >= DATE_SUB(NOW(), $window)",
        // คนกดยืนยัน/ปฏิเสธ — state บอกว่าผลคืออะไร
        'review' => "SELECT 'review' AS kind, reviewed_at AS occurred_at, id AS ref_id,
                            location, record_origin, review_status AS state,
                            observation_count AS metric, camera_name AS extra
                     FROM vision_incidents
                     WHERE reviewed_at IS NOT NULL
                       AND reviewed_at >= DATE_SUB(NOW(), $window)",
        // ปิดงาน
        'resolve' => "SELECT 'resolve' AS kind, resolved_at AS occurred_at, id AS ref_id,
                            location, record_origin, action_status AS state,
                            observation_count AS metric, camera_name AS extra
                     FROM vision_incidents
                     WHERE resolved_at IS NOT NULL
                       AND resolved_at >= DATE_SUB(NOW(), $window)",
        default => throw new InvalidArgumentException("kind: ค่าไม่ถูกต้อง"),
    };
}

/** $kind = null → ทุกประเภทต่อกันด้วย UNION ALL */
function activity_sql(?string $kind, int $days): string
{
    $kinds = $kind === null ? ACTIVITY_KINDS : [$kind];
    $branches = array_map(fn(string $k) => activity_branch_sql($k, $days), $kinds);
    return implode("\n UNION ALL \n", $branches);
}

// PDO คืนตัวเลขเป็น string — cast ให้ frontend ไม่ต้องเดา
function activity_cast_row(array $row): array
{
    $row['ref_id'] = (int)$row['ref_id'];
    $row['metric'] = (int)$row['metric'];
    return $row;
}
