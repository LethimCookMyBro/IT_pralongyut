// Run: node afternoon/tests/detect_source_test.js
// Execute the actual page script with controllable network replies, without a browser.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../js/detect.js'), 'utf8');

function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}
function page() {
    const nodes = new Map();
    const requests = new Map();
    function element(id) {
        if (!nodes.has(id)) nodes.set(id, {
            textContent: '', hidden: false, dataset: {}, children: [],
            classList: { add() {}, remove() {} },
            addEventListener() {}, replaceChildren() {}, appendChild() {},
            setAttribute(name, value) { this[name] = value; },
            getAttribute(name) { return this[name]; },
            removeAttribute(name) { delete this[name]; },
            load() {},
        });
        return nodes.get(id);
    }
    const context = vm.createContext({
        document: { getElementById: element, createElement: () => element(Symbol()), addEventListener() {} },
        navigator: {}, setInterval: () => 1, clearInterval() {},
        fetch(url) {
            if (url === 'api/live-detection.php') {
                return Promise.resolve({ ok: true, json: async () => ({ available: false }) });
            }
            const request = deferred();
            requests.set(url, request);
            return request.promise;
        },
    });
    vm.runInContext(source, context, { filename: 'detect.js' });
    return { context, element, request: (id) => requests.get(`runtime/demo-videos/${id}.json`) };
}
const stats = (id, count) => ({ video: `${id}.webm`, frames: 120, fps: 8, max_detected_count: count,
    max_confidence: count / 10, model: id, source_label: id, classes: { Plastic: count } });
const reply = (value) => ({ ok: true, json: async () => value });
const flush = () => new Promise(setImmediate);

async function checkRace(mode) {
    const ui = page(); // Initial road request remains pending.
    const roadJson = deferred();
    if (mode === 'delayed JSON') {
        ui.request('road').resolve({ ok: true, json: () => roadJson.promise });
        await flush();
    }
    const cityLoad = ui.context.loadSource('city');
    ui.request('city').resolve(reply(stats('city', 7)));
    await cityLoad;
    function expectCity() {
        assert.equal(ui.element('detect-video').src, 'runtime/demo-videos/city.webm');
        assert.equal(ui.element('detect-video').hidden, false);
        assert.equal(ui.element('detect-fallback-img').hidden, true);
        assert.equal(ui.element('live-count').textContent, '7');
        assert.equal(ui.element('live-confidence').textContent, '70.0%');
        assert.equal(ui.element('live-model').textContent, 'city');
        assert.equal(ui.element('detect-viewer-title').textContent, 'วิดีโอผลตรวจ · พื้นที่สาธารณะ');
        assert.equal(ui.element('detect-media-mode').textContent, 'เล่นซ้ำ · 15.0 วินาที');
    }
    expectCity();
    if (mode === 'stale error') ui.request('road').reject(new Error('late network failure'));
    else if (mode === 'delayed JSON') roadJson.resolve(stats('road', 1));
    else ui.request('road').resolve(reply(stats('road', 1)));
    await flush();
    expectCity();
    console.log(`PASS ${mode}: city video and stats survive late road response`);
}
(async () => {
    for (const mode of ['stale success', 'stale error', 'delayed JSON']) await checkRace(mode);
    const ui = page();
    ui.request('road').resolve({ ok: false });
    await flush();
    assert.equal(ui.element('detect-video').hidden, true);
    assert.equal(ui.element('detect-video').getAttribute('src'), undefined);
    assert.equal(ui.element('detect-fallback-img').src, 'assets/vision/sample-road.jpg');
    assert.equal(ui.element('detect-viewer-title').textContent, 'ภาพผลตรวจ · ริมถนน');
    assert.equal(ui.element('detect-media-mode').textContent, 'ภาพตัวอย่าง');
    assert.equal(ui.element('live-count').textContent, '1');
    assert.equal(ui.element('live-confidence').textContent, '51.6%');
    console.log('PASS missing video: intentional static fallback with matching evidence');
    console.log('4 passed, 0 failed');
})().catch((error) => { console.error(error); process.exitCode = 1; });
