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

## Important files
- afternoon/api/vision.php — GET incident queue + summary, POST ingest raw observation
- afternoon/api/vision-review.php — POST confirm/reject/resolve, always by incident id
- afternoon/api/vision-observations.php — GET raw observations for one incident (drill-down)
- afternoon/lib/vision_config.php — INCIDENT_AGGREGATION_WINDOW_MINUTES (currently 10),
  the only knob controlling how raw observations get grouped into one incident
- afternoon/js/vision.js
- afternoon/vision.html

Other pages follow the same pattern with their own api/js/html trio:
index.html + js/app.js + api/stats.php (waste_stats dashboard),
report.html + js/report.js + api/reports.php (citizen reports).
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
- node --check afternoon\js\ui.js
- node --check afternoon\js\app.js
- node --check afternoon\js\report.js
- node --check afternoon\js\vision.js