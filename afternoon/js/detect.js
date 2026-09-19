// Phase D — live status viewer for the local pLitter worker.
// The worker owns inference. This page only polls a fixed read-only PHP endpoint.

const POLL_MS = 800;

const els = {
    status: document.getElementById('detector-status'),
    statusText: document.getElementById('detector-status-text'),
    frame: document.getElementById('live-frame'),
    frameEmpty: document.getElementById('detect-frame-empty'),
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

function renderOffline(data) {
    setStatus(data.status || 'offline', data.message || 'ตัวตรวจจับไม่ได้ทำงาน');
    els.frame.hidden = true;
    els.frameEmpty.hidden = false;
    els.caption.textContent = data.message || 'ยังไม่มีข้อมูลจาก detector';
    els.source.textContent = '—';
    els.model.textContent = '—';
    els.count.textContent = '—';
    els.confidence.textContent = '—';
    els.inference.textContent = '—';
    els.age.textContent = '—';
    renderClasses({});
    els.postState.textContent = 'ยังไม่มี observation จาก local worker';
}

function renderSnapshot(data) {
    const snap = data.snapshot;
    const state = data.status === 'live' ? 'live' : 'stale';
    setStatus(state, data.message);

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
        if (data.frame_url !== lastFrameUrl) {
            lastFrameUrl = data.frame_url;
            els.frame.src = data.frame_url;
        }
        els.frame.hidden = false;
        els.frameEmpty.hidden = true;
    } else {
        els.frame.hidden = true;
        els.frameEmpty.hidden = false;
    }

    els.caption.textContent = snap.camera_name + ' · ' + snap.area_type.toUpperCase() + ' · ' + snap.generated_at;
}

async function pollDetection() {
    try {
        const response = await fetch('api/live-detection.php', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || 'HTTP ' + response.status);
        }
        if (!data.available || !data.snapshot) {
            renderOffline(data);
        } else {
            renderSnapshot(data);
        }
    } catch (error) {
        renderOffline({
            status: 'offline',
            message: 'อ่านสถานะ detector ไม่สำเร็จ: ' + error.message,
        });
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

startPolling();
