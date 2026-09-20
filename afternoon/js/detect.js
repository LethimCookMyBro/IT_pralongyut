// Phase D — live status viewer for the local pLitter worker.
// The worker owns inference. This page only polls a fixed read-only PHP endpoint.


// หน้านี้แสดงได้สองโหมด และต้องบอกให้ชัดเสมอว่ากำลังดูโหมดไหน:
//   1) เฟรมสด - มี worker รันอยู่บนเครื่อง local
//   2) ตัวอย่างผลลัพธ์ - ไม่มี worker (เช่นตอนเปิดจาก Railway) แสดงภาพที่ worker
//      เคยรันไว้จริง เพื่อให้เห็นว่าโมเดลให้ผลอย่างไร ไม่ใช่ภาพจำลองและไม่ใช่ของสด

const POLL_MS = 800;

// ตัวเลขทั้งหมดอ่านจาก runtime/vision/latest.json ของเฟรมนั้น ๆ ตอนรัน worker จริง
// ที่มา + คำสั่งที่ใช้รัน บันทึกไว้ใน assets/vision/README.md - ห้ามแก้ตัวเลขด้วยมือ
const SAMPLES = {
    road: {
        image: 'assets/vision/sample-road.jpg',
        source_label: 'ขยะริมถนน',
        location: 'วิดีโออ้างอิง — ริมถนน',
        camera_name: 'REPLAY-ROAD-01',
        model: 'pLitterStreet',
        detected_count: 1,
        max_confidence: 0.5162,
        inference_ms: 128.2,
        classes: { Plastic: 1 },
    },
    city: {
        image: 'assets/vision/sample-city.jpg',
        source_label: 'ขยะในเมือง / พื้นที่สาธารณะ',
        location: 'วิดีโออ้างอิง — พื้นที่สาธารณะ',
        camera_name: 'REPLAY-CITY-01',
        model: 'pLitterStreet',
        detected_count: 2,
        max_confidence: 0.7609,
        inference_ms: 114.0,
        classes: { Plastic: 2 },
    },
    water: {
        image: 'assets/vision/sample-water.jpg',
        source_label: 'ขยะในน้ำ',
        location: 'วิดีโออ้างอิง — ทางน้ำ',
        camera_name: 'REPLAY-WATER-01',
        model: 'pLitterFloat',
        detected_count: 8,
        max_confidence: 0.5268,
        inference_ms: 35.5,
        classes: { debris: 7, styrofoam: 1 },
    },
};

const DEFAULT_SOURCE = 'road';

const els = {
    status: document.getElementById('detector-status'),
    statusText: document.getElementById('detector-status-text'),
    frame: document.getElementById('live-frame'),
    frameEmpty: document.getElementById('detect-frame-empty'),
    frameBadge: document.getElementById('frame-badge'),
    panelTitle: document.getElementById('panel-title'),
    picker: document.getElementById('source-picker'),
    caption: document.getElementById('detect-caption'),
    source: document.getElementById('live-source'),
    model: document.getElementById('live-model'),
    count: document.getElementById('live-count'),
    confidence: document.getElementById('live-confidence'),
    inference: document.getElementById('live-inference'),
    age: document.getElementById('live-age'),
    classes: document.getElementById('live-classes'),
    postState: document.getElementById('live-post-state'),
    webcamStart: document.getElementById('webcam-start'),
    webcamStop: document.getElementById('webcam-stop'),
    webcamPreview: document.getElementById('webcam-preview'),
    webcamNote: document.getElementById('webcam-note'),
};

let pollTimer = null;
let lastFrameUrl = '';
let webcamStream = null;
// source ที่ผู้ใช้เลือกดูตัวอย่าง - ตอน worker ทำงาน จะถูกตั้งตาม source จริงของ worker
let selectedSource = DEFAULT_SOURCE;
let workerIsLive = false;
// กดเลือกเองแล้ว หน้าเว็บต้องไม่ดึงกลับไป source ของ worker ทุกครั้งที่ poll
let userPicked = false;

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
        li.textContent = 'ไม่พบวัตถุในเฟรมล่าสุด';
        els.classes.appendChild(li);
        return;
    }
    for (const entry of entries) {
        const li = document.createElement('li');
        li.textContent = entry[0] + ': ' + entry[1];
        els.classes.appendChild(li);
    }
}

// ไม่มี worker -> ยังต้องเห็นว่าโมเดลทำอะไรได้ จึงแสดงผลจริงที่เคยรันไว้
// ทุกช่องที่แสดงตัวเลขต้องมาจาก SAMPLES ชุดเดียวกัน ไม่ผสมกับค่าสด
function renderSample(message) {
    const sample = SAMPLES[selectedSource] ?? SAMPLES[DEFAULT_SOURCE];
    workerIsLive = false;
    setStatus('offline', message);

    setFrame(sample.image, 'ตัวอย่างผลลัพธ์');
    els.panelTitle.textContent = 'ตัวอย่างผลการตรวจ';
    els.caption.textContent = sample.camera_name + ' · ' + sample.location
        + ' — เฟรมที่ worker รันไว้จริง (ไม่ใช่ภาพสด)';
    els.source.textContent = sample.source_label + ' · ' + sample.location;
    els.model.textContent = sample.model;
    els.count.textContent = String(sample.detected_count);
    els.confidence.textContent = percent(sample.max_confidence);
    els.inference.textContent = Number(sample.inference_ms).toFixed(1) + ' ms';
    els.age.textContent = 'ไม่ใช่ข้อมูลสด - เป็นตัวอย่าง';
    renderClasses(sample.classes);
    els.postState.textContent = 'ตอน worker ทำงานจริง ผลตรวจจะถูกส่งเข้าคิว '
        + 'แล้วรวมเป็นเหตุให้เจ้าหน้าที่ยืนยันในศูนย์ตรวจสอบ';
}

// ป้ายมุมภาพเป็นตัวบอกว่ากำลังดูของสดหรือตัวอย่าง - ต้องอัปเดตคู่กับ src เสมอ
function setFrame(url, badge) {
    if (url !== lastFrameUrl) {
        lastFrameUrl = url;
        els.frame.src = url;
    }
    els.frame.hidden = false;
    els.frameEmpty.hidden = true;
    els.frameBadge.hidden = false;
    els.frameBadge.textContent = badge;
    els.frameBadge.classList.toggle('is-live', badge === 'เฟรมสด');
}

function renderSnapshot(data) {
    const snap = data.snapshot;
    const state = data.status === 'live' ? 'live' : 'stale';
    workerIsLive = true;
    setStatus(state, data.message);

    // worker เป็นเจ้าของ source จริง - ตัวเลือกบนหน้าเว็บตามมันไป ไม่ใช่สั่งมัน
    if (!userPicked && snap.source_id && SAMPLES[snap.source_id]) {
        selectedSource = snap.source_id;
        syncPicker();
    }
    els.panelTitle.textContent = 'ผลการตรวจล่าสุด';
    els.source.textContent = snap.source_label + ' · ' + snap.location;
    els.model.textContent = snap.model;
    els.count.textContent = String(snap.detected_count);
    els.confidence.textContent = percent(snap.max_confidence);
    els.inference.textContent = snap.inference_ms === null ? '—' : Number(snap.inference_ms).toFixed(1) + ' ms';
    els.age.textContent = data.age_seconds + ' วินาทีที่แล้ว';
    renderClasses(snap.classes);

    if (snap.last_post_status === 201) {
        els.postState.textContent = 'ส่ง observation ล่าสุดสำเร็จ' + (snap.last_post_at ? ' · ' + snap.last_post_at : '');
    } else if (snap.last_post_error) {
        els.postState.textContent = 'ส่ง observation ไม่สำเร็จ: ' + snap.last_post_error;
    } else {
        els.postState.textContent = 'เฟรมล่าสุดยังไม่ถึงรอบส่ง observation';
    }

    if (data.frame_url) {
        // เฟรมค้างไม่ใช่เฟรมสด - ป้ายต้องต่างกัน ไม่งั้นภาพเก่าดูเหมือนของตอนนี้
        setFrame(data.frame_url, state === 'live' ? 'เฟรมสด' : 'เฟรมล่าสุด (ค้าง)');
    } else {
        els.frame.hidden = true;
        els.frameEmpty.hidden = false;
        els.frameBadge.hidden = true;
    }

    els.caption.textContent = snap.camera_name + ' · ' + snap.location + ' · ' + snap.generated_at;
}

async function pollDetection() {
    try {
        const response = await fetch('api/live-detection.php', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || 'HTTP ' + response.status);
        }
        if (!data.available || !data.snapshot) {
            renderSample(data.message || 'ยังไม่มี worker ทำงานอยู่ - แสดงตัวอย่างผลลัพธ์');
        } else if (userPicked && data.snapshot.source_id !== selectedSource) {
            // worker รัน source อื่นอยู่ - แสดงตัวอย่างของอันที่ผู้ใช้เลือก
            // ห้ามผสมภาพของ source หนึ่งกับตัวเลขของอีก source
            renderSample('worker กำลังรัน ' + data.snapshot.source_label + ' อยู่ - นี่คือตัวอย่างของวิดีโอที่เลือก');
            workerIsLive = true;  // renderSample ตั้ง false ไว้ ต้องตั้งกลับหลังเรียก
        } else {
            renderSnapshot(data);
        }
    } catch (error) {
        renderSample('อ่านสถานะ detector ไม่สำเร็จ - แสดงตัวอย่างผลลัพธ์');
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

function syncPicker() {
    for (const card of els.picker.children) {
        card.setAttribute('aria-checked', String(card.dataset.source === selectedSource));
    }
}

els.picker.addEventListener('click', (event) => {
    const card = event.target.closest('.source-card');
    if (!card || !SAMPLES[card.dataset.source]) return;
    selectedSource = card.dataset.source;
    userPicked = true;
    syncPicker();
    // เปลี่ยน source ที่หน้าเว็บไม่ได้ - worker เป็นคนเลือกตอนสั่งรัน
    // จึงแสดงตัวอย่างของ source ที่กด แล้วรอบ poll ถัดไปจะดึงกลับไปที่ของสดเอง
    renderSample('ตัวอย่างของวิดีโอที่เลือก'
        + (workerIsLive ? ' - worker กำลังรัน source อื่นอยู่' : ''));
});

syncPicker();
startPolling();
