# Incident Detail — Evidence Image + Readable Layout — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use `superpowers:executing-plans` to implement
> task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `afternoon/incident.html` from a flat field dump into a page where an officer
sees *what / where / what evidence / what to do next* within seconds, and show the real
detector evidence image when the incident has one.

**Architecture:** No backend architecture change. Event Aggregation, the Incident workflow and
incident-level Human Review are untouched. The only data-model change is one nullable column,
`vision_observations.image_path`, because an image is evidence of *one detection event*, not of
an incident. `api/vision.php` POST accepts an optional `image_path` and validates it down to a
filename inside `afternoon/assets/vision/`. `api/vision-incident.php` gains a read-only
`evidence` block (newest observations that have an image). The page is rewritten in HTML/CSS/JS
with no new dependency.

**Tech Stack:** PHP 8 (PDO/MySQL), plain HTML, CSS custom properties, vanilla JS. No build step,
no framework.

**Spec:** the user brief in this conversation (Incident Detail redesign + evidence image),
plus `CLAUDE.md` for project-wide rules.

## Global Constraints

- Do not change backend architecture, Event Aggregation, or the Incident review workflow.
- Human review stays at INCIDENT level. Never introduce observation-level review.
- Migrations are additive only — `ALTER TABLE` only. No `DROP`, `TRUNCATE`, or DB reset.
- No fabricated evidence images. Only detector outputs already in `references/` may be used,
  and only attached to the observation that actually produced them.
- Never store binary or base64 image data in MySQL. The DB stores a relative path only.
- Never accept an arbitrary path from the browser. Validate to `assets/vision/<safe-name>.<ext>`.
- Light theme only. One primary accent (teal `--color-primary`); status colors for badges and
  small inline text only — never as card/row backgrounds or a second primary-looking button.
- Reuse existing tokens/components (`--space-*`, `--text-*`, `.card`, `.btn*`, `.status-badge`,
  `.replay-banner`). No new one-off styles where a token exists.
- Thai first; English technical terms only as secondary `.term-en` notes.
- No horizontal page scroll at 390px.
- Preserve the list API contract: `page` / `per_page` in, `pagination` object out.
- Mutating actions keep going through `confirmAction()` (`<dialog>`), never `alert()`/`confirm()`.
- Do not touch `api/reports_buggy.php` or `lib/validate_buggy.php` (intentional training copies).
- Source tree and `C:\xampp\htdocs\bangsaen` must end up identical.

## Verified provenance (established during research — do not re-derive)

| Artifact | Detector | Result | Where it landed |
|---|---|---|---|
| `references/spike/out/litter_singapore_ecp.jpg` | `pLitterStreet_YOLOv5l.pt` via `run_inference.py` | 5 boxes (Pile 0.34, Plastic 0.49/0.59/0.59/0.32), max **0.59** | `post_to_vision_api.py` POSTed it as `Replay-Street-01`, `detected_count=5`, `max_confidence=0.5886` → observations **3, 11, 12** → incidents **3** and **7** |
| `references/spike/out_float/floating_litter_water.jpg` | `pLitterFloat_800x752_to_640x640.pt` via `run_inference_float.py` | annotated, but counts were **never persisted** and it was **never POSTed** | **no observation** — must not be attached to anything |

Source photos are Wikimedia Commons (`references/test_images/ATTRIBUTION*.txt`): the street
image is CC BY 2.0 (vaidehi shah), the floating image CC BY-SA 4.0 (Jemir Shamir). Neither is a
Bangsaen municipal camera.

Incidents **1, 2, 56–70** are manual test rows / `seed_demo.php` fiction. They get **no** image —
they are the "no evidence" path and must keep working.

## File Structure

| File | Responsibility |
|---|---|
| `afternoon/lib/vision_evidence.php` (create) | The single place that decides whether a string is an acceptable evidence image path. Used on write *and* on read. |
| `afternoon/assets/vision/*.jpg` (create) | Web-served copies of real detector outputs, resized for the page. |
| `afternoon/assets/vision/README.md` (create) | Provenance: which detector run produced which file, and the source photo licence. |
| `afternoon/sql/migrations/002_vision_observation_image.sql` (create) | Additive `ALTER TABLE` + provenance-matched backfill. |
| `afternoon/sql/schema.sql` (modify) | Same column for fresh installs. |
| `afternoon/api/vision.php` (modify) | POST accepts optional `image_path`, validated, stored on the observation. |
| `afternoon/api/vision-observations.php` (modify) | Return `image_path`, re-validated on read. |
| `afternoon/api/vision-incident.php` (modify) | Add read-only `evidence` array (newest images first, max 5). |
| `afternoon/incident.html` (modify) | New document structure / information hierarchy. |
| `afternoon/js/incident.js` (modify) | Render the new structure; evidence gallery; unchanged action flow. |
| `afternoon/css/style.css` (modify) | Replace the `Incident detail page` block. |
| `afternoon/tests/run_tests.php` (modify) | Unit tests for the path validator. |
| `afternoon/tests/vision_integration_test.php` (modify) | End-to-end tests for `image_path` through the real API. |
| `references/spike/post_to_vision_api_float.py` (create) | Lets the float image be ingested honestly by whoever has torch. |
| `CLAUDE.md` (modify) | Two short factual lines about the new column and asset folder. |

---

### Task 1: Evidence path validator

**Files:**
- Create: `afternoon/lib/vision_evidence.php`
- Modify: `afternoon/tests/run_tests.php`

**Interfaces:**
- Produces: `VISION_EVIDENCE_PREFIX` (string `'assets/vision/'`),
  `vision_evidence_root(): string` (absolute dir),
  `validate_evidence_image_path(mixed $value): ?string` — returns the normalized relative path,
  `null` when the value is absent/empty, throws `InvalidArgumentException` prefixed
  `image_path:` when present but unacceptable.
  `evidence_path_or_null(mixed $value): ?string` — never throws; returns `null` for anything
  unacceptable. Used on read so a bad legacy row can't reach the browser.
- Consumes: nothing.

- [ ] **Step 1: Write the failing tests** — append to `afternoon/tests/run_tests.php`, after the
      search-term block and before the final `echo`:

```php
// ---------- PHASE F: evidence image path ----------

function expect_image_error($value): void
{
    try {
        validate_evidence_image_path($value);
    } catch (InvalidArgumentException $e) {
        if (!str_starts_with($e->getMessage(), "image_path")) {
            throw new Exception("error should start with 'image_path' got: " . $e->getMessage());
        }
        return;
    }
    throw new Exception("expected InvalidArgumentException for " . var_export($value, true));
}

check("absent image_path is null", function () {
    assert(validate_evidence_image_path(null) === null);
    assert(validate_evidence_image_path("") === null);
    assert(validate_evidence_image_path("   ") === null);
});

check("known evidence file is accepted", function () {
    $ok = validate_evidence_image_path("assets/vision/street-replay-01.jpg");
    assert($ok === "assets/vision/street-replay-01.jpg", var_export($ok, true));
});

check("path traversal is rejected", function () {
    foreach ([
        "assets/vision/../../lib/db.php",
        "assets/vision/..%2F..%2Fdb.php",
        "../assets/vision/street-replay-01.jpg",
        "assets/vision/sub/street-replay-01.jpg",
        "assets/vision/.hidden.jpg",
    ] as $bad) {
        expect_image_error($bad);
    }
});

check("absolute and windows paths are rejected", function () {
    foreach ([
        "/etc/passwd",
        "C:\\xampp\\htdocs\\bangsaen\\index.html",
        "assets\\vision\\street-replay-01.jpg",
        "//host/share/x.jpg",
    ] as $bad) {
        expect_image_error($bad);
    }
});

check("urls and javascript: are rejected", function () {
    foreach ([
        "javascript:alert(1)",
        "JavaScript:alert(1)",
        "data:image/png;base64,AAAA",
        "http://evil.example/x.jpg",
        "https://evil.example/assets/vision/x.jpg",
        "//evil.example/x.jpg",
    ] as $bad) {
        expect_image_error($bad);
    }
});

check("wrong folder or extension is rejected", function () {
    foreach ([
        "assets/other/street-replay-01.jpg",
        "street-replay-01.jpg",
        "assets/vision/shell.php",
        "assets/vision/street-replay-01.jpg.php",
        "assets/vision/street-replay-01.svg",
    ] as $bad) {
        expect_image_error($bad);
    }
});

check("missing file is rejected", fn() => expect_image_error("assets/vision/does-not-exist.jpg"));

check("null byte and non-string are rejected", function () {
    expect_image_error("assets/vision/street-replay-01.jpg\0.php");
    expect_image_error(123);
    expect_image_error(true);
    expect_image_error([]);
});

check("evidence_path_or_null never throws", function () {
    assert(evidence_path_or_null("assets/vision/../../lib/db.php") === null);
    assert(evidence_path_or_null("javascript:alert(1)") === null);
    assert(evidence_path_or_null(null) === null);
    assert(evidence_path_or_null("assets/vision/street-replay-01.jpg") === "assets/vision/street-replay-01.jpg");
});
```

      Add the require at the top of the file, next to the existing two:

```php
require __DIR__ . "/../lib/vision_evidence.php";
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `C:\xampp\php\php.exe afternoon\tests\run_tests.php`
Expected: fatal error — `vision_evidence.php` does not exist yet.

- [ ] **Step 3: Create the asset folder and the real evidence file** (the validator requires the
      file to exist, so this comes before the implementation). Resize the real annotated detector
      output for web use — resizing does not alter the detection result, and the untouched
      original stays in `references/spike/out/`:

```bash
mkdir -p afternoon/assets/vision
python -c "
from PIL import Image
src = r'references/spike/out/litter_singapore_ecp.jpg'
im = Image.open(src)
im.thumbnail((1400, 1400), Image.LANCZOS)
im.save(r'afternoon/assets/vision/street-replay-01.jpg', quality=82, optimize=True)
print(im.size)
"
```

- [ ] **Step 4: Write the implementation** — `afternoon/lib/vision_evidence.php`:

```php
<?php
declare(strict_types=1);

// ภาพหลักฐานของ "การตรวจพบหนึ่งครั้ง" (observation) — ไม่ใช่ของ incident
//
// DB เก็บแค่ relative path เช่น assets/vision/street-replay-01.jpg
// ไม่เก็บ binary/base64 และไม่รับ path จาก browser แบบอิสระ
//
// กติกา: ต้องเป็นไฟล์รูปที่อยู่ในโฟลเดอร์ที่อนุญาตเท่านั้น ชั้นเดียว ไม่มีโฟลเดอร์ย่อย
// ใช้ทั้งตอนเขียน (POST api/vision.php) และตอนอ่าน (กันแถวเก่า/แถวที่ถูกแก้มือหลุดไปที่ browser)

const VISION_EVIDENCE_PREFIX = 'assets/vision/';
const VISION_EVIDENCE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

function vision_evidence_root(): string
{
    return __DIR__ . '/../assets/vision';
}

/**
 * คืน relative path ที่ปลอดภัยแล้ว, null ถ้าไม่ได้ส่งมา,
 * และ throw ถ้าส่งมาแต่ใช้ไม่ได้ (เช่น ../, URL, นามสกุลไม่ใช่รูป, ไฟล์ไม่มีจริง)
 */
function validate_evidence_image_path(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException('image_path: ต้องเป็น string');
    }

    $path = trim($value);
    if ($path === '') {
        return null;
    }

    // null byte ตัดทิ้งทันที — กัน "x.jpg\0.php"
    if (str_contains($path, "\0")) {
        throw new InvalidArgumentException('image_path: มีอักขระที่ไม่อนุญาต');
    }
    // backslash = path แบบ Windows, ไม่รับ
    if (str_contains($path, '\\')) {
        throw new InvalidArgumentException('image_path: ต้องใช้ / เท่านั้น');
    }
    // URL / scheme ทุกชนิด (http:, https:, data:, javascript:) และ protocol-relative
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $path) || str_starts_with($path, '//')) {
        throw new InvalidArgumentException('image_path: ต้องเป็น path ในโปรเจกต์ ไม่ใช่ URL');
    }
    if (!str_starts_with($path, VISION_EVIDENCE_PREFIX)) {
        throw new InvalidArgumentException('image_path: ต้องอยู่ใน ' . VISION_EVIDENCE_PREFIX);
    }

    $name = substr($path, strlen(VISION_EVIDENCE_PREFIX));
    // ชั้นเดียว: ห้ามมี / เหลือ, ห้ามขึ้นต้นด้วยจุด, อนุญาตเฉพาะ a-z A-Z 0-9 . _ -
    // เงื่อนไขนี้ตัด "..", "../x", "sub/x.jpg", ".hidden.jpg" ไปพร้อมกัน
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
        throw new InvalidArgumentException('image_path: ชื่อไฟล์ไม่ถูกต้อง');
    }
    if (str_contains($name, '..')) {
        throw new InvalidArgumentException('image_path: ชื่อไฟล์ไม่ถูกต้อง');
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, VISION_EVIDENCE_EXTENSIONS, true)) {
        throw new InvalidArgumentException(
            'image_path: ต้องเป็นไฟล์รูป (' . implode(', ', VISION_EVIDENCE_EXTENSIONS) . ')'
        );
    }
    // "x.jpg.php" มีนามสกุลสุดท้ายเป็น php อยู่แล้ว จึงตกที่เงื่อนไขบน
    // แต่ตรวจซ้ำว่ามีจุดเดียวสำหรับนามสกุล เพื่อกัน double extension แบบอื่น
    if (substr_count($name, '.') !== 1) {
        throw new InvalidArgumentException('image_path: ชื่อไฟล์ไม่ถูกต้อง');
    }

    // ต้องมีไฟล์จริงในโฟลเดอร์ที่อนุญาต — ไม่ให้ DB ชี้ไปที่ไฟล์ที่ไม่มีอยู่
    $full = vision_evidence_root() . '/' . $name;
    if (!is_file($full)) {
        throw new InvalidArgumentException('image_path: ไม่พบไฟล์นี้ใน ' . VISION_EVIDENCE_PREFIX);
    }

    return VISION_EVIDENCE_PREFIX . $name;
}

/** เวอร์ชันสำหรับตอนอ่าน: ไม่ throw — ค่าที่ใช้ไม่ได้กลายเป็น null */
function evidence_path_or_null(mixed $value): ?string
{
    try {
        return validate_evidence_image_path($value);
    } catch (InvalidArgumentException $e) {
        return null;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `C:\xampp\php\php.exe afternoon\tests\run_tests.php`
Expected: all PASS, `0 failed`.

- [ ] **Step 6: Write the provenance README** — `afternoon/assets/vision/README.md`:

```markdown
# ภาพหลักฐานจากโมเดล (evidence images)

ไฟล์ในโฟลเดอร์นี้คือ **ผลลัพธ์จริงจากโมเดลตรวจจับ** ที่รันในโปรเจกต์นี้
(วาด bounding box โดย YOLOv5 เอง) ไม่ใช่ภาพจำลอง ไม่ใช่ placeholder
และ **ไม่ใช่ภาพจากกล้อง CCTV ของเทศบาลบางแสน**

ย่อขนาดเพื่อใช้บนเว็บเท่านั้น ผลการตรวจไม่ถูกแก้ไข — ต้นฉบับอยู่ที่ `references/spike/`

## street-replay-01.jpg

- โมเดล: `references/pLitter/weights/pLitterStreet_YOLOv5l.pt`
- สคริปต์: `references/spike/run_inference.py`
- ต้นฉบับผลลัพธ์: `references/spike/out/litter_singapore_ecp.jpg`
- ผลตรวจ: 5 กล่อง (Pile 0.34, Plastic 0.49, 0.59, 0.59, 0.32) — ความมั่นใจสูงสุด 0.59
- เข้าสู่ระบบผ่าน: `references/spike/post_to_vision_api.py`
  (`camera_name = Replay-Street-01`, `detected_count = 5`, `max_confidence = 0.5886`)
- ภาพต้นทาง: Litter on Singapore's East Coast Park — vaidehi shah, CC BY 2.0,
  via Wikimedia Commons (ดู `references/test_images/ATTRIBUTION.txt`)

## floating-replay-01.jpg

- โมเดล: `references/pLitter/weights/pLitterFloat_800x752_to_640x640.pt`
- สคริปต์: `references/spike/run_inference_float.py`
- ต้นฉบับผลลัพธ์: `references/spike/out_float/floating_litter_water.jpg`
- ภาพต้นทาง: Nature's Silent Struggle — Jemir Shamir, CC BY-SA 4.0,
  via Wikimedia Commons (ดู `references/test_images/ATTRIBUTION_floating.txt`)
- **สถานะ: ยังไม่ได้ผูกกับ observation ใด** เพราะการรันครั้งนั้นไม่ได้บันทึกจำนวนที่ตรวจพบไว้
  และไม่เคยถูก POST เข้า API
  ถ้าต้องการให้มีเหตุทางน้ำที่มีภาพหลักฐานจริง ให้รัน
  `references/spike/post_to_vision_api_float.py` (ต้องมี torch) ซึ่งจะรันโมเดลใหม่
  แล้วส่งผลจริงพร้อม `image_path` เข้า API
- ห้ามผูกไฟล์นี้กับเหตุที่มีอยู่ด้วยมือ — จะกลายเป็นหลักฐานที่ไม่ตรงกับการตรวจจริง

## กติกา

- เพิ่มไฟล์ที่นี่ได้เฉพาะผลลัพธ์จากการรันโมเดลจริง และต้องบันทึกที่มาไว้ในไฟล์นี้
- ชื่อไฟล์ต้องเป็น `[A-Za-z0-9][A-Za-z0-9._-]*` นามสกุล jpg/jpeg/png/webp
  (ดู `afternoon/lib/vision_evidence.php`)
- `vision_observations.image_path` เก็บแค่ path เช่น `assets/vision/street-replay-01.jpg`
```

- [ ] **Step 7: Add the floating evidence file**

```bash
python -c "
from PIL import Image
im = Image.open(r'references/spike/out_float/floating_litter_water.jpg')
im.thumbnail((1400, 1400), Image.LANCZOS)
im.save(r'afternoon/assets/vision/floating-replay-01.jpg', quality=82, optimize=True)
print(im.size)
"
```

- [ ] **Step 8: Commit**

```bash
git add afternoon/lib/vision_evidence.php afternoon/assets/vision afternoon/tests/run_tests.php
git commit -m "feat(vision): validate evidence image paths, add real detector evidence assets"
```

---

### Task 2: Database column + provenance-matched backfill

**Files:**
- Create: `afternoon/sql/migrations/002_vision_observation_image.sql`
- Modify: `afternoon/sql/schema.sql`

**Interfaces:**
- Produces: `vision_observations.image_path VARCHAR(255) NULL`.
- Consumes: `afternoon/assets/vision/street-replay-01.jpg` from Task 1.

- [ ] **Step 1: Record the row counts the migration must preserve**

```bash
C:/xampp/mysql/bin/mysql.exe -u root --default-character-set=utf8mb4 -e "USE bangsaen_waste; SELECT COUNT(*) AS obs FROM vision_observations; SELECT COUNT(*) AS inc FROM vision_incidents;"
```
Expected: `obs 35`, `inc 19`. Write the numbers down — Step 4 re-checks them.

- [ ] **Step 2: Write the migration** — `afternoon/sql/migrations/002_vision_observation_image.sql`:

```sql
-- 002_vision_observation_image.sql
-- เพิ่มภาพหลักฐานให้ "การตรวจพบหนึ่งครั้ง" (observation)
-- ภาพเป็นหลักฐานของการตรวจครั้งนั้น จึงผูกกับ observation ไม่ใช่ผูกกับ incident แบบแข็ง
--
-- additive เท่านั้น: ALTER TABLE + UPDATE เฉพาะแถวที่พิสูจน์ที่มาได้
-- ไม่มี DROP / TRUNCATE / ไม่แตะข้อมูลเดิมแถวอื่น
--
-- รัน: C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 < afternoon/sql/migrations/002_vision_observation_image.sql

USE bangsaen_waste;

ALTER TABLE vision_observations
    ADD COLUMN image_path VARCHAR(255) NULL AFTER source_mode;

-- Backfill: ผูกภาพกับ "การตรวจพบที่สร้างภาพนั้นจริง ๆ" เท่านั้น
--
-- references/spike/run_inference.py รัน pLitterStreet_YOLOv5l กับ
-- references/test_images/litter_singapore_ecp.jpg ได้ 5 กล่อง ความมั่นใจสูงสุด 0.59
-- แล้ว references/spike/post_to_vision_api.py ส่งผลนั้นเข้า API เป็น
-- camera_name = 'Replay-Street-01', detected_count = 5, max_confidence = 0.5886
--
-- เงื่อนไขด้านล่างจึงเจาะจงลายเซ็นของการรันครั้งนั้น ไม่ใช่ "กล้องนี้ทั้งหมด"
-- แถว seed/demo และแถวทดสอบอื่นจะไม่ถูกแตะ และยังไม่มีภาพ (image_path = NULL) ตามจริง
UPDATE vision_observations
SET image_path = 'assets/vision/street-replay-01.jpg'
WHERE camera_name = 'Replay-Street-01'
  AND source_mode = 'replay'
  AND detected_count = 5
  AND max_confidence = 0.5886
  AND image_path IS NULL;
```

- [ ] **Step 3: Run the migration**

```bash
C:/xampp/mysql/bin/mysql.exe -u root --default-character-set=utf8mb4 < afternoon/sql/migrations/002_vision_observation_image.sql
```
Expected: no output, exit 0.

- [ ] **Step 4: Verify the data survived and only the right rows were touched**

```bash
C:/xampp/mysql/bin/mysql.exe -u root --default-character-set=utf8mb4 --table -e "USE bangsaen_waste; SELECT COUNT(*) AS obs FROM vision_observations; SELECT COUNT(*) AS inc FROM vision_incidents; SELECT id, incident_id, camera_name, detected_count, max_confidence, image_path FROM vision_observations WHERE image_path IS NOT NULL ORDER BY id;"
```
Expected: still `obs 35` / `inc 19`, and exactly observations **3, 11, 12** carry
`assets/vision/street-replay-01.jpg`.

- [ ] **Step 5: Mirror the column into `schema.sql`** so a fresh install matches. In the
      `CREATE TABLE vision_observations` block, insert the column after `source_mode`:

```sql
    source_mode ENUM('replay','camera','cctv') NOT NULL DEFAULT 'replay',
    -- path ของภาพหลักฐาน เช่น assets/vision/street-replay-01.jpg
    -- เก็บแค่ path ไม่เก็บ binary/base64 — เพิ่มใน migrations/002_vision_observation_image.sql
    image_path VARCHAR(255) NULL,
    captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
```

- [ ] **Step 6: Commit**

```bash
git add afternoon/sql/migrations/002_vision_observation_image.sql afternoon/sql/schema.sql
git commit -m "feat(db): add nullable vision_observations.image_path with provenance-matched backfill"
```

---

### Task 3: API — accept, store, and return the evidence path

**Files:**
- Modify: `afternoon/api/vision.php`
- Modify: `afternoon/api/vision-observations.php`
- Modify: `afternoon/api/vision-incident.php`
- Modify: `afternoon/tests/vision_integration_test.php`

**Interfaces:**
- Consumes: `validate_evidence_image_path()`, `evidence_path_or_null()` from Task 1.
- Produces:
  - `POST api/vision.php` accepts optional `image_path`; the returned `observation` object
    carries `image_path` (string or null). Invalid values → HTTP 422, body
    `{"error":"image_path: …"}`. The field is optional — every existing caller keeps working.
  - `GET api/vision-observations.php` — each observation gains `image_path` (string or null).
  - `GET api/vision-incident.php` — new top-level key `evidence`: an array (newest first, max 5)
    of `{observation_id:int, image_path:string, captured_at:string, detected_count:int,
    max_confidence:float|null}`. Empty array when the incident has no images.
    Existing keys `incident` and `aggregation` are unchanged.

- [ ] **Step 1: Write the failing integration tests** — in
      `afternoon/tests/vision_integration_test.php`, add these `check(...)` blocks inside the
      `try { ... }` block, after the `'zero detection is raw observation only'` check:

```php
    check('observation accepts a known evidence image', function () use ($base, $prefix, &$incidentIMG) {
        [$status, $json] = request_json('POST', "$base/vision.php", [
            'camera_name' => $prefix . 'IMG',
            'location' => 'Test Zone',
            'area_type' => 'land',
            'detected_count' => 5,
            'max_confidence' => 0.5,
            'source_mode' => 'replay',
            'image_path' => 'assets/vision/street-replay-01.jpg',
        ]);
        if ($status !== 201) {
            throw new Exception("expected 201, got $status");
        }
        if ($json['observation']['image_path'] !== 'assets/vision/street-replay-01.jpg') {
            throw new Exception('image_path not stored: ' . var_export($json['observation']['image_path'], true));
        }
        $incidentIMG = (int)$json['incident']['id'];
    });

    check('incident detail exposes evidence newest-first', function () use ($base, &$incidentIMG) {
        [$status, $json] = request_json('GET', "$base/vision-incident.php?id=$incidentIMG");
        if ($status !== 200) {
            throw new Exception("expected 200, got $status");
        }
        if (!isset($json['evidence']) || !is_array($json['evidence'])) {
            throw new Exception('missing evidence array');
        }
        if (count($json['evidence']) !== 1) {
            throw new Exception('expected exactly 1 evidence item, got ' . count($json['evidence']));
        }
        if ($json['evidence'][0]['image_path'] !== 'assets/vision/street-replay-01.jpg') {
            throw new Exception('evidence image_path mismatch');
        }
    });

    check('observation drill-down returns image_path', function () use ($base, &$incidentIMG) {
        [$status, $json] = request_json('GET', "$base/vision-observations.php?incident_id=$incidentIMG");
        if ($status !== 200) {
            throw new Exception("expected 200, got $status");
        }
        if ($json['observations'][0]['image_path'] !== 'assets/vision/street-replay-01.jpg') {
            throw new Exception('drill-down image_path mismatch');
        }
    });

    check('incident without image returns empty evidence', function () use ($base, &$incidentC2) {
        [$status, $json] = request_json('POST', "$base/vision.php", [
            'camera_name' => 'NOIMG-' . date('His'),
            'location' => 'Test Zone',
            'area_type' => 'land',
            'detected_count' => 2,
            'max_confidence' => 0.4,
            'source_mode' => 'replay',
        ]);
        if ($status !== 201) {
            throw new Exception("expected 201, got $status");
        }
        if ($json['observation']['image_path'] !== null) {
            throw new Exception('image_path should be null when omitted');
        }
        $incidentC2 = (int)$json['incident']['id'];

        [$status, $json] = request_json('GET', "$base/vision-incident.php?id=$incidentC2");
        if ($status !== 200 || $json['evidence'] !== []) {
            throw new Exception('expected empty evidence array');
        }
    });

    check('dangerous image_path values are rejected with 422', function () use ($base, $prefix) {
        $bad = [
            'assets/vision/../../lib/db.php',
            '../assets/vision/street-replay-01.jpg',
            'javascript:alert(1)',
            'http://evil.example/x.jpg',
            '//evil.example/x.jpg',
            'data:image/png;base64,AAAA',
            'C:\\xampp\\htdocs\\bangsaen\\index.html',
            'assets/vision/shell.php',
            'assets/vision/does-not-exist.jpg',
            'assets/other/street-replay-01.jpg',
        ];
        foreach ($bad as $value) {
            [$status, $json] = request_json('POST', "$base/vision.php", [
                'camera_name' => $prefix . 'BAD',
                'location' => 'Test Zone',
                'area_type' => 'land',
                'detected_count' => 1,
                'max_confidence' => 0.5,
                'source_mode' => 'replay',
                'image_path' => $value,
            ]);
            if ($status !== 422) {
                throw new Exception("expected 422 for " . var_export($value, true) . ", got $status");
            }
            if (!str_starts_with((string)($json['error'] ?? ''), 'image_path')) {
                throw new Exception('error should name image_path, got: ' . var_export($json['error'] ?? null, true));
            }
        }
    });
```

      Declare the two new incident variables next to the existing ones near the top:

```php
$incidentIMG = null;
$incidentC2 = null;
```

      Extend the cleanup in the `finally` block so the extra rows are removed too. Replace the
      two existing `DELETE` statements with:

```php
    $pdo = db();
    foreach (["$prefix%", 'NOIMG-%'] as $pattern) {
        $stmt = $pdo->prepare("DELETE FROM vision_observations WHERE camera_name LIKE :prefix");
        $stmt->execute(['prefix' => $pattern]);
        $stmt = $pdo->prepare("DELETE FROM vision_incidents WHERE camera_name LIKE :prefix");
        $stmt->execute(['prefix' => $pattern]);
    }
```

- [ ] **Step 2: Run the integration test to verify it fails**

Run: `C:\xampp\php\php.exe afternoon\tests\vision_integration_test.php`
Expected: the new checks FAIL (422 not returned / `evidence` key missing); the pre-existing
checks still PASS.

- [ ] **Step 3: Implement in `afternoon/api/vision.php`** — add the require next to the others:

```php
require __DIR__ . '/../lib/vision_evidence.php';
```

      In `validate_vision_observation()`, after the `$source_mode` block and before `return`:

```php
    // ภาพหลักฐานของการตรวจครั้งนี้ — optional
    // validate_evidence_image_path() บังคับให้เหลือแค่ไฟล์รูปในโฟลเดอร์ที่อนุญาต
    $image_path = validate_evidence_image_path($data["image_path"] ?? null);
```

      and extend the returned array with:

```php
        "image_path" => $image_path,
```

      `post_vision()` already catches `InvalidArgumentException` and answers 422, so no change
      there. Update the observation INSERT to carry the new column:

```php
        $observation = $pdo->prepare(
            "INSERT INTO vision_observations
                (incident_id, camera_name, location, area_type, detected_count, max_confidence, source_mode, image_path)
             VALUES
                (:incident_id, :camera_name, :location, :area_type, :detected_count, :max_confidence, :source_mode, :image_path)"
        );
```

      The existing `$observation->execute(['incident_id' => $incident_id] + $clean);` keeps
      working because `$clean` now contains `image_path`.

      The aggregation lookup and both incident SQL statements stay exactly as they are — an
      image never affects how observations group into an incident.

- [ ] **Step 4: Implement in `afternoon/api/vision-observations.php`** — add the require:

```php
require __DIR__ . '/../lib/vision_evidence.php';
```

      Add the column to the SELECT list:

```php
        "SELECT id, incident_id, camera_name, location, area_type,
                detected_count, max_confidence, source_mode, image_path, captured_at
```

      and sanitize it on read inside the existing `array_map` callback, next to the other casts:

```php
        // กันแถวเก่า/แถวที่ถูกแก้มือไม่ให้ path แปลก ๆ หลุดไปถึง browser
        $row['image_path'] = evidence_path_or_null($row['image_path']);
```

- [ ] **Step 5: Implement in `afternoon/api/vision-incident.php`** — add the require:

```php
require __DIR__ . '/../lib/vision_evidence.php';
```

      After the `$row['max_confidence']` cast and before `json_response(...)`:

```php
    // ภาพหลักฐาน: เอา observation ล่าสุดที่มีภาพ (ใหม่ก่อน) ไม่เกิน 5 รายการ
    // อ่านอย่างเดียว ไม่เปลี่ยน contract เดิมของ key incident/aggregation
    $ev = db()->prepare(
        "SELECT id, image_path, captured_at, detected_count, max_confidence
         FROM vision_observations
         WHERE incident_id = :id AND image_path IS NOT NULL
         ORDER BY captured_at DESC, id DESC
         LIMIT 5"
    );
    $ev->execute(['id' => $id]);

    $evidence = [];
    foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $safe = evidence_path_or_null($item['image_path']);
        if ($safe === null) {
            continue;
        }
        $evidence[] = [
            'observation_id' => (int)$item['id'],
            'image_path' => $safe,
            'captured_at' => $item['captured_at'],
            'detected_count' => (int)$item['detected_count'],
            'max_confidence' => $item['max_confidence'] !== null ? (float)$item['max_confidence'] : null,
        ];
    }
```

      and add the key to the response, after `'incident' => $row,`:

```php
        'evidence' => $evidence,
```

- [ ] **Step 6: Run both test suites to verify they pass**

Run: `C:\xampp\php\php.exe afternoon\tests\run_tests.php`
Run: `C:\xampp\php\php.exe afternoon\tests\vision_integration_test.php`
Expected: both report `0 failed`.

- [ ] **Step 7: Confirm the test rows cleaned themselves up**

```bash
C:/xampp/mysql/bin/mysql.exe -u root --default-character-set=utf8mb4 -e "USE bangsaen_waste; SELECT COUNT(*) AS obs FROM vision_observations; SELECT COUNT(*) AS inc FROM vision_incidents;"
```
Expected: back to `obs 35` / `inc 19`.

- [ ] **Step 8: Commit**

```bash
git add afternoon/api/vision.php afternoon/api/vision-observations.php afternoon/api/vision-incident.php afternoon/tests/vision_integration_test.php
git commit -m "feat(api): carry validated evidence image_path through vision endpoints"
```

---

### Task 4: Rewrite the Incident Detail page

**Files:**
- Modify: `afternoon/incident.html`
- Modify: `afternoon/js/incident.js`
- Modify: `afternoon/css/style.css`

**Interfaces:**
- Consumes: `GET api/vision-incident.php` (`incident`, `aggregation`, `evidence`) and
  `GET api/vision-observations.php` (now including `image_path`) from Task 3; `confirmAction`,
  `showToast`, `escapeHtml`, `formatDateTime`, `renderPagination`, `apiQuery`, `icon` from
  `js/ui.js`; `statusStack`, `trendTag`, `trendInfo`, `areaTag`, `fmtConf`, `SOURCE_MODE_LABEL`
  from `js/vision-common.js`.
- Produces: nothing other pages depend on. `vision.html` is untouched this round.

Reading order on the page — this is the whole point of the task:

1. back link
2. title `เหตุที่อาจเป็นจุดขยะ #N` + status badge
3. location line + `camera · พบล่าสุด …`
4. evidence image (or empty state) beside the summary panel
5. summary: พบทั้งหมด / สูงสุด / แนวโน้ม / ความมั่นใจของโมเดล, then the action buttons
6. `รายละเอียดเพิ่มเติม`
7. `ประวัติการตรวจพบ`
8. `<details>` — `ดูรายละเอียดทางเทคนิค`

- [ ] **Step 1: Replace the `<main>` of `afternoon/incident.html`** (keep `<head>`, `<header>`
      and the three `<script>` tags exactly as they are):

```html
    <main>
        <a class="back-link" href="vision.html">
            <svg class="icon" aria-hidden="true"><use href="#icon-arrow-left"></use></svg>กลับไปคิวตรวจสอบ
        </a>

        <div class="msg error hidden" id="load-error" role="alert"></div>

        <article id="incident-detail" hidden>
            <header class="incident-head">
                <div class="incident-head-top">
                    <h1 class="page-title">เหตุที่อาจเป็นจุดขยะ <span class="muted num" id="incident-id"></span></h1>
                    <span id="incident-status"></span>
                </div>
                <p class="incident-place" id="incident-place"></p>
                <p class="incident-subline" id="incident-subline"></p>
            </header>

            <div class="incident-main">
                <section class="evidence" aria-labelledby="evidence-heading">
                    <h2 class="visually-hidden" id="evidence-heading">ภาพหลักฐานจากโมเดล</h2>
                    <div id="evidence-body"></div>
                </section>

                <section class="incident-summary" aria-labelledby="summary-heading">
                    <h2 id="summary-heading">สรุปเหตุ</h2>
                    <dl class="summary-list" id="summary-list"></dl>
                    <div class="summary-action">
                        <p class="muted" id="action-note"></p>
                        <div class="btn-row" id="action-row"></div>
                    </div>
                </section>
            </div>

            <section class="incident-block" aria-labelledby="more-heading">
                <h2 id="more-heading">รายละเอียดเพิ่มเติม</h2>
                <dl class="detail-grid" id="detail-grid"></dl>
            </section>

            <section class="incident-block" aria-labelledby="timeline-heading">
                <h2 id="timeline-heading">ประวัติการตรวจพบ</h2>
                <p class="muted" id="observation-note"></p>
                <ol class="observation-list" id="observation-list"></ol>
                <nav class="pagination" id="observation-pagination"></nav>
            </section>

            <details class="tech-details">
                <summary>ดูรายละเอียดทางเทคนิค</summary>
                <div class="tech-details-body">
                    <p class="muted">
                        <span class="term-en">Possible Waste Incident</span> — เกิดจากการรวมข้อมูลการตรวจพบหลายครั้ง
                        จากจุดเดียวกัน ไม่ใช่การยืนยันว่ามีขยะจริง คนตรวจเป็นผู้ตัดสิน
                    </p>
                    <dl class="detail-grid" id="tech-grid"></dl>
                </div>
            </details>
        </article>
    </main>
```

- [ ] **Step 2: Rewrite `afternoon/js/incident.js`.** Keep `ACTION_DIALOG`, the `action-row`
      click handler and `loadIncident()`'s fetch/error flow as they are — the review flow must
      not change. Replace `renderIncident()` and `loadObservations()`, and add the evidence
      renderer:

```js
// ป้องกันชั้นที่สอง: ถึง API จะกรองมาแล้ว ก็ไม่ยอมใส่ path แปลก ๆ ลงใน src
const EVIDENCE_PATH_RE = /^assets\/vision\/[A-Za-z0-9][A-Za-z0-9._-]*\.(jpg|jpeg|png|webp)$/i;

const safeEvidencePath = (p) => (typeof p === 'string' && EVIDENCE_PATH_RE.test(p) ? p : null);

let currentEvidence = [];

function renderEvidence(evidence) {
    const body = document.getElementById('evidence-body');
    currentEvidence = (Array.isArray(evidence) ? evidence : [])
        .filter((item) => safeEvidencePath(item.image_path));

    if (currentEvidence.length === 0) {
        body.innerHTML = `
            <div class="evidence-empty">
                ${icon('icon-camera')}
                <p class="evidence-empty-title">ยังไม่มีภาพหลักฐานสำหรับเหตุนี้</p>
                <p class="muted">การตรวจพบของเหตุนี้ไม่ได้แนบภาพไว้ — ดูจำนวนที่ตรวจพบและเวลาได้จากประวัติการตรวจพบด้านล่าง</p>
            </div>`;
        return;
    }

    const main = currentEvidence[0];
    const thumbs = currentEvidence.length > 1
        ? `<ul class="evidence-thumbs">${currentEvidence.map((item, i) => `
            <li>
                <button type="button" class="evidence-thumb${i === 0 ? ' is-current' : ''}"
                        data-evidence-index="${i}"
                        aria-label="ดูภาพจากการตรวจพบเวลา ${escapeHtml(formatDateTime(item.captured_at))}">
                    <img src="${escapeHtml(safeEvidencePath(item.image_path))}" alt="">
                </button>
            </li>`).join('')}</ul>`
        : '';

    body.innerHTML = `
        <figure class="evidence-figure">
            <img id="evidence-image" src="${escapeHtml(safeEvidencePath(main.image_path))}"
                 alt="ภาพจากการตรวจพบด้วยโมเดล มีกรอบล้อมวัตถุที่โมเดลตรวจพบ">
            <figcaption>
                <span id="evidence-caption"></span>
                <span class="evidence-disclaimer">
                    ภาพจากการตรวจพบด้วยโมเดลในโหมด Replay · ยังไม่ใช่ภาพจาก CCTV เทศบาลจริง
                </span>
            </figcaption>
        </figure>
        ${thumbs}`;

    setEvidenceCaption(0);
}

function setEvidenceCaption(index) {
    const item = currentEvidence[index];
    if (!item) return;
    document.getElementById('evidence-caption').textContent =
        `ตรวจพบ ${Number(item.detected_count)} ชิ้น · ${formatDateTime(item.captured_at)}`;
}

document.getElementById('evidence-body').addEventListener('click', (event) => {
    const button = event.target.closest('button[data-evidence-index]');
    if (!button) return;
    const index = Number(button.dataset.evidenceIndex);
    const item = currentEvidence[index];
    const path = item && safeEvidencePath(item.image_path);
    if (!path) return;

    document.getElementById('evidence-image').src = path;
    setEvidenceCaption(index);
    document.querySelectorAll('.evidence-thumb').forEach((el, i) => {
        el.classList.toggle('is-current', i === index);
    });
});
```

      `renderIncident()` becomes three grouped renders instead of one 12-row grid. Note the
      confidence row is a normal row — never bigger than location/status/action:

```js
function renderIncident(incident, aggregation, evidence) {
    document.title = `เหตุ #${incident.id} ${incident.location} — Bangsaen Waste Vision`;
    document.getElementById('incident-id').textContent = `#${incident.id}`;
    document.getElementById('incident-status').innerHTML = statusStack(incident);

    document.getElementById('incident-place').textContent = incident.location;
    document.getElementById('incident-subline').textContent =
        `${incident.camera_name} · พบล่าสุด ${formatDateTime(incident.last_seen)}`;

    renderEvidence(evidence);

    // สรุปเหตุ: ตัวเลขที่ใช้ตัดสินใจ 4 ค่า
    const summary = [
        ['พบทั้งหมด', `${Number(incident.observation_count)} ครั้ง`],
        ['จำนวนสูงสุด', `${Number(incident.peak_detected_count)} ชิ้น`],
        ['แนวโน้ม', trendTag(incident)],
        ['ความมั่นใจของโมเดล', escapeHtml(fmtConf(incident.max_confidence)),
            'เป็นค่าประกอบการตรวจสอบ ไม่ใช่ค่าความแม่นยำของระบบ'],
    ];
    document.getElementById('summary-list').innerHTML = summary
        .map(([label, value, note]) => `
            <div class="summary-row">
                <dt>${escapeHtml(label)}</dt>
                <dd>${value}${note ? `<span class="detail-note">${escapeHtml(note)}</span>` : ''}</dd>
            </div>`)
        .join('');

    // รายละเอียดเพิ่มเติม: ข้อมูลระดับรอง
    const details = [
        ['พบครั้งแรก', escapeHtml(formatDateTime(incident.first_seen))],
        ['พบล่าสุด', escapeHtml(formatDateTime(incident.last_seen))],
        ['ประเภทพื้นที่', areaTag(incident.area_type)],
        ['จุด/กล้อง', escapeHtml(incident.camera_name)],
        ['แหล่งภาพ', escapeHtml(SOURCE_MODE_LABEL[incident.source_mode] ?? incident.source_mode),
            'ยังไม่ได้เชื่อมกล้อง/CCTV เทศบาลจริง'],
    ];
    document.getElementById('detail-grid').innerHTML = detailRows(details);

    // ทางเทคนิค: อยู่ใน <details> ไม่แย่งสายตา
    const tech = [
        ['หมายเลขเหตุ', `#${Number(incident.id)}`],
        ['จำนวนที่พบครั้งแรก', `${Number(incident.first_detected_count)} ชิ้น`],
        ['จำนวนที่พบครั้งล่าสุด', `${Number(incident.latest_detected_count)} ชิ้น`],
        ['กติกาการรวมเหตุ', `รวมจากจุดเดิมภายใน ${Number(aggregation.window_minutes)} นาที`,
            'PROTOTYPE / UNCALIBRATED — ยังไม่ได้ปรับค่ากับพื้นที่จริง'],
        ['source mode', escapeHtml(incident.source_mode)],
        ['เวลาที่คนตรวจ', escapeHtml(incident.reviewed_at ? formatDateTime(incident.reviewed_at) : 'ยังไม่ตรวจ')],
        ['เวลาที่ปิดงาน', escapeHtml(incident.resolved_at ? formatDateTime(incident.resolved_at) : 'ยังไม่ปิดงาน')],
    ];
    document.getElementById('tech-grid').innerHTML = detailRows(tech);

    renderActions(incident);
}

function detailRows(items) {
    return items
        .map(([label, value, note]) => `
            <div class="detail-item">
                <dt>${escapeHtml(label)}</dt>
                <dd>${value}${note ? `<span class="detail-note">${escapeHtml(note)}</span>` : ''}</dd>
            </div>`)
        .join('');
}
```

      Update the one call site inside `loadIncident()`:

```js
        renderIncident(data.incident, data.aggregation, data.evidence);
```

      `renderActions()` keeps its current three branches, but the pending note gets shorter so
      it does not compete with the buttons:

```js
        note.textContent = 'ยังไม่ผ่านการตรวจของคน — ดูภาพและข้อมูลด้านบนก่อนตัดสิน';
```

      and the confirmed branch leads with the state:

```js
        note.textContent = 'ยืนยันแล้ว — รอดำเนินการในพื้นที่';
```

      In `loadObservations()`, keep the fetch/pagination logic; only the note and row markup
      change so the timeline reads as `เวลา · พบ N ชิ้น · ความมั่นใจ X%`:

```js
        document.getElementById('observation-note').textContent = observations.length === 0
            ? 'ไม่มีข้อมูลการตรวจพบที่ผูกกับเหตุนี้'
            : 'แต่ละบรรทัดคือการตรวจพบหนึ่งครั้งจากโมเดล เรียงตามเวลา';

        list.innerHTML = observations.length === 0
            ? '<li class="observation-item muted">ไม่มีข้อมูลการตรวจพบ</li>'
            : observations.map((o) => `
                <li class="observation-item">
                    <span class="observation-time">${escapeHtml(formatDateTime(o.captured_at))}</span>
                    <span class="observation-count">พบ <b>${Number(o.detected_count)}</b> ชิ้น</span>
                    <span class="observation-conf">ความมั่นใจ ${escapeHtml(fmtConf(o.max_confidence))}</span>
                    ${safeEvidencePath(o.image_path) ? `<span class="observation-flag">${icon('icon-camera')}มีภาพ</span>` : ''}
                </li>`).join('');
```

- [ ] **Step 3: Replace the `/* ---------- Incident detail page ---------- */` block in
      `afternoon/css/style.css`** (from `.detail-header` through `.observation-meta b`) with the
      block below. `.detail-grid`, `.detail-item`, `.detail-note` and `.observation-list` keep
      their names and look — only the page-level layout is new:

```css
/* ---------- Incident detail page ---------- */
/* ลำดับการอ่าน: หัวเหตุ → สถานที่ → สถานะ → ภาพ → สรุป → action → รายละเอียด → timeline
   ไม่ทำทุกข้อมูลเป็นการ์ดแยกใบ — ข้อมูลที่เกี่ยวกันอยู่ในกล่องเดียวกัน */
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: var(--text-sm);
    color: var(--color-muted);
    text-decoration: none;
    margin-bottom: var(--space-3);
}

.back-link:hover {
    color: var(--color-primary);
}

.incident-head {
    margin-bottom: var(--space-5);
}

.incident-head-top {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
}

.incident-head .page-title {
    margin: 0;
}

/* สถานที่เป็นสิ่งที่ต้องเห็นก่อน ไม่ใช่ field หนึ่งในตาราง */
.incident-place {
    margin: var(--space-3) 0 0;
    font-size: var(--text-lg);
    font-weight: 600;
    color: var(--color-text);
}

.incident-subline {
    margin: var(--space-1) 0 0;
    font-size: var(--text-sm);
    color: var(--color-muted);
}

.incident-main {
    display: grid;
    grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr);
    gap: var(--space-4);
    align-items: start;
    margin-bottom: var(--space-6);
}

/* ---------- ภาพหลักฐาน ---------- */
.evidence-figure {
    margin: 0;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}

.evidence-figure img {
    display: block;
    width: 100%;
    height: auto;           /* ไม่ crop หลักฐาน */
    background: var(--color-bg-alt);
}

.evidence-figure figcaption {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: var(--space-3) var(--space-4);
    border-top: 1px solid var(--color-border);
    font-size: var(--text-sm);
    color: var(--color-text-2);
}

.evidence-disclaimer {
    font-size: var(--text-xs);
    color: var(--color-muted);
}

.evidence-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-2);
    text-align: center;
    padding: var(--space-7) var(--space-4);
    background: var(--color-surface);
    border: 1px dashed var(--color-border-strong);
    border-radius: var(--radius-md);
}

.evidence-empty .icon {
    width: 1.75rem;
    height: 1.75rem;
    color: var(--color-muted);
}

.evidence-empty-title {
    margin: 0;
    font-weight: 600;
    color: var(--color-text-2);
}

.evidence-empty .muted {
    margin: 0;
    max-width: 34ch;
}

.evidence-thumbs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    list-style: none;
    margin: var(--space-2) 0 0;
    padding: 0;
}

.evidence-thumb {
    padding: 0;
    width: 64px;
    height: 48px;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: none;
    overflow: hidden;
    cursor: pointer;
}

.evidence-thumb img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.evidence-thumb.is-current {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 1px var(--color-primary);
}

/* ---------- สรุปเหตุ + ปุ่มตัดสินใจ ---------- */
.incident-summary {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-4);
}

.incident-summary h2 {
    margin: 0 0 var(--space-3);
    font-size: var(--text-md);
}

.summary-list {
    margin: 0;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: var(--space-3);
    padding: var(--space-2) 0;
    border-bottom: 1px solid var(--color-border);
}

.summary-row dt {
    font-size: var(--text-sm);
    color: var(--color-muted);
    margin: 0;
}

.summary-row dd {
    margin: 0;
    font-size: var(--text-base);
    font-weight: 600;
    color: var(--color-text);
    text-align: right;
    font-variant-numeric: tabular-nums;
}

/* หมายเหตุใต้ค่า (เช่น confidence ไม่ใช่ accuracy) ชิดขวาให้ตรงกับค่า */
.summary-row .detail-note {
    text-align: right;
}

.summary-action {
    margin-top: var(--space-4);
}

.summary-action .muted {
    margin: 0 0 var(--space-3);
    font-size: var(--text-sm);
}

.incident-block {
    margin-bottom: var(--space-6);
}

.incident-block h2 {
    font-size: var(--text-md);
    margin: 0 0 var(--space-3);
}

/* ---------- รายละเอียดเพิ่มเติม / ทางเทคนิค ---------- */
.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 0;
    margin: 0;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}

.detail-item {
    padding: var(--space-3) var(--space-4);
    border-bottom: 1px solid var(--color-border);
    border-right: 1px solid var(--color-border);
    min-width: 0;
}

.detail-item dt {
    font-size: var(--text-xs);
    color: var(--color-muted);
    font-weight: 500;
    margin: 0 0 0.15rem;
}

.detail-item dd {
    margin: 0;
    font-size: var(--text-base);
    color: var(--color-text);
    font-weight: 600;
    overflow-wrap: anywhere;
}

/* หมายเหตุอธิบายค่า (เช่น confidence คืออะไร) */
.detail-note {
    display: block;
    font-size: var(--text-xs);
    font-weight: 400;
    color: var(--color-muted);
    margin-top: 0.15rem;
}

.tech-details {
    border-top: 1px solid var(--color-border);
    padding-top: var(--space-4);
    margin-bottom: var(--space-6);
}

.tech-details summary {
    cursor: pointer;
    font-size: var(--text-sm);
    font-weight: 600;
    color: var(--color-primary);
}

.tech-details-body {
    margin-top: var(--space-3);
}

/* ---------- Timeline ของข้อมูลการตรวจพบ ---------- */
.observation-list {
    list-style: none;
    margin: 0;
    padding: 0;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}

.observation-item {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.35rem var(--space-5);
    padding: 0.6rem var(--space-4);
    border-bottom: 1px solid var(--color-border);
    font-size: var(--text-sm);
    color: var(--color-text-2);
}

.observation-item:last-child {
    border-bottom: none;
}

.observation-time {
    font-variant-numeric: tabular-nums;
    font-weight: 600;
    color: var(--color-text);
    flex: 0 0 auto;
}

.observation-count b {
    color: var(--color-text);
    font-variant-numeric: tabular-nums;
}

.observation-conf {
    color: var(--color-muted);
    font-variant-numeric: tabular-nums;
}

.observation-flag {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    color: var(--color-muted);
    font-size: var(--text-xs);
}

.observation-flag .icon {
    width: 0.9em;
    height: 0.9em;
}

/* จอแคบ: เรียงเป็นคอลัมน์เดียว ภาพมาก่อนสรุป ตามลำดับที่ต้องการอ่าน */
@media (max-width: 820px) {
    .incident-main {
        grid-template-columns: 1fr;
    }
}
```

      Check whether `.visually-hidden` already exists in the stylesheet; add it once if missing:

```css
.visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}
```

      Also check the existing `@media (max-width: 768px)` block near line 1352 — it styles
      `.detail-item`. Keep that rule; it still applies.

- [ ] **Step 4: Syntax-check every JS file**

```bash
for f in afternoon/js/*.js; do node --check "$f" || echo "FAILED $f"; done
```
Expected: no output, no `FAILED`.

- [ ] **Step 5: Commit**

```bash
git add afternoon/incident.html afternoon/js/incident.js afternoon/css/style.css
git commit -m "feat(ui): rebuild incident detail around evidence, summary and next action"
```

---

### Task 5: Float connector script

**Files:**
- Create: `references/spike/post_to_vision_api_float.py`

**Interfaces:**
- Consumes: `POST api/vision.php` with `image_path` from Task 3.
- Produces: nothing other code depends on. This script is **not run** as part of this task —
  torch is not installed in the available Python. It exists so the floating evidence file can
  be ingested honestly later instead of being hand-attached to an unrelated incident.

- [ ] **Step 1: Write the script**, mirroring the existing `post_to_vision_api.py`:

```python
"""
Bangsaen Waste Vision - floating-litter connector (replay mode)
Real detector -> Raw Observation (+ evidence image) -> POST /api/vision.php
                -> Event Aggregation -> Incident -> MySQL

Mirrors post_to_vision_api.py but uses the pLitterFloat weights and attaches the
annotated output as evidence via image_path.

The image referenced by image_path must already exist in afternoon/assets/vision/
(see that folder's README.md). The API re-validates the path and rejects anything
outside that folder.

Requires torch. Not run during the incident-detail redesign - no torch in that env,
so no incident currently references floating-replay-01.jpg.
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
    "location": "ภาพอ้างอิงทางน้ำ (Wikimedia Commons, ไม่ใช่กล้องบางแสนจริง) - ทดสอบ pipeline เท่านั้น",
    "area_type": "water",
    "detected_count": detected_count,
    "max_confidence": max_confidence,
    "source_mode": "replay",
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
    print(f"API responded {resp.status}: {resp.read().decode('utf-8')}")
```

- [ ] **Step 2: Byte-compile it to catch syntax errors** (it cannot be executed without torch)

```bash
python -m py_compile references/spike/post_to_vision_api_float.py && echo OK
```
Expected: `OK`.

- [ ] **Step 3: Commit**

```bash
git add references/spike/post_to_vision_api_float.py
git commit -m "feat(spike): add floating-litter connector that attaches its own evidence image"
```

---

### Task 6: Sync, full verification, docs

**Files:**
- Modify: `CLAUDE.md`
- Sync: `C:\xampp\htdocs\bangsaen`

- [ ] **Step 1: Copy the source tree over the live XAMPP copy**

```bash
cp -r afternoon/. /c/xampp/htdocs/bangsaen/
diff -rq afternoon /c/xampp/htdocs/bangsaen && echo "IN SYNC"
```
Expected: `IN SYNC`.

- [ ] **Step 2: Run every test suite**

```bash
python morning/test_waste_logic.py
python morning/analyze.py
C:/xampp/php/php.exe afternoon/tests/run_tests.php
C:/xampp/php/php.exe afternoon/tests/vision_integration_test.php
C:/xampp/php/php.exe afternoon/tests/list_api_test.php
for f in afternoon/js/*.js; do node --check "$f" || echo "FAILED $f"; done
```
Expected: every suite reports `0 failed` / passes; no `FAILED` from `node --check`.

- [ ] **Step 3: Browser-verify the incident WITH evidence** — `incident.html?id=7`
      (observations 11 and 12, both carrying `street-replay-01.jpg`, so the thumbnail strip
      renders). Check: the location reads within a few seconds, the image shows real bounding
      boxes, the status badge and the action buttons are obvious, confidence is labelled
      `ความมั่นใจของโมเดล` with the "ไม่ใช่ค่าความแม่นยำของระบบ" note and is not the largest
      thing on the page, the timeline is readable, technical info is collapsed, no console
      errors, no failed network requests.

- [ ] **Step 4: Browser-verify the incident WITHOUT evidence** — `incident.html?id=56`.
      The empty state `ยังไม่มีภาพหลักฐานสำหรับเหตุนี้` must show and nothing may break.

- [ ] **Step 5: Verify the confirmation dialog still works** — open a `pending` incident, press
      `ปฏิเสธ`, confirm the `<dialog>` appears with focus on cancel, then dismiss with Esc and
      confirm no status change was written.

- [ ] **Step 6: Check 390px has no horizontal overflow** — resize to 390px wide and evaluate
      `document.documentElement.scrollWidth <= window.innerWidth` on both incident 7 and 56.

- [ ] **Step 7: Update `CLAUDE.md`** — two factual additions only, no UI prose.
      Under the `vision_observations:` description add:

```
image_path (VARCHAR(255) NULL) — relative path ของภาพหลักฐานจากโมเดล เช่น
assets/vision/street-replay-01.jpg เพิ่มโดย
afternoon/sql/migrations/002_vision_observation_image.sql (ALTER TABLE, แถวเดิมได้ NULL).
เก็บแค่ path ไม่เก็บ binary/base64. POST api/vision.php รับ image_path แบบ optional และ
validate ด้วย afternoon/lib/vision_evidence.php ให้เหลือเฉพาะไฟล์รูปใน afternoon/assets/vision/
```

      In `## Important files` add:

```
- afternoon/lib/vision_evidence.php — validate/normalize evidence image path
  (allowlist folder + extension, ไฟล์ต้องมีจริง, กัน ../ และ URL/scheme)
- afternoon/assets/vision/ — ภาพผลลัพธ์จริงจาก detector + README.md ที่บันทึกที่มาของแต่ละไฟล์
```

      And to the `api/vision-incident.php` line append:
      `— คืน evidence (observation ล่าสุดที่มีภาพ สูงสุด 5) ด้วย`

- [ ] **Step 8: Re-sync and commit**

```bash
cp -r afternoon/. /c/xampp/htdocs/bangsaen/
diff -rq afternoon /c/xampp/htdocs/bangsaen && echo "IN SYNC"
git add CLAUDE.md docs/superpowers/plans
git commit -m "docs: record vision evidence image_path in CLAUDE.md"
```

---

## Self-Review

**Spec coverage**

| Spec requirement | Task |
|---|---|
| Target layout / reading order | 4 (Steps 1–3) |
| Evidence image, real detector output only | 1, 2 (provenance-matched backfill) |
| `image_path` on `vision_observations`, nullable, additive migration | 2 |
| `schema.sql` matches fresh install | 2 Step 5 |
| No binary/base64 in DB; relative path only | 1 (validator), 2 (VARCHAR) |
| No arbitrary path from browser; validate to allowed folder | 1, 3 |
| Main image = latest observation with an image | 3 Step 5 (`ORDER BY captured_at DESC`) |
| Empty state, no fake placeholder | 4 Step 2 (`renderEvidence`) |
| Both disclaimers under the image | 4 Step 1/2 (`evidence-disclaimer`) |
| Thumbnails 3–5, click to swap main image | 4 Step 2/3 |
| Timeline works without images | 4 Step 2 (`loadObservations`) |
| Information hierarchy (primary / secondary / technical) | 4 Step 2 (`summary` / `details` / `tech`) |
| Confidence wording + secondary note, not oversized | 4 Step 2/3 |
| Status/action states incl. resolved & rejected | 4 Step 2 (`renderActions` branches kept) |
| Confirmation dialog preserved | 4 (handler untouched), 6 Step 5 |
| Thai-first language swaps | 4 Step 1/2 |
| Design system, no gradients, grouped not card-per-field | 4 Step 3 |
| Mobile order, no horizontal scroll at 390px | 4 Step 3, 6 Step 6 |
| Queue thumbnails | intentionally **not done** — explicitly optional, detail page is priority |
| Tests incl. traversal / `javascript:` / external URL | 1 Step 1, 3 Step 1 |
| Incident with and without image both work | 3 Step 1, 6 Steps 3–4 |
| Migration preserves data | 2 Steps 1 & 4 |
| All existing suites pass | 6 Step 2 |
| Browser verification | 6 Steps 3–6 |
| XAMPP in sync | 6 Steps 1 & 8 |
| `CLAUDE.md` short factual update, `SPEC_TEMPLATE.md` untouched | 6 Step 7 |

**Not in scope, per the brief:** image upload, live CCTV, video, maps, AI explanation, heatmaps,
cloud storage, auth, notifications, model settings.

**Type consistency:** `validate_evidence_image_path` / `evidence_path_or_null` are named
identically in Tasks 1, 3; `image_path` is the field name in DB, API and JS throughout;
`evidence[].observation_id` is the only place the observation id is renamed, and it is read
nowhere else.
