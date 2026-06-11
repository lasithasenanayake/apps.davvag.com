WEBDOCK.component().register(function (exports) {
    var api;
    var handler;
    var accessSearchTimer;

    var bindData = {
        errors: [],
        info: [],
        projects: [],
        accessProfileRows: [],
        accessSearch: "",
        accessMatches: [],
        accessSearching: false,
        accessProfilesLoading: false,
        accessSearchMessage: "",
        form: emptyProject(),
        formOpen: false,
        selected: null
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: initialize,
            createProject: createProject,
            closeProjectForm: closeProjectForm,
            selectProject: selectProject,
            saveProject: saveProject,
            deleteProject: deleteProject,
            openTasks: openTasks,
            openPasswordVault: openPasswordVault,
            ChangePermision: ChangePermision,
            toggleProfile: toggleProfile,
            hasProfile: hasProfile,
            searchAccessProfiles: searchAccessProfiles,
            runAccessSearch: runAccessSearch,
            addAccessProfile: addAccessProfile,
            removeAccessProfile: removeAccessProfile,
            accessProfiles: accessProfiles,
            isAccessRowVisible: isAccessRowVisible
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function emptyProject() {
        return {
            status: "Active",
            projectColor: "#337ab7",
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
        loadProjects();
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
        bindData.formOpen = true;
        bindData.accessProfileRows = [];
        bindData.accessSearch = "";
        bindData.accessMatches = [];
        bindData.accessSearchMessage = "";
    }

    function closeProjectForm() {
        bindData.formOpen = false;
        bindData.accessSearch = "";
        bindData.accessMatches = [];
        bindData.accessSearchMessage = "";
    }

    function selectProject(project) {
        bindData.selected = project;
        bindData.form = clone(project);
        bindData.formOpen = true;
        bindData.form.AccessProfiles = parseIds(bindData.form.profileids);
        bindData.accessProfileRows = [];
        loadAssignedProfiles();
        api.services.ProjectDetails({projectId: project.projectId}).then(function (response) {
            if (response.success && response.result.project) {
                bindData.form = clone(response.result.project);
                bindData.form.AccessProfiles = response.result.accessProfileIds || parseIds(bindData.form.profileids);
                loadAssignedProfiles();
                bindData.accessSearch = "";
                bindData.accessMatches = [];
                bindData.accessSearchMessage = "";
            }
        });
    }

    function saveProject() {
        clearMessages();
        if (!bindData.form.name) {
            setError("Project name is required.");
            return;
        }
        syncAccessProfileIds();
        var accessProfileIds = (bindData.form.AccessProfiles || []).slice(0);
        api.services.SaveProject(bindData.form).then(function (response) {
            if (response.success) {
                bindData.form = response.result;
                bindData.form.AccessProfiles = response.result.AccessProfiles || accessProfileIds;
                loadAssignedProfiles();
                upsert(bindData.projects, response.result, "projectId");
                bindData.selected = response.result;
                setInfo("Project saved.");
                closeProjectForm();
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
                closeProjectForm();
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

    function openPasswordVault(project) {
        if (!project || !project.projectId) {
            return;
        }
        navigate("../password-vault?projectId=" + project.projectId);
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

    function addAccessProfile(profile) {
        if (!profile || !profile.id) {
            return;
        }
        bindData.form.AccessProfiles = bindData.form.AccessProfiles || [];
        if (!hasProfile(profile)) {
            bindData.form.AccessProfiles.push(profile.id);
        }
        loadAssignedProfiles();
        bindData.accessSearchMessage = "";
    }

    function removeAccessProfile(profile) {
        if (!profile || !profile.id) {
            return;
        }
        bindData.form.AccessProfiles = (bindData.form.AccessProfiles || []).filter(function (profileId) {
            return String(profileId) !== String(profile.id);
        });
        loadAssignedProfiles();
    }

    function searchAccessProfiles() {
        if (accessSearchTimer) {
            clearTimeout(accessSearchTimer);
        }
        accessSearchTimer = setTimeout(runAccessSearch, 300);
    }

    function runAccessSearch() {
        var q = (bindData.accessSearch || "").trim();
        if (q.length < 2) {
            bindData.accessMatches = [];
            bindData.accessSearchMessage = "";
            return;
        }
        bindData.accessSearching = true;
        bindData.accessSearchMessage = "";
        api.services.SearchProfileByEmail({email: q}).then(function (response) {
            bindData.accessSearching = false;
            bindData.accessMatches = response.success ? (response.result || []) : [];
            bindData.accessSearchMessage = bindData.accessMatches.length === 0 ? "No profile found for that email." : "";
        }).error(function () {
            bindData.accessSearching = false;
            bindData.accessMatches = [];
            bindData.accessSearchMessage = "Profile search failed.";
        });
    }

    function accessProfiles() {
        return bindData.accessProfileRows || [];
    }

    function loadAssignedProfiles() {
        var ids = (bindData.form.AccessProfiles || []).slice(0);
        if (ids.length === 0) {
            bindData.accessProfileRows = [];
            return;
        }
        bindData.accessProfilesLoading = true;
        api.services.ProjectAssignedProfiles({
            projectId: bindData.form.projectId || 0,
            profileIds: ids
        }).then(function (response) {
            bindData.accessProfilesLoading = false;
            bindData.accessProfileRows = response.success ? (response.result || []) : [];
        }).error(function () {
            bindData.accessProfilesLoading = false;
            bindData.accessProfileRows = [];
            setError("Could not load assigned profiles.");
        });
    }

    function syncAccessProfileIds() {
        var ids = [];
        var seen = {};
        (bindData.form.AccessProfiles || []).forEach(function (profileId) {
            if (profileId !== "" && profileId !== null && !seen[String(profileId)]) {
                seen[String(profileId)] = true;
                ids.push(profileId);
            }
        });
        bindData.form.AccessProfiles = ids;
    }

    function isAccessRowVisible(profile) {
        var found = false;
        (bindData.accessProfileRows || []).forEach(function (row) {
            if (String(row.id) === String(profile.id)) {
                found = true;
            }
        });
        return found;
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
        link.href = "components/task-tracker/task-style/file/task-common.css?v=2.2";
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
