// หน้าสถิติ (index.html) — fetch /api/stats.php แล้ว render การ์ดสรุป + ตาราง

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

const fmt = (n) => (n === null || n === undefined ? '—' : Number(n).toLocaleString('th-TH', { maximumFractionDigits: 2 }));
const fmtPct = (n) => (n === null || n === undefined ? '—' : `${Number(n)}%`);
// สถิติทั้ง 6 การ์ดเป็นข้อมูลอ้างอิง ไม่มีการ์ดไหน "ต้องทำอะไรต่อ" จึงไม่ใช้สี accent แยกรายการ
const SUMMARY_ACCENTS = ['', '', '', '', '', ''];

async function loadStats() {
    document.querySelector('#top5-table tbody').innerHTML = skeletonRows(5, 5);
    document.querySelector('#rows-table tbody').innerHTML = skeletonRows(6, 8);

    try {
        const res = await fetch('api/stats.php');
        const data = await res.json();
        if (!res.ok) {
            document.getElementById('summary-cards').innerHTML =
                '<p class="msg error">โหลดข้อมูลสถิติไม่สำเร็จ</p>';
            return;
        }
        renderYear(data.year);
        renderSummary(data.summary);
        renderTop5(data.top5_generated);
        renderRows(data.rows);
    } catch (err) {
        document.getElementById('summary-cards').innerHTML =
            '<p class="msg error">เชื่อมต่อ API สถิติไม่สำเร็จ</p>';
    }
}

function renderYear(year) {
    document.getElementById('stats-year').textContent = year;
}

function renderSummary(summary) {
    const cards = [
        ['เกิดขึ้นต่อวัน', `${fmt(summary.total_generated_tpd)} ตัน`],
        ['เก็บขนไปกำจัด', `${fmt(summary.total_collected_tpd)} ตัน`],
        ['นำไปใช้ประโยชน์', `${fmt(summary.total_utilized_tpd)} ตัน`],
        ['กำจัดถูกต้อง', `${fmt(summary.total_proper_tpd)} ตัน`],
        ['กำจัดไม่ถูกต้อง', `${fmt(summary.total_improper_tpd)} ตัน`],
        ['อัตรากำจัดถูกต้องรวม', fmtPct(summary.proper_disposal_rate)],
    ];
    document.getElementById('summary-cards').innerHTML = cards
        .map(([label, value], i) => `
            <div class="card ${SUMMARY_ACCENTS[i] || ''}" style="${staggerStyle(i)}">
                <div class="label">${escapeHtml(label)}</div>
                <div class="value num">${escapeHtml(value)}</div>
            </div>`)
        .join('');
}

function renderTop5(top5) {
    const tbody = document.querySelector('#top5-table tbody');
    if (!Array.isArray(top5) || top5.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="empty-state">ไม่มีข้อมูล</td></tr>';
        return;
    }
    tbody.innerHTML = top5
        .map((row, i) => `
            <tr style="${staggerStyle(i)}">
                <td class="num">${i + 1}</td>
                <td>${escapeHtml(row.local_gov)}</td>
                <td>${escapeHtml(row.district)}</td>
                <td class="num">${escapeHtml(fmt(row.generated_tpd))}</td>
                <td class="num">${escapeHtml(fmtPct(row.proper_disposal_rate))}</td>
            </tr>`)
        .join('');
}

function renderRows(rows) {
    const tbody = document.querySelector('#rows-table tbody');
    if (!Array.isArray(rows) || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="empty-state">ไม่มีข้อมูล</td></tr>';
        return;
    }
    tbody.innerHTML = rows
        .map((row, i) => `
            <tr style="${staggerStyle(i)}">
                <td>${escapeHtml(row.local_gov)}</td>
                <td>${escapeHtml(row.district)}</td>
                <td class="num">${escapeHtml(fmt(row.generated_tpd))}</td>
                <td class="num">${escapeHtml(fmt(row.collected_tpd))}</td>
                <td class="num">${escapeHtml(fmt(row.utilized_tpd))}</td>
                <td class="num">${escapeHtml(fmt(row.proper_tpd))}</td>
                <td class="num">${escapeHtml(fmt(row.improper_tpd))}</td>
                <td class="num">${escapeHtml(fmtPct(row.proper_disposal_rate))}</td>
            </tr>`)
        .join('');
}

loadStats();
