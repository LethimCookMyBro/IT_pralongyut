# CLAUDE.md

## Project
Bangsaen Waste Vision

Local workspace:
C:\Users\DMI\Downloads\IT_palonbgyut\bangsaen-waste-participant

Live XAMPP copy:
C:\xampp\htdocs\bangsaen

## Current reality
This repository is no longer only a workshop stub.
The afternoon web app is implemented and runnable on XAMPP.

Stack:
- PHP
- MySQL
- HTML
- CSS
- Vanilla JS
- Python for the morning part and CV/testing

Do not migrate the stack.

## Key architecture
Waste Vision currently works as:

Raw Observations
→ Event Aggregation
→ Incident Queue
→ Human Review at Incident level
→ Action
→ Resolve

Important:
- observation != incident != task
- Human review happens at INCIDENT level
- Do not revert to observation-level review

## Database
Current relevant tables (see afternoon/sql/schema.sql):
- waste_stats — morning open-data import, read by api/stats.php
- reports — citizen-submitted reports, read/write by api/reports.php
- vision_observations
- vision_incidents

vision_observations:
raw model observations (one row per detection event, never shown as a review queue itself).
`record_origin` is independent from `source_mode`: `demo_seed` means seeded/demo workflow data;
`detector_run` means the row came from a real model inference. A detector running on a reference
video is therefore `source_mode = replay` + `record_origin = detector_run`.
image_path (VARCHAR(255) NULL) — relative path ของภาพหลักฐานจากโมเดล เช่น
assets/vision/street-replay-01.jpg เพิ่มโดย
afternoon/sql/migrations/002_vision_observation_image.sql (ALTER TABLE, แถวเดิมได้ NULL).
เก็บแค่ path ไม่เก็บ binary/base64. POST api/vision.php รับ image_path แบบ optional และ
validate ด้วย afternoon/lib/vision_evidence.php ให้เหลือเฉพาะไฟล์รูปใน afternoon/assets/vision/

vision_incidents:
aggregated incidents for human review. Columns include `record_origin`
(`demo_seed` | `detector_run`), review_status (pending → confirmed | rejected)
and action_status (none → needs_check → resolved). Aggregation must never merge
observations across different `record_origin` values. reject is terminal; confirm
is what unlocks needs_check → resolve.

reports also has latitude / longitude (both NULL-able) and location_source
(preset | gps | manual, default manual). Added by
afternoon/sql/migrations/001_reports_location.sql (ALTER TABLE, keeps old
rows; old rows get location_source = manual and NULL coordinates).
schema.sql already contains the same columns for a fresh install.

reports.record_origin (citizen | demo_seed, default citizen) marks which rows are
fictional demo data. Added by afternoon/sql/migrations/004_reports_record_origin.sql.
It is a different vocabulary from the vision tables (demo_seed | detector_run) and
unrelated to location_source. Only tools/seed_demo.php writes demo_seed; only
tools/clear_demo.php deletes those rows. Never relabel existing rows in bulk —
a row submitted through the real form is citizen even if it looks like test data.

Migrations are additive only — never DROP or recreate a live table.

Two setup scripts, and they are not interchangeable:
- afternoon/sql/schema.sql — local/workshop reset. Contains DROP TABLE.
  Never run it against a deployed database.
- afternoon/sql/railway_init.sql — safe first setup on a real database.
  CREATE TABLE IF NOT EXISTS only, no DROP/TRUNCATE/DELETE, migration columns
  inlined in the CREATE (plain MySQL 8 has no ALTER ... IF NOT EXISTS, unlike
  the MariaDB 10.4 that ships with XAMPP), and the waste_stats seed guarded by
  WHERE NOT EXISTS so re-running it is a no-op. It intentionally does not
  CREATE/USE a hardcoded database name; pass Railway's MYSQLDATABASE as DBNAME.
  Verified on a throwaway DB: both runs exited 0, seed stayed at 24 rows, and
  record_origin exists on incidents + observations.

## Important files
- afternoon/api/vision.php — GET incident queue + summary, POST ingest raw observation
- afternoon/api/vision-review.php — POST confirm/reject/resolve, always by incident id
- afternoon/api/vision-incident.php — GET one incident for the detail page
  — คืน evidence (observation ล่าสุดที่มีภาพ สูงสุด 5) ด้วย
- afternoon/api/vision-observations.php — GET raw observations for one incident (drill-down)
- afternoon/api/reports-similar.php — GET possible duplicate open reports (warning only,
  never blocks submitting; the GPS radius in lib/report_config.php is PROTOTYPE /
  UNCALIBRATED, not a validated threshold)
- afternoon/lib/vision_config.php — INCIDENT_AGGREGATION_WINDOW_MINUTES (currently 10),
  the only knob controlling how raw observations get grouped into one incident
- afternoon/sql/migrations/003_vision_record_origin.sql — additive migration that adds
  `record_origin` to incidents + observations; existing rows default to `demo_seed`
- afternoon/api/activity.php — GET derived 90-day activity timeline (days, kind,
  q, page, per_page). There is no log table: the timeline is a UNION ALL over
  reports.created_at + vision_incidents.first_seen/reviewed_at/resolved_at, so it
  only shows events the schema actually timestamps. Do not backfill fake history.
- afternoon/lib/activity.php — ACTIVITY_MAX_DAYS/DEFAULT_DAYS (90),
  ACTIVITY_KINDS (report|detect|review|resolve), day+kind validation and the
  UNION ALL builder. $days is cast to int before being embedded in SQL.
- afternoon/lib/pagination.php — shared page/per_page parsing + pagination payload
- afternoon/lib/vision_evidence.php — validate/normalize evidence image path
  (allowlist folder + extension, ไฟล์ต้องมีจริง, กัน ../ และ URL/scheme)
- afternoon/assets/vision/ — ภาพผลลัพธ์จริงจาก detector + README.md ที่บันทึกที่มาของแต่ละไฟล์
  sample-road/city/water.jpg เป็นเฟรมจริงจาก worker (--no-post) ที่ detect.html ใช้เป็น
  "ตัวอย่างผลลัพธ์" ตอนไม่มี worker — ตัวเลขใน js/detect.js SAMPLES ต้องตรงกับตาราง
  ใน README ของโฟลเดอร์นั้น ห้ามแก้ด้วยมือ และห้ามผูกไฟล์เหล่านี้กับ observation ใด
- afternoon/js/vision.js — incident queue (list only)
- afternoon/js/incident.js + afternoon/incident.html — incident detail
- afternoon/js/vision-common.js — status/trend/format helpers shared by both vision pages
  (activity.html ก็โหลดไฟล์นี้เพื่อใช้ statusBadge/originTag ชุดเดียวกัน)
- afternoon/js/activity.js + afternoon/activity.html — 90-day activity log page
- afternoon/tools/seed_demo.php — additive demo data through the real APIs
  (INSERT-only; no DELETE/TRUNCATE/DROP). Data is fictional and tagged
  record_origin = demo_seed so the UI can label it and clear_demo.php can remove it.
- afternoon/tools/clear_demo.php — deletes ONLY record_origin = 'demo_seed' rows
  (reports, then vision_observations, then vision_incidents — observations first
  because the FK is ON DELETE SET NULL). Dry-run by default, --yes to delete.
  This is not a reset script: it must never DROP/TRUNCATE or touch citizen /
  detector_run rows.
- afternoon/lib/cli_only.php — required first by both tools/*.php and all three
  tests/*.php; returns 404 when PHP_SAPI !== "cli" so a public URL cannot seed or
  mutate the DB. Do not remove it to "make the script reachable from the browser".
- afternoon/lib/db.php — db_config() reads MYSQLHOST/MYSQLPORT/MYSQLUSER/
  MYSQLPASSWORD/MYSQLDATABASE and falls back per-key to the XAMPP defaults
  (127.0.0.1 / 3306 / root / "" / bangsaen_waste), so the same code runs locally
  and on Railway. Never hardcode a deployment password here.
- afternoon/lib/response.php — sets display_errors=0 unless APP_DEBUG=1.
- afternoon/lib/live_detection.php — validates/reads the local worker runtime contract
  from `afternoon/runtime/vision/latest.json`; returns live/stale/offline without DB.
- afternoon/api/live-detection.php — read-only GET endpoint for the detector status.
- afternoon/detect.html + afternoon/js/detect.js — Local AI status/annotated-frame page.
  It polls the read-only endpoint and asks for webcam permission only after a user click.
  Source picker = three <button role="radio"> cards (not a dropdown). The frame always
  carries a badge saying which mode it is: เฟรมสด / เฟรมล่าสุด (ค้าง) / ตัวอย่างผลลัพธ์.
  With no worker it renders SAMPLES (real past frames) instead of blanking the page.
  Clicking a card sets userPicked, which stops the poll from yanking the view back to
  the worker's own source. Never mix one source's image with another source's numbers.
- local_vision/worker.py — local-only continuous pLitter replay worker. Loads one model
  once, atomically writes latest.jpg/latest.json, POSTs bounded observations to
  api/vision.php as `replay + detector_run`, and keeps only a bounded number of
  evidence images. Do not deploy torch/model weights to Railway.
- afternoon/composer.json — declares PHP runtime extensions including ext-pdo_mysql
  and ext-mbstring so Railway/Railpack installs the drivers used by the APIs.
- afternoon/index.php — minimal entry point (readfile of index.html) so Railpack
  detects a PHP app. Do not duplicate UI in it.

Pages:
- index.html + js/app.js + api/stats.php — waste_stats dashboard, has a year selector
  (GET api/stats.php?year=…)
- report.html + js/report.js + api/reports.php — submit form only (preset area /
  current position / typed location; GPS is requested only on button press).
  Field order is deliberate: location first, then the optional detail textarea,
  then waste_type + amount_kg inside .fieldset-secondary ("ข้อมูลประกอบ").
  amount_kg is still required — the workshop and its tests depend on it.
- reports.html + js/reports.js + api/reports.php — full report list with
  search/filters/pagination, including a record_origin filter. Demo rows carry an
  inline "ตัวอย่าง" tag next to the location so a judge cannot read them as real
  municipal data.
- detect.html + js/detect.js + api/live-detection.php — local detector viewer/status
- vision.html / incident.html — incident queue and incident detail
- activity.html + js/activity.js + api/activity.php — ประวัติย้อนหลัง 90 วัน
  (filters: q / days 7|30|90 / kind). Derived, not a log table.

vision.html splits work into three groups instead of one mixed list, so the
officer opens the page on what is still theirs to do:
  ต้องตรวจ (review) / ต้องดำเนินการ (action) / ประวัติ (history).
The split is server-side — api/vision.php accepts view=review|action|history
(VIEW_CONDITIONS) and the summary carries to_review / to_act / history counts.
Default view is review. An unknown view is a 422 from the API and is reset to
the default client-side. Do not move this filtering into the browser; the
queue must stay paginated for thousands of rows.
The existing indexes already serve these queries (idx_incident_queue on
review_status/action_status/last_seen, idx_incident_source) — do not add more.

List API contract (api/reports.php GET, api/vision.php GET,
api/vision-observations.php GET): accepts page and per_page (defaults 1 and 10,
validated and capped server-side), returns a pagination object with
page, per_page, total and total_pages. Never query unbounded.
Human review actions live on the incident detail page and go through a
<dialog> confirmation (js/ui.js confirmAction) — not raw alert()/confirm().
api/reports_buggy.php and lib/validate_buggy.php are intentionally-broken
training copies for a workshop exercise, not production code — do not
"fix" them as if they were real bugs, and do not confuse them with
api/reports.php / lib/validate.php.

## Notes
- System is still prototype / replay mode
- Do not claim real CCTV integration
- The public Railway demo deployment has been technically verified for the PHP/MySQL
  web/API path, but that is not a validated municipal/production deployment
- Do not change backend architecture unless there is a real bug
- Pre-Phase-D seeded rows are labeled `record_origin = demo_seed`. Phase D real
  pLitter replay runs use `record_origin = detector_run`. `source_mode` describes
  where imagery came from (`replay|camera|cctv`), while `record_origin` describes
  how the row was created (`demo_seed|detector_run`). Keep those concepts separate:
  a real detector run on a reference video is `replay + detector_run`.
  vision.html/incident.html surface this distinction, while still stating that no
  municipal Bangsaen CCTV is connected. A judge must never read seed data as a real incident.
- Railway: deploy the PHP+MySQL web app only (see RAILWAY_DEPLOY.md, root
  directory /afternoon). The Python/pLitter detector stays a local worker —
  do not put torch on Railway.

## Developer guidance
When editing frontend:
- Light theme only for now — no automatic prefers-color-scheme dark mode
  (removed until a dark variant gets its own design pass)
- One primary accent color (teal, see --color-primary in css/style.css);
  status colors (amber/green/red) are for badges and small inline text only,
  never as a card/row background or a second "primary-looking" button
- Reuse the existing tokens/components (--space-*, --text-*, .card, .btn*,
  .status-badge, .replay-banner) instead of introducing new one-off styles
- keep UI clean and professional; prefer consistency over flashy visuals
- do not introduce frameworks/build tooling — plain HTML/CSS/vanilla JS only
- Thai first; English technical terms only as a secondary note (.term-en)
- list tables use class="as-cards" + td[data-label] so rows become cards below
  768px — do not solve narrow screens with horizontal scroll
- browser geolocation only works on a secure context (https or localhost);
  a real deployment needs https and the user granting permission

When editing backend:
- preserve current API contract
- preserve incident-based review flow
- run tests before claiming completion

## Verification
Useful commands:
- python morning\test_waste_logic.py
- python morning\analyze.py
- C:\xampp\php\php.exe afternoon\tests\run_tests.php
- C:\xampp\php\php.exe afternoon\tests\vision_integration_test.php
- C:\xampp\php\php.exe afternoon\tests\list_api_test.php
  (hits the running Apache; inserts only prefixed rows and deletes exactly those)
- node --check on every file in afternoon\js\ (ui, app, report, reports, vision,
  vision-common, incident, locations, detect)
- C:\tmp\cv_venv\Scripts\python.exe local_vision\worker.py --source road --max-inferences 3 --no-post --no-loop
- GET http://localhost/bangsaen/api/live-detection.php while worker is running
- GET http://localhost/bangsaen/api/activity.php?days=90
- Railway: GET /api/stats.php, /api/reports.php, /api/vision.php,
  /api/activity.php and /api/live-detection.php;
  /tools/*.php and /tests/*.php must stay 404