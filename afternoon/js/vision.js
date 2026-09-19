// ศูนย์ตรวจสอบ (vision.html) — คิวเหตุที่ต้องตรวจสอบ
// ข้อมูลการตรวจพบรายครั้งเป็นหลักฐานประกอบ การตรวจของคนทำที่ระดับ "เหตุ" (incident)
//
// Phase D: ตารางเหลือ 7 คอลัมน์ ข้อมูลเทคนิคย้ายไปหน้ารายละเอียด (incident.html?id=…)
// Phase G: ค้นหา + กรองสถานะ/พื้นที่ + แบ่งหน้าฝั่ง server, state อยู่ใน URL
// การเปลี่ยนสถานะเหตุทำที่หน้ารายละเอียดเท่านั้น เพื่อให้มีบริบทครบก่อนตัดสินใจ

// กลุ่มงานสามกลุ่ม — ชื่อกลุ่มตรงกับ view ของ api/vision.php
// เปิดหน้ามาอยู่ที่ "ต้องตรวจ" เสมอ เพราะเป็นงานที่ค้างอยู่กับคน
const VIEWS = {
    review: {
        title: 'เหตุที่รอการตรวจ',
        empty: ['ไม่มีเหตุรอตรวจ', 'ตรวจครบแล้ว เหตุใหม่จะมาแสดงที่นี่'],
    },
    action: {
        title: 'เหตุที่ยืนยันแล้วและยังไม่ปิดงาน',
        empty: ['ไม่มีงานค้าง', 'เหตุที่ยืนยันแล้วถูกปิดงานครบแล้ว'],
    },
    history: {
        title: 'ประวัติเหตุที่ปิดแล้ว',
        empty: ['ยังไม่มีประวัติ', 'เหตุที่ปิดงานหรือถูกปฏิเสธจะมาแสดงที่นี่'],
    },
};

const COLUMN_COUNT = 7;
const SEARCH_DEBOUNCE_MS = 300;
const DEFAULTS = { view: 'review', q: '', area_type: '', page: 1 };

const tbody = document.querySelector('#vision-table tbody');
const paginationEl = document.getElementById('vision-pagination');
const searchInput = document.getElementById('filter-q');
const areaSelect = document.getElementById('filter-area');
const tabsEl = document.getElementById('view-tabs');
const listTitleEl = document.getElementById('list-title');
const queueTotalEl = document.getElementById('queue-total');

let state = readQueryState(DEFAULTS);
// ค่า view จาก URL อาจถูกพิมพ์มั่ว — กันไม่ให้หน้าพังเพราะ API ตอบ 422
if (!VIEWS[state.view]) state.view = DEFAULTS.view;
let requestSeq = 0;

function applyStateToControls() {
    searchInput.value = state.q;
    areaSelect.value = state.area_type;
    listTitleEl.textContent = VIEWS[state.view].title;
    for (const tab of tabsEl.querySelectorAll('.view-tab')) {
        tab.setAttribute('aria-selected', String(tab.dataset.view === state.view));
    }
}

async function loadVision() {
    tbody.innerHTML = skeletonRows(4, COLUMN_COUNT);
    paginationEl.innerHTML = '';
    writeQueryState(state, DEFAULTS);

    const seq = ++requestSeq;
    const query = apiQuery({
        view: state.view,
        q: state.q,
        area_type: state.area_type,
        page: state.page,
        per_page: 10,
    });

    try {
        const res = await fetch(`api/vision.php?${query}`);
        const data = await res.json();
        if (seq !== requestSeq) return;

        if (!res.ok) {
            queueTotalEl.textContent = '';
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

// ตัวเลขบนแท็บเป็นภาพรวมทั้งระบบ ไม่ใช่ผลของคำค้น/ตัวกรอง
function renderSummary(summary) {
    for (const el of tabsEl.querySelectorAll('.view-tab-count')) {
        el.textContent = Number(summary[el.dataset.count]) || 0;
    }
    queueTotalEl.textContent = `เหตุทั้งหมดในระบบ ${Number(summary.total) || 0} รายการ`;
}

function hasFilter() {
    return state.q !== '' || state.area_type !== '';
}

function renderTable(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
        const [emptyTitle, emptyHint] = VIEWS[state.view].empty;
        tbody.innerHTML = hasFilter()
            ? emptyState(COLUMN_COUNT, 'ไม่พบเหตุที่ตรงกับตัวกรอง', 'ลองแก้คำค้นหรือกดล้างตัวกรอง')
            : emptyState(COLUMN_COUNT, emptyTitle, emptyHint);
        return;
    }

    tbody.innerHTML = rows.map((r) => {
        const id = Number(r.id);
        return `
            <tr>
                <td class="cell-lead">
                    <span class="cell-primary">${escapeHtml(r.location)}</span>
                    <span class="cell-secondary">${areaTag(r.area_type)} ${originTag(r.record_origin)}</span>
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
    applyStateToControls();
    loadVision();
}

tabsEl.addEventListener('click', (event) => {
    const tab = event.target.closest('.view-tab');
    if (!tab || tab.dataset.view === state.view) return;
    updateFilter({ view: tab.dataset.view });
});

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

areaSelect.addEventListener('change', () => updateFilter({ area_type: areaSelect.value }));

// ล้างเฉพาะคำค้น/ตัวกรอง — ยังอยู่กลุ่มงานเดิม
document.getElementById('filter-reset').addEventListener('click', () => {
    state = { ...DEFAULTS, view: state.view };
    applyStateToControls();
    loadVision();
});

applyStateToControls();
loadVision();
