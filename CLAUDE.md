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
raw model observations (one row per detection event, never shown as a review queue itself)

vision_incidents:
aggregated incidents for human review. Columns include review_status
(pending → confirmed | rejected) and action_status (none → needs_check → resolved).
reject is terminal; confirm is what unlocks needs_check → resolve.

reports also has latitude / longitude (both NULL-able) and location_source
(preset | gps | manual, default preset). Added by
afternoon/sql/migrations/001_reports_location.sql (ALTER TABLE, keeps old
rows; old rows get location_source = preset and NULL coordinates).
schema.sql already contains the same columns for a fresh install.
Migrations are additive only — never DROP or recreate a live table.

## Important files
- afternoon/api/vision.php — GET incident queue + summary, POST ingest raw observation
- afternoon/api/vision-review.php — POST confirm/reject/resolve, always by incident id
- afternoon/api/vision-incident.php — GET one incident for the detail page
- afternoon/api/vision-observations.php — GET raw observations for one incident (drill-down)
- afternoon/api/reports-similar.php — GET possible duplicate open reports (warning only,
  never blocks submitting; the GPS radius in lib/report_config.php is PROTOTYPE /
  UNCALIBRATED, not a validated threshold)
- afternoon/lib/vision_config.php — INCIDENT_AGGREGATION_WINDOW_MINUTES (currently 10),
  the only knob controlling how raw observations get grouped into one incident
- afternoon/lib/pagination.php — shared page/per_page parsing + pagination payload
- afternoon/js/vision.js — incident queue (list only)
- afternoon/js/incident.js + afternoon/incident.html — incident detail
- afternoon/js/vision-common.js — status/trend/format helpers shared by both vision pages
- afternoon/tools/seed_demo.php — additive demo data through the real APIs
  (INSERT-only; no DELETE/TRUNCATE/DROP). Data is fictional.

Pages:
- index.html + js/app.js + api/stats.php — waste_stats dashboard, has a year selector
  (GET api/stats.php?year=…)
- report.html + js/report.js + api/reports.php — submit form only (preset area /
  current position / typed location; GPS is requested only on button press)
- reports.html + js/reports.js + api/reports.php — full report list with
  search/filters/pagination
- vision.html / incident.html — incident queue and incident detail

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
- Do not claim validated deployment
- Do not change backend architecture unless there is a real bug

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
  vision-common, incident, locations)