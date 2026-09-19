# CURRENT_HANDOFF — Bangsaen Waste Vision

อัปเดตล่าสุด: 2026-09-19
เอกสารนี้คือสถานะล่าสุดทั้งหมดสำหรับ session ถัดไป อ่านไฟล์นี้ก่อนเริ่มงาน

---

## 0. กฎที่ยังบังคับอยู่ (สำคัญที่สุด)

**ห้าม commit / ห้าม push / ห้าม deploy จนกว่าจะได้รับอนุมัติจากผู้ใช้โดยตรง**

รอบที่ผ่านมา **ไม่มี** commit, ไม่มี push, ไม่มี deploy
ทุกอย่างยังเป็น working tree changes เท่านั้น

กฎอื่นที่ยังบังคับ:
- ห้าม over-engineer / ห้ามรื้อ architecture ที่ผ่าน tests แล้ว
- ห้ามทำข้อมูลหรือภาพปลอม
- ห้ามลบข้อมูลเดิม / ห้าม reset database / ห้าม DROP/TRUNCATE live tables
- ห้ามแก้ tests เดิมเพื่อให้โค้ดผ่าน
- ห้าม hardcode secrets / ห้ามใส่ .env จริงใน Git
- ห้าม publish model weights หรือ third-party repo ขึ้น GitHub
- ห้าม deploy Python detector / torch ขึ้น Railway
- inspect source/runtime จริงก่อนทุกครั้ง อย่าเชื่อรายงานเก่าโดยไม่ rerun

---

## 1. Phase A / B / C — เสร็จแล้ว

### Phase A — Git + Railway readiness

ไฟล์ใหม่:
- `.gitignore` — secrets, `__pycache__`, `.venv`, `afternoon/runtime/`, logs,
  `references/pLitter/`, `*.pt`
  (`.vscode/settings.json` ยัง track ไว้ตั้งใจ เพราะเป็นไฟล์ workshop)
- `.dockerignore` — ตัด `.git`, `.env*`, `morning/`, `references/`, `docs/`, `data/`,
  `__pycache__`, runtime
  **ห้ามใส่เพิ่ม:** `afternoon/css`, `afternoon/js`, `afternoon/api`, `afternoon/lib`,
  `afternoon/assets`, `afternoon/sql`
- `.env.example` — MYSQL* + `APP_DEBUG=` ไม่มี secret จริง
- `RAILWAY_DEPLOY.md` — 10 ขั้นตอนภาษาไทย (GitHub → New Project → Web Service →
  Root Directory `/afternoon` → MySQL service → reference `${{MySQL.MYSQLHOST}}` ฯลฯ →
  รัน `railway_init.sql` → Generate Domain → ทดสอบ endpoints)
- `afternoon/index.php` — `readfile(__DIR__ . "/index.html")` ให้ Railpack ตรวจเจอ PHP
- `afternoon/lib/cli_only.php` — 404 เมื่อ `PHP_SAPI !== "cli"`
- `afternoon/sql/railway_init.sql` — non-destructive twin ของ schema.sql

ไฟล์ที่แก้:
- `afternoon/lib/db.php` — `db_config()` อ่าน MYSQLHOST/PORT/USER/PASSWORD/DATABASE
  fallback รายคีย์เป็นค่า XAMPP เดิม
- `afternoon/lib/response.php` — `display_errors=0` ถ้าไม่ได้ตั้ง `APP_DEBUG=1`
- `afternoon/tools/seed_demo.php` + `tests/*.php` 3 ไฟล์ — require `cli_only.php`

**Git operation ที่ทำไปแล้ว (อนุมัติแล้ว, index-only):**
```
git rm --cached references/pLitter
```
ยืนยันหลังทำ: `references/pLitter` ยังอยู่บน disk ครบ,
weights ทั้งสองไฟล์ยังอยู่, local detector ยังรัน inference จริงได้
(โหลด 2.6s, 5 detections, maxconf 0.589), `references/pLitter/` ถูก ignore แล้ว

**railway_init.sql ตรวจใหม่รอบ takeover:** พบและแก้ bug ที่ไฟล์เคย hardcode
`CREATE DATABASE/USE bangsaen_waste` ซึ่งทำให้คำสั่งที่เลือก DBNAME อื่นยังเขียนลงฐาน local เดิมได้
ตอนนี้ไฟล์ไม่ CREATE/USE ชื่อฐานอีกแล้ว และใช้ DBNAME จากคำสั่ง mysql โดยตรง
ทดสอบกับ throwaway DB `init_probe_record_origin_20260919`: สร้าง 4 ตารางถูกฐาน,
seed `waste_stats` 24 แถว, มี `record_origin` ทั้ง incidents/observations,
รันไฟล์ซ้ำ exit 0 และจำนวน seed ยัง 24 แถว

**Hardening ตรวจผ่าน HTTP จริง:**
`/tools/seed_demo.php` และ `/tests/*.php` ทั้ง 3 ไฟล์ → **404**
`/`, `/index.html`, `/api/stats.php`, `/api/vision.php`, `/api/reports.php` → **200**

### Phase B — UI scale / readability

- `afternoon/css/style.css`: type scale ใหม่ (`--text-xs` … `--text-2xl: 2rem`),
  `--container-max: 1320px`, `--container-narrow: 720px`, `--control-height: 2.75rem`,
  input/button สูงขึ้น, `th/td` padding + `--text-base`, `section` margin `--space-8`,
  `.incident-main` เป็น `minmax(0,1.9fr) minmax(300px,1fr)`
- Incident detail: รูปหลักฐานใหญ่เป็นจุดเด่น, location เป็น text ใหญ่สุด,
  status badge มุมขวาบน, technical details อยู่ใน `<details>` ล่างสุด,
  ไม่มีรูป = empty state ตามจริง (ไม่ยัดรูปปลอม)
- **Report form (Phase 5):** `report.html` เรียงใหม่เป็น
  สถานที่ → รายละเอียด (ไม่บังคับ) → `ข้อมูลประกอบ` (ประเภทขยะ + ปริมาณ)
  ใน `fieldset.fieldset-secondary` (กรอบบาง ไม่มีพื้นหลัง legend muted)
  **ไม่เปลี่ยน schema** — `amount_kg` ยัง required เหมือนเดิม
  `report.js` อ่าน field ด้วย id จึงไม่ต้องแก้ JS

### Phase C — scale to thousands + data labeling

- `api/vision.php`: `VIEW_CONDITIONS` = `review` / `action` / `history`
  รับ `?view=` (validate, ค่าผิด → 422), summary เพิ่ม `to_review` / `to_act` / `history`
  **กรองที่ฝั่ง server เท่านั้น** ห้ายกไปทำใน browser
- `vision.html` + `js/vision.js`: แท็บ `ต้องตรวจ / ต้องดำเนินการ / ประวัติ`
  (`.view-tabs` / `.view-tab` / `.view-tab-count`) default = `review`
  state อยู่ใน URL, ล้างตัวกรองแล้วยังอยู่กลุ่มงานเดิม
- **Indexes:** ไม่ได้เพิ่มใหม่ — `idx_incident_queue (review_status, action_status,
  last_seen)` และ `idx_incident_source` ครอบคลุม query ใหม่อยู่แล้ว
- **Data labeling:** `.replay-banner` ยังคงย้ำว่าไม่มี CCTV เทศบาลจริง แต่ UI ตอนนี้
  แยก `record_origin` ชัดเจน: `demo_seed` = **ข้อมูลสาธิต**, `detector_run` =
  **AI ตรวจจริงบนวิดีโออ้างอิง**; queue และ incident detail แสดงที่มาของแต่ละเหตุ

---

## 2. ผล test ล่าสุด (รันจริง 2026-09-19 หลังแก้ครบ)

| Test | ผล |
|---|---|
| `C:\xampp\php\php.exe afternoon\tests\run_tests.php` | **76 passed, 0 failed** |
| `C:\xampp\php\php.exe afternoon\tests\vision_integration_test.php` | **15 passed, 0 failed** |
| `C:\xampp\php\php.exe afternoon\tests\list_api_test.php` | **29 passed, 0 failed** |
| `python morning\test_waste_logic.py` | 5 tests **OK** |
| `python morning\analyze.py` | OK (2566: 67.5%) |
| `node --check` ทั้ง 8 ไฟล์ใน `afternoon\js\` | ok ทุกไฟล์ |

run_tests.php เพิ่ม 3 tests ใหม่สำหรับ DB env config (73 → 76)
ไม่มี test เดิมถูกแก้

**ข้อมูลจริงหลังรัน tests — ไม่เปลี่ยน:**
`vision_incidents=19`, `vision_observations=35`, `reports=21`, `waste_stats=24`

**Browser verify (XAMPP จริง):**
- แท็บนับได้ 10 / 2 / 7 รวม 19 ไม่ซ้อนกลุ่ม, `?view=action` และ `?view=history`
  แสดงเฉพาะกลุ่มตัวเองและเปลี่ยนหัวข้อถูกต้อง
- 390px: index, report, reports, vision, incident → `scrollWidth == clientWidth`
  ทุกหน้า (ไม่มี horizontal overflow) ตารางกลายเป็น card, แท็บ wrap 2 บรรทัด
- หมายเหตุวิธีตรวจ: Chrome บน Windows ย่อหน้าต่างต่ำกว่า ~500px ไม่ได้
  จึงตรวจด้วย same-origin iframe กว้าง 390px (inner document ได้ viewport 390px จริง
  media query ทำงานจริง ไม่ใช่จำลอง)

Workspace sync ไป `C:\xampp\htdocs\bangsaen` แล้ว (เป็นสำเนาคนละชุด ต้อง `cp -r` ทุกครั้ง)

---

## 3. Phase D — ยังไม่เริ่ม

`detect.html` + Python detector worker **ยังไม่ได้เริ่มเลย**
ไม่มีไฟล์ `detect.html`, ไม่มี worker, ไม่มี `api/live-detection.php`,
ไม่มี `afternoon/runtime/`

แผนที่ตกลงไว้ (ยังไม่ทำ):
- Python worker: start ครั้งเดียว → โหลด model ครั้งเดียว → อ่าน frame ต่อเนื่อง →
  infer ~2–5 ครั้ง/วินาที → เขียน `afternoon/runtime/vision/latest.jpg` +
  `latest.json` แบบ atomic replace
- `GET /api/live-detection.php` อ่าน `latest.json` → frontend poll ทุก 500–1000 ms
  (ไม่ใช้ WebSocket)
- POST observation เข้า pipeline เดิมเป็นช่วง (~ทุก 3–5 วินาทีเมื่อยังพบขยะ)
  ไม่ใช่ทุก frame → ให้ Event Aggregation เดิมรวมเป็น 1 incident
- เก็บภาพหลักฐานเฉพาะ frame ที่ส่ง observation (หรือมากสุด first / peak / latest)
  ห้ามให้ disk โตตามจำนวน frame และต้องผ่าน `lib/vision_evidence.php`
- Webcam = optional / bonus ขอ permission หลังผู้ใช้กดเท่านั้น
- บน Railway ที่ไม่มี worker ต้องขึ้นข้อความสุภาพว่า
  "ตัวตรวจจับ Local AI ไม่ได้ทำงานบน deployment นี้" และหน้าอื่นต้องยังใช้ได้

---

## 4. Video source decisions (ล่าสุด)

### VIDEO 3 — LOCKED ห้ามเปลี่ยน
- Pexels **G 9736659** — Trashes Flowing on a Lake
  https://www.pexels.com/video/trashes-flowing-on-a-lake-9736659/
- โมเดล: **pLitterFloat**
- smoke test ที่ verify แล้ว: **6 → 9 → 11 detections**
- ผู้ใช้ยอมรับแล้ว เปลี่ยนได้เฉพาะกรณีมี technical blocker จริง

### VIDEO 1 (ขยะในเมือง) + VIDEO 2 (ขยะริมถนน) — ยังไม่เลือก

**CORRECTION ล่าสุดจากผู้ใช้ — ทับคำสั่งเดิม:**
**ห้ามหาจาก Pexels / Pixabay / stock footage สำหรับ Video 1 และ 2**
ให้ใช้ Google Drive folder นี้เป็นแหล่งหลัก:

https://drive.google.com/drive/folders/1QlFQWKCQrnaox6GdKqPPQUZP6kbJOu7P

- Video 1: ขยะในเมือง → เลือกคลิปแนว fixed/wide CCTV-style จาก Drive → **pLitterStreet**
- Video 2: ขยะริมถนน → เลือกคลิปแนว fixed/wide roadside CCTV-style จาก Drive → **pLitterStreet**

เกณฑ์เลือก (ห้ามเลือกจากหน้าตาคลิปอย่างเดียว) — สำหรับ candidate แต่ละตัวต้อง:
- sample ต้น / กลาง / ท้าย และช่วงที่มีขยะ
- รัน **real pLitterStreet inference** จริง
- บันทึก detection counts, detected classes, max confidence
- save annotated sample แล้ว **ดู bounding box ด้วยตา**

เลือกคลิปที่: model ตรวจได้จริง / boxes สมเหตุผล / กล้องนิ่งพอ / ฉากตรงหมวด /
ไม่ใช่ close-up cinematic / ไม่มี privacy problem

**ตัดออกจาก demo หลักแล้ว:** Khaosod CCTV, Hull CCTV
เหตุผล: มี identifiable persons / suspected offenders และเราจะเก็บ evidence image
แสดงซ้ำใน Incident Detail จึงไม่ควรนำ footage เหล่านั้นมาใช้

**ก่อน wire เข้า `detect.html` ต้องรายงาน top candidate ของ Video 1 และ 2 ให้ผู้ใช้ดูก่อน**

---

## 5. `record_origin` — ทำเสร็จและ verify แล้ว

`record_origin` ถูกแยกจาก `source_mode` แล้วตาม requirement ล่าสุด:

- `source_mode` = ภาพมาจากทางไหน: `replay` | `camera` | `cctv`
- `record_origin` = แถวข้อมูลนี้เกิดขึ้นอย่างไร:
  - `demo_seed` — seed/demo workflow data
  - `detector_run` — ผลจาก real model inference

ตัวอย่างสำคัญ:
`source_mode = replay` + `record_origin = detector_run`
= AI รันจริงบนวิดีโออ้างอิง แต่ยังไม่ใช่ live CCTV เทศบาล

ทำแล้ว:
- `afternoon/sql/migrations/003_vision_record_origin.sql` — additive migration เท่านั้น
- `schema.sql` + `railway_init.sql` มี column inline สำหรับ fresh install
- live DB migrate แล้ว: 19 incidents + 35 observations เดิม backfill เป็น `demo_seed`
- `api/vision.php` validate/store `record_origin` และ aggregation ไม่รวมข้าม origin
- `vision_observations` drill-down คืน `record_origin`
- `seed_demo.php` ส่ง `demo_seed` ชัดเจน
- real spike connectors ส่ง `detector_run`
- UI queue + incident detail แสดง "ข้อมูลสาธิต" แยกจาก "AI ตรวจจริง"
- banner ยังบอกชัดว่าไม่มี municipal Bangsaen CCTV
- integration test เพิ่ม 2 เคส: origin persistence/isolation + invalid origin

Verification รอบ takeover:
- `run_tests.php`: 76 passed, 0 failed
- `vision_integration_test.php`: 15 passed, 0 failed
- `list_api_test.php`: 29 passed, 0 failed
- JS syntax: vision-common.js / vision.js / incident.js ผ่าน
- morning Python tests: 5 tests OK
- analyze.py: เทศบาลเมืองแสนสุข ปี 2566 = 67.5%

---

## 6. ลำดับงานที่ต้องทำต่อ

1. **Video 1 / Video 2** — เข้า Google Drive folder, เลือก candidate ~5–10 ตัวต่อหมวด
   (timebox อย่าวนหาไม่สิ้นสุด), รัน pLitterStreet จริง, save annotated samples,
   **รายงาน top candidate ให้ผู้ใช้อนุมัติก่อน** ห้าม wire เอง
2. **Phase D** — หลังอนุมัติคลิปแล้วเท่านั้น: Python detector worker →
   `api/live-detection.php` → `detect.html` → ต่อเข้า pipeline เดิม
3. **Webcam** — หลัง video detection เสถียรแล้วเท่านั้น
4. **รายงานผล verification ครบ** แล้วค่อยขออนุมัติ commit / push / deploy

`record_origin` ไม่ใช่งานค้างแล้ว — ทำและ verify เสร็จในรอบ takeover นี้

---

## 7. Blockers ที่ยังเหลือ

- **Google Drive folder ต้อง authenticate** — ยังเข้าไม่ได้ จึง enumerate หรือ
  ดาวน์โหลด candidate ของ Video 1/2 ไม่ได้
  ต้องการอย่างใดอย่างหนึ่ง: ผู้ใช้วางไฟล์คลิปไว้ใน path ที่อ่านได้ local
  หรือให้สิทธิ์เข้าถึง Drive
- Video 1/2 ยังไม่ถูกเลือก → ยังห้าม wire source เข้า Phase D
- ยังไม่เคย deploy Railway จริง — เป็นแค่ "Railway-ready" (ตั้งใจให้เป็นแบบนั้น)
- pLitter license caveat ยังไม่ได้ตรวจจบ → ห้าม publish repo/weights

---

## 8. Paths / environment สำคัญ

| อะไร | Path |
|---|---|
| Workspace | `C:\Users\DMI\Downloads\IT_palonbgyut\bangsaen-waste-participant` |
| XAMPP live copy (คนละชุด ต้อง `cp -r afternoon/. /c/xampp/htdocs/bangsaen/`) | `C:\xampp\htdocs\bangsaen` |
| PHP CLI | `C:\xampp\php\php.exe` |
| **CV virtualenv (torch 2.14.0+cpu, cv2, ultralytics)** | **`C:\tmp\cv_venv\Scripts\python.exe`** |
| pLitter repo (untracked in git, ยังอยู่บน disk ~107M) | `references\pLitter` |
| Weights (ห้าม commit) | `references\pLitter\weights\pLitterStreet_YOLOv5l.pt`, `references\pLitter\weights\pLitterFloat_800x752_to_640x640.pt` |
| Spike scripts ที่ใช้ทดสอบ inference | `references\spike\run_inference.py`, `run_inference_float.py`, `post_to_vision_api.py`, `post_to_vision_api_float.py` |
| Candidate video evaluator | `references\spike\evaluate_video_candidate.py` — sample เฟรมจริง + pLitter inference + annotated frames + summary JSON; ไม่แตะ DB |
| Evidence images ที่ commit ได้ | `afternoon\assets\vision\` (+ `README.md` บันทึกที่มา) |
| runtime output ของ Phase D (gitignored, ยังไม่มี) | `afternoon\runtime\vision\` |

**system python ไม่มี torch/cv2** ต้องใช้ `C:\tmp\cv_venv\Scripts\python.exe` เท่านั้น

DB: MariaDB 10.4.32 (XAMPP), database `bangsaen_waste`, user `root`, password ว่าง

---

## 9. สถานะ Git ปัจจุบัน (ยังไม่ commit)

branch `main`, commit ล่าสุด `e175d98 fix the UI and components`

Modified: `CLAUDE.md`, `afternoon/api/vision.php`, `afternoon/api/vision-incident.php`,
`afternoon/api/vision-observations.php`, `afternoon/css/style.css`,
`afternoon/incident.html`, `afternoon/report.html`, `afternoon/vision.html`,
`afternoon/js/incident.js`, `afternoon/js/ui.js`, `afternoon/js/vision.js`,
`afternoon/lib/db.php`, `afternoon/lib/response.php`, `afternoon/sql/schema.sql`,
`afternoon/tests/*.php` (3), `afternoon/tools/seed_demo.php`,
`references/spike/post_to_vision_api.py`, `references/spike/post_to_vision_api_float.py`

Staged deletion (index-only, อนุมัติแล้ว): `references/pLitter` (gitlink)

Untracked: `.gitignore`, `.dockerignore`, `.env.example`, `RAILWAY_DEPLOY.md`,
`afternoon/assets/`, `afternoon/index.php`, `afternoon/lib/cli_only.php`,
`afternoon/lib/vision_evidence.php`,
`afternoon/sql/migrations/002_vision_observation_image.sql`,
`afternoon/sql/railway_init.sql`, `docs/superpowers/`,
`references/spike/post_to_vision_api_float.py`, `docs/CURRENT_HANDOFF.md`

**ยังไม่ commit / ยังไม่ push / ยังไม่ deploy — ต้องขออนุมัติก่อนทุกครั้ง**
