<?php
declare(strict_types=1);

require_once __DIR__ . '/report_photo.php';

const VALID_WASTE_TYPES = ["general", "recyclable", "hazardous", "organic"];
const VALID_REPORT_STATUSES = ["PENDING", "NEEDS_CHECK", "RESOLVED"];

// location_source บอกว่าพิกัด/ชื่อสถานที่มาจากไหน
// preset = เลือกจากพื้นที่ที่กำหนดไว้, gps = ตำแหน่งจาก browser geolocation, manual = ผู้ใช้พิมพ์เอง
const VALID_LOCATION_SOURCES = ["preset", "gps", "manual"];

// record_origin บอกว่าแถวนี้เกิดจากอะไร — แยกจาก location_source ที่บอกว่าพิกัดมาจากไหน
// citizen = คนแจ้งเข้ามาจริง, demo_seed = ข้อมูลตัวอย่างที่ tools/seed_demo.php ใส่ไว้
// หน้าเว็บต้องติดป้ายให้เห็นว่าแถวไหนเป็นตัวอย่าง และ tools/clear_demo.php ลบได้เฉพาะ demo_seed
const VALID_REPORT_ORIGINS = ["citizen", "demo_seed"];

// ตรวจพิกัดจาก browser geolocation ฝั่ง server ด้วย — ห้ามเชื่อค่าที่ client ส่งมา
function validate_coordinate(mixed $value, float $limit, string $name): ?float
{
    if ($value === null || $value === "") {
        return null;
    }
    if (is_bool($value) || !is_numeric($value)) {
        throw new InvalidArgumentException("$name: ต้องเป็นตัวเลข");
    }
    $float = (float)$value;
    if (!is_finite($float)) {
        throw new InvalidArgumentException("$name: ต้องเป็นตัวเลข");
    }
    if ($float < -$limit || $float > $limit) {
        throw new InvalidArgumentException("$name: ต้องอยู่ช่วง -$limit ถึง $limit");
    }
    // คอลัมน์เก็บ DECIMAL(10,7) — ปัดให้ตรงกับ DB ก่อน เพื่อไม่ให้ค่าที่อ่านกลับมาไม่ตรงกับที่ validate
    return round($float, 7);
}

// แปลงจาก morning/waste_logic.py::validate_report (ผ่าน test_waste_logic.py แล้ว)
// รัน tests/run_tests.php เพื่อตรวจ
function validate_report(array $data): array
{
    $location = $data["location"] ?? null;
    if (!is_string($location)) {
        throw new InvalidArgumentException("location: ต้องเป็น string");
    }
    $location = trim($location);
    if (mb_strlen($location) < 3 || mb_strlen($location) > 100) {
        throw new InvalidArgumentException("location: ยาวต้อง 3-100 ตัวอักษร");
    }

    // waste_type / amount_kg เป็นฟิลด์ไม่บังคับตั้งแต่ SPEC §2.2
    // ประชาชนไม่ควรต้องจำแนกประเภทขยะหรือประเมินน้ำหนักเป็นกิโลกรัมก่อนจึงจะแจ้งได้
    // ไม่ส่งมา / ส่งค่าว่าง / ส่ง null = NULL ("ไม่รู้") ไม่ใช่ค่า default ที่เราคิดแทนผู้ใช้
    // แต่ถ้า "ส่งมา" ต้องถูกต้องตามเดิมทุกข้อ — optional ไม่ได้แปลว่าปล่อยค่ามั่วเข้า DB
    $waste_type = $data["waste_type"] ?? null;
    if ($waste_type === null || $waste_type === "") {
        $waste_type = null;
    } elseif (!is_string($waste_type) || !in_array($waste_type, VALID_WASTE_TYPES, true)) {
        throw new InvalidArgumentException("waste_type: ต้องเป็นหนึ่งใน " . implode(", ", VALID_WASTE_TYPES));
    }

    $amount_kg = $data["amount_kg"] ?? null;
    if ($amount_kg === null || $amount_kg === "") {
        $amount_kg = null;
    } else {
        if (is_bool($amount_kg) || is_float($amount_kg)) {
            throw new InvalidArgumentException("amount_kg: ต้องเป็นจำนวนเต็มหรือ string ตัวเลข");
        }
        if (is_int($amount_kg)) {
            // already an integer
        } elseif (is_string($amount_kg) && ctype_digit($amount_kg)) {
            $amount_kg = (int)$amount_kg;
        } else {
            throw new InvalidArgumentException("amount_kg: ต้องเป็นจำนวนเต็มหรือ string ตัวเลข");
        }
        if ($amount_kg < 1 || $amount_kg > 1000) {
            throw new InvalidArgumentException("amount_kg: ต้องอยู่ช่วง 1-1000");
        }
    }

    $detail = $data["detail"] ?? "";
    if ($detail === null) {
        $detail = "";
    }
    if (!is_string($detail)) {
        throw new InvalidArgumentException("detail: ต้องเป็น string");
    }
    if (mb_strlen($detail) > 500) {
        throw new InvalidArgumentException("detail: ต้องยาวไม่เกิน 500 ตัวอักษร");
    }

    $location_source = $data["location_source"] ?? "manual";
    if ($location_source === null || $location_source === "") {
        $location_source = "manual";
    }
    if (!is_string($location_source) || !in_array($location_source, VALID_LOCATION_SOURCES, true)) {
        throw new InvalidArgumentException(
            "location_source: ต้องเป็นหนึ่งใน " . implode(", ", VALID_LOCATION_SOURCES)
        );
    }

    $record_origin = $data["record_origin"] ?? "citizen";
    if ($record_origin === null || $record_origin === "") {
        $record_origin = "citizen";
    }
    if (!is_string($record_origin) || !in_array($record_origin, VALID_REPORT_ORIGINS, true)) {
        throw new InvalidArgumentException(
            "record_origin: ต้องเป็นหนึ่งใน " . implode(", ", VALID_REPORT_ORIGINS)
        );
    }

    $latitude = validate_coordinate($data["latitude"] ?? null, 90.0, "latitude");
    $longitude = validate_coordinate($data["longitude"] ?? null, 180.0, "longitude");

    // พิกัดครึ่งเดียวใช้ไม่ได้ — ต้องมาคู่กันหรือไม่มาเลย
    if (($latitude === null) !== ($longitude === null)) {
        throw new InvalidArgumentException("latitude: ต้องระบุ latitude และ longitude คู่กัน");
    }
    if ($location_source === "gps" && $latitude === null) {
        throw new InvalidArgumentException("latitude: ต้องระบุพิกัดเมื่อ location_source = gps");
    }

    // image_path ปกติถูกตั้งโดย save_report_photo() ฝั่ง server หลังบันทึกไฟล์สำเร็จ
    // ค่าที่ client ส่งมาเองจะผ่านก็ต่อเมื่อชี้ไปไฟล์ที่มีอยู่จริงใน uploads/reports/
    // (ชื่อไฟล์เป็น random 32 hex) — path มั่ว/../ /URL กลายเป็น null ไม่ใช่ error
    $image_path = report_photo_path_or_null($data["image_path"] ?? null);

    return [
        "location" => $location,
        "waste_type" => $waste_type,
        "amount_kg" => $amount_kg,
        "detail" => $detail,
        "image_path" => $image_path,
        "latitude" => $latitude,
        "longitude" => $longitude,
        "location_source" => $location_source,
        "record_origin" => $record_origin,
    ];
}
