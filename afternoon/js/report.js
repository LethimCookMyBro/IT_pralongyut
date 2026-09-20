// หน้าแจ้งจุดขยะ (report.html) — POST api/reports.php อย่างเดียว
//
// SPEC §2.2: เรื่องแจ้งหนึ่งเรื่องต้องการแค่ "สถานที่"
//   สถานที่  = บังคับ
//   รูป      = ไม่บังคับ
//   รายละเอียด = ไม่บังคับ
// ประเภทขยะ/น้ำหนักถูกถอดออกจากฟอร์มนี้แล้ว และ "ไม่ส่งมา" = NULL ใน DB
// ห้ามส่งค่าปลอมมาแทนเพื่อให้ validation ผ่าน
//
// สถานที่มีสามทาง:
//   1. กด "ใช้ตำแหน่งปัจจุบัน" → ขอ Geolocation ตอนกดเท่านั้น → 'gps'
//   2. พิมพ์เอง ('manual') ในช่องที่แสดงอยู่แล้ว
//   3. เปิด disclosure แล้วเลือกสถานที่แนะนำ ('preset')
// GPS ล้มเหลวต้องไม่ทำให้ฟอร์มใช้ไม่ได้ — โฟกัสช่องพิมพ์แทน
//
// หมายเหตุ deployment: navigator.geolocation ต้องได้รับ permission จากผู้ใช้
// และทำงานเฉพาะใน secure context (https หรือ localhost)

const GPS_TIMEOUT_MS = 12000;
const PHOTO_MAX_BYTES = 5 * 1024 * 1024;
const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const DUPLICATE_DEBOUNCE_MS = 600;

const form = document.getElementById('report-form');
const locationInput = document.getElementById('location');
const presetSelect = document.getElementById('preset-location');
const latitudeInput = document.getElementById('latitude');
const longitudeInput = document.getElementById('longitude');
const sourceInput = document.getElementById('location_source');

const gpsBtn = document.getElementById('gps-btn');
const statusEl = document.getElementById('location-status');
const statusTextEl = document.getElementById('location-status-text');
const manualDetails = document.getElementById('location-manual');

const chosenEl = document.getElementById('chosen-location');
const chosenNameEl = document.getElementById('chosen-location-name');
const chosenMetaEl = document.getElementById('chosen-location-meta');

const dupHint = document.getElementById('duplicate-hint');
const dupHintText = document.getElementById('duplicate-hint-text');
const dupHintLink = document.getElementById('duplicate-hint-link');

const photoCameraInput = document.getElementById('photo-camera-input');
const photoFileInput = document.getElementById('photo-file-input');
const photoPreview = document.getElementById('photo-preview');
const photoPreviewImg = document.getElementById('photo-preview-img');
const photoPreviewName = document.getElementById('photo-preview-name');
const photoActions = document.getElementById('photo-actions');
const photoHint = document.getElementById('photo-hint');

let chosenPreset = '';
let selectedPhoto = null;
let previewUrl = '';
let gpsRequestId = 0;

/* ---------- แหล่งที่มาของตำแหน่ง ---------- */

const SOURCE_LABEL = {
    preset: 'สถานที่แนะนำ',
    gps: 'ตำแหน่งปัจจุบัน',
    manual: 'พิมพ์เอง',
};

// ที่มาคำนวณจากสถานะจริง ไม่ได้เดา:
// มีพิกัดแล้ว = gps, ตรงกับ preset ที่เลือก = preset, นอกนั้น = manual
function currentSource() {
    if (latitudeInput.value !== '' && longitudeInput.value !== '') return 'gps';
    if (chosenPreset !== '' && locationInput.value.trim() === chosenPreset) return 'preset';
    return 'manual';
}

function formatCoords(lat, lng) {
    return `${Number(lat).toFixed(5)}, ${Number(lng).toFixed(5)}`;
}

// การ์ดสรุปคือสิ่งเดียวที่ผู้ใช้ต้องอ่านเพื่อรู้ว่าจะส่งตำแหน่งไหนไป
function syncChosenLocation() {
    const name = locationInput.value.trim();
    const source = currentSource();
    sourceInput.value = source;

    if (name === '' || source === 'manual') {
        chosenEl.hidden = true;
        return;
    }
    chosenEl.hidden = false;
    chosenNameEl.textContent = name;
    chosenMetaEl.textContent = source === 'gps'
        ? `${SOURCE_LABEL.gps} · ${formatCoords(latitudeInput.value, longitudeInput.value)}`
        : SOURCE_LABEL[source];
}

function setLocationStatus(message, kind = '') {
    statusEl.hidden = message === '';
    statusEl.className = `location-status${kind ? ` ${kind}` : ''}`;
    statusTextEl.textContent = message;
}

function clearCoordinates() {
    latitudeInput.value = '';
    longitudeInput.value = '';
}

/* ---------- GPS ---------- */

function requestPosition() {
    if (!('geolocation' in navigator)) {
        setLocationStatus('เครื่องนี้หาตำแหน่งไม่ได้ กรุณาพิมพ์หรือเลือกสถานที่เอง', 'is-error');
        locationInput.focus();
        return;
    }
    if (!window.isSecureContext) {
        // ไม่ return — บาง browser ยังยอมให้ขอได้ แต่เตือนไว้ก่อนเพื่อให้เข้าใจถ้าถูกปฏิเสธ
        setLocationStatus('หน้านี้ไม่ได้เปิดผ่าน https เบราว์เซอร์อาจไม่ยอมให้ใช้ตำแหน่ง');
    }

    gpsBtn.disabled = true;
    const requestId = ++gpsRequestId;
    setLocationStatus('กำลังหาตำแหน่ง… ถ้าเบราว์เซอร์ถามสิทธิ์ กรุณากดอนุญาต');

    navigator.geolocation.getCurrentPosition(
        (position) => {
            if (requestId !== gpsRequestId) return;
            gpsBtn.disabled = false;
            acceptPosition(position.coords);
        },
        (error) => {
            if (requestId !== gpsRequestId) return;
            gpsBtn.disabled = false;
            clearCoordinates();
            syncChosenLocation();
            // ล้มเหลวแล้วต้องมีทางไปต่อทันที ไม่ใช่ทางตัน
            setLocationStatus(`${geolocationMessage(error)} — พิมพ์หรือเลือกสถานที่เองได้`, 'is-error');
            locationInput.focus();
        },
        { enableHighAccuracy: true, timeout: GPS_TIMEOUT_MS, maximumAge: 0 }
    );
}

function geolocationMessage(error) {
    switch (error.code) {
        case error.PERMISSION_DENIED:
            return 'ไม่ได้รับอนุญาตให้ใช้ตำแหน่ง';
        case error.POSITION_UNAVAILABLE:
            return 'เครื่องหาตำแหน่งไม่ได้ตอนนี้';
        case error.TIMEOUT:
            return 'ใช้เวลาหาตำแหน่งนานเกินไป';
        default:
            return 'หาตำแหน่งไม่สำเร็จ';
    }
}

// กดครั้งเดียวจบ: ไม่มีขั้นยืนยันซ้ำ เพราะการ์ดสรุปมีปุ่ม "เปลี่ยน" อยู่แล้ว
function acceptPosition(coords) {
    latitudeInput.value = coords.latitude.toFixed(7);
    longitudeInput.value = coords.longitude.toFixed(7);
    const text = formatCoords(latitudeInput.value, longitudeInput.value);

    // location ต้องมีข้อความอ่านได้เสมอ (DB บังคับ) — ยังไม่ตั้งชื่อก็ใช้พิกัดไปก่อน
    if (locationInput.value.trim() === '') {
        locationInput.value = `พิกัด ${text}`;
    }
    const accuracy = Math.round(coords.accuracy ?? 0);
    setLocationStatus(
        `ได้ตำแหน่งแล้ว${accuracy ? ` (คลาดเคลื่อนประมาณ ${accuracy} เมตร)` : ''}`,
        'is-ok'
    );
    syncChosenLocation();
    scheduleDuplicateCheck();
}

function resetLocation() {
    cancelPositionRequest();
    clearCoordinates();
    locationInput.value = '';
    presetSelect.value = '';
    chosenPreset = '';
    setLocationStatus('');
    dupHint.hidden = true;
    syncChosenLocation();
    manualDetails.open = false;
    locationInput.focus();
}

function cancelPositionRequest() {
    gpsRequestId++;
    gpsBtn.disabled = false;
}

/* ---------- เตือนรายการที่อาจซ้ำ (ไม่บล็อกการส่ง) ---------- */
// เดิมเป็นกล่องคั่นกลางที่ต้องกดยืนยันก่อนถึงจะส่งได้ ซึ่งเพิ่มขั้นตอนให้ประชาชน
// ตอนนี้เป็นแค่บรรทัดข้อมูล — ผู้ใช้กดส่งได้ตลอดโดยไม่ต้องอ่านมันก่อน

let dupTimer = null;

function scheduleDuplicateCheck() {
    clearTimeout(dupTimer);
    dupTimer = setTimeout(runDuplicateCheck, DUPLICATE_DEBOUNCE_MS);
}

async function runDuplicateCheck() {
    const location = locationInput.value.trim();
    if (location.length < 3) {
        dupHint.hidden = true;
        return;
    }
    const query = apiQuery({
        location,
        latitude: latitudeInput.value,
        longitude: longitudeInput.value,
    });
    try {
        const res = await fetch(`api/reports-similar.php?${query}`);
        if (!res.ok) return;
        const data = await res.json();
        if (!Array.isArray(data.similar) || data.similar.length === 0) {
            dupHint.hidden = true;
            return;
        }
        const scope = data.radius_meters ? `ในรัศมีประมาณ ${data.radius_meters} เมตร` : 'ชื่อใกล้เคียงกัน';
        dupHintText.textContent =
            `บริเวณนี้มีคนแจ้งไว้แล้ว ${data.similar.length} รายการ (${scope}) — แจ้งซ้ำได้ ไม่ต้องกังวล`;
        dupHintLink.href = `reports.html?${apiQuery({ q: data.similar[0].location })}`;
        dupHint.hidden = false;
    } catch (err) {
        // เป็นแค่ตัวช่วย เรียกไม่ได้ก็ต้องไม่ขัดขวางอะไร
    }
}

/* ---------- รูป (ไม่บังคับ) ---------- */

function setPhotoError(message) {
    photoHint.textContent = message;
    photoHint.classList.add('is-error');
}

function clearPhotoError() {
    photoHint.textContent = 'JPG, PNG หรือ WEBP ขนาดไม่เกิน 5 MB';
    photoHint.classList.remove('is-error');
}

// ตรวจฝั่ง client เพื่อให้ผู้ใช้รู้ผลทันที — ฝั่ง server ตรวจซ้ำอยู่ดีและเป็นตัวตัดสินจริง
function choosePhoto(file) {
    if (!file) return;
    if (!PHOTO_TYPES.includes(file.type)) {
        setPhotoError('รองรับเฉพาะไฟล์รูป JPG, PNG หรือ WEBP');
        return;
    }
    if (file.size > PHOTO_MAX_BYTES) {
        setPhotoError('ไฟล์ใหญ่เกิน 5 MB กรุณาเลือกรูปที่เล็กลง');
        return;
    }
    clearPhotoError();
    revokePreview();
    selectedPhoto = file;
    previewUrl = URL.createObjectURL(file);
    photoPreviewImg.src = previewUrl;
    photoPreviewName.textContent = `${file.name} · ${formatFileSize(file.size)}`;
    photoPreview.hidden = false;
    photoActions.hidden = true;
}

// รูปจากมือถือมักเป็นหลาย MB แต่รูปที่ crop มาแล้วอาจไม่ถึง 1 MB
// ถ้าใช้หน่วย MB อย่างเดียวจะขึ้นว่า "0.0 MB" ซึ่งอ่านแล้วเหมือนไฟล์เสีย
function formatFileSize(bytes) {
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function revokePreview() {
    if (previewUrl) {
        URL.revokeObjectURL(previewUrl);
        previewUrl = '';
    }
}

function removePhoto() {
    revokePreview();
    selectedPhoto = null;
    photoPreviewImg.removeAttribute('src');
    photoPreview.hidden = true;
    photoActions.hidden = false;
    photoCameraInput.value = '';
    photoFileInput.value = '';
    clearPhotoError();
}

/* ---------- ส่งฟอร์ม ---------- */

function showMessage(text, type) {
    const el = document.getElementById('form-msg');
    el.textContent = text;
    el.className = `msg ${type}`;
}

async function submitReport(event) {
    event.preventDefault();

    // Keep the short inline error consistent for every location method.
    const location = locationInput.value.trim();
    if (location.length < 3) {
        showMessage('กรุณาบอกสถานที่ก่อน — กดใช้ตำแหน่งปัจจุบัน หรือพิมพ์ชื่อสถานที่', 'error');
        locationInput.focus();
        return;
    }

    const submitBtn = form.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<svg class="icon icon-spin" aria-hidden="true"><use href="#icon-spinner"></use></svg><span>กำลังส่ง…</span>';

    // ใช้ multipart ทางเดียวทั้งมีรูปและไม่มีรูป — โค้ดเส้นเดียว กฎฝั่ง server ชุดเดียว
    const body = new FormData();
    body.append('location', location);
    body.append('detail', document.getElementById('detail').value);
    body.append('latitude', latitudeInput.value);
    body.append('longitude', longitudeInput.value);
    body.append('location_source', sourceInput.value);
    if (selectedPhoto) {
        body.append('photo', selectedPhoto, selectedPhoto.name);
    }

    try {
        const res = await fetch('api/reports.php', { method: 'POST', body });
        const data = await res.json();

        if (!res.ok) {
            showMessage(data.error ?? 'ส่งเรื่องแจ้งไม่สำเร็จ', 'error');
            return;
        }

        showMessage('ส่งเรื่องแจ้งแล้ว ขอบคุณที่ช่วยกันดูแลพื้นที่', 'success');
        showToast('บันทึกรายการแจ้งแล้ว', 'success');
        resetForm();
    } catch (err) {
        showMessage('เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ กรุณาลองใหม่', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#icon-flag"></use></svg><span>แจ้งจุดนี้</span>';
    }
}

function resetForm() {
    cancelPositionRequest();
    form.reset();
    chosenPreset = '';
    clearCoordinates();
    removePhoto();
    setLocationStatus('');
    dupHint.hidden = true;
    manualDetails.open = false;
    updateCharCounter();
    syncChosenLocation();
}

function updateCharCounter() {
    const detail = document.getElementById('detail');
    document.getElementById('detail-counter').textContent = `${detail.value.length} / 500`;
}

/* ---------- ผูก event ---------- */

fillPresetLocations(presetSelect);

presetSelect.addEventListener('change', () => {
    cancelPositionRequest();
    chosenPreset = presetSelect.value;
    if (chosenPreset === '') {
        syncChosenLocation();
        return;
    }
    locationInput.value = chosenPreset;
    // preset จับคู่ด้วยชื่อ ไม่มีพิกัดผูกไว้ → ล้างพิกัดเดิมออกเพื่อไม่ให้ข้อมูลขัดกัน
    clearCoordinates();
    setLocationStatus('');
    syncChosenLocation();
    scheduleDuplicateCheck();
});

locationInput.addEventListener('input', () => {
    cancelPositionRequest();
    clearCoordinates();
    chosenPreset = '';
    presetSelect.value = '';
    setLocationStatus('');
    syncChosenLocation();
    scheduleDuplicateCheck();
});

gpsBtn.addEventListener('click', requestPosition);
document.getElementById('location-clear-btn').addEventListener('click', resetLocation);

document.getElementById('photo-camera-btn').addEventListener('click', () => photoCameraInput.click());
document.getElementById('photo-file-btn').addEventListener('click', () => photoFileInput.click());
document.getElementById('photo-replace-btn').addEventListener('click', () => photoFileInput.click());
document.getElementById('photo-remove-btn').addEventListener('click', removePhoto);
photoCameraInput.addEventListener('change', (e) => choosePhoto(e.target.files[0]));
photoFileInput.addEventListener('change', (e) => choosePhoto(e.target.files[0]));

form.addEventListener('submit', submitReport);
document.getElementById('detail').addEventListener('input', updateCharCounter);

syncChosenLocation();
