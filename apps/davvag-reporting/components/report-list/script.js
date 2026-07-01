WEBDOCK.component().register(function(exports) {
    var api;
    var root;
    var routes;
    var reports = [];

    function find(selector) {
        return root.find(selector);
    }

    function serviceResult(response) {
        if (!response || response.success !== true) {
            return {
                success: false,
                message: response && response.result ? response.result : "DAVVAG service call failed."
            };
        }
        if (response.result && response.result.success === false) {
            return response.result;
        }
        return response.result;
    }

    function setStatus(message, tone) {
        var status = find("[data-report-status]");
        status.removeClass("is-error is-success is-muted");
        if (tone) {
            status.addClass("is-" + tone);
        }
        status.text(message);
    }

    function navigate(path) {
        path = path || "/";
        if (path.charAt(0) !== "/") {
            path = "/" + path;
        }

        var baseUrl = location.protocol + "//" + location.host + location.pathname + (location.search || "");
        if ((location.hash || "").indexOf("#/app/davvag-reporting") === 0) {
            window.location.href = baseUrl + "#/app/davvag-reporting" + path;
            return;
        }
        window.location.href = baseUrl + "#" + path;
    }

    function loadReports() {
        if (!api || !api.services || !api.services.ListReports) {
            setStatus("Report API is not loaded.", "error");
            return;
        }
        setStatus("Loading reports.", "muted");
        api.services.ListReports().then(function(response) {
            var result = serviceResult(response);
            if (result && result.success === false) {
                setStatus(result.message || "Unable to load reports.", "error");
                return;
            }
            reports = Array.isArray(result) ? result : [];
            renderReports();
            setStatus(reports.length + " report" + (reports.length === 1 ? "" : "s") + " available.", "success");
        }).error(function(response) {
            var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
            setStatus(result.message || "Unable to load reports.", "error");
        });
    }

    function filteredReports() {
        var query = (find("[data-report-search]").val() || "").toLowerCase();
        if (query === "") {
            return reports;
        }
        return reports.filter(function(report) {
            return [
                report.code || "",
                report.title || "",
                report.description || "",
                report.namespace || ""
            ].join(" ").toLowerCase().indexOf(query) > -1;
        });
    }

    function renderReports() {
        var list = find("[data-report-list]");
        var visible = filteredReports();
        list.empty();
        if (!visible.length) {
            list.append($("<div>").addClass("davvag-reporting__empty").text("No reports found."));
            return;
        }
        visible.forEach(function(report) {
            var card = $("<article>").addClass("davvag-reporting__report-card");
            card.append($("<h3>").text(report.title || report.code));
            card.append($("<p>").text(report.description || "No description."));
            card.append($("<div>").addClass("davvag-reporting__meta")
                .append($("<span>").text(report.code || ""))
                .append($("<span>").text(report.namespace || ""))
                .append($("<span>").text((report.fieldCount || 0) + " fields"))
                .append($("<span>").text((report.parameterCount || 0) + " params")));
            if (report.chartEnabled) {
                card.append($("<span>").addClass("davvag-reporting__pill").text("Chart"));
            }
            card.append($("<div>").addClass("davvag-reporting__inline-actions")
                .append($("<button>").attr("type", "button").addClass("btn btn-primary btn-sm").attr("data-view-code", report.code).html('<i class="fa fa-play"></i> View'))
                .append($("<button>").attr("type", "button").addClass("btn btn-default btn-sm").attr("data-edit-code", report.code).html('<i class="fa fa-pencil"></i> Edit'))
                .append($("<button>").attr("type", "button").addClass("btn btn-danger btn-sm").attr("data-delete-code", report.code).html('<i class="fa fa-trash"></i> Delete')));
            list.append(card);
        });
    }

    function deleteReport(code) {
        if (!code || !window.confirm("Delete report '" + code + "'?")) {
            return;
        }
        setStatus("Deleting report.", "muted");
        api.services.DeleteReport({ code: code }).then(function(response) {
            var result = serviceResult(response);
            if (result && result.success === false) {
                setStatus(result.message || "Unable to delete report.", "error");
                return;
            }
            loadReports();
        }).error(function(response) {
            var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
            setStatus(result.message || "Unable to delete report.", "error");
        });
    }

    function bindEvents() {
        find("[data-report-new]").on("click", function() {
            navigate("/designer");
        });
        find("[data-report-settings]").on("click", function() {
            navigate("/settings");
        });
        find("[data-report-refresh]").on("click", loadReports);
        find("[data-report-search]").on("input", renderReports);
        find("[data-report-list]").on("click", "[data-view-code]", function() {
            navigate("/view?code=" + encodeURIComponent($(this).attr("data-view-code")));
        });
        find("[data-report-list]").on("click", "[data-edit-code]", function() {
            navigate("/designer?code=" + encodeURIComponent($(this).attr("data-edit-code")));
        });
        find("[data-report-list]").on("click", "[data-delete-code]", function() {
            deleteReport($(this).attr("data-delete-code"));
        });
    }

    exports.onReady = function(element) {
        root = element;
        api = exports.getComponent("report-api");
        routes = exports.getShellComponent("soss-routes");
        bindEvents();
        loadReports();
    };
});
