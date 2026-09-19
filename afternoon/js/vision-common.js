// คำแปลและตัวช่วยที่ใช้ร่วมกันระหว่างหน้าคิว (vision.js) และหน้ารายละเอียด (incident.js)
// เก็บที่เดียวเพื่อไม่ให้คำเรียกสถานะ/แนวโน้มของสองหน้าเพี้ยนกัน
//
// ภาษาไทยเป็นหลัก คำอังกฤษเป็นข้อความรอง (.term-en) เฉพาะที่จำเป็น

const REVIEW_LABEL = {
    pending: 'รอตรวจสอบ',
    confirmed: 'ยืนยันแล้ว',
    rejected: 'ปฏิเสธ',
};

const REVIEW_ICON = {
    pending: 'icon-clock',
    confirmed: 'icon-check',
    rejected: 'icon-x',
};

const ACTION_LABEL = {
    none: 'ยังไม่มีงาน',
    needs_check: 'ต้องดำเนินการ',
    resolved: 'ดำเนินการแล้ว',
};

const ACTION_ICON = {
    none: null,
    needs_check: 'icon-warning',
    resolved: 'icon-resolve',
};

const AREA_LABEL = { land: 'บนบก', water: 'ทางน้ำ' };
const SOURCE_MODE_LABEL = { replay: 'เล่นซ้ำจากไฟล์ (replay)', camera: 'กล้องทดสอบ', cctv: 'CCTV' };

// ความมั่นใจของโมเดล ไม่ใช่ค่าความแม่นยำของระบบ
const fmtConf = (v) => (v === null || v === undefined ? '—' : `${(Number(v) * 100).toFixed(1)}%`);

function areaTag(value) {
    const icon = value === 'water' ? 'icon-water' : 'icon-land';
    return `<span class="area-tag"><svg class="icon" aria-hidden="true"><use href="#${icon}"></use></svg>${escapeHtml(AREA_LABEL[value] ?? value)}</span>`;
}

// แนวโน้มเทียบจำนวนที่ตรวจพบครั้งแรกกับครั้งล่าสุดของเหตุเดียวกัน
// (ไม่ใช่การพยากรณ์ — เป็นการเทียบสองจุดเท่านั้น)
function trendInfo(incident) {
    if (Number(incident.observation_count) <= 1) {
        return { icon: 'icon-trend-flat', cls: 'trend-flat', label: 'ยังไม่มีแนวโน้ม' };
    }
    const first = Number(incident.first_detected_count);
    const latest = Number(incident.latest_detected_count);
    if (latest > first) return { icon: 'icon-trend-up', cls: 'trend-up', label: 'เพิ่มขึ้น' };
    if (latest < first) return { icon: 'icon-trend-down', cls: 'trend-down', label: 'ลดลง' };
    return { icon: 'icon-trend-flat', cls: 'trend-flat', label: 'คงที่' };
}

function trendTag(incident) {
    const trend = trendInfo(incident);
    return `<span class="trend ${trend.cls}"><svg class="icon" aria-hidden="true"><use href="#${trend.icon}"></use></svg>${escapeHtml(trend.label)}</span>`;
}

function statusBadge(kind, value) {
    const label = kind === 'review' ? (REVIEW_LABEL[value] ?? value) : (ACTION_LABEL[value] ?? value);
    const iconName = kind === 'review' ? REVIEW_ICON[value] : ACTION_ICON[value];
    const iconHtml = iconName ? `<svg class="icon" aria-hidden="true"><use href="#${iconName}"></use></svg>` : '';
    return `<span class="status-badge ${escapeHtml(value)}">${iconHtml}${escapeHtml(label)}</span>`;
}

// สถานะที่คนอ่านเข้าใจได้ในบรรทัดเดียว: review ก่อน ตามด้วยงานที่ค้าง
function statusStack(incident) {
    const parts = [statusBadge('review', incident.review_status)];
    if (incident.action_status !== 'none') {
        parts.push(statusBadge('action', incident.action_status));
    }
    return `<div class="status-stack">${parts.join('')}</div>`;
}
