"""
Bangsaen Waste Vision - Phase 4 proof-of-concept connector
Real detector -> Raw Observation -> POST /api/vision.php -> Event Aggregation -> Incident -> MySQL

Runs the pLitterStreet YOLOv5l model on a real reference photo, builds the
Raw Observation contract per SPEC_TEMPLATE.md, POSTs it to the live PHP API, and lets the server create/update the Incident.
This is the "replay mode" flow: no CCTV, no live camera - a stand-in image
used to prove the pipeline end-to-end.
"""
import json
import urllib.request
import torch

WEIGHTS = r"C:\Users\DMI\Downloads\IT_palonbgyut\bangsaen-waste-participant\references\pLitter\weights\pLitterStreet_YOLOv5l.pt"
IMAGE = r"C:\Users\DMI\Downloads\IT_palonbgyut\bangsaen-waste-participant\references\test_images\litter_singapore_ecp.jpg"
API_URL = "http://localhost/bangsaen/api/vision.php"

model = torch.hub.load('ultralytics/yolov5', 'custom', path=WEIGHTS, force_reload=False, trust_repo=True)
model.conf = 0.25
results = model(IMAGE)
df = results.pandas().xyxy[0]

detected_count = len(df)
max_confidence = round(float(df['confidence'].max()), 4) if detected_count > 0 else None

payload = {
    "camera_name": "Replay-Street-01",
    "location": "ภาพอ้างอิง (Wikimedia Commons, ไม่ใช่กล้องบางแสนจริง) - ทดสอบ pipeline เท่านั้น",
    "area_type": "land",
    "detected_count": detected_count,
    "max_confidence": max_confidence,
    "source_mode": "replay",
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
