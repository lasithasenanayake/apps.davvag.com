WEBDOCK.component().register(function (exports) {
    var api, runtime, routes, adapter, autosaveTimer, changeVersion = 0, saveQueued = false;
    var data = { project: {}, revisionId: null, ready: false, dirty: false, saving: false, saveState: 'Loading', error: '', selection: null, fonts: [], assets: [], uploading: false, zoom: 1, background: '#ffffff', exportOpen: false, exporting: false, exportForm: { format: 'png', scale: 1, quality: 0.92 } };

    exports.vue = {
        data: data,
        methods: { goBack: goBack, goFonts: goFonts, rename: rename, save: save, addText: addText, addShape: addShape, uploadImage: uploadImage, updateSelection: updateSelection, applyFont: applyFont, deleteSelected: deleteSelected, duplicateSelected: duplicateSelected, toggleLock: toggleLock, toggleHidden: toggleHidden, layer: function (d) { adapter.layer(d); }, align: function (a) { adapter.align(a); }, fitImage: function (mode) { adapter.fitSelectedImage(mode); }, flipImage: flipImage, undo: undo, redo: redo, changeBackground: changeBackground, applyZoom: applyZoom, changeZoom: changeZoom, actualSize: function () { data.zoom = 1; applyZoom(); }, fitZoom: fitZoom, openExport: function () { data.exportOpen = true; }, exportDesign: exportDesign, handleKeydown: handleKeydown },
        onReady: init
    };

    function init() {
        api = exports.getComponent('api'); runtime = exports.getComponent('editor-runtime'); routes = exports.getShellComponent('soss-routes');
        if (!runtime || typeof runtime.create !== 'function') runtime = window.DAVVAGImageEditorRuntime;
        if (!runtime || typeof runtime.create !== 'function') { data.error = 'The canvas runtime could not be initialized.'; data.saveState = 'Load failed'; return; }
        var id = projectId(); if (!id) { data.error = 'A project ID is required.'; return; }
        Promise.all([request(api.services.GetProject({ projectId: id })), request(api.services.ListFonts({ pageSize: 200 }))]).then(function (responses) {
            var payload = unwrap(responses[0]); data.project = payload.project; data.revisionId = payload.revisionId || null; data.fonts = unwrap(responses[1]).items || [];
            data.background = payload.document.pages[0].background.color;
            return waitForCanvas(60).then(function () {
                adapter = runtime.create('ie-design-canvas', { width: data.project.width_px, height: data.project.height_px, onChange: markDirty, onSelection: function (selection) { data.selection = selection; } });
                return loadDocumentFonts(payload.document).then(function () { return adapter.load(payload.document); });
            });
        }).then(function () {
            data.ready = true; data.saveState = 'Saved'; bindUnload();
            window.requestAnimationFrame(function () { window.requestAnimationFrame(function () { fitZoom(); if (adapter && adapter.refreshLayout) adapter.refreshLayout(); }); });
        }).catch(fail);
    }
    function waitForCanvas(attempts) {
        return new Promise(function (resolve, reject) {
            function check() {
                var canvas = document.getElementById('ie-design-canvas');
                if (canvas && canvas.isConnected) { resolve(canvas); return; }
                attempts -= 1;
                if (attempts < 1) { reject(new Error('The canvas element was not rendered.')); return; }
                window.requestAnimationFrame(check);
            }
            check();
        });
    }
    function unwrap(response) { return response && response.result !== undefined ? response.result : response; }
    function request(call) { return new Promise(function (resolve, reject) { try { call.then(resolve, reject); if (typeof call.error === 'function') call.error(reject); } catch (error) { reject(error); } }); }
    function projectId() { var match = String(window.location.hash).match(/[?&]id=(\d+)/); return match ? Number(match[1]) : 0; }
    function uuid() { return window.crypto && crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(16).slice(2); }
    function navigate(path) { if (routes && routes.appNavigate) routes.appNavigate(path); else window.location.hash = '#/app/image-editor/' + path.replace(/^\.\.\//, ''); }
    function goBack() { if (data.dirty && !window.confirm('Leave with unsaved changes?')) return; navigate('..'); }
    function goFonts() { if (data.dirty && !window.confirm('Leave with unsaved changes?')) return; navigate('../fonts'); }
    function fail(error) { data.error = error && error.message ? error.message : 'The request could not be completed.'; data.saveState = data.dirty ? 'Save failed' : data.saveState; }
    function markDirty() { if (!data.ready) return; changeVersion += 1; data.dirty = true; data.saveState = 'Unsaved'; if (data.saving) saveQueued = true; window.clearTimeout(autosaveTimer); autosaveTimer = window.setTimeout(function () { save('autosave'); }, 1800); }
    function rename() { if (!data.project.title || data.saving) return; request(api.services.RenameProject({ projectId: data.project.id, title: data.project.title })).catch(fail); }
    function save(type) {
        if (!adapter || !data.dirty) return;
        if (data.saving) { saveQueued = true; return; }
        data.saving = true; data.saveState = 'Saving'; data.error = '';
        var savedVersion = changeVersion; saveQueued = false;
        var documentData = adapter.serialize(); documentData.projectId = data.project.id; documentData.baseRevisionId = data.revisionId; documentData.clientSaveKey = uuid();
        request(api.services.SaveRevision({ projectId: data.project.id, revisionType: type || 'manual', document: documentData })).then(function (response) {
            var saved = unwrap(response); data.revisionId = saved.revisionId;
            data.dirty = changeVersion !== savedVersion; data.saveState = data.dirty ? 'Unsaved' : 'Saved';
        }).catch(function (error) {
            var message = error && error.message || '';
            data.saveState = message.indexOf('another editor') >= 0 ? 'Conflict' : 'Save failed'; fail(error);
        }).finally(function () {
            data.saving = false;
            if (saveQueued) { window.clearTimeout(autosaveTimer); autosaveTimer = window.setTimeout(function () { save('autosave'); }, 250); }
        });
    }
    function addText() { if (adapter && data.ready) adapter.addText({ x: 100, y: 100 }); }
    function addShape(type) { if (adapter && data.ready) adapter.addShape(type, { x: 120, y: 120 }); }
    function updateSelection(key, value) { if (!adapter) return; var changes = {}; changes[key] = value; adapter.updateSelected(changes); }
    function applyFont() {
        var font = data.fonts.find(function (item) { return Number(item.id) === Number(data.selection.fontId); });
        if (!font) { adapter.updateSelected({ fontId: null, fontFamily: 'Arial' }); return; }
        runtime.loadFont(font).then(function (family) { adapter.updateSelected({ fontId: font.id, fontFamily: family, fontWeight: font.font_weight, fontStyle: font.font_style }); }).catch(function () { data.error = 'The selected font could not be loaded.'; });
    }
    function deleteSelected() { adapter.deleteSelected(); data.selection = null; }
    function duplicateSelected() { adapter.duplicateSelected(); }
    function toggleLock() { adapter.updateSelected({ locked: !data.selection.locked }); }
    function toggleHidden() { adapter.updateSelected({ hidden: !data.selection.hidden }); data.selection = null; }
    function flipImage(axis) { var changes = {}; changes[axis] = !data.selection[axis]; adapter.updateSelected(changes); }
    function undo() { adapter.undo().then(function (changed) { if (changed) markDirty(); }); }
    function redo() { adapter.redo().then(function (changed) { if (changed) markDirty(); }); }
    function changeBackground() { adapter.setBackground(data.background); }
    function applyZoom() { if (adapter) adapter.setZoom(data.zoom); }
    function changeZoom(delta) { data.zoom = Math.max(0.1, Math.min(2, data.zoom + delta)); applyZoom(); }
    function fitZoom() {
        var canvas = document.getElementById('ie-design-canvas');
        var host = canvas && canvas.closest('.ie-stage-scroll');
        if (!host || !data.project.width_px || !data.project.height_px) return;
        var style = window.getComputedStyle(host);
        var availableWidth = host.clientWidth - parseFloat(style.paddingLeft || 0) - parseFloat(style.paddingRight || 0) - 16;
        var availableHeight = host.clientHeight - parseFloat(style.paddingTop || 0) - parseFloat(style.paddingBottom || 0) - 16;
        data.zoom = Math.max(0.1, Math.min(2, Math.min(availableWidth / data.project.width_px, availableHeight / data.project.height_px)));
        applyZoom();
    }
    function uploadImage(event, replace) {
        var file = event.target.files && event.target.files[0]; event.target.value = ''; if (!file || data.uploading) return;
        data.uploading = true; data.error = ''; file.uploadName = uuid() + '.' + String(file.name.split('.').pop() || 'png').toLowerCase();
        exports.getAppComponent('davvag-tools', 'davvag-file-uploader', function (uploader) {
            uploader.initialize(); uploader.upload_uncompressed([file], 'image_editor_asset', null, function () {
                var reference = 'components/dock/soss-uploader/service/get/image_editor_asset/' + file.uploadName;
                request(api.services.RegisterAsset({ projectId: data.project.id, mediaReference: reference, originalName: file.name })).then(function (response) { var asset = unwrap(response).asset; return replace ? adapter.replaceSelectedImage(asset) : adapter.addImage(asset, {}); }).catch(fail).finally(function () { data.uploading = false; if (uploader.close) uploader.close(); });
            });
        });
    }
    function loadDocumentFonts(documentData) {
        var ids = {}, jobs = [];
        (documentData.pages[0].elements || []).forEach(function (element) { var id = element.properties && element.properties.fontId; if (id) ids[id] = true; });
        Object.keys(ids).forEach(function (id) { var font = data.fonts.find(function (item) { return Number(item.id) === Number(id); }); if (font) jobs.push(runtime.loadFont(font)); });
        return Promise.all(jobs);
    }
    function openExport() { data.exportOpen = true; }
    function exportDesign() {
        if (data.exporting) return; data.exporting = true; data.error = '';
        loadDocumentFonts(adapter.serialize()).then(function () { return adapter.exportDataUrl(data.exportForm.format, data.exportForm.scale, data.exportForm.quality); }).then(function (url) {
            var link = document.createElement('a'); link.href = url; link.download = String(data.project.title || 'design').replace(/[^a-z0-9_-]+/gi, '-').replace(/^-|-$/g, '') + '.' + (data.exportForm.format === 'jpeg' ? 'jpg' : data.exportForm.format); document.body.appendChild(link); link.click(); link.remove(); data.exportOpen = false;
        }).catch(function (error) { data.error = 'Export failed: ' + (error.message || 'required assets or fonts did not load.'); }).finally(function () { data.exporting = false; });
    }
    function handleKeydown(event) {
        var tag = event.target && event.target.tagName && event.target.tagName.toLowerCase(); if (tag === 'input' || tag === 'textarea' || tag === 'select' || event.target.isContentEditable) return;
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); save('manual'); }
        else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'd') { event.preventDefault(); duplicateSelected(); }
        else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') { event.preventDefault(); event.shiftKey ? redo() : undo(); }
        else if (event.key === 'Delete' || event.key === 'Backspace') { event.preventDefault(); deleteSelected(); }
    }
    function bindUnload() { window.addEventListener('beforeunload', function (event) { if (data.dirty) { event.preventDefault(); event.returnValue = ''; } }); }
});
