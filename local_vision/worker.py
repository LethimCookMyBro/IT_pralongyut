"""Continuous local pLitter worker for Bangsaen Waste Vision.

Loads one model once, samples a replay video at a bounded rate, writes
latest.jpg/latest.json atomically, and periodically POSTs real detections into
the existing PHP incident pipeline.

The worker is intentionally local-only. It is not part of the Railway service.
"""

from __future__ import annotations

import argparse
import json
import os
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

import cv2
import torch


PROJECT_ROOT = Path(__file__).resolve().parents[1]

WEIGHTS = {
    "street": PROJECT_ROOT / "references" / "pLitter" / "weights" / "pLitterStreet_YOLOv5l.pt",
    "float": PROJECT_ROOT / "references" / "pLitter" / "weights" / "pLitterFloat_800x752_to_640x640.pt",
}

SOURCES = {
    "road": {
        "video": PROJECT_ROOT / "Video" / "vid0012.mp4",
        "model_key": "street",
        "model_label": "pLitterStreet",
        "source_label": "ขยะริมถนน",
        "camera_name": "REPLAY-ROAD-01",
        "location": "วิดีโออ้างอิง — ริมถนน",
        "area_type": "land",
    },
    "city": {
        "video": PROJECT_ROOT / "Video" / "vid0269.mp4",
        "model_key": "street",
        "model_label": "pLitterStreet",
        "source_label": "ขยะในเมือง / พื้นที่สาธารณะ",
        "camera_name": "REPLAY-CITY-01",
        "location": "วิดีโออ้างอิง — พื้นที่สาธารณะ",
        "area_type": "land",
    },
    "water": {
        "video": PROJECT_ROOT / "references" / "test_videos" / "water-9736659.mp4",
        "model_key": "float",
        "model_label": "pLitterFloat",
        "source_label": "ขยะในน้ำ",
        "camera_name": "REPLAY-WATER-01",
        "location": "วิดีโออ้างอิง — ทางน้ำ",
        "area_type": "water",
    },
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run the local pLitter replay worker.")
    parser.add_argument("--source", choices=sorted(SOURCES), default="road")
    parser.add_argument("--target-hz", type=float, default=3.0, help="Inference rate for replay video.")
    parser.add_argument("--post-interval", type=float, default=4.0, help="Seconds between observation POSTs.")
    parser.add_argument("--confidence", type=float, default=0.25)
    parser.add_argument("--max-inferences", type=int, default=0, help="0 = run until stopped.")
    parser.add_argument("--no-loop", action="store_true", help="Stop at end of video.")
    parser.add_argument("--no-post", action="store_true", help="Do not POST observations to PHP.")
    parser.add_argument("--api-url", default="http://localhost/bangsaen/api/vision.php")
    parser.add_argument("--web-root", default=None, help="Served afternoon directory. Auto-detects XAMPP.")
    parser.add_argument("--evidence-limit", type=int, default=3)
    return parser.parse_args()


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def resolve_web_root(explicit: str | None) -> Path:
    if explicit:
        return Path(explicit).expanduser().resolve()

    xampp = Path(r"C:\xampp\htdocs\bangsaen")
    if (xampp / "api" / "vision.php").is_file():
        return xampp
    return PROJECT_ROOT / "afternoon"


def atomic_write_bytes(path: Path, payload: bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_name(path.name + ".tmp")
    temp.write_bytes(payload)
    os.replace(temp, path)


def atomic_write_json(path: Path, payload: dict) -> None:
    body = json.dumps(payload, ensure_ascii=False, indent=2).encode("utf-8")
    atomic_write_bytes(path, body)


def atomic_write_jpeg(path: Path, frame_bgr) -> None:
    ok, encoded = cv2.imencode(".jpg", frame_bgr, [int(cv2.IMWRITE_JPEG_QUALITY), 90])
    if not ok:
        raise RuntimeError("could not encode annotated frame")
    atomic_write_bytes(path, encoded.tobytes())


def post_observation(api_url: str, payload: dict) -> tuple[int | None, str | None]:
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    request = urllib.request.Request(
        api_url,
        data=body,
        method="POST",
        headers={"Content-Type": "application/json; charset=utf-8"},
    )
    try:
        with urllib.request.urlopen(request, timeout=5) as response:
            response.read()
            return int(response.status), None
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")
        return int(exc.code), detail[:240]
    except Exception as exc:
        return None, str(exc)[:240]


def prune_evidence(evidence_dir: Path, source_id: str, keep: int) -> None:
    keep = max(1, keep)
    matches = sorted(
        evidence_dir.glob(f"live-{source_id}-*.jpg"),
        key=lambda p: p.stat().st_mtime,
        reverse=True,
    )
    for old in matches[keep:]:
        try:
            old.unlink()
        except OSError:
            pass


def main() -> int:
    args = parse_args()
    if args.target_hz <= 0:
        raise SystemExit("--target-hz must be > 0")
    if args.post_interval < 0:
        raise SystemExit("--post-interval must be >= 0")
    if not 0 <= args.confidence <= 1:
        raise SystemExit("--confidence must be between 0 and 1")
    if args.max_inferences < 0:
        raise SystemExit("--max-inferences must be >= 0")

    source = SOURCES[args.source]
    video_path: Path = source["video"]
    weight_path = WEIGHTS[source["model_key"]]
    if not video_path.is_file():
        raise SystemExit(f"Video not found: {video_path}")
    if not weight_path.is_file():
        raise SystemExit(f"Weights not found: {weight_path}")

    web_root = resolve_web_root(args.web_root)
    runtime_dir = web_root / "runtime" / "vision"
    evidence_dir = web_root / "assets" / "vision"
    runtime_dir.mkdir(parents=True, exist_ok=True)
    evidence_dir.mkdir(parents=True, exist_ok=True)

    cap = cv2.VideoCapture(str(video_path))
    if not cap.isOpened():
        raise SystemExit(f"Could not open video: {video_path}")

    frame_count = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
    fps = float(cap.get(cv2.CAP_PROP_FPS))
    if frame_count < 1 or fps <= 0:
        cap.release()
        raise SystemExit("Video metadata is invalid")

    frame_step = max(1, round(fps / args.target_hz))
    frame_index = 0

    print(f"Loading {source['model_label']} from {weight_path}")
    load_started = time.perf_counter()
    model = torch.hub.load(
        "ultralytics/yolov5",
        "custom",
        path=str(weight_path),
        force_reload=False,
        trust_repo=True,
    )
    model.conf = args.confidence
    print(f"Model loaded in {time.perf_counter() - load_started:.2f}s")
    print(f"Source: {video_path}")
    print(f"Web root: {web_root}")
    print("Open: http://localhost/bangsaen/detect.html")

    last_post_monotonic = 0.0
    last_post_status: int | None = None
    last_post_at: str | None = None
    last_post_error: str | None = None
    inference_count = 0

    try:
        while True:
            if frame_index >= frame_count:
                if args.no_loop:
                    break
                frame_index = 0

            cap.set(cv2.CAP_PROP_POS_FRAMES, frame_index)
            ok, frame_bgr = cap.read()
            if not ok:
                if args.no_loop:
                    break
                frame_index = 0
                continue

            frame_rgb = cv2.cvtColor(frame_bgr, cv2.COLOR_BGR2RGB)
            started = time.perf_counter()
            results = model(frame_rgb)
            inference_ms = (time.perf_counter() - started) * 1000

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
            annotated_bgr = cv2.cvtColor(rendered_rgb, cv2.COLOR_RGB2BGR)
            atomic_write_jpeg(runtime_dir / "latest.jpg", annotated_bgr)

            now_epoch = time.time()
            now_iso = utc_now_iso()
            should_post = (
                not args.no_post
                and detected_count > 0
                and (
                    last_post_monotonic == 0.0
                    or time.monotonic() - last_post_monotonic >= args.post_interval
                )
            )

            if should_post:
                evidence_name = f"live-{args.source}-{int(now_epoch * 1000)}.jpg"
                evidence_path = evidence_dir / evidence_name
                atomic_write_jpeg(evidence_path, annotated_bgr)

                payload = {
                    "camera_name": source["camera_name"],
                    "location": source["location"],
                    "area_type": source["area_type"],
                    "detected_count": detected_count,
                    "max_confidence": max_confidence,
                    "source_mode": "replay",
                    "record_origin": "detector_run",
                    "image_path": f"assets/vision/{evidence_name}",
                }
                last_post_status, last_post_error = post_observation(args.api_url, payload)
                last_post_monotonic = time.monotonic()
                last_post_at = utc_now_iso()
                prune_evidence(evidence_dir, args.source, args.evidence_limit)

            snapshot = {
                "schema_version": 1,
                "generated_at": now_iso,
                "generated_at_epoch": now_epoch,
                "source_id": args.source,
                "source_label": source["source_label"],
                "camera_name": source["camera_name"],
                "location": source["location"],
                "area_type": source["area_type"],
                "model": source["model_label"],
                "detected_count": detected_count,
                "max_confidence": max_confidence,
                "classes": class_counts,
                "inference_ms": round(inference_ms, 1),
                "last_post_status": last_post_status,
                "last_post_at": last_post_at,
                "last_post_error": last_post_error,
            }
            atomic_write_json(runtime_dir / "latest.json", snapshot)

            inference_count += 1
            print(
                f"[{inference_count}] frame={frame_index} count={detected_count} "
                f"max_conf={max_confidence} infer={inference_ms:.1f}ms "
                f"post={last_post_status if should_post else '-'}"
            )

            if args.max_inferences and inference_count >= args.max_inferences:
                break

            frame_index += frame_step
            time.sleep(max(0.0, 1.0 / args.target_hz))
    except KeyboardInterrupt:
        print("Stopped.")
    finally:
        cap.release()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
