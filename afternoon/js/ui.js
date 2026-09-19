// Bangsaen Waste Vision — shared UI helpers (toast, skeleton, motion)
// โหลดก่อนสคริปต์เฉพาะหน้าเสมอ (ไม่มี framework/module system — ผูกกับ global scope ตรง ๆ)

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
        <svg class="icon" aria-hidden="true"><use href="#${TOAST_ICON[type] || 'icon-clock'}"></use></svg>
        <span class="toast-message"></span>
        <button type="button" class="toast-close" aria-label="ปิดข้อความแจ้งเตือน">
            <svg class="icon" aria-hidden="true"><use href="#icon-x"></use></svg>
        </button>
        <span class="toast-timer" style="animation-duration:${duration}ms"></span>`;
    toast.querySelector('.toast-message').textContent = message;

    let timer = null;
    const close = () => {
        clearTimeout(timer);
        toast.classList.add('toast-leaving');
        toast.addEventListener('animationend', () => toast.remove(), { once: true });
    };
    toast.querySelector('.toast-close').addEventListener('click', close);
    timer = setTimeout(close, duration);
    toast.addEventListener('mouseenter', () => clearTimeout(timer));
    toast.addEventListener('mouseleave', () => { timer = setTimeout(close, 1200); });

    region.appendChild(toast);
    return toast;
}

// ใส่ animation-delay แบบ stagger ให้ card/row ที่ render จาก array
function staggerStyle(index, stepMs = 40, maxMs = 400) {
    return `animation-delay:${Math.min(index * stepMs, maxMs)}ms`;
}

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
