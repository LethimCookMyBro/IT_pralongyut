# SPEC — Bangsaen Waste Vision

## ชื่อเว็บ
**Bangsaen Waste Vision**

## ปัญหาขยะที่แก้ (1 ประโยค)
พื้นที่สาธารณะและทางน้ำบางจุดในบางแสนอาจมีขยะสะสมโดยที่เจ้าหน้าที่ไม่สามารถเฝ้าดูทุกจุดหรือทุกกล้องได้ตลอดเวลา จึงต้องการระบบช่วยคัดกรองภาพจากกล้องเพื่อชี้ว่า “จุดไหนควรตรวจสอบ” โดยยังให้มนุษย์เป็นผู้ยืนยันก่อนดำเนินการ

## ผู้ใช้หลัก
- เจ้าหน้าที่เทศบาลเมืองแสนสุข / เจ้าหน้าที่สิ่งแวดล้อม
- ทีมควบคุมงานความสะอาดและทีมดูแลชายหาด
- บริษัทผู้รับเหมาดูแลความสะอาด (ผู้ใช้ที่เป็นไปได้ในอนาคต)

ผู้ใช้รอง: ร้านค้า ประชาชน และนักท่องเที่ยว ผ่านหน้าแจ้งจุดขยะตามโจทย์ Workshop

## แนวคิดระบบ
Computer Vision เป็น **Screening Tool** ไม่ใช่ Final Authority และ Human Review ทำกับ Incident ที่รวมเหตุซ้ำแล้ว ไม่ใช่ทุก detection/frame

```text
Replay Image / Video / CCTV (อนาคต)
        ↓
Local Computer Vision
        ↓
Raw Observations
        ↓
Event Aggregation
        ↓
Incident Queue / Explainable Priority
        ↓
Human Verify: Confirm / Reject Incident
        ↓
ถ้า Confirm → Needs Check / Action
        ↓
Resolved
        ↓
Feedback
```

หลักสำคัญ: `raw detection / observation ≠ incident ≠ task`

## ฟีเจอร์ที่ต้องมี

### 1. หน้าสถิติ — MUST HAVE
ใช้ข้อมูลเปิดของชลบุรี/เทศบาลเมืองแสนสุข แสดงอย่างน้อย:
- ปริมาณขยะที่เกิดขึ้นต่อวัน
- ปริมาณเก็บขนไปกำจัด
- ปริมาณนำไปใช้ประโยชน์
- ปริมาณกำจัดถูกต้อง / ไม่ถูกต้อง
- อัตราการกำจัดถูกต้อง
- 5 อปท. ที่มีปริมาณขยะเกิดขึ้นสูงสุดในปีล่าสุด

ข้อมูลเปิดเป็น Historical / Macro layer ไม่ใช่ข้อมูลสำหรับฝึก Computer Vision และห้ามสร้าง correlation กับผลจากกล้องโดยไม่มีหลักฐาน

### 2. หน้าแจ้งจุดขยะ — MUST HAVE
รับ `location`, `waste_type`, `amount_kg`, `detail` บันทึกลง MySQL และแสดงรายการล่าสุดพร้อม status

หน้าแจ้งยังคงอยู่ตามโจทย์ Workshop แต่ไม่ใช่จุดขายหลักของ Bangsaen Waste Vision

### 3. Waste Vision Dashboard — ฟีเจอร์เด่นของกลุ่ม
MVP แบบ Software-only ใช้ **REPLAY MODE** ก่อน เพราะตอนนี้ยังไม่มี CCTV/กล้องจริงของเทศบาล

ต้องทำได้:
- ใช้ภาพจริงจาก public/open dataset หรือ reference project
- Local detector ทำ inference จริง
- เก็บผลแต่ละครั้งเป็น Raw Observation ใน PHP + MySQL
- รวม Raw Observations จากกล้อง/จุดเดียวกันเป็น Incident ก่อนให้คนตรวจ
- Dashboard หลักแสดง Incident Queue และเปิดดู Raw Observations ย้อนหลังได้
- Incident แสดง first_seen, last_seen, observation_count, peak/latest detected count และ confidence
- เจ้าหน้าที่กด `CONFIRM` หรือ `REJECT` ที่ระดับ Incident
- เมื่อ Confirm เปลี่ยนเป็น `NEEDS CHECK`
- เมื่อดำเนินการแล้วสามารถเปลี่ยนเป็น `RESOLVED`
- UI แสดงคำว่า `REPLAY MODE` และ `PROTOTYPE / UNCALIBRATED` ชัดเจน

ข้อความที่ใช้: “ควรตรวจสอบ”, “Possible Waste Incident”, “Waiting for human verification”

ห้ามใช้ “Confirmed waste” ก่อนคนยืนยัน หรือ claim accuracy บนบางแสนถ้ายังไม่ได้ทดสอบจริง

## Source Modes
- `replay` — ใช้ใน Prototype วันนี้
- `camera` — Webcam/IP camera สำหรับการทดลองภายหลัง
- `cctv` — CCTV เทศบาลเมื่อได้รับสิทธิ์และตรวจแล้วว่ามุมกล้องเหมาะสม

การนำเสนอวันนี้ต้องระบุว่า **ยังไม่ได้เชื่อม CCTV เทศบาลจริง**

## LAND / WATER

### LAND
เหมาะกับพื้นที่สาธารณะ จุดพัก ทางเดิน และพื้นที่ที่กล้องเห็นพื้นชัด

### WATER
Pilot ควรเริ่มจากปากคลอง ทางระบายน้ำ สะพาน หรือจุดที่กล้องมองผิวน้ำจากมุมคงที่

ยังไม่ Claim ว่าสามารถตรวจขยะในทะเลเปิดทุกสภาพได้

## Database

### ตารางเดิม
- `waste_stats`
- `reports`

### ตาราง `vision_observations` — Raw model observations
เก็บผล inference แต่ละครั้งและเชื่อมกับ Incident ด้วย `incident_id`

ฟิลด์หลัก: `incident_id`, `camera_name`, `location`, `area_type`, `detected_count`, `max_confidence`, `source_mode`, `captured_at`

Observation ไม่มี Human Review status เป็น authority

### ตาราง `vision_incidents` — Aggregated events
เก็บเหตุที่รวม Raw Observations แล้ว โดยมี `first_seen`, `last_seen`, `observation_count`, `first_detected_count`, `latest_detected_count`, `peak_detected_count`, `max_confidence`, `review_status`, `action_status`, `reviewed_at`, `resolved_at`

Human Review และ Action status อยู่ที่ Incident

MVP ไม่จำเป็นต้องเก็บภาพลงฐานข้อมูล และ `max_confidence` เป็นค่าประกอบการดู ไม่ใช่หลักฐานยืนยันเหตุการณ์

## API

### API เดิม
- `GET /api/stats.php`
- `GET /api/reports.php`
- `POST /api/reports.php`

### API เพิ่ม
- `GET /api/vision.php` — Incident Queue + summary
- `POST /api/vision.php` — รับ Raw Observation และ create/update Incident
- `GET /api/vision-observations.php?incident_id=...` — ดู Raw Observations ของ Incident
- `POST /api/vision-review.php` — Confirm / Reject / Resolve **Incident**

ตัวอย่าง Detection:

```json
{
  "camera_name": "Replay-W01",
  "location": "จุดทดลองทางน้ำ",
  "area_type": "water",
  "detected_count": 4,
  "max_confidence": 0.82,
  "source_mode": "replay"
}
```

## Detection / Event Aggregation Logic สำหรับ Prototype
- Detection 1 ครั้ง = Raw Observation ไม่ใช่ Incident และไม่ใช่ Task
- Observation ที่ `detected_count > 0` จาก camera/location/area/source เดียวกัน ภายใน aggregation window และ Incident เดิมยัง `pending` ให้ update Incident เดิม
- aggregation window อยู่ใน `afternoon/lib/vision_config.php`
- สถานะของกฎนี้ = **PROTOTYPE / UNCALIBRATED**
- Priority Queue ใช้ rule-based ordering: `observation_count` มากก่อน → `peak_detected_count` มากก่อน → `last_seen` ล่าสุดก่อน
- ไม่มี weighted score และห้ามอ้างว่า threshold/priority rule ผ่านการ validate แล้ว
- ห้าม dispatch งานอัตโนมัติจาก AI detection

## Proof ที่ต้องแสดงวันนี้

```text
ภาพจริง 1 ภาพ
→ pretrained model inference จริง
→ Raw Observation JSON
→ POST เข้า PHP API
→ MySQL เก็บ Raw Observation
→ Event Aggregation สร้าง/อัปเดต Incident
→ Dashboard แสดง Incident PENDING REVIEW
→ เปิดดู Raw Observations ได้
→ Human Confirm/Reject Incident
→ ถ้า Confirm = NEEDS CHECK
→ RESOLVED หลังดำเนินการ
```

ไม่จำเป็นต้องมี CCTV เทศบาลจริง, ESP32/IP camera, prediction, route optimization หรือ smart bin

## Open Data Layer
ใช้ข้อมูลจาก:
https://chonburi.gdcatalog.go.th/dataset/?organization=chonburi_mnre

ใช้เฉพาะข้อมูลที่ตรวจพบจริงใน dataset เช่น generated waste, collected waste, utilized waste, proper/improper disposal และ complaint data ถ้ามีคอลัมน์และข้อมูลที่ใช้ได้จริง

ห้ามสร้างตัวเลขหรือข้อสรุปที่ข้อมูลไม่รองรับ

## Pain / User / Buyer / Proof

### Pain
เมื่อมีหลายพื้นที่หรือหลายกล้อง เจ้าหน้าที่ไม่สามารถเฝ้าดูทุกภาพได้ตลอดเวลา และถ้าส่งทุก detection/frame ให้คนตรวจจะเกิด alert flood ระบบจึงช่วยคัดกรองภาพและรวม Raw Observations เป็น Incident ก่อนชี้ “จุดที่ควรตรวจสอบ”

### User
เจ้าหน้าที่สิ่งแวดล้อม ทีมควบคุมงานความสะอาด และทีมดูแลชายหาด/พื้นที่สาธารณะ

### Buyer — สมมติฐานที่ต้อง Validate
เทศบาล/หน่วยงานท้องถิ่น, บริษัทรับเหมาดูแลความสะอาด, ผู้ดูแลพื้นที่ท่องเที่ยวขนาดใหญ่

### Proof
`Replay Image → Real Detection → Raw Observation → Incident → PHP/MySQL → Dashboard → Human Review`

## โมเดลธุรกิจโดยย่อ
Value: เพิ่ม Software Intelligence ให้กล้องเดิมหรือกล้องราคาต่ำ เพื่อช่วยคัดกรองและรวมเหตุซ้ำเป็น Incident ที่ควรตรวจ โดยยังให้คนเป็นผู้ตัดสิน

Revenue Hypothesis:
- ค่าติดตั้งและตั้งค่าระบบ
- ค่าดูแล/บำรุงรายปี
- ค่าอุปกรณ์หรือกล้องเพิ่มเติมเฉพาะจุด
- อาจคิดตามจำนวนจุดตรวจในอนาคตหลัง Validate ลูกค้า

## Roadmap

### Phase 1 — Prototype วันนี้
Replay image → Local detector → Raw Observation → Event Aggregation → Incident Dashboard → Human review

### Phase 2 — Pilot
ทดสอบกับ Webcam/IP camera และภาพจริงในพื้นที่

### Phase 3 — Municipal CCTV Pilot
เชื่อม CCTV เดิมเฉพาะจุดที่ได้รับอนุญาตและมุมกล้องเหมาะสม

### Phase 4 — Water Monitoring
ทดลอง fixed camera ที่ปากคลอง/สะพาน/ทางน้ำ และประเมินโมเดล floating litter

### Phase 5 — Scale
เก็บ feedback ที่คนยืนยันแล้ว → สร้างชุดข้อมูลบางแสน → ประเมิน/ปรับโมเดล → ขยายจุดตรวจ

## สิ่งที่ห้าม Claim ใน MVP
- ได้ access CCTV เทศบาลแล้ว
- ตรวจขยะได้แม่นทุกกล้อง
- accuracy บนบางแสนโดยยังไม่ได้ทดสอบ
- ตรวจทะเลเปิดได้ทุกสภาพ
- AI เป็นผู้ยืนยันหรือสั่งการแทนคน
- aggregation window / priority rule ผ่านการ validate แล้ว
- prediction ปริมาณขยะอนาคต
- ลดต้นทุน/เวลาทำงาน X% โดยไม่มีการทดลอง

## Definition of Done วันนี้
1. ฟังก์ชันและ tests พื้นฐานของ Workshop ผ่าน
2. Stats page ใช้ข้อมูลจริง
3. Report page ใช้งานได้
4. Detector inference จริงผ่านอย่างน้อย 1 ภาพ
5. Raw Observation ส่งเข้า PHP/MySQL ได้
6. หลาย Observation จาก source เดียวกันรวมเป็น Incident ได้
7. Dashboard หลักแสดง Incident และเปิดดู Raw Observations ได้
8. Confirm/Reject/Resolve ทำกับ Incident และบังคับ state ฝั่ง server
9. Demo ระบุ REPLAY MODE และ PROTOTYPE / UNCALIBRATED ชัดเจน
10. ไม่มี claim เกินหลักฐาน
