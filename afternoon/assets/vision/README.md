# ภาพหลักฐานจากโมเดล (evidence images)

ไฟล์ในโฟลเดอร์นี้คือ **ผลลัพธ์จริงจากโมเดลตรวจจับ** ที่รันในโปรเจกต์นี้
(วาด bounding box โดย YOLOv5 เอง) ไม่ใช่ภาพจำลอง ไม่ใช่ placeholder
และ **ไม่ใช่ภาพจากกล้อง CCTV ของเทศบาลบางแสน**

ย่อขนาดเพื่อใช้บนเว็บเท่านั้น (ยาวด้านมากสุด 1400 px) ผลการตรวจไม่ถูกแก้ไข —
ต้นฉบับความละเอียดเต็มอยู่ที่ `references/spike/`

## street-replay-01.jpg

- โมเดล: `references/pLitter/weights/pLitterStreet_YOLOv5l.pt`
- สคริปต์: `references/spike/run_inference.py`
- ต้นฉบับผลลัพธ์: `references/spike/out/litter_singapore_ecp.jpg` (6000x4000)
- ผลตรวจ: 5 กล่อง (Pile 0.34, Plastic 0.49, 0.59, 0.59, 0.32) — ความมั่นใจสูงสุด 0.59
- เข้าสู่ระบบผ่าน: `references/spike/post_to_vision_api.py`
  (`camera_name = Replay-Street-01`, `detected_count = 5`, `max_confidence = 0.5886`)
- ผูกกับ observation ที่มีลายเซ็นตรงกับการรันครั้งนั้นใน
  `afternoon/sql/migrations/002_vision_observation_image.sql`
- ภาพต้นทาง: Litter on Singapore's East Coast Park — vaidehi shah, CC BY 2.0,
  via Wikimedia Commons (ดู `references/test_images/ATTRIBUTION.txt`)

## floating-replay-01.jpg

- โมเดล: `references/pLitter/weights/pLitterFloat_800x752_to_640x640.pt`
- สคริปต์: `references/spike/run_inference_float.py`
- ต้นฉบับผลลัพธ์: `references/spike/out_float/floating_litter_water.jpg` (4064x3048)
- ภาพต้นทาง: Nature's Silent Struggle — Jemir Shamir, CC BY-SA 4.0,
  via Wikimedia Commons (ดู `references/test_images/ATTRIBUTION_floating.txt`)
- **สถานะ: ยังไม่ได้ผูกกับ observation ใด** เพราะการรันครั้งนั้นไม่ได้บันทึกจำนวนที่ตรวจพบไว้
  และไม่เคยถูก POST เข้า API
  ถ้าต้องการให้มีเหตุทางน้ำที่มีภาพหลักฐานจริง ให้รัน
  `references/spike/post_to_vision_api_float.py` (ต้องมี torch) ซึ่งจะรันโมเดลใหม่
  แล้วส่งผลจริงพร้อม `image_path` เข้า API
- ห้ามผูกไฟล์นี้กับเหตุที่มีอยู่ด้วยมือ — จะกลายเป็นหลักฐานที่ไม่ตรงกับการตรวจจริง

## กติกา

- เพิ่มไฟล์ที่นี่ได้เฉพาะผลลัพธ์จากการรันโมเดลจริง และต้องบันทึกที่มาไว้ในไฟล์นี้
- ชื่อไฟล์ต้องเป็น `[A-Za-z0-9][A-Za-z0-9._-]*` นามสกุล jpg/jpeg/png/webp
  (ดู `afternoon/lib/vision_evidence.php`)
- `vision_observations.image_path` เก็บแค่ path เช่น `assets/vision/street-replay-01.jpg`
  ไม่เก็บ binary หรือ base64
