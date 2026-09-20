-- Bangsaen Waste Watch / Waste Vision — schema.sql
-- สร้างฐานข้อมูล ตาราง และ seed ข้อมูลเปิดจริงจาก data/waste_chonburi.csv
-- รัน: C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 < afternoon/sql/schema.sql
-- (ต้องใส่ --default-character-set=utf8mb4 ไม่งั้นข้อความไทยจะเพี้ยนตอน insert)

CREATE DATABASE IF NOT EXISTS bangsaen_waste CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bangsaen_waste;

-- ตารางเดิม: สถิติขยะจากข้อมูลเปิดจังหวัดชลบุรี (นำเข้าจาก data/waste_chonburi.csv)
DROP TABLE IF EXISTS waste_stats;
CREATE TABLE waste_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    district VARCHAR(100) NOT NULL,
    local_gov VARCHAR(150) NOT NULL,
    generated_tpd DECIMAL(8,2) NULL,
    collected_tpd DECIMAL(8,2) NULL,
    utilized_tpd DECIMAL(8,2) NULL,
    proper_tpd DECIMAL(8,2) NULL,
    improper_tpd DECIMAL(8,2) NULL
);

-- ตารางเดิม: จุดแจ้งขยะจากประชาชน/เจ้าหน้าที่
-- latitude/longitude/location_source เพิ่มใน migrations/001_reports_location.sql
-- record_origin เพิ่มใน migrations/004_reports_record_origin.sql
-- ที่นี่ใส่ไว้ให้ fresh install ได้โครงเดียวกันกับฐานที่ migrate แล้ว
DROP TABLE IF EXISTS reports;
CREATE TABLE reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location VARCHAR(100) NOT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    location_source ENUM('preset','gps','manual') NOT NULL DEFAULT 'manual',
    -- citizen = คนแจ้งจริง, demo_seed = ข้อมูลตัวอย่างจาก tools/seed_demo.php
    record_origin ENUM('citizen','demo_seed') NOT NULL DEFAULT 'citizen',
    waste_type VARCHAR(20) NOT NULL,
    amount_kg INT NOT NULL,
    detail VARCHAR(500) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reports_latlng (latitude, longitude),
    INDEX idx_reports_listing (status, waste_type, created_at)
);

-- Waste Vision:
-- raw detection/observation != incident != task
-- Human review ทำกับ incident ไม่ใช่ observation แต่ละ frame

DROP TABLE IF EXISTS vision_observations;
DROP TABLE IF EXISTS vision_incidents;

CREATE TABLE vision_incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    camera_name VARCHAR(100) NOT NULL,
    location VARCHAR(100) NOT NULL,
    area_type ENUM('land','water') NOT NULL,
    source_mode ENUM('replay','camera','cctv') NOT NULL DEFAULT 'replay',
    record_origin ENUM('demo_seed','detector_run') NOT NULL DEFAULT 'demo_seed',
    first_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    observation_count INT NOT NULL DEFAULT 1,
    first_detected_count INT NOT NULL,
    latest_detected_count INT NOT NULL,
    peak_detected_count INT NOT NULL,
    max_confidence DECIMAL(5,4) NULL,
    review_status ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
    action_status ENUM('none','needs_check','resolved') NOT NULL DEFAULT 'none',
    reviewed_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    INDEX idx_incident_queue (review_status, action_status, last_seen),
    INDEX idx_incident_source (camera_name, location, area_type, source_mode, last_seen)
);

CREATE TABLE vision_observations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    incident_id INT NULL,
    camera_name VARCHAR(100) NOT NULL,
    location VARCHAR(100) NOT NULL,
    area_type ENUM('land','water') NOT NULL,
    detected_count INT NOT NULL,
    max_confidence DECIMAL(5,4) NULL,
    source_mode ENUM('replay','camera','cctv') NOT NULL DEFAULT 'replay',
    record_origin ENUM('demo_seed','detector_run') NOT NULL DEFAULT 'demo_seed',
    -- path ของภาพหลักฐาน เช่น assets/vision/street-replay-01.jpg
    -- เก็บแค่ path ไม่เก็บ binary/base64 — เพิ่มใน migrations/002_vision_observation_image.sql
    image_path VARCHAR(255) NULL,
    captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_observation_incident (incident_id, captured_at),
    CONSTRAINT fk_observation_incident
        FOREIGN KEY (incident_id) REFERENCES vision_incidents(id)
        ON DELETE SET NULL
);

-- Seed: waste_stats จาก data/waste_chonburi.csv (24 แถว, ปี 2564-2566)
INSERT INTO waste_stats (year, district, local_gov, generated_tpd, collected_tpd, utilized_tpd, proper_tpd, improper_tpd) VALUES
(2564, 'เมืองชลบุรี', 'เทศบาลเมืองชลบุรี', 172.40, 170.10, 25.30, 120.60, 24.20),
(2564, 'เมืองชลบุรี', 'เทศบาลเมืองแสนสุข', 64.20, 63.00, 8.10, 41.20, 13.70),
(2564, 'บางละมุง', 'เทศบาลเมืองพัทยา', 338.50, 330.00, 40.10, 210.40, 79.50),
(2564, 'บางละมุง', 'อบต.หนองปรือ', 88.30, 85.10, 5.20, 55.60, 24.30),
(2564, 'ศรีราชา', 'เทศบาลนครเจ้าพระยาสุรศักดิ์', 145.60, 140.20, 18.40, 95.30, 26.50),
(2564, 'ศรีราชา', 'อบต.บึง', 52.10, 49.80, 3.00, 30.20, 16.60),
(2564, 'สัตหีบ', 'เทศบาลเมืองสัตหีบ', 95.40, 92.10, 10.20, 60.40, 21.50),
(2564, 'เกาะสีชัง', 'เทศบาลตำบลเกาะสีชัง', 8.60, 8.10, NULL, 5.20, 2.90),
(2565, 'เมืองชลบุรี', 'เทศบาลเมืองชลบุรี', 178.90, 176.20, 27.10, 128.40, 20.70),
(2565, 'เมืองชลบุรี', 'เทศบาลเมืองแสนสุข', 66.80, 65.50, 9.00, 44.10, 12.40),
(2565, 'บางละมุง', 'เทศบาลเมืองพัทยา', 352.10, 344.60, 42.30, 226.80, 75.50),
(2565, 'บางละมุง', 'อบต.หนองปรือ', 91.20, 88.40, 6.10, 58.90, 23.40),
(2565, 'ศรีราชา', 'เทศบาลนครเจ้าพระยาสุรศักดิ์', 151.30, 146.70, 19.80, 99.50, 27.40),
(2565, 'ศรีราชา', 'อบต.บึง', 54.00, 51.60, 3.40, 32.80, 15.40),
(2565, 'สัตหีบ', 'เทศบาลเมืองสัตหีบ', 98.70, 95.30, 11.00, 63.20, 21.10),
(2565, 'เกาะสีชัง', 'เทศบาลตำบลเกาะสีชัง', 8.90, 8.40, 0.80, 5.60, 2.00),
(2566, 'เมืองชลบุรี', 'เทศบาลเมืองชลบุรี', 185.20, 182.80, 29.40, 135.10, 18.30),
(2566, 'เมืองชลบุรี', 'เทศบาลเมืองแสนสุข', 69.50, 68.20, 9.80, 46.90, 11.50),
(2566, 'บางละมุง', 'เทศบาลเมืองพัทยา', 368.40, 360.10, 45.20, 241.30, 73.60),
(2566, 'บางละมุง', 'อบต.หนองปรือ', 95.60, 92.30, 7.20, 62.10, 23.00),
(2566, 'ศรีราชา', 'เทศบาลนครเจ้าพระยาสุรศักดิ์', 158.10, 153.40, 21.50, 104.20, 27.70),
(2566, 'ศรีราชา', 'อบต.บึง', 56.30, 53.90, 3.80, 35.10, 15.00),
(2566, 'สัตหีบ', 'เทศบาลเมืองสัตหีบ', 102.30, 98.60, 11.90, 66.40, 20.30),
(2566, 'เกาะสีชัง', 'เทศบาลตำบลเกาะสีชัง', 9.20, NULL, 0.90, 5.90, 2.30);
