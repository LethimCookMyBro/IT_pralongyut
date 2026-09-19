# Phase D — Local live/replay detection

State before execution: Phase A/B/C verified; Video 1/2 approved; Railway web exists but DB endpoints are failing because the deployed PHP runtime lacks pdo_mysql.

## Done criteria

1. Railway PHP has pdo_mysql and DB endpoints return real JSON.
2. Railway DB is initialized non-destructively with railway_init.sql.
3. Local detector worker loads the selected pLitter model once and processes video continuously.
4. Worker atomically publishes runtime/vision/latest.jpg + latest.json.
5. GET api/live-detection.php reports live/stale/offline without requiring DB.
6. detect.html polls the endpoint and clearly labels replay/prototype state.
7. Worker posts bounded observations into existing api/vision.php, using record_origin=detector_run.
8. Evidence images are bounded on disk.
9. Road + city videos pass real-model smoke tests; water source is wired when the locked clip is available locally.
10. Webcam permission is requested only after an explicit user click.
11. Existing PHP/Python/JS tests plus new live-detection tests pass.
12. Browser verify localhost and Railway, then commit/push/deploy and re-verify.

## Execution result — 2026-09-19

**COMPLETE for Workshop Demo scope.**

- Railway root cause verified: deployed PHP had PDO but no `pdo_mysql`.
- Added `afternoon/composer.json`; Railpack build installed `pdo_mysql` + `mbstring`.
- Public Stats / Reports / Vision APIs now return HTTP 200.
- Railway DB verified with 24 `waste_stats` rows and required `record_origin` schema.
- Implemented local worker + live endpoint + Detect page.
- Road / city / water all ran real pLitter inference; each source successfully POSTed detector observations.
- Event Aggregation merged repeated detector observations instead of creating a task per frame.
- Evidence files are pruned to a bounded count.
- Local Detect page rendered the annotated frame and live metadata.
- Railway Detect page rendered the intentional offline state without breaking.
- Webcam permission remains click-only.
- Tests: PHP 80/0, Vision 15/0, List/API 29/0, Python 5 OK, all JS/PHP syntax checks passed.
- Phase D implementation committed/pushed as `9b48116`.
- Railway service latest deployment was verified SUCCESS/Online after push; deployment IDs are intentionally not pinned here because every push creates a new one.
- Remote write smoke also passed: report 201, detector observation 201, confirm 200, resolve 200; exact smoke rows were then removed.

Remaining items are municipal-pilot/production hardening, not Phase D blockers.
