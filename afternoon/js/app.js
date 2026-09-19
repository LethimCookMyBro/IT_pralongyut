// หน้าสถิติ (index.html) — fetch api/stats.php แล้ว render การ์ดสรุป + ตาราง
// Phase F: เลือกปีข้อมูลได้ เปลี่ยนปีแล้วอัปเดตการ์ด/top5/ตารางทั้งหมด
// โดยไม่ reload หน้า (เก็บปีที่เลือกไว้ใน query string ด้วย)
// helper กลาง (escapeHtml / skeleton / toast / URL state) อยู่ใน js/ui.js

const fmt = (n) => (n === null || n === undefined ? '—' : Number(n).toLocaleString('th-TH', { maximumFractionDigits: 2 }));
const fmtPct = (n) => (n === null || n === undefined ? '—' : `${Number(n)}%`);

const STATS_DEFAULTS = { year: '' };
const yearSelect = document.getElementById('year-select');
let yearsLoaded = false;

async function loadStats(year = '') {
    document.querySelector('#top5-table tbody').innerHTML = skeletonRows(5, 5);
    document.querySelector('#rows-table tbody').innerHTML = skeletonRows(6, 8);
    if (yearsLoaded) {
        document.getElementById('summary-cards').innerHTML = skeletonCards(6);
    }

    const query = apiQuery({ year });
    try {
        const res = await fetch(`api/stats.php${query ? `?${query}` : ''}`);
        const data = await res.json();
        if (!res.ok) {
            showStatsError(data.error || 'โหลดข้อมูลสถิติไม่สำเร็จ');
            return;
        }
        renderYearOptions(data.years, data.year);
        renderYear(data.year);
        renderSummary(data.summary);
        renderTop5(data.top5_generated);
        renderRows(data.rows);
    } catch (err) {
        showStatsError('เชื่อมต่อ API สถิติไม่สำเร็จ');
    }
}

function showStatsError(message) {
    document.getElementById('summary-cards').innerHTML =
        `<p class="msg error">${escapeHtml(message)}</p>`;
    document.querySelector('#top5-table tbody').innerHTML = emptyState(5, 'ไม่มีข้อมูล');
    document.querySelector('#rows-table tbody').innerHTML = emptyState(8, 'ไม่มีข้อมูล');
}

function renderYear(year) {
    document.getElementById('stats-year').textContent = year;
}

// API เป็นเจ้าของรายการปีที่มีจริง — ไม่ hardcode ปีไว้ใน HTML
function renderYearOptions(years, selected) {
    if (yearsLoaded || !Array.isArray(years) || years.length === 0) return;
    yearSelect.innerHTML = years
        .slice()
        .sort((a, b) => b - a)
        .map((year) => `<option value="${year}" ${Number(year) === Number(selected) ? 'selected' : ''}>${year}</option>`)
        .join('');
    yearSelect.disabled = false;
    yearsLoaded = true;
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
    // การ์ดทั้ง 6 เป็นข้อมูลอ้างอิง ไม่มีใบไหน "ต้องทำอะไรต่อ" จึงไม่ใส่สี accent
    document.getElementById('summary-cards').innerHTML = cards
        .map(([label, value]) => `
            <div class="card">
                <div class="label">${escapeHtml(label)}</div>
                <div class="value num">${escapeHtml(value)}</div>
            </div>`)
        .join('');
}

function renderTop5(top5) {
    const tbody = document.querySelector('#top5-table tbody');
    if (!Array.isArray(top5) || top5.length === 0) {
        tbody.innerHTML = emptyState(5, 'ไม่มีข้อมูลในปีนี้');
        return;
    }
    tbody.innerHTML = top5
        .map((row, i) => `
            <tr>
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
        tbody.innerHTML = emptyState(8, 'ไม่มีข้อมูลในปีนี้');
        return;
    }
    tbody.innerHTML = rows
        .map((row) => `
            <tr>
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

yearSelect.addEventListener('change', () => {
    const year = yearSelect.value;
    writeQueryState({ year }, STATS_DEFAULTS);
    loadStats(year);
});

// ปีจาก URL (?year=2565) ทำให้ refresh/แชร์ลิงก์แล้วได้ปีเดิม
// ถ้าไม่ระบุ stats.php จะเลือกปีล่าสุดให้เอง
loadStats(readQueryState(STATS_DEFAULTS).year);
