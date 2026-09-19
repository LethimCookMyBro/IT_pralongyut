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
        showLoadError('ลิงก์ไม่ถูกต้อง: ต้องระบุหมายเลขเหตุ เช่น incident.html?id=12');
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
        renderIncident(data.incident, data.aggregation);
        loadObservations(1);
    } catch (err) {
        showLoadError('เชื่อมต่อ API ไม่สำเร็จ');
    }
}

function renderIncident(incident, aggregation) {
    document.title = `เหตุ #${incident.id} ${incident.location} — Bangsaen Waste Vision`;
    document.getElementById('incident-id').textContent = `#${incident.id}`;
    document.getElementById('incident-status').innerHTML = statusStack(incident);

    const items = [
        ['สถานที่', escapeHtml(incident.location)],
        ['จุด/กล้อง', escapeHtml(incident.camera_name)],
        ['ประเภทพื้นที่', areaTag(incident.area_type)],
        ['พบครั้งแรก', escapeHtml(formatDateTime(incident.first_seen))],
        ['พบล่าสุด', escapeHtml(formatDateTime(incident.last_seen))],
        ['จำนวนครั้งที่พบ', `${Number(incident.observation_count)} ครั้ง`,
            `รวมจากจุดเดิมภายใน ${Number(aggregation.window_minutes)} นาที`],
        ['จำนวนสูงสุดที่ตรวจพบ', `${Number(incident.peak_detected_count)} ชิ้น`,
            `ครั้งล่าสุดตรวจพบ ${Number(incident.latest_detected_count)} ชิ้น`],
        ['แนวโน้ม', trendTag(incident), 'เทียบครั้งแรกกับครั้งล่าสุด ไม่ใช่การพยากรณ์'],
        ['ความมั่นใจของโมเดล (สูงสุด)', escapeHtml(fmtConf(incident.max_confidence)),
            'เป็นความมั่นใจของโมเดล ไม่ใช่ค่าความแม่นยำของระบบ'],
        ['ที่มาของภาพ', escapeHtml(SOURCE_MODE_LABEL[incident.source_mode] ?? incident.source_mode),
            'ยังไม่ได้เชื่อมกล้อง/CCTV เทศบาลจริง'],
        ['เวลาที่คนตรวจ', escapeHtml(incident.reviewed_at ? formatDateTime(incident.reviewed_at) : 'ยังไม่ตรวจ')],
        ['เวลาที่ปิดงาน', escapeHtml(incident.resolved_at ? formatDateTime(incident.resolved_at) : 'ยังไม่ปิดงาน')],
    ];

    document.getElementById('detail-grid').innerHTML = items
        .map(([label, value, note]) => `
            <div class="detail-item">
                <dt>${escapeHtml(label)}</dt>
                <dd>${value}${note ? `<span class="detail-note">${escapeHtml(note)}</span>` : ''}</dd>
            </div>`)
        .join('');

    renderActions(incident);
}

/* ---------- Action + confirmation ---------- */

// ข้อความ dialog บอกให้ชัดว่าจะเกิดอะไร และอะไรย้อนไม่ได้
const ACTION_DIALOG = {
    confirm: {
        title: 'ยืนยันว่าเป็นเหตุจริง?',
        message: 'ระบบจะบันทึกว่าคนตรวจยืนยันเหตุนี้ และเปลี่ยนสถานะเป็น "ต้องดำเนินการ" เพื่อให้มีคนไปจัดการต่อ',
        confirmLabel: 'ยืนยันเหตุ',
    },
    reject: {
        title: 'ปฏิเสธเหตุนี้?',
        message: 'ระบบจะบันทึกว่าไม่ใช่เหตุจริง เหตุนี้จะถูกปิดและไม่กลับมาอยู่ในคิวอีก การปฏิเสธย้อนกลับไม่ได้',
        confirmLabel: 'ปฏิเสธเหตุ',
        confirmClass: 'btn-reject',
        focusCancel: true,
    },
    resolve: {
        title: 'ทำเครื่องหมายว่าดำเนินการแล้ว?',
        message: 'ใช้เมื่อมีการเก็บ/จัดการจุดนี้เรียบร้อยแล้ว ระบบจะบันทึกเวลาปิดงานและนำเหตุออกจากงานที่ค้าง',
        confirmLabel: 'ดำเนินการแล้ว',
    },
};

function renderActions(incident) {
    const note = document.getElementById('action-note');
    const row = document.getElementById('action-row');

    if (incident.review_status === 'pending') {
        note.textContent = 'เหตุนี้ยังไม่ผ่านการตรวจของคน — ตรวจข้อมูลด้านบนและข้อมูลการตรวจพบด้านล่างก่อนตัดสิน';
        row.innerHTML = `
            <button type="button" data-action="confirm">
                <svg class="icon" aria-hidden="true"><use href="#icon-check"></use></svg>ยืนยันเหตุ
            </button>
            <button type="button" class="btn-reject" data-action="reject">
                <svg class="icon" aria-hidden="true"><use href="#icon-x"></use></svg>ปฏิเสธ
            </button>`;
        return;
    }

    if (incident.review_status === 'confirmed' && incident.action_status !== 'resolved') {
        note.textContent = 'ยืนยันแล้ว รอการดำเนินการในพื้นที่ — กดปุ่มนี้เมื่อจัดการจุดนี้เรียบร้อย';
        row.innerHTML = `
            <button type="button" data-action="resolve">
                <svg class="icon" aria-hidden="true"><use href="#icon-resolve"></use></svg>ทำเครื่องหมายว่าดำเนินการแล้ว
            </button>`;
        return;
    }

    note.textContent = incident.review_status === 'rejected'
        ? 'เหตุนี้ถูกปฏิเสธแล้ว จึงไม่มีขั้นตอนต่อไป (การปฏิเสธเป็นสถานะสุดท้าย)'
        : 'เหตุนี้ดำเนินการเรียบร้อยแล้ว ไม่มีขั้นตอนที่ต้องทำต่อ';
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
            ? 'ไม่มีรายการตรวจพบที่ผูกกับเหตุนี้'
            : 'แต่ละบรรทัดคือการตรวจพบหนึ่งครั้งจากโมเดล เรียงตามเวลา — เป็นหลักฐานประกอบ ไม่ใช่คิวให้ตรวจทีละรายการ';

        list.innerHTML = observations.length === 0
            ? '<li class="observation-item muted">ไม่มีข้อมูลการตรวจพบ</li>'
            : observations.map((o) => `
                <li class="observation-item">
                    <span class="observation-time">${escapeHtml(formatDateTime(o.captured_at))}</span>
                    <span class="observation-meta">
                        <span>ตรวจพบ <b>${Number(o.detected_count)}</b> ชิ้น</span>
                        <span>ความมั่นใจของโมเดล <b>${escapeHtml(fmtConf(o.max_confidence))}</b></span>
                        <span>${escapeHtml(SOURCE_MODE_LABEL[o.source_mode] ?? o.source_mode)}</span>
                    </span>
                </li>`).join('');

        renderPagination(observationPagination, data.pagination, loadObservations);
    } catch (err) {
        list.innerHTML = '<li class="observation-item">เชื่อมต่อ API ไม่สำเร็จ</li>';
    }
}

loadIncident();
