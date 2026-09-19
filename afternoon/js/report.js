// หน้าแจ้งจุดขยะ (report.html) — POST /api/reports.php + แสดงรายการล่าสุด

const STATUS_LABEL = {
    PENDING: 'รอดำเนินการ',
    NEEDS_CHECK: 'ควรตรวจสอบ',
    RESOLVED: 'ดำเนินการแล้ว',
};

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function showMessage(text, type) {
    const el = document.getElementById('form-msg');
    el.textContent = text;
    el.className = `msg ${type}`;
}

async function loadReports() {
    const tbody = document.querySelector('#reports-table tbody');
    tbody.innerHTML = skeletonRows(4, 6);

    try {
        const res = await fetch('api/reports.php');
        if (!res.ok) return;
        const reports = await res.json();
        if (!Array.isArray(reports) || reports.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="empty-state">ยังไม่มีรายการแจ้ง</td></tr>';
            return;
        }
        tbody.innerHTML = reports
            .map((r, i) => `
                <tr style="${staggerStyle(i)}">
                    <td>${escapeHtml(r.location)}</td>
                    <td>${escapeHtml(r.waste_type)}</td>
                    <td class="num">${Number(r.amount_kg)}</td>
                    <td>${escapeHtml(r.detail || '—')}</td>
                    <td><span class="status-badge ${r.status === 'RESOLVED' ? 'resolved' : ''}">${escapeHtml(STATUS_LABEL[r.status] ?? r.status)}</span></td>
                    <td>${escapeHtml(r.created_at)}</td>
                </tr>`)
            .join('');
    } catch (err) {
        // รายการล่าสุดเป็นข้อมูลเสริม ฟอร์มยังคงใช้งานได้
    }
}

async function submitReport(event) {
    event.preventDefault();
    const form = event.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const submitLabel = submitBtn.querySelector('span');
    const originalLabel = submitLabel.textContent;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<svg class="icon icon-spin" aria-hidden="true"><use href="#icon-spinner"></use></svg><span>กำลังส่ง…</span>';

    const payload = {
        location: form.location.value,
        waste_type: form.waste_type.value,
        amount_kg: form.amount_kg.value,
        detail: form.detail.value,
    };

    try {
        const res = await fetch('api/reports.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (!res.ok) {
            showMessage(data.error ?? 'ส่งเรื่องแจ้งไม่สำเร็จ', 'error');
            return;
        }

        showMessage('ส่งเรื่องแจ้งสำเร็จ ขอบคุณที่ช่วยแจ้งจุดขยะ', 'success');
        form.reset();
        updateCharCounter();
        await loadReports();
    } catch (err) {
        showMessage('เชื่อมต่อ API ไม่สำเร็จ', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = `<svg class="icon" aria-hidden="true"><use href="#icon-arrow-right"></use></svg><span>${escapeHtml(originalLabel)}</span>`;
    }
}

function updateCharCounter() {
    const detail = document.getElementById('detail');
    const counter = document.getElementById('detail-counter');
    if (detail && counter) {
        counter.textContent = `${detail.value.length} / 500`;
    }
}

document.getElementById('report-form').addEventListener('submit', submitReport);
document.getElementById('detail').addEventListener('input', updateCharCounter);
loadReports();
