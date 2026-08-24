WEBDOCK.component().register(function (exports) {
    var api;
    var handler;
    var routeData = {};
    var newfiles = [];
    var richTextReady = false;

    var bindData = {
        errors: [],
        info: [],
        project: null,
        allowedProfiles: [],
        allowedProfilesLoading: false,
        taskTypeOptions: [],
        taskTypesLoading: false,
        tasks: [],
        attachments: [],
        selectedTask: null,
        form: emptyTask(),
        formOpen: false,
        isBusy: false,
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
            editTask: editTask,
            closeTaskForm: closeTaskForm,
            saveTask: saveTask,
            deleteTask: deleteTask,
            openTaskView: openTaskView,
            openTimeTracker: openTimeTracker,
            openPasswordVault: openPasswordVault,
            toggleAssignee: toggleAssignee,
            isAssignee: isAssignee,
            removeAttachment: removeAttachment,
            onFileChange: onFileChange,
            attachmentUrl: attachmentUrl,
            taskColorStyle: taskColorStyle,
            progressClass: progressClass,
            priorityClass: priorityClass,
            priorityLabelClass: priorityLabelClass
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
            taskType: "",
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
        ensureRichTextEditor();
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
        loadTaskTypes();
        loadTasks();
    }

    function loadTaskTypes() {
        bindData.taskTypesLoading = true;
        api.services.ListTaskTypes({}).then(function (response) {
            bindData.taskTypesLoading = false;
            bindData.taskTypeOptions = response.success ? (response.result || []) : [];
        }).error(function () {
            bindData.taskTypesLoading = false;
            bindData.taskTypeOptions = [];
            setError("Could not load task types.");
        });
    }

    function loadProject() {
        api.services.ProjectDetails({projectId: routeData.projectId}).then(function (response) {
            if (response.success) {
                bindData.project = response.result.project;
                loadAllowedProfiles();
                closeTaskForm();
            }
        }).error(function () {
            setError("Could not load project.");
        });
    }

    function loadAllowedProfiles() {
        bindData.allowedProfilesLoading = true;
        api.services.ProjectAssignedProfiles({projectId: routeData.projectId}).then(function (response) {
            bindData.allowedProfilesLoading = false;
            bindData.allowedProfiles = response.success ? normalizeProfiles(response.result || []) : [];
        }).error(function () {
            bindData.allowedProfilesLoading = false;
            bindData.allowedProfiles = [];
            setError("Could not load project assignees.");
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
        closeTaskForm();
        loadTasks();
    }

    function createTask() {
        bindData.selectedTask = null;
        bindData.formOpen = true;
        bindData.attachments = [];
        newfiles = [];
        bindData.form = emptyTask();
        bindData.form.projectId = routeData.projectId;
        bindData.form.status = bindData.activeStatus || "New";
        if (bindData.project) {
            bindData.form.sysviewobject = bindData.project.sysviewobject;
        }
        activateRichText(bindData.form.body);
    }

    function selectTask(task) {
        editTask(task);
    }

    function editTask(task) {
        if (bindData.isBusy || !task || !task.taskId) {
            return;
        }
        bindData.isBusy = true;
        bindData.selectedTask = task;
        bindData.formOpen = true;
        if (!bindData.allowedProfiles.length) {
            loadAllowedProfiles();
        }
        bindData.form = clone(task);
        bindData.form.Assignees = [];
        bindData.form.Attachments = [];
        bindData.form.RemovedAttachments = [];
        bindData.attachments = [];
        newfiles = [];
        activateRichText(bindData.form.body);

        api.services.TaskDetails({taskId: task.taskId}).then(function (response) {
            bindData.isBusy = false;
            if (response.success) {
                bindData.form.Assignees = normalizeAssignees(response.result.assignees || []);
                bindData.attachments = response.result.attachments || [];
                bindData.attachments.forEach(function (file) {
                    file.scr = attachmentUrl(file);
                });
                activateRichText(bindData.form.body);
            } else {
                setError("Could not load task details.");
            }
        }).error(function () {
            bindData.isBusy = false;
            setError("Could not load task details.");
        });
    }

    function closeTaskForm() {
        bindData.formOpen = false;
        bindData.selectedTask = null;
        bindData.attachments = [];
        newfiles = [];
        bindData.form = emptyTask();
        bindData.form.projectId = routeData.projectId;
    }

    function saveTask() {
        if (bindData.isBusy) {
            return;
        }
        clearMessages();
        syncRichTextBody();
        if (!bindData.form.subject) {
            setError("Task subject is required.");
            return;
        }
        if (!bindData.form.taskType) {
            setError("Task type is required.");
            return;
        }
        bindData.form.projectId = routeData.projectId;
        bindData.form.Assignees = normalizeAssignees(bindData.form.Assignees || []);
        bindData.form.Attachments = bindData.attachments;
        bindData.isBusy = true;
        api.services.SaveTask(bindData.form).then(function (response) {
            bindData.isBusy = false;
            if (response.success) {
                bindData.form = response.result;
                uploadFiles(response.result.taskId, function () {
                    api.services.NotifyTaskAssignees({
                        taskId: response.result.taskId,
                        eventType: "TaskSaved",
                        message: "Task updated"
                    });
                    loadTasks();
                    bindData.selectedTask = response.result;
                    bindData.formOpen = false;
                    bindData.attachments = [];
                    newfiles = [];
                    setInfo("Task saved.");
                });
            } else {
                setError("Task save failed.");
            }
        }).error(function () {
            bindData.isBusy = false;
            setError("Task save failed.");
        });
    }

    function deleteTask(task) {
        if (bindData.isBusy || !task || !task.taskId) {
            return;
        }
        bindData.isBusy = true;
        api.services.DeleteTask(task).then(function (response) {
            bindData.isBusy = false;
            if (response.success) {
                closeTaskForm();
                loadTasks();
                setInfo("Task deleted.");
            } else {
                setError("Task delete failed.");
            }
        }).error(function () {
            bindData.isBusy = false;
            setError("Task delete failed.");
        });
    }

    function openTaskView(task) {
        if (!task || !task.taskId) {
            return;
        }
        navigate("../task?projectId=" + routeData.projectId + "&taskId=" + task.taskId);
    }

    function openTimeTracker(task) {
        if (!task || !task.taskId) {
            return;
        }
        navigate("../time-tracker?projectId=" + routeData.projectId + "&taskId=" + task.taskId);
    }

    function openPasswordVault() {
        if (!routeData.projectId) {
            return;
        }
        navigate("../password-vault?projectId=" + routeData.projectId);
    }

    function backToProjects() {
        navigate("../projects");
    }

    function toggleAssignee(profile) {
        bindData.form.Assignees = bindData.form.Assignees || [];
        var profileId = profileIdOf(profile);
        if (profileId === null) {
            return;
        }
        var next = [];
        var exists = false;
        bindData.form.Assignees.forEach(function (assignee) {
            if (String(assigneeProfileIdOf(assignee)) === String(profileId)) {
                exists = true;
            } else {
                next.push(assignee);
            }
        });
        if (!exists) {
            next.push({
                id: 0,
                profileId: profileId,
                profileName: profile.name,
                email: profile.email
            });
        }
        bindData.form.Assignees = next;
    }

    function isAssignee(profile) {
        var profileId = profileIdOf(profile);
        if (profileId === null) {
            return false;
        }
        var found = false;
        (bindData.form.Assignees || []).forEach(function (assignee) {
            if (String(assigneeProfileIdOf(assignee)) === String(profileId)) {
                found = true;
            }
        });
        return found;
    }

    function normalizeProfiles(profiles) {
        var seen = {};
        return profiles.filter(function (profile) {
            return profileIdOf(profile) !== null;
        }).map(function (profile) {
            if (profile.id === undefined || profile.id === null) {
                profile.id = profileIdOf(profile);
            }
            return profile;
        }).filter(function (profile) {
            var id = String(profileIdOf(profile));
            if (seen[id]) {
                return false;
            }
            seen[id] = true;
            return true;
        });
    }

    function normalizeAssignees(assignees) {
        return assignees.filter(function (assignee) {
            return assigneeProfileIdOf(assignee) !== null;
        }).map(function (assignee) {
            assignee.profileId = assigneeProfileIdOf(assignee);
            return assignee;
        });
    }

    function profileIdOf(profile) {
        if (!profile) {
            return null;
        }
        if (profile.id !== undefined && profile.id !== null && profile.id !== "") {
            return profile.id;
        }
        if (profile.profileId !== undefined && profile.profileId !== null && profile.profileId !== "") {
            return profile.profileId;
        }
        return null;
    }

    function assigneeProfileIdOf(assignee) {
        if (!assignee) {
            return null;
        }
        if (assignee.profileId !== undefined && assignee.profileId !== null && assignee.profileId !== "") {
            return assignee.profileId;
        }
        return null;
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

    function priorityClass(task) {
        return "tm-priority-" + priorityKey(task);
    }

    function priorityLabelClass(task) {
        return "tm-priority-label-" + priorityKey(task);
    }

    function taskColorStyle(task) {
        var color = (task && task.projectColor) || (bindData.project && bindData.project.projectColor) || "";
        if (!isColor(color)) {
            return {};
        }
        return {
            borderLeftColor: color,
            boxShadow: "inset 5px 0 0 " + color
        };
    }

    function isColor(value) {
        return /^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(String(value || ""));
    }

    function priorityKey(task) {
        var priority = String((task || {}).priority || "Normal").toLowerCase();
        if (priority === "urgent") {
            return "urgent";
        }
        if (priority === "high") {
            return "high";
        }
        if (priority === "low") {
            return "low";
        }
        return "normal";
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
        link.href = "components/task-tracker/task-style/file/task-common.css?v=2.3";
        document.getElementsByTagName("head")[0].appendChild(link);
    }

    function ensureRichTextEditor() {
        if (!bindData.formOpen) {
            return;
        }
        ensureRichTextAssets(function () {
            setTimeout(function () {
                var editor = $("#taskBodyEditor");
                if (!editor.length || !$.fn.Editor) {
                    return;
                }
                if (!editor.data("editor")) {
                    editor.Editor();
                    bindRichTextSync(editor);
                }
                richTextReady = true;
                setRichTextContent(bindData.form.body);
            }, 0);
        });
    }

    function activateRichText(value) {
        setTimeout(function () {
            ensureRichTextEditor();
            setTimeout(function () {
                setRichTextContent(value || "");
            }, 0);
        }, 0);
    }

    function ensureRichTextAssets(cb) {
        if (!document.getElementById("task-richtext-css")) {
            var css = document.createElement("link");
            css.id = "task-richtext-css";
            css.rel = "stylesheet";
            css.type = "text/css";
            css.href = "assets/davvag-cms-generalapps/editor.css";
            document.getElementsByTagName("head")[0].appendChild(css);
        }

        if (window.jQuery && $.fn.Editor) {
            cb();
            return;
        }

        if (document.getElementById("task-richtext-js")) {
            waitForRichText(cb);
            return;
        }

        var script = document.createElement("script");
        script.id = "task-richtext-js";
        script.type = "text/javascript";
        script.src = "assets/davvag-cms-generalapps/editor.js";
        script.onload = cb;
        document.getElementsByTagName("head")[0].appendChild(script);
    }

    function waitForRichText(cb) {
        var attempts = 0;
        var timer = setInterval(function () {
            attempts++;
            if (window.jQuery && $.fn.Editor) {
                clearInterval(timer);
                cb();
            }
            if (attempts > 30) {
                clearInterval(timer);
            }
        }, 100);
    }

    function bindRichTextSync(editor) {
        var content = editor.data("editor");
        if (!content || content.data("taskSyncBound")) {
            return;
        }
        content.data("taskSyncBound", true);
        content.on("keyup paste blur input", function () {
            syncRichTextBody();
        });
    }

    function setRichTextContent(value) {
        var editor = $("#taskBodyEditor");
        if (!editor.length) {
            return;
        }
        if (richTextReady && editor.data("editor")) {
            editor.Editor("setText", value || "");
        } else {
            editor.val(value || "");
        }
    }

    function syncRichTextBody() {
        var editor = $("#taskBodyEditor");
        if (!editor.length) {
            return;
        }
        if (richTextReady && editor.data("editor")) {
            bindData.form.body = editor.Editor("getText");
        } else {
            bindData.form.body = editor.val() || "";
        }
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
