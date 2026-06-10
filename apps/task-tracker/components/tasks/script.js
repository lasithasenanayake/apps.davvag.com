WEBDOCK.component().register(function (exports) {
    var api;
    var handler;
    var routeData = {};
    var newfiles = [];

    var bindData = {
        errors: [],
        info: [],
        project: null,
        allowedProfiles: [],
        tasks: [],
        attachments: [],
        selectedTask: null,
        form: emptyTask(),
        activeStatus: "New",
        statusOptions: ["New", "In Progress", "Waiting", "Done", "Closed"]
    };

    exports.vue = {
        data: bindData,
        methods: {
            backToProjects: backToProjects,
            refresh: initialize,
            setStatusTab: setStatusTab,
            createTask: createTask,
            selectTask: selectTask,
            saveTask: saveTask,
            deleteTask: deleteTask,
            openTaskView: openTaskView,
            toggleAssignee: toggleAssignee,
            isAssignee: isAssignee,
            removeAttachment: removeAttachment,
            onFileChange: onFileChange,
            attachmentUrl: attachmentUrl,
            progressClass: progressClass
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function emptyTask() {
        return {
            status: "New",
            priority: "Normal",
            progress: 0,
            subject: "",
            body: "",
            Assignees: [],
            Attachments: [],
            RemovedAttachments: []
        };
    }

    function initialize() {
        ensureTaskCommonStyles();
        api = exports.getComponent("taskapi");
        handler = exports.getShellComponent("soss-routes");
        routeData = getRouteData();

        if (!api) {
            setError("Task service is not loaded.");
            return;
        }
        if (!routeData.projectId) {
            setError("Project was not selected.");
            return;
        }

        loadProject();
        loadTasks();
    }

    function loadProject() {
        api.services.ProjectDetails({projectId: routeData.projectId}).then(function (response) {
            if (response.success) {
                bindData.project = response.result.project;
                bindData.allowedProfiles = response.result.profiles || [];
                createTask();
            }
        }).error(function () {
            setError("Could not load project.");
        });
    }

    function loadTasks() {
        if (!routeData.projectId) {
            return;
        }
        api.services.ListTasks({
            projectId: routeData.projectId,
            status: bindData.activeStatus
        }).then(function (response) {
            bindData.tasks = response.success ? (response.result || []) : [];
        }).error(function () {
            setError("Could not load tasks.");
        });
    }

    function setStatusTab(status) {
        bindData.activeStatus = status;
        loadTasks();
    }

    function createTask() {
        bindData.selectedTask = null;
        bindData.attachments = [];
        newfiles = [];
        bindData.form = emptyTask();
        bindData.form.projectId = routeData.projectId;
        bindData.form.status = bindData.activeStatus || "New";
        if (bindData.project) {
            bindData.form.sysviewobject = bindData.project.sysviewobject;
        }
    }

    function selectTask(task) {
        bindData.selectedTask = task;
        bindData.form = clone(task);
        bindData.form.Assignees = [];
        bindData.form.Attachments = [];
        bindData.form.RemovedAttachments = [];
        bindData.attachments = [];
        newfiles = [];

        api.services.TaskDetails({taskId: task.taskId}).then(function (response) {
            if (response.success) {
                bindData.form.Assignees = response.result.assignees || [];
                bindData.attachments = response.result.attachments || [];
                bindData.attachments.forEach(function (file) {
                    file.scr = attachmentUrl(file);
                });
            }
        }).error(function () {
            setError("Could not load task details.");
        });
    }

    function saveTask() {
        clearMessages();
        if (!bindData.form.subject) {
            setError("Task subject is required.");
            return;
        }
        bindData.form.projectId = routeData.projectId;
        bindData.form.Attachments = bindData.attachments;
        api.services.SaveTask(bindData.form).then(function (response) {
            if (response.success) {
                bindData.form = response.result;
                uploadFiles(response.result.taskId, function () {
                    api.services.NotifyTaskAssignees({
                        taskId: response.result.taskId,
                        eventType: "TaskSaved",
                        message: "Task updated"
                    });
                    loadTasks();
                    selectTask(response.result);
                    setInfo("Task saved.");
                });
            } else {
                setError("Task save failed.");
            }
        }).error(function () {
            setError("Task save failed.");
        });
    }

    function deleteTask(task) {
        if (!task || !task.taskId) {
            return;
        }
        api.services.DeleteTask(task).then(function (response) {
            if (response.success) {
                createTask();
                loadTasks();
                setInfo("Task deleted.");
            } else {
                setError("Task delete failed.");
            }
        }).error(function () {
            setError("Task delete failed.");
        });
    }

    function openTaskView(task) {
        if (!task || !task.taskId) {
            return;
        }
        navigate("../task?projectId=" + routeData.projectId + "&taskId=" + task.taskId);
    }

    function backToProjects() {
        navigate("../projects");
    }

    function toggleAssignee(profile) {
        bindData.form.Assignees = bindData.form.Assignees || [];
        var next = [];
        var exists = false;
        bindData.form.Assignees.forEach(function (assignee) {
            if (String(assignee.profileId) === String(profile.id)) {
                exists = true;
            } else {
                next.push(assignee);
            }
        });
        if (!exists) {
            next.push({
                id: 0,
                profileId: profile.id,
                profileName: profile.name,
                email: profile.email
            });
        }
        bindData.form.Assignees = next;
    }

    function isAssignee(profile) {
        var found = false;
        (bindData.form.Assignees || []).forEach(function (assignee) {
            if (String(assignee.profileId) === String(profile.id)) {
                found = true;
            }
        });
        return found;
    }

    function onFileChange(e) {
        var files = e.target.files || e.dataTransfer.files;
        if (!files.length) {
            return;
        }
        for (var i = 0; i < files.length; i++) {
            newfiles.push(files[i]);
            previewAttachment(newfiles.length - 1, files[i]);
        }
        e.target.value = "";
    }

    function previewAttachment(index, file) {
        var reader = new FileReader();
        reader.onload = function (e) {
            bindData.attachments.push({
                id: 0,
                name: file.name,
                fileType: file.type,
                size: file.size,
                caption: "",
                scr: e.target.result,
                file: file
            });
        };
        reader.readAsDataURL(file);
    }

    function removeAttachment(index) {
        var file = bindData.attachments[index];
        if (file && file.id && file.id !== 0) {
            bindData.form.RemovedAttachments.push({
                id: file.id,
                taskId: file.taskId,
                name: file.name
            });
        }
        bindData.attachments.splice(index, 1);
        if (newfiles[index]) {
            newfiles.splice(index, 1);
        }
    }

    function uploadFiles(taskId, cb) {
        var files = newfiles || [];
        if (!files.length) {
            cb();
            return;
        }
        exports.getAppComponent("davvag-tools", "davvag-file-uploader", function (uploader) {
            uploader.initialize();
            uploader.upload(files, "task_manager_attachments", taskId, function () {
                newfiles = [];
                if (typeof $ !== "undefined" && $.notify) {
                    $.notify("Attachment has been uploaded", "info");
                }
                cb();
            });
        });
    }

    function attachmentUrl(file) {
        if (!file || !file.taskId || !file.name) {
            return file ? file.scr : "";
        }
        return "components/dock/soss-uploader/service/get/task_manager_attachments/" + file.taskId + "-" + file.name;
    }

    function progressClass(task) {
        var progress = parseInt((task || {}).progress || 0, 10);
        if (progress >= 100) {
            return "progress-bar-success";
        }
        if (progress >= 60) {
            return "progress-bar-info";
        }
        if (progress >= 30) {
            return "progress-bar-warning";
        }
        return "progress-bar-danger";
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
