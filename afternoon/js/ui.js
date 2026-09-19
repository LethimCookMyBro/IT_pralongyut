// Bangsaen Waste Vision — shared UI helpers
// โหลดก่อนสคริปต์เฉพาะหน้าเสมอ (ไม่มี framework/module system — ผูกกับ global scope ตรง ๆ)
// มี: icon sprite, escapeHtml, toast, skeleton, วันที่แบบไทย, pagination, confirm dialog, URL state

/* ---------- Icon sprite ---------- */
// เก็บ symbol ไว้ที่เดียว ทุกหน้าใช้ <use href="#icon-..."> ได้เหมือนกัน
// (เดิม sprite ถูก copy ซ้ำใน HTML ทุกไฟล์ ทำให้ไอคอนหลุดกันเวลาเพิ่มหน้าใหม่)
const ICON_SPRITE = `
<symbol id="icon-chart" viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/><line x1="2" y1="20" x2="22" y2="20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
<symbol id="icon-flag" viewBox="0 0 24 24"><path d="M5 21V4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" fill="none"/><path d="M5 4h13l-3.5 4L18 12H5" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" fill="none"/></symbol>
<symbol id="icon-list" viewBox="0 0 24 24"><line x1="9" y1="7" x2="20" y2="7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><line x1="9" y1="12" x2="20" y2="12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><line x1="9" y1="17" x2="20" y2="17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="4.6" cy="7" r="1.3" fill="currentColor" stroke="none"/><circle cx="4.6" cy="12" r="1.3" fill="currentColor" stroke="none"/><circle cx="4.6" cy="17" r="1.3" fill="currentColor" stroke="none"/></symbol>
<symbol id="icon-eye" viewBox="0 0 24 24"><path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" fill="none"/><circle cx="12" cy="12" r="2.6" stroke="currentColor" stroke-width="1.8" fill="none"/></symbol>
<symbol id="icon-camera" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2" stroke="currentColor" stroke-width="1.8" fill="none"/><path d="M8 7l1.6-2.4A1 1 0 0 1 10.4 4h3.2a1 1 0 0 1 .8.6L16 7" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" fill="none"/><circle cx="12" cy="13.5" r="3.4" stroke="currentColor" stroke-width="1.8" fill="none"/></symbol>
<symbol id="icon-warning" viewBox="0 0 24 24"><path d="M12 3.5 21.5 20h-19L12 3.5z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" fill="none"/><line x1="12" y1="9.5" x2="12" y2="14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="17" r="1" fill="currentColor" stroke="none"/></symbol>
<symbol id="icon-check" viewBox="0 0 24 24"><polyline points="5 12.5 10 17.5 19 6.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
<symbol id="icon-x" viewBox="0 0 24 24"><line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>
<symbol id="icon-resolve" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8" fill="none"/><polyline points="7.5 12.5 10.5 15.5 16.5 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
<symbol id="icon-chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
<symbol id="icon-trend-up" viewBox="0 0 24 24"><polyline points="3 16 9 10 13 14 21 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/><polyline points="14 5 21 5 21 12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
<symbol id="icon-trend-down" viewBox="0 0 24 24"><polyline points="3 8 9 14 13 10 21 19" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/><polyline points="14 19 21 19 21 12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
<symbol id="icon-trend-flat" viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><polyline points="16 8 21 12 16 16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
<symbol id="icon-land" viewBox="0 0 24 24"><circle cx="9" cy="8" r="4" stroke="currentColor" stroke-width="1.8" fill="none"/><line x1="9" y1="12" x2="9" y2="20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><line x1="6" y1="20" x2="12" y2="20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
<symbol id="icon-water" viewBox="0 0 24 24"><path d="M2 9c2-2 4-2 6 0s4 2 6 0 4-2 6 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" fill="none"/><path d="M2 15c2-2 4-2 6 0s4 2 6 0 4-2 6 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" fill="none"/></symbol>
<symbol id="icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8" fill="none"/><polyline points="12 7 12 12 15.5 14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
<symbol id="icon-arrow-right" viewBox="0 0 24 24"><line x1="4" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><polyline points="13 6 19 12 13 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
<symbol id="icon-arrow-left" viewBox="0 0 24 24"><line x1="20" y1="12" x2="5" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><polyline points="11 6 5 12 11 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
<symbol id="icon-spinner" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-dasharray="42 100"/></symbol>
<symbol id="icon-pin" viewBox="0 0 24 24"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" fill="none"/><circle cx="12" cy="10" r="2.6" stroke="currentColor" stroke-width="1.8" fill="none"/></symbol>
<symbol id="icon-crosshair" viewBox="0 0 24 24"><circle cx="12" cy="12" r="7.5" stroke="currentColor" stroke-width="1.8" fill="none"/><circle cx="12" cy="12" r="2" stroke="currentColor" stroke-width="1.8" fill="none"/><line x1="12" y1="1.5" x2="12" y2="5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><line x1="12" y1="19" x2="12" y2="22.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><line x1="1.5" y1="12" x2="5" y2="12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><line x1="19" y1="12" x2="22.5" y2="12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
<symbol id="icon-inbox" viewBox="0 0 24 24"><path d="M3 13.5 5.5 5h13L21 13.5V19a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-5.5z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" fill="none"/><path d="M3 13.5h5l1.2 2.2h5.6L16 13.5h5" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" fill="none"/></symbol>
`;

(function injectIconSprite() {
    if (document.getElementById('icon-sprite')) return;
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.id = 'icon-sprite';
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    svg.setAttribute('style', 'position:absolute;width:0;height:0;overflow:hidden');
    svg.innerHTML = `<defs>${ICON_SPRITE}</defs>`;
    document.body.prepend(svg);
})();

function icon(name, extraClass = '') {
    return `<svg class="icon ${extraClass}" aria-hidden="true"><use href="#${name}"></use></svg>`;
}

/* ---------- Escaping ---------- */
function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

/* ---------- Date / time ---------- */
const DATE_TIME_FORMAT = new Intl.DateTimeFormat('th-TH', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

// MySQL DATETIME ("2026-09-19 14:40:05") → "19 ก.ย. 2569 14:40"
// ถ้าแปลงไม่ได้ให้คืนค่าดิบ ดีกว่าโชว์ "Invalid Date"
function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    return DATE_TIME_FORMAT.format(date);
}

/* ---------- Toast ---------- */
const TOAST_ICON = {
    success: 'icon-check',
    error: 'icon-x',
    info: 'icon-clock',
};

function getToastRegion() {
    let region = document.getElementById('toast-region');
    if (!region) {
        region = document.createElement('div');
        region.id = 'toast-region';
        region.className = 'toast-region';
        region.setAttribute('role', 'status');
        region.setAttribute('aria-live', 'polite');
        document.body.appendChild(region);
    }
    return region;
}

// แจ้งเตือนแบบ toast แทน alert() — auto-dismiss, กด x ปิดเองได้, hover เพื่อหยุดนับเวลา
function showToast(message, type = 'info', duration = 4000) {
    const region = getToastRegion();
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        ${icon(TOAST_ICON[type] || 'icon-clock')}
        <span class="toast-message"></span>
        <button type="button" class="toast-close" aria-label="ปิดข้อความแจ้งเตือน">${icon('icon-x')}</button>
        <span class="toast-timer" style="animation-duration:${duration}ms"></span>`;
    toast.querySelector('.toast-message').textContent = message;

    let timer = null;
    const close = () => {
        clearTimeout(timer);
        toast.classList.add('toast-leaving');
        setTimeout(() => toast.remove(), 200);
    };
    toast.querySelector('.toast-close').addEventListener('click', close);
    timer = setTimeout(close, duration);
    toast.addEventListener('mouseenter', () => clearTimeout(timer));
    toast.addEventListener('mouseleave', () => { timer = setTimeout(close, 1200); });

    region.appendChild(toast);
    return toast;
}

/* ---------- Skeletons ---------- */
function skeletonRows(count, cols) {
    return Array.from({ length: count })
        .map(() => `<tr class="skeleton-row">${'<td><span class="skeleton-bar"></span></td>'.repeat(cols)}</tr>`)
        .join('');
}

function skeletonCards(count = 6) {
    return Array.from({ length: count })
        .map(() => '<div class="card card-skeleton"><span class="skeleton-bar skeleton-bar-sm"></span><span class="skeleton-bar skeleton-bar-lg"></span></div>')
        .join('');
}

function emptyState(colspan, title, hint = '', iconName = 'icon-inbox') {
    return `<tr><td colspan="${colspan}" class="empty-state">
        ${icon(iconName)}
        <span class="empty-state-title">${escapeHtml(title)}</span>
        ${hint ? `<span>${escapeHtml(hint)}</span>` : ''}
    </td></tr>`;
}

/* ---------- Pagination ---------- */
// เลขหน้าที่จะแสดง: หน้าแรก, หน้าสุดท้าย, และหน้ารอบ ๆ หน้าปัจจุบัน คั่นด้วย …
function paginationRange(page, totalPages) {
    if (totalPages <= 7) {
        return Array.from({ length: totalPages }, (_, i) => i + 1);
    }
    const wanted = [1, totalPages, page, page - 1, page + 1]
        .filter((p) => p >= 1 && p <= totalPages)
        .sort((a, b) => a - b);

    const out = [];
    let prev = 0;
    for (const p of wanted) {
        if (p === prev) continue;
        if (prev && p - prev > 1) out.push('gap');
        out.push(p);
        prev = p;
    }
    return out;
}

// container = element ว่าง ๆ, pagination = { page, per_page, total, total_pages }
// onNavigate(page) จะถูกเรียกเมื่อผู้ใช้กดเปลี่ยนหน้า
// ถ้ามีหน้าเดียว จะแสดงแค่บรรทัดจำนวน ไม่ใส่ปุ่มให้รก
function renderPagination(container, pagination, onNavigate) {
    if (!container) return;
    const total = Number(pagination?.total) || 0;
    const perPage = Number(pagination?.per_page) || 10;
    const page = Number(pagination?.page) || 1;
    const totalPages = Number(pagination?.total_pages) || 0;

    if (total === 0) {
        container.innerHTML = '';
        return;
    }

    const from = (page - 1) * perPage + 1;
    const to = Math.min(page * perPage, total);
    const status = from > total
        ? `ไม่มีรายการในหน้า ${page} (ทั้งหมด ${total} รายการ)`
        : `แสดง ${from}–${to} จาก ${total} รายการ`;

    if (totalPages <= 1) {
        container.innerHTML = `<p class="pagination-status">${escapeHtml(status)}</p>`;
        container.removeAttribute('aria-label');
        container.onclick = null;
        return;
    }

    const items = paginationRange(page, totalPages).map((entry) => {
        if (entry === 'gap') {
            return '<li aria-hidden="true" class="pagination-gap">…</li>';
        }
        const current = entry === page;
        return `<li><button type="button" data-page="${entry}"
            ${current ? 'aria-current="page"' : ''}
            aria-label="หน้า ${entry}">${entry}</button></li>`;
    }).join('');

    container.setAttribute('aria-label', 'แบ่งหน้ารายการ');
    container.innerHTML = `
        <p class="pagination-status">${escapeHtml(status)}</p>
        <ul class="pagination-list">
            <li><button type="button" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>ก่อนหน้า</button></li>
            ${items}
            <li><button type="button" data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''}>ถัดไป</button></li>
        </ul>`;

    // assignment ไม่ใช่ addEventListener — render ซ้ำแล้ว handler ไม่ทับกัน
    container.onclick = (event) => {
        const btn = event.target.closest('button[data-page]');
        if (!btn || btn.disabled) return;
        const target = Number(btn.dataset.page);
        if (!Number.isInteger(target) || target < 1 || target > totalPages || target === page) return;
        onNavigate(target);
    };
}

/* ---------- Confirm dialog ---------- */
// ใช้ <dialog>.showModal() ของ browser: ได้ focus trap / Esc / backdrop มาให้เอง
// คืน Promise<boolean> — true เฉพาะเมื่อผู้ใช้กดปุ่มยืนยัน
let dialogSeq = 0;
function confirmAction({
    title,
    message,
    meta = '',
    confirmLabel = 'ยืนยัน',
    cancelLabel = 'ยกเลิก',
    confirmClass = '',
    focusCancel = false,
}) {
    return new Promise((resolve) => {
        const titleId = `confirm-dialog-title-${++dialogSeq}`;
        const dialog = document.createElement('dialog');
        dialog.className = 'confirm-dialog';
        dialog.setAttribute('aria-labelledby', titleId);
        dialog.innerHTML = `
            <form method="dialog">
                <div class="confirm-dialog-body">
                    <h2 class="confirm-dialog-title" id="${titleId}"></h2>
                    <p class="confirm-dialog-message"></p>
                    ${meta ? '<p class="confirm-dialog-meta"></p>' : ''}
                </div>
                <div class="confirm-dialog-actions">
                    <button type="submit" value="cancel" class="btn-ghost"></button>
                    <button type="submit" value="confirm" class="${escapeHtml(confirmClass)}"></button>
                </div>
            </form>`;

        dialog.querySelector('.confirm-dialog-title').textContent = title;
        dialog.querySelector('.confirm-dialog-message').textContent = message;
        if (meta) {
            dialog.querySelector('.confirm-dialog-meta').textContent = meta;
        }
        const cancelBtn = dialog.querySelector('button[value="cancel"]');
        const confirmBtn = dialog.querySelector('button[value="confirm"]');
        cancelBtn.textContent = cancelLabel;
        confirmBtn.textContent = confirmLabel;

        // ห้ามพึ่ง event "close" อย่างเดียว:
        // ตรวจบน Chrome 153 แล้วพบว่า dialog ปิดจริงและตั้ง returnValue ให้ถูกต้อง
        // แต่ไม่ยิง close ทำให้ Promise ค้างและ <dialog> ค้างอยู่ใน DOM
        // จึงฟังทุกทางที่ปิดได้: submit (กดปุ่ม/Enter), cancel (Esc), close (browser ที่ยิงปกติ)
        // มี settled กันไม่ให้ resolve ซ้ำ
        let settled = false;
        const finish = (confirmed) => {
            if (settled) return;
            settled = true;
            if (dialog.open) {
                dialog.close();
            }
            dialog.remove();
            resolve(confirmed);
        };

        dialog.querySelector('form').addEventListener('submit', (event) => {
            finish(event.submitter?.value === 'confirm');
        });
        // cancel = ผู้ใช้ไม่ยืนยัน เสมอ (Esc/backdrop)
        dialog.addEventListener('cancel', () => finish(false));
        dialog.addEventListener('close', () => finish(dialog.returnValue === 'confirm'));

        document.body.appendChild(dialog);
        dialog.showModal();
        // action ที่ปิดทางเดิน (reject) ให้ default focus อยู่ที่ "ยกเลิก"
        (focusCancel ? cancelBtn : confirmBtn).focus();
    });
}

/* ---------- URL query state ---------- */
// เก็บ filter/search/page ไว้ใน query string เพื่อ refresh หรือแชร์ลิงก์แล้ว state ไม่หาย
// ใช้ replaceState เพื่อไม่ให้ทุกการพิมพ์ในช่องค้นหากลายเป็นประวัติปุ่ม Back
function readQueryState(defaults) {
    const params = new URLSearchParams(window.location.search);
    const state = { ...defaults };
    for (const key of Object.keys(defaults)) {
        if (!params.has(key)) continue;
        const raw = params.get(key);
        if (typeof defaults[key] === 'number') {
            const parsed = Number.parseInt(raw, 10);
            state[key] = Number.isInteger(parsed) && parsed > 0 ? parsed : defaults[key];
        } else {
            state[key] = raw;
        }
    }
    return state;
}

function writeQueryState(state, defaults) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(state)) {
        if (value === '' || value === null || value === undefined) continue;
        if (defaults && value === defaults[key]) continue;
        params.set(key, String(value));
    }
    const qs = params.toString();
    window.history.replaceState(null, '', qs ? `?${qs}` : window.location.pathname);
}

// คืน query string สำหรับยิง API (ตัดค่าว่างออก)
function apiQuery(params) {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value === '' || value === null || value === undefined) continue;
        search.set(key, String(value));
    }
    return search.toString();
}
