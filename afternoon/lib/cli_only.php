<?php
declare(strict_types=1);

// สคริปต์ที่ require ไฟล์นี้ เขียน/ลบข้อมูลในฐานข้อมูลได้ จึงต้องรันจาก command line เท่านั้น
// ถ้าเว็บถูก deploy ขึ้นอินเทอร์เน็ต ไฟล์เหล่านี้จะเรียกผ่าน URL ไม่ได้
//
// รันจาก CLI:  C:\xampp\php\php.exe afternoon\tools\seed_demo.php
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    header("Content-Type: text/plain; charset=utf-8");
    exit("Not found\n");
}
