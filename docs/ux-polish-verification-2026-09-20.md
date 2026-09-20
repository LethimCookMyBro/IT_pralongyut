# Final UX polish and flow verification — 2026-09-20

## Scope

Presentation and client interaction, plus a verified timestamp timezone fix. No database migration, no citizen/AI merge,
no new framework, no commit or push. Existing photo validation stays unchanged.

## UX

- Report exposes GPS and manual location immediately; suggested places are secondary.
- Editing location cancels stale GPS callbacks and clears old coordinates/preset state.
- Shared SVG eye/leaf brand mark and favicon; consistent radii and compact filter chevrons.
- Native video controls, selected-source indicator and actual sidecar duration on Detect.
- Helpful queue empty states with refresh/detect or filter-reset actions.
- CSS-only short transitions; reduced-motion preference disables motion.
- Versioned CSS/JS URLs prevent old cached code hiding the new UI.
- Railway SQL TIMESTAMP values were UTC without an offset, so browser parsing showed
  fresh events as seven hours old. Each DB connection now uses `+07:00`; the shared
  date parser explicitly interprets naive SQL time as Bangkok and preserves supplied
  offsets. Existing timestamps are converted by MySQL, not rewritten.

## What actually connects

| Entry | Storage/API | Visible next | Lifecycle/history |
|---|---|---|---|
| Citizen form | POST `api/reports.php` → `reports` | Reports and Activity | Created event only; no officer update/resolve workflow exists |
| Detect video viewer | GET sidecars + `api/live-detection.php` | Annotated video/stats | Read-only; playing a video does not create an incident |
| Local AI worker | POST configured `--api-url` (default local `api/vision.php`) → raw observation → aggregation | Vision review queue and incident detail | Confirm → action queue → resolve → history; reject → history |

Railway E2E used unique `E2E-20260920-1230-*` records. Citizen POST returned 201;
AI observations aggregated into one incident; confirm, resolve and reject returned 200;
invalid confirm-after-resolve and resolve-after-reject returned 409. Citizen and AI
records remained separate in both lists. Test records are removed by exact IDs/labels,
not by broad demo cleanup.

Browser E2E additionally submitted `E2E-20260920-UX-CITIZEN-BROWSER` through the
Railway form and verified its Reports row and single Activity creation event. Incident
20 (`E2E-20260920-UX-BROWSER`) was opened from the queue, confirmed through its modal,
found in the action tab, resolved through its modal, and verified in Activity with
detect/review/resolve entries. These are disposable smoke records, not municipal events.

Activity is a derived view: `reports.created_at` and incident `first_seen`,
`reviewed_at`, `resolved_at`. It is not an immutable event log. A detection entry may
display the incident's current state; old status transitions are not reconstructed.
The worker saves evidence locally and sends a relative path, not image bytes; remote
Railway worker ingestion requires an explicit endpoint and evidence storage contract.

## Demo setup

Existing `tools/seed_demo.php` ran once against the verified empty Railway dataset:
19 reports, 30 observations, 15 incidents, all `demo_seed`. Queue: 9 pending,
2 confirmed/needs_check, 2 confirmed/resolved, 2 rejected (history tab combines 4).
Activity: 42 entries from real API creation/review/resolve timestamps, no backdating.

The seeder is not idempotent: inspect before rerunning. `tools/clear_demo.php`
defaults to dry-run and deletes only `demo_seed`; its destructive mode was not run.

## Minimal next integration — proposal only

Keep reports and AI incidents in their current tables. Add a citizen-work tab or
section to the officer entry point, reusing reports queries rather than copying
reports into AI incidents. Add a narrowly validated report-status endpoint with
explicit allowed transitions and officer authorization. Record lifecycle timestamps
(or an audit event table if full transition history is required) before showing
citizen completion events in Activity. Do not auto-link by location. This is separate
backend work requiring its own implementation/verification pass.

## Verification and limits

- Python logic 5; PHP unit 128; vision integration 15; list API 39; photo HTTP/security 19.
- JS report interaction 11; detect race/fallback 4; queue empty state 2; timestamp parsing 7;
  DB timezone read-only 3: total 233 unique checks. The 7 timestamp checks also pass
  under both UTC and Asia/Bangkok client timezones.
- All 10 page scripts and 20 changed/new PHP files pass syntax checks;
  `analyze.py` and diff whitespace checks pass.
- Local Road/City/Water: browser `readyState=4`, `paused=false`, advancing playback time.
- Desktop/mobile browser checks cover Report, Reports, Detect, Vision and Activity.
- Railway intentionally uses sample images: local-only replay videos are not published.
- Real phone camera and live GPS success are not claimed. Photo HTTP and simulated UI
  checks pass; browser file selection depends on the extension's file-access permission.
- Railway photo storage is ephemeral without an app volume/object store.

## Deployment

Final Railway deployment: `8b7521d2-b7fa-4ec2-a665-5e9e0d5873fa` (SUCCESS).
The CLI uploaded an isolated 50-file production package, excluding local video,
weights, user uploads, secrets, training files and tests. API GET checks: 5/5 HTTP 200.
New SVG/CSS assets: 3/3 HTTP 200 with correct MIME and matching workspace hashes.
Existing smoke timestamp epochs matched exactly before/after the timezone fix.
Browser verified corrected Bangkok history times and relative queue times.
Final cleanup used a transaction and exact ID/location guards: 2 smoke reports,
4 observations and 3 incidents removed. Remaining data: 19 reports / 30 observations /
15 incidents, all `demo_seed`; no E2E records remain. Queue 9 review / 2 action /
4 history (2 resolved, 2 rejected); Activity 42. Browser pagination verified 11–19 of 19.

## Git status snapshot

Branch `main`, HEAD `d37f872`; no staging, commit or push. Includes preserved changes
from the preceding work, not only this polish pass. `.claude` changes are left alone.
`reports_buggy.php` has no content diff despite its modified status.

```text
 D .claude/.headroom_wrap_marker.json
 D .claude/.headroom_wrap_owners.json
 M .claude/settings.local.json
 M .gitignore
 M README.md
 M SPEC_TEMPLATE.md
 M afternoon/activity.html
 M afternoon/api/activity.php
 M afternoon/api/live-detection.php
 M afternoon/api/reports-similar.php
 M afternoon/api/reports.php
 M afternoon/api/reports_buggy.php
 M afternoon/api/stats.php
 M afternoon/api/vision-incident.php
 M afternoon/api/vision-observations.php
 M afternoon/api/vision-review.php
 M afternoon/api/vision.php
 M afternoon/css/style.css
 M afternoon/detect.html
 M afternoon/incident.html
 M afternoon/index.html
 M afternoon/js/activity.js
 M afternoon/js/detect.js
 M afternoon/js/incident.js
 M afternoon/js/report.js
 M afternoon/js/reports.js
 M afternoon/js/ui.js
 M afternoon/js/vision.js
 M afternoon/lib/db.php
 M afternoon/lib/validate.php
 M afternoon/lib/vision_evidence.php
 M afternoon/report.html
 M afternoon/reports.html
 M afternoon/sql/railway_init.sql
 M afternoon/sql/schema.sql
 M afternoon/tests/list_api_test.php
 M afternoon/tests/run_tests.php
 M afternoon/tests/vision_integration_test.php
 M afternoon/tools/clear_demo.php
 M afternoon/tools/seed_demo.php
 M afternoon/vision.html
?? afternoon/assets/app-icon.svg
?? afternoon/css/detect-polish.css
?? afternoon/css/report-polish.css
?? afternoon/lib/report_photo.php
?? afternoon/sql/migrations/005_reports_photo_optional_fields.sql
?? afternoon/tests/db_timezone_test.php
?? afternoon/tests/detect_source_test.js
?? afternoon/tests/report_photo_test.php
?? afternoon/tests/report_photo_ui_test.js
?? afternoon/tests/ui_time_test.js
?? afternoon/tests/vision_empty_test.js
?? afternoon/uploads/reports/.gitkeep
?? afternoon/uploads/reports/.htaccess
?? docs/ux-polish-verification-2026-09-20.md
?? local_vision/render_demo_videos.py
```
