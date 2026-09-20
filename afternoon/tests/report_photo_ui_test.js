// Run: node afternoon/tests/report_photo_ui_test.js
// Checks actual report.js logic; browser file-dialog permissions are not simulated.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const nodes = new Map();
const revoked = [];
const posted = [];
let blobId = 0;
let responseOK = true;
let gpsError;
let gpsSuccess;
function element(id) {
    if (!nodes.has(id)) nodes.set(id, {
        value: '', hidden: false, dataset: {}, events: {}, classes: new Set(), clicks: 0,
        addEventListener(name, fn) { this.events[name] = fn; },
        removeAttribute(name) { delete this[name]; },
        click() { this.clicks++; }, focus() {},
        querySelector() { return element('submit'); },
        reset() { for (const node of nodes.values()) node.value = ''; },
    });
    const node = nodes.get(id);
    node.classList = { add: (name) => node.classes.add(name), remove: (name) => node.classes.delete(name) };
    return node;
}
class Multipart {
    constructor() { this.fields = new Map(); }
    append(name, value, filename) { this.fields.set(name, { value, filename }); }
}
const context = vm.createContext({
    document: { getElementById: element },
    navigator: { geolocation: { getCurrentPosition(success, error) { gpsSuccess = success; gpsError = error; } } },
    window: { isSecureContext: true },
    URL: { createObjectURL: () => `blob:test-${++blobId}`, revokeObjectURL: (url) => revoked.push(url) },
    FormData: Multipart, fillPresetLocations() {}, showToast() {},
    setTimeout: () => 1, clearTimeout() {}, apiQuery: () => '',
    fetch: async (url, options) => {
        posted.push({ url, ...options });
        return { ok: responseOK, json: async () => responseOK ? { id: 1 } : { error: 'test failure' } };
    },
});
vm.runInContext(fs.readFileSync(path.join(__dirname, '../js/report.js'), 'utf8'), context, { filename: 'report.js' });
const jpg = { name: 'camera.jpg', type: 'image/jpeg', size: 2000 };
const png = { name: 'replacement.png', type: 'image/png', size: 3000 };
const webp = { name: 'photo.webp', type: 'image/webp', size: 4000 };
const change = (id, file) => element(id).events.change({ target: { files: [file] } });
const selected = () => vm.runInContext('selectedPhoto', context);
let passed = 0;
function pass(name) { passed++; console.log(`PASS ${name}`); }
(async () => {
    context.requestPosition();
    gpsSuccess({ coords: { latitude: 13.283, longitude: 100.924, accuracy: 10 } });
    assert.equal(element('location_source').value, 'gps');
    context.requestPosition();
    gpsError({ code: 1, PERMISSION_DENIED: 1 });
    assert.equal(element('latitude').value, '');
    assert.equal(element('longitude').value, '');
    assert.equal(element('location_source').value, 'manual');
    assert.equal(element('gps-btn').disabled, false);
    pass('GPS failure clears coordinates and switches source to manual');

    context.requestPosition();
    const staleSuccess = gpsSuccess;
    const staleError = gpsError;
    element('location').value = 'Typed location';
    element('location').events.input();
    staleSuccess({ coords: { latitude: 14, longitude: 101, accuracy: 5 } });
    staleError({ code: 1, PERMISSION_DENIED: 1 });
    assert.equal(element('location_source').value, 'manual');
    assert.equal(element('latitude').value, '');
    assert.equal(element('longitude').value, '');
    assert.equal(element('location').value, 'Typed location');
    assert.equal(element('chosen-location').hidden, true);
    assert.equal(element('gps-btn').disabled, false);
    pass('typing cancels late GPS success and error');

    context.acceptPosition({ latitude: 13.283, longitude: 100.924, accuracy: 10 });
    context.requestPosition();
    const oldGPS = gpsSuccess;
    element('preset-location').value = 'Preset beach';
    element('preset-location').events.change();
    oldGPS({ coords: { latitude: 14, longitude: 101, accuracy: 5 } });
    assert.equal(element('location_source').value, 'preset');
    assert.equal(element('location').value, 'Preset beach');
    assert.equal(element('latitude').value, '');
    assert.equal(element('longitude').value, '');
    assert.equal(element('chosen-location').hidden, false);
    element('location').value = 'Changed manually';
    element('location').events.input();
    assert.equal(element('location_source').value, 'manual');
    assert.equal(element('preset-location').value, '');
    pass('preset replaces GPS; later typing resets preset');

    const html = fs.readFileSync(path.join(__dirname, '../report.html'), 'utf8');
    assert.ok(html.indexOf('id="location"') < html.indexOf('<details class="location-manual"'));
    assert.ok(html.includes('บอกแค่ว่าอยู่ตรงไหน ถ้ามีรูปหรืออยากเล่าเพิ่มค่อยใส่'));
    pass('manual input is visible before optional preset disclosure');

    element('photo-camera-btn').events.click();
    assert.equal(element('photo-camera-input').clicks, 1);
    change('photo-camera-input', jpg);
    assert.equal(selected(), jpg);
    assert.equal(element('photo-preview-img').src, 'blob:test-1');
    assert.equal(element('photo-preview').hidden, false);
    assert.equal(element('photo-actions').hidden, true);
    pass('camera button and preview');

    element('photo-replace-btn').events.click();
    assert.equal(element('photo-file-input').clicks, 1);
    change('photo-file-input', png);
    assert.equal(selected(), png);
    assert.equal(element('photo-preview-img').src, 'blob:test-2');
    assert.deepEqual(revoked, ['blob:test-1']);
    pass('replace revokes old preview');

    for (const invalid of [{ ...png, type: 'image/svg+xml' }, { ...png, size: 5 * 1024 * 1024 + 1 }]) {
        context.choosePhoto(invalid);
        assert.equal(selected(), png);
        assert.equal(element('photo-preview-img').src, 'blob:test-2');
        assert.equal(element('photo-hint').classes.has('is-error'), true);
    }
    context.choosePhoto(undefined);
    assert.equal(selected(), png);
    pass('invalid replacement and cancelled chooser preserve previous photo');

    element('photo-camera-input').value = 'old camera';
    element('photo-file-input').value = 'old upload';
    element('photo-remove-btn').events.click();
    assert.equal(selected(), null);
    assert.equal(element('photo-preview-img').src, undefined);
    assert.equal(element('photo-preview').hidden, true);
    assert.equal(element('photo-actions').hidden, false);
    assert.equal(element('photo-camera-input').value, '');
    assert.equal(element('photo-file-input').value, '');
    assert.deepEqual(revoked, ['blob:test-1', 'blob:test-2']);
    pass('remove clears both inputs and preview');

    element('location').value = 'Test beach';
    element('detail').value = 'Optional note';
    context.syncChosenLocation();
    context.choosePhoto(webp);
    responseOK = false;
    await context.submitReport({ preventDefault() {} });
    assert.equal(selected(), webp);
    assert.equal(posted[0].body.fields.get('photo').value, webp);
    assert.equal(posted[0].body.fields.get('photo').filename, 'photo.webp');
    assert.equal(posted[0].headers, undefined); // Browser supplies the multipart boundary.
    assert.equal(element('submit').disabled, false);
    pass('failed submission preserves photo and sends multipart file');

    responseOK = true;
    await context.submitReport({ preventDefault() {} });
    assert.equal(posted[1].body.fields.get('location').value, 'Test beach');
    assert.equal(posted[1].body.fields.get('detail').value, 'Optional note');
    assert.equal(selected(), null);
    assert.equal(element('photo-preview').hidden, true);
    assert.equal(element('location').value, '');
    assert.equal(revoked.at(-1), 'blob:test-3');
    pass('success resets form and releases preview');

    element('location').value = 'Location only';
    context.syncChosenLocation();
    await context.submitReport({ preventDefault() {} });
    assert.equal(posted[2].body.fields.has('photo'), false);
    assert.equal(posted[2].body.fields.has('waste_type'), false);
    assert.equal(posted[2].body.fields.has('amount_kg'), false);
    pass('location-only submission omits photo and legacy required fields');
    console.log(`${passed} passed, 0 failed`);
})().catch((error) => { console.error(error); process.exitCode = 1; });
