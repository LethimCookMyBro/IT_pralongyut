<?php
declare(strict_types=1);

const VALID_WASTE_TYPES = ["general", "recyclable", "hazardous", "organic"];

// แปลงจาก waste_logic.py ตอนเช้า — รัน tests/run_tests.php เพื่อตรวจ
function validate_report(array $data): array
{
    $location = $data["location"] ?? null;
    if (!is_string($location)) {
        throw new InvalidArgumentException("location: ต้องเป็น string");
    }
    $location = trim($location);
    if (strlen($location) < 3 || strlen($location) > 100) {
        throw new InvalidArgumentException("location: ยาวต้อง 3-100 ตัวอักษร");
    }

    $waste_type = $data["waste_type"] ?? null;
    if (!in_array($waste_type, VALID_WASTE_TYPES)) {
        throw new InvalidArgumentException("waste_type: ต้องเป็นหนึ่งใน " . implode(", ", VALID_WASTE_TYPES));
    }

    $amount_kg = $data["amount_kg"] ?? null;
    if (!is_int($amount_kg)) {
        if (!ctype_digit((string)$amount_kg)) {
            throw new InvalidArgumentException("amount_kg: ไม่ใช่ตัวเลขจำนวนเต็ม");
        }
        $amount_kg = (int)$amount_kg;
    }
    if ($amount_kg < 1 || $amount_kg > 1000) {
        throw new InvalidArgumentException("amount_kg: ต้องอยู่ช่วง 1-1000");
    }

    $detail = $data["detail"] ?? "";
    if (!is_string($detail)) {
        throw new InvalidArgumentException("detail: ต้องเป็น string");
    }
    if (mb_strlen($detail) > 500) {
        $detail = mb_substr($detail, 0, 500);
    }

    return [
        "location" => $location,
        "waste_type" => $waste_type,
        "amount_kg" => $amount_kg,
        "detail" => $detail,
    ];
}
