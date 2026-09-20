<?php
declare(strict_types=1);

require_once __DIR__ . '/vision_evidence.php';

// รูปที่ประชาชนแนบมากับเรื่องแจ้ง (report.html → POST api/reports.php แบบ multipart)
//
// ต่างจาก assets/vision/ ตรงที่ไฟล์พวกนี้มาจากคนภายนอก จึงถือว่า **ไม่น่าเชื่อถือทั้งหมด**
// กฎที่ยึด:
//   - ไม่ใช้ชื่อไฟล์จากผู้ใช้เลย ระบบสุ่มชื่อเองทุกครั้ง
//     (ชื่อจากผู้ใช้พา path traversal / double extension / อักขระแปลกเข้ามาได้)
//   - ตัดสินชนิดไฟล์จาก **เนื้อไฟล์จริง** ด้วย getimagesize() ไม่ใช่จาก
//     $_FILES[...]['type'] ซึ่ง browser ส่งมาและปลอมได้
//   - DB เก็บแค่ relative path ไม่เก็บ binary/base64
//   - ตอนอ่านกลับยังตรวจซ้ำผ่าน safe_media_path() เหมือน assets/vision/
//
// หมายเหตุ deployment: โฟลเดอร์นี้เป็น local filesystem
// บน Railway ไฟล์อัปโหลดจะหายเมื่อ deploy ใหม่ (ephemeral) — แถวใน DB ยังอยู่
// แต่รูปจะไม่มี ดังนั้นฝั่งอ่านต้องทนกับ "มี path แต่ไม่มีไฟล์" ได้เสมอ

const REPORT_PHOTO_PREFIX = 'uploads/reports/';
const REPORT_PHOTO_EXTENSIONS = ['jpg', 'png', 'webp'];
const REPORT_PHOTO_MAX_BYTES = 5 * 1024 * 1024;

// ชนิดที่ getimagesize() ตรวจได้จริง → นามสกุลที่เราจะตั้งให้
// ไม่รับ GIF/BMP/SVG: SVG เป็น XML ที่ฝัง script ได้ ส่วนที่เหลือไม่จำเป็นกับ use case นี้
const REPORT_PHOTO_TYPE_EXTENSION = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_WEBP => 'webp',
];

function report_photo_root(): string
{
    return __DIR__ . '/../uploads/reports';
}

/**
 * รับ $_FILES['photo'] แล้วคืน relative path ที่บันทึกแล้ว
 * คืน null ถ้าผู้ใช้ไม่ได้แนบรูป (รูปเป็นฟิลด์ไม่บังคับ)
 * throw InvalidArgumentException ถ้าแนบมาแต่ใช้ไม่ได้
 */
function save_report_photo(mixed $file): ?string
{
    if ($file === null) {
        return null;
    }
    if (!is_array($file) || !isset($file['error']) || !is_int($file['error'])) {
        throw new InvalidArgumentException('photo: malformed upload');
    }
    // ฟอร์มที่ไม่ได้เลือกไฟล์จะส่ง UPLOAD_ERR_NO_FILE มา — ไม่ใช่ error ของผู้ใช้
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('photo: ไฟล์ใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('photo: อัปโหลดไม่สำเร็จ กรุณาลองใหม่');
    }

    $tmp = $file['tmp_name'] ?? '';
    // กันไม่ให้ชี้ไปไฟล์อื่นในเครื่อง — ต้องเป็นไฟล์ที่มาจาก HTTP upload จริงเท่านั้น
    if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('photo: ไฟล์อัปโหลดไม่ถูกต้อง');
    }

    $size = filesize($tmp);
    if ($size <= 0) {
        throw new InvalidArgumentException('photo: ไฟล์ว่าง');
    }
    if ($size > REPORT_PHOTO_MAX_BYTES) {
        $mb = (int)(REPORT_PHOTO_MAX_BYTES / 1024 / 1024);
        throw new InvalidArgumentException("photo: ไฟล์ต้องไม่เกิน {$mb} MB");
    }

    // ตัดสินจากเนื้อไฟล์ ไม่ใช่จากนามสกุลหรือ MIME ที่ browser ส่งมา
    $info = @getimagesize($tmp);
    if ($info === false || !isset($info[2]) || !isset(REPORT_PHOTO_TYPE_EXTENSION[$info[2]])) {
        throw new InvalidArgumentException('photo: รองรับเฉพาะไฟล์รูป JPG, PNG หรือ WEBP');
    }
    $ext = REPORT_PHOTO_TYPE_EXTENSION[$info[2]];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if ($mime !== $info['mime']) {
        throw new InvalidArgumentException('photo: invalid image MIME');
    }

    $root = report_photo_root();
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        throw new InvalidArgumentException('photo: เซิร์ฟเวอร์ยังไม่พร้อมรับไฟล์');
    }

    // ชื่อไฟล์มาจาก random_bytes ล้วน ๆ — ไม่มีส่วนไหนมาจาก input ของผู้ใช้
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $root . '/' . $name)) {
        throw new InvalidArgumentException('photo: บันทึกไฟล์ไม่สำเร็จ');
    }

    return REPORT_PHOTO_PREFIX . $name;
}

/** ตอนอ่านกลับ: ค่าที่ใช้ไม่ได้/ไฟล์หายกลายเป็น null แทนที่จะปล่อย path เสียไปถึง browser */
function report_photo_path_or_null(mixed $value): ?string
{
    try {
        return safe_media_path(
            $value,
            REPORT_PHOTO_PREFIX,
            report_photo_root(),
            REPORT_PHOTO_EXTENSIONS,
            'image_path'
        );
    } catch (InvalidArgumentException $e) {
        return null;
    }
}
