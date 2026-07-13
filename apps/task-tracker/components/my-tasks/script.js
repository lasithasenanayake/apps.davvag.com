WEBDOCK.component().register(function (exports) {
    var api;
    var handler;

    var bindData = {
        errors: [],
        info: [],
        loading: false,
        tasks: [],
        activeStatus: "New",
        statusOptions: ["New", "In Progress", "Waiting", "Done", "Closed"]
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: initialize,
            setStatusTab: setStatusTab,
            openProjects: openProjects,
            openProjectTasks: openProjectTasks,
            openTaskView: openTaskView,
            openTimeTracker: openTimeTracker,
            openPasswordVault: openPasswordVault,
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

    function initialize() {
        ensureTaskCommonStyles();
        api = exports.getComponent("taskapi");
        handler = exports.getShellComponent("soss-routes");

        if (!api) {
            setError("Task service is not loaded.");
            return;
        }
        loadTasks();
    }

    function loadTasks() {
        clearMessages();
        bindData.loading = true;
        api.services.ListMyTasks({status: bindData.activeStatus}).then(function (response) {
            bindData.loading = false;
            bindData.tasks = response.success ? (response.result || []) : [];
        }).error(function () {
            bindData.loading = false;
            setError("Could not load assigned tasks.");
        });
    }

    function setStatusTab(status) {
        bindData.activeStatus = status;
        loadTasks();
    }

    function openProjects() {
        navigate("../projects");
    }

    function openProjectTasks(task) {
        if (!task || !task.projectId) {
            return;
        }
        navigate("../tasks?projectId=" + task.projectId);
    }

    function openTaskView(task) {
        if (!task || !task.taskId || !task.projectId) {
            return;
        }
        navigate("../task-view?projectId=" + task.projectId + "&taskId=" + task.taskId);
    }

    function openTimeTracker(task) {
        if (!task || !task.taskId || !task.projectId) {
            return;
        }
        navigate("../time-tracker?projectId=" + task.projectId + "&taskId=" + task.taskId);
    }

    function openPasswordVault(task) {
        if (!task || !task.projectId) {
            return;
        }
        navigate("../password-vault?projectId=" + task.projectId);
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
        var color = task && task.projectColor ? task.projectColor : "";
        if (!/^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(String(color || ""))) {
            return {};
        }
        return {
            borderLeftColor: color,
            boxShadow: "inset 5px 0 0 " + color
        };
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

    function clearMessages() {
        bindData.errors = [];
        bindData.info = [];
    }
});
