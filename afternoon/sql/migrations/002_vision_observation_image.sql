-- 002_vision_observation_image.sql
-- เพิ่มภาพหลักฐานให้ "การตรวจพบหนึ่งครั้ง" (observation)
-- ภาพเป็นหลักฐานของการตรวจครั้งนั้น จึงผูกกับ observation ไม่ใช่ผูกกับ incident แบบแข็ง
--
-- additive เท่านั้น: ALTER TABLE + UPDATE เฉพาะแถวที่พิสูจน์ที่มาได้
-- ไม่มี DROP / TRUNCATE และไม่แตะข้อมูลเดิมแถวอื่น
--
-- รัน: C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 < afternoon/sql/migrations/002_vision_observation_image.sql

USE bangsaen_waste;

ALTER TABLE vision_observations
    ADD COLUMN image_path VARCHAR(255) NULL AFTER source_mode;

-- Backfill: ผูกภาพกับ "การตรวจพบที่สร้างภาพนั้นจริง ๆ" เท่านั้น
--
-- references/spike/run_inference.py รัน pLitterStreet_YOLOv5l กับ
-- references/test_images/litter_singapore_ecp.jpg ได้ 5 กล่อง ความมั่นใจสูงสุด 0.59
-- แล้ว references/spike/post_to_vision_api.py ส่งผลนั้นเข้า API เป็น
-- camera_name = 'Replay-Street-01', detected_count = 5, max_confidence = 0.5886
--
-- เงื่อนไขด้านล่างจึงเจาะจงลายเซ็นของการรันครั้งนั้น ไม่ใช่ "กล้องนี้ทั้งหมด"
-- แถว seed/demo และแถวทดสอบอื่นจะไม่ถูกแตะ และยังไม่มีภาพ (image_path = NULL) ตามจริง
UPDATE vision_observations
SET image_path = 'assets/vision/street-replay-01.jpg'
WHERE camera_name = 'Replay-Street-01'
  AND source_mode = 'replay'
  AND detected_count = 5
  AND max_confidence = 0.5886
  AND image_path IS NULL;
