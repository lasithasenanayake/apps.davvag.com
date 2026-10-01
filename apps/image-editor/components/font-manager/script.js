WEBDOCK.component().register(function (exports) {
    var api, runtime, routes;
    var data = { fonts: [], capabilities: {}, busy: false, error: '', form: { displayFamily: '', licenseName: '', visibility: 'private', attested: false } };

    exports.vue = {
        data: data,
        methods: {
            upload: upload,
            approve: function (font) { action('ApproveFont', font); },
            archive: function (font) { action('ArchiveFont', font); },
            goBack: function () { if (routes && routes.appNavigate) routes.appNavigate('..'); }
        },
        onReady: function () {
            api = exports.getComponent('api');
            runtime = exports.getComponent('editor-runtime');
            if (!runtime || typeof runtime.loadFont !== 'function') runtime = window.DAVVAGImageEditorRuntime;
            routes = exports.getShellComponent('soss-routes');
            Promise.all([
                request(api.services.Capabilities()),
                request(api.services.ListFonts({ pageSize: 200, includePending: true }))
            ]).then(function (responses) {
                data.capabilities = unwrap(responses[0]);
                data.fonts = unwrap(responses[1]).items || [];
                loadPreviews();
            }).catch(fail);
        }
    };

    function unwrap(response) { return response && response.result !== undefined ? response.result : response; }
    function request(call) { return new Promise(function (resolve, reject) { try { call.then(resolve, reject); if (typeof call.error === 'function') call.error(reject); } catch (error) { reject(error); } }); }
    function fail(error) { data.error = error && error.message || 'The request failed.'; }
    function refresh() {
        return request(api.services.ListFonts({ pageSize: 200, includePending: true })).then(function (response) {
            data.fonts = unwrap(response).items || [];
            loadPreviews();
        });
    }
    function upload() {
        var input = document.getElementById('ie-font-file');
        var file = input.files && input.files[0];
        if (!file || data.busy) return;
        data.busy = true;
        file.uploadName = (window.crypto && crypto.randomUUID ? crypto.randomUUID() : Date.now()) + '.' + file.name.split('.').pop().toLowerCase();
        exports.getAppComponent('davvag-tools', 'davvag-file-uploader', function (uploader) {
            uploader.initialize();
            uploader.upload_uncompressed([file], 'image_editor_font', null, function () {
                request(api.services.RegisterFont({
                    mediaReference: 'components/dock/soss-uploader/service/get/image_editor_font/' + file.uploadName,
                    originalName: file.name,
                    displayFamily: data.form.displayFamily,
                    licenseName: data.form.licenseName,
                    visibility: data.form.visibility,
                    attested: data.form.attested
                })).then(function () {
                    input.value = '';
                    data.form = { displayFamily: '', licenseName: '', visibility: 'private', attested: false };
                    return refresh();
                }).catch(fail).finally(function () {
                    data.busy = false;
                    if (uploader.close) uploader.close();
                });
            });
        });
    }
    function action(method, font) {
        if (data.busy) return;
        data.busy = true;
        request(api.services[method]({ fontId: font.id })).then(refresh).catch(fail).finally(function () { data.busy = false; });
    }
    function loadPreviews() {
        data.fonts.forEach(function (font) {
            if (font.status !== 'active' || !font.media_reference) return;
            runtime.loadFont(font).then(function (family) { font.loadedFamily = family; }).catch(function () {});
        });
    }
});
