WEBDOCK.component().register(function (exports) {
    var api;
    var data = { projects: [], templates: [], form: { projectId: 0, title: '', category: 'General' }, busy: false, error: '' };

    exports.vue = {
        data: data,
        methods: {
            createDraft: createDraft,
            publish: function (item) { change('PublishTemplate', item); },
            unpublish: function (item) { change('UnpublishTemplate', item); },
            archive: function (item) { change('ArchiveTemplate', item); }
        },
        onReady: function () { api = exports.getComponent('api'); reload(); }
    };

    function unwrap(response) { return response && response.result !== undefined ? response.result : response; }
    function request(call) { return new Promise(function (resolve, reject) { try { call.then(resolve, reject); if (typeof call.error === 'function') call.error(reject); } catch (error) { reject(error); } }); }
    function fail(error) { data.error = error && error.message || 'The request failed.'; }
    function reload() {
        return Promise.all([
            request(api.services.ListProjects({ status: 'active', pageSize: 200 })),
            request(api.services.ListTemplates({ status: 'all', pageSize: 200, includeDrafts: true }))
        ]).then(function (responses) {
            data.projects = unwrap(responses[0]).items || [];
            data.templates = unwrap(responses[1]).items || [];
        }).catch(fail);
    }
    function createDraft() {
        if (data.busy) return;
        data.busy = true;
        request(api.services.CreateTemplateDraft({ projectId: data.form.projectId, title: data.form.title, category: data.form.category }))
            .then(reload).catch(fail).finally(function () { data.busy = false; });
    }
    function change(method, item) {
        if (data.busy) return;
        data.busy = true;
        request(api.services[method]({ templateId: item.id })).then(reload).catch(fail).finally(function () { data.busy = false; });
    }
});
