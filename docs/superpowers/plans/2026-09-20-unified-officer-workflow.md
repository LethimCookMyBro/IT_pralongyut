# Unified Officer Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put citizen reports and AI incidents into one officer queue while each record remains in its original table and its lifecycle appears in Activity.

**Architecture:** Add lifecycle timestamps to `reports`, a small report action endpoint, and a read-only server-side queue endpoint that normalizes `reports` and `vision_incidents` with `UNION ALL`. Keep AI ingestion and source tables unchanged. The UI renders one composite status and routes actions to the endpoint for the row's source.

**Tech Stack:** PHP 8, MySQL/MariaDB, vanilla JavaScript, existing PHP/Node test scripts.

**Spec:** User product decision dated 2026-09-20 in this session.

## Global Constraints

- Keep `reports` and `vision_incidents` separate; never copy reports into incidents.
- Citizen reports enter the queue immediately; photo AI enrichment is out of scope.
- Search, filters, and pagination stay server-side.
- User-facing states are only `รอตรวจสอบ`, `รอดำเนินการ`, `เสร็จแล้ว`, `ไม่รับเรื่อง`.
- User-facing actions are only `รับเรื่อง`, `ไม่รับเรื่อง`, `ปิดงาน`.
- Preserve `demo_seed`; every seeded row is visibly marked `ตัวอย่าง`.
- Add real timestamps; never reuse `created_at` as review or resolution time.
- Do not commit or push until every local and Railway flow has been verified.
- Do not publish source video unless its license/provenance allows redistribution.

## Review Focus

- Concurrent or repeated lifecycle actions must return `409` without changing timestamps.
- Queue pagination totals must describe the combined filtered result, not one source table.
- Source filtering and text search must be enforced in SQL before `LIMIT/OFFSET`.
- Demo citizen and demo AI rows must show both their source badge and `ตัวอย่าง`.
- Rejected rows must produce a review Activity event but no fabricated resolve event.

---

### Task 1: Citizen lifecycle and Activity

**Files:**
- Create: `afternoon/sql/migrations/006_reports_lifecycle.sql`
- Create: `afternoon/api/report-review.php`
- Modify: `afternoon/sql/schema.sql`
- Modify: `afternoon/sql/railway_init.sql`
- Modify: `afternoon/lib/validate.php`
- Modify: `afternoon/lib/activity.php`
- Test: `afternoon/tests/run_tests.php`
- Test: `afternoon/tests/list_api_test.php`

**Interfaces:**
- Consumes: existing `reports.status` values and `db()` transaction pattern.
- Produces: `POST api/report-review.php` with `{id, action}` where action is `accept|reject|resolve`; report timestamps `reviewed_at` and `resolved_at`; Activity review/resolve rows from reports.

- [ ] Add failing unit assertions for `REJECTED` and report Activity SQL branches.
- [ ] Add failing integration checks for accept, reject, resolve, invalid transition, timestamps, and Activity.
- [ ] Run the focused tests and confirm failure because the endpoint/columns do not exist.
- [ ] Add the two nullable timestamp columns and `REJECTED` status support to reset/install/migration SQL.
- [ ] Implement transactional report lifecycle updates with row locking and `409` transition guards.
- [ ] Extend derived Activity SQL using stored report timestamps only.
- [ ] Re-run the focused and full PHP tests.

### Task 2: Combined queue and composite UI

**Files:**
- Create: `afternoon/api/review-queue.php`
- Modify: `afternoon/js/vision-common.js`
- Modify: `afternoon/js/vision.js`
- Modify: `afternoon/js/incident.js`
- Modify: `afternoon/vision.html`
- Modify: `afternoon/js/reports.js`
- Modify: `afternoon/js/activity.js`
- Modify: `afternoon/reports.html`
- Modify: `afternoon/css/style.css`
- Modify: `afternoon/tools/seed_demo.php`
- Test: `afternoon/tests/list_api_test.php`
- Test: `afternoon/tests/review_queue_ui_test.js`

**Interfaces:**
- Consumes: normalized report lifecycle from Task 1 and existing AI lifecycle.
- Produces: `GET api/review-queue.php?view=&source=&q=&area_type=&page=&per_page=` returning normalized `items`, global `summary`, and `pagination`; `compositeStatus(item)` used by Queue, Reports, Incident, and Activity UI.

- [ ] Add failing API tests for combined totals, citizen/AI source filter, search, pagination, and demo origin.
- [ ] Add a failing Node test for the four composite status labels and action labels.
- [ ] Run focused tests and confirm the new API/helper is missing.
- [ ] Implement one `UNION ALL` query with filters applied before bounded pagination.
- [ ] Render `[ประชาชนแจ้ง]`, `[AI ตรวจพบ]`, and `[ตัวอย่าง]` badges without exposing technical origin codes.
- [ ] Route citizen actions to `report-review.php` and AI actions to `vision-review.php`; keep AI details linked.
- [ ] Replace stacked review/action badges with one composite badge everywhere.
- [ ] Seed citizen examples into pending, in-progress, resolved, and rejected states using real lifecycle actions.
- [ ] Run focused tests, syntax checks, and the full local suite.

### Task 3: Publishable demo video and deployment verification

**Files:**
- Create only when redistribution is verified: `afternoon/assets/demo-videos/<source>.webm`
- Modify: `afternoon/js/detect.js`
- Modify: `afternoon/assets/vision/README.md`
- Modify if needed: `.gitignore`
- Test: `afternoon/tests/detect_source_test.js`

**Interfaces:**
- Consumes: locally generated annotated videos plus documented source rights.
- Produces: committed static WebM paths under `assets/demo-videos`; JPG fallback for every unpublishable/missing source.

- [ ] Record provenance and redistribution terms for Road, City, and Water from repository metadata or the authoritative source.
- [ ] For allowed sources only, render compressed WebM files and check codec, duration, dimensions, and byte size.
- [ ] Add a failing UI test expecting the publishable asset path.
- [ ] Change `VIDEO_DIR` to `assets/demo-videos` while preserving matching JPG fallback.
- [ ] Run video UI tests and JavaScript syntax checks.
- [ ] Verify public Railway returns `200`, a video content type, `readyState >= 2`, and successful playback after interaction or muted autoplay for each published source.
- [ ] Exercise citizen and AI smoke flows using a unique prefix, verify Activity, and delete only rows with that prefix.
- [ ] Review the complete diff and all fresh test output; only then request/perform the final commit and push allowed by the user.
