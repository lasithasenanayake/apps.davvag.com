WEBDOCK.component().register(function (exports) {
    var api;
    var routes;
    var mountedData;
    var vueApp;
    var data = {
        presets: [],
        projects: [],
        loading: false,
        creating: false,
        busyId: 0,
        error: '',
        filters: { search: '', status: 'active' },
        createForm: { title: 'Untitled design', width: 1280, height: 720 }
    };

    exports.vue = {
        data: data,
        computed: {
            filteredProjects: function () {
                var term = String(this.filters.search || '').toLowerCase();
                return this.projects.filter(function (project) {
                    return (!term || String(project.title || '').toLowerCase().indexOf(term) >= 0);
                });
            }
        },
        methods: {
            loadProjects: loadProjects,
            createFromPreset: createFromPreset,
            createCustom: createCustom,
            openProject: openProject,
            duplicateProject: duplicateProject,
            renameProject: renameProject,
            toggleArchive: toggleArchive,
            deleteProject: deleteProject,
            goTemplates: function () { navigate('../templates'); },
            goFonts: function () { navigate('../fonts'); },
            presetStyle: function (preset) { return { aspectRatio: preset.width + '/' + preset.height }; },
            projectRatio: function (project) { return { aspectRatio: project.width_px + '/' + project.height_px }; }
        },
        onReady: function (scope, element) {
            mountedData = scope || data;
            var node = element && element.jquery ? element[0] : element;
            vueApp = node && node.__vue__ ? node.__vue__ : null;
            api = exports.getComponent('api');
            routes = exports.getShellComponent('soss-routes');
            loadPresets();
            loadProjects();
        }
    };

    function result(response) { return response && response.result !== undefined ? response.result : response; }
    function items(response) {
        var value = response;
        for (var depth = 0; depth < 5 && value; depth += 1) {
            if (Array.isArray(value)) return value;
            if (Array.isArray(value.items)) return value.items;
            if (Array.isArray(value.projects)) return value.projects;
            if (value.result !== undefined && value.result !== value) { value = value.result; continue; }
            if (value.data !== undefined && value.data !== value) { value = value.data; continue; }
            break;
        }
        return [];
    }
    function setCollection(name, nextItems) {
        var value = Array.isArray(nextItems) ? nextItems.slice() : [];
        data[name] = value;
        if (mountedData && mountedData !== data) mountedData[name] = value;
        if (vueApp && vueApp[name] !== value) vueApp[name] = value;
        if (vueApp && typeof vueApp.$forceUpdate === 'function') vueApp.$forceUpdate();
    }
    function message(error) { return error && error.message ? error.message : 'The request could not be completed.'; }
    function request(call) { return new Promise(function (resolve, reject) { try { call.then(resolve, reject); if (typeof call.error === 'function') call.error(reject); } catch (error) { reject(error); } }); }
    function navigate(path) { if (routes && routes.appNavigate) routes.appNavigate(path); else window.location.hash = '#/app/image-editor/' + String(path).replace(/^\.\.\//, ''); }
    function loadPresets() {
        api.services.Presets().then(function (response) { setCollection('presets', items(response)); }).error(function (error) { data.error = message(error); });
    }
    function loadProjects() {
        if (data.loading) return;
        data.loading = true; data.error = '';
        api.services.ListProjects({ status: data.filters.status, pageSize: 100 }).then(function (response) {
            setCollection('projects', items(response));
            data.loading = false;
        }).error(function (error) {
            data.error = message(error);
            data.loading = false;
        });
    }
    function createFromPreset(preset) { createProject({ title: preset.name, width: preset.width, height: preset.height, designType: preset.code }); }
    function createCustom() { createProject({ title: data.createForm.title, width: data.createForm.width, height: data.createForm.height, designType: 'custom' }); }
    function createProject(payload) {
        if (data.creating) return;
        data.creating = true; data.error = '';
        request(api.services.CreateProject(payload)).then(function (r) { openProject(result(r).project); }).catch(function (e) { data.error = message(e); }).finally(function () { data.creating = false; });
    }
    function openProject(project) { if (project && project.id) navigate('../editor?id=' + encodeURIComponent(project.id)); }
    function duplicateProject(project) {
        if (data.busyId) return; data.busyId = project.id;
        request(api.services.DuplicateProject({ projectId: project.id })).then(function (r) { openProject(result(r).project); }).catch(function (e) { data.error = message(e); }).finally(function () { data.busyId = 0; });
    }
    function renameProject(project) {
        var title = window.prompt('Design title', project.title || '');
        if (!title || title === project.title || data.busyId) return;
        data.busyId = project.id;
        request(api.services.RenameProject({ projectId: project.id, title: title })).then(loadProjects).catch(function (e) { data.error = message(e); }).finally(function () { data.busyId = 0; });
    }
    function toggleArchive(project) {
        if (data.busyId) return; data.busyId = project.id;
        request(api.services.ChangeProjectStatus({ projectId: project.id, status: project.status === 'active' ? 'archived' : 'active' })).then(loadProjects).catch(function (e) { data.error = message(e); }).finally(function () { data.busyId = 0; });
    }
    function deleteProject(project) {
        if (data.busyId || !window.confirm('Move this design to Deleted?')) return; data.busyId = project.id;
        request(api.services.ChangeProjectStatus({ projectId: project.id, status: 'deleted' })).then(loadProjects).catch(function (e) { data.error = message(e); }).finally(function () { data.busyId = 0; });
    }
});
