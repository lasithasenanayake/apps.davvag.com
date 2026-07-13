WEBDOCK.component().register(function (exports) {
    var scope;
    var api;
    var newfiles = [];

    var bindData = {
        loading: false,
        errors: [],
        info: [],
        projects: [],
        profiles: [],
        tasks: [],
        attachments: [],
        workLogs: [],
        notifications: [],
        selectedProject: null,
        selectedTask: null,
        projectForm: emptyProject(),
        taskForm: emptyTask(),
        workLogForm: emptyWorkLog(),
        filters: {
            taskStatus: ""
        },
        statusOptions: ["New", "In Progress", "Waiting", "Done", "Closed"]
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: initialize,
            selectProject: selectProject,
            newProject: newProject,
            saveProject: saveProject,
            deleteProject: deleteProject,
            ChangePermision: ChangePermision,
            toggleProjectProfile: toggleProjectProfile,
            hasProjectProfile: hasProjectProfile,
            newTask: newTask,
            selectTask: selectTask,
            loadTasks: loadTasks,
            saveTask: saveTask,
            deleteTask: deleteTask,
            setTaskStatus: setTaskStatus,
            toggleAssignee: toggleAssignee,
            isAssignee: isAssignee,
            addWorkLog: addWorkLog,
            removeAttachment: removeAttachment,
            onFileChange: onFileChange,
            progressClass: progressClass,
            priorityClass: priorityClass,
            priorityLabelClass: priorityLabelClass,
            attachmentUrl: attachmentUrl,
            formatProfileName: formatProfileName,
            clearMessages: clearMessages
        },
        onReady: function (s) {
            scope = s;
            initialize();
        }
    };

    exports.onReady = function () {
    };

    function initialize() {
        ensureTaskCommonStyles();
        api = exports.getComponent("taskapi");

        if (!api) {
            setError("Task service is not loaded.");
            return;
        }

        loadProfiles();
        loadProjects();
    }

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

    function emptyWorkLog() {
        return {
            comments: "",
            durationInMinutes: 0,
            progress: 0,
            status: ""
        };
    }

    function clone(obj) {
        return JSON.parse(JSON.stringify(obj || {}));
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

    function loadProfiles() {
        api.services.ListProfiles({}).then(function (response) {
            if (response.success) {
                bindData.profiles = response.result || [];
            }
        }).error(function () {
            setError("Could not load profiles.");
        });
    }

    function loadProjects() {
        bindData.loading = true;
        api.services.ListProjects({}).then(function (response) {
            bindData.loading = false;
            if (response.success) {
                bindData.projects = response.result || [];
                if (!bindData.selectedProject && bindData.projects.length > 0) {
                    selectProject(bindData.projects[0]);
                }
            } else {
                setError("Could not load projects.");
            }
        }).error(function () {
            bindData.loading = false;
            setError("Could not load projects.");
        });
    }

    function newProject() {
        bindData.selectedProject = null;
        bindData.projectForm = emptyProject();
        bindData.tasks = [];
        newTask();
    }

    function selectProject(project) {
        bindData.selectedProject = project;
        bindData.projectForm = clone(project);
        bindData.projectForm.AccessProfiles = parseProfileIds(bindData.projectForm.profileids);
        bindData.selectedTask = null;
        bindData.taskForm = emptyTask();
        loadTasks();
    }

    function saveProject() {
        clearMessages();
        if (!bindData.projectForm.name) {
            setError("Project name is required.");
            return;
        }

        api.services.SaveProject(bindData.projectForm).then(function (response) {
            if (response.success) {
                bindData.projectForm = response.result;
                upsertLocal(bindData.projects, response.result, "projectId");
                selectProject(response.result);
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
                removeLocal(bindData.projects, project, "projectId");
                newProject();
                setInfo("Project deleted.");
            } else {
                setError("Project delete failed.");
            }
        }).error(function () {
            setError("Project delete failed.");
        });
    }

    function ChangePermision(project) {
        var target = project || bindData.projectForm;
        if (!target) {
            return;
        }
        openViewObject(target.sysviewobject, function (data, shellpopup) {
            target.sysviewobject = data;
            bindData.projectForm.sysviewobject = data;
            api.services.SaveProject(target).then(function () {
                setInfo("Project permission updated.");
            }).error(function () {
                setError("Error changing project permission.");
            });
            shellpopup.close();
        });
    }

    function toggleProjectProfile(profile) {
        bindData.projectForm.AccessProfiles = bindData.projectForm.AccessProfiles || [];
        var id = String(profile.id);
        var next = [];
        var exists = false;
        bindData.projectForm.AccessProfiles.forEach(function (profileId) {
            if (String(profileId) === id) {
                exists = true;
            } else {
                next.push(profileId);
            }
        });
        if (!exists) {
            next.push(profile.id);
        }
        bindData.projectForm.AccessProfiles = next;
    }

    function hasProjectProfile(profile) {
        var found = false;
        (bindData.projectForm.AccessProfiles || []).forEach(function (profileId) {
            if (String(profileId) === String(profile.id)) {
                found = true;
            }
        });
        return found;
    }

    function loadTasks() {
        if (!bindData.selectedProject || !bindData.selectedProject.projectId) {
            bindData.tasks = [];
            return;
        }
        api.services.ListTasks({
            projectId: bindData.selectedProject.projectId,
            status: bindData.filters.taskStatus
        }).then(function (response) {
            if (response.success) {
                bindData.tasks = response.result || [];
            } else {
                setError("Could not load tasks.");
            }
        }).error(function () {
            setError("Could not load tasks.");
        });
    }

    function newTask() {
        bindData.selectedTask = null;
        bindData.attachments = [];
        bindData.workLogs = [];
        bindData.notifications = [];
        newfiles = [];
        bindData.taskForm = emptyTask();
        if (bindData.selectedProject) {
            bindData.taskForm.projectId = bindData.selectedProject.projectId;
            bindData.taskForm.sysviewobject = bindData.selectedProject.sysviewobject;
        }
    }

    function selectTask(task) {
        bindData.selectedTask = task;
        bindData.taskForm = clone(task);
        bindData.taskForm.Assignees = [];
        bindData.taskForm.Attachments = [];
        bindData.taskForm.RemovedAttachments = [];
        newfiles = [];

        api.services.TaskDetails({taskId: task.taskId}).then(function (response) {
            if (response.success) {
                bindData.attachments = response.result.attachments || [];
                bindData.workLogs = response.result.workLogs || [];
                bindData.notifications = response.result.notifications || [];
                bindData.taskForm.Assignees = response.result.assignees || [];
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
        if (!bindData.selectedProject || !bindData.selectedProject.projectId) {
            setError("Select a project first.");
            return;
        }
        if (!bindData.taskForm.subject) {
            setError("Task subject is required.");
            return;
        }

        bindData.taskForm.projectId = bindData.selectedProject.projectId;
        bindData.taskForm.Attachments = bindData.attachments;

        api.services.SaveTask(bindData.taskForm).then(function (response) {
            if (response.success) {
                bindData.taskForm = response.result;
                upsertLocal(bindData.tasks, response.result, "taskId");
                uploadFiles(response.result.taskId, function () {
                    api.services.NotifyTaskAssignees({
                        taskId: response.result.taskId,
                        eventType: "TaskSaved",
                        message: "Task updated"
                    });
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
                removeLocal(bindData.tasks, task, "taskId");
                newTask();
                setInfo("Task deleted.");
            } else {
                setError("Task delete failed.");
            }
        }).error(function () {
            setError("Task delete failed.");
        });
    }

    function setTaskStatus(status) {
        bindData.taskForm.status = status;
        if (status === "Done" || status === "Closed") {
            bindData.taskForm.progress = 100;
        }
        saveTask();
    }

    function toggleAssignee(profile) {
        bindData.taskForm.Assignees = bindData.taskForm.Assignees || [];
        var exists = false;
        var next = [];
        bindData.taskForm.Assignees.forEach(function (assignee) {
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
        bindData.taskForm.Assignees = next;
    }

    function isAssignee(profile) {
        var found = false;
        (bindData.taskForm.Assignees || []).forEach(function (assignee) {
            if (String(assignee.profileId) === String(profile.id)) {
                found = true;
            }
        });
        return found;
    }

    function addWorkLog() {
        if (!bindData.taskForm.taskId) {
            setError("Save the task before adding work logs.");
            return;
        }
        var log = clone(bindData.workLogForm);
        log.taskId = bindData.taskForm.taskId;
        log.progress = log.progress || bindData.taskForm.progress || 0;
        log.status = log.status || bindData.taskForm.status || "In Progress";

        api.services.SaveWorkLog(log).then(function (response) {
            if (response.success) {
                bindData.workLogs.unshift(response.result);
                bindData.taskForm.progress = response.result.progress;
                bindData.taskForm.status = response.result.status;
                bindData.workLogForm = emptyWorkLog();
                api.services.NotifyTaskAssignees({
                    taskId: bindData.taskForm.taskId,
                    eventType: "WorkLog",
                    message: "Work log added"
                });
            } else {
                setError("Work log save failed.");
            }
        }).error(function () {
            setError("Work log save failed.");
        });
    }

    function onFileChange(e) {
        var files = e.target.files || e.dataTransfer.files;
        if (!files.length) {
            return;
        }
        createAttachments(files);
        e.target.value = "";
    }

    function createAttachments(files) {
        newfiles = newfiles || [];
        for (var i = 0; i < files.length; i++) {
            newfiles.push(files[i]);
            getAttachmentPreview(newfiles.length - 1, files[i]);
        }
    }

    function getAttachmentPreview(index, file) {
        var reader = new FileReader();
        reader.onload = function (e) {
            newfiles[index].scr = e.target.result;
            bindData.attachments.push({
                id: 0,
                name: newfiles[index].name,
                fileType: newfiles[index].type,
                size: newfiles[index].size,
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
            bindData.taskForm.RemovedAttachments.push({
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

    function formatProfileName(profileId) {
        var name = profileId;
        bindData.profiles.forEach(function (profile) {
            if (String(profile.id) === String(profileId)) {
                name = profile.name;
            }
        });
        return name;
    }

    function parseProfileIds(value) {
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

    function upsertLocal(arr, item, key) {
        var found = false;
        arr.forEach(function (existing, index) {
            if (String(existing[key]) === String(item[key])) {
                arr.splice(index, 1, item);
                found = true;
            }
        });
        if (!found) {
            arr.unshift(item);
        }
    }

    function removeLocal(arr, item, key) {
        arr.forEach(function (existing, index) {
            if (String(existing[key]) === String(item[key])) {
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
});
