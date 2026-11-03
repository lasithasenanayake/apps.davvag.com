// Run with: node tests/popup_regression_test.js
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const root = path.resolve(__dirname, "..");

function productComponent(name) {
    const requests = [];
    const completions = [];
    const service = {services: {}};
    ["Product", "SaveProduct"].forEach(method => {
        service.services[method] = () => ({then(resolve) {
            const request = {resolve};
            requests.push(request);
            return {error(reject) { request.reject = reject; }};
        }});
    });
    const component = {
        getComponent: () => service,
        getAppComponent: (app, name, ready) => ready({initialize() {}}),
        Complete: value => completions.push(value)
    };
    vm.runInNewContext(fs.readFileSync(path.join(root, "apps", name, "script.js"), "utf8"), {
        WEBDOCK: {component: () => ({register: setup => setup(component)})},
        localStorage: {}, sessionStorage: {}, console: {log() {}}, window: {}, document: {addEventListener() {}}
    }, {filename: name + "/script.js"});
    return {component, requests, completions};
}

for (const name of ["product_app", "product_app_checkout"]) {
    const {component, requests, completions} = productComponent(name);
    assert(Array.isArray(component.vue.data.product.groupItems));
    // Match the framework: completedEvent is a collection, not a callable.
    component.vue.onReady(component.vue.data, {data: {itemid: 42}, completedEvent: []});
    assert.equal(component.vue.data.loadingProduct, true);
    requests.shift().resolve({success: true, result: {itemid: 42, qty: 2, groupItems: null}});
    assert.equal(component.vue.data.loadingProduct, false);
    assert(Array.isArray(component.vue.data.product.groupItems));
    const item = {itemid: 42, qty: 2};
    component.vue.methods.additem(item, true);
    assert.equal(completions.pop().method, "add_item");
    component.vue.methods.addchecout(item, true);
    assert.equal(completions.length, 1, "Buy must emit exactly one completion");
    assert.equal(completions.pop().method, "buy_item");
    component.vue.methods.trade(item);
    assert.equal(completions.pop().url, "#/app/stelup_shop/traditem?itemid=42");
    component.vue.methods.edit(item);
    assert.equal(completions.pop().url, "#/app/stelup_shop/itemonboard?id=42");
    component.vue.methods.marksold(item);
    requests.shift().resolve({success: true});
    assert.equal(completions.pop().method, "item_unlisted");

    const missing = productComponent(name);
    missing.component.vue.onReady(missing.component.vue.data, {});
    assert.equal(missing.component.vue.data.loadingProduct, false);
    assert(missing.component.vue.data.productError);
    const failed = productComponent(name);
    failed.component.vue.onReady(failed.component.vue.data, {data: {itemid: 42}});
    failed.requests.shift().reject(new Error("Request failed"));
    assert.equal(failed.component.vue.data.loadingProduct, false);
    assert(failed.component.vue.data.productError);
}
// A send must execute without unsupported jQuery selectors or closing the dialog.
for (const name of ["messaging", "product-comments"]) {
    let completed = false;
    const disabledStates = [];
    const response = value => ({then(resolve) {
        resolve({success: true, result: value});
        return {error() {}};
    }});
    const service = {services: {
        AllComments: () => response([]),
        Messages: () => response([]),
        Comment: () => response({pid: 99, comment: "Useful product"}),
        SendMessage: () => response({m_from: 99, userboxid: 99, messagetext: "Hello%20seller"})
    }};
    const component = {
        getComponent: () => service,
        getShellComponent: () => ({newValidator: () => ({map() {}, validate() {}})}),
        Complete: () => { completed = true; }
    };
    vm.runInNewContext(fs.readFileSync(path.join(root, "apps", name, "script.js"), "utf8"), {
        WEBDOCK: {component: () => ({register: setup => setup(component)})},
        localStorage: {profile: JSON.stringify({id: 99})},
        console: {log() {}},
        $: selector => {
            assert(!selector.includes(":textarea"), "Unsupported textarea pseudo-selector");
            return {prop: (key, value) => disabledStates.push(value)};
        }
    }, {filename: name + "/script.js"});
    const data = component.vue.data;
    component.vue.onReady(data, {data: {itemid: 42, storeid: 5}, completedEvent: []});
    data.message = "Hello seller";
    data.comment = "Useful product";
    component.vue.methods.send(name === "messaging" ? data.message : data.comment);
    assert.equal((data.messages || data.comments).length, 1);
    assert.equal(name === "messaging" ? data.message : data.comment, "");
    assert.equal(disabledStates.at(-1), false);
    assert.equal(completed, false, "Sending must leave the conversation open");
}
console.log("Popup callbacks, Buy, product loading/errors, message and comment submission passed.");

// CMS getComponent returns registered components before their onLoad runs.
// Opening repeatedly must initialize the coordinator exactly once.
for (const name of ["home-sp", "profile", "frmproduct-list", "seller-product-admin"]) {
    let initializations = 0;
    const opened = [];
    const popup = {onLoad() {
        initializations++;
        this.open = (...args) => opened.push(args);
    }};
    const component = {getComponent: id => {
        assert.equal(id, "shop-popup");
        return popup;
    }};
    vm.runInNewContext(fs.readFileSync(path.join(root, "apps", name, "script.js"), "utf8"), {
        WEBDOCK: {component: () => ({register: setup => setup(component)})},
        localStorage: {}, sessionStorage: {}, console: {log() {}}, window: {}, document: {addEventListener() {}}
    }, {filename: name + "/script.js"});
    assert.equal(popup.open, undefined);
    const product = {itemid: 42};
    component.vue.methods.downloadapp("stelup_shop", "product_app", product, "Product");
    component.vue.methods.downloadapp("stelup_shop", "product_app", product, "Product");
    assert.equal(initializations, 1);
    assert.equal(opened.length, 2);
    assert.equal(opened[0][2], product);
    assert.equal(typeof opened[0][4], "function");
}
console.log("Preloaded popup initialization and repeated opening passed for all four catalog views.");
