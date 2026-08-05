WEBDOCK.component().register(function (exports) {
    var api;
    var handler;
    var requestId = 0;

    var bindData = {
        errors: [],
        loading: false,
        loadingProjects: false,
        projectOptions: [],
        viewMode: "project",
        filters: {
            period: "weekly",
            startDate: "",
            endDate: "",
            projectId: ""
        },
        report: emptyReport()
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: loadReport,
            periodChanged: periodChanged,
            filterChanged: loadReport,
            setViewMode: setViewMode,
            openDetailed: openDetailed,
            openProjects: openProjects,
            displayDate: displayDate,
            decimalHours: decimalHours,
            projectColorStyle: projectColorStyle
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function emptyReport() {
        return {
            filters: null,
            totalMinutes: 0,
            totalHHMM: "00:00",
            totalHours: 0,
            projects: [],
            dates: []
        };
    }

    function initialize() {
        ensureTaskCommonStyles();
        api = exports.getComponent("taskapi");
        handler = exports.getShellComponent("soss-routes");
        setPresetRange(new Date());
        applyRouteFilters();
        if (!api || !api.services) {
            setError("Task service is not loaded.");
            return;
        }
        loadProjects();
        loadReport();
    }

    function loadProjects() {
        bindData.loadingProjects = true;
        api.services.ListProjects({}).then(function (response) {
            bindData.loadingProjects = false;
            bindData.projectOptions = response.success ? (response.result || []) : [];
        }).error(function () {
            bindData.loadingProjects = false;
            setError("Could not load report projects.");
        });
    }

    function loadReport() {
        if (!api || !api.services) {
            return;
        }
        clearMessages();
        var activeRequest = ++requestId;
        bindData.loading = true;
        api.services.WorkLogSummary(reportRequest()).then(function (response) {
            if (activeRequest !== requestId) {
                return;
            }
            bindData.loading = false;
            if (!response.success) {
                bindData.report = emptyReport();
                setError("Could not load the work log summary.");
                return;
            }
            bindData.report = normalizeReport(response.result);
            applyEffectiveFilters(bindData.report.filters);
        }).error(function () {
            if (activeRequest !== requestId) {
                return;
            }
            bindData.loading = false;
            bindData.report = emptyReport();
            setError("Could not load the work log summary.");
        });
    }

    function normalizeReport(report) {
        report = report || {};
        report.projects = report.projects || [];
        report.dates = report.dates || [];
        report.totalMinutes = parseInt(report.totalMinutes || 0, 10);
        report.totalHHMM = report.totalHHMM || "00:00";
        report.totalHours = Number(report.totalHours || 0);
        return report;
    }

    function reportRequest() {
        return {
            period: bindData.filters.period,
            startDate: bindData.filters.startDate,
            endDate: bindData.filters.endDate,
            projectId: bindData.filters.projectId
        };
    }

    function periodChanged() {
        setPresetRange(new Date());
        loadReport();
    }

    function setPresetRange(anchor) {
        anchor = new Date(anchor.getFullYear(), anchor.getMonth(), anchor.getDate());
        var start = new Date(anchor.getTime());
        var end = new Date(anchor.getTime());
        if (bindData.filters.period === "weekly") {
            var day = start.getDay();
            var mondayOffset = day === 0 ? -6 : 1 - day;
            start.setDate(start.getDate() + mondayOffset);
            end = new Date(start.getTime());
            end.setDate(end.getDate() + 6);
        } else if (bindData.filters.period === "monthly") {
            start = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
            end = new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0);
        }
        bindData.filters.startDate = formatDate(start);
        bindData.filters.endDate = formatDate(end);
    }

    function applyEffectiveFilters(filters) {
        if (!filters) {
            return;
        }
        bindData.filters.period = filters.period || bindData.filters.period;
        bindData.filters.startDate = filters.startDate || bindData.filters.startDate;
        bindData.filters.endDate = filters.endDate || bindData.filters.endDate;
        bindData.filters.projectId = filters.projectId === "" ? "" : String(filters.projectId || "");
    }

    function setViewMode(mode) {
        bindData.viewMode = mode === "date" ? "date" : "project";
    }

    function openDetailed() {
        navigate("../task-work-log-detailed" + filterQuery());
    }

    function openProjects() {
        navigate("../projects");
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

    function applyRouteFilters() {
        var data = {};
        if (handler && handler.getInputData) {
            data = handler.getInputData() || {};
        }
        if ((!data.period && !data.startDate) && window.location.href.indexOf("?") > -1) {
            window.location.href.split("?")[1].split("&").forEach(function (pair) {
                var parts = pair.split("=");
                data[decodeURIComponent(parts[0])] = decodeURIComponent(parts[1] || "");
            });
        }
        if (data.period === "weekly" || data.period === "monthly" || data.period === "specific") {
            bindData.filters.period = data.period;
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(String(data.startDate || ""))) {
            bindData.filters.startDate = data.startDate;
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(String(data.endDate || ""))) {
            bindData.filters.endDate = data.endDate;
        }
        if (String(data.projectId || "") !== "") {
            bindData.filters.projectId = String(data.projectId);
        }
    }

    function filterQuery() {
        var values = [
            "period=" + encodeURIComponent(bindData.filters.period),
            "startDate=" + encodeURIComponent(bindData.filters.startDate),
            "endDate=" + encodeURIComponent(bindData.filters.endDate)
        ];
        if (bindData.filters.projectId !== "") {
            values.push("projectId=" + encodeURIComponent(bindData.filters.projectId));
        }
        return "?" + values.join("&");
    }

    function formatDate(value) {
        return value.getFullYear() + "-" + pad(value.getMonth() + 1) + "-" + pad(value.getDate());
    }

    function displayDate(value) {
        var parts = String(value || "").split("-");
        if (parts.length !== 3) {
            return value || "";
        }
        return parts[2] + "/" + parts[1] + "/" + parts[0];
    }

    function decimalHours(value) {
        return Number(value || 0).toFixed(2);
    }

    function projectColorStyle(color) {
        if (!/^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(String(color || ""))) {
            return {};
        }
        return {backgroundColor: color};
    }

    function pad(value) {
        return value < 10 ? "0" + value : String(value);
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
    }
});
