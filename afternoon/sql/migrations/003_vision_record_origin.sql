-- 003_vision_record_origin.sql
-- แยก "ช่องทางภาพ" (source_mode) ออกจาก "ที่มาของแถวข้อมูล" (record_origin)
-- additive only: ห้าม DROP / TRUNCATE / DELETE
-- แถวเดิมทั้งหมดได้ demo_seed เพื่อไม่อ้างว่าเป็นผลจาก detector จริงโดยไม่มีหลักฐาน
--
-- รันครั้งเดียวบนฐานที่มีอยู่:
-- C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 bangsaen_waste -e "source afternoon/sql/migrations/003_vision_record_origin.sql"

ALTER TABLE vision_incidents
    ADD COLUMN record_origin ENUM('demo_seed','detector_run')
    NOT NULL DEFAULT 'demo_seed'
    AFTER source_mode;

ALTER TABLE vision_observations
    ADD COLUMN record_origin ENUM('demo_seed','detector_run')
    NOT NULL DEFAULT 'demo_seed'
    AFTER source_mode;
