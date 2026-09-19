# Bangsaen Waste Vision — สถานะและแผนงาน

> อ้างอิงหลัก: [`SPEC_TEMPLATE.md`](../SPEC_TEMPLATE.md) และ [`BMC.md`](../BMC.md)
> เอกสารนี้สรุปว่า **วันนี้ (workshop) ทำอะไรสำเร็จจริงแล้ว**, อะไรยังไม่ทำแต่ทำต่อได้ง่าย,
> และอะไรเป็นของ pilot จริงกับเทศบาลในอนาคต — ทุกข้อความยึดกติกา "screening tool" ตาม
> SPEC_TEMPLATE.md §สิ่งที่ห้าม Claim ใน MVP อย่างเคร่งครัด

## สรุปสถานะ ณ วันนี้

Proof ที่พิสูจน์แล้วจริง (ไม่ใช่ mock/ไม่ใช่ตัวเลขสมมติ):

```text
ภาพจริง (license ชัดเจน, ไม่ใช่กล้องบางแสน)
  → pLitterStreet YOLOv5l pretrained model inference จริง (CPU)
  → bounding box / detection จริง (5 detections, confidence 0.32-0.59)
  → JSON ตาม contract ใน SPEC_TEMPLATE.md
  → POST /api/vision.php
  → MySQL เก็บ Raw Observation และเชื่อม vision_incidents
  → Event Aggregation สร้าง/อัปเดต Incident → Incident Queue แสดง PENDING REVIEW
  → Confirm/Reject/Resolve ที่ระดับ Incident ผ่าน API → DB ได้จริง; interaction ผ่าน browser UI ยังรอตรวจด้วยตา
```

ทดสอบทั้ง 2 โมเดลของ pLitter (street และ floating/CCTV) กับภาพจริงคนละภาพ ทั้งคู่ detect ได้จริง — ดูรายละเอียดใน `references/spike/`. หลังเพิ่ม Event Aggregation ได้ rerun pLitterStreet จริง 2 รอบติดกัน: รอบแรกสร้าง Incident และรอบสองเข้า Incident เดิม (`aggregated_into_existing_incident=true`, observation_count=2).

## MUST HAVE TODAY — ทำเสร็จและ demo ได้จริง

| รายการ | สถานะ | หลักฐาน |
|---|---|---|
| `morning/waste_logic.py` ตรง spec | ✅ | `test_waste_logic.py` ผ่านทั้งหมด (ไม่แก้ test) |
| `morning/analyze.py` รันกับข้อมูลจริง | ✅ | รันจริงกับ `data/waste_chonburi.csv` ได้ผลลัพธ์ top5/อัตรากำจัดถูกต้อง |
| `afternoon/sql/schema.sql` (waste_stats, reports, vision_observations, vision_incidents) | ✅ | live DB migrate สำเร็จและรักษา observations เดิมไว้ |
| `lib/validate.php` (พอร์ตจาก Python ที่ test ผ่านแล้ว) | ✅ | `tests/run_tests.php` 22/22 ผ่าน |
| `api/stats.php`, `api/reports.php` | ✅ | ทดสอบผ่าน curl ครบ (success/validation/malformed/filter/405) |
| หน้าสถิติ + หน้าแจ้งจุดขยะ (HTML/JS/CSS) | ✅ | โหลดผ่าน XAMPP จริง, endpoint เชื่อมสำเร็จ |
| CV spike: 1 ภาพจริง → pretrained model → detection จริง | ✅ | pLitterStreet + pLitterFloat ทั้งคู่ (ดู `references/spike/`) |
| `api/vision.php` (GET Incident Queue, POST Raw Observation + aggregation) | ✅ | live Apache ตอบ incident/aggregation contract ใหม่ |
| `api/vision-review.php` (Confirm/Reject/Resolve Incident) | ✅ | integration test บังคับลำดับ state ฝั่ง server (resolve ก่อน confirm → 409, confirm ซ้ำ → 409) |
| Event Aggregation + Human-in-the-loop | ✅ | `tests/vision_integration_test.php` 8/8: 4 observations → 1 incident, raw drill-down, source แยก, priority order, zero detection, confirm→resolve, reject, resolve-before-confirm=409 |
| Waste Vision Dashboard (`vision.html` + `js/vision.js`) แสดง REPLAY MODE ชัดเจน + ปุ่ม Confirm/Reject/Resolve | ✅ | ทดสอบผ่าน curl กับ static file serving; **ยังไม่ได้ตรวจด้วยตาในเบราว์เซอร์จริง** (Chrome extension ไม่เชื่อมต่อในเซสชันนี้) |
| ข้อความ UI ใช้ "ควรตรวจสอบ" / "Possible Waste Incident" / ไม่ใช้ "Confirmed waste" ก่อนคนยืนยัน | ✅ | ตรวจโค้ด `vision.html`/`vision.js` แล้ว |

## NICE TO HAVE — ยังไม่ทำวันนี้ แต่ต่อยอดง่ายด้วยโครงที่มีอยู่

- **Filter UI บน dashboard** — API รองรับ `?review_status=`, `?action_status=`, `?area_type=` แล้ว แต่ `vision.html` ยังไม่มีปุ่ม/dropdown กรอง
- **Browser interaction test** — API/aggregation/state machine มี `tests/vision_integration_test.php` แล้ว แต่ยังควรคลิก Dashboard จริงก่อน present
- **แสดงภาพ/thumbnail ของ observation** — SPEC_TEMPLATE.md ระบุชัดว่า MVP ไม่จำเป็นต้องเก็บภาพ จึงยังไม่ทำ
- **Batch/scheduled replay หลายภาพ** — ตอนนี้ script `references/spike/post_to_vision_api.py` รันทีละภาพ ทีละครั้งเท่านั้น
- **ระบบ login สำหรับเจ้าหน้าที่ก่อนกด Confirm/Reject** — ตอนนี้ endpoint เปิดให้เรียกได้โดยไม่มี auth (พอสำหรับ demo ในห้อง แต่ไม่พร้อม deploy จริง)

## FUTURE — MUNICIPAL CCTV PILOT (ไม่ทำวันนี้ ต้องคุยกับเทศบาลก่อน)

- เชื่อม live camera/CCTV จริง (`source_mode = camera` หรือ `cctv`) — ต้องได้รับสิทธิ์เข้าถึงจากเทศบาลและตรวจมุมกล้องว่าเหมาะสมก่อน (ตาม SPEC_TEMPLATE.md §Source Modes)
- **ยังไม่มีการวัด accuracy ของโมเดลบนภาพบางแสนจริง** — ตัวเลข AP50 ที่ pLitter รายงาน (0.77 street / 0.43 floating) เป็นผลจาก dataset ของ pLitter เอง ไม่ใช่ของบางแสน ห้ามนำไปอ้างเป็น accuracy ของระบบนี้
- วัด false positive / precision ตาม Metrics ใน `BMC.md` (ต้องมีรอบ pilot จริงกับเจ้าหน้าที่ก่อนถึงจะมีตัวเลข)
- Calibrate aggregation window / confidence / priority rule — ตอนนี้ใช้ aggregation window 10 นาทีใน `lib/vision_config.php` และระบุ **PROTOTYPE / UNCALIBRATED** ชัดเจน
- นโยบายเก็บ/ลบภาพจากกล้องจริง (privacy) — ยังไม่ได้ออกแบบ เพราะ MVP วันนี้ไม่เก็บภาพ
- **ต้องติดต่อผู้ดูแล pLitter (GIC/AIT) เพื่อขอความชัดเจนเรื่อง license ก่อนใช้งานเชิง production/พาณิชย์** — repo ต้นทางไม่มีไฟล์ LICENSE ระบุไว้

## ข้อจำกัดที่ต้องพูดตอน present

- นี่คือ REPLAY MODE ทั้งหมด — ยังไม่ได้เชื่อมกล้อง/CCTV เทศบาลจริง
- ภาพที่ใช้ทดสอบเป็นภาพอ้างอิงจาก Wikimedia Commons (license เปิด, มี attribution) **ไม่ใช่ภาพจากบางแสน**
- pLitter pretrained weights ไม่มีไฟล์ LICENSE ชัดเจนในตัว repo ต้นทาง
- AI detection ถูกเก็บเป็น Raw Observation ก่อน ระบบรวมเป็น Incident แล้วจึงให้เจ้าหน้าที่ Confirm/Reject; state machine บังคับที่ Incident ฝั่ง server
- ไม่มีการอ้าง accuracy บนพื้นที่บางแสน ไม่มีการอ้างว่าตรวจทะเลเปิดได้ทุกสภาพ ไม่มีการลดต้นทุน/เวลาทำงานเป็น % ใด ๆ ทั้งสิ้น — ยังไม่มีข้อมูล pilot จริงรองรับ
