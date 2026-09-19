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
- model: pLitterFloat
- smoke test เดิมที่ยืนยันแล้ว: 6 → 9 → 11 detections

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

ก่อน wire คลิปเข้า `detect.html` ต้องดู annotated frames ด้วยตาและอนุมัติ Video 1/2 ก่อน
