// Run: node afternoon/tests/ui_time_test.js (also run with TZ=UTC and TZ=Asia/Bangkok).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const now = Date.parse('2026-09-20T07:05:00Z');
class Clock extends Date { static now() { return now; } }
const context = vm.createContext({
    Date: Clock, Intl,
    document: { getElementById: () => null, createElementNS: () => ({ setAttribute() {} }), body: { prepend() {} } },
});
vm.runInContext(fs.readFileSync(path.join(__dirname, '../js/ui.js'), 'utf8'), context, { filename: 'ui.js' });
const expected = Date.parse('2026-09-20T07:00:00Z');
let passed = 0;
for (const input of ['2026-09-20 14:00:00', '2026-09-20T14:00:00',
    '2026-09-20T07:00:00Z', '2026-09-20T14:00:00+07:00', '2026-09-20T02:00:00-05:00']) {
    assert.equal(context.parseServerDate(input).getTime(), expected, input);
    assert.equal(context.formatRelativeTime(input), new Intl.RelativeTimeFormat('th-TH', { numeric: 'auto' }).format(-5, 'minute'));
    assert.equal(context.formatTime(input), '14:00');
    assert.equal(context.formatDateTime(input), new Intl.DateTimeFormat('th-TH', {
        dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Bangkok',
    }).format(new Date(expected)));
    passed++;
}
assert.equal(context.parseServerDate('2026-09-20 14:00:00.123').getTime(), expected + 123);
passed++;
for (const format of ['formatDateTime', 'formatRelativeTime', 'formatTime']) {
    assert.equal(context[format]('not-a-date'), 'not-a-date');
    assert.equal(context[format](null), '—');
    assert.equal(context[format](''), '—');
}
passed++;
console.log(`${passed} passed, 0 failed (TZ=${process.env.TZ || 'system'})`);
