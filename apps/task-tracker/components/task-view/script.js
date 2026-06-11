WEBDOCK.component().register(function (exports) {
    var api;
    var handler;
    var routeData = {};
    var commentFiles = [];

    var bindData = {
        errors: [],
        info: [],
        project: null,
        task: null,
        assignees: [],
        attachments: [],
        workLogs: [],
        comments: [],
        notifications: [],
        logForm: emptyLog(),
        commentForm: emptyComment(),
        commentAttachments: [],
        discussionOpen: false,
        progressOpen: false,
        statusOptions: ["New", "In Progress", "Waiting", "Done", "Closed"]
    };

    exports.vue = {
        data: bindData,
        methods: {
            backToTasks: backToTasks,
            refresh: initialize,
            addWorkLog: addWorkLog,
            attachmentUrl: attachmentUrl,
            progressClass: progressClass,
            priorityClass: priorityClass,
            priorityLabelClass: priorityLabelClass,
            syncWorkDate: syncWorkDate,
            recalcMinutes: recalcMinutes,
            onCommentFileChange: onCommentFileChange,
            removeCommentAttachment: removeCommentAttachment,
            saveComment: saveComment,
            onReplyFileChange: onReplyFileChange,
            removeReplyAttachment: removeReplyAttachment,
            saveReply: saveReply,
            commentAttachmentUrl: commentAttachmentUrl,
            openReply: openReply,
            cancelReply: cancelReply,
            toggleDiscussion: toggleDiscussion,
            toggleProgress: toggleProgress,
            commentHtml: commentHtml
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function emptyLog() {
        return {
            comments: "",
            logDate: today(),
            startTime: "",
            endTime: "",
            startDate: "",
            endDate: "",
            durationInMinutes: 0,
            progress: 0,
            status: "In Progress"
        };
    }

    function emptyComment() {
        return {
            body: "",
            parentCommentId: 0
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
        if (!routeData.taskId) {
            setError("Task was not selected.");
            return;
        }
        loadProject();
        loadTask();
    }

    function loadProject() {
        if (!routeData.projectId) {
            return;
        }
        api.services.ProjectDetails({projectId: routeData.projectId}).then(function (response) {
            if (response.success) {
                bindData.project = response.result.project;
            }
        });
    }

    function loadTask() {
        api.services.TaskDetails({taskId: routeData.taskId}).then(function (response) {
            if (response.success) {
                bindData.task = response.result.task;
                bindData.assignees = response.result.assignees || [];
                bindData.attachments = response.result.attachments || [];
                bindData.workLogs = response.result.workLogs || [];
                bindData.comments = prepareComments(response.result.comments || []);
                bindData.notifications = response.result.notifications || [];
                bindData.logForm.progress = bindData.task ? bindData.task.progress : 0;
                bindData.logForm.status = bindData.task ? bindData.task.status : "In Progress";
            }
        }).error(function () {
            setError("Could not load task.");
        });
    }

    function onCommentFileChange(e) {
        var files = e.target.files || e.dataTransfer.files;
        if (!files.length) {
            return;
        }
        addCommentFiles(files, bindData.commentAttachments, commentFiles);
        e.target.value = "";
    }

    function removeCommentAttachment(index) {
        bindData.commentAttachments.splice(index, 1);
        if (commentFiles[index]) {
            commentFiles.splice(index, 1);
        }
    }

    function saveComment() {
        saveCommentPayload(bindData.commentForm, bindData.commentAttachments, commentFiles, function () {
            bindData.commentForm = emptyComment();
            bindData.commentAttachments = [];
            commentFiles = [];
        });
    }

    function onReplyFileChange(e, comment) {
        var files = e.target.files || e.dataTransfer.files;
        if (!files.length) {
            return;
        }
        comment.replyFiles = comment.replyFiles || [];
        comment.replyAttachments = comment.replyAttachments || [];
        addCommentFiles(files, comment.replyAttachments, comment.replyFiles);
        e.target.value = "";
    }

    function removeReplyAttachment(comment, index) {
        comment.replyAttachments.splice(index, 1);
        if (comment.replyFiles && comment.replyFiles[index]) {
            comment.replyFiles.splice(index, 1);
        }
    }

    function saveReply(comment) {
        var payload = {
            body: comment.replyBody || "",
            parentCommentId: comment.commentId
        };
        saveCommentPayload(payload, comment.replyAttachments || [], comment.replyFiles || [], function () {
            comment.replyBody = "";
            comment.replyAttachments = [];
            comment.replyFiles = [];
            comment.replyOpen = false;
        });
    }

    function openReply(comment) {
        comment.replyOpen = true;
    }

    function cancelReply(comment) {
        comment.replyBody = "";
        comment.replyAttachments = [];
        comment.replyFiles = [];
        comment.replyOpen = false;
    }

    function toggleDiscussion() {
        bindData.discussionOpen = !bindData.discussionOpen;
    }

    function toggleProgress() {
        bindData.progressOpen = !bindData.progressOpen;
    }

    function saveCommentPayload(payload, attachments, files, cleanup) {
        clearMessages();
        if (!bindData.task || !bindData.task.taskId) {
            setError("Task is not loaded.");
            return;
        }
        if ((!payload.body || payload.body.trim() === "") && (!attachments || attachments.length === 0)) {
            setError("Comment text or attachment is required.");
            return;
        }
        var data = clone(payload);
        data.taskId = bindData.task.taskId;
        data.Attachments = attachments || [];
        api.services.SaveComment(data).then(function (response) {
            if (response.success) {
                api.services.NotifyTaskAssignees({
                    taskId: bindData.task.taskId,
                    eventType: "Discussion",
                    message: "Task discussion updated"
                });
                uploadCommentFiles(response.result.commentId, files || [], function () {
                    if (cleanup) {
                        cleanup();
                    }
                    setInfo("Comment saved.");
                    loadTask();
                });
            } else {
                setError("Comment save failed.");
            }
        }).error(function () {
            setError("Comment save failed.");
        });
    }

    function addCommentFiles(files, attachments, fileStore) {
        for (var i = 0; i < files.length; i++) {
            fileStore.push(files[i]);
            previewCommentAttachment(files[i], attachments);
        }
    }

    function previewCommentAttachment(file, attachments) {
        var reader = new FileReader();
        reader.onload = function (e) {
            attachments.push({
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

    function uploadCommentFiles(commentId, files, cb) {
        if (!files.length) {
            cb();
            return;
        }
        exports.getAppComponent("davvag-tools", "davvag-file-uploader", function (uploader) {
            uploader.initialize();
            uploader.upload(files, "task_manager_comment_attachments", commentId, function () {
                if (typeof $ !== "undefined" && $.notify) {
                    $.notify("Comment attachment has been uploaded", "info");
                }
                cb();
            });
        });
    }

    function prepareComments(comments) {
        comments.forEach(function (comment) {
            comment.replyBody = "";
            comment.replyFiles = [];
            comment.replyAttachments = [];
            comment.replyOpen = false;
            comment.Attachments = comment.Attachments || [];
            comment.replies = prepareComments(comment.replies || []);
        });
        return comments;
    }

    function commentHtml(value) {
        var html = String(value || "");
        html = html.replace(/<!doctype[^>]*>/gi, "");
        html = html.replace(/<\/?(html|head|body)[^>]*>/gi, "");
        html = html.replace(/<script[\s\S]*?<\/script>/gi, "");
        html = html.replace(/<style[\s\S]*?<\/style>/gi, "");
        html = html.replace(/<iframe[\s\S]*?<\/iframe>/gi, "");
        html = html.replace(/<object[\s\S]*?<\/object>/gi, "");
        html = html.replace(/<embed[\s\S]*?>/gi, "");
        html = html.replace(/\son\w+="[^"]*"/gi, "");
        html = html.replace(/\son\w+='[^']*'/gi, "");
        html = html.replace(/\s(href|src)=["']\s*javascript:[^"']*["']/gi, "");
        return html;
    }

    function addWorkLog() {
        clearMessages();
        if (!bindData.task || !bindData.task.taskId) {
            setError("Task is not loaded.");
            return;
        }
        recalcMinutes();
        var log = clone(bindData.logForm);
        log.taskId = bindData.task.taskId;
        log.startDate = buildDateTime(log.logDate, log.startTime);
        log.endDate = buildDateTime(log.logDate, log.endTime);
        delete log.startTime;
        delete log.endTime;
        api.services.SaveWorkLog(log).then(function (response) {
            if (response.success) {
                api.services.NotifyTaskAssignees({
                    taskId: bindData.task.taskId,
                    eventType: "WorkLog",
                    message: "Work progress updated"
                });
                bindData.logForm = emptyLog();
                setInfo("Work log saved.");
                loadTask();
            } else {
                setError("Work log save failed.");
            }
        }).error(function () {
            setError("Work log save failed.");
        });
    }

    function recalcMinutes() {
        if (!bindData.logForm.logDate || !bindData.logForm.startTime || !bindData.logForm.endTime) {
            return;
        }
        bindData.logForm.startDate = buildDateTime(bindData.logForm.logDate, bindData.logForm.startTime);
        bindData.logForm.endDate = buildDateTime(bindData.logForm.logDate, bindData.logForm.endTime);
        var start = new Date(bindData.logForm.startDate);
        var end = new Date(bindData.logForm.endDate);
        if (end > start) {
            bindData.logForm.durationInMinutes = Math.round((end.getTime() - start.getTime()) / 60000);
        } else {
            bindData.logForm.durationInMinutes = 0;
        }
    }

    function syncWorkDate() {
        if (bindData.logForm.startTime) {
            bindData.logForm.startDate = buildDateTime(bindData.logForm.logDate, bindData.logForm.startTime);
        }
        if (bindData.logForm.endTime) {
            bindData.logForm.endDate = buildDateTime(bindData.logForm.logDate, bindData.logForm.endTime);
        }
        recalcMinutes();
    }

    function backToTasks() {
        navigate("../tasks?projectId=" + routeData.projectId);
    }

    function attachmentUrl(file) {
        if (!file || !file.taskId || !file.name) {
            return "";
        }
        return "components/dock/soss-uploader/service/get/task_manager_attachments/" + file.taskId + "-" + file.name;
    }

    function commentAttachmentUrl(file) {
        if (!file || !file.commentId || !file.name) {
            return file ? file.scr : "";
        }
        return "components/dock/soss-uploader/service/get/task_manager_comment_attachments/" + file.commentId + "-" + file.name;
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

    function getRouteData() {
        var data = {};
        if (handler && handler.getInputData) {
            data = handler.getInputData() || {};
        }
        if ((!data.taskId || !data.projectId) && window.location.href.indexOf("?") > -1) {
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

    function today() {
        var d = new Date();
        var month = String(d.getMonth() + 1);
        var day = String(d.getDate());
        if (month.length === 1) {
            month = "0" + month;
        }
        if (day.length === 1) {
            day = "0" + day;
        }
        return d.getFullYear() + "-" + month + "-" + day;
    }

    function buildDateTime(dateValue, timeValue) {
        if (!dateValue || !timeValue) {
            return "";
        }
        return dateValue + "T" + timeValue;
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
