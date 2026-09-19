<?php
declare(strict_types=1);

/**
 * การเชื่อมต่อฐานข้อมูล
 *
 * อ่านค่าจาก environment variables ก่อน (ชื่อเดียวกับที่ Railway MySQL ตั้งให้)
 * ถ้าไม่มี ใช้ค่า XAMPP เดิมบนเครื่อง local — workshop จึงรันได้เหมือนเดิมโดยไม่ต้องตั้งอะไร
 *
 * ห้าม hardcode รหัสผ่านของ deployment ไว้ในไฟล์นี้ (ดู .env.example)
 */

/**
 * ค่าที่จะใช้ต่อฐานข้อมูล — แยกออกมาเพื่อให้ทดสอบได้โดยไม่ต้องต่อฐานข้อมูลจริง
 * ห้ามพิมพ์ค่าที่ได้จากฟังก์ชันนี้ออกไปที่ response (มีรหัสผ่านอยู่ข้างใน)
 */
function db_config(): array
{
    // รหัสผ่านว่างเป็นค่าที่ถูกต้องของ XAMPP จึงเช็ค false (ไม่ได้ตั้งไว้) แยกจากค่าว่าง
    $pass = getenv("MYSQLPASSWORD");

    return [
        "host" => getenv("MYSQLHOST") ?: "127.0.0.1",
        "port" => getenv("MYSQLPORT") ?: "3306",
        "name" => getenv("MYSQLDATABASE") ?: "bangsaen_waste",
        "user" => getenv("MYSQLUSER") ?: "root",
        "pass" => $pass === false ? "" : $pass,
    ];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        ["host" => $host, "port" => $port, "name" => $name, "user" => $user, "pass" => $pass] = db_config();

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
