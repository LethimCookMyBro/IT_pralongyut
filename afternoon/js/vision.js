// ศูนย์ตรวจสอบรวมเรื่องแจ้งจากประชาชนและเหตุที่ AI ตรวจพบ
// การค้นหา ตัวกรอง และ pagination ทำที่ api/review-queue.php ทั้งหมด
const VIEWS = {
    review: {
        title: 'รายการที่รอตรวจสอบ',
        empty: ['ยังไม่มีรายการที่รอตรวจสอบ', 'เรื่องแจ้งและเหตุที่ AI ตรวจพบจะเข้าคิวนี้ทันที'],
    },
    action: {
        title: 'รายการที่รอดำเนินการ',
        empty: ['ไม่มีงานค้าง', 'รายการที่รับเรื่องแล้วจะอยู่ที่นี่จนกว่าจะปิดงาน'],
    },
    history: {
        title: 'ประวัติรายการที่ปิดแล้ว',
        empty: ['ยังไม่มีประวัติ', 'รายการที่ปิดงานหรือไม่รับเรื่องจะมาแสดงที่นี่'],
    },
};

const SEARCH_DEBOUNCE_MS = 300;
const DEFAULTS = { view: 'review', q: '', source: '', area_type: '', page: 1 };
const queueEl = document.getElementById('incident-queue');
const paginationEl = document.getElementById('vision-pagination');
const searchInput = document.getElementById('filter-q');
const sourceSelect = document.getElementById('filter-source');
const areaSelect = document.getElementById('filter-area');
const tabsEl = document.getElementById('view-tabs');
const listTitleEl = document.getElementById('list-title');
const queueTotalEl = document.getElementById('queue-total');
const filterMore = document.getElementById('filter-more');

let state = readQueryState(DEFAULTS);
if (!VIEWS[state.view]) state.view = DEFAULTS.view;
let requestSeq = 0;

function applyStateToControls() {
    searchInput.value = state.q;
    sourceSelect.value = state.source;
    areaSelect.value = state.area_type;
    listTitleEl.textContent = VIEWS[state.view].title;
    filterMore.open = state.source !== '' || state.area_type !== '';
    for (const tab of tabsEl.querySelectorAll('.view-tab')) {
        tab.setAttribute('aria-selected', String(tab.dataset.view === state.view));
    }
}

async function loadVision() {
    queueEl.innerHTML = skeletonQueue(4);
    paginationEl.innerHTML = '';
    writeQueryState(state, DEFAULTS);
    const seq = ++requestSeq;
    const query = apiQuery({ ...state, per_page: 10 });

    try {
        const res = await fetch(`api/review-queue.php?${query}`);
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
        renderQueue(data.items);
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

function renderSummary(summary) {
    for (const el of tabsEl.querySelectorAll('.view-tab-count')) {
        el.textContent = Number(summary[el.dataset.count]) || 0;
    }
    queueTotalEl.textContent = `รายการทั้งหมดในระบบ ${Number(summary.total) || 0} รายการ`;
}

function hasFilter() {
    return state.q !== '' || state.source !== '' || state.area_type !== '';
}

function citizenActions(item) {
    if (item.review_status === 'pending') {
        return `<button type="button" class="btn btn-primary btn-sm" data-report-action="accept">รับเรื่อง</button>
            <button type="button" class="btn-reject btn-sm" data-report-action="reject">ไม่รับเรื่อง</button>`;
    }
    if (item.action_status === 'needs_check') {
        return '<button type="button" class="btn btn-primary btn-sm" data-report-action="resolve">ปิดงาน</button>';
    }
    return '';
}

function renderQueue(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
        const [emptyTitle, emptyHint] = VIEWS[state.view].empty;
        queueEl.innerHTML = hasFilter()
            ? `<li class="queue-empty">${emptyStateBlock('ไม่พบรายการที่ค้นหา', 'ลองใช้คำอื่น หรือดูรายการในกลุ่มนี้ทั้งหมด')}<button type="button" class="btn-ghost" data-empty-reset>ล้างตัวกรอง</button></li>`
            : `<li class="queue-empty">${emptyStateBlock(emptyTitle, emptyHint)}<a class="btn btn-primary" href="detect.html">เปิดตรวจจับ AI <span aria-hidden="true">→</span></a><button type="button" class="btn-ghost" data-empty-refresh>โหลดอีกครั้ง</button></li>`;
        return;
    }

    queueEl.innerHTML = rows.map((item) => {
        const id = Number(item.id);
        const detail = item.source === 'citizen'
            ? [item.detail, item.amount_kg == null ? '' : `${Number(item.amount_kg)} กก.`].filter(Boolean).join(' · ')
            : `${Number(item.observation_count)} การตรวจพบ`;
        const actions = item.source === 'citizen'
            ? citizenActions(item)
            : `<a class="btn btn-ghost btn-sm" href="incident.html?id=${id}">เปิดรายการ<svg class="icon" aria-hidden="true"><use href="#icon-arrow-right"></use></svg></a>`;
        const photo = item.image_path
            ? `<a class="report-thumb" href="${escapeHtml(item.image_path)}" target="_blank" rel="noopener"><img src="${escapeHtml(item.image_path)}" alt="รูปที่ประชาชนแนบ" loading="lazy"></a>`
            : '';
        return `<li class="queue-row" data-source="${escapeHtml(item.source)}" data-id="${id}" data-location="${escapeHtml(item.location)}">
            ${photo}
            <div class="queue-body">
                <span class="queue-place">${escapeHtml(item.location)}</span>
                <span class="queue-meta">${sourceTags(item)}${detail ? `<span>${escapeHtml(detail)}</span>` : ''}<span aria-hidden="true">·</span><span>${escapeHtml(formatRelativeTime(item.last_seen))}</span></span>
            </div>
            <div class="queue-side">${compositeStatusBadge(item)}<div class="review-actions">${actions}</div></div>
        </li>`;
    }).join('');
}

function skeletonQueue(count) {
    return Array.from({ length: count }).map(() => `<li class="queue-row skeleton-row"><div class="queue-body"><span class="skeleton-bar skeleton-bar-lg"></span><span class="skeleton-bar skeleton-bar-sm"></span></div></li>`).join('');
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

queueEl.addEventListener('click', async (event) => {
    if (event.target.closest('[data-empty-reset]')) {
        updateFilter({ q: '', source: '', area_type: '' });
        return;
    }
    if (event.target.closest('[data-empty-refresh]')) {
        loadVision();
        return;
    }

    const button = event.target.closest('button[data-report-action]');
    if (!button) return;
    const row = button.closest('.queue-row');
    const action = button.dataset.reportAction;
    const labels = {
        accept: ['รับเรื่องนี้?', 'รายการจะย้ายไปรอดำเนินการ', 'รับเรื่อง'],
        reject: ['ไม่รับเรื่องนี้?', 'รายการจะปิดเป็นไม่รับเรื่องและย้อนกลับไม่ได้', 'ไม่รับเรื่อง'],
        resolve: ['ปิดงานนี้?', 'ใช้เมื่อดำเนินการในพื้นที่เรียบร้อยแล้ว', 'ปิดงาน'],
    };
    const [title, message, confirmLabel] = labels[action];
    const confirmed = await confirmAction({
        title,
        message,
        meta: row.dataset.location,
        confirmLabel,
        confirmClass: action === 'reject' ? 'btn-reject' : '',
        focusCancel: action === 'reject',
    });
    if (!confirmed) return;

    button.disabled = true;
    try {
        const res = await fetch('api/report-review.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: Number(row.dataset.id), action }),
        });
        const data = await res.json();
        if (!res.ok) {
            showToast(data.error ?? 'ทำรายการไม่สำเร็จ', 'error');
            return;
        }
        showToast('บันทึกแล้ว', 'success');
        await loadVision();
    } catch (err) {
        showToast('เชื่อมต่อ API ไม่สำเร็จ', 'error');
    } finally {
        button.disabled = false;
    }
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
sourceSelect.addEventListener('change', () => updateFilter({ source: sourceSelect.value }));
areaSelect.addEventListener('change', () => updateFilter({ area_type: areaSelect.value }));
document.getElementById('filter-reset').addEventListener('click', () => {
    state = { ...DEFAULTS, view: state.view };
    applyStateToControls();
    loadVision();
});

applyStateToControls();
loadVision();
