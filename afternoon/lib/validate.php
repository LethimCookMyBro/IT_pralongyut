<?php
declare(strict_types=1);

const VALID_WASTE_TYPES = ["general", "recyclable", "hazardous", "organic"];

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

    $waste_type = $data["waste_type"] ?? null;
    if (!is_string($waste_type) || !in_array($waste_type, VALID_WASTE_TYPES, true)) {
        throw new InvalidArgumentException("waste_type: ต้องเป็นหนึ่งใน " . implode(", ", VALID_WASTE_TYPES));
    }

    $amount_kg = $data["amount_kg"] ?? null;
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

    return [
        "location" => $location,
        "waste_type" => $waste_type,
        "amount_kg" => $amount_kg,
        "detail" => $detail,
    ];
}
