<?php
declare(strict_types=1);

// ภาพหลักฐานของ "การตรวจพบหนึ่งครั้ง" (observation) — ไม่ใช่ของ incident
//
// DB เก็บแค่ relative path เช่น assets/vision/street-replay-01.jpg
// ไม่เก็บ binary/base64 และไม่รับ path จาก browser แบบอิสระ
//
// กติกา: ต้องเป็นไฟล์รูปที่อยู่ในโฟลเดอร์ที่อนุญาตเท่านั้น ชั้นเดียว ไม่มีโฟลเดอร์ย่อย
// ใช้ทั้งตอนเขียน (POST api/vision.php) และตอนอ่าน (กันแถวเก่า/แถวที่ถูกแก้มือหลุดไปถึง browser)

const VISION_EVIDENCE_PREFIX = 'assets/vision/';
const VISION_EVIDENCE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

function vision_evidence_root(): string
{
    return __DIR__ . '/../assets/vision';
}

/**
 * คืน relative path ที่ปลอดภัยแล้ว, null ถ้าไม่ได้ส่งมา,
 * และ throw ถ้าส่งมาแต่ใช้ไม่ได้ (เช่น ../, URL, นามสกุลไม่ใช่รูป, ไฟล์ไม่มีจริง)
 */
function validate_evidence_image_path(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException('image_path: ต้องเป็น string');
    }

    $path = trim($value);
    if ($path === '') {
        return null;
    }

    // null byte ตัดทิ้งทันที — กัน "x.jpg\0.php"
    if (str_contains($path, "\0")) {
        throw new InvalidArgumentException('image_path: มีอักขระที่ไม่อนุญาต');
    }
    // backslash = path แบบ Windows, ไม่รับ
    if (str_contains($path, '\\')) {
        throw new InvalidArgumentException('image_path: ต้องใช้ / เท่านั้น');
    }
    // URL / scheme ทุกชนิด (http:, https:, data:, javascript:) และ protocol-relative
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $path) || str_starts_with($path, '//')) {
        throw new InvalidArgumentException('image_path: ต้องเป็น path ในโปรเจกต์ ไม่ใช่ URL');
    }
    if (!str_starts_with($path, VISION_EVIDENCE_PREFIX)) {
        throw new InvalidArgumentException('image_path: ต้องอยู่ใน ' . VISION_EVIDENCE_PREFIX);
    }

    $name = substr($path, strlen(VISION_EVIDENCE_PREFIX));
    // ชั้นเดียว: ห้ามมี / เหลือ, ห้ามขึ้นต้นด้วยจุด, อนุญาตเฉพาะ a-z A-Z 0-9 . _ -
    // เงื่อนไขนี้ตัด "..", "../x", "sub/x.jpg", ".hidden.jpg" ไปพร้อมกัน
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
        throw new InvalidArgumentException('image_path: ชื่อไฟล์ไม่ถูกต้อง');
    }
    if (str_contains($name, '..')) {
        throw new InvalidArgumentException('image_path: ชื่อไฟล์ไม่ถูกต้อง');
    }
    // มีจุดเดียวสำหรับนามสกุล — กัน double extension เช่น x.jpg.php
    if (substr_count($name, '.') !== 1) {
        throw new InvalidArgumentException('image_path: ชื่อไฟล์ไม่ถูกต้อง');
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, VISION_EVIDENCE_EXTENSIONS, true)) {
        throw new InvalidArgumentException(
            'image_path: ต้องเป็นไฟล์รูป (' . implode(', ', VISION_EVIDENCE_EXTENSIONS) . ')'
        );
    }

    // ต้องมีไฟล์จริงในโฟลเดอร์ที่อนุญาต — ไม่ให้ DB ชี้ไปที่ไฟล์ที่ไม่มีอยู่
    $full = vision_evidence_root() . '/' . $name;
    if (!is_file($full)) {
        throw new InvalidArgumentException('image_path: ไม่พบไฟล์นี้ใน ' . VISION_EVIDENCE_PREFIX);
    }

    return VISION_EVIDENCE_PREFIX . $name;
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
