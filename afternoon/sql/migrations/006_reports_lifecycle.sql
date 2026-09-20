-- Citizen reports join the officer lifecycle without moving into vision_incidents.
-- Run once on existing databases. New installs already include these columns.
ALTER TABLE reports
    ADD COLUMN reviewed_at TIMESTAMP NULL DEFAULT NULL AFTER created_at,
    ADD COLUMN resolved_at TIMESTAMP NULL DEFAULT NULL AFTER reviewed_at;
