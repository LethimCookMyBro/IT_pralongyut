// หน้ารายการแจ้ง (reports.html) — GET api/reports.php แบบแบ่งหน้าฝั่ง server
// Phase C + G: ค้นหาสถานที่ / กรองประเภท / กรองสถานะ / แบ่งหน้า 10 รายการ
// state ทั้งหมดเก็บใน URL query string → refresh หรือแชร์ลิงก์แล้วได้หน้าเดิม

const STATUS_LABEL = {
    PENDING: 'รอตรวจสอบ',
    NEEDS_CHECK: 'รอดำเนินการ',
    RESOLVED: 'เสร็จแล้ว',
    REJECTED: 'ไม่รับเรื่อง',
};

const WASTE_TYPE_LABEL = {
    general: 'ทั่วไป',
    recyclable: 'รีไซเคิล',
    hazardous: 'อันตราย',
    organic: 'อินทรีย์',
};

const COLUMN_COUNT = 3;
const SEARCH_DEBOUNCE_MS = 300;
const DEFAULTS = { q: '', waste_type: '', status: '', record_origin: '', page: 1 };

const tbody = document.querySelector('#reports-table tbody');
const paginationEl = document.getElementById('reports-pagination');
const searchInput = document.getElementById('filter-q');
const typeSelect = document.getElementById('filter-type');
const statusSelect = document.getElementById('filter-status');
const originSelect = document.getElementById('filter-origin');

let state = readQueryState(DEFAULTS);
let requestSeq = 0;

function applyStateToControls() {
    searchInput.value = state.q;
    typeSelect.value = state.waste_type;
    statusSelect.value = state.status;
    originSelect.value = state.record_origin;
    // ตัวกรองพับไว้เป็นค่าตั้งต้น แต่ถ้ามาจากลิงก์ที่กรองไว้ ต้องกางให้เห็นว่ากรองอะไรอยู่
    document.getElementById('filter-more').open =
        state.waste_type !== '' || state.status !== '' || state.record_origin !== '';
}

async function loadReports() {
    tbody.innerHTML = skeletonRows(Math.min(state.page === 1 ? 5 : 3, 5), COLUMN_COUNT);
    paginationEl.innerHTML = '';
    writeQueryState(state, DEFAULTS);

    // กัน response เก่ามาทับผลใหม่เวลาพิมพ์ค้นหาเร็ว ๆ
    const seq = ++requestSeq;
    const query = apiQuery({ ...state, per_page: 10 });

    try {
        const res = await fetch(`api/reports.php?${query}`);
        const data = await res.json();
        if (seq !== requestSeq) return;

        if (!res.ok) {
            tbody.innerHTML = emptyState(COLUMN_COUNT, 'โหลดรายการไม่สำเร็จ', data.error ?? '', 'icon-warning');
            return;
        }

        // ถ้าอยู่หน้าเกินจำนวนหน้าจริง (เช่น เปลี่ยน filter แล้วเหลือน้อยลง) ให้ถอยไปหน้าสุดท้าย
        const { total_pages: totalPages } = data.pagination;
        if (totalPages > 0 && state.page > totalPages) {
            state.page = totalPages;
            loadReports();
            return;
        }

        renderRows(data.reports);
        renderPagination(paginationEl, data.pagination, (page) => {
            state.page = page;
            loadReports();
            document.getElementById('reports-table').scrollIntoView({ block: 'start' });
        });
    } catch (err) {
        if (seq !== requestSeq) return;
        tbody.innerHTML = emptyState(COLUMN_COUNT, 'เชื่อมต่อ API ไม่สำเร็จ', 'ลองโหลดหน้าใหม่อีกครั้ง', 'icon-warning');
    }
}

function hasFilter() {
    return state.q !== '' || state.waste_type !== '' || state.status !== '' || state.record_origin !== '';
}

function renderRows(reports) {
    if (!Array.isArray(reports) || reports.length === 0) {
        tbody.innerHTML = hasFilter()
            ? emptyState(COLUMN_COUNT, 'ไม่พบรายการที่ตรงกับตัวกรอง', 'ลองแก้คำค้นหรือกดล้างตัวกรอง')
            : emptyState(COLUMN_COUNT, 'ยังไม่มีรายการแจ้ง', 'เมื่อมีคนแจ้งจุดขยะ รายการจะแสดงที่นี่');
        return;
    }

    tbody.innerHTML = reports.map((r) => {
        const status = STATUS_LABEL[r.status] ?? r.status;
        const extra = [r.detail, r.waste_type ? (WASTE_TYPE_LABEL[r.waste_type] ?? r.waste_type) : '',
            r.amount_kg == null ? '' : `${Number(r.amount_kg)} กก.`].filter(Boolean).join(' · ');
        // ป้าย "ตัวอย่าง" ติดกับชื่อจุดเลย เพื่อให้อ่านผ่าน ๆ ก็ไม่เข้าใจผิดว่าเป็นเรื่องจริง
        const demoTag = r.record_origin === 'demo_seed'
            ? '<span class="origin-tag demo_seed">ตัวอย่าง</span>'
            : '';
        // รูปที่ประชาชนแนบมา — path ถูก validate ฝั่ง server แล้ว (null ถ้าไฟล์หาย)
        const photo = r.image_path
            ? `<a class="report-thumb" href="${escapeHtml(r.image_path)}" target="_blank" rel="noopener">
                   <img src="${escapeHtml(r.image_path)}" alt="รูปที่แนบมากับรายการนี้" loading="lazy">
               </a>`
            : '';
        return `
            <tr>
                <td class="cell-lead">
                    <div class="report-item">${photo}<div>
                        <span class="cell-primary">${escapeHtml(r.location)}${demoTag}</span>
                        ${extra ? `<span class="cell-secondary">${escapeHtml(extra)}</span>` : ''}
                    </div></div>
                </td>
                <td data-label="สถานะ"><span class="status-badge ${escapeHtml(r.status)}">${escapeHtml(status)}</span></td>
                <td data-label="เวลาแจ้ง">${escapeHtml(formatDateTime(r.created_at))}</td>
            </tr>`;
    }).join('');
}

// เปลี่ยน filter/ค้นหา = กลับไปหน้า 1 เสมอ
function updateFilter(patch) {
    state = { ...state, ...patch, page: 1 };
    loadReports();
}

let searchTimer = null;
searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => updateFilter({ q: searchInput.value.trim() }), SEARCH_DEBOUNCE_MS);
});

// กด Enter ในช่องค้นหาไม่ต้องรอ debounce และไม่ต้อง submit form (ไม่มี reload)
document.getElementById('filter-bar').addEventListener('submit', (event) => {
    event.preventDefault();
    clearTimeout(searchTimer);
    updateFilter({ q: searchInput.value.trim() });
});

typeSelect.addEventListener('change', () => updateFilter({ waste_type: typeSelect.value }));
statusSelect.addEventListener('change', () => updateFilter({ status: statusSelect.value }));
originSelect.addEventListener('change', () => updateFilter({ record_origin: originSelect.value }));

document.getElementById('filter-reset').addEventListener('click', () => {
    state = { ...DEFAULTS };
    applyStateToControls();
    loadReports();
});

applyStateToControls();
loadReports();
