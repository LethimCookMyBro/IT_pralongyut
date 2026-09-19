from pathlib import Path
import cv2, torch, json
root=Path(__file__).resolve().parents[2]
model=torch.hub.load("ultralytics/yolov5","yolov5s",pretrained=True,trust_repo=True)
out={}
for name in ["vid0012.mp4","vid0249.mp4","vid0256.mp4","vid0269.mp4"]:
    p=root/"Video"/name
    cap=cv2.VideoCapture(str(p))
    n=int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
    cap.set(cv2.CAP_PROP_POS_FRAMES,max(0,n//2))
    ok,frame=cap.read(); cap.release()
    if not ok:
        out[name]={"error":"read failed"}; continue
    rgb=cv2.cvtColor(frame,cv2.COLOR_BGR2RGB)
    df=model(rgb).pandas().xyxy[0]
    keep=df[df["confidence"]>=0.25]
    out[name]=[{"name":str(r["name"]),"confidence":round(float(r["confidence"]),3)} for _,r in keep.iterrows()]
print(json.dumps(out,ensure_ascii=False,indent=2))
