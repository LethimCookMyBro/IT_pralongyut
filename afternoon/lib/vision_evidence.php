<?php
declare(strict_types=1);

// ภาพหลักฐานของ "การตรวจพบหนึ่งครั้ง" (observation) — ไม่ใช่ของ incident
//
// DB เก็บแค่ relative path เช่น assets/vision/street-replay-01.jpg
// ไม่เก็บ binary/base64 และไม่รับ path จาก browser แบบอิสระ
//
// กติกา: ต้องเป็นไฟล์รูปที่อยู่ในโฟลเดอร์ที่อนุญาตเท่านั้น ชั้นเดียว ไม่มีโฟลเดอร์ย่อย
// ใช้ทั้งตอนเขียน (POST api/vision.php) และตอนอ่าน (กันแถวเก่า/แถวที่ถูกแก้มือหลุดไปถึง browser)
//
// safe_media_path() เป็นตัวตรวจกลาง — lib/report_photo.php ก็เรียกตัวนี้ด้วย
// ให้รูปหลักฐานของ AI และรูปที่ประชาชนแนบใช้กฎความปลอดภัยชุดเดียวกัน
// (แก้กฎที่เดียว ไม่ต้องไล่แก้สองที่แล้วหลุดไปที่ใดที่หนึ่ง)

const VISION_EVIDENCE_PREFIX = 'assets/vision/';
const VISION_EVIDENCE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

function vision_evidence_root(): string
{
    return __DIR__ . '/../assets/vision';
}

/**
 * ตัวตรวจกลางของ relative path รูปที่เก็บลง DB
 *
 * ผ่านก็ต่อเมื่อ: อยู่ใต้ $prefix พอดี, ชั้นเดียว, ชื่อไฟล์ปลอดภัย,
 * นามสกุลอยู่ใน allowlist และไฟล์มีอยู่จริงใน $root
 *
 * ตัดทิ้ง: null byte, backslash, URL/scheme ทุกชนิด, protocol-relative,
 * "..", double extension (x.jpg.php) และชื่อที่ขึ้นต้นด้วยจุด
 */
function safe_media_path(
    mixed $value,
    string $prefix,
    string $root,
    array $extensions,
    string $field
): ?string {
    if ($value === null) {
        return null;
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException("$field: ต้องเป็น string");
    }

    $path = trim($value);
    if ($path === '') {
        return null;
    }

    // null byte ตัดทิ้งทันที — กัน "x.jpg\0.php"
    if (str_contains($path, "\0")) {
        throw new InvalidArgumentException("$field: มีอักขระที่ไม่อนุญาต");
    }
    // backslash = path แบบ Windows, ไม่รับ
    if (str_contains($path, '\\')) {
        throw new InvalidArgumentException("$field: ต้องใช้ / เท่านั้น");
    }
    // URL / scheme ทุกชนิด (http:, https:, data:, javascript:) และ protocol-relative
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $path) || str_starts_with($path, '//')) {
        throw new InvalidArgumentException("$field: ต้องเป็น path ในโปรเจกต์ ไม่ใช่ URL");
    }
    if (!str_starts_with($path, $prefix)) {
        throw new InvalidArgumentException("$field: ต้องอยู่ใน " . $prefix);
    }

    $name = substr($path, strlen($prefix));
    // ชั้นเดียว: ห้ามมี / เหลือ, ห้ามขึ้นต้นด้วยจุด, อนุญาตเฉพาะ a-z A-Z 0-9 . _ -
    // เงื่อนไขนี้ตัด "..", "../x", "sub/x.jpg", ".hidden.jpg" ไปพร้อมกัน
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
        throw new InvalidArgumentException("$field: ชื่อไฟล์ไม่ถูกต้อง");
    }
    if (str_contains($name, '..')) {
        throw new InvalidArgumentException("$field: ชื่อไฟล์ไม่ถูกต้อง");
    }
    // มีจุดเดียวสำหรับนามสกุล — กัน double extension เช่น x.jpg.php
    if (substr_count($name, '.') !== 1) {
        throw new InvalidArgumentException("$field: ชื่อไฟล์ไม่ถูกต้อง");
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $extensions, true)) {
        throw new InvalidArgumentException(
            "$field: ต้องเป็นไฟล์รูป (" . implode(', ', $extensions) . ")"
        );
    }

    // ต้องมีไฟล์จริงในโฟลเดอร์ที่อนุญาต — ไม่ให้ DB ชี้ไปที่ไฟล์ที่ไม่มีอยู่
    if (!is_file($root . '/' . $name)) {
        throw new InvalidArgumentException("$field: ไม่พบไฟล์นี้ใน " . $prefix);
    }

    return $prefix . $name;
}

/**
 * คืน relative path ที่ปลอดภัยแล้ว, null ถ้าไม่ได้ส่งมา,
 * และ throw ถ้าส่งมาแต่ใช้ไม่ได้ (เช่น ../, URL, นามสกุลไม่ใช่รูป, ไฟล์ไม่มีจริง)
 */
function validate_evidence_image_path(mixed $value): ?string
{
    return safe_media_path(
        $value,
        VISION_EVIDENCE_PREFIX,
        vision_evidence_root(),
        VISION_EVIDENCE_EXTENSIONS,
        'image_path'
    );
}

/** เวอร์ชันสำหรับตอนอ่าน: ไม่ throw — ค่าที่ใช้ไม่ได้กลายเป็น null */
function evidence_path_or_null(mixed $value): ?string
{
    try {
        return validate_evidence_image_path($value);
    } catch (InvalidArgumentException $e) {
        return null;
    }
}
