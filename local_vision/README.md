# Local Vision Worker

รันจาก project root ด้วย environment ที่ verify แล้ว:

C:\tmp\cv_venv\Scripts\python.exe local_vision\worker.py --source road
C:\tmp\cv_venv\Scripts\python.exe local_vision\worker.py --source city
C:\tmp\cv_venv\Scripts\python.exe local_vision\worker.py --source water

Source:
- road = Video/vid0012.mp4 + pLitterStreet
- city = Video/vid0269.mp4 + pLitterStreet
- water = references/test_videos/water-9736659.mp4 + pLitterFloat

Output:
- afternoon/runtime/vision/latest.jpg
- afternoon/runtime/vision/latest.json
- ถ้ามี C:\xampp\htdocs\bangsaen ตัว worker จะเขียนไปที่ live copy นั้นโดยอัตโนมัติ

Observation:
- POST ไป http://localhost/bangsaen/api/vision.php ทุกประมาณ 4 วินาทีเมื่อยังตรวจพบขยะ
- record_origin = detector_run
- evidence image เก็บแบบ bounded: ล่าสุดไม่เกิน 3 รูปต่อ source

Smoke test โดยไม่แตะ DB:
C:\tmp\cv_venv\Scripts\python.exe local_vision\worker.py --source road --max-inferences 3 --no-post --no-loop

หน้าเว็บ:
http://localhost/bangsaen/detect.html
