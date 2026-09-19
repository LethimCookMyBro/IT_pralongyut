// หน้าแจ้งจุดขยะ (report.html) — POST api/reports.php อย่างเดียว
// รายการแจ้งทั้งหมดย้ายไปหน้า reports.html แล้ว (Phase C)
//
// Phase B: เลือกสถานที่ได้ 3 ทาง
//   1. เลือกพื้นที่จาก preset (js/locations.js) → location_source = 'preset'
//   2. กด "ใช้ตำแหน่งปัจจุบัน" → ขอ Geolocation ตอนกดเท่านั้น → 'gps'
//   3. พิมพ์เอง → 'manual'
// GPS ล้มเหลวต้องไม่ทำให้ฟอร์มใช้ไม่ได้ — ผู้ใช้ยังเลือก/พิมพ์ได้ตามปกติ
//
// หมายเหตุ deployment: navigator.geolocation ต้องได้รับ permission จากผู้ใช้
// และทำงานเฉพาะใน secure context (https หรือ localhost)

const WASTE_TYPE_LABEL = {
    general: 'ทั่วไป',
    recyclable: 'รีไซเคิล',
    hazardous: 'อันตราย',
    organic: 'อินทรีย์',
};

const GPS_TIMEOUT_MS = 12000;

const form = document.getElementById('report-form');
const locationInput = document.getElementById('location');
const presetSelect = document.getElementById('preset-location');
const latitudeInput = document.getElementById('latitude');
const longitudeInput = document.getElementById('longitude');
const sourceInput = document.getElementById('location_source');
const summaryEl = document.getElementById('location-summary');

const gpsBtn = document.getElementById('gps-btn');
const statusEl = document.getElementById('location-status');
const statusTextEl = document.getElementById('location-status-text');
const coordsEl = document.getElementById('location-coords');
const confirmRow = document.getElementById('location-confirm-row');

const duplicateBox = document.getElementById('duplicate-warning');
const duplicateList = document.getElementById('duplicate-list');
const duplicateNote = document.getElementById('duplicate-note');

let chosenPreset = '';
let pendingPosition = null;   // ตำแหน่งที่ได้จาก GPS แต่ผู้ใช้ยังไม่กด "ใช้ตำแหน่งนี้"
let duplicateAcknowledged = false;

/* ---------- แหล่งที่มาของตำแหน่ง ---------- */

const SOURCE_LABEL = { preset: 'เลือกจากพื้นที่ที่กำหนด', gps: 'ตำแหน่งปัจจุบันของอุปกรณ์', manual: 'พิมพ์เอง' };

// ที่มาของตำแหน่งคำนวณจากสถานะจริง ไม่ได้เดา:
// มีพิกัดที่ผู้ใช้ยืนยันแล้ว = gps, ตรงกับ preset ที่เลือก = preset, นอกนั้น = manual
function syncLocationSource() {
    let source = 'manual';
    if (latitudeInput.value !== '' && longitudeInput.value !== '') {
        source = 'gps';
    } else if (chosenPreset !== '' && locationInput.value.trim() === chosenPreset) {
        source = 'preset';
    }
    sourceInput.value = source;

    let text = `ที่มาของตำแหน่ง: <strong>${escapeHtml(SOURCE_LABEL[source])}</strong>`;
    if (source === 'gps') {
        text += ` <span class="muted">(${escapeHtml(formatCoords(latitudeInput.value, longitudeInput.value))})</span>`;
    }
    summaryEl.innerHTML = text;
}

function formatCoords(lat, lng) {
    return `${Number(lat).toFixed(5)}, ${Number(lng).toFixed(5)}`;
}

function setLocationStatus(message, kind = '', { showConfirm = false, coords = '' } = {}) {
    statusEl.hidden = false;
    statusEl.className = `location-status${kind ? ` ${kind}` : ''}`;
    statusTextEl.textContent = message;
    coordsEl.hidden = coords === '';
    coordsEl.textContent = coords;
    confirmRow.hidden = !showConfirm;
}

function clearCoordinates() {
    latitudeInput.value = '';
    longitudeInput.value = '';
    pendingPosition = null;
    confirmRow.hidden = true;
}

/* ---------- GPS ---------- */

function requestPosition() {
    if (!('geolocation' in navigator)) {
        setLocationStatus('อุปกรณ์หรือเบราว์เซอร์นี้ไม่รองรับการหาตำแหน่ง กรุณาเลือกพื้นที่หรือพิมพ์สถานที่เอง', 'is-error');
        return;
    }
    if (!window.isSecureContext) {
        // ไม่ return — บาง browser ยังยอมให้ขอได้ แต่เตือนไว้ก่อนเพื่อให้เข้าใจถ้าถูกปฏิเสธ
        setLocationStatus('หน้านี้ไม่ได้เปิดผ่านการเชื่อมต่อที่ปลอดภัย (https) เบราว์เซอร์อาจไม่ยอมให้ใช้ตำแหน่ง', 'is-error');
    }

    gpsBtn.disabled = true;
    setLocationStatus('กำลังขอตำแหน่งจากอุปกรณ์… หากเบราว์เซอร์ถามสิทธิ์ กรุณากดอนุญาต');

    navigator.geolocation.getCurrentPosition(
        (position) => {
            gpsBtn.disabled = false;
            pendingPosition = position.coords;
            const accuracy = Math.round(position.coords.accuracy ?? 0);
            setLocationStatus(
                `รับตำแหน่งสำเร็จ${accuracy ? ` (ความคลาดเคลื่อนประมาณ ${accuracy} เมตร)` : ''} — ตรวจสอบแล้วกดยืนยัน`,
                'is-ok',
                { showConfirm: true, coords: formatCoords(position.coords.latitude, position.coords.longitude) }
            );
        },
        (error) => {
            gpsBtn.disabled = false;
            pendingPosition = null;
            setLocationStatus(`${geolocationMessage(error)} — ยังแจ้งได้ตามปกติ โดยเลือกพื้นที่หรือพิมพ์สถานที่เอง`, 'is-error');
        },
        { enableHighAccuracy: true, timeout: GPS_TIMEOUT_MS, maximumAge: 0 }
    );
}

function geolocationMessage(error) {
    switch (error.code) {
        case error.PERMISSION_DENIED:
            return 'ไม่ได้รับอนุญาตให้ใช้ตำแหน่ง (ถ้าเปลี่ยนใจ ตั้งค่าสิทธิ์ตำแหน่งของเว็บนี้ในเบราว์เซอร์ได้)';
        case error.POSITION_UNAVAILABLE:
            return 'อุปกรณ์หาตำแหน่งไม่ได้ในขณะนี้';
        case error.TIMEOUT:
            return 'ใช้เวลาหาตำแหน่งนานเกินไป';
        default:
            return 'หาตำแหน่งไม่สำเร็จ';
    }
}

function acceptPosition() {
    if (!pendingPosition) return;
    latitudeInput.value = pendingPosition.latitude.toFixed(7);
    longitudeInput.value = pendingPosition.longitude.toFixed(7);
    const coords = formatCoords(latitudeInput.value, longitudeInput.value);

    // location ยังต้องมีข้อความอ่านได้ (DB บังคับ) — ถ้ายังว่างให้เติมพิกัดไว้ก่อน
    if (locationInput.value.trim() === '') {
        locationInput.value = `พิกัด ${coords}`;
    }
    pendingPosition = null;
    confirmRow.hidden = true;
    setLocationStatus('ใช้ตำแหน่งนี้แล้ว ระบบจะบันทึกพิกัดไปกับรายการแจ้ง', 'is-ok', { coords });
    resetDuplicateWarning();
    syncLocationSource();
}

function rejectPosition() {
    clearCoordinates();
    setLocationStatus('ไม่ใช้ตำแหน่งจากอุปกรณ์ เลือกพื้นที่หรือพิมพ์สถานที่เองได้');
    syncLocationSource();
}

/* ---------- Phase H: เตือนรายการที่อาจซ้ำ ---------- */

function resetDuplicateWarning() {
    duplicateBox.hidden = true;
    duplicateAcknowledged = false;
}

// คืน true = พบรายการใกล้เคียง (หยุดรอให้ผู้ใช้ตัดสินใจ)
// endpoint นี้เป็นตัวช่วยเตือน ถ้าเรียกไม่ได้ต้องไม่ขัดขวางการส่ง
async function checkSimilarReports(payload) {
    const query = apiQuery({
        waste_type: payload.waste_type,
        location: payload.location,
        latitude: payload.latitude,
        longitude: payload.longitude,
    });
    try {
        const res = await fetch(`api/reports-similar.php?${query}`);
        if (!res.ok) return false;
        const data = await res.json();
        if (!Array.isArray(data.similar) || data.similar.length === 0) return false;
        renderDuplicateWarning(data);
        return true;
    } catch (err) {
        return false;
    }
}

function renderDuplicateWarning(data) {
    const scope = data.radius_meters
        ? `ในรัศมีประมาณ ${data.radius_meters} เมตร`
        : 'ที่มีชื่อสถานที่ใกล้เคียงกัน';
    duplicateNote.textContent =
        `พบ ${data.similar.length} รายการประเภทเดียวกัน ${scope} ที่แจ้งไว้ภายใน ${data.window_days} วันและยังไม่ปิดเรื่อง ` +
        '(เกณฑ์นี้เป็นค่าตั้งต้นของต้นแบบ ยังไม่ผ่านการปรับเทียบ)';

    duplicateList.innerHTML = data.similar
        .map((row) => {
            const distance = row.distance_m === null ? '' : ` · ห่างประมาณ ${Math.round(row.distance_m)} ม.`;
            return `<li>
                <span class="cell-primary">${escapeHtml(row.location)}</span>
                <span class="cell-secondary">${escapeHtml(WASTE_TYPE_LABEL[row.waste_type] ?? row.waste_type)}
                    · ${Number(row.amount_kg)} กก. · แจ้ง ${escapeHtml(formatDateTime(row.created_at))}${escapeHtml(distance)}</span>
            </li>`;
        })
        .join('');

    document.getElementById('duplicate-view-link').href =
        `reports.html?${apiQuery({ q: data.similar[0].location, waste_type: data.similar[0].waste_type })}`;
    duplicateBox.hidden = false;
    duplicateBox.scrollIntoView({ block: 'nearest' });
}

/* ---------- ส่งฟอร์ม ---------- */

function showMessage(text, type) {
    const el = document.getElementById('form-msg');
    el.textContent = text;
    el.className = `msg ${type}`;
}

function collectPayload() {
    return {
        location: locationInput.value.trim(),
        waste_type: form.waste_type.value,
        amount_kg: form.amount_kg.value,
        detail: form.detail.value,
        latitude: latitudeInput.value === '' ? null : Number(latitudeInput.value),
        longitude: longitudeInput.value === '' ? null : Number(longitudeInput.value),
        location_source: sourceInput.value,
    };
}

async function submitReport(event) {
    event.preventDefault();
    if (!form.reportValidity()) return;

    const submitBtn = form.querySelector('button[type="submit"]');
    const payload = collectPayload();

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<svg class="icon icon-spin" aria-hidden="true"><use href="#icon-spinner"></use></svg><span>กำลังส่ง…</span>';

    try {
        if (!duplicateAcknowledged && await checkSimilarReports(payload)) {
            showMessage('ตรวจพบรายการที่อาจซ้ำ กรุณาตรวจสอบด้านล่างก่อนส่ง', 'error');
            return;
        }

        const res = await fetch('api/reports.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (!res.ok) {
            showMessage(data.error ?? 'ส่งเรื่องแจ้งไม่สำเร็จ', 'error');
            return;
        }

        showMessage('ส่งเรื่องแจ้งสำเร็จ ขอบคุณที่ช่วยแจ้งจุดขยะ', 'success');
        showToast('บันทึกรายการแจ้งแล้ว', 'success');
        resetForm();
    } catch (err) {
        showMessage('เชื่อมต่อ API ไม่สำเร็จ', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#icon-arrow-right"></use></svg><span>ส่งเรื่องแจ้ง</span>';
    }
}

function resetForm() {
    form.reset();
    chosenPreset = '';
    clearCoordinates();
    statusEl.hidden = true;
    resetDuplicateWarning();
    updateCharCounter();
    syncLocationSource();
}

function updateCharCounter() {
    const detail = document.getElementById('detail');
    document.getElementById('detail-counter').textContent = `${detail.value.length} / 500`;
}

/* ---------- ผูก event ---------- */

fillPresetLocations(presetSelect);

presetSelect.addEventListener('change', () => {
    chosenPreset = presetSelect.value;
    if (chosenPreset === '') {
        syncLocationSource();
        return;
    }
    locationInput.value = chosenPreset;
    // preset เป็นการจับคู่ด้วยชื่อ ไม่มีพิกัดผูกไว้ → ล้างพิกัดเดิมออกเพื่อไม่ให้ข้อมูลขัดกัน
    clearCoordinates();
    statusEl.hidden = true;
    resetDuplicateWarning();
    syncLocationSource();
});

locationInput.addEventListener('input', () => {
    resetDuplicateWarning();
    syncLocationSource();
});
document.getElementById('waste_type').addEventListener('change', resetDuplicateWarning);

gpsBtn.addEventListener('click', requestPosition);
document.getElementById('gps-confirm-btn').addEventListener('click', acceptPosition);
document.getElementById('gps-cancel-btn').addEventListener('click', rejectPosition);

document.getElementById('duplicate-submit-btn').addEventListener('click', () => {
    duplicateAcknowledged = true;
    duplicateBox.hidden = true;
    form.requestSubmit();
});

form.addEventListener('submit', submitReport);
document.getElementById('detail').addEventListener('input', updateCharCounter);
syncLocationSource();
