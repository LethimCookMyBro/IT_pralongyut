# Publishable demo video

Only `water.webm` is published. Road and City stay on the JPG fallback because their source clips came from a supplied Google Drive folder with no verified public redistribution grant.

## Water

- Source: Pexels video 9736659, “Trashes Flowing on a Lake” by T Studio
- Source URL: https://www.pexels.com/video/trashes-flowing-on-a-lake-9736659/
- License: https://www.pexels.com/license/ (free use and modification; attribution is not required)
- Source SHA-256: `F78F05DCFA8D48C21F5D4149C4DF5BAF202467831974CB227FEEC24588E42686`
- Derived WebM SHA-256: `7853EF5689339B7E6F2B641AE4AD68DACD8BA619381924BDFAC2234BEDC0DF0A`
- Output: VP8/WebM, 960×540, 8 fps, 96 frames, 12 seconds, 7,423,227 bytes

Rendered with:

```powershell
C:\tmp\cv_venv\Scripts\python.exe local_vision\render_demo_videos.py `
  --source water --seconds 12 --fps 8 --max-width 960 `
  --out-dir afternoon\assets\demo-videos
```

The WebM is an annotated workshop output. Model weights and third-party detector source are not published here.
