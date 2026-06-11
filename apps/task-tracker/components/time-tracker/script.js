WEBDOCK.component().register(function (exports) {
    var api;
    var handler;
    var routeData = {};
    var timer = null;

    var bindData = {
        errors: [],
        info: [],
        project: null,
        task: null,
        mode: "tracking",
        paused: false,
        elapsedSeconds: 0,
        startAt: null,
        endAt: null,
        logForm: emptyLog(),
        statusOptions: ["New", "In Progress", "Waiting", "Done", "Closed"]
    };

    exports.vue = {
        data: bindData,
        methods: {
            backToTask: backToTask,
            togglePause: togglePause,
            resumeTimer: resumeTimer,
            stopTimer: stopTimer,
            saveWorkLog: saveWorkLog,
            startAgain: startAgain,
            formattedTime: formattedTime,
            ringStyle: ringStyle
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function emptyLog() {
        return {
            comments: "",
            durationInMinutes: 1,
            progress: 0,
            status: "In Progress"
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
        if (!routeData.taskId || !routeData.projectId) {
            setError("Open the time tracker from a task.");
            return;
        }
        loadProject();
        loadTask();
    }

    function loadProject() {
        api.services.ProjectDetails({projectId: routeData.projectId}).then(function (response) {
            if (response.success) {
                bindData.project = response.result.project;
            }
        });
    }

    function loadTask() {
        api.services.TaskDetails({taskId: routeData.taskId}).then(function (response) {
            if (response.success && response.result.task) {
                bindData.task = response.result.task;
                resetLogForm();
                startTimer();
            } else {
                setError("Could not load task.");
            }
        }).error(function () {
            setError("Could not load task.");
        });
    }

    function startTimer() {
        clearTimer();
        bindData.mode = "tracking";
        bindData.paused = false;
        bindData.elapsedSeconds = 0;
        bindData.startAt = new Date();
        bindData.endAt = null;
        timer = setInterval(function () {
            if (!bindData.paused) {
                bindData.elapsedSeconds++;
            }
        }, 1000);
    }

    function startAgain() {
        resetLogForm();
        startTimer();
    }

    function togglePause() {
        bindData.paused = !bindData.paused;
    }

    function resumeTimer() {
        bindData.mode = "tracking";
        bindData.paused = false;
        if (!timer) {
            timer = setInterval(function () {
                if (!bindData.paused) {
                    bindData.elapsedSeconds++;
                }
            }, 1000);
        }
    }

    function stopTimer() {
        clearTimer();
        bindData.paused = false;
        bindData.endAt = new Date();
        bindData.mode = "logging";
        resetLogForm();
    }

    function clearTimer() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    function resetLogForm() {
        bindData.logForm = emptyLog();
        bindData.logForm.durationInMinutes = Math.max(1, Math.round(bindData.elapsedSeconds / 60));
        bindData.logForm.progress = bindData.task ? parseInt(bindData.task.progress || 0, 10) : 0;
        bindData.logForm.status = bindData.task ? (bindData.task.status || "In Progress") : "In Progress";
    }

    function saveWorkLog() {
        clearMessages();
        if (!bindData.task || !bindData.task.taskId) {
            setError("Task is not loaded.");
            return;
        }
        if (!bindData.logForm.comments || bindData.logForm.comments.trim() === "") {
            setError("Enter what you worked on before logging time.");
            return;
        }

        var startAt = bindData.startAt || new Date();
        var endAt = bindData.endAt || new Date();
        var log = clone(bindData.logForm);
        log.taskId = bindData.task.taskId;
        log.durationInMinutes = Math.max(1, parseInt(log.durationInMinutes || 0, 10));
        log.progress = Math.max(0, Math.min(100, parseInt(log.progress || 0, 10)));
        log.logDate = formatDateTime(startAt);
        log.startDate = formatDateTime(startAt);
        log.endDate = formatDateTime(endAt);

        api.services.SaveWorkLog(log).then(function (response) {
            if (response.success) {
                api.services.NotifyTaskAssignees({
                    taskId: bindData.task.taskId,
                    eventType: "TimeTracker",
                    message: "Work time logged"
                });
                bindData.task.progress = response.result.progress;
                bindData.task.status = response.result.status;
                bindData.mode = "saved";
                setInfo("Work log saved.");
            } else {
                setError("Work log save failed.");
            }
        }).error(function () {
            setError("Work log save failed.");
        });
    }

    function formattedTime() {
        var total = parseInt(bindData.elapsedSeconds || 0, 10);
        var hours = Math.floor(total / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        var seconds = total % 60;
        if (hours > 0) {
            return pad(hours) + ":" + pad(minutes) + ":" + pad(seconds);
        }
        return pad(minutes) + ":" + pad(seconds);
    }

    function ringStyle() {
        var degrees = Math.round(((bindData.elapsedSeconds % 3600) / 3600) * 360);
        if (degrees < 8 && bindData.elapsedSeconds > 0) {
            degrees = 8;
        }
        return {
            background: "conic-gradient(#a844ff 0deg, #7b20ef " + degrees + "deg, #201844 " + degrees + "deg, #201844 360deg)"
        };
    }

    function backToTask() {
        clearTimer();
        if (routeData.projectId && routeData.taskId) {
            navigate("../task-view?projectId=" + routeData.projectId + "&taskId=" + routeData.taskId);
        } else {
            navigate("../my-tasks");
        }
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

    function formatDateTime(value) {
        var month = pad(value.getMonth() + 1);
        var day = pad(value.getDate());
        var hours = pad(value.getHours());
        var minutes = pad(value.getMinutes());
        return value.getFullYear() + "-" + month + "-" + day + "T" + hours + ":" + minutes;
    }

    function pad(value) {
        value = String(value);
        return value.length === 1 ? "0" + value : value;
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
