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
        empty: ['ยังไม่มีเหตุที่ต้องตรวจ', 'เหตุใหม่จะมาแสดงที่นี่เมื่อ AI ส่งเข้าคิว'],
    },
    action: {
        title: 'เหตุที่ยืนยันแล้วและยังไม่ปิดงาน',
        empty: ['ไม่มีงานค้าง', 'เหตุที่ยืนยันแล้วและรอดำเนินการจะอยู่ที่นี่'],
    },
    history: {
        title: 'ประวัติเหตุที่ปิดแล้ว',
        empty: ['ยังไม่มีประวัติ', 'เหตุที่ปิดงานหรือถูกปฏิเสธจะมาแสดงที่นี่'],
    },
};

const SEARCH_DEBOUNCE_MS = 300;
const DEFAULTS = { view: 'review', q: '', area_type: '', page: 1 };

const queueEl = document.getElementById('incident-queue');
const paginationEl = document.getElementById('vision-pagination');
const searchInput = document.getElementById('filter-q');
const areaSelect = document.getElementById('filter-area');
const tabsEl = document.getElementById('view-tabs');
const listTitleEl = document.getElementById('list-title');
const queueTotalEl = document.getElementById('queue-total');
const filterMore = document.getElementById('filter-more');

let state = readQueryState(DEFAULTS);
// ค่า view จาก URL อาจถูกพิมพ์มั่ว — กันไม่ให้หน้าพังเพราะ API ตอบ 422
if (!VIEWS[state.view]) state.view = DEFAULTS.view;
let requestSeq = 0;

function applyStateToControls() {
    searchInput.value = state.q;
    areaSelect.value = state.area_type;
    listTitleEl.textContent = VIEWS[state.view].title;
    // ตัวกรองพับไว้ แต่ถ้ามาจากลิงก์ที่กรองไว้ต้องกางให้เห็นว่ากรองอะไรอยู่
    filterMore.open = state.area_type !== '';
    for (const tab of tabsEl.querySelectorAll('.view-tab')) {
        tab.setAttribute('aria-selected', String(tab.dataset.view === state.view));
    }
}

async function loadVision() {
    queueEl.innerHTML = skeletonQueue(4);
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
            queueEl.innerHTML = `<li>${emptyStateBlock('โหลดคิวไม่สำเร็จ', data.error ?? '', 'icon-warning')}</li>`;
            return;
        }

        const { total_pages: totalPages } = data.pagination;
        if (totalPages > 0 && state.page > totalPages) {
            state.page = totalPages;
            loadVision();
            return;
        }

        renderSummary(data.summary);
        renderQueue(data.incidents);
        renderPagination(paginationEl, data.pagination, (page) => {
            state.page = page;
            loadVision();
            queueEl.scrollIntoView({ block: 'start' });
        });
    } catch (err) {
        if (seq !== requestSeq) return;
        queueEl.innerHTML = `<li>${emptyStateBlock('เชื่อมต่อ API ไม่สำเร็จ', 'ลองโหลดหน้าใหม่อีกครั้ง', 'icon-warning')}</li>`;
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

function renderQueue(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
        const [emptyTitle, emptyHint] = VIEWS[state.view].empty;
        queueEl.innerHTML = hasFilter()
            ? `<li class="queue-empty">${emptyStateBlock('ไม่พบรายการที่ค้นหา', 'ลองใช้คำอื่น หรือดูรายการในกลุ่มนี้ทั้งหมด')}<button type="button" class="btn-ghost" data-empty-reset>ล้างตัวกรอง</button></li>`
            : `<li class="queue-empty">${emptyStateBlock(emptyTitle, emptyHint)}<a class="btn btn-primary" href="detect.html">เปิดตรวจจับ AI <span aria-hidden="true">→</span></a><button type="button" class="btn-ghost" data-empty-refresh>โหลดอีกครั้ง</button></li>`;
        return;
    }

    // หนึ่งแถว = หนึ่งประโยคที่อ่านจบใน 1-2 วินาที
    // "ที่ไหน" ตัวใหญ่ / "พบกี่ครั้ง เมื่อไหร่" บรรทัดรอง / สถานะ + ปุ่มเดียว
    queueEl.innerHTML = rows.map((r) => {
        const id = Number(r.id);
        const count = Number(r.observation_count);
        const repeat = count > 1 ? `พบซ้ำ ${count} ครั้ง` : 'พบ 1 ครั้ง';
        const who = r.record_origin === 'demo_seed'
            ? '<span class="origin-tag demo_seed">ตัวอย่าง</span>'
            : '<span class="source-pill">AI</span>';
        return `
            <li class="queue-row">
                <div class="queue-body">
                    <span class="queue-place">${escapeHtml(r.location)}</span>
                    <span class="queue-meta">
                        ${who}
                        <span>${escapeHtml(repeat)}</span>
                        <span aria-hidden="true">·</span>
                        <span>${escapeHtml(formatRelativeTime(r.last_seen))}</span>
                    </span>
                </div>
                <div class="queue-side">
                    ${statusStack(r)}
                    <a class="btn btn-ghost btn-sm" href="incident.html?id=${id}">
                        ตรวจสอบ<svg class="icon" aria-hidden="true"><use href="#icon-arrow-right"></use></svg>
                    </a>
                </div>
            </li>`;
    }).join('');
}

// skeleton ของคิวต้องหน้าตาเหมือนแถวจริง ไม่งั้นหน้ากระตุกตอนข้อมูลมา
function skeletonQueue(count) {
    return Array.from({ length: count }).map(() => `
        <li class="queue-row skeleton-row">
            <div class="queue-body">
                <span class="skeleton-bar skeleton-bar-lg"></span>
                <span class="skeleton-bar skeleton-bar-sm"></span>
            </div>
        </li>`).join('');
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

queueEl.addEventListener('click', (event) => {
    if (event.target.closest('[data-empty-reset]')) updateFilter({ q: '', area_type: '' });
    if (event.target.closest('[data-empty-refresh]')) loadVision();
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
