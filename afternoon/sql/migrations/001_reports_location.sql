-- Migration 001 — เพิ่มฟิลด์ตำแหน่งให้ตาราง reports
--
-- NON-DESTRUCTIVE: ALTER TABLE เท่านั้น ไม่มี DROP / TRUNCATE / DELETE
-- ข้อมูล reports เดิมอยู่ครบ และจะได้ location_source = 'manual' (ค่า default)
-- ซึ่งตรงกับความจริงว่าเดิมผู้ใช้พิมพ์ชื่อสถานที่เองทั้งหมด
--
-- รัน:
--   C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 < afternoon/sql/migrations/001_reports_location.sql
--
-- IF NOT EXISTS ใช้ได้เพราะ XAMPP ใช้ MariaDB (ตรวจแล้ว 10.4) — รันซ้ำได้ปลอดภัย

USE bangsaen_waste;

ALTER TABLE reports
    ADD COLUMN IF NOT EXISTS latitude DECIMAL(10,7) NULL AFTER location,
    ADD COLUMN IF NOT EXISTS longitude DECIMAL(10,7) NULL AFTER latitude,
    ADD COLUMN IF NOT EXISTS location_source ENUM('preset','gps','manual')
        NOT NULL DEFAULT 'manual' AFTER longitude;

-- index ช่วย bounding-box query ของ api/reports-similar.php
ALTER TABLE reports
    ADD INDEX IF NOT EXISTS idx_reports_latlng (latitude, longitude);

-- index ช่วย filter/search + pagination ของ api/reports.php
ALTER TABLE reports
    ADD INDEX IF NOT EXISTS idx_reports_listing (status, waste_type, created_at);
