const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

function editor(responses) {
    let component;
    const calls = [];
    const services = new Proxy({}, { get: (_, name) => payload => {
        calls.push({ name, payload });
        const response = responses.shift();
        return { then(done) {
            if (!response.networkError) done(response);
            return { error(failed) { if (response.networkError) failed(); } };
        } };
    } });
    const context = {
        WEBDOCK: { component: () => ({ register(fn) { component = { getComponent: () => ({ services }) }; fn(component); } }) },
        URLSearchParams, location: { hash: '' }, setTimeout: fn => fn(),
        document: { querySelector: () => null }
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../components/package-editor/script.js'), 'utf8'), context);
    component.vue.onReady(null, null);
    calls.length = 0;
    component.vue.data.id = 4;
    component.vue.data.status = 'published';
    return { ...component.vue, calls };
}
function lookups() {
    return Array.from({ length: 3 }, () => ({ success: true, result: { items: [] } }));
}
const review = { version_id: 7, through_id: 25, active_enrolments: 12, lessons: [{ id: 56 }, { id: 57 }] };

test('reviews published access before applying every batch with pinned version', () => {
    const ui = editor([...lookups(), { success: true, result: review },
        { success: true, result: { processed: 10, added_grants: 10, after_id: 23, has_more: true } },
        { success: true, result: { processed: 2, added_grants: 2, after_id: 25, has_more: false } }]);
    ui.methods.extendAccess();
    assert.equal(ui.calls.length, 0);
    ui.methods.reviewAccess();
    assert.equal(ui.calls[0].payload.preview, true);
    ui.methods.extendAccess();
    assert.equal(ui.calls.length, 3);
    assert.equal(ui.calls[1].payload.version_id, 7);
    assert.equal(ui.calls[2].payload.version_id, 7);
    assert.equal(ui.calls[2].payload.through_id, 25);
    assert.equal(ui.calls[2].payload.after_id, 23);
    assert.equal(ui.data.accessProcessed, 12);
    assert.equal(ui.data.accessAdded, 12);
    assert.equal(ui.data.extending, false);
    assert.equal(ui.data.accessReview, null);
    assert.match(ui.data.info, /12 active enrolments/);
});

test('interruption stops batches and explains how to finish partial access safely', () => {
    const ui = editor([...lookups(), { success: true, result: review },
        { success: true, result: { processed: 10, added_grants: 10, after_id: 23, has_more: true } },
        { networkError: true }]);
    ui.methods.reviewAccess();
    ui.methods.extendAccess();
    assert.equal(ui.data.extending, false);
    assert.equal(ui.data.accessReview, null);
    assert.equal(ui.data.accessProcessed, 10);
    assert.equal(ui.data.info, '');
    assert.match(ui.data.error, /Review access and retry/);
    assert.match(ui.data.error, /already granted are kept/);
});

test('server rejection clears review and never reports a completed update', () => {
    const ui = editor([...lookups(), { success: true, result: review }, { success: false, message: 'Published package changed.' }]);
    ui.methods.reviewAccess();
    ui.methods.extendAccess();
    assert.equal(ui.data.extending, false);
    assert.equal(ui.data.accessReview, null);
    assert.equal(ui.data.info, '');
    assert.match(ui.data.error, /Published package changed/);
});
