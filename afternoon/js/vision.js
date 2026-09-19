// Waste Vision Dashboard — Incident Queue.
// Raw observations are drill-down evidence; Human Review happens at Incident level.

const REVIEW_LABEL = {
    pending: 'รอตรวจสอบ (PENDING REVIEW)',
    confirmed: 'ยืนยันโดยเจ้าหน้าที่ (CONFIRMED)',
    rejected: 'ปฏิเสธ (REJECTED)',
};

const REVIEW_ICON = {
    pending: 'icon-clock',
    confirmed: 'icon-check',
    rejected: 'icon-x',
};

const ACTION_LABEL = {
    none: '—',
    needs_check: 'ควรไปตรวจ/ดำเนินการ (NEEDS CHECK)',
    resolved: 'ดำเนินการแล้ว (RESOLVED)',
};

const ACTION_ICON = {
    none: null,
    needs_check: 'icon-warning',
    resolved: 'icon-resolve',
};

// accent-attention = ต้องมีคนทำอะไรต่อ (pending_review, needs_check) การ์ดอื่นเป็นสถิติอ้างอิง ให้เรียบ
const SUMMARY_CARD_DEFS = [
    ['pending_review', 'Incidents รอตรวจสอบ', 'accent-attention'],
    ['confirmed', 'ยืนยันแล้ว', ''],
    ['rejected', 'ปฏิเสธ', ''],
    ['needs_check', 'ต้องดำเนินการ', 'accent-attention'],
    ['resolved', 'Resolved', ''],
    ['total', 'Incidents ทั้งหมด', ''],
];

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

const fmtConf = (v) => (v === null || v === undefined ? '—' : `${(Number(v) * 100).toFixed(1)}%`);

function areaTag(v) {
    const icon = v === 'water' ? 'icon-water' : 'icon-land';
    const label = v === 'water' ? 'ทางน้ำ (water)' : 'บนบก (land)';
    return `<span class="area-tag"><svg class="icon" aria-hidden="true"><use href="#${icon}"></use></svg>${escapeHtml(label)}</span>`;
}

function trendInfo(incident) {
    const n = Number(incident.observation_count);
    if (n <= 1) return { icon: 'icon-trend-flat', cls: 'trend-flat', label: 'ยังไม่มีแนวโน้ม' };
    const first = Number(incident.first_detected_count);
    const latest = Number(incident.latest_detected_count);
    if (latest > first) return { icon: 'icon-trend-up', cls: 'trend-up', label: 'เพิ่มขึ้น' };
    if (latest < first) return { icon: 'icon-trend-down', cls: 'trend-down', label: 'ลดลง' };
    return { icon: 'icon-trend-flat', cls: 'trend-flat', label: 'คงที่' };
}

function statusBadge(kind, value) {
    const label = kind === 'review' ? (REVIEW_LABEL[value] ?? value) : (ACTION_LABEL[value] ?? value);
    const icon = kind === 'review' ? REVIEW_ICON[value] : ACTION_ICON[value];
    const iconHtml = icon ? `<svg class="icon" aria-hidden="true"><use href="#${icon}"></use></svg>` : '';
    return `<span class="status-badge ${escapeHtml(value)}">${iconHtml}${escapeHtml(label)}</span>`;
}

async function loadVision() {
    document.querySelector('#vision-table tbody').innerHTML = skeletonRows(3, 11);

    try {
        const res = await fetch('api/vision.php');
        const data = await res.json();
        if (!res.ok) {
            document.getElementById('summary-cards').innerHTML =
                '<p class="msg error">โหลดข้อมูล Waste Vision ไม่สำเร็จ</p>';
            return;
        }
        renderSummary(data.summary);
        renderTable(data.incidents);
    } catch (err) {
        document.getElementById('summary-cards').innerHTML =
            '<p class="msg error">เชื่อมต่อ Waste Vision API ไม่สำเร็จ</p>';
    }
}

function renderSummary(summary) {
    document.getElementById('summary-cards').innerHTML = SUMMARY_CARD_DEFS
        .map(([key, label, accent], i) => `
            <div class="card ${accent}" style="${staggerStyle(i)}">
                <div class="label">${escapeHtml(label)}</div>
                <div class="value num">${Number(summary[key]) || 0}</div>
            </div>`)
        .join('');
}

function renderTable(rows) {
    const tbody = document.querySelector('#vision-table tbody');
    if (!Array.isArray(rows) || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" class="empty-state">ยังไม่มี Incident</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map((r, i) => {
        const id = Number(r.id);
        const trend = trendInfo(r);
        return `
        <tr style="${staggerStyle(i)}">
            <td>${escapeHtml(r.first_seen)}</td>
            <td>${escapeHtml(r.last_seen)}</td>
            <td>${escapeHtml(r.camera_name)}</td>
            <td>${escapeHtml(r.location)}</td>
            <td>${areaTag(r.area_type)}</td>
            <td class="num">${Number(r.observation_count)}</td>
            <td class="num">${Number(r.peak_detected_count)} / ${Number(r.latest_detected_count)}</td>
            <td><span class="trend ${trend.cls}"><svg class="icon" aria-hidden="true"><use href="#${trend.icon}"></use></svg>${escapeHtml(trend.label)}</span></td>
            <td class="num">${escapeHtml(fmtConf(r.max_confidence))}</td>
            <td>
                <div class="status-stack">
                    ${statusBadge('review', r.review_status)}
                    ${statusBadge('action', r.action_status)}
                </div>
            </td>
            <td>
                ${renderActions(r)}
                <button class="btn-sm btn-ghost raw-toggle" type="button" id="raw-toggle-${id}"
                        aria-expanded="false" aria-controls="raw-collapse-${id}" onclick="toggleRaw(${id})">
                    <svg class="icon" aria-hidden="true"><use href="#icon-chevron"></use></svg>
                    <span>Raw observations</span>
                </button>
            </td>
        </tr>
        <tr class="raw-observation-row">
            <td colspan="11">
                <div class="raw-collapse" id="raw-collapse-${id}">
                    <div class="raw-collapse-inner"><div id="raw-content-${id}" class="muted">กำลังโหลด…</div></div>
                </div>
            </td>
        </tr>`;
    }).join('');
}

function renderActions(r) {
    const id = Number(r.id);
    if (r.review_status === 'pending') {
        return `
            <div class="review-actions">
                <button class="btn-sm btn-confirm" type="button" onclick="reviewIncident(${id}, 'confirm', this)">
                    <svg class="icon" aria-hidden="true"><use href="#icon-check"></use></svg>Confirm Incident
                </button>
                <button class="btn-sm btn-reject" type="button" onclick="reviewIncident(${id}, 'reject', this)">
                    <svg class="icon" aria-hidden="true"><use href="#icon-x"></use></svg>Reject
                </button>
            </div>`;
    }
    if (r.review_status === 'confirmed' && r.action_status === 'needs_check') {
        return `
            <div class="review-actions">
                <button class="btn-sm btn-resolve" type="button" onclick="reviewIncident(${id}, 'resolve', this)">
                    <svg class="icon" aria-hidden="true"><use href="#icon-resolve"></use></svg>Resolve
                </button>
            </div>`;
    }
    return '';
}

async function toggleRaw(incidentId) {
    const toggleBtn = document.getElementById(`raw-toggle-${incidentId}`);
    const collapse = document.getElementById(`raw-collapse-${incidentId}`);
    const content = document.getElementById(`raw-content-${incidentId}`);
    if (!toggleBtn || !collapse || !content) return;

    const isOpen = collapse.classList.toggle('is-open');
    toggleBtn.setAttribute('aria-expanded', String(isOpen));
    if (!isOpen || content.dataset.loaded === 'true') return;

    try {
        const res = await fetch(`api/vision-observations.php?incident_id=${encodeURIComponent(incidentId)}`);
        const data = await res.json();
        if (!res.ok) {
            content.textContent = data.error ?? 'โหลด raw observations ไม่สำเร็จ';
            return;
        }
        const observations = Array.isArray(data.observations) ? data.observations : [];
        if (observations.length === 0) {
            content.textContent = 'ไม่มี raw observation สำหรับ incident นี้';
        } else {
            content.innerHTML = observations.map((o) => `
                <div class="raw-observation-item">
                    <span>#${Number(o.id)}</span>
                    <span>${escapeHtml(o.captured_at)}</span>
                    <span>detected=${Number(o.detected_count)}</span>
                    <span>confidence=${escapeHtml(fmtConf(o.max_confidence))}</span>
                    <span>source=${escapeHtml(o.source_mode)}</span>
                </div>`).join('');
        }
        content.dataset.loaded = 'true';
    } catch (err) {
        content.textContent = 'เชื่อมต่อ raw observation API ไม่สำเร็จ';
    }
}

async function reviewIncident(id, action, triggerBtn) {
    if (triggerBtn) triggerBtn.disabled = true;
    try {
        const res = await fetch('api/vision-review.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, action }),
        });
        const data = await res.json();
        if (!res.ok) {
            showToast(data.error ?? 'ทำรายการไม่สำเร็จ', 'error');
            return;
        }
        showToast(`อัปเดต incident #${id} สำเร็จ`, 'success');
        await loadVision();
    } catch (err) {
        showToast('เชื่อมต่อ API ไม่สำเร็จ', 'error');
    } finally {
        if (triggerBtn) triggerBtn.disabled = false;
    }
}

loadVision();
