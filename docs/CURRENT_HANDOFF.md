# CURRENT_HANDOFF — Bangsaen Waste Vision

อัปเดตล่าสุด: 2026-09-19
ให้อ่านไฟล์นี้ก่อนเริ่ม session ถัดไป และตรวจ runtime/Git ซ้ำก่อนกล่าวอ้างสถานะ

---

## 0. State

**State: พร้อม Demo / Workshop release**

Phase A / B / C / D ถูกทำและ verify แล้วตาม scope ของ prototype

ไม่ใช่ production-ready สำหรับเทศบาล เพราะยังไม่มี:
- municipal CCTV integration จริง
- auth/authorization สำหรับเจ้าหน้าที่
- evaluation/calibration ด้วยข้อมูลบางแสนจริง
- production privacy/retention/backup/monitoring
- ข้อสรุป license pLitter สำหรับ production/เชิงพาณิชย์

ห้ามเปลี่ยนคำว่า prototype/replay ให้กลายเป็น claim ว่าใช้งานกับ CCTV เทศบาลจริงแล้ว

---

## 1. Workspace / Git / deploy

Workspace:
`C:\Users\DMI\Downloads\IT_palonbgyut\bangsaen-waste-participant`

XAMPP live copy:
`C:\xampp\htdocs\bangsaen`

Branch:
`main`

Remote:
`https://github.com/LethimCookMyBro/IT_pralongyut.git`

Phase D implementation commit ที่ verify แล้ว:
`9b48116 Add local live detection and Railway MySQL runtime support`

Public Railway:
`https://itpralongyut-production.up.railway.app`

Railway service:
- project: `reliable-motivation`
- web service: `IT_pralongyut`
- database: `MySQL`
- region: Southeast Asia

Railway deployment ID จะเปลี่ยนทุกครั้งที่ push (แม้เป็น docs-only push)
จึงไม่ล็อก ID ไว้ใน handoff นี้ ให้ใช้ `railway status` / `railway deployment list`
ตรวจ active deployment ล่าสุด; หลัง Phase D และการ push เอกสารล่าสุด service ถูก verify ว่า **SUCCESS / Online**

---

## 2. ปัญหา Railway ที่แก้แล้ว

อาการเดิม:
- หน้าเว็บ static เปิดได้
- `/api/stats.php`, `/api/reports.php`, `/api/vision.php` → 500 `database error`

Root cause ที่ตรวจจาก container จริง:
- environment variables ของ MySQL มีอยู่
- PHP มี PDO แต่ **ไม่มี `pdo_mysql`**
- เรียก `db()` แล้วได้ `PDOException: could not find driver`

Fix:
- เพิ่ม `afternoon/composer.json`
- require `ext-pdo_mysql`, `ext-mbstring`, `ext-pdo`
- Railpack build ใหม่และติดตั้ง extension จริง

หลัง deploy ตรวจด้วย `railway ssh php -m` แล้วมี:
- `PDO`
- `pdo_mysql`
- `mbstring`
- `mysqli`

Public stats API กลับมา 200 และหน้า index render ตัวเลขจาก DB จริงแล้ว

---

## 3. Railway DB / public verification

Remote DB ที่ตรวจ:
- `waste_stats = 24`
- `reports = 0`
- `vision_incidents = 0`
- `vision_observations = 0`
- deployed `vision_incidents.record_origin` มี enum `demo_seed|detector_run`

Public:
- `GET /api/stats.php` → 200 + JSON จริง
- `GET /api/reports.php` → 200
- `GET /api/vision.php` → 200
- `GET /api/live-detection.php` → 200 + offline state ตามจริง
- `GET /detect.html` → 200
- `/tools/seed_demo.php` → 404
- `/tests/run_tests.php` → 404

Headless Chrome public index ตรวจแล้ว:
- ไม่มี `database error`
- ปี 2566 ถูก render
- card รวมขยะ = 1,044.6 ตัน
- ตาราง top 5 และ rows ถูก render จาก API

Headless Chrome public detect ตรวจแล้ว:
- REPLAY MODE / PROTOTYPE แสดง
- detector แสดง offline เพราะ Railway ไม่มี local Python worker
- หน้าไม่พัง

Remote write smoke test ตรวจแล้วด้วย row prefix เฉพาะ:
- POST report → HTTP 201
- POST detector observation (`replay + detector_run`) → HTTP 201
- Confirm incident → HTTP 200 / `needs_check`
- Resolve incident → HTTP 200 / `resolved`
- cleanup ลบเฉพาะ smoke rows สำเร็จ: observation 1, incident 1, report 1
- หลัง cleanup Railway DB กลับสู่ข้อมูลก่อน smoke test

---

## 4. Phase D — ทำแล้ว

### Worker

ไฟล์:
`local_vision/worker.py`

ทำงาน:
- โหลด pLitter model ครั้งเดียว
- replay video ต่อเนื่อง
- default ~3 inference/sec
- เขียน `latest.jpg` + `latest.json` แบบ atomic replace
- POST observation เป็นช่วง (~4 sec default) เมื่อมี detection
- payload ใช้:
  - `source_mode = replay`
  - `record_origin = detector_run`
- จำกัด evidence ล่าสุดต่อ source (default 3)
- network POST fail ไม่ทำให้ inference loop ตาย

Worker ไม่ถูก deploy ไป Railway

### Web/API

ไฟล์ใหม่:
- `afternoon/lib/live_detection.php`
- `afternoon/api/live-detection.php`
- `afternoon/detect.html`
- `afternoon/js/detect.js`

พฤติกรรม:
- endpoint อ่าน runtime โดยไม่ต้อง DB
- state: live / stale / offline / invalid
- frontend poll 800 ms
- Railway ไม่มี runtime → offline อย่างสุภาพ
- webcam ขอ permission หลัง user click เท่านั้น
- webcam เป็น preview ไม่ใช่ input ของ detector

---

## 5. Demo videos — final

### Road
`Video/vid0012.mp4`
- pLitterStreet
- candidate screen: detection 7/7 sampled frames
- worker smoke ทำงานจริง
- POST observation จริง → 201
- Event Aggregation รวมซ้ำเป็น incident เดียว

### City / public area
`Video/vid0269.mp4`
- pLitterStreet
- candidate screen: detection 7/7 sampled frames
- worker smoke ทำงานจริง
- POST observation จริง → 201
- Event Aggregation รวมซ้ำเป็น incident เดียว

### Water
Pexels G 9736659 — Trashes Flowing on a Lake
- local file: `references/test_videos/water-9736659.mp4`
- gitignored
- pLitterFloat
- worker smoke ล่าสุด: 7 → 5 → 7 detections
- POST จริง 2 ครั้ง → 201
- aggregation ทำงาน

วิดีโอจริงทั้งหมดและ pLitter weights ห้าม push ขึ้น Git โดยอัตโนมัติ

---

## 6. Local detector proof ล่าสุด

Local XAMPP + worker:
- `GET /api/live-detection.php` ขณะ worker รัน → `available=true`, `status=live`
- คืน source/model/count/confidence/inference time
- `last_post_status=201`
- `detect.html` render annotated frame จริง
- headless Chrome เห็นสถานะ Local AI กำลังทำงาน

Responsive:
- same-origin iframe กว้างประมาณ 390px
- document/body `scrollWidth == clientWidth`
- ไม่มี horizontal overflow

หลัง worker หยุด endpoint เปลี่ยนเป็น stale ตามเวลา ไม่ปลอมว่า live

---

## 7. Local tests ล่าสุด

- `C:\xampp\php\php.exe -d zend.assertions=1 afternoon\tests\run_tests.php`
  → **80 passed, 0 failed**
- `C:\xampp\php\php.exe afternoon\tests\vision_integration_test.php`
  → **15 passed, 0 failed**
- `C:\xampp\php\php.exe afternoon\tests\list_api_test.php`
  → **29 passed, 0 failed**
- morning Python tests → **5 OK**
- `morning/analyze.py` → OK; แสนสุข 2566 = **67.5%**
- `node --check` ทุก JS → PASS
- PHP `-l` ทุก PHP file → PASS
- `python -m py_compile local_vision/worker.py` → PASS

Phase D เพิ่ม 4 unit tests:
- runtime absent → offline
- fresh snapshot → live
- old snapshot → stale
- malformed JSON → invalid/fail closed

---

## 8. Local DB หลัง detector test

ข้อมูล local ใช้สำหรับ demo/testing และไม่เหมือน Railway DB

ตัวอย่างหลัง Phase D test:
- total incidents = 22
- top road detector incident มี `record_origin=detector_run`
- road observations ถูก aggregate หลายครั้งเข้าหนึ่ง incident

อย่าเอาจำนวน local demo rows ไปพูดเป็นเหตุจริงในบางแสน

---

## 9. Files สำคัญ

| เรื่อง | Path |
|---|---|
| Spec | `SPEC_TEMPLATE.md` |
| BMC | `BMC.md` |
| State/plan | `docs/BANGSAEN_WASTE_VISION_PLAN.md` |
| Local worker | `local_vision/worker.py` |
| Worker guide | `local_vision/README.md` |
| Detect page | `afternoon/detect.html` |
| Detect JS | `afternoon/js/detect.js` |
| Live endpoint | `afternoon/api/live-detection.php` |
| Runtime validator | `afternoon/lib/live_detection.php` |
| Incident ingest | `afternoon/api/vision.php` |
| Human review | `afternoon/api/vision-review.php` |
| Aggregation config | `afternoon/lib/vision_config.php` |
| DB config | `afternoon/lib/db.php` |
| Railway init | `afternoon/sql/railway_init.sql` |
| Railway PHP runtime declaration | `afternoon/composer.json` |
| Deploy guide | `RAILWAY_DEPLOY.md` |
| Video selection | `references/test_videos/SELECTION.md` |

CV environment:
`C:\tmp\cv_venv\Scripts\python.exe`

Weights:
- `references\pLitter\weights\pLitterStreet_YOLOv5l.pt`
- `references\pLitter\weights\pLitterFloat_800x752_to_640x640.pt`

---

## 10. วิธี Demo local

จาก project root:

```bat
C:\tmp\cv_venv\Scripts\python.exe local_vision\worker.py --source road
```

แล้วเปิด:

`http://localhost/bangsaen/detect.html`

เปลี่ยน source:
- `--source road`
- `--source city`
- `--source water`

ดู Incident Queue:
`http://localhost/bangsaen/vision.html`

---

## 11. ข้อจำกัดที่ต้องพูดตรง ๆ

- ยังไม่เชื่อม CCTV เทศบาลจริง
- วิดีโอเป็น reference/replay
- ไม่มี accuracy ของบางแสนจริง
- aggregation/confidence ยัง PROTOTYPE / UNCALIBRATED
- Public Railway detector offline โดย design เพราะ inference อยู่ local
- Public demo ยังไม่มี login/authorization สำหรับ officer workflow
- pLitter license ต้องตรวจให้ชัดก่อน production/commercial use
- ยังไม่มี production privacy/retention/backup/monitoring

สิ่งเหล่านี้ไม่ขัดกับ Definition of Done ของ Workshop แต่เป็นงานของ municipal pilot / production hardening

---

## 12. ถ้าจะทำต่อหลัง Workshop

ลำดับที่เหมาะ:
1. Pilot design กับกล้องจริง 1–3 จุดหลังได้รับสิทธิ์
2. Auth/RBAC สำหรับเจ้าหน้าที่
3. เก็บ human-reviewed ground truth
4. วัด precision / false positive / review time
5. Calibrate aggregation + threshold
6. Privacy/retention + production monitoring/backup
7. ตัดสิน deployment architecture ของ local inference สำหรับสถานที่จริง

อย่าเพิ่ม feature ใหม่ก่อนมี requirement/pilot evidence
