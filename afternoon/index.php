<?php
declare(strict_types=1);

// มีไว้สองอย่าง:
//   1) ให้ตัวตรวจ build ของ Railway (Railpack) เห็นว่านี่คือ PHP app
//   2) ให้เปิด "/" แล้วได้หน้าแรกเลย
//
// ไม่ได้ทำ UI ซ้ำ — ส่งไฟล์ index.html เดิมออกไปตรง ๆ
// URL เดิมทุกหน้า (index.html, report.html, reports.html, vision.html, incident.html) ยังใช้ได้เหมือนเดิม

header("Content-Type: text/html; charset=utf-8");
readfile(__DIR__ . "/index.html");
