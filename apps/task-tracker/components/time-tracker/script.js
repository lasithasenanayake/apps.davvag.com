WEBDOCK.component().register(function (exports) {
    var api;
    var handler;
    var routeData = {};
    var timer = null;
    var activityListenersBound = false;

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
        pauseStartedAt: null,
        pausedDurationMs: 0,
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
        bindActivityListeners();
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
        bindData.pauseStartedAt = null;
        bindData.pausedDurationMs = 0;
        timer = setInterval(function () {
            syncElapsedSeconds();
        }, 1000);
    }

    function startAgain() {
        resetLogForm();
        startTimer();
    }

    function togglePause() {
        if (bindData.paused) {
            resumeElapsedClock();
        } else {
            syncElapsedSeconds();
            bindData.pauseStartedAt = new Date();
            bindData.paused = true;
        }
    }

    function resumeTimer() {
        bindData.mode = "tracking";
        if (bindData.startAt && bindData.endAt) {
            bindData.startAt = new Date(bindData.startAt.getTime() + Math.max(0, new Date().getTime() - bindData.endAt.getTime()));
        }
        bindData.endAt = null;
        if (bindData.paused) {
            resumeElapsedClock();
        }
        if (!timer) {
            timer = setInterval(function () {
                syncElapsedSeconds();
            }, 1000);
        }
        syncElapsedSeconds();
    }

    function stopTimer() {
        clearTimer();
        bindData.endAt = new Date();
        if (bindData.paused) {
            resumeElapsedClock(bindData.endAt);
        }
        syncElapsedSeconds(bindData.endAt);
        bindData.paused = false;
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
        bindData.logForm.durationInMinutes = calculateDurationInMinutes(bindData.startAt, bindData.endAt, bindData.elapsedSeconds);
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
        syncElapsedSeconds(endAt);
        var log = clone(bindData.logForm);
        log.taskId = bindData.task.taskId;
        log.durationInMinutes = calculateDurationInMinutes(startAt, endAt, bindData.elapsedSeconds);
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
        syncElapsedSeconds(bindData.endAt);
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
        syncElapsedSeconds(bindData.endAt);
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
        var seconds = pad(value.getSeconds());
        return value.getFullYear() + "-" + month + "-" + day + "T" + hours + ":" + minutes + ":" + seconds;
    }

    function bindActivityListeners() {
        if (activityListenersBound) {
            return;
        }
        activityListenersBound = true;
        document.addEventListener("visibilitychange", syncFromAppState);
        window.addEventListener("focus", syncFromAppState);
    }

    function syncFromAppState() {
        if (!bindData.startAt) {
            return;
        }
        if (bindData.mode === "tracking") {
            syncElapsedSeconds();
            return;
        }
        if (bindData.mode === "logging") {
            syncElapsedSeconds(bindData.endAt || new Date());
            bindData.logForm.durationInMinutes = calculateDurationInMinutes(bindData.startAt, bindData.endAt, bindData.elapsedSeconds);
        }
    }

    function resumeElapsedClock(referenceTime) {
        var resumeAt = referenceTime || new Date();
        if (bindData.pauseStartedAt) {
            bindData.pausedDurationMs += Math.max(0, resumeAt.getTime() - bindData.pauseStartedAt.getTime());
        }
        bindData.pauseStartedAt = null;
        bindData.paused = false;
        syncElapsedSeconds();
    }

    function syncElapsedSeconds(referenceTime) {
        if (!bindData.startAt) {
            bindData.elapsedSeconds = 0;
            return;
        }
        var endAt = referenceTime || bindData.endAt || new Date();
        var elapsedMs = endAt.getTime() - bindData.startAt.getTime() - parseInt(bindData.pausedDurationMs || 0, 10);
        if (bindData.paused && bindData.pauseStartedAt) {
            elapsedMs -= Math.max(0, endAt.getTime() - bindData.pauseStartedAt.getTime());
        }
        bindData.elapsedSeconds = Math.max(0, Math.floor(elapsedMs / 1000));
    }

    function calculateDurationInMinutes(startAt, endAt, fallbackElapsedSeconds) {
        if (startAt && endAt) {
            var diffMs = endAt.getTime() - startAt.getTime() - parseInt(bindData.pausedDurationMs || 0, 10);
            if (diffMs > 0) {
                return Math.max(1, Math.round(diffMs / 60000));
            }
        }
        return Math.max(1, Math.round(parseInt(fallbackElapsedSeconds || 0, 10) / 60));
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
