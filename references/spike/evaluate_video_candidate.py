"""Evaluate a local video candidate with the real pLitter model.

This is a screening tool for Phase D source selection only:
- samples evenly across the real video
- runs real pLitterStreet or pLitterFloat inference
- saves annotated sample frames
- writes a JSON summary with counts/classes/confidence/inference time

It does NOT post observations, create incidents, or fake detections.
Run with the verified CV environment:
    C:\\tmp\\cv_venv\\Scripts\\python.exe references\\spike\\evaluate_video_candidate.py --video <path> --model street
"""

from __future__ import annotations

import argparse
import json
import time
from pathlib import Path

import cv2
import torch
from PIL import Image


PROJECT_ROOT = Path(__file__).resolve().parents[2]
WEIGHTS = {
    "street": PROJECT_ROOT / "references" / "pLitter" / "weights" / "pLitterStreet_YOLOv5l.pt",
    "float": PROJECT_ROOT / "references" / "pLitter" / "weights" / "pLitterFloat_800x752_to_640x640.pt",
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run real pLitter inference on sampled video frames.")
    parser.add_argument("--video", required=True, help="Path to a local video file.")
    parser.add_argument("--model", choices=sorted(WEIGHTS), required=True, help="pLitter model to use.")
    parser.add_argument("--samples", type=int, default=5, help="Evenly spaced frames to sample (default: 5).")
    parser.add_argument("--confidence", type=float, default=0.25, help="Model confidence threshold (default: 0.25).")
    parser.add_argument(
        "--out-dir",
        default=None,
        help="Output directory. Default: references/spike/candidate_eval/<video-stem>-<model>",
    )
    return parser.parse_args()


def sample_indexes(frame_count: int, sample_count: int) -> list[int]:
    if frame_count < 1:
        raise ValueError("video reports no frames")
    if sample_count < 1:
        raise ValueError("--samples must be >= 1")
    if sample_count == 1:
        return [frame_count // 2]

    indexes = [
        round(i * (frame_count - 1) / (sample_count - 1))
        for i in range(sample_count)
    ]
    # Very short clips can round to the same frame more than once.
    return list(dict.fromkeys(indexes))


def main() -> int:
    args = parse_args()

    video = Path(args.video).expanduser().resolve()
    if not video.is_file():
        raise SystemExit(f"Video not found: {video}")
    if not (0.0 <= args.confidence <= 1.0):
        raise SystemExit("--confidence must be between 0 and 1")

    weights = WEIGHTS[args.model]
    if not weights.is_file():
        raise SystemExit(f"Weights not found: {weights}")

    out_dir = (
        Path(args.out_dir).expanduser().resolve()
        if args.out_dir
        else PROJECT_ROOT / "references" / "spike" / "candidate_eval" / f"{video.stem}-{args.model}"
    )
    out_dir.mkdir(parents=True, exist_ok=True)

    cap = cv2.VideoCapture(str(video))
    if not cap.isOpened():
        raise SystemExit(f"Could not open video: {video}")

    frame_count = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
    fps = float(cap.get(cv2.CAP_PROP_FPS))
    width = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
    height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
    indexes = sample_indexes(frame_count, args.samples)

    print(f"Loading {args.model} model from {weights}")
    load_started = time.perf_counter()
    model = torch.hub.load(
        "ultralytics/yolov5",
        "custom",
        path=str(weights),
        force_reload=False,
        trust_repo=True,
    )
    model.conf = args.confidence
    model_load_seconds = time.perf_counter() - load_started

    samples: list[dict] = []
    try:
        for ordinal, frame_index in enumerate(indexes, start=1):
            cap.set(cv2.CAP_PROP_POS_FRAMES, frame_index)
            ok, frame_bgr = cap.read()
            if not ok:
                samples.append(
                    {
                        "sample": ordinal,
                        "frame_index": frame_index,
                        "error": "frame read failed",
                    }
                )
                continue

            frame_rgb = cv2.cvtColor(frame_bgr, cv2.COLOR_BGR2RGB)
            infer_started = time.perf_counter()
            results = model(frame_rgb)
            inference_seconds = time.perf_counter() - infer_started

            detections = results.pandas().xyxy[0]
            detected_count = int(len(detections))
            max_confidence = (
                round(float(detections["confidence"].max()), 4)
                if detected_count
                else None
            )
            class_counts = {
                str(name): int(count)
                for name, count in detections["name"].value_counts().to_dict().items()
            }

            rendered_rgb = results.render()[0]
            annotated_name = f"sample-{ordinal:02d}-frame-{frame_index}.jpg"
            annotated_path = out_dir / annotated_name
            Image.fromarray(rendered_rgb).save(annotated_path, quality=92)

            samples.append(
                {
                    "sample": ordinal,
                    "frame_index": frame_index,
                    "timestamp_seconds": round(frame_index / fps, 3) if fps > 0 else None,
                    "detected_count": detected_count,
                    "max_confidence": max_confidence,
                    "classes": class_counts,
                    "inference_seconds": round(inference_seconds, 4),
                    "annotated_image": annotated_name,
                }
            )

            print(
                f"[{ordinal}/{len(indexes)}] frame={frame_index} "
                f"count={detected_count} max_conf={max_confidence} classes={class_counts}"
            )
    finally:
        cap.release()

    valid_samples = [s for s in samples if "detected_count" in s]
    counts = [int(s["detected_count"]) for s in valid_samples]
    summary = {
        "video": str(video),
        "model": args.model,
        "weights": str(weights),
        "confidence_threshold": args.confidence,
        "model_load_seconds": round(model_load_seconds, 4),
        "video_metadata": {
            "frame_count": frame_count,
            "fps": round(fps, 4),
            "width": width,
            "height": height,
            "duration_seconds": round(frame_count / fps, 3) if fps > 0 else None,
        },
        "sample_count_requested": args.samples,
        "sample_count_completed": len(valid_samples),
        "detection_summary": {
            "min": min(counts) if counts else None,
            "max": max(counts) if counts else None,
            "frames_with_detection": sum(1 for c in counts if c > 0),
        },
        "samples": samples,
    }

    summary_path = out_dir / "summary.json"
    summary_path.write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Summary: {summary_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
