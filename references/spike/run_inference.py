"""Spike: 1 real image -> pLitter pretrained YOLOv5l (street) -> real bounding boxes.
Loads the model architecture via torch.hub from ultralytics/yolov5 (classic hub loading
for a *custom* .pt checkpoint) instead of pLitter's vendored Yolov5_StrongSORT_OSNet
submodule, which is only needed for video tracking, not single-image detection.
"""
import time
import torch

WEIGHTS = r"C:\Users\DMI\Downloads\IT_palonbgyut\bangsaen-waste-participant\references\pLitter\weights\pLitterStreet_YOLOv5l.pt"
IMAGE = r"C:\Users\DMI\Downloads\IT_palonbgyut\bangsaen-waste-participant\references\test_images\litter_singapore_ecp.jpg"
OUT = r"C:\Users\DMI\Downloads\IT_palonbgyut\bangsaen-waste-participant\references\spike\output_detected.jpg"

print("Loading model via torch.hub (ultralytics/yolov5, custom weights)...")
t0 = time.time()
model = torch.hub.load('ultralytics/yolov5', 'custom', path=WEIGHTS, force_reload=False, trust_repo=True)
load_time = time.time() - t0
print(f"Model loaded in {load_time:.1f}s")

model.conf = 0.25  # default confidence threshold

print("Running inference on", IMAGE)
t0 = time.time()
results = model(IMAGE)
infer_time = time.time() - t0
print(f"Inference done in {infer_time:.2f}s")

df = results.pandas().xyxy[0]
print(f"\nDetections: {len(df)}")
print(df[['name', 'confidence', 'xmin', 'ymin', 'xmax', 'ymax']].to_string())

results.save(save_dir=r"C:\Users\DMI\Downloads\IT_palonbgyut\bangsaen-waste-participant\references\spike\out")
print("\nSaved annotated output to references/spike/out/")
