# Video selection — 2026-09-19

คัดจากไฟล์จริงใน `Video/` ด้วย pLitterStreet และ sample 7 เฟรมต่อคลิป
ใช้ `C:\tmp\cv_venv\Scripts\python.exe`

## เลือก

### Video 1 — ขยะริมถนน: `Video/vid0012.mp4`

- 1920×1080, 59.94 fps, 15.632 s
- pLitterStreet พบขยะ 7/7 sampled frames
- detected_count: 1 → 1 → 2 → 2 → 2 → 2 → 2
- class: Plastic
- max confidence ต่อ sample ≈ 0.46–0.52
- scene sanity check ด้วย generic YOLOv5s เฟรมกลาง: car ×4
- เหมาะกับ slot “ขยะริมถนน” เพราะบริบทมีรถ/ถนน และ pLitter ตรวจได้ต่อเนื่อง

### Video 2 — ขยะในเมือง/พื้นที่สาธารณะ: `Video/vid0269.mp4`

- 1920×1080, 25 fps, 29.76 s
- pLitterStreet พบขยะ 7/7 sampled frames
- detected_count: 2 → 3 → 2 → 2 → 3 → 2 → 2
- class: Plastic
- max confidence ต่อ sample ≈ 0.72–0.80
- scene sanity check ด้วย generic YOLOv5s เฟรมกลาง: person ×2, umbrella ×2
- เหมาะกับ slot “ขยะในเมือง/พื้นที่สาธารณะ”; เป็น technical candidate ที่แข็งที่สุดใน 4 คลิป

## ไม่เลือก

### `Video/vid0249.mp4`
- 5.9 s
- pLitterStreet พบ 5/7 sampled frames
- counts: 0 → 1 → 2 → 2 → 3 → 2 → 0
- classes: Plastic + Face mask
- หลุดเป็น 0 ทั้งต้นและท้าย และสั้นเกินไปเมื่อเทียบกับสองตัวที่เลือก

### `Video/vid0256.mp4`
- 1280×720, 14.534 s
- pLitterStreet พบเพียง 3/7 sampled frames
- counts: 0 → 0 → 1 → 1 → 1 → 0 → 0
- class: Plastic
- max confidence ≈ 0.26–0.37
- ไม่เสถียรพอสำหรับ demo หลัก

## Video 3 ที่ล็อกไว้เดิม

G 9736659 — Trashes Flowing on a Lake
- model: pLitterFloat
- smoke test เดิม: 6 → 9 → 11 detections
- slot: ขยะในน้ำ / ทะเล

## Final demo source set

1. ขยะริมถนน → `vid0012.mp4` → pLitterStreet
2. ขยะในเมือง/พื้นที่สาธารณะ → `vid0269.mp4` → pLitterStreet
3. ขยะในน้ำ/ทะเล → G 9736659 → pLitterFloat

Annotated samples + summaries อยู่ใน `references/spike/candidate_eval/`.
ยังไม่ wire เข้า `detect.html` ณ จุดที่เขียนเอกสารนี้
