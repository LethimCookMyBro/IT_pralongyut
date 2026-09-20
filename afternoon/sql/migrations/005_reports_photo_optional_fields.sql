-- 005_reports_photo_optional_fields.sql
-- ปรับ reports ให้ตรงกับ SPEC ใหม่ของหน้าแจ้งจุดขยะ (SPEC_TEMPLATE.md §2.2)
--
-- เปลี่ยนสองเรื่อง:
--   1. waste_type / amount_kg → NULL ได้
--   2. เพิ่ม image_path สำหรับรูปที่ประชาชนแนบมา
--
-- ทำไม:
-- ฟอร์มเดิมบังคับให้ประชาชนเลือกประเภทขยะและประเมินน้ำหนักเป็นกิโลกรัมก่อนจึงจะแจ้งได้
-- คนทั่วไปประเมินน้ำหนักจากการมองไม่ได้ และไม่ควรต้องจำแนกประเภทให้ถูกก่อนแจ้ง
-- ทางแก้ที่ผิดคือให้ frontend ส่งค่าปลอม (เช่น general/1) เพื่อให้ validation ผ่าน
-- เพราะจะได้ข้อมูลที่ดูเหมือนจริงแต่ไม่มีใครกรอก — จึงเลือกให้คอลัมน์เป็น NULL ตามความจริง
-- NULL = "ไม่รู้" ไม่ใช่ "ศูนย์" หน้ารายการต้องแสดง — ไม่ใช่เดาค่า
--
-- additive / non-destructive:
-- - MODIFY ที่ทำคือ "ผ่อน NOT NULL ให้เป็น NULL ได้" ซึ่งไม่ทำให้แถวเดิมเสียค่า
--   (ตรงข้ามกับการบีบ NULL → NOT NULL ที่จะทำข้อมูลหาย)
-- - ไม่มี DROP / TRUNCATE / DELETE และไม่ได้สร้างตารางใหม่
-- - แถวเดิมทุกแถวยังมี waste_type/amount_kg เท่าเดิม และได้ image_path = NULL
--
-- รันครั้งเดียวบนฐานที่มีอยู่:
-- C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 bangsaen_waste < afternoon/sql/migrations/005_reports_photo_optional_fields.sql

ALTER TABLE reports
    MODIFY COLUMN waste_type VARCHAR(20) NULL,
    MODIFY COLUMN amount_kg INT NULL,
    ADD COLUMN image_path VARCHAR(255) NULL AFTER detail;

-- ไม่มีขั้น backfill โดยเจตนา
-- แถวเก่ามีค่าที่คนกรอกไว้จริงอยู่แล้ว ห้ามเขียนทับ
-- และแถวใหม่ที่ไม่มีค่าต้องเป็น NULL ไม่ใช่ค่า default ที่ระบบคิดขึ้นเอง
