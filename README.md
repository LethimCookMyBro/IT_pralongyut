# Bangsaen Waste Vision

ระบบต้นแบบ (prototype) สำหรับติดตามปัญหาขยะในพื้นที่บางแสน ประกอบด้วย
เว็บแอป PHP/MySQL ที่ใช้งานได้จริง + ตัวตรวจจับขยะด้วย AI ที่รันบนเครื่อง local

> **สถานะ:** prototype / replay mode
> **ไม่ได้** เชื่อมต่อกล้อง CCTV ของเทศบาลบางแสน และ **ไม่ใช่** ระบบรับเรื่องจริงของหน่วยงาน
> ตัว AI รันบน**เครื่อง local เท่านั้น** ไม่ได้รันบน Railway

---

## Stack diagram

อ่านจากบนลงล่าง = เส้นทางของข้อมูลหนึ่งชิ้น ตั้งแต่ต้นทางจนถึงคนที่ตัดสินใจ

```
             ┌─────────────── ต้นทางของข้อมูล ───────────────┐
             │                                              │
 ┌───────────┴────────────┐              ┌──────────────────┴──────────────────┐
 │  คน (ประชาชน)           │              │  AI (Local Vision Worker)            │
 │  กรอกฟอร์มแจ้งจุดขยะ     │              │  Python + PyTorch + YOLOv5 (pLitter) │
 │  report.html            │              │  local_vision/worker.py              │
 └───────────┬────────────┘              │  ดูวิดีโออ้างอิง ไม่ใช่ CCTV เทศบาล      │
             │                            └──────────────────┬──────────────────┘
             │ POST                                          │ POST
             ▼                                               ▼
 ═══════════════════════════════════════════════════════════════════════════════
            ชั้นเซิร์ฟเวอร์  —  PHP 8 ล้วน (ไม่มี framework, ไม่มี build step)
 ───────────────────────────────────────────────────────────────────────────────
   api/reports.php          api/vision.php              api/stats.php
   api/reports-similar.php  api/vision-review.php       api/activity.php
                            api/vision-incident.php     api/live-detection.php
                            api/vision-observations.php
 ───────────────────────────────────────────────────────────────────────────────
   lib/  →  db · validate · pagination · vision_config · vision_evidence
            activity · live_detection · report_config · cli_only
 ═══════════════════════════╤═══════════════════════════════════════════════════
                            │ PDO
 ┌──────────────────────────▼──────────────────────────────────────────────────┐
 │  MySQL / MariaDB                                                            │
 │    waste_stats          ← ข้อมูลเปิดจริง (นำเข้าจากงานช่วงเช้า)                 │
 │    reports              ← เรื่องที่คนแจ้ง (citizen | demo_seed)                │
 │    vision_observations  ← ผลตรวจดิบ 1 แถว = 1 ครั้งที่โมเดลเจอ                 │
 │    vision_incidents     ← "เหตุ" ที่รวมจาก observations ไว้ให้คนตรวจ            │
 └──────────────────────────┬──────────────────────────────────────────────────┘
                            │ GET (JSON, แบ่งหน้าเสมอ)
 ┌──────────────────────────▼──────────────────────────────────────────────────┐
 │  เบราว์เซอร์  —  HTML + CSS + vanilla JS                                      │
 │    index.html    สถิติขยะ          detect.html    ดูผล AI                     │
 │    report.html   แจ้งจุดขยะ         vision.html    ศูนย์ตรวจสอบ (คิวเหตุ)        │
 │    reports.html  รายการแจ้ง         incident.html  รายละเอียดเหตุ + ปุ่มตัดสินใจ  │
 │                  activity.html  ประวัติย้อนหลัง 90 วัน                          │
 └─────────────────────────────────────────────────────────────────────────────┘
```

### AI รันที่ไหน (ข้อนี้สำคัญ อย่าเข้าใจผิด)

```
  เครื่อง local (คอมของคุณ)                     Railway (cloud)
  ┌────────────────────────────┐              ┌────────────────────────────┐
  │ worker.py + PyTorch        │              │ PHP + MySQL เท่านั้น         │
  │ + โมเดล pLitter (~100 MB)  │   POST  →    │                            │
  │ + วิดีโออ้างอิง             │  (ถ้าตั้งค่า)  │ ไม่มี torch ไม่มีโมเดล        │
  │                            │              │ ไม่ประมวลผลภาพใด ๆ           │
  │ เขียน runtime/vision/      │              │                            │
  │   latest.jpg + latest.json │              │ detect.html จะแสดง          │
  └─────────────┬──────────────┘              │ "ตัวอย่างผลลัพธ์" แทนเฟรมสด   │
                │                             └────────────────────────────┘
                │ อ่านผ่าน api/live-detection.php
                ▼
           detect.html
```

---

## Flow ของงาน (ทำไมต้องมี "เหตุ" ไม่ใช่แค่ "ผลตรวจ")

```
  ผลตรวจดิบ        รวมเป็นเหตุ         คนตรวจ            สั่งงาน          ปิดงาน
  observation  →  incident     →  review_status  →  action_status  →  resolved
  (โมเดลเจอ)      (ตามช่วงเวลา)     confirm/reject     needs_check
```

- โมเดลเจอ 30 ครั้งในจุดเดียวกัน = **1 เหตุ** ไม่ใช่ 30 งาน
  (ช่วงเวลาที่ใช้รวมตั้งไว้ที่ `afternoon/lib/vision_config.php` ตอนนี้ 10 นาที)
- **คนตัดสินที่ระดับ "เหตุ"** ไม่ใช่ที่ผลตรวจดิบ — AI คัดกรอง คนยืนยัน
- `reject` คือจุดจบ ต้อง `confirm` ก่อนถึงจะสั่งงานและปิดงานได้

---

## อะไรจริง อะไรเป็นเดโม

| ส่วน | สถานะ |
|---|---|
| สถิติใน `waste_stats` | **ข้อมูลเปิดจริง** (`data/waste_chonburi.csv`) |
| การตรวจจับของโมเดล | **โมเดลจริง รันจริง** (pLitter YOLOv5) แต่ดู**วิดีโออ้างอิง** ไม่ใช่กล้องบางแสน |
| ภาพใน `afternoon/assets/vision/` | **ผลลัพธ์จริงจากโมเดล** บันทึกที่มาไว้ทุกไฟล์ใน README ของโฟลเดอร์นั้น |
| เรื่องแจ้งที่ติดป้าย `ตัวอย่าง` | **ข้อมูลสมมติ** (`record_origin = demo_seed`) ลบได้ด้วย `tools/clear_demo.php` |
| เหตุที่ติดป้าย `ข้อมูลสาธิต` | **ข้อมูลสมมติ** ใส่ไว้ให้เห็นการทำงานของคิว |
| เหตุที่ติดป้าย `จากโมเดลจริง` | มาจากการรัน `local_vision/worker.py` จริง (`detector_run`) |
| กล้อง CCTV ของเทศบาล | **ไม่มี** ไม่เคยเชื่อมต่อ |
| ค่า confidence | ค่าที่โมเดลรายงานต่อเฟรม **ยังไม่ได้ปรับเทียบ** กับพื้นที่บางแสน |
| รัศมีเตือนเรื่องซ้ำ | **ยังไม่ได้ปรับเทียบ** เป็นค่าตั้งต้นของต้นแบบ (`lib/report_config.php`) |

คำสองคำที่ต้องแยกกันเสมอ:

- `source_mode` = ภาพมาจากไหน — `replay` | `camera` | `cctv`
- `record_origin` = แถวนี้เกิดขึ้นได้ยังไง — `demo_seed` | `detector_run`
  (ของตาราง `reports` คือ `citizen` | `demo_seed`)

โมเดลจริงที่รันกับวิดีโออ้างอิงจึงเป็น `replay` + `detector_run` —
เป็นผลตรวจจริง แต่ไม่ใช่ภาพจากกล้องเทศบาล

---

## รันบนเครื่องตัวเอง

### 1. เว็บแอป (PHP + MySQL) — ส่วนหลัก

ต้องมี XAMPP (Apache + MariaDB + PHP 8)

```bash
# 1) copy โค้ดไป htdocs
xcopy /E /I /Y afternoon C:\xampp\htdocs\bangsaen

# 2) สร้างฐานข้อมูล — ไฟล์นี้มี DROP TABLE ใช้กับเครื่อง local เท่านั้น
C:\xampp\mysql\bin\mysql.exe -u root < afternoon\sql\schema.sql

# 3) เปิด http://localhost/bangsaen/index.html
```

ใส่ข้อมูลตัวอย่าง (ไม่บังคับ — ทุกแถวติดป้ายว่าเป็นตัวอย่าง และลบทีหลังได้):

```bash
C:\xampp\php\php.exe afternoon\tools\seed_demo.php         # ใส่
C:\xampp\php\php.exe afternoon\tools\clear_demo.php        # ดูก่อนว่าจะลบอะไร (dry run)
C:\xampp\php\php.exe afternoon\tools\clear_demo.php --yes  # ลบจริง
```

### 2. ตัวตรวจจับ AI (Python) — ไม่บังคับ

ถ้าไม่รัน หน้า `detect.html` จะแสดง "ตัวอย่างผลลัพธ์" ซึ่งเป็นเฟรมจริงจากการรันครั้งก่อน

```bash
# ต้องมี venv ที่ลง torch + opencv + ultralytics ไว้แล้ว
C:\tmp\cv_venv\Scripts\python.exe local_vision\worker.py --source road

# ลองสั้น ๆ โดยไม่เขียนอะไรลงฐานข้อมูล
C:\tmp\cv_venv\Scripts\python.exe local_vision\worker.py --source water --max-inferences 3 --no-post --no-loop
```

`--source` เลือกได้: `road` | `city` | `water`

### 3. งานช่วงเช้า (วิเคราะห์ข้อมูลเปิด)

```bash
python morning\test_waste_logic.py
python morning\analyze.py
```

---

## Deploy

### รูปแจ้งจุดขยะและวิดีโอสาธิต

- แจ้งได้ด้วยสถานที่อย่างเดียว รูปและรายละเอียดเป็นทางเลือก; ไม่ประเมินน้ำหนักหรือสร้างผล AI จากรูปผู้แจ้ง
- รับ JPEG/PNG/WEBP ไม่เกิน 5 MB ตรวจ MIME ฝั่ง API และใช้ชื่อสุ่มใน `uploads/reports/`
- Railway app ปัจจุบันไม่มี volume สำหรับรูป: รูปอาจหายหลัง restart/redeploy; API คืน `image_path: null` เมื่อไฟล์หาย ข้อมูลเรื่องแจ้งใน MySQL ยังอยู่
- DB ต้องตรวจ `DESCRIBE reports` แล้วใช้เฉพาะ migration `004`/`005`/`006` ที่ยังขาด ห้ามใช้ `schema.sql`; `CREATE TABLE IF NOT EXISTS` ไม่อัปเดตตารางเดิม
- `006_reports_lifecycle.sql` เพิ่ม `reviewed_at` และ `resolved_at` เพื่อบันทึกเวลาจริงของ workflow
- วิดีโอ Water ที่เผยแพร่ได้อยู่ใน `afternoon/assets/demo-videos/`; Road/City ใช้ JPG fallback เพราะยังไม่มีหลักฐานสิทธิ์เผยแพร่ต้นฉบับ
- Render ใหม่ด้วย `C:\tmp\cv_venv\Scripts\python.exe local_vision\render_demo_videos.py` แล้วคัดลอกเฉพาะไฟล์ที่ตรวจสิทธิ์แล้วไป `afternoon/assets/demo-videos/`
- weights และวิดีโอต้นฉบับเป็น local-only
- CCTV ยังเป็นโหมดจำลอง; จะต่อ RTSP จริงต้องมี URL/credential และบริการ capture/stream เพิ่ม

รายละเอียดอยู่ใน [`RAILWAY_DEPLOY.md`](RAILWAY_DEPLOY.md) สรุปสั้น ๆ:

- ขึ้น Railway **เฉพาะ** เว็บ PHP/MySQL ตั้ง root directory = `/afternoon`
- ตั้งฐานข้อมูลครั้งแรกด้วย `afternoon/sql/railway_init.sql`
  (**ไม่ใช่** `schema.sql` — อันนั้นมี `DROP TABLE`)
- ค่าเชื่อมต่อ DB อ่านจาก env `MYSQLHOST` / `MYSQLPORT` / `MYSQLUSER` /
  `MYSQLPASSWORD` / `MYSQLDATABASE` ไม่มีรหัสผ่านอยู่ในโค้ด
- **ห้ามเอา torch หรือไฟล์โมเดลขึ้น Railway** — ตัวตรวจจับเป็น local worker
- `tools/*.php` และ `tests/*.php` ต้องตอบ 404 เมื่อเปิดผ่าน URL (บังคับด้วย `lib/cli_only.php`)

---

## โครงสร้างโฟลเดอร์

```
bangsaen-waste-participant/
├── README.md               ← ไฟล์นี้
├── CLAUDE.md               ← กติกา/สถาปัตยกรรม สำหรับคนหรือ AI ที่มาแก้ต่อ
├── RAILWAY_DEPLOY.md       ← วิธี deploy
├── data/
│   └── waste_chonburi.csv  ← ข้อมูลเปิดจริง
├── morning/                ← งานช่วงเช้า (Python วิเคราะห์ข้อมูล)
│   ├── waste_logic.py
│   ├── analyze.py
│   └── test_waste_logic.py
├── local_vision/
│   └── worker.py           ← ตัวตรวจจับ AI (รัน local เท่านั้น)
├── references/             ← โมเดล pLitter + สคริปต์ทดลอง (ไม่ขึ้น cloud)
└── afternoon/              ← เว็บแอป = สิ่งที่ deploy
    ├── index.php           ← entry point ให้ Railway รู้ว่าเป็นแอป PHP
    ├── *.html              ← 7 หน้า
    ├── js/                 ← vanilla JS หน้าละไฟล์ + ui.js / vision-common.js ที่ใช้ร่วมกัน
    ├── css/style.css       ← design token ชุดเดียว ธีมสว่าง สีหลักเขียวน้ำเงิน
    ├── api/                ← endpoint JSON
    ├── lib/                ← ตรรกะที่ใช้ร่วมกัน (db, validate, pagination, …)
    ├── sql/
    │   ├── schema.sql          ← รีเซ็ตเครื่อง local (มี DROP TABLE)
    │   ├── railway_init.sql    ← ตั้งค่าครั้งแรกบน server จริง (ปลอดภัย รันซ้ำได้)
    │   └── migrations/         ← เพิ่มคอลัมน์อย่างเดียว ไม่ลบของเดิม
    ├── assets/vision/      ← ภาพผลลัพธ์จริงจากโมเดล + บันทึกที่มา
    ├── runtime/vision/     ← worker เขียนเฟรมล่าสุดลงที่นี่ (ไม่ commit)
    ├── tools/              ← seed_demo.php / clear_demo.php (CLI เท่านั้น)
    └── tests/              ← ชุดทดสอบ PHP (CLI เท่านั้น)
```

ไฟล์ที่ลงท้าย `_buggy` (`api/reports_buggy.php`, `lib/validate_buggy.php`,
`morning/waste_logic_buggy.py`) **มีบั๊กฝังไว้โดยตั้งใจ** สำหรับแบบฝึกหัด
ไม่ใช่โค้ดจริงของระบบ — อย่า "แก้"

---

## ตรวจว่ายังไม่พัง

```bash
python morning\test_waste_logic.py
C:\xampp\php\php.exe afternoon\tests\run_tests.php
C:\xampp\php\php.exe afternoon\tests\review_workflow_test.php
C:\xampp\php\php.exe afternoon\tests\vision_integration_test.php
C:\xampp\php\php.exe afternoon\tests\list_api_test.php   # ต้องเปิด Apache ไว้
node afternoon\tests\review_queue_ui_test.js
```

`list_api_test.php` ยิงใส่ Apache จริง โดยใส่เฉพาะแถวที่มี prefix ของตัวเอง
แล้วลบเฉพาะแถวนั้นเมื่อจบ
