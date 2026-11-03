// Run with: node tests/messaging_regression_test.js
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const root = path.resolve(__dirname, "..");
const pending = {messages: [], sends: []};
function request(queue, params) {
    return {then(resolve) {
        const item = {params, resolve};
        queue.push(item);
        return {error(reject) { item.reject = reject; }};
    }};
}
const service = {services: {
    Messages: params => request(pending.messages, params),
    SendMessage: params => request(pending.sends, params)
}};
const component = {getComponent: () => service};
vm.runInNewContext(fs.readFileSync(path.join(root, "apps/messaging/script.js"), "utf8"), {
    WEBDOCK: {component: () => ({register: setup => setup(component)})},
    localStorage: {profile: JSON.stringify({id: 99})}
});
const data = component.vue.data;
component.vue.onReady(data, {data: {pid: 5}});
pending.messages.shift().resolve({success: true, result: [{id: 1, m_from: 5, messagetext: "A 100% original message"}]});
assert.equal(data.messages[0].messagetext, "A 100% original message");
assert.equal(component.vue.methods.isOwn({m_from: 99}), true);
data.message = "   ";
component.vue.methods.send();
assert.equal(pending.sends.length, 0);
data.message = "Keep my draft";
component.vue.methods.send();
component.vue.methods.send();
assert.equal(pending.sends.length, 1, "Duplicate sends must be blocked");
pending.sends.shift().reject(new Error("Offline"));
assert.equal(data.message, "Keep my draft");
assert.equal(data.sending, false);
component.vue.methods.send();
const sent = pending.sends.shift();
assert.equal(sent.params.id, 5);
assert.equal(sent.params.message, "Keep%20my%20draft");
sent.resolve({success: true, result: {id: 2, m_from: 99, messagetext: "Keep%20my%20draft"}});
assert.equal(data.message, "");
assert.equal(data.messages.length, 2);
data.message = "Late reply";
component.vue.methods.send();
component.vue.beforeDestroy();
pending.sends.shift().resolve({success: true, result: {id: 3, messagetext: "Late reply"}});
assert.equal(data.messages.length, 2, "A disposed conversation must ignore late replies");

// Rapid selection and Back invalidate stale downloader requests.
const ticks = [], loads = [];
let destroyed = 0;
const dock = {__vue__: {$destroy() { destroyed++; }}};
const inbox = {
    getComponent: () => ({services: {MessageInbox: () => ({then(done) {done({success: true, result: []}); return {error() {}};}})}}),
    getAppComponent: (app, name, ready) => ready({initialize() {}, downloadAPP(...args) { loads.push(args); }})
};
vm.runInNewContext(fs.readFileSync(path.join(root, "apps/messages/script.js"), "utf8"), {
    WEBDOCK: {component: () => ({register: setup => setup(inbox)})},
    Vue: {nextTick: callback => ticks.push(callback)},
    document: {getElementById: () => dock},
    $: () => ({empty() {}})
});
inbox.vue.onReady();
inbox.vue.methods.openConversation({m_from: 5, name: "First"});
inbox.vue.methods.openConversation({m_from: 6, name: "Second"});
while (ticks.length) { ticks.shift()(); }
assert.equal(loads.length, 1);
assert.equal(loads[0][6].pid, 6);
assert.equal(loads[0][7].isCurrent(), true);
inbox.vue.methods.back();
assert.equal(loads[0][7].isCurrent(), false);
assert.equal(inbox.vue.data.active, null);
assert(destroyed > 0);
console.log("Message validation, send retry, draft retention and stale-conversation regressions passed.");
