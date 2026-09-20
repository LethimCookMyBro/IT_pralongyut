-- 004_reports_record_origin.sql
-- แยก "เรื่องที่คนแจ้งเข้ามาจริง" ออกจาก "ข้อมูลตัวอย่างสำหรับเดโม"
-- additive only: ห้าม DROP / TRUNCATE / DELETE
--
-- ทำไมต้องมี: tools/seed_demo.php ใส่เรื่องแจ้งสมมติเพื่อให้เห็น pagination/filter ทำงาน
-- แต่เดิมแถวเหล่านั้นหน้าตาเหมือนเรื่องจริงทุกอย่าง กรรมการ/ผู้ใช้จึงอ่านเป็นข้อมูลจริง
-- ของเทศบาลได้ คอลัมน์นี้ทำให้หน้าเว็บติดป้าย "ตัวอย่าง" ได้ และ tools/clear_demo.php
-- ลบเฉพาะแถว demo_seed ได้โดยไม่แตะเรื่องจริง
--
-- แถวเดิมทั้งหมดได้ 'citizen' — ตรงกับสัญญาเดิมของ API ที่ไม่มีคอลัมน์นี้
-- (ถ้าฐานนี้เคยรัน seed_demo.php ไว้ ดูวิธี relabel ท้ายไฟล์)
--
-- รันครั้งเดียวบนฐานที่มีอยู่:
-- C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 bangsaen_waste < afternoon/sql/migrations/004_reports_record_origin.sql

ALTER TABLE reports
    ADD COLUMN record_origin ENUM('citizen','demo_seed')
    NOT NULL DEFAULT 'citizen'
    AFTER location_source;

-- ทางเลือก (ต้องตัดสินใจเอง ไม่รันให้อัตโนมัติ):
-- ถ้ารู้แน่ว่าแถวก่อนหน้านี้มาจาก tools/seed_demo.php ทั้งหมด ให้ relabel ด้วย
--   UPDATE reports SET record_origin = 'demo_seed' WHERE id <= <id สุดท้ายของ seed>;
-- ห้าม relabel แบบเหวี่ยง — การติดป้ายผิดทางใดก็ทำให้ข้อมูลอธิบายตัวเองผิด
