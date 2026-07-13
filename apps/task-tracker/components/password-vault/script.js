WEBDOCK.component().register(function (exports) {
    var api;
    var taskApi;
    var handler;
    var routeData = {};
    var searchTimer;

    var bindData = {
        errors: [],
        info: [],
        projectId: "",
        project: null,
        vaults: [],
        loading: false,
        isBusy: false,
        search: "",
        selected: null,
        form: emptyVault(),
        formOpen: false
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: initialize,
            loadVaults: loadVaultsDebounced,
            createVault: createVault,
            editVault: editVault,
            closeVaultForm: closeVaultForm,
            saveVault: saveVault,
            deleteVault: deleteVault,
            copyPassword: copyPassword,
            ChangePermision: ChangePermision,
            backToProjects: backToProjects,
            externalUrl: externalUrl,
            statusClass: statusClass
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function emptyVault() {
        return {
            title: "",
            websiteUrl: "",
            username: "",
            password: "",
            passwordConfirm: "",
            notes: "",
            status: "Active",
            projectId: bindData && bindData.projectId ? bindData.projectId : ""
        };
    }

    function initialize() {
        ensureTaskCommonStyles();
        handler = exports.getShellComponent("soss-routes");
        routeData = getRouteData();
        bindData.projectId = routeData.projectId || "";
        bindData.project = null;
        api = exports.getComponent("passwordvaultapi");
        taskApi = exports.getComponent("taskapi");
        if (!api) {
            setError("Password vault service is not loaded.");
            return;
        }
        if (!bindData.projectId) {
            setError("Select a project before opening the password vault.");
            bindData.vaults = [];
            return;
        }
        loadProject();
        loadVaults();
    }

    function loadProject() {
        if (!taskApi || !bindData.projectId) {
            return;
        }
        taskApi.services.ProjectDetails({projectId: bindData.projectId}).then(function (response) {
            if (response.success && response.result && response.result.project) {
                bindData.project = response.result.project;
            }
        });
    }

    function loadVaultsDebounced() {
        if (searchTimer) {
            clearTimeout(searchTimer);
        }
        searchTimer = setTimeout(loadVaults, 250);
    }

    function loadVaults() {
        if (!api) {
            return;
        }
        bindData.loading = true;
        if (!bindData.projectId) {
            return;
        }
        api.services.ListVaults({projectId: bindData.projectId, search: bindData.search}).then(function (response) {
            bindData.loading = false;
            bindData.vaults = response.success ? (response.result || []) : [];
        }).error(function () {
            bindData.loading = false;
            setError("Could not load password vault records.");
        });
    }

    function createVault() {
        clearMessages();
        bindData.selected = null;
        bindData.form = emptyVault();
        bindData.form.projectId = bindData.projectId;
        if (bindData.project && bindData.project.sysviewobject !== undefined) {
            bindData.form.sysviewobject = bindData.project.sysviewobject;
        }
        bindData.formOpen = true;
    }

    function editVault(vault) {
        if (bindData.isBusy || !vault || !vault.vaultId) {
            return;
        }
        bindData.isBusy = true;
        clearMessages();
        bindData.selected = vault;
        bindData.formOpen = true;
        bindData.form = scrubSecret(clone(vault));
        api.services.VaultDetails({vaultId: vault.vaultId, projectId: bindData.projectId}).then(function (response) {
            bindData.isBusy = false;
            if (response.success && response.result) {
                bindData.form = scrubSecret(response.result);
                bindData.form.projectId = bindData.projectId;
            } else {
                setError("Could not load vault details.");
            }
        }).error(function () {
            bindData.isBusy = false;
            setError("Could not load vault details.");
        });
    }

    function closeVaultForm() {
        bindData.formOpen = false;
        bindData.selected = null;
        bindData.form = emptyVault();
        bindData.form.projectId = bindData.projectId;
    }

    function saveVault() {
        if (bindData.isBusy) {
            return;
        }
        clearMessages();
        if (!bindData.form.title && !bindData.form.websiteUrl) {
            setError("Title or website URL is required.");
            return;
        }
        if (!bindData.form.username) {
            setError("Username is required.");
            return;
        }
        if (!bindData.projectId) {
            setError("Project id is required.");
            return;
        }
        if (!bindData.form.vaultId && !bindData.form.password) {
            setError("Password is required.");
            return;
        }
        if (bindData.form.password && bindData.form.password !== bindData.form.passwordConfirm) {
            setError("Passwords do not match.");
            return;
        }

        bindData.form.projectId = bindData.projectId;
        bindData.isBusy = true;
        api.services.SaveVault(clone(bindData.form)).then(function (response) {
            bindData.isBusy = false;
            if (response.success) {
                upsert(bindData.vaults, response.result, "vaultId");
                bindData.selected = response.result;
                closeVaultForm();
                loadVaults();
                setInfo("Login saved.");
            } else {
                setError("Login save failed.");
            }
        }).error(function () {
            bindData.isBusy = false;
            setError("Login save failed.");
        });
    }

    function deleteVault(vault) {
        if (bindData.isBusy || !vault || !vault.vaultId) {
            return;
        }
        if (!window.confirm("Delete this vault record?")) {
            return;
        }
        clearMessages();
        bindData.isBusy = true;
        api.services.DeleteVault({vaultId: vault.vaultId, projectId: bindData.projectId}).then(function (response) {
            bindData.isBusy = false;
            if (response.success) {
                remove(bindData.vaults, vault, "vaultId");
                closeVaultForm();
                setInfo("Login deleted.");
            } else {
                setError("Login delete failed.");
            }
        }).error(function () {
            bindData.isBusy = false;
            setError("Login delete failed.");
        });
    }

    function copyPassword(vault) {
        if (!vault || !vault.vaultId) {
            return;
        }
        clearMessages();
        api.services.CopyPassword({vaultId: vault.vaultId, projectId: bindData.projectId}).then(function (response) {
            if (response.success && response.result && response.result.password !== undefined) {
                writeClipboard(response.result.password, function () {
                    setInfo("Password copied to clipboard.");
                }, function () {
                    setError("Could not copy password to clipboard.");
                });
            } else {
                setError("Password copy failed.");
            }
        }).error(function () {
            setError("Password copy failed.");
        });
    }

    function ChangePermision(vault) {
        if (bindData.isBusy) {
            return;
        }
        var target = vault || bindData.form;
        openViewObject(target.sysviewobject, function (data, shellpopup) {
            target.sysviewobject = data;
            if (bindData.form && target.vaultId === bindData.form.vaultId) {
                bindData.form.sysviewobject = data;
            }
            if (target.vaultId) {
                var saveData = scrubSecret(clone(target));
                saveData.projectId = bindData.projectId;
                bindData.isBusy = true;
                api.services.SaveVault(saveData).then(function (response) {
                    bindData.isBusy = false;
                    if (response.success) {
                        upsert(bindData.vaults, response.result, "vaultId");
                        setInfo("Vault permission updated.");
                    } else {
                        setError("Error changing vault permission.");
                    }
                }).error(function () {
                    bindData.isBusy = false;
                    setError("Error changing vault permission.");
                });
            }
            shellpopup.close();
        });
    }

    function backToProjects() {
        if (handler && handler.appNavigate) {
            handler.appNavigate("../projects");
        } else {
            window.location.hash = "#/app/task-tracker/projects";
        }
    }

    function writeClipboard(value, success, fail) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(success).catch(function () {
                fallbackCopy(value, success, fail);
            });
            return;
        }
        fallbackCopy(value, success, fail);
    }

    function fallbackCopy(value, success, fail) {
        var input = document.createElement("textarea");
        input.value = value;
        input.setAttribute("readonly", "readonly");
        input.style.position = "fixed";
        input.style.left = "-9999px";
        document.body.appendChild(input);
        input.select();
        try {
            if (document.execCommand("copy")) {
                success();
            } else {
                fail();
            }
        } catch (e) {
            fail();
        }
        document.body.removeChild(input);
    }

    function externalUrl(url) {
        if (!url) {
            return "";
        }
        if (/^https?:\/\//i.test(url)) {
            return url;
        }
        return "https://" + url;
    }

    function statusClass(vault) {
        var status = String((vault || {}).status || "Active").toLowerCase();
        if (status === "archived") {
            return "label-default";
        }
        if (status === "inactive") {
            return "label-warning";
        }
        return "label-success";
    }

    function scrubSecret(vault) {
        vault = vault || emptyVault();
        vault.password = "";
        vault.passwordConfirm = "";
        vault.status = vault.status || "Active";
        vault.projectId = vault.projectId || bindData.projectId;
        return vault;
    }

    function getRouteData() {
        var data = {};
        if (handler && handler.getInputData) {
            data = handler.getInputData() || {};
        }
        if (!data.projectId && window.location.href.indexOf("?") > -1) {
            window.location.href.split("?")[1].split("&").forEach(function (pair) {
                var parts = pair.split("=");
                data[decodeURIComponent(parts[0])] = decodeURIComponent(parts[1] || "");
            });
        }
        return data;
    }

    function clone(obj) {
        return JSON.parse(JSON.stringify(obj || {}));
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
        link.href = "components/task-tracker/task-style/file/task-common.css?v=2.3";
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
