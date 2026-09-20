"""Render annotated demo videos from the locked reference clips.

Runs the same pLitter weights the live worker uses, draws the model's own boxes
on every processed frame, and writes a WebM/VP8 clip that Chrome plays natively
plus a JSON sidecar holding the aggregate numbers measured during that render.

Everything the detect page shows about a video comes from this run. Nothing here
invents a detection, a count, or a confidence value -- if the model saw nothing,
the sidecar says zero.

Local-only. Output lands in afternoon/runtime/demo-videos/, which is gitignored:
the source clips are not ours to publish and the outputs are large.

    C:\\tmp\\cv_venv\\Scripts\\python.exe local_vision\\render_demo_videos.py
    C:\\tmp\\cv_venv\\Scripts\\python.exe local_vision\\render_demo_videos.py --source water --seconds 12
"""

from __future__ import annotations

import argparse
import time
from pathlib import Path

import cv2
import torch

from worker import SOURCES, WEIGHTS, PROJECT_ROOT, resolve_web_root, atomic_write_json

# WebM/VP8 ไม่ใช่ MP4/H.264 โดยตั้งใจ:
# OpenCV build นี้เข้ารหัส H.264 ไม่ได้ (ต้องมี openh264 DLL แยก) และ mp4v ที่มันเขียนได้
# Chrome ไม่เล่น ส่วน WebM/VP8 เล่นได้ทันทีทุก Chrome/Edge/Firefox โดยไม่ต้องลงอะไรเพิ่ม
# ทดสอบแล้วว่าเขียนและอ่านกลับได้ครบเฟรมในเครื่องนี้
VIDEO_FOURCC = "VP80"
VIDEO_EXT = "webm"

# กล่องที่วาดใช้สีเดียวกับ accent ของเว็บ (teal) เพื่อให้หน้า detect ดูเป็นชุดเดียวกัน
BOX_BGR = (110, 118, 15)
LABEL_BGR = (255, 255, 255)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Render annotated pLitter demo videos.")
    parser.add_argument("--source", choices=sorted(SOURCES) + ["all"], default="all")
    parser.add_argument("--seconds", type=float, default=15.0, help="Length of clip to render.")
    parser.add_argument("--fps", type=float, default=8.0, help="Output FPS (also the inference rate).")
    parser.add_argument("--confidence", type=float, default=0.25)
    parser.add_argument("--max-width", type=int, default=960, help="Downscale wider frames before writing.")
    parser.add_argument("--out-dir", default=None, help="Defaults to <web root>/runtime/demo-videos")
    return parser.parse_args()


def load_model(model_key: str, confidence: float):
    weight_path = WEIGHTS[model_key]
    if not weight_path.is_file():
        raise SystemExit(f"Weights not found: {weight_path}")
    model = torch.hub.load(
        "ultralytics/yolov5",
        "custom",
        path=str(weight_path),
        force_reload=False,
        trust_repo=True,
    )
    model.conf = confidence
    return model


def draw_detections(frame_bgr, detections) -> None:
    """วาดกล่องจากผลของโมเดลโดยตรง — ไม่มีการเดาหรือเติมกล่องเอง"""
    for row in detections.itertuples():
        x1, y1, x2, y2 = (int(row.xmin), int(row.ymin), int(row.xmax), int(row.ymax))
        cv2.rectangle(frame_bgr, (x1, y1), (x2, y2), BOX_BGR, 2)
        label = f"{row.name} {row.confidence:.2f}"
        (tw, th), _ = cv2.getTextSize(label, cv2.FONT_HERSHEY_SIMPLEX, 0.5, 1)
        cv2.rectangle(frame_bgr, (x1, max(0, y1 - th - 8)), (x1 + tw + 8, y1), BOX_BGR, -1)
        cv2.putText(
            frame_bgr, label, (x1 + 4, max(th, y1 - 5)),
            cv2.FONT_HERSHEY_SIMPLEX, 0.5, LABEL_BGR, 1, cv2.LINE_AA,
        )


def render_one(source_id: str, args: argparse.Namespace, out_dir: Path) -> dict:
    source = SOURCES[source_id]
    video_path: Path = source["video"]
    if not video_path.is_file():
        raise SystemExit(f"Video not found: {video_path}")

    cap = cv2.VideoCapture(str(video_path))
    if not cap.isOpened():
        raise SystemExit(f"Could not open video: {video_path}")

    src_fps = float(cap.get(cv2.CAP_PROP_FPS)) or 30.0
    frame_total = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
    frame_step = max(1, round(src_fps / args.fps))
    wanted_frames = max(1, int(args.seconds * args.fps))

    print(f"\n[{source_id}] {video_path.name} — {source['model_label']}")
    model = load_model(source["model_key"], args.confidence)

    final_out = out_dir / f"{source_id}.{VIDEO_EXT}"
    writer = None

    counts: list[int] = []
    confidences: list[float] = []
    class_totals: dict[str, int] = {}
    inference_times: list[float] = []
    frame_index = 0
    written = 0

    try:
        while written < wanted_frames and frame_index < frame_total:
            cap.set(cv2.CAP_PROP_POS_FRAMES, frame_index)
            ok, frame_bgr = cap.read()
            if not ok:
                break

            if frame_bgr.shape[1] > args.max_width:
                scale = args.max_width / frame_bgr.shape[1]
                frame_bgr = cv2.resize(
                    frame_bgr, (args.max_width, int(frame_bgr.shape[0] * scale))
                )

            started = time.perf_counter()
            results = model(cv2.cvtColor(frame_bgr, cv2.COLOR_BGR2RGB))
            inference_times.append((time.perf_counter() - started) * 1000)

            detections = results.pandas().xyxy[0]
            counts.append(int(len(detections)))
            if len(detections):
                confidences.append(float(detections["confidence"].max()))
                for name, n in detections["name"].value_counts().to_dict().items():
                    class_totals[str(name)] = class_totals.get(str(name), 0) + int(n)

            draw_detections(frame_bgr, detections)

            if writer is None:
                h, w = frame_bgr.shape[:2]
                writer = cv2.VideoWriter(
                    str(final_out), cv2.VideoWriter_fourcc(*VIDEO_FOURCC), args.fps, (w, h)
                )
                if not writer.isOpened():
                    raise SystemExit(f"Could not open writer for {final_out}")
            writer.write(frame_bgr)

            written += 1
            frame_index += frame_step
            if written % 10 == 0:
                print(f"  {written}/{wanted_frames} frames, last count={counts[-1]}")
    finally:
        cap.release()
        if writer is not None:
            writer.release()

    if written == 0:
        raise SystemExit(f"No frames rendered for {source_id}")

    # ตัวเลขทุกตัวมาจากการรันรอบนี้จริง ไม่ได้ตั้งไว้ล่วงหน้า
    stats = {
        "schema_version": 1,
        "source_id": source_id,
        "source_label": source["source_label"],
        "location": source["location"],
        "camera_name": source["camera_name"],
        "area_type": source["area_type"],
        "model": source["model_label"],
        "video": f"{source_id}.{VIDEO_EXT}",
        "source_video": video_path.name,
        "rendered_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "frames": written,
        "fps": args.fps,
        "confidence_threshold": args.confidence,
        "frames_with_detection": sum(1 for c in counts if c > 0),
        "max_detected_count": max(counts) if counts else 0,
        "avg_detected_count": round(sum(counts) / len(counts), 2) if counts else 0,
        "max_confidence": round(max(confidences), 4) if confidences else None,
        "classes": class_totals,
        "avg_inference_ms": round(sum(inference_times) / len(inference_times), 1),
        "video_codec": "VP8/WebM",
    }
    atomic_write_json(out_dir / f"{source_id}.json", stats)

    print(
        f"  done: {written} frames, detections in {stats['frames_with_detection']}, "
        f"max={stats['max_detected_count']}, max_conf={stats['max_confidence']}"
    )
    return stats


def main() -> int:
    args = parse_args()
    out_dir = Path(args.out_dir).resolve() if args.out_dir else resolve_web_root(None) / "runtime" / "demo-videos"
    out_dir.mkdir(parents=True, exist_ok=True)
    print(f"Output: {out_dir}")

    targets = sorted(SOURCES) if args.source == "all" else [args.source]
    rendered = [render_one(s, args, out_dir) for s in targets]

    # index.json ให้หน้าเว็บรู้ว่ามีวิดีโอไหนบ้างโดยไม่ต้องยิงหลายครั้ง
    atomic_write_json(out_dir / "index.json", {
        "schema_version": 1,
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "sources": [s["source_id"] for s in rendered],
    })
    print(f"\nRendered {len(rendered)} video(s) into {out_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
