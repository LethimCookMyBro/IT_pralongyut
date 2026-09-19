# Bangsaen Waste Vision — สถานะและแผนงาน

> อัปเดตล่าสุด: 2026-09-19
> อ้างอิงข้อกำหนด: [`SPEC_TEMPLATE.md`](../SPEC_TEMPLATE.md) และ [`BMC.md`](../BMC.md)

## State ปัจจุบัน

**พร้อม Demo / Workshop release และมี Public Railway Demo ที่ตรวจการทำงานแล้ว**

คำว่า “พร้อม” ในที่นี้หมายถึงต้นแบบสำหรับสาธิตตาม scope ของโครงการ ไม่ได้หมายถึง
production-ready สำหรับเทศบาล และยัง **ห้าม** อ้างว่าเชื่อม CCTV เทศบาลจริงหรือมี
accuracy ที่ผ่านการวัดในบางแสนจริง

## ระบบที่พิสูจน์แล้ว

```text
วิดีโออ้างอิงจริง
→ Local pLitter inference จริง
→ annotated frame + latest detector state
→ Raw Observation (record_origin = detector_run)
→ Event Aggregation
→ Incident Queue
→ เจ้าหน้าที่ Confirm / Reject
→ Confirm แล้วจึง Resolve
```

ฝั่งเว็บ:

```text
Railway PHP
→ PDO MySQL
→ Railway MySQL
→ Stats / Reports / Vision APIs
→ Web Dashboard
```

Local detector และ Railway web แยกหน้าที่กันโดยตั้งใจ:
- Python / PyTorch / pLitter รันบนเครื่อง local
- Railway ให้บริการ PHP + MySQL + หน้าเว็บ
- หน้า `detect.html` บน Railway ต้องบอกตรง ๆ ว่า Local AI ไม่ได้ทำงานบน deployment นี้
- หน้าอื่นของเว็บยังต้องทำงานตามปกติ

## Phase A — Railway / Git / deployment readiness ✅

ทำแล้ว:
- Environment-based DB config
- `.env` และ model/video files ถูกกันออกจาก Git
- `railway_init.sql` แบบ non-destructive
- CLI-only guard สำหรับ seed/tests
- `composer.json` ประกาศ `ext-pdo_mysql` + `ext-mbstring`
- Railway build ติดตั้ง `pdo_mysql` จริงแล้ว

ปัญหา `database error` ที่เคยเห็นบน Railway เกิดจาก PHP runtime ไม่มี
`pdo_mysql` ไม่ใช่เพราะข้อมูลหาย หลังแก้แล้ว public `/api/stats.php` ตอบ 200
และหน้า index แสดงข้อมูลจริงจาก Railway MySQL ได้

## Phase B — UI / readability ✅

- Light dashboard theme
- Responsive layout
- Incident detail ให้ภาพหลักฐานเป็นจุดเด่น
- Report form จัดลำดับใหม่โดยไม่เปลี่ยน schema
- List pages รองรับ mobile card layout

## Phase C — Incident queue / scale / provenance ✅

- Server-side queue split: ต้องตรวจ / ต้องดำเนินการ / ประวัติ
- Pagination/filter/search อยู่ฝั่ง server
- `source_mode` และ `record_origin` แยกความหมายกัน
- `demo_seed` ไม่ถูกรวมกับ `detector_run`
- Evidence path ถูก validate และจำกัด folder/extension
- Human review ยังอยู่ที่ Incident level

## Phase D — Local continuous detection ✅

ไฟล์หลัก:
- `local_vision/worker.py`
- `afternoon/api/live-detection.php`
- `afternoon/lib/live_detection.php`
- `afternoon/detect.html`
- `afternoon/js/detect.js`

พฤติกรรมที่ตรวจแล้ว:
- โหลด model ครั้งเดียว
- infer video ต่อเนื่องประมาณ 3 ครั้ง/วินาทีโดยค่าเริ่มต้น
- เขียน `latest.jpg` + `latest.json` แบบ atomic replace
- frontend poll ทุก 800 ms
- POST observation เป็นช่วง ไม่ใช่ทุก frame
- Event Aggregation รวม observation ซ้ำเป็น incident เดียว
- evidence ของ worker จำกัดจำนวนต่อ source
- webcam ขอ permission หลังผู้ใช้กดปุ่มเท่านั้น และเป็น preview ไม่ใช่ detector input

### Demo source ที่ล็อกแล้ว

1. **ขยะริมถนน** — `Video/vid0012.mp4` → pLitterStreet
   - candidate screening เดิม: detection 7/7 sampled frames
   continuous worker smoke: 1, 1, 1 detection ใน 3 inference แรก และ POST จริงได้ 201

2. **ขยะในเมือง / พื้นที่สาธารณะ** — `Video/vid0269.mp4` → pLitterStreet
   - candidate screening เดิม: detection 7/7 sampled frames
   continuous worker smoke: 2, 3, 3 detections ใน 3 inference แรก และ POST จริงได้ 201

3. **ขยะในน้ำ** — Pexels G 9736659 → pLitterFloat
   - local file ถูกเก็บแบบ gitignored
   continuous worker smoke ล่าสุด: **7 → 5 → 7 detections** และ POST จริงได้ 201

ตัวเลขเหล่านี้เป็นเพียงผลจากวิดีโออ้างอิง ไม่ใช่ accuracy ของระบบในบางแสน

## Verification ล่าสุด

| รายการ | ผล |
|---|---|
| PHP validation/runtime tests | **80 passed, 0 failed** |
| Vision integration tests | **15 passed, 0 failed** |
| List/API tests | **29 passed, 0 failed** |
| Python morning tests | **5 tests OK** |
| `morning/analyze.py` | OK — เทศบาลเมืองแสนสุข ปี 2566 = **67.5%** |
| JavaScript syntax | ทุกไฟล์ผ่าน |
| PHP syntax | ทุกไฟล์ใน `afternoon/` ผ่าน |
| Phase D worker Python compile | ผ่าน |
| Local `detect.html` + real worker | ผ่าน |
| Detect viewport ~390px | ไม่มี horizontal overflow |
| Railway `/api/stats.php` | 200 + JSON จริง |
| Railway `/api/reports.php` | 200 |
| Railway `/api/vision.php` | 200 |
| Railway `/api/live-detection.php` | 200 + offline state ตามจริง |
| Railway `/detect.html` | 200 |
| Railway report write smoke | POST 201 แล้ว cleanup exact test row |
| Railway detector observation write smoke | POST 201; Confirm 200; Resolve 200; cleanup exact test rows |
| Public seed/tests URLs | 404 ตามที่กำหนด |

Railway database ที่ตรวจ:
- `waste_stats = 24`
- `reports = 0`
- `vision_incidents = 0`
- `vision_observations = 0`
- `record_origin` มีอยู่ใน deployed schema

## สิ่งที่ยังไม่ควรอ้าง / งานสำหรับ Pilot จริง

สิ่งเหล่านี้ **ไม่ใช่ blocker ของ Workshop Demo** แต่ต้องทำก่อนใช้จริงกับเทศบาล:

- Auth / authorization สำหรับเจ้าหน้าที่
- เชื่อม CCTV/IP camera ที่ได้รับสิทธิ์จริง
- วัด precision / false-positive rate บนภาพบางแสนจริง
- Calibrate confidence / aggregation window / priority rule
- กำหนด retention/privacy policy สำหรับภาพจริง
- Monitoring / backup / incident recovery สำหรับ production
- ตรวจ license ของ pLitter weights ให้ชัดก่อนใช้งาน production/เชิงพาณิชย์

## Public demo

`https://itpralongyut-production.up.railway.app`

Public demo ใช้สำหรับแสดง PHP/MySQL/UI เท่านั้น Local detector ไม่ได้รันบน Railway
และหน้า Detect จะแสดง offline อย่างตรงไปตรงมาเมื่อไม่มี local worker
