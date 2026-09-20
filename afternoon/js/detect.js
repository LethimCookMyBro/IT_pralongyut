// หน้าตรวจจับด้วย AI (detect.html)
//
// ภาพหลักของหน้านี้คือ "วิดีโอที่ AI ตรวจแล้ว" ไม่ใช่ภาพนิ่ง
// วิดีโอถูก render ไว้ล่วงหน้าโดย local_vision/render_demo_videos.py ซึ่งรันโมเดลจริง
// แล้ววาดกรอบจากผลของโมเดลเอง ตัวเลขข้าง ๆ อ่านจาก JSON ที่ script ตัวเดียวกันเขียนไว้
// จึงเป็นตัวเลขของการรันจริง ไม่ใช่ค่าที่พิมพ์ทิ้งไว้ในโค้ด
//
// กฎที่ห้ามผิด: ตัวเลขในแผงด้านข้าง ต้องเป็นของ "สิ่งที่เห็นอยู่บนจอ" เสมอ
// ห้ามเอาตัวเลขของ worker ที่กำลังรัน source อื่นมาโชว์คู่กับวิดีโอที่เลือกอยู่
// สถานะ/เฟรมของ worker จึงอยู่ในส่วนข้อมูลทางเทคนิคเท่านั้น
//
// ไม่มีไฟล์วิดีโอ (เช่นเปิดจาก Railway) → สลับไปแสดงเฟรมตัวอย่างที่ commit ไว้
// พร้อมบอกว่าโหมดวิดีโอทำงานบนเครื่อง local — ต้องไม่ดูเหมือนหน้า error

const POLL_MS = 2000;
const VIDEO_DIR = 'runtime/demo-videos';

// เฟรมสำรองสำหรับตอนไม่มีไฟล์วิดีโอ
// ตัวเลขชุดนี้มาจากการรัน worker จริง บันทึกที่มาไว้ใน assets/vision/README.md
// ห้ามแก้ด้วยมือให้ต่างจากไฟล์นั้น
const SAMPLES = {
    road: {
        image: 'assets/vision/sample-road.jpg',
        source_label: 'ริมถนน',
        location: 'วิดีโออ้างอิง — ริมถนน',
        camera_name: 'REPLAY-ROAD-01',
        model: 'pLitterStreet',
        max_detected_count: 1,
        max_confidence: 0.5162,
        avg_inference_ms: 128.2,
        classes: { Plastic: 1 },
    },
    city: {
        image: 'assets/vision/sample-city.jpg',
        source_label: 'พื้นที่สาธารณะ',
        location: 'วิดีโออ้างอิง — พื้นที่สาธารณะ',
        camera_name: 'REPLAY-CITY-01',
        model: 'pLitterStreet',
        max_detected_count: 2,
        max_confidence: 0.7609,
        avg_inference_ms: 114.0,
        classes: { Plastic: 2 },
    },
    water: {
        image: 'assets/vision/sample-water.jpg',
        source_label: 'ทางน้ำ',
        location: 'วิดีโออ้างอิง — ทางน้ำ',
        camera_name: 'REPLAY-WATER-01',
        model: 'pLitterFloat',
        max_detected_count: 8,
        max_confidence: 0.5268,
        avg_inference_ms: 35.5,
        classes: { debris: 7, styrofoam: 1 },
    },
};

const DEFAULT_SOURCE = 'road';

const els = {
    status: document.getElementById('detector-status'),
    statusText: document.getElementById('detector-status-text'),
    stage: document.getElementById('detect-stage'),
    video: document.getElementById('detect-video'),
    fallbackImg: document.getElementById('detect-fallback-img'),
    badge: document.getElementById('frame-badge'),
    localNote: document.getElementById('detect-local-note'),
    picker: document.getElementById('source-picker'),
    caption: document.getElementById('detect-caption'),
    viewerTitle: document.getElementById('detect-viewer-title'),
    mediaMode: document.getElementById('detect-media-mode'),
    count: document.getElementById('live-count'),
    confidence: document.getElementById('live-confidence'),
    state: document.getElementById('live-state'),
    model: document.getElementById('live-model'),
    classes: document.getElementById('live-classes'),
    techCamera: document.getElementById('tech-camera'),
    techVideo: document.getElementById('tech-video'),
    techFrames: document.getElementById('tech-frames'),
    techHits: document.getElementById('tech-hits'),
    techAvg: document.getElementById('tech-avg'),
    techInference: document.getElementById('tech-inference'),
    techThreshold: document.getElementById('tech-threshold'),
    workerDetail: document.getElementById('worker-detail'),
    workerFrameWrap: document.getElementById('worker-frame-wrap'),
    workerFrame: document.getElementById('worker-frame'),
    workerFrameCaption: document.getElementById('worker-frame-caption'),
    webcamStart: document.getElementById('webcam-start'),
    webcamStop: document.getElementById('webcam-stop'),
    webcamPreview: document.getElementById('webcam-preview'),
    webcamNote: document.getElementById('webcam-note'),
};

let selectedSource = DEFAULT_SOURCE;
let pollTimer = null;
let webcamStream = null;
// เก็บ stats ของแต่ละ source ไว้ ไม่ต้องยิงซ้ำตอนสลับไปมา
const statsCache = {};

function percent(value) {
    if (value === null || value === undefined) return '—';
    return (Number(value) * 100).toFixed(1) + '%';
}

function setStatus(state, label) {
    els.status.dataset.state = state;
    els.statusText.textContent = label;
}

function renderClasses(classes) {
    els.classes.replaceChildren();
    const entries = Object.entries(classes || {}).filter((entry) => Number(entry[1]) > 0);
    if (!entries.length) {
        const li = document.createElement('li');
        li.className = 'muted';
        li.textContent = 'ไม่พบวัตถุ';
        els.classes.appendChild(li);
        return;
    }
    for (const [name, count] of entries) {
        const li = document.createElement('li');
        li.textContent = `${name}: ${count}`;
        els.classes.appendChild(li);
    }
}

function setTech(fields) {
    els.techCamera.textContent = fields.camera ?? '—';
    els.techVideo.textContent = fields.video ?? '—';
    els.techFrames.textContent = fields.frames ?? '—';
    els.techHits.textContent = fields.hits ?? '—';
    els.techAvg.textContent = fields.avg ?? '—';
    els.techInference.textContent = fields.inference ?? '—';
    els.techThreshold.textContent = fields.threshold ?? '—';
}

/* ---------- วิดีโอ (ภาพหลักของหน้า) ---------- */

function showVideo(stats) {
    els.viewerTitle.textContent = `วิดีโอผลตรวจ · ${SAMPLES[selectedSource].source_label}`;
    els.mediaMode.textContent = `เล่นซ้ำ · ${(stats.frames / stats.fps).toFixed(1)} วินาที`;
    els.video.hidden = false;
    els.fallbackImg.hidden = true;
    els.localNote.hidden = true;
    els.badge.hidden = false;
    els.badge.textContent = 'ผลตรวจจาก AI';
    els.badge.classList.add('is-live');

    els.count.textContent = String(stats.max_detected_count);
    els.confidence.textContent = percent(stats.max_confidence);
    els.state.textContent = 'วิดีโอเล่นซ้ำ';
    els.model.textContent = stats.model;
    renderClasses(stats.classes);

    els.caption.textContent =
        `${stats.source_label} · จำนวนแสดงค่าสูงสุดต่อเฟรม`;

    setTech({
        camera: stats.camera_name,
        video: stats.source_video,
        frames: `${stats.frames} เฟรม @ ${stats.fps} fps`,
        hits: `${stats.frames_with_detection} เฟรม`,
        avg: `${stats.avg_detected_count} ชิ้น`,
        inference: `${stats.avg_inference_ms} ms`,
        threshold: String(stats.confidence_threshold),
    });
}

// ไม่มีไฟล์วิดีโอ → เฟรมจริงที่เคยรันไว้ + บอกว่าโหมดวิดีโออยู่บนเครื่อง local
function showFallback() {
    const sample = SAMPLES[selectedSource] ?? SAMPLES[DEFAULT_SOURCE];
    els.viewerTitle.textContent = `ภาพผลตรวจ · ${sample.source_label}`;
    els.mediaMode.textContent = 'ภาพตัวอย่าง';
    els.video.hidden = true;
    els.video.removeAttribute('src');
    els.fallbackImg.hidden = false;
    els.fallbackImg.src = sample.image;
    els.localNote.hidden = false;
    els.badge.hidden = false;
    els.badge.textContent = 'เฟรมตัวอย่าง';
    els.badge.classList.remove('is-live');

    els.count.textContent = String(sample.max_detected_count);
    els.confidence.textContent = percent(sample.max_confidence);
    els.state.textContent = 'ภาพตัวอย่าง';
    els.model.textContent = sample.model;
    renderClasses(sample.classes);
    els.caption.textContent = `${sample.source_label} · ภาพผลตรวจจาก AI`;

    setTech({
        camera: sample.camera_name,
        video: '—',
        frames: '1 เฟรม',
        hits: '1 เฟรม',
        avg: `${sample.max_detected_count} ชิ้น`,
        inference: `${sample.avg_inference_ms} ms`,
        threshold: '0.25',
    });
}

async function loadSource(sourceId) {
    selectedSource = sourceId;
    syncPicker();

    if (statsCache[sourceId] === 'missing') {
        showFallback();
        return;
    }

    let stats = statsCache[sourceId];
    if (!stats) {
        try {
            const res = await fetch(`${VIDEO_DIR}/${sourceId}.json`, { cache: 'no-store' });
            if (!res.ok) throw new Error('no stats');
            stats = await res.json();
            statsCache[sourceId] = stats;
        } catch (err) {
            statsCache[sourceId] = 'missing';
            if (selectedSource !== sourceId) return;
            showFallback();
            return;
        }
    }

    // ตั้ง src ก่อน แล้วรอ event: โหลดไม่ได้เมื่อไหร่ค่อยสลับไป fallback
    // (ไม่เดาล่วงหน้าว่ามีหรือไม่มีไฟล์ ให้ browser เป็นคนบอก)
    if (selectedSource !== sourceId) return;
    els.video.src = `${VIDEO_DIR}/${stats.video}`;
    els.video.load();
    showVideo(stats);
}

els.video.addEventListener('error', () => {
    if (els.video.getAttribute('src')) showFallback();
});

/* ---------- สถานะ worker (ข้อมูลประกอบ ไม่ใช่ภาพหลัก) ---------- */

async function pollDetection() {
    try {
        const response = await fetch('api/live-detection.php', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'HTTP ' + response.status);

        if (!data.available || !data.snapshot) {
            setStatus('offline', 'โหมดตัวอย่าง (ไม่มี AI ทำงานอยู่)');
            els.workerDetail.textContent = data.message || 'ไม่มี worker ทำงานอยู่บนเครื่องนี้';
            els.workerFrameWrap.hidden = true;
            return;
        }

        const snap = data.snapshot;
        const live = data.status === 'live';
        setStatus(live ? 'live' : 'stale', live ? 'Local AI กำลังทำงาน' : 'Local AI หยุดไปเมื่อครู่');
        els.workerDetail.textContent =
            `${snap.source_label} · ${snap.camera_name} · พบ ${snap.detected_count} ชิ้น · `
            + `ความมั่นใจสูงสุด ${percent(snap.max_confidence)} · อัปเดต ${data.age_seconds} วินาทีที่แล้ว`;

        if (data.frame_url) {
            els.workerFrame.src = data.frame_url;
            els.workerFrameCaption.textContent = live
                ? 'เฟรมสดจาก worker'
                : 'เฟรมล่าสุด (ค้าง) จาก worker';
            els.workerFrameWrap.hidden = false;
        } else {
            els.workerFrameWrap.hidden = true;
        }
    } catch (error) {
        setStatus('offline', 'โหมดตัวอย่าง');
        els.workerDetail.textContent = 'อ่านสถานะ worker ไม่สำเร็จ';
        els.workerFrameWrap.hidden = true;
    }
}

function startPolling() {
    if (pollTimer !== null) return;
    pollDetection();
    pollTimer = setInterval(pollDetection, POLL_MS);
}

function stopPolling() {
    if (pollTimer === null) return;
    clearInterval(pollTimer);
    pollTimer = null;
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopPolling();
    else startPolling();
});

/* ---------- Webcam (โบนัส) ---------- */

els.webcamStart.addEventListener('click', async () => {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        els.webcamNote.textContent = 'เบราว์เซอร์นี้ไม่รองรับการเปิดเว็บแคม';
        return;
    }
    try {
        webcamStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
        els.webcamPreview.srcObject = webcamStream;
        els.webcamPreview.hidden = false;
        els.webcamStart.disabled = true;
        els.webcamStop.disabled = false;
        els.webcamNote.textContent = 'กำลังพรีวิวจากกล้องเครื่องนี้ ภาพไม่ถูกส่งเข้า Local AI worker หรือ Cloud';
    } catch (error) {
        els.webcamNote.textContent = 'เปิดเว็บแคมไม่ได้: ' + error.message;
    }
});

els.webcamStop.addEventListener('click', () => {
    if (webcamStream) {
        webcamStream.getTracks().forEach((track) => track.stop());
        webcamStream = null;
    }
    els.webcamPreview.srcObject = null;
    els.webcamPreview.hidden = true;
    els.webcamStart.disabled = false;
    els.webcamStop.disabled = true;
    els.webcamNote.textContent = 'พรีวิวในเบราว์เซอร์เท่านั้น ยังไม่ใช่ input ของ pLitter worker';
});

/* ---------- ตัวเลือกพื้นที่ ---------- */

function syncPicker() {
    for (const card of els.picker.children) {
        card.setAttribute('aria-checked', String(card.dataset.source === selectedSource));
    }
}

els.picker.addEventListener('click', (event) => {
    const card = event.target.closest('.source-card');
    if (!card || !SAMPLES[card.dataset.source] || card.dataset.source === selectedSource) return;
    loadSource(card.dataset.source);
});

loadSource(DEFAULT_SOURCE);
startPolling();
