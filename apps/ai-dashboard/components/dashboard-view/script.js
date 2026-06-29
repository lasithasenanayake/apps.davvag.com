WEBDOCK.component().register(function(exports) {
    var api;
    var root;
    var state = {
        period: "daily",
        startDate: "",
        endDate: "",
        summary: {},
        series: [],
        profileBreakdown: [],
        applicationBreakdown: [],
        agentBreakdown: [],
        recentErrors: []
    };

    function find(selector) {
        return root.find(selector);
    }

    function serviceResult(response) {
        if (!response || response.success !== true) {
            return {
                success: false,
                message: response && response.result && response.result.message ? response.result.message : "DAVVAG service call failed."
            };
        }
        return response.result || { success: false, message: "DAVVAG service returned an empty response." };
    }

    function setStatus(message, tone) {
        var status = find("[data-dashboard-status]");
        status.removeClass("is-error is-success is-muted");
        if (tone) {
            status.addClass("is-" + tone);
        }
        status.text(message);
    }

    function pad2(value) {
        value = String(value);
        return value.length < 2 ? "0" + value : value;
    }

    function localDateString(date) {
        return date.getFullYear() + "-" + pad2(date.getMonth() + 1) + "-" + pad2(date.getDate());
    }

    function shiftedDate(days) {
        var date = new Date();
        date.setDate(date.getDate() + days);
        return localDateString(date);
    }

    function defaultStart(period) {
        if (period === "monthly") {
            return shiftedDate(-365);
        }
        if (period === "weekly") {
            return shiftedDate(-84);
        }
        return shiftedDate(-29);
    }

    function initializeDates() {
        state.endDate = localDateString(new Date());
        state.startDate = defaultStart(state.period);
        find("[data-dashboard-start]").val(state.startDate);
        find("[data-dashboard-end]").val(state.endDate);
    }

    function formatNumber(value) {
        var number = parseInt(value || 0, 10) || 0;
        if (number.toLocaleString) {
            return number.toLocaleString();
        }
        return String(number);
    }

    function collectFilters() {
        state.startDate = find("[data-dashboard-start]").val() || state.startDate;
        state.endDate = find("[data-dashboard-end]").val() || state.endDate;
        return {
            period: state.period,
            startDate: state.startDate,
            endDate: state.endDate,
            profileId: find("[data-dashboard-profile]").val(),
            appCode: find("[data-dashboard-app]").val(),
            agentCode: find("[data-dashboard-agent]").val()
        };
    }

    function loadDashboard() {
        if (!api || !api.services || !api.services.UsageDashboard) {
            setStatus("Dashboard API is not loaded.", "error");
            return;
        }

        find("[data-dashboard-refresh], [data-dashboard-period]").prop("disabled", true);
        setStatus("Loading usage data.", "muted");
        api.services.UsageDashboard(collectFilters())
            .then(function(response) {
                find("[data-dashboard-refresh], [data-dashboard-period]").prop("disabled", false);
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Usage dashboard failed to load.", "error");
                    return;
                }
                state.summary = result.summary || {};
                state.series = result.series || [];
                state.profileBreakdown = result.profileBreakdown || [];
                state.applicationBreakdown = result.applicationBreakdown || [];
                state.agentBreakdown = result.agentBreakdown || [];
                state.recentErrors = result.recentErrors || [];
                renderDashboard();
                setStatus("Usage dashboard updated.", "success");
            })
            .error(function(response) {
                find("[data-dashboard-refresh], [data-dashboard-period]").prop("disabled", false);
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Usage dashboard failed to load.", "error");
            });
    }

    function renderDashboard() {
        var summary = state.summary || {};
        find("[data-usage-interactions]").text(formatNumber(summary.interactions));
        find("[data-usage-input]").text(formatNumber(summary.inputTokens));
        find("[data-usage-output]").text(formatNumber(summary.outputTokens));
        find("[data-usage-total]").text(formatNumber(summary.totalTokens));
        find("[data-usage-estimated]").text(formatNumber(summary.estimatedInteractions));
        find("[data-usage-errors]").text(formatNumber(summary.errors));
        renderUsageSeries();
        renderBreakdown("[data-usage-profiles]", state.profileBreakdown, ["profileId", "profileName"]);
        renderBreakdown("[data-usage-apps]", state.applicationBreakdown, ["appCode", "appName"]);
        renderBreakdown("[data-usage-agents]", state.agentBreakdown, ["agentCode", "agentName"]);
        renderErrors();
    }

    function renderUsageSeries() {
        var body = find("[data-usage-series]");
        body.empty();
        if (!state.series.length) {
            body.append(emptyTableRow(6, "No usage recorded for this range."));
            return;
        }
        for (var i = 0; i < state.series.length; i++) {
            var row = state.series[i];
            body.append($("<tr>")
                .append($("<td>").text(row.period || ""))
                .append($("<td>").text(formatNumber(row.interactions)))
                .append($("<td>").text(formatNumber(row.inputTokens)))
                .append($("<td>").text(formatNumber(row.outputTokens)))
                .append($("<td>").text(formatNumber(row.totalTokens)))
                .append($("<td>").text(formatNumber(row.errors))));
        }
    }

    function renderBreakdown(selector, rows, labelKeys) {
        var body = find(selector);
        body.empty();
        if (!rows.length) {
            body.append(emptyTableRow(5, "No matching records."));
            return;
        }
        for (var i = 0; i < rows.length && i < 15; i++) {
            var row = rows[i];
            var label = row[labelKeys[0]] || "unknown";
            var secondary = row[labelKeys[1]] || "";
            body.append($("<tr>")
                .append($("<td>").append($("<strong>").text(label)).append(secondary ? $("<span>").text(secondary) : ""))
                .append($("<td>").text(formatNumber(row.interactions)))
                .append($("<td>").text(formatNumber(row.totalTokens)))
                .append($("<td>").text(formatNumber(row.estimatedInteractions)))
                .append($("<td>").text(formatNumber(row.errors))));
        }
    }

    function renderErrors() {
        var list = find("[data-usage-errors-list]");
        list.empty();
        if (!state.recentErrors.length) {
            list.append($("<div>").addClass("ai-dashboard__empty").text("No recent errors for this range."));
            return;
        }
        for (var i = 0; i < state.recentErrors.length; i++) {
            var error = state.recentErrors[i];
            list.append($("<article>").addClass("ai-dashboard__error-item")
                .append($("<strong>").text(error.stage || "error"))
                .append($("<span>").text((error.createdAt || "") + " | " + (error.appCode || "unknown") + " | " + (error.profileId || "unknown")))
                .append($("<p>").text(error.message || "")));
        }
    }

    function emptyTableRow(colspan, text) {
        return $("<tr>").append($("<td>").attr("colspan", colspan).text(text));
    }

    function setPeriod(period) {
        state.period = period;
        state.startDate = defaultStart(period);
        state.endDate = localDateString(new Date());
        find("[data-dashboard-period]").removeClass("is-active");
        find('[data-dashboard-period="' + period + '"]').addClass("is-active");
        find("[data-dashboard-start]").val(state.startDate);
        find("[data-dashboard-end]").val(state.endDate);
        loadDashboard();
    }

    function bindEvents() {
        find("[data-dashboard-period]").on("click", function() {
            setPeriod($(this).data("dashboard-period"));
        });
        find("[data-dashboard-refresh]").on("click", loadDashboard);
    }

    exports.onReady = function(element) {
        root = element;
        api = exports.getComponent("dashboard-api");
        bindEvents();
        initializeDates();
        loadDashboard();
    };
});
