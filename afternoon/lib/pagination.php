<?php
declare(strict_types=1);

// Server-side pagination ที่ใช้ร่วมกันระหว่าง api/reports.php และ api/vision.php
// ทุก list endpoint ต้องมี LIMIT เสมอ ห้ามดึงข้อมูลไม่จำกัด

const PAGINATION_DEFAULT_PER_PAGE = 10;
const PAGINATION_MAX_PER_PAGE = 100;

// page/per_page ที่ไม่ใช่จำนวนเต็ม >= 1 = คำขอผิด (422)
// page ที่เกินจำนวนหน้าจริง "ไม่ใช่" error — จะได้ list ว่างพร้อม total ที่ถูกต้อง
// เพื่อให้ UI clamp กลับหน้าสุดท้ายได้เองโดยไม่ต้องจัดการ error
function pagination_positive_int(mixed $value, int $fallback, string $name): int
{
    if ($value === null || $value === '') {
        return $fallback;
    }
    if (is_int($value)) {
        $int = $value;
    } elseif (is_string($value) && ctype_digit($value)) {
        $int = (int)$value;
    } else {
        throw new InvalidArgumentException("$name: ต้องเป็นจำนวนเต็ม >= 1");
    }
    if ($int < 1) {
        throw new InvalidArgumentException("$name: ต้องเป็นจำนวนเต็ม >= 1");
    }
    return $int;
}

function pagination_params(array $query, int $default_per_page = PAGINATION_DEFAULT_PER_PAGE): array
{
    $page = pagination_positive_int($query['page'] ?? null, 1, 'page');
    $per_page = pagination_positive_int($query['per_page'] ?? null, $default_per_page, 'per_page');
    if ($per_page > PAGINATION_MAX_PER_PAGE) {
        throw new InvalidArgumentException('per_page: ต้องไม่เกิน ' . PAGINATION_MAX_PER_PAGE);
    }

    return [
        'page' => $page,
        'per_page' => $per_page,
        'offset' => ($page - 1) * $per_page,
    ];
}

function pagination_meta(int $page, int $per_page, int $total): array
{
    return [
        'page' => $page,
        'per_page' => $per_page,
        'total' => $total,
        'total_pages' => $total === 0 ? 0 : (int)ceil($total / $per_page),
    ];
}

// ใช้กับ search box: แปลงคำค้นเป็น LIKE pattern และ escape % _ ที่ผู้ใช้พิมพ์มา
// คืน null ถ้าคำค้นว่าง เพื่อให้ caller ข้าม WHERE ไปเลย
function search_like_pattern(mixed $value, int $max_length = 100): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $term = trim($value);
    if ($term === '') {
        return null;
    }
    if (mb_strlen($term) > $max_length) {
        $term = mb_substr($term, 0, $max_length);
    }
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    return "%$escaped%";
}
