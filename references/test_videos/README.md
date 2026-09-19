# Phase D — Local Test Videos

โฟลเดอร์นี้ไว้เก็บวิดีโออ้างอิงสำหรับคัดเลือกก่อนทำหน้า Detect

วิดีโอจริงถูก ignore ใน Git โดย `.gitignore` และห้าม commit/push ไฟล์วิดีโอโดยไม่ตรวจสิทธิ์ก่อน

## แหล่งที่ล็อกไว้

Video 1 — ขยะในเมือง
- เลือกจาก Google Drive ที่ผู้ใช้ให้
- ลักษณะ: fixed/wide CCTV-style, ภาพไม่ต้องคม, ขยะเป็นส่วนหนึ่งของฉาก
- model: pLitterStreet

Video 2 — ขยะริมถนน
- เลือกจาก Google Drive เดียวกัน
- ลักษณะ: fixed/wide roadside CCTV-style, เห็น road edge/sidewalk, ไม่ cinematic
- model: pLitterStreet

Video 3 — ขยะในน้ำ / ทะเล
- G 9736659 — Trashes Flowing on a Lake
- source: https://www.pexels.com/video/trashes-flowing-on-a-lake-9736659/
- local file (gitignored): `water-9736659.mp4`
- model: pLitterFloat
- worker smoke test ล่าสุด: **7 → 5 → 7 detections** ใน 3 เฟรมแรก
- POST เข้า Incident Pipeline จริง 2 ครั้งได้ HTTP 201 และรวมเป็น Incident เดียวตาม aggregation

Google Drive:
https://drive.google.com/drive/folders/1QlFQWKCQrnaox6GdKqPPQUZP6kbJOu7P

## วิธีคัด candidate

ใช้ Python environment นี้เท่านั้น:

`C:\tmp\cv_venv\Scripts\python.exe`

ตัวอย่าง Video 1/2:

```bat
C:\tmp\cv_venv\Scripts\python.exe references\spike\evaluate_video_candidate.py ^
  --video references\test_videos\candidate-city.mp4 ^
  --model street ^
  --samples 5
```

ตัวอย่าง Video 3:

```bat
C:\tmp\cv_venv\Scripts\python.exe references\spike\evaluate_video_candidate.py ^
  --video references\test_videos\candidate-water.mp4 ^
  --model float ^
  --samples 5
```

ผลจะอยู่ใต้ `references/spike/candidate_eval/`:
- annotated sample frames
- `summary.json` ที่มีจำนวนตรวจพบ, class, max confidence และ inference time

สคริปต์นี้ไม่ POST API, ไม่สร้าง Incident และไม่แตะฐานข้อมูล

Video 1/2 ได้รับอนุมัติแล้วและถูก wire เข้า Phase D; Video 3 ถูกดาวน์โหลดจากแหล่งที่ล็อกไว้และ verify ด้วย pLitterFloat จริงแล้ว
