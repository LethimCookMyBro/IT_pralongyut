// หน้ารายละเอียดเหตุ (incident.html?id=…) — Phase E
//
// อ่าน: GET api/vision-incident.php?id=  และ GET api/vision-observations.php?incident_id=
// เปลี่ยนสถานะ: POST api/vision-review.php (ยืนยัน/ปฏิเสธ/ดำเนินการแล้ว) เหมือนเดิม
// การตรวจของคนยังทำที่ระดับ "เหตุ" เท่านั้น ไม่ใช่รายการตรวจพบแต่ละครั้ง
//
// ทุก action ที่เปลี่ยนข้อมูลต้องผ่าน confirmAction() ซึ่งใช้ <dialog> ของ browser
// (ไม่ใช้ alert()/confirm() ดิบ)

const OBSERVATIONS_PER_PAGE = 10;

const detailSection = document.getElementById('incident-detail');
const errorBox = document.getElementById('load-error');
const observationPagination = document.getElementById('observation-pagination');

const incidentId = Number.parseInt(new URLSearchParams(window.location.search).get('id') ?? '', 10);
let currentIncident = null;

function showLoadError(message) {
    detailSection.hidden = true;
    errorBox.textContent = message;
    errorBox.className = 'msg error';
}

async function loadIncident() {
    if (!Number.isInteger(incidentId) || incidentId < 1) {
        showLoadError('ยังไม่ได้เลือกจุด กรุณากลับไปเลือกจากศูนย์ตรวจสอบ');
        return;
    }

    try {
        const res = await fetch(`api/vision-incident.php?id=${incidentId}`);
        const data = await res.json();
        if (!res.ok) {
            showLoadError(data.error ?? 'โหลดรายละเอียดเหตุไม่สำเร็จ');
            return;
        }
        currentIncident = data.incident;
        errorBox.className = 'msg error hidden';
        detailSection.hidden = false;
        renderIncident(data.incident, data.aggregation, data.evidence);
        loadObservations(1);
    } catch (err) {
        showLoadError('เชื่อมต่อ API ไม่สำเร็จ');
    }
}

/* ---------- ภาพหลักฐานจากโมเดล ---------- */

// ป้องกันชั้นที่สอง: ถึง API จะกรองมาแล้ว ก็ไม่ยอมใส่ path แปลก ๆ ลงใน src
const EVIDENCE_PATH_RE = /^assets\/vision\/[A-Za-z0-9][A-Za-z0-9._-]*\.(jpg|jpeg|png|webp)$/i;

const safeEvidencePath = (p) => (typeof p === 'string' && EVIDENCE_PATH_RE.test(p) ? p : null);

let currentEvidence = [];

function renderEvidence(evidence) {
    const body = document.getElementById('evidence-body');
    currentEvidence = (Array.isArray(evidence) ? evidence : [])
        .filter((item) => safeEvidencePath(item.image_path));

    // ไม่มีรูป = บอกตรง ๆ ห้ามใส่ placeholder ที่ดูเหมือนภาพจริง
    if (currentEvidence.length === 0) {
        body.innerHTML = `
            <div class="evidence-empty">
                ${icon('icon-camera')}
                <p class="evidence-empty-title">ยังไม่มีภาพหลักฐานสำหรับเหตุนี้</p>
                <p class="muted">ดูข้อมูลการตรวจพบด้านล่าง</p>
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
                    Replay · ภาพผลตรวจจาก AI
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

/* ---------- เนื้อหาหลักของหน้า ---------- */

function detailRows(items) {
    return items
        .map(([label, value, note]) => `
            <div class="detail-item">
                <dt>${escapeHtml(label)}</dt>
                <dd>${value}${note ? `<span class="detail-note">${escapeHtml(note)}</span>` : ''}</dd>
            </div>`)
        .join('');
}

function renderIncident(incident, aggregation, evidence) {
    document.title = `เหตุ #${incident.id} ${incident.location} — Bangsaen Waste Vision`;
    document.getElementById('incident-id').textContent = `#${incident.id}`;
    document.getElementById('incident-status').innerHTML = statusStack(incident);

    // สถานที่คือสิ่งที่ต้องเห็นก่อน ไม่ใช่ field หนึ่งในตาราง
    document.getElementById('incident-place').textContent = incident.location;
    document.getElementById('incident-subline').textContent =
        `พบล่าสุด ${formatDateTime(incident.last_seen)}`;

    const originBanner = document.getElementById('origin-banner-text');
    if (incident.record_origin === 'detector_run') {
        originBanner.textContent = 'Replay · AI';
    } else {
        originBanner.textContent = 'Replay · ตัวอย่าง';
    }

    renderEvidence(evidence);

    // สรุปเหตุ: ตัวเลขที่ใช้ตัดสินใจ 4 ค่า
    const summary = [
        ['พบทั้งหมด', `${Number(incident.observation_count)} ครั้ง`],
        ['จำนวนสูงสุด', `${Number(incident.peak_detected_count)} ชิ้น`],
        ['แนวโน้ม', trendTag(incident)],
    ];
    document.getElementById('summary-list').innerHTML = summary
        .map(([label, value, note]) => `
            <div class="summary-row">
                <dt>${escapeHtml(label)}</dt>
                <dd>${value}${note ? `<span class="detail-note">${escapeHtml(note)}</span>` : ''}</dd>
            </div>`)
        .join('');

    // รายละเอียดเพิ่มเติม: ข้อมูลระดับรอง
    document.getElementById('detail-grid').innerHTML = detailRows([
        ['พบครั้งแรก', escapeHtml(formatDateTime(incident.first_seen))],
        ['พบล่าสุด', escapeHtml(formatDateTime(incident.last_seen))],
        ['ประเภทพื้นที่', areaTag(incident.area_type)],
        ['จุด/กล้อง', escapeHtml(incident.camera_name)],
        ['แหล่งภาพ', escapeHtml(SOURCE_MODE_LABEL[incident.source_mode] ?? incident.source_mode),
            'ยังไม่ได้เชื่อมกล้อง/CCTV เทศบาลจริง'],
        ['ที่มาของข้อมูล', originTag(incident.record_origin),
            incident.record_origin === 'detector_run' ? 'ผลจากโมเดลจริงบนวิดีโออ้างอิง' : 'ข้อมูลที่สร้างไว้เพื่อสาธิต workflow'],
    ]);

    // ทางเทคนิค: อยู่ใน <details> ไม่แย่งสายตา
    document.getElementById('tech-grid').innerHTML = detailRows([
        ['ขอบเขต', 'วิดีโออ้างอิง ไม่ใช่ CCTV เทศบาลหรือเหตุจริงจากบางแสน'],
        ['ความมั่นใจของโมเดล', escapeHtml(fmtConf(incident.max_confidence)), 'ไม่ใช่ค่าความแม่นยำของระบบ'],
        ['หมายเลขเหตุ', `#${Number(incident.id)}`],
        ['จำนวนที่พบครั้งแรก', `${Number(incident.first_detected_count)} ชิ้น`],
        ['จำนวนที่พบครั้งล่าสุด', `${Number(incident.latest_detected_count)} ชิ้น`],
        ['กติกาการรวมเหตุ', `รวมจากจุดเดิมภายใน ${Number(aggregation.window_minutes)} นาที`,
            'PROTOTYPE / UNCALIBRATED — ยังไม่ได้ปรับค่ากับพื้นที่จริง'],
        ['source mode', escapeHtml(incident.source_mode)],
        ['record origin', escapeHtml(incident.record_origin ?? 'unknown')],
        ['เวลาที่คนตรวจ', escapeHtml(incident.reviewed_at ? formatDateTime(incident.reviewed_at) : 'ยังไม่ตรวจ')],
        ['เวลาที่ปิดงาน', escapeHtml(incident.resolved_at ? formatDateTime(incident.resolved_at) : 'ยังไม่ปิดงาน')],
    ]);

    renderActions(incident);
}

/* ---------- Action + confirmation ---------- */

// ข้อความ dialog บอกให้ชัดว่าจะเกิดอะไร และอะไรย้อนไม่ได้
const ACTION_DIALOG = {
    confirm: {
        title: 'รับเรื่องนี้?',
        message: 'รายการจะย้ายไปรอดำเนินการเพื่อให้เจ้าหน้าที่จัดการต่อ',
        confirmLabel: 'รับเรื่อง',
    },
    reject: {
        title: 'ไม่รับเรื่องนี้?',
        message: 'รายการจะปิดเป็นไม่รับเรื่องและย้อนกลับไม่ได้',
        confirmLabel: 'ไม่รับเรื่อง',
        confirmClass: 'btn-reject',
        focusCancel: true,
    },
    resolve: {
        title: 'ปิดงานนี้?',
        message: 'ใช้เมื่อมีการเก็บ/จัดการจุดนี้เรียบร้อยแล้ว ระบบจะบันทึกเวลาปิดงานและนำเหตุออกจากงานที่ค้าง',
        confirmLabel: 'ปิดงาน',
    },
};

function renderActions(incident) {
    const note = document.getElementById('action-note');
    const row = document.getElementById('action-row');

    if (incident.review_status === 'pending') {
        note.textContent = 'ยังไม่ผ่านการตรวจของคน — ดูภาพและข้อมูลด้านบนก่อนตัดสิน';
        row.innerHTML = `
            <button type="button" data-action="confirm">
                <svg class="icon" aria-hidden="true"><use href="#icon-check"></use></svg>รับเรื่อง
            </button>
            <button type="button" class="btn-reject" data-action="reject">
                <svg class="icon" aria-hidden="true"><use href="#icon-x"></use></svg>ไม่รับเรื่อง
            </button>`;
        return;
    }

    if (incident.review_status === 'confirmed' && incident.action_status !== 'resolved') {
        note.textContent = 'รับเรื่องแล้ว — รอดำเนินการในพื้นที่ กดปุ่มนี้เมื่อจัดการจุดนี้เรียบร้อย';
        row.innerHTML = `
            <button type="button" data-action="resolve">
                <svg class="icon" aria-hidden="true"><use href="#icon-resolve"></use></svg>ปิดงาน
            </button>`;
        return;
    }

    note.textContent = incident.review_status === 'rejected'
        ? 'รายการนี้ไม่รับเรื่องแล้ว จึงไม่มีขั้นตอนต่อไป'
        : 'รายการนี้เสร็จแล้ว ไม่มีขั้นตอนที่ต้องทำต่อ';
    row.innerHTML = '';
}

document.getElementById('action-row').addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action]');
    if (!button || !currentIncident) return;

    const action = button.dataset.action;
    const dialog = ACTION_DIALOG[action];
    if (!dialog) return;

    const confirmed = await confirmAction({
        ...dialog,
        meta: `${currentIncident.location} · ${currentIncident.camera_name} · พบ ${currentIncident.observation_count} ครั้ง · พบล่าสุด ${formatDateTime(currentIncident.last_seen)}`,
    });
    if (!confirmed) return;

    button.disabled = true;
    try {
        const res = await fetch('api/vision-review.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: currentIncident.id, action }),
        });
        const data = await res.json();
        if (!res.ok) {
            showToast(data.error ?? 'ทำรายการไม่สำเร็จ', 'error');
            return;
        }
        showToast('บันทึกการตรวจแล้ว', 'success');
        await loadIncident();
    } catch (err) {
        showToast('เชื่อมต่อ API ไม่สำเร็จ', 'error');
    } finally {
        button.disabled = false;
    }
});

/* ---------- ข้อมูลการตรวจพบ (timeline) ---------- */

async function loadObservations(page) {
    const list = document.getElementById('observation-list');
    list.innerHTML = '<li class="observation-item muted">กำลังโหลด…</li>';

    const query = apiQuery({ incident_id: incidentId, page, per_page: OBSERVATIONS_PER_PAGE });
    try {
        const res = await fetch(`api/vision-observations.php?${query}`);
        const data = await res.json();
        if (!res.ok) {
            list.innerHTML = `<li class="observation-item">${escapeHtml(data.error ?? 'โหลดข้อมูลการตรวจพบไม่สำเร็จ')}</li>`;
            return;
        }

        const observations = Array.isArray(data.observations) ? data.observations : [];
        document.getElementById('observation-note').textContent = observations.length === 0
            ? 'ไม่มีข้อมูลการตรวจพบที่ผูกกับเหตุนี้'
            : 'แต่ละบรรทัดคือการตรวจพบหนึ่งครั้งจากโมเดล เรียงตามเวลา — เป็นหลักฐานประกอบ ไม่ใช่คิวให้ตรวจทีละรายการ';

        list.innerHTML = observations.length === 0
            ? '<li class="observation-item muted">ไม่มีข้อมูลการตรวจพบ</li>'
            : observations.map((o) => `
                <li class="observation-item">
                    <span class="observation-time">${escapeHtml(formatTime(o.captured_at))}</span>
                    <span class="observation-count">พบ <b>${Number(o.detected_count)}</b> ชิ้น</span>
                    <span class="observation-conf">ความมั่นใจของโมเดล ${escapeHtml(fmtConf(o.max_confidence))}</span>
                    ${o.image_path ? '<span class="observation-flag">มีภาพ</span>' : ''}
                </li>`).join('');

        renderPagination(observationPagination, data.pagination, loadObservations);
    } catch (err) {
        list.innerHTML = '<li class="observation-item">เชื่อมต่อ API ไม่สำเร็จ</li>';
    }
}

loadIncident();
