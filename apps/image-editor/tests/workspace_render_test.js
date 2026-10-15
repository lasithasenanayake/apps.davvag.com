'use strict';

var assert = require('assert');
var fs = require('fs');
var path = require('path');
var vueOptions;

function serviceResult(payload) {
    return {
        then: function (callback) { callback(payload); return this; },
        error: function () { return this; }
    };
}

var api = {
    services: {
        Presets: function () { return serviceResult({ success: true, result: { items: [] } }); },
        ListProjects: function () {
            return serviceResult({
                success: true,
                result: {
                    items: [{ id: 8, title: 'Video thumbnail', status: 'active', width_px: 1280, height_px: 720 }],
                    pagination: { page: 0, pageSize: 100, total: 1, hasMore: false }
                }
            });
        }
    }
};

global.WEBDOCK = {
    component: function () {
        return {
            register: function (factory) {
                var componentExports = {
                    getComponent: function () { return api; },
                    getShellComponent: function () { return null; }
                };
                factory(componentExports);
                vueOptions = componentExports.vue;
            }
        };
    }
};

require('../components/workspace/script.js');
vueOptions.onReady(vueOptions.data, null, {});

assert.strictEqual(vueOptions.data.loading, false, 'Workspace should release its loading state.');
assert.strictEqual(vueOptions.data.projects.length, 1, 'Returned projects should enter reactive state.');
assert.strictEqual(vueOptions.computed.filteredProjects.call(vueOptions.data).length, 1, 'Returned projects should pass the default filter.');

['workspace', 'editor', 'template-gallery'].forEach(function (component) {
    var template = fs.readFileSync(path.join(__dirname, '..', 'components', component, 'partial.html'), 'utf8');
    assert.strictEqual(template.indexOf('v-else-if'), -1, component + ' must remain compatible with Vue 2.0.3.');
});

console.log('Image Editor workspace rendering contract: OK');
