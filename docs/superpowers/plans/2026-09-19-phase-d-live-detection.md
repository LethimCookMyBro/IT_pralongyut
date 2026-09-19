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
