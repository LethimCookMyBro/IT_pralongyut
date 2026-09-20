// หน้าประวัติย้อนหลัง (activity.html) — GET api/activity.php แบบแบ่งหน้าฝั่ง server
// ไม่มีตาราง log แยก: ไทม์ไลน์ derive จาก reports + vision_incidents (ดู lib/activity.php)
// state ทั้งหมดเก็บใน URL query string → refresh หรือแชร์ลิงก์แล้วได้หน้าเดิม

const KIND_LABEL = {
    report: 'คนแจ้งจุดขยะ',
    detect: 'AI พบเหตุใหม่',
    review: 'เจ้าหน้าที่ตรวจ',
    resolve: 'ปิดงาน',
};

const KIND_ICON = {
    report: 'icon-flag',
    detect: 'icon-camera',
    review: 'icon-eye',
    resolve: 'icon-resolve',
};

// สถานะของ reports ใช้คำเดียวกับหน้ารายการแจ้ง
const REPORT_STATUS_LABEL = {
    PENDING: 'รอดำเนินการ',
    NEEDS_CHECK: 'ควรตรวจสอบ',
    RESOLVED: 'ดำเนินการแล้ว',
};

const COLUMN_COUNT = 5;
const SEARCH_DEBOUNCE_MS = 300;
const DEFAULTS = { q: '', days: 90, kind: '', page: 1 };

const tbody = document.querySelector('#activity-table tbody');
const paginationEl = document.getElementById('activity-pagination');
const totalEl = document.getElementById('activity-total');
const searchInput = document.getElementById('filter-q');
const daysSelect = document.getElementById('filter-days');
const kindSelect = document.getElementById('filter-kind');

let state = readQueryState(DEFAULTS);
let requestSeq = 0;

function applyStateToControls() {
    searchInput.value = state.q;
    daysSelect.value = String(state.days);
    kindSelect.value = state.kind;
}

async function loadActivity() {
    tbody.innerHTML = skeletonRows(5, COLUMN_COUNT);
    paginationEl.innerHTML = '';
    totalEl.textContent = '';
    writeQueryState(state, DEFAULTS);

    const seq = ++requestSeq;
    const query = apiQuery({ ...state, per_page: 20 });

    try {
        const res = await fetch(`api/activity.php?${query}`);
        const data = await res.json();
        if (seq !== requestSeq) return;

        if (!res.ok) {
            // ค่า filter ที่ server ไม่รับ (เช่นแก้ URL เอง) → ถอยไปค่าเริ่มต้น
            tbody.innerHTML = emptyState(COLUMN_COUNT, 'โหลดประวัติไม่สำเร็จ', data.error ?? '', 'icon-warning');
            return;
        }

        const { total, total_pages: totalPages } = data.pagination;
        if (totalPages > 0 && state.page > totalPages) {
            state.page = totalPages;
            loadActivity();
            return;
        }

        totalEl.textContent = total === 0
            ? ''
            : `พบ ${total} เหตุการณ์ ในช่วง ${data.days} วันล่าสุด`;
        renderRows(data.activities);
        renderPagination(paginationEl, data.pagination, (page) => {
            state.page = page;
            loadActivity();
            document.getElementById('activity-table').scrollIntoView({ block: 'start' });
        });
    } catch (err) {
        if (seq !== requestSeq) return;
        tbody.innerHTML = emptyState(COLUMN_COUNT, 'เชื่อมต่อ API ไม่สำเร็จ', 'ลองโหลดหน้าใหม่อีกครั้ง', 'icon-warning');
    }
}

function hasFilter() {
    return state.q !== '' || state.kind !== '' || Number(state.days) !== DEFAULTS.days;
}

// ผลลัพธ์ของเหตุการณ์: reports ใช้สถานะของเรื่อง, เหตุจาก AI ใช้สถานะตรวจ/สถานะงาน
function stateCell(row) {
    if (row.kind === 'report') {
        const label = REPORT_STATUS_LABEL[row.state] ?? row.state;
        return `<span class="status-badge ${escapeHtml(row.state)}">${escapeHtml(label)}</span>`;
    }
    return statusBadge(row.kind === 'resolve' ? 'action' : 'review', row.state);
}

// อ้างอิงกลับไปที่ของจริง: เหตุจาก AI เปิดหน้ารายละเอียดได้ เรื่องแจ้งยังไม่มีหน้ารายละเอียด
function refCell(row) {
    if (row.kind === 'report') {
        return `<span class="muted">แจ้ง #${row.ref_id}</span>`;
    }
    return `<a href="incident.html?id=${row.ref_id}">เหตุ #${row.ref_id}</a>`;
}

function renderRows(activities) {
    if (!Array.isArray(activities) || activities.length === 0) {
        tbody.innerHTML = hasFilter()
            ? emptyState(COLUMN_COUNT, 'ไม่พบเหตุการณ์ที่ตรงกับตัวกรอง', 'ลองขยายช่วงเวลาหรือกดล้างตัวกรอง')
            : emptyState(COLUMN_COUNT, 'ยังไม่มีประวัติในช่วงนี้', 'เมื่อมีการแจ้งเรื่อง การตรวจ หรือการปิดงาน รายการจะแสดงที่นี่');
        return;
    }

    tbody.innerHTML = activities.map((row) => {
        const kindLabel = KIND_LABEL[row.kind] ?? row.kind;
        const icon = KIND_ICON[row.kind] ?? 'icon-clock';
        // ป้าย "ข้อมูลสาธิต" ติดที่แถว เพื่อไม่ให้ประวัติเดโมถูกอ่านเป็นเหตุการณ์จริง
        const demoTag = row.record_origin === 'demo_seed'
            ? '<span class="origin-tag demo_seed">ตัวอย่าง</span>'
            : row.record_origin === 'detector_run' ? '<span class="source-pill">Replay</span>' : '';
        return `
            <tr>
                <td data-label="เวลา">${escapeHtml(formatDateTime(row.occurred_at))}</td>
                <td data-label="กิจกรรม">
                    <span class="activity-kind">
                        <svg class="icon" aria-hidden="true"><use href="#${icon}"></use></svg>${escapeHtml(kindLabel)}
                    </span>
                </td>
                <td class="cell-lead">
                    <span class="cell-primary">${escapeHtml(row.location)}${demoTag}</span>
                </td>
                <td data-label="ผลลัพธ์">${stateCell(row)}</td>
                <td data-label="อ้างอิง">${refCell(row)}</td>
            </tr>`;
    }).join('');
}

function updateFilter(patch) {
    state = { ...state, ...patch, page: 1 };
    loadActivity();
}

let searchTimer = null;
searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => updateFilter({ q: searchInput.value.trim() }), SEARCH_DEBOUNCE_MS);
});

document.getElementById('filter-bar').addEventListener('submit', (event) => {
    event.preventDefault();
    clearTimeout(searchTimer);
    updateFilter({ q: searchInput.value.trim() });
});

daysSelect.addEventListener('change', () => updateFilter({ days: Number(daysSelect.value) }));
kindSelect.addEventListener('change', () => updateFilter({ kind: kindSelect.value }));

document.getElementById('filter-reset').addEventListener('click', () => {
    state = { ...DEFAULTS };
    applyStateToControls();
    loadActivity();
});

applyStateToControls();
loadActivity();
