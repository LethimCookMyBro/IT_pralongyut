import time
import torch

WEIGHTS = r"C:\Users\DMI\Downloads\IT_palonbgyut\bangsaen-waste-participant\references\pLitter\weights\pLitterFloat_800x752_to_640x640.pt"
IMAGE = r"C:\Users\DMI\Downloads\IT_palonbgyut\bangsaen-waste-participant\references\test_images\floating_litter_water.jpg"
OUT_DIR = r"C:\Users\DMI\Downloads\IT_palonbgyut\bangsaen-waste-participant\references\spike\out_float"

t0 = time.time()
model = torch.hub.load('ultralytics/yolov5', 'custom', path=WEIGHTS, force_reload=False, trust_repo=True)
print(f"Model loaded in {time.time() - t0:.1f}s")
model.conf = 0.25

print(f"Running inference on {IMAGE}")
t1 = time.time()
results = model(IMAGE)
print(f"Inference done in {time.time() - t1:.2f}s")

df = results.pandas().xyxy[0]
print(f"\nDetections: {len(df)}")
print(df[['name', 'confidence', 'xmin', 'ymin', 'xmax', 'ymax']].to_string())

results.save(save_dir=OUT_DIR)
