// ศูนย์ตรวจสอบ (vision.html) — คิวเหตุที่ต้องตรวจสอบ
// ข้อมูลการตรวจพบรายครั้งเป็นหลักฐานประกอบ การตรวจของคนทำที่ระดับ "เหตุ" (incident)
//
// Phase D: ตารางเหลือ 7 คอลัมน์ ข้อมูลเทคนิคย้ายไปหน้ารายละเอียด (incident.html?id=…)
// Phase G: ค้นหา + กรองสถานะ/พื้นที่ + แบ่งหน้าฝั่ง server, state อยู่ใน URL
// การเปลี่ยนสถานะเหตุทำที่หน้ารายละเอียดเท่านั้น เพื่อให้มีบริบทครบก่อนตัดสินใจ

// accent-attention = ยังต้องมีคนทำอะไรต่อ ใบอื่นเป็นสถิติอ้างอิง
const SUMMARY_CARD_DEFS = [
    ['pending_review', 'รอตรวจสอบ', 'accent-attention'],
    ['needs_check', 'ต้องดำเนินการ', 'accent-attention'],
    ['confirmed', 'ยืนยันแล้ว', ''],
    ['resolved', 'ดำเนินการแล้ว', ''],
    ['rejected', 'ปฏิเสธ', ''],
    ['total', 'เหตุทั้งหมด', ''],
];

// ตัวเลือกสถานะเดียวในหน้าจอ แปลงเป็น query ของ API ที่มีสองคอลัมน์
// (review_status กับ action_status) — ไม่แก้ contract ของ API
const STATUS_QUERY = {
    pending: { review_status: 'pending' },
    needs_check: { action_status: 'needs_check' },
    resolved: { action_status: 'resolved' },
    rejected: { review_status: 'rejected' },
};

const COLUMN_COUNT = 7;
const SEARCH_DEBOUNCE_MS = 300;
const DEFAULTS = { q: '', status: '', area_type: '', page: 1 };

const tbody = document.querySelector('#vision-table tbody');
const paginationEl = document.getElementById('vision-pagination');
const searchInput = document.getElementById('filter-q');
const statusSelect = document.getElementById('filter-status');
const areaSelect = document.getElementById('filter-area');

let state = readQueryState(DEFAULTS);
let requestSeq = 0;

function applyStateToControls() {
    searchInput.value = state.q;
    statusSelect.value = state.status;
    areaSelect.value = state.area_type;
}

async function loadVision() {
    tbody.innerHTML = skeletonRows(4, COLUMN_COUNT);
    paginationEl.innerHTML = '';
    writeQueryState(state, DEFAULTS);

    const seq = ++requestSeq;
    const query = apiQuery({
        q: state.q,
        area_type: state.area_type,
        page: state.page,
        per_page: 10,
        ...(STATUS_QUERY[state.status] ?? {}),
    });

    try {
        const res = await fetch(`api/vision.php?${query}`);
        const data = await res.json();
        if (seq !== requestSeq) return;

        if (!res.ok) {
            document.getElementById('summary-cards').innerHTML =
                `<p class="msg error">${escapeHtml(data.error ?? 'โหลดข้อมูลไม่สำเร็จ')}</p>`;
            tbody.innerHTML = emptyState(COLUMN_COUNT, 'โหลดคิวไม่สำเร็จ', data.error ?? '', 'icon-warning');
            return;
        }

        const { total_pages: totalPages } = data.pagination;
        if (totalPages > 0 && state.page > totalPages) {
            state.page = totalPages;
            loadVision();
            return;
        }

        renderSummary(data.summary);
        renderTable(data.incidents);
        renderPagination(paginationEl, data.pagination, (page) => {
            state.page = page;
            loadVision();
            document.getElementById('vision-table').scrollIntoView({ block: 'start' });
        });
    } catch (err) {
        if (seq !== requestSeq) return;
        document.getElementById('summary-cards').innerHTML =
            '<p class="msg error">เชื่อมต่อ API ไม่สำเร็จ</p>';
        tbody.innerHTML = emptyState(COLUMN_COUNT, 'เชื่อมต่อ API ไม่สำเร็จ', 'ลองโหลดหน้าใหม่อีกครั้ง', 'icon-warning');
    }
}

// summary เป็นภาพรวมทั้งระบบ ไม่ใช่ผลของตัวกรอง — ระบุไว้ในหัวข้อการ์ดด้วย
function renderSummary(summary) {
    document.getElementById('summary-cards').innerHTML = SUMMARY_CARD_DEFS
        .map(([key, label, accent]) => `
            <div class="card ${accent}">
                <div class="label">${escapeHtml(label)}</div>
                <div class="value num">${Number(summary[key]) || 0}</div>
            </div>`)
        .join('');
}

function hasFilter() {
    return state.q !== '' || state.status !== '' || state.area_type !== '';
}

function renderTable(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
        tbody.innerHTML = hasFilter()
            ? emptyState(COLUMN_COUNT, 'ไม่พบเหตุที่ตรงกับตัวกรอง', 'ลองแก้คำค้นหรือกดล้างตัวกรอง')
            : emptyState(COLUMN_COUNT, 'ยังไม่มีเหตุในคิว', 'เมื่อระบบรวมข้อมูลการตรวจพบได้เป็นเหตุ จะแสดงที่นี่');
        return;
    }

    tbody.innerHTML = rows.map((r) => {
        const id = Number(r.id);
        return `
            <tr>
                <td class="cell-lead">
                    <span class="cell-primary">${escapeHtml(r.location)}</span>
                    <span class="cell-secondary">${areaTag(r.area_type)}</span>
                </td>
                <td data-label="จุด/กล้อง">${escapeHtml(r.camera_name)}</td>
                <td data-label="พบล่าสุด">${escapeHtml(formatDateTime(r.last_seen))}</td>
                <td class="num" data-label="จำนวนครั้งที่พบ">${Number(r.observation_count)}</td>
                <td data-label="แนวโน้ม">${trendTag(r)}</td>
                <td data-label="สถานะ">${statusStack(r)}</td>
                <td class="col-action">
                    <a class="btn btn-ghost btn-sm" href="incident.html?id=${id}">
                        ดูรายละเอียด<svg class="icon" aria-hidden="true"><use href="#icon-arrow-right"></use></svg>
                    </a>
                </td>
            </tr>`;
    }).join('');
}

function updateFilter(patch) {
    state = { ...state, ...patch, page: 1 };
    loadVision();
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

statusSelect.addEventListener('change', () => updateFilter({ status: statusSelect.value }));
areaSelect.addEventListener('change', () => updateFilter({ area_type: areaSelect.value }));

document.getElementById('filter-reset').addEventListener('click', () => {
    state = { ...DEFAULTS };
    applyStateToControls();
    loadVision();
});

applyStateToControls();
loadVision();
