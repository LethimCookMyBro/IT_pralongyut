<?php
declare(strict_types=1);

// เมื่อเว็บอยู่บนอินเทอร์เน็ตสาธารณะ ข้อความ error ของ PHP (path ในเครื่อง, ชื่อ user ฐานข้อมูล)
// ไม่ควรถูกส่งออกไปกับ response — ปิดไว้เป็นค่าเริ่มต้น และยังเขียนลง error log ตามปกติ
// ตั้ง APP_DEBUG=1 เมื่ออยากเห็น error บนหน้าจอตอนพัฒนา
if (getenv("APP_DEBUG") !== "1") {
    ini_set("display_errors", "0");
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400): never
{
    json_response(["error" => $message], $status);
}
