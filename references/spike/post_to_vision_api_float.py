"""
Bangsaen Waste Vision - floating-litter connector (replay mode)
Real detector -> Raw Observation (+ evidence image) -> POST /api/vision.php
  -> Event Aggregation -> Incident -> MySQL

Mirrors post_to_vision_api.py but runs the pLitterFloat weights and attaches the
annotated output as evidence via image_path.

The file referenced by image_path must already exist in afternoon/assets/vision/
(see that folder's README.md). The API re-validates the path server-side and
rejects anything outside that folder - the value below is a hint, not a trusted
input.

Run this with C:\\tmp\\cv_venv\\Scripts\\python.exe (the verified CV environment).
Do not attach floating-replay-01.jpg to an incident by hand; running this script
is the honest way to create a detector_run record and link the evidence.
"""
import json
import urllib.request
import torch

WEIGHTS = r"C:\Users\DMI\Downloads\IT_palonbgyut\bangsaen-waste-participant\references\pLitter\weights\pLitterFloat_800x752_to_640x640.pt"
IMAGE = r"C:\Users\DMI\Downloads\IT_palonbgyut\bangsaen-waste-participant\references\test_images\floating_litter_water.jpg"
API_URL = "http://localhost/bangsaen/api/vision.php"
EVIDENCE_PATH = "assets/vision/floating-replay-01.jpg"

model = torch.hub.load('ultralytics/yolov5', 'custom', path=WEIGHTS, force_reload=False, trust_repo=True)
model.conf = 0.25
results = model(IMAGE)
df = results.pandas().xyxy[0]

detected_count = len(df)
max_confidence = round(float(df['confidence'].max()), 4) if detected_count > 0 else None

payload = {
    "camera_name": "Replay-Float-01",
    "location": "ภาพอ้างอิง (Wikimedia Commons, ไม่ใช่กล้องบางแสนจริง) - ทดสอบ pipeline ทางน้ำ",
    "area_type": "water",
    "detected_count": detected_count,
    "max_confidence": max_confidence,
    "source_mode": "replay",
    "record_origin": "detector_run",
    "image_path": EVIDENCE_PATH,
}

print("Real detection ->", payload)

req = urllib.request.Request(
    API_URL,
    data=json.dumps(payload).encode("utf-8"),
    headers={"Content-Type": "application/json"},
    method="POST",
)
with urllib.request.urlopen(req) as resp:
    body = resp.read().decode("utf-8")
    print(f"API responded {resp.status}: {body}")
