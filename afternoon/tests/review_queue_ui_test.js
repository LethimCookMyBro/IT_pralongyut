'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'vision-common.js'), 'utf8');
const context = { escapeHtml: (value) => String(value) };
vm.createContext(context);
vm.runInContext(source, context);

const cases = [
    [{ review_status: 'pending', action_status: 'none' }, ['pending', 'รอตรวจสอบ']],
    [{ review_status: 'confirmed', action_status: 'needs_check' }, ['in_progress', 'รอดำเนินการ']],
    [{ review_status: 'confirmed', action_status: 'resolved' }, ['resolved', 'เสร็จแล้ว']],
    [{ review_status: 'rejected', action_status: 'none' }, ['rejected', 'ไม่รับเรื่อง']],
];

for (const [item, expected] of cases) {
    const actual = context.compositeStatus(item);
    assert.deepEqual([actual.key, actual.label], expected);
}

assert.match(context.sourceTags({ source: 'citizen', record_origin: 'citizen' }), /ประชาชนแจ้ง/);
assert.match(context.sourceTags({ source: 'ai', record_origin: 'detector_run' }), /AI ตรวจพบ/);
const demo = context.sourceTags({ source: 'citizen', record_origin: 'demo_seed' });
assert.match(demo, /ประชาชนแจ้ง/);
assert.match(demo, /ตัวอย่าง/);

console.log('PASS composite status and source tags');
