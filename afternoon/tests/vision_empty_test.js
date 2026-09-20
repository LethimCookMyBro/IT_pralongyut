// Run: node afternoon/tests/vision_empty_test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const nodes = new Map();
function node(id) {
    if (!nodes.has(id)) nodes.set(id, { value: '', innerHTML: '', dataset: {}, handlers: {},
        querySelectorAll: () => [], addEventListener(event, handler) { this.handlers[event] = handler; } });
    return nodes.get(id);
}
const context = vm.createContext({
    document: { getElementById: node },
    readQueryState: defaults => ({ ...defaults }), writeQueryState() {},
    apiQuery: () => '', fetch: () => new Promise(() => {}),
    emptyStateBlock: (title, hint) => `<h2>${title}</h2><p>${hint}</p>`,
    setTimeout, clearTimeout,
});
vm.runInContext(fs.readFileSync(path.join(__dirname, '../js/vision.js'), 'utf8'), context);
context.renderQueue([]);
assert.match(node('incident-queue').innerHTML, /href="detect.html"/);
assert.match(node('incident-queue').innerHTML, /data-empty-refresh/);
console.log('PASS empty queue provides detect and refresh actions');
vm.runInContext("state.q = 'no-match'; renderQueue([])", context);
assert.match(node('incident-queue').innerHTML, /data-empty-reset/);
assert.doesNotMatch(node('incident-queue').innerHTML, /href="detect.html"/);
node('incident-queue').handlers.click({ target: { closest: selector => selector === '[data-empty-reset]' } });
assert.equal(node('filter-q').value, '');
assert.equal(node('filter-area').value, '');
assert.equal(vm.runInContext('state.view', context), 'review');
console.log('PASS filtered empty resets filters without changing queue tab');
console.log('2 passed, 0 failed');
