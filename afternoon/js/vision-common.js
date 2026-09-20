// คำแปลและตัวช่วยที่ใช้ร่วมกันระหว่างหน้าคิว (vision.js) และหน้ารายละเอียด (incident.js)
// เก็บที่เดียวเพื่อไม่ให้คำเรียกสถานะ/แนวโน้มของสองหน้าเพี้ยนกัน
//
// ภาษาไทยเป็นหลัก คำอังกฤษเป็นข้อความรอง (.term-en) เฉพาะที่จำเป็น

const REVIEW_LABEL = {
    pending: 'รอตรวจสอบ',
    confirmed: 'รอดำเนินการ',
    rejected: 'ไม่รับเรื่อง',
};

const REVIEW_ICON = {
    pending: 'icon-clock',
    confirmed: 'icon-check',
    rejected: 'icon-x',
};

const ACTION_LABEL = {
    none: 'ยังไม่มีงาน',
    needs_check: 'รอดำเนินการ',
    resolved: 'เสร็จแล้ว',
};

const ACTION_ICON = {
    none: null,
    needs_check: 'icon-warning',
    resolved: 'icon-resolve',
};

const AREA_LABEL = { land: 'บนบก', water: 'ทางน้ำ' };
const SOURCE_MODE_LABEL = { replay: 'เล่นซ้ำจากไฟล์ (replay)', camera: 'กล้องทดสอบ', cctv: 'CCTV' };
const RECORD_ORIGIN_LABEL = { demo_seed: 'ตัวอย่าง', detector_run: 'AI ตรวจพบ' };

// ความมั่นใจของโมเดล ไม่ใช่ค่าความแม่นยำของระบบ
const fmtConf = (v) => (v === null || v === undefined ? '—' : `${(Number(v) * 100).toFixed(1)}%`);

function areaTag(value) {
    const icon = value === 'water' ? 'icon-water' : 'icon-land';
    return `<span class="area-tag"><svg class="icon" aria-hidden="true"><use href="#${icon}"></use></svg>${escapeHtml(AREA_LABEL[value] ?? value)}</span>`;
}

function originTag(value) {
    const label = RECORD_ORIGIN_LABEL[value] ?? value ?? 'ไม่ทราบที่มา';
    return `<span class="origin-tag ${escapeHtml(value ?? 'unknown')}">${escapeHtml(label)}</span>`;
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

function compositeStatus(item) {
    if (item.review_status === 'rejected') {
        return { key: 'rejected', label: 'ไม่รับเรื่อง', icon: 'icon-x' };
    }
    if (item.action_status === 'resolved') {
        return { key: 'resolved', label: 'เสร็จแล้ว', icon: 'icon-resolve' };
    }
    if (item.review_status === 'confirmed') {
        return { key: 'in_progress', label: 'รอดำเนินการ', icon: 'icon-warning' };
    }
    return { key: 'pending', label: 'รอตรวจสอบ', icon: 'icon-clock' };
}

function compositeStatusBadge(item) {
    const status = compositeStatus(item);
    return `<span class="status-badge ${status.key}"><svg class="icon" aria-hidden="true"><use href="#${status.icon}"></use></svg>${status.label}</span>`;
}

function sourceTags(item) {
    const source = item.source === 'citizen' ? 'ประชาชนแจ้ง' : 'AI ตรวจพบ';
    const tags = [`<span class="source-pill ${escapeHtml(item.source)}">${source}</span>`];
    if (item.record_origin === 'demo_seed') {
        tags.push('<span class="origin-tag demo_seed">ตัวอย่าง</span>');
    }
    return tags.join('');
}

// ชื่อเดิมคงไว้ให้หน้ารายละเอียดใช้ร่วมกัน โดยคืนป้าย composite เพียงอันเดียว
function statusStack(item) {
    return `<div class="status-stack">${compositeStatusBadge(item)}</div>`;
}
