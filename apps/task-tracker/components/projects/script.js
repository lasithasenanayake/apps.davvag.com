WEBDOCK.component().register(function (exports) {
    var api;
    var handler;

    var bindData = {
        errors: [],
        info: [],
        projects: [],
        profiles: [],
        form: emptyProject(),
        selected: null
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: initialize,
            createProject: createProject,
            selectProject: selectProject,
            saveProject: saveProject,
            deleteProject: deleteProject,
            openTasks: openTasks,
            ChangePermision: ChangePermision,
            toggleProfile: toggleProfile,
            hasProfile: hasProfile
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function emptyProject() {
        return {
            status: "Active",
            name: "",
            description: "",
            smtpHost: "",
            smtpPort: "587",
            smtpUser: "",
            smtpPassword: "",
            smtpSecure: "tls",
            smtpFromEmail: "",
            imapHost: "",
            imapPort: "993",
            imapUser: "",
            imapPassword: "",
            imapSecure: "ssl",
            imapMailbox: "INBOX",
            AccessProfiles: []
        };
    }

    function initialize() {
        ensureTaskCommonStyles();
        api = exports.getComponent("taskapi");
        handler = exports.getShellComponent("soss-routes");
        if (!api) {
            setError("Task service is not loaded.");
            return;
        }
        loadProfiles();
        loadProjects();
    }

    function loadProfiles() {
        api.services.ListProfiles({}).then(function (response) {
            bindData.profiles = response.success ? (response.result || []) : [];
        }).error(function () {
            setError("Could not load profiles.");
        });
    }

    function loadProjects() {
        api.services.ListProjects({}).then(function (response) {
            bindData.projects = response.success ? (response.result || []) : [];
        }).error(function () {
            setError("Could not load projects.");
        });
    }

    function createProject() {
        bindData.selected = null;
        bindData.form = emptyProject();
    }

    function selectProject(project) {
        bindData.selected = project;
        bindData.form = clone(project);
        bindData.form.AccessProfiles = parseIds(bindData.form.profileids);
        api.services.ProjectDetails({projectId: project.projectId}).then(function (response) {
            if (response.success && response.result.project) {
                bindData.form = clone(response.result.project);
                bindData.form.AccessProfiles = response.result.accessProfileIds || parseIds(bindData.form.profileids);
            }
        });
    }

    function saveProject() {
        clearMessages();
        if (!bindData.form.name) {
            setError("Project name is required.");
            return;
        }
        api.services.SaveProject(bindData.form).then(function (response) {
            if (response.success) {
                bindData.form = response.result;
                upsert(bindData.projects, response.result, "projectId");
                bindData.selected = response.result;
                setInfo("Project saved.");
            } else {
                setError("Project save failed.");
            }
        }).error(function () {
            setError("Project save failed.");
        });
    }

    function deleteProject(project) {
        if (!project || !project.projectId) {
            return;
        }
        api.services.DeleteProject(project).then(function (response) {
            if (response.success) {
                remove(bindData.projects, project, "projectId");
                createProject();
                setInfo("Project deleted.");
            } else {
                setError("Project delete failed.");
            }
        }).error(function () {
            setError("Project delete failed.");
        });
    }

    function openTasks(project) {
        if (!project || !project.projectId) {
            return;
        }
        navigate("../tasks?projectId=" + project.projectId);
    }

    function ChangePermision(project) {
        var target = project || bindData.form;
        openViewObject(target.sysviewobject, function (data, shellpopup) {
            target.sysviewobject = data;
            bindData.form.sysviewobject = data;
            api.services.SaveProject(target).then(function () {
                setInfo("Project permission updated.");
            }).error(function () {
                setError("Error changing project permission.");
            });
            shellpopup.close();
        });
    }

    function toggleProfile(profile) {
        bindData.form.AccessProfiles = bindData.form.AccessProfiles || [];
        var id = String(profile.id);
        var next = [];
        var exists = false;
        bindData.form.AccessProfiles.forEach(function (profileId) {
            if (String(profileId) === id) {
                exists = true;
            } else {
                next.push(profileId);
            }
        });
        if (!exists) {
            next.push(profile.id);
        }
        bindData.form.AccessProfiles = next;
    }

    function hasProfile(profile) {
        var found = false;
        (bindData.form.AccessProfiles || []).forEach(function (profileId) {
            if (String(profileId) === String(profile.id)) {
                found = true;
            }
        });
        return found;
    }

    function navigate(path) {
        if (handler && handler.appNavigate) {
            handler.appNavigate(path);
        } else {
            window.location.hash = "#/app/task-tracker" + normalizePath(path);
        }
    }

    function normalizePath(path) {
        return path.indexOf("../") === 0 ? "/" + path.substring(3) : path;
    }

    function clone(obj) {
        return JSON.parse(JSON.stringify(obj || {}));
    }

    function parseIds(value) {
        if (!value) {
            return [];
        }
        if (Array.isArray(value)) {
            return value;
        }
        return String(value).split(",").filter(function (item) {
            return item !== "";
        });
    }

    function upsert(arr, item, key) {
        var done = false;
        arr.forEach(function (row, index) {
            if (String(row[key]) === String(item[key])) {
                arr.splice(index, 1, item);
                done = true;
            }
        });
        if (!done) {
            arr.unshift(item);
        }
    }

    function remove(arr, item, key) {
        arr.forEach(function (row, index) {
            if (String(row[key]) === String(item[key])) {
                arr.splice(index, 1);
            }
        });
    }

    function ensureTaskCommonStyles() {
        if (document.getElementById("task-tracker-common-css")) {
            return;
        }
        var link = document.createElement("link");
        link.id = "task-tracker-common-css";
        link.rel = "stylesheet";
        link.type = "text/css";
        link.href = "components/task-tracker/task-style/file/task-common.css?v=0.3";
        document.getElementsByTagName("head")[0].appendChild(link);
    }

    function setError(message) {
        bindData.errors.push(message);
    }

    function setInfo(message) {
        bindData.info.push(message);
    }

    function clearMessages() {
        bindData.errors = [];
        bindData.info = [];
    }
});
